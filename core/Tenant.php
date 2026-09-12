<?php
declare(strict_types=1);

/**
 * SaaS tenants.
 *
 * A tenant is one church organisation. The installer — and the
 * `2026_10_tenants` migration on an existing site — seeds a single default
 * tenant, so a single-church install behaves exactly as it always has.
 *
 * Rules of the road:
 *  - Tables added from the SaaS work onward carry `tenant_id`. The older content
 *    tables are still single-tenant and get migrated in the Phase 7 rollout.
 *  - `settings.tenant_id = NULL` means "shared defaults for every tenant"; a row
 *    carrying a tenant_id overrides only the values that differ for that tenant.
 *  - Resolution order: super-admin switcher → matching domain/subdomain → the
 *    seeded default tenant.
 */
final class Tenant
{
    private const SESSION_KEY = 'tenant_id';
    private const SESSION_ALLOWED = 'tenant_switch_allowed';

    private static ?array $current = null;
    private static bool $resolved = false;
    private static ?array $allCache = null;

    /** The tenant serving this request, or null before install / on failure. */
    public static function current(): ?array
    {
        if (!self::$resolved) {
            self::resolve();
        }
        return self::$current;
    }

    public static function id(): ?int
    {
        $tenant = self::current();
        return $tenant !== null ? (int) $tenant['id'] : null;
    }

    public static function name(): string
    {
        $tenant = self::current();
        return $tenant !== null ? (string) $tenant['name'] : '';
    }

    public static function isResolved(): bool
    {
        return self::$resolved;
    }

    /** Drops the cached tenant so the next call re-resolves. */
    public static function forget(): void
    {
        self::$resolved = false;
        self::$current = null;
        self::$allCache = null;
    }

    /** Every tenant: default first, then alphabetical. */
    public static function all(): array
    {
        if (self::$allCache !== null) {
            return self::$allCache;
        }
        try {
            return self::$allCache = self::db()
                ->query('SELECT * FROM tenants ORDER BY is_default DESC, name ASC')
                ->fetchAll();
        } catch (Throwable $e) {
            return self::$allCache = [];
        }
    }

    public static function find(int $id): ?array
    {
        try {
            $stmt = self::db()->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Matches a request host against `domain`, then against `subdomain`. */
    public static function forHost(string $host): ?array
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }
        // Strip a port so "yaya.example.org:8443" still matches its domain.
        $bare = (string) preg_replace('/:\d+$/', '', $host);
        $label = explode('.', $bare)[0] ?? '';

        try {
            $stmt = self::db()->prepare('SELECT * FROM tenants WHERE is_active = 1 AND domain IS NOT NULL AND LOWER(domain) IN (?, ?) LIMIT 1');
            $stmt->execute([$host, $bare]);
            $match = $stmt->fetch();
            if ($match) {
                return $match;
            }
            if ($label !== '' && $label !== 'www') {
                $stmt = self::db()->prepare('SELECT * FROM tenants WHERE is_active = 1 AND subdomain IS NOT NULL AND LOWER(subdomain) = ? LIMIT 1');
                $stmt->execute([$label]);
                $match = $stmt->fetch();
                if ($match) {
                    return $match;
                }
            }
        } catch (Throwable $e) {
            // Tenant tables not migrated yet — fall through to the default.
        }
        return null;
    }

    /** Onboards a church. Returns ['id'=>..,'slug'=>..] or ['errors'=>[..]]. */
    public static function create(string $name, array $extra = []): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['errors' => ['Please provide a church name.']];
        }
        $slug = self::slugFor((string) ($extra['slug'] ?? $name));
        if ($slug === '') {
            return ['errors' => ['Please use at least one letter or number in the name.']];
        }

        try {
            $pdo = self::db();
            $check = $pdo->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
            $check->execute([$slug]);
            if ($check->fetch()) {
                return ['errors' => ['That web address is already taken — please choose another.']];
            }

            $pdo->prepare('INSERT INTO tenants (name, slug, domain, subdomain, plan, is_active) VALUES (?, ?, ?, ?, ?, 1)')
                ->execute([
                    $name,
                    $slug,
                    self::normaliseHost($extra['domain'] ?? null),
                    self::slugFor((string) ($extra['subdomain'] ?? '')) ?: null,
                    (string) ($extra['plan'] ?? 'standard'),
                ]);

            self::forget();
            return ['id' => (int) $pdo->lastInsertId(), 'slug' => $slug];
        } catch (Throwable $e) {
            return ['errors' => ['Could not create the church: ' . $e->getMessage()]];
        }
    }

    /**
     * Switches the super admin's active tenant for this session.
     *
     * The caller must already have verified the user is a super admin; the
     * `allowed` flag is what lets resolve() trust the session afterwards without
     * running an authorisation query on every single request.
     */
    public static function setCurrent(?int $id, bool $allowed = false): void
    {
        if (!$allowed) {
            return;
        }
        if ($id === null) {
            unset($_SESSION[self::SESSION_KEY]);
        } else {
            $_SESSION[self::SESSION_KEY] = $id;
        }
        $_SESSION[self::SESSION_ALLOWED] = true;
        self::forget();
    }

    private static function resolve(): void
    {
        self::$resolved = true;
        if (!defined('APP_IS_INSTALLED') || !APP_IS_INSTALLED) {
            return;
        }
        try {
            // 1. Super-admin switcher — only trusted when setCurrent() set the flag.
            if (!empty($_SESSION[self::SESSION_ALLOWED]) && !empty($_SESSION[self::SESSION_KEY])) {
                $chosen = self::find((int) $_SESSION[self::SESSION_KEY]);
                if ($chosen && (int) $chosen['is_active'] === 1) {
                    self::$current = $chosen;
                    return;
                }
            }

            // 2. A host that maps to a tenant.
            if (PHP_SAPI !== 'cli') {
                $byHost = self::forHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
                if ($byHost) {
                    self::$current = $byHost;
                    return;
                }
            }

            // 3. The seeded default, falling back to the lowest id.
            $row = self::db()->query('SELECT * FROM tenants WHERE is_default = 1 AND is_active = 1 ORDER BY id ASC LIMIT 1')->fetch();
            if (!$row) {
                $row = self::db()->query('SELECT * FROM tenants WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetch();
            }
            self::$current = $row ?: null;
        } catch (Throwable $e) {
            error_log('Tenant resolve failed: ' . $e->getMessage());
            self::$current = null;
        }
    }

    /** Stable URL-safe key, e.g. "RCCG LP63 YAYA" → "rccg-lp63-yaya". */
    private static function slugFor(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value))), '-');
    }

    /** Normalises a full hostname, rejecting anything without a dot. */
    private static function normaliseHost($value): ?string
    {
        $value = strtolower(trim((string) $value));
        $value = (string) preg_replace('#^https?://#', '', $value);
        $value = (string) preg_replace('/:\d+$/', '', $value);
        $value = rtrim($value, '/');
        return ($value === '' || !str_contains($value, '.')) ? null : $value;
    }

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }
}
