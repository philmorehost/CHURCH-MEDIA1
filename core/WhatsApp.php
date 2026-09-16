<?php
declare(strict_types=1);

/**
 * WhatsApp, via Meta's Cloud API.
 *
 * Three things in here are load-bearing, and all three are the kind of thing that is invisible
 * until it goes wrong in front of a congregation.
 *
 * **1. The webhook signature is the only thing standing between this endpoint and the public
 * internet.** `/api/wa-webhook.php` has to be reachable anonymously for Meta to call it, so
 * anybody can POST to it. `verifySignature()` is what makes such a request provably from Meta:
 * an HMAC-SHA256 of the *raw* request body, keyed on the app secret, compared in constant time.
 * Every failure mode returns false — no secret configured, no header, malformed header, wrong
 * digest. It is written that way deliberately: a webhook endpoint that fails open is worse than
 * one that is switched off.
 *
 * **2. The 24-hour customer service window governs what may be sent.** Inside it, a free-form
 * reply is allowed. Outside it, the only thing Meta will accept is an approved template — and
 * trying to send free text anyway does not fail loudly, it is simply rejected, so a church would
 * discover the rule by having messages silently not arrive. `canSendFreeform()` answers the
 * question before a send is attempted, and the window expiry is stored rather than derived so a
 * later change to the rule cannot re-open conversations that had already closed.
 *
 * **3. Nothing is sent unless the channel is switched on and configured.** `wa_enabled` defaults
 * to 0. Talking to a congregation from a number that is not verified or not yet approved is
 * worse than not talking to them.
 *
 * The audience layer is deliberately *not* reimplemented here: contacts, groups and segments all
 * come from Phase 2, and a WhatsApp number is normalised through `Sms::normaliseMsisdn()` so the
 * same person is one row whichever channel reaches them.
 */
final class WhatsApp
{
    /** Graph API version. Bump deliberately — Meta deprecates versions on a schedule. */
    private const GRAPH_VERSION = 'v21.0';
    private const GRAPH_BASE = 'https://graph.facebook.com/';

    /** The customer service window, in hours. Meta's rule, not ours. */
    public const WINDOW_HOURS = 24;

    /** Longest message body Meta will accept for a text message. */
    public const MAX_TEXT_LENGTH = 4096;

    /**
     * @var callable|null
     *
     * Test seam, exactly as in Sms: setting a callable replaces the HTTP request so the send
     * path, the error mapping and the window rules can all be driven offline. Never set outside
     * a test.
     */
    private static $transport = null;

    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /* --------------------------------------------------------------- settings */

    /** Whether the channel is switched on at all. Off unless an admin turns it on. */
    public static function enabled(): bool
    {
        return (bool) setting('wa_enabled', 0);
    }

    public static function phoneNumberId(): string
    {
        return trim((string) setting('wa_phone_number_id', ''));
    }

    public static function businessAccountId(): string
    {
        return trim((string) setting('wa_business_account_id', ''));
    }

    /** The decrypted access token, or '' when none is stored. */
    public static function accessToken(): string
    {
        $stored = (string) setting('wa_access_token', '');
        if ($stored === '') {
            return '';
        }
        // A token that will not decrypt means the encryption key changed. Report it as missing
        // rather than sending ciphertext to Meta.
        return decryptSecret($stored) ?? '';
    }

    /** The app secret, used to verify webhook signatures. */
    public static function appSecret(): string
    {
        $stored = (string) setting('wa_app_secret', '');
        if ($stored === '') {
            return '';
        }
        return decryptSecret($stored) ?? '';
    }

    /** The token Meta echoes back during the webhook subscription handshake. */
    public static function verifyToken(): string
    {
        return trim((string) setting('wa_verify_token', ''));
    }

    public static function defaultLanguage(): string
    {
        $language = trim((string) setting('wa_default_language', 'en'));
        return $language !== '' ? $language : 'en';
    }

    public static function displayName(): string
    {
        return trim((string) setting('wa_display_name', ''));
    }

    /** Everything the send path needs. Missing any of it means nothing can be sent. */
    public static function configured(): bool
    {
        return self::phoneNumberId() !== '' && self::accessToken() !== '';
    }

