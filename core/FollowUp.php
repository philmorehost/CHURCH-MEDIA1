<?php
declare(strict_types=1);

/**
 * Following up a first-time visitor.
 *
 * A **sequence** is a named list of **steps**. A step is either:
 *
 *  - an **email** — free, goes out on its own, wording templated; or
 *  - a **task** — something a person has to do: ring them, visit them, introduce them to the pastor.
 *
 * Both kinds end up in `follow_up_actions`, one row per step per enrolment. That was a deliberate
 * choice over two tables: "what is outstanding for this visitor" is then one question with one
 * answer, and the page that answers it does not have to union two shapes together.
 *
 * **A newcomer need not have an email.** They arrived with a phone number, or nothing. So a sequence
 * is not an email campaign that happens to have tasks in it — it is a plan, and the email steps are
 * the part that can be done without anybody remembering. A visitor with no address still gets the
 * tasks queued; the page says plainly that the emails cannot reach them rather than skipping quietly.
 *
 * **Enrolment is relative, not dated.** `enrolled_on` is day 0 and every `day_offset` counts from
 * there, so one sequence works for a visitor who arrives on any day of the year and a church does not
 * have to rewrite its plan every January.
 *
 * Nothing here sends anything. `FollowUpRunner` decides what is due and sends it; this class is the
 * shape of the data and the rules about it, which is what makes those rules testable without a mail
 * server.
 */
final class FollowUp
{
    public const MAX_NAME = 150;
    public const MAX_DESCRIPTION = 255;
    public const MAX_SUBJECT = 200;
    public const MAX_TASK = 200;
    public const MAX_BODY = 20000;
    public const MAX_NOTE = 255;

    /** A step more than a year after somebody's first visit is a typo, not a plan. */
    public const MAX_DAY_OFFSET = 365;

    public const CHANNELS = ['email', 'task'];

