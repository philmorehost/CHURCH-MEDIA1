<?php
declare(strict_types=1);

/**
 * The public prayer wall, and the "I prayed for this" counter behind it.
 *
 * Every decision about what a visitor is allowed to see lives here rather than in
 * the view or the API, because the rule that really matters is a privacy rule: a
 * request submitted anonymously must never leak its name publicly — not on the
 * wall, not in the JSON, not on the answered list — while the pastoral team still
 * needs to know who wrote it.
 *
 * Rules of the road:
 *  - `is_anonymous` is the only flag the public shape trusts. A blank name is
 *    treated as anonymous rather than as an empty string waiting to be filled in.
 *  - `email` and `ip_address` never leave this class on a public read.
 *  - `archived` hides a request everywhere public, including the answered list.
 *  - A prayer is counted once per visitor, enforced by a unique key rather than by
 *    a read-then-write, so two clicks racing each other cannot double-count.
 */
final class PrayerWall
{
    /** Statuses an admin can pick from. `archived` hides the request publicly. */
    public const STATUSES = ['new', 'prayed', 'archived'];

    /** The public projection. Deliberately does not include email or ip_address. */
    private const PUBLIC_COLUMNS = 'id, name, message, is_public, is_anonymous, is_featured,'
        . ' increment_count, answered_at, answer_note, created_at, org_unit_id';

    /* ------------------------------------------------------------------ reads */

    /**
     * Open requests: public, not archived, not yet marked answered.
     *
     * Featured requests float to the top so an admin can pin one without having to
     * reorder anything.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function wall(int $limit = 30): array
    {
        return self::publicRows('answered_at IS NULL', 'is_featured DESC, created_at DESC', $limit);
    }

    /**
     * The answered wall — where a request ended up, newest answer first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function answered(int $limit = 12): array
    {
        return self::publicRows('answered_at IS NOT NULL', 'answered_at DESC', $limit);
    }

    /** Headline numbers for the page. */
    public static function stats(): array
    {
        try {
            $pdo = self::db();
            $params = [];
            $where = self::tenantClause($params);

            $wall = $pdo->prepare(
                'SELECT COUNT(*) FROM prayer_requests'
                . ' WHERE is_public = 1 AND status != \'archived\' AND answered_at IS NULL AND ' . $where
            );
            $wall->execute($params);

            $answered = $pdo->prepare(
                'SELECT COUNT(*) FROM prayer_requests'
                . ' WHERE is_public = 1 AND status != \'archived\' AND answered_at IS NOT NULL AND ' . $where
            );
            $answered->execute($params);

            $prayers = $pdo->prepare(
                'SELECT COALESCE(SUM(increment_count), 0) FROM prayer_requests WHERE is_public = 1 AND status != \'archived\' AND ' . $where
            );
            $prayers->execute($params);

            return [
                'open' => (int) $wall->fetchColumn(),
                'answered' => (int) $answered->fetchColumn(),
                'prayers' => (int) $prayers->fetchColumn(),
            ];
        } catch (Throwable $e) {
            error_log('PrayerWall stats failed: ' . $e->getMessage());
            return ['open' => 0, 'answered' => 0, 'prayers' => 0];
        }
    }

