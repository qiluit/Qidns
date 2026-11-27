<?php

declare(strict_types=1);

namespace QiDNS\Lib\Whois;

use QiDNS\Core\DB;
use QiDNS\Core\Crypto;
use QiDNS\Lib\Provider\ProviderFactory;

/**
 * 注册局实际 NS 实证 + 失效域名判定。
 *
 * 系统里有两个彼此独立的维度，不可混用：
 *  - 真实持有 registrar：来自各注册商账号 API（QueryDomainList / 西部数码 / 腾讯云 domain）
 *  - 在用 DNS effective_dns：来自注册局实际 NS 委派（本类 dns_get_record 探测）
 *
 * 【DNS 管理】只看真实持有；【解析管理】只看在用 DNS。两个菜单都排除失效域名。
 *
 * 本类只负责 NS 实证探测与「在用 DNS」归类（effective_dns/ns_servers/ns_checked_at），
 * 另外提供「NS 是否已切到过期停放集群」的判定（isParkedNsList），供归属判定作反证。
 * 域名是否仍属于我、是否失效（is_stale/stale_reason）由 OwnershipResolver 结合
 * 账号 API 权限与注册状态判定——因为 zone 会残留，单看能否拉到 API 或单看 NS 都不够。
 */
class NsResolver
{
    /** NS 主机后缀 → 服务商代码。按后缀精确匹配，避免子串误判。 */
    private const NS_SUFFIX = [
        'dnspod' => ['dnspod.net', 'dnspod.cn', 'dnspod.com', 'dnsv2.com', 'dnsv3.com', 'dnsv5.com'],
        'aliyun' => ['hichina.com', 'alidns.com', 'aliyun.com', 'aliyundns.com'],
        // west 含两套：myhostadmin.net 为标准 NS，diymysite.com 为其 DIY 智能 DNS
        // （实测 ns1.diymysite.com 与 ns1.myhostadmin.net 解析到同一组 IP 集群）
        'west'   => ['myhostadmin.net', 'west.cn', 'xundns.com', 'west263.com', 'diymysite.com'],
    ];

    /**
     * 「过期停放 NS」主机名首段。域名过期/进入赎回期后，注册商会把注册局 NS 改到
     * 专用停放集群，这类 NS 不承载真实解析（阿里云为 expirens1~4.hichina.com）。
     *
     * 用途：作为「解析已停用」的独立反证。注册商状态接口偶有拿不到（如西数只给
     * clienthold），而 NS 是否已被切到停放集群是注册局侧的客观事实，可交叉验证。
     */
    private const PARKED_NS_PREFIX = ['expirens', 'expiredns', 'parkingns'];

