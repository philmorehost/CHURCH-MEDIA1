<?php
declare(strict_types=1);

/**
 * The interface language.
 *
 * ⚠️ **This class is `Lang`, not `Locale`, on purpose.** `ext-intl` defines a class called `Locale`, and
 * declaring our own would be a fatal error on any host with intl enabled — which is most managed hosting
 * and none of the machines this was developed on. A name that collides with an extension is a bug that
 * appears only in production, so it is avoided rather than discovered.
 *
 * Three decisions, each of which the rest of this file follows from:
 *
 * 1. **A catalogue is a PHP file in `lang/`, not a database table and not gettext.** A church's interface
 *    language is a property of the *code* it runs, not of its data, so it belongs in the repository
 *    beside the views it translates and it deploys with them. `.po`/gettext would additionally need the
 *    `.mo` compiled on the host and the locale installed at OS level — the step that fails on cheap
 *    shared hosting and silently serves English. Deriving the available list from `lang/*.php` also means
 *    **adding a language is adding a file**, with no list anywhere to update.
 *
 * 2. **A partial catalogue is never wrong, only English.** Resolution is requested → English → the key
 *    itself. That is what makes it safe to translate the shell first and the rest later, and safe for a
 *    volunteer to translate thirty strings and stop.
 *
 * 3. **The visitor's choice outranks the church's.** `default_locale` is the language for people who have
 *    not chosen; it is not an override of somebody who has. A visitor who picked Yorùbá keeps it while
 *    browsing a church that defaults to English, which is the entire point of having a switcher.
 *
 * The language lives in a **cookie**, not the session. It has to survive signing out and it has to work
 * for a visitor who never signs in — a session does neither. It is a preference, not a credential, so
 * nothing in it is trusted: the value is checked against the catalogues on disk on every read, which is
 * why a forged cookie can only ever select a language that exists.
 */
final class Lang
{
    /** Where `xx.php` catalogues live. */
    private const DIR = LANG_PATH;

    /** The language every other catalogue falls back to. */
    public const FALLBACK = 'en';

    /** A year, so a language choice is not asked for again on the next visit. */
    private const COOKIE_DAYS = 365;

    /** @var array<string, string>|null code => the language's own name */
    private static ?array $available = null;

    private static ?string $current = null;

    /** @var array<string, array<string, mixed>> catalogue files exactly as written, metadata included */
    private static array $files = [];

    /** @var array<string, array<string, string>> just the translatable strings of each catalogue */
    private static array $catalogue = [];

    /** The cookie the visitor's choice is kept in. */
    public static function cookieName(): string
    {
        return 'lang';
    }

    /** True for a plausible catalogue code: `en`, `yo`, or `pt-br`. */
    private static function isCode(string $code): bool
    {
        return preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/', $code) === 1;
    }

    /**
     * Every locale with a catalogue on disk, keyed by code, English first.
     *
     * Read from the directory rather than declared in a list, so the two cannot disagree: a language that
     * is not on disk is not offered, and a file that is on disk is offered without an edit anywhere else.
     *
     * @return array<string, string>
     */
    public static function available(): array
    {
        if (self::$available !== null) {
            return self::$available;
        }

        $found = [];
        foreach (glob(self::DIR . '/*.php') ?: [] as $file) {
            $code = strtolower(basename($file, '.php'));
            if (!self::isCode($code)) {
                continue;
            }
            $name = (string) self::meta($code, '__name', '');
            $found[$code] = $name !== '' ? $name : strtoupper($code);
        }

        if (!isset($found[self::FALLBACK])) {
            $found[self::FALLBACK] = 'English';
        }

        // English keeps the first slot whatever else is added, so a switcher does not reorder itself as
        // languages come and go.
        $english = $found[self::FALLBACK];
        unset($found[self::FALLBACK]);
        ksort($found);

        return self::$available = [self::FALLBACK => $english] + $found;
    }

