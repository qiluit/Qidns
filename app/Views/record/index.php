<?php
use QiDNS\Core\View;
/** @var array $domain @var array $records @var array $types @var array|null $account @var int $targetAccountId @var array $zones @var bool $isEffective @var string $effectiveDns */
$providerLabels = ['dnspod' => 'DNSPod', 'aliyun' => '阿里云', 'west' => '西部数码', 'other' => '第三方 DNS'];
$labelOf = static function (string $p) use ($providerLabels): string {
    return $providerLabels[$p] ?? $p;
};
$liveAccountId = 0;
foreach ($zones as $z) {
    if (!empty($z['live'])) {
        $liveAccountId = (int)$z['account_id'];
        break;
    }
}
?>
<h1 class="page-title">解析记录 <span class="muted" style="font-size:14px"><?= View::e($domain['domain']) ?></span></h1>

<?php if (!empty($zones)): ?>
<div class="zone-switch">
    <span class="zone-switch-label">挂在</span>
    <?php foreach ($zones as $z): ?>
        <?php
        $cls = in_array($z['provider'], ['dnspod', 'aliyun', 'west'], true) ? 'chip-' . $z['provider'] : 'chip-zone-idle';
        $isCur = ((int)$z['account_id'] === $targetAccountId);
        $tip = ($z['account_name'] ?: $labelOf((string)$z['provider']))
             . (!empty($z['live']) ? ' · 正在生效' : ' · 未生效');
        ?>
        <a class="chip zone-chip <?= $cls ?><?= !empty($z['live']) ? ' is-live' : '' ?><?= $isCur ? ' is-current' : '' ?>"
           href="/index.php?r=Record/index&domain_id=<?= (int)$domain['id'] ?>&account_id=<?= (int)$z['account_id'] ?>"
           title="<?= View::e($tip) ?>"><?= View::e($labelOf((string)$z['provider'])) ?></a>
    <?php endforeach; ?>
    <?php if ($targetAccountId === 0): ?>
        <span class="muted" style="font-size:12px">（请先选一个平台）</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($targetAccountId === 0): ?>
<div class="notice notice-warn">
    <strong>没有可操作的解析平台。</strong>该域名当前没有登记任何解析平台的 zone，请先到「服务商账号」同步，或检查它是否已经转手。
</div>
<?php elseif (!$isEffective): ?>
<div class="notice notice-warn">
    <strong>这份 zone 当前没有生效。</strong>
    注册局 NS 实际指向
    <?php if ($effectiveDns !== '' && $effectiveDns !== 'other'): ?>
        <strong><?= View::e($labelOf($effectiveDns)) ?></strong>
    <?php elseif ($effectiveDns === 'other'): ?>
        <strong>第三方 DNS</strong>（<?= View::e((string)$domain['ns_servers']) ?>）
    <?php else: ?>
        <strong>未知（查无 NS）</strong>
    <?php endif; ?>
    ，而你正在操作的是 <strong><?= View::e($account['name'] ?? '该账号') ?></strong> 的
    <strong><?= View::e($labelOf((string)($account['provider'] ?? ''))) ?></strong> 解析平台。
    这里的新增 / 修改 / 删除<strong>只会写进这份 zone，不会影响线上解析</strong>。
    <?php if ($liveAccountId > 0): ?>
        <a href="/index.php?r=Record/index&domain_id=<?= (int)$domain['id'] ?>&account_id=<?= $liveAccountId ?>">去生效的平台</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="toolbar">
    <div>
        <button class="btn btn-primary" onclick="openCreate()"<?= $targetAccountId > 0 ? '' : ' disabled' ?>>+ 新增记录</button>
        <button class="btn" onclick="syncNow()"<?= $targetAccountId > 0 ? '' : ' disabled' ?>>同步线上</button>
        <a class="btn" href="/index.php?r=Record/snapshots&domain_id=<?= (int)$domain['id'] ?>">快照/回滚</a>
    </div>
</div>

<div class="card">
    <table>
        <thead><tr><th>主机记录</th><th>类型</th><th>解析值</th><th>线路</th><th>TTL</th><th>MX优先级</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php if (empty($records)): ?>
            <tr><td colspan="8" class="muted"><?= $targetAccountId > 0 ? '暂无缓存记录，点击「同步线上」从 ' . View::e($account['name'] ?? '该平台') . ' 拉取。' : '没有可操作的解析平台。' ?></td></tr>
        <?php else: ?>
            <?php foreach ($records as $r): ?>
            <tr>
                <td class="mono"><?= View::e($r['rr']) ?></td>
                <td><span class="chip"><?= View::e($r['type']) ?></span></td>
                <td class="mono" style="max-width:300px; word-break:break-all"><?= View::e($r['value']) ?></td>
                <td><?= View::e($r['line']) ?></td>
                <td><?= (int)$r['ttl'] ?></td>
                <td><?= (int)$r['mx'] ?></td>
                <td><span class="chip <?= $r['status'] ? 'chip-ok' : 'chip-danger' ?>"><?= $r['status'] ? '启用' : '停用' ?></span></td>
                <td>
                    <button class="btn btn-sm" onclick="openEdit(<?= View::e(json_encode($r, JSON_UNESCAPED_UNICODE)) ?>)">编辑</button>
                    <button class="btn btn-sm btn-danger" onclick="delRecord(<?= (int)$r['id'] ?>)">删除</button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 新增/编辑弹窗 -->
