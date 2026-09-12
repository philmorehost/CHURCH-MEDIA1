<?php
declare(strict_types=1);

/**
 * Drives the SMS queue for one run.
 *
 * Kept out of `cli/sms_worker.php` so the whole send loop can be driven from a test
 * without shelling out — which is the only way to prove the thing that matters most:
 * that a retry, an overlap or a mid-campaign failure never texts anyone twice.
 *
 * `partial` never means "something is broken". It means the run stopped for a reason an
 * admin can act on — an empty wallet, quiet hours, the daily cap — with that reason
 * recorded in `paused_reason`. A campaign that said `failed` would invite an admin to
 * re-send the whole thing, which is exactly how people get texted twice.
 *
 * **On delivery receipts:** the gateway answers one code per API call, not per number, so
 * "sent" means *the gateway accepted it for delivery*. It is not proof a handset received
 * it, and nothing in the admin screens should claim otherwise.
 */
final class SmsRunner
{
    /** Seconds a single run may take, so a cron tick never piles onto the last. */
    public const DEFAULT_DEADLINE = 50;

    /** True when the message needs a per-recipient send. */
    public static function hasPlaceholders(string $message): bool
    {
        return (bool) preg_match('/\{(name|first_name|church)\}/i', $message);
    }

    /**
     * Works the queue.
     *
     * @param  array{deadline?:int,only_campaign?:int,force?:bool} $options
     * @return array<string, mixed>
     */
    public static function run(array $options = []): array
    {
        $deadlineSeconds = (int) ($options['deadline'] ?? self::DEFAULT_DEADLINE);
        $onlyCampaign = (int) ($options['only_campaign'] ?? 0);
        $force = (bool) ($options['force'] ?? false);

        $started = microtime(true);
        $deadline = $started + max(5, $deadlineSeconds);

        $summary = [
            'ok' => true,
            'promoted' => SmsCampaign::promoteDue(),
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'paused' => 0,
            'paused_reasons' => [],
            'outcomes' => [],
            'stopped' => null,
        ];

        if (!Sms::configured()) {
            $summary['ok'] = false;
            $summary['stopped'] = 'No SMS API token is configured.';
            return $summary;
        }

        // Gate 1: quiet hours. Campaigns stay queued and resume on their own.
        if (!$force && !SmsCampaign::withinQuietHours()) {
            $summary['stopped'] = 'Outside the sending window (' . SmsCampaign::quietHoursLabel() . ').';
            return $summary;
        }

        // Gate 2: the daily cap, checked before anything is claimed.
        $cap = SmsCampaign::dailyCap();
        if ($cap > 0 && SmsCampaign::unitsSentToday() >= $cap) {
            $reason = 'Daily cap of ' . $cap . ' unit(s) already used. Sending resumes tomorrow.';
            foreach (SmsCampaign::active(50) as $row) {
                SmsCampaign::pause((int) $row['id'], $reason);
                $summary['paused']++;
                $summary['paused_reasons'][] = $reason;
            }
            $summary['stopped'] = $reason;
            return $summary;
        }

        // One balance check for the whole run, not one per campaign.
        $balance = Sms::balance();
        $spendable = $balance['balance'] !== null ? (int) $balance['balance'] : null;

        $campaigns = $onlyCampaign > 0
            ? array_values(array_filter([SmsCampaign::find($onlyCampaign)]))
            : SmsCampaign::active();

        foreach ($campaigns as $campaign) {
            if (time() >= $deadline) {
                $summary['stopped'] = 'Time budget reached; the rest resumes on the next run.';
                break;
            }
            if (!is_array($campaign)) {
                continue;
            }

            $campaignId = (int) $campaign['id'];
            if (!in_array((string) $campaign['status'], SmsCampaign::ACTIVE_STATUSES, true)) {
                $summary['outcomes'][$campaignId] = 'skipped (' . $campaign['status'] . ')';
                continue;
            }

            $remaining = SmsCampaign::pendingCount($campaignId);
            if ($remaining === 0) {
                $summary['outcomes'][$campaignId] = SmsCampaign::finish($campaignId);
                continue;
            }

            $result = self::workCampaign($campaign, $remaining, $spendable, $cap, $deadline);
            $summary['sent'] += $result['sent'];
            $summary['failed'] += $result['failed'];
            $summary['processed']++;
            $summary['outcomes'][$campaignId] = $result['outcome'];

            if ($result['paused_reason'] !== null) {
                $summary['paused']++;
                $summary['paused_reasons'][] = $result['paused_reason'];
            }
            if ($result['stop_run']) {
                $summary['stopped'] = $result['paused_reason'] ?? 'Stopped early.';
                break;
            }
        }

        $summary['elapsed'] = round(microtime(true) - $started, 1);
        return $summary;
    }

