<?php \QiDNS\Core\View::setLayout(null); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 · QiDNS</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">
<div class="login-box">
    <h1>QiDNS</h1>
    <p class="subtitle">多平台 DNS 解析管理系统</p>
    <form id="loginForm">
        <label>用户名</label>
        <input type="text" name="username" autocomplete="username" required autofocus>
        <label>密码</label>
        <input type="password" name="password" autocomplete="current-password" required>
        <button type="submit" class="btn btn-primary btn-block">登 录</button>
    </form>
    <div class="msg" id="loginMsg"></div>
</div>
<script>
document.getElementById('loginForm').addEventListener('submit', function(e){
    e.preventDefault();
    var msgEl = document.getElementById('loginMsg');
    msgEl.textContent = '';
    msgEl.className = 'msg';
    var fd = new FormData(this);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/index.php?r=Auth/doLogin');
    xhr.onreadystatechange = function(){
        if(xhr.readyState === 4){
            var r = JSON.parse(xhr.responseText);
            if(r.code === 0){ location.href = '/index.php?r=Dashboard'; }
            else { msgEl.textContent = r.msg; msgEl.className = 'msg error'; }
        }
    };
    xhr.send(fd);
});
</script>
</body>
</html>