    /**
     * 探测一个域名的注册局实际 NS。
     * @return array ['provider'=>dnspod|aliyun|west|other|'', 'servers'=>string[], 'no_ns'=>bool]
     *              查无 NS 时 provider='' 且 no_ns=true；NS 属于第三方时 provider='other'
     */
    public static function detect(string $domain): array
    {
        $hosts = [];
        $records = @dns_get_record($domain, DNS_NS);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['target'])) {
                    $hosts[] = rtrim(strtolower((string)$r['target']), '.');
                }
            }
        }
        $hosts = array_values(array_unique($hosts));

        if (!$hosts) {
            return ['provider' => '', 'servers' => [], 'no_ns' => true];
        }
        return ['provider' => self::matchProvider($hosts), 'servers' => $hosts, 'no_ns' => false];
    }

    /**
     * 该 NS 主机名是否为「过期停放」服务器。
     * 只看首段标签（expirens3.hichina.com → expirens3），避免把
     * 形如 ns1.expirens-example.com 的普通域名误判。
     */
    public static function isParkedHost(string $host): bool
    {
        $host = rtrim(strtolower(trim($host)), '.');
        if ($host === '') {
            return false;
        }
        $label = explode('.', $host)[0];
        foreach (self::PARKED_NS_PREFIX as $prefix) {
            if (strpos($label, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 一组 NS（逗号分隔，落库格式）是否「全部」为过期停放服务器。
     * 只要还有一台正常 DNS，域名就仍可能解析生效，故必须全部命中才算停用。
     */
    public static function isParkedNsList(string $csv): bool
    {
        $hosts = array_filter(array_map('trim', explode(',', $csv)), static fn($h) => $h !== '');
        if (!$hosts) {
            return false;
        }
        foreach ($hosts as $host) {
            if (!self::isParkedHost($host)) {
                return false;
            }
        }
        return true;
    }

    /** 多个 NS 命中不同服务商时取命中数最多者；全不认识则 other */
    private static function matchProvider(array $hosts): string
    {
        $hits = [];
        foreach ($hosts as $h) {
            foreach (self::NS_SUFFIX as $code => $suffixes) {
                foreach ($suffixes as $suffix) {
                    if (substr($h, -strlen($suffix)) === $suffix) {
                        $hits[$code] = ($hits[$code] ?? 0) + 1;
                        break 2;
                    }
                }
            }
        }
        if (!$hits) {
            return 'other';
        }
        arsort($hits);
        return (string)array_key_first($hits);
    }

    /**
     * 由 NS 实证结果算出要落库的「在用 DNS」字段。
     * 注意：失效判定（is_stale）已上移到 OwnershipResolver（需结合账号 API 权限），
     * 本类只负责 NS 探测与归类，不再判断域名是否属于我。
     * @param array $ns detect() 的返回
     */
    public static function classify(array $ns): array
    {
        return [
            'effective_dns' => $ns['no_ns'] ? '' : $ns['provider'],
            'ns_servers'    => implode(',', $ns['servers']),
            'ns_checked_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 批量刷新 NS 实证与失效标记（按域名 id）。
     * @param int[]|null $ids 指定域名 id；null = 全库
     */
    public static function refreshAll(?array $ids = null): array
    {
        if ($ids === null) {
            $rows = DB::all('SELECT id, domain, registrar, registrar_account_id, expire_at FROM qd_domain ORDER BY id');
        } elseif (!$ids) {
            return self::emptyStat();
        } else {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::all("SELECT id, domain, registrar, registrar_account_id, expire_at FROM qd_domain WHERE id IN ($ph)", $ids);
        }
        return self::refreshRows($rows);
    }

    /**
     * 按域名字符串批量刷新（供账号同步后调用，此时手上只有域名列表）。
     * 自动忽略库里不存在的域名。
     * @param string[] $names
     */
    public static function refreshAllByNames(array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map('trim', $names))));
        if (!$names) {
            return self::emptyStat();
        }
        $ph = implode(',', array_fill(0, count($names), '?'));
        $rows = DB::all("SELECT id, domain, registrar, registrar_account_id, expire_at FROM qd_domain WHERE domain IN ($ph)", $names);
        return self::refreshRows($rows);
    }

    /** 对一批域名行执行 NS 探测 + 落库，返回统计 */
    private static function refreshRows(array $rows): array
    {
        $stat = self::emptyStat();
        $stat['total'] = count($rows);
        foreach ($rows as $row) {
            $ns = self::detect((string)$row['domain']);

            // 公网查无 NS 时，回退读该域名注册商账号登记的 NS 实证。
            // 与「设置 NS」弹窗同源，保证列表「在用 NS」与弹窗显示一致。
            if ($ns['no_ns']) {
                $ns = self::detectFromRegistrar($row, $ns);
            }

            $c = self::classify($ns);
            DB::exec(
                'UPDATE qd_domain SET effective_dns=?, ns_servers=?, ns_checked_at=? WHERE id=?',
                [$c['effective_dns'], $c['ns_servers'], $c['ns_checked_at'], (int)$row['id']]
            );
            $key = $c['effective_dns'] === '' ? '(查无NS)' : $c['effective_dns'];
            $stat['effective'][$key] = ($stat['effective'][$key] ?? 0) + 1;
            if ($ns['no_ns']) $stat['no_ns']++;
        }
        ksort($stat['effective']);
        return $stat;
    }

    /**
     * 公网查无 NS 时，回退到注册商账号 API 读取登记的 NS。
     *
     * 场景：域名刚接入 / NS 未全球生效 / 本地 DNS 查不到，但注册商处已明确登记 NS
     * （如 yunjiameng.com 公网查无，西数账号里登记 ns3/ns4.diymysite.com）。
     * 与「设置 NS」弹窗共用同一 getNameservers 数据源，避免两处显示不一致。
     *
     * 归类规则：能按 NS 后缀识别服务商就沿用识别结果；识别不出（如自建 NS 主机名）时，
     * 因为该 NS 是从我方注册商账号读到的，直接归为该注册商代码——这是可信托管证据。
     */
    private static function detectFromRegistrar(array $row, array $ns): array
    {
        $accountId = (int)($row['registrar_account_id'] ?? 0);
        $registrar = (string)($row['registrar'] ?? '');
        if ($accountId <= 0 || $registrar === '') {
            return $ns;
        }
        $account = DB::one('SELECT provider, credentials FROM qd_account WHERE id = ?', [$accountId]);
        if (!$account) {
            return $ns;
        }
        try {
            $cred = json_decode(Crypto::decrypt((string)$account['credentials']), true) ?: [];
            $provider = ProviderFactory::make((string)$account['provider'], $cred);
            $hosts = $provider->getNameservers((string)$row['domain']);
        } catch (\Throwable $e) {
            // 注册商接口不可用时保持原结果（查无 NS），不影响归属判定
            return $ns;
        }

        $hosts = array_values(array_unique(array_filter(array_map(
            static fn($h) => rtrim(strtolower(trim((string)$h)), '.'),
            $hosts
        ))));
        if (!$hosts) {
            return $ns;
        }

        $code = self::matchProvider($hosts);
        // 识别不出具体服务商（自建 NS 主机名）→ 归为该域名所属注册商
        if ($code === 'other' || $code === '') {
            $code = $registrar;
        }
        return ['provider' => $code, 'servers' => $hosts, 'no_ns' => false];
    }

    private static function emptyStat(): array
    {
        return ['total' => 0, 'effective' => [], 'no_ns' => 0];
    }
}