    /**
     * Sends as much of one campaign as the budget and the clock allow.
     *
     * @return array{sent:int,failed:int,outcome:string,paused_reason:?string,stop_run:bool}
     */
    private static function workCampaign(array $campaign, int $remaining, ?int $spendable, int $cap, float $deadline): array
    {
        $campaignId = (int) $campaign['id'];
        $message = (string) $campaign['message'];
        $senderId = (string) ($campaign['sender_id'] ?: Sms::defaultSenderId());
        $unitsPerRecipient = max(1, Sms::segmentsFor($message));
        $personalised = self::hasPlaceholders($message);

        $out = ['sent' => 0, 'failed' => 0, 'outcome' => '', 'paused_reason' => null, 'stop_run' => false];

        // Wallet gate: stop before spending anything rather than part-way through.
        if ($spendable !== null && $remaining * $unitsPerRecipient > $spendable) {
            $reason = sprintf(
                'Wallet balance is %s unit(s) but this campaign still needs %d. Top up the SMS wallet, then resume it.',
                number_format($spendable),
                $remaining * $unitsPerRecipient
            );
            SmsCampaign::pause($campaignId, $reason);
            self::alert('SMS campaign paused — balance too low', $reason, $campaign);
            $out['paused_reason'] = $reason;
            $out['outcome'] = 'paused (balance)';
            $out['stop_run'] = true; // every other campaign would hit the same wall
            return $out;
        }

        while (time() < $deadline) {
            // Re-check the cap between batches: a large campaign starts inside the cap
            // and would otherwise sail straight past it.
            if ($cap > 0 && SmsCampaign::unitsSentToday() >= $cap) {
                $reason = 'Daily cap of ' . $cap . ' unit(s) reached mid-campaign. Sending resumes tomorrow.';
                self::pauseWithRelease($campaignId, $reason, $campaign);
                $out['paused_reason'] = $reason;
                $out['outcome'] = 'paused (daily cap)';
                return $out;
            }

            $batch = SmsCampaign::claim($campaignId, Sms::batchSize());
            if ($batch['rows'] === []) {
                break;
            }
            $rows = $batch['rows'];

            if ($personalised) {
                // Every message is unique, so it cannot be batched. Slower, and the
                // reason the deadline and the queue exist at all.
                foreach ($rows as $row) {
                    if (time() >= $deadline) {
                        SmsCampaign::release((int) $row['id']);
                        break 2;
                    }
                    $text = Sms::personalise($message, [
                        'name' => (string) ($row['name'] ?? ''),
                        'church' => (string) setting('site_title', ''),
                    ]);
                    $send = Sms::send([(string) $row['msisdn']], $text, $senderId, $campaignId);
                    $applied = self::applyOutcome($send, [$row], $unitsPerRecipient, $campaignId, $campaign);
                    $out['sent'] += $applied['sent'];
                    $out['failed'] += $applied['failed'];
                    if ($applied['paused_reason'] !== null) {
                        $out['paused_reason'] = $applied['paused_reason'];
                        $out['outcome'] = $applied['outcome'];
                        $out['stop_run'] = $applied['stop_run'];
                        return $out;
                    }
                }
            } else {
                $send = Sms::send(array_map(static fn(array $r): string => (string) $r['msisdn'], $rows), $message, $senderId, $campaignId);
                $applied = self::applyOutcome($send, $rows, $unitsPerRecipient, $campaignId, $campaign);
                $out['sent'] += $applied['sent'];
                $out['failed'] += $applied['failed'];
                if ($applied['paused_reason'] !== null) {
                    $out['paused_reason'] = $applied['paused_reason'];
                    $out['outcome'] = $applied['outcome'];
                    $out['stop_run'] = $applied['stop_run'];
                    return $out;
                }
            }
        }

        $counters = SmsCampaign::refreshCounters($campaignId);
        if ($counters['pending'] === 0) {
            $out['outcome'] = SmsCampaign::finish($campaignId);
        } else {
            SmsCampaign::finish($campaignId);
            $out['outcome'] = 'sending (' . $counters['pending'] . ' left, resumes next run)';
        }

        return $out;
    }

