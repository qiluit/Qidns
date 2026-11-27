<?php

declare(strict_types=1);

namespace QiDNS\Controllers;

use QiDNS\Core\Crypto;
use QiDNS\Core\DB;
use QiDNS\Core\Request;
use QiDNS\Core\Response;
use QiDNS\Core\View;
use QiDNS\Lib\Audit;
use QiDNS\Lib\Provider\ProviderFactory;

/**
 * 解析模板 + 跨平台同步
 */
class TemplateController
{
    public function index(): void
    {
        $templates = DB::all('SELECT * FROM qd_template ORDER BY id DESC');
        foreach ($templates as &$t) {
            $t['records'] = json_decode($t['content'], true) ?: [];
        }
        unset($t);

        // 应用模板的可选域名：需有 DNS 托管商且未失效（与两个菜单口径一致）
        $appDomains = DB::all(
            'SELECT d.id, d.domain, d.tags, d.dns_provider, da.name AS dns_account_name
             FROM qd_domain d
             LEFT JOIN qd_account da ON da.id = d.dns_account_id
             WHERE d.dns_provider != "" AND d.is_stale = 0
             ORDER BY d.domain'
        );
        // 标签只从「可选域名」中收集，保证标签与可勾选域名一一对应
        $tagSet = [];
        foreach ($appDomains as &$d) {
            $d['tag_list'] = [];
            foreach (explode(',', (string)$d['tags']) as $tag) {
                $tag = trim($tag);
                if ($tag !== '') {
                    $d['tag_list'][] = $tag;
                    $tagSet[$tag] = true;
                }
            }
        }
        unset($d);
        $appTags = array_keys($tagSet);
        sort($appTags, SORT_STRING | SORT_FLAG_CASE);

        echo View::render('template/index', [
            'templates' => $templates,
            'appDomains' => $appDomains,
            'appTags' => $appTags,
        ]);
    }

