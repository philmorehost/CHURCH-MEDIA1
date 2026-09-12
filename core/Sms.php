<?php
declare(strict_types=1);

/**
 * The PhilmoreSMS gateway.
 *
 * Rules of the road:
 *  - **This class never throws.** Every method returns `['ok' => bool, 'error' => string]`
 *    so the worker, the admin screens and a cron run all handle failure the same way.
 *  - **The token never leaves this class except to the gateway.** It is stored
 *    encrypted, decrypted only to build a request, and never written to the log table,
 *    an error message, or a rendered page.
 *  - **Count money before spending it.** `segmentsFor()` knows that a single `₦` or
 *    emoji drops the whole message from GSM-7 to UCS-2 encoding, which cuts a segment
 *    from 160 characters to 70 — so a "160 character" message that contains one emoji
 *    costs three units, not one. Guessing here means a church overspends.
 *  - **Normalise, never mangle.** A number that does not match its country's rules is
 *    rejected with a reason, because silently "fixing" it sends a message to a stranger.
 */

final class Sms
{
    /** Gateway root. Every endpoint below hangs off this. */
    private const BASE = 'https://app.philmoresms.com/api/';

    /** Characters in the GSM-7 basic set, which a single SMS segment can hold. */
    private const GSM7_PER_SEGMENT = 160;
    private const GSM7_PER_MULTI_SEGMENT = 153;

    /** UCS-2 (anything outside GSM-7) holds far less per segment. */
    private const UCS2_PER_SEGMENT = 70;
    private const UCS2_PER_MULTI_SEGMENT = 67;

    /**
     * GSM-7 characters that count as two characters because they are escape-prefixed
     * in the encoding. Getting this wrong under-counts and under-charges.
     */
    private const GSM7_EXTENDED = ['^', '{', '}', '\\', '[', '~', ']', '|', '€'];

    /**
     * The GSM 03.38 basic character set. Anything outside it forces the *whole* message
     * into UCS-2, which holds less than half as much per segment.
     *
     * Note the escaped `\$`: an unescaped `$` followed by a multi-byte character is
     * parsed as a variable name, since bytes above 0x7F are valid in PHP identifiers.
     */
    private const GSM7_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\x1BÆæßÉ"
        . " !\"#¤%&'()*+,-./0123456789:;<=>?"
        . "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§"
        . "¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** Friendly text for the codes the gateway can return. */
    private const ERROR_MESSAGES = [
        '000' => 'Sent successfully.',
        '400' => 'The gateway rejected the request — check the sender ID and the recipient numbers.',
        '401' => 'The gateway rejected the API token. Check it in SMS → Settings.',
        '405' => 'The gateway rejected the request method. This is a bug, not a setting.',
        '107' => 'Insufficient wallet balance. Top up the SMS wallet and send again.',
        '110' => 'The message contains a word the gateway blocks. Reword it and try again.',
    ];

    /** A number of seconds to wait before each retry, for transient failures. */
    private const RETRY_DELAYS = [2, 5];

    private static ?array $countryCache = null;

    /* --------------------------------------------------------------- settings */

    /** The decrypted API token, or '' when none is stored. */
    public static function token(): string
    {
        $stored = (string) setting('sms_token', '');
        if ($stored === '') {
            return '';
        }
        $plain = decryptSecret($stored);
        // A token that will not decrypt means the encryption key changed. Report it as
        // missing rather than sending the ciphertext to the gateway.
        return $plain ?? '';
    }

    /** True when a token is stored and decrypts. */
    public static function configured(): bool
    {
        return self::token() !== '';
    }

    /** The default country, e.g. '234'. */
    public static function defaultCountry(): string
    {
        $code = preg_replace('/\D/', '', (string) setting('sms_default_country', '234')) ?? '';
        return $code !== '' ? $code : '234';
    }

    public static function defaultSenderId(): string
    {
        return strtoupper(trim((string) setting('sms_default_sender_id', '')));
    }

    /** How many recipients go into one gateway call. */
    public static function batchSize(): int
    {
        $size = (int) setting('sms_batch_size', 100);
        return $size > 0 ? min($size, 1000) : 100;
    }

    /* ------------------------------------------------------------- countries */

