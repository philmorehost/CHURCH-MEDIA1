<?php
declare(strict_types=1);

/**
 * Member lifecycle: registration, email verification, password reset and profile
 * edits. Session handling lives in MemberAuth — this class never touches $_SESSION.
 *
 * Emailed tokens are handed out in plaintext but stored only as a SHA-256 hash, so
 * a leaked database read does not hand anyone a working link. The token is also
 * single-use: verifying or resetting clears the hash, so an old email cannot be
 * replayed.
 */
class Member
{
    /** How long an emailed link stays valid. */
    public const VERIFY_TTL_HOURS = 24;

    /** Reset links are shorter-lived than verification links on purpose. */
    public const RESET_TTL_HOURS = 2;

    /** The notification categories a member can switch off. */
    public const NOTIFICATION_KEYS = array('devotional', 'events', 'prayer', 'giving', 'reading_plan');

    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Phone numbers reuse the SMS normaliser rather than growing a second one — the
     * column documents the same "2348031234567" shape either way, and two
     * implementations would eventually disagree.
     */
    public static function normalisePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $country = trim((string) setting('sms_default_country'));
        return Sms::normaliseMsisdn($phone, $country !== '' ? $country : null);
    }

    /** @return string[] Human-readable problems with a registration submission. */
    public static function validateRegistration(string $name, string $email, string $password, string $confirm): array
    {
        $errors = array();
        if (mb_strlen(trim($name)) < 2) {
            $errors[] = 'Please enter your full name.';
        }
        if (!filter_var(self::normaliseEmail($email), FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'That email address does not look right.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Choose a password of at least 8 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }
        return $errors;
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::getInstance()->getConnection()
            ->prepare('SELECT * FROM members WHERE email = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([self::normaliseEmail($email), MemberAuth::tenantKey()]);
        return $stmt->fetch() ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::getInstance()->getConnection()
            ->prepare('SELECT * FROM members WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, MemberAuth::tenantKey()]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Creates an unverified member.
     *
     * @return array{id?:int,token?:string,errors?:string[]}
     */
    public static function register(string $name, string $email, ?string $phone, string $password): array
    {
        $email = self::normaliseEmail($email);

        if (self::findByEmail($email) !== null) {
            return array('errors' => array('That email is already registered — please sign in instead.'));
        }

        $pdo = Database::getInstance()->getConnection();
        $token = self::newToken();

        $pdo->prepare(
            'INSERT INTO members (tenant_id, name, email, phone, password_hash, verify_token_hash, verify_expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            MemberAuth::tenantKey(),
            trim($name),
            $email,
            self::normalisePhone($phone),
            password_hash($password, PASSWORD_ARGON2ID),
            self::hashToken($token),
            self::expiry(self::VERIFY_TTL_HOURS),
        ));

        return array('id' => (int) $pdo->lastInsertId(), 'token' => $token);
    }

    /** Issues a fresh verification link, replacing any previous one. */
    public static function reissueVerification(int $memberId): ?string
    {
        $token = self::newToken();
        $stmt = Database::getInstance()->getConnection()
            ->prepare('UPDATE members SET verify_token_hash = ?, verify_expires_at = ? WHERE id = ?');
        $stmt->execute(array(self::hashToken($token), self::expiry(self::VERIFY_TTL_HOURS), $memberId));
        return $stmt->rowCount() > 0 ? $token : null;
    }

    /**
     * Consumes a verification token.
     *
     * @return int|null The member id on success, null if the token is unknown, already
     *                  used or expired. Clearing the hash is what makes it single-use.
     */
    public static function verify(string $token): ?int
    {
        if ($token === '') {
            return null;
        }
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            'SELECT id FROM members
              WHERE verify_token_hash = ? AND tenant_id = ? AND verify_expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute(array(self::hashToken($token), MemberAuth::tenantKey()));
        $id = $stmt->fetchColumn();

        if ($id === false) {
            return null;
        }

        $pdo->prepare(
            'UPDATE members
                SET is_verified = 1, verify_token_hash = NULL, verify_expires_at = NULL
              WHERE id = ?'
        )->execute(array((int) $id));

        return (int) $id;
    }

    /**
     * Starts a password reset.
     *
     * Returns the plaintext token, or null when no such member exists. Callers must
     * show the same confirmation either way, so this form cannot be used to discover
     * which addresses are registered.
     */
    public static function issueReset(string $email): ?string
    {
        $member = self::findByEmail($email);
        if ($member === null || (bool) $member['is_suspended']) {
            return null;
        }
        $token = self::newToken();
        Database::getInstance()->getConnection()
            ->prepare('UPDATE members SET reset_token_hash = ?, reset_expires_at = ? WHERE id = ?')
            ->execute(array(self::hashToken($token), self::expiry(self::RESET_TTL_HOURS), (int) $member['id']));
        return $token;
    }

    /** Consumes a reset token and sets the new password. */
    public static function resetPassword(string $token, string $password): ?int
    {
        if ($token === '' || strlen($password) < 8) {
            return null;
        }
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            'SELECT id FROM members
              WHERE reset_token_hash = ? AND tenant_id = ? AND reset_expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute(array(self::hashToken($token), MemberAuth::tenantKey()));
        $id = $stmt->fetchColumn();

        if ($id === false) {
            return null;
        }

        $pdo->prepare(
            'UPDATE members
                SET password_hash = ?, reset_token_hash = NULL, reset_expires_at = NULL,
                    is_verified = 1
              WHERE id = ?'
        )->execute(array(password_hash($password, PASSWORD_ARGON2ID), (int) $id));

        return (int) $id;
    }

    /** True when a reset link is still inside its window. */
    public static function tokenLooksValid(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $stmt = Database::getInstance()->getConnection()->prepare(
            'SELECT COUNT(*) FROM members
              WHERE reset_token_hash = ? AND tenant_id = ? AND reset_expires_at > NOW()'
        );
        $stmt->execute(array(self::hashToken($token), MemberAuth::tenantKey()));
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function verificationUrl(string $token): string
    {
        return baseUrl('/member/verify?token=' . urlencode($token));
    }

    public static function resetUrl(string $token): string
    {
        return baseUrl('/member/reset-password?token=' . urlencode($token));
    }

    /**
     * Email verification. Returns whether the mailer accepted it, so the caller can
     * tell the member to ask an admin rather than leave them waiting for nothing.
     */
    public static function sendVerification(string $to, string $name, string $token): bool
    {
        $church = setting('site_title') ?: 'our church';
        $body = "Hello " . $name . ",\n\n"
            . "Welcome to " . $church . ". Please confirm this address so we know it is really you:\n\n"
            . self::verificationUrl($token) . "\n\n"
            . "The link works for " . self::VERIFY_TTL_HOURS . " hours.\n\n"
            . "If you did not create this account, you can ignore this email.\n";
        return Mailer::send($to, 'Confirm your email — ' . $church, $body);
    }

    public static function sendReset(string $to, string $name, string $token): bool
    {
        $church = setting('site_title') ?: 'our church';
        $body = "Hello " . $name . ",\n\n"
            . "Someone asked to reset the password on your " . $church . " account.\n\n"
            . self::resetUrl($token) . "\n\n"
            . "The link works for " . self::RESET_TTL_HOURS . " hours and can only be used once.\n\n"
            . "If this was not you, no action is needed — your password has not changed.\n";
        return Mailer::send($to, 'Reset your password — ' . $church, $body);
    }

    /** @return array<string,bool> Every category, defaulting to on. */
    public static function preferences(array $member): array
    {
        $stored = json_decode((string) ($member['notification_prefs'] ?? ''), true);
        if (!is_array($stored)) {
            $stored = array();
        }
        $prefs = array();
        foreach (self::NOTIFICATION_KEYS as $key) {
            $prefs[$key] = !array_key_exists($key, $stored) ? true : (bool) $stored[$key];
        }
        return $prefs;
    }

    public static function savePreferences(int $memberId, array $submitted): void
    {
        $prefs = array();
        foreach (self::NOTIFICATION_KEYS as $key) {
            $prefs[$key] = !empty($submitted[$key]);
        }
        Database::getInstance()->getConnection()
            ->prepare('UPDATE members SET notification_prefs = ? WHERE id = ?')
            ->execute(array(json_encode($prefs), $memberId));
    }

    /** Same shape as `User`/device registration uses, so SMS and WhatsApp reuse it. */
    public static function updateProfile(int $memberId, string $name, ?string $phone, bool $smsConsent, bool $waConsent): void
    {
        Database::getInstance()->getConnection()
            ->prepare('UPDATE members SET name = ?, phone = ?, sms_consent = ?, whatsapp_consent = ? WHERE id = ?')
            ->execute(array(trim($name), self::normalisePhone($phone), $smsConsent ? 1 : 0, $waConsent ? 1 : 0, $memberId));
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Only the hash is ever persisted. */
    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function expiry(int $hours): string
    {
        return date('Y-m-d H:i:s', time() + ($hours * 3600));
    }
}