    public function save(): void
    {
        $id = (int)Request::post('id', 0);
        $name = trim((string)Request::post('name', ''));
        $remark = trim((string)Request::post('remark', ''));
        $recordsJson = (string)Request::post('records', '');

        if ($name === '') {
            Response::fail('请填写模板名');
        }
        $records = json_decode($recordsJson, true);
        if (!is_array($records) || empty($records)) {
            Response::fail('模板记录格式错误');
        }

        // 兼容前端逐条录入：字段缺失时给默认值，避免落库为空
        foreach ($records as &$r) {
            if (!is_array($r)) { $r = []; }
            $r['rr']    = trim((string)($r['rr'] ?? '')) ?: '@';
            $r['type']  = strtoupper(trim((string)($r['type'] ?? ''))) ?: 'A';
            $r['value'] = trim((string)($r['value'] ?? ''));
            $r['ttl']   = max(1, (int)($r['ttl'] ?? 600));
            $r['line']  = trim((string)($r['line'] ?? '')) ?: '默认';
            $r['mx']    = max(0, (int)($r['mx'] ?? 0));
        }
        unset($r);
        $records = array_values(array_filter($records, function ($r) {
            return $r['value'] !== '';
        }));
        if (empty($records)) {
            Response::fail('模板记录格式错误');
        }

        $content = json_encode($records, JSON_UNESCAPED_UNICODE);
        if ($id > 0) {
            DB::exec('UPDATE qd_template SET name=?, content=?, remark=?, updated_at=? WHERE id=?',
                [$name, $content, $remark, date('Y-m-d H:i:s'), $id]);
            Audit::log('update_template', $name);
        } else {
            DB::insert('INSERT INTO qd_template (name, content, remark, created_at, updated_at) VALUES (?,?,?,?,?)',
                [$name, $content, $remark, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            Audit::log('add_template', $name);
        }
        Response::ok(null, '模板已保存');
    }

    public function delete(): void
    {
        $id = (int)Request::post('id', 0);
        $t = DB::one('SELECT * FROM qd_template WHERE id = ?', [$id]);
        if ($t) {
            DB::exec('DELETE FROM qd_template WHERE id = ?', [$id]);
            Audit::log('delete_template', $t['name']);
        }
        Response::ok(null, '已删除');
    }

    /** 应用模板：将模板记录套用到指定域名（支持跨平台多选） */
    public function apply(): void
    {
        $templateId = (int)Request::post('template_id', 0);
        $domainIds = (array)Request::post('domain_ids', []);
        $dryRun = (bool)Request::post('dry_run', false);

        $template = DB::one('SELECT * FROM qd_template WHERE id = ?', [$templateId]);
        if (!$template) {
            Response::fail('模板不存在');
        }
        $records = json_decode($template['content'], true) ?: [];
        if (empty($records)) {
            Response::fail('模板里没有记录');
        }
        if (empty($domainIds)) {
            Response::fail('请选择要应用的域名');
        }

        $results = [];
        foreach ($domainIds as $domainId) {
            $domainId = (int)$domainId;
            $domain = DB::one('SELECT * FROM qd_domain WHERE id = ?', [$domainId]);
            if (!$domain) {
                continue;
            }
            $item = [
                'domain' => $domain['domain'],
                'provider' => (string)($domain['dns_provider'] ?? ''),
                'created' => 0, 'skipped' => 0, 'failed' => 0,
                'status' => 'success', 'error' => '',
            ];

            if (empty($domain['dns_provider']) || empty($domain['dns_account_id'])) {
                $results[] = ['status' => 'error', 'error' => '没有生效的解析平台（NS 未指向我方已接入账号）'] + $item;
                continue;
            }
            $account = DB::one('SELECT * FROM qd_account WHERE id = ?', [(int)$domain['dns_account_id']]);
            if (!$account) {
                $results[] = ['status' => 'error', 'error' => '托管账号不存在（可能已删除）'] + $item;
                continue;
            }
            $cred = json_decode(Crypto::decrypt((string)$account['credentials']), true) ?: [];
            $p = ProviderFactory::make($account['provider'], $cred);

            // 先读线上现有记录，按「同名同类型同值同线路」判重：
            // 模板重复应用 / 域名已有同名记录时跳过，而不是让整批失败。
            $existing = [];
            try {
                foreach ($p->listRecords($domain['domain']) as $row) {
                    $existing[self::recordKey($row)] = true;
                }
            } catch (\Throwable $e) {
                // 读不到就当作没有，靠创建异常兜底，不影响主流程
            }

            if ($dryRun) {
                foreach ($records as $r) {
                    if (isset($existing[self::recordKey($r)])) {
                        $item['skipped']++;
                    } else {
                        $item['created']++;      // 预览里 created 表示「将新增」
                    }
                }
                $item['status'] = 'dry';
                $results[] = $item;
                continue;
            }

            // 真正执行：逐条创建，单条失败不影响其余记录
            foreach ($records as $r) {
                $key = self::recordKey($r);
                if (isset($existing[$key])) {
                    $item['skipped']++;
                    continue;
                }
                try {
                    $remoteId = $p->createRecord($domain['domain'], [
                        'rr' => $r['rr'] ?? '@',
                        'type' => $r['type'] ?? 'A',
                        'value' => $r['value'] ?? '',
                        'ttl' => $r['ttl'] ?? 600,
                        'line' => $r['line'] ?? '默认',
                        'mx' => $r['mx'] ?? 0,
                    ]);
                } catch (\Throwable $e) {
                    $item['failed']++;
                    if ($item['error'] === '') {
                        $item['error'] = $e->getMessage();
                    }
                    continue;
                }
                DB::insert(
                    'INSERT INTO qd_record (account_id, domain_id, domain, rr, type, value, ttl, line, mx, status, remote_id, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $domain['dns_account_id'], $domainId, $domain['domain'],
                        $r['rr'] ?? '@', $r['type'] ?? 'A', $r['value'] ?? '',
                        $r['ttl'] ?? 600, $r['line'] ?? '默认', $r['mx'] ?? 0,
                        1, $remoteId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
                    ]
                );
                $existing[$key] = true;
                $item['created']++;
            }

            if ($item['failed'] > 0) {
                $item['status'] = $item['created'] > 0 ? 'partial' : 'error';
            } else {
                $item['status'] = $item['created'] > 0 ? 'success' : 'skip';
            }
            $results[] = $item;
        }

        Audit::log('apply_template', $template['name'], $results);
        Response::ok($results, $dryRun ? '预览完成' : '应用完成');
    }

    /**
     * 记录去重键：主机记录 + 类型 + 值 + 线路（MX 再带优先级）。
     * 各服务商的 listRecords 已归一到同一套字段，可跨平台对比。
     */
    private static function recordKey(array $r): string
    {
        $rr = strtolower(trim((string)($r['rr'] ?? '')));
        if ($rr === '') {
            $rr = '@';
        }
        $type = strtoupper(trim((string)($r['type'] ?? 'A')));
        $value = trim((string)($r['value'] ?? ''));
        if ($type === 'TXT') {
            $value = trim($value, "\"'");   // 有的服务商给 TXT 值加引号
        }
        $line = trim((string)($r['line'] ?? '')) ?: '默认';
        $mx = $type === 'MX' ? (int)($r['mx'] ?? 0) : 0;
        return $rr . '|' . $type . '|' . strtolower($value) . '|' . $line . '|' . $mx;
    }
}
