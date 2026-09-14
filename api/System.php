<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 系统状态采集
 *
 * 全部直接读 /proc，不依赖 shell_exec。
 * 理由：宝塔默认会把 shell_exec / exec 加进 disable_functions，用 shell 命令会直接 500。
 * 读 /proc 是纯文件读取，PHP-FPM 下没有权限问题，也没有命令注入面。
 */

final class System
{
    /** 依赖 /proc 的接口只在 Linux 上有意义 */
    public static function supported()
    {
        return is_dir('/proc') && is_readable('/proc/stat');
    }

    /** 一次拿齐前台监控卡片要的全部数据 */
    public static function snapshot()
    {
        return [
            'supported' => self::supported(),
            'hostname'  => self::hostname(),
            'os'        => self::osName(),
            'uptime'    => self::uptime(),
            'load'      => self::load(),
            'cpu'       => self::cpuPercent(),
            'cpu_count' => self::cpuCount(),
            'memory'    => self::memory(),
            'disk'      => self::rootDisk(),
            'php'       => PHP_VERSION,
            'time'      => time(),
        ];
    }

    public static function hostname()
    {
        $h = @gethostname();
        if (is_string($h) && $h !== '') return $h;

        $n = @file_get_contents('/proc/sys/kernel/hostname');
        return $n ? trim($n) : php_uname('n');
    }

    /** 从 /etc/os-release 读发行版名称 */
    public static function osName()
    {
        $s = @file_get_contents('/etc/os-release');
        if (is_string($s) && $s !== '' && preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', $s, $m)) {
            return $m[1];
        }
        return php_uname('s') . ' ' . php_uname('r');
    }

    /** 开机时长（秒） */
    public static function uptime()
    {
        $s = @file_get_contents('/proc/uptime');
        if (is_string($s) && $s !== '') {
            return (int)floor((float)explode(' ', trim($s))[0]);
        }
        return 0;
    }

    /** 1 / 5 / 15 分钟平均负载 */
    public static function load()
    {
        $s = @file_get_contents('/proc/loadavg');
        if (!is_string($s) || $s === '') return null;
        $p = explode(' ', trim($s));
        if (count($p) < 3) return null;
        return ['one' => (float)$p[0], 'five' => (float)$p[1], 'fifteen' => (float)$p[2]];
    }

    /** 逻辑 CPU 核数 */
    public static function cpuCount()
    {
        $s = @file_get_contents('/proc/cpuinfo');
        if (is_string($s) && $s !== '') {
            $n = preg_match_all('/^processor\s*:/m', $s);
            if ($n > 0) return $n;
        }
        return 1;
    }

    /**
     * CPU 使用率（%）。
     * 需要两次采样求差值 —— /proc/stat 给的是开机以来的累计值，单次读只能得到平均值。
     * 采样间隔 120ms，对前端体感无影响。
     */
    public static function cpuPercent($intervalMs = 120)
    {
        $a = self::cpuSample();
        if ($a === null) return null;

        usleep(max(20000, (int)$intervalMs * 1000));

        $b = self::cpuSample();
        if ($b === null) return null;

        $dt = $b['total'] - $a['total'];
        $di = $b['idle'] - $a['idle'];
        if ($dt <= 0) return 0.0;

        return round((1 - $di / $dt) * 100, 1);
    }

    private static function cpuSample()
    {
        $s = @file_get_contents('/proc/stat');
        if (!is_string($s) || $s === '') return null;

        $line = strtok($s, "\n");
        if (!is_string($line) || strpos($line, 'cpu ') !== 0) return null;

        $v = array_map('floatval', preg_split('/\s+/', trim(substr($line, 4))));
        if (count($v) < 4) return null;

        return [
            'total' => array_sum($v),
            'idle'  => $v[3] + (isset($v[4]) ? $v[4] : 0),   // idle + iowait 都算空闲
        ];
    }

