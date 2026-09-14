<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 站点模块（对标 Sun-Panel 的 itemIcon）
 *
 * 这是本项目优先级最高的模块：前台直接增删改 + 拖动排序（分组内 + 跨分组）。
 *
 * 权限约定：
 *  - 读：Auth::spaceUserId()，未登录也能看公开空间
 *  - 写：必须登录，只写自己空间
 *  - hit（点击计数）：不要求登录，任何人点都能计
 */

/**
 * 根据 icon 的值猜图标类型（对标 Sun-Panel itemType）
 *   1 = 纯文字（显示名称首字母）
 *   2 = 图片（上传的相对路径 / 完整 URL / data:）
 *   3 = 在线图标（Iconify 名，形如 mdi:home）
 */
function sites_detect_icon_type($icon)
{
    $icon = trim((string)$icon);
    if ($icon === '') return 1;
    if (preg_match('#^(https?:)?//#i', $icon)) return 2;
    if ($icon[0] === '/' || strpos($icon, 'data:') === 0) return 2;
    if (preg_match('#\.(png|jpe?g|gif|webp|ico|svg)$#i', $icon)) return 2;
    if (strpos($icon, ':') !== false) return 3;
    return 2;
}

/**
 * 把前端提交的原始数据整理成可入库的站点结构。
 * 只接受白名单字段，前端多传的东西一律丢掉。
 */
function sites_payload(array $src, array $defaults = [])
{
    $hasDefaultType = array_key_exists('icon_type', $defaults);

    $out = array_merge([
        'name'         => '',
        'url'          => '',
        'url_internal' => '',
        'description'  => '',
        'icon'         => '',
        'icon_bg'      => '',
        'open_method'  => 2,
        'category_id'  => '0',
    ], $defaults);

    foreach (['name', 'description', 'icon', 'icon_bg'] as $f) {
        if (array_key_exists($f, $src)) {
            $out[$f] = trim((string)$src[$f]);
        }
    }
    foreach (['url', 'url_internal'] as $f) {
        if (array_key_exists($f, $src) && (string)$src[$f] !== '') {
            $out[$f] = nav_normalize_url($src[$f]);
        }
    }
    if (array_key_exists('category_id', $src)) {
        $out['category_id'] = (string)$src['category_id'];
    }
    if (array_key_exists('open_method', $src)) {
        $om = (int)$src['open_method'];
        $out['open_method'] = in_array($om, [1, 2, 3], true) ? $om : 2;
    }

    /*
     * icon_type 的取值优先级：
     *  1. 前端显式传了 → 信前端（合法值范围内）
     *  2. 编辑场景且这次没动 icon → 保留库里原有的类型
     *  3. 其余情况 → 按 icon 的值猜
     */
    $srcType = array_key_exists('icon_type', $src) ? (int)$src['icon_type'] : 0;
    if (in_array($srcType, [1, 2, 3], true)) {
        $out['icon_type'] = $srcType;
    } elseif ($hasDefaultType && !array_key_exists('icon', $src)) {
        $out['icon_type'] = (int)$defaults['icon_type'];
    } else {
        $out['icon_type'] = sites_detect_icon_type($out['icon']);
    }

    // 名称兜底：只给了网址时，用主机名当名称
    if ($out['name'] === '' && $out['url'] !== '') {
        $out['name'] = nav_host_from_url($out['url']);
    }

    // 生成 ID
    if (array_key_exists('id', $src) && trim((string)$src['id']) !== '') {
        $out['id'] = trim((string)$src['id']);
    }

    return $out;
}

/** 校验一条站点数据，返回错误消息，没问题返回 '' */
function sites_validate(array $s)
{
    if ($s['name'] === '') return '名称不能为空';
    if (mb_strlen($s['name'], 'UTF-8') > 60) return '名称不能超过 60 个字';
    if ($s['url'] === '' && $s['url_internal'] === '') return '内网或外网地址至少填一个';
    if ($s['url'] !== '' && !nav_valid_host($s['url'])) return '外网地址格式不正确';
    if ($s['url_internal'] !== '' && !nav_valid_host($s['url_internal'])) return '内网地址格式不正确';
    if (mb_strlen($s['description'], 'UTF-8') > 200) return '描述不能超过 200 个字';
    return '';
}

