<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 首页数据
 *
 * 一次请求把前台首屏需要的所有东西给全：用户、分组、站点、设置、版本。
 * 对标 Sun-Panel 的 / + /panel/userConfig/get + /panel/itemIconGroup/getList
 * + /panel/itemIcon/getListByGroupId 四连调，这里合并成一个请求，首屏少三次往返。
 */

return [

    'index' => function () {
        $spaceId = Auth::spaceUserId();
        $user    = Auth::current();

        Response::ok([
            // user = 当前登录的人（未登录为 null）
            'user'            => Auth::publicUser($user),
            // space_user = 当前正在展示谁的空间（未登录时是公开访问用户或最早的管理员）
            'space_user'      => Auth::publicUser(Auth::spaceUser()),
            'editable'        => Auth::check(),
            'is_admin'        => Auth::isAdmin(),
            'categories'      => Model::categories($spaceId),
            'sites'           => Model::sites($spaceId),
            'settings'        => Model::settings($spaceId),
            'version'         => nav_version('version', '2.1.1'),
            'released_at'     => nav_version('released_at', ''),
            'site_name'       => nav_version('name', '导航站'),
            'need_pwd_change' => (Db::getMeta('force_password_change', '0') === '1'),
        ]);
    },
];
