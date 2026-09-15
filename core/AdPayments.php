<?php
declare(strict_types=1);

/**
 * What a payment attempt means, recorded in one place.
 *
 * The routes decide *when* to ask; this decides *what the answer means* and writes it down. That split is
 * not tidiness — it is what makes the money logic testable at all. `redirect()` calls `exit`, so a route
 * handler cannot be driven from a harness without ending the harness process; a method that takes the
 * gateway's answer and returns an outcome can be, and every branch below is reached that way.
 *
 * Four rules this class exists to keep, each of which was got wrong somewhere else in this codebase at
 * least once:
 *
 * 1. **A verification that did not succeed is not a payment.** Nothing here credits an advert on a failed,
 *    empty or uncertain answer.
 * 2. **A return URL can be replayed.** Every write is guarded so that visiting the URL twice records the
 *    outcome once; a paid advert is read and never re-written.
 * 3. **Only an attempt the gateway saw counts as an attempt.** A closed payment window is not a failed
 *    payment, so it does not consume one of the advertiser's two online attempts. Otherwise two mis-clicks
 *    send somebody to bank transfer for no reason.
 * 4. **The counter is derived, never incremented.** `ads.payment_attempts` is re-read from the attempt rows
 *    after every outcome, so a webhook arriving first, a replayed return, or a crash between two writes
 *    cannot leave it disagreeing with what happened.
 */
final class AdPayments
{
    /** The advertiser asked for this: after two failed online attempts, bank transfer is the only option. */
    public const MAX_ONLINE_ATTEMPTS = 2;

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }

    /**
     * Records the outcome of one verification against one advert.
     *
     * @param  array<string, mixed> $ad       the advert row
     * @param  array<string, mixed> $verified the array `Payhub::verify()` returned
     * @return array{outcome:string,reason:string} 'paid' | 'pending' | 'failed', and what to tell the advertiser
     */
    public static function recordOutcome(array $ad, array $verified): array
    {
        $adId = (int) $ad['id'];
        $reference = (string) $ad['payment_reference'];

        // Already settled. A return URL that has been visited once will be visited again — by a refresh, a
        // back button, a mail client prefetching the link — and none of those may record anything twice.
        if ((string) $ad['payment_status'] === 'paid') {
            return ['outcome' => 'paid', 'reason' => ''];
        }

        $reason = (string) ($verified['reason'] ?? '');
        if ($reason === '') {
            $reason = (string) ($verified['error'] ?? '');
        }
        $raw = $verified['raw'] ?? [];
        $payload = (is_array($raw) && $raw !== []) ? (string) json_encode($raw) : null;

        if (!empty($verified['paid'])) {
            self::db()->prepare('UPDATE ads SET payment_status = "paid" WHERE id = ? AND tenant_id = ?')
                ->execute([$adId, (int) $ad['tenant_id']]);

            // `AND status = "pending"` is the second half of the replay guard: the attempt that was waiting
            // is settled, and a later visit finds nothing left to settle.
            self::db()->prepare('UPDATE ad_payments SET status = "success", gateway_response = ?, failure_reason = NULL
                                 WHERE ad_id = ? AND status = "pending" AND payment_method = "online"')
                ->execute([$payload, $adId]);

            self::syncAttemptCount($adId);

            return ['outcome' => 'paid', 'reason' => ''];
        }

        $status = (string) ($verified['status'] ?? '');

        // The gateway has never heard of this reference: the window was closed, or the page was abandoned.
        // No payment was attempted, so the row is removed rather than marked failed and the advertiser's
        // two attempts are untouched.
        if ($status === '') {
            self::db()->prepare('DELETE FROM ad_payments WHERE ad_id = ? AND reference = ? AND status = "pending" AND payment_method = "online"')
                ->execute([$adId, $reference]);
            self::syncAttemptCount($adId);

            return ['outcome' => 'failed', 'reason' => 'No payment was started.'];
        }

        if ($status === 'pending') {
            // The gateway knows about it and has not decided. The attempt stays pending and is not counted
            // as a failure, because it has not failed — it is still open.
            return ['outcome' => 'pending', 'reason' => $reason];
        }

        self::db()->prepare('UPDATE ad_payments SET status = "failed", failure_reason = ?, gateway_response = ?
                             WHERE ad_id = ? AND status = "pending" AND payment_method = "online"')
            ->execute([mb_substr($reason, 0, 255), $payload, $adId]);

        self::syncAttemptCount($adId);

        return ['outcome' => 'failed', 'reason' => $reason];
    }

    /** Re-derives the cached counter from the attempt rows. The rows are the record; this is a convenience. */
    public static function syncAttemptCount(int $adId): void
    {
        self::db()->prepare('UPDATE ads SET payment_attempts = (SELECT COUNT(*) FROM ad_payments p WHERE p.ad_id = ads.id) WHERE id = ?')
            ->execute([$adId]);
    }

    /** How many online attempts this advert has actually failed. The retry rule is measured on this. */
    public static function failedOnlineAttempts(int $adId): int
    {
        $stmt = self::db()->prepare('SELECT COUNT(*) FROM ad_payments WHERE ad_id = ? AND payment_method = "online" AND status = "failed"');
        $stmt->execute([$adId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many online attempts are left before bank transfer becomes the only option.
     *
     * Counted **per advert**, not per publisher: the advert is what is being bought, and an advertiser who
     * abandons one advert should not have the next one start already exhausted. Recorded as a decision in
     * docs/ROADMAP.md section 9b.
     */
    public static function remainingOnlineAttempts(int $adId): int
    {
        return max(0, self::MAX_ONLINE_ATTEMPTS - self::failedOnlineAttempts($adId));
    }
}
