<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 数据访问层
 *
 * 所有读写的 SQL 都集中在这里，模块文件只调用方法、不直接拼 SQL。
 * 这样表结构变化时只需要改这一个文件。
 */

final class Model
{
    /**
     * 系统自带的那个分组的名字。
     *
     * 注意：它就是一个**普通分组** —— 可以随便改名、也删得掉。
     * 这里没有「未分类」这种特殊概念：站点永远属于某个真实分组，
     * 用户一个分组都没有时才会自动补上它。
     */
    const DEFAULT_CATEGORY_NAME = '我的收藏';

    /** settings 表里应当是整数的字段 */
    const SETTINGS_INT_FIELDS = [
        'bg_blur', 'bg_mask', 'clock_visible', 'clock_second', 'logo_visible',
        'footer_html', 'search_box_show', 'net_toggle_show',
        'icon_hide_title', 'icon_hide_desc',
        'max_width', 'margin_top', 'margin_bottom', 'margin_x',
        'monitor_show', 'monitor_title',
    ];

    /* ==================================================================
     * settings
     * ================================================================== */

    public static function ensureSettings($userId)
    {
        Db::pdo()->prepare('INSERT OR IGNORE INTO settings (user_id) VALUES (?)')->execute([(int)$userId]);
    }

    public static function settings($userId)
    {
        $userId = (int)$userId;
        self::ensureSettings($userId);

        $stmt = Db::pdo()->prepare('SELECT * FROM settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return self::defaultSettings();
        }

        foreach (self::SETTINGS_INT_FIELDS as $f) {
            if (isset($row[$f])) $row[$f] = (int)$row[$f];
        }
        $row['user_id'] = (int)$row['user_id'];
        return $row;
    }

    public static function defaultSettings()
    {
        return [
            'user_id'         => 0,
            'theme'           => 'theme-dark',
            'theme_mode'      => 'auto',
            'wallpaper'       => '',
            'bg_blur'         => 0,
            'bg_mask'         => 0,
            'clock_visible'   => 1,
            'clock_second'    => 0,
            'logo_visible'    => 1,
            'logo_text'       => '导航站',
            'logo_image'      => '',
            'footer'          => '',
            'footer_html'     => 0,
            'search_engine'   => 'baidu',
            'search_box_show' => 1,
            'net_toggle_show' => 1,
            'open_mode'       => 'new',
            'icon_style'      => 'icon',
            'icon_text_color' => '#ffffff',
            'icon_hide_title' => 0,
            'icon_hide_desc'  => 0,
            'max_width'       => 1200,
            'margin_top'      => 4,
            'margin_bottom'   => 7,
            'margin_x'        => 15,
            'monitor_show'    => 0,
            'monitor_title'   => 1,
            'sort_mode'       => 'manual',
        ];
    }

    /** 允许通过 API 写入的 settings 字段白名单 */
    const SETTINGS_WRITABLE = [
        'theme', 'theme_mode', 'wallpaper', 'bg_blur', 'bg_mask',
        'clock_visible', 'clock_second', 'logo_visible', 'logo_text', 'logo_image',
        'footer', 'footer_html', 'search_engine', 'search_box_show', 'net_toggle_show',
        'open_mode', 'icon_style', 'icon_text_color', 'icon_hide_title', 'icon_hide_desc',
        'max_width', 'margin_top', 'margin_bottom', 'margin_x',
        'monitor_show', 'monitor_title', 'sort_mode',
    ];

    public static function saveSettings($userId, array $data)
    {
        self::ensureSettings($userId);

        $sets = [];
        $params = [];
        foreach (self::SETTINGS_WRITABLE as $f) {
            if (!array_key_exists($f, $data)) continue;
            $v = $data[$f];
            if (in_array($f, self::SETTINGS_INT_FIELDS, true)) {
                $v = (int)(bool)(is_string($v) ? ($v !== '' && $v !== '0' && strtolower($v) !== 'false') : $v);
                if (in_array($f, ['bg_blur', 'bg_mask', 'max_width', 'margin_top', 'margin_bottom', 'margin_x'], true)) {
                    $v = (int)$data[$f];   // 这几个是真正的数值，不是开关
                }
            } else {
                $v = (string)$v;
            }
            $sets[] = $f . ' = ?';
            $params[] = $v;
        }

        if ($sets) {
            $sets[] = 'updated_at = ?';
            $params[] = time();
            $params[] = (int)$userId;
            Db::pdo()->prepare('UPDATE settings SET ' . implode(', ', $sets) . ' WHERE user_id = ?')
                ->execute($params);
        }

        return self::settings($userId);
    }

