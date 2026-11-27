<?php

declare(strict_types=1);

namespace QiDNS\Lib;

use QiDNS\Core\Auth;
use QiDNS\Core\DB;
use QiDNS\Core\Request;

/**
 * 审计日志
 */
class Audit
{
    public static function log(string $action, string $target = '', array $detail = []): void
    {
        try {
            DB::insert(
                'INSERT INTO qd_audit (user_id, username, action, target, detail, ip, created_at)
                 VALUES (?,?,?,?,?,?,?)',
                [
                    Auth::id(),
                    Auth::username(),
                    $action,
                    $target,
                    $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : '',
                    Request::ip(),
                    date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            // 审计失败不影响主流程
        }
    }
}
