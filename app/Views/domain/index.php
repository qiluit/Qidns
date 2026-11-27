<?php
use QiDNS\Core\View;
/** @var array $domains @var array $accounts @var int $accountId @var string $keyword @var string $tag @var array $tagList @var array $tagColors @var string $suffix @var array $suffixOptions @var string $sort @var string $order @var bool $showStale @var int $staleCount */
$providerLabels = ['dnspod' => 'DNSPod', 'aliyun' => '阿里云', 'west' => '西部数码', 'other' => '第三方 DNS'];
// 排序链接需透传当前检索条件，避免点击表头后筛选被清空
$filterParams = [];
if ($accountId > 0) $filterParams['account_id'] = $accountId;
if ($keyword !== '') $filterParams['keyword'] = $keyword;
if ($tag !== '') $filterParams['tag'] = $tag;
if ($suffix !== '') $filterParams['suffix'] = $suffix;
if ($showStale) $filterParams['show_stale'] = 1;
?>
<h1 class="page-title">域名管理</h1>

<!-- 视图切换 -->
<div class="view-tabs">
    <a href="/index.php?r=Domain" class="view-tab active">全部域名</a>
    <a href="/index.php?r=Domain&view=hosting" class="view-tab">按在用 DNS 分组</a>
</div>

<!-- 检索栏 -->
<div class="card" style="padding:16px 20px">
    <form method="get" action="/index.php" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end">
        <input type="hidden" name="r" value="Domain">
        <div style="min-width:160px">
            <label style="margin-top:0">接入商</label>
            <select name="account_id">
                <option value="0">全部账号</option>
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= $accountId === (int)$a['id'] ? 'selected' : '' ?>><?= View::e($a['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="min-width:200px">
            <label style="margin-top:0">关键词</label>
            <input type="text" name="keyword" value="<?= View::e($keyword) ?>" placeholder="域名 / 备注模糊搜索">
        </div>
        <div style="min-width:160px">
            <label style="margin-top:0">标签</label>
            <select name="tag">
                <option value="">全部标签</option>
                <?php foreach ($tagList as $t): ?>
                    <option value="<?= View::e($t) ?>" <?= $tag === $t ? 'selected' : '' ?>><?= View::e($t) ?></option>
                <?php endforeach; ?>
            </select>
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
        <?php if ($accountId > 0 || $keyword !== '' || $tag !== '' || $suffix !== '' || $showStale): ?>
            <a href="/index.php?r=Domain" class="btn">重置</a>
        <?php endif; ?>
        <button type="button" class="btn" onclick="refreshNs(0)" title="重探NS并结合各账号API权限，重算哪些域名仍属于我">刷新归属判定</button>
        <?php if ($showStale): ?>
            <a href="/index.php?r=Domain" class="btn">返回正常域名</a>
        <?php elseif ($staleCount > 0): ?>
            <a href="/index.php?r=Domain&show_stale=1" class="btn">查看失效域名 (<?= (int)$staleCount ?>)</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($showStale): ?>
<div class="notice">当前<strong>仅显示失效域名</strong>（共 <?= (int)$staleCount ?> 个）：既不在我方注册商持有列表、NS 也不指向我方解析列表（已转手 / 仅残留 zone / 疑似未注册）。它们已在两个菜单中排除；鼠标悬停「失效」标签可看具体原因，重新刷新归属后若重新纳入会自愈解除。</div>
<?php endif; ?>

<div class="card">
    <table>
        <thead><tr>
            <th><a href="<?= View::e(View::sortLink('Domain', 'domain', $sort, $order, $filterParams)) ?>" class="sort-link">域名<?= View::sortArrow('domain', $sort, $order) ?></a></th>
            <th><a href="<?= View::e(View::sortLink('Domain', 'registrar', $sort, $order, $filterParams)) ?>" class="sort-link">注册商<?= View::sortArrow('registrar', $sort, $order) ?></a></th>
            <th><a href="<?= View::e(View::sortLink('Domain', 'dns', $sort, $order, $filterParams)) ?>" class="sort-link" title="一个域名可以同时挂在多家解析平台；彩色=正在生效，灰色=挂着但没生效">DNS 托管<?= View::sortArrow('dns', $sort, $order) ?></a></th>
            <th>标签</th>
            <th>记录数</th>
            <th><a href="<?= View::e(View::sortLink('Domain', 'expire', $sort, $order, $filterParams)) ?>" class="sort-link">到期日<?= View::sortArrow('expire', $sort, $order) ?></a></th>
            <th>操作</th>
        </tr></thead>
        <tbody>
        <?php if (empty($domains)): ?>
            <tr><td colspan="7" class="muted">暂无域名，请先在「服务商账号」中同步域名，或调整检索条件。</td></tr>
        <?php else: ?>
            <?php foreach ($domains as $d): ?>
            <tr<?= (int)$d['is_stale'] === 1 ? ' style="opacity:.55"' : '' ?>>
                <td class="mono">
                    <?= View::e($d['domain']) ?>
                    <button type="button" class="whois-mini" onclick="openWhois(<?= (int)$d['id'] ?>)" title="WHOIS 查询（注册局数据）">WHOIS</button>
                    <?php if ((int)$d['is_stale'] === 1): ?>
                        <span class="chip chip-danger" title="<?= View::e($d['stale_reason']) ?>">失效</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= View::registrarChip((string)$d['registrar'], (string)($d['whois_registrar'] ?? ''), $providerLabels) ?>
                </td>
                <td class="td-nowrap">
                    <?php
                    $zones = $d['zones'] ?? [];
                    $eff = (string)$d['effective_dns'];
                    $labelOf = static function (string $p) use ($providerLabels): string {
                        return $providerLabels[$p] ?? $p;
                    };
                    // 没有彩色 chip（没挂在我方生效平台上）时，补一个说明「现在到底谁在生效」
                    $effNote = '';
                    if ($eff !== '' && $eff !== 'other') {
                        $effNote = '<span class="chip chip-zone-miss" title="注册局 NS 指向' . View::e($labelOf($eff))
                                 . '，但我方账号里没有这个域名的 zone，无法在这里操作解析">'
                                 . View::e($labelOf($eff)) . ' · 未接入</span>';
                    } elseif ($eff === 'other') {
                        $effNote = '<span class="chip chip-zone-miss" title="NS：' . View::e((string)$d['ns_servers'])
                                 . '">生效于第三方 DNS</span>';
                    } elseif ($eff === '') {
                        $effNote = '<span class="chip chip-zone-idle">查无 NS</span>';
                    }
                    ?>
                    <?php if (!empty($zones)): ?>
                        <?php $hasLive = false; ?>
                        <?php foreach (array_slice($zones, 0, 2) as $z): ?>
                            <?php
                            $live = ($z['provider'] === $eff);
                            $hasLive = $hasLive || $live;
                            $cls = in_array($z['provider'], ['dnspod', 'aliyun', 'west'], true)
                                ? 'chip-' . $z['provider'] : 'chip-zone-idle';
                            $tip = ($z['account_name'] ?: $labelOf((string)$z['provider']))
                                 . ($live ? ' · 正在生效，点击查看解析记录' : ' · 挂在此平台但未生效，点击查看该平台的 zone');
                            $href = '/index.php?r=Record/index&domain_id=' . (int)$d['id'] . '&account_id=' . (int)$z['account_id'];
                            ?>
                            <a class="chip zone-chip <?= $live ? $cls . ' is-live' : 'chip-zone-idle' ?>" href="<?= View::e($href) ?>" title="<?= View::e($tip) ?>"><?= View::e($labelOf((string)$z['provider'])) ?></a>
                        <?php endforeach; ?>
                        <?php if (count($zones) > 2): ?>
                            <?php
                            $rest = array_slice($zones, 2);
                            $restTip = implode('、', array_map(static function ($z) use ($labelOf): string {
                                return $labelOf((string)$z['provider']) . '（' . ($z['account_name'] ?: '未知账号') . '）';
                            }, $rest));
                            ?>
                            <span class="chip chip-zone-idle" title="<?= View::e($restTip) ?>">+<?= count($rest) ?></span>
                        <?php endif; ?>
                        <?php if (!$hasLive): ?><?= $effNote ?><?php endif; ?>
                    <?php else: ?>
                        <?= $effNote !== '' ? $effNote : '<span class="muted">—</span>' ?>
                    <?php endif; ?>
                </td>
                <td class="tag-cell"><?= View::tagCell(
                        $d['tag_list'],
                        (int)$d['id'],
                        $tagColors ?? [],
                        '<button class="btn btn-sm" data-tags="' . View::e((string)$d['tags']) . '"'
                        . ' onclick="editTags(' . (int)$d['id'] . ', this.getAttribute(\'data-tags\'))">标</button>'
                    ) ?></td>
                <td><?= (int)$d['record_count'] ?></td>
                <td>
                    <?php if ($d['expire_at']): ?>
                        <?php $left = (int)floor((strtotime($d['expire_at']) - time()) / 86400); ?>
                        <span class="chip <?= $left <= 30 ? ($left <= 7 ? 'chip-danger' : 'chip-warn') : '' ?>"><?= View::e($d['expire_at']) ?></span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $recAcc = !empty($zones) ? (int)$zones[0]['account_id'] : 0; ?>
                    <div class="row-actions">
                        <a class="btn btn-sm" href="/index.php?r=Record/index&domain_id=<?= (int)$d['id'] ?><?= $recAcc > 0 ? '&account_id=' . $recAcc : '' ?>">解析记录</a>
                        <button class="btn btn-sm" onclick="syncRecords(<?= (int)$d['id'] ?>, <?= $recAcc ?>)"<?= $recAcc > 0 ? '' : ' disabled title="没有可用的解析平台"' ?>>同步</button>
                        <button class="btn btn-sm" onclick="refreshNs(<?= (int)$d['id'] ?>, '<?= View::e($d['domain']) ?>')" title="重新判定该域名是否仍属于我">归属</button>
                        <button class="btn btn-sm" onclick="checkExpire(<?= (int)$d['id'] ?>)">查到期</button>
                        <button class="btn btn-sm btn-danger" onclick="delDomain(<?= (int)$d['id'] ?>)">删除</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 标签编辑弹窗 -->
<div id="tagModal" class="qd-modal-mask" style="display:none">
    <div class="qd-modal qd-tag-modal">
        <h2>设置标签</h2>
        <p class="qd-modal-sub">多个标签用逗号分隔，用于筛选与批量选择。</p>
        <form id="tagForm">
            <input type="hidden" name="id" id="tag_id">
            <label style="margin-top:0">标签</label>
            <input type="text" name="tags" id="tag_input" placeholder="如：客户,备案,重要">
            <div id="tagSuggestion" class="qd-tag-suggest"></div>
            <div class="qd-modal-actions">
                <button type="submit" class="btn btn-primary">保存</button>
                <button type="button" class="btn" onclick="closeTagModal()">取消</button>
            </div>
        </form>
    </div>
</div>

<script>
// 已有标签集合（用于建议）
var allTags = <?= json_encode($tagList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var allTagColors = <?= json_encode($tagColors ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

function syncRecords(id, accountId){
    qidnsPost('/index.php?r=Domain/syncRecords', {domain_id: id, account_id: accountId || 0}, function(r){
        if(r && r.code === 0){
            var d = r.data || {};
            var note = d.effective === false
                ? '\n注意：' + (d.account || '该平台') + ' 这份 zone 当前未生效，改动不会影响线上解析。'
                : '';
            qidnsAlert('从 ' + (d.account || '服务商') + ' 同步完成，共 ' + d.count + ' 条解析记录。' + note, {title: '同步成功'}).then(function(){ location.reload(); });
        } else { qidnsAlert(r ? r.msg : '同步失败', {title: '同步失败'}); }
    });
}
function refreshNs(id, domain){
    qidnsRefreshNs(id, domain);
}
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
function delDomain(id){
    qidnsConfirm('确定删除该域名及其记录缓存吗？（不影响线上解析）', function(){
        qidnsPost('/index.php?r=Domain/delete', {id: id}, function(r){
            if(r && r.code === 0){ location.reload(); }
            else { qidnsAlert(r ? r.msg : '删除失败', {title: '删除失败'}); }
        });
    }, {title: '删除域名', okText: '删除', danger: true});
}

// 标签编辑
function editTags(id, currentTags){
    document.getElementById('tag_id').value = id;
    document.getElementById('tag_input').value = currentTags || '';
    renderSuggestions(currentTags || '');   // 建议里排除已有标签，不能传空串
    document.getElementById('tagModal').style.display = 'flex';
}
function closeTagModal(){ document.getElementById('tagModal').style.display = 'none'; }
function renderSuggestions(input){
    var box = document.getElementById('tagSuggestion');
    var current = input.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
    var html = '';
    allTags.forEach(function(t){
        if(current.indexOf(t) === -1){
            html += '<a class="chip chip-tag tag-c-' + (allTagColors[t] || 'blue') + '" style="cursor:pointer;margin:2px" onclick="addTag(\'' + t.replace(/'/g, "\\'") + '\')">+' + t + '</a>';
        }
    });
    box.innerHTML = html;
}
function addTag(t){
    var inp = document.getElementById('tag_input');
    var cur = inp.value.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
    if(cur.indexOf(t) === -1){
        cur.push(t);
        inp.value = cur.join(',');
        renderSuggestions(inp.value);
    }
}
document.getElementById('tag_input').addEventListener('input', function(){ renderSuggestions(this.value); });

document.getElementById('tagForm').addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(this);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/index.php?r=Domain/updateTags');
    xhr.onreadystatechange = function(){
        if(xhr.readyState === 4){
            var r = JSON.parse(xhr.responseText);
            if(r.code === 0){ location.reload(); }
            else { qidnsAlert(r.msg, {title: '保存失败'}); }
        }
    };
    xhr.send(fd);
});
</script>
