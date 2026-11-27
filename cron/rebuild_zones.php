<?php

/**
 * QiDNS 一次性/可重复执行的维护脚本：重建「域名 ↔ DNS 平台」关联表
 *
 * 用途：
 *  - 首次引入 qd_domain_zone 时，把三个账号当前真实存在的 zone 全部登记一遍；
 *  - 之后若怀疑关联表与线上不一致，也可重复执行（幂等，不写域名业务字段）。
 *
 * 用法：php cron/rebuild_zones.php
 */

declare(strict_types=1);

define('APP_RUNNING', true);
define('APP_ROOT', dirname(__DIR__));

date_default_timezone_set('Asia/Shanghai');

if (!file_exists(APP_ROOT . '/config.php')) {
    exit("未安装\n");
}

require APP_ROOT . '/app/Core/autoload.php';

use QiDNS\Core\Crypto;
use QiDNS\Core\DB;
use QiDNS\Lib\Provider\ProviderFactory;
use QiDNS\Lib\ZoneMap;

echo '[' . date('Y-m-d H:i:s') . "] 重建域名-平台关联\n";

$now = date('Y-m-d H:i:s');
$accounts = DB::all('SELECT * FROM qd_account ORDER BY id');

// 域名 => id（只登记已入库域名，不在此处新建）
$domainIds = [];
foreach (DB::all('SELECT id, domain FROM qd_domain') as $row) {
    $domainIds[strtolower((string)$row['domain'])] = (int)$row['id'];
}

$totalZones = 0;
$totalLinked = 0;

foreach ($accounts as $account) {
    $cred = json_decode(Crypto::decrypt($account['credentials']), true) ?: [];
    try {
        $provider = ProviderFactory::make((string)$account['provider'], $cred);
        $list = $provider->listDomains();
    } catch (\Throwable $e) {
        echo "  ! 账号 {$account['name']} 拉取失败：" . $e->getMessage() . "\n";
        continue;
    }

    $logical = ProviderFactory::logicalProvider((string)$account['provider']);
    $seen = [];
    foreach ($list as $d) {
        $name = strtolower((string)($d['domain'] ?? ''));
        if ($name === '' || !isset($domainIds[$name])) {
            continue;
        }
        $seen[$domainIds[$name]] = true;
        ZoneMap::upsert($domainIds[$name], (int)$account['id'], $logical, $now);
        $totalLinked++;
    }

    // 该账号已消失的 zone：删掉关联（幂等，不碰域名本身）
    $removed = 0;
    ZoneMap::ensure();
    $rows = DB::all('SELECT domain_id FROM qd_domain_zone WHERE account_id = ?', [(int)$account['id']]);
    foreach ($rows as $r) {
        if (!isset($seen[(int)$r['domain_id']])) {
            DB::exec('DELETE FROM qd_domain_zone WHERE account_id = ? AND domain_id = ?', [(int)$account['id'], (int)$r['domain_id']]);
            $removed++;
        }
    }

    $totalZones += count($list);
    printf("  - 账号 %d %s（%s）zone=%d，已登记=%d，清理失效关联=%d\n",
        (int)$account['id'], $account['name'], $logical, count($list), count($seen), $removed);
}

$changed = ZoneMap::refreshPointer();
echo "  关联总数={$totalLinked}，生效指针更新={$changed} 行\n";

$cross = DB::all(
    'SELECT COUNT(*) AS c FROM (
        SELECT domain_id FROM qd_domain_zone GROUP BY domain_id HAVING COUNT(*) > 1
     ) t'
);
echo '  同时挂在多家平台的域名：' . (int)($cross[0]['c'] ?? 0) . " 个\n";
echo '[' . date('Y-m-d H:i:s') . "] 完成\n";
