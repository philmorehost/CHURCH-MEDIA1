#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * WhatsApp broadcast worker. Run from cron every minute; see Admin → WhatsApp → Guide.
 * Safe to run by hand at any time.
 *
 * What one run does, in order:
 *   1. Refuses to send when the channel is switched off or half-configured, leaving
 *      campaigns queued rather than failing them, so fixing the setting resumes the send.
 *   2. Refuses outside the quiet-hours window — the church's own sending window, shared with
 *      SMS, because "do not message me at 2am" is about the person, not the channel.
 *   3. Enforces the daily cap. This is the one that matters most on a new number: Meta's
 *      limit for an unverified number is low, and exceeding it does not fail loudly — it
 *      starts silently throttling, which looks exactly like messages not arriving. Hitting
 *      the cap pauses the campaign instead of pressing on.
 *   4. Sends in claimed batches with a delay between messages, and records an outcome per
 *      recipient.
 *
 * **Only templates are sent.** A broadcast goes to people who have not messaged recently, so
 * the 24-hour free-form window is closed for essentially all of them. Every recipient is sent
 * the campaign's approved template; there is no free-text path here by design.
 *
 * **Opt-in was enforced at queue time, not here.** Somebody who never opted in was recorded as
 * skipped with the reason when the audience was built. The worker sends what is queued, because
 * re-checking here would mean two places could disagree about who may be messaged.
 *
 * **On delivery.** `sent` means Meta accepted it. Whether it arrived, and whether it was read,
 * arrives later by webhook and updates the same rows. Nothing here claims more than that.
 *
 * Usage:
 *   php cli/wa_worker.php                  work the queue
 *   php cli/wa_worker.php --campaign=12    only campaign 12
 *   php cli/wa_worker.php --status         print the queue and exit
 *   php cli/wa_worker.php --force          ignore quiet hours (not the daily cap)
 *   php cli/wa_worker.php --dry-run        show what would be sent, send nothing
 *   php cli/wa_worker.php --quiet          print nothing on a run with no work
 */

if (!defined('STDERR')) {
    $errStream = @fopen('php://stderr', 'wb');
    define('STDERR', $errStream ?: fopen('php://output', 'wb'));
}
if (!defined('STDOUT')) {
    $outStream = @fopen('php://stdout', 'wb');
    define('STDOUT', $outStream ?: fopen('php://output', 'wb'));
}

require __DIR__ . '/../bootstrap.php';

if (!defined('APP_IS_INSTALLED') || !APP_IS_INSTALLED) {
    fwrite(STDERR, "wa_worker: the application is not installed; nothing to do.\n");
    exit(1);
}

$options = array_slice($argv, 1);
$onlyCampaign = 0;
$statusOnly = false;
$force = false;
$dryRun = false;
$silent = false;

foreach ($options as $option) {
    if (str_starts_with($option, '--campaign=')) {
        $onlyCampaign = (int) substr($option, 11);
    } elseif ($option === '--status') {
        $statusOnly = true;
    } elseif ($option === '--force') {
        $force = true;
    } elseif ($option === '--dry-run') {
        $dryRun = true;
    } elseif ($option === '--quiet') {
        $silent = true;
    } elseif ($option === '--help' || $option === '-h') {
        fwrite(STDOUT, "Usage: php cli/wa_worker.php [--campaign=ID] [--status] [--force] [--dry-run] [--quiet]\n");
        exit(0);
    } else {
        fwrite(STDERR, "wa_worker: unknown option {$option}\n");
        exit(2);
    }
}

function waSay(string $line, bool $silent): void
{
    if (!$silent) {
        fwrite(STDOUT, $line . "\n");
    }
}

/* ------------------------------------------------------------------------ status */

