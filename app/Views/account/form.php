<?php
use QiDNS\Core\View;
/** @var array|null $account @var array $providers @var array $cred */
$isEdit = $account !== null;
$curProvider = $isEdit ? (string)$account['provider'] : 'dnspod';
$hasLegacy = isset($cred['login_token']);
$hasApi3 = isset($cred['secret_id']) || isset($cred['secret_key']);
?>
<h1 class="page-title"><?= $isEdit ? '编辑账号' : '添加账号' ?></h1>

<div class="card account-form">
    <?php if ($isEdit): ?>
    <div class="msg success" style="margin-bottom:16px">编辑时凭证框留空 = 保留原凭证不修改；填了新值才会覆盖。</div>
    <?php endif; ?>
    <!-- autocomplete=off + 各凭证框 new-password：阻止浏览器把本表单当登录表单自动填入已存密码 -->
    <form id="accountForm" autocomplete="off">
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$account['id'] ?>"><?php endif; ?>

        <div class="form-row">
            <div>
                <label>账号名 *</label>
                <input type="text" name="name" value="<?= $isEdit ? View::e($account['name']) : '' ?>" autocomplete="off" required>
            </div>
            <div>
                <label>服务商 *</label>
                <select name="provider" id="providerSelect" onchange="toggleProvider()" autocomplete="off">
                    <?php foreach ($providers as $code => $label): ?>
                        <option value="<?= $code ?>" <?= $curProvider === $code ? 'selected' : '' ?>><?= View::e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- 腾讯云 DNSPod：一个账号填两套 API，至少必填一套 -->
        <div id="credDnspod" class="cred-block">
            <div class="msg" style="margin:12px 0 14px">
                同一个腾讯云账号的两套 API 填在一起即可：下方两套<b>至少填写一套</b>。
                传统 Token 管解析；API 3.0 同时管解析与「DNS 管理」所需的注册域名。
            </div>
            <div class="cred-grid">
                <fieldset class="cred-fieldset">
                    <legend class="cred-legend">① 传统 DNSPod Token<?= $hasLegacy ? ' · 已配置' : '' ?></legend>
                    <div class="cred-fields">
                        <div>
                            <label>ID</label>
                            <input type="text" name="token_id" inputmode="numeric" autocomplete="off" placeholder="<?= $hasLegacy ? '已配置，留空保留' : '如 655177' ?>">
                        </div>
                        <div>
                            <label>Token</label>
                            <input type="password" name="token_secret" autocomplete="new-password" placeholder="<?= $hasLegacy ? '已配置，留空保留' : 'Token 密钥部分' ?>">
                        </div>
                    </div>
                    <p class="muted" style="margin-top:6px">
                        DNSPod 控制台 → 用户中心 → 安全设置 → API Token（是「DNSPod Token」而非腾讯云密钥）。ID 与 Token 分开填，仅管解析；留空表示不用这套。
                    </p>
                </fieldset>

                <fieldset class="cred-fieldset">
                    <legend class="cred-legend">② 腾讯云 API 3.0 密钥<?= $hasApi3 ? ' · 已配置' : '' ?></legend>
                    <div class="cred-fields">
                        <div>
                            <label>APPID</label>
                            <input type="text" name="appid" inputmode="numeric" autocomplete="off" placeholder="<?= $hasApi3 ? '已配置，留空保留' : '如 1251065001' ?>">
                        </div>
                        <div>
                            <label>SecretId</label>
                            <input type="text" name="secret_id" autocomplete="off" placeholder="<?= $hasApi3 ? '已配置，留空保留' : '如 AKIDxxxxxxxx' ?>">
                        </div>
                    </div>
                    <label>SecretKey</label>
                    <input type="password" name="secret_key" autocomplete="new-password" placeholder="<?= $hasApi3 ? '已配置，留空保留' : '与 SecretId 配对的密钥（签名必需）' ?>">
                    <p class="muted" style="margin-top:6px">
                        即 DNSPod 控制台「腾讯云 API 密钥」一栏（TC3 签名）。需它才能读取腾讯云注册域名、支撑「DNS 管理」菜单。SecretId 与 SecretKey 需同时填写才算这套已配置；APPID 仅作备注。
                    </p>
                </fieldset>
            </div>
        </div>

        <div id="credAliyun" class="cred-block" style="display:none">
            <div class="form-row">
                <div>
                    <label>AccessKey ID *</label>
                    <input type="text" name="access_key_id" autocomplete="off" placeholder="阿里云 AccessKey ID">
                </div>
                <div>
                    <label>AccessKey Secret *</label>
                    <input type="password" name="access_key_secret" autocomplete="new-password" placeholder="阿里云 AccessKey Secret">
                </div>
            </div>
            <p class="muted" style="margin-top:6px">需为该 RAM 用户授予 AliyunDNSFullAccess 权限。</p>
        </div>

        <div id="credWest" class="cred-block" style="display:none">
            <div class="form-row">
                <div>
                    <label>会员账号（用户名）*</label>
                    <input type="text" name="username" autocomplete="off" placeholder="西部数码会员账号">
                </div>
                <div>
                    <label>API 密码 *</label>
                    <input type="password" name="api_password" autocomplete="new-password" placeholder="代理商管理 → API接口配置 中设置的 API 密码">
                </div>
            </div>
            <p class="muted" style="margin-top:6px">
                一个账号即可拉取总账户下所有域名，无需逐域名接入。<br>
                需为西部数码正式代理：登录后台 →「代理商管理」→「API接口配置」查看/设置 API 密码。
            </p>
        </div>

        <label>备注</label>
        <input type="text" name="remark" value="<?= $isEdit ? View::e($account['remark']) : '' ?>" autocomplete="off">

        <div style="margin-top:20px; display:flex; gap:12px">
            <button type="submit" class="btn btn-primary">保存</button>
            <a href="/index.php?r=Account" class="btn">取消</a>
        </div>
        <div class="msg" id="formMsg"></div>
    </form>
</div>

<script>
function toggleProvider(){
    var p = document.getElementById('providerSelect').value;
    document.getElementById('credDnspod').style.display = (p === 'dnspod') ? '' : 'none';
    document.getElementById('credAliyun').style.display = (p === 'aliyun') ? '' : 'none';
    document.getElementById('credWest').style.display = (p === 'west') ? '' : 'none';
}
toggleProvider();

document.getElementById('accountForm').addEventListener('submit', function(e){
    e.preventDefault();
    var msg = document.getElementById('formMsg');
    var showErr = function(t){ msg.textContent = t; msg.className = 'msg error'; };
    var fd = new FormData(this);
    var provider = fd.get('provider');

    if (provider === 'dnspod') {
        var tid = (fd.get('token_id') || '').trim();
        var tsec = (fd.get('token_secret') || '').trim();
        // ID 与 Token 必须成对：只填一个会拼出无效凭证
        if ((tid === '') !== (tsec === '')) {
            showErr('传统 DNSPod Token 的 ID 与 Token 请同时填写，或同时留空保留原值');
            return;
        }
        // 拼回后端既有的单字段格式（新增时两空则传空，由后端判定至少一套）
        fd.delete('token_id');
        fd.delete('token_secret');
        fd.set('login_token', tid === '' ? '' : (tid + ',' + tsec));
    }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/index.php?r=Account/save');
    xhr.onreadystatechange = function(){
        if(xhr.readyState === 4){
            var r = JSON.parse(xhr.responseText);
            if(r.code === 0){ location.href = '/index.php?r=Account'; }
            else { showErr(r.msg); }
        }
    };
    xhr.send(fd);
});
</script>
