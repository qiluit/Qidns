<?php

declare(strict_types=1);

namespace QiDNS\Controllers;

use QiDNS\Core\DB;
use QiDNS\Core\Request;
use QiDNS\Core\Response;
use QiDNS\Core\View;
use QiDNS\Lib\BulkTaskTrait;
use QiDNS\Lib\Whois\WhoisQuery;

/**
 * 域名/证书到期监控
 */
class MonitorController
{
    use BulkTaskTrait;
    public function index(): void
    {
        // 表头排序（白名单防注入）：默认到期日升序 = 最快到期在前
        $sort = (string)Request::get('sort', 'expire');
        $order = strtolower((string)Request::get('order', 'asc'));
        $sortMap = [
            'domain' => 'd.domain',
            'expire' => 'd.expire_at',
        ];
        $sortColumn = $sortMap[$sort] ?? 'd.expire_at';
        $orderSql = $order === 'desc' ? 'DESC' : 'ASC';

        // 剩余天数与到期日是同向单调关系，按到期日排即可（此页已过滤无到期日的域名）
        $domains = DB::all(
            "SELECT d.*,
                    ra.name AS registrar_account_name,
                    da.name AS dns_account_name
             FROM qd_domain d
             LEFT JOIN qd_account ra ON ra.id = d.registrar_account_id
             LEFT JOIN qd_account da ON da.id = d.dns_account_id
             WHERE d.is_stale = 0 AND d.expire_at IS NOT NULL AND d.expire_at != ''
             ORDER BY {$sortColumn} {$orderSql}, d.id ASC"
        );

        foreach ($domains as &$d) {
            $d['days_left'] = (int)floor((strtotime($d['expire_at']) - time()) / 86400);
        }

        echo View::render('monitor/index', [
            'domains' => $domains,
            'sort' => $sort,
            'order' => $orderSql,
        ]);
    }

    /** 检查证书到期（实时） */
    public function checkSsl(): void
    {
        $domainId = (int)Request::post('domain_id', 0);
        $domain = DB::one('SELECT * FROM qd_domain WHERE id = ?', [$domainId]);
        if (!$domain) {
            Response::fail('域名不存在');
        }
        $ssl = WhoisQuery::sslExpire($domain['domain']);
        Response::ok($ssl, '查询完成');
    }

    /* ==================== 批量刷新 WHOIS（复用 BulkTaskTrait） ==================== */

    private const BULK_SESSION_KEY = 'qd_bulk_whois';
    /** WHOIS 单次查询较慢，每批 3 个 + 内置间隔，降低被拒风险 */
    protected function bulkBatchSize(): int { return 3; }

    protected function bulkKey(): string { return self::BULK_SESSION_KEY; }

    protected function bulkAuditName(): string { return 'bulk_refresh_whois'; }

    /** 一批 WHOIS 刷新：查到期日，查到的写回；ok=成功更新，bad=未取到 */
    protected function bulkProcess(array $ids, array $ctx): array
    {
        $ok = 0; $bad = 0;
        foreach ($ids as $id) {
            $d = DB::one('SELECT id, domain, registrar FROM qd_domain WHERE id = ?', [$id]);
            if (!$d) { continue; }
            $whois = WhoisQuery::domainExpire($d['domain']);

            // 西部数码域名：注册局日期是西数接盘后自己续的，覆盖了会把「已过期」洗成未过期，
            // 到期日一律以代理商接口为准，这里只回填 WHOIS 注册商
            if ((string)$d['registrar'] === 'west') {
                if (!empty($whois['registrar'])) {
                    DB::exec('UPDATE qd_domain SET whois_registrar = ? WHERE id = ?', [$whois['registrar'], $d['id']]);
                }
                $ok++;
                usleep(200000);
                continue;
            }

            if (!empty($whois['expire_at'])) {
                DB::exec('UPDATE qd_domain SET expire_at = ?, whois_registrar = ? WHERE id = ?', [
                    $whois['expire_at'], $whois['registrar'] ?? '', $d['id'],
                ]);
                $ok++;
            } else {
                $bad++;
            }
            usleep(200000); // WHOIS 限速，避免被服务商拒绝
        }
        return ['ok' => $ok, 'bad' => $bad];
    }

    public function bulkStart(): void
    {
        $ids = DB::all('SELECT id FROM qd_domain WHERE is_stale = 0 ORDER BY id');
        $this->bulkBegin(array_column($ids, 'id'));
    }

    public function bulkStep(): void   { $this->bulkAdvance(); }
    public function bulkStatus(): void { $this->bulkProgress(); }
    public function bulkStop(): void   { $this->bulkHalt(); }

    /** 兼容旧入口：保留但改为提示走新的批量弹窗 */
    public function refreshAll(): void
    {
        Response::fail('请使用「批量刷新 WHOIS」进度弹窗执行');
    }
}