    /* ==================================================================
     * categories（对标 Sun-Panel item_icon_group）
     * ================================================================== */

    public static function categories($userId)
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, name, icon, icon_bg, description, sort
             FROM categories WHERE user_id = ? ORDER BY sort ASC, id ASC'
        );
        $stmt->execute([(int)$userId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['id']   = (string)$r['id'];
            $r['sort'] = (int)$r['sort'];
        }
        unset($r);
        return $rows;
    }

    /** 用户的第一个分组 id（按 sort 排），一个都没有时返回 null */
    public static function firstCategoryId($userId)
    {
        $stmt = Db::pdo()->prepare('SELECT id FROM categories WHERE user_id = ? ORDER BY sort ASC, id ASC LIMIT 1');
        $stmt->execute([(int)$userId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (string)$id;
    }

    /**
     * 保证用户至少有一个分组，返回可以用的分组 id。
     *
     * 只在「一个分组都没有」时才会创建 —— 它就是个普通分组，
     * 用户删得掉、改得了名，删光了下次请求又会补一个，站点永远有归属。
     */
    public static function ensureDefaultCategory($userId)
    {
        $userId = (int)$userId;
        $id = self::firstCategoryId($userId);
        if ($id !== null) return $id;

        $newId = nav_next_id();
        Db::pdo()->prepare(
            'INSERT OR IGNORE INTO categories (id, user_id, name, sort, created_at) VALUES (?,?,?,?,?)'
        )->execute([(string)$newId, $userId, self::DEFAULT_CATEGORY_NAME, 0, time()]);
        return (string)$newId;
    }

    /**
     * 自愈：把「指向不存在分组」的站点收回到第一个分组。
     *
     * 覆盖三种历史脏数据：category_id 为 NULL、为已删除分组的 id、
     * 以及早期版本那个特殊的 '0'。
     */
    public static function healOrphanSites($userId)
    {
        $userId = (int)$userId;
        $first  = self::ensureDefaultCategory($userId);

        Db::pdo()->prepare(
            'UPDATE sites SET category_id = ?, updated_at = ?
             WHERE user_id = ?
               AND (category_id IS NULL
                    OR category_id NOT IN (SELECT id FROM categories WHERE user_id = ?))'
        )->execute([$first, time(), $userId, $userId]);

        return $first;
    }

    public static function categoryExists($userId, $id)
    {
        $stmt = Db::pdo()->prepare('SELECT COUNT(*) FROM categories WHERE user_id = ? AND id = ?');
        $stmt->execute([(int)$userId, (string)$id]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function insertCategory($userId, $id, $name, $sort, $icon = '', $iconBg = '', $description = '')
    {
        $now = time();
        Db::pdo()->prepare(
            'INSERT OR IGNORE INTO categories (id, user_id, name, icon, icon_bg, description, sort, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([(string)$id, (int)$userId, $name, $icon, $iconBg, $description, (int)$sort, $now, $now]);
    }

    public static function nextCategorySort($userId)
    {
        $stmt = Db::pdo()->prepare('SELECT COALESCE(MAX(sort), -1) FROM categories WHERE user_id = ?');
        $stmt->execute([(int)$userId]);
        return (int)$stmt->fetchColumn() + 1;
    }

    /* ==================================================================
     * sites（对标 Sun-Panel item_icon）
     * ================================================================== */

    public static function sites($userId, $categoryId = null)
    {
        $sql = 'SELECT id, name, url, url_internal, description, icon, icon_type, icon_bg,
                       open_method, category_id, sort, hits
                FROM sites WHERE user_id = ?';
        $params = [(int)$userId];

        if ($categoryId !== null && $categoryId !== '') {
            $sql .= ' AND category_id = ?';
            $params[] = (string)$categoryId;
        }
        $sql .= ' ORDER BY sort ASC, id ASC';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['id']          = (string)$r['id'];
            $r['category_id'] = (string)$r['category_id'];
            $r['sort']        = (int)$r['sort'];
            $r['icon_type']   = (int)$r['icon_type'];
            $r['open_method'] = (int)$r['open_method'];
            $r['hits']        = (int)$r['hits'];
        }
        unset($r);
        return $rows;
    }

    public static function siteExists($userId, $id)
    {
        $stmt = Db::pdo()->prepare('SELECT COUNT(*) FROM sites WHERE user_id = ? AND id = ?');
        $stmt->execute([(int)$userId, (string)$id]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function insertSite($userId, array $s)
    {
        $now = time();
        Db::pdo()->prepare(
            'INSERT INTO sites (id, user_id, name, url, url_internal, description, icon, icon_type, icon_bg,
                                open_method, category_id, sort, hits, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?,?)'
        )->execute([
            (string)$s['id'],
            (int)$userId,
            (string)$s['name'],
            (string)(isset($s['url']) ? $s['url'] : ''),
            (string)(isset($s['url_internal']) ? $s['url_internal'] : ''),
            (string)(isset($s['description']) ? $s['description'] : ''),
            (string)(isset($s['icon']) ? $s['icon'] : ''),
            (int)(isset($s['icon_type']) ? $s['icon_type'] : 2),
            (string)(isset($s['icon_bg']) ? $s['icon_bg'] : ''),
            (int)(isset($s['open_method']) ? $s['open_method'] : 2),
            (string)(isset($s['category_id']) ? $s['category_id'] : '0'),
            (int)(isset($s['sort']) ? $s['sort'] : 0),
            $now,
            $now,
        ]);
    }

    /** 允许通过 API 写入的 sites 字段白名单 */
    const SITES_WRITABLE = [
        'name', 'url', 'url_internal', 'description', 'icon',
        'icon_type', 'icon_bg', 'open_method', 'category_id',
    ];

    public static function updateSite($userId, $id, array $data)
    {
        $sets = [];
        $params = [];
        foreach (self::SITES_WRITABLE as $f) {
            if (!array_key_exists($f, $data)) continue;
            if (in_array($f, ['icon_type', 'open_method'], true)) {
                $params[] = (int)$data[$f];
            } else {
                $params[] = trim((string)$data[$f]);
            }
            $sets[] = $f . ' = ?';
        }
        if (!$sets) return false;

        $sets[] = 'updated_at = ?';
        $params[] = time();
        $params[] = (string)$id;
        $params[] = (int)$userId;

        Db::pdo()->prepare('UPDATE sites SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?')
            ->execute($params);
        return true;
    }

    public static function nextSiteSort($userId, $categoryId)
    {
        $stmt = Db::pdo()->prepare('SELECT COALESCE(MAX(sort), -1) FROM sites WHERE user_id = ? AND category_id = ?');
        $stmt->execute([(int)$userId, (string)$categoryId]);
        return (int)$stmt->fetchColumn() + 1;
    }

    /**
     * 按提交的顺序重排站点。同时支持：
     *  - 纯 ID 数组           → 只改 sort（分组内拖动）
     *  - ['id' => x, 'category_id' => y] 对象数组 → 同时改 sort 和 category_id（跨分组拖动）
     * 数据准备在前端做：把受影响分组里所有站点的最终顺序一起提交。
     */
    public static function applySiteSort($userId, array $items)
    {
        $userId = (int)$userId;
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $updBoth = $pdo->prepare('UPDATE sites SET sort = ?, category_id = ?, updated_at = ? WHERE id = ? AND user_id = ?');
            $updSort = $pdo->prepare('UPDATE sites SET sort = ?, updated_at = ? WHERE id = ? AND user_id = ?');

            $now = time();
            $i = 0;
            foreach ($items as $it) {
                $sid = '';
                $cat = null;

                if (is_array($it)) {
                    $sid = isset($it['id']) ? (string)$it['id'] : '';
                    if (array_key_exists('category_id', $it)) {
                        $cat = (string)$it['category_id'];
                    }
                } else {
                    $sid = (string)$it;
                }

                if ($sid === '') continue;

                if ($cat === null) {
                    $updSort->execute([$i, $now, $sid, $userId]);
                } else {
                    $updBoth->execute([$i, $cat, $now, $sid, $userId]);
                }
                $i++;
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        return true;
    }

    /** 把 sort 压实成 0,1,2... 消除空洞 */
    public static function normalizeCategorySort($userId)
    {
        $userId = (int)$userId;
        $rows = Db::pdo()->prepare('SELECT id FROM categories WHERE user_id = ? ORDER BY sort ASC, id ASC');
        $rows->execute([$userId]);
        $ids = $rows->fetchAll(PDO::FETCH_COLUMN);

        $upd = Db::pdo()->prepare('UPDATE categories SET sort = ? WHERE id = ? AND user_id = ?');
        foreach ($ids as $i => $id) {
            $upd->execute([$i, $id, $userId]);
        }
    }

    public static function deleteSites($userId, array $ids)
    {
        if (!$ids) return 0;
        $userId = (int)$userId;
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        $n = 0;
        try {
            $del = $pdo->prepare('DELETE FROM sites WHERE id = ? AND user_id = ?');
            foreach ($ids as $id) {
                if (is_array($id)) continue;
                $del->execute([(string)$id, $userId]);
                $n += $del->rowCount();
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $n;
    }

    /**
     * 删除分组（真正的删除 —— 没有任何分组是不可删的）。
     *
     * 组内站点不会丢，统一搬到「剩下的第一个分组」；
     * 若用户把分组删光了，会先补一个默认分组再接住这些站点。
     *
     * @param bool $keepFallback 删光后是否补一个默认分组。备份导入走 replace 时传 false，
     *                           免得每次导入都凭空多出一个空分组。
     */
    public static function deleteCategories($userId, array $ids, $keepFallback = true)
    {
        $userId = (int)$userId;
        $pdo    = Db::pdo();

        // 只认自己名下的分组，同时按 sort 顺序拿到「剩下的第一个」
        $wanted = [];
        $order  = [];
        foreach (self::categories($userId) as $c) {
            $order[] = $c['id'];
        }
        foreach ($ids as $id) {
            if (is_array($id)) continue;
            $id = (string)$id;
            if ($id !== '') $wanted[$id] = true;
        }

        $del  = array_values(array_filter($order, function ($id) use ($wanted) { return isset($wanted[$id]); }));
        if (!$del) return 0;

        $rest = array_values(array_filter($order, function ($id) use ($wanted) { return !isset($wanted[$id]); }));

        // 删光的话先准备好兜底分组（站点必须有归属）
        $target = $rest ? $rest[0] : ($keepFallback ? nav_next_id() : '');

        $pdo->beginTransaction();
        $n = 0;
        try {
            if (!$rest && $keepFallback) {
                $pdo->prepare('INSERT OR IGNORE INTO categories (id, user_id, name, sort, created_at) VALUES (?,?,?,?,?)')
                    ->execute([(string)$target, $userId, self::DEFAULT_CATEGORY_NAME, 0, time()]);
            }

            $delStmt = $pdo->prepare('DELETE FROM categories WHERE id = ? AND user_id = ?');
            $mvStmt  = $pdo->prepare('UPDATE sites SET category_id = ?, updated_at = ? WHERE category_id = ? AND user_id = ?');
            foreach ($del as $id) {
                $delStmt->execute([$id, $userId]);
                $n += $delStmt->rowCount();
                if ($target !== '') {
                    $mvStmt->execute([(string)$target, time(), $id, $userId]);
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::normalizeCategorySort($userId);
        return $n;
    }

    /** 被删分组里的站点现在归到哪了（给接口返回提示用） */
    public static function categoryName($userId, $id)
    {
        $c = self::getCategory($userId, $id);
        return $c ? $c['name'] : '';
    }

    /* ==================================================================
     * categories 补充
     * ================================================================== */

    public static function getCategory($userId, $id)
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, name, icon, icon_bg, description, sort FROM categories WHERE user_id = ? AND id = ?'
        );
        $stmt->execute([(int)$userId, (string)$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['id']   = (string)$row['id'];
        $row['sort'] = (int)$row['sort'];
        return $row;
    }

    /** 可写字段：name / icon / icon_bg / description（sort 走 saveSort） */
    public static function updateCategory($userId, $id, array $data)
    {
        $allowed = ['name', 'icon', 'icon_bg', 'description'];
        $sets = [];
        $params = [];
        foreach ($allowed as $f) {
            if (!array_key_exists($f, $data)) continue;
            $sets[] = $f . ' = ?';
            $params[] = trim((string)$data[$f]);
        }
        if (!$sets) return false;

        $sets[] = 'updated_at = ?';
        $params[] = time();
        $params[] = (string)$id;
        $params[] = (int)$userId;

        Db::pdo()->prepare('UPDATE categories SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?')
            ->execute($params);
        return true;
    }

    /** 每个分组下的站点数，前端用来显示角标、判断空分组 */
    public static function categorySiteCounts($userId)
    {
        $stmt = Db::pdo()->prepare('SELECT category_id, COUNT(*) AS n FROM sites WHERE user_id = ? GROUP BY category_id');
        $stmt->execute([(int)$userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string)$r['category_id']] = (int)$r['n'];
        }
        return $out;
    }

    /** 重排分组顺序（前端提交完整顺序） */
    public static function applyCategorySort($userId, array $ids)
    {
        $userId = (int)$userId;
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare('UPDATE categories SET sort = ?, updated_at = ? WHERE id = ? AND user_id = ?');
            $now = time();
            $i = 0;
            foreach ($ids as $id) {
                if (is_array($id)) $id = isset($id['id']) ? $id['id'] : '';
                $id = (string)$id;
                if ($id === '') continue;
                $upd->execute([$i, $now, $id, $userId]);
                $i++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        return true;
    }

    /* ==================================================================
     * sites 补充
     * ================================================================== */

    public static function getSite($userId, $id)
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, name, url, url_internal, description, icon, icon_type, icon_bg,
                    open_method, category_id, sort, hits
             FROM sites WHERE user_id = ? AND id = ?'
        );
        $stmt->execute([(int)$userId, (string)$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['id']          = (string)$row['id'];
        $row['category_id'] = (string)$row['category_id'];
        $row['sort']        = (int)$row['sort'];
        $row['icon_type']   = (int)$row['icon_type'];
        $row['open_method'] = (int)$row['open_method'];
        $row['hits']        = (int)$row['hits'];
        return $row;
    }

    /** 批量插入（批量添加站点用，单事务） */
    public static function insertSitesBatch($userId, array $list)
    {
        if (!$list) return 0;
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($list as $s) {
                self::insertSite($userId, $s);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        return count($list);
    }

    /** 点击计数 +1，返回新的点击数 */
    public static function bumpSiteHit($userId, $id)
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('UPDATE sites SET hits = hits + 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([(string)$id, (int)$userId]);
        if ($stmt->rowCount() === 0) return null;

        $q = $pdo->prepare('SELECT hits FROM sites WHERE id = ? AND user_id = ?');
        $q->execute([(string)$id, (int)$userId]);
        return (int)$q->fetchColumn();
    }

    /** 这些站点当前属于哪些分组（删站点后要重排这些分组） */
    public static function categoriesOfSites($userId, array $ids)
    {
        $ids = array_values(array_filter(array_map(function ($v) {
            return is_array($v) ? '' : (string)$v;
        }, $ids), function ($v) { return $v !== ''; }));
        if (!$ids) return [];

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([(int)$userId], $ids);
        $stmt = Db::pdo()->prepare("SELECT DISTINCT category_id FROM sites WHERE user_id = ? AND id IN ($ph)");
        $stmt->execute($params);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** 把某分组内的 sort 压实成 0,1,2...（删站后调用） */
    public static function normalizeSiteSort($userId, $categoryId)
    {
        $userId = (int)$userId;
        $stmt = Db::pdo()->prepare('SELECT id FROM sites WHERE user_id = ? AND category_id = ? ORDER BY sort ASC, id ASC');
        $stmt->execute([$userId, (string)$categoryId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $upd = Db::pdo()->prepare('UPDATE sites SET sort = ? WHERE id = ? AND user_id = ?');
        foreach ($ids as $i => $id) {
            $upd->execute([$i, $id, $userId]);
        }
    }

    /**
     * 分组化的排序提交：{ 分组id: [站点id, ...], ... }
     * 这是跨分组拖动的推荐提交方式 —— sort 在各自分组内从 0 开始，语义干净。
     */
    public static function applySiteSortGrouped($userId, array $groups)
    {
        $userId = (int)$userId;
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare('UPDATE sites SET sort = ?, category_id = ?, updated_at = ? WHERE id = ? AND user_id = ?');
            $now = time();
            foreach ($groups as $catId => $ids) {
                if (!is_array($ids)) continue;
                $i = 0;
                foreach ($ids as $id) {
                    if (is_array($id)) $id = isset($id['id']) ? $id['id'] : '';
                    $id = (string)$id;
                    if ($id === '') continue;
                    $upd->execute([$i, (string)$catId, $now, $id, $userId]);
                    $i++;
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        return true;
    }

    /* ==================================================================
     * files（上传文件登记，对标 Sun-Panel 的 file 表）
     * ================================================================== */

    public static function insertFile($userId, $src, $fileName, $ext, $mime, $size, $method = 1)
    {
        Db::pdo()->prepare(
            'INSERT INTO files (user_id, src, file_name, ext, mime, size, method, created_at) VALUES (?,?,?,?,?,?,?,?)'
        )->execute([(int)$userId, (string)$src, (string)$fileName, (string)$ext, (string)$mime, (int)$size, (int)$method, time()]);

        return (int)Db::pdo()->lastInsertId();
    }

    public static function listFiles($userId, $limit = 200, $method = null)
    {
        $sql = 'SELECT id, src, file_name, ext, mime, size, method, created_at FROM files WHERE user_id = ?';
        $params = [(int)$userId];
        if ($method !== null) {
            $sql .= ' AND method = ?';
            $params[] = (int)$method;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(1000, (int)$limit));

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['id']         = (int)$r['id'];
            $r['size']       = (int)$r['size'];
            $r['method']     = (int)$r['method'];
            $r['created_at'] = (int)$r['created_at'];
        }
        unset($r);
        return $rows;
    }

    public static function getFile($userId, $id)
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM files WHERE id = ? AND user_id = ?');
        $stmt->execute([(int)$id, (int)$userId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['id']     = (int)$row['id'];
        $row['size']   = (int)$row['size'];
        $row['method'] = (int)$row['method'];
        return $row;
    }

    /** 删除文件登记，返回被删掉的那一行（调用方据此去删磁盘文件） */
    public static function deleteFile($userId, $id)
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM files WHERE id = ? AND user_id = ?');
        $stmt->execute([(int)$id, (int)$userId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        Db::pdo()->prepare('DELETE FROM files WHERE id = ? AND user_id = ?')->execute([(int)$id, (int)$userId]);
        return $row;
    }

    /** 某个文件的 URL 是否已被站点引用（删文件前提示用） */
    public static function fileInUse($userId, $src)
    {
        $stmt = Db::pdo()->prepare('SELECT COUNT(*) FROM sites WHERE user_id = ? AND (icon = ? OR icon_bg = ?)');
        $stmt->execute([(int)$userId, (string)$src, (string)$src]);
        return (int)$stmt->fetchColumn();
    }

    /* ==================================================================
     * releases（版本更新记录）
     * ================================================================== */

    /** 全部版本记录，新的排前面。notes 是 { '新增': [...], '优化': [...], '修复': [...] } */
    public static function releases()
    {
        $rows = Db::pdo()->query('SELECT version, released_at, notes FROM releases')->fetchAll();
        if (!$rows) return [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'version'     => (string)$r['version'],
                'released_at' => (string)$r['released_at'],
                'notes'       => nav_json($r['notes'], []),
            ];
        }

        // 版本号倒序（2.0.10 要排在 2.0.9 前面，所以用 version_compare 而不是字符串比较）
        usort($out, function ($a, $b) {
            $c = version_compare($a['version'], $b['version']);
            if ($c !== 0) return -$c;
            return strcmp($b['released_at'], $a['released_at']);
        });

        return $out;
    }

    public static function saveRelease($version, $releasedAt, array $notes)
    {
        Db::pdo()->prepare('INSERT OR REPLACE INTO releases (version, released_at, notes) VALUES (?,?,?)')
            ->execute([
                (string)$version,
                (string)$releasedAt,
                json_encode($notes, JSON_UNESCAPED_UNICODE),
            ]);
        return true;
    }

    /* ==================================================================
     * users
     * ================================================================== */

    public static function users()
    {
        $rows = Db::pdo()->query(
            'SELECT id, username, role, nickname, email, avatar, status, last_login_at, created_at
             FROM users ORDER BY id ASC'
        )->fetchAll();

        foreach ($rows as &$r) {
            $r['id']            = (int)$r['id'];
            $r['status']        = (int)$r['status'];
            $r['last_login_at'] = $r['last_login_at'] === null ? null : (int)$r['last_login_at'];
        }
        unset($r);
        return $rows;
    }

    public static function userCount()
    {
        return (int)Db::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public static function adminCount()
    {
        return (int)Db::pdo()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 1")->fetchColumn();
    }
}
