// QiDNS 通用前端脚本

// 通用 AJAX POST 封装（带超时，网络错误也回调，避免批量任务干等）
function qidnsPost(url, data, cb) {
    var fd = new FormData();
    for (var k in data) {
        if (data.hasOwnProperty(k)) {
            if (Array.isArray(data[k])) {
                data[k].forEach(function(v){ fd.append(k + '[]', v); });
            } else {
                fd.append(k, data[k]);
            }
        }
    }
    var xhr = new XMLHttpRequest();
    var settled = false;
    function finish(r) {
        if (settled) return;
        settled = true;
        cb(r, xhr);
    }
    xhr.open('POST', url);
    xhr.timeout = 60000;
    xhr.ontimeout = function(){ finish(null); };
    xhr.onerror = function(){ finish(null); };
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            var r = null;
            try { r = JSON.parse(xhr.responseText); } catch (e) {}
            finish(r);
        }
    };
    xhr.send(fd);
}

/* ==================== 统一对话框（全站唯一的 alert / confirm） ====================
 * 原生 alert/confirm 样式随浏览器变、无法定制、还会阻塞渲染，是"界面老一套"的根源。
 * 这里用同一套 .qd-modal 体系做单例对话框，全站不再出现浏览器原生弹窗：
 *   qidnsAlert(msg, opts)             → Promise，关闭后 resolve
 *   qidnsConfirm(msg, onOk, opts)     → 确认后执行 onOk；同样返回 Promise<boolean>
 *   qidnsResult(title, items)         → 多条结果（每条带成功/跳过/失败状态）
 */
var qdDialogEl = null, qdDialogResolve = null, qdDialogOpts = null;

function qdDialogBuild() {
    if (qdDialogEl) { return qdDialogEl; }
    var box = document.createElement('div');
    box.id = 'qdDialog';
    box.className = 'qd-modal-mask qd-dialog-mask';
    box.style.display = 'none';
    box.innerHTML =
        '<div class="qd-modal qd-dialog" role="dialog" aria-modal="true">' +
          '<h2 id="qdDialogTitle">提示</h2>' +
          '<div class="qd-dialog-body" id="qdDialogBody"></div>' +
          '<div class="qd-modal-actions">' +
            '<button type="button" class="btn btn-primary" id="qdDialogOk">确定</button>' +
            '<button type="button" class="btn" id="qdDialogCancel">取消</button>' +
          '</div>' +
        '</div>';
    document.body.appendChild(box);
    document.getElementById('qdDialogOk').addEventListener('click', function(){ qdDialogDone(true); });
    document.getElementById('qdDialogCancel').addEventListener('click', function(){ qdDialogDone(false); });
    box.addEventListener('click', function(e){ if (e.target === box) { qdDialogDone(false); } });
    document.addEventListener('keydown', function(e){
        if (box.style.display !== 'none' && e.key === 'Escape') { qdDialogDone(false); }
    });
    qdDialogEl = box;
    return box;
}

function qdDialogOpen(opts) {
    var box = qdDialogBuild();
    qdDialogOpts = opts || {};
    document.getElementById('qdDialogTitle').textContent = qdDialogOpts.title || '提示';

    var body = document.getElementById('qdDialogBody');
    if (qdDialogOpts.html) {
        body.innerHTML = qdDialogOpts.html;
        body.classList.remove('qd-dialog-plain');
    } else {
        body.textContent = qdDialogOpts.text || '';
        body.classList.add('qd-dialog-plain');
    }

    var ok = document.getElementById('qdDialogOk');
    ok.textContent = qdDialogOpts.okText || (qdDialogOpts.confirm === false ? '知道了' : '确定');
    ok.className = 'btn ' + (qdDialogOpts.danger ? 'btn-danger' : 'btn-primary');
    document.getElementById('qdDialogCancel').textContent = qdDialogOpts.cancelText || '取消';
    document.getElementById('qdDialogCancel').style.display = qdDialogOpts.confirm === false ? 'none' : '';
    box.classList.toggle('qd-dialog-danger', !!qdDialogOpts.danger);

    box.style.display = 'flex';
    setTimeout(function(){ try { ok.focus(); } catch (e) {} }, 30);
    return new Promise(function(resolve){ qdDialogResolve = resolve; });
}

