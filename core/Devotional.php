<?php
declare(strict_types=1);

/**
 * Daily devotionals — one per church per day.
 *
 * Two rules this class exists to hold in one place, because breaking either is invisible
 * until somebody notices a day with two devotionals or a day with none:
 *
 *   1. **One per day per church.** `uniq_devotional_day` enforces it in the schema, and
 *      `save()` treats a second write for the same day as an update rather than an error —
 *      the natural way to use the editor is to come back to today.
 *
 *   2. **`org_unit_id` of 0 means church-wide.** That differs from `sermon_series`, which uses
 *      NULL, and the reason is in the migration: a unique key containing a nullable column
 *      cannot stop duplicates, and two devotionals for the same day is precisely the bug worth
 *      preventing. The NULL-means-shared convention cannot carry a unique constraint at all.
 *      Every read goes through `scopeClause()` so the three-way rule is written down once
 *      rather than re-derived at each call site, where getting it wrong just makes an entry
 *      silently fail to appear.
 */
final class Devotional
{
    /** Church-wide: not a real `org_units` id. */
    public const SHARED = 0;

    /** "Do not filter by unit at all" — for the admin month view and the public page. */
    public const ALL_UNITS = -1;

    public const MAX_TITLE = 180;

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }

    private static function tenantId(): int
    {
        return (class_exists('Tenant') ? Tenant::id() : null) ?? 0;
    }

    /* ------------------------------------------------------------------ reads */

    /** The devotional for one day, or null. */
    public static function forDate(string $date, int $unitId = self::ALL_UNITS, bool $publishedOnly = true): ?array
    {
        $rows = self::between($date, $date, $unitId, $publishedOnly);
        return $rows[$date] ?? null;
    }

    /** Today's devotional, or null. */
    public static function today(int $unitId = self::ALL_UNITS): ?array
    {
        return self::forDate(date('Y-m-d'), $unitId);
    }

    /**
     * Devotionals in an inclusive date range, keyed by day, one row per day.
     *
     * When a church has written its own for a day that also has a church-wide one, the
     * church's own wins — a church that took the trouble to write its own should see its own,
     * not the entry it was shadowing. The ordering does that, and "first row per day wins"
     * keeps the rule in one line.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function between(string $from, string $to, int $unitId = self::ALL_UNITS, bool $publishedOnly = true): array
    {
        list($clause, $scopeParams) = self::scopeClause($unitId);

        $sql = 'SELECT * FROM devotionals
                 WHERE tenant_id = ?
                   AND publish_on BETWEEN ? AND ?
                   AND ' . $clause;
        if ($publishedOnly) {
            $sql .= ' AND is_published = 1';
        }
        $sql .= ' ORDER BY publish_on ASC, org_unit_id DESC, id ASC';

        $stmt = self::db()->prepare($sql);
        $stmt->execute(array_merge(array(self::tenantId(), $from, $to), $scopeParams));

        $byDay = array();
        foreach ($stmt->fetchAll() as $row) {
            $day = (string) $row['publish_on'];
            if (!isset($byDay[$day])) {
                $byDay[$day] = $row;
            }
        }
        return $byDay;
    }

    /**
     * A whole month keyed by day — the admin grid and the public archive both use this.
     *
     * `$publishedOnly` is a parameter rather than always-on because the admin grid passes
     * `false`: a draft the admin cannot see is a draft they cannot publish, and hunting for it
     * by URL is not a workflow.
     */
    public static function month(int $year, int $month, int $unitId = self::ALL_UNITS, bool $publishedOnly = true): array
    {
        $month = max(1, min(12, $month));
        $first = sprintf('%04d-%02d-01', $year, $month);
        return self::between($first, date('Y-m-t', strtotime($first)), $unitId, $publishedOnly);
    }

    public static function find(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM devotionals WHERE id = ? AND tenant_id = ?');
        $stmt->execute(array($id, self::tenantId()));
        return $stmt->fetch() ?: null;
    }

    /** The most recent entries, newest first — the public archive list. */
    public static function latest(int $limit = 20, int $unitId = self::ALL_UNITS): array
    {
        $limit = max(1, min(100, $limit));
        list($clause, $scopeParams) = self::scopeClause($unitId);

        $stmt = self::db()->prepare(
            'SELECT * FROM devotionals
              WHERE tenant_id = ? AND is_published = 1 AND publish_on <= CURDATE() AND ' . $clause . '
              ORDER BY publish_on DESC, org_unit_id DESC
              LIMIT ' . $limit
        );
        $stmt->execute(array_merge(array(self::tenantId()), $scopeParams));
        return $stmt->fetchAll();
    }

    /* ----------------------------------------------------------------- writes */

    /**
     * Creates or updates the devotional for a day.
     *
     * @return array{ok:bool,id?:int,updated?:bool,errors?:string[]}
     */
    public static function save(int $id, array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $day = trim((string) ($data['publish_on'] ?? ''));

        $errors = array();
        if ($title === '') {
            $errors[] = 'Give the devotional a title.';
        } elseif (mb_strlen($title) > self::MAX_TITLE) {
            $errors[] = 'That title is too long — keep it under ' . self::MAX_TITLE . ' characters.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || strtotime($day) === false) {
            $errors[] = 'Choose the day this devotional is for.';
        }
        if ($errors) {
            return array('ok' => false, 'errors' => $errors);
        }

        $fields = array(
            'org_unit_id' => max(0, (int) ($data['org_unit_id'] ?? self::SHARED)),
            'publish_on' => $day,
            'title' => $title,
            'scripture_reference' => self::nullIfBlank($data['scripture_reference'] ?? null),
            'scripture_text' => self::nullIfBlank($data['scripture_text'] ?? null),
            'body' => self::nullIfBlank($data['body'] ?? null),
            'audio_path' => self::nullIfBlank($data['audio_path'] ?? null),
            'is_published' => !empty($data['is_published']) ? 1 : 0,
        );
        $setSql = implode(', ', array_map(static function (string $column): string {
            return $column . ' = ?';
        }, array_keys($fields)));
        $values = array_values($fields);
        $pdo = self::db();

        if ($id > 0) {
            if (self::find($id) === null) {
                return array('ok' => false, 'errors' => array('That devotional no longer exists.'));
            }
            $pdo->prepare('UPDATE devotionals SET ' . $setSql . ' WHERE id = ?')
                ->execute(array_merge($values, array($id)));
            return array('ok' => true, 'id' => $id);
        }

        // Reuse the row if this day already has one. Without this the unique key would turn
        // "write today's devotional" — the most ordinary thing to do — into an error.
        $existing = $pdo->prepare('SELECT id FROM devotionals WHERE tenant_id = ? AND org_unit_id = ? AND publish_on = ? LIMIT 1');
        $existing->execute(array(self::tenantId(), $fields['org_unit_id'], $day));
        $existingId = $existing->fetchColumn();

        if ($existingId !== false) {
            $pdo->prepare('UPDATE devotionals SET ' . $setSql . ' WHERE id = ?')
                ->execute(array_merge($values, array((int) $existingId)));
            return array('ok' => true, 'id' => (int) $existingId, 'updated' => true);
        }

        $columns = array_merge(array('tenant_id'), array_keys($fields));
        $params = array_merge(array(self::tenantId()), $values);
        if (!empty($data['sermon_id'])) {
            $columns[] = 'sermon_id';
            $params[] = (int) $data['sermon_id'];
        }
        if (!empty($data['created_by'])) {
            $columns[] = 'created_by';
            $params[] = (int) $data['created_by'];
        }

        $pdo->prepare(
            'INSERT INTO devotionals (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        )->execute($params);

        return array('ok' => true, 'id' => (int) $pdo->lastInsertId());
    }

    public static function delete(int $id): bool
    {
        $stmt = self::db()->prepare('DELETE FROM devotionals WHERE id = ? AND tenant_id = ?');
        $stmt->execute(array($id, self::tenantId()));
        return $stmt->rowCount() > 0;
    }

    /** Which days of a month have an entry — used to shade the grid. */
    public static function daysWithEntries(int $year, int $month, int $unitId = self::ALL_UNITS): array
    {
        return array_keys(self::month($year, $month, $unitId));
    }

    /* ---------------------------------------------------------------- helpers */

    /**
     * "This church's own, or the church-wide one", or no filter at all.
     *
     * Written once because the three-way rule is the easiest thing here to get wrong, and the
     * failure is silent: the entry simply does not appear.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function scopeClause(int $unitId): array
    {
        if ($unitId === self::ALL_UNITS) {
            return array('1 = 1', array());
        }
        if ($unitId <= 0) {
            return array('org_unit_id = ?', array(self::SHARED));
        }
        return array('(org_unit_id = ? OR org_unit_id = ?)', array($unitId, self::SHARED));
    }

    private static function nullIfBlank($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
