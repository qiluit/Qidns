<?php

declare(strict_types=1);

namespace QiDNS\Controllers;

use QiDNS\Core\Crypto;
use QiDNS\Core\DB;
use QiDNS\Core\Request;
use QiDNS\Core\Response;
use QiDNS\Core\View;
use QiDNS\Lib\Audit;
use QiDNS\Lib\OwnershipResolver;
use QiDNS\Lib\Provider\DnspodUnifiedProvider;
use QiDNS\Lib\Provider\ProviderFactory;
use QiDNS\Lib\Whois\NsResolver;
use QiDNS\Lib\ZoneMap;

class AccountController
{
    // 腾讯云 DNSPod 的两套 API 已整合为一个服务商，新增/编辑统一选 dnspod
    private const PROVIDERS = [
        'dnspod' => '腾讯云 DNSPod（传统 Token + API 3.0）',
        'aliyun' => '阿里云 DNS',
        'west' => '西部数码',
    ];

    /** 同步任务阶段：顺序即执行顺序，from/to 是该阶段在总进度条上占的百分比区间 */
    private const SYNC_STEPS = [
        'zones'       => ['label' => '拉取服务商域名列表', 'from' => 0,  'to' => 10],
        'write'       => ['label' => '写入域名库',        'from' => 10, 'to' => 30],
        'owned'       => ['label' => '拉取注册商持有列表', 'from' => 30, 'to' => 40],
        'apply_owned' => ['label' => '更新持有与到期信息', 'from' => 40, 'to' => 50],
        'ns'          => ['label' => '刷新在用 NS',       'from' => 50, 'to' => 90],
        'evidence'    => ['label' => '汇总各账号证据',     'from' => 90, 'to' => 95],
        'apply_stale' => ['label' => '重算域名归属',       'from' => 95, 'to' => 100],
    ];

    /** 单步处理量：域名写入/持有/归属按行处理，NS 探测按域名（DNS 查询慢，步子更小） */
    private const SYNC_BATCH_ROWS = 40;
    private const SYNC_BATCH_NS = 8;
    private const SYNC_BATCH_STALE = 200;

    public function index(): void
    {
        $accounts = DB::all('SELECT * FROM qd_account ORDER BY id DESC');
        ZoneMap::ensure();
        foreach ($accounts as &$a) {
            // 持有（注册商）+ 该账号下确实存在的 zone 去重计数
            $a['domain_count'] = (int)DB::val(
                'SELECT COUNT(*) FROM qd_domain d
                  WHERE d.registrar_account_id = ?
                     OR EXISTS (SELECT 1 FROM qd_domain_zone z WHERE z.domain_id = d.id AND z.account_id = ?)',
                [$a['id'], $a['id']]
            );
        }
        unset($a);
        echo View::render('account/index', [
            'accounts' => $accounts,
            'providers' => self::PROVIDERS,
        ]);
    }

    public function add(): void
    {
        echo View::render('account/form', [
            'account' => null,
            'providers' => self::PROVIDERS,
            'cred' => [],
        ]);
    }

    public function edit(): void
    {
        $id = (int)Request::get('id', 0);
        $account = DB::one('SELECT * FROM qd_account WHERE id = ?', [$id]);
        if (!$account) {
            Response::redirect('/index.php?r=Account');
        }
        $cred = json_decode(Crypto::decrypt($account['credentials']), true) ?: [];
        // 隐藏敏感值，仅保留字段名提示「已配置」由视图按 cred key 判断
        $masked = [];
        foreach ($cred as $k => $v) {
            $masked[$k] = '';
        }
        echo View::render('account/form', [
            'account' => $account,
            'providers' => self::PROVIDERS,
            'cred' => $masked,
        ]);
    }

