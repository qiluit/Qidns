<?php
use QiDNS\Core\View;
/** @var string $view @var array $groups @var array $providers @var string $provider @var string $keyword
 *  @var string $suffix @var array $suffixOptions @var string $ownership @var string $sort @var string $order
 *  @var array $stat @var array $noNs @var array $tagColors */
$filter = ['view' => 'hosting'];
if ($provider !== '') $filter['provider'] = $provider;
if ($keyword !== '') $filter['keyword'] = $keyword;
if ($suffix !== '') $filter['suffix'] = $suffix;
if ($ownership !== '') $filter['ownership'] = $ownership;
$providerLabels = ['dnspod' => 'DNSPod', 'aliyun' => '阿里云', 'west' => '西部数码', 'other' => '第三方 DNS'];
?>
<h1 class="page-title">域名管理</h1>

<!-- 视图切换 -->
<div class="view-tabs">
    <a href="/index.php?r=Domain" class="view-tab">全部域名</a>
    <a href="/index.php?r=Domain&view=hosting" class="view-tab active">按在用 DNS 分组</a>
</div>

<div class="notice">
    <strong>当前视图</strong>：按注册局 <strong>NS 实证的当前在用 DNS</strong> 分组，NS 指向哪家就归到哪家，注册商是谁不影响归类。
    「归属确认」列用于甄别：有注册商确认为「真实持有」；注册商未确认的多为历史残留解析 zone。
    失效域名（已转手 / 我方无持有且 NS 不指向我方 / 疑似未注册）已排除。
</div>

<div class="stats">
    <div class="stat"><div class="num"><?= (int)$stat['total'] ?></div><div class="label">在用 DNS 域名</div></div>
    <div class="stat"><div class="num"><?= (int)$stat['owned'] ?></div><div class="label">真实持有</div></div>
    <div class="stat"><div class="num"><?= (int)$stat['unknown'] ?></div><div class="label">归属未确认（疑似残留）</div></div>
    <div class="stat"><div class="num"><?= (int)$stat['no_ns'] ?></div><div class="label">查无 NS（未分组）</div></div>
    <?php if ((int)$stat['stale'] > 0): ?>
    <div class="stat"><div class="num"><?= (int)$stat['stale'] ?></div><div class="label">失效已排除</div></div>
    <?php endif; ?>
</div>

<?php if (!empty($noNs)): ?>
<div class="card" style="padding:14px 20px">
    <h2 class="section-title" style="margin-top:0;font-size:15px">查无 NS（有持有或到期日，暂不归入服务商分组）</h2>
    <p class="muted" style="margin:6px 0 10px">下列域名注册局查不到 NS 记录，但我方持有列表里仍有它，未判定失效，不误杀；待 NS 生效后会自动归入对应服务商。</p>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ($noNs as $n): ?>
            <span class="chip" title="注册商:<?= View::e($n['registrar'] ?: '未确认') ?> / 到期:<?= View::e($n['expire_at'] ?: '无') ?>"><?= View::e($n['domain']) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card" style="padding:16px 20px">
    <form method="get" action="/index.php" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end">
        <input type="hidden" name="r" value="Domain">
        <input type="hidden" name="view" value="hosting">
        <div style="min-width:170px">
            <label style="margin-top:0">DNS 服务商</label>
            <select name="provider">
                <option value="">全部服务商</option>
                <?php foreach ($providers as $code => $label): ?>
                    <option value="<?= $code ?>" <?= $provider === $code ? 'selected' : '' ?>><?= View::e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="min-width:160px">
            <label style="margin-top:0">归属甄别</label>
            <select name="ownership">
                <option value="">全部</option>
                <option value="owned" <?= $ownership === 'owned' ? 'selected' : '' ?>>仅真实持有</option>
                <option value="unknown" <?= $ownership === 'unknown' ? 'selected' : '' ?>>仅归属未确认</option>
            </select>
        </div>
        <div style="min-width:200px">
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
        <?php if ($provider !== '' || $keyword !== '' || $suffix !== '' || $ownership !== ''): ?>
            <a href="/index.php?r=Domain&view=hosting" class="btn">重置</a>
        <?php endif; ?>
        <button type="button" class="btn" onclick="refreshNs(0)" title="重探NS并结合各账号API权限，重算哪些域名仍属于我">刷新归属判定</button>
    </form>
