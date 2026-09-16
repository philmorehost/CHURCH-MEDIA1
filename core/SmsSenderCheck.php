<?php
declare(strict_types=1);

/**
 * Watches the sender IDs a church has submitted and records the gateway's verdict on each.
 *
 * Split out of `cli/sms_sender_check.php` exactly as `core/SmsRunner.php` is split out of
 * `cli/sms_worker.php`: the worker owns the schedule, the reporting and the exit code, and this owns
 * what is true about one church's sender IDs. Keeping the pass here is also what makes it testable —
 * the sender-ID logic was previously reachable only by running the script.
 *
 * A pass is always for one named church, and it polls with that church's own gateway token. Asking the
 * gateway about another church's sender ID gets an answer about an ID that account does not own — and
 * the row would be marked rejected on the strength of it, permanently.
 */
final class SmsSenderCheck
{
    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }

    /**
     * The minimum minutes between checks for a request of this age.
     *
     * Polling backs off as a request gets older, because a sender ID that has been pending for a week is
     * not going to be approved in the next fifteen minutes.
     */
    public static function intervalFor(int $ageMinutes): int
    {
        if ($ageMinutes < 360) {   // under 6 hours
            return 15;
        }
        if ($ageMinutes < 1440) {  // under a day
            return 120;
        }
        if ($ageMinutes < 10080) { // under a week
            return 360;
        }
        return 1440;
    }

    /**
     * Checks every sender ID one church is waiting on, and writes down what the gateway said.
     *
     * `$isPlatformChurch` selects the scoping. The platform church sees both its own rows and the shared
     * ones (`tenant_id IS NULL`); every other church sees strictly its own. The shared rows belong to the
     * platform rather than to any one church, so they are polled during the platform church's pass and
     * not during every church's — a three-church install would otherwise poll each shared row three
     * times, with three different tokens, and could get three different answers, of which the last
     * would win.
     *
     * Note `tenant_id <=> ?` would *not* do here. `<=>` is null-safe equality, so `NULL <=> 1` is false:
     * it matches the shared rows only when the bound value is itself null, which is how
     * `admin/partials/sms/sender-ids.php` uses it — from a web request where `Tenant::id()` can be null.
     * A pass always names a concrete church, so the shared rows have to be asked for explicitly.
     *
     * Only `pending` rows can change, and a manual override is not ours to overrule.
     *
     * @return array{configured:bool,checked:int,approved:int,rejected:int,pending:int,errors:int,lines:array<int,array{stream:string,text:string}>}
     */
    public static function pass(int $tenantId, bool $checkAll, bool $isPlatformChurch): array
    {
        $out = [
            'configured' => true,
            'checked' => 0,
            'approved' => 0,
            'rejected' => 0,
            'pending' => 0,
            'errors' => 0,
            'lines' => [],
        ];

        if (!Sms::configured()) {
            $out['configured'] = false;
            return $out;
        }

        foreach (self::dueRows($tenantId, $isPlatformChurch) as $row) {
            if (!$checkAll && !self::isDue($row)) {
                continue;
            }

            $result = self::resolve($row);
            $out['checked']++;
            $out[$result['outcome']]++;
            $out['lines'][] = $result['line'];
        }

        return $out;
    }

    /**
     * The church scoping, in one place, because it is needed by both the due set and the listing.
     *
     * `tenant_id <=> ?` would *not* do. `<=>` is null-safe equality, so `NULL <=> 1` is false: it matches
     * the shared rows only when the bound value is itself null, which is how
     * `admin/partials/sms/sender-ids.php` uses it — from a web request where `Tenant::id()` can be null.
     * A pass always names a concrete church, so the shared rows have to be asked for explicitly.
     */
    private static function scopedTo(bool $isPlatformChurch): string
    {
        return $isPlatformChurch ? '(tenant_id = ? OR tenant_id IS NULL)' : 'tenant_id = ?';
    }

    /**
     * Every sender ID row one church can see, whatever its status. For the operator listing.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rowsFor(int $tenantId, bool $isPlatformChurch): array
    {
        try {
            $stmt = self::db()->prepare(
                'SELECT * FROM sms_senders WHERE ' . self::scopedTo($isPlatformChurch) . ' ORDER BY status ASC, id ASC'
            );
            $stmt->execute([$tenantId]);

            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('SmsSenderCheck rowsFor failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * The rows that could still change, for this church alone.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function dueRows(int $tenantId, bool $isPlatformChurch): array
    {
        try {
            $stmt = self::db()->prepare(
                "SELECT * FROM sms_senders
                 WHERE status = 'pending' AND status_source = 'gateway' AND " . self::scopedTo($isPlatformChurch) . "
                 ORDER BY id ASC"
            );
            $stmt->execute([$tenantId]);

            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('SmsSenderCheck dueRows failed: ' . $e->getMessage());
            return [];
        }
    }

    /** False while a row is inside its back-off window, so the gateway is not hammered. */
    private static function isDue(array $row): bool
    {
        if ($row['last_checked_at'] === null) {
            return true;
        }

        $ageMinutes = (int) floor((time() - strtotime((string) $row['created_at'])) / 60);
        $sinceLast = (int) floor((time() - strtotime((string) $row['last_checked_at'])) / 60);

        return $sinceLast >= self::intervalFor(max(0, $ageMinutes));
    }

    /**
     * Asks the gateway about one sender ID and records the answer.
     *
     * @return array{outcome:string,line:array{stream:string,text:string}}
     */
    private static function resolve(array $row): array
    {
        $id = (int) $row['id'];
        $senderId = (string) $row['sender_id'];

        $result = Sms::senderIdStatus($senderId);

        if (!$result['ok']) {
            // Record the attempt so a gateway that is down does not cause a retry storm.
            $error = (string) ($result['error'] ?? 'check failed');
            self::note($id, 'Check failed: ' . $error);

            return [
                'outcome' => 'errors',
                'line' => ['stream' => 'err', 'text' => sprintf('%s — %s', $senderId, $error)],
            ];
        }

        // The gateway is inconsistent about the key and the case, so read it loosely.
        $raw = $result['raw'];
        $status = '';
        foreach (['status', 'senderIDStatus', 'sender_status', 'message', 'data'] as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $status = strtolower(trim((string) $raw[$key]));
                break;
            }
        }

        // Match on the words rather than on an exact string: the gateway has answered with
        // "Approved", "approved." and "Sender ID approved" at different times.
        if (str_contains($status, 'approve')) {
            return self::approve($row, $senderId);
        }
        if (str_contains($status, 'reject') || str_contains($status, 'declin') || str_contains($status, 'denied')) {
            return self::reject($row, $senderId, $status);
        }

        self::note($id, 'Still pending at the gateway: ' . ($status !== '' ? $status : 'no status given'));

        return [
            'outcome' => 'pending',
            'line' => ['stream' => 'out', 'text' => ''],
        ];
    }

    /**
     * @return array{outcome:string,line:array{stream:string,text:string}}
     */
    private static function approve(array $row, string $senderId): array
    {
        $id = (int) $row['id'];

        self::db()->prepare("UPDATE sms_senders SET status = 'approved', status_source = 'gateway', approved_at = NOW(), last_checked_at = NOW(), last_check_note = NULL WHERE id = ?")
            ->execute([$id]);

        // If nothing else is set as default yet, make this one it — a church that has just been
        // approved should be able to send without hunting for a setting. `setting()` and `settingSave()`
        // are both per church, so inside a pass this writes the church the pass is for, and never the
        // church that happens to be serving the request.
        if ((string) setting('sms_default_sender_id', '') === '') {
            settingSave(['sms_default_sender_id' => $senderId]);
        }

        self::notifyApproved($row);

        return [
            'outcome' => 'approved',
            'line' => ['stream' => 'out', 'text' => sprintf('%s approved.', $senderId)],
        ];
    }

    /**
     * @return array{outcome:string,line:array{stream:string,text:string}}
     */
    private static function reject(array $row, string $senderId, string $status): array
    {
        $note = mb_substr('The gateway rejected this sender ID. ' . ($status !== '' ? 'Gateway said: ' . $status : ''), 0, 255);

        self::db()->prepare("UPDATE sms_senders SET status = 'rejected', status_source = 'gateway', rejection_note = ?, last_checked_at = NOW() WHERE id = ?")
            ->execute([$note, (int) $row['id']]);

        self::notifyRejected($row, $note);

        return [
            'outcome' => 'rejected',
            'line' => ['stream' => 'out', 'text' => sprintf('%s rejected.', $senderId)],
        ];
    }

    /** Records an attempt without changing the verdict, so the back-off clock moves. */
    private static function note(int $id, string $note): void
    {
        self::db()->prepare('UPDATE sms_senders SET last_checked_at = NOW(), last_check_note = ? WHERE id = ?')
            ->execute([mb_substr($note, 0, 255), $id]);
    }

    /**
     * Tells the church that submitted the sender ID that it is ready to use.
     *
     * Addressed to the submitting unit, and to the submitter's own unit when they are different — the
     * person who filled the form and the church it belongs to both want to know. `media_team` is
     * included because that is who the roadmap has submitting these, and they are the ones the approval
     * unblocks.
     */
    private static function notifyApproved(array $row): void
    {
        $units = [];
        if (!empty($row['org_unit_id'])) {
            $units[] = (int) $row['org_unit_id'];
        }
        if (!empty($row['submitted_by'])) {
            $submitterUnit = self::unitOf((int) $row['submitted_by']);
            if ($submitterUnit > 0) {
                $units[] = $submitterUnit;
            }
        }

        if ($units === []) {
            // No unit attached — tell everyone who can act on it rather than nobody. Bounded to the
            // sender's own church: a pass runs as the church the row belongs to, so asking for
            // `Unit::all()` would name every church in the platform.
            $tenantId = (int) ($row['tenant_id'] ?? 0);
            if ($tenantId <= 0) {
                $tenantId = (int) (Tenant::id() ?? 0);
            }
            foreach (Unit::allForTenant($tenantId, 'id ASC') as $unit) {
                $units[] = (int) $unit['id'];
            }
        }

        try {
            Notifier::send(
                $units,
                'Sender ID ' . (string) $row['sender_id'] . ' approved',
                'Your sender ID ' . (string) $row['sender_id'] . ' has been approved and is ready to use. '
                . 'You can now send SMS from Admin → SMS → Compose.',
                ['email' => true, 'push' => true, 'roles' => ['admin', 'editor', 'media_team']]
            );
        } catch (Throwable $e) {
            error_log('SmsSenderCheck notify failed: ' . $e->getMessage());
        }
    }

    /** Tells the submitter why it was refused, so they can fix it and try again. */
    private static function notifyRejected(array $row, string $note): void
    {
        $units = [];
        if (!empty($row['org_unit_id'])) {
            $units[] = (int) $row['org_unit_id'];
        } elseif (!empty($row['submitted_by'])) {
            $submitterUnit = self::unitOf((int) $row['submitted_by']);
            if ($submitterUnit > 0) {
                $units[] = $submitterUnit;
            }
        }

        if ($units === []) {
            return;
        }

        try {
            Notifier::send(
                $units,
                'Sender ID ' . (string) $row['sender_id'] . ' was not approved',
                $note . "\n\nYou can submit a different sender ID under Admin → SMS → Sender IDs.",
                ['email' => true, 'push' => false, 'roles' => ['admin', 'editor', 'media_team']]
            );
        } catch (Throwable $e) {
            error_log('SmsSenderCheck notify failed: ' . $e->getMessage());
        }
    }

    /** The unit a user belongs to, or 0. */
    private static function unitOf(int $userId): int
    {
        try {
            $stmt = self::db()->prepare('SELECT org_unit_id FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);

            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
