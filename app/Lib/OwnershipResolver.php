<?php

declare(strict_types=1);

namespace QiDNS\Lib;

use QiDNS\Core\Crypto;
use QiDNS\Core\DB;
use QiDNS\Lib\Provider\ProviderFactory;
use QiDNS\Lib\Provider\ProviderInterface;
use QiDNS\Lib\Whois\NsResolver;

/**
 * 域名归属判定（精准版）
 *
 * 核心问题：一个域名当前是否仍属于「我」，不能用「API 能否拉到 / 能否发起解析」判断——
 * DNSPod 等解析服务商的 zone 在域名转手后会残留在原账号，仍可增删改记录，
 * 但注册局 NS 早已指向新主人的 DNS，这些记录并不生效，等于没有实际解析权。
 *
 * 判定规则：
 *   失效（不再属于我）当满足以下任一：
 *     A) 注册商侧状态为「不可赎回」（domain_status=4）：已过赎回期，域名被释放或
 *        转为注册商自己持有，谁都救不回来——这才是真正该排除的终态。
 *        **仅处于赎回期（domain_status=2）不判失效**：还能赎回就还有归属，
 *        列表继续保留并标红提醒，避免把「还能救」的域名提前判死。
 *     B) 在用 NS 全部被换成注册商的「过期停放」集群（expirens* 等）：解析已停用。
 *   判为「我的」当且仅当未被上述反证否定，且满足以下任一：
 *     1) 持有证据：出现在某注册商的「真实持有」列表里且状态正常
 *        （西部数码 getdomains、阿里云 QueryDomainList、腾讯云 domain…）
 *     2) 有效解析证据：出现在某服务商的解析 zone 列表里，且注册局 NS 实际指向该服务商
 *        （effective_dns == 逻辑服务商，且该域名在该服务商 zone 中）
 *
 * 关于 2) 的盲区（用户明确允许）：域名已转手、注册局 NS 仍指向我方 DNS 且 zone 还在，
 * 此时原理上我们仍能改记录生效（客户自注册域名、仅委托我方解析的典型场景），判为我的。
 * 与之相对，A) 的不可赎回域名 NS 已被注册局切走/停解析，属于「看着像我的、实际不生效」，
 * 必须判失效——这正是 qiluhost.com 这类残留 zone 与真实有效解析的分界线。
 *
 * 两个证据都不满足 → 判定失效（is_stale=1），两菜单统一排除，总览可复核。
 * NS/持有状态恢复后重新判定即自愈解除，不物理删除。
 *
 * 状态口径（各注册商统一）：0 未知 / 1 急需续费 / 2 赎回期（仍可赎回）/ 3 正常 / 4 不可赎回。
 * 来源：①阿里云 DomainStatus、腾讯云 BuyStatus、西部数码按接口到期日推算；
 * ②注册局在用 NS 是否已被换成过期停放集群（NsResolver::isParkedNsList）。
 *
 * 容错：任一账号 API 拉取失败则整轮跳过重算，绝不因网络抖动/凭证过期
 * 把整批域名误标失效（宁可不更新，不可误杀）。
 */
class OwnershipResolver
{
    /**
     * 汇总所有启用账号的 API 证据。
     * @return array{owned:array<string,int>, zones:array, failed:string[]}
     *         owned[域名] = 注册状态（0未知 1急需续费 2赎回期 3正常；同一域名多条取最「好」状态）
     */
    public static function collectEvidence(): array
    {
        $owned = [];   // 注册商真实持有：域名小写 => domain_status
        $zones = [];   // 逻辑服务商 => [域名小写 => true]
        $failed = [];

        $accounts = DB::all('SELECT * FROM qd_account WHERE status = 1 ORDER BY id');
        foreach ($accounts as $a) {
            $name = (string)$a['name'];
            $provider = (string)$a['provider'];
            $logical = ProviderFactory::logicalProvider($provider);
            try {
                $cred = json_decode(Crypto::decrypt((string)$a['credentials']), true) ?: [];
                $p = ProviderFactory::make($provider, $cred);

                // zone 证据：所有 DNS 接入账号的 listDomains
                $zoneRows = $p->listDomains();
                foreach ($zoneRows as $d) {
                    $domain = strtolower(trim((string)($d['domain'] ?? '')));
                    if ($domain !== '') {
                        $zones[$logical][$domain] = true;
                    }
                }
                // 持有证据：注册商账号的 listDomains（西数）及独立持有接口（阿里云等）
                if (self::isRegistrar($provider)) {
                    foreach ($zoneRows as $d) {
                        $domain = strtolower(trim((string)($d['domain'] ?? '')));
                        if ($domain !== '') {
                            $owned[$domain] = self::mergeStatus($owned[$domain] ?? null, (int)($d['domain_status'] ?? 0));
                        }
                    }
                }
                // 独立持有接口：阿里云 QueryDomainList、腾讯云 domain 服务（DNSPod 统一 provider）
                foreach ($p->listOwnedDomains() as $o) {
                    $domain = strtolower(trim((string)($o['domain'] ?? '')));
                    if ($domain !== '') {
                        $owned[$domain] = self::mergeStatus($owned[$domain] ?? null, (int)($o['domain_status'] ?? 0));
                    }
                }
            } catch (\Throwable $e) {
                $failed[] = $name . '(' . $provider . '): ' . $e->getMessage();
            }
        }
        return ['owned' => $owned, 'zones' => $zones, 'failed' => $failed];
    }

