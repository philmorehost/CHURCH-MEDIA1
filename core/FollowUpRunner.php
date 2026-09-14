<?php
declare(strict_types=1);

/**
 * Decides what a follow-up sequence owes today, and does it.
 *
 * Kept apart from `FollowUp` on the same principle as `RosterNotifier` versus `ServiceRoster`: the
 * decision rules — who is due, what they get, what is skipped — are the part that can be wrong in a
 * way nobody notices, and they should be assertable without a mail server in the way.
 *
 * **Email is the only thing that sends itself.** A `task` step creates a row on somebody's list and
 * stops there. Nothing here sends a text message: an automatic SMS spends the church's wallet per
 * message, so it belongs in an explicit decision with a setting and a price attached, not in the same
 * change as free email.
 *
 * Three rules worth stating plainly, because each of them is a decision rather than an accident:
 *
 *  - **One email per person per run, at most.** A church enrolling last month's visitors backdates
 *    `enrolled_on`, and every step up to today becomes due at once. Without this rule the visitor gets
 *    the whole sequence in one minute, which is how a follow-up becomes a complaint. The earliest due
 *    step goes now; the rest catch up over the following runs.
 *  - **A failure is recorded and retried, up to a point.** The action row is written *before* sending,
 *    so the unique key on (enrolment, step) is what stops a double-running cron sending twice. A
 *    failed send keeps its row — with the error on it, visible to whoever looks — and becomes
 *    eligible again after an hour, because the failures worth retrying are the transient ones
 *    (a mail server briefly refusing). After a handful of attempts it stops retrying, so a mistyped
 *    address is not for ever.
 *  - **A newcomer marked inactive is not followed up**, checked here as well as when the status was
 *    set. Somebody has to be able to stop this at 2am without editing a sequence.
 *
 * **One church at a time.** `follow_up_sequences.tenant_id` is the church a sequence belongs to, so
 * `due()` is scoped to it and the caller — `cli/followup_worker.php` — makes one pass per church through
 * `Tenant::each()`. A cron has no request host, so without that the church being served is only ever the
 * default one, and the run emailed every church's visitors a sequence belonging to whichever church
 * resolved first. `newcomers` carries no `tenant_id` of its own, so the sequence is the only church these
 * rules have to go on — and it is the right one, because the sequence owns the words that get sent.
 */
final class FollowUpRunner
{
    /** Enrolments examined per run. */
    public const BATCH_LIMIT = 500;

    /** At most one email per person per run — see the note above about backdated enrolments. */
    public const MAX_EMAILS_PER_ENROLMENT = 1;

    /** How long a claim is held before another run may retry it. */
    public const RETRY_AFTER_MINUTES = 60;

    /** Attempts before an address is treated as broken rather than busy. */
    public const MAX_ATTEMPTS = 5;

    /** @var PDO|null */
    private static $pdo = null;

    /** @var callable|null */
    private static $mailer = null;

    /** The church being served. 0 never matches a row, so an unresolvable church reaches nobody. */
    private static function tenantId(): int
    {
        return (class_exists('Tenant') ? Tenant::id() : null) ?? 0;
    }

