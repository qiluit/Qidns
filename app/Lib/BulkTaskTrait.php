<?php

declare(strict_types=1);

namespace QiDNS\Lib;

use QiDNS\Core\Request;
use QiDNS\Core\Response;

/**
 * 批量任务通用骨架（分批推进 / 进度查询 / 幂等启动 / 中断）
 *
 * 解决一次性同步请求容易超时、重复启动导致多套任务争抢的问题：
 *   - 启动幂等：同一批目标重复点「开始」直接恢复现有任务，不重置游标
 *   - 分批推进：每请求只处理一小批，前端轮询进度条
 *   - 兜底收尾：游标已到末尾但状态未落库时强制收尾，避免进度卡在 N/N-3
 *   - 可中断、可限速（间隔前端传，后端封顶）
 *
 * 使用者需实现三个钩子：bulkKey() 任务隔离键、bulkProcess() 处理一批、
 * bulkAuditName() 审计动作名。统计统一为 ok / bad 两项。
 */
trait BulkTaskTrait
{
    /** @return string 会话中隔离不同批量任务的键 */
    abstract protected function bulkKey(): string;

    /**
     * 处理一批域名。
     * @param array $ids 本批域名 id
     * @param array $ctx 任务上下文（启动时的证据等，只读传递）
     * @return array{ok:int,bad:int}
     */
    abstract protected function bulkProcess(array $ids, array $ctx): array;

    /** @return string 审计日志动作名 */
    abstract protected function bulkAuditName(): string;

    /** 单批数量：越小单请求越快、进度越平滑 */
    protected function bulkBatchSize(): int { return 5; }

    /** 单批间隔上限（毫秒），防止前端传超大值把任务拖死 */
    protected function bulkMaxSleepMs(): int { return 2000; }

    /**
     * 启动（或恢复）批量任务。同一目标集合重复调用不会重置进度。
     * @param array $ids 待处理域名 id（有序）
     * @param array $ctx 任务上下文，如归属判定的预拉证据
     */
    protected function bulkBegin(array $ids, array $ctx = []): void
    {
        $key = $this->bulkKey();
        $ids = array_values(array_map('intval', $ids));
        $sig = self::bulkSignature($ids);
        $task = $_SESSION[$key] ?? null;

        // 幂等：同一批目标且仍在运行，直接恢复，避免重复启动造成多套任务争抢
        if ($task && $task['status'] === 'running' && ($task['sig'] ?? '') === $sig) {
            Response::ok([
                'token' => $task['token'],
                'total' => (int)$task['total'],
                'done'  => (int)$task['cursor'],
                'ok' => (int)$task['ok'],
                'bad' => (int)$task['bad'],
                'resumed' => true,
                'batch' => $this->bulkBatchSize(),
            ], '任务进行中，已恢复原进度');
        }

        $task = [
            'token' => bin2hex(random_bytes(8)),
            'ids' => $ids,
            'sig' => $sig,
            'total' => count($ids),
            'cursor' => 0,
            'ok' => 0,
            'bad' => 0,
            'ctx' => $ctx,
            'status' => 'running',
            'started_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $_SESSION[$key] = $task;
        Response::ok([
            'token' => $task['token'],
            'total' => $task['total'],
            'done' => 0,
            'resumed' => false,
            'batch' => $this->bulkBatchSize(),
        ], '已就绪，共 ' . $task['total'] . ' 个域名');
    }

    /** 推进一批：只处理 cursor 起的 N 个，累加进度与统计 */
    protected function bulkAdvance(): void
    {
        $token = (string)Request::post('token', '');
        $sleepMs = max(0, min($this->bulkMaxSleepMs(), (int)Request::post('sleep_ms', 0)));
        $key = $this->bulkKey();
        $task = $_SESSION[$key] ?? null;

        if (!$task || $task['token'] !== $token) {
            Response::fail('批量任务不存在或令牌不匹配，请重新开始');
        }
        if ($task['status'] !== 'running') {
            Response::fail('批量任务已结束或已中断');
        }

        $batch = array_slice($task['ids'], $task['cursor'], $this->bulkBatchSize());
        if ($batch) {
            $r = $this->bulkProcess($batch, $task['ctx'] ?? []);
            $task['ok'] += (int)($r['ok'] ?? 0);
            $task['bad'] += (int)($r['bad'] ?? 0);
            $task['cursor'] += count($batch);
            $task['updated_at'] = date('Y-m-d H:i:s');
        }
        // 兜底：游标已到末尾却没落 status（上一批异常中断等），强制收尾防卡住
        if ($task['cursor'] >= $task['total']) {
            $task['cursor'] = (int)$task['total'];
            if ($task['status'] === 'running') {
                $task['status'] = 'done';
                Audit::log($this->bulkAuditName(), '全库批量', [
                    'total' => $task['total'],
                    'ok' => $task['ok'],
                    'bad' => $task['bad'],
                ]);
            }
        }
        $_SESSION[$key] = $task;

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        Response::ok([
            'done' => (int)$task['cursor'],
            'total' => (int)$task['total'],
            'ok' => (int)$task['ok'],
            'bad' => (int)$task['bad'],
            'status' => $task['status'],
        ]);
    }

    /** 查询当前进度（弹窗重开/页面刷新后恢复） */
    protected function bulkProgress(): void
    {
        $task = $_SESSION[$this->bulkKey()] ?? null;
        if (!$task) {
            Response::ok(['status' => 'none']);
            return;
        }
        Response::ok([
            'token' => $task['token'],
            'done' => (int)$task['cursor'],
            'total' => (int)$task['total'],
            'ok' => (int)$task['ok'],
            'bad' => (int)$task['bad'],
            'status' => $task['status'],
        ]);
    }

    /** 中断任务（停止按钮），已处理部分保留 */
    protected function bulkHalt(): void
    {
        $key = $this->bulkKey();
        $task = $_SESSION[$key] ?? null;
        if ($task && $task['status'] === 'running') {
            $task['status'] = 'stopped';
            $task['updated_at'] = date('Y-m-d H:i:s');
            $_SESSION[$key] = $task;
        }
        Response::ok(['stopped' => true], '已停止');
    }

    private static function bulkSignature(array $ids): string
    {
        return hash('sha256', implode(',', $ids));
    }
}
