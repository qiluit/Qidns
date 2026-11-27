<?php
use QiDNS\Core\View;
/** @var array $accounts @var array $providers */
?>
<h1 class="page-title">服务商账号</h1>

<div class="toolbar">
    <p class="muted">管理各家服务商的 API 凭证，凭证加密存储。腾讯云 DNSPod 的两套 API 填在同一个账号里（至少一套）。</p>
    <a href="/index.php?r=Account/add" class="btn btn-primary">+ 添加账号</a>
</div>

<div class="card">
    <table>
        <thead><tr><th>账号名</th><th>服务商</th><th>域名数</th><th>最近同步</th><th>操作</th></tr></thead>
        <tbody>
        <?php if (empty($accounts)): ?>
            <tr><td colspan="5" class="muted">暂无账号，请先添加。</td></tr>
        <?php else: ?>
            <?php foreach ($accounts as $a): ?>
            <tr>
                <td><?= View::e($a['name']) ?></td>
                <td><span class="chip"><?= View::e($providers[$a['provider']] ?? $a['provider']) ?></span></td>
                <td><?= (int)$a['domain_count'] ?></td>
                <td class="muted"><?= $a['last_sync_at'] ? View::e($a['last_sync_at']) : '—' ?></td>
                <td class="td-nowrap">
                    <button class="btn btn-sm" onclick="syncAccount(<?= (int)$a['id'] ?>, <?= View::e(json_encode((string)$a['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)) ?>)">同步域名</button>
                    <button class="btn btn-sm" onclick="testAccount(<?= (int)$a['id'] ?>)">测试</button>
                    <a class="btn btn-sm" href="/index.php?r=Account/edit&id=<?= (int)$a['id'] ?>">编辑</a>
                    <button class="btn btn-sm btn-danger" onclick="delAccount(<?= (int)$a['id'] ?>)">删除</button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 同步进度弹窗：阶段清单 + 总进度条 + 已用时 -->
<div id="qdSyncModal" class="qd-modal-mask" style="display:none">
    <div class="qd-modal">
        <h2 id="qdSyncTitle">同步域名</h2>
        <p class="muted" style="margin:2px 0 14px">从服务商拉取域名、刷新在用 NS 并重算归属，过程中可随时停止或关闭。</p>
        <div class="qd-progress"><div class="qd-progress-bar" id="qdSyncBar"></div></div>
        <div class="qd-sync-meta">
            <span id="qdSyncNote">准备中…</span>
            <span id="qdSyncElapsed" class="qd-sync-elapsed"></span>
        </div>
        <div class="qd-steps" id="qdSyncSteps"></div>
        <div class="qd-modal-actions">
            <button type="button" class="btn btn-primary" id="qdSyncResume" style="display:none" onclick="resumeSync()">继续</button>
            <button type="button" class="btn" id="qdSyncStop" disabled onclick="stopSync()">停止</button>
            <button type="button" class="btn" id="qdSyncClose" onclick="closeSyncModal()">关闭</button>
        </div>
    </div>
</div>

<script>
/* 同步任务：后端分阶段推进，这里只负责串请求与渲染进度 */
var qdSync = { id: 0, name: '', token: '', round: 0, running: false, finished: false,
               timer: null, ticker: null, startedAt: 0, fails: 0 };