    /** 内存（字节）。用 MemAvailable 而不是 MemFree —— 后者不含可回收的缓存，会虚高。 */
    public static function memory()
    {
        $s = @file_get_contents('/proc/meminfo');
        if (!is_string($s) || $s === '') return null;

        $kv = [];
        foreach (explode("\n", $s) as $l) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $l, $m)) {
                $kv[$m[1]] = (float)$m[2] * 1024;
            }
        }
        if (empty($kv['MemTotal'])) return null;

        $total = $kv['MemTotal'];
        $free  = isset($kv['MemAvailable']) ? $kv['MemAvailable']
            : (isset($kv['MemFree']) ? $kv['MemFree'] : 0);
        $used  = max(0, $total - $free);

        return [
            'total'   => $total,
            'used'    => $used,
            'free'    => $free,
            'percent' => $total > 0 ? round($used / $total * 100, 1) : 0,
            'swap_total' => isset($kv['SwapTotal']) ? $kv['SwapTotal'] : 0,
            'swap_used'  => isset($kv['SwapTotal'], $kv['SwapFree']) ? max(0, $kv['SwapTotal'] - $kv['SwapFree']) : 0,
        ];
    }

    /** 站点所在分区的磁盘占用 */
    public static function rootDisk()
    {
        $path = NAV_ROOT;
        if (!is_dir($path)) $path = '/';

        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);
        if ($total === false || $total <= 0) return null;

        $used = $total - $free;
        return [
            'path'    => $path,
            'total'   => (float)$total,
            'used'    => (float)$used,
            'free'    => (float)$free,
            'percent' => round($used / $total * 100, 1),
        ];
    }

    /**
     * 所有真实挂载点的磁盘占用。
     * 过滤掉 proc / tmpfs / overlay 这些伪文件系统，否则列表里会塞满无意义的条目。
     */
    public static function disks()
    {
        $lines = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return [];

        $skipFs = [
            'proc', 'sysfs', 'devtmpfs', 'tmpfs', 'devpts', 'cgroup', 'cgroup2', 'overlay',
            'squashfs', 'autofs', 'securityfs', 'debugfs', 'tracefs', 'fusectl', 'mqueue',
            'hugetlbfs', 'pstore', 'bpf', 'configfs', 'nsfs', 'ramfs', 'binfmt_misc',
            'rpc_pipefs', 'efivarfs', 'fuse.gvfsd-fuse', 'fuse.portal', 'selinuxfs', 'sysfs',
        ];

        $out  = [];
        $seen = [];

        foreach ($lines as $line) {
            $p = preg_split('/\s+/', $line);
            if (count($p) < 3) continue;

            list($dev, $mount, $fs) = [$p[0], $p[1], $p[2]];
            if (in_array($fs, $skipFs, true)) continue;

            // 只保留块设备和网络文件系统
            $isBlock = (strpos($dev, '/dev/') === 0);
            $isNet   = (bool)preg_match('#^[A-Za-z0-9_.\-]+:/#', $dev);
            if (!$isBlock && !$isNet) continue;

            if (isset($seen[$mount])) continue;
            $seen[$mount] = true;

            $total = @disk_total_space($mount);
            $free  = @disk_free_space($mount);
            if ($total === false || $total <= 0) continue;

            $used = $total - $free;
            $out[] = [
                'device'  => $dev,
                'mount'   => $mount,
                'fs'      => $fs,
                'total'   => (float)$total,
                'used'    => (float)$used,
                'free'    => (float)$free,
                'percent' => round($used / $total * 100, 1),
            ];
        }

        // 使用率高的排前面，方便一眼看出哪个盘快满了
        usort($out, function ($a, $b) { return $b['percent'] <=> $a['percent']; });
        return $out;
    }

    /* ==================================================================
     * 存储与安全自检
     * ================================================================== */

    /**
     * 报告数据库真实落点、v1 遗留文件、上传目录可写性。
     *
     * 为什么需要这个：宝塔默认的 .user.ini 里 open_basedir 只放行站点根，
     * v2 想把库放到站点根「同级」的 <站点名>-data/ 就会被拦下，代码随即
     * 静默退回站点根内的 storage/ —— 那等于数据库又能被 HTTP 直接下载。
     * 这个接口把真实落点摊开，不登服务器也能一眼确认有没有退回去。
     */
    public static function storage()
    {
        $dbFile    = Db::file();          // 走 resolveDbFile()，是「真实」路径而不是配置值
        $root      = NAV_ROOT;
        $rootReal  = realpath($root);
        $dbDirReal = realpath(dirname($dbFile));
        $dbReal    = ($dbDirReal !== false) ? $dbDirReal . DIRECTORY_SEPARATOR . basename($dbFile)
            : $dbFile;

        // 判断库是否落在站点根内部。
        // 必须用「真实路径 + 分隔符」做前缀比较：直接 strpos 会把
        // /www/wwwroot/123.lan-data 误判成落在 /www/wwwroot/123.lan 里面。
        $inside = false;
        if ($rootReal !== false) {
            $prefix = rtrim($rootReal, '/\\') . DIRECTORY_SEPARATOR;
            $inside = (strpos($dbReal, $prefix) === 0);
        }

        $legacy       = Db::config('db_legacy_file');
        $legacyExists = $legacy && file_exists($legacy);

        $uploadDir      = Db::config('upload_dir');
        $uploadWritable = is_dir($uploadDir) && is_writable($uploadDir);

        $warnings = [];
        if ($inside) {
            $warnings[] = '数据库落在站点根目录内，可能被直接下载。请检查 .user.ini 的 open_basedir 是否包含 '
                . dirname($dbFile) . '，改完记得让 PHP-FPM 重新加载。';
        }
        if ($legacyExists) {
            $warnings[] = '站点根下仍有 v1 遗留的 ' . basename($legacy)
                . '，可被匿名下载。确认新库数据正常后请删除它。';
        }
        if (!$uploadWritable) {
            $warnings[] = '上传目录不存在或不可写：' . $uploadDir;
        }

        return [
            'db_file'            => $dbFile,
            'db_exists'          => file_exists($dbFile),
            'db_size'            => file_exists($dbFile) ? (int)filesize($dbFile) : 0,
            'db_outside_webroot' => !$inside,
            'web_root'           => ($rootReal !== false) ? $rootReal : $root,
            'open_basedir'       => (string)ini_get('open_basedir'),
            'legacy_db'          => $legacy ? $legacy : '',
            'legacy_db_exists'   => (bool)$legacyExists,
            'upload_dir'         => $uploadDir,
            'upload_writable'    => $uploadWritable,
            'warnings'           => $warnings,
        ];
    }
}