if ($statusOnly) {
    // One report per church. The queue, the cap and the Meta credentials are all per church, and from a
    // shell the church being served is only ever the default one — so reporting once would print one
    // church's queue and call it the install's. Written straight to STDOUT rather than through waSay(),
    // because asking for --status is asking for output whatever --quiet says.
    $status = Tenant::each(static function (int $tenantId) use ($onlyCampaign): array {
        $campaigns = $onlyCampaign > 0
            ? array_filter(array(WaCampaign::find($onlyCampaign)))
            : WaCampaign::active(50);

        $rows = array();
        foreach ($campaigns as $campaign) {
            $rows[] = waCampaignLine($campaign, WaCampaign::refreshCounters((int) $campaign['id']));
        }

        return array(
            'rows' => $rows,
            'today' => WaCampaign::sentToday(),
            'cap' => WaCampaign::dailyCap(),
        );
    });

    $several = count($status) > 1;
    foreach ($status as $tenantId => $entry) {
        if ($entry['ok'] !== true || !is_array($entry['result'])) {
            fwrite(STDERR, 'wa_worker: status failed for church ' . $tenantId
                . ' — ' . (string) ($entry['error'] ?? 'unknown error') . "\n");
            continue;
        }
        $report = $entry['result'];

        if ($several) {
            $church = Tenant::find((int) $tenantId);
            fwrite(STDOUT, "\n" . (string) ($church['name'] ?? ('Church ' . $tenantId)) . "\n");
        }

        if (!$report['rows']) {
            fwrite(STDOUT, "No campaigns are queued or sending.\n");
        } else {
            foreach ($report['rows'] as $line) {
                fwrite(STDOUT, $line . "\n");
            }
        }

        fwrite(STDOUT, sprintf("Sent today: %d of %d.\n", (int) $report['today'], (int) $report['cap']));
    }
    exit(0);
}

/* ------------------------------------------------------------------------ work */