    /**
     * Which of these requests this visitor has already prayed for, so the button
     * can render in its finished state without one query per card.
     *
     * @param  array<int, mixed> $requestIds
     * @return array<int, int>   The subset already prayed for.
     */
    public static function participated(array $requestIds, string $sessionHash): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $requestIds), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || $sessionHash === '') {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = self::db()->prepare(
                'SELECT request_id FROM prayer_participants'
                . ' WHERE session_hash = ? AND request_id IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$sessionHash], $ids));
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            error_log('PrayerWall participation read failed: ' . $e->getMessage());
            return [];
        }
    }

    /** A single request, raw — the admin side needs the contact details. */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = self::db()->prepare('SELECT * FROM prayer_requests WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** A single request in its public projection. */
    public static function shaped(int $id): ?array
    {
        $row = self::find($id);
        return $row !== null ? self::publicShape($row) : null;
    }

    /* ----------------------------------------------------------------- writes */

    /**
     * Records a submitted request.
     *
     * @return array{id?:int,errors?:array<int,string>}
     */
    public static function submit(string $name, string $email, string $message, bool $isPublic, bool $anonymous): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['errors' => ['Please share a prayer request.']];
        }
        if (mb_strlen($message) > 2000) {
            return ['errors' => ['Please keep your request under 2000 characters.']];
        }

        $email = trim($email);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['errors' => ['That email address looks invalid.']];
        }

        $name = trim($name);
        // Leaving the name blank is a request for anonymity, so record it as such
        // rather than storing an empty string a later edit could accidentally fill.
        if ($anonymous || $name === '') {
            $anonymous = true;
        }

        try {
            $stmt = self::db()->prepare(
                'INSERT INTO prayer_requests (tenant_id, name, email, message, is_public, is_anonymous, ip_address)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                self::tenantId(),
                $name !== '' ? $name : null,
                $email !== '' ? $email : null,
                $message,
                $isPublic ? 1 : 0,
                $anonymous ? 1 : 0,
                function_exists('clientIp') ? clientIp() : null,
            ]);

            return ['id' => (int) self::db()->lastInsertId()];
        } catch (Throwable $e) {
            error_log('PrayerWall submit failed: ' . $e->getMessage());
            return ['errors' => ['We could not save that just now — please try again.']];
        }
    }

    /**
     * Counts one visitor praying for one request, at most once ever.
     *
     * @return array{ok:bool,counted?:bool,prayer_count?:int,message?:string}
     */
    public static function recordPrayer(int $requestId, string $sessionHash): array
    {
        if ($requestId <= 0 || $sessionHash === '') {
            return ['ok' => false, 'message' => 'That prayer request is no longer on the wall.'];
        }

        try {
            $pdo = self::db();

            // Only an open, public request belonging to *this* church can be prayed
            // for. Without the tenant clause a member of one church could bump
            // another church's counter by guessing an id.
            $params = [];
            $where = self::tenantClause($params);
            $check = $pdo->prepare(
                'SELECT increment_count FROM prayer_requests'
                . ' WHERE id = ? AND is_public = 1 AND status != \'archived\' AND ' . $where
                . ' LIMIT 1'
            );
            $check->execute(array_merge([$requestId], $params));
            $count = $check->fetchColumn();

            if ($count === false) {
                return ['ok' => false, 'message' => 'That prayer request is no longer on the wall.'];
            }
            $count = (int) $count;

            // INSERT IGNORE + unique key: the de-duplication is atomic.
            $insert = $pdo->prepare('INSERT IGNORE INTO prayer_participants (tenant_id, request_id, session_hash) VALUES (?, ?, ?)');
            $insert->execute([self::tenantId(), $requestId, $sessionHash]);
            $counted = $insert->rowCount() > 0;

            if ($counted) {
                $pdo->prepare('UPDATE prayer_requests SET increment_count = increment_count + 1 WHERE id = ?')->execute([$requestId]);
                $count++;
            }

            return ['ok' => true, 'counted' => $counted, 'prayer_count' => $count];
        } catch (Throwable $e) {
            error_log('PrayerWall count failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not count that just now.'];
        }
    }

    /** Marks a request answered (with the note that will show publicly) or reopens it. */
    public static function markAnswered(int $id, bool $answered, ?string $note = null): bool
    {
        if ($id <= 0) {
            return false;
        }
        try {
            $note = $note !== null ? trim($note) : '';
            self::db()->prepare('UPDATE prayer_requests SET answered_at = ?, answer_note = ? WHERE id = ?')
                ->execute([
                    $answered ? date('Y-m-d H:i:s') : null,
                    $answered && $note !== '' ? $note : null,
                    $id,
                ]);
            return true;
        } catch (Throwable $e) {
            error_log('PrayerWall markAnswered failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function setFeatured(int $id, bool $featured): bool
    {
        if ($id <= 0) {
            return false;
        }
        try {
            self::db()->prepare('UPDATE prayer_requests SET is_featured = ? WHERE id = ?')
                ->execute([$featured ? 1 : 0, $id]);
            return true;
        } catch (Throwable $e) {
            error_log('PrayerWall setFeatured failed: ' . $e->getMessage());
            return false;
        }
    }

    /* ---------------------------------------------------------------- shaping */

    /** The name the public may see. Anonymous always wins over a stored name. */
    public static function displayName(array $row): string
    {
        if ((int) ($row['is_anonymous'] ?? 0) === 1) {
            return 'Anonymous';
        }
        $name = trim((string) ($row['name'] ?? ''));
        return $name !== '' ? $name : 'Anonymous';
    }

    /**
     * Strips a row down to what a visitor may receive. The absence of `email`,
     * `ip_address` and the raw `name` here is the whole point.
     *
     * @return array<string, mixed>
     */
    public static function publicShape(array $row): array
    {
        $answered = !empty($row['answered_at']);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => self::displayName($row),
            'is_anonymous' => (int) ($row['is_anonymous'] ?? 0) === 1,
            'is_featured' => (int) ($row['is_featured'] ?? 0) === 1,
            'message' => (string) ($row['message'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'prayer_count' => (int) ($row['increment_count'] ?? 0),
            'answered' => $answered,
            'answered_at' => $answered ? (string) $row['answered_at'] : null,
            'answer_note' => $answered && !empty($row['answer_note']) ? (string) $row['answer_note'] : null,
        ];
    }

    /* ---------------------------------------------------------------- internals */

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function publicRows(string $condition, string $order, int $limit): array
    {
        $limit = max(1, min(120, $limit));
        try {
            $params = [];
            $where = self::tenantClause($params);
            $stmt = self::db()->prepare(
                'SELECT ' . self::PUBLIC_COLUMNS . ' FROM prayer_requests'
                . ' WHERE is_public = 1 AND status != \'archived\' AND ' . $condition
                . ' AND ' . $where
                . ' ORDER BY ' . $order . ' LIMIT ' . $limit
            );
            $stmt->execute($params);

            return array_map([self::class, 'publicShape'], $stmt->fetchAll());
        } catch (Throwable $e) {
            error_log('PrayerWall read failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Tenant predicate plus the matching parameters. Falls back to `IS NULL` when no
     * tenant is resolved (pre-install, or a failed lookup) rather than exposing
     * another church's wall.
     *
     * @param array<int, mixed> $params appended to in place
     */
    private static function tenantClause(array &$params): string
    {
        $tenantId = self::tenantId();
        if ($tenantId === null) {
            return 'tenant_id IS NULL';
        }
        $params[] = $tenantId;
        return 'tenant_id = ?';
    }

    private static function tenantId(): ?int
    {
        return class_exists('Tenant') ? Tenant::id() : null;
    }

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }
}