    private static function db(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = Database::getInstance()->getConnection();
        }
        return self::$pdo;
    }

    /**
     * Substitutes the mail transport, the same shape as `RosterNotifier::setMailer()`.
     *
     * The claim behaviour and the one-email-per-run rule are the parts whose bugs are invisible in
     * production — a duplicate is merely annoying and a silent skip looks like nothing happened —
     * and neither should need a working SMTP server to assert.
     */
    public static function setMailer(?callable $mailer): void
    {
        self::$mailer = $mailer;
    }

    private static function mail(string $to, string $subject, string $body): bool
    {
        if (self::$mailer !== null) {
            return (bool) (self::$mailer)($to, $subject, $body);
        }
        return Mailer::send($to, $subject, $body);
    }

    /**
     * Everything the runner considers, before any of the per-run limits are applied.
     *
     * Side-effect free, so the rules can be asserted directly rather than inferred from who happened
     * to get a message. Rows already past their attempt limit are excluded here, which is what makes
     * that limit real rather than decorative.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function due(PDO $pdo): array
    {
        $stale = 'DATE_SUB(NOW(), INTERVAL ' . self::RETRY_AFTER_MINUTES . ' MINUTE)';

        $sql = 'SELECT e.id AS enrolment_id, e.enrolled_on, e.sequence_id,'
            . ' s.name AS sequence_name, s.org_unit_id,'
            . ' n.id AS newcomer_id, n.name AS newcomer_name, n.email AS newcomer_email, n.follow_up_status,'
            . ' st.id AS step_id, st.day_offset, st.channel, st.subject, st.body, st.task_label,'
            . ' a.id AS action_id, a.attempts,'
            . ' DATE_ADD(e.enrolled_on, INTERVAL st.day_offset DAY) AS due_on'
            . ' FROM follow_up_enrolments e'
            . ' JOIN follow_up_sequences s ON s.id = e.sequence_id'
            . ' JOIN newcomers n ON n.id = e.newcomer_id'
            . ' JOIN follow_up_steps st ON st.sequence_id = e.sequence_id'
            . ' LEFT JOIN follow_up_actions a ON a.enrolment_id = e.id AND a.step_id = st.id'
            . " WHERE e.status = 'active'"
            . ' AND s.is_active = 1'
            . ' AND s.tenant_id = ?'
            . ' AND st.is_active = 1'
            // Second guard, not the only one. Setting somebody inactive also stops their enrolments;
            // this is here so a row that was somehow missed still cannot be chased.
            . " AND n.follow_up_status <> 'inactive'"
            // An email step for a visitor who left no address cannot be sent. Without this the runner
            // tried to send to an empty string, which the mail server refused — so the attempt was
            // recorded as a failure, retried five times, and sat on the Follow-up page as a broken
            // sequence. A task step still comes through, which is the point: a visitor who left only a
            // phone number is the common case, and the tasks are the part of a sequence that reaches
            // everybody. `FollowUp::unreachable()` is where these visitors are meant to appear.
            . " AND (st.channel <> 'email' OR (n.email IS NOT NULL AND n.email <> ''))"
            . ' AND DATE_ADD(e.enrolled_on, INTERVAL st.day_offset DAY) <= CURDATE()'
            . ' AND ('
            // Never attempted: no row yet, so the insert is the claim.
            . '   a.id IS NULL'
            // Attempted and failed, long enough ago, and not yet given up on.
            . "   OR (a.channel = 'email' AND a.sent_at IS NULL AND a.attempts < " . self::MAX_ATTEMPTS
            . '       AND (a.claimed_at IS NULL OR a.claimed_at < ' . $stale . '))'
            . ' )'
            . ' ORDER BY e.id ASC, st.day_offset ASC, st.id ASC LIMIT ' . self::BATCH_LIMIT;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(self::tenantId()));
        return $stmt->fetchAll();
    }

    /**
     * Who would be written to right now, and why — the answer to "did this thing do anything".
     *
     * Applies the one-email-per-run rule, so the dry run shows the same set a real run would use
     * rather than a superset that makes it look busier than it is.
     *
     * @return array<int, array<string, mixed>> each row gains `kind`: 'email' or 'task'
     */
    public static function targets(PDO $pdo): array
    {
        $emailsSeen = [];
        $out = [];

        foreach (self::due($pdo) as $row) {
            if ((string) $row['channel'] === 'task') {
                $row['kind'] = 'task';
                $out[] = $row;
                continue;
            }

            $enrolmentId = (int) $row['enrolment_id'];
            $already = $emailsSeen[$enrolmentId] ?? 0;
            if ($already >= self::MAX_EMAILS_PER_ENROLMENT) {
                continue;
            }
            $emailsSeen[$enrolmentId] = $already + 1;
            $row['kind'] = 'email';
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Does the work.
     *
     * There is deliberately no "the switch is off, so nothing happened" result here, unlike the SMS
     * and roster workers. The control for this feature is each sequence's own on/off switch, which is
     * on the page an admin already uses — a second, less discoverable switch that did the same thing
     * would be one more control that can be set wrong and then blamed on the software.
     *
     * @return array{sent: int, failed: int, tasks: int}
     */
    public static function run(bool $dryRun = false): array
    {
        $pdo = self::db();
        $summary = ['sent' => 0, 'failed' => 0, 'tasks' => 0];

        $targets = self::targets($pdo);
        if ($dryRun) {
            foreach ($targets as $row) {
                if ((string) $row['kind'] === 'email') {
                    $summary['sent']++;
                } else {
                    $summary['tasks']++;
                }
            }
            return $summary;
        }

        foreach ($targets as $row) {
            $stepId = (int) $row['step_id'];
            $enrolmentId = (int) $row['enrolment_id'];

            if ((string) $row['kind'] === 'task') {
                if (self::claimTask($pdo, $enrolmentId, $stepId, (string) $row['due_on'])) {
                    $summary['tasks']++;
                }
                continue;
            }

            $actionId = self::claimEmail($pdo, $enrolmentId, $stepId, (string) $row['due_on'], (int) ($row['action_id'] ?? 0) ?: null);
            if ($actionId === null) {
                // Somebody else got there first. The unique key did its job.
                continue;
            }

            // `due()` aliases the newcomer's columns to keep them apart from the step's, so the
            // placeholder lookup has to be told which is the name. Passing the row straight through
            // silently produced "Dear ," — a real email, sent, with the one personal thing in it
            // missing.
            $person = ['name' => (string) ($row['newcomer_name'] ?? '')];
            $subject = FollowUp::render((string) ($row['subject'] ?? ''), $person);
            $body = FollowUp::render((string) ($row['body'] ?? ''), $person);

            if (self::mail((string) $row['newcomer_email'], $subject, $body)) {
                $pdo->prepare('UPDATE follow_up_actions SET sent_at = NOW(), error = NULL WHERE id = ?')->execute([$actionId]);
                $summary['sent']++;
            } else {
                // Kept, not deleted: the row is the record of the attempt, its error is what the page
                // shows, and it stays eligible for a retry until the attempt limit is reached. The
                // attempt is counted when it is claimed rather than here, so a failure does not count
                // twice.
                $pdo->prepare('UPDATE follow_up_actions SET error = ? WHERE id = ?')
                    ->execute(['The mail server refused it', $actionId]);
                $summary['failed']++;
            }
        }

        // Enrolments with nothing left to do are closed here rather than in a second command, so a
        // "finished" count is always current after a run. Scoped to the church being served, because a
        // run made as one church has no business writing another church's enrolment rows — and "the
        // outcome would have been the same anyway" is the argument that quietly erases the boundary.
        FollowUp::closeFinished($pdo, self::tenantId());

        return $summary;
    }

    /**
     * Writes the row that claims an email. Returns null if somebody else already holds it.
     *
     * This is the whole idempotency story: an INSERT that only one caller can win because of the
     * unique key, rather than a check-then-act in PHP that a future edit could weaken.
     */
    private static function claimEmail(PDO $pdo, int $enrolmentId, int $stepId, string $dueOn, ?int $existingActionId): ?int
    {
        if ($existingActionId !== null) {
            // A retry. The claim is refreshed conditionally — so two runners cannot both decide to
            // retry — and the attempt is counted here, at the moment it is made, rather than in the
            // failure branch where a single failure would be counted twice.
            //
            // The EXISTS is the church guard. The INSERT path below cannot carry one (the row it
            // writes has no church of its own — it is reached through the enrolment), so it relies on
            // `due()` having been scoped; this path can, and does, so an id that somehow arrived from
            // another church still cannot have its attempt count or its claim touched.
            $stmt = $pdo->prepare(
                'UPDATE follow_up_actions SET claimed_at = NOW(), attempts = attempts + 1'
                . ' WHERE id = ? AND sent_at IS NULL AND attempts < ' . self::MAX_ATTEMPTS
                . ' AND (claimed_at IS NULL OR claimed_at < DATE_SUB(NOW(), INTERVAL ' . self::RETRY_AFTER_MINUTES . ' MINUTE))'
                . ' AND EXISTS (SELECT 1 FROM follow_up_enrolments e JOIN follow_up_sequences s ON s.id = e.sequence_id'
                . ' WHERE e.id = follow_up_actions.enrolment_id AND s.tenant_id = ?)'
            );
            $stmt->execute(array($existingActionId, self::tenantId()));
            return $stmt->rowCount() === 1 ? $existingActionId : null;
        }

        try {
            $pdo->prepare("INSERT INTO follow_up_actions (enrolment_id, step_id, channel, due_on, claimed_at, attempts) VALUES (?, ?, 'email', ?, NOW(), 1)")
                ->execute([$enrolmentId, $stepId, $dueOn]);
            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return null;
            }
            throw $e;
        }
    }

    /** Writes the row that puts a task on somebody's list, once. */
    private static function claimTask(PDO $pdo, int $enrolmentId, int $stepId, string $dueOn): bool
    {
        try {
            $pdo->prepare("INSERT INTO follow_up_actions (enrolment_id, step_id, channel, due_on) VALUES (?, ?, 'task', ?)")
                ->execute([$enrolmentId, $stepId, $dueOn]);
            return true;
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Emails that have failed and are still eligible to be tried again.
     *
     * Worth surfacing separately from "sent": a sequence that looks like it is running while every
     * message bounces is the exact failure this phase exists to prevent.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function failures(?array $user, int $limit = 100, ?int $tenantId = null): array
    {
        $clause = Unit::scopeClause($user, 'n.org_unit_id');

        // `$tenantId` is for the console, which reports one church at a time. The admin page passes
        // nothing, so this stays the unit-scoped report it has always been.
        $churchClause = $tenantId === null ? '' : ' AND s.tenant_id = ?';

        $stmt = self::db()->prepare(
            'SELECT a.id, a.attempts, a.error, a.due_on, a.claimed_at,'
            . ' n.id AS newcomer_id, n.name AS newcomer_name, n.email,'
            . ' st.subject, st.task_label, st.channel,'
            . ' s.name AS sequence_name, e.id AS enrolment_id'
            . ' FROM follow_up_actions a'
            . ' JOIN follow_up_enrolments e ON e.id = a.enrolment_id'
            . ' JOIN follow_up_sequences s ON s.id = e.sequence_id'
            . ' JOIN follow_up_steps st ON st.id = a.step_id'
            . ' JOIN newcomers n ON n.id = e.newcomer_id'
            . " WHERE a.channel = 'email' AND a.sent_at IS NULL AND a.attempts > 0"
            . ($clause !== '' ? ' AND ' . $clause : '')
            . $churchClause
            . ' ORDER BY a.attempts DESC, a.due_on ASC LIMIT ' . (int) $limit
        );
        $stmt->execute($tenantId === null ? array() : array($tenantId));
        return $stmt->fetchAll();
    }
}
