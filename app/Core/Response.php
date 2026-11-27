<?php

declare(strict_types=1);

namespace QiDNS\Core;

/**
 * HTTP 响应封装（支持 HTML / JSON / 跳转）
 */
class Response
{
    /** 输出 JSON 并结束 */
    public static function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** 输出成功 JSON */
    public static function ok($data = null, string $msg = 'ok'): void
    {
        self::json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    /** 输出失败 JSON */
    public static function fail(string $msg = '操作失败', int $code = 1, int $httpCode = 200): void
    {
        self::json(['code' => $code, 'msg' => $msg, 'data' => null], $httpCode);
    }

    /** 跳转 */
    public static function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}
