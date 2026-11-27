<?php

declare(strict_types=1);

namespace QiDNS\Core;

/**
 * AES-256-CBC 加密工具（用于服务商凭证存储）
 */
class Crypto
{
    private static function key(): string
    {
        $key = $GLOBALS['qidns_config']['app_key'] ?? '';
        if (strlen($key) !== 32) {
            // 兜底：由 DB 密码派生，避免空密钥
            $key = substr(hash('sha256', $GLOBALS['qidns_config']['db']['password'] ?? 'qidns'), 0, 32);
        }
        return $key;
    }

    public static function encrypt(string $plain): string
    {
        $iv = openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }
}
