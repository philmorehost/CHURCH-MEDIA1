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

    /**
     * A church forced for the duration of one `runAs()` block, or null when the block forces none.
     *
     * Deliberately separate from the session switcher: this is a background worker saying "act as
     * this church for the next few statements", and it must never be written to a session or trusted
     * across requests.
     */
    private static ?int $override = null;

    /**
     * True while a `runAs()` block is in force — including when it forces **no** church.
     *
     * The flag matters because `runAs(0, …)` is a real case: it is what a worker gets for a row that
     * belongs to no church (`tenant_id = 0`). Without the flag that would silently fall through to the
     * ambient church — which in a cron is the default one — and the row would be sent under a church it
     * does not belong to. That is the exact fault this whole stage exists to remove.
     */
    private static bool $overrideActive = false;

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

    /**
     * Runs `$work` as one named church, then puts the previous one back.
     *
     * This is how a background worker acts as one church at a time. It is deliberately **not**
     * `setCurrent()`: that is a user action — it writes the session, and later requests trust it only
     * because the caller authorised it — and a cron must never touch a session. The override lives for
     * the length of the callable and is restored in a `finally`, so an exception inside `$work` cannot
     * leave the process acting as the wrong church. Nested calls unwind in order.
     *
     * The override takes precedence over the session switcher and the host, because the caller is
     * stating which church this block of work is about. In a web request it is used at most to re-state
     * the church already being served — see `each()` — so a super admin who has switched church is
     * never overridden behind their back.
     *
     * @param callable $work
     * @return mixed whatever $work returned
     */
    public static function runAs(int $tenantId, callable $work)
    {
        $hadOverride = self::$overrideActive;
        $previous = self::$override;

        self::$overrideActive = true;
        self::$override = $tenantId > 0 ? $tenantId : null;
        self::forget();
        try {
            return $work($tenantId, self::current());
        } finally {
            self::$overrideActive = $hadOverride;
            self::$override = $previous;
            self::forget();
        }
    }

    /**
     * Runs `$work` once per church, passing `(int $tenantId, array $tenant)`.
     *
     * For the background workers, and it is the only correct answer to "which churches does this run
     * cover?":
     *
     *  - From a **cron** there is no host to resolve from, so one pass per active church.
     *  - From a **web request** — an admin clicking a "send now" button — just the church being served,
     *    because a screen must never act on churches the person looking at it cannot see.
     *
     * On a single-church install both cases are one pass as that church, so converting a worker to this
     * changes nothing there. The pass is wrapped in `runAs()`, so the restore is owned here and no
     * caller can forget it.
     *
     * @param callable $work
     * @return array<int, array{ok:bool,result:mixed,error:?string}> one entry per church, keyed by id
     */
    public static function each(callable $work): array
    {
        $results = [];

        if (PHP_SAPI !== 'cli') {
            $serving = self::id();
            if ($serving !== null) {
                $results[$serving] = self::pass($serving, $work);
            }
            return $results;
        }

        foreach (self::activeTenants() as $tenant) {
            $results[(int) $tenant['id']] = self::pass((int) $tenant['id'], $work);
        }
        return $results;
    }

    /**
     * One church's pass, with its failure held rather than thrown.
     *
     * A cron that stops at the first church with a problem stops being a schedule and becomes a
     * complaint: the other churches' reminders would simply not go out, and nothing would say so. So the
     * exception is logged, returned in the entry for that church, and the run carries on to the next.
     * The caller decides what a failure means for its exit code — this only guarantees that the failure
     * is visible and that nobody else is skipped.
     *
     * @param callable $work
     * @return array{ok:bool,result:mixed,error:?string}
     */
    private static function pass(int $tenantId, callable $work): array
    {
        try {
            return ['ok' => true, 'result' => self::runAs($tenantId, $work), 'error' => null];
        } catch (Throwable $e) {
            error_log('Tenant::each pass failed for church ' . $tenantId . ': ' . $e->getMessage());
            return ['ok' => false, 'result' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Every active church, lowest id first, as plain rows.
     *
     * Reads the table directly rather than going through `all()`/`forget()` so a run being made as one
     * church cannot change which churches are left to visit.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function activeTenants(): array
    {
        try {
            return self::db()->query('SELECT * FROM tenants WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
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

        // 0. A background worker acting as a named church (see runAs()). This comes first because it is
        //    the caller stating the subject of the work, and a cron has neither a session nor a host.
        if (self::$overrideActive) {
            if (self::$override !== null) {
                try {
                    $forced = self::find(self::$override);
                    if ($forced && (int) $forced['is_active'] === 1) {
                        self::$current = $forced;
                        return;
                    }
                } catch (Throwable $e) {
                    error_log('Tenant runAs failed: ' . $e->getMessage());
                }
            }
            // A church that has gone away, been deactivated, or was never named (0) leaves the run with
            // no church at all, rather than silently acting as a different one.
            self::$current = null;
            return;
        }

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