</div>

<?php if (empty($groups)): ?>
    <div class="card"><p class="muted">没有符合条件的域名。</p></div>
<?php endif; ?>

<?php foreach ($groups as $code => $rows): ?>
<div class="card">
    <h2 class="section-title" style="margin-top:0">
        <?= View::e($providers[$code] ?? $code) ?>
        <span class="chip"><?= count($rows) ?></span>
        <span class="muted" style="font-size:12px;font-weight:400">在用 DNS 代码 <?= View::e($code) ?></span>
    </h2>
    <table>
        <thead><tr>
            <th><a href="<?= View::e(View::sortLink('Domain', 'domain', $sort, $order, $filter)) ?>" class="sort-link">域名<?= View::sortArrow('domain', $sort, $order) ?></a></th>
            <th>真实归属（参考）</th>
            <th>归属确认</th>
            <th>NS 实证依据</th>
            <th>标签</th>
            <th><a href="<?= View::e(View::sortLink('Domain', 'expire', $sort, $order, $filter)) ?>" class="sort-link">到期日<?= View::sortArrow('expire', $sort, $order) ?></a></th>
            <th>记录数</th>
            <th>操作</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $d): ?>
            <tr>
                <td class="mono"><?= View::e($d['domain']) ?> <button type="button" class="whois-mini" onclick="openWhois(<?= (int)$d['id'] ?>)" title="WHOIS 查询（注册局数据）">WHOIS</button></td>
                <td><?= View::registrarChip((string)$d['registrar'], (string)($d['whois_registrar'] ?? ''), $providerLabels) ?></td>
                <td>
                    <?php if ($d['registrar']): ?>
                        <span class="chip chip-ok">真实持有</span>
                    <?php else: ?>
                        <span class="chip chip-warn">归属未确认</span>
                    <?php endif; ?>
                </td>
                <td class="mono muted" style="font-size:11px;max-width:220px;word-break:break-all"><?= View::e($d['ns_servers'] ?: '—') ?></td>
                <td class="tag-cell"><?= View::tagCell($d['tag_list'], (int)$d['id'], $tagColors ?? []) ?></td>
                <td>
                    <?php if ($d['expire_at']): ?>
                        <span class="chip <?= ($d['days_left'] !== null && $d['days_left'] <= 30) ? 'chip-warn' : '' ?>"><?= View::e($d['expire_at']) ?></span>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
                <td><?= (int)$d['record_count'] ?></td>
                <td>
                    <div class="row-actions">
                        <a class="btn btn-sm" href="/index.php?r=Record/index&domain_id=<?= (int)$d['id'] ?>">解析记录</a>
                        <button class="btn btn-sm" onclick="syncRecords(<?= (int)$d['id'] ?>)">同步</button>
                        <button class="btn btn-sm" onclick="refreshNs(<?= (int)$d['id'] ?>, '<?= View::e($d['domain']) ?>')" title="重新判定该域名是否仍属于我">归属</button>
                        <button class="btn btn-sm" onclick="checkExpire(<?= (int)$d['id'] ?>)">查到期</button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>

<script>
// 复用域名管理的批量判定进度流程（统一在 app.js）
function syncRecords(id){
    qidnsPost('/index.php?r=Domain/syncRecords', {domain_id: id}, function(r){
        if(r && r.code === 0){
            qidnsAlert('同步完成，共 ' + r.data.count + ' 条解析记录。', {title: '同步成功'}).then(function(){ location.reload(); });
        } else { qidnsAlert(r ? r.msg : '同步失败', {title: '同步失败'}); }
    });
}
function refreshNs(id, domain){ qidnsRefreshNs(id, domain); }
function checkExpire(id){
    qidnsPost('/index.php?r=Domain/checkExpire', {domain_id: id}, function(r){
        if(r && r.code === 0){
            var d = r.data;
            var text = d.expire_at
                ? '到期日：' + d.expire_at + '\n' + (d.days_left < 0 ? '已过期 ' + Math.abs(d.days_left) + ' 天' : '剩余 ' + d.days_left + ' 天')
                : '未从 WHOIS 查询到到期日。';
            qidnsAlert(text, {title: '域名到期'}).then(function(){ location.reload(); });
        } else { qidnsAlert(r ? r.msg : '查询失败', {title: '查询失败'}); }
    });
}
</script>
