<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 路由表
 *
 * 格式：'action 名' => [ 模块文件名（不含 .php）, 模块内的处理器名 ]
 * 新增接口时：① 在这里登记；② 在 api/modules/<模块>.php 里实现对应的处理器。
 */

return [

    /* ==================== 首页 ==================== */
    'home.index'            => ['home',        'index'],

    /* ==================== 认证 ==================== */
    'auth.login'            => ['auth',        'login'],
    'auth.logout'           => ['auth',        'logout'],
    'auth.me'               => ['auth',        'me'],
    'auth.changePassword'   => ['auth',        'changePassword'],
    'auth.updateProfile'    => ['auth',        'updateProfile'],

    /* ==================== 分类（对标 Sun-Panel itemIconGroup）==================== */
    'categories.list'       => ['categories',  'listAll'],
    'categories.add'        => ['categories',  'add'],
    'categories.edit'       => ['categories',  'edit'],
    'categories.delete'     => ['categories',  'remove'],
    'categories.deletes'    => ['categories',  'removeMany'],
    'categories.saveSort'   => ['categories',  'saveSort'],

    /* ==================== 站点（对标 Sun-Panel itemIcon）==================== */
    'sites.list'            => ['sites',       'listAll'],
    'sites.add'             => ['sites',       'add'],
    'sites.addMultiple'     => ['sites',       'addMultiple'],
    'sites.edit'            => ['sites',       'edit'],
    'sites.delete'          => ['sites',       'remove'],
    'sites.deletes'         => ['sites',       'removeMany'],
    'sites.saveSort'        => ['sites',       'saveSort'],
    'sites.hit'             => ['sites',       'hit'],

    /* ==================== 图标 ==================== */
    'icons.upload'          => ['icons',       'upload'],
    'icons.fetch'           => ['icons',       'fetch'],       // 自动抓取 favicon
    'icons.search'          => ['icons',       'search'],      // 在线图标库搜索（Iconify 代理）
    'icons.collections'     => ['icons',       'collections'], // 在线图标库分类
    'icons.listFiles'       => ['icons',       'listFiles'],
    'icons.deleteFile'      => ['icons',       'deleteFile'],

    /* ==================== 设置（对标 Sun-Panel panel_json）==================== */
    'settings.get'          => ['settings',    'get'],
    'settings.save'         => ['settings',    'save'],
    'settings.reset'        => ['settings',    'reset'],

    /* ==================== 用户（对标 Sun-Panel panel/users）==================== */
    'users.list'            => ['users',       'listAll'],
    'users.create'          => ['users',       'create'],
    'users.update'          => ['users',       'update'],
    'users.deletes'         => ['users',       'removeMany'],
    'users.getPublicVisit'  => ['users',       'getPublicVisit'],
    'users.setPublicVisit'  => ['users',       'setPublicVisit'],

    /* ==================== 系统监控 ==================== */
    'system.status'         => ['system',      'status'],
    'system.disks'          => ['system',      'disks'],
    'system.storage'        => ['system',      'storage'],

    /* ==================== 备份 ==================== */
    'backup.export'         => ['backup',      'export'],
    'backup.import'         => ['backup',      'import'],

    /* ==================== 版本更新记录 ==================== */
    'releases.list'         => ['releases',    'listAll'],
    'releases.add'          => ['releases',    'add'],
];
