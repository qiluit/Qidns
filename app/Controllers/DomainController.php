<?php

declare(strict_types=1);

namespace QiDNS\Controllers;

use QiDNS\Core\Crypto;
use QiDNS\Core\DB;
use QiDNS\Core\Request;
use QiDNS\Core\Response;
use QiDNS\Core\View;
use QiDNS\Lib\Audit;
use QiDNS\Lib\BulkTaskTrait;
use QiDNS\Lib\DomainFilter;
use QiDNS\Lib\OwnershipResolver;
use QiDNS\Lib\Provider\ProviderFactory;
use QiDNS\Lib\TagStore;
use QiDNS\Lib\Whois\NsResolver;
use QiDNS\Lib\Whois\WhoisQuery;
use QiDNS\Lib\ZoneMap;

class DomainController
{
    use BulkTaskTrait;
    /** 解析管理视图用到的「在用 DNS」服务商选项（与 HostingController 口径一致） */
    private const DNS_PROVIDERS = [
        'aliyun' => '阿里云',
        'dnspod' => '腾讯云 DNSPod',
        'west' => '西部数码',
        'other' => '其他 / 第三方 DNS',
    ];

    public function index(): void
    {
        $view = (string)Request::get('view', 'all');
        if ($view === 'hosting') {
            $this->hostingView();
            return;
        }

        $accountId = (int)Request::get('account_id', 0);   // 按接入商（账号）检索
        $keyword = trim((string)Request::get('keyword', '')); // 关键词
        $tag = trim((string)Request::get('tag', ''));        // 标签
        $suffix = strtolower(trim((string)Request::get('suffix', ''))); // 后缀（如 com / com.cn）
        $showStale = (int)Request::get('show_stale', 0) === 1; // 是否仅查看失效域名（复核/恢复用）
        $sort = (string)Request::get('sort', 'id');          // 排序字段
        $order = strtolower((string)Request::get('order', 'desc')); // asc|desc

        // 排序白名单（防 SQL 注入）
        $sortMap = [
            'id' => 'd.id',
            'domain' => 'd.domain',
            'registrar' => 'd.registrar',
            'dns' => 'd.dns_provider',
            'expire' => 'd.expire_at',
        ];
        $sortColumn = $sortMap[$sort] ?? 'd.id';
        $order = $order === 'asc' ? 'ASC' : 'DESC';

        $sql = 'SELECT d.*,
                       ra.name AS registrar_account_name,
                       da.name AS dns_account_name
                FROM qd_domain d
                LEFT JOIN qd_account ra ON ra.id = d.registrar_account_id
                LEFT JOIN qd_account da ON da.id = d.dns_account_id
                WHERE 1=1';
        $params = [];

        // show_stale=1 时「仅看失效域名」；否则默认排除失效域名（疑似未注册/已释放）
        if ($showStale) {
            $sql .= ' AND d.is_stale = 1';
        } else {
            $sql .= ' AND d.is_stale = 0';
        }

        // 后缀筛选：按域名结尾匹配（单段/复合后缀通用）
        if ($suffix !== '' && preg_match('/^[a-z0-9][a-z0-9.-]*$/', $suffix)) {
            $sql .= ' AND LOWER(d.domain) LIKE ?';
            $params[] = '%.' . $suffix;
        }

        // 按接入商检索：注册商精确匹配账号；DNS 侧按「该账号下确实有这个 zone」匹配。
        // 不按「谁在生效」匹配——一个域名可能挂在这家却没生效，用户仍需在账号视图里看到它。
        if ($accountId > 0) {
            $sql .= ' AND (d.registrar_account_id = ?
                           OR EXISTS (SELECT 1 FROM qd_domain_zone z WHERE z.domain_id = d.id AND z.account_id = ?))';
            $params[] = $accountId;
            $params[] = $accountId;
        }

