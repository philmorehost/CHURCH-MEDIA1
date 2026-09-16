<?php
declare(strict_types=1);

/**
 * Anonymous traffic and interaction analytics.
 *
 * Design notes:
 *  - Raw events are written **at the end of the request** (shutdown hook) so
 *    analytics can never slow a page down or break one. Every failure is
 *    swallowed and only logged.
 *  - Only traffic/interaction that is not already countable elsewhere is
 *    recorded. Giving, newcomers, attendance, likes, saves and comments are read
 *    from their own tables by the dashboard instead — no double counting.
 *  - No IP address is ever stored. The visitor identity is a rotating device hash
 *    from the existing Fingerprint helper.
 *  - Raw events are pruned on a retention window; `analytics_daily` keeps the
 *    long-term history and is rewritten idempotently per day.
 */
final class Analytics
{
    /** Allowed events → the label shown on the dashboard. */
    public const EVENTS = [
        'page_view' => 'Page views',
        'post_view' => 'Reel & post views',
        'sermon_view' => 'Sermon views',
        'event_view' => 'Event views',
        'testimony_view' => 'Testimony views',
        'video_play' => 'Video plays',
        'search' => 'Searches',
        'app_open' => 'App opens',
    ];

    /** tenant_id 0 means "not attributed", which keeps the roll-up key non-null. */
    private const UNATTRIBUTED = 0;

    /** @var array<int, array<string, mixed>> */
    private static array $buffer = [];
    private static bool $hooked = false;

    public static function enabled(): bool
    {
        return (int) setting('analytics_enabled', 1) === 1;
    }

    public static function retentionDays(): int
    {
        return max(7, min(3650, (int) setting('analytics_retention_days', 180)));
    }

