<?php
use QiDNS\Core\View;
/** @var array $domains @var string $sort @var string $order */
?>
<h1 class="page-title">到期监控</h1>

<div class="toolbar">
    <p class="muted">域名注册到期日（来自 WHOIS / 服务商同步）与 HTTPS 证书到期监控。点击「到期日」表头可切换正序/倒序，默认最快到期在前。</p>
    <button class="btn" onclick="qidnsBulkWhois()">批量刷新 WHOIS</button>
</div>

<div class="card">
    <table>
        <thead><tr>
            <th><a href="<?= View::e(View::sortLink('Monitor', 'domain', $sort, $order)) ?>" class="sort-link">域名<?= View::sortArrow('domain', $sort, $order) ?></a></th>
            <th>注册商</th>
            <th><a href="<?= View::e(View::sortLink('Monitor', 'expire', $sort, $order)) ?>" class="sort-link">到期日<?= View::sortArrow('expire', $sort, $order) ?></a></th>
            <th>剩余天数</th>
            <th>证书检查</th>
        </tr></thead>
        <tbody>
        <?php if (empty($domains)): ?>
            <tr><td colspan="5" class="muted">暂无域名到期信息，请先在「域名管理」中同步或查询到期日。</td></tr>
        <?php else: ?>
            <?php foreach ($domains as $d): ?>
            <tr>
                <td class="mono"><?= View::e($d['domain']) ?></td>
                <td><?= View::e($d['registrar_account_name'] ?: '—') ?></td>
                <td><?= View::e($d['expire_at']) ?></td>
                <td>
                    <?php $left = (int)$d['days_left']; ?>
                    <span class="chip <?= $left <= 7 ? 'chip-danger' : ($left <= 30 ? 'chip-warn' : 'chip-ok') ?>"><?= $left < 0 ? '已过期 ' . abs($left) . ' 天' : $left . ' 天' ?></span>
                </td>
                <td>
                    <button type="button" class="ssl-chip" id="ssl-<?= (int)$d['id'] ?>"
                            data-domain="<?= View::e($d['domain']) ?>"
                            onclick="checkSsl(<?= (int)$d['id'] ?>)"
                            title="点击查看证书到期，再次点击重新查询">检查 SSL</button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
// SSL 检查：点击后原地显示结果标签，再次点击重新查询（无弹窗）
function checkSsl(id){
    var el = document.getElementById('ssl-' + id);
    var domain = el.getAttribute('data-domain') || '';
    el.className = 'ssl-chip loading';
    el.textContent = '查询中…';
    qidnsPost('/index.php?r=Monitor/checkSsl', {domain_id: id}, function(r){
        if (r && r.code === 0) {
            var d = r.data;
            if (d.expire_at) {
                var left = d.days_left;
                el.className = 'ssl-chip ' + (left <= 7 ? 'danger' : (left <= 30 ? 'warn' : 'ok'));
                el.innerHTML = '证书 ' + left + ' 天<small>' + d.expire_at + '</small>';
                el.title = domain + ' 证书到期：' + d.expire_at + '，剩余 ' + left + ' 天。点击重新查询';
            } else {
                el.className = 'ssl-chip none';
                el.textContent = '无证书';
                el.title = domain + ' 未获取到证书（未部署 HTTPS 或端口不可达）。点击重新查询';
            }
        } else {
            el.className = 'ssl-chip none';
            el.textContent = '查询失败';
            el.title = (r ? r.msg : '网络错误') + '。点击重新查询';
        }
    });
}
</script>
