<?php

declare(strict_types=1);

namespace QiDNS\Lib;

/**
 * 解析记录快照（用于回滚）
 */
class Snapshot
{
    /** 为某域名当前记录打快照 */
    public static function capture(int $domainId, string $domain, string $provider, array $records, string $reason = ''): int
    {
        return \QiDNS\Core\DB::insert(
            'INSERT INTO qd_snapshot (domain_id, domain, provider, content, reason, created_at)
             VALUES (?,?,?,?,?,?)',
            [
                $domainId,
                $domain,
                $provider,
                json_encode($records, JSON_UNESCAPED_UNICODE),
                $reason,
                date('Y-m-d H:i:s'),
            ]
        );
    }

    /** 取某域名最新快照 */
    public static function latest(int $domainId): ?array
    {
        return \QiDNS\Core\DB::one(
            'SELECT * FROM qd_snapshot WHERE domain_id = ? ORDER BY id DESC LIMIT 1',
            [$domainId]
        );
    }

    /** 按 ID 取快照 */
    public static function get(int $id): ?array
    {
        return \QiDNS\Core\DB::one('SELECT * FROM qd_snapshot WHERE id = ?', [$id]);
    }
}
