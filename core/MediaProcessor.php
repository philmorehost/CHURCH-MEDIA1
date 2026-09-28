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

        switch ($info['mime']) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($sourcePath);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($sourcePath);
                break;
            case 'image/webp':
                $image = @imagecreatefromwebp($sourcePath);
                break;
            case 'image/bmp':
                $image = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($sourcePath) : false;
                break;
            case 'image/avif':
                $image = function_exists('imagecreatefromavif') ? @imagecreatefromavif($sourcePath) : false;
                break;
            default:
                $image = false;
                break;
        }
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

        switch ($mime) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($sourcePath);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($sourcePath);
                break;
            case 'image/webp':
                $image = @imagecreatefromwebp($sourcePath);
                break;
            case 'image/bmp':
                $image = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($sourcePath) : false;
                break;
            case 'image/avif':
                $image = function_exists('imagecreatefromavif') ? @imagecreatefromavif($sourcePath) : false;
                break;
            default:
                $image = false;
                break;
        }
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

    /**
     * Crops and formats an uploaded ad image into an exact 9:16 vertical ratio (1080x1920 WebP).
     */
    public static function processAdImage(string $sourcePath, string $destinationDirectory, int $quality = 85): ?string
    {
        if (!is_file($sourcePath)) {
            return null;
        }
        $info = @getimagesize($sourcePath);
        if (!$info) {
            return null;
        }

        switch ($info['mime']) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($sourcePath);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($sourcePath);
                break;
            case 'image/webp':
                $image = @imagecreatefromwebp($sourcePath);
                break;
            case 'image/bmp':
                $image = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($sourcePath) : false;
                break;
            case 'image/avif':
                $image = function_exists('imagecreatefromavif') ? @imagecreatefromavif($sourcePath) : false;
                break;
            default:
                $image = false;
                break;
        }
        if ($image === false) {
            return null;
        }

        $srcW = imagesx($image);
        $srcH = imagesy($image);
        $targetW = 1080;
        $targetH = 1920;

        $srcRatio = $srcW / $srcH;
        $targetRatio = $targetW / $targetH;

        if ($srcRatio > $targetRatio) {
            $cropH = $srcH;
            $cropW = (int) round($srcH * $targetRatio);
            $srcX = (int) round(($srcW - $cropW) / 2);
            $srcY = 0;
        } else {
            $cropW = $srcW;
            $cropH = (int) round($srcW / $targetRatio);
            $srcX = 0;
            $srcY = (int) round(($srcH - $cropH) / 2);
        }

        $canvas = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $image, 0, 0, $srcX, $srcY, $targetW, $targetH, $cropW, $cropH);
        imagedestroy($image);

        if (!is_dir($destinationDirectory)) {
            mkdir($destinationDirectory, 0775, true);
        }
        $filename = uniqid('ad_img_', true) . '.webp';
        $ok = imagewebp($canvas, $destinationDirectory . '/' . $filename, $quality);
        imagedestroy($canvas);

        return $ok ? $filename : null;
    }

    /**
     * Processes an ad video into an exact 9:16 vertical reel format.
     */
    public static function processAdVideo(string $sourcePath, string $destinationDirectory, string $thumbDirectory): array
    {
        return self::processVideoToReel($sourcePath, $destinationDirectory, $thumbDirectory, null, true);
    }

    /**
     * Downloads and stores a remote thumbnail image for YouTube, Facebook, Vimeo,
     * or other video links as a local WebP cover image ('webp/...').
     */
    public static function fetchVideoUrlThumbnail(?string $videoUrl): ?string
    {
        if (!$videoUrl) {
            return null;
        }
        $videoUrl = trim($videoUrl);
        $imageUrl = null;

        // 1. YouTube
        $ytId = youtubeVideoId($videoUrl);
        if ($ytId) {
            $imageUrl = 'https://i.ytimg.com/vi/' . $ytId . '/hqdefault.jpg';
        }
        // 2. Vimeo
        elseif (preg_match('#vimeo\.com/(?:video/)?([0-9]+)#', $videoUrl, $m)) {
            $vimeoApi = 'https://vimeo.com/api/v2/video/' . $m[1] . '.json';
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $json = @file_get_contents($vimeoApi, false, $ctx);
            if ($json) {
                $data = json_decode($json, true);
                if (is_array($data) && !empty($data[0]['thumbnail_large'])) {
                    $imageUrl = $data[0]['thumbnail_large'];
                }
            }
        }

        if (!$imageUrl) {
            return null;
        }

        $ctx = stream_context_create(['http' => [
            'timeout' => 8,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n",
        ]]);
        $imgData = @file_get_contents($imageUrl, false, $ctx);
        if ($imgData === false || strlen($imgData) < 100) {
            return null;
        }

        $tempPath = sys_get_temp_dir() . '/' . uniqid('vthumb_', true);
        file_put_contents($tempPath, $imgData);
        $storedName = self::compressImage($tempPath, UPLOADS_WEBP_PATH, 1280, 80, 'sermon_cover_');
        @unlink($tempPath);

        return $storedName ? 'webp/' . $storedName : null;
    }

    /**
     * SVG initial-letter favicon, used when no favicon has been uploaded.
     *
     * Declared `void` rather than `never`: `never` is PHP 8.1 syntax, and on
     * PHP 7 it is read as a class name. The function writes the image and then
     * exits, so a void return is accurate on every supported version.
     */
    public static function renderDynamicFavicon(string $initial): void
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

    /**
     * Outputs this church's app icon as a square PNG, at the requested size.
     *
     * Served by the `/app-icon.png` route and referenced by `views/manifest.php` and the layout's
     * `apple-touch-icon`, so the icon under an installed app is the church's own rather than the
     * artwork shipped with the code. It is generated rather than pointing straight at the uploaded
     * logo for two reasons, each of which is the difference between "the church's logo appears" and a
     * silent fallback to the platform default:
     *
     * 1. Uploads are stored as **WebP**, which several launchers — and iOS for `apple-touch-icon`
     *    specifically — refuse as an icon, so a manifest pointed straight at the stored file is an
     *    icon some devices simply do not draw.
     * 2. A launcher needs the exact size the manifest declares. The logo is whatever shape the church
     *    uploaded, so drawing it onto a square canvas is what makes a `sizes` claim true instead of a
     *    guess that can make an install be refused.
     *
     * The image is **contained**, never cropped: a crest or a wordmark has its edges doing work, and a
     * launcher that shows two-thirds of it is worse than one that shows all of it a little smaller.
     * Whatever the image does not cover is filled with the site's own `--bg-0`, so a transparent logo
     * sits on the colour the app opens on rather than on black or on white.
     *
     * The size arrives from the query string, so it is taken from a whitelist rather than trusted — an
     * unbounded number there would be an unbounded allocation.
     *
     * Declared `void` for the same PHP 7 reason as `renderDynamicFavicon`: it writes the image and then
     * exits, so `never` would be a syntax error on anything older than 8.1.
     */
    public static function renderAppIcon(int $size): void
    {
        if (!in_array($size, [96, 128, 152, 167, 180, 192, 256, 384, 512], true)) {
            $size = 512;
        }

        $source = self::appIconSource();

        if (function_exists('imagecreatetruecolor')) {
            $logo = null;
            if ($source !== null) {
                // imagecreatefromstring sniffs the format, so WebP support is whatever GD has.
                $data = @file_get_contents($source);
                if ($data !== false) {
                    $logo = @imagecreatefromstring($data);
                }
            }

            $canvas = imagecreatetruecolor($size, $size);
            if ($canvas !== false) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $bg = imagecolorallocate($canvas, 0x0a, 0x09, 0x12);
                imagefilledrectangle($canvas, 0, 0, $size, $size, $bg);
                imagealphablending($canvas, true);

                if ($logo instanceof GdImage) {
                    $srcW = imagesx($logo);
                    $srcH = imagesy($logo);
                    if ($srcW > 0 && $srcH > 0) {
                        $scale = min($size / $srcW, $size / $srcH);
                        $dstW = max(1, (int) round($srcW * $scale));
                        $dstH = max(1, (int) round($srcH * $scale));
                        imagecopyresampled(
                            $canvas,
                            $logo,
                            (int) floor(($size - $dstW) / 2),
                            (int) floor(($size - $dstH) / 2),
                            0,
                            0,
                            $dstW,
                            $dstH,
                            $srcW,
                            $srcH
                        );
                    }
                    imagedestroy($logo);
                } else {
                    self::paintLetterMark($canvas, $size);
                }

                header('Content-Type: image/png');
                header('Cache-Control: public, max-age=86400');
                imagepng($canvas);
                imagedestroy($canvas);
                exit;
            }
        }

        // No GD (or no canvas): hand back the uploaded file untouched when there is one — it is still
        // the church's own image — and otherwise the generated letter tile. No path here can serve the
        // artwork shipped with the code, which belongs to a different church.
        if ($source !== null) {
            $info = @getimagesize($source);
            header('Content-Type: ' . ($info['mime'] ?? 'application/octet-stream'));
            header('Cache-Control: public, max-age=86400');
            readfile($source);
            exit;
        }

        self::renderDynamicFavicon((string) setting('site_title', 'C'));
    }

    /**
     * The uploaded logo, else the uploaded favicon, else null.
     *
     * Only a file that actually exists counts: a path in the database with nothing behind it (a restore
     * that missed the uploads folder, say) must not become a broken icon, so it falls through to the
     * letter tile instead.
     */
    private static function appIconSource(): ?string
    {
        foreach (['logo_path', 'favicon_path'] as $key) {
            $file = trim((string) (setting($key) ?? ''));
            if ($file !== '' && is_file(UPLOADS_PATH . '/' . $file)) {
                return UPLOADS_PATH . '/' . $file;
            }
        }
        return null;
    }

    /** The church's initial in the site's gold-on-dark, centred — used when nothing has been uploaded. */
    private static function paintLetterMark(GdImage $canvas, int $size): void
    {
        $letter = mb_strtoupper(mb_substr(trim((string) setting('site_title', 'C')) ?: 'C', 0, 1));
        $gold = imagecolorallocate($canvas, 0xe8, 0xb9, 0x5f);

        // A real font when the server has one, which is the normal case — the same probe the share cards
        // use, so the icon and the cards pick the same typeface rather than drifting apart.
        $font = class_exists('ShareCard') ? ShareCard::fontPath(true) : null;
        if ($font !== null && function_exists('imagettftext')) {
            $pt = $size * 0.5;
            $box = @imagettfbbox($pt, 0, $font, $letter);
            if (is_array($box)) {
                $width = abs($box[4] - $box[0]);
                $height = abs($box[5] - $box[1]);
                $x = (int) round(($size - $width) / 2 - $box[0]);
                $y = (int) round(($size - $height) / 2 - $box[1]);
                imagettftext($canvas, $pt, 0, $x, $y, $gold, $font, $letter);
                return;
            }
        }

        // No TrueType font: draw the glyph with GD's built-in bitmap font onto a small tile and scale it
        // up. Blocky, but visible and correct, which is what this fallback is for.
        $tile = imagecreatetruecolor(24, 24);
        if ($tile === false) {
            return;
        }
        imagealphablending($tile, false);
        imagesavealpha($tile, true);
        imagefilledrectangle($tile, 0, 0, 24, 24, imagecolorallocatealpha($tile, 0, 0, 0, 127));
        imagealphablending($tile, true);
        $tw = imagefontwidth(5) * strlen($letter);
        imagestring($tile, 5, (int) ((24 - $tw) / 2), (int) ((24 - imagefontheight(5)) / 2), $letter, imagecolorallocate($tile, 0xe8, 0xb9, 0x5f));
        $half = (int) round($size * 0.5);
        imagecopyresampled($canvas, $tile, (int) round(($size - $half) / 2), (int) round(($size - $half) / 2), 0, 0, $half, $half, 24, 24);
        imagedestroy($tile);
    }
}