    /**
     * Queues an event. Unknown event names are ignored so the table cannot be
     * filled with junk.
     */
    public static function record(string $event, array $data = []): void
    {
        if (!isset(self::EVENTS[$event]) || !self::enabled()) {
            return;
        }

        $unitId = (int) ($data['org_unit_id'] ?? 0);
        $entityId = (int) ($data['entity_id'] ?? 0);
        $country = isset($data['country']) ? strtoupper(substr((string) $data['country'], 0, 2)) : null;
        $session = $data['session_hash'] ?? null;

        self::$buffer[] = [
            'tenant_id' => self::tenant(),
            'occurred_at' => (string) ($data['occurred_at'] ?? date('Y-m-d H:i:s')),
            'event' => $event,
            'path' => self::clip($data['path'] ?? null, 255),
            'org_unit_id' => $unitId > 0 ? $unitId : null,
            'entity_type' => self::clip($data['entity_type'] ?? null, 30),
            'entity_id' => $entityId > 0 ? $entityId : null,
            'device' => ($data['device'] ?? 'web') === 'app' ? 'app' : 'web',
            'session_hash' => self::clip($session, 64),
            'referrer_host' => self::clip($data['referrer_host'] ?? null, 120),
            'country' => ($country !== null && strlen($country) === 2) ? $country : null,
            'meta' => self::clip($data['meta'] ?? null, 255),
        ];

        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /**
     * Records a content view for a slug-based detail page (sermon, event).
     *
     * Done server-side so a view is still counted when JavaScript is off, and so
     * we know exactly which record was opened. Table and column names come from
     * our own code, never from the request.
     */
    public static function recordEntityBySlug(string $entityType, string $table, string $slug, string $slugColumn = 'slug'): void
    {
        try {
            $stmt = self::db()->prepare('SELECT id, org_unit_id FROM `' . $table . '` WHERE `' . $slugColumn . '` = ? LIMIT 1');
            $stmt->execute([$slug]);
            $row = $stmt->fetch();
            if (!$row) {
                return;
            }
            self::record($entityType . '_view', [
                'org_unit_id' => (int) ($row['org_unit_id'] ?? 0),
                'entity_type' => $entityType,
                'entity_id' => (int) $row['id'],
                'path' => rtrim((string) ($_SERVER['REQUEST_URI'] ?? ''), '/'),
            ]);
        } catch (Throwable $e) {
            // Analytics must never break a page render.
        }
    }

    /** Writes everything queued so far. Safe to call more than once. */
    public static function flush(): void
    {
        if (!self::$buffer) {
            return;
        }
        $rows = self::$buffer;
        self::$buffer = [];

        try {
            $pdo = self::db();
            $columns = '(tenant_id, occurred_at, event, path, org_unit_id, entity_type, entity_id, device, session_hash, referrer_host, country, meta)';
            $placeholders = [];
            $values = [];
            foreach ($rows as $row) {
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                foreach (['tenant_id', 'occurred_at', 'event', 'path', 'org_unit_id', 'entity_type', 'entity_id', 'device', 'session_hash', 'referrer_host', 'country', 'meta'] as $key) {
                    $values[] = $row[$key];
                }
            }
            $pdo->prepare('INSERT INTO analytics_events ' . $columns . ' VALUES ' . implode(', ', $placeholders))->execute($values);
        } catch (Throwable $e) {
            error_log('Analytics flush skipped: ' . $e->getMessage());
        }
    }

    /**
     * True for crawlers, link-preview fetchers and uptime monitors. WhatsApp and
     * Telegram previews are excluded on purpose — a shared link should not look
     * like a visit.
     */
    public static function looksLikeBot(?string $userAgent = null, ?string $method = null): bool
    {
        // Only the beacon POST is ever counted. In CLI there is no request method,
        // so this also stops a stray command-line call from recording anything.
        $method = strtoupper((string) ($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
        if ($method !== 'POST') {
            return true;
        }
        $ua = strtolower((string) ($userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
        if (trim($ua) === '') {
            return true;
        }
        foreach (['bot', 'crawl', 'spider', 'slurp', 'fetcher', 'whatsapp', 'telegram', 'curl', 'wget', 'python-requests', 'headless', 'lighthouse', 'monitor', 'preview'] as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** Recomputes one day of the roll-up from scratch, so re-running is safe. */
    public static function rollup(string $day): int
    {
        $day = date('Y-m-d', (int) strtotime($day));
        $pdo = self::db();

        $pdo->prepare('DELETE FROM analytics_daily WHERE day = ?')->execute([$day]);

        $stmt = $pdo->prepare(
            'INSERT INTO analytics_daily (tenant_id, day, event, device, entity_type, entity_id, org_unit_id, hits)
             SELECT tenant_id, ?, event, device,
                    COALESCE(entity_type, \'\'), COALESCE(entity_id, 0), COALESCE(org_unit_id, 0), COUNT(*)
             FROM analytics_events
             WHERE occurred_at >= ? AND occurred_at < ?
             GROUP BY tenant_id, event, device, COALESCE(entity_type, \'\'), COALESCE(entity_id, 0), COALESCE(org_unit_id, 0)
             ON DUPLICATE KEY UPDATE hits = VALUES(hits)'
        );
        $stmt->execute([
            $day,
            $day . ' 00:00:00',
            date('Y-m-d', (int) strtotime($day . ' +1 day')) . ' 00:00:00',
        ]);

        return $stmt->rowCount();
    }

    /** Rolls up any day in the last $days that has no roll-up rows yet. */
    public static function rollupMissingDays(int $days = 7): int
    {
        $days = max(1, min(90, $days));
        $done = 0;
        for ($i = 1; $i <= $days; $i++) {
            $day = date('Y-m-d', (int) strtotime('-' . $i . ' day'));
            $stmt = self::db()->prepare('SELECT COUNT(*) FROM analytics_daily WHERE day = ?');
            $stmt->execute([$day]);
            if ((int) $stmt->fetchColumn() === 0) {
                self::rollup($day);
                $done++;
            }
        }
        return $done;
    }

    /** Deletes raw events past the retention window. The roll-ups are untouched. */
    public static function prune(?int $keepDays = null): int
    {
        $keep = $keepDays ?? self::retentionDays();
        $cutoff = date('Y-m-d 00:00:00', (int) strtotime('-' . $keep . ' day'));
        $stmt = self::db()->prepare('DELETE FROM analytics_events WHERE occurred_at < ?');
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    /* ---------------- dashboard queries ---------------- */

    /** Event counts for a date range, keyed by event name. */
    public static function counts(string $from, string $to, ?array $unitIds = null): array
    {
        [$where, $params] = self::range($from, $to, $unitIds);
        $stmt = self::db()->prepare('SELECT event, COUNT(*) AS n FROM analytics_events WHERE ' . $where . ' GROUP BY event');
        $stmt->execute($params);
        $out = array_fill_keys(array_keys(self::EVENTS), 0);
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['event']] = (int) $row['n'];
        }
        return $out;
    }

    /** Distinct devices — the honest headcount, since page views double-count. */
    public static function visitors(string $from, string $to, ?array $unitIds = null): int
    {
        [$where, $params] = self::range($from, $to, $unitIds);
        $stmt = self::db()->prepare('SELECT COUNT(DISTINCT session_hash) FROM analytics_events WHERE session_hash IS NOT NULL AND ' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Daily counts for one event, zero-filled across the whole range. */
    public static function timeseries(string $from, string $to, string $event = 'page_view', ?array $unitIds = null): array
    {
        [$where, $params] = self::range($from, $to, $unitIds);
        $params[] = $event;
        $stmt = self::db()->prepare(
            'SELECT DATE(occurred_at) AS day, COUNT(*) AS n FROM analytics_events WHERE ' . $where . ' AND event = ? GROUP BY DATE(occurred_at)'
        );
        $stmt->execute($params);

        $found = [];
        foreach ($stmt->fetchAll() as $row) {
            $found[(string) $row['day']] = (int) $row['n'];
        }

        $out = [];
        $cursor = (int) strtotime($from);
        $end = (int) strtotime($to);
        while ($cursor <= $end) {
            $key = date('Y-m-d', $cursor);
            $out[$key] = $found[$key] ?? 0;
            $cursor = (int) strtotime('+1 day', $cursor);
        }
        return $out;
    }

    /** Most-viewed reels, sermons, events or testimonies. */
    public static function topEntities(string $from, string $to, string $entityType, int $limit = 10, ?array $unitIds = null): array
    {
        [$where, $params] = self::range($from, $to, $unitIds);
        // Count the matching view event only, so a video play (which also carries
        // an entity_type) cannot inflate the "most viewed" ranking.
        $params[] = $entityType;
        $params[] = $entityType . '_view';
        $limit = max(1, min(50, $limit));
        $stmt = self::db()->prepare(
            'SELECT entity_id, COUNT(*) AS n FROM analytics_events
             WHERE ' . $where . ' AND entity_type = ? AND event = ? AND entity_id IS NOT NULL
             GROUP BY entity_id ORDER BY n DESC LIMIT ' . $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Most-visited pages (paths), with the site root normalised to "/". */
    public static function topPaths(string $from, string $to, int $limit = 10, ?array $unitIds = null): array
    {
        [$where, $params] = self::range($from, $to, $unitIds);
        $limit = max(1, min(50, $limit));
        $stmt = self::db()->prepare(
            'SELECT COALESCE(path, \'/\') AS path, COUNT(*) AS n FROM analytics_events
             WHERE ' . $where . ' AND event = \'page_view\'
             GROUP BY COALESCE(path, \'/\') ORDER BY n DESC LIMIT ' . $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** What people actually searched for — the clearest content-gap signal. */
    public static function topSearches(string $from, string $to, int $limit = 10, ?array $unitIds = null): array
    {
        [$where, $params] = self::range($from, $to, $unitIds);
        $limit = max(1, min(50, $limit));
        $stmt = self::db()->prepare(
            'SELECT meta AS term, COUNT(*) AS n FROM analytics_events
             WHERE ' . $where . ' AND event = \'search\' AND meta IS NOT NULL AND meta <> \'\'
             GROUP BY meta ORDER BY n DESC LIMIT ' . $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Web vs app, and where visitors come from. */
    public static function deviceSplit(string $from, string $to, ?array $unitIds = null): array
    {
        [$where, $params] = self::range($from, $to, $unitIds);
        $stmt = self::db()->prepare('SELECT device, COUNT(*) AS n FROM analytics_events WHERE ' . $where . ' GROUP BY device');
        $stmt->execute($params);
        $out = ['web' => 0, 'app' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['device'] === 'app' ? 'app' : 'web'] += (int) $row['n'];
        }
        return $out;
    }

    /** Top referrer hosts — how people are finding the church. */
    public static function topReferrers(string $from, string $to, int $limit = 8, ?array $unitIds = null): array
    {
        [$where, $params] = self::range($from, $to, $unitIds);
        $limit = max(1, min(50, $limit));
        $stmt = self::db()->prepare(
            'SELECT referrer_host, COUNT(*) AS n FROM analytics_events
             WHERE ' . $where . ' AND referrer_host IS NOT NULL AND referrer_host <> \'\'
             GROUP BY referrer_host ORDER BY n DESC LIMIT ' . $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Per-church comparison, narrowed to the current tenant. */
    public static function byUnit(string $from, string $to, int $limit = 12): array
    {
        $stmt = self::db()->prepare(
            'SELECT org_unit_id, COUNT(*) AS n
             FROM analytics_events
             WHERE tenant_id = ? AND DATE(occurred_at) BETWEEN ? AND ? AND org_unit_id IS NOT NULL
             GROUP BY org_unit_id ORDER BY n DESC LIMIT ' . max(1, min(50, $limit))
        );
        $stmt->execute([self::tenant(), $from, $to]);
        return $stmt->fetchAll();
    }

    /** Lifetime totals from the roll-up table (survives raw-event pruning). */
    public static function lifetime(?array $unitIds = null): array
    {
        try {
            $sql = 'SELECT event, SUM(hits) AS n FROM analytics_daily WHERE tenant_id = ?';
            $params = [self::tenant()];
            if ($unitIds !== null) {
                if (!$unitIds) {
                    return array_fill_keys(array_keys(self::EVENTS), 0);
                }
                $sql .= ' AND org_unit_id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')';
                foreach ($unitIds as $id) {
                    $params[] = (int) $id;
                }
            }
            $sql .= ' GROUP BY event';
            $stmt = self::db()->prepare($sql);
            $stmt->execute($params);
            $out = array_fill_keys(array_keys(self::EVENTS), 0);
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) $row['event']] = (int) $row['n'];
            }
            return $out;
        } catch (Throwable $e) {
            return array_fill_keys(array_keys(self::EVENTS), 0);
        }
    }

    /** The first day with recorded data, so charts can start somewhere sensible. */
    public static function firstDay(): ?string
    {
        try {
            $value = self::db()->query('SELECT MIN(occurred_at) FROM analytics_events')->fetchColumn();
            return $value ? substr((string) $value, 0, 10) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /* ---------------- internals ---------------- */

    /**
     * Builds a WHERE fragment plus params for the current tenant, a date range
     * and an optional unit scope. Tenant scoping lives here so every dashboard
     * query is isolated in one place — one church must never see another's traffic.
     */
    private static function range(string $from, string $to, ?array $unitIds = null): array
    {
        $from = self::clampDate($from, '-30 day');
        $to = self::clampDate($to, 'today');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $where = 'tenant_id = ? AND occurred_at >= ? AND occurred_at < ?';
        $params = [self::tenant(), $from . ' 00:00:00', date('Y-m-d', (int) strtotime($to . ' +1 day')) . ' 00:00:00'];

        if ($unitIds !== null) {
            if (!$unitIds) {
                // A scoped user with no units must see nothing, not everything.
                $where .= ' AND 1 = 0';
            } else {
                $where .= ' AND org_unit_id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')';
                foreach ($unitIds as $id) {
                    $params[] = (int) $id;
                }
            }
        }

        return [$where, $params];
    }

    private static function clampDate(string $value, string $fallback): string
    {
        $ts = strtotime(trim($value));
        return $ts === false ? date('Y-m-d', (int) strtotime($fallback)) : date('Y-m-d', $ts);
    }

    private static function clip($value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function tenant(): int
    {
        $id = class_exists('Tenant') ? Tenant::id() : null;
        return $id ?? self::UNATTRIBUTED;
    }

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }
}
