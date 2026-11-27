<?php
use QiDNS\Core\View;
/** @var array $templates @var array $appDomains @var array $appTags */
?>
<h1 class="page-title">解析模板</h1>

<div class="toolbar">
    <a href="#" class="btn btn-primary" onclick="openTemplate()">+ 新建模板</a>
</div>

<div class="card">
    <table>
        <thead><tr><th>模板名</th><th>记录数</th><th>备注</th><th>操作</th></tr></thead>
        <tbody>
        <?php if (empty($templates)): ?>
            <tr><td colspan="4" class="muted">暂无模板。</td></tr>
        <?php else: ?>
            <?php foreach ($templates as $t): ?>
            <tr>
                <td><?= View::e($t['name']) ?></td>
                <td><?= count($t['records']) ?></td>
                <td class="muted"><?= View::e($t['remark']) ?></td>
                <td>
                    <button class="btn btn-sm" onclick="openTemplate(<?= View::e(json_encode([
                        'id' => (int)$t['id'],
                        'name' => (string)($t['name'] ?? ''),
                        'remark' => (string)($t['remark'] ?? ''),
                        'records' => $t['records'],
                    ], JSON_UNESCAPED_UNICODE)) ?>)">编辑</button>
                    <button class="btn btn-sm" onclick="applyTemplate(<?= (int)$t['id'] ?>, <?= View::e(json_encode((string)$t['name'], JSON_UNESCAPED_UNICODE)) ?>)">应用</button>
                    <button class="btn btn-sm btn-danger" onclick="delTemplate(<?= (int)$t['id'] ?>)">删除</button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 新建/编辑模板弹窗 -->
<div id="tplModal" class="qd-modal-mask" style="display:none">
    <div class="qd-modal qd-tpl-modal">
        <h2 id="tplTitle">新建模板</h2>
        <p class="qd-modal-sub">把常用记录组合存成模板，之后可一键套用到任意域名。</p>
        <form id="tplForm">
            <input type="hidden" name="id" id="tpl_id">
            <div class="form-row">
                <div>
                    <label style="margin-top:0">模板名 *</label>
                    <input type="text" name="name" id="tpl_name" required>
                </div>
                <div>
                    <label style="margin-top:0">备注</label>
                    <input type="text" name="remark" id="tpl_remark">
                </div>
            </div>

            <label style="margin-top:18px">解析记录</label>
            <div id="tplRows"></div>
            <button type="button" class="btn" onclick="addTplRow()">+ 添加记录</button>

            <div class="qd-modal-actions">
                <button type="submit" class="btn btn-primary">保存</button>
                <button type="button" class="btn" onclick="closeTplModal()">取消</button>
            </div>
        </form>
    </div>
</div>

<!-- 应用模板弹窗 -->
<div id="applyModal" class="qd-modal-mask" style="display:none">
    <div class="qd-modal qd-apply-modal">
        <h2>应用模板</h2>
        <p class="qd-modal-sub" id="ap_tpl_name"></p>
        <form id="applyForm">
            <input type="hidden" name="template_id" id="ap_template_id">

            <!-- 按标签快速选择 -->
            <label style="margin-top:0">按标签快速选择</label>
            <div class="ap-tagbar-wrap">
                <select id="ap_tagSelect" class="ap-tag-select" onchange="apSelectTagFromDropdown(this)">
                    <option value="">选择标签快捷筛选…</option>
                </select>
                <div id="ap_tagBar" class="ap-tagbar"></div>
            </div>

            <!-- 搜索 / 服务商筛选 / 批量勾选 -->
            <div class="ap-controls">
                <input type="text" id="ap_search" class="ap-search" placeholder="搜索域名、备注或标签…" oninput="apRenderList()">
                <select id="ap_provider" onchange="apRenderList()">
                    <option value="">全部服务商</option>
                </select>
                <button type="button" class="btn" onclick="apBatch(true)">全选当前</button>
                <button type="button" class="btn" onclick="apBatch(false)">取消当前</button>
                <button type="button" class="btn" onclick="apClear()">清空</button>
            </div>

            <!-- 域名可视化列表 -->
            <div id="ap_list" class="ap-list"></div>
            <div class="ap-summary">已选 <strong id="ap_count">0</strong> 个域名</div>

            <label class="qd-dryrun"><input type="checkbox" name="dry_run" value="1"> 预览（dry-run，不真正下发）</label>
            <div class="qd-modal-actions">
                <button type="submit" class="btn btn-primary">应用</button>
                <button type="button" class="btn" onclick="closeApplyModal()">取消</button>
            </div>
        </form>
    </div>