    /**
     * Country rules keyed by dial code, falling back to a small built-in set when the
     * lookup table is missing (pre-migration) so normalisation still works.
     *
     * @return array<string, array{dial_code:string,name:string,national_length:int,trunk_prefix:string}>
     */
    public static function countries(): array
    {
        if (self::$countryCache !== null) {
            return self::$countryCache;
        }

        $fallback = [
            '234' => ['dial_code' => '234', 'name' => 'Nigeria', 'national_length' => 10, 'trunk_prefix' => '0'],
            '233' => ['dial_code' => '233', 'name' => 'Ghana', 'national_length' => 9, 'trunk_prefix' => '0'],
            '254' => ['dial_code' => '254', 'name' => 'Kenya', 'national_length' => 9, 'trunk_prefix' => '0'],
            '27' => ['dial_code' => '27', 'name' => 'South Africa', 'national_length' => 9, 'trunk_prefix' => '0'],
            '1' => ['dial_code' => '1', 'name' => 'United States / Canada', 'national_length' => 10, 'trunk_prefix' => '1'],
            '44' => ['dial_code' => '44', 'name' => 'United Kingdom', 'national_length' => 10, 'trunk_prefix' => '0'],
        ];

        try {
            $rows = Database::getInstance()->getConnection()
                ->query('SELECT dial_code, name, national_length, trunk_prefix FROM sms_countries WHERE is_active = 1')
                ->fetchAll();
            if ($rows) {
                $out = [];
                foreach ($rows as $row) {
                    $out[(string) $row['dial_code']] = [
                        'dial_code' => (string) $row['dial_code'],
                        'name' => (string) $row['name'],
                        'national_length' => (int) $row['national_length'],
                        'trunk_prefix' => (string) $row['trunk_prefix'],
                    ];
                }
                return self::$countryCache = $out;
            }
        } catch (Throwable $e) {
            // Not migrated yet — the built-in set is enough to keep normalising.
        }

        return self::$countryCache = $fallback;
    }

    public static function forgetCountries(): void
    {
        self::$countryCache = null;
    }

    public static function countryName(string $dialCode): string
    {
        return self::countries()[$dialCode]['name'] ?? ('+' . $dialCode);
    }

    /* ---------------------------------------------------------- normalisation */

    /**
     * Turns whatever a human typed into the gateway's expected form: dial code plus
     * national number, digits only, no plus and no trunk zero.
     *
     * '08031234567', '+2348031234567', '2348031234567', '8031234567' and
     * '0803 123 4567' all become '2348031234567' for country 234. Returns null when
     * the number cannot be understood for that country, and the caller reports why —
     * guessing sends somebody else's message to a stranger.
     */
    public static function normaliseMsisdn(string $raw, ?string $country = null): ?string
    {
        $country = $country !== null && $country !== '' ? preg_replace('/\D/', '', $country) : self::defaultCountry();
        $country = $country !== '' ? $country : self::defaultCountry();

        // Keep a leading + only to remember that a dial code follows.
        $hadPlus = str_starts_with(trim($raw), '+');
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        // A number written with an explicit international prefix.
        if (!$hadPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hadPlus = true;
        }

        $countries = self::countries();
        $rules = $countries[$country] ?? null;

        // Already carries some dial code. Match the longest one first so 234 is not
        // mistaken for the start of a longer code.
        foreach (self::dialCodesByLength($countries) as $code) {
            if (!str_starts_with($digits, $code)) {
                continue;
            }
            $codeRules = $countries[$code];
            $rest = self::stripTrunk(substr($digits, strlen($code)), $codeRules['trunk_prefix']);

            // The number's own country rules win whenever the length fits.
            if (strlen($rest) === $codeRules['national_length']) {
                return $code . $rest;
            }

            // A leading + states the country explicitly, so a known dial code is
            // accepted even when it is not the default country — otherwise a church
            // with members abroad could not text them at all.
            if ($hadPlus && strlen($rest) >= 6 && strlen($rest) <= 12) {
                return $code . $rest;
            }

            // A bare local number that happens to begin with another country's dial
            // code is still local, so stop looking rather than mangling it.
            if ($code === $country) {
                break;
            }
        }

        if ($rules === null) {
            // Unknown country: accept only something already in international form.
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
        }

        // Local form: drop the trunk prefix, then require the country's national length.
        $national = self::stripTrunk($digits, $rules['trunk_prefix']);
        if (strlen($national) !== $rules['national_length']) {
            return null;
        }

        return $rules['dial_code'] . $national;
    }

