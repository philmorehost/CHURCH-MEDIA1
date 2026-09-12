<?php
declare(strict_types=1);

/**
 * Sermon series — a named run of sermons, and the thing a podcast feed is built around.
 *
 * A series used to be free text on the sermon. That was enough for a filter chip and nothing
 * else: no description, no artwork, no episode ordering, and renaming it meant editing every
 * sermon by hand. It is a table now.
 *
 * Two rules this class exists to hold in one place, because getting either wrong is invisible
 * until someone complains:
 *
 *   1. **`sermons.series` and `sermons.series_id` must never disagree.** The old text column is
 *      still read by the API and the mobile app, so whenever a sermon is attached to a series
 *      the title is written into the text column too. `syncLegacyTitle()` is the only place
 *      that happens.
 *
 *   2. **A sermon's episode number is unique inside its series.** Two episodes numbered 3 is
 *      not a cosmetic problem — it is a podcast feed that lists the same episode twice.
 *      `nextPosition()` hands out the first free number rather than counting rows, because
 *      counting breaks the moment an episode in the middle is deleted.
 *
 * Deleting a series keeps its sermons and detaches them (see `delete()`), rather than cascading
 * the sermons away. Losing a month of messages because someone tidied up a series name would be
 * unforgivable, and the admin is told exactly how many sermons will be detached before it happens.
 */
