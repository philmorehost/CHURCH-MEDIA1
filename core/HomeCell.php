<?php
declare(strict_types=1);

/**
 * Home cells — the small midweek gatherings.
 *
 * A "cell" is a leaf-level unit: the deepest level the church has configured, a Parish by default.
 * The details live on the unit rather than in a table of their own on purpose. A church that adds a
 * "House Fellowship" level below Parish then gets cells at that level automatically, and the finder
 * and the hierarchy can never disagree about what a cell is.
 *
 * Two of the fields are published carefully:
 *
 *  - `leader_phone` is stored for the church's own use and shown only when `leader_phone_public` is
 *    ticked for that cell. A leader's personal number on a public page gets scraped and called, and
 *    the person filling in the form is not necessarily the person who would be phoned, so
 *    publication is a separate and explicit choice rather than a side effect of saving.
 *  - `cell_is_public` hides a cell from the finder without deleting anything, which is what a church
 *    needs when a cell is between leaders.
 */
final class HomeCell
{
    /** The week, in the order people read it — Sunday first. */
    public const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public const MAX_TIME = 12;
    public const MAX_ADDRESS = 255;
    public const MAX_LEADER = 150;
    public const MAX_PHONE = Phone::MAX_LENGTH;
    public const MAX_CAPACITY = 100000;

    private static ?PDO $pdo = null;

    private static function db(): PDO
    {
        return self::$pdo ??= Database::getInstance()->getConnection();
    }

