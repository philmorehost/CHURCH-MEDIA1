<?php
declare(strict_types=1);

/**
 * The SMS send queue.
 *
 * This class owns the state machine of a campaign and the arithmetic of the counters.
 * The one thing it must never get wrong is sending the same message to the same person
 * twice, because that is a real bill and a real annoyance. Three things guard it:
 *
 *  1. `UNIQUE (campaign_id, msisdn)` on the queue means a recipient can only ever be
 *     added once, however many times a group is re-imported or a button re-clicked.
 *  2. **Claiming** a batch writes a `lock_token` and `claimed_at` before anything is
 *     sent, and only the claiming run reads those rows back. A cron run that overlaps
 *     another, or a worker killed mid-batch, cannot pick them up again until the claim
 *     is considered stale.
 *  3. A **stale claim** (older than `CLAIM_TIMEOUT`) is treated as "that send probably
 *     failed" and is retried, but only up to `MAX_ATTEMPTS`, after which the row is
 *     marked failed and left alone rather than looped forever.
 *
 * Ordered steps in a worker run: promoteDue() → claim() → send → recordSent()/
 * recordFailure() → refreshCounters() → finish().
 */
final class SmsCampaign
{
    public const STATUSES = ['draft', 'scheduled', 'queued', 'sending', 'sent', 'partial', 'failed', 'cancelled'];

    /** Statuses a campaign can still be worked on in. */
    public const ACTIVE_STATUSES = ['queued', 'sending'];

    /** A claim older than this is assumed abandoned and may be retried. */
    public const CLAIM_TIMEOUT_MINUTES = 10;

    /** After this many attempts a recipient is marked failed rather than retried. */
    public const MAX_ATTEMPTS = 3;

    /** How many campaigns one worker run will touch. */
    public const CAMPAIGNS_PER_RUN = 5;

