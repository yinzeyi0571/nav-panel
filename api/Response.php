<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 统一响应
 *
 * 所有接口都返回 { code, msg, data } 结构：
 *   code = 0     成功
 *   code = 401   未登录
 *   code = 403   无权限
 *   code = 1     业务失败（msg 给用户看）
 * 这与 Sun-Panel 的响应结构一致（它的前端也是判 code === 0）。
 */

final class Response
{
    /** 直接输出并结束 */
    public static function send($code, $msg = '', $data = null)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        $out = ['code' => (int)$code, 'msg' => (string)$msg, 'data' => $data];

        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok($data = null, $msg = 'ok')
    {
        self::send(0, $msg, $data);
    }

    public static function fail($msg = '操作失败', $code = 1, $data = null)
    {
        self::send($code, $msg, $data);
    }

    public static function unauthorized($msg = '请先登录')
    {
        self::send(401, $msg);
    }

    public static function forbidden($msg = '无权限操作')
    {
        self::send(403, $msg);
    }

    public static function fatal($msg = '服务器错误', $code = 500)
    {
        self::send($code, $msg);
    }
}
