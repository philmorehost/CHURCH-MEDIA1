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
            $secure = setting('smtp_secure');
            $smtpConfig = [
                'smtp_host' => $smtpHost,
                'smtp_port' => (int) (setting('smtp_port') ?: ($config['smtp_port'] ?? 587)),
                'smtp_secure' => ($secure !== null && $secure !== '') ? $secure : ($config['smtp_secure'] ?? 'tls'),
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

    private static function sendViaSmtp(array $config, string $to, string $subject, string $body): bool
    {
        $host = $config['smtp_host'];
        $port = (int) ($config['smtp_port'] ?? 587);
        $secure = $config['smtp_secure'] ?? 'tls'; // 'ssl' | 'tls' | ''
        $transport = $secure === 'ssl' ? 'ssl://' . $host : $host;

        $socket = @fsockopen($transport, $port, $errno, $errstr, 10);
        if (!$socket) {
            throw new RuntimeException("Could not connect to SMTP host: $errstr");
        }

        $expect = function (string $prefix) use ($socket) {
            $line = fgets($socket, 512);
            if ($line === false || !str_starts_with($line, $prefix)) {
                throw new RuntimeException('Unexpected SMTP response: ' . $line);
            }
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
