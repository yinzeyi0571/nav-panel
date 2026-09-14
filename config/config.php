<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 导航站 v2 站点配置
 *
 * 部署到宝塔时只需要改这个文件，不要改 api/ 下的业务代码。
 * 兼容 PHP 7.4（NTS）。
 */

$webRoot = __DIR__;                 // <站点根>/config
$siteRoot = dirname($webRoot);      // <站点根>

// SQLite 主库位置。
// 默认放在站点根目录的「上一级」，这样 HTTP 访问不到，避免 data.db 被直接下载。
// 例如站点是 /www/wwwroot/123.lan/，库就是 /www/wwwroot/123.lan-data/data.db
// 想放别处：设置环境变量 NAV_DB_FILE 即可，不用改代码。
// rtrim 是为了站点根直接挂在 / 下时（如 /site）不拼出 //xxx-data 这种双斜杠路径
$dbFile = (getenv('NAV_DB_FILE') !== false && getenv('NAV_DB_FILE') !== '')
    ? getenv('NAV_DB_FILE')
    : rtrim(dirname($siteRoot), '/\\') . '/' . basename($siteRoot) . '-data/data.db';

return [

    /* ==================== 数据库 ==================== */

    // SQLite 主库位置（路径怎么算出来的见文件开头 $dbFile）
    'db_file' => $dbFile,

    // 老库位置（v1 在站点根下的 data.db）。首次启动会自动迁移过去。
    'db_legacy_file' => $siteRoot . '/data.db',

    // 如果上面那个目录没权限创建，自动退回站点根下的 storage/
    'db_fallback_dir' => $siteRoot . '/storage',

    /* ==================== 上传 ==================== */

    // 必须在站点根下，否则浏览器取不到图标
    'upload_dir' => $siteRoot . '/uploads',
    'upload_url' => '/uploads',
    'upload_allowed_ext' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'svg'],
    'upload_max_bytes' => 2097152,   // 2MB

    /* ==================== 图标 ==================== */

    // 自动抓取站点 favicon 的超时（秒）
    'favicon_timeout' => 5,
    // 抓取结果的缓存时长（秒），默认 7 天。设为 0 表示永不过期。
    'favicon_cache_ttl' => 604800,
    // 抓 favicon 时是否跳过 HTTPS 证书校验。
    // 局域网里的 NAS / 软路由大多是自签证书，保持 true 才能抓到图标。
    'favicon_insecure' => true,

    // 在线图标库（Iconify）。前端不直连，统一走本站代理，避免跨域。
    'iconify_api' => 'https://api.iconify.design',
    'iconify_fallbacks' => ['https://api.simplesvg.com', 'https://api.unisvg.com'],

    /* ==================== 会话 ==================== */

    'session_name' => 'nav_sid',

    // 勾选「记住我」后的登录有效期（秒）。
    // 这里给 10 年 ≈ 永不过期：本站在每次访问时会「滑动续期」，只要还在用就永远不会掉线。
    // 注意浏览器对单条 Cookie 有硬上限（Chrome/Safari 约 400 天），所以真正保证「永不失效」
    // 靠的是滑动续期，而不是这个数字本身。
    // 不勾「记住我」时发的是浏览器会话 Cookie：关掉浏览器就失效（此值不生效）。
    'session_lifetime' => 315360000,

    // 会话文件存放目录。
    // 必须放在站点根之外（和数据库同目录，天然不可通过 HTTP 下载）。
    // 关键原因：宝塔的 session.save_path 默认是 /tmp，而 /tmp 是整台服务器所有 PHP 应用共用的；
    // 宝塔面板自己也是 PHP，它触发会话垃圾回收时会按它自己的 session.gc_maxlifetime（默认 1440 秒）
    // 扫描 /tmp，把我们的 sess_nav_sid* 一起删掉 —— 表现就是「明明记住我了，20 分钟没点就又掉线」。
    // 独立目录后，回收范围只限本站，互不干扰。
    'session_dir' => rtrim(dirname($dbFile), '/\\') . '/sessions',

    /* ==================== 其它 ==================== */

    'debug' => false,
];