function qdDialogDone(ok) {
    if (!qdDialogResolve) { return; }
    var opts = qdDialogOpts || {};
    var resolve = qdDialogResolve;
    qdDialogResolve = null;
    qdDialogOpts = null;
    if (qdDialogEl) { qdDialogEl.style.display = 'none'; }
    if (ok && typeof opts.onOk === 'function') { opts.onOk(); }
    if (!ok && typeof opts.onCancel === 'function') { opts.onCancel(); }
    resolve(!!ok);
}

function qidnsAlert(msg, opts) {
    opts = opts || {};
    return qdDialogOpen({
        title: opts.title || '提示',
        text: msg,
        html: opts.html,
        okText: opts.okText || '知道了',
        confirm: false,
        onOk: opts.onOk
    });
}

/** 兼容旧写法：qidnsConfirm(msg, function(){ ... })，同时返回 Promise<boolean> */
function qidnsConfirm(msg, onOk, opts) {
    opts = opts || {};
    return qdDialogOpen({
        title: opts.title || '请确认',
        text: msg,
        okText: opts.okText || '确定',
        cancelText: opts.cancelText || '取消',
        danger: !!opts.danger,
        onOk: onOk
    });
}

/** 多条结果：items = [{label, detail, state: 'ok'|'skip'|'bad'}] */
function qidnsResult(title, items, opts) {
    opts = opts || {};
    var icon = { ok: '成功', skip: '跳过', part: '部分', bad: '失败' };
    var html = '<div class="qd-dialog-list">' + items.map(function(it){
        var st = it.state || 'ok';
        return '<div class="qd-dialog-item qd-' + st + '">' +
                 '<span class="qd-dialog-tag">' + (icon[st] || '') + '</span>' +
                 '<span class="qd-dialog-text"><b>' + qdEsc(it.label || '') + '</b>' +
                   (it.detail ? '<em>' + qdEsc(it.detail) + '</em>' : '') +
                 '</span>' +
               '</div>';
    }).join('') + '</div>';
    return qdDialogOpen({
        title: title,
        html: html,
        okText: opts.okText || '知道了',
        confirm: false,
        onOk: opts.onOk,
        onCancel: opts.onCancel
    });
}

/* ==================== 归属判定入口 ====================
 * 单点与批量共用同一个弹窗与分批骨架：
 *   - 单点：目标集合缩为 1 条，弹窗自动开跑、显示该域名与判定结果
 *   - 批量：全库分批推进
 * 单点不再用 confirm/alert，也不整页刷新后才给结论。
 */
function qidnsRefreshNs(id, domain) {
    if (id > 0) {
        openBulk('ns', { single: { id: id, domain: domain || ('#' + id) } });
        return;
    }
    openBulk('ns');
}

// 到期监控页的「批量刷新 WHOIS」入口
function qidnsBulkWhois() { openBulk('whois'); }

/* ==================== 通用批量任务弹窗（唯一一种弹窗） ====================
 * 归属判定与 WHOIS 刷新共用：配置驱动文案与接口前缀。
 * 稳定性设计：
 *   - 单例弹窗 + display:flex（居中，不用内联 block 覆盖 CSS）
 *   - round 代次：每次开弹窗/启动/停止都自增，丢弃所有在途旧回调，杜绝多套循环争抢
 *   - 后端幂等启动：重复点「开始」恢复原进度，不重置
 *   - 失败重试 3 次 + 「继续」从断点恢复，不再卡死在某个百分比
 *   - 完成时在当前弹窗内显示结果，不再弹第二种确认框
 */
