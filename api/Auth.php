<?php
if (!defined('NAV_ENTRY')) { http_response_code(403); exit('forbidden'); }
/**
 * 认证与权限
 *
 * 密码策略：password_hash() + PASSWORD_DEFAULT（bcrypt）。
 * 注意：Sun-Panel 用的是 32 位无盐 MD5，这里刻意不复刻 —— 它不安全。
 */

final class Auth
{
    /** 当前登录用户（未登录返回 null） */
    public static function current()
    {
        static $cached = false;
        static $user = null;

        if ($cached) return $user;
        $cached = true;

        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($uid <= 0) return $user = null;

        $stmt = Db::pdo()->prepare('SELECT id, username, role, nickname, email, avatar, status FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();

        if (!$row) {
            unset($_SESSION['user_id']);
            return $user = null;
        }
        if ((int)$row['status'] !== 1) {
            unset($_SESSION['user_id']);
            return $user = null;
        }

        $row['id'] = (int)$row['id'];
        return $user = $row;
    }

    public static function id()
    {
        $u = self::current();
        return $u ? (int)$u['id'] : 0;
    }

    public static function check()
    {
        return self::current() !== null;
    }

    public static function isAdmin()
    {
        $u = self::current();
        return $u && $u['role'] === 'admin';
    }

    public static function requireAuth()
    {
        $u = self::current();
        if (!$u) Response::unauthorized('请先登录');
        return $u;
    }

    public static function requireAdmin()
    {
        $u = self::requireAuth();
        if ($u['role'] !== 'admin') Response::forbidden('需要平台管理权限');
        return $u;
    }

    /**
     * 当前展示的数据空间。
     * 已登录 → 自己的空间；未登录 → 公开访问用户的空间，没有则退回最早的管理员。
     */
    public static function spaceUserId()
    {
        $u = self::current();
        if ($u) return (int)$u['id'];

        // 显式配置的公开访问用户（对标 Sun-Panel 的 setPublicVisitUser）
        $pub = Db::getMeta('public_visit_user_id');
        if ($pub !== null && $pub !== '' && (int)$pub > 0) {
            $stmt = Db::pdo()->prepare('SELECT id FROM users WHERE id = ? AND status = 1');
            $stmt->execute([(int)$pub]);
            $id = $stmt->fetchColumn();
            if ($id) return (int)$id;
        }

        $stmt = Db::pdo()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : 0;
    }

    /**
     * 当前展示空间的主人。
     *
     * 和 current() 的区别：未登录时 current() 是 null，而这里返回「正在看谁的空间」。
     * 前端要显示空间属主、判断是否只读，靠的就是这个。
     */
    public static function spaceUser()
    {
        static $cached = false;
        static $row = null;
        if ($cached) return $row;
        $cached = true;

        $id = self::spaceUserId();
        if ($id <= 0) return $row = null;

        $stmt = Db::pdo()->prepare(
            'SELECT id, username, role, nickname, email, avatar, status FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) return $row = null;

        $r['id'] = (int)$r['id'];
        return $row = $r;
    }

    /* ==================== 操作 ==================== */

    /**
     * 校验账号密码并登录。
     *
     * $remember = true  → 下发长期 Cookie（默认 10 年 + 每次访问滑动续期），关浏览器也不掉线
     * $remember = false → 只发浏览器会话 Cookie，关掉浏览器就失效
     */
    public static function attempt($username, $password, $remember = false)
    {
        $username = trim((string)$username);
        if ($username === '' || $password === '') {
            return ['ok' => false, 'msg' => '账号和密码不能为空'];
        }

        $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        if (!$row || !password_verify((string)$password, $row['password_hash'])) {
            return ['ok' => false, 'msg' => '账号或密码错误'];
        }
        if ((int)$row['status'] !== 1) {
            return ['ok' => false, 'msg' => '该账号已被停用'];
        }

        // 会话固定攻击防护
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['id'];
        $_SESSION['_renew']  = 0;

        // 勾了记住我就换发长期 Cookie；没勾则清掉标记，维持浏览器会话 Cookie
        nav_remember_session((bool)$remember);

        Db::pdo()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([time(), (int)$row['id']]);

        return ['ok' => true, 'user' => [
            'id'       => (int)$row['id'],
            'username' => $row['username'],
            'role'     => $row['role'],
            'nickname' => isset($row['nickname']) ? $row['nickname'] : '',
        ]];
    }

    public static function logout()
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function changePassword($oldPlain, $newPlain)
    {
        $u = self::requireAuth();
        $newPlain = (string)$newPlain;

        if (strlen($newPlain) < 6) return ['ok' => false, 'msg' => '新密码至少 6 位'];

        $stmt = Db::pdo()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([(int)$u['id']]);
        $hash = $stmt->fetchColumn();

        if (!password_verify((string)$oldPlain, $hash)) {
            return ['ok' => false, 'msg' => '原密码错误'];
        }

        Db::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($newPlain, PASSWORD_DEFAULT), (int)$u['id']]);

        Db::setMeta('force_password_change', '0');
        return ['ok' => true, 'msg' => '密码修改成功'];
    }

    /** 输出给前端的用户对象（统一字段） */
    public static function publicUser($row)
    {
        if (!$row) return null;
        return [
            'id'       => (int)$row['id'],
            'username' => $row['username'],
            'nickname' => isset($row['nickname']) && $row['nickname'] !== '' ? $row['nickname'] : $row['username'],
            'role'     => $row['role'],
            'avatar'   => isset($row['avatar']) ? $row['avatar'] : '',
            'isAdmin'  => ($row['role'] === 'admin'),
        ];
    }
}
