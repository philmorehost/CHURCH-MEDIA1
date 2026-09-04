<?php
declare(strict_types=1);

/** Per-session CSRF token, used by every state-changing admin form. */
class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }

    public static function requireValid(): void
    {
        if (!self::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Session expired — please go back and try again.');
        }
    }
}
