<?php
use QiDNS\Core\View;
/** @var int $accountCount @var int $domainCount @var int $staleCount @var int $recordCount @var array $providerStats @var array $registrarStats @var array $expiringDomains @var array $recentAudit */
$names = ['dnspod' => '腾讯云 DNSPod', 'aliyun' => '阿里云 DNS', 'west' => '西部数码', 'other' => '第三方 DNS'];
$colors = ['dnspod' => '#0ea5e9', 'aliyun' => '#f97316', 'west' => '#4356b5', 'other' => '#94a3b8'];

// 托管分布：合计与 conic-gradient 色段（纯 CSS 环形图）
$hostTotal = 0;
foreach ($providerStats as $s) { $hostTotal += (int)$s['cnt']; }
$stops = [];
$offset = 0.0;
foreach ($providerStats as $s) {
    $pct = $hostTotal > 0 ? (int)$s['cnt'] / $hostTotal * 100 : 0;
    $color = $colors[$s['provider']] ?? $colors['other'];
    $stops[] = $color . ' ' . round($offset, 3) . '% ' . round($offset + $pct, 3) . '%';
    $offset += $pct;
}
$donut = $hostTotal > 0 ? 'conic-gradient(' . implode(',', $stops) . ')' : '#eef2f7';

// 注册商分布：合计
$regTotal = 0;
foreach ($registrarStats as $s) { $regTotal += (int)$s['cnt']; }

$actionLabels = [
    'add_account' => '新增账号', 'update_account' => '编辑账号', 'delete_account' => '删除账号',
    'sync_domains' => '同步域名', 'bulk_refresh_ns' => '批量归属判定', 'refresh_ns' => '刷新归属',
    'save_template' => '保存模板', 'apply_template' => '应用模板', 'delete_template' => '删除模板',
    'check_expire' => '查到期', 'login' => '登录',
];
?>
<h1 class="page-title">仪表盘</h1>

<div class="stats stats-rich">
    <div class="stat stat-accounts">
        <div class="stat-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><circle cx="7" cy="7.5" r="1"/><circle cx="7" cy="16.5" r="1"/></svg>
        </div>
        <div class="stat-body"><div class="num"><?= (int)$accountCount ?></div><div class="label">服务商账号</div></div>
    </div>
    <div class="stat stat-domains">
        <div class="stat-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.7 4 6 4 9s-1.4 6.3-4 9c-2.6-2.7-4-6-4-9s1.4-6.3 4-9z"/></svg>
        </div>
        <div class="stat-body"><div class="num"><?= (int)$domainCount ?></div><div class="label">有效域名</div></div>
    </div>
    <div class="stat stat-records">
        <div class="stat-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16"/><circle cx="4" cy="6" r="1" fill="currentColor"/><circle cx="4" cy="12" r="1" fill="currentColor"/><circle cx="4" cy="18" r="1" fill="currentColor"/></svg>
        </div>
        <div class="stat-body"><div class="num"><?= (int)$recordCount ?></div><div class="label">解析记录</div></div>
    </div>
    <?php if ($staleCount > 0): ?>
    <a class="stat stat-stale" href="/index.php?r=Domain&show_stale=1" style="text-decoration:none">
        <div class="stat-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2.5 20h19L12 3z"/><path d="M12 9v5M12 17.5v.01"/></svg>
        </div>
        <div class="stat-body"><div class="num"><?= (int)$staleCount ?></div><div class="label">失效已排除 →</div></div>
    </a>
    <?php endif; ?>
</div>

