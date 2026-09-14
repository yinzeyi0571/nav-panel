<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 认证模块
 *
 * 对标 Sun-Panel: /user/getAuthInfo、/user/updatePassword、/user/updateInfo
 */

return [

    'login' => function () {
        $remember = nav_bool('remember', 1) === 1;   // 不传默认「记住我」，避免旧前端被意外降级成关浏览器即失效
        $r = Auth::attempt(nav_str('username'), (string)nav_param('password', ''), $remember);
        if (!$r['ok']) Response::fail($r['msg']);

        $user = Auth::current();
        Response::ok([
            'user' => Auth::publicUser($user),
            'settings' => Model::settings((int)$r['user']['id']),
            'remember' => $remember ? 1 : 0,
        ], '登录成功');
    },

    'logout' => function () {
        Auth::logout();
        Response::ok(null, '已退出登录');
    },

    'me' => function () {
        $user = Auth::current();
        Response::ok([
            'user'     => Auth::publicUser($user),
            'is_admin' => Auth::isAdmin(),
            'editable' => Auth::check(),
        ]);
    },

    'changePassword' => function () {
        $r = Auth::changePassword(nav_param('old_password', ''), nav_str('new_password'));
        if (!$r['ok']) Response::fail($r['msg']);
        Response::ok(null, $r['msg']);
    },

    'updateProfile' => function () {
        $u = Auth::requireAuth();
        $sets = [];
        $params = [];

        if (array_key_exists('nickname', nav_input())) {
            $nickname = nav_str('nickname');
            if (mb_strlen($nickname, 'UTF-8') > 20) Response::fail('昵称不能超过 20 个字');
            $sets[] = 'nickname = ?';
            $params[] = $nickname;
        }
        if (array_key_exists('email', nav_input())) {
            $email = nav_str('email');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) Response::fail('邮箱格式不正确');
            $sets[] = 'email = ?';
            $params[] = $email;
        }
        if (array_key_exists('avatar', nav_input())) {
            $sets[] = 'avatar = ?';
            $params[] = nav_str('avatar');
        }

        if (!$sets) Response::fail('没有需要更新的内容');

        $params[] = (int)$u['id'];
        Db::pdo()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        Response::ok(['user' => Auth::publicUser(Auth::current())], '资料已更新');
    },
];
