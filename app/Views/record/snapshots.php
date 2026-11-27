<?php
use QiDNS\Core\View;
/** @var array $snapshots @var int $domainId */
$providerLabels = ['dnspod' => 'DNSPod', 'aliyun' => '阿里云', 'west' => '西部数码', 'other' => '第三方 DNS'];
?>
<h1 class="page-title">解析快照 / 回滚</h1>
<p class="muted">每次「同步记录」前都会自动保存快照，可一键回滚到历史版本。回滚会写回该快照原本所在的解析平台。</p>

<div class="card">
    <table>
        <thead><tr><th>ID</th><th>域名</th><th>服务商</th><th>原因</th><th>时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php if (empty($snapshots)): ?>
            <tr><td colspan="6" class="muted">暂无快照。</td></tr>
        <?php else: ?>
            <?php foreach ($snapshots as $s): ?>
            <tr>
                <td><?= (int)$s['id'] ?></td>
                <td class="mono"><?= View::e($s['domain']) ?></td>
                <td><span class="chip"><?= View::e($providerLabels[$s['provider']] ?? $s['provider']) ?></span></td>
                <td><?= View::e($s['reason']) ?></td>
                <td class="muted"><?= View::e($s['created_at']) ?></td>
                <td>
                    <button class="btn btn-sm btn-danger" onclick="rollback(<?= (int)$s['id'] ?>)">回滚到此版本</button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function rollback(id){
    qidnsConfirm('回滚将删除线上当前所有记录，并按该快照重建。确定吗？', function(){
        qidnsPost('/index.php?r=Record/rollback', {snapshot_id: id}, function(r){
            if(r && r.code === 0){
                qidnsAlert('已按该快照回滚线上解析记录。', {title: '回滚成功'}).then(function(){ location.reload(); });
            } else { qidnsAlert(r ? r.msg : '回滚失败', {title: '回滚失败'}); }
        });
    }, {title: '回滚解析记录', okText: '回滚', danger: true});
}
</script>
