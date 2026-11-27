<?php

declare(strict_types=1);

namespace QiDNS\Controllers;

use QiDNS\Core\Auth;
use QiDNS\Core\DB;
use QiDNS\Core\Request;
use QiDNS\Core\Response;
use QiDNS\Core\View;
use QiDNS\Lib\Audit;

class AuthController
{
    public function login(): void
    {
        if (Auth::check()) {
            Response::redirect('/index.php?r=Dashboard');
        }
        View::setLayout(null);
        echo View::render('auth/login', [], false);
    }

    public function doLogin(): void
    {
        $username = trim((string)Request::post('username', ''));
        $password = (string)Request::post('password', '');

        if ($username === '' || $password === '') {
            Response::fail('请输入用户名和密码');
        }

        $user = DB::one('SELECT * FROM qd_user WHERE username = ?', [$username]);
        if (!$user || !password_verify($password, $user['password'])) {
            Audit::log('login_fail', $username);
            Response::fail('用户名或密码错误');
        }

        Auth::login((int)$user['id'], $user['username'], $user['nickname']);
        DB::exec('UPDATE qd_user SET last_login_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $user['id']]);
        Audit::log('login', $username);

        Response::ok(null, '登录成功');
    }

    public function logout(): void
    {
        Audit::log('logout', Auth::username());
        Auth::logout();
        Response::redirect('/index.php?r=Auth/login');
    }
}
