<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 图标处理：本地上传 / 自动抓取 favicon / 在线图标库（Iconify 代理）
 *
 * 为什么这三件事放在一个类里：
 * 它们产出的都是「一个图标地址」，最终都写进 sites.icon（配合 icon_type 区分为图片或在线图标）。
 * 放在一起便于统一处理落盘目录、files 表登记和缓存。
 */

final class Icons
{
    /* ==================================================================
     * 一、本地上传
     * ================================================================== */

    /**
     * 处理 $_FILES['file'] 上传。
     * @return array ['ok'=>bool, 'msg'=>string, 'file'=>array]
     */
    public static function handleUpload($userId)
    {
        if (empty($_FILES['file'])) {
            return ['ok' => false, 'msg' => '没有收到文件'];
        }
        $f = $_FILES['file'];

        if (is_array($f['name'])) {
            return ['ok' => false, 'msg' => '一次只能上传一个文件'];
        }
        if ((int)$f['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'msg' => self::uploadErrorText((int)$f['error'])];
        }

        $maxBytes = (int)Db::config('upload_max_bytes', 2097152);
        if ((int)$f['size'] > $maxBytes) {
            return ['ok' => false, 'msg' => '文件不能超过 ' . round($maxBytes / 1048576, 1) . ' MB'];
        }

        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $allowed = (array)Db::config('upload_allowed_ext', ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'svg']);
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            return ['ok' => false, 'msg' => '只支持 ' . implode(' / ', $allowed) . ' 格式'];
        }

        // 只信文件内容，不信扩展名
        $realExt = self::imageExt((string)@file_get_contents($f['tmp_name'], false, null, 0, 512));
        if ($realExt === '') {
            return ['ok' => false, 'msg' => '这个文件不是有效的图片'];
        }

        // SVG 是可执行的 XML，可能带脚本。这里只做内容扫描，发现可疑就拒收。
        if ($realExt === 'svg') {
            $svg = (string)@file_get_contents($f['tmp_name']);
            if (!self::svgIsSafe($svg)) {
                return ['ok' => false, 'msg' => 'SVG 里含有脚本内容，已拒绝上传'];
            }
        }

        $sub = date('Y/m/d');
        $dir = self::uploadDir() . '/' . $sub;
        if (!self::ensureDir($dir)) {
            return ['ok' => false, 'msg' => '上传目录不可写，请检查 uploads/ 权限'];
        }

        $base = date('His') . '_' . bin2hex(random_bytes(4));
        $name = $base . '.' . $realExt;

