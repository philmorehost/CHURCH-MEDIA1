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

    /**
     * Enforces a limit for a page route, stopping the request with a styled 429 when
     * the visitor has been too busy.
     *
     * This is the page-side counterpart to the `if (!attempt(...))` checks the JSON
     * endpoints do — a page cannot answer with a JSON error body.
     */
    public static function require(string $action, int $limit, int $windowSeconds, ?string $key = null): void
    {
        if (self::attempt($action, $key ?? self::subject(), $limit, $windowSeconds)) {
            return;
        }
        self::deny($windowSeconds);
    }

    /** The visitor a page-level limit is counted against. */
    private static function subject(): string
    {
        if (class_exists('Fingerprint')) {
            return Fingerprint::hash();
        }
        return function_exists('clientIp') ? clientIp() : 'anonymous';
    }

    /** Ends the request with a 429, rendering the themed page when it is available. */
    private static function deny(int $retryAfter): void
    {
        if (!headers_sent()) {
            http_response_code(429);
            header('Retry-After: ' . max(1, $retryAfter));
            header('Content-Type: text/html; charset=utf-8');
        }

        // In an API or CLI context there is no layout to render into, so keep a
        // dependency-free fallback rather than risking a second failure.
        if (function_exists('render') && defined('VIEWS_PATH') && is_file(VIEWS_PATH . '/429.php')) {
            render('429', ['metaTitle' => 'Please slow down']);
        } else {
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>Please slow down</title></head>'
                . '<body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#0a0912;color:#fff;padding:64px 24px;text-align:center;">'
                . '<h1 style="font-size:24px;margin:0 0 12px;">Please slow down</h1>'
                . '<p style="color:#c9c4de;max-width:420px;margin:0 auto 24px;">You have made a lot of requests in a short time. '
                . 'Please wait a minute and try again.</p>'
                . '<p><a href="/" style="color:#e8b95f;text-decoration:none;">Back to the home page</a></p>'
                . '</body></html>';
        }
        exit;
    }
}
