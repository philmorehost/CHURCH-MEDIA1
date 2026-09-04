<?php
declare(strict_types=1);

/**
 * Anonymous visitor fingerprint used to dedupe likes/views and key rate
 * limits without accounts or cookies containing PII. Salted so it can't be
 * reversed to an IP/UA pair outside this app.
 */
class Fingerprint
{
    public static function hash(): string
    {
        $config = require CONFIG_PATH . '/security.php';
        $raw = clientIp() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . $config['fingerprint_salt'];
        return hash('sha256', $raw);
    }
}