final class Series
{
    public const MAX_TITLE = 150;

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }

    private static function tenantId(): ?int
    {
        return class_exists('Tenant') ? Tenant::id() : null;
    }

    /* ------------------------------------------------------------------ reads */

    /**
     * Series visible to an admin, newest first, each with how many sermons it holds.
     *
     * @param  array<int, mixed> $scopeUnitIds  Empty means "no unit restriction".
     * @return array<int, array<string, mixed>>
     */
    public static function all(array $scopeUnitIds = [], bool $publishedOnly = false): array
    {
        $params = [];
        $clauses = [];

        $tenantId = self::tenantId();
        if ($tenantId !== null) {
            $clauses[] = '(s.tenant_id = ? OR s.tenant_id IS NULL)';
            $params[] = $tenantId;
        }

        // A series with no unit is shared, the same convention the rest of the system uses.
        $ids = self::cleanIds($scopeUnitIds);
        if ($ids !== []) {
            $clauses[] = '(s.org_unit_id IS NULL OR s.org_unit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
            foreach ($ids as $id) {
                $params[] = $id;
            }
        }

        if ($publishedOnly) {
            $clauses[] = 's.is_published = 1';
        }

        $where = $clauses === [] ? '' : (' WHERE ' . implode(' AND ', $clauses));

        try {
            $stmt = self::db()->prepare(
                'SELECT s.*,
                        (SELECT COUNT(*) FROM sermons sm WHERE sm.series_id = s.id AND sm.is_published = 1) AS sermon_count,
                        (SELECT MAX(sm.published_at) FROM sermons sm WHERE sm.series_id = s.id AND sm.is_published = 1) AS latest_at
                 FROM sermon_series s' . $where . '
                 ORDER BY s.sort_order ASC, s.title ASC'
            );
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = self::db()->prepare('SELECT * FROM sermon_series WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Looks a series up by its public slug. */
    public static function findBySlug(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }
        try {
            $stmt = self::db()->prepare('SELECT * FROM sermon_series WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * The sermons in a series, in episode order.
     *
     * Ordered by `series_position` first so the feed reads as a series should, with the unnumbered
     * ones falling back to date. Position is nullable on purpose: a church can attach sermons to a
     * series without numbering them, and those should still appear in a sensible order rather than
     * all together at the top.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function sermons(int $seriesId, bool $publishedOnly = true, int $limit = 200): array
    {
        if ($seriesId <= 0) {
            return [];
        }
        try {
            $sql = 'SELECT * FROM sermons WHERE series_id = ?';
            if ($publishedOnly) {
                $sql .= ' AND is_published = 1';
            }
            $sql .= ' ORDER BY (series_position IS NULL) ASC, series_position ASC, published_at DESC LIMIT ' . max(1, $limit);
            $stmt = self::db()->prepare($sql);
            $stmt->execute([$seriesId]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** A short plain-text blurb for a series card or a feed, falling back to the episodes. */
    public static function blurb(array $series, int $maxLength = 200): string
    {
        $text = trim((string) ($series['description'] ?? ''));
        if ($text === '') {
            $count = (int) ($series['sermon_count'] ?? 0);
            return $count > 0
                ? $count . ($count === 1 ? ' message' : ' messages') . ' in this series.'
                : 'A series of messages.';
        }
        return mb_strimwidth($text, 0, $maxLength, '…');
    }

    /* ------------------------------------------------------------------ writes */

    /**
     * Creates or updates a series.
     *
     * @param  array<string, mixed> $extra  description, cover_image, org_unit_id, is_published, sort_order
     * @return array{ok:bool,id?:int,action?:string,error?:string}
     */
    public static function save(int $id, string $title, array $extra = []): array
    {
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
        if ($title === '') {
            return ['ok' => false, 'error' => 'Give the series a name.'];
        }
        $title = mb_substr($title, 0, self::MAX_TITLE);

        try {
            $pdo = self::db();

            // A published series address is out in the world: search results, a link shared on
            // WhatsApp, and the item links inside the podcast feed. Renaming a series must not
            // move it, or every one of those stops working. So the existing slug is kept and only
            // a series that has never had one is given a fresh slug from its title.
            $slug = '';
            if ($id > 0) {
                $current = $pdo->prepare('SELECT slug FROM sermon_series WHERE id = ?');
                $current->execute([$id]);
                $slug = (string) $current->fetchColumn();
            }
            if ($slug === '') {
                $slug = self::uniqueSlug($title, $id);
            }

            $fields = [
                'title' => $title,
                'slug' => $slug,
                'description' => self::nullIfBlank($extra['description'] ?? null),
                'cover_image' => self::nullIfBlank($extra['cover_image'] ?? null),
                'org_unit_id' => !empty($extra['org_unit_id']) ? (int) $extra['org_unit_id'] : null,
                'is_published' => !empty($extra['is_published']) ? 1 : 0,
                'sort_order' => (int) ($extra['sort_order'] ?? 0),
            ];

            if ($id > 0) {
                $set = implode(', ', array_map(static fn(string $c): string => '`' . $c . '` = ?', array_keys($fields)));
                $pdo->prepare("UPDATE sermon_series SET {$set} WHERE id = ?")
                    ->execute(array_merge(array_values($fields), [$id]));

                // The legacy text column has to follow a rename, or the API and the app would
                // go on showing the old name while the website showed the new one.
                self::syncLegacyTitle($id, $title);

                return ['ok' => true, 'id' => $id, 'action' => 'updated'];
            }

            $columns = implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', array_keys($fields)));
            $marks = implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO sermon_series (tenant_id, {$columns}) VALUES (?, {$marks})")
                ->execute(array_merge([self::tenantId()], array_values($fields)));

            return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'action' => 'created'];
        } catch (Throwable $e) {
            error_log('Series save failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'That series could not be saved.'];
        }
    }

    /**
     * Deletes a series and detaches its sermons.
     *
     * The sermons survive. The legacy text column is cleared with them, so a detached sermon does
     * not keep a series name that no longer exists and reappear in the old text-based filter.
     *
     * @return array{ok:bool,detached:int}
     */
    public static function delete(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'detached' => 0];
        }
        try {
            $pdo = self::db();
            $pdo->beginTransaction();

            $detach = $pdo->prepare('UPDATE sermons SET series_id = NULL, series_position = NULL, series = NULL WHERE series_id = ?');
            $detach->execute([$id]);
            $detached = $detach->rowCount();

            $pdo->prepare('DELETE FROM sermon_series WHERE id = ?')->execute([$id]);
            $pdo->commit();

            return ['ok' => true, 'detached' => $detached];
        } catch (Throwable $e) {
            if (self::db()->inTransaction()) {
                self::db()->rollBack();
            }
            error_log('Series delete failed: ' . $e->getMessage());
            return ['ok' => false, 'detached' => 0];
        }
    }

    /**
     * The first free episode number in a series.
     *
     * Counts up to the highest number in use rather than counting the rows, so deleting episode 2
     * of 5 does not make the next one a second episode 5.
     */
    public static function nextPosition(int $seriesId): int
    {
        if ($seriesId <= 0) {
            return 1;
        }
        try {
            $stmt = self::db()->prepare('SELECT COALESCE(MAX(series_position), 0) FROM sermons WHERE series_id = ?');
            $stmt->execute([$seriesId]);
            return (int) $stmt->fetchColumn() + 1;
        } catch (Throwable $e) {
            return 1;
        }
    }

    /**
     * Attaches a sermon to a series, or detaches it.
     *
     * A null or zero series id detaches. Passing a position of null asks for the next free
     * number, which is what the admin form does when the field is left blank.
     */
    public static function attach(int $sermonId, ?int $seriesId, ?int $position = null): bool
    {
        if ($sermonId <= 0) {
            return false;
        }

        try {
            $pdo = self::db();

            if ($seriesId === null || $seriesId <= 0) {
                $pdo->prepare('UPDATE sermons SET series_id = NULL, series_position = NULL, series = NULL WHERE id = ?')
                    ->execute([$sermonId]);
                return true;
            }

            $series = self::find($seriesId);
            if ($series === null) {
                return false;
            }

            $position = $position !== null && $position > 0 ? $position : self::nextPosition($seriesId);
            $pdo->prepare('UPDATE sermons SET series_id = ?, series_position = ?, series = ? WHERE id = ?')
                ->execute([$seriesId, $position, (string) $series['title'], $sermonId]);

            return true;
        } catch (Throwable $e) {
            error_log('Series attach failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Writes a series title into the legacy text column of every sermon that belongs to it. */
    private static function syncLegacyTitle(int $seriesId, string $title): void
    {
        try {
            self::db()->prepare('UPDATE sermons SET series = ? WHERE series_id = ?')->execute([$title, $seriesId]);
        } catch (Throwable $e) {
            error_log('Series legacy sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Propagates the church a series belongs to onto its sermons.
     *
     * Without this, a sermon added to a series after the fact could stay unassigned while the
     * series is scoped to a church, and the sermon would vanish from that church's public pages.
     */
    public static function adoptUnit(int $seriesId, ?int $unitId): int
    {
        if ($seriesId <= 0 || $unitId === null || $unitId <= 0) {
            return 0;
        }
        try {
            $stmt = self::db()->prepare('UPDATE sermons SET org_unit_id = ? WHERE series_id = ? AND org_unit_id IS NULL');
            $stmt->execute([$unitId, $seriesId]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /* --------------------------------------------------------------- utilities */

    /** A slug that is free, ignoring the row being edited. */
    private static function uniqueSlug(string $title, int $ignoreId = 0): string
    {
        $base = slugify($title);
        if ($base === '') {
            $base = 'series';
        }
        $base = mb_substr($base, 0, 160);

        $slug = $base;
        $suffix = 2;
        $pdo = self::db();

        while (true) {
            $stmt = $pdo->prepare('SELECT id FROM sermon_series WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->execute([$slug, $ignoreId]);
            if (!$stmt->fetchColumn()) {
                return $slug;
            }
            $slug = $base . '-' . $suffix;
            $suffix++;
            if ($suffix > 500) {
                return $base . '-' . bin2hex(random_bytes(3));
            }
        }
    }

    /** @param array<int, mixed> $ids @return array<int, int> */
    private static function cleanIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    private static function nullIfBlank($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