    /** Why sending is unavailable, in words an admin can act on. Null when it is fine. */
    public static function problem(): ?string
    {
        if (!self::enabled()) {
            return 'WhatsApp is switched off. Turn it on in Settings once the number is live.';
        }
        if (self::phoneNumberId() === '') {
            return 'No WhatsApp phone number ID is set. Copy it from your Meta app dashboard.';
        }
        if (self::accessToken() === '') {
            return 'The WhatsApp access token is missing, or it could not be decrypted.';
        }
        if (self::appSecret() === '') {
            return 'No app secret is set, so incoming webhooks cannot be verified and will be rejected.';
        }
        if (self::verifyToken() === '') {
            return 'No webhook verify token is set. Meta needs one to confirm the webhook address.';
        }
        return null;
    }

    /** A stored token must never be rendered in full, anywhere. */
    public static function maskedToken(): string
    {
        $token = self::accessToken();
        if ($token === '') {
            return '';
        }
        if (strlen($token) <= 8) {
            return str_repeat('•', strlen($token));
        }
        return substr($token, 0, 4) . str_repeat('•', 12) . substr($token, -4);
    }

    public static function maskedAppSecret(): string
    {
        $secret = self::appSecret();
        if ($secret === '') {
            return '';
        }
        return substr($secret, 0, 3) . str_repeat('•', 10);
    }

    /* ------------------------------------------------------- signature & handshake */

    /**
     * The GET handshake Meta performs when a webhook address is registered.
     *
     * Returns the challenge to echo back, or null when the request should be refused. A missing
     * configured verify token returns null rather than accepting: an install that has not been
     * set up yet must not be subscribable by anybody who guesses the URL.
     *
     * @param array<string, mixed> $query
     */
    public static function verifyChallenge(array $query): ?string
    {
        $expected = self::verifyToken();
        if ($expected === '') {
            return null;
        }

        $mode = (string) ($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');

        if ($mode !== 'subscribe' || $challenge === '') {
            return null;
        }
        if (!hash_equals($expected, $token)) {
            return null;
        }

        return $challenge;
    }

    /**
     * Whether a webhook request really came from Meta.
     *
     * The digest is computed over the **raw request body**. Re-encoding the parsed form or JSON
     * changes the bytes and the signature then never matches — which is the usual way this check
     * gets broken, and it breaks by rejecting everything, so it is noticed. The reverse mistake,
     * verifying the parsed body, can be made to pass.
     *
     * Every uncertain case returns false. An unconfigured secret, a missing header, a header with
     * the wrong prefix, or a digest of the wrong length are all refusals.
     */
    public static function verifySignature(string $rawBody, ?string $header): bool
    {
        $secret = self::appSecret();
        if ($secret === '' || $header === null || $header === '') {
            return false;
        }

        $header = trim($header);
        if (!str_starts_with($header, 'sha256=')) {
            return false;
        }
        $provided = substr($header, 7);
        if ($provided === '' || preg_match('/^[0-9a-f]{64}$/i', $provided) !== 1) {
            return false;
        }

        // hash_equals, not ===, so the comparison does not leak how much of the digest matched.
        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($provided));
    }

    /* ------------------------------------------------------------- 24-hour window */