        // 关键词检索：域名模糊匹配（也匹配备注）
        if ($keyword !== '') {
            $sql .= ' AND (d.domain LIKE ? OR d.remark LIKE ?)';
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // 标签检索：tags 字段精确匹配单个标签
        if ($tag !== '') {
            $sql .= ' AND FIND_IN_SET(?, d.tags)';
            $params[] = $tag;
        }

        // 到期日排序时，把「无到期日」的域名固定沉底：
        // MySQL 中 NULL 在 ASC 时排最前，不处理会让正序看到的都是没到期日的域名。
        $nullLast = $sort === 'expire'
            ? "(d.expire_at IS NULL OR d.expire_at = '') ASC, "
            : '';
        $sql .= ' ORDER BY ' . $nullLast . $sortColumn . ' ' . $order . ', d.id DESC';

        $domains = DB::all($sql, $params);
        $zoneMap = ZoneMap::mapByDomainIds(array_column($domains, 'id'));
        foreach ($domains as &$d) {
            $d['record_count'] = (int)DB::val('SELECT COUNT(*) FROM qd_record WHERE domain_id = ?', [$d['id']]);
            $d['tag_list'] = $d['tags'] !== '' ? explode(',', $d['tags']) : [];

            // 「挂在哪几家平台」：生效那家排最前，其余按账号稳定排序
            $eff = (string)$d['effective_dns'];
            $zones = $zoneMap[(int)$d['id']] ?? [];
            usort($zones, static function (array $a, array $b) use ($eff): int {
                $rank = static function (array $z) use ($eff): int {
                    return $z['provider'] === $eff ? 0 : 1;
                };
                return $rank($a) === $rank($b)
                    ? ((int)$a['account_id'] <=> (int)$b['account_id'])
                    : ($rank($a) <=> $rank($b));
            });
            $d['zones'] = $zones;
        }
        unset($d);
        $accounts = DB::all('SELECT id, name, provider FROM qd_account ORDER BY id DESC');

        // 全部已用标签（以字典为准）
        $tagList = $this->collectTags();
        $tagColors = TagStore::colorMap();

        echo View::render('domain/index', [
            'view' => 'all',
            'domains' => $domains,
            'accounts' => $accounts,
            'accountId' => $accountId,
            'keyword' => $keyword,
            'tag' => $tag,
            'tagList' => $tagList,
            'tagColors' => $tagColors,
            'suffix' => $suffix,
            'suffixOptions' => DomainFilter::suffixOptions(),
            'sort' => $sort,
            'order' => $order,
            'showStale' => $showStale,
            'staleCount' => (int)DB::val('SELECT COUNT(*) FROM qd_domain WHERE is_stale = 1'),
        ]);
    }

