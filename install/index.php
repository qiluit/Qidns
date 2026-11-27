<?php

declare(strict_types=1);

define('APP_RUNNING', true);
define('APP_ROOT', dirname(__DIR__));

date_default_timezone_set('Asia/Shanghai');

// 已安装则跳转主页
if (file_exists(APP_ROOT . '/config.php')) {
    header('Location: /index.php');
    exit;
}

$step = (int)($_GET['step'] ?? 1);
$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = (int)($_POST['step'] ?? 1);

    if ($step === 1) {
        // 校验环境 + 写 config.php
        $dbHost = trim($_POST['db_host'] ?? '');
        $dbPort = (int)($_POST['db_port'] ?? 3306);
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = (string)($_POST['db_pass'] ?? '');

        // 测试连接
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
            new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            $error = '数据库连接失败: ' . $e->getMessage();
        }

        if ($error === '') {
            $appKey = bin2hex(random_bytes(16)); // 32 字节 hex
            $config = "<?php\n\nreturn [\n"
                . "    'name' => 'QiDNS',\n"
                . "    'timezone' => 'Asia/Shanghai',\n"
                . "    'session_name' => 'qidns_session',\n"
                . "    'debug' => false,\n"
                . "    'app_key' => '" . $appKey . "',\n"
                . "    'curl_cainfo' => '',\n"
                . "    'db' => [\n"
                . "        'host' => '" . addslashes($dbHost) . "',\n"
                . "        'port' => " . $dbPort . ",\n"
                . "        'database' => '" . addslashes($dbName) . "',\n"
                . "        'username' => '" . addslashes($dbUser) . "',\n"
                . "        'password' => '" . addslashes($dbPass) . "',\n"
                . "        'charset' => 'utf8mb4',\n"
                . "        'persistent' => false,\n"
                . "    ],\n"
                . "];\n";

            if (file_put_contents(APP_ROOT . '/config.php', $config) === false) {
                $error = '无法写入 config.php，请检查目录权限';
            } else {
                $step = 2;
            }
        }
    } elseif ($step === 2) {
        // 建表 + 初始化管理员
        require APP_ROOT . '/app/Core/autoload.php';
        try {
            $pdo = \QiDNS\Core\DB::pdo();
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            // 逐条执行
            $statements = preg_split('/;\s*[\r\n]+/', $sql);
            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if ($stmt !== '' && stripos($stmt, '--') !== 0) {
                    $pdo->exec($stmt);
                }
            }

            $username = trim($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $nickname = trim($_POST['nickname'] ?? '');

            if ($username === '' || strlen($password) < 6) {
                throw new \RuntimeException('用户名不能为空，密码至少 6 位');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO qd_user (username, password, nickname, created_at) VALUES (?,?,?,?)')
                ->execute([$username, $hash, $nickname, date('Y-m-d H:i:s')]);

            $ok = '安装完成！';
            $step = 3;
        } catch (\Throwable $e) {
            $error = '建表或初始化失败: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装向导 · QiDNS</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">
<div class="login-box" style="width:480px">
    <h1>QiDNS 安装向导</h1>
    <p class="subtitle">多平台 DNS 解析管理系统</p>

    <?php if ($error): ?>
        <div class="msg error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($ok): ?>
        <div class="msg success" style="margin-bottom:16px"><?= htmlspecialchars($ok) ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <form method="post">
            <input type="hidden" name="step" value="1">
            <label>数据库主机</label>
            <input type="text" name="db_host" value="127.0.0.1" required>
            <label>端口</label>
            <input type="number" name="db_port" value="3306" required>
            <label>数据库名</label>
            <input type="text" name="db_name" value="qiludns" required>
            <label>用户名</label>
            <input type="text" name="db_user" value="qiludns" required>
            <label>密码</label>
            <input type="password" name="db_pass" value="">
            <button type="submit" class="btn btn-primary btn-block" style="margin-top:20px">下一步</button>
        </form>
    <?php elseif ($step === 2): ?>
        <form method="post">
            <input type="hidden" name="step" value="2">
            <label>管理员用户名</label>
            <input type="text" name="username" value="admin" required>
            <label>昵称</label>
            <input type="text" name="nickname" value="管理员">
            <label>密码（至少 6 位）</label>
            <input type="password" name="password" required>
            <button type="submit" class="btn btn-primary btn-block" style="margin-top:20px">完成安装</button>
        </form>
    <?php else: ?>
        <p>安装完成，请删除 <code>install/</code> 目录以提升安全性。</p>
        <a href="/index.php" class="btn btn-primary btn-block" style="margin-top:16px">进入系统</a>
    <?php endif; ?>
</div>
</body>
</html>
