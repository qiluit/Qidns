<?php
use QiDNS\Core\View;
/** @var array $groups @var array $providers @var string $provider @var string $keyword
 *  @var string $suffix @var array $suffixOptions @var string $sort @var string $order @var array $stat */
$statusText = [1 => '急需续费', 2 => '急需赎回', 3 => '正常', 4 => '不可赎回'];
// 排序链接需透传当前筛选条件
$filter = [];
if ($provider !== '') $filter['provider'] = $provider;
if ($keyword !== '') $filter['keyword'] = $keyword;
if ($suffix !== '') $filter['suffix'] = $suffix;
?>
<h1 class="page-title">DNS 管理 <span class="muted" style="font-size:14px">域名真实归属</span></h1>

<div class="notice">
    <strong>归类口径</strong>：只按域名<strong>真实归属服务商（注册商）</strong>分组。域名 DNS 托管到哪家不影响归属，
    「在用 DNS」列仅为 NS 实证参考。例如在西部数码注册、NS 指向阿里云的域名，仍归在「西部数码」下。
    归属未确认、以及失效域名（已过期进入赎回期 / 已转手 / 我方无持有且 NS 不指向我方 / 疑似未注册）均不混入任何服务商分组。
</div>

<div class="stats">
    <div class="stat"><div class="num"><?= (int)$stat['owned'] ?></div><div class="label">真实持有域名</div></div>
    <div class="stat"><div class="num"><?= (int)$stat['redeem'] ?></div><div class="label">急需赎回</div></div>
    <div class="stat"><div class="num"><?= (int)$stat['renew'] ?></div><div class="label">急需续费</div></div>
    <div class="stat"><div class="num"><a href="/index.php?r=Domain&view=hosting&ownership=unknown" style="color:inherit;text-decoration:none"><?= (int)$stat['unknown'] ?></a></div><div class="label">归属未确认 → 去甄别</div></div>
    <?php if ((int)$stat['stale'] > 0): ?>
    <div class="stat"><div class="num"><?= (int)$stat['stale'] ?></div><div class="label">失效已排除</div></div>
    <?php endif; ?>
</div>

