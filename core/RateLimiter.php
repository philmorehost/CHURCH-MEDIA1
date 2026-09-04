<?php
declare(strict_types=1);

/**
 * File-based sliding-window rate limiter for anonymous public endpoints
 * (likes, views, prayer requests, newsletter signup, search). Avoids a DB
 * write on every hit — counters live in storage/cache/ratelimit as small
 * JSON files keyed by action+fingerprint.
 */
class RateLimiter
{
    private static function dir(): string
    {
        $dir = STORAGE_PATH . '/cache/ratelimit';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** Returns true if the hit is allowed (and records it); false if the caller should be throttled. */
    public static function attempt(string $action, string $key, int $limit, int $windowSeconds): bool
    {
        $file = self::dir() . '/' . hash('sha256', $action . ':' . $key) . '.json';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return true; // fail open rather than blocking legitimate traffic on disk errors
        }

        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $hits = $raw ? (json_decode($raw, true) ?: []) : [];
        $cutoff = time() - $windowSeconds;
        $hits = array_values(array_filter($hits, fn ($t) => $t > $cutoff));

        $allowed = count($hits) < $limit;
        if ($allowed) {
            $hits[] = time();
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($hits));
        flock($handle, LOCK_UN);
        fclose($handle);

        return $allowed;
    }

    /** Convenience wrapper reading from config/security.php's `rate_limits` map. */
    public static function attemptConfigured(string $action, string $key): bool
    {
        $config = require CONFIG_PATH . '/security.php';
        $rule = $config['rate_limits'][$action] ?? ['limit' => 30, 'window' => 60];
        return self::attempt($action, $key, $rule['limit'], $rule['window']);
    }
}