var QD_BULK_CFG = {
    ns: {
        title: '批量刷新归属判定',
        desc: '分批处理域名，实时显示进度；中途可停止，已处理部分会保留。',
        singleTitle: '归属判定',
        singleDesc: function (domain) {
            return '重探 ' + domain + ' 的 NS，并结合各账号 API 权限判定是否仍属于我。';
        },
        base: '/index.php?r=Domain/',
        okLabel: '有效', badLabel: '失效'
    },
    whois: {
        title: '批量刷新 WHOIS',
        desc: '分批处理域名，逐条查询 WHOIS 到期日；中途可停止，已处理部分会保留。',
        base: '/index.php?r=Monitor/',
        okLabel: '已更新', badLabel: '未取到'
    }
};

var qdBulk = {
    key: '', cfg: null, single: null, token: '', running: false, finished: false,
    timer: null, round: 0, done: 0, total: 0, ok: 0, bad: 0, fails: 0
};

function openBulk(key, opts) {
    var cfg = QD_BULK_CFG[key];
    if (!cfg) return;
    var single = (opts && opts.single) ? opts.single : null;
    qdBulk.round++;                       // 丢弃之前所有在途回调
    if (qdBulk.timer) { clearTimeout(qdBulk.timer); qdBulk.timer = null; }
    qdBulk.key = key;
    qdBulk.cfg = cfg;
    qdBulk.single = single;
    qdBulk.running = false;
    qdBulk.finished = false;
    qdBulk.token = '';
    qdBulk.done = qdBulk.total = qdBulk.ok = qdBulk.bad = qdBulk.fails = 0;

    var box = document.getElementById('qdBulkModal');
    if (!box) {
        box = document.createElement('div');
        box.id = 'qdBulkModal';
        box.className = 'qd-modal-mask';
        box.innerHTML =
            '<div class="qd-modal">' +
              '<h2 id="qdBulkTitle"></h2>' +
              '<p class="muted" id="qdBulkDesc" style="margin:2px 0 14px"></p>' +
              '<div class="qd-bulk-stat"><span>进度 <strong id="qdBulkCount">0 / 0</strong></span>' +
                '<span><span id="qdBulkOkLabel">有效</span> <strong id="qdBulkOk" class="qd-ok">0</strong></span>' +
                '<span><span id="qdBulkBadLabel">失效</span> <strong id="qdBulkBad" class="qd-bad">0</strong></span></div>' +
              '<div class="qd-progress"><div class="qd-progress-bar" id="qdBulkBar"></div></div>' +
              '<div class="qd-bulk-note" id="qdBulkNote">准备中…</div>' +
              '<label class="qd-inline-label" style="margin-top:14px">每批间隔（毫秒）' +
                '<select id="qdBulkSleep" style="width:auto;min-width:96px;margin-left:8px">' +
                  '<option value="0">0（最快）</option>' +
                  '<option value="150" selected>150</option>' +
                  '<option value="300">300</option>' +
                  '<option value="600">600</option>' +
                  '<option value="1000">1000（最稳）</option>' +
                '</select></label>' +
              '<div class="qd-modal-actions">' +
                '<button type="button" class="btn btn-primary" id="qdBulkStart">开始</button>' +
                '<button type="button" class="btn" id="qdBulkStop" disabled>停止</button>' +
                '<button type="button" class="btn" id="qdBulkClose">关闭</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(box);
        document.getElementById('qdBulkStart').addEventListener('click', onBulkStartClick);
        document.getElementById('qdBulkStop').addEventListener('click', bulkStop);
        document.getElementById('qdBulkClose').addEventListener('click', closeBulk);
    }
    // 标题/描述：单点显示具体域名
    document.getElementById('qdBulkTitle').textContent =
        (single && cfg.singleTitle) ? cfg.singleTitle : cfg.title;
    document.getElementById('qdBulkDesc').textContent =
        single ? (cfg.singleDesc ? cfg.singleDesc(single.domain) : cfg.desc) : cfg.desc;
    document.getElementById('qdBulkOkLabel').textContent = cfg.okLabel;
    document.getElementById('qdBulkBadLabel').textContent = cfg.badLabel;

    // 单点无需「每批间隔」与「停止」
    var sleepWrap = document.getElementById('qdBulkSleep').parentNode;
    sleepWrap.style.display = single ? 'none' : '';
    document.getElementById('qdBulkStop').style.display = single ? 'none' : '';

    var sb = document.getElementById('qdBulkStart');
    sb.textContent = single ? '判定中…' : '开始';
    sb.disabled = !!single;               // 单点自动开跑，无需点开始
    document.getElementById('qdBulkStop').disabled = true;
    setBulkUI(0, 0, 0, 0, single ? '正在收集各账号证据…' : '准备中…');
    box.style.display = 'flex';   // 与 CSS .qd-modal-mask 的 flex 居中一致

    if (single) { bulkStart(); }
}

