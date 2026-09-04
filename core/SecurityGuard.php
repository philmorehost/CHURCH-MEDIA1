<?php
declare(strict_types=1);

/**
 * cPHulk/Imunify360-style brute-force & access-control engine for the admin
 * login surface: per-IP and per-username failed-attempt thresholds,
 * immediate blocks on non-existent usernames, IP/country allow-deny lists,
 * and auto-whitelisting ("king icon") after repeated successful sessions.
 */
class SecurityGuard
{
    private PDO $pdo;
    private array $config;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->config = require CONFIG_PATH . '/security.php';
    }

    /** Call once per request (site-wide) to enforce country/IP blocks before anything else runs. */
    public function inspectRequest(string $ipAddress, ?string $countryCode): void
    {
        if ($countryCode) {
            $stmt = $this->pdo->prepare('SELECT status FROM country_rules WHERE country_code = ?');
            $stmt->execute([$countryCode]);
            $status = $stmt->fetchColumn();
            if ($status === 'blacklisted') {
                $this->deny('Access denied: your location is restricted.');
            }
        }

        $stmt = $this->pdo->prepare('SELECT type, expires_at FROM ip_rules WHERE ip_address = ?');
        $stmt->execute([$ipAddress]);
        $rule = $stmt->fetch();

        if ($rule && $rule['type'] === 'blacklist') {
            if (!$rule['expires_at'] || strtotime((string) $rule['expires_at']) > time()) {
                $this->deny('Access denied: this IP address has been blocked due to security violations.');
            }
        }
    }

    private function deny(string $message): never
    {
        http_response_code(403);
        exit($message);
    }

    /** Best-effort country lookup — works behind Cloudflare or with the geoip PECL extension; null otherwise (check simply no-ops). */
    public static function resolveCountryCode(): ?string
    {
        if (!empty($_SERVER['HTTP_CF_IPCOUNTRY']) && $_SERVER['HTTP_CF_IPCOUNTRY'] !== 'XX') {
            return strtoupper($_SERVER['HTTP_CF_IPCOUNTRY']);
        }
        if (function_exists('geoip_country_code_by_name')) {
            $code = @geoip_country_code_by_name(clientIp());
            return $code ?: null;
        }
        return null;
    }

    public function handleFailedLogin(string $ipAddress, string $username): void
    {
        $this->log($ipAddress, $username, 'failed_login');

        $userStmt = $this->pdo->prepare('SELECT id FROM users WHERE username = ?');
        $userStmt->execute([$username]);
        if (!$userStmt->fetchColumn()) {
            $this->blockIp($ipAddress, 'Immediate block: targeted non-existent user account "' . $username . '"', $this->config['unknown_user_block_minutes']);
            return;
        }

        $userFailures = $this->countRecent('username_attempted', $username, $this->config['user_protection_period']);
        if ($userFailures >= $this->config['max_user_failures']) {
            $this->pdo->prepare('UPDATE users SET is_suspended = 1 WHERE username = ?')->execute([$username]);
            Mailer::sendSecurityAlert(
                'Account Suspended: ' . $username,
                "The account \"$username\" was suspended after $userFailures failed login attempts from IP $ipAddress."
            );
        }

        $ipFailures = $this->countRecent('ip_address', $ipAddress, $this->config['ip_protection_period']);
        if ($ipFailures >= $this->config['max_ip_failures']) {
            $this->blockIp($ipAddress, "Brute-force limit reached ($ipFailures failures)", $this->config['ip_block_duration_minutes']);
        }
    }

    public function handleSuccessfulLogin(array $user, string $ipAddress): void
    {
        $this->log($ipAddress, $user['username'], 'successful_login');

        $stmt = $this->pdo->prepare('
            INSERT INTO ip_rules (ip_address, type, is_auto_whitelisted, successful_session_count)
            VALUES (?, "whitelist", 0, 1)
            ON DUPLICATE KEY UPDATE
                successful_session_count = successful_session_count + 1,
                is_auto_whitelisted = IF(successful_session_count + 1 >= ?, 1, is_auto_whitelisted)
        ');
        $stmt->execute([$ipAddress, $this->config['auto_whitelist_after_sessions']]);

        $ruleStmt = $this->pdo->prepare('SELECT type FROM ip_rules WHERE ip_address = ?');
        $ruleStmt->execute([$ipAddress]);
        $rule = $ruleStmt->fetch();

        if ((!$rule || $rule['type'] !== 'whitelist') && !empty($user['notify_on_login'])) {
            Mailer::sendSecurityAlert(
                'New Admin Login — ' . $user['name'],
                "A successful admin login was detected from a non-whitelisted IP: $ipAddress"
            );
        }

        $this->pdo->prepare('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?')
            ->execute([$ipAddress, $user['id']]);
    }

    private function log(string $ip, string $username, string $eventType): void
    {
        $this->pdo->prepare('INSERT INTO security_logs (ip_address, username_attempted, event_type, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$ip, $username, $eventType]);
    }

    private function countRecent(string $column, string $value, int $minutes): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM security_logs
            WHERE $column = ? AND event_type = 'failed_login'
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$value, $minutes]);
        return (int) $stmt->fetchColumn();
    }

    private function blockIp(string $ipAddress, string $reason, int $durationMinutes): void
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+$durationMinutes minutes"));
        $stmt = $this->pdo->prepare('
            INSERT INTO ip_rules (ip_address, type, reason, expires_at)
            VALUES (?, "blacklist", ?, ?)
            ON DUPLICATE KEY UPDATE type = "blacklist", reason = ?, expires_at = ?, is_auto_whitelisted = 0
        ');
        $stmt->execute([$ipAddress, $reason, $expiresAt, $reason, $expiresAt]);
    }
}