    /**
     * The languages a visitor may switch to.
     *
     * A catalogue says `'__offered' => false` while it is still being written, which keeps it out of the
     * footer switcher without hiding it from the church's settings screen and without a second list of
     * languages existing anywhere. A translation can therefore be added, looked at and corrected in
     * public, and the day it is ready the only change is the file saying so.
     *
     * A value that is not `false` counts as offered — including a mistyped `'no'`, because a typo must not
     * silently remove a language a church is depending on. `cli/lang_check.php` reports the typo instead.
     *
     * @return array<string, string>
     */
    public static function offered(): array
    {
        return array_filter(
            self::available(),
            static fn (string $name, string $code): bool => self::meta($code, '__offered', true) !== false,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * One catalogue file, exactly as it was written — metadata (`__name`, `__offered`) included.
     *
     * @return array<string, mixed>
     */
    public static function file(string $code): array
    {
        $code = strtolower(trim($code));

        if (array_key_exists($code, self::$files)) {
            return self::$files[$code];
        }

        $data = [];
        $path = self::DIR . '/' . $code . '.php';

        // `$code` is validated to a strict shape before it is used as a path, and the file is ours.
        if (self::isCode($code) && is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                $data = $loaded;
            }
        }

        return self::$files[$code] = $data;
    }

    /**
     * A metadata value from a catalogue: `__name`, `__offered`.
     *
     * Keys beginning with `__` describe the file. They are not text to be shown, which is why they are
     * kept out of the string map and out of every comparison `cli/lang_check.php` makes.
     *
     * No parameter or return types on purpose: `__offered` is a boolean and `__name` is a string, so the
     * honest signature has no single type — and `mixed`, which would say that, is PHP 8.0 and this code
     * runs on the 7.4 floor.
     */
    public static function meta(string $code, string $key, $default = null)
    {
        return self::file($code)[$key] ?? $default;
    }

    /**
     * The translatable strings of a catalogue — everything that is not `__` metadata.
     *
     * Empty values are dropped rather than kept: a key mapped to `''` renders as nothing at all, which is
     * the one outcome worth ruling out. Dropping it here means the English fallback answers instead.
     *
     * @return array<string, string>
     */
    public static function catalogue(string $code): array
    {
        $code = strtolower(trim($code));

        if (isset(self::$catalogue[$code])) {
            return self::$catalogue[$code];
        }

        $strings = [];
        foreach (self::file($code) as $key => $value) {
            if (!is_string($key) || str_starts_with($key, '__')) {
                continue;
            }
            if (is_string($value) && trim($value) !== '') {
                $strings[$key] = $value;
            }
        }

        return self::$catalogue[$code] = $strings;
    }

    /** The language being served: the visitor's choice, else the church's default, else English. */
    public static function current(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $available = self::available();

        $chosen = self::code($_COOKIE[self::cookieName()] ?? null);
        if ($chosen !== null && isset($available[$chosen])) {
            return self::$current = $chosen;
        }

        // `setting()` resolves config → the shared row → this church's own row, so a church that has never
        // chosen a language gets the config default.
        $church = self::code(setting('default_locale'));
        if ($church !== null && isset($available[$church])) {
            return self::$current = $church;
        }

        return self::$current = self::FALLBACK;
    }

    /** Normalises a candidate code, or null when it is not one. Takes anything: a cookie is input. */
    private static function code($value): ?string
    {
        $code = strtolower(trim((string) $value));

        return $code !== '' && self::isCode($code) ? $code : null;
    }

    /** True when the visitor is reading the source language, so nothing is being translated. */
    public static function isFallback(): bool
    {
        return self::current() === self::FALLBACK;
    }

    /**
     * Looks a key up in the language being served.
     *
     * Returns the key itself when no catalogue has it — never an empty string, because an empty label is
     * invisible (see the note in `core/helpers.php`'s `t()`).
     *
     * @param array<string, string> $vars values for `:placeholder` tokens
     */
    public static function translate(string $key, array $vars = []): string
    {
        $current = self::current();

        $text = self::catalogue($current)[$key] ?? null;

        if ($text === null && $current !== self::FALLBACK) {
            $text = self::catalogue(self::FALLBACK)[$key] ?? null;
        }

        if ($text === null) {
            return $key;
        }

        // `strtr` rather than `str_replace` so that a placeholder like `:name` cannot be replaced twice,
        // and rather than `sprintf` so that a bare `%` in a church's own text is not a format specifier.
        return $vars === [] ? $text : strtr($text, $vars);
    }

    /**
     * Records the visitor's choice.
     *
     * Refuses a code with no catalogue, so this can never write a preference that `current()` would then
     * have to ignore. The `$_COOKIE` write is what makes the choice take effect on the *same* request, for
     * callers that do not redirect.
     */
    public static function remember(string $code): bool
    {
        $code = strtolower(trim($code));

        if (!isset(self::available()[$code])) {
            return false;
        }

        self::$current = $code;
        $_COOKIE[self::cookieName()] = $code;

        if (!headers_sent()) {
            setcookie(self::cookieName(), $code, [
                'expires' => time() + (self::COOKIE_DAYS * 86400),
                'path' => '/',
                // A preference is not a credential, and no script on the page needs to read it: the
                // switcher is server-rendered. HttpOnly costs nothing here.
                'httponly' => true,
                // Set only over HTTPS, matching how `baseUrl()` decides the scheme — a secure cookie sent
                // over plain HTTP is dropped by the browser, which would make the switcher look broken
                // on a site that has not got its certificate yet.
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'samesite' => 'Lax',
            ]);
        }

        return true;
    }

    /**
     * The address to return to after a language switch, reduced to something a redirect cannot abuse.
     *
     * Only a same-site absolute path survives. `//evil.test` and `https://evil.test` are both rejected —
     * a switcher that can be pointed anywhere is an open redirect that lends this church's domain to
     * somebody else's link, and it is the classic way a "remember where I was" feature is turned into a
     * phishing hop. Query strings are kept, because a page's own filters live there; anything with a
     * CR/LF in it is dropped, because a redirect target is a response header.
     */
    public static function safeNext($next): string
    {
        $next = trim((string) $next);

        if ($next === '' || $next[0] !== '/') {
            return '/';
        }
        if (str_starts_with($next, '//') || str_starts_with($next, '/\\')) {
            return '/';
        }
        if (preg_match('/[\r\n]/', $next) === 1) {
            return '/';
        }

        return $next;
    }

    /** Drops every cached value. For a harness that switches language, and for a settings save. */
    public static function forget(): void
    {
        self::$current = null;
        self::$available = null;
        self::$files = [];
        self::$catalogue = [];
    }
}
