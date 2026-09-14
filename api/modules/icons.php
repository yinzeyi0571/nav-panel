<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 图标模块（对标 Sun-Panel 的上传 + getSiteFavicon + 在线图标库）
 *
 * 三条产出路径，最终都写进 sites.icon：
 *   icons.upload      本地上传（multipart/form-data，字段名 file）
 *   icons.fetch       填域名自动抓 favicon
 *   icons.search      在线图标库搜索（Iconify，走本站代理避免跨域）
 */

return [

    /** 本地上传图标 */
    'upload' => function () {
        Auth::requireAuth();
        $r = Icons::handleUpload(Auth::id());
        if (!$r['ok']) Response::fail($r['msg']);

        Response::ok(['file' => $r['file'], 'icon' => $r['file']['src'], 'icon_type' => 2], $r['msg']);
    },

    /** 自动抓取 favicon */
    'fetch' => function () {
        Auth::requireAuth();

        $url = nav_str('url');
        if ($url === '') Response::fail('请先填写网址');

        $r = Icons::fetchFavicon($url, Auth::id(), nav_bool('force') === 1);
        if (!$r['ok']) Response::fail($r['msg']);

        Response::ok([
            'icon'      => $r['icon'],
            'icon_type' => 2,
            'cached'    => (bool)$r['cached'],
            'source'    => isset($r['source']) ? $r['source'] : '',
        ], $r['msg']);
    },

    /** 在线图标库搜索 */
    'search' => function () {
        Auth::requireAuth();

        $q = nav_str('query');
        if ($q === '') $q = nav_str('q');
        if ($q === '') Response::fail('请输入关键词');
        if (mb_strlen($q, 'UTF-8') > 50) Response::fail('关键词太长了');

        $r = Icons::searchIconify($q, nav_int('limit', 64));
        if (!$r['ok']) Response::fail($r['msg']);

        Response::ok(['icons' => $r['icons'], 'total' => $r['total'], 'query' => $q]);
    },

    /** 在线图标库的图标集列表 */
    'collections' => function () {
        Auth::requireAuth();

        $r = Icons::iconifyCollections();
        if (!$r['ok']) Response::fail($r['msg']);

        Response::ok(['collections' => $r['collections']]);
    },

    /** 已上传/已抓取的文件列表（前台图标面板的「我的图标」页签用） */
    'listFiles' => function () {
        Auth::requireAuth();

        $method = nav_param('method', null);
        $files = Model::listFiles(
            Auth::id(),
            nav_int('limit', 200),
            ($method === null || $method === '') ? null : (int)$method
        );

        Response::ok(['files' => $files]);
    },

    /** 删除一个图标文件 */
    'deleteFile' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        $id = nav_int('id');
        if ($id <= 0) Response::fail('缺少文件 ID');

        // 先查有没有站点在用，再决定删不删。
        // （早期版本是先删登记、发现被引用再补回去，那样会让文件 ID 漂移，已改掉。）
        $row = Model::getFile($uid, $id);
        if (!$row) Response::fail('文件不存在');

        $inUse = Model::fileInUse($uid, $row['src']);
        if ($inUse > 0 && nav_bool('force') !== 1) {
            Response::fail('还有 ' . $inUse . ' 个站点在使用这个图标，确认要删除请再点一次', 1, ['in_use' => $inUse]);
        }

        Model::deleteFile($uid, $id);
        $path = Icons::srcToPath($row['src']);
        if ($path !== null && is_file($path)) @unlink($path);

        Response::ok(['deleted' => 1, 'in_use' => $inUse], '文件已删除');
    },
];
