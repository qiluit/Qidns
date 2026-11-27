<?php

declare(strict_types=1);

namespace QiDNS\Lib;

use QiDNS\Core\DB;

/**
 * 域名 ↔ DNS 解析平台（zone）关联表
 *
 * 事实前提：一个域名可以同时挂在多个 DNS 平台的多个账号下（历史迁移、复制 zone 没删等），
 * 但注册局 NS 只能指向其中一家，只有那一家真正生效。
 *
 * 因此：
 *  - 「挂在哪几家」记在本表，一家一行，互不覆盖 → 域名列表据此渲染多个 chip；
 *  - qd_domain.dns_provider / dns_account_id 退化为「生效指针」，只供解析记录页
 *    确定默认操作目标；真实生效与否一律以 effective_dns（NS 实证）为准。
 */
class ZoneMap
{
    /** 属于我方接入的解析平台（other 是第三方，不算） */
    public const KNOWN = ['dnspod', 'aliyun', 'west'];

    private static $ready = false;

    /** 建表（幂等）：只在本进程首次访问时查一次 information_schema */
    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }
        $exists = (int)DB::val(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['qd_domain_zone']
        );
        if ($exists === 0) {
            DB::exec(
                "CREATE TABLE IF NOT EXISTS qd_domain_zone (
                    id int(11) unsigned NOT NULL AUTO_INCREMENT,
                    domain_id int(11) unsigned NOT NULL,
                    account_id int(11) unsigned NOT NULL,
                    provider varchar(16) NOT NULL DEFAULT '',
                    last_seen_at datetime DEFAULT NULL,
                    created_at datetime NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_domain_account (domain_id, account_id),
                    KEY idx_domain (domain_id),
                    KEY idx_account (account_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }
        self::$ready = true;
    }

    /** 记录「该账号下确实存在这个域名的 zone」 */
    public static function upsert(int $domainId, int $accountId, string $provider, string $now): void
    {
        self::ensure();
        DB::exec(
            'INSERT INTO qd_domain_zone (domain_id, account_id, provider, last_seen_at, created_at)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE provider=VALUES(provider), last_seen_at=VALUES(last_seen_at)',
            [$domainId, $accountId, $provider, $now, $now]
        );
    }

    /**
     * 批量取关联：返回 [domain_id => [ ['provider'=>..,'account_id'=>..,'account_name'=>..], ... ]]
     * 排序规则：生效平台优先放在调用方，这里只按 provider/account_id 稳定排序。
     */
    public static function mapByDomainIds(array $domainIds): array
    {
        if (!$domainIds) {
            return [];
        }
        self::ensure();
        $ids = array_values(array_unique(array_map('intval', $domainIds)));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::all(
            "SELECT z.domain_id, z.provider, z.account_id, z.last_seen_at,
                    a.name AS account_name, a.provider AS account_provider
               FROM qd_domain_zone z
               LEFT JOIN qd_account a ON a.id = z.account_id
              WHERE z.domain_id IN ($in)
              ORDER BY z.provider ASC, z.account_id ASC",
            $ids
        );
        $out = [];
        foreach ($rows as $r) {
            $r['domain_id'] = (int)$r['domain_id'];
            $r['account_id'] = (int)$r['account_id'];
            $out[$r['domain_id']][] = $r;
        }
        return $out;
    }

    /** 某账号挂着哪些域名（账号页「查看域名」用） */
    public static function domainIdsOfAccount(int $accountId): array
    {
        self::ensure();
        $rows = DB::all('SELECT domain_id FROM qd_domain_zone WHERE account_id = ?', [$accountId]);
        return array_map(static function ($r) {
            return (int)$r['domain_id'];
        }, $rows);
    }

    /**
     * 解析「这次要操作哪个平台的账号」。
     *
     *  @param int $accountId        显式指定的账号（从某一家的 chip 点进来）；0 = 不指定
     *  @param int $pointerAccountId 生效指针里的账号（qd_domain.dns_account_id）
     *  @return array|null 账号行；null = 无法确定（既没指定、指针空、又有多家 zone）
     */
    public static function resolveAccount(int $domainId, int $accountId, int $pointerAccountId): ?array
    {
        self::ensure();
        if ($accountId > 0) {
            $linked = (int)DB::val(
                'SELECT COUNT(*) FROM qd_domain_zone WHERE domain_id = ? AND account_id = ?',
                [$domainId, $accountId]
            );
            return $linked > 0 ? DB::one('SELECT * FROM qd_account WHERE id = ?', [$accountId]) : null;
        }
        if ($pointerAccountId > 0) {
            return DB::one('SELECT * FROM qd_account WHERE id = ?', [$pointerAccountId]);
        }
        // 指针为空（NS 指向第三方/查无 NS）：只有一家 zone 时可确定，多家则需要用户先选
        $rows = DB::all('SELECT account_id FROM qd_domain_zone WHERE domain_id = ? LIMIT 2', [$domainId]);
        if (count($rows) === 1) {
            return DB::one('SELECT * FROM qd_account WHERE id = ?', [(int)$rows[0]['account_id']]);
        }
        return null;
    }

    /** 该账号下的 zone 是否就是 NS 实际指向的那一家（决定解析记录页是否只是「看着好玩」） */
    public static function isEffective(int $domainId, int $accountId, string $effectiveDns): bool
    {
        if ($effectiveDns === '') {
            return false;
        }
        self::ensure();
        $provider = (string)DB::val(
            'SELECT provider FROM qd_domain_zone WHERE domain_id = ? AND account_id = ?',
            [$domainId, $accountId]
        );
        return $provider !== '' && $provider === $effectiveDns;
    }

    /** 账号被删除时，清掉它的 zone 关联（域名本身保留） */
    public static function purgeAccount(int $accountId): void
    {
        self::ensure();
        DB::exec('DELETE FROM qd_domain_zone WHERE account_id = ?', [$accountId]);
    }

    /** 域名被删除时，清掉它的全部 zone 关联（否则关联表留下指向空域名的孤儿行） */
    public static function purgeDomain(int $domainId): void
    {
        self::ensure();
        DB::exec('DELETE FROM qd_domain_zone WHERE domain_id = ?', [$domainId]);
    }

    /**
     * 依据 NS 实证（effective_dns）重算「生效指针」dns_provider / dns_account_id。
     *
     * 规则（按优先级）：
     *  1) NS 指向我方某平台，且该平台确实有 zone → 指针指向该平台的账号；
     *  2) NS 指向我方某平台，但我方没接入这个账号/没有 zone → provider 记录该平台、account_id=0
     *     （表示「生效但未接入」，解析记录页不可操作，只提示去接入）；
     *  3) NS 为 other / 空 → 没有生效的我方平台，指针清空；
     *     但若该域名只挂在一家 zone，仍把指针指向那家，方便查看/清理这份 zone。
     *
     * @param int[] $domainIds 只重算这些域名；传空数组表示全表
     */
    public static function refreshPointer(array $domainIds = []): int
    {
        self::ensure();
        if ($domainIds) {
            $ids = array_values(array_unique(array_map('intval', $domainIds)));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $domains = DB::all(
                "SELECT id, dns_provider, dns_account_id, effective_dns FROM qd_domain WHERE id IN ($in)",
                $ids
            );
        } else {
            $domains = DB::all('SELECT id, dns_provider, dns_account_id, effective_dns FROM qd_domain');
        }
        if (!$domains) {
            return 0;
        }

        $zones = self::mapByDomainIds(array_column($domains, 'id'));

        $changed = 0;
        foreach ($domains as $d) {
            $id = (int)$d['id'];
            $eff = (string)$d['effective_dns'];
            $list = $zones[$id] ?? [];

            $provider = '';
            $accountId = 0;

            if (in_array($eff, self::KNOWN, true)) {
                $provider = $eff;
                foreach ($list as $z) {
                    if ($z['provider'] === $eff) {
                        $accountId = (int)$z['account_id'];
                        break;
                    }
                }
            } elseif (count($list) === 1) {
                // 没有生效平台，但只有一份 zone：指向它，便于查看/清理
                $provider = (string)$list[0]['provider'];
                $accountId = (int)$list[0]['account_id'];
            }

            if ((string)$d['dns_provider'] === $provider && (int)$d['dns_account_id'] === $accountId) {
                continue;
            }
            DB::exec(
                'UPDATE qd_domain SET dns_provider = ?, dns_account_id = ? WHERE id = ?',
                [$provider, $accountId, $id]
            );
            $changed++;
        }
        return $changed;
    }
}
