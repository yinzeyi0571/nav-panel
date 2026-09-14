<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 引导文件
 *
 * 做四件事：读配置 → 连数据库 → 跑迁移 → 开会话。
 * 每个请求都会跑一遍，迁移是幂等的，所以升级只需覆盖文件，不用手工执行 SQL。
 */

define('NAV_API_DIR', __DIR__);
define('NAV_ROOT', dirname(__DIR__));

if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 500, 'msg' => '需要 PHP 7.4 或更高版本', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 配置 ----
$GLOBALS['NAV_CONFIG']  = require NAV_ROOT . '/config/config.php';
$GLOBALS['NAV_VERSION'] = require NAV_ROOT . '/config/version.php';

// ---- 类 ----
require NAV_API_DIR . '/Db.php';
require NAV_API_DIR . '/Response.php';
require NAV_API_DIR . '/Auth.php';
require NAV_API_DIR . '/Model.php';
require NAV_API_DIR . '/Settings.php';
require NAV_API_DIR . '/Icons.php';
require NAV_API_DIR . '/System.php';

// 先把配置交给 Db，后面 nav_cfg() 才能拿到值
Db::boot($GLOBALS['NAV_CONFIG']);

$__debug = !empty($GLOBALS['NAV_CONFIG']['debug']);
ini_set('display_errors', $__debug ? '1' : '0');
error_reporting($__debug ? E_ALL : (E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING));
unset($__debug);

// ---- 未捕获异常统一转成 JSON，避免把 PHP 报错吐给前端 ----
set_exception_handler(function ($e) {
    $debug = !empty($GLOBALS['NAV_CONFIG']['debug']);
    Response::fatal($debug ? ($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()) : '服务器错误');
});

// ---- 时区 ----
date_default_timezone_set('Asia/Shanghai');

// ---- 会话 ----
if (session_status() !== PHP_SESSION_ACTIVE) {
    $lifetime = (int)nav_cfg('session_lifetime', 315360000);
    if ($lifetime < 3600) $lifetime = 3600;

    // 会话文件独立存放。
    // 宝塔的 /tmp 是全服务器共用的：面板/别的站点也是 PHP，它们触发会话回收时会按各自的
    // gc_maxlifetime（默认 1440 秒）扫描同一个目录，把我们的会话文件一并删掉。
    // 落到站点根外的独立目录后，回收范围只限本站。
    $sessionDir = (string)nav_cfg('session_dir', '');
    if ($sessionDir !== '') {
        if (!is_dir($sessionDir)) @mkdir($sessionDir, 0700, true);
        if (is_dir($sessionDir) && is_writable($sessionDir)) {
            @session_save_path($sessionDir);
        }
    }

    @ini_set('session.gc_maxlifetime', (string)$lifetime);
    @ini_set('session.use_strict_mode', '1');

    // 这里发的是「浏览器会话 Cookie」（lifetime = 0，关浏览器即失效）。
    // 勾了「记住我」的登录，会在 Auth::attempt() 里换成带过期时间的长期 Cookie，
    // 由 nav_remember_session() 负责下发并滑动续期。
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off'),
    ]);
    session_name((string)nav_cfg('session_name', 'nav_sid'));
    session_start();

    // 已登录且是「记住我」的会话：快到期时自动往后顺延
    nav_remember_session();
}

// ---- 数据库 ----
Db::migrate();

/* ==================================================================
 * 全局小工具
 * ================================================================== */

/** 读配置 */
function nav_cfg($key = null, $default = null)
{
    return Db::config($key, $default);
}

/**
 * 「记住我」的长期登录 Cookie。
 *
 * 两种调用方式：
 *   nav_remember_session(true)  登录成功时显式下发（不管 $_SESSION 里原来记着什么）
 *   nav_remember_session()      每个请求自动跑一遍：只对记住我的会话做滑动续期
 *
 * 为什么要自己下发 Cookie：PHP 只在「新建会话 / 重新生成 ID」时才发 Set-Cookie，
 * 日常请求不会重发，所以 Cookie 的过期时间是死的（登录那一刻 + N 天），
 * 天天在用也会在第 N 天被踢出去。这里主动顺延，做到「只要在用就永不掉线」。
 *
 * 续期间隔 30 天：远小于浏览器 400 天的单条 Cookie 上限，又不会每个请求都带 Set-Cookie。
 */
