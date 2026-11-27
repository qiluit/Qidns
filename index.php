<?php

declare(strict_types=1);

define('APP_RUNNING', true);
define('APP_ROOT', __DIR__);
define('APP_START', microtime(true));

date_default_timezone_set('Asia/Shanghai');

if (!file_exists(APP_ROOT . '/config.php')) {
    header('Location: /install/');
    exit;
}

require APP_ROOT . '/app/Core/autoload.php';

use QiDNS\Core\Application;

(new Application())->run();