function setBulkUI(done, total, ok, bad, note) {
    document.getElementById('qdBulkCount').textContent = done + ' / ' + total;
    document.getElementById('qdBulkOk').textContent = ok;
    document.getElementById('qdBulkBad').textContent = bad;
    var pct = total > 0 ? Math.round(done * 100 / total) : 0;
    var bar = document.getElementById('qdBulkBar');
    bar.style.width = pct + '%';
    bar.textContent = pct > 8 ? pct + '%' : '';
    if (pct >= 100) { bar.classList.add('done'); } else { bar.classList.remove('done'); }
    if (note !== null) { document.getElementById('qdBulkNote').textContent = note; }
}

/** 时长不确定阶段（如收集各账号证据）用滑动不确定态，避免"停在 0%"让人以为卡死 */
function setBulkBusy(busy) {
    var bar = document.getElementById('qdBulkBar');
    if (!bar) return;
    var wrap = bar.parentNode;
    if (busy) {
        wrap.classList.add('indeterminate');
        bar.textContent = '';
    } else {
        wrap.classList.remove('indeterminate');
    }
}

function onBulkStartClick() {
    if (qdBulk.finished) { location.reload(); return; }  // 完成后按钮变「查看结果」
    bulkStart();
}

function bulkStart() {
    if (qdBulk.running) return;
    var myRound = ++qdBulk.round;          // 新一轮，作废在途回调
    qdBulk.running = true;
    qdBulk.finished = false;
    qdBulk.fails = 0;
    var sb = document.getElementById('qdBulkStart');
    sb.textContent = qdBulk.single ? '判定中…' : '开始';
    sb.disabled = true;
    document.getElementById('qdBulkStop').disabled = false;
    setBulkBusy(true);                     // 收集证据时长不定，用不确定态
    setBulkUI(qdBulk.done, qdBulk.total, qdBulk.ok, qdBulk.bad, '正在收集各账号证据…');
    var payload = {};
    if (qdBulk.single) { payload.target_id = qdBulk.single.id; }
    qidnsPost(qdBulk.cfg.base + 'bulkStart', payload, function(r){
        if (myRound !== qdBulk.round) return;            // 已被新操作取代
        setBulkBusy(false);
        if (!r || r.code !== 0) {
            qdBulk.running = false;
            sb.textContent = qdBulk.single ? '重试' : '开始';
            sb.disabled = false;
            setBulkUI(qdBulk.done, qdBulk.total, qdBulk.ok, qdBulk.bad, '启动失败：' + (r ? r.msg : '网络错误'));
            return;
        }
        qdBulk.token = r.data.token;
        qdBulk.total = r.data.total;
        qdBulk.done  = r.data.done || 0;
        qdBulk.ok    = r.data.ok || 0;
        qdBulk.bad   = r.data.bad || 0;
        setBulkUI(qdBulk.done, qdBulk.total, qdBulk.ok, qdBulk.bad,
            r.data.resumed ? '已恢复原进度，继续处理…' : '开始处理…');
        if (qdBulk.total === 0 || qdBulk.done >= qdBulk.total) { finishBulk(); return; }
        bulkStep();
    });
}

