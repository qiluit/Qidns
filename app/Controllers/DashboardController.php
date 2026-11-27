<?php

declare(strict_types=1);

namespace QiDNS\Controllers;

use QiDNS\Core\DB;
use QiDNS\Core\View;

class DashboardController
{
    public function index(): void
    {
        $accountCount = (int)DB::val('SELECT COUNT(*) FROM qd_account');
        $domainCount = (int)DB::val('SELECT COUNT(*) FROM qd_domain WHERE is_stale = 0');
        $staleCount = (int)DB::val('SELECT COUNT(*) FROM qd_domain WHERE is_stale = 1');
        $recordCount = (int)DB::val('SELECT COUNT(*) FROM qd_record');

        // 按 DNS 托管商统计（域名托管分布）——排除失效域名
        $providerStats = DB::all(
            "SELECT dns_provider AS provider, COUNT(*) AS cnt FROM qd_domain
             WHERE dns_provider != '' AND is_stale = 0 GROUP BY dns_provider"
        );

        // 按注册商统计——排除失效域名
        $registrarStats = DB::all(
            "SELECT registrar AS provider, COUNT(*) AS cnt FROM qd_domain
             WHERE registrar != '' AND is_stale = 0 GROUP BY registrar"
        );

        // 临期域名（30 天内到期）——排除失效域名
        $expiringDomains = DB::all(
            "SELECT * FROM qd_domain
             WHERE is_stale = 0 AND expire_at IS NOT NULL AND expire_at != ''
               AND expire_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY expire_at ASC LIMIT 10"
        );

        // 最近审计
        $recentAudit = DB::all(
            'SELECT * FROM qd_audit ORDER BY id DESC LIMIT 10'
        );

        echo View::render('dashboard/index', [
            'accountCount' => $accountCount,
            'domainCount' => $domainCount,
            'staleCount' => $staleCount,
            'recordCount' => $recordCount,
            'providerStats' => $providerStats,
            'registrarStats' => $registrarStats,
            'expiringDomains' => $expiringDomains,
            'recentAudit' => $recentAudit,
        ]);
    }
}
