<?php

use QiDNS\Core\View;

/** @var array $tags @var array $colors @var array $stat */
$colorNames = ['blue' => '蓝', 'green' => '绿', 'orange' => '橙', 'purple' => '紫', 'pink' => '粉', 'teal' => '青', 'gray' => '灰', 'red' => '红'];
?>
<h1 class="page-title">标签管理</h1>
<p class="muted" style="margin:-6px 0 16px">
    标签用于域名分组、筛选与模板批量选择。改名会同步更新所有域名上的旧标签，删除会把该标签从所有域名上摘掉。
</p>

<div class="stats">
    <div class="stat"><div class="num"><?= (int)$stat['total'] ?></div><div class="label">标签总数</div></div>
    <div class="stat"><div class="num"><?= (int)$stat['used'] ?></div><div class="label">在用标签</div></div>
    <div class="stat"><div class="num"><?= (int)$stat['idle'] ?></div><div class="label">暂未使用</div></div>
    <div class="stat"><div class="num"><?= (int)$stat['links'] ?></div><div class="label">标签关联（<?= (int)$stat['domains'] ?> 个域名）</div></div>
</div>

<div class="card" style="padding:16px 20px">
    <div class="tag-create">
        <div class="tag-create-name">
            <label style="margin-top:0">新标签</label>
            <input type="text" id="newTagName" maxlength="64" placeholder="如：客户、备案、待续费" autocomplete="off">
        </div>
        <div>
            <label style="margin-top:0">颜色</label>
            <div class="color-picker" id="newTagColors">
                <?php foreach ($colors as $c): ?>
                    <label class="color-pick<?= $c === 'blue' ? ' is-on' : '' ?>" data-color="<?= View::e($c) ?>">
                        <input type="radio" name="new_color" value="<?= View::e($c) ?>"<?= $c === 'blue' ? ' checked' : '' ?>>
                        <span class="color-dot tag-dot-<?= View::e($c) ?>"></span><?= View::e($colorNames[$c] ?? $c) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="button" class="btn btn-primary" onclick="createTag()">添加标签</button>
    </div>
</div>

<div class="card">
    <div class="tag-toolbar">
        <input type="text" id="tagSearch" class="tag-search" placeholder="搜索标签名" autocomplete="off"
               oninput="filterTags(this.value)">
        <span class="muted" id="tagCount">共 <?= (int)$stat['total'] ?> 个</span>
    </div>
    <table>
        <thead><tr>
            <th>标签</th>
            <th>使用域名</th>
            <th>颜色</th>
            <th>创建时间</th>
            <th>操作</th>
        </tr></thead>
        <tbody id="tagRows">
        <?php if (empty($tags)): ?>
            <tr><td colspan="5" class="muted">还没有标签。域名同步不会带标签，可在上方新建，或在域名列表里用「标」按钮给域名打标签。</td></tr>
        <?php else: ?>
            <?php foreach ($tags as $t): ?>
                <tr data-id="<?= (int)$t['id'] ?>" data-name="<?= View::e((string)$t['name']) ?>"
                    data-color="<?= View::e((string)$t['color']) ?>" data-count="<?= (int)$t['use_count'] ?>">
                    <td>
                        <span class="chip chip-tag tag-c-<?= View::e((string)$t['color']) ?>"><?= View::e((string)$t['name']) ?></span>
                    </td>
                    <td class="td-nowrap">
                        <?php if ((int)$t['use_count'] > 0): ?>
                            <?= (int)$t['use_count'] ?> 个
                            <a class="tag-stat-link" href="/index.php?r=Domain&tag=<?= urlencode((string)$t['name']) ?>">查看</a>
                        <?php else: ?>
                            <span class="muted">未使用</span>
                        <?php endif; ?>
                    </td>
                    <td class="td-nowrap">
                        <span class="color-dot tag-dot-<?= View::e((string)$t['color']) ?>"></span>
                        <?= View::e($colorNames[(string)$t['color']] ?? (string)$t['color']) ?>
                    </td>
                    <td class="muted td-nowrap"><?= View::e(substr((string)$t['created_at'], 0, 10)) ?></td>
                    <td>
                        <div class="row-actions">
                            <button type="button" class="btn btn-sm" onclick="openTagEdit(this)">编辑</button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="delTag(this)">删除</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 编辑标签 -->
<div id="tagEditModal" class="qd-modal-mask" style="display:none">
    <div class="qd-modal qd-tag-modal">
        <h2>编辑标签</h2>
        <p class="qd-modal-sub" id="tagEditSub">改名会同步更新所有域名上使用该标签的记录。</p>
        <form id="tagEditForm" onsubmit="return false">
            <input type="hidden" id="tagEditId">
            <label style="margin-top:0">标签名</label>
            <input type="text" id="tagEditName" maxlength="64" autocomplete="off">
            <label>颜色</label>
            <div class="color-picker" id="tagEditColors">
                <?php foreach ($colors as $c): ?>
                    <label class="color-pick" data-color="<?= View::e($c) ?>">
                        <input type="radio" name="edit_color" value="<?= View::e($c) ?>">
                        <span class="color-dot tag-dot-<?= View::e($c) ?>"></span><?= View::e($colorNames[$c] ?? $c) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="qd-modal-actions">
                <button type="button" class="btn btn-primary" onclick="saveTag()">保存</button>
                <button type="button" class="btn" onclick="closeTagEdit()">取消</button>
            </div>
        </form>
    </div>
