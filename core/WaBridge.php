<?php
declare(strict_types=1);

/**
 * Client for the local WhatsApp bridge.
 *
 * The bridge is a small Node process that pairs a disposable number the way WhatsApp Web does.
 * It exists for exactly two jobs the official Cloud API refuses to do: list who is in a group,
 * and post into one. Everything else goes through WhatsApp:: and the Graph API as before.
 *
 * It is off unless wa_unofficial_enabled is set, and it stays off unless three things are true:
 * enabled, an address, and a token. Any of them missing means every call here fails with a
 * sentence an admin can act on rather than a blank screen.
 *
 * Only the reads are used by the admin today. Group posting is wired but deliberately not
 * exposed in the UI yet - see the guide tab.
 */
final class WaBridge
{
    /** Optional test seam: fn(string $method, string $path, ?array $body): array */
    private static $transport = null;

    private const CONNECT_TIMEOUT = 2;
    private const TIMEOUT = 15;

    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    public static function enabled(): bool
    {
        return WhatsApp::bridgeEnabled();
    }

    public static function tokenSet(): bool
    {
        return WhatsApp::bridgeToken() !== '';
    }

    /**
     * Why the bridge cannot be used, in words an admin can act on. Null when it is ready.
     *
     * Nothing here calls the network, so it is safe to run on every page render.
     */
    public static function problem(): ?string
    {
        if (!self::enabled()) {
            return 'The unofficial bridge is switched off. Turn it on under WhatsApp → Settings once a disposable number is ready.';
        }
        if (WhatsApp::bridgeToken() === '') {
            return 'No bridge token is set, so the bridge would reject every request. Set one under WhatsApp → Settings.';
        }

        return self::urlProblem();
    }

    /**
     * Refuses any address that is not this machine or a private network.
     *
     * This is the rule that keeps the token private. It travels in a plain HTTP header, which is
     * only acceptable because the socket never leaves the host. If the bridge were allowed to sit
     * on a public address, the token would be readable by anyone on the path - and the holder of
     * that token can send WhatsApp messages as the church.
     */
    public static function urlProblem(): ?string
    {
        $url = WhatsApp::bridgeUrl();
        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return 'The bridge address is not a URL. It should look like http://127.0.0.1:8787.';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'The bridge address must start with http:// or https://.';
        }

        if (!self::hostIsLocal((string) $parts['host'])) {
            return 'The bridge address must point at this machine or a private address (127.x, 10.x, 172.16-31.x, 192.168.x). '
                . 'A public address would send the bridge token over the open internet.';
        }

