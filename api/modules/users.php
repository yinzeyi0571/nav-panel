<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 用户管理（仅管理员）
 *
 * 这是本站比 Sun-Panel 强的地方 —— Sun-Panel 只有单账号。
 * 权限模型：admin 能管全部用户；user 只能改自己的资料和密码（走 auth.* 接口）。
 */

/** 是否还能安全地删掉这些用户（不能删自己、不能删最后一个管理员） */
function users_delete_guard(array $ids)
{
    $me = Auth::id();
    foreach ($ids as $id) {
        if ((int)$id === $me) return '不能删除当前登录的账号';
    }

    $admins = Db::pdo()->query("SELECT id FROM users WHERE role = 'admin' AND status = 1")
        ->fetchAll(PDO::FETCH_COLUMN);
    $remain = array_diff(array_map('intval', $admins), array_map('intval', $ids));
    if (!$remain) return '至少要保留一个管理员账号';
    return '';
}

return [

    'listAll' => function () {
        Auth::requireAdmin();
        Response::ok([
            'users' => Model::users(),
            'public_visit_user_id' => (int)Db::getMeta('public_visit_user_id', '0'),
        ]);
    },

    'create' => function () {
        Auth::requireAdmin();

        $username = nav_str('username');
        if ($username === '') Response::fail('账号不能为空');
        if (!preg_match('/^[A-Za-z0-9_.\-@]{2,32}$/', $username)) {
            Response::fail('账号只能包含字母、数字、下划线、点、横线和 @，长度 2~32');
        }

        $password = (string)nav_param('password', '');
        if (strlen($password) < 6) Response::fail('密码至少 6 位');

        $role = nav_str('role', 'user');
        if (!in_array($role, ['admin', 'user'], true)) $role = 'user';

        $stmt = Db::pdo()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ((int)$stmt->fetchColumn() > 0) Response::fail('该账号已存在');

        $email = nav_str('email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) Response::fail('邮箱格式不正确');

        Db::pdo()->prepare(
            'INSERT INTO users (username, password_hash, role, nickname, email, status, created_at) VALUES (?,?,?,?,?,1,?)'
        )->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            nav_str('nickname', $username),
            $email,
            time(),
        ]);
        $id = (int)Db::pdo()->lastInsertId();

        Model::ensureSettings($id);
        Model::ensureDefaultCategory($id);

        Response::ok(['id' => $id, 'users' => Model::users()], '用户已创建');
    },

    'update' => function () {
        Auth::requireAdmin();

        $id = nav_int('id');
        if ($id <= 0) Response::fail('缺少用户 ID');

        $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $cur = $stmt->fetch();
        if (!$cur) Response::fail('用户不存在');

        $sets = [];
        $params = [];

        if (array_key_exists('nickname', nav_input())) {
            $sets[] = 'nickname = ?';
            $params[] = nav_str('nickname');
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
        if (array_key_exists('role', nav_input())) {
            $role = nav_str('role');
            if (!in_array($role, ['admin', 'user'], true)) Response::fail('角色不合法');
            // 别把自己降级成普通用户，否则没人能管后台
            if ($id === Auth::id() && $role !== 'admin') Response::fail('不能降低自己的权限');
            $sets[] = 'role = ?';
            $params[] = $role;
        }
        if (array_key_exists('status', nav_input())) {
            $status = nav_int('status') === 1 ? 1 : 0;
            if ($id === Auth::id() && $status !== 1) Response::fail('不能停用当前登录的账号');
            $sets[] = 'status = ?';
            $params[] = $status;
        }
        if (nav_str('password') !== '') {
            $pwd = (string)nav_param('password', '');
            if (strlen($pwd) < 6) Response::fail('密码至少 6 位');
            $sets[] = 'password_hash = ?';
            $params[] = password_hash($pwd, PASSWORD_DEFAULT);
        }

        if (!$sets) Response::fail('没有需要更新的内容');

        $params[] = $id;
        Db::pdo()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        Response::ok(['users' => Model::users()], '用户已更新');
    },

    'removeMany' => function () {
        Auth::requireAdmin();

        $ids = nav_arr('ids');
        if (!$ids) Response::fail('请选择要删除的用户');

        $guard = users_delete_guard($ids);
        if ($guard !== '') Response::fail($guard);

        $n = 0;
        $delSites = Db::pdo()->prepare('DELETE FROM sites WHERE user_id = ?');
        $delCats  = Db::pdo()->prepare('DELETE FROM categories WHERE user_id = ?');

        Db::pdo()->beginTransaction();
        try {
            $del = Db::pdo()->prepare('DELETE FROM users WHERE id = ?');
            foreach ($ids as $id) {
                $uid = (int)$id;
                if ($uid <= 0) continue;
                // 连带的站点和分组一起清掉，避免留下孤儿数据
                $delSites->execute([$uid]);
                $delCats->execute([$uid]);
                $del->execute([$uid]);
                $n += $del->rowCount();
            }
            Db::pdo()->commit();
        } catch (Exception $e) {
            Db::pdo()->rollBack();
            Response::fail('删除失败：' . $e->getMessage());
        }

        Response::ok(['deleted' => $n, 'users' => Model::users()], '已删除 ' . $n . ' 个用户');
    },

    /** 查询「公开访问」指向哪个用户 */
    'getPublicVisit' => function () {
        Auth::requireAdmin();

        $pubId = (int)Db::getMeta('public_visit_user_id', '0');
        $user = null;
        if ($pubId > 0) {
            foreach (Model::users() as $u) {
                if ($u['id'] === $pubId) { $user = $u; break; }
            }
        }

        Response::ok([
            'enabled' => $pubId > 0,
            'user_id' => $pubId,
            'user'    => $user,
            'users'   => Model::users(),
        ]);
    },

    /** 设置「公开访问」指向的用户；传 0 表示关闭 */
    'setPublicVisit' => function () {
        Auth::requireAdmin();

        $uid = nav_int('user_id');
        if ($uid <= 0) {
            Db::setMeta('public_visit_user_id', '0');
            Response::ok(['enabled' => false, 'user_id' => 0], '已关闭公开访问');
        }

        $stmt = Db::pdo()->prepare('SELECT id, username FROM users WHERE id = ? AND status = 1');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        if (!$row) Response::fail('该用户不存在或已停用');

        Db::setMeta('public_visit_user_id', (string)$uid);
        Response::ok([
            'enabled' => true,
            'user_id' => $uid,
            'username' => $row['username'],
        ], '未登录访客将看到「' . $row['username'] . '」的导航页');
    },
];