function bulkStep() {
    if (!qdBulk.running) return;
    var myRound = qdBulk.round;
    var sleep = 0;
    if (!qdBulk.single) {
        var sleepEl = document.getElementById('qdBulkSleep');
        sleep = sleepEl ? (parseInt(sleepEl.value, 10) || 0) : 0;
    }
    qidnsPost(qdBulk.cfg.base + 'bulkStep', {token: qdBulk.token, sleep_ms: sleep}, function(r){
        if (myRound !== qdBulk.round || !qdBulk.running) return;  // 旧循环回调，丢弃
        if (!r || r.code !== 0) {
            qdBulk.fails++;
            if (qdBulk.fails <= 3) {
                setBulkUI(qdBulk.done, qdBulk.total, qdBulk.ok, qdBulk.bad,
                    '连接中断，重试 ' + qdBulk.fails + '/3…');
                qdBulk.timer = setTimeout(bulkStep, 400);
            } else {
                qdBulk.running = false;
                document.getElementById('qdBulkStop').disabled = true;
                var sb = document.getElementById('qdBulkStart');
                var resumeLabel = qdBulk.single ? '重试' : '继续';
                sb.textContent = resumeLabel;
                sb.disabled = false;
                setBulkUI(qdBulk.done, qdBulk.total, qdBulk.ok, qdBulk.bad,
                    '连接中断，点「' + resumeLabel + '」' + (qdBulk.single ? '重新判定' : '从断点恢复') +
                    '（' + (r ? r.msg : '网络错误') + '）');
            }
            return;
        }
        qdBulk.fails = 0;
        var d = r.data;
        qdBulk.done = d.done;
        qdBulk.ok = d.ok;
        qdBulk.bad = d.bad;
        if (d.status === 'done' || d.done >= d.total) { finishBulk(d); return; }
        setBulkUI(qdBulk.done, qdBulk.total, qdBulk.ok, qdBulk.bad, '处理中… ' + d.done + '/' + d.total);
        qdBulk.timer = setTimeout(bulkStep, 50);   // 留出重绘间隔，进度条可见跳动
    });
}

function finishBulk(d) {
    if (d) { qdBulk.done = d.done; qdBulk.ok = d.ok; qdBulk.bad = d.bad; }
    qdBulk.running = false;
    qdBulk.finished = true;
    if (qdBulk.timer) { clearTimeout(qdBulk.timer); qdBulk.timer = null; }
    setBulkBusy(false);
    document.getElementById('qdBulkStop').disabled = true;
    var sb = document.getElementById('qdBulkStart');
    if (qdBulk.single) {
        var alive = qdBulk.ok >= 1;
        sb.textContent = '完成';
        setBulkUI(1, 1, qdBulk.ok, qdBulk.bad,
            qdBulk.single.domain + (alive
                ? ' 仍属于我（有效）。'
                : ' 判定为失效（已转手或仅残留 zone）。') +
            '点「完成」刷新列表查看最新状态。');
    } else {
        sb.textContent = '查看结果';
        setBulkUI(qdBulk.total, qdBulk.total, qdBulk.ok, qdBulk.bad,
            '完成：' + qdBulk.cfg.okLabel + ' ' + qdBulk.ok + '，' + qdBulk.cfg.badLabel + ' ' + qdBulk.bad +
            '（共 ' + qdBulk.total + ' 个）。点「查看结果」刷新列表。');
    }
    sb.disabled = false;
}

function bulkStop() {
    if (!qdBulk.running) return;
    qdBulk.round++;                        // 作废在途回调
    if (qdBulk.timer) { clearTimeout(qdBulk.timer); qdBulk.timer = null; }
    qidnsPost(qdBulk.cfg.base + 'bulkStop', {}, function(){
        qdBulk.running = false;
        document.getElementById('qdBulkStop').disabled = true;
        var sb = document.getElementById('qdBulkStart');
        sb.textContent = '继续';
        sb.disabled = false;
        setBulkUI(qdBulk.done, qdBulk.total, qdBulk.ok, qdBulk.bad,
            '已停止（已处理 ' + qdBulk.done + ' / ' + qdBulk.total + '，点「继续」从断点恢复）');
    });
}

