<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 外观设置的校验规则
 *
 * 单独成类的原因：settings 模块和 backup 模块都要用。
 * 而 index.php 每次请求只 require 一个模块文件，
 * 把校验函数写在模块里会导致另一个模块调用时「函数未定义」致命错误。
 */

final class Settings
{
    const ENUM = [
        'theme_mode' => ['auto', 'dark', 'light'],
        'open_mode'  => ['new', 'current', 'dialog'],
        'sort_mode'  => ['manual', 'name', 'hits', 'created'],
    ];

    const RANGES = [
        'bg_blur'       => [0, 100],
        'bg_mask'       => [0, 100],
        'max_width'     => [480, 3840],
        'margin_top'    => [0, 60],
        'margin_bottom' => [0, 60],
        'margin_x'      => [0, 200],
    ];

    /** 壁纸 / Logo 只接受本站上传路径或 http(s) 外链 */
    public static function validAsset($v)
    {
        $v = trim((string)$v);
        if ($v === '') return true;

        $base = Icons::uploadUrl();
        if ($base !== '' && strpos($v, $base . '/') === 0) {
            return strpos($v, '..') === false;      // 禁止路径穿越
        }
        if (preg_match('#^https?://#i', $v)) {
            return mb_strlen($v, 'UTF-8') <= 500;
        }

        return false;   // data:、javascript:、相对路径一律拒绝
    }

    /** 校验提交上来的设置，返回错误消息，没问题返回 '' */
    public static function validate(array $in)
    {
        foreach (self::ENUM as $field => $allowed) {
            if (!array_key_exists($field, $in)) continue;
            if (!in_array((string)$in[$field], $allowed, true)) {
                return $field . ' 的取值不合法';
            }
        }

        // 主题名 / 图标风格 / 搜索引擎：只校验字符集，具体取值由前端提供
        foreach (['theme', 'icon_style', 'search_engine'] as $field) {
            if (!array_key_exists($field, $in)) continue;
            if (!preg_match('/^[a-z0-9_\-]{1,24}$/i', (string)$in[$field])) {
                return $field . ' 的取值不合法';
            }
        }

        foreach (self::RANGES as $field => $r) {
            if (!array_key_exists($field, $in)) continue;
            $v = (int)$in[$field];
            if ($v < $r[0] || $v > $r[1]) {
                return $field . ' 应在 ' . $r[0] . ' ~ ' . $r[1] . ' 之间';
            }
        }

        if (array_key_exists('icon_text_color', $in)
            && !preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$in['icon_text_color'])) {
            return '图标文字颜色格式不正确';
        }

        if (array_key_exists('logo_text', $in) && mb_strlen((string)$in['logo_text'], 'UTF-8') > 50) {
            return '站点标题不能超过 50 个字';
        }
        if (array_key_exists('footer', $in) && mb_strlen((string)$in['footer'], 'UTF-8') > 2000) {
            return '页脚内容不能超过 2000 个字';
        }

        foreach (['wallpaper', 'logo_image'] as $field) {
            if (!array_key_exists($field, $in)) continue;
            if (!self::validAsset($in[$field])) {
                return $field === 'wallpaper'
                    ? '壁纸只支持本站上传的图片或 http(s) 外链'
                    : 'Logo 只支持本站上传的图片或 http(s) 外链';
            }
        }

        return '';
    }
}