    public function save(): void
    {
        $id = (int)Request::post('id', 0);
        $name = trim((string)Request::post('name', ''));
        $provider = (string)Request::post('provider', '');
        $remark = trim((string)Request::post('remark', ''));

        if ($name === '' || !isset(self::PROVIDERS[$provider])) {
            Response::fail('请填写账号名并选择服务商');
        }
        $credentials = $this->collectCredentials($provider);

        // 编辑：留空字段用旧值补全
        $oldCred = [];
        if ($id > 0) {
            $old = DB::one('SELECT * FROM qd_account WHERE id = ?', [$id]);
            if ($old) {
                $oldCred = json_decode(Crypto::decrypt($old['credentials']), true) ?: [];
                foreach ($credentials as $k => $v) {
                    if ($v === '' && isset($oldCred[$k])) {
                        $credentials[$k] = $oldCred[$k];
                    }
                }
            }
        }

        if (!$this->validateCredentials($provider, $credentials)) {
            Response::fail('凭证填写不完整（DNSPod 至少填写一套：传统 Token 或 腾讯云 API 3.0 的 SecretId+SecretKey）');
        }

        $encrypted = Crypto::encrypt(json_encode($credentials, JSON_UNESCAPED_UNICODE));

        if ($id > 0) {
            DB::exec(
                'UPDATE qd_account SET name=?, provider=?, credentials=?, remark=?, updated_at=? WHERE id=?',
                [$name, $provider, $encrypted, $remark, date('Y-m-d H:i:s'), $id]
            );
            Audit::log('update_account', $name);
        } else {
            $id = DB::insert(
                'INSERT INTO qd_account (name, provider, credentials, remark, created_at, updated_at)
                 VALUES (?,?,?,?,?,?)',
                [$name, $provider, $encrypted, $remark, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
            );
            Audit::log('add_account', $name);
        }

        Response::ok(['id' => $id], '保存成功');
    }

    public function delete(): void
    {
        $id = (int)Request::post('id', 0);
        $account = DB::one('SELECT * FROM qd_account WHERE id = ?', [$id]);
        if (!$account) {
            Response::fail('账号不存在');
        }
        DB::begin();
        try {
            DB::exec('UPDATE qd_domain SET registrar_account_id=0, registrar="" WHERE registrar_account_id=?', [$id]);

            // 清掉该账号的 zone 关联：同一域名若还挂在别家，域名本身保留
            ZoneMap::purgeAccount($id);
            ZoneMap::refreshPointer();

            // 清理彻底无主的孤儿域名（没有注册商归属，也没有任何平台的 zone）
            $orphans = DB::all(
                'SELECT d.id FROM qd_domain d
                  WHERE d.registrar = ""
                    AND NOT EXISTS (SELECT 1 FROM qd_domain_zone z WHERE z.domain_id = d.id)'
            );
            foreach ($orphans as $o) {
                DB::exec('DELETE FROM qd_record WHERE domain_id = ?', [(int)$o['id']]);
                DB::exec('DELETE FROM qd_snapshot WHERE domain_id = ?', [(int)$o['id']]);
                DB::exec('DELETE FROM qd_domain WHERE id = ?', [(int)$o['id']]);
            }

            DB::exec('DELETE FROM qd_account WHERE id = ?', [$id]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            Response::fail('删除失败: ' . $e->getMessage());
        }
        Audit::log('delete_account', $account['name']);
        Response::ok(null, '已删除');
    }

    /** 测试账号连通性（DNSPod 统一账号会分别报告两套凭证状态） */
    public function test(): void
    {
        $id = (int)Request::post('id', 0);
        $account = DB::one('SELECT * FROM qd_account WHERE id = ?', [$id]);
        if (!$account) {
            Response::fail('账号不存在');
        }
        $cred = json_decode(Crypto::decrypt($account['credentials']), true) ?: [];
        $p = ProviderFactory::make($account['provider'], $cred);
        $ok = $p->test();
        $detail = '';
        if ($p instanceof DnspodUnifiedProvider) {
            $st = $p->credentialStatus();
            $fmt = function (string $label, array $s): string {
                return $label . ':' . ($s['available'] ? '已配置' : '未配置');
            };
            $detail = $fmt('传统Token', $st['legacy']) . ' / ' . $fmt('API3.0', $st['api3']);
        }
        if ($ok) {
            Response::ok(['detail' => $detail], '连接成功' . ($detail ? '（' . $detail . '）' : ''));
        }
        Response::fail('连接失败，请检查凭证' . ($detail ? '（' . $detail . '）' : ''));
    }

    /*
     * ==================== 同步账号域名（分阶段任务，带进度） ====================
     *
     * 域名多时一次同步要几十秒（服务商分页 API + 逐域名 DNS 探测），单请求既看不到进度
     * 也容易撞 PHP 超时。这里把同步拆成固定阶段，前端逐步轮询，进度条 + 阶段计数一起走：
     *   zones → write → owned → apply_owned → ns → evidence → apply_stale
     * 阶段内的处理量按批切分（写入 40 行 / NS 8 个域名 / 归属 200 行），
     * 单请求耗时可控；阶段间的不可计数量（拉列表、汇总证据）用不确定态进度条表示。
     *
     * 写入规则：
     *  - 西部数码（west）：getdomains 即真实持有 → 写 registrar + 到期日
     *  - 阿里云：DescribeDomains(AliDomain=true) 为解析 zone；QueryDomainList 为真实持有 → 写 registrar
     *  - 腾讯云 DNSPod：DescribeDomainList(解析) 为 zone；domain 服务为真实持有 → 写 registrar
     *  - 域名按字符串唯一；「挂在哪些平台」写入 qd_domain_zone（一家一行，互不覆盖）；
     *    qd_domain.dns_provider 只是「生效指针」，收尾时按 NS 实证统一重算
     * 各阶段均为幂等 upsert，中断后「继续」从断点重跑不会写重或写错。
     */

    /** 启动（或恢复）同步任务 */
    public function syncStart(): void
    {
        $id = (int)Request::post('id', 0);
        $account = DB::one('SELECT * FROM qd_account WHERE id = ?', [$id]);
        if (!$account) {
            Response::fail('账号不存在');
        }

        $key = self::syncKey($id);
        $task = $_SESSION[$key] ?? null;
        // 续跑：同账号任务未完成（运行中/已停止/上次中断）就从断点继续，不重置进度
        if ($task && in_array($task['status'], ['running', 'stopped', 'error'], true)) {
            $task['status'] = 'running';
            $task['msg'] = '';
            $_SESSION[$key] = $task;
            Response::ok($this->syncState($task, true), '已恢复原进度，继续同步');
        }

        $provider = (string)$account['provider'];
        $logical = ProviderFactory::logicalProvider($provider);
        $skip = [];
        // 只有阿里云、腾讯云 DNSPod 有独立的「真实持有」接口；西部数码的 listDomains 本身就是持有列表
        if ($provider !== 'aliyun' && $logical !== 'dnspod') {
            $skip['owned'] = true;
            $skip['apply_owned'] = true;
        }

        $task = [
            'token' => bin2hex(random_bytes(8)),
            'account_id' => $id,
            'account_name' => (string)$account['name'],
            'provider' => $provider,
            'logical' => $logical,
            'phase' => 'zones',
            'skip' => $skip,
            'zones' => [],        // [['domain'=>, 'expire_at'=>], ...]
            'owned' => [],        // [['domain'=>, 'expire_at'=>, 'domain_status'=>], ...]
            'zone_names' => [],
            'names' => [],
            'evidence' => [],
            'apply_ids' => [],
            'write_done' => 0, 'write_total' => 0,
            'owned_done' => 0, 'owned_total' => 0,
            'ns_done' => 0, 'ns_total' => 0,
            'stale_done' => 0, 'stale_total' => 0,
            'counts' => ['zones' => 0, 'owned' => 0, 'excluded' => 0, 'unlinked' => 0, 'stale' => 0, 'mine' => 0],
            'status' => 'running',
            'msg' => '',
            'started_at' => date('Y-m-d H:i:s'),
        ];
        $_SESSION[$key] = $task;
        Response::ok($this->syncState($task), '已开始同步');
    }

    /** 推进一个批次 */
    public function syncStep(): void
    {
        $id = (int)Request::post('id', 0);
        $token = (string)Request::post('token', '');
        $key = self::syncKey($id);
        $task = $_SESSION[$key] ?? null;

        if (!$task || (string)$task['token'] !== $token) {
            Response::fail('同步任务不存在或已过期，请重新开始');
        }
        if ($task['status'] !== 'running') {
            Response::ok($this->syncState($task));
        }

        try {
            $this->syncAdvance($task);
        } catch (\Throwable $e) {
            // 以 error 状态返回而不是抛错：前端要能保留阶段进度并给「继续」按钮
            $task['status'] = 'error';
            $task['msg'] = '中断：' . $e->getMessage() . '（已完成部分已保存，可点「继续」从断点重试）';
            $_SESSION[$key] = $task;
            Response::ok($this->syncState($task), '同步中断');
        }
        $_SESSION[$key] = $task;
        Response::ok($this->syncState($task));
    }

    /** 停止任务（已处理部分保留） */
    public function syncStop(): void
    {
        $id = (int)Request::post('id', 0);
        $key = self::syncKey($id);
        $task = $_SESSION[$key] ?? null;
        if (!$task) {
            Response::ok(['status' => 'none'], '无进行中的同步任务');
        }
        if ($task['status'] === 'running') {
            $task['status'] = 'stopped';
            $_SESSION[$key] = $task;
        }
        Response::ok($this->syncState($task), '已停止');
    }

    private static function syncKey(int $accountId): string
    {
        return 'qd_sync_' . $accountId;
    }

    /** 按阶段派发一批处理 */
    private function syncAdvance(array &$task): void
    {
        switch ($task['phase']) {
            case 'zones':       $this->syncFetchZones($task); break;
            case 'write':       $this->syncWriteZones($task); break;
            case 'owned':       $this->syncFetchOwned($task); break;
            case 'apply_owned': $this->syncApplyOwned($task); break;
            case 'ns':          $this->syncRefreshNs($task); break;
            case 'evidence':    $this->syncCollectEvidence($task); break;
            case 'apply_stale': $this->syncApplyStale($task); break;
            default:            $this->syncFinish($task); break;
        }
    }

    /** 阶段一：拉取服务商域名列表（API 分页，耗时不定 → 不确定态） */
    private function syncFetchZones(array &$task): void
    {
        $rows = $this->syncProvider($task)->listDomains();
        $zones = [];
        $names = [];
        foreach ($rows as $d) {
            $domain = trim((string)($d['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }
            // 注册状态只对「列表即真实持有」的服务商有意义（西部数码），其余给 0
            $zones[] = [
                'domain' => $domain,
                'expire_at' => (string)($d['expire_at'] ?? ''),
                'domain_status' => (int)($d['domain_status'] ?? 0),
            ];
            $names[] = $domain;
        }
        $task['zones'] = $zones;
        $task['zone_names'] = $names;
        $task['write_total'] = count($zones);
        $task['write_done'] = 0;
        $task['counts']['zones'] = count($zones);
        if ($task['write_total'] > 0) {
            $task['phase'] = 'write';
            return;
        }
        // 该账号下已无任何 zone：清掉历史关联（若本次列表为空而库里有登记，prune 内部会视为异常并跳过）
        $this->applyPrune($task);
        $this->syncNextAfterZones($task);
    }

    /** 阶段二：分批写入域名库 */
    private function syncWriteZones(array &$task): void
    {
        $now = date('Y-m-d H:i:s');
        $batch = array_slice($task['zones'], (int)$task['write_done'], self::SYNC_BATCH_ROWS);
        foreach ($batch as $d) {
            $this->writeZoneRow($d, (int)$task['account_id'], (string)$task['logical'], $task['provider'] === 'west', $now);
        }
        $task['write_done'] += count($batch);

        if ($task['write_done'] >= $task['write_total']) {
            $task['write_done'] = $task['write_total'];
            // 全部写入完成后，才清理「本账号已删除」的历史 zone 关联（在写入之后做，避免误删重名的域名行）
            $this->applyPrune($task);
            DB::exec('UPDATE qd_account SET last_sync_at=? WHERE id=?', [$now, (int)$task['account_id']]);
            $this->syncNextAfterZones($task);
        }
    }

    /** 阶段三：拉取注册商真实持有列表（QueryDomainList / 腾讯云 domain，耗时不定） */
    private function syncFetchOwned(array &$task): void
    {
        $rows = $this->syncProvider($task)->listOwnedDomains();
        $owned = [];
        foreach ($rows as $o) {
            $domain = trim((string)($o['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }
            $owned[] = [
                'domain' => $domain,
                'expire_at' => (string)($o['expire_at'] ?? ''),
                'domain_status' => (int)($o['domain_status'] ?? 0),
            ];
        }
        $task['owned'] = $owned;
        $task['owned_total'] = count($owned);
        $task['owned_done'] = 0;
        $task['counts']['owned'] = count($owned);
        if ($task['owned_total'] === 0) {
            $this->syncEnterNs($task);
            return;
        }
        $task['phase'] = 'apply_owned';
    }

    /** 阶段四：分批写入持有维度（registrar/到期日/状态），收尾时（阿里云）收敛残留 zone */
    private function syncApplyOwned(array &$task): void
    {
        $now = date('Y-m-d H:i:s');
        $batch = array_slice($task['owned'], (int)$task['owned_done'], self::SYNC_BATCH_ROWS);
        foreach ($batch as $o) {
            $this->applyOwnedRow($o, (int)$task['account_id'], (string)$task['logical'], $now);
        }
        $task['owned_done'] += count($batch);
        if ($task['owned_done'] < $task['owned_total']) {
            return;
        }
        $task['owned_done'] = $task['owned_total'];

        $this->syncEnterNs($task);
    }

    /** 阶段五：分批刷新在用 NS（逐域名 DNS 探测，最慢的一段，计数精确） */
    private function syncRefreshNs(array &$task): void
    {
        $batch = array_slice($task['names'], (int)$task['ns_done'], self::SYNC_BATCH_NS);
        if ($batch) {
            NsResolver::refreshAllByNames($batch);
            $task['ns_done'] += count($batch);
        }
        if ($task['ns_done'] >= $task['ns_total']) {
            $task['ns_done'] = $task['ns_total'];
            $task['phase'] = 'evidence';
        }
    }

    /** 阶段六：汇总各账号 API 证据（多个服务商逐个调用，耗时不定 → 不确定态） */
    private function syncCollectEvidence(array &$task): void
    {
        $ev = OwnershipResolver::collectEvidence();
        if (!empty($ev['failed'])) {
            // 宁可不更新，不可误杀：证据不全就跳过本轮重算，保留原判定
            $task['msg'] = '部分账号 API 拉取失败，本轮跳过归属重算：' . implode('；', $ev['failed']);
            $task['phase'] = 'done';
            $this->syncFinish($task);
            return;
        }
        $ids = DB::all('SELECT id FROM qd_domain ORDER BY id');
        $task['evidence'] = $ev;
        $task['apply_ids'] = array_map(static function ($r): int { return (int)$r['id']; }, $ids);
        $task['stale_total'] = count($task['apply_ids']);
        $task['stale_done'] = 0;
        if ($task['stale_total'] === 0) {
            $task['phase'] = 'done';
            $this->syncFinish($task);
            return;
        }
        $task['phase'] = 'apply_stale';
    }

    /** 阶段七：分批重算归属（本地判定，按 id 分页写库） */
    private function syncApplyStale(array &$task): void
    {
        $batch = array_slice($task['apply_ids'], (int)$task['stale_done'], self::SYNC_BATCH_STALE);
        if ($batch) {
            $r = OwnershipResolver::applyEvidence($task['evidence'], $batch);
            $task['counts']['stale'] += (int)($r['stale'] ?? 0);
            $task['counts']['mine'] += (int)($r['mine'] ?? 0);
            $task['stale_done'] += count($batch);
        }
        if ($task['stale_done'] >= $task['stale_total']) {
            $task['stale_done'] = $task['stale_total'];
            $task['phase'] = 'done';
            $this->syncFinish($task);
        }
    }

    /** 收尾：落状态 + 审计（只落一次） */
    private function syncFinish(array &$task): void
    {
        if ($task['status'] !== 'running') {
            return;
        }
        $task['status'] = 'done';
        // 收尾：关联表已登记完，按 NS 实证重算「生效指针」
        // （dns_provider/dns_account_id 现在只表示「生效平台」，供解析记录页确定默认操作对象）
        $task['counts']['pointer'] = ZoneMap::refreshPointer();
        Audit::log('sync_domains', (string)$task['account_name'], [
            'count' => (int)$task['counts']['zones'],
            'owned' => (int)$task['counts']['owned'],
            'excluded' => (int)$task['counts']['excluded'],
            'unlinked' => (int)$task['counts']['unlinked'],
            'stale' => (int)$task['counts']['stale'],
        ]);
        // 完成后释放中间快照（域名列表/证据/ID 表），避免 session 长期背一大坨数据
        $task['zones'] = [];
        $task['owned'] = [];
        $task['names'] = [];
        $task['apply_ids'] = [];
        $task['evidence'] = [];
    }

    /** 域名写入完成后进入下一阶段：有持有接口就拉持有，否则直接刷新 NS */
    private function syncNextAfterZones(array &$task): void
    {
        if (empty($task['skip']['owned'])) {
            $task['phase'] = 'owned';
            return;
        }
        $this->syncEnterNs($task);
    }

    /** 进入 NS 阶段：目标域名 = 解析列表 ∪ 持有列表（去重） */
    private function syncEnterNs(array &$task): void
    {
        $names = $task['zone_names'];
        foreach ($task['owned'] as $o) {
            $names[] = $o['domain'];
        }
        $names = array_values(array_unique($names));
        $task['names'] = $names;
        $task['ns_total'] = count($names);
        $task['ns_done'] = 0;
        $task['phase'] = $task['ns_total'] > 0 ? 'ns' : 'evidence';
    }

    /**
     * 单一域名写入（upsert 域名 + 登记「该账号下有这个 zone」）
     *
     * 同一域名可以同时挂在多家平台，所以这里**绝不覆盖**别家的托管记录：
     *  - 域名行只更新注册商维度（仅注册商账号）与 updated_at；
     *  - 「挂在哪几家」统一登记到 qd_domain_zone，一家一行；
     *  - qd_domain.dns_provider 是「生效指针」，由收尾阶段按 NS 实证统一重算，此处不写。
     */
    private function writeZoneRow(array $d, int $accountId, string $logical, bool $isRegistrar, string $now): void
    {
        $domain = (string)$d['domain'];
        $expire = ($d['expire_at'] ?? '') ?: null;
        // 注册状态只由「列表本身即真实持有」的账号（西部数码）提供；0=未知时不覆盖库里已有状态
        $status = $isRegistrar ? (int)($d['domain_status'] ?? 0) : 0;
        $exist = DB::one('SELECT id FROM qd_domain WHERE domain = ?', [$domain]);

        if ($exist) {
            $id = (int)$exist['id'];
            if ($isRegistrar) {
                DB::exec(
                    'UPDATE qd_domain SET registrar=?, registrar_account_id=?, expire_at=COALESCE(?, expire_at),
                            domain_status=IF(? > 0, ?, domain_status), updated_at=? WHERE id=?',
                    [$logical, $accountId, $expire, $status, $status, $now, $id]
                );
            } else {
                DB::exec('UPDATE qd_domain SET updated_at=? WHERE id=?', [$now, $id]);
            }
        } else {
            $id = DB::insert(
                'INSERT INTO qd_domain (domain, registrar, registrar_account_id, dns_provider, dns_account_id, expire_at, domain_status, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                $isRegistrar
                    ? [$domain, $logical, $accountId, $logical, $accountId, $expire, $status, $now, $now]
                    : [$domain, '', 0, $logical, $accountId, $expire, 0, $now, $now]
            );
        }

        ZoneMap::upsert($id, $accountId, $logical, $now);
    }

    /**
     * 把注册商侧「真实持有」的单个域名写入 registrar 维度。
     * 已有其他注册商归属时保留原值（例如西部数码注册但 DNS 接入腾讯云的域名），不串改归属。
     */
    private function applyOwnedRow(array $o, int $accountId, string $registrarCode, string $now): void
    {
        $domain = (string)$o['domain'];
        $expire = ($o['expire_at'] ?? '') ?: null;
        $status = (int)($o['domain_status'] ?? 0);

        $exist = DB::one('SELECT id, registrar FROM qd_domain WHERE domain = ?', [$domain]);
        if ($exist) {
            if ($exist['registrar'] !== '' && $exist['registrar'] !== $registrarCode) {
                return;
            }
            DB::exec(
                'UPDATE qd_domain SET registrar=?, registrar_account_id=?,
                        expire_at=COALESCE(?, expire_at),
                        domain_status=IF(? > 0, ?, domain_status), updated_at=? WHERE id=?',
                [$registrarCode, $accountId, $expire, $status, $status, $now, $exist['id']]
            );
            return;
        }
        DB::insert(
            'INSERT INTO qd_domain (domain, registrar, registrar_account_id, expire_at, domain_status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?)',
            [$domain, $registrarCode, $accountId, $expire, $status, $now, $now]
        );
    }

    /** 按任务上下文构造服务商实例（每步重新取账号，避免把凭证塞进 session） */
    private function syncProvider(array $task)
    {
        $account = DB::one('SELECT * FROM qd_account WHERE id = ?', [$task['account_id']]);
        if (!$account) {
            throw new \RuntimeException('账号已不存在');
        }
        $cred = json_decode(Crypto::decrypt((string)$account['credentials']), true) ?: [];
        return ProviderFactory::make((string)$task['provider'], $cred);
    }

    /** 进度快照：阶段清单 + 总百分比 + 计数（前端只负责渲染，不再自己算进度） */
    private function syncState(array $task, bool $resumed = false): array
    {
        $order = array_keys(self::SYNC_STEPS);
        $phase = (string)$task['phase'];
        $cur = array_search($phase, $order, true);
        $cur = $cur === false ? count($order) : (int)$cur;
        $status = (string)$task['status'];

        $steps = [];
        foreach ($order as $i => $key) {
            if (!empty($task['skip'][$key])) {
                continue;
            }
            if ($status === 'done' || $i < $cur) {
                $state = 'done';
            } elseif ($i > $cur) {
                $state = 'wait';
            } else {
                $state = ['running' => 'run', 'error' => 'bad', 'stopped' => 'stop'][$status] ?? 'wait';
            }
            $steps[] = [
                'key' => $key,
                'label' => self::SYNC_STEPS[$key]['label'],
                'state' => $state,
                'detail' => $this->syncStepDetail($task, $key),
            ];
        }

        [$done, $total] = $this->syncPhaseCounters($task, $phase);
        // 拉列表 / 汇总证据这类不可计数量的阶段用不确定态，避免停在 0% 让人以为卡死
        $indeterminate = $status === 'running' && in_array($phase, ['zones', 'owned', 'evidence'], true);
        $ratio = ($indeterminate || $total <= 0) ? 0.0 : min(1.0, $done / $total);
        $percent = 0;
        if ($status === 'done') {
            $percent = 100;
        } elseif (isset(self::SYNC_STEPS[$phase])) {
            $meta = self::SYNC_STEPS[$phase];
            $percent = (int)round($meta['from'] + ($meta['to'] - $meta['from']) * $ratio);
        }

        return [
            'token' => (string)$task['token'],
            'status' => $status,
            'phase' => $phase,
            'phase_label' => self::SYNC_STEPS[$phase]['label'] ?? '同步完成',
            'percent' => $percent,
            'indeterminate' => $indeterminate,
            'done' => $done,
            'total' => $total,
            'steps' => $steps,
            'counts' => $task['counts'],
            'msg' => (string)$task['msg'],
            'resumed' => $resumed,
            'elapsed' => max(0, time() - strtotime((string)$task['started_at'])),
        ];
    }

    /** 当前阶段的 [已完成, 总数] */
    private function syncPhaseCounters(array $task, string $phase): array
    {
        switch ($phase) {
            case 'write':       return [(int)$task['write_done'], (int)$task['write_total']];
            case 'apply_owned': return [(int)$task['owned_done'], (int)$task['owned_total']];
            case 'ns':          return [(int)$task['ns_done'], (int)$task['ns_total']];
            case 'apply_stale': return [(int)$task['stale_done'], (int)$task['stale_total']];
        }
        return [0, 0];
    }

    /** 阶段右侧的计数文案 */
    private function syncStepDetail(array $task, string $key): string
    {
        switch ($key) {
            case 'zones':
                return $task['counts']['zones'] > 0 ? $task['counts']['zones'] . ' 个' : '';
            case 'write':
                return $task['write_total'] > 0 ? $task['write_done'] . ' / ' . $task['write_total'] : '';
            case 'owned':
                return $task['owned_total'] > 0 ? $task['owned_total'] . ' 个' : '';
            case 'apply_owned':
                return $task['owned_total'] > 0 ? $task['owned_done'] . ' / ' . $task['owned_total'] : '';
            case 'ns':
                return $task['ns_total'] > 0 ? $task['ns_done'] . ' / ' . $task['ns_total'] : '';
            case 'apply_stale':
                return $task['stale_total'] > 0 ? $task['stale_done'] . ' / ' . $task['stale_total'] : '';
        }
        return '';
    }

    /**
     * 收敛阿里云残留 zone：不属于该账号的过期域名直接排除，不再显示。
     * 有效白名单 = 真实持有（QueryDomainList）∪ 有效 zone（AliDomain=true）。
     */
    /** 执行一次本账号的关联清理并累加统计 */
    private function applyPrune(array &$task): void
    {
        $r = $this->pruneAccountZones($task['zones'], (int)$task['account_id']);
        $task['counts']['excluded'] += (int)$r['excluded'];
        $task['counts']['unlinked'] += (int)$r['unlinked'];
    }

    /**
     * 清理本账号已不存在的 zone 关联。
     *
     * 一个域名可能同时挂在多家平台，所以「本账号没有这个 zone」不等于「域名失效」：
     *  - 解除本账号的关联行；
     *  - 若别家还挂着 → 域名保留，只算「解绑」；
     *  - 若全平台都没了且无注册商归属 → 视为孤儿域名，连本地记录/快照一起清掉；
     *  - 若全平台都没了但有注册商归属 → 域名保留（只是暂时没有解析 zone）。
     *
     * 防误删：本次拉到的域名列表为空、而库里却有登记时，判定为接口异常，整轮跳过。
     */
    private function pruneAccountZones(array $zones, int $accountId): array
    {
        $seen = [];
        foreach ($zones as $z) {
            $name = strtolower(trim((string)($z['domain'] ?? '')));
            if ($name !== '') {
                $seen[$name] = true;
            }
        }

        ZoneMap::ensure();
        $rows = DB::all(
            'SELECT z.domain_id, d.domain, d.registrar
               FROM qd_domain_zone z
               JOIN qd_domain d ON d.id = z.domain_id
              WHERE z.account_id = ?',
            [$accountId]
        );
        if (!$seen && $rows) {
            return ['excluded' => 0, 'unlinked' => 0, 'skipped' => true];
        }

        $excluded = 0;
        $unlinked = 0;
        foreach ($rows as $r) {
            if (isset($seen[strtolower((string)$r['domain'])])) {
                continue;
            }
            $domainId = (int)$r['domain_id'];
            DB::exec('DELETE FROM qd_domain_zone WHERE account_id = ? AND domain_id = ?', [$accountId, $domainId]);

            $others = (int)DB::val('SELECT COUNT(*) FROM qd_domain_zone WHERE domain_id = ?', [$domainId]);
            if ($others > 0 || (string)$r['registrar'] !== '') {
                $unlinked++;
                continue;
            }
            DB::exec('DELETE FROM qd_record WHERE domain_id = ?', [$domainId]);
            DB::exec('DELETE FROM qd_snapshot WHERE domain_id = ?', [$domainId]);
            DB::exec('DELETE FROM qd_domain WHERE id = ?', [$domainId]);
            $excluded++;
        }
        return ['excluded' => $excluded, 'unlinked' => $unlinked, 'skipped' => false];
    }

    private function collectCredentials(string $provider): array
    {
        switch ($provider) {
            case 'dnspod':
                return [
                    'login_token' => trim((string)Request::post('login_token', '')),
                    'secret_id' => trim((string)Request::post('secret_id', '')),
                    'secret_key' => trim((string)Request::post('secret_key', '')),
                    'appid' => trim((string)Request::post('appid', '')),
                ];
            case 'aliyun':
                return [
                    'access_key_id' => trim((string)Request::post('access_key_id', '')),
                    'access_key_secret' => trim((string)Request::post('access_key_secret', '')),
                ];
            case 'west':
                return [
                    'username' => trim((string)Request::post('username', '')),
                    'api_password' => trim((string)Request::post('api_password', '')),
                ];
        }
        return [];
    }

    private function validateCredentials(string $provider, array $cred): bool
    {
        switch ($provider) {
            case 'dnspod':
                $hasLegacy = ($cred['login_token'] ?? '') !== '';
                $hasApi3 = ($cred['secret_id'] ?? '') !== '' && ($cred['secret_key'] ?? '') !== '';
                return $hasLegacy || $hasApi3; // 至少一套
            case 'aliyun':
                return ($cred['access_key_id'] ?? '') !== '' && ($cred['access_key_secret'] ?? '') !== '';
            case 'west':
                return ($cred['username'] ?? '') !== '' && ($cred['api_password'] ?? '') !== '';
        }
        return false;
    }
}
