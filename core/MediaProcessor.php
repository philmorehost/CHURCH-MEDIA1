<?php
declare(strict_types=1);

/**
 * Image → WebP compression (GD) and video → vertical 9:16 reel conversion
 * (FFmpeg, optional). Video uploads are stored as their original file and
 * marked "ready" so they play immediately in the feed; FFmpeg is only used to
 * polish them into a true 9:16 crop, never as a gate for playback.
 */
class MediaProcessor
{
    public static function processImage(string $sourcePath, string $destinationDirectory, int $quality = 82): ?string
    {
        $info = @getimagesize($sourcePath);
        if (!$info) {
            return null;
        }

        $image = match ($info['mime']) {
            'image/jpeg' => imagecreatefromjpeg($sourcePath),
            'image/png' => imagecreatefrompng($sourcePath),
            'image/gif' => imagecreatefromgif($sourcePath),
            'image/webp' => imagecreatefromwebp($sourcePath),
            'image/bmp' => function_exists('imagecreatefrombmp') ? imagecreatefrombmp($sourcePath) : false,
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($sourcePath) : false,
            default => false,
        };
        if ($image === false) {
            return null;
        }

        // Cap the longest edge so nothing oversized reaches the browser/app.
        $maxEdge = 1920;
        $width = imagesx($image);
        $height = imagesy($image);
        if (max($width, $height) > $maxEdge) {
            $scale = $maxEdge / max($width, $height);
            $newWidth = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        if (!is_dir($destinationDirectory)) {
            mkdir($destinationDirectory, 0775, true);
        }
        $filename = uniqid('img_', true) . '.webp';
        $ok = imagewebp($image, $destinationDirectory . '/' . $filename, $quality);
        imagedestroy($image);

        return $ok ? $filename : null;
    }

    /**
     * Compresses an uploaded image down to a small WebP (max edge capped, so
     * oversized photos shrink dramatically). Falls back to the original file
     * when WebP would be larger than the source — the result is always the
     * smaller of the two. Animated GIFs are stored untouched (conversion would
     * flatten them to a single frame). Accepts JPEG, PNG, GIF, WebP, BMP, AVIF.
     */
    public static function compressImage(string $sourcePath, string $destinationDirectory, int $maxEdge = 1280, int $quality = 78, string $prefix = 'form_'): ?string
    {
        if (!is_file($sourcePath)) {
            return null;
        }
        $info = @getimagesize($sourcePath);
        if (!$info) {
            return null;
        }
        $mime = $info['mime'];

        if ($mime === 'image/gif' && self::isAnimatedGif($sourcePath)) {
            if (!is_dir($destinationDirectory)) {
                mkdir($destinationDirectory, 0775, true);
            }
            $name = uniqid($prefix, true) . '.gif';
            copy($sourcePath, $destinationDirectory . '/' . $name);
            return $name;
        }

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            'image/bmp' => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($sourcePath) : false,
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($sourcePath) : false,
            default => false,
        };
        if ($image === false) {
            return null;
        }

        // Downscale so nothing large reaches disk (keeps storage tiny).
        $width = imagesx($image);
        $height = imagesy($image);
        if (max($width, $height) > $maxEdge) {
            $scale = $maxEdge / max($width, $height);
            $newWidth = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }
        imagealphablending($image, true);
        imagesavealpha($image, true);

        if (!is_dir($destinationDirectory)) {
            mkdir($destinationDirectory, 0775, true);
        }
        $name = uniqid($prefix, true) . '.webp';
        $outPath = $destinationDirectory . '/' . $name;
        $ok = imagewebp($image, $outPath, $quality);
        imagedestroy($image);

        if (!$ok || !is_file($outPath)) {
            return null;
        }

        // Keep whichever representation is smaller on disk.
        if (filesize($outPath) >= filesize($sourcePath)) {
            $ext = preg_replace('/[^a-z0-9]/', '', strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION))) ?: 'img';
            $keepName = uniqid($prefix, true) . '.' . $ext;
            copy($sourcePath, $destinationDirectory . '/' . $keepName);
            @unlink($outPath);
            return $keepName;
        }
        return $name;
    }

    private static function isAnimatedGif(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $count = 0;
        while (!feof($handle) && $count < 2) {
            $chunk = fread($handle, 1024 * 1024);
            $count += substr_count((string) $chunk, "\x00\x21\xF9\x04");
        }
        fclose($handle);
        return $count > 1;
    }

    /** Generates a WebP poster frame + a vertical 9:16 reel; returns null values for whichever step FFmpeg can't do. */
    public static function processVideoToReel(string $sourcePath, string $destinationDirectory, string $thumbDirectory, ?string $coverSource = null, bool $extractThumb = true): array
    {
        $ffmpeg = self::ffmpegBinary();
        if (!is_dir($destinationDirectory)) {
            mkdir($destinationDirectory, 0775, true);
        }
        if (!is_dir($thumbDirectory)) {
            mkdir($thumbDirectory, 0775, true);
        }

        // A cover frame captured client-side (or uploaded by the admin) always
        // wins over an FFmpeg-extracted frame — it's the "default cover" the
        // admin sees, and it keeps a poster available even without FFmpeg.
        $thumbName = null;
        if ($coverSource && is_file($coverSource)) {
            $coverFile = self::processImage($coverSource, $thumbDirectory, 82);
            $thumbName = $coverFile ? $coverFile : null;
        }

        if (!$ffmpeg) {
            // No FFmpeg configured: keep a copy of the original as the playable
            // source. It's "ready" — browsers can play the original file, so the
            // feed never blocks a reel on a conversion step.
            $filename = uniqid('reel_', true) . '.' . pathinfo($sourcePath, PATHINFO_EXTENSION);
            $destPath = $destinationDirectory . '/' . $filename;
            copy($sourcePath, $destPath);
            return ['file' => $filename, 'thumbnail' => $thumbName, 'status' => 'ready', 'converted' => false];
        }

        $filename = uniqid('reel_', true) . '.mp4';
        $outputPath = $destinationDirectory . '/' . $filename;
        $cmd = sprintf(
            '%s -y -i %s -vf %s -c:v libx264 -crf 23 -preset fast -movflags +faststart -c:a aac -b:a 128k %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($sourcePath),
            escapeshellarg('scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920'),
            escapeshellarg($outputPath)
        );
        exec($cmd, $__, $exitCode);

        if ($exitCode !== 0 || !is_file($outputPath)) {
            // Conversion failed — keep the original file as the playable source.
            $filename = uniqid('reel_', true) . '.' . pathinfo($sourcePath, PATHINFO_EXTENSION);
            copy($sourcePath, $destinationDirectory . '/' . $filename);
            return ['file' => $filename, 'thumbnail' => $thumbName, 'status' => 'ready', 'converted' => false];
        }

        if (!$thumbName && $extractThumb) {
            $thumbName = uniqid('thumb_', true) . '.webp';
            $thumbCmd = sprintf(
                '%s -y -i %s -ss 00:00:00.5 -frames:v 1 %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($outputPath),
                escapeshellarg($thumbDirectory . '/' . $thumbName)
            );
            exec($thumbCmd, $__, $thumbExit);
            if ($thumbExit !== 0 || !is_file($thumbDirectory . '/' . $thumbName)) {
                $thumbName = null;
            }
        }

        return [
            'file' => $filename,
            'thumbnail' => $thumbName,
            'status' => 'ready',
            'converted' => true,
        ];
    }

    /**
     * Converts one uploaded video item still stored under originals/ to a
     * vertical 9:16 reel. Shared by the admin web flow and the cron worker so
     * both paths behave identically. Returns its status after the attempt:
     * 'ready' (converted, or original kept playable), 'failed', or 'missing'.
     */
    public static function convertOriginalVideo(PDO $pdo, int $itemId): string
    {
        $stmt = $pdo->prepare("SELECT i.* FROM media_post_items i WHERE i.id = ? AND i.type = 'video' AND i.source = 'upload' AND i.file_path LIKE 'originals/%'");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            return 'missing';
        }
        $sourcePath = UPLOADS_PATH . '/' . $item['file_path'];
        if (!is_file($sourcePath)) {
            $pdo->prepare('UPDATE media_post_items SET processing_status = ? WHERE id = ?')->execute(['failed', $itemId]);
            return 'failed';
        }

        $hasCover = $item['thumbnail_path'] && is_file(UPLOADS_PATH . '/' . $item['thumbnail_path']);
        $result = self::processVideoToReel($sourcePath, UPLOADS_REELS_PATH, UPLOADS_THUMBS_PATH, null, !$hasCover);

        if ($result['status'] !== 'ready') {
            // The original already plays as-is; keep it and stay ready.
            return 'ready';
        }

        $newThumb = $hasCover ? $item['thumbnail_path'] : ($result['thumbnail'] ? 'thumbs/' . $result['thumbnail'] : null);

        // Only a real 9:16 crop marks the item as converted; a no-ffmpeg (or
        // failed) copy keeps converted_at NULL so the UI can tell them apart.
        $convertedAt = !empty($result['converted']) ? date('Y-m-d H:i:s') : null;

        $pdo->prepare('UPDATE media_post_items SET file_path = ?, thumbnail_path = ?, processing_status = ?, converted_at = ? WHERE id = ?')
            ->execute(['reels/' . $result['file'], $newThumb, 'ready', $convertedAt, $itemId]);

        @unlink($sourcePath); // originals only live until the reel is ready
        return 'ready';
    }

    private static function ffmpegBinary(): ?string
    {
        $path = setting('ffmpeg_path', null);
        if ($path && is_file($path)) {
            return $path;
        }
        // Common local fallback if the admin drops a static build next to the project.
        $bundled = ROOT_PATH . '/bin/ffmpeg/ffmpeg.exe';
        return is_file($bundled) ? $bundled : null;
    }

    /** SVG initial-letter favicon, used when no favicon has been uploaded. */
    public static function renderDynamicFavicon(string $initial): never
    {
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=86400');
        $char = e(strtoupper(substr(trim($initial) ?: 'C', 0, 1)));
        echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<rect width="100" height="100" rx="20" fill="#1a1530"/>'
            . '<text x="50%" y="56%" dominant-baseline="middle" text-anchor="middle" fill="#e8b95f" '
            . 'font-size="56" font-family="Georgia, serif" font-weight="700">' . $char . '</text></svg>';
        exit;
    }
}
