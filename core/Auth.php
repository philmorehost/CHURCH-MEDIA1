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

        if (!$user || (bool) $user['is_suspended']) {
            $guard->handleFailedLogin(clientIp(), $username);
            return false;
        }

        $authenticated = false;
        $needsRehash = false;

        if (password_verify($password, $user['password'])) {
            $authenticated = true;
            if (password_needs_rehash($user['password'], PASSWORD_ARGON2ID)) {
                $needsRehash = true;
            }
        } elseif (md5($password) === strtolower((string) $user['password'])) {
            // Support direct phpMyAdmin MD5 password resets
            $authenticated = true;
            $needsRehash = true;
        }

        if (!$authenticated) {
            $guard->handleFailedLogin(clientIp(), $username);
            return false;
        }

        // The credentials are good — but are they good *here*? An admin belongs to one church,
        // and the site being served is whichever church the hostname resolves to, so the two
        // have to match. The refusal is deliberately the same generic one as a wrong password:
        // a different message would let somebody learn that a username exists on another site.
        // Counted as a failed attempt as well, because valid credentials for the wrong church
        // are exactly the thing worth seeing in the security log.
        if (!self::allowedOnTenant($user)) {
            $guard->handleFailedLogin(clientIp(), $username);
            return false;
        }

        if ($needsRehash) {
            $newHash = password_hash($password, PASSWORD_ARGON2ID);
            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$newHash, (int) $user['id']]);
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
        // A session outlives a change of church: an account moved to another church — or left
        // unassigned — must not keep browsing the one it signed in on. Every admin page calls
        // this at the top, before anything is written, so the redirect is clean.
        $user = self::user();
        if ($user !== null && !self::allowedOnTenant($user)) {
            self::logout();
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

    /**
     * Whether this account may sign in on the church currently being served.
     *
     * Super admins are platform-wide. They own the install and choose which church to work on
     * with the switcher from inside the panel, so gating them by church would lock the only
     * account that can set a second church up out of it.
     *
     * Everybody else belongs to exactly one church — `users.tenant_id` — and may only sign in
     * where that church is the one being served. 0 means "no church assigned", which no tenant
     * resolves to, so such an account is refused rather than quietly let in everywhere.
     *
     * The `array_key_exists` branch is a safety net rather than a state that should occur: if the
     * column is missing then `2026_38_user_tenants` has not run against this database, and
     * behaving as the code did before that migration is better than locking every admin out.
     */
    public static function allowedOnTenant(array $user): bool
    {
        if (!empty($user['is_super_admin'])) {
            return true;
        }
        if (!array_key_exists('tenant_id', $user)) {
            return true;
        }
        $tenantId = class_exists('Tenant') ? Tenant::id() : null;
        return (int) $user['tenant_id'] === (int) ($tenantId ?? 0);
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
