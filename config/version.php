<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 版本信息
 *
 * 前台底部显示 version，点击跳转到更新页（#/changelog）。
 * 每次发版同时更新这里和数据库 releases 表（由 api/modules/releases.php 读取）。
 */

return [
    'version'     => '2.1.1',
    'released_at' => '2026-09-14',
    'name'        => '导航站',
];