    /** Explains why normaliseMsisdn() refused a number, for an import dry-run. */
    public static function whyInvalid(string $raw, ?string $country = null): string
    {
        $country = $country !== null && $country !== '' ? $country : self::defaultCountry();
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if ($digits === '') {
            return 'No digits found.';
        }
        $rules = self::countries()[$country] ?? null;
        if ($rules === null) {
            return 'Country ' . $country . ' is not in the country list.';
        }
        $national = self::stripTrunk($digits, $rules['trunk_prefix']);
        $expected = $rules['national_length'];
        return 'A ' . $rules['name'] . ' number needs ' . $expected . ' digits after +' . $rules['dial_code']
            . '; this has ' . strlen($national) . '.';
    }

    /**
     * Dial codes longest first, so 234 wins over 23 and 27 over 2.
     *
     * The values are cast back to strings because PHP silently turns numeric string
     * array keys into integers, and every caller compares them with str_starts_with().
     *
     * @return array<int, string>
     */
    private static function dialCodesByLength(array $countries): array
    {
        $codes = array_map('strval', array_keys($countries));
        usort($codes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return $codes;
    }

    private static function stripTrunk(string $digits, string $trunkPrefix): string
    {
        if ($trunkPrefix !== '' && str_starts_with($digits, $trunkPrefix)) {
            // Only strip when what remains still looks like a national number, so a
            // number whose national part genuinely starts with that digit is kept.
            return substr($digits, strlen($trunkPrefix));
        }
        return $digits;
    }

    /* ------------------------------------------------------------- segments */

    /**
     * How many SMS segments a message needs.
     *
     * GSM-7 packs 160 characters into one segment and 153 into each after that; if the
     * message contains anything outside that alphabet — a ₦, an emoji, a Chinese
     * character — the whole message switches to UCS-2, which holds only 70 then 67.
     * That difference is invisible on screen and very visible on the bill.
     */
    public static function segmentsFor(string $message): int
    {
        if ($message === '') {
            return 0;
        }

        if (self::isGsm7($message)) {
            // Characters in the extended set occupy two septets each.
            $length = 0;
            $chars = preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($chars as $char) {
                $length += in_array($char, self::GSM7_EXTENDED, true) ? 2 : 1;
            }
            if ($length <= self::GSM7_PER_SEGMENT) {
                return 1;
            }
            return (int) ceil($length / self::GSM7_PER_MULTI_SEGMENT);
        }

        // UCS-2 counts UTF-16 code units, so anything outside the basic multilingual
        // plane (most emoji) occupies two.
        $units = 0;
        $chars = preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $char) {
            $units += mb_ord($char) > 0xFFFF ? 2 : 1;
        }
        if ($units <= self::UCS2_PER_SEGMENT) {
            return 1;
        }
        return (int) ceil($units / self::UCS2_PER_MULTI_SEGMENT);
    }

    /** The encoding the gateway will actually use, for display in the composer. */
    public static function encodingFor(string $message): string
    {
        return $message === '' ? 'GSM-7' : (self::isGsm7($message) ? 'GSM-7' : 'UCS-2');
    }

