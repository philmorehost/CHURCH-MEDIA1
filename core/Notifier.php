<?php
declare(strict_types=1);

/**
 * Delivers an in-app notification to a set of units, plus email and push.
 *
 * Extracted from the Notifications screen so the SMS sender-ID approval can send the
 * same thing. Delivery lives here; deciding *who* to notify stays with the caller,
 * because the audience rules differ (the notifications screen expands a picked unit's
 * subtree, the sender-ID approval targets one church).
 *
 * Every delivery is best-effort: an SMTP server that is down, or a push token that has
 * expired, must not stop the in-app notification from being recorded.
 */
final class Notifier
{
    /**
     * @param array<int, mixed> $unitIds  Units that should receive it.
     * @param array<string, mixed> $options {
     *     sender_id:     int|null   The user sending it, for the "from" line.
     *     target_unit_id:int|null   The unit that was picked, for the audit trail.
     *     target_level:  string|null
     *     email:         bool       Default true.
     *     push:          bool       Default true.
     *     roles:         array      Roles to email. Default admin + editor.
     * }
     * @return array{ok:bool,notification_id:int,units:int,emailed:int,pushed:int,errors:array<int,string>}
     */
    public static function send(array $unitIds, string $title, string $body, array $options = []): array
    {
        $title = trim($title);
        $body = trim($body);
        $result = ['ok' => false, 'notification_id' => 0, 'units' => 0, 'emailed' => 0, 'pushed' => 0, 'errors' => []];

        if ($title === '' || $body === '') {
            $result['errors'][] = 'A notification needs both a title and a message.';
            return $result;
        }

        // De-duplicated and cast to int, so a caller passing strings cannot inject
        // anything into the queries below.
        $unitIds = array_values(array_unique(array_filter(array_map('intval', $unitIds), static fn(int $id): bool => $id > 0)));
        if ($unitIds === []) {
            $result['errors'][] = 'There are no recipient units.';
            return $result;
        }

        $pdo = self::db();

        try {
            $pdo->prepare('INSERT INTO notifications (sender_id, title, body, target_unit_id, target_level) VALUES (?, ?, ?, ?, ?)')
                ->execute([
                    isset($options['sender_id']) ? (int) $options['sender_id'] : null,
                    $title,
                    $body,
                    isset($options['target_unit_id']) ? (int) $options['target_unit_id'] : null,
                    $options['target_level'] ?? null,
                ]);
            $notificationId = (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            $result['errors'][] = 'The notification could not be saved: ' . $e->getMessage();
            return $result;
        }

        $result['notification_id'] = $notificationId;

        // One row per receiving unit, so a mid-level admin can mark it read just as a
        // church can. The unique key keeps this idempotent.
        $insert = $pdo->prepare('INSERT IGNORE INTO notification_recipients (notification_id, org_unit_id) VALUES (?, ?)');
        foreach ($unitIds as $unitId) {
            try {
                $insert->execute([$notificationId, $unitId]);
                $result['units']++;
            } catch (Throwable $e) {
                $result['errors'][] = 'Could not queue unit ' . $unitId . '.';
            }
        }

        if (($options['push'] ?? true) === true) {
            $result['pushed'] = self::push($unitIds, $title, $body, $notificationId);
        }

        if (($options['email'] ?? true) === true) {
            $result['emailed'] = self::email($unitIds, $title, $body, $notificationId, $options['roles'] ?? ['admin', 'editor']);
        }

        $result['ok'] = $result['units'] > 0;
        return $result;
    }

    /**
     * Pushes to the churches plus the picked unit, rather than to every intermediate
     * level: a device subscribes to the topic of the church it is browsing, so the
     * leaves are what actually reaches people.
     *
     * @param array<int, int> $unitIds
     */
    private static function push(array $unitIds, string $title, string $body, int $notificationId): int
    {
        if (!class_exists('Pusher')) {
            return 0;
        }

        $sent = 0;
        try {
            $leafType = Unit::leafType();
            $leafIds = [];
            foreach (Unit::all('id ASC') as $unit) {
                if (in_array((int) $unit['id'], $unitIds, true) && (string) $unit['type'] === $leafType) {
                    $leafIds[] = (int) $unit['id'];
                }
            }

            foreach (array_unique(array_merge($leafIds, $unitIds)) as $unitId) {
                if (Pusher::sendToUnit($unitId, $title, $body, null, [
                    'type' => 'admin_notice',
                    'notification_id' => (string) $notificationId,
                ])) {
                    $sent++;
                }
            }
        } catch (Throwable $e) {
            error_log('Notifier push failed: ' . $e->getMessage());
        }

        return $sent;
    }

    /**
     * Emails every user in one of the units whose role is in $roles.
     *
     * @param array<int, int>    $unitIds
     * @param array<int, string> $roles
     */
    private static function email(array $unitIds, string $title, string $body, int $notificationId, array $roles): int
    {
        $roles = array_values(array_filter(array_map('strval', $roles)));
        if ($roles === [] || !class_exists('Mailer')) {
            return 0;
        }

        $emailed = 0;
        try {
            $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
            $rolePlaceholders = implode(',', array_fill(0, count($roles), '?'));
            $stmt = self::db()->prepare(
                "SELECT name, email, org_unit_id FROM users
                  WHERE org_unit_id IN ({$placeholders})
                    AND role IN ({$rolePlaceholders})
                    AND email IS NOT NULL AND email != ''"
            );
            $stmt->execute(array_merge($unitIds, $roles));

            $deliveredUnits = [];
            foreach ($stmt->fetchAll() as $user) {
                if (Mailer::send($user['email'], $title, $body . "\n\nView it in the admin dashboard: " . self::adminUrl() . '/admin/notifications')) {
                    $emailed++;
                    $deliveredUnits[(int) $user['org_unit_id']] = true;
                }
            }

            $mark = self::db()->prepare('UPDATE notification_recipients SET delivered_at = NOW() WHERE notification_id = ? AND org_unit_id = ?');
            foreach (array_keys($deliveredUnits) as $unitId) {
                $mark->execute([$notificationId, $unitId]);
            }
        } catch (Throwable $e) {
            error_log('Notifier email failed: ' . $e->getMessage());
        }

        return $emailed;
    }

    private static function adminUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }
}