        if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
            return ['ok' => false, 'msg' => '文件保存失败'];
        }
        @chmod($dir . '/' . $name, 0644);

        $src = self::uploadUrl() . '/' . $sub . '/' . $name;
        $id  = Model::insertFile($userId, $src, (string)$f['name'], $realExt, self::mimeOf($realExt), (int)$f['size'], 1);

        return ['ok' => true, 'msg' => '上传成功', 'file' => [
            'id'   => $id,
            'src'  => $src,
            'ext'  => $realExt,
            'size' => (int)$f['size'],
        ]];
    }

    /* ==================================================================
     * 二、自动抓取 favicon
     * ================================================================== */

    /**
     * 填个域名，自动去抓它的站点图标。
     *
     * 抓取顺序：/favicon.ico → 页面里 <link rel="icon"> → /apple-touch-icon.png
     * 结果按主机名缓存到 uploads/favicon/，默认 7 天内不重复抓。
     *
     * @return array ['ok'=>bool, 'msg'=>string, 'icon'=>string, 'cached'=>bool]
     */
    public static function fetchFavicon($url, $userId, $force = false)
    {
        $url  = nav_normalize_url($url);
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || !nav_valid_host($url)) {
            return ['ok' => false, 'msg' => '请填写有效的网址'];
        }

        $dir   = self::uploadDir() . '/favicon';
        $key   = md5($host);
        $ttl   = (int)Db::config('favicon_cache_ttl', 604800);

        // 1. 命中缓存（扩展名不定，扫一遍目录）
        if (!$force) {
            $hit = self::findCached($dir, $key, $ttl);
            if ($hit !== null) {
                return ['ok' => true, 'msg' => '已使用缓存图标', 'icon' => $hit['url'], 'cached' => true];
            }
        }

        // 2. 逐个候选地址试
        $candidates = [
            self::schemeFor($url) . '://' . $host . '/favicon.ico',
            self::schemeFor($url) . '://' . $host . '/apple-touch-icon.png',
        ];

        // 3. 顺带解析首页里的 <link rel="icon">
        $page = self::httpGet(self::schemeFor($url) . '://' . $host . '/', 524288);
        if ($page !== null) {
            foreach (self::iconLinksFromHtml($page['body'], self::schemeFor($url) . '://' . $host . '/') as $u) {
                $candidates[] = $u;
            }
        }
        $candidates = array_values(array_unique($candidates));

        $tried = [];
        foreach ($candidates as $cand) {
            $tried[] = $cand;
            $res = self::httpGet($cand, 512000);
            if ($res === null) continue;

            $ext = self::imageExt($res['body']);
            if ($ext === '') continue;                 // 拿到的是 404 页面之类的
            if ($ext === 'svg' && !self::svgIsSafe($res['body'])) continue;

            if (!self::ensureDir($dir)) {
                return ['ok' => false, 'msg' => '图标缓存目录不可写'];
            }
            $file = $dir . '/' . $key . '.' . $ext;
            if (@file_put_contents($file, $res['body']) === false) {
                return ['ok' => false, 'msg' => '图标保存失败'];
            }
            @chmod($file, 0644);

            $src = self::uploadUrl() . '/favicon/' . $key . '.' . $ext;
            Model::insertFile($userId, $src, $host . '.' . $ext, $ext, self::mimeOf($ext), strlen($res['body']), 2);

            return ['ok' => true, 'msg' => '图标抓取成功', 'icon' => $src, 'cached' => false, 'source' => $cand];
        }

        return ['ok' => false, 'msg' => '没抓到图标，请手动上传', 'tried' => $tried];
    }

    /** 在缓存目录里找 <key>.<任意扩展名>，且没过期 */
    private static function findCached($dir, $key, $ttl)
    {
        foreach (['png', 'ico', 'jpg', 'jpeg', 'gif', 'webp', 'svg'] as $ext) {
            $file = $dir . '/' . $key . '.' . $ext;
            if (!is_file($file)) continue;
            if ($ttl > 0 && (time() - filemtime($file)) > $ttl) continue;
            return ['url' => self::uploadUrl() . '/favicon/' . $key . '.' . $ext];
        }
        return null;
    }

    /** 从首页 HTML 里抽 <link rel="icon" href="..."> */
    private static function iconLinksFromHtml($html, $pageUrl)
    {
        if (!preg_match_all('/<link\b[^>]*>/i', (string)$html, $m)) return [];

        $out = [];
        foreach ($m[0] as $tag) {
            if (!preg_match('/\brel\s*=\s*["\']?([^"\'>\s]+)/i', $tag, $r)) continue;
            if (stripos($r[1], 'icon') === false) continue;                  // icon / shortcut icon / apple-touch-icon
            if (!preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $h)) continue;

            $abs = self::absolutize(html_entity_decode(trim($h[1]), ENT_QUOTES, 'UTF-8'), $pageUrl);
            if ($abs !== '') $out[] = $abs;
        }
        return $out;
    }

    /** 把相对地址补成绝对地址 */
    private static function absolutize($link, $base)
    {
        if ($link === '' || stripos($link, 'data:') === 0) return '';
        if (preg_match('#^https?://#i', $link)) return $link;

        $p = parse_url($base);
        if (!isset($p['scheme']) || !isset($p['host'])) return '';

        if (strpos($link, '//') === 0) return $p['scheme'] . ':' . $link;
        if ($link[0] === '/') {
            return $p['scheme'] . '://' . $p['host'] . $link;
        }
        $dirPart = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
        return $p['scheme'] . '://' . $p['host'] . $dirPart . $link;
    }

    /** 局域网地址（IP / 单标签主机名 / localhost）默认走 http */
    private static function schemeFor($url)
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === 'http' || $scheme === 'https') {
            // 用户明确写了 https 就用 https，即使目标是局域网
            return $scheme;
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === 'localhost' || strpos($host, '.') === false
            || preg_match('#^\d{1,3}(\.\d{1,3}){3}$#', $host)) {
            return 'http';
        }
        return 'https';
    }

    /* ==================================================================
     * 三、在线图标库（Iconify 代理）
     * ================================================================== */

    /** 搜索在线图标。前端不直连 Iconify，统一走这里，避免跨域和混合内容问题。 */
    public static function searchIconify($query, $limit = 64)
    {
        $limit = max(1, min(256, (int)$limit));
        $q = trim((string)$query);

        $path = '/search?query=' . rawurlencode($q) . '&limit=' . $limit;
        $data = self::iconifyGet($path);

        if ($data === null) {
            return ['ok' => false, 'msg' => '在线图标库暂时连不上，请稍后重试'];
        }

        // Iconify 返回 {icons:[...], collections:{...}, total:n}
        $icons = isset($data['icons']) && is_array($data['icons']) ? array_slice($data['icons'], 0, $limit) : [];
        return ['ok' => true, 'icons' => $icons, 'total' => isset($data['total']) ? (int)$data['total'] : count($icons)];
    }

    /** 图标集列表（前端图标库左侧分类栏用） */
    public static function iconifyCollections()
    {
        $data = self::iconifyGet('/collections');
        if ($data === null) {
            return ['ok' => false, 'msg' => '在线图标库暂时连不上，请稍后重试'];
        }

        $out = [];
        foreach ($data as $prefix => $c) {
            if (!is_array($c)) continue;
            $out[] = [
                'prefix' => (string)$prefix,
                'name'   => isset($c['name']) ? (string)$c['name'] : (string)$prefix,
                'total'  => isset($c['total']) ? (int)$c['total'] : 0,
                'sample' => isset($c['samples']) && is_array($c['samples']) ? array_slice($c['samples'], 0, 8) : [],
            ];
        }
        // 大的图标集排前面，方便找
        usort($out, function ($a, $b) { return $b['total'] - $a['total']; });
        return ['ok' => true, 'collections' => $out];
    }

    /** 依次尝试主站和备用站，任一成功即返回 */
    private static function iconifyGet($path)
    {
        $hosts = array_merge(
            [(string)Db::config('iconify_api', 'https://api.iconify.design')],
            (array)Db::config('iconify_fallbacks', [])
        );

        foreach ($hosts as $h) {
            $h = rtrim((string)$h, '/');
            if ($h === '') continue;
            $res = self::httpGet($h . $path, 1048576, true);
            if ($res === null) continue;
            $json = json_decode($res['body'], true);
            if (is_array($json)) return $json;
        }
        return null;
    }

    /* ==================================================================
     * 四、底层工具
     * ================================================================== */

    /** 带超时的 GET，失败返回 null */
    private static function httpGet($url, $maxBytes = 524288, $json = false)
    {
        if (!function_exists('curl_init')) {
            return self::httpGetNoCurl($url, $maxBytes);
        }

        $timeout = max(3, (int)Db::config('favicon_timeout', 5));
        $insecure = (bool)Db::config('favicon_insecure', true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout * 2,
            // 局域网自签证书非常常见，默认不校验证书；只用于取图标，风险可控。
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NavBot/2.0)',
            CURLOPT_HTTPHEADER     => ['Accept: ' . ($json ? 'application/json' : 'image/*,text/html;q=0.8,*/*;q=0.5')],
        ]);

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_errno($ch);
        curl_close($ch);

        if ($err !== 0 || $code < 200 || $code >= 300) return null;
        if (!is_string($body) || $body === '') return null;
        if (strlen($body) > $maxBytes) return null;

        return ['body' => $body, 'code' => $code];
    }

    /** 没装 curl 扩展时的兜底实现 */
    private static function httpGetNoCurl($url, $maxBytes)
    {
        $timeout = max(3, (int)Db::config('favicon_timeout', 5));
        $ctx = stream_context_create([
            'http' => [
                'timeout'         => $timeout,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'user_agent'      => 'Mozilla/5.0 (compatible; NavBot/2.0)',
                'ignore_errors'   => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $body = @file_get_contents($url, false, $ctx, 0, $maxBytes);
        if (!is_string($body) || $body === '') return null;
        return ['body' => $body, 'code' => 200];
    }

    /** 按字节头判断真实图片格式，返回扩展名或 '' */
    private static function imageExt($data)
    {
        if (!is_string($data) || $data === '') return '';
        if (strncmp($data, "\x00\x00\x01\x00", 4) === 0) return 'ico';
        if (strncmp($data, "\x89PNG\r\n\x1a\n", 8) === 0) return 'png';
        if (strncmp($data, 'GIF87a', 6) === 0 || strncmp($data, 'GIF89a', 6) === 0) return 'gif';
        if (strncmp($data, "\xFF\xD8\xFF", 3) === 0) return 'jpg';
        if (strncmp($data, 'RIFF', 4) === 0 && substr($data, 8, 4) === 'WEBP') return 'webp';

        $head = ltrim(substr($data, 0, 600));
        if (stripos($head, '<svg') !== false || stripos($head, '<?xml') !== false) return 'svg';

        return '';
    }

    /** SVG 内容安全扫描：拦掉脚本、事件属性、外链实体 */
    private static function svgIsSafe($svg)
    {
        $svg = (string)$svg;
        if (stripos($svg, '<script') !== false) return false;
        if (stripos($svg, '<foreignObject') !== false) return false;
        if (stripos($svg, 'javascript:') !== false) return false;
        if (preg_match('/\son[a-z]+\s*=/i', $svg)) return false;      // onload= / onclick= ...
        if (stripos($svg, '<!ENTITY') !== false) return false;        // XXE
        return true;
    }

    private static function mimeOf($ext)
    {
        $map = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
            'svg' => 'image/svg+xml',
        ];
        $ext = strtolower((string)$ext);
        return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
    }

    private static function uploadErrorText($code)
    {
        $map = [
            UPLOAD_ERR_INI_SIZE   => '文件超过服务器限制',
            UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL    => '文件只上传了一部分',
            UPLOAD_ERR_NO_FILE    => '没有选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
            UPLOAD_ERR_EXTENSION  => '上传被扩展阻止',
        ];
        return isset($map[$code]) ? $map[$code] : '上传失败（错误码 ' . $code . '）';
    }

    public static function uploadDir()
    {
        return rtrim((string)Db::config('upload_dir', NAV_ROOT . '/uploads'), '/\\');
    }

    public static function uploadUrl()
    {
        return rtrim((string)Db::config('upload_url', '/uploads'), '/');
    }

    private static function ensureDir($dir)
    {
        if (is_dir($dir)) return true;
        return @mkdir($dir, 0755, true) || is_dir($dir);
    }

    /** 把 /uploads/xxx 这样的地址还原成磁盘路径（删文件时用） */
    public static function srcToPath($src)
    {
        $src  = (string)$src;
        $base = self::uploadUrl();
        if ($base !== '' && strpos($src, $base) !== 0) return null;

        $rel = ltrim(substr($src, strlen($base)), '/');
        if ($rel === '' || strpos($rel, '..') !== false) return null;

        return self::uploadDir() . '/' . $rel;
    }
}
