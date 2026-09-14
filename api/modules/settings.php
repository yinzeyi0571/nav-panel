<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 外观与行为设置（对标 Sun-Panel 的 user_config.panel_json）
 *
 * 读：任何人（未登录也要能正确渲染公开空间的样式）
 * 写：必须登录，只写自己的设置
 *
 * 校验规则在 api/Settings.php 里（backup 模块也要用，不能写在模块文件内）。
 */

return [

    /** 取当前空间的外观设置 */
    'get' => function () {
        $spaceId = Auth::spaceUserId();
        Response::ok([
            'settings' => Model::settings($spaceId),
            'editable' => Auth::check(),
        ]);
    },

    /** 保存设置 */
    'save' => function () {
        Auth::requireAuth();
        $uid  = Auth::id();
        $in   = nav_input();

        $err = Settings::validate($in);
        if ($err !== '') Response::fail($err);

        $saved = Model::saveSettings($uid, $in);
        Response::ok(['settings' => $saved], '设置已保存');
    },

    /** 恢复默认外观（只重置样式相关，不动账号和站点） */
    'reset' => function () {
        Auth::requireAuth();
        $uid = Auth::id();

        $defaults = Model::defaultSettings();
        unset($defaults['user_id']);

        $saved = Model::saveSettings($uid, $defaults);
        Response::ok(['settings' => $saved], '已恢复默认外观');
    },
];
