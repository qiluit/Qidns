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
use QiDNS\Lib\Snapshot;
use QiDNS\Lib\ZoneMap;

class RecordController
{
    private const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

    public function index(): void
    {
        $domainId = (int)Request::get('domain_id', 0);
        $accountId = (int)Request::get('account_id', 0);
        $domain = DB::one('SELECT * FROM qd_domain WHERE id = ?', [$domainId]);
        if (!$domain) {
            Response::redirect('/index.php?r=Domain');
        }

        $account = ZoneMap::resolveAccount($domainId, $accountId, (int)$domain['dns_account_id']);
        $targetId = $account ? (int)$account['id'] : 0;

        $zones = ZoneMap::mapByDomainIds([$domainId])[$domainId] ?? [];
        $eff = (string)$domain['effective_dns'];
        foreach ($zones as &$z) {
            $z['live'] = ($z['provider'] === $eff);
        }
        unset($z);

        $records = $targetId > 0
            ? DB::all('SELECT * FROM qd_record WHERE domain_id = ? AND account_id = ? ORDER BY id ASC', [$domainId, $targetId])
            : [];

        echo View::render('record/index', [
            'domain' => $domain,
            'records' => $records,
            'types' => self::RECORD_TYPES,
            'account' => $account,
            'targetAccountId' => $targetId,
            'zones' => $zones,
            'isEffective' => $targetId > 0 && ZoneMap::isEffective($domainId, $targetId, $eff),
            'effectiveDns' => $eff,
        ]);
    }

    /** 新增记录（dry-run 模式支持） */
    public function create(): void
    {
        $domainId = (int)Request::post('domain_id', 0);
        $domain = $this->getDomainWithAccount($domainId);
        if (!$domain) {
            Response::fail('域名不存在');
        }

        $record = $this->collectRecord();
        $dryRun = (bool)Request::post('dry_run', false);

        if ($dryRun) {
            Response::ok([
                'preview' => '将新增 ' . $record['type'] . ' 记录：' . $record['rr'] . ' → ' . $record['value'],
            ], '预览（未真正执行）');
        }

        [$p, $account] = $this->getProvider($domain, (int)Request::post('account_id', 0));
        $remoteId = $p->createRecord($domain['domain'], $record);

        $now = date('Y-m-d H:i:s');
        DB::insert(
            'INSERT INTO qd_record (account_id, domain_id, domain, rr, type, value, ttl, line, mx, status, remote_id, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                (int)$account['id'], $domainId, $domain['domain'],
                $record['rr'], $record['type'], $record['value'], $record['ttl'],
                $record['line'] ?? '默认', $record['mx'] ?? 0, 1, $remoteId, $now, $now,
            ]
        );

