<?php /** @var string $content */ ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QiDNS · 多平台 DNS 解析管理系统</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="logo">QiDNS</div>
        <nav>
            <a href="/index.php?r=Dashboard" class="<?= ($_GET['r'] ?? 'Dashboard') === 'Dashboard' ? 'active' : '' ?>">仪表盘</a>
            <a href="/index.php?r=Account" class="<?= strpos($_GET['r'] ?? '', 'Account') === 0 ? 'active' : '' ?>">服务商账号</a>
            <a href="/index.php?r=Registry" class="<?= strpos($_GET['r'] ?? '', 'Registry') === 0 ? 'active' : '' ?>">DNS 管理</a>
            <a href="/index.php?r=Domain" class="<?= ($_GET['r'] ?? '') === 'Domain' && ($_GET['view'] ?? 'all') !== 'hosting' ? 'active' : '' ?>">域名管理</a>
            <a href="/index.php?r=Tag" class="<?= strpos($_GET['r'] ?? '', 'Tag') === 0 ? 'active' : '' ?>">标签管理</a>
            <a href="/index.php?r=Template" class="<?= strpos($_GET['r'] ?? '', 'Template') === 0 ? 'active' : '' ?>">解析模板</a>
            <a href="/index.php?r=Monitor" class="<?= strpos($_GET['r'] ?? '', 'Monitor') === 0 ? 'active' : '' ?>">到期监控</a>
        </nav>
        <div class="sidebar-footer">
            <span><?= \QiDNS\Core\View::e(\QiDNS\Core\Auth::nickname() ?: \QiDNS\Core\Auth::username()) ?></span>
            <a href="/index.php?r=Auth/logout" class="logout">退出</a>
        </div>
    </aside>
    <main class="main">
        <?= $content ?>
    </main>
</div>
<script src="/assets/js/app.js"></script>
</body>
</html>
