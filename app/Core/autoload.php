<?php

declare(strict_types=1);

/**
 * 轻量 PSR-4 风格自动加载（QiDNS 命名空间 → app/ 目录）
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'QiDNS\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// 加载配置
$GLOBALS['qidns_config'] = require APP_ROOT . '/config.php';

// 错误显示（生产环境关闭）
$display = (bool)($GLOBALS['qidns_config']['debug'] ?? false);
ini_set('display_errors', $display ? '1' : '0');
error_reporting($display ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING));

/**
 * 兜底异常处理：未捕获异常不再变成空白 500
 *  - AJAX（POST）：返回统一 JSON，前端能直接弹出后端真实原因（如服务商 API 报错）
 *  - 页面（GET）：给一张简洁错误页
 *  - 堆栈只进 error_log，不把内部路径暴露到界面
 */
set_exception_handler(static function (\Throwable $e): void {
    error_log('[qidns] ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());

    $debug = (bool)($GLOBALS['qidns_config']['debug'] ?? false);
    // 业务异常（RuntimeException）通常来自服务商 API，原文对用户有意义；其它异常按环境脱敏
    $msg = ($e instanceof \RuntimeException || $debug)
        ? $e->getMessage()
        : '服务端处理失败，请重试或查看运行日志';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['code' => 1, 'msg' => $msg, 'data' => null], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="zh-CN"><meta charset="utf-8"><title>出错了 · QiDNS</title>'
        . '<div style="font-family:system-ui,-apple-system,sans-serif;padding:56px;max-width:720px;margin:0 auto">'
        . '<h1 style="font-size:20px;margin:0 0 12px">出错了</h1>'
        . '<p style="color:#5b6b7c;line-height:1.7">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="margin-top:24px"><a href="/index.php?r=Dashboard" style="color:#083858">返回首页</a></p>'
        . '</div></html>';
});