function syncAccount(id, name){
    var myRound = ++qdSync.round;      // 作废在途回调
    syncResetTimer();
    qdSync.id = id;
    qdSync.name = name || '';
    qdSync.token = '';
    qdSync.running = true;
    qdSync.finished = false;
    qdSync.fails = 0;
    qdSync.startedAt = Date.now();

    document.getElementById('qdSyncTitle').textContent = '同步域名' + (qdSync.name ? ' · ' + qdSync.name : '');
    document.getElementById('qdSyncSteps').innerHTML = '';
    document.getElementById('qdSyncNote').textContent = '正在启动…';
    document.getElementById('qdSyncElapsed').textContent = '';
    qdSyncBarSet(0, true);
    document.getElementById('qdSyncModal').style.display = 'flex';
    syncButtons();
    startSyncTicker();

    qidnsPost('/index.php?r=Account/syncStart', {id: id}, function(r){
        if (myRound !== qdSync.round || !qdSync.running) return;
        if (!r || r.code !== 0) {
            syncAbort('启动失败：' + (r ? r.msg : '网络错误'));
            return;
        }
        var d = r.data;
        qdSync.token = d.token;
        if (d.elapsed > 0) { qdSync.startedAt = Date.now() - d.elapsed * 1000; }  // 续跑时接上已用时
        renderSyncState(d);
        if (d.status === 'done') { syncFinished(d); return; }
        if (d.status === 'stopped' || d.status === 'error') { syncPaused(); return; }
        syncStep();
    });
}

function syncStep(){
    if (!qdSync.running) return;
    var myRound = qdSync.round;
    qidnsPost('/index.php?r=Account/syncStep', {id: qdSync.id, token: qdSync.token}, function(r){
        if (myRound !== qdSync.round || !qdSync.running) return;
        if (!r || r.code !== 0) {
            qdSync.fails++;
            if (qdSync.fails <= 3) {   // 网络抖动先重试，避免整轮同步白跑
                document.getElementById('qdSyncNote').textContent = '连接中断，重试 ' + qdSync.fails + '/3…';
                qdSync.timer = setTimeout(syncStep, 700);
            } else {
                syncAbort('连接中断：' + (r ? r.msg : '网络错误') + '。点「继续」可从断点恢复。');
            }
            return;
        }
        qdSync.fails = 0;
        var d = r.data;
        renderSyncState(d);
        if (d.status === 'done') { syncFinished(d); return; }
        if (d.status === 'stopped' || d.status === 'error') { syncPaused(); return; }
        qdSync.timer = setTimeout(syncStep, 60);   // 留出重绘间隔，进度条可见跳动
    });
}

function renderSyncState(d){
    document.getElementById('qdSyncSteps').innerHTML = (d.steps || []).map(function(s){
        return '<div class="qd-step ' + s.state + '">' +
                 '<span class="qd-step-dot"></span>' +
                 '<span class="qd-step-name">' + qdEsc(s.label) + '</span>' +
                 '<span class="qd-step-detail">' + qdEsc(s.detail || '') + '</span>' +
               '</div>';
    }).join('');

    qdSyncBarSet(d.status === 'done' ? 100 : d.percent, !!d.indeterminate);

    var note = d.msg;
    if (!note) {
        note = d.indeterminate
            ? d.phase_label + '，请稍候…'
            : d.phase_label + (d.total > 0 ? '：' + d.done + ' / ' + d.total : '…');
    }
    document.getElementById('qdSyncNote').textContent = note;
    syncButtons();
}

/** 按钮随状态切换：运行中只有「停止」，暂停/中断给「继续」，完成后变「查看结果」 */
function syncButtons(){
    document.getElementById('qdSyncStop').style.display = qdSync.running ? '' : 'none';
    document.getElementById('qdSyncResume').style.display = (!qdSync.running && !qdSync.finished) ? '' : 'none';
    document.getElementById('qdSyncClose').textContent = qdSync.finished ? '查看结果' : '关闭';
}

function qdSyncBarSet(pct, indeterminate){
    var bar = document.getElementById('qdSyncBar');
    var wrap = bar.parentNode;
    if (indeterminate) {
        wrap.classList.add('indeterminate');
        bar.textContent = '';
        return;
    }
    wrap.classList.remove('indeterminate');
    bar.style.width = pct + '%';
    bar.textContent = pct >= 8 ? pct + '%' : '';
    if (pct >= 100) { bar.classList.add('done'); } else { bar.classList.remove('done'); }
}