function nav_remember_session($remember = null)
{
    if (empty($_SESSION['user_id'])) return;

    if ($remember === null) {
        if (empty($_SESSION['remember'])) return;   // 没勾记住我 → 保持浏览器会话 Cookie
    } elseif ($remember) {
        $_SESSION['remember'] = 1;
    } else {
        unset($_SESSION['remember'], $_SESSION['_renew']);
        return;
    }

    $ttl = max(3600, (int)nav_cfg('session_lifetime', 315360000));
    $now = time();
    $last = isset($_SESSION['_renew']) ? (int)$_SESSION['_renew'] : 0;
    if ($last > 0 && ($now - $last) < 2592000) return;   // 30 天内续过就不再发

    if (!headers_sent()) {
        @setcookie(session_name(), session_id(), [
            'expires'  => $now + $ttl,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off'),
        ]);
    }
    $_SESSION['_renew'] = $now;
}

/** 读版本信息 */
function nav_version($key = null, $default = null)
{
    $v = $GLOBALS['NAV_VERSION'];
    if ($key === null) return $v;
    return isset($v[$key]) ? $v[$key] : $default;
}

/** 请求体（JSON 或 form），解析一次后缓存 */
function nav_input()
{
    static $in = null;
    if ($in !== null) return $in;

    $in = [];
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $in = $decoded;
        }
    }
    if (!empty($_POST)) {
        $in = array_merge($in, $_POST);
    }
    if (!empty($_GET)) {
        $in = array_merge($in, $_GET);
    }
    return $in;
}

/** 取一个参数 */
function nav_param($key, $default = null)
{
    $in = nav_input();
    if (array_key_exists($key, $in)) return $in[$key];
    if (isset($_FILES[$key])) return $_FILES[$key];
    return $default;
}

/** 取字符串参数（去空白） */
function nav_str($key, $default = '')
{
    $v = nav_param($key, $default);
    if (is_array($v)) return $default;
    return trim((string)$v);
}

/** 取整数参数 */
function nav_int($key, $default = 0)
{
    $v = nav_param($key, $default);
    if (is_array($v)) return $default;
    return (int)$v;
}

/** 取布尔参数（0/1） */
function nav_bool($key, $default = 0)
{
    $v = nav_param($key, null);
    if ($v === null) return (int)$default;
    if (is_bool($v)) return $v ? 1 : 0;
    return (int)(bool)$v;
}

/** 生成业务 ID（字符串型，和 v1 的 id 格式保持一致） */
function nav_next_id()
{
    return (string)round(microtime(true) * 1000) . random_int(100, 999);
}

/** JSON 解码，失败给默认值 */
function nav_json($raw, $default = [])
{
    if (!is_string($raw) || $raw === '') return $default;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : $default;
}

/** 把用户手输的地址补全成合法 URL（baidu.com → http://baidu.com） */
function nav_normalize_url($url)
{
    $url = trim((string)$url);
    if ($url === '') return '';
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $url)) return $url;   // 已有协议
    if (strpos($url, '//') === 0) return 'https:' . $url;                  // //host
    if (stripos($url, 'localhost') === 0 || preg_match('#^\d{1,3}(\.\d{1,3}){3}#', $url)) {
        return 'http://' . ltrim($url, '/');                               // 局域网地址用 http
    }
    return 'http://' . ltrim($url, '/');
}

/** 从地址里取主机名，作为站点的默认名称 */
function nav_host_from_url($url)
{
    $host = parse_url(nav_normalize_url($url), PHP_URL_HOST);
    if (!$host) return '';
    $host = preg_replace('/^www\./i', '', $host);
    return (string)$host;
}

/** 参数强制转成数组（POST 可能给 JSON 字符串，也可能给真数组） */
function nav_arr($key)
{
    $v = nav_param($key, null);
    if (is_string($v)) $v = nav_json($v, []);
    return is_array($v) ? $v : [];
}

/**
 * 地址里的主机名是否合法。
 *
 * nav_normalize_url() 会无脑给任何字符串补上 http://，
 * 所以用户把一整句话（尤其是中文）粘进来时，需要这道校验拦住它。
 * 单标签主机名（nas / nas.lan）是局域网常态，必须放行。
 */
function nav_valid_host($url)
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '' || strlen($host) > 253) return false;
    return (bool)preg_match('/^[A-Za-z0-9_]([A-Za-z0-9\-_.]*[A-Za-z0-9_])?$/', $host);
}