    /**
     * 同一域名被多个账号/接口报到时合并注册状态：
     * 只要有一处说「正常」，就不能因为另一处（可能是残留条目）说「赎回」而误杀。
     */
    private static function mergeStatus(?int $current, int $next): int
    {
        if ($current === null) {
            return $next;
        }
        return self::statusRank($next) > self::statusRank($current) ? $next : $current;
    }

    /** 状态可信度排序：正常(3) > 未知(0) > 临期(1) > 赎回(2) > 不可赎回(4) */
    private static function statusRank(int $status): int
    {
        return [3 => 5, 0 => 4, 1 => 3, 2 => 2, 4 => 1][$status] ?? 4;
    }

    /**
     * 重算归属失效标记（全库或指定 id）。
     * 内部会实时拉取所有账号 API 证据；任一账号失败则整轮跳过。
     *
     * @param int[]|null $ids null=全库
     * @return array{ok:bool,total:int,stale:int,mine:int,failed:string[]}
     */
    public static function recheck(?array $ids = null): array
    {
        $ev = self::collectEvidence();
        if ($ev['failed']) {
            return ['ok' => false, 'total' => 0, 'stale' => 0, 'mine' => 0, 'failed' => $ev['failed']];
        }
        return self::applyEvidence($ev, $ids);
    }

    /**
     * 用「已收集好的证据」对指定域名判定并落库。
     * 供分批刷新任务复用：证据（各账号 API）只在任务开始时拉一次，
     * 后续每批直接传入，避免每批重复拉取导致更慢。
     *
     * @param array<string,int>|array<string,bool> $ev owned（兼容旧任务里的 true 值）
     * @param int[]|null $ids null=全库
     * @return array{ok:bool,total:int,stale:int,mine:int,failed:string[]}
     */
    public static function applyEvidence(array $ev, ?array $ids = null): array
    {
        if (!empty($ev['failed'])) {
            return ['ok' => false, 'total' => 0, 'stale' => 0, 'mine' => 0, 'failed' => $ev['failed']];
        }

        if ($ids === null) {
            $rows = DB::all('SELECT id, domain, effective_dns, ns_servers FROM qd_domain ORDER BY id');
        } elseif (!$ids) {
            return ['ok' => true, 'total' => 0, 'stale' => 0, 'mine' => 0, 'failed' => []];
        } else {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::all("SELECT id, domain, effective_dns, ns_servers FROM qd_domain WHERE id IN ($ph)", $ids);
        }

        $now = date('Y-m-d H:i:s');
        $ownedMap = (array)($ev['owned'] ?? []);
        $zones = (array)($ev['zones'] ?? []);
        $stat = ['ok' => true, 'total' => count($rows), 'stale' => 0, 'mine' => 0, 'failed' => []];
        foreach ($rows as $r) {
            $domain = strtolower(trim((string)$r['domain']));
            $eff = (string)$r['effective_dns'];
            $label = ['dnspod' => 'DNSPod', 'aliyun' => '阿里云', 'west' => '西部数码'][$eff] ?? $eff;

            // 反证一：注册商侧「不可赎回」（过完赎回期）→ 域名已释放/被注册商自己持有
            // 仅处于赎回期（2）不判失效：还能赎回就仍算持有，界面标红提醒即可
            $ownedState = array_key_exists($domain, $ownedMap) ? (int)$ownedMap[$domain] : null;
            $dead = $ownedState === ProviderInterface::STATUS_DEAD;

            // 反证二：在用 NS 已被换成注册商的过期停放集群 → 解析已停用
            $parked = NsResolver::isParkedNsList((string)($r['ns_servers'] ?? ''));

            $hasOwned = $ownedState !== null && !$dead;
            $hasValidDns = !$dead && !$parked
                && $eff !== '' && $eff !== 'other'
                && isset($zones[$eff][$domain]);

            $mine = $hasOwned || $hasValidDns;
            $stale = $mine ? 0 : 1;

            if ($mine) {
                $reason = '';
                $stat['mine']++;
            } else {
                $stat['stale']++;
                if ($dead) {
                    $reason = '注册商侧已过赎回期，域名不可赎回（已释放/被注册商持有）';
                } elseif ($parked) {
                    $reason = 'NS 已成注册商过期停放服务器，解析已停用';
                } elseif ($eff === '' || $eff === 'other') {
                    $reason = $eff === 'other'
                        ? '无注册商持有 + NS指向第三方DNS(疑似已转手)'
                        : '无注册商持有 + 查无在用NS(疑似未注册/已释放)';
                } else {
                    $reason = '无注册商持有 + NS指向' . $label . '但不在我方该服务商解析列表(疑似已转手/仅残留zone)';
                }
            }
            DB::exec(
                'UPDATE qd_domain SET is_stale=?, stale_reason=?, updated_at=? WHERE id=?',
                [$stale, $reason, $now, (int)$r['id']]
            );
        }
        return $stat;
    }

    /** 凭证类型中，哪些「listDomains 列表本身就是真实持有」（无需独立持有接口） */
    private static function isRegistrar(string $provider): bool
    {
        // 西部数码代理平台 getdomains 拉的就是账户下持有的全部域名。
        // 阿里云、腾讯云 DNSPod 的 listDomains 是解析 zone（会残留），
        // 它们的真实持有走各自的 listOwnedDomains（QueryDomainList / domain 服务），
        // 已在 collectEvidence 中统一收集，不在此列。
        return $provider === 'west';
    }
}