    /* ------------------------------------------------------------------ reads */

    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = self::db()->prepare('SELECT * FROM sms_campaigns WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Campaigns a worker should work on now: those queued or mid-send, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function active(int $limit = self::CAMPAIGNS_PER_RUN): array
    {
        $limit = max(1, min(50, $limit));
        try {
            $stmt = self::db()->query(
                "SELECT * FROM sms_campaigns
                 WHERE status IN ('queued','sending')
                 ORDER BY COALESCE(scheduled_at, created_at) ASC, id ASC
                 LIMIT {$limit}"
            );
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Recipients still waiting to be sent, ignoring any claim. */
    public static function pendingCount(int $campaignId): int
    {
        $stmt = self::db()->prepare("SELECT COUNT(*) FROM sms_campaign_recipients WHERE campaign_id = ? AND status = 'pending'");
        $stmt->execute([$campaignId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, int> counts keyed by status */
    public static function statusCounts(int $campaignId): array
    {
        $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        $stmt = self::db()->prepare('SELECT status, COUNT(*) AS n FROM sms_campaign_recipients WHERE campaign_id = ? GROUP BY status');
        $stmt->execute([$campaignId]);
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    /* ------------------------------------------------------------- promotion */

    /**
     * Moves scheduled campaigns whose moment has arrived into the queue.
     *
     * @return int how many were promoted
     */
    public static function promoteDue(): int
    {
        try {
            $stmt = self::db()->prepare(
                "UPDATE sms_campaigns SET status = 'queued'
                 WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()"
            );
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('SmsCampaign promoteDue failed: ' . $e->getMessage());
            return 0;
        }
    }

    /* ---------------------------------------------------------------- queueing */

    /**
     * Fills a campaign's queue from a list of contacts.
     *
     * Numbers are normalised and de-duplicated first, opted-out contacts are skipped,
     * and the unique key makes the whole thing safe to re-run — which is what lets an
     * admin add more recipients to a campaign that has not started yet.
     *
     * @param  array<int, array<string, mixed>> $contacts  Each needs msisdn; may carry id, name.
     * @return array{queued:int,duplicates:int,opted_out:int,invalid:int,rejected:int,total:int}
     */
    public static function queueRecipients(int $campaignId, array $contacts): array
    {
        $stats = ['queued' => 0, 'duplicates' => 0, 'opted_out' => 0, 'invalid' => 0, 'rejected' => 0, 'total' => 0];
        $pdo = self::db();

        $insert = $pdo->prepare(
            'INSERT IGNORE INTO sms_campaign_recipients (campaign_id, contact_id, msisdn, name) VALUES (?, ?, ?, ?)'
        );
        $exists = $pdo->prepare('SELECT 1 FROM sms_campaign_recipients WHERE campaign_id = ? AND msisdn = ? LIMIT 1');

        $seen = [];
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $stats['total']++;

            if (!empty($contact['is_opted_out'])) {
                $stats['opted_out']++;
                continue;
            }

            $raw = (string) ($contact['msisdn'] ?? '');
            $msisdn = Sms::normaliseMsisdn($raw, isset($contact['country_code']) ? (string) $contact['country_code'] : null);
            if ($msisdn === null) {
                $stats['invalid']++;
                continue;
            }

            // Counted here as well as by the unique key so the screen can say *why*
            // fewer recipients were queued than were selected.
            if (isset($seen[$msisdn])) {
                $stats['duplicates']++;
                continue;
            }
            $seen[$msisdn] = true;

            $insert->execute([$campaignId, isset($contact['id']) ? (int) $contact['id'] : null, $msisdn, $contact['name'] ?? null]);
            if ($insert->rowCount() > 0) {
                $stats['queued']++;
                continue;
            }

            // The insert was ignored. That means either the number is already queued for
            // this campaign — or the row was rejected outright, most often by a foreign
            // key. Reporting a rejection as "already queued" would tell an admin their
            // list is safely imported when in fact nothing was saved.
            $exists->execute([$campaignId, $msisdn]);
            if ($exists->fetchColumn()) {
                $stats['duplicates']++;
            } else {
                $stats['rejected']++;
                error_log(sprintf(
                    'SmsCampaign: could not queue %s for campaign %d (suppressed by INSERT IGNORE).',
                    $msisdn,
                    $campaignId
                ));
            }
        }

        return $stats;
    }

    /* --------------------------------------------------------------- claiming */

    /**
     * Claims up to $limit waiting recipients for this run.
     *
     * The claim is a single UPDATE keyed on a per-run token, so two runs racing each
     * other cannot both come away with the same rows: whoever's UPDATE lands second
     * finds `status` already moved on for those rows and takes nothing.
     *
     * @return array{token:string,rows:array<int, array<string, mixed>>}
     */
    public static function claim(int $campaignId, int $limit, ?string $token = null): array
    {
        $token = $token ?? bin2hex(random_bytes(8));
        $limit = max(1, min(1000, $limit));
        $pdo = self::db();

        // Rows whose claim has gone stale are released first, so an abandoned batch is
        // retried rather than silently stuck.
        self::releaseStale($campaignId);

        $claim = $pdo->prepare(
            "UPDATE sms_campaign_recipients
                SET lock_token = ?, claimed_at = NOW(), attempts = attempts + 1
              WHERE campaign_id = ? AND status = 'pending' AND lock_token IS NULL
              ORDER BY id ASC
              LIMIT {$limit}"
        );
        $claim->execute([$token, $campaignId]);

        // Claiming rows is the moment sending actually starts, so record it here rather
        // than guessing later. The status follows from this, which is why it has to be
        // set somewhere deterministic instead of in the counter refresh.
        if ($claim->rowCount() > 0) {
            $pdo->prepare('UPDATE sms_campaigns SET started_at = COALESCE(started_at, NOW()) WHERE id = ?')
                ->execute([$campaignId]);
        }

        $rows = $pdo->prepare('SELECT * FROM sms_campaign_recipients WHERE lock_token = ?');
        $rows->execute([$token]);

        return ['token' => $token, 'rows' => $rows->fetchAll()];
    }

    /**
     * Frees claims older than the timeout, and gives up on rows that have failed too
     * many times.
     *
     * @return int rows released
     */
    public static function releaseStale(int $campaignId): int
    {
        try {
            $pdo = self::db();

            // Too many attempts: stop retrying and record why, so the campaign can finish.
            $exhausted = $pdo->prepare(
                "UPDATE sms_campaign_recipients
                    SET status = 'failed', lock_token = NULL,
                        error_note = COALESCE(error_note, 'Gave up after repeated attempts without a result.')
                  WHERE campaign_id = ? AND status = 'pending' AND attempts >= ? AND lock_token IS NOT NULL"
            );
            $exhausted->execute([$campaignId, self::MAX_ATTEMPTS]);

            $released = $pdo->prepare(
                "UPDATE sms_campaign_recipients SET lock_token = NULL
                  WHERE campaign_id = ? AND status = 'pending' AND lock_token IS NOT NULL
                    AND claimed_at < (NOW() - INTERVAL " . self::CLAIM_TIMEOUT_MINUTES . " MINUTE)"
            );
            $released->execute([$campaignId]);
            return $released->rowCount();
        } catch (Throwable $e) {
            error_log('SmsCampaign releaseStale failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** Frees every claim on a campaign, so nothing is left locked when work stops. */
    public static function releaseAllClaims(int $campaignId): int
    {
        try {
            $stmt = self::db()->prepare("UPDATE sms_campaign_recipients SET lock_token = NULL WHERE campaign_id = ? AND status = 'pending' AND lock_token IS NOT NULL");
            $stmt->execute([$campaignId]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('SmsCampaign releaseAllClaims failed: ' . $e->getMessage());
            return 0;
        }
    }

    /* -------------------------------------------------------------- recording */

    /**
     * Records the outcome for one recipient.
     *
     * Both outcomes clear the claim, which is what lets the campaign finish even when
     * something failed.
     */
    public static function recordSent(int $recipientId, int $units): bool
    {
        try {
            self::db()->prepare(
                "UPDATE sms_campaign_recipients
                    SET status = 'sent', units = ?, sent_at = NOW(), lock_token = NULL,
                        error_code = NULL, error_note = NULL
                  WHERE id = ?"
            )->execute([$units, $recipientId]);
            return true;
        } catch (Throwable $e) {
            error_log('SmsCampaign recordSent failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function recordFailure(int $recipientId, ?string $code, string $note): bool
    {
        try {
            self::db()->prepare(
                "UPDATE sms_campaign_recipients
                    SET status = 'failed', lock_token = NULL, error_code = ?, error_note = ?
                  WHERE id = ?"
            )->execute([$code, mb_substr($note, 0, 255), $recipientId]);
            return true;
        } catch (Throwable $e) {
            error_log('SmsCampaign recordFailure failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Puts a claimed row back in the queue without counting an attempt against it. */
    public static function release(int $recipientId): void
    {
        try {
            self::db()->prepare('UPDATE sms_campaign_recipients SET lock_token = NULL, attempts = GREATEST(0, attempts - 1) WHERE id = ?')
                ->execute([$recipientId]);
        } catch (Throwable $e) {
            error_log('SmsCampaign release failed: ' . $e->getMessage());
        }
    }

    /* --------------------------------------------------------------- counters */

    /**
     * Recomputes the campaign's counters from the queue rather than incrementing them
     * as it goes. A counter that is derived cannot drift out of step with the rows it
     * is describing, which is what makes a retry safe.
     *
     * @return array<string, int>
     */
    public static function refreshCounters(int $campaignId): array
    {
        $counts = self::statusCounts($campaignId);
        $units = 0;
        try {
            $stmt = self::db()->prepare("SELECT COALESCE(SUM(units), 0) FROM sms_campaign_recipients WHERE campaign_id = ? AND status = 'sent'");
            $stmt->execute([$campaignId]);
            $units = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            // Units stay at zero; the counts are still worth writing.
        }

        $counters = [
            'total_recipients' => array_sum($counts),
            'sent_count' => $counts['sent'],
            'failed_count' => $counts['failed'],
            'skipped_count' => $counts['skipped'],
            'units_charged' => $units,
            'pending' => $counts['pending'],
        ];

        // Whether sending has begun decides queued vs sending. Anything else would be a
        // guess, and a campaign that says "sending" before it has sent anything is lying
        // to the admin reading the screen.
        $started = false;
        try {
            $stmt = self::db()->prepare('SELECT started_at IS NOT NULL FROM sms_campaigns WHERE id = ? LIMIT 1');
            $stmt->execute([$campaignId]);
            $started = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            // Treat it as not started, which reads as "queued" — the safe half of the guess.
        }

        $counters['status'] = self::statusFor($counters, $started);

        try {
            self::db()->prepare(
                'UPDATE sms_campaigns
                    SET total_recipients = ?, sent_count = ?, failed_count = ?, skipped_count = ?,
                        units_charged = ?, status = ?
                  WHERE id = ?'
            )->execute([
                $counters['total_recipients'],
                $counters['sent_count'],
                $counters['failed_count'],
                $counters['skipped_count'],
                $counters['units_charged'],
                $counters['status'],
                $campaignId,
            ]);
        } catch (Throwable $e) {
            error_log('SmsCampaign refreshCounters failed: ' . $e->getMessage());
        }

        return $counters;
    }

    /**
     * What status the campaign should be in, given its queue.
     *
     * A campaign that is still sending but has nothing left to send is finished — the
     * worker does not have to remember to tell it so.
     */
    private static function statusFor(array $counters, bool $started): string
    {
        if ($counters['total_recipients'] === 0) {
            return 'failed';
        }
        if ($counters['pending'] > 0) {
            return $started ? 'sending' : 'queued';
        }
        // Nothing left to send. If nothing ever went out, the campaign failed outright
        // rather than being "partly" successful.
        if ($counters['sent_count'] === 0) {
            return 'failed';
        }
        return $counters['failed_count'] > 0 || $counters['skipped_count'] > 0 ? 'partial' : 'sent';
    }

    /** No work left in the queue for this campaign. */
    public static function isComplete(int $campaignId): bool
    {
        return self::pendingCount($campaignId) === 0;
    }

    /**
     * Timestamps the campaign when it has finished, and reports how it ended.
     *
     * @return string the resulting status
     */
    public static function finish(int $campaignId): string
    {
        $counters = self::refreshCounters($campaignId);

        try {
            if ($counters['pending'] > 0) {
                // Still work to do — leave it startable and resumable.
                self::db()->prepare('UPDATE sms_campaigns SET started_at = COALESCE(started_at, NOW()) WHERE id = ?')
                    ->execute([$campaignId]);
            } else {
                self::db()->prepare('UPDATE sms_campaigns SET finished_at = COALESCE(finished_at, NOW()), paused_reason = NULL WHERE id = ?')
                    ->execute([$campaignId]);
            }
        } catch (Throwable $e) {
            error_log('SmsCampaign finish failed: ' . $e->getMessage());
        }

        return (string) (self::find($campaignId)['status'] ?? $counters['status']);
    }

    /**
     * Parks a campaign because something outside it needs fixing — an empty wallet,
     * quiet hours, the daily cap.
     *
     * Deliberately never called "failed": nothing is wrong with the campaign, and a
     * church that sees "failed" will re-send the whole thing. `partial` plus a reason
     * says exactly what happened and is safe to resume from.
     */
    public static function pause(int $campaignId, string $reason): void
    {
        try {
            // Refresh first: a paused campaign is about to be read by an admin, and the
            // counters describing it must match the rows rather than lag a run behind.
            self::refreshCounters($campaignId);
            self::db()->prepare("UPDATE sms_campaigns SET status = 'partial', paused_reason = ? WHERE id = ?")
                ->execute([mb_substr($reason, 0, 255), $campaignId]);
        } catch (Throwable $e) {
            error_log('SmsCampaign pause failed: ' . $e->getMessage());
        }
    }

    /**
     * Puts a campaign back in the queue.
     *
     * Only intended for a paused campaign: it will not resurrect a cancelled one,
     * because cancelling is a decision the church made on purpose.
     */
    public static function resume(int $campaignId): bool
    {
        try {
            $stmt = self::db()->prepare("UPDATE sms_campaigns SET status = 'queued', paused_reason = NULL WHERE id = ? AND status IN ('partial','sending','queued')");
            $stmt->execute([$campaignId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Stops a scheduled or queued campaign. Recipients already sent are untouched. */
    public static function cancel(int $campaignId): bool
    {
        try {
            $stmt = self::db()->prepare("UPDATE sms_campaigns SET status = 'cancelled', finished_at = NOW() WHERE id = ? AND status IN ('draft','scheduled','queued','sending','partial')");
            $stmt->execute([$campaignId]);
            if ($stmt->rowCount() === 0) {
                return false;
            }
            self::db()->prepare("UPDATE sms_campaign_recipients SET status = 'skipped', lock_token = NULL WHERE campaign_id = ? AND status = 'pending'")
                ->execute([$campaignId]);
            self::refreshCounters($campaignId);
            // refreshCounters() derives a status, so put the cancellation back.
            self::db()->prepare("UPDATE sms_campaigns SET status = 'cancelled' WHERE id = ?")->execute([$campaignId]);
            return true;
        } catch (Throwable $e) {
            error_log('SmsCampaign cancel failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Queues only the recipients that failed, for a retry.
     *
     * This is the "resend to failures only" button. Because it rewrites the *same*
     * rows rather than inserting new ones, the unique key has already guaranteed there
     * is exactly one row per number — so a retry cannot reach anyone twice.
     *
     * @return int rows put back in the queue
     */
    public static function reopenFailures(int $campaignId): int
    {
        try {
            $pdo = self::db();

            // Anything still pending would otherwise be sent alongside the retry.
            $stmt = $pdo->prepare(
                "UPDATE sms_campaign_recipients
                    SET status = 'pending', attempts = 0, error_code = NULL, error_note = NULL,
                        lock_token = NULL, claimed_at = NULL, sent_at = NULL
                  WHERE campaign_id = ? AND status = 'failed'"
            );
            $stmt->execute([$campaignId]);
            $reopened = $stmt->rowCount();

            if ($reopened > 0) {
                // Clear started_at so it reads as queued rather than mid-send: the retry
                // has not begun yet.
                $pdo->prepare("UPDATE sms_campaigns SET status = 'queued', started_at = NULL, finished_at = NULL, paused_reason = NULL WHERE id = ?")
                    ->execute([$campaignId]);
                self::refreshCounters($campaignId);
            }

            return $reopened;
        } catch (Throwable $e) {
            error_log('SmsCampaign reopenFailures failed: ' . $e->getMessage());
            return 0;
        }
    }

    /* ----------------------------------------------------------- limits and time */

    /**
     * Units already spent today, summed from the queue rather than from a counter, so
     * it cannot disagree with what was actually sent.
     */
    public static function unitsSentToday(): int
    {
        try {
            $stmt = self::db()->query("SELECT COALESCE(SUM(units), 0) FROM sms_campaign_recipients WHERE status = 'sent' AND sent_at >= CURDATE()");
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** The daily cap, or 0 when unlimited. */
    public static function dailyCap(): int
    {
        return max(0, (int) setting('sms_daily_unit_cap', 0));
    }

    /** True when another $units would push past today's cap. */
    public static function wouldExceedDailyCap(int $units): bool
    {
        $cap = self::dailyCap();
        if ($cap <= 0) {
            return false;
        }
        return self::unitsSentToday() + $units > $cap;
    }

    /**
     * Whether sending is currently allowed by the quiet-hours window.
     *
     * Wraps correctly across midnight, so a 20:00–07:00 window means what it says
     * rather than never being open.
     */
    public static function withinQuietHours(?int $hour = null): bool
    {
        $hour = $hour ?? (int) date('G');
        $start = (int) setting('sms_quiet_start', 7);
        $end = (int) setting('sms_quiet_end', 20);

        // A window that starts and ends at the same hour, or is inverted, is read as
        // "no restriction" rather than as "never send".
        if ($start === $end || $start < 0 || $end < 0 || $start > 23 || $end > 23) {
            return true;
        }
        if ($start < $end) {
            return $hour >= $start && $hour < $end;
        }
        // Spans midnight.
        return $hour >= $start || $hour < $end;
    }

    /** A readable form of the window, for the screens. */
    public static function quietHoursLabel(): string
    {
        $start = (int) setting('sms_quiet_start', 7);
        $end = (int) setting('sms_quiet_end', 20);
        if ($start === $end) {
            return 'no restriction';
        }
        return sprintf('%02d:00 – %02d:00', $start, $end);
    }

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }
}
