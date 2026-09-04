<?php
declare(strict_types=1);

/** Session-based admin authentication. No visitor-facing accounts exist — this guards /admin only. */
class Auth
{
    public static function attempt(string $username, string $password): bool
    {
        $pdo = Database::getInstance()->getConnection();
        $guard = new SecurityGuard($pdo);

        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || (bool) $user['is_suspended'] || !password_verify($password, $user['password'])) {
            $guard->handleFailedLogin(clientIp(), $username);
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = (int) $user['id'];
        $guard->handleSuccessfulLogin($user, clientIp());
        return true;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['admin_user_id']);
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $stmt = Database::getInstance()->getConnection()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['admin_user_id']]);
        $user = $stmt->fetch() ?: null;
        if ($user) {
            unset($user['password']);
        }
        return $cache = $user;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('/admin/login');
        }
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        $user = self::user();
        if (!$user || !in_array($user['role'], $roles, true)) {
            http_response_code(403);
            exit('You do not have permission to view this page.');
        }
    }

    /** Whether the current user is the flagged super-admin (owner) account. */
    public static function isSuperAdmin(): bool
    {
        $user = self::user();
        return $user !== null && !empty($user['is_super_admin']);
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_user_id']);
        session_regenerate_id(true);
    }
}