    /** Mirrors the `newcomers.follow_up_status` enum, so the two lists cannot drift apart. */
    public const VISIT_STATUSES = ['new', 'contacted', 'followed_up', 'returned', 'inactive'];
    public const VISIT_LABELS = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'followed_up' => 'Followed up',
        'returned' => 'Returned',
        'inactive' => 'Inactive',
    ];

    /**
     * How long before silence counts as a stall.
     *
     * Two thresholds rather than one because the two states are not equally urgent. Somebody who has
     * never been contacted is three days late — a first visit goes cold within a week. Somebody who
     * was contacted and has not been since is quiet, not lost, and two weeks is when that becomes
     * worth chasing.
     */
    public const STALL_NEW_DAYS = 3;
    public const STALL_CONTACTED_DAYS = 14;

    /** Placeholders an email body and subject may use. */
    public const PLACEHOLDERS = ['{{name}}', '{{first_name}}', '{{church}}'];

    /** @var PDO|null */
    private static $pdo = null;

    private static function db(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = Database::getInstance()->getConnection();
        }
        return self::$pdo;
    }

    /* ================================================================ sequences == */

    /** Whether a sequence is inside the user's admin scope. */
    public static function inScope(?array $user, array $sequence): bool
    {
        return Unit::inScope($user, $sequence['org_unit_id'] === null ? null : (int) $sequence['org_unit_id']);
    }

    /**
     * Sequences the user may see, newest activity first.
     *
     * @return array<int, array<string, mixed>> each row gains `step_count` and `active_count`
     */
    public static function sequences(?array $user, bool $includeInactive = false): array
    {
        $pdo = self::db();
        $clause = Unit::scopeClause($user, 's.org_unit_id');

        $sql = 'SELECT s.*,'
            . ' (SELECT COUNT(*) FROM follow_up_steps st WHERE st.sequence_id = s.id) AS step_count,'
            . ' (SELECT COUNT(*) FROM follow_up_steps st WHERE st.sequence_id = s.id AND st.channel = \'email\') AS email_step_count,'
            . ' (SELECT COUNT(*) FROM follow_up_enrolments e WHERE e.sequence_id = s.id AND e.status = \'active\') AS active_count,'
            . ' (SELECT COUNT(*) FROM follow_up_enrolments e WHERE e.sequence_id = s.id) AS enrolled_count'
            . ' FROM follow_up_sequences s'
            . ' WHERE 1 = 1'
            . ($clause !== '' ? ' AND ' . $clause : '')
            . ($includeInactive ? '' : ' AND s.is_active = 1')
            . ' ORDER BY s.is_active DESC, s.name ASC';

        return $pdo->query($sql)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function findSequence(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM follow_up_sequences WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Creates or updates a sequence.
     *
     * @param array<string, mixed> $data
     * @return array{ok: bool, id?: int, errors?: array<int, string>}
     */
    public static function saveSequence(?array $user, int $id, array $data): array
    {
        $errors = [];
        $name = self::trimTo((string) ($data['name'] ?? ''), self::MAX_NAME);
        $description = self::trimTo((string) ($data['description'] ?? ''), self::MAX_DESCRIPTION);
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($name === '') {
            $errors[] = 'Give the sequence a name — "First visit follow-up", for example.';
        }

        // A sequence belongs to a church. Falling back to the creator's own unit means a scoped
        // admin's form needs no picker and still lands somewhere they can see it afterwards.
        $unitId = isset($data['org_unit_id']) && (int) $data['org_unit_id'] > 0
            ? (int) $data['org_unit_id']
            : (int) ($user['org_unit_id'] ?? 0);
        $unitId = $unitId > 0 ? $unitId : null;

        if ($unitId !== null && !Unit::inScope($user, $unitId)) {
            $errors[] = 'Choose one of your own churches for this sequence.';
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $pdo = self::db();
        if ($id > 0) {
            $pdo->prepare('UPDATE follow_up_sequences SET name = ?, description = ?, is_active = ?, org_unit_id = ? WHERE id = ?')
                ->execute([$name, $description !== '' ? $description : null, $isActive, $unitId, $id]);
            return ['ok' => true, 'id' => $id];
        }

        $pdo->prepare('INSERT INTO follow_up_sequences (tenant_id, org_unit_id, name, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([Tenant::id(), $unitId, $name, $description !== '' ? $description : null, $isActive, (int) ($user['id'] ?? 0) ?: null]);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }

    /**
     * Deletes a sequence and its steps.
     *
     * Refused once anybody has been enrolled, for the same reason a roster role with people on it is
     * refused: the cascade would take the record of what was actually sent to real people, and that
     * record is closer to history than to configuration. Deactivate it instead — that stops the work
     * without erasing it.
     *
     * @return array{ok: bool, errors?: array<int, string>}
     */
    public static function deleteSequence(int $id): array
    {
        $pdo = self::db();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM follow_up_enrolments WHERE sequence_id = ?");
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            return ['ok' => false, 'errors' => ['People have already been enrolled in this sequence, so it cannot be deleted — switch it off instead.']];
        }

        $pdo->prepare('DELETE FROM follow_up_sequences WHERE id = ?')->execute([$id]);
        return ['ok' => true];
    }

    /* ==================================================================== steps == */

    /**
     * @return array<int, array<string, mixed>> ordered by when they happen
     */
    public static function steps(int $sequenceId, bool $activeOnly = false): array
    {
        $stmt = self::db()->prepare(
            'SELECT * FROM follow_up_steps WHERE sequence_id = ?'
            . ($activeOnly ? ' AND is_active = 1' : '')
            . ' ORDER BY day_offset ASC, id ASC'
        );
        $stmt->execute([$sequenceId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function findStep(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM follow_up_steps WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, id?: int, errors?: array<int, string>}
     */
    public static function saveStep(int $sequenceId, int $stepId, array $data): array
    {
        $errors = [];
        $offset = (int) ($data['day_offset'] ?? 0);
        $channel = in_array((string) ($data['channel'] ?? 'email'), self::CHANNELS, true)
            ? (string) $data['channel']
            : 'email';
        $subject = self::trimTo((string) ($data['subject'] ?? ''), self::MAX_SUBJECT);
        $body = self::trimTo((string) ($data['body'] ?? ''), self::MAX_BODY);
        $taskLabel = self::trimTo((string) ($data['task_label'] ?? ''), self::MAX_TASK);
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($offset < 0 || $offset > self::MAX_DAY_OFFSET) {
            $errors[] = 'The day must be between 0 and ' . self::MAX_DAY_OFFSET . ' after the first visit.';
        }

        if ($channel === 'email') {
            if ($subject === '') {
                $errors[] = 'An email step needs a subject line.';
            }
            if ($body === '') {
                $errors[] = 'An email step needs some words in it.';
            }
        } elseif ($taskLabel === '') {
            $errors[] = 'Say what has to be done — "Ring them", "Visit them", "Introduce them to a cell leader".';
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $pdo = self::db();
        $values = [
            $offset,
            $channel,
            $subject !== '' ? $subject : null,
            $body !== '' ? $body : null,
            $taskLabel !== '' ? $taskLabel : null,
            $isActive,
        ];

        if ($stepId > 0) {
            $values[] = $stepId;
            $pdo->prepare('UPDATE follow_up_steps SET day_offset = ?, channel = ?, subject = ?, body = ?, task_label = ?, is_active = ? WHERE id = ?')
                ->execute($values);
            return ['ok' => true, 'id' => $stepId];
        }

        $values[] = $sequenceId;
        $pdo->prepare('INSERT INTO follow_up_steps (day_offset, channel, subject, body, task_label, is_active, sequence_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute($values);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }

    /**
     * Removes a step, but only while nothing has happened because of it.
     *
     * Once an action row exists the step has either sent something or put a task in front of
     * somebody, and deleting it would take that with it. Switching it off is the honest way to stop
     * using it — that leaves the history readable.
     *
     * @return array{ok: bool, errors?: array<int, string>}
     */
    public static function deleteStep(int $stepId): array
    {
        $pdo = self::db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM follow_up_actions WHERE step_id = ?');
        $stmt->execute([$stepId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return ['ok' => false, 'errors' => ['This step has already gone out to people, so it cannot be deleted — switch it off instead.']];
        }

        $pdo->prepare('DELETE FROM follow_up_steps WHERE id = ?')->execute([$stepId]);
        return ['ok' => true];
    }

    /**
     * A sensible starting plan, so a new church is not staring at a blank form.
     *
     * These are the offsets most churches would choose anyway — same day, then day 3, 7, 14, 30 — and
     * both email steps show a placeholder so it is obvious the wording can be personalised rather
     * than reading as the product's own voice.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function starterSteps(): array
    {
        return [
            [
                'day_offset' => 0,
                'channel' => 'email',
                'subject' => 'Thank you for worshipping with us',
                'body' => "Dear {{first_name}},\n\nIt was a joy to have you with us at {{church}}. Thank you for coming — we would love to see you again.\n\nIf there is anything we can pray with you about, or anything you would like to ask, simply reply to this email.\n\nGod bless,\n{{church}}",
                'is_active' => 1,
            ],
            [
                'day_offset' => 3,
                'channel' => 'task',
                'task_label' => 'Ring {{first_name}} and see how they are settling in',
                'is_active' => 1,
            ],
            [
                'day_offset' => 7,
                'channel' => 'email',
                'subject' => 'We would love to see you again this Sunday',
                'body' => "Dear {{first_name}},\n\nWe have been praying for you this week. Our services are on again this Sunday and there is a place for you.\n\nIf you would like to meet somebody first, or find a home cell near you, just reply and we will sort it out.\n\nWith love,\n{{church}}",
                'is_active' => 1,
            ],
            [
                'day_offset' => 14,
                'channel' => 'task',
                'task_label' => 'Invite {{first_name}} to a home cell',
                'is_active' => 1,
            ],
        ];
    }

    /* =============================================================== enrolments == */

    /**
     * Puts a newcomer into a sequence.
     *
     * Idempotent by design. The unique key on (newcomer_id, sequence_id) is the guarantee, but the
     * existing row is checked first so a second click reads as "already enrolled" instead of an
     * error — and the insert is still wrapped, because two admins clicking at the same moment is
     * exactly the race the key exists for.
     *
     * A newcomer marked `inactive` is refused rather than enrolled and immediately stopped. There is
     * no reason to start something whose only outcome is to be cancelled, and doing it anyway would
     * put a confusing enrolment in the history.
     *
     * @return array{ok: bool, id?: int, existing?: bool, errors?: array<int, string>}
     */
    public static function enrol(int $newcomerId, int $sequenceId, array $options = []): array
    {
        $pdo = self::db();

        $stmt = $pdo->prepare('SELECT id, follow_up_status FROM newcomers WHERE id = ?');
        $stmt->execute([$newcomerId]);
        $newcomer = $stmt->fetch();
        if ($newcomer === false) {
            return ['ok' => false, 'errors' => ['That newcomer no longer exists.']];
        }
        if ((string) $newcomer['follow_up_status'] === 'inactive') {
            return ['ok' => false, 'errors' => ['That newcomer is marked inactive, so there is nothing to follow up.']];
        }

        $sequence = self::findSequence($sequenceId);
        if ($sequence === null) {
            return ['ok' => false, 'errors' => ['That sequence no longer exists.']];
        }

        $stmt = $pdo->prepare('SELECT id FROM follow_up_enrolments WHERE newcomer_id = ? AND sequence_id = ?');
        $stmt->execute([$newcomerId, $sequenceId]);
        $existingId = $stmt->fetchColumn();
        if ($existingId !== false) {
            return ['ok' => true, 'id' => (int) $existingId, 'existing' => true];
        }

        // Day 0 is the day they are enrolled, not the day they first visited. Backdating is allowed
        // for a church catching up on last month's visitors, but note what it does: every step up to
        // today becomes due at once. The runner sends at most one email per enrolment per run for
        // exactly that reason, so a backdated enrolment drips out rather than arriving as a burst.
        $enrolledOn = (string) ($options['enrolled_on'] ?? '');
        $enrolledOn = self::validDate($enrolledOn) ? $enrolledOn : date('Y-m-d');

        try {
            $pdo->prepare('INSERT INTO follow_up_enrolments (sequence_id, newcomer_id, enrolled_on, status, enrolled_by) VALUES (?, ?, ?, \'active\', ?)')
                ->execute([$sequenceId, $newcomerId, $enrolledOn, (int) ($options['enrolled_by'] ?? 0) ?: null]);
        } catch (PDOException $e) {
            // 23000 is the duplicate-key race: somebody else enrolled them between the check and the
            // insert. That is the outcome we wanted anyway.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $stmt = $pdo->prepare('SELECT id FROM follow_up_enrolments WHERE newcomer_id = ? AND sequence_id = ?');
            $stmt->execute([$newcomerId, $sequenceId]);
            return ['ok' => true, 'id' => (int) $stmt->fetchColumn(), 'existing' => true];
        }

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }

    /** @return array<string, mixed>|null */
    public static function findEnrolment(int $id): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT e.*, s.name AS sequence_name, s.org_unit_id AS sequence_unit_id,'
            . ' n.name AS newcomer_name, n.email AS newcomer_email, n.whatsapp_phone AS newcomer_phone,'
            . ' n.follow_up_status AS newcomer_status, n.org_unit_id AS newcomer_unit_id'
            . ' FROM follow_up_enrolments e'
            . ' JOIN follow_up_sequences s ON s.id = e.sequence_id'
            . ' JOIN newcomers n ON n.id = e.newcomer_id'
            . ' WHERE e.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public static function enrolmentsFor(int $newcomerId): array
    {
        $stmt = self::db()->prepare(
            'SELECT e.*, s.name AS sequence_name'
            . ' FROM follow_up_enrolments e'
            . ' JOIN follow_up_sequences s ON s.id = e.sequence_id'
            . ' WHERE e.newcomer_id = ?'
            . ' ORDER BY e.created_at DESC, e.id DESC'
        );
        $stmt->execute([$newcomerId]);
        return $stmt->fetchAll();
    }

    /**
     * Stops one enrolment. The reason is kept, because "we gave up" and "they asked us to stop" are
     * different facts and only one of them is a complaint.
     */
    public static function stop(int $enrolmentId, string $reason, ?int $userId = null): void
    {
        self::db()->prepare(
            "UPDATE follow_up_enrolments SET status = 'stopped', stopped_reason = ?, stopped_at = NOW() WHERE id = ? AND status = 'active'"
        )->execute([self::trimTo($reason, self::MAX_DESCRIPTION) ?: null, $enrolmentId]);
    }

    /**
     * Stops every running sequence for one newcomer.
     *
     * Called when somebody is marked inactive — "a person who opts out mid-sequence stops
     * immediately" is a promise the product makes, and the moment to keep it is the moment the
     * status changes rather than the next time a worker happens to run. The runner checks the status
     * too, so this is the second of two guards rather than the only one.
     *
     * @return int how many enrolments were stopped
     */
    public static function stopAllFor(int $newcomerId, string $reason): int
    {
        $stmt = self::db()->prepare(
            "UPDATE follow_up_enrolments SET status = 'stopped', stopped_reason = ?, stopped_at = NOW() WHERE newcomer_id = ? AND status = 'active'"
        );
        $stmt->execute([self::trimTo($reason, self::MAX_DESCRIPTION) ?: null, $newcomerId]);
        return $stmt->rowCount();
    }

    /**
     * Marks every enrolment that has run out of steps as finished.
     *
     * Distinct from `stopped`: `finished` means the plan completed, `stopped` means it was cut short.
     * Both stop the work; only the reason differs, and a report that cannot tell them apart cannot
     * answer "how many of our visitors did we see through".
     *
     * **What counts as "reached" depends on the channel, and that distinction is the whole point.**
     * A task step has been reached as soon as its row exists — it is on somebody's list, and whether
     * a human has done it is a different question, tracked by the task list rather than here. An
     * email step has only been reached once `sent_at` is set. Treating a merely *attempted* email as
     * reached would mark the enrolment finished while the message had never left, which drops the
     * visitor out of the sequence silently and stops the retry that was about to fix it.
     *
     * An enrolment in a sequence with no active steps is deliberately left alone rather than
     * finished: switching every step off is how a church pauses a sequence, and the enrolments should
     * still be there when it switches them back on.
     */
    public static function closeFinished(PDO $pdo): int
    {
        $sql = "UPDATE follow_up_enrolments e
                SET e.status = 'finished'
                WHERE e.status = 'active'
                  AND EXISTS (SELECT 1 FROM follow_up_steps st WHERE st.sequence_id = e.sequence_id AND st.is_active = 1)
                  AND NOT EXISTS (
                      SELECT 1 FROM follow_up_steps st
                      WHERE st.sequence_id = e.sequence_id
                        AND st.is_active = 1
                        AND NOT EXISTS (
                            SELECT 1 FROM follow_up_actions a
                            WHERE a.enrolment_id = e.id
                              AND a.step_id = st.id
                              AND (a.channel = 'task' OR a.sent_at IS NOT NULL)
                        )
                  )";
        return (int) $pdo->exec($sql);
    }

    /* ================================================================== actions == */

    /**
     * The plan for one visitor: every active step in order, with the action row beside it if that
     * step has already happened.
     *
     * The projection is computed rather than stored, so a step added this morning shows up on every
     * existing enrolment without a backfill — and a step nobody has reached yet costs no row.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function timeline(int $enrolmentId): array
    {
        $pdo = self::db();
        $stmt = $pdo->prepare('SELECT enrolled_on FROM follow_up_enrolments WHERE id = ?');
        $stmt->execute([$enrolmentId]);
        $enrolledOn = $stmt->fetchColumn();
        if ($enrolledOn === false) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT st.*, a.id AS action_id, a.channel AS action_channel, a.due_on, a.sent_at, a.done_at,'
            . ' a.done_by, a.note, a.error, u.name AS done_by_name'
            . ' FROM follow_up_steps st'
            . ' LEFT JOIN follow_up_actions a ON a.step_id = st.id AND a.enrolment_id = ?'
            . ' LEFT JOIN users u ON u.id = a.done_by'
            . ' WHERE st.sequence_id = (SELECT sequence_id FROM follow_up_enrolments WHERE id = ?)'
            . ' AND st.is_active = 1'
            . ' ORDER BY st.day_offset ASC, st.id ASC'
        );
        $stmt->execute([$enrolmentId, $enrolmentId]);
        $rows = $stmt->fetchAll();

        $base = strtotime((string) $enrolledOn);
        foreach ($rows as &$row) {
            $offset = (int) $row['day_offset'];
            $row['planned_on'] = date('Y-m-d', strtotime('+' . $offset . ' day', $base));
            $row['is_done'] = $row['sent_at'] !== null || $row['done_at'] !== null;
            $row['is_overdue'] = !$row['is_done'] && $row['planned_on'] < date('Y-m-d');
        }
        unset($row);

        return $rows;
    }

    /**
     * Tasks somebody still has to do, across everyone the user can see.
     *
     * Only enrolments that are still running are considered: stopping a sequence is how a church says
     * "we are not doing this any more", and leaving its tasks on the list would make that meaningless.
     * The rows themselves are kept, so the history stays readable.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function openTasks(?array $user, int $limit = 200): array
    {
        $clause = Unit::scopeClause($user, 'n.org_unit_id');

        $stmt = self::db()->prepare(
            'SELECT a.id, a.due_on, a.note, e.id AS enrolment_id,'
            . ' st.task_label, st.day_offset, s.name AS sequence_name,'
            . ' n.id AS newcomer_id, n.name AS newcomer_name, n.whatsapp_phone, n.email, n.follow_up_status'
            . ' FROM follow_up_actions a'
            . ' JOIN follow_up_enrolments e ON e.id = a.enrolment_id'
            . ' JOIN follow_up_steps st ON st.id = a.step_id'
            . ' JOIN follow_up_sequences s ON s.id = e.sequence_id'
            . ' JOIN newcomers n ON n.id = e.newcomer_id'
            . " WHERE a.channel = 'task' AND a.done_at IS NULL AND e.status = 'active'"
            . ($clause !== '' ? ' AND ' . $clause : '')
            . ' ORDER BY a.due_on ASC, a.id ASC LIMIT ' . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** How many tasks are outstanding, for the nav badge and the dashboard. */
    public static function openTaskCount(?array $user): int
    {
        $clause = Unit::scopeClause($user, 'n.org_unit_id');

        $stmt = self::db()->prepare(
            'SELECT COUNT(*) FROM follow_up_actions a'
            . ' JOIN follow_up_enrolments e ON e.id = a.enrolment_id'
            . ' JOIN newcomers n ON n.id = e.newcomer_id'
            . " WHERE a.channel = 'task' AND a.done_at IS NULL AND e.status = 'active'"
            . ($clause !== '' ? ' AND ' . $clause : '')
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Ticks a task off.
     *
     * The "not already done" condition is in the WHERE clause rather than in a PHP branch, the same
     * way the roster's owner check is: a second click, or two admins at once, changes nothing.
     *
     * @return array{ok: bool, errors?: array<int, string>}
     */
    public static function completeTask(int $actionId, int $userId, string $note = ''): array
    {
        $pdo = self::db();
        $stmt = $pdo->prepare("UPDATE follow_up_actions SET done_at = NOW(), done_by = ?, note = ? WHERE id = ? AND channel = 'task' AND done_at IS NULL");
        $stmt->execute([$userId > 0 ? $userId : null, self::trimTo($note, self::MAX_NOTE) ?: null, $actionId]);

        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'errors' => ['That task is not one you can tick off — it may already be done.']];
        }
        return ['ok' => true];
    }

    /* ================================================================ dashboard == */

    /**
     * How many visitors sit in each state, plus how many need attention.
     *
     * @return array<string, int> keyed by status, with `total` and `stalled` alongside
     */
    public static function pipeline(?array $user): array
    {
        $clause = Unit::scopeClause($user, 'org_unit_id');

        $stmt = self::db()->prepare(
            'SELECT follow_up_status, COUNT(*) AS c FROM newcomers WHERE 1 = 1'
            . ($clause !== '' ? ' AND ' . $clause : '')
            . ' GROUP BY follow_up_status'
        );
        $stmt->execute();

        $out = array_fill_keys(self::VISIT_STATUSES, 0);
        $out['total'] = 0;
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) $row['follow_up_status'];
            $count = (int) $row['c'];
            $out[$key] = $count;
            $out['total'] += $count;
        }
        $out['stalled'] = count(self::stalled($user, 500));

        return $out;
    }

    /**
     * Visitors nobody has managed to move.
     *
     * "Stalled" is measured from a different clock in each state, on purpose. Somebody who has never
     * been contacted is judged from when they first appeared — `created_at` — because that is how long
     * the church has had their name. Somebody already contacted is judged from `updated_at`, because
     * the question there is how long since anyone last did anything, and a note added yesterday means
     * somebody did do something.
     *
     * Visitors marked `returned`, `followed_up` or `inactive` are not stalled: the first two have
     * been dealt with, and the last is deliberately closed.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function stalled(?array $user, int $limit = 50): array
    {
        $clause = Unit::scopeClause($user, 'n.org_unit_id');

        $sql = 'SELECT n.id, n.name, n.email, n.whatsapp_phone, n.follow_up_status, n.visit_date,'
            . ' n.created_at, n.updated_at,'
            . ' DATEDIFF(CURDATE(), CASE WHEN n.follow_up_status = \'new\' THEN n.created_at ELSE n.updated_at END) AS days_idle'
            . ' FROM newcomers n'
            . ' WHERE n.follow_up_status IN (\'new\', \'contacted\')'
            . ' AND ('
            . '   (n.follow_up_status = \'new\' AND n.created_at <= DATE_SUB(CURDATE(), INTERVAL ' . self::STALL_NEW_DAYS . ' DAY))'
            . '   OR (n.follow_up_status = \'contacted\' AND n.updated_at <= DATE_SUB(CURDATE(), INTERVAL ' . self::STALL_CONTACTED_DAYS . ' DAY))'
            . ' )'
            . ($clause !== '' ? ' AND ' . $clause : '')
            . ' ORDER BY days_idle DESC, n.id ASC LIMIT ' . (int) $limit;

        return self::db()->query($sql)->fetchAll();
    }

    /**
     * Visitors who are part of a running sequence but have no email address, so its email steps
     * cannot reach them.
     *
     * This exists to be shown rather than acted on. A sequence that half-works is the exact failure
     * this whole phase is trying to avoid, and the person who can fix it — ask the visitor for an
     * address, or ring them instead — is the one reading the page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function unreachable(?array $user, int $limit = 50): array
    {
        $clause = Unit::scopeClause($user, 'n.org_unit_id');

        $stmt = self::db()->prepare(
            'SELECT DISTINCT n.id, n.name, n.whatsapp_phone, e.id AS enrolment_id, s.name AS sequence_name'
            . ' FROM follow_up_enrolments e'
            . ' JOIN follow_up_sequences s ON s.id = e.sequence_id'
            . ' JOIN newcomers n ON n.id = e.newcomer_id'
            . " WHERE e.status = 'active' AND (n.email IS NULL OR n.email = '')"
            . ($clause !== '' ? ' AND ' . $clause : '')
            . ' ORDER BY n.name ASC LIMIT ' . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /* ================================================================== helpers == */

    /**
     * Fills an email's placeholders.
     *
     * Unknown placeholders are left alone rather than blanked. A body that reads "Dear {{frist_name}}"
     * because of a typo is visibly wrong and gets fixed; one that reads "Dear ," looks like the
     * software is broken and gets the whole feature blamed.
     */
    public static function render(string $text, array $newcomer): string
    {
        $name = trim((string) ($newcomer['name'] ?? ''));
        $first = $name === '' ? '' : preg_split('/\s+/', $name)[0];

        return str_replace(
            ['{{name}}', '{{first_name}}', '{{church}}'],
            [$name, $first, (string) setting('site_title', '')],
            $text
        );
    }

    /** Where an offset lands, as a date, for showing "12 March" beside a step. */
    public static function offsetLabel(int $days): string
    {
        if ($days === 0) {
            return 'Same day';
        }
        if ($days === 1) {
            return 'Next day';
        }
        return $days . ' days after';
    }

    /** Strict Y-m-d, because `strtotime()` accepts far too much. */
    public static function validDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Trim, and refuse to store more than the column holds rather than let MySQL truncate it. */
    private static function trimTo(string $value, int $max): string
    {
        $value = trim($value);
        return mb_substr($value, 0, $max);
    }
}
