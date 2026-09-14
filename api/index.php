<?php
/**
 * API 统一入口
 *
 * 所有请求都走这里，用 action 参数分发到 api/modules/ 下的模块。
 *   例: POST /api/index.php   body: {"action":"sites.add", "name":"群晖", "url":"http://..."}
 *       GET  /api/index.php?action=releases.list
 *
 * 沿用 v1 的「单一入口 + action」模式：不依赖 nginx 重写规则，宝塔默认配置直接能跑。
 */

define('NAV_ENTRY', true);

require __DIR__ . '/bootstrap.php';

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

// 预检请求
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!in_array($method, ['GET', 'POST'], true)) {
    Response::send(405, '不支持的请求方法');
}

$action = nav_str('action');

// 不带 action 的 GET = 首页引导数据（保持与 v1 的 api.php 行为一致）
if ($action === '') {
    if ($method !== 'GET') {
        Response::fail('缺少 action 参数');
    }
    $action = 'home.index';
}

$routes = require __DIR__ . '/routes.php';

if (!isset($routes[$action])) {
    Response::send(404, '未知操作：' . $action);
}

list($module, $handler) = $routes[$action];

// 模块名只允许字母，防止路径穿越
if (!preg_match('/^[a-z][a-z0-9_]*$/', $module)) {
    Response::fatal('非法的模块名');
}

$file = __DIR__ . '/modules/' . $module . '.php';
if (!is_file($file)) {
    Response::fatal('模块尚未实现：' . $module);
}

$handlers = require $file;
if (!is_array($handlers) || !isset($handlers[$handler]) || !is_callable($handlers[$handler])) {
    Response::fatal('处理器尚未实现：' . $module . '.' . $handler);
}

// 执行
$handlers[$handler]();
