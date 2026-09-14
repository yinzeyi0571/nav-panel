<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 备份：导入 / 导出
 *
 * 导出的是可读的 JSON，包含分组、站点、外观设置。用户和密码不在其中
 * （有意为之 —— 导出文件很容易被随手转发，不该带凭据）。
 *
 * 导入支持两种模式：
 *   replace —— 清空当前空间的导航数据再导入（默认）
 *   merge   —— 追加，遇到同名分组则复用，站点 ID 冲突则重新分配
 */

/** 导出当前空间的导航数据 */
function backup_collect($userId)
{
    return [
        'format'      => 'nav-backup',
        'format_ver'  => 1,
        'app_version' => nav_version('version', '2.1.1'),
        'exported_at' => date('c'),
        'categories'  => Model::categories($userId),
        'sites'       => Model::sites($userId),
        'settings'    => Model::settings($userId),
    ];
}

return [

    /** 导出 */
    'export' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        Response::ok([
            'file' => 'nav-backup-' . date('Ymd-His') . '.json',
            'data' => backup_collect($uid),
        ], '导出成功');
    },

    /** 导入 */
    'import' => function () {
        Auth::requireAuth();
        $uid  = Auth::id();
        $mode = nav_str('mode', 'replace');
        if (!in_array($mode, ['replace', 'merge'], true)) $mode = 'replace';

        $raw = nav_param('data', null);
        if (is_string($raw)) $raw = nav_json($raw, null);
        if (!is_array($raw)) Response::fail('备份内容无法解析');

        // 兼容直接贴导出文件全文（顶层就是备份对象）和 { data: {...} } 两种贴法
        if (isset($raw['data']) && is_array($raw['data']) && !isset($raw['sites'])) {
            $raw = $raw['data'];
        }

        $cats  = isset($raw['categories']) && is_array($raw['categories']) ? $raw['categories'] : [];
        $sites = isset($raw['sites']) && is_array($raw['sites']) ? $raw['sites'] : [];
        if (!$cats && !$sites) Response::fail('备份里没有分组或站点数据');
        if (count($sites) > 5000) Response::fail('站点数量超出上限');

        $settings = isset($raw['settings']) && is_array($raw['settings']) ? $raw['settings'] : [];

        // ---- replace：先清空 ----
        if ($mode === 'replace') {
            $oldSites = Model::sites($uid);
            Model::deleteSites($uid, array_map(function ($s) { return $s['id']; }, $oldSites));
            $oldCats = Model::categories($uid);
            // 这里不再自动补默认分组，否则每导入一次就多出一个空分组
            Model::deleteCategories($uid, array_map(function ($c) { return $c['id']; }, $oldCats), false);
        }

        // ---- 分组 ----
        $existing = [];
        foreach (Model::categories($uid) as $c) {
            $existing[(string)$c['id']] = true;
        }

        $catCount = 0;
        $catMap   = [];       // 备份里的分组 ID => 实际落库的分组 ID
        $sort = Model::nextCategorySort($uid);

        foreach ($cats as $c) {
            if (!is_array($c)) continue;
            $name = isset($c['name']) ? trim((string)$c['name']) : '';
            if ($name === '') continue;

            $origId = isset($c['id']) ? (string)$c['id'] : '';

            $newId = $origId;

            // ID 不可用（为空 / 与现有分组冲突）时才重新分配。
            if ($newId === '' || isset($existing[$newId])) {
                $reuse = '';
                if ($mode === 'merge') {
                    foreach (Model::categories($uid) as $ec) {
                        if ($ec['name'] === $name) {
                            $reuse = (string)$ec['id'];
                            break;
                        }
                    }
                }

                if ($reuse !== '') {
                    $newId = $reuse;                  // 复用同名分组，不新建
                } else {
                    $newId = nav_next_id();
                    $guard = 0;
                    while (isset($existing[$newId])) {
                        $newId = nav_next_id();
                        if (++$guard > 50) break;
                    }
                }
            }

            if (!isset($existing[$newId])) {
                Model::insertCategory(
                    $uid, $newId, $name, $sort++,
                    isset($c['icon']) ? (string)$c['icon'] : '',
                    isset($c['icon_bg']) ? (string)$c['icon_bg'] : '',
                    isset($c['description']) ? (string)$c['description'] : ''
                );
                $existing[$newId] = true;
                $catCount++;
            }
            if ($origId !== '') $catMap[$origId] = $newId;
        }
        $catMap['0'] = '0';

        // ---- 站点 ----
        $exists = [];
        foreach (Model::sites($uid) as $s) {
            $exists[(string)$s['id']] = true;
        }

        $siteCount = 0;
        $sortByCat = [];
        $skipped   = 0;

        foreach ($sites as $s) {
            if (!is_array($s)) { $skipped++; continue; }

            $name = isset($s['name']) ? trim((string)$s['name']) : '';
            if ($name === '') { $skipped++; continue; }

            $catId = isset($s['category_id']) ? (string)$s['category_id'] : '';
            if (isset($catMap[$catId])) $catId = $catMap[$catId];
            // 分组不存在（备份里缺了 / 名字为空被跳过）→ 归到第一个分组
            if ($catId === '' || !Model::categoryExists($uid, $catId)) {
                $catId = Model::ensureDefaultCategory($uid);
            }

            $id = isset($s['id']) ? (string)$s['id'] : '';
            if ($id === '' || isset($exists[$id])) $id = nav_next_id();
            $exists[$id] = true;

            if (!isset($sortByCat[$catId])) {
                $sortByCat[$catId] = Model::nextSiteSort($uid, $catId);
            }

            Model::insertSite($uid, [
                'id'           => $id,
                'name'         => $name,
                'url'          => isset($s['url']) ? (string)$s['url'] : '',
                'url_internal' => isset($s['url_internal']) ? (string)$s['url_internal'] : '',
                'description'  => isset($s['description']) ? (string)$s['description'] : '',
                'icon'         => isset($s['icon']) ? (string)$s['icon'] : '',
                'icon_type'    => isset($s['icon_type']) ? (int)$s['icon_type'] : 2,
                'icon_bg'      => isset($s['icon_bg']) ? (string)$s['icon_bg'] : '',
                'open_method'  => isset($s['open_method']) ? (int)$s['open_method'] : 2,
                'category_id'  => $catId,
                'sort'         => $sortByCat[$catId]++,
            ]);
            $siteCount++;
        }

        // ---- 外观设置 ----
        $applySettings = nav_bool('apply_settings') === 1;
        if ($applySettings && $settings) {
            unset($settings['user_id']);
            $err = Settings::validate($settings);
            if ($err === '') Model::saveSettings($uid, $settings);
        }

        Response::ok([
            'mode'      => $mode,
            // categories / sites 是「本次新建了多少」，total_* 是导入后的总数
            'categories' => $catCount,
            'sites'     => $siteCount,
            'total_categories' => count(Model::categories($uid)),
            'total_sites'      => count(Model::sites($uid)),
            'skipped'   => $skipped,
            'settings_applied' => $applySettings,
            'counts'    => Model::categorySiteCounts($uid),
        ], '导入完成：新建 ' . $catCount . ' 个分组、' . $siteCount . ' 个站点');
    },
];
