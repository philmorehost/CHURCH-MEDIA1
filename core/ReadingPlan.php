<?php
declare(strict_types=1);

/**
 * Bible reading plans, and how far each member has got through one.
 *
 * A plan is a list of days; a day is a set of passages. A passage is stored broken into its parts —
 * book, chapters, verses — rather than as the text "Genesis 1:1-5", because the app has to open it
 * offline in the bundled KJV and cannot parse prose reliably. The readable label is *derived* from
 * those parts, so the two can never disagree.
 *
 * Reading is:
 *
 *   Genesis 1                  one chapter
 *   Genesis 1-3                a run of chapters
 *   Genesis 1:1-5              verses within one chapter
 *   John 3:16                  a single verse
 *   Genesis 1; Psalm 23        two passages in a day, separated by ";" in the editor
 *
 * Verse numbers are checked for sanity but not against the length of the chapter, because the
 * chapter text is not loaded on the server — the app holds the only copy. "John 3:99" is therefore
 * accepted here and simply shows nothing there. Validating it would mean shipping the whole Bible to
 * the server to catch a typo nobody makes twice.
 */
final class ReadingPlan
{
    public const MAX_NAME = 150;

    /** A plan longer than this is a mistake, not a plan. */
    public const MAX_DAYS = 500;

    public const MAX_PASSAGES_PER_DAY = 12;

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }

    private static function tenantId(): int
    {
        return (class_exists('Tenant') ? Tenant::id() : null) ?? 0;
    }

    /* ------------------------------------------------------------- references */

    /**
     * Reads one passage out of the form a person writes it.
     *
     * @return array{ok:bool,passage?:array<string,mixed>,error?:string}
     */
    public static function parseReference(string $text): array
    {
        $clean = (string) preg_replace('/\s+/', ' ', trim($text));
        if ($clean === '') {
            return array('ok' => false, 'error' => 'is empty');
        }

        // The book is everything up to the first number that is not a leading "1 ", "2 " or "3 " —
        // which is why the split cannot simply be at the first space, since "1 Samuel" starts with a
        // number and "Song of Solomon" has spaces inside it.
        if (!preg_match('/^((?:[1-3]\s)?[A-Za-z][A-Za-z.\s]*?)\s+(\d+)(?::(\d+)(?:-(\d+))?)?(?:-(\d+))?$/', $clean, $m)) {
            return array(
                'ok' => false,
                'error' => 'is not a passage in the form "Genesis 1", "Genesis 1-3", "Genesis 1:1-5" or "John 3:16"',
            );
        }

        $book = BibleBooks::normalise((string) $m[1]);
        if ($book === null) {
            $suggestions = BibleBooks::suggestions((string) $m[1]);
            return array(
                'ok' => false,
                'error' => $suggestions === array()
                    ? 'does not begin with a book of the Bible'
                    : 'is not a book I know — did you mean ' . implode(' or ', $suggestions) . '?',
            );
        }

        $chapterStart = (int) $m[2];
        $verseStart = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
        $verseEnd = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null;
        $chapterEnd = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : null;

        $chapters = BibleBooks::chapters($book);
        if ($chapterStart < 1 || $chapterStart > $chapters) {
            return array(
                'ok' => false,
                'error' => $book . ' has ' . $chapters . ' chapter' . ($chapters === 1 ? '' : 's')
                    . ', so chapter ' . $chapterStart . ' does not exist',
            );
        }

        // A verse range spanning chapters has no meaning here, and reading one as though it did
        // would quietly change what the plan says.
        if ($chapterEnd !== null && $verseStart !== null) {
            return array(
                'ok' => false,
                'error' => 'mixes a chapter range with verses — give either chapters (1-3) or verses within one chapter (1:1-5)',
            );
        }

        if ($chapterEnd !== null) {
            if ($chapterEnd < $chapterStart) {
                return array('ok' => false, 'error' => 'has a chapter range that runs backwards');
            }
            if ($chapterEnd > $chapters) {
                return array(
                    'ok' => false,
                    'error' => $book . ' has ' . $chapters . ' chapters, so chapter ' . $chapterEnd . ' does not exist',
                );
            }
        }

        if ($verseStart !== null && $verseStart < 1) {
            return array('ok' => false, 'error' => 'has a verse number below 1');
        }
        if ($verseEnd !== null) {
            if ($verseStart === null) {
                return array('ok' => false, 'error' => 'has a verse range with no starting verse');
            }
            if ($verseEnd < $verseStart) {
                return array('ok' => false, 'error' => 'has a verse range that runs backwards');
            }
        }

        return array('ok' => true, 'passage' => array(
            'book' => $book,
            'chapter_start' => $chapterStart,
            'chapter_end' => $chapterEnd,
            'verse_start' => $verseStart,
            'verse_end' => $verseEnd,
        ));
    }

    /** The readable form of a passage, e.g. "Genesis 1-3" or "John 3:16". */
    public static function labelFor(array $passage): string
    {
        $book = (string) ($passage['book'] ?? '');
        $start = (int) ($passage['chapter_start'] ?? 0);
        $chapterEnd = !empty($passage['chapter_end']) ? (int) $passage['chapter_end'] : null;
        $verseStart = !empty($passage['verse_start']) ? (int) $passage['verse_start'] : null;
        $verseEnd = !empty($passage['verse_end']) ? (int) $passage['verse_end'] : null;

        if ($chapterEnd !== null && $chapterEnd !== $start) {
            return $book . ' ' . $start . '-' . $chapterEnd;
        }
        if ($verseStart === null) {
            return $book . ' ' . $start;
        }
        if ($verseEnd === null || $verseEnd === $verseStart) {
            return $book . ' ' . $start . ':' . $verseStart;
        }
        return $book . ' ' . $start . ':' . $verseStart . '-' . $verseEnd;
    }

    /** A day's passages as one line, e.g. "Genesis 1; Psalm 23". */
    public static function labelForDay(array $passages): string
    {
        $labels = array();
        foreach ($passages as $passage) {
            $labels[] = self::labelFor($passage);
        }
        return implode('; ', $labels);
    }

    /**
     * Reads a whole plan out of the editor: one line per day, passages on a day separated by ";".
     *
     * A blank line is a rest day, which keeps the days after it on the numbers they were written
     * with — a plan that rests on Sundays depends on that. Trailing blank lines are trimmed, because
     * a stray newline at the end of a text box is not a request for an extra day.
     *
     * @return array{days:array<int,array<int,array<string,mixed>>>,errors:array<int,string>}
     */
    public static function parseBlock(string $block): array
    {
        $lines = (array) preg_split('/\r\n|\r|\n/', $block);
        $days = array();
        $errors = array();

        foreach ($lines as $index => $line) {
            $day = $index + 1;
            $trimmed = trim((string) $line);

            if ($trimmed === '') {
                $days[$day] = array();
                continue;
            }

            $passages = array();
            foreach (explode(';', $trimmed) as $piece) {
                $piece = trim($piece);
                if ($piece === '') {
                    continue;
                }
                if (count($passages) >= self::MAX_PASSAGES_PER_DAY) {
                    $errors[] = 'Day ' . $day . ' has more than ' . self::MAX_PASSAGES_PER_DAY . ' passages.';
                    break;
                }
                $parsed = self::parseReference($piece);
                if (!$parsed['ok']) {
                    $errors[] = 'Day ' . $day . ': "' . $piece . '" ' . $parsed['error'] . '.';
                    continue;
                }
                $passages[] = $parsed['passage'];
            }
            $days[$day] = $passages;
        }

        while ($days !== array() && end($days) === array()) {
            array_pop($days);
        }

        return array('days' => $days, 'errors' => $errors);
    }

    /* ------------------------------------------------------------------ plans */

    public static function find(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM reading_plans WHERE id = ? AND tenant_id = ?');
        $stmt->execute(array($id, self::tenantId()));
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> Every plan, published first then by name. */
    public static function all(): array
    {
        $stmt = self::db()->prepare('SELECT * FROM reading_plans WHERE tenant_id = ? ORDER BY is_published DESC, name ASC');
        $stmt->execute(array(self::tenantId()));
        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> The plans a member may choose. */
    public static function published(): array
    {
        $stmt = self::db()->prepare('SELECT * FROM reading_plans WHERE tenant_id = ? AND is_published = 1 ORDER BY name ASC');
        $stmt->execute(array(self::tenantId()));
        return $stmt->fetchAll();
    }

    public static function delete(int $id): bool
    {
        // Passages and progress follow it, because both foreign keys cascade — a deleted plan cannot
        // leave half of itself behind for a member to trip over.
        $stmt = self::db()->prepare('DELETE FROM reading_plans WHERE id = ? AND tenant_id = ?');
        $stmt->execute(array($id, self::tenantId()));
        return $stmt->rowCount() > 0;
    }

    /**
     * Creates or replaces a plan.
     *
     * @return array{ok:bool,id?:int,updated?:bool,days?:int,errors?:array<int,string>}
     */
    public static function savePlan(int $id, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $isPublished = !empty($data['is_published']);
        $createdBy = (int) ($data['created_by'] ?? 0);

        $parsed = self::parseBlock((string) ($data['days'] ?? ''));
        $errors = $parsed['errors'];
        $plan = $parsed['days'];

        if ($name === '') {
            $errors[] = 'Give the plan a name.';
        } elseif (mb_strlen($name) > self::MAX_NAME) {
            $errors[] = 'The name is longer than ' . self::MAX_NAME . ' characters.';
        }

        if ($plan === array()) {
            $errors[] = 'Add at least one day — each line is a day, with passages separated by ";".';
        } elseif (count($plan) > self::MAX_DAYS) {
            $errors[] = 'That is ' . count($plan) . ' days, and the limit is ' . self::MAX_DAYS . '.';
        }

        if ($errors !== array()) {
            return array('ok' => false, 'errors' => $errors);
        }

        $existing = $id > 0 ? self::find($id) : null;
        if ($id > 0 && $existing === null) {
            return array('ok' => false, 'errors' => array('That plan no longer exists.'));
        }

        // Checked here rather than left to the unique key, so the message names the clash instead of
        // arriving as a database error.
        $clash = self::db()->prepare('SELECT id FROM reading_plans WHERE tenant_id = ? AND name = ? AND id != ? LIMIT 1');
        $clash->execute(array(self::tenantId(), $name, $id));
        if ($clash->fetchColumn() !== false) {
            return array('ok' => false, 'errors' => array('There is already a plan called "' . $name . '".'));
        }

        $db = self::db();
        $count = count($plan);

        try {
            $db->beginTransaction();

            if ($existing === null) {
                $insert = $db->prepare('INSERT INTO reading_plans (tenant_id, name, description, days_count, is_published, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                $insert->execute(array(
                    self::tenantId(),
                    $name,
                    $description !== '' ? $description : null,
                    $count,
                    $isPublished ? 1 : 0,
                    $createdBy > 0 ? $createdBy : null,
                ));
                $id = (int) $db->lastInsertId();
            } else {
                $update = $db->prepare('UPDATE reading_plans SET name = ?, description = ?, days_count = ?, is_published = ? WHERE id = ? AND tenant_id = ?');
                $update->execute(array($name, $description !== '' ? $description : null, $count, $isPublished ? 1 : 0, $id, self::tenantId()));
            }

            // Replaced wholesale rather than diffed: an edit that drops a day has to drop its
            // passages with it. Member progress is keyed on the day number, which an edit never
            // moves, so nobody's streak is disturbed by a plan being re-worded.
            $db->prepare('DELETE FROM reading_plan_passages WHERE plan_id = ?')->execute(array($id));

            $insertPassage = $db->prepare('INSERT INTO reading_plan_passages (tenant_id, plan_id, day_number, sort_order, book, chapter_start, chapter_end, verse_start, verse_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($plan as $dayNumber => $passages) {
                foreach ($passages as $order => $passage) {
                    $insertPassage->execute(array(
                        self::tenantId(),
                        $id,
                        (int) $dayNumber,
                        (int) $order,
                        $passage['book'],
                        $passage['chapter_start'],
                        $passage['chapter_end'],
                        $passage['verse_start'],
                        $passage['verse_end'],
                    ));
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return array('ok' => false, 'errors' => array('Could not save the plan: ' . $e->getMessage()));
        }

        return array('ok' => true, 'id' => $id, 'updated' => $existing !== null, 'days' => $count);
    }

    /**
     * Every day of a plan, keyed by day number.
     *
     * Days with no passage come back as empty lists, because a plan that rests on Sundays has to
     * show those days in the editor and to the member reading ahead.
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function days(int $planId): array
    {
        $stmt = self::db()->prepare('SELECT * FROM reading_plan_passages WHERE plan_id = ? ORDER BY day_number ASC, sort_order ASC');
        $stmt->execute(array($planId));

        $days = array();
        foreach ($stmt->fetchAll() as $row) {
            $days[(int) $row['day_number']][] = $row;
        }
        ksort($days);
        return $days;
    }

    /** @return array<int,array<string,mixed>> */
    public static function passagesForDay(int $planId, int $dayNumber): array
    {
        $stmt = self::db()->prepare('SELECT * FROM reading_plan_passages WHERE plan_id = ? AND day_number = ? ORDER BY sort_order ASC');
        $stmt->execute(array($planId, $dayNumber));
        return $stmt->fetchAll();
    }

    /** The plan as the editor's text box wants it: one line per day, in order. */
    public static function editorText(int $planId): string
    {
        $plan = self::find($planId);
        if ($plan === null) {
            return '';
        }

        $days = self::days($planId);
        $total = (int) $plan['days_count'];
        if ($days !== array()) {
            $total = max($total, (int) max(array_keys($days)));
        }

        $lines = array();
        for ($day = 1; $day <= $total; $day++) {
            $lines[] = self::labelForDay($days[$day] ?? array());
        }
        return implode("\n", $lines);
    }

    /* --------------------------------------------------------------- progress */

    /** What a day's reading is called, or a note that there is none. */
    public static function dayLabel(int $planId, int $dayNumber): string
    {
        $label = self::labelForDay(self::passagesForDay($planId, $dayNumber));
        return $label !== '' ? $label : 'Rest day';
    }

    /**
     * Marks a day read.
     *
     * `INSERT IGNORE`, so marking a day twice keeps the first date rather than moving it. That is
     * deliberate: the streak counts when the reading actually happened, so a double click, a page
     * reload or a second device must not be able to stretch it.
     *
     * @return bool True when this call was the one that marked it.
     */
    public static function markRead(int $memberId, int $planId, int $dayNumber, ?string $on = null): bool
    {
        $stmt = self::db()->prepare('INSERT IGNORE INTO reading_progress (tenant_id, member_id, plan_id, day_number, completed_on) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute(array(
            self::tenantId(),
            $memberId,
            $planId,
            $dayNumber,
            $on !== null && $on !== '' ? $on : date('Y-m-d'),
        ));
        return $stmt->rowCount() > 0;
    }

    public static function unmarkRead(int $memberId, int $planId, int $dayNumber): bool
    {
        $stmt = self::db()->prepare('DELETE FROM reading_progress WHERE member_id = ? AND plan_id = ? AND day_number = ?');
        $stmt->execute(array($memberId, $planId, $dayNumber));
        return $stmt->rowCount() > 0;
    }

    /** @return array<int,int> The days completed, keyed and valued by day number, for isset(). */
    public static function completedDays(int $memberId, int $planId): array
    {
        $stmt = self::db()->prepare('SELECT day_number FROM reading_progress WHERE member_id = ? AND plan_id = ? ORDER BY day_number ASC');
        $stmt->execute(array($memberId, $planId));

        $days = array();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $day) {
            $days[(int) $day] = (int) $day;
        }
        return $days;
    }

    public static function hasReadToday(int $memberId, int $planId): bool
    {
        $stmt = self::db()->prepare('SELECT 1 FROM reading_progress WHERE member_id = ? AND plan_id = ? AND completed_on = CURDATE() LIMIT 1');
        $stmt->execute(array($memberId, $planId));
        return $stmt->fetchColumn() !== false;
    }

    /**
     * How many days running the member has read.
     *
     * Counted from the dates rather than from a running total, so it cannot drift: marking days out
     * of order, re-marking one, or correcting a mistake all land on the honest answer. A streak that
     * ended yesterday still counts, because the member may simply not have got to today's reading
     * yet; one that ended before that is over.
     */
    public static function streakFor(int $memberId): int
    {
        // 400 days is beyond any plan here, and the limit keeps the work bounded for a member who
        // has been reading for years.
        $stmt = self::db()->prepare('SELECT DISTINCT completed_on FROM reading_progress WHERE member_id = ? ORDER BY completed_on DESC LIMIT 400');
        $stmt->execute(array($memberId));
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($dates === array()) {
            return 0;
        }

        $mostRecent = (string) $dates[0];
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($mostRecent !== $today && $mostRecent !== $yesterday) {
            return 0;
        }

        $streak = 0;
        $expected = $mostRecent;
        foreach ($dates as $date) {
            if ((string) $date !== $expected) {
                break;
            }
            $streak++;
            $expected = date('Y-m-d', strtotime($expected . ' -1 day'));
        }
        return $streak;
    }

    /**
     * Everything a member's dashboard needs about their plan.
     *
     * @return array{done:int,total:int,percent:int,streak:int,read_today:bool,next_day:int|null}
     */
    public static function progressFor(int $memberId, int $planId): array
    {
        $plan = self::find($planId);
        $total = $plan !== null ? (int) $plan['days_count'] : 0;
        $done = count(self::completedDays($memberId, $planId));

        return array(
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
            'streak' => self::streakFor($memberId),
            'read_today' => self::hasReadToday($memberId, $planId),
            'next_day' => self::nextDay($memberId, $planId),
        );
    }

    /** The first day not yet marked, or null once the plan is finished. */
    public static function nextDay(int $memberId, int $planId): ?int
    {
        $plan = self::find($planId);
        $total = $plan !== null ? (int) $plan['days_count'] : 0;
        if ($total < 1) {
            return null;
        }

        $done = self::completedDays($memberId, $planId);
        for ($day = 1; $day <= $total; $day++) {
            if (!isset($done[$day])) {
                return $day;
            }
        }
        return null;
    }

    /**
     * Puts a member on a plan, or takes them off every plan with 0.
     *
     * Progress is not cleared when they switch, because it is keyed by plan: going back to a plan
     * they started months ago finds their place still marked rather than silently reset.
     */
    public static function join(int $memberId, int $planId): void
    {
        self::db()->prepare('UPDATE members SET reading_plan_id = ? WHERE id = ?')
            ->execute(array($planId > 0 ? $planId : null, $memberId));
    }

    /** The plan a member is following, or null. */
    public static function currentFor(int $memberId): ?array
    {
        $stmt = self::db()->prepare('SELECT reading_plan_id FROM members WHERE id = ? LIMIT 1');
        $stmt->execute(array($memberId));
        $planId = (int) $stmt->fetchColumn();
        return $planId > 0 ? self::find($planId) : null;
    }
}
