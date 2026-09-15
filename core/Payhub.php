<?php
declare(strict_types=1);

/**
 * The PayHub gateway, in one place.
 *
 * Two reasons this exists, both found by reading the code rather than by a bug report:
 *
 * 1. **The same integration was written out three times** in `core/routes.php` — the advert checkout, the
 *    giving checkout, and the callback — with three different assumptions. They disagreed about which
 *    settings key holds the credential (`payhub_api_key`, which does not exist in the `settings` table, vs
 *    `payhub_public_key`), about the endpoint path, and about the **unit of the amount**. One of them
 *    therefore never reached the gateway at all, and another under-charged by a hundredfold.
 *
 * 2. **A payment must never be assumed.** The callback used to set `$paid = true` when it could not reach
 *    the gateway for any reason — no secret key, no curl, a timeout — so `?ref=…` on a callback URL marked
 *    a donation completed without any money moving. Every "we could not check" branch here returns
 *    *not paid*, and says why.
 *
 * The documented facts this file encodes, from `https://merchant.payhub.com.ng/api-reference.php`:
 *
 *  - **Amounts are in kobo.** `500000` is ₦5,000. `amountInKobo()` is the only place that conversion
 *    happens, so it cannot be right in one flow and wrong in the next.
 *  - **The public key goes to the browser and the secret key never does.** Card data goes to PayHub's
 *    iframe, not to us, which is what keeps this application out of PCI scope. The secret key is used for
 *    server-to-server calls only.
 *  - **`/api/transaction/verify/:reference` is the authoritative answer**, and the docs are emphatic that a
 *    top-level `status: true` means only "transaction retrieved". The outcome is the `paid` boolean or
 *    `data.status` (`success` / `pending` / `failed`).
 *  - **Webhooks are signed** as `X-Payhub-Signature = HMAC-SHA256(raw body, secret)`.
 *
 * `setTransport()` exists for the same reason `Sms::setTransport()` does: without a PayHub account no
 * transaction can be taken, so the only way to test the branches is to replace the HTTP call. It is a test
 * seam and nothing in the application calls it.
 */
final class Payhub
{
    /** Everything the API documents lives under this. */
    private const BASE = 'https://merchant.payhub.com.ng';

    /** The inline checkout script, for `<script src="…">` on the advertiser's own page. */
    public const INLINE_SCRIPT = self::BASE . '/inline.js';

    /**
     * The origin the browser has to be allowed to load script and make requests from.
     *
     * Derived from `BASE` rather than written out a second time, because the Content-Security-Policy in
     * `bootstrap.php` and the `<script src>` in the checkout page have to name the same host. Two literals
     * that must match are two literals that will eventually not.
     */
    public static function scriptOrigin(): string
    {
        return self::BASE;
    }

    /** @var callable|null test seam — see the class docblock */
    private static $transport = null;

    /** Replaces the HTTP call. Pass null to go back to curl. */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /** True when the church has switched the gateway on. */
    public static function enabled(): bool
    {
        return !empty(setting('payhub_enabled'));
    }

    /**
     * **The only thing a screen should ask before offering online payment.**
     *
     * Deliberately requires the secret key as well as the switch: without it the server cannot verify a
     * payment afterwards, so offering the button would be offering something that cannot be completed.
     */
    public static function configured(): bool
    {
        return self::enabled() && self::secretKey() !== '';
    }

    /** True when the browser-side inline checkout can also run, which needs the public key. */
    public static function inlineReady(): bool
    {
        return self::configured() && self::publicKey() !== '';
    }

    public static function publicKey(): string
    {
        return trim((string) setting('payhub_public_key'));
    }

    public static function secretKey(): string
    {
        return trim((string) setting('payhub_secret_key'));
    }

    /**
     * True when configured API keys are Test Mode keys (e.g. pk_test_*, sk_test_*).
     */
    public static function isTestMode(): bool
    {
        $secret = self::secretKey();
        $public = self::publicKey();
        return str_starts_with($secret, 'sk_test_')
            || str_starts_with($public, 'pk_test_')
            || str_contains(strtolower($secret), 'test')
            || str_contains(strtolower($public), 'test');
    }