    /** When a window opened by an inbound message closes. Null when there was no inbound. */
    public static function windowExpiresAt(?string $lastInboundAt): ?string
    {
        if ($lastInboundAt === null || trim($lastInboundAt) === '') {
            return null;
        }
        $ts = strtotime($lastInboundAt);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts + (self::WINDOW_HOURS * 3600));
    }

    /** Whether free-form replies are still allowed. */
    public static function isWindowOpen(?string $windowExpiresAt, ?int $now = null): bool
    {
        if ($windowExpiresAt === null || trim($windowExpiresAt) === '') {
            return false;
        }
        $expires = strtotime($windowExpiresAt);
        if ($expires === false) {
            return false;
        }
        return ($now ?? time()) < $expires;
    }

    /** Whole hours left in the window, rounded down. 0 when it has closed. */
    public static function hoursRemaining(?string $windowExpiresAt, ?int $now = null): int
    {
        if (!self::isWindowOpen($windowExpiresAt, $now)) {
            return 0;
        }
        $seconds = strtotime((string) $windowExpiresAt) - ($now ?? time());
        return (int) max(0, floor($seconds / 3600));
    }

    /** A short phrase for the inbox, so an admin can see at a glance what they may send. */
    public static function windowLabel(?string $windowExpiresAt, ?int $now = null): string
    {
        if (!self::isWindowOpen($windowExpiresAt, $now)) {
            return 'Window closed — an approved template is required';
        }
        $hours = self::hoursRemaining($windowExpiresAt, $now);
        if ($hours >= 1) {
            return 'Window open for another ' . $hours . 'h — free replies allowed';
        }
        $minutes = (int) max(1, floor((strtotime((string) $windowExpiresAt) - ($now ?? time())) / 60));
        return 'Window closes in ' . $minutes . ' min — free replies allowed';
    }

    /**
     * Whether a free-form message may be sent to this conversation.
     *
     * This is the gate the composer asks before it offers a text box. Outside the window the
     * answer is no and the only remaining option is a template.
     */
    public static function canSendFreeform(?string $windowExpiresAt, ?int $now = null): bool
    {
        return self::isWindowOpen($windowExpiresAt, $now);
    }

    /* -------------------------------------------------------------------- sending */

    /**
     * Sends an approved template.
     *
     * @param  array<int, string> $bodyParams  Values for the template's {{1}}, {{2}} … placeholders
     * @return array{ok:bool,message_id:?string,error:?string,code:?string,http:?int,raw:array<string,mixed>}
     */
    public static function sendTemplate(string $msisdn, string $templateName, array $bodyParams = [], ?string $language = null): array
    {
        $name = trim($templateName);
        if ($name === '') {
            return self::failure('No template was chosen.');
        }

        $components = [];
        if ($bodyParams) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    static fn (string $value): array => ['type' => 'text', 'text' => $value],
                    array_values($bodyParams)
                ),
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $msisdn,
            'type' => 'template',
            'template' => [
                'name' => $name,
                'language' => ['code' => $language !== null && $language !== '' ? $language : self::defaultLanguage()],
            ],
        ];
        if ($components) {
            $payload['template']['components'] = $components;
        }

        return self::post('/messages', $payload, 'template:' . $name);
    }

    /**
     * Sends a free-form text message.
     *
     * Refuses when the window is closed, because Meta would refuse it too — the difference being
     * that a refusal here can explain itself instead of looking like a message that vanished.
     *
     * @param bool $windowOpen  The caller's answer from canSendFreeform(), passed in rather than
     *                          re-read so the decision and the send cannot disagree.
     */
    public static function sendText(string $msisdn, string $body, bool $windowOpen, ?string $replyToMessageId = null): array
    {
        if (!$windowOpen) {
            return self::failure(
                'The 24-hour reply window has closed, so only an approved template can be sent. '
                . 'A template is also the only way to start a new conversation.'
            );
        }

        $text = trim($body);
        if ($text === '') {
            return self::failure('The message is empty.');
        }
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return self::failure('That message is longer than WhatsApp allows (' . self::MAX_TEXT_LENGTH . ' characters).');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $msisdn,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ];
        // Replying into the thread keeps the context visible to the person reading it.
        if ($replyToMessageId !== null && $replyToMessageId !== '') {
            $payload['context'] = ['message_id' => $replyToMessageId];
        }

        return self::post('/messages', $payload, 'text');
    }

    /**
     * Sends media by Meta's media id.
     *
     * The Cloud API will not fetch an arbitrary URL for a message sent outside the window and is
     * unreliable about it inside, so a media id from a prior upload is the supported path.
     *
     * @param  string $kind  image, audio, video, document or sticker
     * @return array{ok:bool,message_id:?string,error:?string,code:?string,http:?int,raw:array<string,mixed>}
     */
    public static function sendMedia(string $msisdn, string $mediaId, string $kind = 'image', ?string $caption = null, bool $windowOpen = true): array
    {
        $allowed = ['image', 'audio', 'video', 'document', 'sticker'];
        if (!in_array($kind, $allowed, true)) {
            return self::failure('Unsupported media type: ' . $kind . '.');
        }
        if (trim($mediaId) === '') {
            return self::failure('No media was provided.');
        }
        if (!$windowOpen) {
            return self::failure('The 24-hour reply window has closed, so only an approved template can be sent.');
        }

        $media = ['id' => $mediaId];
        // Captions do not exist for audio or stickers, and sending one is an error.
        if ($caption !== null && trim($caption) !== '' && in_array($kind, ['image', 'video', 'document'], true)) {
            $media['caption'] = $caption;
        }

        return self::post('/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $msisdn,
            'type' => $kind,
            $kind => $media,
        ], 'media:' . $kind);
    }

    /**
     * Marks a message as read, which also clears the unread badge on the person's phone.
     *
     * Meta bills for conversations rather than messages, so acknowledging a read costs nothing —
     * and leaving a message unread when it has been dealt with is misleading to the sender.
     */
    public static function markRead(string $waMessageId): array
    {
        if (trim($waMessageId) === '') {
            return self::failure('No message to acknowledge.');
        }
        return self::post('/messages', [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $waMessageId,
        ], 'read');
    }

    /**
     * The one place an outbound request is made.
     *
     * Mirrors Sms::call(): one function does the transport, the error mapping and the audit
     * record, so there is a single place to look when something does not arrive.
     */
    private static function post(string $path, array $payload, string $summary): array
    {
        if (!self::enabled()) {
            return self::failure('WhatsApp is switched off.');
        }
        $phoneId = self::phoneNumberId();
        $token = self::accessToken();
        if ($phoneId === '' || $token === '') {
            return self::failure('WhatsApp is not fully configured yet.');
        }

        $url = self::GRAPH_BASE . self::GRAPH_VERSION . '/' . rawurlencode($phoneId) . $path;
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return self::failure('That message could not be encoded.');
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        if (self::$transport !== null) {
            // Test seam: no HTTP request and no cost. See setTransport().
            $response = (self::$transport)('POST', $url, $headers, $json);
            $body = (string) ($response['body'] ?? '');
            $httpStatus = (int) ($response['http'] ?? 200);
            $curlError = (string) ($response['error'] ?? '');
        } else {
            if (!function_exists('curl_init')) {
                return self::failure('cURL is not available on this server.');
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            $body = $body === false ? '' : (string) $body;
        }

        if ($curlError !== '') {
            return self::failure('Could not reach WhatsApp: ' . $curlError, $httpStatus, $summary);
        }

        $decoded = json_decode($body, true);
        $raw = is_array($decoded) ? $decoded : [];

        // Meta reports failures in an `error` object with its own numeric code. Surface that code
        // rather than the HTTP status, because the status is usually 400 for everything.
        if (isset($raw['error']) && is_array($raw['error'])) {
            $code = isset($raw['error']['code']) ? (string) $raw['error']['code'] : null;
            $message = (string) ($raw['error']['message'] ?? 'WhatsApp rejected that request.');
            return [
                'ok' => false,
                'message_id' => null,
                'error' => $message,
                'code' => $code,
                'http' => $httpStatus,
                'raw' => $raw,
            ];
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            return self::failure('WhatsApp returned HTTP ' . $httpStatus . '.', $httpStatus, $summary);
        }

        $messageId = (string) ($raw['messages'][0]['id'] ?? '');

        return [
            'ok' => true,
            'message_id' => $messageId !== '' ? $messageId : null,
            'error' => null,
            'code' => null,
            'http' => $httpStatus,
            'raw' => $raw,
        ];
    }

    /** @return array{ok:bool,message_id:?string,error:?string,code:?string,http:?int,raw:array<string,mixed>} */
    private static function failure(string $message, ?int $httpStatus = null, string $summary = ''): array
    {
        return [
            'ok' => false,
            'message_id' => null,
            'error' => $message,
            'code' => null,
            'http' => $httpStatus,
            'raw' => [],
        ];
    }

    /* ----------------------------------------------------------- phone numbers */

    /**
     * Normalises a number the way Meta sends it.
     *
     * Meta hands over `wa_id` as international digits with no plus (`2348012345678`). That is
     * already the form `sms_contacts` stores, so it is passed through when it looks like a full
     * international number and only otherwise handed to the Phase 2 normaliser. Doing it the
     * other way round — always re-normalising — risks stripping a real leading digit off an
     * already-correct number.
     */
    public static function normalise(string $raw, ?string $country = null): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        // A bare international number: try the country table before touching it.
        foreach (Sms::countries() as $dialCode => $rules) {
            if (str_starts_with($digits, (string) $dialCode)) {
                $national = substr($digits, strlen((string) $dialCode));
                $expected = (int) ($rules['national_length'] ?? 0);
                if ($expected > 0 && strlen($national) === $expected) {
                    return (string) $dialCode . $national;
                }
            }
        }

        return Sms::normaliseMsisdn($digits, $country);
    }

    /* --------------------------------------------------------------- replies */

    /**
     * Replies to a conversation with free text, recording it either way.
     *
     * The window is checked here rather than by the caller so that no screen can forget to. A
     * screen that offered a text box outside the window would produce a message Meta refuses and
     * an admin who cannot tell whether it went.
     *
     * @param  array<string, mixed> $conversation  A wa_conversations row
     * @return array{ok:bool,error:?string,message_id:?string,code:?string,http:?int,raw:array<string,mixed>}
     */
    public static function replyToConversation(array $conversation, string $body, ?int $userId = null, ?int $now = null): array
    {
        $msisdn = (string) ($conversation['msisdn'] ?? '');
        if ($msisdn === '') {
            return self::failure('That conversation has no number attached.');
        }

        $windowOpen = self::isWindowOpen(
            isset($conversation['window_expires_at']) ? (string) $conversation['window_expires_at'] : null,
            $now
        );

        return self::dispatch(
            (int) ($conversation['id'] ?? 0),
            fn (): array => self::sendText($msisdn, $body, $windowOpen),
            'text',
            trim($body),
            null,
            $userId
        );
    }

    /**
     * Sends an approved template into a conversation, recording it either way.
     *
     * This is the only thing that works once the window has closed, and the only thing that can
     * start a conversation with somebody who has not messaged first.
     *
     * @param  array<int, string>   $params
     * @param  array<string, mixed> $conversation
     * @return array{ok:bool,error:?string,message_id:?string,code:?string,http:?int,raw:array<string,mixed>}
     */
    public static function sendTemplateToConversation(array $conversation, string $templateName, array $params = [], ?int $userId = null): array
    {
        $msisdn = (string) ($conversation['msisdn'] ?? '');
        if ($msisdn === '') {
            return self::failure('That conversation has no number attached.');
        }

        return self::dispatch(
            (int) ($conversation['id'] ?? 0),
            fn (): array => self::sendTemplate($msisdn, $templateName, $params),
            'template',
            self::renderTemplatePreview($templateName, $params),
            $templateName,
            $userId
        );
    }

    /**
     * Runs a send and files the result, whatever it was.
     *
     * A refused send is recorded too, marked failed. Not recording it would leave an admin
     * looking at a conversation with no sign they had tried, and the reason would exist only in
     * a log they cannot see.
     *
     * @param  callable():array{ok:bool,message_id:?string,error:?string,code:?string,http:?int,raw:array<string,mixed>} $send
     * @return array{ok:bool,error:?string,message_id:?string,code:?string,http:?int,raw:array<string,mixed>}
     */
    private static function dispatch(
        int $conversationId,
        callable $send,
        string $type,
        ?string $body,
        ?string $templateName,
        ?int $userId
    ): array {
        $result = $send();

        if ($conversationId <= 0) {
            return $result;
        }

        try {
            $pdo = Database::getInstance()->getConnection();

            $pdo->prepare(
                'INSERT INTO wa_messages
                    (tenant_id, conversation_id, direction, type, template_name, body, wa_message_id, status, error_code, error_note, sent_by)
                 VALUES (?, ?, "out", ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                self::tenantId(),
                $conversationId,
                mb_substr($type, 0, 24),
                $templateName,
                $body,
                $result['message_id'] ?? null,
                !empty($result['ok']) ? 'sent' : 'failed',
                $result['code'] ?? null,
                isset($result['error']) && $result['error'] !== null ? mb_substr((string) $result['error'], 0, 255) : null,
                $userId,
            ]);

            if (!empty($result['ok'])) {
                $pdo->prepare('UPDATE wa_conversations SET last_outbound_at = NOW() WHERE id = ?')
                    ->execute([$conversationId]);
            }
        } catch (Throwable $e) {
            // The send already happened. Losing the local record is bad, but throwing here would
            // be worse: the admin would be told it failed when the person may already have it.
            error_log('WhatsApp outbound record failed: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * A best-effort rendering of what a template will look like, for the conversation view.
     *
     * This is our *local* copy of the body, which may have drifted from what Meta has approved.
     * It is for reading, never for deciding whether a template may be sent.
     *
     * @param array<int, string> $params
     */
    public static function renderTemplatePreview(string $templateName, array $params): ?string
    {
        try {
            $stmt = Database::getInstance()->getConnection()
                ->prepare('SELECT body_text FROM wa_templates WHERE tenant_id <=> ? AND name = ? ORDER BY id LIMIT 1');
            $stmt->execute([self::tenantId(), $templateName]);
            $body = (string) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }

        if ($body === '') {
            return null;
        }

        // {{1}}, {{2}} … are Meta's positional placeholders.
        foreach (array_values($params) as $index => $value) {
            $body = str_replace('{{' . ($index + 1) . '}}', (string) $value, $body);
        }

        return $body;
    }

    private static function tenantId(): ?int
    {
        return class_exists('Tenant') ? Tenant::id() : null;
    }
}
