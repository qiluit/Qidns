<?php

declare(strict_types=1);

namespace QiDNS\Core;

/**
 * 会话与鉴权
 */
class Auth
{
    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name($GLOBALS['qidns_config']['session_name'] ?? 'qidns_session');
            session_start();
        }
    }

    public static function login(int $id, string $username, string $nickname): void
    {
        self::init();
        $_SESSION['uid'] = $id;
        $_SESSION['username'] = $username;
        $_SESSION['nickname'] = $nickname;
        session_regenerate_id(true);
    }

    public static function logout(): void
    {
        self::init();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        self::init();
        return !empty($_SESSION['uid']);
    }

    public static function id(): int
    {
        return (int)($_SESSION['uid'] ?? 0);
    }

    public static function username(): string
    {
        return (string)($_SESSION['username'] ?? '');
    }

    public static function nickname(): string
    {
        return (string)($_SESSION['nickname'] ?? '');
    }
}
