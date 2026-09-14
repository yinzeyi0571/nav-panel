<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 数据库层：连接 + 表结构定义 + 幂等迁移
 *
 * 设计原则：
 *  1. 迁移是「幂等」的 —— 每次请求都可以安全跑一遍，不依赖迁移状态表是否完好。
 *     已存在的表不动，缺的列补上，缺的索引补上。旧库升级和新装库走同一套代码。
 *  2. 不 DROP、不重建、不删数据。v1 的 data.db 直接原地升级。
 *  3. 兼容 PHP 7.4。
 */

final class Db
{
    /** @var PDO|null */
    private static $pdo = null;

    /** @var array 配置 */
    private static $cfg = [];

    /** @var string|null 实际解析出来的库文件路径（可能因落盘失败退回 storage/） */
    private static $resolvedFile = null;

    /* ==================================================================
     * 表结构定义（新装库用 CREATE TABLE IF NOT EXISTS 建出来）
     * ================================================================== */
    const TABLES = [

        'users' => "CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role          TEXT NOT NULL DEFAULT 'user',
            nickname      TEXT DEFAULT '',
            email         TEXT DEFAULT '',
            avatar        TEXT DEFAULT '',
            status        INTEGER NOT NULL DEFAULT 1,
            last_login_at INTEGER,
            created_at    INTEGER NOT NULL
        )",

