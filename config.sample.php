<?php

declare(strict_types=1);

defined('APP_RUNNING') || exit;

// QiDNS 配置模板 —— 安装向导会据此生成 config.php
// 生产环境请勿修改本文件，实际配置在 config.php

return [
    'name' => 'QiDNS',
    'timezone' => 'Asia/Shanghai',
    'session_name' => 'qidns_session',

    // 应用密钥：用于加密服务商凭证（AES-256-CBC），安装时自动生成 32 字节随机串
    'app_key' => '',

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'qiludns',
        'username' => 'qiludns',
        'password' => '',
        'charset' => 'utf8mb4',
        'persistent' => false,
    ],
];