    /**
     * Naira to kobo — for the INLINE window only.
     *
     * The gateway's own inline example is `amount: document.getElementById("amount").value * 100`, and
     * `PayhubPop.setup()` takes that kobo figure. This is the conversion the checkout page puts in
     * `data-payhub`.
     */
    public static function amountInKobo($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Naira, unchanged — for the HOSTED checkout only.
     *
     * The API reference says "Amount is in kobo" for `Initialize Transaction`, and then shows its own
     * example: `authorization_url: https://merchant.payhub.com.ng/checkout.php?ref=PH_abc&amount=500000`.
     * A real transaction proves which of those is true: an advert priced at **₦9,000** was sent as 900000
     * kobo, and the gateway's checkout page **displayed ₦900,000** — a hundred times the price the
     * advertiser had chosen and seen on our own page.
     *
     * So the two paths disagree, and the money decides it. Inline takes kobo (its own documentation says
     * so, and it multiplies by 100 itself). The hosted checkout takes the naira figure. Sending kobo to the
     * hosted page charges a hundred times what the advertiser agreed to; sending naira to the inline window
     * would charge a hundredth. Hence two functions with names that cannot be confused, rather than one
     * helper used by both — which is exactly the mistake this replaces.
     *
     * ⚠️ The hosted figure was confirmed against a live test transaction on 2026-09-15. If PayHub later
     * corrects `checkout.php` to divide by 100, this must be re-checked with a small real payment; nothing
     * here can detect that from the outside, because their page echoes the number it was given either way.
     */
    public static function amountInNaira($amount): int
    {
        return (int) round((float) $amount);
    }

    /** A reference the gateway will accept and we can find again. Unique by construction. */
    public static function reference(string $prefix = 'PH'): string
    {
        return $prefix . '_' . strtoupper(bin2hex(random_bytes(8)));
    }

    /**
     * Starts a transaction server-side and returns the hosted checkout URL.
     *
     * Used by the flows that redirect, and by anything that wants a payment link to send somebody.
     *
     * @param  array<string, mixed> $payload email, amount (kobo), reference, callback_url, metadata…
     * @return array{ok:bool,authorization_url:string,reference:string,error:string,raw:array<string,mixed>}
     */
    public static function initialize(array $payload): array
    {
        $result = self::call('POST', '/api/transaction/initialize', $payload);
        $body = $result['body'];

        $url = (string) ($body['data']['authorization_url'] ?? ($body['checkout_url'] ?? ''));

        return [
            'ok' => $result['ok'] && $url !== '',
            'authorization_url' => $url,
            'reference' => (string) ($body['data']['reference'] ?? ($body['reference'] ?? ($payload['reference'] ?? ''))),
            'error' => $url !== '' ? '' : ($result['error'] !== '' ? $result['error'] : (string) ($body['message'] ?? 'The gateway did not return a checkout URL.')),
            'raw' => $body,
        ];
    }

    /**
     * Asks the gateway what actually happened to a reference.
     *
     * This is the authoritative source of truth for every payment decision in the application, and it is
     * always called **server-side**: a browser can post any reference it likes to a return URL, so the
     * return URL's own claim is worth nothing.
     *
     * @return array{ok:bool,paid:bool,status:string,reason:string,error:string,raw:array<string,mixed>}
     */
    public static function verify(string $reference): array
    {
        $reference = trim($reference);

        if ($reference === '') {
            return ['ok' => false, 'paid' => false, 'status' => '', 'reason' => '', 'error' => 'No reference to verify.', 'raw' => []];
        }

        if (self::secretKey() === '') {
            // Not "assume it worked". A verification that cannot be made is not a payment.
            return ['ok' => false, 'paid' => false, 'status' => '', 'reason' => '', 'error' => 'No PayHub secret key is configured, so the payment cannot be verified.', 'raw' => []];
        }

        $result = self::call('GET', '/api/transaction/verify/' . rawurlencode($reference));
        $body = $result['body'];

        if (!$result['ok']) {
            return ['ok' => false, 'paid' => false, 'status' => '', 'reason' => '', 'error' => $result['error'], 'raw' => []];
        }

        $status = strtolower((string) ($body['data']['status'] ?? ($body['data']['payment_status'] ?? '')));
        $paid = !empty($body['paid']) || !empty($body['data']['paid']) || $status === 'success';

        return [
            'ok' => true,
            'paid' => $paid,
            'status' => $status !== '' ? $status : ($paid ? 'success' : 'failed'),
            // What a person can be shown. `gateway_response` is the gateway's own words ("Insufficient
            // funds"), which is what an advertiser needs to see to know what to do next.
            'reason' => trim((string) ($body['data']['gateway_response'] ?? ($body['message'] ?? ''))),
            'error' => '',
            'raw' => $body,
        ];
    }

    /**
     * True when a webhook genuinely came from PayHub.
     *
     * The signature is over the **raw body**, so the caller must pass the body as received and not a
     * re-encoded array — JSON key order would change and the signature would never match.
     */
    public static function verifyWebhook(string $rawBody, string $signature): bool
    {
        $secret = self::secretKey();

        if ($secret === '') {
            return false;
        }
        if ($signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    /**
     * One HTTP call, through the seam if a test has replaced it.
     *
     * @param  array<string, mixed>|null $payload
     * @return array{ok:bool,body:array<string,mixed>,error:string}
     */
    private static function call(string $method, string $path, ?array $payload = null): array
    {
        if (self::$transport !== null) {
            /** @var array{ok:bool,body:array<string,mixed>,error:string} $result */
            $result = (self::$transport)($method, $path, $payload);

            return $result;
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'body' => [], 'error' => 'curl is not available on this server, so PayHub cannot be reached.'];
        }

        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . self::secretKey(),
            ],
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $curlError = (string) curl_error($ch);
        curl_close($ch);

        $body = json_decode((string) $response, true);

        if (!is_array($body)) {
            return [
                'ok' => false,
                'body' => [],
                'error' => $curlError !== '' ? $curlError : 'The gateway returned a response that is not JSON.',
            ];
        }

        return ['ok' => true, 'body' => $body, 'error' => ''];
    }
}