function closeBulk() {
    if (qdBulk.running) {
        // 单点判定直接关（用户已明确意图），批量才确认停止
        if (qdBulk.single) {
            doCloseBulk();
        } else {
            qidnsConfirm('任务正在进行中，停止并关闭吗？（已处理部分会保留）', doCloseBulk,
                { title: '停止任务', okText: '停止并关闭', danger: true });
        }
        return;
    }
    doCloseBulk();
}

function doCloseBulk() {
    if (qdBulk.running) {
        qidnsPost(qdBulk.cfg.base + 'bulkStop', {}, function(){});
        qdBulk.round++;
        qdBulk.running = false;
        if (qdBulk.timer) { clearTimeout(qdBulk.timer); qdBulk.timer = null; }
        setBulkBusy(false);
    }
    document.getElementById('qdBulkModal').style.display = 'none';
}

/* ==================== WHOIS 详情浮层（域名列小按钮） ==================== */
function openWhois(id) {
    var box = document.getElementById('qdWhoisModal');
    if (!box) {
        box = document.createElement('div');
        box.id = 'qdWhoisModal';
        box.className = 'qd-modal-mask';
        box.innerHTML =
            '<div class="qd-modal qd-whois-modal">' +
              '<h2 id="qdWhoisTitle">WHOIS 查询</h2>' +
              '<div id="qdWhoisBody" class="qd-whois-body"><span class="muted">查询中…</span></div>' +
              '<div class="qd-modal-actions">' +
                '<button type="button" class="btn" id="qdWhoisClose">关闭</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(box);
        document.getElementById('qdWhoisClose').addEventListener('click', function(){ closeWhois(); });
        // 点击遮罩空白处关闭
        box.addEventListener('click', function(e){ if (e.target === box) { closeWhois(); } });
    }
    document.getElementById('qdWhoisTitle').textContent = 'WHOIS 查询';
    document.getElementById('qdWhoisBody').innerHTML = '<span class="muted">查询中…（注册局 RDAP / WHOIS）</span>';
    box.style.display = 'flex';
    qidnsPost('/index.php?r=Domain/whoisLookup', {domain_id: id}, function(r){
        var body = document.getElementById('qdWhoisBody');
        if (!r || r.code !== 0) {
            body.innerHTML = '<div class="qd-whois-err">' + (r ? qdEsc(r.msg) : '网络错误') + '</div>';
            return;
        }
        var d = r.data || {};
        document.getElementById('qdWhoisTitle').textContent = 'WHOIS · ' + qdEsc(d.domain || '');
        var source = d.source === 'rdap' ? 'RDAP（HTTPS）' : (d.source === 'whois' ? 'WHOIS 协议' : '未获取到');
        var days = d.expire_at
            ? '<span class="qd-whois-days ' + (d.days_left <= 30 ? 'urgent' : '') + '">剩余 ' + d.days_left + ' 天</span>'
            : '';
        var rows = [
            ['数据来源', qdEsc(source)],
            ['注册商', qdEsc(d.registrar_name || '—')],
            ['到期日', qdEsc(d.expire_at || '—') + ' ' + days],
            ['注册时间', qdEsc(d.created_at || '—')],
            ['更新时间', qdEsc(d.updated_at || '—')],
            ['域名服务器', (d.nameservers && d.nameservers.length) ? qdEsc(d.nameservers.slice(0, 6).join('\n')) : '—'],
            ['域名状态', (d.status && d.status.length) ? qdEsc(d.status.slice(0, 8).join(', ')) : '—'],
        ];
        body.innerHTML = rows.map(function(row){
            return '<div class="qd-whois-row"><span class="qd-whois-k">' + row[0] + '</span>' +
                   '<span class="qd-whois-v">' + row[1].replace(/\n/g, '<br>') + '</span></div>';
        }).join('');
    });
}

function closeWhois() {
    var box = document.getElementById('qdWhoisModal');
    if (box) { box.style.display = 'none'; }
}

