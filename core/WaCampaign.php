<?php
declare(strict_types=1);

/**
 * Broadcasts over WhatsApp.
 *
 * This is a sibling of `SmsCampaign`, not a copy of it, because four things genuinely differ and
 * inheriting the SMS shape would carry rules that do not apply:
 *
 *   1. **No wallet, no per-message cost.** Meta bills per 24-hour conversation, so there is
 *      nothing to debit per recipient and no unit counters to keep.
 *
 *   2. **Consent is opt-IN, not opt-out.** WhatsApp requires it, and messaging people who never
 *      agreed is how a number gets reported and banned. Somebody who is not opted in is recorded
 *      as *skipped, with the reason* — never quietly dropped, because "I sent it to 400 people"
 *      and "it went to 120" must not look the same.
 *
 *   3. **Delivery is asynchronous.** Statuses arrive later by webhook, so a recipient moves
 *      `sent → delivered → read` over time rather than being resolved at send. `applyStatus()` is
 *      what the webhook calls so the campaign counters follow.
 *
 *   4. **Rate limits are per-second**, and a newly registered number has a low daily ceiling.
 *      Exceeding it does not fail loudly; it starts silently throttling. Hence the delay and the
 *      daily cap, which defaults to Meta's own unverified limit.
 *
 * What it does share with SMS is the claiming model: `claimed_at` + `lock_token` stop an
 * overlapping cron run, or a worker killed mid-batch, from messaging the same person twice.
 */
final class WaCampaign
{
    /** How many campaigns one worker run will touch, so a large queue cannot monopolise a cron. */
    public const CAMPAIGNS_PER_RUN = 3;

