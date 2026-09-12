<?php
declare(strict_types=1);

/**
 * Comment moderation.
 *
 * Comments are screened on the way in and land as either `approved` or held
 * (`pending` / `spam`). Everything is driven by the admin screen, and the
 * defaults are deliberately permissive — moderation ships **off**, so an
 * existing site keeps publishing comments exactly as it did before until the
 * church switches it on.
 *
 * The public API reads `status = 'approved'` in addition to the older
 * `is_published` flag, which is kept working so nothing that used it breaks.
 */
final class CommentModeration
{
    public const MODES = [
        'off' => 'Off — publish every comment immediately',
        'all' => 'Hold every comment for review',
        'links' => 'Hold comments containing a link',
        'words' => 'Hold comments containing a blocked word',
    ];

    /** How much is held for review. */
    public static function mode(): string
    {
        $mode = (string) setting('comments_moderation', 'off');
        return array_key_exists($mode, self::MODES) ? $mode : 'off';
    }

    /** Reports on a single comment before it is auto-flagged for attention. */
    public static function flagThreshold(): int
    {
        return max(1, (int) setting('comments_flag_threshold', 3));
    }

    /**
     * Admin-managed word lists for the current church.
     *
     * @return array{block: string[], spam: string[]}
     */
    public static function lists(): array
    {
        $out = ['block' => [], 'spam' => []];
        try {
            $stmt = self::db()->prepare('SELECT kind, word FROM comment_blocklist WHERE tenant_id <=> ? ORDER BY word ASC');
            $stmt->execute([self::tenantId()]);
            foreach ($stmt->fetchAll() as $row) {
                $kind = $row['kind'] === 'spam' ? 'spam' : 'block';
                $out[$kind][] = (string) $row['word'];
            }
        } catch (Throwable $e) {
            // Table not migrated yet — moderation simply has no word list.
        }
        return $out;
    }

    /** Replaces both word lists for the current church. */
    public static function saveLists(string $blockText, string $spamText): int
    {
        $pdo = self::db();
        $tenantId = self::tenantId();

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM comment_blocklist WHERE tenant_id <=> ?')->execute([$tenantId]);
            $ins = $pdo->prepare('INSERT IGNORE INTO comment_blocklist (tenant_id, kind, word) VALUES (?, ?, ?)');
            $saved = 0;
            foreach (['block' => $blockText, 'spam' => $spamText] as $kind => $text) {
                foreach (self::parseWords($text) as $word) {
                    $ins->execute([$tenantId, $kind, $word]);
                    $saved++;
                }
            }
            $pdo->commit();
            return $saved;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Screens an incoming comment.
     *
     * @return array{status:string, reason:?string}
     */
    public static function screen(string $name, string $message): array
    {
        $haystack = mb_strtolower($name . ' ' . $message);
        $lists = self::lists();

        foreach ($lists['spam'] as $word) {
            if ($word !== '' && str_contains($haystack, $word)) {
                return ['status' => 'spam', 'reason' => 'Spam word: "' . $word . '"'];
            }
        }

        $mode = self::mode();
        if ($mode === 'all') {
            return ['status' => 'pending', 'reason' => 'Every comment is reviewed before it appears'];
        }
        if ($mode === 'links' && preg_match('#https?://|www\.#i', $message)) {
            return ['status' => 'pending', 'reason' => 'Contains a link'];
        }
        if ($mode === 'words') {
            foreach ($lists['block'] as $word) {
                if ($word !== '' && str_contains($haystack, $word)) {
                    return ['status' => 'pending', 'reason' => 'Blocked word: "' . $word . '"'];
                }
            }
        }

        return ['status' => 'approved', 'reason' => null];
    }

    /**
     * Records a reader report. One report per fingerprint per comment, so a
     * single person cannot force a comment off the site by reporting it repeatedly.
     *
     * @return array{ok:bool, message:string, report_count:int, flagged:bool}
     */
    public static function report(int $commentId, ?string $reason): array
    {
        if ($commentId <= 0) {
            return ['ok' => false, 'message' => 'comment_id is required.', 'report_count' => 0, 'flagged' => false];
        }

        $pdo = self::db();
        $exists = $pdo->prepare('SELECT id FROM post_comments WHERE id = ?');
        $exists->execute([$commentId]);
        if (!$exists->fetchColumn()) {
            return ['ok' => false, 'message' => 'Comment not found.', 'report_count' => 0, 'flagged' => false];
        }

        $ins = $pdo->prepare('INSERT IGNORE INTO comment_reports (comment_id, fingerprint_hash, reason) VALUES (?, ?, ?)');
        $ins->execute([
            $commentId,
            Fingerprint::hash(),
            ($reason !== null && trim($reason) !== '') ? mb_substr(trim($reason), 0, 255) : null,
        ]);

        if ($ins->rowCount() > 0) {
            $pdo->prepare('UPDATE post_comments SET report_count = report_count + 1 WHERE id = ?')->execute([$commentId]);
        }

        $countStmt = $pdo->prepare('SELECT report_count FROM post_comments WHERE id = ?');
        $countStmt->execute([$commentId]);
        $count = (int) $countStmt->fetchColumn();

        $flagged = $count >= self::flagThreshold();
        if ($flagged) {
            $pdo->prepare('UPDATE post_comments SET is_flagged = 1 WHERE id = ?')->execute([$commentId]);
        }

        return [
            'ok' => true,
            'message' => $ins->rowCount() > 0
                ? 'Thank you — a leader will review this comment.'
                : 'You have already reported this comment.',
            'report_count' => $count,
            'flagged' => $flagged,
        ];
    }

    /** Splits a textarea into clean, lowercased, de-duplicated words. */
    private static function parseWords(string $text): array
    {
        $parts = preg_split('/[\r\n,]+/', $text) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $word = mb_strtolower(trim($part));
            if ($word !== '' && mb_strlen($word) <= 100) {
                $out[$word] = true;
            }
        }
        return array_keys($out);
    }

    private static function tenantId(): ?int
    {
        return class_exists('Tenant') ? Tenant::id() : null;
    }

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }
}