// One pass per church, because every gate below reads a per-church setting: `WhatsApp` reads its
// number ID, token and app secret from `setting('wa_*')`, the quiet-hours window is shared with SMS
// but still per church, and the daily cap is per number — Meta's ceiling applies to the number, not to
// the installation. Hoisting those gates above the loop, as this file used to, meant the default
// church's settings decided for everybody, and a church with no number configured stopped every other
// church's queue from being worked at all.
$runs = Tenant::each(static function (int $tenantId) use ($onlyCampaign, $force, $dryRun): array {
    $result = array(
        'stopped' => null,
        'notes' => array(),
        'sent' => 0,
        'failed' => 0,
        'released' => 0,
        'worked' => false,
    );

    $problem = WhatsApp::problem();
    if ($problem !== null) {
        // Left queued rather than failed. Fixing the setting should resume the send, not require
        // an admin to notice a failure and re-create the campaign.
        $result['stopped'] = 'Not sending: ' . $problem;
        return $result;
    }

    if (!$force && !SmsCampaign::withinQuietHours()) {
        $result['stopped'] = 'Outside the sending window (' . SmsCampaign::quietHoursLabel() . '). Leaving campaigns queued.';
        return $result;
    }

    $remaining = WaCampaign::remainingToday();
    if ($remaining <= 0) {
        $result['stopped'] = sprintf(
            'Daily cap reached (%d of %d). Leaving campaigns queued; they resume tomorrow.',
            WaCampaign::sentToday(),
            WaCampaign::dailyCap()
        );
        return $result;
    }

    $batchSize = WaCampaign::batchSize();
    $delayMs = WaCampaign::sendDelayMs();
    $toSend = min($batchSize, $remaining);

    $campaigns = $onlyCampaign > 0
        ? array_filter(array(WaCampaign::find($onlyCampaign)))
        : WaCampaign::active(WaCampaign::CAMPAIGNS_PER_RUN);

    if (!$campaigns) {
        $result['stopped'] = 'Nothing queued.';
        return $result;
    }

    $token = bin2hex(random_bytes(8));

    foreach ($campaigns as $campaign) {
        $campaignId = (int) $campaign['id'];

        if ((string) $campaign['status'] === 'paused') {
            continue;
        }

        $stale = WaCampaign::releaseStale($campaignId);
        if ($stale > 0) {
            $result['notes'][] = sprintf('#%d released %d stale claim(s) from an interrupted run.', $campaignId, $stale);
            $result['released'] += $stale;
        }

        $pending = WaCampaign::pendingCount($campaignId);
        if ($pending === 0) {
            WaCampaign::finish($campaignId);
            continue;
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->prepare('UPDATE wa_campaigns SET status = "sending", started_at = COALESCE(started_at, NOW()) WHERE id = ? AND status = "queued"')
            ->execute([$campaignId]);

        $recipients = WaCampaign::claim($campaignId, $toSend, $token);
        if (!$recipients) {
            WaCampaign::finish($campaignId);
            continue;
        }

        $result['worked'] = true;

        foreach ($recipients as $recipient) {
            // The cap is re-checked inside the loop: a batch of 50 can cross it partway, and
            // stopping mid-batch is the difference between a slow day and a throttled number.
            if (WaCampaign::remainingToday() <= 0) {
                WaCampaign::release((int) $recipient['id']);
                WaCampaign::pause($campaignId, 'Daily message cap reached');
                $result['notes'][] = sprintf('#%d paused: daily cap reached.', $campaignId);
                break;
            }

            if ($dryRun) {
                WaCampaign::release((int) $recipient['id']);
                $result['notes'][] = sprintf('  would send to %s', (string) $recipient['msisdn']);
                continue;
            }

            $params = [];
            if (!empty($recipient['params'])) {
                $decoded = json_decode((string) $recipient['params'], true);
                if (is_array($decoded)) {
                    $params = array_map('strval', $decoded);
                }
            }

            $conversation = waConversationFor((string) $recipient['msisdn'], $recipient);

            // Deliberately NOT `$result`. That is this pass's accumulator, and naming the API's answer
            // the same thing silently threw away every count the pass had made — the pass then
            // reported zero sent however many messages it had actually sent.
            $send = WhatsApp::sendTemplateToConversation(
                $conversation,
                (string) $campaign['template_name'],
                $params,
                isset($campaign['created_by']) ? (int) $campaign['created_by'] : null
            );

            if (!empty($send['ok'])) {
                WaCampaign::recordSent((int) $recipient['id'], $send['message_id'] ?? null);
                $result['sent']++;
            } else {
                WaCampaign::recordFailure(
                    (int) $recipient['id'],
                    $send['code'] ?? null,
                    (string) ($send['error'] ?? 'Unknown error')
                );
                $result['failed']++;
            }

            if ($delayMs > 0) {
                // Meta's limits are per second. Hammering the endpoint is how a new number gets
                // throttled, and a throttle is indistinguishable from a send that silently failed.
                usleep($delayMs * 1000);
            }
        }

        /*
         * Sweep the batch: anything still claimed was not sent.
         *
         * This is the guarantee, not a tidy-up. Leaving the loop early — because the cap was reached,
         * because this was a dry run, or because a send threw — would otherwise leave the remaining
         * recipients locked until releaseStale() picked them up ten minutes later. They would not be
         * lost, but the campaign would sit looking stuck and the next cron run would find nothing to
         * do, which reads as a fault rather than as "wait".
         */
        $batchIds = array_map(static fn (array $r): int => (int) $r['id'], $recipients);
        if ($batchIds !== []) {
            $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
            $pdo->prepare(
                "UPDATE wa_campaign_recipients
                 SET claimed_at = NULL, lock_token = NULL
                 WHERE id IN ($placeholders) AND sent_at IS NULL AND status = 'queued'"
            )->execute($batchIds);
        }

        WaCampaign::refreshCounters($campaignId);

        if (WaCampaign::pendingCount($campaignId) === 0) {
            WaCampaign::finish($campaignId);
        }
    }

    return $result;
});

/* ---------------------------------------------------------------------- summary */

$several = count($runs) > 1;
$totals = ['sent' => 0, 'failed' => 0, 'released' => 0];
$stoppedSomewhere = false;
$failedPasses = 0;
$didWork = false;

foreach ($runs as $tenantId => $entry) {
    $church = $several ? Tenant::find((int) $tenantId) : null;
    $label = $several ? ((string) ($church['name'] ?? ('Church ' . $tenantId)) . ': ') : '';

    if ($entry['ok'] !== true || !is_array($entry['result'])) {
        // The pass threw. Tenant::each held it so the other churches still ran, so this is where it
        // has to become visible — silently skipping a church is how a broadcast quietly stops.
        $failedPasses++;
        fwrite(STDERR, 'wa_worker: ' . $label . 'the run failed — ' . (string) ($entry['error'] ?? 'unknown error') . "\n");
        continue;
    }

    $summary = $entry['result'];

    foreach ($summary['notes'] as $note) {
        waSay($label . $note, $silent);
    }

    // A church that could not send is not a failure and has no totals to add. One church being
    // switched off, or out of quota, must not stop the others.
    if ($summary['stopped'] !== null) {
        waSay($label . $summary['stopped'], $silent);
        $stoppedSomewhere = true;
        continue;
    }

    $totals['sent'] += (int) $summary['sent'];
    $totals['failed'] += (int) $summary['failed'];
    $totals['released'] += (int) $summary['released'];
    if ($summary['worked']) {
        $didWork = true;
    }

    waSay(sprintf(
        '%sToday: %d of %d sent.',
        $label,
        WaCampaign::sentToday(),
        WaCampaign::dailyCap()
    ), $silent);
}

// The per-church ceilings differ, so there is no honest install-wide "today" figure to print here —
// each church's own line is above. And a run where every church stopped prints no total at all: each
// of them already said why, and "Sent 0, failed 0" under a reason is noise that reads like a summary.
if ($didWork) {
    waSay(sprintf(
        'Sent %d, failed %d%s.',
        $totals['sent'],
        $totals['failed'],
        $totals['released'] > 0 ? ', released ' . $totals['released'] : ''
    ), $silent);
}

exit($failedPasses > 0 ? 1 : 0);

/**
 * One campaign's line in the --status report.
 *
 * @param array<string, mixed> $campaign
 * @param array<string, int> $counters
 */
function waCampaignLine(array $campaign, array $counters): string
{
    return sprintf(
        '#%d  %s  [%s]  queued %d · sent %d · delivered %d · read %d · failed %d · skipped %d',
        (int) $campaign['id'],
        (string) $campaign['name'],
        (string) $campaign['status'],
        (int) $counters['queued'],
        (int) $counters['sent'],
        (int) $counters['delivered'],
        (int) $counters['read'],
        (int) $counters['failed'],
        (int) $counters['skipped']
    );
}

/**
 * The conversation a campaign message belongs to, creating one if the person has never written.
 *
 * A broadcast to somebody who has never messaged the church has no conversation yet, and without
 * one the outbound message would have nowhere to be filed — so the inbox would show replies from
 * a person with no history.
 *
 * @param array<string, mixed> $recipient
 * @return array<string, mixed>
 */
function waConversationFor(string $msisdn, array $recipient): array
{
    $pdo = Database::getInstance()->getConnection();
    $tenantId = class_exists('Tenant') ? Tenant::id() : null;

    $stmt = $pdo->prepare('SELECT * FROM wa_conversations WHERE tenant_id <=> ? AND msisdn = ? LIMIT 1');
    $stmt->execute([$tenantId, $msisdn]);
    $conversation = $stmt->fetch();
    if ($conversation) {
        return $conversation;
    }

    $pdo->prepare(
        'INSERT INTO wa_conversations (tenant_id, msisdn, contact_id, display_name, status)
         VALUES (?, ?, ?, ?, "open")'
    )->execute([
        $tenantId,
        $msisdn,
        $recipient['contact_id'] ?? null,
        $recipient['contact_name'] ?? null,
    ]);

    $id = (int) $pdo->lastInsertId();

    // Filed against the recipient row so the conversation view can find it later.
    $pdo->prepare('UPDATE wa_campaign_recipients SET conversation_id = ? WHERE id = ?')
        ->execute([$id, (int) $recipient['id']]);

    $stmt = $pdo->prepare('SELECT * FROM wa_conversations WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: ['id' => $id, 'msisdn' => $msisdn, 'window_expires_at' => null];
}
