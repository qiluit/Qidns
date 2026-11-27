<?php

declare(strict_types=1);

namespace QiDNS\Core;

/**
 * 主应用：路由分发
 * 路由规则：/index.php?r=控制器/动作 或 pathinfo
 */
class Application
{
    public function run(): void
    {
        Auth::init();

        $route = $this->resolveRoute();
        $controller = $route['controller'];
        $action = $route['action'];

        $class = 'QiDNS\\Controllers\\' . $controller . 'Controller';
        if (!class_exists($class)) {
            $this->notFound('控制器不存在');
        }
        $instance = new $class();

        if (!method_exists($instance, $action)) {
            $this->notFound('动作不存在');
        }

        // 登录检查（login 与 install 动作豁免）
        $needAuth = !($controller === 'Auth' && in_array($action, ['login', 'doLogin', 'logout'], true));
        if ($needAuth && !Auth::check()) {
            Response::redirect('/index.php?r=Auth/login');
        }

        $instance->$action();
    }

    private function resolveRoute(): array
    {
        // 支持 ?r=Controller/action 与 ?r=Controller（默认 index）
        $r = trim((string)($_GET['r'] ?? ''), '/');
        if ($r === '') {
            $r = 'Dashboard';
        }
        $parts = explode('/', $r);
        $controller = $this->normalizeController($parts[0] ?? 'Dashboard');
        $action = $this->normalizeAction($parts[1] ?? 'index');

        return ['controller' => $controller, 'action' => $action];
    }

    /** 控制器名：首字母大写，下划线转驼峰 */
    private function normalizeController(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        $parts = explode('_', $name);
        return implode('', array_map('ucfirst', $parts));
    }

    /** 动作名：保持首字母小写，下划线转驼峰 */
    private function normalizeAction(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        if ($name === '') {
            return 'index';
        }
        $parts = explode('_', $name);
        $first = lcfirst(array_shift($parts));
        foreach ($parts as $p) {
            $first .= ucfirst($p);
        }
        return $first;
    }

    private function notFound(string $msg): void
    {
        http_response_code(404);
        echo '<h1>404 Not Found</h1><p>' . htmlspecialchars($msg) . '</p>';
        exit;
    }
}