        return null;
    }

    private static function hostIsLocal(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || $host === '::1' || $host === '0:0:0:0:0:0:0:1') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            // A hostname other than "localhost". It could resolve anywhere, so it is refused; the
            // message tells the admin to use an address instead.
            return false;
        }

        if (strpos($host, '127.') === 0 || strpos($host, '10.') === 0 || strpos($host, '192.168.') === 0) {
            return true;
        }

        // 172.16.0.0 - 172.31.255.255, the rest of the private range (and where Docker lives).
        return (bool) preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host);
    }

    /**
     * Liveness and pairing state. Cheap enough to call on a page render, but it does hit the
     * network, so callers should not do it in a loop.
     *
     * @return array{ok:bool,error:?string,mode:?string,connected:bool,paired:bool,number:?string,data:array<string,mixed>}
     */
    public static function health(): array
    {
        $result = self::request('GET', '/health');

        if (!$result['ok']) {
            return [
                'ok' => false,
                'error' => $result['error'],
                'mode' => null,
                'connected' => false,
                'paired' => false,
                'number' => null,
                'data' => [],
            ];
        }

        $data = $result['data'];

        return [
            'ok' => true,
            'error' => null,
            'mode' => isset($data['mode']) ? (string) $data['mode'] : null,
            'connected' => !empty($data['connected']),
            'paired' => !empty($data['paired']),
            'number' => isset($data['number']) && $data['number'] !== null ? (string) $data['number'] : null,
            'data' => $data,
        ];
    }

    /**
     * @return array{ok:bool,error:?string,groups:array<int,array{jid:string,name:string,participants:int}>}
     */
    public static function groups(): array
    {
        $result = self::request('GET', '/groups');

        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'groups' => []];
        }

        $groups = [];
        foreach ((array) ($result['data']['groups'] ?? []) as $row) {
            if (!is_array($row) || empty($row['jid'])) {
                continue;
            }
            $groups[] = [
                'jid' => (string) $row['jid'],
                'name' => (string) ($row['name'] ?? ''),
                'participants' => (int) ($row['participants'] ?? 0),
            ];
        }

        return ['ok' => true, 'error' => null, 'groups' => $groups];
    }

    /**
     * @return array{ok:bool,error:?string,members:array<int,array{number:string,jid:string,admin:?string}>}
     */
    public static function members(string $jid): array
    {
        if (!self::isGroupJid($jid)) {
            return ['ok' => false, 'error' => 'That is not a group id.', 'members' => []];
        }

        $result = self::request('GET', '/groups/' . rawurlencode($jid) . '/members');

        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'members' => []];
        }

        $members = [];
        foreach ((array) ($result['data']['members'] ?? []) as $row) {
            if (!is_array($row) || empty($row['number'])) {
                continue;
            }
            $members[] = [
                'number' => (string) $row['number'],
                'jid' => (string) ($row['jid'] ?? ''),
                'admin' => isset($row['admin']) && $row['admin'] !== null ? (string) $row['admin'] : null,
            ];
        }

        return ['ok' => true, 'error' => null, 'members' => $members];
    }

    /**
     * Posts a message into a group.
     *
     * The result is shaped like WhatsApp::sendTemplate() so callers can treat both channels the
     * same way. This is the one method here that puts something in front of a human being, so it
     * is the one that should be reached for least.
     *
     * @return array{ok:bool,error:?string,message_id:?string,http:?int}
     */
    public static function sendGroup(string $jid, string $text): array
    {
        if (!self::isGroupJid($jid)) {
            return ['ok' => false, 'error' => 'That is not a group id.', 'message_id' => null, 'http' => null];
        }

        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => 'The message is empty.', 'message_id' => null, 'http' => null];
        }
        if (mb_strlen($text) > 4096) {
            return ['ok' => false, 'error' => 'The message is longer than WhatsApp allows (4096 characters).', 'message_id' => null, 'http' => null];
        }

        $result = self::request('POST', '/send-group', ['jid' => $jid, 'text' => $text]);

        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'message_id' => null, 'http' => $result['status'] > 0 ? $result['status'] : null];
        }

        return [
            'ok' => true,
            'error' => null,
            'message_id' => isset($result['data']['id']) ? (string) $result['data']['id'] : null,
            'http' => $result['status'],
        ];
    }

    public static function isGroupJid(string $jid): bool
    {
        return substr($jid, -5) === '@g.us';
    }

    /**
     * One HTTP call to the bridge.
     *
     * Failure is reported as a sentence, never a stack trace, because every caller is a screen an
     * admin is looking at and the most likely problem is "the Node process is not running".
     *
     * @return array{ok:bool,status:int,data:array<string,mixed>,error:?string}
     */
    private static function request(string $method, string $path, ?array $body = null): array
    {
        $problem = self::problem();
        if ($problem !== null) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $problem];
        }

        if (self::$transport !== null) {
            $handled = (self::$transport)($method, $path, $body);
            if (is_array($handled)) {
                return $handled;
            }
        }

        $url = WhatsApp::bridgeUrl() . $path;
        $token = WhatsApp::bridgeToken();

        $ch = curl_init($url);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            // The overwhelmingly common cause is that the bridge is simply not running. Say so,
            // rather than passing on "Failed to connect to 127.0.0.1 port 8787".
            error_log('WhatsApp bridge unreachable: ' . $curlError);
            return [
                'ok' => false,
                'status' => 0,
                'data' => [],
                'error' => 'The bridge is not responding at ' . WhatsApp::bridgeUrl() . '. Is the Node service running?',
            ];
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => [],
                'error' => 'The bridge sent a reply that was not JSON. Check its logs.',
            ];
        }

        if ($status >= 400) {
            return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => self::explain($status, $data)];
        }

        return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => null];
    }

    /** Turns a bridge failure into something worth reading. */
    private static function explain(int $status, array $data): string
    {
        $said = isset($data['error']) ? trim((string) $data['error']) : '';

        if ($status === 401) {
            return 'The bridge rejected the token. Copy it again into WhatsApp → Settings.';
        }
        if ($status === 429) {
            $wait = isset($data['retry_after_seconds']) ? (int) $data['retry_after_seconds'] : 0;
            return $said !== ''
                ? $said . ($wait > 0 ? ' Try again in ' . $wait . ' second' . ($wait === 1 ? '' : 's') . '.' : '')
                : 'The bridge is rate limiting. Slow down.';
        }
        if ($status === 503 && $said === '') {
            return 'The bridge is running but not connected to WhatsApp. Re-pair the number.';
        }

        return $said !== '' ? $said : 'The bridge returned HTTP ' . $status . '.';
    }
}