        'categories' => "CREATE TABLE IF NOT EXISTS categories (
            id          TEXT NOT NULL,
            user_id     INTEGER NOT NULL,
            name        TEXT NOT NULL,
            icon        TEXT DEFAULT '',
            icon_bg     TEXT DEFAULT '',
            description TEXT DEFAULT '',
            sort        INTEGER DEFAULT 0,
            created_at  INTEGER,
            updated_at  INTEGER,
            PRIMARY KEY (id, user_id)
        )",

        'sites' => "CREATE TABLE IF NOT EXISTS sites (
            id           TEXT NOT NULL,
            user_id      INTEGER NOT NULL,
            name         TEXT NOT NULL,
            url          TEXT DEFAULT '',
            url_internal TEXT DEFAULT '',
            description  TEXT DEFAULT '',
            icon         TEXT DEFAULT '',
            icon_type    INTEGER NOT NULL DEFAULT 2,
            icon_bg      TEXT DEFAULT '',
            open_method  INTEGER NOT NULL DEFAULT 2,
            category_id  TEXT DEFAULT '0',
            sort         INTEGER DEFAULT 0,
            hits         INTEGER NOT NULL DEFAULT 0,
            created_at   INTEGER,
            updated_at   INTEGER,
            PRIMARY KEY (id, user_id)
        )",

        'settings' => "CREATE TABLE IF NOT EXISTS settings (
            user_id         INTEGER PRIMARY KEY,
            theme           TEXT DEFAULT 'theme-dark',
            theme_mode      TEXT DEFAULT 'auto',
            wallpaper       TEXT DEFAULT '',
            bg_blur         INTEGER NOT NULL DEFAULT 0,
            bg_mask         INTEGER NOT NULL DEFAULT 0,
            clock_visible   INTEGER NOT NULL DEFAULT 1,
            clock_second    INTEGER NOT NULL DEFAULT 0,
            logo_visible    INTEGER NOT NULL DEFAULT 1,
            logo_text       TEXT DEFAULT '导航站',
            logo_image      TEXT DEFAULT '',
            footer          TEXT DEFAULT '',
            footer_html     INTEGER NOT NULL DEFAULT 0,
            search_engine   TEXT DEFAULT 'baidu',
            search_box_show INTEGER NOT NULL DEFAULT 1,
            net_toggle_show INTEGER NOT NULL DEFAULT 1,
            open_mode       TEXT DEFAULT 'new',
            icon_style      TEXT DEFAULT 'icon',
            icon_text_color TEXT DEFAULT '#ffffff',
            icon_hide_title INTEGER NOT NULL DEFAULT 0,
            icon_hide_desc  INTEGER NOT NULL DEFAULT 0,
            max_width       INTEGER NOT NULL DEFAULT 1200,
            margin_top      INTEGER NOT NULL DEFAULT 4,
            margin_bottom   INTEGER NOT NULL DEFAULT 7,
            margin_x        INTEGER NOT NULL DEFAULT 15,
            monitor_show    INTEGER NOT NULL DEFAULT 0,
            monitor_title   INTEGER NOT NULL DEFAULT 1,
            sort_mode       TEXT DEFAULT 'manual',
            updated_at      INTEGER
        )",

        'files' => "CREATE TABLE IF NOT EXISTS files (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            src        TEXT NOT NULL,
            file_name  TEXT DEFAULT '',
            ext        TEXT DEFAULT '',
            mime       TEXT DEFAULT '',
            size       INTEGER NOT NULL DEFAULT 0,
            method     INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL
        )",

        'releases' => "CREATE TABLE IF NOT EXISTS releases (
            version     TEXT PRIMARY KEY,
            released_at TEXT NOT NULL,
            notes       TEXT NOT NULL DEFAULT '{}'
        )",

        'meta' => "CREATE TABLE IF NOT EXISTS meta (
            k TEXT PRIMARY KEY,
            v TEXT
        )",
    ];

    /* ==================================================================
     * 增量列：旧库缺哪列补哪列（对标 Sun-Panel 的字段）
     * 格式: 表名 => [ 列名 => 列定义 ]
     * ================================================================== */
    const ADD_COLUMNS = [

        'users' => [
            'nickname'      => "TEXT DEFAULT ''",
            'email'         => "TEXT DEFAULT ''",
            'avatar'        => "TEXT DEFAULT ''",
            'status'        => "INTEGER NOT NULL DEFAULT 1",
            'last_login_at' => "INTEGER",
        ],

        'categories' => [
            // 对标 Sun-Panel item_icon_group.icon / .description
            'icon'        => "TEXT DEFAULT ''",
            'icon_bg'     => "TEXT DEFAULT ''",
            'description' => "TEXT DEFAULT ''",
            'created_at'  => "INTEGER",
            'updated_at'  => "INTEGER",
        ],

        'sites' => [
            // 对标 Sun-Panel item_icon.icon_json / open_method
            'icon_type'   => "INTEGER NOT NULL DEFAULT 2",   // 1文字 2图片 3在线图标
            'icon_bg'     => "TEXT DEFAULT ''",
            'open_method' => "INTEGER NOT NULL DEFAULT 2",   // 1当前页 2新窗口 3弹窗
            'hits'        => "INTEGER NOT NULL DEFAULT 0",
            'created_at'  => "INTEGER",
            'updated_at'  => "INTEGER",
        ],

        'settings' => [
            // 对标 Sun-Panel panel_json
            'theme_mode'      => "TEXT DEFAULT 'auto'",
            'bg_blur'         => "INTEGER NOT NULL DEFAULT 0",
            'bg_mask'         => "INTEGER NOT NULL DEFAULT 0",
            'clock_second'    => "INTEGER NOT NULL DEFAULT 0",
            'logo_text'       => "TEXT DEFAULT '导航站'",
            'logo_image'      => "TEXT DEFAULT ''",
            'footer_html'     => "INTEGER NOT NULL DEFAULT 0",
            'search_box_show' => "INTEGER NOT NULL DEFAULT 1",
            'net_toggle_show' => "INTEGER NOT NULL DEFAULT 1",
            'icon_text_color' => "TEXT DEFAULT '#ffffff'",
            'icon_hide_title' => "INTEGER NOT NULL DEFAULT 0",
            'icon_hide_desc'  => "INTEGER NOT NULL DEFAULT 0",
            'max_width'       => "INTEGER NOT NULL DEFAULT 1200",
            'margin_top'      => "INTEGER NOT NULL DEFAULT 4",
            'margin_bottom'   => "INTEGER NOT NULL DEFAULT 7",
            'margin_x'        => "INTEGER NOT NULL DEFAULT 15",
            'monitor_show'    => "INTEGER NOT NULL DEFAULT 0",
            'monitor_title'   => "INTEGER NOT NULL DEFAULT 1",
            'sort_mode'       => "TEXT DEFAULT 'manual'",
            'updated_at'      => "INTEGER",
        ],
    ];

    /* ==================================================================
     * 索引
     * ================================================================== */
    const INDEXES = [
        "CREATE INDEX IF NOT EXISTS idx_sites_user_sort  ON sites (user_id, sort)",
        "CREATE INDEX IF NOT EXISTS idx_sites_user_cat   ON sites (user_id, category_id, sort)",
        "CREATE INDEX IF NOT EXISTS idx_cat_user_sort    ON categories (user_id, sort)",
        "CREATE INDEX IF NOT EXISTS idx_files_user        ON files (user_id, created_at)",
    ];

    /* ================================================================== */

    public static function boot(array $cfg)
    {
        self::$cfg = $cfg;
    }

    public static function config($key = null, $default = null)
    {
        if ($key === null) return self::$cfg;
        return isset(self::$cfg[$key]) ? self::$cfg[$key] : $default;
    }

    /** 取得（并初始化）PDO 连接 */
    public static function pdo()
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $file = self::resolveDbFile();

        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$pdo = $pdo;
        return $pdo;
    }

    /**
     * 决定数据库文件放哪：
     *  1. 配置里的主位置
     *  2. 主位置建不出来 → 退回站点根下的 storage/
     *  3. 目标不存在但旧库存在 → 先把旧库复制过去（平滑迁移）
     */
    private static function resolveDbFile()
    {
        if (self::$resolvedFile !== null) return self::$resolvedFile;

        $target = self::config('db_file');
        $dir = dirname($target);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $fallbackDir = self::config('db_fallback_dir');
            if (!is_dir($fallbackDir)) @mkdir($fallbackDir, 0755, true);
            $target = rtrim($fallbackDir, '/\\') . '/data.db';
        }

        // 首次运行：把 v1 的 data.db 搬过来
        if (!file_exists($target)) {
            $legacy = self::config('db_legacy_file');
            if ($legacy && file_exists($legacy)) {
                // 旧库可能开着 WAL，连 -wal / -shm 一起搬，保证数据完整
                @copy($legacy, $target);
                if (file_exists($legacy . '-wal')) @copy($legacy . '-wal', $target . '-wal');
                if (file_exists($legacy . '-shm')) @copy($legacy . '-shm', $target . '-shm');
            }
        }

        return self::$resolvedFile = $target;
    }

    /** 当前实际使用的数据库文件路径 */
    public static function file()
    {
        // 必须走 resolveDbFile()，不能直接读配置：
        // 主目录建不出来时会退回 storage/，直接读配置会报出一个不存在的路径。
        return self::resolveDbFile();
    }

    /* ==================================================================
     * 迁移
     * ================================================================== */

    public static function migrate()
    {
        $pdo = self::pdo();
        $isFresh = !self::tableExists('users') || !self::hasAnyUser();

        // 1. 建表
        foreach (self::TABLES as $sql) {
            $pdo->exec($sql);
        }

        // 2. 补列（旧库升级）
        $added = [];
        foreach (self::ADD_COLUMNS as $table => $columns) {
            $have = self::columnNames($table);
            if (!$have) continue;                 // 表不存在，跳过
            foreach ($columns as $col => $def) {
                if (isset($have[strtolower($col)])) continue;
                $pdo->exec('ALTER TABLE ' . self::ident($table) . ' ADD COLUMN ' . self::ident($col) . ' ' . $def);
                $added[] = $table . '.' . $col;
            }
        }

        // 3. 建索引
        foreach (self::INDEXES as $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) { /* 索引冲突忽略 */ }
        }

        // 4. 首次安装的种子数据
        if ($isFresh) {
            self::seed();
        }

        // 5. 版本标记
        self::setMeta('schema_version', '2');
        if (self::getMeta('installed_at') === null) {
            self::setMeta('installed_at', (string)time());
        }

        // 6. 补齐已有用户的 settings 行，并把分组数据收拾干净
        $ids = $pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $uid = (int)$id;
            $pdo->prepare('INSERT OR IGNORE INTO settings (user_id) VALUES (?)')->execute([$uid]);
            self::normalizeCategories($uid);
        }

        // 7. 版本记录（升级安装也要补上，用 INSERT OR IGNORE 保证幂等）
        self::seedRelease();

        // 8. 建立可能还缺失的数据列默认值（旧行补默认值）
        self::backfill();

        return $added;
    }

    /**
     * 分组数据收拾干净（每次请求都跑，幂等）。
     *
     * v2 早期版本给每个用户塞过一个 id='0' 的「未分类」分组，前端还把它当特殊的兜底桶，
     * 结果同一个分组被渲染两遍、还平白多占一块区域。现在没有这个概念了：
     * 分组就是分组，站点永远挂在某个真实分组上。
     *
     * 做三件事：
     *   a. 把遗留的 id='0' 分组转成普通分组（空壳就删掉，有站点就改名保留）
     *   b. 保证用户至少有一个分组
     *   c. 把没有归宿的站点收回到第一个分组
     */
    private static function normalizeCategories($uid)
    {
        $pdo = self::pdo();
        $uid = (int)$uid;
        $defName = '我的收藏';

        // a. 遗留的 id='0'
        $stmt = $pdo->prepare("SELECT name FROM categories WHERE user_id = ? AND id = '0'");
        $stmt->execute([$uid]);
        $legacyName = $stmt->fetchColumn();

        if ($legacyName !== false) {
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE user_id = ?');
            $cnt->execute([$uid]);
            $total = (int)$cnt->fetchColumn();

            $used = $pdo->prepare("SELECT COUNT(*) FROM sites WHERE user_id = ? AND category_id = '0'");
            $used->execute([$uid]);
            $siteCount = (int)$used->fetchColumn();

            if ($siteCount === 0 && $total > 1) {
                // 纯自动生成的空壳，用户还有别的分组 → 直接去掉
                $pdo->prepare("DELETE FROM categories WHERE user_id = ? AND id = '0'")->execute([$uid]);
            } elseif ((string)$legacyName === '未分类' || (string)$legacyName === '') {
                // 还叫「未分类」就换成正常名字；用户自己改过的名字不动
                $pdo->prepare('UPDATE categories SET name = ? WHERE user_id = ? AND id = ?')
                    ->execute([$defName, $uid, '0']);
            }
        }

        // b. 一个分组都没有就补一个
        $stmt = $pdo->prepare('SELECT id FROM categories WHERE user_id = ? ORDER BY sort ASC, id ASC LIMIT 1');
        $stmt->execute([$uid]);
        $first = $stmt->fetchColumn();

        if ($first === false) {
            $first = (string)round(microtime(true) * 1000) . random_int(100, 999);
            $pdo->prepare('INSERT OR IGNORE INTO categories (id, user_id, name, sort, created_at) VALUES (?,?,?,?,?)')
                ->execute([$first, $uid, $defName, 0, time()]);
        }
        $first = (string)$first;

        // c. 没有归宿的站点收回来（NULL / 已删除的分组 / 历史的 '0'）
        $pdo->prepare(
            'UPDATE sites SET category_id = ?, updated_at = ?
             WHERE user_id = ?
               AND (category_id IS NULL
                    OR category_id NOT IN (SELECT id FROM categories WHERE user_id = ?))'
        )->execute([$first, time(), $uid, $uid]);
    }

    /** 旧行补默认值 —— 新增列对已存在的行是 NULL，这里补上 */
    private static function backfill()
    {
        $pdo = self::pdo();
        $sqls = [
            "UPDATE sites SET icon_type = 2 WHERE icon_type IS NULL",
            "UPDATE sites SET open_method = 2 WHERE open_method IS NULL",
            "UPDATE sites SET hits = 0 WHERE hits IS NULL",
            "UPDATE sites SET created_at = strftime('%s','now') WHERE created_at IS NULL",
            "UPDATE categories SET created_at = strftime('%s','now') WHERE created_at IS NULL",
            "UPDATE settings SET bg_blur = 0 WHERE bg_blur IS NULL",
            "UPDATE settings SET bg_mask = 0 WHERE bg_mask IS NULL",
            "UPDATE settings SET max_width = 1200 WHERE max_width IS NULL",
            "UPDATE settings SET margin_top = 4 WHERE margin_top IS NULL",
            "UPDATE settings SET margin_bottom = 7 WHERE margin_bottom IS NULL",
            "UPDATE settings SET margin_x = 15 WHERE margin_x IS NULL",
            "UPDATE settings SET clock_second = 0 WHERE clock_second IS NULL",
            "UPDATE settings SET footer_html = 0 WHERE footer_html IS NULL",
            "UPDATE settings SET icon_text_color = '#ffffff' WHERE icon_text_color IS NULL",
            "UPDATE settings SET logo_text = '导航站' WHERE logo_text IS NULL OR logo_text = ''",
            "UPDATE settings SET theme_mode = 'auto' WHERE theme_mode IS NULL",
            "UPDATE settings SET sort_mode = 'manual' WHERE sort_mode IS NULL",
            "UPDATE users SET status = 1 WHERE status IS NULL",
            "UPDATE users SET nickname = username WHERE nickname IS NULL OR nickname = ''",
        ];
        foreach ($sqls as $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) { /* 列不存在时忽略 */ }
        }
    }

    /** 全新安装的种子数据 */
    private static function seed()
    {
        $pdo = self::pdo();

        $pdo->prepare('INSERT INTO users (username, password_hash, role, nickname, created_at) VALUES (?,?,?,?,?)')
            ->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin', '管理员', time()]);
        $uid = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT OR IGNORE INTO settings (user_id) VALUES (?)')->execute([$uid]);
        // 自带一个普通分组（不是「未分类」，改名/删除都随意）
        $pdo->prepare('INSERT OR IGNORE INTO categories (id, user_id, name, sort, created_at) VALUES (?,?,?,?,?)')
            ->execute([
                (string)round(microtime(true) * 1000) . random_int(100, 999),
                $uid,
                '我的收藏',
                0,
                time(),
            ]);

        $pdo->prepare('INSERT OR IGNORE INTO meta (k, v) VALUES (?,?)')
            ->execute(['force_password_change', '1']);
    }

    /** 写入 v2.0.0 的初始更新记录 */
    private static function seedRelease()
    {
        $pdo = self::pdo();
        $notes = json_encode([
            '新增' => [
                '前台直接添加、编辑、删除站点，无需进入后台',
                '前台拖动排序：分组内拖动 + 跨分组拖动，松手即保存',
                '分组整体拖动排序',
                '对标 Sun-Panel：图标三态（文字 / 图片 / 在线图标库 Iconify）',
                '对标 Sun-Panel：图标底色、分组图标、打开方式三选（当前页 / 新窗口 / 弹窗）',
                '对标 Sun-Panel：自动抓取站点 favicon 并缓存到本地',
                '对标 Sun-Panel：背景模糊与遮罩可调',
                '对标 Sun-Panel：布局参数（最大宽度 / 上下左右边距）',
                '对标 Sun-Panel：页脚支持 HTML',
                '批量添加站点：一行一条，或名称与网址分两栏',
                '批量删除站点与分组',
                '版本更新页与前台底部版本号',
                '深浅色三态：深色 / 浅色 / 跟随系统',
                '右键菜单：打开、新标签页打开、复制链接、编辑、删除',
                '系统监控：CPU / 内存 / 磁盘',
            ],
            '优化' => [
                '后端拆分为 api/ 模块化结构，前端重构为 Vue3 单页应用',
                '数据库文件移出 Web 根目录，避免被直接下载',
                '数据库改为自动增量迁移，旧数据原地升级不丢失',
                '分组不再有「未分类」这个特殊概念：系统自带一个普通分组，改名、删除都随意；删除分组时组内站点会自动搬到剩下的第一个分组，站点永远不会无处可去',
                '顶部那排编辑工具（添加站点 / 批量添加 / 新建分组 / 批量选择 / 图标样式）收进一个小图标里，页面更清爽',
                '站点排序在删除后会自动压实，不会留下空档',
                '导入备份时同名分组会自动复用，不再重复创建',
            ],
            '修复' => [
                '前端路由改为 Hash 模式，刷新不再 404',
                '修复同一个分组在前台被重复渲染成两块的问题',
                '修复批量添加时会把中文说明文字当成网址建成站点的问题',
                '修复删除图标文件后文件 ID 漂移导致再次删除失败的问题',
            ],
        ], JSON_UNESCAPED_UNICODE);

        $pdo->prepare('INSERT OR IGNORE INTO releases (version, released_at, notes) VALUES (?,?,?)')
            ->execute(['2.0.0', '2026-09-14', $notes]);

        self::seedRelease210();
        self::seedRelease211();
    }

    /** 写入 v2.1.0 的初始更新记录 */
    private static function seedRelease210()
    {
        $notes = json_encode([
            '新增' => [
                '登录页新增「记住我」：勾选后长期保持登录，关掉浏览器再打开依然是登录状态',
                '会话改为每次访问自动滑动续期 —— 只要还在用就不会掉线（此前是登录后固定 7 天，第 8 天必被踢出）',
            ],
            '优化' => [
                '会话文件从共享的 /tmp 迁到站点根外的独立目录：不再受服务器上其他 PHP 程序（如宝塔面板）会话回收的影响',
                '接口返回 401（登录失效）时自动跳回登录页，并保留原访问地址，登录后回到原处',
            ],
            '修复' => [
                '修复「记住我」场景下明明在用却仍被强制退出的问题',
                '修复会话失效后页面停在半登录状态、点击按钮只报错的问题',
            ],
        ], JSON_UNESCAPED_UNICODE);

        self::pdo()->prepare('INSERT OR IGNORE INTO releases (version, released_at, notes) VALUES (?,?,?)')
            ->execute(['2.1.0', '2026-09-14', $notes]);
    }

    /** 写入 v2.1.1 的初始更新记录 */
    private static function seedRelease211()
    {
        $notes = json_encode([
            '修复' => [
                '修复「导入备份」时选好 JSON 文件后「开始导入」按钮一直是灰的、点不动的问题',
                '根因是导出的文件里多包了一层接口外壳（file / data），导入时校验不到分组和站点数据',
            ],
            '优化' => [
                '导出的备份文件改为干净的备份本体，同时也兼容导入旧版本导出的文件',
                '解析失败时直接在页面上显示失败原因，不再只有按钮变灰让人摸不着头脑',
                '导入文件自动忽略 UTF-8 BOM（用记事本另存过的 JSON 也能正常识别）',
            ],
        ], JSON_UNESCAPED_UNICODE);

        self::pdo()->prepare('INSERT OR IGNORE INTO releases (version, released_at, notes) VALUES (?,?,?)')
            ->execute(['2.1.1', '2026-09-14', $notes]);
    }

    /* ==================================================================
     * 工具
     * ================================================================== */

    private static function ident($name)
    {
        return '"' . str_replace('"', '', $name) . '"';
    }

    public static function tableExists($table)
    {
        $stmt = self::pdo()->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** @return array 小写列名 => true */
    private static function columnNames($table)
    {
        if (!self::tableExists($table)) return [];
        $rows = self::pdo()->query('PRAGMA table_info(' . self::ident($table) . ')')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[strtolower($r['name'])] = true;
        }
        return $out;
    }

    private static function hasAnyUser()
    {
        if (!self::tableExists('users')) return false;
        return (int)self::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }

    public static function getMeta($k, $default = null)
    {
        try {
            $stmt = self::pdo()->prepare('SELECT v FROM meta WHERE k = ?');
            $stmt->execute([$k]);
            $v = $stmt->fetchColumn();
            return ($v === false) ? $default : $v;
        } catch (Exception $e) {
            return $default;
        }
    }

    public static function setMeta($k, $v)
    {
        try {
            self::pdo()->prepare('INSERT INTO meta (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')
                ->execute([$k, (string)$v]);
        } catch (Exception $e) {
            /* meta 表异常不影响主流程 */
        }
    }
}