    /** A claim older than this is assumed abandoned and released. */
    public const CLAIM_MINUTES = 10;

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }

    private static function tenantId(): ?int
    {
        return class_exists('Tenant') ? Tenant::id() : null;
    }

    /* ------------------------------------------------------------------ reads */

    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = self::db()->prepare('SELECT * FROM wa_campaigns WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function active(int $limit = self::CAMPAIGNS_PER_RUN): array
    {
        $stmt = self::db()->prepare(
            'SELECT * FROM wa_campaigns WHERE status IN ("queued", "sending") ORDER BY id ASC LIMIT ' . max(1, $limit)
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function pendingCount(int $campaignId): int
    {
        $stmt = self::db()->prepare('SELECT COUNT(*) FROM wa_campaign_recipients WHERE campaign_id = ? AND status = "queued"');
        $stmt->execute([$campaignId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, int> */
    public static function statusCounts(int $campaignId): array
    {
        $stmt = self::db()->prepare('SELECT status, COUNT(*) AS n FROM wa_campaign_recipients WHERE campaign_id = ? GROUP BY status');
        $stmt->execute([$campaignId]);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    /* ---------------------------------------------------------------- audience */

    /**
     * Queues recipients for a campaign.
     *
     * @param  array<int, array<string, mixed>> $contacts    Rows from the Phase 2 contact layer
     * @param  array<int, string>               $params      Template values; may contain {name},
     *                                                       {first_name} or {church}
     * @return array{queued:int,skipped_optout:int,skipped_invalid:int,already:int,total:int}
     */
    public static function queueRecipients(int $campaignId, array $contacts, array $params = []): array
    {
        $stats = [
            'queued' => 0,
            'skipped_optout' => 0,
            'skipped_invalid' => 0,
            'already' => 0,
            'total' => 0,
        ];

        $campaign = self::find($campaignId);
        if ($campaign === null) {
            return $stats;
        }

        $pdo = self::db();
        $tenantId = self::tenantId();

        // Who has actually opted in. One query rather than one per recipient.
        $optedIn = [];
        $optStmt = $pdo->prepare('SELECT msisdn FROM wa_opt_ins WHERE tenant_id <=> ? AND is_opted_in = 1');
        $optStmt->execute([$tenantId]);
        foreach ($optStmt->fetchAll(PDO::FETCH_COLUMN) as $number) {
            $optedIn[(string) $number] = true;
        }

        // An inbound message is an opt-in even if the opt-in row was never written, and a
        // conversation that has opted out overrides everything.
        $convStmt = $pdo->prepare('SELECT msisdn, is_opted_out FROM wa_conversations WHERE tenant_id <=> ?');
        $convStmt->execute([$tenantId]);
        $optedOut = [];
        foreach ($convStmt->fetchAll() as $row) {
            $number = (string) $row['msisdn'];
            if ((int) $row['is_opted_out'] === 1) {
                $optedOut[$number] = true;
            } else {
                $optedIn[$number] = true;
            }
        }

        $insert = $pdo->prepare(
            'INSERT INTO wa_campaign_recipients
                (tenant_id, campaign_id, contact_id, msisdn, contact_name, params, status, skip_reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );

        foreach ($contacts as $contact) {
            $stats['total']++;

            $number = trim((string) ($contact['msisdn'] ?? ''));
            if ($number === '') {
                $stats['skipped_invalid']++;
                continue;
            }

            $name = trim((string) ($contact['name'] ?? ''));
            $params = array_map(
                static fn (string $value): string => Sms::personalise($value, $contact),
                array_values($params)
            );

            $payload = $params === [] ? null : json_encode($params, JSON_UNESCAPED_UNICODE);

            if (isset($optedOut[$number])) {
                // Recorded rather than dropped. An admin who sent to 400 people needs to know it
                // reached 120, and why the other 280 did not.
                $insert->execute([$tenantId, $campaignId, $contact['id'] ?? null, $number, $name !== '' ? $name : null, $payload, 'skipped', 'opted out']);
                $stats['skipped_optout']++;
                continue;
            }

            if (!isset($optedIn[$number])) {
                $insert->execute([$tenantId, $campaignId, $contact['id'] ?? null, $number, $name !== '' ? $name : null, $payload, 'skipped', 'never opted in']);
                $stats['skipped_invalid']++;
                continue;
            }

            $insert->execute([$tenantId, $campaignId, $contact['id'] ?? null, $number, $name !== '' ? $name : null, $payload, 'queued', null]);
            if ($insert->rowCount() > 0) {
                $stats['queued']++;
            } else {
                $stats['already']++;
            }
        }

        $counters = self::refreshCounters($campaignId);
        $pdo->prepare('UPDATE wa_campaigns SET total_count = ?, status = IF(status = "draft", "queued", status) WHERE id = ?')
            ->execute([$counters['total'] ?? 0, $campaignId]);

        return $stats;
    }

    /* ----------------------------------------------------------------- claiming */

    /**
     * Claims a batch of recipients for sending.
     *
     * The claim is written before the send, so a worker that dies mid-batch leaves a claim that
     * `releaseStale()` can find rather than a message that was sent twice.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function claim(int $campaignId, int $limit, ?string $token = null): array
    {
        $token = $token ?? bin2hex(random_bytes(8));
        $pdo = self::db();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM wa_campaign_recipients
                 WHERE campaign_id = ? AND status = "queued" AND claimed_at IS NULL
                 ORDER BY id ASC LIMIT ' . max(1, $limit) . ' FOR UPDATE'
            );
            $stmt->execute([$campaignId]);
            $rows = $stmt->fetchAll();

            if ($rows) {
                $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare(
                    "UPDATE wa_campaign_recipients SET claimed_at = NOW(), lock_token = ?, attempts = attempts + 1 WHERE id IN ($placeholders)"
                )->execute(array_merge([$token], $ids));

                foreach ($rows as &$row) {
                    $row['lock_token'] = $token;
                }
                unset($row);
            }

            $pdo->commit();
            return $rows;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('WhatsApp claim failed: ' . $e->getMessage());
            return [];
        }
    }

    public static function recordSent(int $recipientId, ?string $waMessageId): bool
    {
        // `sent` rather than `delivered`: Meta has accepted it. Whether it arrived is a separate
        // fact that only the webhook knows.
        $stmt = self::db()->prepare(
            'UPDATE wa_campaign_recipients
             SET status = "sent", wa_message_id = ?, sent_at = NOW(), status_at = NOW(), claimed_at = NULL, lock_token = NULL
             WHERE id = ?'
        );
        return $stmt->execute([$waMessageId, $recipientId]);
    }

    public static function recordFailure(int $recipientId, ?string $code, string $note): bool
    {
        $stmt = self::db()->prepare(
            'UPDATE wa_campaign_recipients
             SET status = "failed", error_code = ?, error_note = ?, status_at = NOW(), claimed_at = NULL, lock_token = NULL
             WHERE id = ?'
        );
        return $stmt->execute([$code, mb_substr($note, 0, 255), $recipientId]);
    }

    /** Returns a claim without sending — used when the campaign is paused mid-batch. */
    public static function release(int $recipientId): void
    {
        self::db()->prepare('UPDATE wa_campaign_recipients SET claimed_at = NULL, lock_token = NULL WHERE id = ?')
            ->execute([$recipientId]);
    }

    public static function releaseStale(int $campaignId, int $minutes = self::CLAIM_MINUTES): int
    {
        $stmt = self::db()->prepare(
            'UPDATE wa_campaign_recipients
             SET claimed_at = NULL, lock_token = NULL
             WHERE campaign_id = ? AND claimed_at IS NOT NULL AND claimed_at < (NOW() - INTERVAL ? MINUTE)'
        );
        $stmt->execute([$campaignId, max(1, $minutes)]);
        return $stmt->rowCount();
    }

    public static function releaseAllClaims(int $campaignId): int
    {
        $stmt = self::db()->prepare(
            'UPDATE wa_campaign_recipients SET claimed_at = NULL, lock_token = NULL WHERE campaign_id = ? AND claimed_at IS NOT NULL'
        );
        $stmt->execute([$campaignId]);
        return $stmt->rowCount();
    }

    /* --------------------------------------------------------------- webhook side */

    /**
     * Applies a delivery status from the webhook to the campaign recipient that produced it.
     *
     * Called with the `wa_message_id` Meta sent back. Ranking matches the message table: a late
     * `delivered` must not undo a `read`, and a `failed` always wins because it is terminal.
     */
    public static function applyStatus(string $waMessageId, string $status, ?string $code = null, ?string $note = null): bool
    {
        if ($waMessageId === '' || !in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            return false;
        }

        $stmt = self::db()->prepare(
            "UPDATE wa_campaign_recipients
             SET status = ?, status_at = NOW(), error_code = COALESCE(?, error_code), error_note = COALESCE(?, error_note)
             WHERE wa_message_id = ?
               AND (
                   status = 'failed'
                   OR ? = 'failed'
                   OR FIELD(status, 'queued', 'sent', 'delivered', 'read')
                      < FIELD(?, 'queued', 'sent', 'delivered', 'read')
               )"
        );
        $stmt->execute([$status, $code, $note, $waMessageId, $status, $status]);

        return $stmt->rowCount() > 0;
    }

    /* ----------------------------------------------------------------- counters */

    /** @return array<string, int> */
    public static function refreshCounters(int $campaignId): array
    {
        $counts = self::statusCounts($campaignId);
        $counters = [
            'total' => array_sum($counts),
            'queued' => $counts['queued'] ?? 0,
            'sent' => $counts['sent'] ?? 0,
            'delivered' => $counts['delivered'] ?? 0,
            'read' => $counts['read'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'skipped' => $counts['skipped'] ?? 0,
        ];

        self::db()->prepare(
            'UPDATE wa_campaigns
             SET total_count = ?, queued_count = ?, sent_count = ?, delivered_count = ?, read_count = ?, failed_count = ?, skipped_count = ?
             WHERE id = ?'
        )->execute([
            $counters['total'], $counters['queued'], $counters['sent'], $counters['delivered'],
            $counters['read'], $counters['failed'], $counters['skipped'], $campaignId,
        ]);

        return $counters;
    }

    public static function isComplete(int $campaignId): bool
    {
        if (self::pendingCount($campaignId) !== 0) {
            return false;
        }
        $stmt = self::db()->prepare('SELECT COUNT(*) FROM wa_campaign_recipients WHERE campaign_id = ? AND claimed_at IS NOT NULL');
        $stmt->execute([$campaignId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    /** Marks a finished campaign. Returns the final status so the worker can report it. */
    public static function finish(int $campaignId): string
    {
        $counters = self::refreshCounters($campaignId);

        $status = 'done';
        if ($counters['sent'] === 0 && $counters['failed'] > 0) {
            $status = 'done';
        }

        self::db()->prepare('UPDATE wa_campaigns SET status = ?, finished_at = NOW() WHERE id = ?')
            ->execute([$status, $campaignId]);

        return $status;
    }

    public static function pause(int $campaignId, string $reason): void
    {
        self::db()->prepare('UPDATE wa_campaigns SET status = "paused", pause_reason = ? WHERE id = ? AND status IN ("queued","sending")')
            ->execute([mb_substr($reason, 0, 255), $campaignId]);
    }

    public static function resume(int $campaignId): bool
    {
        $stmt = self::db()->prepare('UPDATE wa_campaigns SET status = "queued", pause_reason = NULL WHERE id = ? AND status = "paused"');
        $stmt->execute([$campaignId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        self::releaseAllClaims($campaignId);
        return true;
    }

    public static function cancel(int $campaignId): bool
    {
        $pdo = self::db();
        // Anything not yet sent is dropped from the queue, but rows are kept so the record of who
        // was targeted survives the cancellation.
        $pdo->prepare('UPDATE wa_campaign_recipients SET status = "skipped", skip_reason = "campaign cancelled", claimed_at = NULL, lock_token = NULL WHERE campaign_id = ? AND status = "queued"')
            ->execute([$campaignId]);
        $stmt = $pdo->prepare('UPDATE wa_campaigns SET status = "cancelled", finished_at = NOW() WHERE id = ? AND status IN ("draft","queued","sending","paused")');
        $stmt->execute([$campaignId]);
        self::refreshCounters($campaignId);
        return $stmt->rowCount() > 0;
    }

    /** Puts failed recipients back in the queue, for the failures worth retrying. */
    public static function reopenFailures(int $campaignId, array $excludeCodes = []): int
    {
        $sql = 'UPDATE wa_campaign_recipients SET status = "queued", error_code = NULL, error_note = NULL, claimed_at = NULL, lock_token = NULL
                WHERE campaign_id = ? AND status = "failed"';
        $params = [$campaignId];

        if ($excludeCodes) {
            $sql .= ' AND (error_code IS NULL OR error_code NOT IN (' . implode(',', array_fill(0, count($excludeCodes), '?')) . '))';
            $params = array_merge($params, array_map('strval', $excludeCodes));
        }

        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        $count = $stmt->rowCount();
        if ($count > 0) {
            self::refreshCounters($campaignId);
            self::db()->prepare('UPDATE wa_campaigns SET status = "queued", finished_at = NULL WHERE id = ? AND status = "done"')
                ->execute([$campaignId]);
        }
        return $count;
    }

    /* -------------------------------------------------------------------- limits */

    public static function batchSize(): int
    {
        $size = (int) setting('wa_batch_size', 50);
        return $size > 0 ? min($size, 500) : 50;
    }

    /** Milliseconds between sends. Meta's per-second limit is unforgiving on a new number. */
    public static function sendDelayMs(): int
    {
        $delay = (int) setting('wa_send_delay_ms', 250);
        return max(0, min($delay, 10000));
    }

    /** How many messages went out today, counted from the recipients that actually sent. */
    public static function sentToday(): int
    {
        $stmt = self::db()->prepare(
            'SELECT COUNT(*) FROM wa_campaign_recipients WHERE sent_at IS NOT NULL AND DATE(sent_at) = CURDATE() AND tenant_id <=> ?'
        );
        $stmt->execute([self::tenantId()]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * The daily ceiling. Defaults to 250 because that is Meta's own limit for a number that is not
     * yet verified for higher throughput. Exceeding it does not fail loudly — it starts silently
     * throttling, which looks like messages simply not arriving.
     */
    public static function dailyCap(): int
    {
        $cap = (int) setting('wa_daily_message_cap', 250);
        return max(0, $cap);
    }

    public static function wouldExceedDailyCap(int $sending): bool
    {
        $cap = self::dailyCap();
        if ($cap === 0) {
            return false;
        }
        return (self::sentToday() + $sending) > $cap;
    }

    public static function remainingToday(): int
    {
        $cap = self::dailyCap();
        if ($cap === 0) {
            return PHP_INT_MAX;
        }
        return max(0, $cap - self::sentToday());
    }
}