<div class="card" style="padding:16px 20px">
    <form method="get" action="/index.php" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end">
        <input type="hidden" name="r" value="Registry">
        <div style="min-width:170px">
            <label style="margin-top:0">归属服务商</label>
            <select name="provider">
                <option value="">全部服务商</option>
                <?php foreach ($providers as $code => $label): ?>
                    <option value="<?= $code ?>" <?= $provider === $code ? 'selected' : '' ?>><?= View::e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="min-width:220px">
            <label style="margin-top:0">关键词</label>
            <input type="text" name="keyword" value="<?= View::e($keyword) ?>" placeholder="域名 / 备注模糊搜索">
        </div>
        <div style="min-width:130px">
            <label style="margin-top:0">后缀</label>
            <select name="suffix">
                <option value="">全部后缀</option>
                <?php foreach ($suffixOptions as $o): ?>
                    <option value="<?= View::e($o['value']) ?>" <?= $suffix === $o['value'] ? 'selected' : '' ?>><?= View::e($o['label']) ?> (<?= (int)$o['count'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">检索</button>
        <?php if ($provider !== '' || $keyword !== '' || $suffix !== ''): ?>
            <a href="/index.php?r=Registry" class="btn">重置</a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($groups)): ?>
    <div class="card"><p class="muted">没有符合条件的域名。请先在「服务商账号」中同步域名。</p></div>
<?php endif; ?>

<?php foreach ($groups as $code => $rows): ?>
<div class="card">
    <h2 class="section-title" style="margin-top:0">
        <?= View::e($providers[$code] ?? $code) ?>
        <span class="chip"><?= count($rows) ?></span>
        <span class="muted" style="font-size:12px;font-weight:400">注册商代码 <?= View::e($code) ?></span>
    </h2>
    <table>
        <thead><tr>
            <th><a href="<?= View::e(View::sortLink('Registry', 'domain', $sort, $order, $filter)) ?>" class="sort-link">域名<?= View::sortArrow('domain', $sort, $order) ?></a></th>
            <th><a href="<?= View::e(View::sortLink('Registry', 'expire', $sort, $order, $filter)) ?>" class="sort-link">到期日<?= View::sortArrow('expire', $sort, $order) ?></a></th>
            <th>剩余</th>
            <th>注册状态</th>
            <th>在用 DNS（NS实证）</th>
            <th>记录数</th>
            <th>操作</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $d): ?>
            <tr>
                <td class="mono"><?= View::e($d['domain']) ?></td>
                <td>
                    <?php if ($d['expire_at']): ?>
                        <span class="chip <?= ($d['days_left'] !== null && $d['days_left'] <= 30) ? 'chip-warn' : '' ?>"><?= View::e($d['expire_at']) ?></span>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($d['days_left'] !== null): ?>
                        <?php $dl = (int)$d['days_left']; ?>
                        <span class="chip <?= $dl <= 7 ? 'chip-danger' : ($dl <= 30 ? 'chip-warn' : '') ?>"><?= $dl < 0 ? '已过期 ' . abs($dl) : $dl ?> 天</span>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
                <td>
                    <?php $st = (int)$d['domain_status']; ?>
                    <?php if ($st === 2): ?>
                        <span class="chip chip-danger"><?= $statusText[$st] ?></span>
                    <?php elseif ($st === 1): ?>
                        <span class="chip chip-warn"><?= $statusText[$st] ?></span>
                    <?php elseif ($st === 4): ?>
                        <span class="chip chip-danger"><?= $statusText[$st] ?></span>
                    <?php elseif ($st === 3): ?>
                        <span class="chip chip-ok">正常</span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($d['effective_dns']): ?>
                        <span class="chip"><?= View::e($providers[$d['effective_dns']] ?? ($d['effective_dns'] === 'other' ? '第三方 DNS' : $d['effective_dns'])) ?></span>
                    <?php else: ?><span class="muted">查无 NS</span><?php endif; ?>
                </td>
                <td><?= (int)$d['record_count'] ?></td>
                <td>
                    <a class="btn btn-sm" href="/index.php?r=Record/index&domain_id=<?= (int)$d['id'] ?>">解析记录</a>
                    <button class="btn btn-sm btn-primary" onclick="openNsModal(<?= (int)$d['id'] ?>, '<?= View::e($d['domain']) ?>')">设置 NS</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>

<!-- 设置 NS 弹窗（统一走全站 .qd-modal 体系，不再用原生 confirm/alert） -->
<div id="nsModal" class="qd-modal-mask" style="display:none" aria-hidden="true">
    <div class="qd-modal qd-ns-modal" role="dialog" aria-modal="true" aria-labelledby="nsTitle">
        <h2 id="nsTitle">修改域名 NS</h2>
        <p class="qd-modal-sub" id="nsDomain"></p>
        <div class="notice">
            修改注册商处的 NS 将<strong>接管该域名的解析权</strong>。请确认新 NS 服务商已配置好完整解析记录，
            否则域名可能中断。修改后 24~48 小时全球生效。此操作会记入审计日志。
        </div>
        <form id="nsForm" autocomplete="off">
            <input type="hidden" name="id" id="ns_id">
            <label for="ns_input">新 NS 服务器（2~6 个，每行一个）</label>
            <textarea name="ns" id="ns_input" rows="4" class="qd-ns-input" placeholder="dns9.hichina.com&#10;dns10.hichina.com"></textarea>
            <p class="qd-ns-hint" id="nsCurrent"></p>
            <label class="qd-ns-ack"><input type="checkbox" id="nsAck"> 我已核对新 NS 地址，确认写入注册商</label>
            <div class="msg" id="nsMsg"></div>
            <div class="qd-modal-actions">
                <button type="submit" class="btn btn-primary" id="nsSubmit">提交修改</button>
                <button type="button" class="btn" id="nsCancel">取消</button>
            </div>
        </form>
    </div>