</div>

<script>
function activeColor(boxId){
    var box = document.getElementById(boxId);
    var on = box.querySelector('.color-pick.is-on');
    return on ? on.getAttribute('data-color') : 'blue';
}

// 色板单选：点哪个高亮哪个（原生 radio 隐藏，交给 label 态表达）
document.querySelectorAll('.color-picker').forEach(function(box){
    box.addEventListener('click', function(e){
        var pick = e.target.closest('.color-pick');
        if(!pick || !box.contains(pick)) return;
        box.querySelectorAll('.color-pick').forEach(function(p){ p.classList.remove('is-on'); });
        pick.classList.add('is-on');
        var radio = pick.querySelector('input[type=radio]');
        if(radio) radio.checked = true;
    });
});

function createTag(){
    var name = document.getElementById('newTagName').value.trim();
    if(!name){ qidnsAlert('请先填写标签名', {title: '缺少标签名'}); return; }
    qidnsPost('/index.php?r=Tag/create', {name: name, color: activeColor('newTagColors')}, function(r){
        if(r && r.code === 0){
            location.reload();
        } else {
            qidnsAlert(r ? r.msg : '创建失败', {title: '创建失败'});
        }
    });
}
document.getElementById('newTagName').addEventListener('keydown', function(e){
    if(e.key === 'Enter'){ e.preventDefault(); createTag(); }
});

function openTagEdit(btn){
    var tr = btn.closest('tr');
    document.getElementById('tagEditId').value = tr.getAttribute('data-id');
    document.getElementById('tagEditName').value = tr.getAttribute('data-name');
    var color = tr.getAttribute('data-color');
    var box = document.getElementById('tagEditColors');
    box.querySelectorAll('.color-pick').forEach(function(p){
        var on = p.getAttribute('data-color') === color;
        p.classList.toggle('is-on', on);
        var radio = p.querySelector('input[type=radio]');
        if(radio) radio.checked = on;
    });
    document.getElementById('tagEditSub').textContent =
        '该标签当前用于 ' + tr.getAttribute('data-count') + ' 个域名。改名会同步更新这些域名上的旧标签。';
    document.getElementById('tagEditModal').style.display = 'flex';
    document.getElementById('tagEditName').focus();
}
function closeTagEdit(){ document.getElementById('tagEditModal').style.display = 'none'; }

function saveTag(){
    var id = document.getElementById('tagEditId').value;
    var name = document.getElementById('tagEditName').value.trim();
    if(!name){ qidnsAlert('标签名不能为空', {title: '保存失败'}); return; }
    qidnsPost('/index.php?r=Tag/update', {id: id, name: name, color: activeColor('tagEditColors')}, function(r){
        if(r && r.code === 0){ location.reload(); }
        else { qidnsAlert(r ? r.msg : '保存失败', {title: '保存失败'}); }
    });
}

function delTag(btn){
    var tr = btn.closest('tr');
    var name = tr.getAttribute('data-name');
    var count = parseInt(tr.getAttribute('data-count'), 10) || 0;
    var msg = count > 0
        ? '确定删除标签「' + name + '」吗？它正在被 ' + count + ' 个域名使用，删除后会从这些域名上一起摘掉。'
        : '确定删除标签「' + name + '」吗？';
    qidnsConfirm(msg, function(){
        qidnsPost('/index.php?r=Tag/delete', {id: tr.getAttribute('data-id')}, function(r){
            if(r && r.code === 0){ location.reload(); }
            else { qidnsAlert(r ? r.msg : '删除失败', {title: '删除失败'}); }
        });
    }, {title: '删除标签', okText: '删除', danger: true});
}

function filterTags(q){
    q = (q || '').trim().toLowerCase();
    var rows = document.querySelectorAll('#tagRows tr[data-id]');
    var shown = 0;
    rows.forEach(function(tr){
        var hit = !q || (tr.getAttribute('data-name') || '').toLowerCase().indexOf(q) !== -1;
        tr.style.display = hit ? '' : 'none';
        if(hit) shown++;
    });
    document.getElementById('tagCount').textContent = '共 ' + shown + ' 个' + (q ? '（搜索：' + q + '）' : '');
}

document.getElementById('tagEditModal').addEventListener('click', function(e){
    if(e.target === this) closeTagEdit();
});
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeTagEdit();
});
</script>