    /**
     * Records how the gateway answered for a batch of already-claimed rows.
     *
     * @param  array{ok:bool,code:?string,error:?string} $send
     * @param  array<int, array<string, mixed>>          $rows
     * @return array{sent:int,failed:int,paused_reason:?string,outcome:string,stop_run:bool}
     */
    private static function applyOutcome(array $send, array $rows, int $unitsPerRecipient, int $campaignId, array $campaign): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'paused_reason' => null, 'outcome' => '', 'stop_run' => false];

        if ($send['ok']) {
            foreach ($rows as $row) {
                SmsCampaign::recordSent((int) $row['id'], $unitsPerRecipient);
            }
            $out['sent'] = count($rows);
            $out['outcome'] = 'sending';
            return $out;
        }

        $code = (string) ($send['code'] ?? '');

        // Out of credit. Hand the rows back as pending — they were never sent, so
        // marking them failed would silently abandon work that still needs doing.
        if ($code === '107') {
            foreach ($rows as $row) {
                SmsCampaign::release((int) $row['id']);
            }
            $reason = 'The SMS wallet ran out mid-campaign. Top it up, then resume to send the rest.';
            SmsCampaign::pause($campaignId, $reason);
            self::alert('SMS campaign paused — wallet empty', $reason, $campaign);
            $out['paused_reason'] = $reason;
            $out['outcome'] = 'paused (wallet empty)';
            $out['stop_run'] = true; // no other campaign can succeed either
            return $out;
        }

        foreach ($rows as $row) {
            SmsCampaign::recordFailure(
                (int) $row['id'],
                $send['code'],
                (string) ($send['error'] ?? 'The gateway rejected this message.')
            );
            $out['failed']++;
        }
        $out['outcome'] = 'sending (' . $out['failed'] . ' failed)';

        // A rejected token or a blocked word will fail identically for every remaining
        // batch, so stop rather than burning through the list.
        if (Sms::isFatalCode($send['code'])) {
            $reason = 'Stopped after the gateway refused the message: ' . (string) ($send['error'] ?? $code);
            SmsCampaign::pause($campaignId, $reason);
            self::alert('SMS campaign stopped', $reason, $campaign);
            $out['paused_reason'] = $reason;
            $out['outcome'] = 'paused (gateway ' . $code . ')';
            $out['stop_run'] = true;
        }

        return $out;
    }

    /** Releases this campaign's claims, then parks it with a reason. */
    private static function pauseWithRelease(int $campaignId, string $reason, array $campaign): void
    {
        SmsCampaign::releaseAllClaims($campaignId);
        SmsCampaign::pause($campaignId, $reason);
        self::alert('SMS campaign paused', $reason, $campaign);
    }

    /**
     * Tells the people who can fix it.
     *
     * Sent to the campaign's own church, or to every unit with an admin when the
     * campaign is not tied to one — a paused campaign nobody hears about is a campaign
     * nobody resumes.
     */
    private static function alert(string $title, string $reason, array $campaign): void
    {
        try {
            $units = [];
            if (!empty($campaign['org_unit_id'])) {
                $units[] = (int) $campaign['org_unit_id'];
            } else {
                foreach (Unit::all('id ASC') as $unit) {
                    $units[] = (int) $unit['id'];
                }
            }

            Notifier::send(
                $units,
                $title,
                $reason . "\n\nCampaign: " . (string) $campaign['title'] . ' (#' . (int) $campaign['id'] . ')',
                ['email' => true, 'push' => true]
            );
        } catch (Throwable $e) {
            // An alert that cannot be delivered must not undo the pause.
            error_log('SmsRunner alert failed: ' . $e->getMessage());
        }
    }
}