function syncFinished(d){
    qdSync.running = false;
    qdSync.finished = true;
    stopSyncTicker();
    syncResetTimer();
    var c = d.counts || {};
    var parts = ['域名 ' + (c.zones || 0) + ' 个'];
    if (c.owned) { parts.push('注册商持有 ' + c.owned + ' 个'); }
    if (c.excluded) { parts.push('清理残留 zone ' + c.excluded + ' 个'); }
    if (c.stale) { parts.push('判定失效 ' + c.stale + ' 个'); }
    document.getElementById('qdSyncNote').textContent = (d.msg ? d.msg + ' ' : '') + '同步完成：' + parts.join('，') + '。';
    document.getElementById('qdSyncElapsed').textContent = '用时 ' + syncElapsed() + ' 秒';
    syncButtons();
}

/** 停止 / 出错：保留进度，给「继续」 */
function syncPaused(){
    qdSync.running = false;
    stopSyncTicker();
    syncResetTimer();
    syncButtons();
}

function syncAbort(msg){
    qdSync.running = false;
    stopSyncTicker();
    syncResetTimer();
    document.getElementById('qdSyncNote').textContent = msg;
    syncButtons();
}

function resumeSync(){
    syncAccount(qdSync.id, qdSync.name);   // syncStart 对未完成任务是「续跑」，不重置游标
}

function stopSync(){
    if (!qdSync.running) return;
    qdSync.round++;
    syncResetTimer();
    qidnsPost('/index.php?r=Account/syncStop', {id: qdSync.id}, function(r){
        qdSync.running = false;
        stopSyncTicker();
        if (r && r.code === 0 && r.data && r.data.steps) {
            renderSyncState(r.data);
            syncPaused();
        } else {
            syncAbort('已停止，点「继续」从断点恢复。');
        }
    });
}

function closeSyncModal(){
    if (qdSync.running) {
        qidnsConfirm('同步正在进行中，停止并关闭吗？（已同步部分会保留，下次可继续）', function(){
            qdSync.round++;
            syncResetTimer();
            qidnsPost('/index.php?r=Account/syncStop', {id: qdSync.id}, function(){});
            qdSync.running = false;
            stopSyncTicker();
            document.getElementById('qdSyncModal').style.display = 'none';
        }, {title: '停止同步', okText: '停止并关闭', danger: true});
        return;
    }
    var needReload = qdSync.finished;
    document.getElementById('qdSyncModal').style.display = 'none';
    if (needReload) { location.reload(); }
}

function syncResetTimer(){
    if (qdSync.timer) { clearTimeout(qdSync.timer); qdSync.timer = null; }
}

/** 已用时：让「卡住」和「正在跑」一眼可分 */
function syncElapsed(){
    return Math.max(0, Math.round((Date.now() - qdSync.startedAt) / 1000));
}
function startSyncTicker(){
    stopSyncTicker();
    qdSync.ticker = setInterval(function(){
        if (!qdSync.running) return;
        document.getElementById('qdSyncElapsed').textContent = '已用时 ' + syncElapsed() + ' 秒';
    }, 1000);
}
function stopSyncTicker(){
    if (qdSync.ticker) { clearInterval(qdSync.ticker); qdSync.ticker = null; }
}

function testAccount(id){
    qidnsPost('/index.php?r=Account/test', {id: id}, function(r){
        if(r && r.code === 0){ qidnsAlert('账号凭证可用，API 连接正常。', {title: '连接成功'}); }
        else { qidnsAlert(r ? r.msg : '请求失败', {title: '连接失败'}); }
    });
}
function delAccount(id){
    qidnsConfirm('确定删除该账号及其域名、记录缓存吗？', function(){
        qidnsPost('/index.php?r=Account/delete', {id: id}, function(r){
            if(r && r.code === 0){ location.reload(); }
            else { qidnsAlert(r ? r.msg : '删除失败', {title: '删除失败'}); }
        });
    }, {title: '删除账号', okText: '删除', danger: true});
}
</script>
