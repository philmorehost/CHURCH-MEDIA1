<?php
declare(strict_types=1);

/**
 * Google Firebase Cloud Messaging (HTTP v1) push sender.
 *
 * To activate, create config/firebase.php that returns:
 *   return [
 *     'service_account' => ROOT_PATH . '/storage/service-account.json', // downloaded JSON key
 *     'project_id'      => 'your-fcm-project-id',                        // shown in Firebase console
 *   ];
 *
 * When not configured (or the service-account file is missing) every method
 * returns false quietly, so the site keeps working without push enabled.
 */
class Pusher
{
    private static ?string $token = null;
    private static int $tokenExpires = 0;

    private static function config(): array
    {
        $file = CONFIG_PATH . '/firebase.php';
        return is_file($file) ? (array) require $file : [];
    }

    private static function projectId(): string
    {
        return (string) (self::config()['project_id'] ?? '');
    }

    private static function serviceAccountPath(): string
    {
        return (string) (self::config()['service_account'] ?? '');
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function accessToken(): ?string
    {
        if (self::$token && self::$tokenExpires > time() + 60) {
            return self::$token;
        }
        $path = self::serviceAccountPath();
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            return null;
        }
        $now = time();
        $b64h = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $b64c = self::base64Url(json_encode([
            'iss' => $json['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $signingInput = $b64h . '.' . $b64c;
        $signature = '';
        openssl_sign($signingInput, $signature, $json['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = $signingInput . '.' . self::base64Url($signature);

        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
            'timeout' => 15,
        ]]);
        $res = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
        if ($res === false) {
            return null;
        }
        $data = json_decode($res, true);
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }
        self::$token = (string) $data['access_token'];
        self::$tokenExpires = $now + (int) ($data['expires_in'] ?? 3600);
        return self::$token;
    }

    /**
     * Send a push to a device token or a topic ('topic/NAME').
     * Returns true when FCM accepted the message.
     */
    public static function send(string $tokenOrTopic, string $title, string $body, ?string $imageUrl = null, array $data = []): bool
    {
        $token = self::accessToken();
        $projectId = self::projectId();
        if ($token === null || $projectId === '') {
            return false;
        }
        $message = ['notification' => ['title' => $title, 'body' => $body]];
        if ($imageUrl !== null && $imageUrl !== '') {
            $message['notification']['image'] = $imageUrl;
        }
        if (str_starts_with($tokenOrTopic, 'topic/')) {
            $message['topic'] = substr($tokenOrTopic, 6);
        } else {
            $message['token'] = $tokenOrTopic;
        }
        if ($data) {
            $message['data'] = $data;
        }
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
            'content' => json_encode(['message' => $message]),
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            return false;
        }
        $parsed = json_decode($res, true);
        return is_array($parsed) && !empty($parsed['name']);
    }

    /** Broadcast to every subscribed device (topic 'all'). */
    public static function broadcast(string $title, string $body, ?string $imageUrl = null, array $data = []): bool
    {
        return self::send('topic/all', $title, $body, $imageUrl, $data);
    }

    /**
     * Send to every device subscribed to a unit's topic (topic 'unit-{id}').
     * The app subscribes to this topic when the user browses that church.
     */
    public static function sendToUnit(int $unitId, string $title, string $body, ?string $imageUrl = null, array $data = []): bool
    {
        return self::send('topic/unit-' . $unitId, $title, $body, $imageUrl, $data);
    }

    /** Push a newly published post to its church's subscribers + broadcast. */
    public static function notifyNewPost(PDO $pdo, int $postId, ?int $orgUnitId, string $caption): void
    {
        $body = $caption !== '' ? mb_strimwidth($caption, 0, 100, '…') : 'Tap to watch it now.';
        $imageUrl = null;
        $stmt = $pdo->prepare("SELECT file_path FROM media_post_items WHERE media_post_id = ? AND type = 'image' ORDER BY sort_order ASC LIMIT 1");
        $stmt->execute([$postId]);
        $cover = $stmt->fetchColumn();
        if ($cover && !str_starts_with((string) $cover, 'http')) {
            $imageUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . '/uploads/' . $cover;
        }
        self::notifyContent($orgUnitId, 'New reel', $body, $imageUrl, ['type' => 'post', 'post_id' => (string) $postId]);
    }

    /** Push a newly published event to its church's subscribers + broadcast. */
    public static function notifyNewEvent(PDO $pdo, int $eventId, ?int $orgUnitId, string $title, ?string $location = null): void
    {
        $body = mb_strimwidth($title, 0, 90, '…');
        if ($location !== null && $location !== '') {
            $body .= ' · ' . mb_strimwidth($location, 0, 40, '…');
        }
        self::notifyContent($orgUnitId, 'New event', $body, null, ['type' => 'event', 'event_id' => (string) $eventId]);
    }

    /** Push a newly published sermon to its church's subscribers + broadcast. */
    public static function notifyNewSermon(PDO $pdo, int $sermonId, ?int $orgUnitId, string $title): void
    {
        self::notifyContent($orgUnitId, 'New sermon', mb_strimwidth($title, 0, 100, '…'), null, ['type' => 'sermon', 'sermon_id' => (string) $sermonId]);
    }

    /** Shared: send to the church's topic (with church name) + broadcast. */
    private static function notifyContent(?int $orgUnitId, string $title, string $body, ?string $imageUrl = null, array $data = []): void
    {
        if ($orgUnitId !== null && $orgUnitId > 0) {
            $unit = Unit::find($orgUnitId);
            $unitTitle = $unit ? $unit['name'] . ' — ' . $title : $title;
            self::sendToUnit($orgUnitId, $unitTitle, $body, $imageUrl, $data);
        }
        self::broadcast($title, $body, $imageUrl, $data);
    }
}
