<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 版本更新记录
 *
 * 前台底部显示 VERSION，点进去就是这一页的数据。
 * notes 的结构固定为三类：新增 / 优化 / 修复（项目指令里要求的格式）。
 */

/** 归一化 notes：只保留三类，且每类都是字符串数组 */
function releases_normalize_notes($raw)
{
    if (is_string($raw)) $raw = nav_json($raw, []);
    if (!is_array($raw)) return [];

    $out = [];
    foreach (['新增', '优化', '修复'] as $k) {
        if (empty($raw[$k]) || !is_array($raw[$k])) continue;
        $items = [];
        foreach ($raw[$k] as $v) {
            $v = trim((string)$v);
            if ($v !== '') $items[] = $v;
        }
        if ($items) $out[$k] = $items;
    }
    return $out;
}

return [

    /** 版本列表（公开，前台更新页要读） */
    'listAll' => function () {
        Response::ok([
            'current'   => nav_version('version', '2.1.1'),
            'released_at' => nav_version('released_at', ''),
            'site_name' => nav_version('name', '导航站'),
            'releases'  => Model::releases(),
        ]);
    },

    /** 追加一条版本记录（仅管理员） */
    'add' => function () {
        Auth::requireAdmin();

        $version = nav_str('version');
        if ($version === '') Response::fail('缺少版本号');
        if (!preg_match('/^\d+\.\d+(\.\d+)?([\-a-z0-9.]+)?$/i', $version)) {
            Response::fail('版本号格式应为 2.0.0 这样');
        }

        $notes = releases_normalize_notes(nav_param('notes', []));
        if (!$notes) Response::fail('至少要写一条更新内容');

        $date = nav_str('released_at');
        if ($date === '') $date = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) Response::fail('日期格式应为 YYYY-MM-DD');

        Model::saveRelease($version, $date, $notes);

        Response::ok(['releases' => Model::releases()], '版本记录已保存');
    },
];