<div class="dash-2col">
    <!-- 在用 DNS 分布：环形图 + 图例（口径=NS 实际指向的平台） -->
    <div class="card">
        <h2 class="section-title" style="margin-top:0">在用 DNS 分布</h2>
        <?php if ($hostTotal === 0): ?>
            <p class="muted">暂无数据，请先<a href="/index.php?r=Account/add">添加服务商账号</a>并同步。</p>
        <?php else: ?>
        <div class="dash-donut-wrap">
            <div class="dash-donut" style="background:<?= $donut ?>">
                <div class="dash-donut-hole">
                    <div class="num"><?= (int)$hostTotal ?></div>
                    <div class="label">在用域名</div>
                </div>
            </div>
            <ul class="dash-legend">
                <?php foreach ($providerStats as $s): ?>
                    <?php
                    $code = (string)$s['provider'];
                    $cnt = (int)$s['cnt'];
                    $pct = $hostTotal > 0 ? round($cnt / $hostTotal * 100, 1) : 0;
                    $color = $colors[$code] ?? $colors['other'];
                    ?>
                    <li>
                        <span class="lg-dot" style="background:<?= $color ?>"></span>
                        <span class="lg-name"><?= View::e($names[$code] ?? $code) ?></span>
                        <span class="lg-bar"><i style="width:<?= $pct ?>%;background:<?= $color ?>"></i></span>
                        <span class="lg-val"><b><?= $cnt ?></b><em><?= $pct ?>%</em></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <!-- 注册商分布：占比条 -->
    <div class="card">
        <h2 class="section-title" style="margin-top:0">域名注册商分布</h2>
        <?php if ($regTotal === 0): ?>
            <p class="muted">暂无注册商数据，同步账号后自动统计。</p>
        <?php else: ?>
        <ul class="dash-legend dash-legend-tall">
            <?php foreach ($registrarStats as $s): ?>
                <?php
                $code = (string)$s['provider'];
                $cnt = (int)$s['cnt'];
                $pct = $regTotal > 0 ? round($cnt / $regTotal * 100, 1) : 0;
                $color = $colors[$code] ?? $colors['other'];
                ?>
                <li>
                    <span class="lg-dot" style="background:<?= $color ?>"></span>
                    <span class="lg-name"><?= View::e($names[$code] ?? $code) ?></span>
                    <span class="lg-bar"><i style="width:<?= $pct ?>%;background:<?= $color ?>"></i></span>
                    <span class="lg-val"><b><?= $cnt ?></b><em><?= $pct ?>%</em></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="dash-footnote">共 <?= (int)$regTotal ?> 个域名在我方注册商账号下持有</div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($expiringDomains)): ?>
<div class="card">
    <h2 class="section-title" style="margin-top:0">临期域名（30 天内）</h2>
    <div class="dash-expiring">
        <?php foreach ($expiringDomains as $d): ?>
            <?php
            $left = (int)floor((strtotime($d['expire_at']) - time()) / 86400);
            $level = $left <= 7 ? 'danger' : ($left <= 15 ? 'warn' : 'soon');
            ?>
            <div class="exp-card exp-<?= $level ?>">
                <div class="exp-domain mono" title="<?= View::e($d['domain']) ?>"><?= View::e($d['domain']) ?></div>
                <div class="exp-date"><?= View::e($d['expire_at']) ?></div>
                <div class="exp-days">
                    <?php if ($left < 0): ?><span class="exp-overdue">已过期</span><b><?= abs($left) ?></b> 天<?php else: ?><b><?= $left ?></b> 天<?php endif; ?>
                </div>
                <div class="exp-meter"><i style="width:<?= max(4, min(100, (30 - $left) / 30 * 100)) ?>%"></i></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="section-title" style="margin-top:0">最近操作</h2>
    <?php if (empty($recentAudit)): ?>
        <p class="muted">暂无操作记录。</p>
    <?php else: ?>
        <table class="dash-audit">
            <thead><tr><th>时间</th><th>用户</th><th>操作</th><th>对象</th></tr></thead>
            <tbody>
            <?php foreach ($recentAudit as $a): ?>
                <?php $act = (string)$a['action']; ?>
                <tr>
                    <td class="muted"><?= View::e($a['created_at']) ?></td>
                    <td><?= View::e($a['username']) ?></td>
                    <td><span class="chip"><?= View::e($actionLabels[$act] ?? $act) ?></span></td>
                    <td><?= View::e($a['target']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
