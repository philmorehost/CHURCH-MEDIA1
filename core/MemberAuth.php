<?php
declare(strict_types=1);

/**
 * Session-based member authentication for the public site.
 *
 * Deliberately a separate class, and a separate session key, from `Auth`. `Auth`
 * guards /admin off `$_SESSION['admin_user_id']`; members use `$_SESSION['member_id']`.
 * Keeping them apart means a member session can never satisfy `Auth::check()` and an
 * admin session can never satisfy `MemberAuth::check()` — neither leaks into the other,
 * even in one browser. That separation is the whole reason this is not folded into Auth.
 *
 * Membership is **per-church**: every lookup is scoped to the resolved tenant, so a
 * member of one church cannot sign in on another church's site even with the right
 * password. `tenantKey()` is the single place that decision is expressed.
 */
class MemberAuth
{
    /** Not `admin_user_id` — see the class docblock. */
    private const SESSION_KEY = 'member_id';

    /** Signed out after this long without activity. */
    public const IDLE_TIMEOUT = 5184000; // 60 days

    /**
     * The tenant a member row belongs to.
     *
     * 0 means "no tenant resolved" and is a real, matchable value rather than NULL —
     * see the note on the `2026_25_members` migration. Reads and writes both go through
     * here, so a row always carries the same value login will search for.
     */
    public static function tenantKey(): int
    {
        return Tenant::id() ?? 0;
    }

    public static function attempt(string $email, string $password): bool
    {
        $email = Member::normaliseEmail($email);
        $pdo = Database::getInstance()->getConnection();
        $guard = new SecurityGuard($pdo);

        $stmt = $pdo->prepare('SELECT * FROM members WHERE email = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$email, self::tenantKey()]);
        $member = $stmt->fetch();

        // A suspended or unknown member is treated exactly like a wrong password.
        if (!$member || (bool) $member['is_suspended']) {
            $guard->handleFailedLogin(clientIp(), $email);
            return false;
        }

        if (!password_verify($password, (string) $member['password_hash'])) {
            $guard->handleFailedLogin(clientIp(), $email);
            return false;
        }

        if (password_needs_rehash((string) $member['password_hash'], PASSWORD_ARGON2ID)) {
            $pdo->prepare('UPDATE members SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_ARGON2ID), (int) $member['id']]);
        }

        // Adopt the bookmarks this browser made before the account existed, so the saved
        // list does not start empty on the day somebody registers. Idempotent, and it
        // never touches a row that already belongs to someone else.
        MemberActivity::claimFor((int) $member['id'], Fingerprint::hash(), (string) $member['email']);

        self::login((int) $member['id']);
        return true;
    }

    /**
     * Establishes the session. Called by `attempt()`, and directly after a member
     * proves ownership of their address, so verification signs them straight in
     * instead of bouncing them to a login form they just came from.
     */
    public static function login(int $memberId): void
    {
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $memberId;
        $_SESSION['member_seen_at'] = time();
        Database::getInstance()->getConnection()
            ->prepare('UPDATE members SET last_seen_at = NOW() WHERE id = ?')
            ->execute([$memberId]);
    }

    public static function check(): bool
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        $seen = (int) ($_SESSION['member_seen_at'] ?? 0);
        if ($seen > 0 && (time() - $seen) > self::IDLE_TIMEOUT) {
            self::logout();
            return false;
        }
        $_SESSION['member_seen_at'] = time();
        return true;
    }

    public static function id(): ?int
    {
        return self::check() ? (int) $_SESSION[self::SESSION_KEY] : null;
    }

    /**
     * The signed-in member, without any secret columns.
     *
     * Re-queries with the tenant guard so a session that outlives a tenant change
     * resolves to nothing rather than to another church's member.
     */
    public static function member(): ?array
    {
        $id = self::id();
        if ($id === null) {
            return null;
        }
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $stmt = Database::getInstance()->getConnection()
            ->prepare('SELECT * FROM members WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, self::tenantKey()]);
        $row = $stmt->fetch() ?: null;
        if ($row) {
            unset($row['password_hash'], $row['verify_token_hash'], $row['reset_token_hash']);
        }
        return $cache = $row;
    }

    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        // Remember where they were headed so login can return them there.
        $_SESSION['member_intended'] = $_SERVER['REQUEST_URI'] ?? '/member';
        flash('member_error', 'Please sign in to continue.');
        redirect('/member/login');
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION['member_seen_at']);
        session_regenerate_id(true);
    }
}