        Audit::log('add_record', $domain['domain'], [
            'rr' => $record['rr'], 'type' => $record['type'], 'value' => $record['value'],
        ]);
        Response::ok(['remote_id' => $remoteId], '新增成功');
    }

    /** 更新记录 */
    public function update(): void
    {
        $id = (int)Request::post('id', 0);
        $record = DB::one('SELECT * FROM qd_record WHERE id = ?', [$id]);
        if (!$record) {
            Response::fail('记录不存在');
        }
        $domain = $this->getDomainWithAccount((int)$record['domain_id']);
        if (!$domain) {
            Response::fail('域名不存在');
        }

        $newRecord = $this->collectRecord();
        $dryRun = (bool)Request::post('dry_run', false);
        if ($dryRun) {
            Response::ok([
                'preview' => '将更新记录 ' . $record['rr'] . '：' . $record['value'] . ' → ' . $newRecord['value'],
            ], '预览（未真正执行）');
        }

        // 更新/删除按记录自己所属的平台操作（本地缓存已按账号隔离）
        $accountId = (int)$record['account_id'] ?: (int)Request::post('account_id', 0);
        $p = $this->getProvider($domain, $accountId)[0];
        $p->updateRecord($domain['domain'], $record['remote_id'], $newRecord);

        DB::exec(
            'UPDATE qd_record SET rr=?, type=?, value=?, ttl=?, line=?, mx=?, updated_at=? WHERE id=?',
            [
                $newRecord['rr'], $newRecord['type'], $newRecord['value'], $newRecord['ttl'],
                $newRecord['line'] ?? '默认', $newRecord['mx'] ?? 0, date('Y-m-d H:i:s'), $id,
            ]
        );

        Audit::log('update_record', $domain['domain'], [
            'rr' => $record['rr'], 'old_value' => $record['value'], 'new_value' => $newRecord['value'],
        ]);
        Response::ok(null, '更新成功');
    }

    /** 删除记录 */
    public function delete(): void
    {
        $id = (int)Request::post('id', 0);
        $record = DB::one('SELECT * FROM qd_record WHERE id = ?', [$id]);
        if (!$record) {
            Response::fail('记录不存在');
        }
        $domain = $this->getDomainWithAccount((int)$record['domain_id']);
        if (!$domain) {
            Response::fail('域名不存在');
        }

        $accountId = (int)$record['account_id'] ?: (int)Request::post('account_id', 0);
        $p = $this->getProvider($domain, $accountId)[0];
        $p->deleteRecord($domain['domain'], $record['remote_id']);

        DB::exec('DELETE FROM qd_record WHERE id = ?', [$id]);

        Audit::log('delete_record', $domain['domain'], [
            'rr' => $record['rr'], 'type' => $record['type'], 'value' => $record['value'],
        ]);
        Response::ok(null, '删除成功');
    }

    // ---- 特色：回滚 ----

    /** 快照列表 */
    public function snapshots(): void
    {
        $domainId = (int)Request::get('domain_id', 0);
        $snapshots = DB::all(
            'SELECT * FROM qd_snapshot WHERE domain_id = ? ORDER BY id DESC',
            [$domainId]
        );
        echo View::render('record/snapshots', [
            'snapshots' => $snapshots,
            'domainId' => $domainId,
        ]);
    }

    /** 回滚到指定快照 */
    public function rollback(): void
    {
        $snapshotId = (int)Request::post('snapshot_id', 0);
        $snap = Snapshot::get($snapshotId);
        if (!$snap) {
            Response::fail('快照不存在');
        }
        $domain = $this->getDomainWithAccount((int)$snap['domain_id']);
        if (!$domain) {
            Response::fail('域名不存在');
        }

        $targetRecords = json_decode($snap['content'], true) ?: [];

        // 回滚必须回到快照原本所在的那家平台，否则会把 A 平台的记录写进 B 平台
        $accountId = (int)Request::post('account_id', 0);
        if ($accountId === 0 && (string)$snap['provider'] !== '') {
            $row = DB::one(
                'SELECT account_id FROM qd_domain_zone WHERE domain_id = ? AND provider = ? ORDER BY account_id ASC LIMIT 1',
                [(int)$snap['domain_id'], (string)$snap['provider']]
            );
            $accountId = $row ? (int)$row['account_id'] : 0;
        }
        [$p, $account] = $this->getProvider($domain, $accountId);
        $targetId = (int)$account['id'];

        // 拉取线上当前记录
        $current = $p->listRecords($domain['domain']);

        DB::begin();
        try {
            // 删除线上所有记录
            foreach ($current as $c) {
                if ($c['remote_id'] !== '') {
                    $p->deleteRecord($domain['domain'], $c['remote_id']);
                }
            }
            // 按快照重建
            foreach ($targetRecords as $r) {
                $p->createRecord($domain['domain'], [
                    'rr' => $r['rr'], 'type' => $r['type'], 'value' => $r['value'],
                    'ttl' => $r['ttl'], 'line' => $r['line'], 'mx' => $r['mx'],
                ]);
            }
            // 本地缓存按线上最新状态整体重写：快照里的行 id 往往已因「重新同步」失效，
            // 再按 id 回写 remote_id 会更新 0 行、留下无 remote_id 的坏缓存
            DB::exec('DELETE FROM qd_record WHERE domain_id = ? AND account_id = ?', [(int)$domain['id'], $targetId]);
            $now = date('Y-m-d H:i:s');
            foreach ($p->listRecords($domain['domain']) as $r) {
                DB::insert(
                    'INSERT INTO qd_record (account_id, domain_id, domain, rr, type, value, ttl, line, mx, status, remote_id, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $targetId, (int)$domain['id'], $domain['domain'],
                        $r['rr'], $r['type'], $r['value'], $r['ttl'], $r['line'], $r['mx'],
                        $r['status'], $r['remote_id'], $now, $now,
                    ]
                );
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollback();
            Audit::log('rollback_fail', $domain['domain'], ['snapshot_id' => $snapshotId, 'error' => $e->getMessage()]);
            Response::fail('回滚失败：' . $e->getMessage() . '。线上记录可能已被清空或部分重建，请到服务商控制台核对后重新同步记录。');
        }

        Audit::log('rollback', $domain['domain'], ['snapshot_id' => $snapshotId]);
        Response::ok(null, '回滚成功');
    }

    // ---- 内部 ----

    private function getDomainWithAccount(int $domainId): ?array
    {
        $domain = DB::one('SELECT * FROM qd_domain WHERE id = ?', [$domainId]);
        return $domain ?: null;
    }

    /**
     * 取本次操作的解析平台。
     *
     * 一个域名可能同时挂在多家平台，用哪个由 $accountId 决定：
     *  0 → 按生效指针（qd_domain.dns_account_id）；指针为空且只有一家 zone 时用那家。
     *
     * @return array{0: object, 1: array} [Provider 实例, 账号行]
     */
    private function getProvider(array $domain, int $accountId = 0): array
    {
        $account = ZoneMap::resolveAccount((int)$domain['id'], $accountId, (int)$domain['dns_account_id']);
        if (!$account) {
            if ($accountId > 0) {
                throw new \RuntimeException('该域名不在这个账号的解析平台下');
            }
            // 区分「多家要选」与「一家都没有」，别让用户去找一个不存在的平台
            $zoneCount = (int)DB::val('SELECT COUNT(*) FROM qd_domain_zone WHERE domain_id = ?', [(int)$domain['id']]);
            throw new \RuntimeException($zoneCount > 1
                ? '该域名挂在多家平台，请从上方选择要操作哪一家'
                : '该域名没有可操作的解析平台（未接入我方 DNS 账号）');
        }
        $cred = json_decode(Crypto::decrypt($account['credentials']), true) ?: [];
        return [ProviderFactory::make($account['provider'], $cred), $account];
    }

    private function collectRecord(): array
    {
        $rr = trim((string)Request::post('rr', ''));
        $type = strtoupper(trim((string)Request::post('type', 'A')));
        $value = trim((string)Request::post('value', ''));
        $ttl = (int)Request::post('ttl', 600);
        $line = trim((string)Request::post('line', '默认'));
        $mx = (int)Request::post('mx', 0);

        if ($rr === '') {
            $rr = '@';
        }
        if (!in_array($type, self::RECORD_TYPES, true)) {
            Response::fail('不支持的记录类型: ' . $type);
        }
        if ($value === '') {
            Response::fail('解析值不能为空');
        }

        return [
            'rr' => $rr,
            'type' => $type,
            'value' => $value,
            'ttl' => $ttl,
            'line' => $line,
            'mx' => $mx,
        ];
    }
}
