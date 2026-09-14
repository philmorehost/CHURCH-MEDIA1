#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * The daily "you have not read yet" nudge for members on a reading plan.
 *
 * Called by cli/reading_worker.php from cron. Like DevotionalPush, deciding *who* is reminded is kept
 * in `targets()` and separated from the sending, so the rules can be asserted without touching FCM.
 *
 * **This is what makes the reading-plan switch on the member dashboard real.** That checkbox has
 * existed since the members table was created and controlled nothing; it is now the thing that
 * decides whether this worker is allowed to reach the member. See Member::wantsNotification().
 *
 * **Who gets it.** A device whose member is on a published plan, has not ticked anything today, has
 * not finished the plan, and has not switched reading reminders off. Devices are grouped by member,
 * because the day is claimed per member — a member with two phones must get one nudge on each
 * rather than one nudge on the first.
 *
 * **Never twice in a day.** `members.reading_reminded_on` is claimed with a conditional UPDATE
 * before the send, so an hourly cron sends once and the rest of the day's runs do nothing. Same
 * trade-off as the devotional: a missed nudge is recoverable, a repeated one is the thing people
 * uninstall for.
 *
 * **One church at a time.** A device carries `device_tokens.tenant_id`, and a plan carries
 * `reading_plans.tenant_id`, so the audience and the plan the nudge quotes are both scoped to the church
 * being served. The caller — `cli/reading_worker.php` — makes one pass per church through
 * `Tenant::each()`; a cron has no request host, so without that the church being served is only ever the
 * default one. Before this, a single run read the switch and the quiet-hours window of whichever church
 * resolved first and nudged every church's members about another church's plan.
 *
 * A device with `tenant_id = 0` ("no church assigned") is reached by nobody rather than by everybody.
 */
final class ReadingReminder
{
    /** Devices one run will consider. */
    public const BATCH_LIMIT = 2000;

    /** The church being served. 0 never matches a row, so an unresolvable church reaches nobody. */
    private static function tenantId(): int
    {
        return (class_exists('Tenant') ? Tenant::id() : null) ?? 0;
    }

    /**
     * @return array{stopped:?string,sent:int,skipped:int,failed:int,claimed:int,pruned:int,reasons:array<int,string>}
     */
    public static function run(bool $force = false, bool $dryRun = false): array
    {
        $summary = array(
            'stopped' => null,
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
            'claimed' => 0,
            'pruned' => 0,
            'reasons' => array(),
        );

        $blocked = self::blockedReason($force);
        if ($blocked !== null) {
            $summary['stopped'] = $blocked;
            return $summary;
        }

        $pdo = Database::getInstance()->getConnection();

        if (self::devices($pdo) === array()) {
            $summary['reasons'][] = 'No devices are bound to a member yet. A reminder needs a signed-in member.';
            return $summary;
        }

        foreach (self::targets($pdo) as $group) {
            $memberId = (int) $group['member_id'];
            $deviceCount = count($group['devices']);

            if ($dryRun) {
                $summary['sent'] += $deviceCount;
                continue;
            }

            // One claim per member, before any of their devices are sent to.
            if (!self::claim($pdo, $memberId)) {
                $summary['skipped'] += $deviceCount;
                continue;
            }
            $summary['claimed']++;

            foreach ($group['devices'] as $device) {
                $ok = Pusher::send(
                    (string) $device['token'],
                    $group['title'],
                    $group['body'],
                    null,
                    array(
                        'type' => 'reading_plan',
                        'plan_id' => (string) $group['plan_id'],
                        'day' => (string) $group['day'],
                    )
                );

                if ($ok) {
                    $summary['sent']++;
                    continue;
                }

                $summary['failed']++;
                if (self::tokenIsGone(Pusher::getLastError())) {
                    // Scoped, so a token id from another church's list could never be deleted here.
                    $pdo->prepare('DELETE FROM device_tokens WHERE id = ? AND tenant_id = ?')
                        ->execute(array((int) $device['id'], self::tenantId()));
                    $summary['pruned']++;
                }
            }
        }

        if ($summary['pruned'] > 0) {
            $summary['reasons'][] = $summary['pruned'] . ' device token(s) had been uninstalled and were removed.';
        }

        return $summary;
    }