return [

    /** 列出站点，可按分组过滤 */
    'listAll' => function () {
        $spaceId = Auth::spaceUserId();
        $cat = nav_param('category_id', null);

        Response::ok([
            'sites'    => Model::sites($spaceId, $cat === null ? null : (string)$cat),
            'editable' => Auth::check(),
        ]);
    },

    /** 新增单个站点 */
    'add' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        $s = sites_payload(nav_input());

        // 分组必须真实存在：没指定、或指定了不存在的，就归到第一个分组
        if ($s['category_id'] === '' || !Model::categoryExists($uid, $s['category_id'])) {
            $s['category_id'] = Model::ensureDefaultCategory($uid);
        }

        $err = sites_validate($s);
        if ($err !== '') Response::fail($err);

        if (!isset($s['id'])) $s['id'] = nav_next_id();
        if (Model::siteExists($uid, $s['id'])) Response::fail('该站点已存在');

        $s['sort'] = Model::nextSiteSort($uid, $s['category_id']);
        Model::insertSite($uid, $s);

        Response::ok([
            'site'   => Model::getSite($uid, $s['id']),
            'counts' => Model::categorySiteCounts($uid),
        ], '站点已添加');
    },

    /**
     * 批量添加（对标 Sun-Panel 的 addMultiple）
     *
     * items 每一项可以是：
     *   字符串  —— 「群晖|http://192.168.0.118」或直接「http://xxx」，直接粘一堆网址进来用
     *   对象    —— 完整字段
     * 也支持顶层传 names / urls 两个数组（一行一个的文本域批量导入）。
     */
    'addMultiple' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        $catId = nav_str('category_id', '');
        if ($catId === '' || !Model::categoryExists($uid, $catId)) {
            $catId = Model::ensureDefaultCategory($uid);
        }

        $items = nav_arr('items');

        // 兼容「名称一行一个 + 网址一行一个」的文本域导入
        if (!$items) {
            $names = nav_arr('names');
            $urls  = nav_arr('urls');
            $max = max(count($names), count($urls));
            for ($i = 0; $i < $max; $i++) {
                $items[] = [
                    'name' => isset($names[$i]) ? $names[$i] : '',
                    'url'  => isset($urls[$i]) ? $urls[$i] : '',
                ];
            }
        }

        if (!$items) Response::fail('没有可添加的内容');
        if (count($items) > 200) Response::fail('一次最多添加 200 个站点');

        $sort = Model::nextSiteSort($uid, $catId);
        $batch = [];
        $skipped = [];

        foreach ($items as $raw) {
            // 字符串形式：可能带「名称|网址」分隔
            if (!is_array($raw)) {
                $line = trim((string)$raw);
                if ($line === '') continue;

                if (strpos($line, '|') !== false) {
                    list($n, $u) = explode('|', $line, 2);
                    $raw = ['name' => trim($n), 'url' => trim($u)];
                } else {
                    $raw = ['url' => $line];
                }
            }

            $s = sites_payload($raw, ['category_id' => $catId]);
            $s['category_id'] = $catId;
            $err = sites_validate($s);
            if ($err !== '') { $skipped[] = ['name' => $s['name'], 'reason' => $err]; continue; }

            if (!isset($s['id']) || $s['id'] === '') $s['id'] = nav_next_id();
            if (Model::siteExists($uid, $s['id'])) $s['id'] = nav_next_id();

            $s['sort'] = $sort++;
            $batch[] = $s;
        }

        if (!$batch) Response::fail($skipped ? ('全部被跳过：' . $skipped[0]['reason']) : '没有可添加的内容');

        Model::insertSitesBatch($uid, $batch);

        Response::ok([
            'added'   => count($batch),
            'skipped' => $skipped,
            'sites'   => Model::sites($uid, $catId),
            'counts'  => Model::categorySiteCounts($uid),
        ], '已添加 ' . count($batch) . ' 个站点');
    },

    /** 编辑站点 */
    'edit' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        $id = nav_str('id');
        if ($id === '') Response::fail('缺少站点 ID');
        if (!Model::siteExists($uid, $id)) Response::fail('站点不存在');

        $cur = Model::getSite($uid, $id);
        $s = sites_payload(nav_input(), [
            'name'         => $cur['name'],
            'url'          => $cur['url'],
            'url_internal' => $cur['url_internal'],
            'description'  => $cur['description'],
            'icon'         => $cur['icon'],
            'icon_bg'      => $cur['icon_bg'],
            'icon_type'    => $cur['icon_type'],
            'open_method'  => $cur['open_method'],
            'category_id'  => $cur['category_id'],
        ]);

        // 分组必须存在；不合法就保持原分组（原分组也没了才补默认分组）
        if ($s['category_id'] === '' || !Model::categoryExists($uid, $s['category_id'])) {
            $s['category_id'] = Model::categoryExists($uid, $cur['category_id'])
                ? $cur['category_id']
                : Model::ensureDefaultCategory($uid);
        }

        $err = sites_validate($s);
        if ($err !== '') Response::fail($err);

        Model::updateSite($uid, $id, $s);

        // 换了分组 → 把旧分组的 sort 压实一下
        if ($s['category_id'] !== $cur['category_id']) {
            Model::normalizeSiteSort($uid, $cur['category_id']);
        }

        Response::ok([
            'site'   => Model::getSite($uid, $id),
            'counts' => Model::categorySiteCounts($uid),
        ], '站点已更新');
    },

    /** 删除单个站点 */
    'remove' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        $id = nav_str('id');
        if ($id === '') Response::fail('缺少站点 ID');

        $cats = Model::categoriesOfSites($uid, [$id]);
        $n = Model::deleteSites($uid, [$id]);
        foreach ($cats as $c) Model::normalizeSiteSort($uid, $c);

        Response::ok(['deleted' => $n, 'counts' => Model::categorySiteCounts($uid)], '站点已删除');
    },

    /** 批量删除站点（对标 Sun-Panel 的 deletes 收数组） */
    'removeMany' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        $ids = nav_arr('ids');
        if (!$ids) {
            $ids = nav_arr('items');
        }
        if (!$ids) Response::fail('请选择要删除的站点');

        $cats = Model::categoriesOfSites($uid, $ids);
        $n = Model::deleteSites($uid, $ids);
        foreach ($cats as $c) Model::normalizeSiteSort($uid, $c);

        Response::ok(['deleted' => $n, 'counts' => Model::categorySiteCounts($uid)], '已删除 ' . $n . ' 个站点');
    },

    /**
     * 保存排序 —— 拖拽松手后调用
     *
     * 两种提交格式，前端按场景选：
     *  A. groups: { "分组id": ["站点id", ...], ... }   推荐，sort 在各自分组内从 0 开始
     *  B. items:  [{id, category_id}, ...]             一个扁平数组，按最终显示顺序排好
     *     扁平格式下 sort 被写成全局递增序号，但同组内的相对顺序仍然正确，跨分组也安全。
     */
    'saveSort' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        // A. 分组化提交
        $groups = nav_param('groups', null);
        if (is_string($groups)) $groups = nav_json($groups, null);
        if (is_array($groups) && $groups) {
            Model::applySiteSortGrouped($uid, $groups);
            Response::ok([
                'sites'  => Model::sites($uid),
                'counts' => Model::categorySiteCounts($uid),
            ], '排序已保存');
        }

        // B. 扁平提交
        $items = nav_arr('items');
        if (!$items) Response::fail('缺少排序数据');

        Model::applySiteSort($uid, $items);
        Response::ok([
            'sites'  => Model::sites($uid),
            'counts' => Model::categorySiteCounts($uid),
        ], '排序已保存');
    },

    /** 点击计数（前台点开站点时调用，不需要登录） */
    'hit' => function () {
        $spaceId = Auth::spaceUserId();
        $id = nav_str('id');
        if ($id === '') Response::fail('缺少站点 ID');

        $hits = Model::bumpSiteHit($spaceId, $id);
        if ($hits === null) Response::fail('站点不存在');

        Response::ok(['id' => $id, 'hits' => $hits]);
    },
];
