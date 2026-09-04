<?php
declare(strict_types=1);

/**
 * Minimal cPanel UAPI client used to auto-create church admin email accounts
 * (and optional forwarders) when a registration is approved.
 *
 * Credentials come from Settings → Corporate Email (cPanel): the cPanel host
 * (port 2083), the cPanel username, and an API token (cPanel → Security →
 * Manage API Tokens). The token is the only credential needed for the API and
 * is far safer than storing the cPanel password.
 */
class CpanelApi
{
    private string $host;
    private string $user;
    private string $token;
    private int $port;

    /** @param array{host?:string,user?:string,token?:string,port?:int} $cfg */
    public function __construct(array $cfg)
    {
        // Tolerate common input mistakes: "https://host", "host:2083", "host/".
        $raw = trim((string) ($cfg['host'] ?? ''));
        $raw = preg_replace('#^https?://#i', '', $raw) ?? '';
        $raw = rtrim($raw, '/');
        if (preg_match('#^(.*):(\d+)$#', $raw, $m)) {
            $this->host = $m[1];
            $this->port = (int) $m[2];
        } else {
            $this->host = $raw;
            $this->port = max(1, (int) ($cfg['port'] ?? 2083));
        }
        $this->user = (string) ($cfg['user'] ?? '');
        $this->token = (string) ($cfg['token'] ?? '');
    }

    public function configured(): bool
    {
        return $this->host !== '' && $this->user !== '' && $this->token !== '';
    }

    /** Lightweight call used by the Settings "Test cPanel connection" button. */
    public function testConnection(): array
    {
        return $this->request('Email/list_pops', []);
    }

    /** Create a POP/IMAP mailbox. Returns ['ok'=>bool,'error'=>?string,'exists'=>bool]. */
    public function createEmail(string $domain, string $localPart, string $password, int $quotaMB): array
    {
        $result = $this->request('Email/add_pop', [
            'email' => $localPart . '@' . $domain,
            'password' => $password,
            'quota' => max(0, $quotaMB),
            'domain' => $domain,
        ]);
        if (!$result['ok'] && stripos((string) $result['error'], 'exist') !== false) {
            // Already created earlier — treat as success (idempotent).
            return ['ok' => true, 'error' => null, 'exists' => true];
        }
        $result['exists'] = false;
        return $result;
    }

    /** Create a forwarder from a corporate mailbox to an external backup inbox. */
    public function createForwarder(string $domain, string $localPart, string $forwardTo): array
    {
        return $this->request('Email/add_forwarder', [
            'domain' => $domain,
            'forwarder' => $localPart,
            'fwdemail' => $forwardTo,
            'fwdopt' => 'fwd', // forward only, no local copy
        ]);
    }

    /** cPanel UAPI request; returns ['ok'=>bool,'error'=>?string]. */
    private function request(string $module, array $params): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'cPanel API is not configured.'];
        }
        $url = 'https://' . $this->host . ':' . $this->port . '/execute/' . $module . '?' . http_build_query($params);

        // Prefer cURL — follows redirects and handles TLS reliably on shared
        // hosts. Falls back to the HTTP stream wrapper only if the cURL
        // extension isn't compiled in.
        //
        // cPanel UAPI with an API Token requires the header:
        //   Authorization: cpanel <username>:<token>   (plain, NOT Basic/base64)
        // Using CURLOPT_USERPWD would send Basic auth which cPanel rejects with
        // a 401 login page even when the credentials are correct.
        $cpanelAuthHeader = 'Authorization: cpanel ' . $this->user . ':' . $this->token;
        $body = false;
        $status = 0;
        $transport = 'unknown';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPGET => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTPHEADER => [$cpanelAuthHeader, 'Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $transport = 'curl';
            if ($body === false) {
                return ['ok' => false, 'error' => 'Could not reach the cPanel API (' . $this->host . ':' . $this->port . '). ' . ($err !== '' ? 'cURL: ' . $err : 'No response.')];
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => $cpanelAuthHeader . "\r\n" .
                                "Accept: application/json\r\n",
                    'timeout' => 30,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            $transport = 'stream';
            if (!empty($http_response_header)) {
                foreach ($http_response_header as $line) {
                    if (preg_match('#^HTTP/\S+\s+(\d+)#', (string) $line, $m)) {
                        $status = (int) $m[1];
                    }
                }
            }
            if ($body === false) {
                return ['ok' => false, 'error' => 'Could not reach the cPanel API (' . $this->host . ':' . $this->port . '). No response.'];
            }
        }

        $data = json_decode($body, true);

        // Imunify360 (or similar server WAF) intercepts automated API calls with
        // an HTTP 403 before they ever reach cPanel — credentials are fine.
        if ($status === 403 && (stripos($body, 'imunify') !== false || stripos($body, 'bot-protection') !== false || stripos($body, 'automation') !== false)) {
            return ['ok' => false, 'error' => 'Blocked by Imunify360 bot-protection before reaching cPanel (your username, token, and host are fine). Whitelist the IP the app connects from (on the live server that is the server’s own public IP) in Imunify360 — cPanel → Imunify360 → Settings → Whitelist — or ask your hosting provider to allow automation from that IP, then test again.'];
        }

        if (!is_array($data)) {
            $excerpt = trim((string) preg_replace('/\s+/', ' ', (string) $body));
            $excerpt = mb_substr($excerpt, 0, 200);
            $hint = '';
            if ($status === 401 || $status === 403) {
                $hint = ' cPanel rejected the credentials (this is the login page it returns on auth failure). The username/token format is right, so check: (a) the Host is the cPanel hostname you log in with (a website domain resolves to a shared IP and hits another account); (b) the token belongs to the SAME cPanel account as the username; (c) the token has no IP restriction blocking your server; (d) the account has no two-factor auth blocking API tokens.';
            } elseif ($status === 404) {
                $hint = ' Not found — this is probably not the cPanel server. Use the cPanel hostname you log in with (e.g. cpanel.<provider>.com), not the website domain.';
            } elseif (stripos($excerpt, '<html') !== false || stripos($excerpt, '<!doctype') !== false) {
                $hint = ' The server returned a web page instead of the API — confirm the cPanel host/port (2083 for cPanel) and that API access is allowed.';
            }
            return ['ok' => false, 'error' => 'Unexpected cPanel API response (HTTP ' . $status . ', via ' . $transport . ').' . $hint . ' Body: ' . ($excerpt !== '' ? $excerpt : '(empty)')];
        }
        $errors = $data['errors'] ?? [];
        if (!empty($errors)) {
            return ['ok' => false, 'error' => implode(' ', array_map('strval', $errors))];
        }
        $ok = (int) ($data['status'] ?? 0) === 1;
        return ['ok' => $ok, 'error' => $ok ? null : 'cPanel API returned status 0 for ' . $module . '.'];
    }
}