    /**
     * Members to nudge today, each with the devices to reach them on.
     *
     * Public and free of side effects so the rules can be asserted directly.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function targets(PDO $pdo): array
    {
        // The plan the nudge quotes is scoped as well as the device. Two guards stand between a member and
        // another church's plan, and each was checked on its own by breaking the other:
        //   - with this join scoped and `ReadingPlan::find()` church-blind, the row is not fetched and the
        //     member is still not reminded;
        //   - with this join church-blind and `find()` scoped, `nextDay()` cannot resolve the plan and
        //     returns null, and the member is still not reminded.
        // Neither alone is what makes the query correct — both are — and only breaking them together lets a
        // cross-church reminder through. Do not remove one on the grounds that the other covers it: that
        // reasoning is exactly how the pair becomes one, and then none.
        $tenantId = self::tenantId();

        $sql = "SELECT d.id AS device_id, d.token, m.id AS member_id, m.reading_plan_id,
                       p.name AS plan_name, p.days_count
                FROM device_tokens d
                JOIN members m ON m.id = d.member_id
                JOIN reading_plans p ON p.id = m.reading_plan_id AND p.is_published = 1 AND p.tenant_id = ?
                WHERE d.tenant_id = ?
                  AND d.token <> ''
                  AND m.is_suspended = 0
                  AND m.reading_plan_id IS NOT NULL
                  AND (m.reading_reminded_on IS NULL OR m.reading_reminded_on <> CURDATE())
                  AND NOT EXISTS (
                        SELECT 1 FROM reading_progress rp
                        WHERE rp.member_id = m.id
                          AND rp.plan_id = m.reading_plan_id
                          AND rp.completed_on = CURDATE()
                  )
                ORDER BY d.id ASC
                LIMIT " . self::BATCH_LIMIT;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($tenantId, $tenantId));

        $groups = array();
        $wants = array();
        $passages = array();

        foreach ($stmt->fetchAll() as $row) {
            $memberId = (int) $row['member_id'];

            if (!array_key_exists($memberId, $wants)) {
                $wants[$memberId] = Member::wantsNotification($memberId, 'reading_plan');
            }
            if ($wants[$memberId] === false) {
                continue;
            }

            if (!isset($groups[$memberId])) {
                $planId = (int) $row['reading_plan_id'];
                $nextDay = ReadingPlan::nextDay($memberId, $planId);

                // Finished the plan: there is nothing to nudge about.
                if ($nextDay === null) {
                    continue;
                }

                // Many members are on the same day of the same plan, so the passages are looked up
                // once per plan-and-day rather than once per person.
                $cacheKey = $planId . ':' . $nextDay;
                if (!isset($passages[$cacheKey])) {
                    $passages[$cacheKey] = ReadingPlan::labelForDay(ReadingPlan::passagesForDay($planId, $nextDay));
                }

                $groups[$memberId] = array(
                    'member_id' => $memberId,
                    'plan_id' => $planId,
                    'day' => $nextDay,
                    'title' => 'Day ' . $nextDay . ' · ' . $passages[$cacheKey],
                    'body' => self::bodyFor($memberId, (string) $row['plan_name']),
                    'devices' => array(),
                );
            }

            if (isset($groups[$memberId])) {
                $groups[$memberId]['devices'][] = array('id' => (int) $row['device_id'], 'token' => (string) $row['token']);
            }
        }

        return array_values($groups);
    }

    /** The nudge's second line, which mentions the streak only when there is one to lose. */
    private static function bodyFor(int $memberId, string $planName): string
    {
        $streak = ReadingPlan::streakFor($memberId);
        if ($streak > 1) {
            return 'You are ' . $streak . ' days in — a few minutes keeps it going.';
        }
        return 'A few minutes with the Word to keep your place in ' . $planName . '.';
    }

    /** Why sending cannot happen right now, or null when it can. */
    private static function blockedReason(bool $force): ?string
    {
        if ((int) setting('reading_reminder_enabled', 1) !== 1) {
            return 'The reading reminder is switched off in Settings.';
        }

        if (!$force && !SmsCampaign::withinQuietHours()) {
            return 'Outside the sending window (' . SmsCampaign::quietHoursLabel() . '). Nothing sent.';
        }

        if (!Pusher::configured()) {
            $detail = Pusher::getLastError();
            return 'Push is not configured'
                . ($detail !== null && $detail !== '' ? ' — ' . $detail : ' — check Admin → Firebase.');
        }

        return null;
    }

    /** Every device bound to a member in the church being served, so an empty run can say so rather
     *  than looking like a bug. */
    private static function devices(PDO $pdo): array
    {
        $sql = "SELECT id FROM device_tokens WHERE tenant_id = ? AND member_id IS NOT NULL AND token <> '' LIMIT " . self::BATCH_LIMIT;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(self::tenantId()));
        return $stmt->fetchAll();
    }

    /**
     * Takes today for this member, so a later run in the same day leaves them alone.
     *
     * The condition in the WHERE is what makes this safe against an hourly cron: only one caller
     * can change the row, and the loser is told so by rowCount() rather than by luck. The church in the
     * WHERE is belt and braces — the id came from a query this run already scoped — and it is the shape
     * every other claim in this codebase uses.
     */
    private static function claim(PDO $pdo, int $memberId): bool
    {
        $stmt = $pdo->prepare(
            'UPDATE members SET reading_reminded_on = CURDATE()
             WHERE id = ? AND tenant_id = ? AND (reading_reminded_on IS NULL OR reading_reminded_on <> CURDATE())'
        );
        $stmt->execute(array($memberId, self::tenantId()));
        return $stmt->rowCount() === 1;
    }

    /**
     * Whether an FCM error means the token itself is dead.
     *
     * Deliberately narrow, for the same reason as DevotionalPush: deleting a live device over a
     * payload bug of ours would shrink the audience with nothing for anyone to notice.
     */
    private static function tokenIsGone(?string $error): bool
    {
        if ($error === null || $error === '') {
            return false;
        }
        foreach (array('UNREGISTERED', 'NOT_FOUND') as $needle) {
            if (stripos($error, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /** How many members in the church being served are on a published plan, for the admin screen and
     *  the worker's --status. */
    public static function audienceSize(): int
    {
        // Suspended members are excluded, because targets() excludes them: a status line that
        // counted people the run will never reach would be worse than no status line.
        $tenantId = self::tenantId();
        $stmt = Database::getInstance()->getConnection()->prepare(
            'SELECT COUNT(*) FROM members m
             JOIN reading_plans p ON p.id = m.reading_plan_id AND p.is_published = 1 AND p.tenant_id = ?
             WHERE m.tenant_id = ? AND m.is_suspended = 0'
        );
        $stmt->execute(array($tenantId, $tenantId));
        return (int) $stmt->fetchColumn();
    }
}