    /**
     * Every leaf unit with its cell details, including the ones hidden from the public finder.
     * This is what the admin grid lists.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $stmt = self::db()->prepare('SELECT * FROM org_units WHERE type = ? ORDER BY name ASC');
        $stmt->execute([Unit::leafType()]);
        $leaves = $stmt->fetchAll();
        if (!$leaves) {
            return [];
        }

        // One pass for every unit's full path label, rather than a query per unit.
        $labels = Unit::labelsById();

        return array_map(static function (array $u) use ($labels): array {
            $u = self::decorate($u);
            $u['path_label'] = $labels[(int) $u['id']] ?? (string) $u['name'];
            return $u;
        }, $leaves);
    }

    /**
     * The cells the public finder may show.
     *
     * A cell is listed when it is switched on *and* has a meeting day. The meeting day is the
     * signal that somebody actually filled this in: a church with 200 parishes and 12 real cells
     * must not get 188 blank cards, and requiring a day is a simpler rule to explain than
     * "enough of the fields".
     *
     * @return array<int, array<string, mixed>>
     */
    public static function published(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $c): bool => self::isListed($c)
        ));
    }

    /** True when this cell should appear on the public finder. */
    public static function isListed(array $cell): bool
    {
        return !empty($cell['cell_is_public']) && trim((string) ($cell['meeting_day'] ?? '')) !== '';
    }

    public static function find(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM org_units WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::decorate($row) : null;
    }

    /**
     * Validates and saves one cell's details.
     *
     * This writes the whole record: a detail missing from $in is stored as empty, not left alone.
     * That is what the admin form needs — emptying a field is how you remove it — and the form
     * posts every field on every save, including both switches as a hidden-input pair, so a
     * checkbox that failed to render cannot silently unpublish a cell. A partial caller would have
     * to post the full set too, which is worth knowing before calling this from anywhere new.
     *
     * @return array{errors?: array<int, string>, ok?: bool}
     */
    public static function save(int $id, array $in): array
    {
        $unit = Unit::find($id);
        if ($unit === null) {
            return ['errors' => ['That unit no longer exists.']];
        }
        if ($unit['type'] !== Unit::leafType()) {
            // Only the smallest grouping meets in a home. Offering the form on a Zone would invite
            // details that the finder never reads.
            return ['errors' => ['Only a ' . Unit::labelFor(Unit::leafType()) . ' can be listed as a home cell — this is a '
                . Unit::labelFor((string) $unit['type']) . '.']];
        }

        $errors = [];

        $day = trim((string) ($in['meeting_day'] ?? ''));
        if ($day !== '') {
            $match = null;
            foreach (self::DAYS as $candidate) {
                if (strcasecmp($candidate, $day) === 0) {
                    $match = $candidate;
                    break;
                }
            }
            if ($match === null) {
                $errors[] = 'Choose a meeting day from the list, or leave it empty for a unit that does not meet as a cell.';
            }
            $day = $match ?? '';
        }

        $time = trim((string) ($in['meeting_time'] ?? ''));
        if (mb_strlen($time) > self::MAX_TIME) {
            $errors[] = 'Meeting time is too long — keep it short, like "6:30 PM".';
        }

        $address = trim((string) ($in['meeting_address'] ?? ''));
        if (mb_strlen($address) > self::MAX_ADDRESS) {
            $errors[] = 'The address is too long.';
        }

        $leader = trim((string) ($in['leader_name'] ?? ''));
        if (mb_strlen($leader) > self::MAX_LEADER) {
            $errors[] = 'The leader\'s name is too long.';
        }

        $phoneRaw = trim((string) ($in['leader_phone'] ?? ''));
        $phone = null;
        if ($phoneRaw !== '') {
            $phone = self::normalisePhone($phoneRaw);
            if ($phone === null) {
                $errors[] = 'That contact number does not look right. Include the country code, or leave it empty.';
            } elseif (mb_strlen($phone) > self::MAX_PHONE) {
                $errors[] = 'The contact number is too long.';
            }
        }

        $capacityRaw = trim((string) ($in['capacity'] ?? ''));
        $capacity = null;
        if ($capacityRaw !== '') {
            if (!preg_match('/^\d+$/', $capacityRaw) || (int) $capacityRaw < 1 || (int) $capacityRaw > self::MAX_CAPACITY) {
                $errors[] = 'Capacity must be a whole number of people, or left empty.';
            } else {
                $capacity = (int) $capacityRaw;
            }
        }

        if ($errors) {
            return ['errors' => $errors];
        }

        // A number that is not allowed to be published is still stored — the church needs it to
        // ring the leader. Clearing the number, though, must also clear the permission, or a stale
        // "yes" would sit there waiting for the next number that gets typed in.
        $phonePublic = $phone !== null && !empty($in['leader_phone_public']) ? 1 : 0;

        self::db()->prepare(
            'UPDATE org_units SET meeting_day = ?, meeting_time = ?, meeting_address = ?, leader_name = ?,'
            . ' leader_phone = ?, leader_phone_public = ?, capacity = ?, cell_is_public = ? WHERE id = ?'
        )->execute([
            $day !== '' ? $day : null,
            $time !== '' ? $time : null,
            $address !== '' ? $address : null,
            $leader !== '' ? $leader : null,
            $phone,
            $phonePublic,
            $capacity,
            empty($in['cell_is_public']) ? 0 : 1,
            $id,
        ]);

        return ['ok' => true];
    }

    /**
     * Filters the published cells by free text and/or an ancestor unit.
     *
     * Filtering happens in PHP rather than SQL because a cell matches on its *ancestors'* names too
     * ("Zone 3"), and doing that in SQL means walking the tree in a recursive query for a list that
     * is in the low hundreds at most. The whole set is fetched once and matched in memory.
     *
     * @param int|null $insideUnit match cells at or below this unit
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $query = '', ?int $insideUnit = null): array
    {
        $query = mb_strtolower(trim($query));
        $inSubtree = null;
        if ($insideUnit !== null && $insideUnit > 0) {
            $inSubtree = Unit::subtreeIds($insideUnit);
        }

        return array_values(array_filter(self::published(), static function (array $cell) use ($query, $inSubtree): bool {
            if ($inSubtree !== null && !in_array((int) $cell['id'], $inSubtree, true)) {
                return false;
            }
            if ($query === '') {
                return true;
            }
            $haystack = mb_strtolower(implode(' ', [
                (string) $cell['name'],
                (string) $cell['path_label'],
                (string) ($cell['leader_name'] ?? ''),
                (string) ($cell['meeting_address'] ?? ''),
            ]));
            return mb_strpos($haystack, $query) !== false;
        }));
    }

    /** What is still missing before this cell is worth showing to a visitor, in reading order. */
    public static function missing(array $cell): array
    {
        $missing = [];
        if (trim((string) ($cell['meeting_day'] ?? '')) === '') {
            $missing[] = 'meeting day';
        }
        if (trim((string) ($cell['meeting_address'] ?? '')) === '') {
            $missing[] = 'address';
        }
        if (trim((string) ($cell['leader_name'] ?? '')) === '') {
            $missing[] = 'leader';
        }
        if (trim((string) ($cell['leader_phone'] ?? '')) === '') {
            $missing[] = 'contact number';
        }
        return $missing;
    }

    /**
     * Normalises a typed number to dial-code form, or null when it cannot be one.
     *
     * Kept as a one-line delegation rather than inlined so every caller in this file reads the same,
     * and so the definition of a valid number lives in exactly one place (core/Phone.php) now that
     * service assignments collect numbers too.
     */
    public static function normalisePhone(string $raw): ?string
    {
        return Phone::normalise($raw);
    }

    /** "Up to 40 people", or empty when nobody recorded it. */
    public static function capacityLabel($capacity): string
    {
        $capacity = (int) $capacity;
        return $capacity > 0 ? 'Space for about ' . $capacity : '';
    }

    /** Casts the integer-ish columns so callers compare ints rather than numeric strings. */
    private static function decorate(array $unit): array
    {
        $unit['id'] = (int) $unit['id'];
        $unit['capacity'] = $unit['capacity'] !== null ? (int) $unit['capacity'] : null;
        $unit['cell_is_public'] = (int) ($unit['cell_is_public'] ?? 1);
        $unit['leader_phone_public'] = (int) ($unit['leader_phone_public'] ?? 0);
        return $unit;
    }
}