</div>

<script>
var nsLoading = false;   // 请求进行中
var nsDone = false;      // 已成功提交（此时主按钮变「完成」）

function nsMsg(kind, text) {
    var el = document.getElementById('nsMsg');
    el.className = 'msg' + (kind ? ' ' + kind : '');
    el.textContent = text || '';
}

function openNsModal(id, domain) {
    nsLoading = false;
    nsDone = false;
    document.getElementById('ns_id').value = id;
    document.getElementById('nsDomain').textContent = '域名：' + domain;
    document.getElementById('ns_input').value = '';
    document.getElementById('nsAck').checked = false;
    document.getElementById('nsCurrent').textContent = '正在读取注册商当前 NS…';
    document.getElementById('nsSubmit').disabled = false;
    document.getElementById('nsSubmit').textContent = '提交修改';
    document.getElementById('nsCancel').textContent = '取消';
    nsMsg('', '');
    document.getElementById('nsModal').style.display = 'flex';
    qidnsPost('/index.php?r=Domain/currentNs', {id: id}, function(r){
        if (nsDone) return;                                  // 弹窗状态已切换，丢弃旧回调
        if (r && r.code === 0 && r.data.ns && r.data.ns.length) {
            document.getElementById('ns_input').value = r.data.ns.join('\n');
            document.getElementById('nsCurrent').textContent = '当前注册商 NS：' + r.data.ns.join(', ');
        } else {
            document.getElementById('nsCurrent').textContent = (r && r.data && r.data.hint) ? r.data.hint : '未读取到当前 NS，请手动填写';
        }
    });
}

function closeNsModal() {
    if (nsLoading) return;                                   // 提交中不允许关闭，避免误判结果
    document.getElementById('nsModal').style.display = 'none';
}

document.getElementById('nsCancel').addEventListener('click', function(){ closeNsModal(); });

// 点遮罩空白处 / 按 Esc 关闭
document.getElementById('nsModal').addEventListener('mousedown', function(e){
    if (e.target === this) closeNsModal();
});
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && document.getElementById('nsModal').style.display === 'flex') closeNsModal();
});

document.getElementById('nsForm').addEventListener('submit', function(e){
    e.preventDefault();
    var btn = document.getElementById('nsSubmit');

    if (nsDone) { closeNsModal(); return; }                  // 「完成」：直接收起
    if (nsLoading) return;

    var ns = document.getElementById('ns_input').value.trim();
    if (!ns) { nsMsg('error', '请填写 NS 服务器'); document.getElementById('ns_input').focus(); return; }
    if (!document.getElementById('nsAck').checked) { nsMsg('error', '请先勾选「我已核对新 NS 地址」再提交'); return; }

    nsLoading = true;
    btn.disabled = true;
    btn.textContent = '提交中…';
    nsMsg('', '正在写入注册商，请稍候…');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/index.php?r=Domain/setNameservers');
    xhr.timeout = 60000;
    function restore(label) {
        nsLoading = false;
        btn.disabled = false;
        btn.textContent = label;
    }
    xhr.onload = function(){
        var r = null;
        try { r = JSON.parse(xhr.responseText); } catch (err) {}
        if (!r) { restore('重新提交'); nsMsg('error', '返回数据异常，请重试'); return; }
        if (r.code === 0) {
            nsDone = true;
            restore('完成');
            document.getElementById('nsCancel').textContent = '关闭';
            nsMsg('success', r.msg + '。');
        } else {
            restore('重新提交');
            nsMsg('error', r.msg);
        }
    };
    xhr.onerror = function(){ restore('重新提交'); nsMsg('error', '网络错误，请稍后重试'); };
    xhr.ontimeout = function(){ restore('重新提交'); nsMsg('error', '请求超时（注册商接口较慢时可能已受理，请稍后刷新核对）'); };
    xhr.send(new FormData(this));
});
</script>
