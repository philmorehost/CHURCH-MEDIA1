<?php
declare(strict_types=1);

/**
 * GET  /api/wa-webhook — the subscription handshake Meta performs when the URL is registered.
 * POST /api/wa-webhook — inbound messages and delivery statuses.
 *
 * This endpoint has to be reachable by anyone, because Meta's servers are the caller. The
 * signature check is therefore the entire security boundary: without it, anyone who learns the
 * URL can inject messages into the church's inbox and mark conversations as read.
 *
 * The raw request body is read **once** into `$raw` and used for both the signature check and
 * the JSON parse. Reading `php://input` twice is not reliable, and re-encoding a decoded payload
 * would change the bytes the signature was computed over.
 *
 * Meta retries a delivery that does not get a 2xx, for up to several hours. Retries are safe
 * because `wa_messages.wa_message_id` is unique and a delivery already seen is skipped rather
 * than filed twice.
 */

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/* ---------------------------------------------------------------- handshake */

if ($method === 'GET') {
    $challenge = WhatsApp::verifyChallenge($_GET);

    if ($challenge === null) {
        // Deliberately the same answer for a wrong token and for an install that has no verify
        // token configured: whether this church has been set up is not a stranger's business.
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo $challenge;
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

/* ----------------------------------------------------------------- signature */

$raw = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;

if (!WhatsApp::verifySignature($raw, is_string($signature) ? $signature : null)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    // No detail about why. A caller who is not Meta does not need to know whether the secret is
    // missing, the header is absent, or the digest was wrong.
    echo json_encode(['status' => 'error', 'message' => 'Invalid signature.']);
    exit;
}

/* -------------------------------------------------------------------- parsing */

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    // Signed but unreadable. 400 rather than 500: retrying will not help.
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Malformed payload.']);
    exit;
}

// Meta sends other products down the same URL when one app is subscribed to several.
if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ignored', 'message' => 'Not a WhatsApp payload.']);
    exit;
}

$pdo = Database::getInstance()->getConnection();
$tenantId = class_exists('Tenant') ? Tenant::id() : null;

/** Finds or creates the conversation for a number, and files it against a church. */
$conversationFor = static function (string $msisdn, ?string $displayName, ?string $phoneNumberId) use ($pdo, $tenantId): int {
    $find = $pdo->prepare('SELECT id FROM wa_conversations WHERE tenant_id <=> ? AND msisdn = ? LIMIT 1');
    $find->execute([$tenantId, $msisdn]);
    $id = (int) $find->fetchColumn();
    if ($id > 0) {
        if ($displayName !== null && $displayName !== '') {
            $pdo->prepare('UPDATE wa_conversations SET display_name = ? WHERE id = ? AND (display_name IS NULL OR display_name = ?)')
                ->execute([$displayName, $id, '']);
        }
        return $id;
    }

    // An inbound number belongs to whoever the number is, not to head office. The unit is
    // resolved through the contact record when one exists, so a church sees its own people.
    $unitId = null;
    $contactId = null;
    $contact = $pdo->prepare('SELECT id, org_unit_id FROM sms_contacts WHERE tenant_id <=> ? AND msisdn = ? LIMIT 1');
    $contact->execute([$tenantId, $msisdn]);
    $row = $contact->fetch();
    if ($row) {
        $contactId = (int) $row['id'];
        $unitId = $row['org_unit_id'] !== null ? (int) $row['org_unit_id'] : null;
    }

    $pdo->prepare('INSERT INTO wa_conversations (tenant_id, msisdn, contact_id, display_name, org_unit_id, status) VALUES (?, ?, ?, ?, ?, "open")')
        ->execute([$tenantId, $msisdn, $contactId, $displayName !== null && $displayName !== '' ? $displayName : null, $unitId]);

    return (int) $pdo->lastInsertId();
};