</div>

<script>
var TPL_TYPES = ['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA'];
var TPL_LINES = ['默认','电信','联通','移动','教育网','海外','搜索引擎'];

// 应用模板可选域名与标签（全转义，防注入）
var AP_DOMAINS = <?= json_encode($appDomains, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
var AP_TAGS = <?= json_encode($appTags, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
var apSelected = {};   // id -> true
var apActiveTags = {}; // 标签 -> true（选中态，用于筛选+快速选择）

function apEsc(s){
    s = s == null ? '' : String(s);
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function apProviderClass(p){
    if(p === 'dnspod') return 'chip-dnspod';
    if(p === 'aliyun') return 'chip-aliyun';
    if(p === 'west') return 'chip-west';
    return 'chip-other';
}
function apInitProviderFilter(){
    var set = {}, sel = document.getElementById('ap_provider');
    AP_DOMAINS.forEach(function(d){ if(d.dns_provider){ set[d.dns_provider] = true; } });
    Object.keys(set).sort().forEach(function(p){
        var o = document.createElement('option');
        o.value = p; o.textContent = p;
        sel.appendChild(o);
    });
}
function apInitTagSelect(){
    var sel = document.getElementById('ap_tagSelect');
    AP_TAGS.forEach(function(t){
        var o = document.createElement('option');
        o.value = t; o.textContent = t;
        sel.appendChild(o);
    });
    if(!AP_TAGS.length){ sel.disabled = true; }
}
function apSelectTagFromDropdown(sel){
    if(!sel.value){ return; }
    var t = sel.value;
    // 下拉为单选语义：先清掉其他标签，只保留所选；再次选中同一标签则取消
    var wasActive = !!apActiveTags[t];
    apActiveTags = {};
    if(!wasActive){ apActiveTags[t] = true; }
    sel.value = wasActive ? '' : t;
    apRenderTags();
    apRenderList();
}
function apRenderTags(){
    var bar = document.getElementById('ap_tagBar');
    if(!AP_TAGS.length){ bar.innerHTML = '<span class="muted" style="font-size:13px">暂无已使用的标签</span>'; return; }
    bar.innerHTML = AP_TAGS.map(function(t, i){
        var on = apActiveTags[t] ? ' active' : '';
        var n = AP_DOMAINS.filter(function(d){ return (d.tag_list||[]).indexOf(t) !== -1; }).length;
        return '<button type="button" data-idx="' + i + '" class="chip chip-tag ap-tag' + on + '" onclick="apToggleTagByIndex(this)">' + apEsc(t) + ' <span class="ap-tag-num">' + n + '</span></button>';
    }).join('');
}
function apToggleTagByIndex(el){
    apToggleTag(AP_TAGS[parseInt(el.getAttribute('data-idx'), 10)]);
}
function apToggleTag(t){
    if(apActiveTags[t]){ delete apActiveTags[t]; }
    else { apActiveTags[t] = true; }
    // 同步下拉：chip 多选保留，下拉仅反映唯一选中项，多选时置空
    var active = Object.keys(apActiveTags);
    document.getElementById('ap_tagSelect').value = active.length === 1 ? active[0] : '';
    apRenderTags();
    apRenderList();
}
function apMatch(d){
    var kw = document.getElementById('ap_search').value.trim().toLowerCase();
    var pv = document.getElementById('ap_provider').value;
    if(pv && d.dns_provider !== pv){ return false; }
    // 标签筛选：选中多个标签时取交集（域名需含全部选中标签）
    var active = Object.keys(apActiveTags);
    for(var i=0;i<active.length;i++){
        if((d.tag_list||[]).indexOf(active[i]) === -1){ return false; }
    }
    if(kw){
        var hay = [d.domain, d.tags, d.dns_provider, d.dns_account_name].join(' ').toLowerCase();
        if(hay.indexOf(kw) === -1){ return false; }
    }
    return true;
}
function apVisibleDomains(){
    return AP_DOMAINS.filter(apMatch);
}
function apRenderList(){
    var box = document.getElementById('ap_list');
    var list = apVisibleDomains();
    if(!list.length){ box.innerHTML = '<div class="ap-empty">没有匹配的域名</div>'; apUpdateCount(); return; }
    // 标签只在「按标签筛选」时出现：未筛选一律不显示，避免多标签撑爆单行版面；
    // 已筛选时也只回显当前选中的那几个标签（交集筛选保证它们都在域名标签里）。
    var activeTags = Object.keys(apActiveTags);
    box.innerHTML = list.map(function(d){
        var checked = apSelected[d.id] ? ' checked' : '';
        var pn = d.dns_account_name || d.dns_provider;
        var tags = activeTags.map(function(t){
            return '<span class="chip ap-mini-tag">' + apEsc(t) + '</span>';
        }).join('');
        return '<label class="ap-item' + checked + '">' +
                 '<input type="checkbox" value="' + d.id + '"' + checked + ' onchange="apToggleItem(' + d.id + ', this.checked)">' +
                 '<span class="ap-domain">' + apEsc(d.domain) + '</span>' +
                 '<span class="chip ' + apProviderClass(d.dns_provider) + '" title="' + apEsc(pn) + '">' + apEsc(pn) + '</span>' +
                 (tags ? '<span class="ap-item-tags">' + tags + '</span>' : '') +
               '</label>';
    }).join('');
    apUpdateCount();
}
function apToggleItem(id, on){
    if(on){ apSelected[id] = true; } else { delete apSelected[id]; }
    var item = document.querySelector('#ap_list input[value="' + id + '"]');
    if(item && item.parentNode){ item.parentNode.className = on ? 'ap-item checked' : 'ap-item'; }
    apUpdateCount();
}
function apBatch(on){
    apVisibleDomains().forEach(function(d){
        if(on){ apSelected[d.id] = true; } else { delete apSelected[d.id]; }
    });
    apRenderList();
}
function apClear(){ apSelected = {}; apRenderList(); }
function apUpdateCount(){
    document.getElementById('ap_count').textContent = Object.keys(apSelected).length;
}

function tplTypeOptions(sel){
    sel = sel || 'A';
    return TPL_TYPES.map(function(t){ return '<option value="'+t+'"'+(t===sel?' selected':'')+'>'+t+'</option>'; }).join('');
}
function tplLineOptions(sel){
    sel = sel || '默认';
    return TPL_LINES.map(function(l){ return '<option'+(l===sel?' selected':'')+'>'+l+'</option>'; }).join('');
}

function addTplRow(data){
    data = data || {};
    var box = document.getElementById('tplRows');
    var empty = box.querySelector('.tpl-empty');
    if(empty){ empty.parentNode.removeChild(empty); }
    var div = document.createElement('div');
    div.className = 'tpl-row';
    div.innerHTML =
        '<div class="tpl-grid">' +
            '<div><label>主机记录</label><input type="text" class="tr-rr" value="'+(data.rr || '@')+'"></div>' +
            '<div><label>类型</label><select class="tr-type">'+tplTypeOptions(data.type)+'</select></div>' +
            '<div><label>解析值 *</label><input type="text" class="tr-value" value="'+(data.value || '')+'" placeholder="如 1.2.3.4"></div>' +
            '<div><label>TTL（秒）</label><input type="number" class="tr-ttl" value="'+(data.ttl || 600)+'"></div>' +
            '<div><label>线路</label><select class="tr-line">'+tplLineOptions(data.line)+'</select></div>' +
            '<div><label>MX优先级</label><input type="number" class="tr-mx" value="'+(data.mx || 0)+'"></div>' +
        '</div>' +
        '<div class="tpl-row-actions"><button type="button" class="btn btn-sm btn-danger" onclick="removeTplRow(this)">删除这条</button></div>';
    box.appendChild(div);
}
function removeTplRow(btn){
    var box = document.getElementById('tplRows');
    btn.closest('.tpl-row').parentNode.removeChild(btn.closest('.tpl-row'));
    if(box.querySelectorAll('.tpl-row').length === 0){
        box.innerHTML = '<div class="tpl-empty">还没有记录，点击「+ 添加记录」逐条添加。</div>';
    }
}

function openTemplate(tpl){
    document.getElementById('tplForm').reset();
    document.getElementById('tplRows').innerHTML = '';
    var isEdit = !!(tpl && tpl.id);
    document.getElementById('tpl_id').value = isEdit ? tpl.id : '';
    document.getElementById('tplTitle').textContent = isEdit ? '编辑模板' : '新建模板';
    if(isEdit){
        document.getElementById('tpl_name').value = tpl.name || '';
        document.getElementById('tpl_remark').value = tpl.remark || '';
        var recs = tpl.records || [];
        if(recs.length){
            recs.forEach(function(r){ addTplRow(r); });
        } else {
            addTplRow();
        }
    } else {
        addTplRow();
    }
    document.getElementById('tplModal').style.display = 'flex';
}
function closeTplModal(){ document.getElementById('tplModal').style.display = 'none'; }

document.getElementById('tplForm').addEventListener('submit', function(e){
    e.preventDefault();
    var name = document.getElementById('tpl_name').value.trim();
    if(!name){ qidnsAlert('请填写模板名', {title: '无法保存'}); return; }
    var remark = document.getElementById('tpl_remark').value;
    var rows = document.querySelectorAll('#tplRows .tpl-row');
    if(rows.length === 0){ qidnsAlert('请点击「+ 添加记录」至少添加一条记录', {title: '无法保存'}); return; }
    var records = [];
    for(var i = 0; i < rows.length; i++){
        var row = rows[i];
        var value = row.querySelector('.tr-value').value.trim();
        if(!value){ qidnsAlert('第 ' + (i + 1) + ' 条记录的解析值不能为空', {title: '无法保存'}); return; }
        records.push({
            rr: row.querySelector('.tr-rr').value.trim() || '@',
            type: row.querySelector('.tr-type').value,
            value: value,
            ttl: parseInt(row.querySelector('.tr-ttl').value, 10) || 600,
            line: row.querySelector('.tr-line').value,
            mx: parseInt(row.querySelector('.tr-mx').value, 10) || 0
        });
    }
    var fd = new FormData();
    fd.append('id', document.getElementById('tpl_id').value);
    fd.append('name', name);
    fd.append('remark', remark);
    fd.append('records', JSON.stringify(records));
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/index.php?r=Template/save');
    xhr.onreadystatechange = function(){
        if(xhr.readyState === 4){
            var r = JSON.parse(xhr.responseText);
            if(r.code === 0){ location.reload(); }
            else { qidnsAlert(r.msg, {title: '保存失败'}); }
        }
    };
    xhr.send(fd);
});

function applyTemplate(id, name){
    document.getElementById('ap_template_id').value = id;
    document.getElementById('ap_tpl_name').textContent = '模板：' + (name || '') + '　·　勾选下方域名后应用';
    apSelected = {};
    apActiveTags = {};
    document.getElementById('ap_search').value = '';
    document.getElementById('ap_provider').value = '';
    document.getElementById('ap_tagSelect').value = '';
    // 服务商/标签下拉只初始化一次
    if(document.getElementById('ap_provider').options.length <= 1){ apInitProviderFilter(); }
    if(document.getElementById('ap_tagSelect').options.length <= 1){ apInitTagSelect(); }
    apRenderTags();
    apRenderList();
    document.getElementById('applyModal').style.display = 'flex';
}
function closeApplyModal(){ document.getElementById('applyModal').style.display = 'none'; }

document.getElementById('applyForm').addEventListener('submit', function(e){
    e.preventDefault();
    var ids = Object.keys(apSelected);
    if(ids.length === 0){ qidnsAlert('请至少勾选一个域名', {title: '未选择域名'}); return; }
    var btn = e.target.querySelector('button[type=submit]');
    if(btn && btn.disabled){ return; }
    var oldText = btn ? btn.textContent : '';
    if(btn){ btn.disabled = true; btn.textContent = '处理中…'; }

    var fd = new FormData(this);
    fd.delete('domain_ids[]');
    ids.forEach(function(id){ fd.append('domain_ids[]', id); });
    var xhr = new XMLHttpRequest();
    xhr.timeout = 120000;
    function done(r){
        if(btn){ btn.disabled = false; btn.textContent = oldText; }
        if(!r || r.code !== 0){ qidnsAlert(r ? r.msg : '请求失败（可能超时）', {title: '应用失败'}); return; }
        showApplyResult(r.data || []);
    }
    xhr.onreadystatechange = function(){
        if(xhr.readyState === 4){
            var r = null;
            try { r = JSON.parse(xhr.responseText); } catch(err){}
            done(r);
        }
    };
    xhr.ontimeout = function(){ done(null); };
    xhr.send(fd);
});

/** 应用结果：每个域名一条，标明新增/跳过/失败，失败带服务商原因 */
function showApplyResult(list){
    if(!list.length){ qidnsAlert('没有任何域名被处理。', {title: '应用结果'}); return; }
    var hasFail = false, hasChange = false;
    var items = list.map(function(it){
        var label = it.domain + (it.provider ? '（' + it.provider + '）' : '');
        var detail, state;
        if(it.status === 'dry'){
            state = it.created > 0 ? 'part' : 'skip';
            detail = '预览：将新增 ' + it.created + ' 条' + (it.skipped ? '，已存在 ' + it.skipped + ' 条（跳过）' : '');
        } else if(it.status === 'success'){
            state = 'ok'; hasChange = true;
            detail = '新增 ' + it.created + ' 条' + (it.skipped ? '，跳过已存在 ' + it.skipped + ' 条' : '');
        } else if(it.status === 'skip'){
            state = 'skip';
            detail = '记录均已存在，跳过 ' + it.skipped + ' 条（未做改动）';
        } else if(it.status === 'partial'){
            state = 'bad'; hasFail = true; hasChange = true;
            detail = '新增 ' + it.created + ' 条，失败 ' + it.failed + ' 条：' + (it.error || '');
        } else {
            state = 'bad'; hasFail = true;
            detail = '失败 ' + it.failed + ' 条：' + (it.error || '未知错误');
        }
        return { label: label, detail: detail, state: state };
    });
    var title = hasFail ? '应用完成（部分失败）' : '应用完成';
    var reload = function(){ if(hasChange){ location.reload(); } };
    qidnsResult(title, items, { okText: hasChange ? '知道了，刷新列表' : '知道了', onOk: reload, onCancel: reload });
}

function delTemplate(id){
    qidnsConfirm('确定删除该模板吗？', function(){
        qidnsPost('/index.php?r=Template/delete', {id: id}, function(r){
            if(r && r.code === 0){ location.reload(); }
            else { qidnsAlert(r ? r.msg : '删除失败', {title: '删除失败'}); }
        });
    }, {title: '删除模板', okText: '删除', danger: true});
}
</script>
