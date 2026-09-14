<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 系统监控
 *
 * 关于权限：系统信息（负载 / 内存 / 磁盘 / 内核版本）本身不敏感，
 * 但没必要对匿名访客敞开。所以：
 *   已登录 → 始终返回
 *   未登录 → 仅当所在空间打开了「显示系统监控」才返回，否则只给空壳
 */

/** 是否允许当前访客看监控数据 */
function system_can_view()
{
    if (Auth::check()) return true;

    $settings = Model::settings(Auth::spaceUserId());
    return !empty($settings['monitor_show']);
}

return [

    /** 完整状态快照 */
    'status' => function () {
        if (!system_can_view()) {
            Response::ok(['supported' => false, 'hidden' => true]);
        }

        Response::ok(System::snapshot());
    },

    /** 只需磁盘列表时用它，省掉 CPU 两次采样 */
    'disks' => function () {
        if (!system_can_view()) {
            Response::ok(['disks' => [], 'hidden' => true]);
        }

        Response::ok([
            'supported' => System::supported(),
            'disks'     => System::disks(),
        ]);
    },

    /**
     * 存储自检：数据库落点 / v1 遗留文件 / 上传目录可写性。
     * 会暴露服务器路径，所以只给管理员。
     */
    'storage' => function () {
        Auth::requireAdmin();
        Response::ok(System::storage());
    },
];
