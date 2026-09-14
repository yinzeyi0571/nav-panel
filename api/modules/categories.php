<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 分类模块（对标 Sun-Panel 的 itemIconGroup）
 *
 * 权限约定：
 *  - 读：走 Auth::spaceUserId()，未登录也能读公开空间
 *  - 写：必须登录，且只写自己空间（Auth::id()）
 */

return [

    /** 列出分组 + 每组站点数 */
    'listAll' => function () {
        $spaceId = Auth::spaceUserId();
        Response::ok([
            'categories' => Model::categories($spaceId),
            'counts'     => Model::categorySiteCounts($spaceId),
            'editable'   => Auth::check(),
        ]);
    },

    /** 新增分组 */
    'add' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        $name = nav_str('name');
        if ($name === '') Response::fail('分组名称不能为空');
        if (mb_strlen($name, 'UTF-8') > 20) Response::fail('分组名称不能超过 20 个字');

        $id = nav_str('id');
        if ($id === '') $id = nav_next_id();
        if (Model::categoryExists($uid, $id)) Response::fail('该分组已存在');

        Model::insertCategory(
            $uid,
            $id,
            $name,
            Model::nextCategorySort($uid),
            nav_str('icon'),
            nav_str('icon_bg'),
            nav_str('description')
        );

        Response::ok([
            'category' => Model::getCategory($uid, $id),
            'counts'   => Model::categorySiteCounts($uid),
        ], '分组已创建');
    },

    /** 编辑分组（名称 / 图标 / 图标底色 / 描述） */
    'edit' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        $id = nav_str('id');
        if ($id === '') Response::fail('缺少分组 ID');
        if (!Model::categoryExists($uid, $id)) Response::fail('分组不存在');

        $data = [];
        foreach (['name', 'icon', 'icon_bg', 'description'] as $f) {
            if (array_key_exists($f, nav_input())) $data[$f] = nav_str($f);
        }
        if (!$data) Response::fail('没有需要更新的内容');

        if (isset($data['name'])) {
            if ($data['name'] === '') Response::fail('分组名称不能为空');
            if (mb_strlen($data['name'], 'UTF-8') > 20) Response::fail('分组名称不能超过 20 个字');
        }

        Model::updateCategory($uid, $id, $data);
        Response::ok(['category' => Model::getCategory($uid, $id)], '分组已更新');
    },

    /** 删除单个分组（组内站点自动搬到剩下的第一个分组） */
    'remove' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        $id = nav_str('id');
        if ($id === '') Response::fail('缺少分组 ID');
        if (!Model::categoryExists($uid, $id)) Response::fail('分组不存在');

        $n = Model::deleteCategories($uid, [$id]);

        // 站点搬到哪儿了，告诉用户一声
        $to = Model::categoryName($uid, Model::firstCategoryId($uid));
        $msg = ($n > 0 && $to !== '') ? ('分组已删除，组内站点已移到「' . $to . '」') : '分组已删除';

        Response::ok(['deleted' => $n, 'counts' => Model::categorySiteCounts($uid)], $msg);
    },

    /** 批量删除分组 */
    'removeMany' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        $ids = nav_arr('ids');
        if (!$ids) Response::fail('请选择要删除的分组');

        $n = Model::deleteCategories($uid, $ids);

        $to = Model::categoryName($uid, Model::firstCategoryId($uid));
        $msg = ($n > 0 && $to !== '') ? ('已删除 ' . $n . ' 个分组，组内站点已移到「' . $to . '」') : ('已删除 ' . $n . ' 个分组');

        Response::ok(['deleted' => $n, 'counts' => Model::categorySiteCounts($uid)], $msg);
    },

    /** 保存分组顺序（拖动排序后提交完整顺序） */
    'saveSort' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        $ids = nav_arr('ids');
        if (!$ids) {
            $ids = nav_arr('items');
        }
        if (!$ids) Response::fail('缺少排序数据');

        Model::applyCategorySort($uid, $ids);
        Response::ok(['categories' => Model::categories($uid)], '分组顺序已保存');
    },
];
