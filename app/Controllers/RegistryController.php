<?php

declare(strict_types=1);

namespace QiDNS\Controllers;

use QiDNS\Core\DB;
use QiDNS\Core\Request;
use QiDNS\Core\View;
use QiDNS\Lib\DomainFilter;

/**
 * DNS 管理（域名资产视角）
 *
 * 归类规则：只按「域名真实归属服务商」= 注册商 registrar 分组。
 * 域名 DNS 托管到哪家不影响归属，托管过来的信息仅作参考列，不作为分组依据。
 * 归属未确认（registrar 为空）的域名不混入任何服务商分组，单独计数提示。
 * 与「解析管理」一致，统一排除失效域名（is_stale=1，由 OwnershipResolver 结合账号 API 权限判定）。
 */
class RegistryController
{
    private const PROVIDERS = [
        'aliyun' => '阿里云',
        'west' => '西部数码',
        'dnspod' => '腾讯云 DNSPod',
    ];

    public function index(): void
    {
        $provider = trim((string)Request::get('provider', ''));
        $keyword = trim((string)Request::get('keyword', ''));
        $suffix = strtolower(trim((string)Request::get('suffix', '')));
        $sort = (string)Request::get('sort', 'expire');
        $order = strtolower((string)Request::get('order', 'asc'));

        // 排序白名单；到期日排序时把「无到期日」沉底
        $sortMap = ['domain' => 'd.domain', 'expire' => 'd.expire_at'];
        $sortColumn = $sortMap[$sort] ?? 'd.expire_at';
        $orderSql = $order === 'desc' ? 'DESC' : 'ASC';
        $nullLast = $sortColumn === 'd.expire_at'
            ? "(d.expire_at IS NULL OR d.expire_at = '') ASC, "
            : '';

        $sql = 'SELECT d.*,
                       ra.name AS registrar_account_name,
                       da.name AS dns_account_name
                FROM qd_domain d
                LEFT JOIN qd_account ra ON ra.id = d.registrar_account_id
                LEFT JOIN qd_account da ON da.id = d.dns_account_id
                WHERE d.registrar != "" AND d.is_stale = 0';
        $params = [];

        if ($provider !== '' && isset(self::PROVIDERS[$provider])) {
            $sql .= ' AND d.registrar = ?';
            $params[] = $provider;
        }
        if ($keyword !== '') {
            $sql .= ' AND (d.domain LIKE ? OR d.remark LIKE ?)';
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($suffix !== '' && preg_match('/^[a-z0-9][a-z0-9.-]*$/', $suffix)) {
            $sql .= ' AND LOWER(d.domain) LIKE ?';
            $params[] = '%.' . $suffix;
        }
        $sql .= ' ORDER BY ' . $nullLast . $sortColumn . ' ' . $orderSql . ', d.id ASC';

        $domains = DB::all($sql, $params);
        foreach ($domains as &$d) {
            $d['days_left'] = $d['expire_at']
                ? (int)floor((strtotime($d['expire_at']) - time()) / 86400)
                : null;
            $d['record_count'] = (int)DB::val('SELECT COUNT(*) FROM qd_record WHERE domain_id = ?', [$d['id']]);
        }
        unset($d);

        // 按注册商分桶
        $groups = [];
        foreach ($domains as $d) {
            $groups[$d['registrar']][] = $d;
        }

        // 统计（与列表同口径：排除失效域名）
        // 「急需赎回」只统计仍在赎回期内、还能救回来的域名——这类域名不再判失效，
        // 会照常出现在列表里并标红；过完赎回期的（status=4）已被判失效，不计入。
        $stat = [
            'owned' => (int)DB::val('SELECT COUNT(*) FROM qd_domain WHERE registrar != "" AND is_stale = 0'),
            'unknown' => (int)DB::val('SELECT COUNT(*) FROM qd_domain WHERE registrar = "" AND is_stale = 0'),
            'renew' => (int)DB::val('SELECT COUNT(*) FROM qd_domain WHERE domain_status = 1 AND is_stale = 0'),
            'redeem' => (int)DB::val('SELECT COUNT(*) FROM qd_domain WHERE domain_status = 2 AND is_stale = 0'),
            'stale' => (int)DB::val('SELECT COUNT(*) FROM qd_domain WHERE is_stale = 1'),
        ];

        echo View::render('registry/index', [
            'groups' => $groups,
            'providers' => self::PROVIDERS,
            'provider' => $provider,
            'keyword' => $keyword,
            'suffix' => $suffix,
            'suffixOptions' => DomainFilter::suffixOptions(),
            'sort' => $sort,
            'order' => $orderSql,
            'stat' => $stat,
        ]);
    }
}