<div id="recordModal" class="qd-modal-mask" style="display:none">
    <div class="qd-modal qd-record-modal">
        <h2 id="modalTitle">新增记录</h2>
        <p class="qd-modal-sub" id="recordDomain">域名：<?= View::e($domain['domain']) ?>　·　平台：<?= View::e($account['name'] ?? '未选择') ?><?= ($targetAccountId > 0 && !$isEffective) ? '（未生效）' : '' ?>　·　记录变更会直接下发到该平台</p>
        <form id="recordForm">
            <input type="hidden" name="domain_id" value="<?= (int)$domain['id'] ?>">
            <input type="hidden" name="account_id" value="<?= (int)$targetAccountId ?>">
            <input type="hidden" name="id" id="f_id">
            <div class="form-row">
                <div>
                    <label style="margin-top:0">主机记录</label>
                    <input type="text" name="rr" id="f_rr" value="@">
                </div>
                <div>
                    <label style="margin-top:0">类型</label>
                    <select name="type" id="f_type" onchange="toggleMx()">
                        <?php foreach ($types as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <label>解析值 *</label>
            <input type="text" name="value" id="f_value" required>
            <div class="form-row">
                <div>
                    <label>TTL（秒）</label>
                    <input type="number" name="ttl" id="f_ttl" value="600">
                </div>
                <div>
                    <label>线路</label>
                    <select name="line" id="f_line">
                        <option>默认</option><option>电信</option><option>联通</option><option>移动</option>
                        <option>教育网</option><option>海外</option><option>搜索引擎</option>
                    </select>
                </div>
            </div>
            <div id="mxWrap" style="display:none">
                <label>MX 优先级</label>
                <input type="number" name="mx" id="f_mx" value="10">
            </div>
            <label class="qd-dryrun"><input type="checkbox" name="dry_run" value="1" id="f_dryrun"> 预览变更（dry-run，不真正下发）</label>
            <div class="qd-modal-actions">
                <button type="submit" class="btn btn-primary">确定</button>
                <button type="button" class="btn" onclick="closeModal()">取消</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreate(){
    document.getElementById('modalTitle').textContent = '新增记录';
    document.getElementById('f_id').value = '';
    document.getElementById('f_rr').value = '@';
    document.getElementById('f_type').value = 'A';
    document.getElementById('f_value').value = '';
    document.getElementById('f_ttl').value = '600';
    document.getElementById('f_line').value = '默认';
    document.getElementById('f_mx').value = '10';
    toggleMx();
    document.getElementById('recordModal').style.display = 'flex';
}
function openEdit(r){
    document.getElementById('modalTitle').textContent = '编辑记录';
    document.getElementById('f_id').value = r.id;
    document.getElementById('f_rr').value = r.rr;
    document.getElementById('f_type').value = r.type;
    document.getElementById('f_value').value = r.value;
    document.getElementById('f_ttl').value = r.ttl;
    document.getElementById('f_line').value = r.line;
    document.getElementById('f_mx').value = r.mx;
    toggleMx();
    document.getElementById('recordModal').style.display = 'flex';
}
function closeModal(){ document.getElementById('recordModal').style.display = 'none'; }
function toggleMx(){ document.getElementById('mxWrap').style.display = (document.getElementById('f_type').value === 'MX') ? '' : 'none'; }

document.getElementById('recordForm').addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(this);
    var hasId = document.getElementById('f_id').value !== '';
    var url = hasId ? '/index.php?r=Record/update' : '/index.php?r=Record/create';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.onreadystatechange = function(){
        if(xhr.readyState === 4){
            var r = JSON.parse(xhr.responseText);
            if(r.code === 0){
                if(r.data && r.data.preview){
                    closeModal();
                    qidnsAlert(r.data.preview, {title: '预览变更（未下发）'});
                }
                else { location.reload(); }
            } else { qidnsAlert(r.msg, {title: '提交失败'}); }
        }
    };
    xhr.send(fd);
});

function syncNow(){
    qidnsPost('/index.php?r=Domain/syncRecords', {domain_id: <?= (int)$domain['id'] ?>, account_id: <?= (int)$targetAccountId ?>}, function(r){
        if(r && r.code === 0){
            var d = r.data || {};
            var note = d.effective === false
                ? '\n注意：' + (d.account || '该平台') + ' 这份 zone 当前未生效，改动不会影响线上解析。'
                : '';
            qidnsAlert('从 ' + (d.account || '服务商') + ' 同步完成，共 ' + d.count + ' 条解析记录。' + note, {title: '同步成功'}).then(function(){ location.reload(); });
        } else { qidnsAlert(r ? r.msg : '同步失败', {title: '同步失败'}); }
    });
}
function delRecord(id){
    qidnsConfirm('确定删除该记录吗？（会同步删除线上解析）', function(){
        qidnsPost('/index.php?r=Record/delete', {id: id}, function(r){
            if(r && r.code === 0){ location.reload(); }
            else { qidnsAlert(r ? r.msg : '删除失败', {title: '删除失败'}); }
        });
    }, {title: '删除解析记录', okText: '删除', danger: true});
}
</script>
