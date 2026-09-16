<?php
declare(strict_types=1);

/**
 * Minimal mail service. Uses a hand-rolled SMTP client when config/mail.php
 * has SMTP credentials (needed for real deliverability — Gmail etc. reject
 * PHP's mail()); falls back to mail() otherwise. No external libraries,
 * per the vanilla-PHP constraint.
 */
class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $config = is_file(CONFIG_PATH . '/mail.php') ? require CONFIG_PATH . '/mail.php' : [];

        // SMTP can be configured from the admin Settings page (super admin) or
        // config/mail.php. Settings take priority.
        $smtpHost = setting('smtp_host') ?: ($config['smtp_host'] ?? '');
        if ($smtpHost !== '') {
            // An explicit "" is the Settings page's "None" and has to be honoured. Treating it as
            // "unset" and substituting 'tls' made the client send STARTTLS to a server the admin had
            // told us not to encrypt to; the greeting that comes back is not the one STARTTLS expects,
            // so the send dies with an SMTP protocol error that reads like a bad password. The column
            // is created with DEFAULT 'tls', so only a NULL really means "never chosen".
            $secure = setting('smtp_secure');
            $smtpConfig = [
                'smtp_host' => $smtpHost,
                'smtp_port' => (int) (setting('smtp_port') ?: ($config['smtp_port'] ?? 587)),
                'smtp_secure' => $secure !== null ? (string) $secure : (string) ($config['smtp_secure'] ?? 'tls'),
                'smtp_username' => setting('smtp_username') ?: ($config['smtp_username'] ?? ''),
                'smtp_password' => setting('smtp_password') ?: ($config['smtp_password'] ?? ''),
                'from_address' => setting('smtp_from') ?: ($config['from_address'] ?? ''),
            ];
            try {
                return self::sendViaSmtp($smtpConfig, $to, $subject, $body);
            } catch (Throwable $e) {
                error_log('Mailer SMTP error: ' . $e->getMessage());
                return false;
            }
        }

        $from = $config['from_address'] ?? setting('smtp_from') ?? ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8";
        return @mail($to, $subject, $body, $headers);
    }

    public static function sendSecurityAlert(string $subject, string $body): bool
    {
        $to = setting('contact_email');
        if (!$to) {
            return false;
        }
        return self::send($to, '[Security Alert] ' . $subject, $body);
    }

    /**
     * Whether mail is going out through configured SMTP, rather than PHP's mail().
     *
     * Deliberately not "can we send at all": the fallback will attempt mail(), which works on some
     * hosts and vanishes into a void on others with no error. This answers the question an admin
     * actually has — is delivery set up properly — and reading it here keeps the workers' status
     * lines honest instead of reassuring.
     */
    public static function configured(): bool
    {
        $config = is_file(CONFIG_PATH . '/mail.php') ? require CONFIG_PATH . '/mail.php' : [];
        return (string) (setting('smtp_host') ?: ($config['smtp_host'] ?? '')) !== '';
    }

    private static function sendViaSmtp(array $config, string $to, string $subject, string $body): bool
    {
        $host = $config['smtp_host'];
        $port = (int) ($config['smtp_port'] ?? 587);
        $secure = $config['smtp_secure'] ?? 'tls'; // 'ssl' | 'tls' | ''
        $transport = $secure === 'ssl' ? 'ssl://' . $host : $host;

        $socket = @fsockopen($transport, $port, $errno, $errstr, 3);
        if (!$socket) {
            throw new RuntimeException("Could not connect to SMTP host: $errstr");
        }
        @stream_set_timeout($socket, 3);

        // Reads ONE complete SMTP reply and insists it carries the code we are waiting for.
        //
        // A reply is one or more lines: every line but the last carries '-' as its fourth character
        // ("250-smtp.example.com at your service", "250-SIZE 35882577", "250 STARTTLS"). Reading only
        // the first line left the rest of the greeting in the buffer, so every later response was read
        // one line early and the whole session drifted out of step - harmless-looking here, fatal
        // against every real server, which all answer EHLO with several lines. The send then died with
        // "Unexpected SMTP response: 250 HELP", which reads like a rejected password and sends the
        // search to the credentials instead of to the client.
        $expect = function (string $code) use ($socket) {
            do {
                $line = fgets($socket, 512);
                if ($line === false) {
                    throw new RuntimeException('SMTP server closed the connection');
                }
                if (strpos($line, $code) !== 0) {
                    throw new RuntimeException('Unexpected SMTP response: ' . rtrim($line));
                }
            } while (isset($line[3]) && $line[3] === '-');
        };
        $send = function (string $line) use ($socket) {
            fwrite($socket, $line . "\r\n");
        };

        $expect('220');
        $send('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $expect('250');

        if ($secure === 'tls') {
            $send('STARTTLS');
            $expect('220');
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $send('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $expect('250');
        }

        if (!empty($config['smtp_username'])) {
            $send('AUTH LOGIN');
            $expect('334');
            $send(base64_encode($config['smtp_username']));
            $expect('334');
            $send(base64_encode($config['smtp_password'] ?? ''));
            $expect('235');
        }

        $from = $config['from_address'] ?? $config['smtp_username'];
        $send('MAIL FROM:<' . $from . '>');
        $expect('250');
        $send('RCPT TO:<' . $to . '>');
        $expect('250');
        $send('DATA');
        $expect('354');

        $headers = "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\nContent-Type: text/plain; charset=UTF-8";
        $send($headers . "\r\n\r\n" . $body . "\r\n.");
        $expect('250');

        $send('QUIT');
        fclose($socket);
        return true;
    }
}