    /** Every character must be in the GSM-7 alphabet, or the message is UCS-2. */
    private static function isGsm7(string $message): bool
    {
        $chars = preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $char) {
            if (!str_contains(self::GSM7_BASIC, $char) && !in_array($char, self::GSM7_EXTENDED, true)) {
                return false;
            }
        }
        return true;
    }

    /** Total units one message costs, whatever the recipient count. */
    public static function unitsFor(string $message): int
    {
        return self::segmentsFor($message);
    }

    /**
     * Units a campaign will cost: segments × valid, opted-in recipients.
     *
     * Invalid numbers and duplicates do not cost anything because they are never sent,
     * so they are excluded here rather than inflating the estimate.
     *
     * @param array<int, string> $msisdns
     */
    public static function estimateUnits(array $msisdns, string $message, ?string $country = null): int
    {
        $valid = self::prepareRecipients($msisdns, $country);
        return count($valid) * self::segmentsFor($message);
    }

    /* ----------------------------------------------------------- recipients */

    /**
     * Normalises and de-dupes a recipient list, dropping unusable numbers.
     *
     * The result is cast back to strings because PHP silently turns a numeric string
     * array key into an integer, and callers compare and store these as strings.
     *
     * @param  array<int, mixed> $raw
     * @return array<int, string>   Normalised, unique, in the order given.
     */
    public static function prepareRecipients(array $raw, ?string $country = null): array
    {
        $out = [];
        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $normalised = self::normaliseMsisdn((string) $value, $country);
            if ($normalised !== null && !isset($out[$normalised])) {
                $out[$normalised] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    /** Splits a number into country code and national part, for storage. */
    public static function splitCountry(string $msisdn): array
    {
        $countries = self::countries();
        foreach (self::dialCodesByLength($countries) as $code) {
            if (str_starts_with($msisdn, $code)) {
                return ['country' => $code, 'national' => substr($msisdn, strlen($code))];
            }
        }
        return ['country' => '', 'national' => $msisdn];
    }

    /** A readable form for the screen, e.g. +234 803 123 4567. */
    public static function prettyMsisdn(string $msisdn): string
    {
        $split = self::splitCountry($msisdn);
        if ($split['country'] === '') {
            return '+' . $msisdn;
        }
        $national = $split['national'];
        // Groups of 3 read best for the lengths these markets use.
        $groups = str_split($national, $national !== '' && strlen($national) % 3 !== 0 ? 3 : 3);
        return '+' . $split['country'] . ' ' . implode(' ', $groups);
    }

    /* ------------------------------------------------------------ gateway */

    /** Reads the wallet balance and records a snapshot for the spend history. */
    public static function balance(bool $record = true): array
    {
        $result = self::call('balance.php', []);
        $balance = null;

        if ($result['ok']) {
            // The gateway is inconsistent about the key, so accept the likely ones.
            foreach (['balance', 'wallet', 'amount', 'units'] as $key) {
                if (isset($result['raw'][$key]) && is_numeric($result['raw'][$key])) {
                    $balance = (float) $result['raw'][$key];
                    break;
                }
            }
            if ($balance === null && isset($result['raw']['data']) && is_numeric($result['raw']['data'])) {
                $balance = (float) $result['raw']['data'];
            }
        }

        $result['balance'] = $balance;

        if ($record) {
            try {
                Database::getInstance()->getConnection()
                    ->prepare('INSERT INTO sms_wallet_log (tenant_id, balance, error_code) VALUES (?, ?, ?)')
                    ->execute([self::tenantId(), $balance, $result['ok'] ? '000' : ($result['code'] ?? null)]);
            } catch (Throwable $e) {
                // A snapshot that cannot be recorded must not fail a balance check.
            }
        }

        return $result;
    }

    /**
     * Sends one message to a list of numbers, chunked into gateway-sized batches.
     *
     * Stops at the first hard failure (a bad token or an empty wallet will not fix
     * itself) but keeps going after a transient one, so one flaky batch does not
     * abandon the rest of a campaign.
     *
     * @param  array<int, string> $msisdns  Already normalised.
     * @return array{ok:bool,sent:int,failed:int,units:int,code:?string,error:?string,batches:array<int,array<string,mixed>>}
     */
    public static function send(array $msisdns, string $message, ?string $senderId = null, ?int $campaignId = null): array
    {
        $message = trim($message);
        if ($message === '') {
            return self::sendFailure('The message is empty.');
        }

        $recipients = self::prepareRecipients($msisdns);
        if ($recipients === []) {
            return self::sendFailure('There are no valid recipient numbers.');
        }

        $senderId = strtoupper(trim($senderId ?? self::defaultSenderId()));
        if ($senderId === '') {
            return self::sendFailure('No sender ID is set. Register one under SMS → Sender IDs.');
        }

        $unitsPerRecipient = self::segmentsFor($message);
        $summary = ['ok' => true, 'sent' => 0, 'failed' => 0, 'units' => 0, 'code' => null, 'error' => null, 'batches' => []];

        foreach (array_chunk($recipients, self::batchSize()) as $batch) {
            $attempt = 0;
            do {
                $result = self::call('sms.php', [
                    'senderID' => $senderId,
                    'recipients' => implode(',', $batch),
                    'message' => $message,
                ], $campaignId, count($batch));

                if ($result['ok']) {
                    $summary['sent'] += count($batch);
                    $summary['units'] += count($batch) * $unitsPerRecipient;
                    $summary['batches'][] = ['ok' => true, 'count' => count($batch), 'code' => $result['code']];
                    break;
                }

                // Only a transient failure is worth retrying. A rejected token, a blocked
                // word or an empty wallet will fail identically every time.
                $retryable = in_array((string) $result['code'], ['', '400'], true);
                if ($retryable && $attempt < count(self::RETRY_DELAYS)) {
                    sleep(self::RETRY_DELAYS[$attempt]);
                    $attempt++;
                    continue;
                }

                $summary['failed'] += count($batch);
                $summary['batches'][] = ['ok' => false, 'count' => count($batch), 'code' => $result['code'], 'error' => $result['error']];
                $summary['code'] = $result['code'];
                $summary['error'] = $result['error'];

                // A hard failure affects every remaining batch too, so stop rather than
                // burning through the rest of the list.
                if (!$retryable) {
                    $summary['ok'] = false;
                    $summary['remaining'] = 0;
                    return $summary;
                }
                break;
            } while (true);
        }

        if ($summary['failed'] > 0) {
            $summary['ok'] = false;
        }

        return $summary;
    }

    private static function sendFailure(string $error): array
    {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'units' => 0, 'code' => null, 'error' => $error, 'batches' => []];
    }

    /** Pushes a sender ID to the gateway for approval. */
    public static function registerSenderId(string $senderId, string $sampleMessage): array
    {
        $senderId = strtoupper(trim($senderId));
        $problem = self::senderIdProblem($senderId);
        if ($problem !== null) {
            return ['ok' => false, 'error' => $problem, 'code' => null];
        }
        if (trim($sampleMessage) === '') {
            return ['ok' => false, 'error' => 'A sample message is required so the gateway can validate the sender ID.', 'code' => null];
        }

        return self::call('senderID.php', [
            'senderID' => $senderId,
            'message' => trim($sampleMessage),
        ]);
    }

    /** Asks the gateway what it thinks of a sender ID. */
    public static function senderIdStatus(string $senderId): array
    {
        return self::call('check_senderID.php', ['senderID' => strtoupper(trim($senderId))]);
    }

    /**
     * The rule the gateway enforces and the one the roadmap confirmed: at most 11
     * characters, letters and digits only.
     *
     * Returns the reason it is unacceptable, or null when it is fine.
     */
    public static function senderIdProblem(string $senderId): ?string
    {
        $senderId = trim($senderId);
        if ($senderId === '') {
            return 'Enter a sender ID.';
        }
        if (mb_strlen($senderId) > 11) {
            return 'A sender ID may be at most 11 characters (' . mb_strlen($senderId) . ' given).';
        }
        if (preg_match('/^[A-Za-z0-9]+$/', $senderId) !== 1) {
            return 'A sender ID may contain letters and numbers only — no spaces, punctuation or symbols.';
        }
        return null;
    }

    /** Turns a gateway error code into something a church administrator can act on. */
    public static function errorMessage(?string $code): string
    {
        $code = (string) $code;
        if ($code === '') {
            return 'The gateway could not be reached. Check the connection and try again.';
        }
        return self::ERROR_MESSAGES[$code] ?? ('The gateway returned error code ' . $code . '.');
    }

    /** True when a code means the problem will not fix itself by retrying. */
    public static function isFatalCode(?string $code): bool
    {
        return in_array((string) $code, ['401', '405', '107', '110'], true);
    }

    /* ------------------------------------------------------------ transport */

    /**
     * One gateway call. Returns a normalised result and writes the attempt to the log.
     *
     * The token is added here and nowhere else, and the log row is built from an
     * allow-list of fields so a future parameter cannot leak into it by accident.
     *
     * @return array{ok:bool,code:?string,error:?string,raw:array<string,mixed>,http:?int}
     */
    private static function call(string $endpoint, array $params, ?int $campaignId = null, int $recipientCount = 0): array
    {
        $token = self::token();
        if ($token === '') {
            return ['ok' => false, 'code' => '401', 'error' => 'No API token is configured.', 'raw' => [], 'http' => null];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'code' => null, 'error' => 'cURL is not available on this server.', 'raw' => [], 'http' => null];
        }

        $payload = array_merge(['token' => $token], $params);
        $started = microtime(true);

        $ch = curl_init(self::BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $duration = (int) round((microtime(true) - $started) * 1000);

        if ($body === false) {
            self::log($endpoint, $payload, null, $curlError !== '' ? $curlError : 'No response from the gateway.', $httpStatus, $duration, $campaignId, $recipientCount);
            return ['ok' => false, 'code' => null, 'error' => $curlError !== '' ? $curlError : 'No response from the gateway.', 'raw' => [], 'http' => $httpStatus];
        }

        $decoded = json_decode((string) $body, true);
        $raw = is_array($decoded) ? $decoded : [];

        // The gateway answers with a JSON error_code; anything non-000 is a failure.
        $code = null;
        foreach (['error_code', 'errorCode', 'code', 'status'] as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $code = (string) $raw[$key];
                break;
            }
        }

        if ($code === null) {
            // No code at all: fall back to the HTTP status so a 500 is not read as success.
            $ok = $httpStatus >= 200 && $httpStatus < 300;
            $error = $ok ? null : ('The gateway returned HTTP ' . $httpStatus . '.');
            self::log($endpoint, $payload, null, $error, $httpStatus, $duration, $campaignId, $recipientCount);
            return ['ok' => $ok, 'code' => null, 'error' => $error, 'raw' => $raw, 'http' => $httpStatus];
        }

        // Treat leading-zero codes and the numeric 0 the same way.
        $normalisedCode = str_pad(ltrim($code, '0') === '' ? '0' : ltrim($code, '0'), 3, '0', STR_PAD_LEFT);
        $ok = $normalisedCode === '000' || $code === '0' || $code === '200';

        $error = $ok ? null : self::errorMessage($normalisedCode);
        self::log($endpoint, $payload, $normalisedCode, $error, $httpStatus, $duration, $campaignId, $recipientCount);

        return ['ok' => $ok, 'code' => $normalisedCode, 'error' => $error, 'raw' => $raw, 'http' => $httpStatus];
    }

    /**
     * Records one gateway attempt.
     *
     * `$payload` is reduced to an allow-list, so the token — which is in that array —
     * can never reach the log by accident.
     */
    private static function log(
        string $endpoint,
        array $payload,
        ?string $code,
        ?string $error,
        ?int $httpStatus,
        int $durationMs,
        ?int $campaignId,
        int $recipientCount
    ): void {
        try {
            $recipients = (string) ($payload['recipients'] ?? '');
            $sample = '';
            if ($recipients !== '') {
                $parts = explode(',', $recipients);
                // A handful of numbers is enough to diagnose; the full list is not the log's job.
                $sample = implode(',', array_slice($parts, 0, 3)) . (count($parts) > 3 ? '…' : '');
            }

            $summary = 'POST ' . $endpoint;
            if (isset($payload['senderID'])) {
                $summary .= ' senderID=' . substr((string) $payload['senderID'], 0, 11);
            }
            if (isset($payload['message'])) {
                $summary .= ' message=' . mb_strimwidth((string) $payload['message'], 0, 120, '…');
            }

            Database::getInstance()->getConnection()
                ->prepare('INSERT INTO sms_messages_log
                    (tenant_id, campaign_id, endpoint, recipient_count, recipient_sample, sender_id, request_summary, error_code, error_note, http_status, duration_ms)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    self::tenantId(),
                    $campaignId,
                    $endpoint,
                    $recipientCount,
                    $sample !== '' ? mb_substr($sample, 0, 255) : null,
                    isset($payload['senderID']) ? mb_substr((string) $payload['senderID'], 0, 11) : null,
                    mb_substr($summary, 0, 500),
                    $code,
                    $error !== null ? mb_substr($error, 0, 255) : null,
                    $httpStatus,
                    $durationMs,
                ]);
        } catch (Throwable $e) {
            // Logging must never be the reason a send fails.
        }
    }

    /** A stored token must never be rendered in full, anywhere. */
    public static function maskedToken(): string
    {
        $token = self::token();
        if ($token === '') {
            return '';
        }
        if (strlen($token) <= 8) {
            return str_repeat('•', strlen($token));
        }
        return substr($token, 0, 4) . str_repeat('•', 12) . substr($token, -4);
    }

    private static function tenantId(): ?int
    {
        return class_exists('Tenant') ? Tenant::id() : null;
    }
}
