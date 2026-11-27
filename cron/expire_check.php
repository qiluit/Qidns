<?php

/**
 * QiDNS 定时任务：域名/证书到期巡检
 * 用法：php cron/expire_check.php
 * 建议挂到计划任务（每天一次）
 */

declare(strict_types=1);

define('APP_RUNNING', true);
define('APP_ROOT', dirname(__DIR__));

date_default_timezone_set('Asia/Shanghai');

if (!file_exists(APP_ROOT . '/config.php')) {
    exit("未安装\n");
}

require APP_ROOT . '/app/Core/autoload.php';

use QiDNS\Core\DB;
use QiDNS\Lib\Whois\WhoisQuery;

echo "[" . date('Y-m-d H:i:s') . "] 开始到期巡检\n";

$domains = DB::all('SELECT id, domain FROM qd_domain');
$updated = 0;

foreach ($domains as $d) {
    $whois = WhoisQuery::domainExpire($d['domain']);
    if ($whois['expire_at'] !== '') {
        DB::exec('UPDATE qd_domain SET expire_at=? WHERE id=?', [$whois['expire_at'], $d['id']]);
        $updated++;
        echo "  - {$d['domain']} 到期日: {$whois['expire_at']}（剩余 {$whois['days_left']} 天）\n";
    }
    usleep(300000); // 限速
}

echo "完成，更新 $updated / " . count($domains) . " 个域名\n";