/** Records consent. A person messaging us first is the strongest opt-in there is. */
$recordOptIn = static function (string $msisdn, string $source) use ($pdo, $tenantId): void {
    $pdo->prepare(
        'INSERT INTO wa_opt_ins (tenant_id, msisdn, is_opted_in, opted_in_at, source)
         VALUES (?, ?, 1, NOW(), ?)
         ON DUPLICATE KEY UPDATE is_opted_in = 1, opted_in_at = COALESCE(opted_in_at, NOW()), opted_out_at = NULL'
    )->execute([$tenantId, $msisdn, $source]);
};

$received = 0;
$statuses = 0;
$skipped = 0;

/* ------------------------------------------------------------------ processing */

foreach (($payload['entry'] ?? []) as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    foreach (($entry['changes'] ?? []) as $change) {
        if (!is_array($change) || ($change['field'] ?? '') !== 'messages') {
            continue;
        }

        $value = is_array($change['value'] ?? null) ? $change['value'] : [];
        $phoneNumberId = isset($value['metadata']['phone_number_id']) ? (string) $value['metadata']['phone_number_id'] : null;

        // WhatsApp profile names, which are the only display name Meta gives us.
        $nameByNumber = [];
        foreach (($value['contacts'] ?? []) as $contact) {
            if (is_array($contact) && isset($contact['wa_id'])) {
                $nameByNumber[(string) $contact['wa_id']] = isset($contact['profile']['name'])
                    ? (string) $contact['profile']['name']
                    : null;
            }
        }

        /* ---------------------------------------------------------- inbound */

        foreach (($value['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }

            $msisdn = WhatsApp::normalise((string) ($message['from'] ?? ''));
            if ($msisdn === null) {
                $skipped++;
                continue;
            }

            $waMessageId = trim((string) ($message['id'] ?? ''));

            // Idempotency: Meta retries, and a retry must not appear as a second message.
            if ($waMessageId !== '') {
                $seen = $pdo->prepare('SELECT COUNT(*) FROM wa_messages WHERE wa_message_id = ?');
                $seen->execute([$waMessageId]);
                if ((int) $seen->fetchColumn() > 0) {
                    $skipped++;
                    continue;
                }
            }

            $conversationId = $conversationFor(
                $msisdn,
                $nameByNumber[(string) ($message['from'] ?? '')] ?? null,
                $phoneNumberId
            );

            $type = (string) ($message['type'] ?? 'unknown');
            $body = waExtractBody($message, $type);

            // An inbound message opens the 24-hour window. The expiry is stored rather than
            // derived so that a later change to the rule cannot re-open closed conversations.
            $sentAt = isset($message['timestamp']) && is_numeric($message['timestamp'])
                ? date('Y-m-d H:i:s', (int) $message['timestamp'])
                : date('Y-m-d H:i:s');

            $pdo->prepare(
                'UPDATE wa_conversations
                 SET last_inbound_at = ?, window_expires_at = ?, unread_count = unread_count + 1, status = "open"
                 WHERE id = ?'
            )->execute([$sentAt, WhatsApp::windowExpiresAt($sentAt), $conversationId]);

            try {
                $pdo->prepare(
                    'INSERT INTO wa_messages
                        (tenant_id, conversation_id, direction, type, body, media_id, media_mime, wa_message_id, status, payload, created_at)
                     VALUES (?, ?, "in", ?, ?, ?, ?, ?, "received", ?, ?)'
                )->execute([
                    $tenantId,
                    $conversationId,
                    mb_substr($type, 0, 24),
                    $body,
                    isset($message['image']['id']) ? (string) $message['image']['id'] : (isset($message[$type]['id']) ? (string) $message[$type]['id'] : null),
                    isset($message[$type]['mime_type']) ? (string) $message[$type]['mime_type'] : null,
                    $waMessageId !== '' ? $waMessageId : null,
                    json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $sentAt,
                ]);
            } catch (Throwable $e) {
                // Almost always the unique key, i.e. the same delivery raced twice. The message
                // is already filed, so carrying on is correct.
                error_log('WhatsApp inbound insert failed: ' . $e->getMessage());
                $skipped++;
                continue;
            }

            $recordOptIn($msisdn, 'inbound');
            $received++;
        }

        /* -------------------------------------------------------- statuses */

        foreach (($value['statuses'] ?? []) as $status) {
            if (!is_array($status) || !isset($status['id'])) {
                continue;
            }

            $id = (string) $status['id'];
            $state = (string) ($status['status'] ?? '');
            if (!in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
                continue;
            }

            $errorCode = null;
            $errorNote = null;
            if ($state === 'failed' && isset($status['errors'][0]) && is_array($status['errors'][0])) {
                $errorCode = isset($status['errors'][0]['code']) ? (string) $status['errors'][0]['code'] : null;
                $errorNote = isset($status['errors'][0]['title'])
                    ? (string) $status['errors'][0]['title']
                    : (isset($status['errors'][0]['message']) ? (string) $status['errors'][0]['message'] : null);
            }

            // Statuses can arrive out of order, and "read" is not a downgrade from "delivered".
            // Ranking them stops a late "sent" from overwriting a "read".
            //
            // Both sides of the comparison go through FIELD() rather than a rank array held in
            // PHP: FIELD is 1-based, so a 0-based array silently drops every promotion by one
            // step — which is exactly how "sent" → "delivered" disappears without an error.
            $stmt = $pdo->prepare(
                "UPDATE wa_messages
                 SET status = ?, status_at = NOW(), error_code = COALESCE(?, error_code), error_note = COALESCE(?, error_note)
                 WHERE wa_message_id = ?
                   AND (
                       status = 'failed'
                       OR ? = 'failed'
                       OR FIELD(status, 'received', 'queued', 'sent', 'delivered', 'read')
                          < FIELD(?, 'received', 'queued', 'sent', 'delivered', 'read')
                   )"
            );
            $stmt->execute([$state, $errorCode, $errorNote, $id, $state, $state]);

            if ($stmt->rowCount() > 0) {
                $statuses++;
            }

            // Called unconditionally, and deliberately outside the check above. The message row
            // and the campaign recipient row are different tables, and the status is worth
            // applying to whichever exists: a campaign message whose message row was never
            // written (or was already at this status) still needs its recipient updated, or a
            // broadcast report would show every message as "sent" forever.
            try {
                WaCampaign::applyStatus($id, $state, $errorCode, $errorNote);
            } catch (Throwable $e) {
                error_log('WhatsApp campaign status update failed: ' . $e->getMessage());
            }
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'ok',
    'received' => $received,
    'statuses' => $statuses,
    'skipped' => $skipped,
]);

/**
 * The readable text of an inbound message, whatever shape it arrived in.
 *
 * Meta nests the text differently per type, and a reply to a button or a list is a common way
 * for someone to answer an invitation — so those are unwrapped too rather than stored as an
 * empty body that reads like a broken message.
 *
 * @param array<string, mixed> $message
 */
function waExtractBody(array $message, string $type): ?string
{
    return match ($type) {
        'text' => isset($message['text']['body']) ? (string) $message['text']['body'] : null,
        'button' => isset($message['button']['text']) ? (string) $message['button']['text'] : null,
        'interactive' => (string) (
            $message['interactive']['button_reply']['title']
            ?? $message['interactive']['list_reply']['title']
            ?? ''
        ) ?: null,
        'image', 'video', 'document' => isset($message[$type]['caption'])
            ? (string) $message[$type]['caption']
            : null,
        'location' => trim(
            (string) ($message['location']['name'] ?? '')
            . ' ' . (string) ($message['location']['address'] ?? '')
        ) ?: null,
        'reaction' => isset($message['reaction']['emoji']) ? (string) $message['reaction']['emoji'] : null,
        // Audio, stickers and anything unrecognised carry no text at all.
        default => null,
    };
}