    /**
     * 解析管理视图（原 HostingController）：按注册局 NS 实证的「在用 DNS」分组。
     * 逻辑整体搬入本控制器，HostingController 仅做兼容重定向，避免两处重复维护。
     */
    private function hostingView(): void
    {
        $provider = trim((string)Request::get('provider', ''));
        $keyword = trim((string)Request::get('keyword', ''));
        $suffix = strtolower(trim((string)Request::get('suffix', '')));
        $ownership = trim((string)Request::get('ownership', '')); // owned|unknown|''
        $sort = (string)Request::get('sort', 'domain');
        $order = strtolower((string)Request::get('order', 'asc'));

        $sortMap = ['domain' => 'd.domain', 'expire' => 'd.expire_at'];
        $sortColumn = $sortMap[$sort] ?? 'd.domain';
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
                WHERE d.effective_dns != "" AND d.is_stale = 0';
        $params = [];

        if ($provider !== '' && isset(self::DNS_PROVIDERS[$provider])) {
            $sql .= ' AND d.effective_dns = ?';
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
        if ($ownership === 'owned') {
            $sql .= ' AND d.registrar != ""';
        } elseif ($ownership === 'unknown') {
            $sql .= ' AND d.registrar = ""';
        }
        $sql .= ' ORDER BY ' . $nullLast . $sortColumn . ' ' . $orderSql . ', d.id ASC';

        $domains = DB::all($sql, $params);
        foreach ($domains as &$d) {
            $d['days_left'] = $d['expire_at']
                ? (int)floor((strtotime($d['expire_at']) - time()) / 86400)
                : null;
            $d['record_count'] = (int)DB::val('SELECT COUNT(*) FROM qd_record WHERE domain_id = ?', [$d['id']]);
            $d['tag_list'] = $d['tags'] !== '' ? explode(',', $d['tags']) : [];
        }
        unset($d);

        $groups = [];
        foreach ($domains as $d) {
            $groups[$d['effective_dns']][] = $d;
        }

        $noNs = DB::all(
            "SELECT d.domain, d.registrar, d.expire_at
             FROM qd_domain d
             WHERE d.effective_dns = '' AND d.is_stale = 0
             ORDER BY d.domain ASC"
        );

        $stat = [
            'total' => (int)DB::val('SELECT COUNT(*) FROM qd_domain WHERE effective_dns != "" AND is_stale = 0'),
            'owned' => (int)DB::val('SELECT COUNT(*) FROM qd_domain WHERE effective_dns != "" AND is_stale = 0 AND registrar != ""'),
            'unknown' => (int)DB::val('SELECT COUNT(*) FROM qd_domain WHERE effective_dns != "" AND is_stale = 0 AND registrar = ""'),
            'no_ns' => count($noNs),
            'stale' => (int)DB::val('SELECT COUNT(*) FROM qd_domain WHERE is_stale = 1'),
        ];

        echo View::render('domain/hosting', [
            'view' => 'hosting',
            'groups' => $groups,
            'providers' => self::DNS_PROVIDERS,
            'provider' => $provider,
            'keyword' => $keyword,
            'suffix' => $suffix,
            'suffixOptions' => DomainFilter::suffixOptions(),
            'ownership' => $ownership,
            'sort' => $sort,
            'order' => $orderSql,
            'stat' => $stat,
            'noNs' => $noNs,
            'tagColors' => TagStore::colorMap(),
        ]);
    }

    /** 收集所有已用标签（以标签字典为准，含尚未使用的标签；域名上残留的会补登记） */
    private function collectTags(): array
    {
        return TagStore::names();
    }

    /** 更新域名标签 */
    public function updateTags(): void
    {
        $id = (int)Request::post('id', 0);
        $tags = trim((string)Request::post('tags', ''));
        $domain = DB::one('SELECT * FROM qd_domain WHERE id = ?', [$id]);
        if (!$domain) {
            Response::fail('域名不存在');
        }

        $normalized = implode(',', TagStore::normalizeList($tags));

        DB::exec('UPDATE qd_domain SET tags=?, updated_at=? WHERE id=?',
            [$normalized, date('Y-m-d H:i:s'), $id]);
        // 打上去的新标签若字典里没有，顺手登记，标签管理页才能看到并改名/删除
        TagStore::importFromDomains();

        Audit::log('update_tags', $domain['domain'], ['tags' => $normalized]);
        Response::ok(['tags' => $normalized], '标签已更新');
    }

    /** 从某个域名上移除单个标签（域名列表双击标签触发） */
    public function removeTag(): void
    {
        $id = (int)Request::post('domain_id', 0);
        $tag = trim((string)Request::post('tag', ''));
        $domain = DB::one('SELECT * FROM qd_domain WHERE id = ?', [$id]);
        if (!$domain) {
            Response::fail('域名不存在');
        }
        if ($tag === '') {
            Response::fail('标签不能为空');
        }

        $parts = TagStore::normalizeList((string)$domain['tags']);
        $left = array_values(array_filter($parts, static function ($t) use ($tag) {
            return $t !== $tag;
        }));
        if (count($left) === count($parts)) {
            Response::fail('该域名没有标签「' . $tag . '」');
        }

        $normalized = implode(',', $left);
        DB::exec('UPDATE qd_domain SET tags=?, updated_at=? WHERE id=?',
            [$normalized, date('Y-m-d H:i:s'), $id]);

        Audit::log('update_tags', $domain['domain'], ['removed' => $tag, 'tags' => $normalized]);
        Response::ok(['tags' => $normalized, 'removed' => $tag], '已从该域名移除「' . $tag . '」');
    }

    /**
     * 手动刷新归属判定（单个域名或全量）。
     * 先重探 NS（更新在用 DNS），再结合所有账号 API 权限重算失效。
     * 失效可自愈：域名恢复注册 / NS 生效 / 重新纳入我方账号后，刷新即自动解除。
     */
    /** WHOIS 详情查询（域名列浮层）：直连注册局 RDAP/WHOIS，不经过解析商 API */
    public function whoisLookup(): void
    {
        $id = (int)Request::post('domain_id', 0);
        $domain = DB::one('SELECT domain FROM qd_domain WHERE id = ?', [$id]);
        if (!$domain) {
            Response::fail('域名不存在');
        }
        $info = WhoisQuery::whoisInfo($domain['domain']);
        Response::ok($info, '查询完成');
    }

    /* ==================== 批量归属判定（复用 BulkTaskTrait） ==================== */

    private const BULK_SESSION_KEY = 'qd_bulk_refresh';

    protected function bulkKey(): string { return self::BULK_SESSION_KEY; }

    protected function bulkAuditName(): string { return 'bulk_refresh_ns'; }

    /** 一批归属判定：NS 实证探测 + 用启动时缓存的证据判定 */
    protected function bulkProcess(array $ids, array $ctx): array
    {
        NsResolver::refreshAll($ids);
        $r = OwnershipResolver::applyEvidence(
            ['owned' => $ctx['owned'] ?? [], 'zones' => $ctx['zones'] ?? [], 'failed' => []],
            $ids
        );
        // NS 可能刚刚变了，生效指针跟着重算（决定解析记录页默认操作哪一家）
        ZoneMap::refreshPointer($ids);
        return ['ok' => (int)($r['mine'] ?? 0), 'bad' => (int)($r['stale'] ?? 0)];
    }

    /** 启动：预拉一次证据（后续每批复用），再交给通用骨架建任务 */
    public function bulkStart(): void
    {
        // 单条判定：目标集合只含该域名，复用同一套骨架（前端表现为单点进度）
        $targetId = (int)Request::post('target_id', 0);
        if ($targetId > 0) {
            $row = DB::one('SELECT id FROM qd_domain WHERE id = ?', [$targetId]);
            if (!$row) {
                Response::fail('域名不存在');
            }
            $ids = [(int)$row['id']];
        } else {
            $ids = DB::all('SELECT id FROM qd_domain ORDER BY id');
            $ids = array_map('intval', array_column($ids, 'id'));
        }

        $ev = OwnershipResolver::collectEvidence();
        if (!empty($ev['failed'])) {
            Response::fail('部分账号接口不可用，已取消判定以免误判：' . implode('；', $ev['failed']));
        }
        $this->bulkBegin($ids, ['owned' => $ev['owned'] ?? [], 'zones' => $ev['zones'] ?? []]);
    }

    public function bulkStep(): void   { $this->bulkAdvance(); }
    public function bulkStatus(): void { $this->bulkProgress(); }
    public function bulkStop(): void   { $this->bulkHalt(); }

    /**
     * 拉取某域名的解析记录并缓存（走 DNS 托管商 API）
     *
     * 一个域名可能挂在多家平台，所以支持 account_id 指定同步哪一家：
     * 不传时按「生效指针」走；本地缓存按 (domain_id, account_id) 隔离，多平台互不覆盖。
     */
    public function syncRecords(): void
    {
        $domainId = (int)Request::post('domain_id', 0);
        $accountId = (int)Request::post('account_id', 0);
        $domain = DB::one('SELECT * FROM qd_domain WHERE id = ?', [$domainId]);
        if (!$domain) {
            Response::fail('域名不存在');
        }

        $account = ZoneMap::resolveAccount($domainId, $accountId, (int)$domain['dns_account_id']);
        if (!$account) {
            Response::fail($accountId > 0
                ? '该域名不在这个账号的解析平台下'
                : '该域名挂在多家平台或未关联解析平台，请从「DNS 托管」列指定要同步哪一家');
        }
        $targetAccountId = (int)$account['id'];
        $cred = json_decode(Crypto::decrypt($account['credentials']), true) ?: [];
        $p = ProviderFactory::make($account['provider'], $cred);
        $logical = ProviderFactory::logicalProvider((string)$account['provider']);

        $records = $p->listRecords($domain['domain']);
        $now = date('Y-m-d H:i:s');

        // 快照当前记录（变更前）——按平台隔离，别家的缓存不动
        $oldRecords = DB::all('SELECT * FROM qd_record WHERE domain_id = ? AND account_id = ?', [$domainId, $targetAccountId]);
        \QiDNS\Lib\Snapshot::capture($domainId, $domain['domain'], $logical, $oldRecords, '同步前快照');

        DB::begin();
        try {
            DB::exec('DELETE FROM qd_record WHERE domain_id = ? AND account_id = ?', [$domainId, $targetAccountId]);
            foreach ($records as $r) {
                DB::insert(
                    'INSERT INTO qd_record (account_id, domain_id, domain, rr, type, value, ttl, line, mx, status, remote_id, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $targetAccountId, $domainId, $domain['domain'],
                        $r['rr'], $r['type'], $r['value'], $r['ttl'], $r['line'], $r['mx'],
                        $r['status'], $r['remote_id'], $now, $now,
                    ]
                );
            }
            DB::exec('UPDATE qd_domain SET updated_at=? WHERE id=?', [$now, $domainId]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            Response::fail('同步记录失败: ' . $e->getMessage());
        }

        Audit::log('sync_records', $domain['domain'], [
            'count' => count($records),
            'account' => $account['name'],
            'effective' => ZoneMap::isEffective($domainId, $targetAccountId, (string)$domain['effective_dns']),
        ]);
        Response::ok([
            'count' => count($records),
            'account' => $account['name'],
            'effective' => ZoneMap::isEffective($domainId, $targetAccountId, (string)$domain['effective_dns']),
        ], '同步成功');
    }

    /** 查询域名到期信息 */
    public function checkExpire(): void
    {
        $domainId = (int)Request::post('domain_id', 0);
        $domain = DB::one('SELECT * FROM qd_domain WHERE id = ?', [$domainId]);
        if (!$domain) {
            Response::fail('域名不存在');
        }

        $whois = WhoisQuery::domainExpire($domain['domain']);

        // 西部数码域名：注册局那边的到期日是西数接盘后自己续的，不能用来覆盖接口时限，
        // 否则已过期（客户未续费）的域名会被显示成未过期。只回填 WHOIS 注册商。
        $isWest = (string)$domain['registrar'] === 'west';

        $sets = [];
        $params = [];
        if ($whois['expire_at'] !== '' && !$isWest) {
            $sets[] = 'expire_at=?';
            $params[] = $whois['expire_at'];
        }
        if ($whois['registrar'] !== '') {
            $sets[] = 'whois_registrar=?';
            $params[] = $whois['registrar'];
        }
        if ($sets) {
            $params[] = $domainId;
            DB::exec('UPDATE qd_domain SET ' . implode(',', $sets) . ' WHERE id=?', $params);
        }

        if ($isWest) {
            // 回显库里（代理商接口）的到期日，保持与「DNS 管理」口径一致
            $expire = (string)$domain['expire_at'];
            Response::ok([
                'expire_at' => $expire,
                'days_left' => $expire !== '' ? (int)floor((strtotime($expire) - time()) / 86400) : 0,
                'registrar' => $whois['registrar'],
            ], '西部数码域名以代理商接口时限为准，未采用注册局日期');
        }

        Response::ok([
            'expire_at' => $whois['expire_at'],
            'days_left' => $whois['days_left'],
            'registrar' => $whois['registrar'],
        ], '查询完成');
    }

    /**
     * 修改域名在注册商处的 NS（高风险写操作）。
     * 安全约束：仅我方确认持有（registrar 非空、未失效）的域名可改，
     * 凭证取自该域名的注册商账号，写入审计日志。成功后 NS 需 24-48h 全球生效。
     */
    public function setNameservers(): void
    {
        $id = (int)Request::post('id', 0);
        $nsRaw = trim((string)Request::post('ns', ''));
        $domain = DB::one('SELECT * FROM qd_domain WHERE id = ?', [$id]);
        if (!$domain) {
            Response::fail('域名不存在');
        }

        // 仅我方注册商持有的域名允许改 NS（排除客户托管域名 / 灰色 / 失效）
        if ((string)$domain['registrar'] === '') {
            Response::fail('该域名不在我方注册商持有列表（WHOIS 注册商≠我方账号），不能改 NS');
        }
        if ((int)$domain['is_stale'] === 1) {
            Response::fail('该域名已判定失效，不能改 NS');
        }
        $accountId = (int)$domain['registrar_account_id'];
        if ($accountId <= 0) {
            Response::fail('该域名未关联注册商账号，无法改 NS');
        }
        $account = DB::one('SELECT * FROM qd_account WHERE id = ?', [$accountId]);
        if (!$account) {
            Response::fail('注册商账号不存在');
        }

        // NS 列表：支持换行/逗号/空格分隔，2~6 个，严格主机名格式
        $ns = $this->parseNsList($nsRaw);
        if (count($ns) < 2 || count($ns) > 6) {
            Response::fail('NS 数量需为 2~6 个（当前 ' . count($ns) . ' 个）');
        }

        $cred = json_decode(Crypto::decrypt($account['credentials']), true) ?: [];
        $p = ProviderFactory::make($account['provider'], $cred);
        $before = [];
        try {
            $before = $p->getNameservers($domain['domain']);
            $p->setNameservers($domain['domain'], $ns);
        } catch (\Throwable $e) {
            Audit::log('set_ns_fail', $domain['domain'], ['error' => $e->getMessage(), 'ns' => $ns]);
            Response::fail('修改 NS 失败：' . $e->getMessage());
        }

        Audit::log('set_ns', $domain['domain'], [
            'registrar' => $domain['registrar'],
            'account_id' => $accountId,
            'before' => $before,
            'after' => $ns,
        ]);
        Response::ok([
            'domain' => $domain['domain'],
            'ns' => $ns,
            'before' => $before,
        ], 'NS 修改已提交，通常 24~48 小时全球生效');
    }

    /** 读取域名在注册商处登记的当前 NS（弹窗预填用） */
    public function currentNs(): void
    {
        $id = (int)Request::post('id', 0);
        $domain = DB::one('SELECT * FROM qd_domain WHERE id = ?', [$id]);
        if (!$domain) {
            Response::fail('域名不存在');
        }
        if ((string)$domain['registrar'] === '' || (int)$domain['registrar_account_id'] <= 0) {
            Response::ok(['ns' => [], 'hint' => '该域名非我方注册商持有，无法从注册商读取 NS']);
            return;
        }
        $account = DB::one('SELECT * FROM qd_account WHERE id = ?', [(int)$domain['registrar_account_id']]);
        if (!$account) {
            Response::ok(['ns' => [], 'hint' => '注册商账号不存在']);
            return;
        }
        $cred = json_decode(Crypto::decrypt($account['credentials']), true) ?: [];
        $p = ProviderFactory::make($account['provider'], $cred);
        try {
            $ns = $p->getNameservers($domain['domain']);
        } catch (\Throwable $e) {
            Response::fail('读取当前 NS 失败：' . $e->getMessage());
        }
        Response::ok(['ns' => $ns ?? []]);
    }

    /** 解析 NS 输入：换行/逗号/分号/空格分隔，去尾点，校验主机名格式 */
    private function parseNsList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $s) {
            $s = rtrim(trim($s), '.');
            if ($s === '') {
                continue;
            }
            // 合法域名标签：字母数字与连字符，点分隔，每段非空且不以连字符开头/结尾
            if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $s)) {
                Response::fail('NS 格式不合法：' . $s);
            }
            if (!in_array(strtolower($s), array_map('strtolower', $out), true)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** 删除域名（仅删本地缓存，不动线上） */
    public function delete(): void
    {
        $id = (int)Request::post('id', 0);
        $domain = DB::one('SELECT * FROM qd_domain WHERE id = ?', [$id]);
        if (!$domain) {
            Response::fail('域名不存在');
        }
        // 关联表、记录缓存、快照必须一起清，否则留下指向空域名的孤儿行
        DB::begin();
        try {
            ZoneMap::purgeDomain($id);
            DB::exec('DELETE FROM qd_record WHERE domain_id = ?', [$id]);
            DB::exec('DELETE FROM qd_snapshot WHERE domain_id = ?', [$id]);
            DB::exec('DELETE FROM qd_domain WHERE id = ?', [$id]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            Response::fail('删除失败: ' . $e->getMessage());
        }
        Audit::log('delete_domain', $domain['domain']);
        Response::ok(null, '已删除');
    }
}