/** HTML 转义，防止 WHOIS 原文里的尖括号破坏浮层 */
function qdEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/* ==================== 标签列：折叠展开 + 单击筛选 / 双击取消 ====================
 * 域名列表与「按在用 DNS 分组」共用：
 *  - 行内只铺 2 个标签，其余收进 .tag-extra，点 » 在单元格内向下展开（行高增加）；
 *  - 标签 chip 单击按该标签筛选，双击从当前域名上摘掉这个标签。
 * 两者共用同一个元素，所以单击延后 240ms 执行：期间来了第二击就取消跳转交给 dblclick。
 */
function toggleTagRow(btn){
    var cell = btn.closest('.tag-cell');
    var extra = cell ? cell.querySelector('.tag-extra') : null;
    if(!extra) return;
    if(extra.hasAttribute('hidden')){
        extra.removeAttribute('hidden');
        btn.textContent = '«';
        btn.title = '收起';
    } else {
        extra.setAttribute('hidden', '');
        btn.textContent = '» ' + (btn.getAttribute('data-count') || '');
        btn.title = '展开其余标签';
    }
}

var tagChipTimer = null, tagChipPending = null;

document.addEventListener('click', function(e){
    var chip = e.target.closest('.tag-chip');
    if(!chip || !chip.hasAttribute('data-domain-id')) return;
    e.preventDefault();
    if(tagChipTimer){
        if(tagChipPending === chip) return;            // 同一 chip 的第二击：等 dblclick
        clearTimeout(tagChipTimer);                     // 换了个 chip：先把前一个的筛选走掉
        location.href = tagChipPending.getAttribute('href');
        return;
    }
    tagChipPending = chip;
    tagChipTimer = setTimeout(function(){
        tagChipTimer = null;
        tagChipPending = null;
        location.href = chip.getAttribute('href');
    }, 240);
});

document.addEventListener('dblclick', function(e){
    var chip = e.target.closest('.tag-chip');
    if(!chip || !chip.hasAttribute('data-domain-id')) return;
    e.preventDefault();
    clearTimeout(tagChipTimer);
    tagChipTimer = null;
    tagChipPending = null;
    removeTagFromDomain(chip);
});

function removeTagFromDomain(chip){
    var id = chip.getAttribute('data-domain-id');
    var tag = chip.getAttribute('data-tag');
    var cell = chip.closest('.tag-cell');
    qidnsPost('/index.php?r=Domain/removeTag', {domain_id: id, tag: tag}, function(r){
        if(r && r.code === 0){
            dropTagChip(chip);
            // 「标」按钮回填的是 data-tags，必须跟着更新，否则下次打开编辑会看到旧标签再保存回去
            var editBtn = cell ? cell.querySelector('[data-tags]') : null;
            if(editBtn) editBtn.setAttribute('data-tags', (r.data && r.data.tags) || '');
        } else {
            qidnsAlert(r ? r.msg : '移除失败', {title: '移除失败'});
        }
    });
}

/** 就地摘掉一个 chip，并从折叠区补位，保持「列里 2 个 + » N」的结构 */
function dropTagChip(chip){
    var cell = chip.closest('.tag-cell');
    if(!cell){ chip.remove(); return; }
    var list = cell.querySelector('.tag-list');
    var extra = cell.querySelector('.tag-extra');
    var more = cell.querySelector('.tag-more');
    chip.remove();

    while(list.querySelectorAll('a.tag-chip').length < 2 && extra && extra.querySelector('a.tag-chip')){
        list.appendChild(extra.querySelector('a.tag-chip'));
    }
    if(!list.querySelectorAll('a.tag-chip').length){
        list.innerHTML = '<span class="muted">—</span>';
        if(more) more.remove();
        if(extra) extra.remove();
        return;
    }
    if(extra && !extra.querySelector('a.tag-chip')){
        extra.remove();
        if(more) more.remove();
        return;
    }
    if(more && extra){
        var n = extra.querySelectorAll('a.tag-chip').length;
        more.setAttribute('data-count', n);
        more.textContent = '» ' + n;
        more.title = '展开其余 ' + n + ' 个标签';
    }
}
