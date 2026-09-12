<?php
declare(strict_types=1);

/**
 * Generated social share cards.
 *
 * Builds a 1200×630 PNG for a sermon, event, reel or testimony so a link shared
 * into WhatsApp or Facebook shows a proper preview rather than a bare URL. Cards
 * are generated once and cached on disk, keyed by a hash of the content, so
 * editing a title produces a new file automatically.
 *
 * Safety and robustness:
 *  - Only whitelisted content types can be rendered, and only rows that are
 *    published — the query string can never be used to draw arbitrary text.
 *  - Every image operation is guarded: no GD, no FreeType, an unreadable cover or
 *    an unsupported format all fall back to a simpler card rather than an error.
 *  - The card is purely decorative, so callers treat a null return as "use the
 *    plain logo instead" and never surface a failure to a visitor.
 */
final class ShareCard
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;

    /** Entity types a card can be generated for. Anything else is refused. */
    public const TYPES = ['sermon', 'event', 'post', 'testimony'];

    /** Hard ceiling on cached cards, oldest evicted first. 0 disables pruning. */
    private const MAX_CACHE_FILES = 4000;

    /** Brand colours, mirroring the site palette. */
    private const BG = [10, 9, 18];
    private const GOLD = [232, 185, 95];
    private const INK = [255, 255, 255];
    private const INK_DIM = [220, 216, 242];

    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /** True when we can draw nice type, rather than only being able to draw shapes. */
    public static function hasType(): bool
    {
        return self::fontPath(false) !== null;
    }

    /**
     * The first usable TrueType font on this server, or null.
     *
     * Hosting varies wildly — a cPanel box has DejaVu, Windows has Arial — so a
     * list of plausible paths is probed and the win is remembered per request.
     */
    public static function fontPath(bool $bold = false): ?string
    {
        static $resolved = [];
        $key = $bold ? 'bold' : 'regular';
        if (array_key_exists($key, $resolved)) {
            return $resolved[$key];
        }

        $override = (string) setting('og_font_path', '');
        $candidates = [];
        if ($override !== '') {
            $candidates[] = $bold ? $override : preg_replace('/-Bold(?=\.ttf$)/i', '', $override);
            $candidates[] = $override;
        }
        if ($bold) {
            array_push($candidates,
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
                '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
                '/Library/Fonts/Arial Bold.ttf',
                'C:\\Windows\\Fonts\\arialbd.ttf',
                'C:\\Windows\\Fonts\\segoeuib.ttf'
            );
        } else {
            array_push($candidates,
                preg_replace('/-Bold(?=\.ttf$)/i', '', (string) setting('og_font_path', '')) ?: '',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
                '/usr/share/fonts/liberation/LiberationSans-Regular.ttf',
                '/Library/Fonts/Arial.ttf',
                'C:\\Windows\\Fonts\\arial.ttf',
                'C:\\Windows\\Fonts\\segoeui.ttf'
            );
        }

        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && is_file($path) && is_readable($path)) {
                return $resolved[$key] = $path;
            }
        }
        return $resolved[$key] = null;
    }

    /** Public URL for a card, used for the og:image tag. */
    public static function urlFor(string $type, int $id, ?string $slug = null): string
    {
        $query = ['type' => $type, 'id' => $id];
        if ($slug !== null && $slug !== '') {
            $query['slug'] = $slug;
        }
        return '/api/og?' . http_build_query($query);
    }

    /** Where generated cards are cached. */
    public static function cacheDir(): string
    {
        $dir = STORAGE_PATH . '/cache/og';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Normalises a published row into the shape the renderer wants.
     *
     * @return array{type:string,id:int,title:string,subtitle:string,eyebrow:string,cover:?string,url:string}|null
     */
    public static function resolve(string $type, ?int $id = null, ?string $slug = null): ?array
    {
        if (!in_array($type, self::TYPES, true)) {
            return null;
        }

        try {
            $pdo = self::db();
        } catch (Throwable $e) {
            return null;
        }

        try {
            switch ($type) {
                case 'sermon':
                    $row = self::find($pdo, 'sermons',
                        'id, title, slug, speaker, scripture_ref, cover_image, published_at',
                        'is_published = 1', $id, $slug);
                    if (!$row) {
                        return null;
                    }
                    $bits = array_filter([
                        $row['speaker'] ?: null,
                        $row['scripture_ref'] ?: null,
                        !empty($row['published_at']) ? date('F j, Y', (int) strtotime((string) $row['published_at'])) : null,
                    ]);
                    return self::compose('sermon', 'Sermon', (int) $row['id'], (string) $row['title'],
                        $row['cover_image'] ?? null, $bits, '/sermons/' . $row['slug']);

                case 'event':
                    $row = self::find($pdo, 'events',
                        'id, title, slug, start_at, location, cover_image',
                        'is_published = 1', $id, $slug);
                    if (!$row) {
                        return null;
                    }
                    $bits = array_filter([
                        !empty($row['start_at']) ? date('l, F j, Y · g:i A', (int) strtotime((string) $row['start_at'])) : null,
                        $row['location'] ?: null,
                    ]);
                    return self::compose('event', 'Upcoming Event', (int) $row['id'], (string) $row['title'],
                        $row['cover_image'] ?? null, $bits, '/events/' . $row['slug']);

                case 'testimony':
                    $row = self::find($pdo, 'testimonies',
                        'id, title, name, media_url, submitted_at',
                        "status = 'approved'", $id, null, 'id');
                    if (!$row) {
                        return null;
                    }
                    $bits = array_filter([
                        $row['name'] ? 'Shared by ' . $row['name'] : null,
                        !empty($row['submitted_at']) ? date('F j, Y', (int) strtotime((string) $row['submitted_at'])) : null,
                    ]);
                    return self::compose('testimony', 'Testimony', (int) $row['id'], (string) $row['title'],
                        $row['media_url'] ?? null, $bits, '/testimonies');

                case 'post':
                    $row = self::find($pdo, 'media_posts',
                        'id, caption, slug, post_type, created_at',
                        'is_published = 1', $id, $slug);
                    if (!$row) {
                        return null;
                    }
                    $cover = null;
                    $thumb = $pdo->prepare('SELECT thumbnail_path, file_path, type FROM media_post_items WHERE media_post_id = ? ORDER BY sort_order ASC LIMIT 1');
                    $thumb->execute([(int) $row['id']]);
                    if ($item = $thumb->fetch()) {
                        $cover = $item['thumbnail_path'] ?: ($item['type'] === 'image' ? $item['file_path'] : null);
                    }
                    $caption = trim((string) ($row['caption'] ?? ''));
                    $eyebrow = $row['post_type'] === 'vertical_reel' ? 'Reel' : 'From the feed';
                    return self::compose('post', $eyebrow, (int) $row['id'],
                        $caption !== '' ? $caption : (string) setting('site_title'),
                        $cover,
                        array_filter([!empty($row['created_at']) ? date('F j, Y', (int) strtotime((string) $row['created_at'])) : null]),
                        '/feed');
            }
        } catch (Throwable $e) {
            error_log('ShareCard resolve failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Generates the card if it is not already cached.
     *
     * @return string|null Absolute path to the PNG, or null when it cannot be drawn.
     */
    public static function generate(array $entity): ?string
    {
        if (!self::available()) {
            return null;
        }

        $file = self::cacheDir() . '/' . self::cacheKey($entity) . '.png';
        if (is_file($file) && filesize($file) > 0) {
            return $file;
        }

        try {
            $image = self::render($entity);
        } catch (Throwable $e) {
            error_log('ShareCard render failed: ' . $e->getMessage());
            return null;
        }
        if (!$image) {
            return null;
        }

        // Write to a temporary name then rename, so two concurrent requests can
        // never serve a half-written PNG.
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = imagepng($image, $tmp, 6);
        imagedestroy($image);

        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);
            return null;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return is_file($file) ? $file : null;
        }

        self::prune($file);
        return $file;
    }

    /**
     * Keeps the card cache from growing without bound.
     *
     * Editing a sermon title mints a brand-new filename, so without this a church
     * that edits often would slowly fill its disk. Deleting a card is harmless:
     * it is regenerated on the next request.
     */
    private static function prune(string $keepFile): void
    {
        // Superseded versions of the same item — filenames start "<type>-<id>-".
        if (preg_match('#^([a-z0-9]+-\d+)-[0-9a-f]{16}\.png$#i', basename($keepFile), $m)) {
            foreach (glob(self::cacheDir() . '/' . $m[1] . '-*.png') ?: [] as $file) {
                if ($file !== $keepFile) {
                    @unlink($file);
                }
            }
        }

        // A hard ceiling on the whole directory, oldest first. The settings table
        // is a single wide row, so this is a constant rather than a setting.
        if (self::MAX_CACHE_FILES <= 0) {
            return;
        }
        $files = glob(self::cacheDir() . '/*.png') ?: [];
        if (count($files) <= self::MAX_CACHE_FILES) {
            return;
        }
        usort($files, static fn(string $a, string $b): int => (int) filemtime($a) <=> (int) filemtime($b));
        foreach (array_slice($files, 0, count($files) - self::MAX_CACHE_FILES) as $file) {
            if ($file !== $keepFile) {
                @unlink($file);
            }
        }
    }

    /** Removes cached cards for one item (used when content changes). */
    public static function forget(string $type, int $id): void
    {
        $pattern = self::cacheDir() . '/' . preg_replace('/[^a-z0-9]/i', '', $type) . '-' . $id . '-*.png';
        foreach (glob($pattern) ?: [] as $file) {
            @unlink($file);
        }
    }

    /** Cache key: content changes produce a new file automatically. */
    private static function cacheKey(array $entity): string
    {
        $fingerprint = implode('|', [
            (string) $entity['type'],
            (string) $entity['id'],
            (string) $entity['title'],
            (string) $entity['subtitle'],
            (string) $entity['eyebrow'],
            (string) ($entity['cover'] ?? ''),
            (string) setting('site_title'),
            (string) setting('og_card_version', '1'),
            self::hasType() ? 'type' : 'notype',
        ]);
        return preg_replace('/[^a-z0-9]/i', '', (string) $entity['type'])
            . '-' . (int) $entity['id'] . '-' . substr(hash('sha256', $fingerprint), 0, 16);
    }

    /* ---------------- rendering ---------------- */

    private static function render(array $entity): ?GdImage
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (!$image) {
            return null;
        }
        imagealphablending($image, true);
        imagesavealpha($image, true);
        imageantialias($image, true);

        $drewCover = self::paintCover($image, $entity['cover'] ?? null);
        if (!$drewCover) {
            self::paintBrandBackground($image);
        }

        // Scrim so white text stays readable over a photograph. The plain branded
        // background is already dark, so it needs only a light touch.
        self::paintScrim($image, self::HEIGHT - 300, 300, $drewCover ? 96 : 60);

        self::paintText($image, $entity);

        return $image;
    }

    /** Fills the canvas with the site's dark branded gradient plus a gold glow. */
    private static function paintBrandBackground(GdImage $image): void
    {
        for ($y = 0; $y < self::HEIGHT; $y++) {
            $t = $y / (self::HEIGHT - 1);
            $r = (int) round(self::BG[0] + (26 - self::BG[0]) * $t);
            $g = (int) round(self::BG[1] + (20 - self::BG[1]) * $t);
            $b = (int) round(self::BG[2] + (52 - self::BG[2]) * $t);
            $colour = imagecolorallocate($image, $r, $g, $b);
            imagefilledrectangle($image, 0, $y, self::WIDTH, $y, $colour);
        }

        // A soft gold wedge in the top-right, drawn as translucent bands so no
        // imagefilter or alpha maths is needed.
        for ($i = 0; $i < 90; $i++) {
            $colour = imagecolorallocatealpha($image, self::GOLD[0], self::GOLD[1], self::GOLD[2], 124 - (int) round($i * 1.1));
            imagefilledpolygon($image, [
                self::WIDTH - 420 + $i * 2, 0,
                self::WIDTH, 0,
                self::WIDTH, 220 + $i * 2,
            ], $colour);
        }

        // A large monogram, so a card with no photograph still looks deliberate.
        if (self::hasType()) {
            $initial = mb_strtoupper(mb_substr((string) setting('site_title'), 0, 1));
            $font = (string) self::fontPath(true);
            $colour = imagecolorallocatealpha($image, self::GOLD[0], self::GOLD[1], self::GOLD[2], 96);
            imagettftext($image, 260, 0, self::WIDTH - 470, 400, $colour, $font, $initial);
        }
    }

    /** Cover-crops the entity's image to fill the card. False when unavailable. */
    private static function paintCover(GdImage $image, ?string $cover): bool
    {
        if ($cover === null || trim($cover) === '') {
            return false;
        }
        $path = self::imagePath($cover);
        if ($path === null) {
            return false;
        }

        // imagecreatefromstring sniffs the format, so WebP support is optional.
        $src = @imagecreatefromstring((string) @file_get_contents($path));
        if (!$src) {
            return false;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($src);
            return false;
        }

        $targetRatio = self::WIDTH / self::HEIGHT;
        $srcRatio = $srcW / $srcH;
        if ($srcRatio > $targetRatio) {
            $cropH = $srcH;
            $cropW = (int) round($srcH * $targetRatio);
            $cropX = (int) round(($srcW - $cropW) / 2);
            $cropY = 0;
        } else {
            $cropW = $srcW;
            $cropH = (int) round($srcW / $targetRatio);
            $cropX = 0;
            // Bias the crop towards the top, where faces usually are.
            $cropY = (int) round(($srcH - $cropH) * 0.3);
        }

        imagecopyresampled($image, $src, 0, 0, $cropX, $cropY, self::WIDTH, self::HEIGHT, $cropW, $cropH);
        imagedestroy($src);
        return true;
    }

    /** Draws a translucent band, fading in as it goes down. */
    private static function paintScrim(GdImage $image, int $y, int $height, int $maxAlpha): void
    {
        for ($i = 0; $i < $height; $i++) {
            $progress = $height > 1 ? $i / ($height - 1) : 1;
            $alpha = 127 - (int) round($progress * $maxAlpha);
            $colour = imagecolorallocatealpha($image, 6, 5, 12, max(0, min(127, $alpha)));
            imagefilledrectangle($image, 0, $y + $i, self::WIDTH, $y + $i, $colour);
        }
    }

    private static function paintText(GdImage $image, array $entity): void
    {
        $margin = 72;
        $maxWidth = self::WIDTH - $margin * 2;
        $baselineBottom = self::HEIGHT - 84;

        $hasType = self::hasType();

        // --- footer: site name, always present ---
        $footY = self::HEIGHT - 62;
        if ($hasType) {
            $colour = imagecolorallocate($image, self::GOLD[0], self::GOLD[1], self::GOLD[2]);
            imagettftext($image, 20, 0, $margin, $footY, $colour, (string) self::fontPath(true), mb_strtoupper(mb_substr((string) setting('site_title'), 0, 46)));

            $host = self::host();
            if ($host !== '') {
                $colour = imagecolorallocate($image, self::INK_DIM[0], self::INK_DIM[1], self::INK_DIM[2]);
                $box = imagettfbbox(18, 0, (string) self::fontPath(false), $host);
                $width = $box ? abs($box[4] - $box[0]) : 0;
                imagettftext($image, 18, 0, self::WIDTH - $margin - $width, $footY, $colour, (string) self::fontPath(false), $host);
            }
        }

        $bottom = $hasType ? $footY - 52 : $baselineBottom;
        $cursor = $bottom;

        // --- subtitle (date, speaker, location) ---
        $subtitleLines = [];
        if ($hasType && $entity['subtitle'] !== '') {
            $subtitleLines = self::wrap($entity['subtitle'], 24, (string) self::fontPath(false), $maxWidth, 1);
            $cursor -= 34;
            $colour = imagecolorallocate($image, self::INK_DIM[0], self::INK_DIM[1], self::INK_DIM[2]);
            foreach ($subtitleLines as $line) {
                imagettftext($image, 24, 0, $margin, $cursor + 24, $colour, (string) self::fontPath(false), $line);
            }
            $cursor -= 18;
        }

        // --- title, auto-shrinking until it fits in three lines ---
        if ($hasType && $entity['title'] !== '') {
            $font = (string) self::fontPath(true);
            $lines = [];
            foreach ([64, 58, 52, 46, 40, 36] as $size) {
                $lines = self::wrap($entity['title'], $size, $font, $maxWidth, 3);
                if (count($lines) <= 3) {
                    $lineHeight = (int) round($size * 1.24);
                    $blockHeight = $lineHeight * count($lines);
                    if ($blockHeight <= $cursor - 150) {
                        break;
                    }
                }
            }
            $size = $size ?? 52;
            $lineHeight = (int) round($size * 1.24);
            $totalHeight = $lineHeight * count($lines);
            $top = $cursor - $totalHeight;
            $colour = imagecolorallocate($image, self::INK[0], self::INK[1], self::INK[2]);
            foreach ($lines as $i => $line) {
                imagettftext($image, $size, 0, $margin, $top + ($i + 1) * $lineHeight - (int) round($lineHeight * 0.26), $colour, $font, $line);
            }
            $cursor = $top;
        }

        // --- eyebrow tab, top-left ---
        if ($hasType && $entity['eyebrow'] !== '') {
            $font = (string) self::fontPath(true);
            $size = 20;
            $text = mb_strtoupper($entity['eyebrow']);
            $box = imagettfbbox($size, 0, $font, $text);
            $textWidth = $box ? abs($box[4] - $box[0]) : 0;
            $pad = 18;
            $tabW = $textWidth + $pad * 2;
            $tabH = 46;

            // Solid gold tab with dark ink on it: gold-on-gold is unreadable, and
            // white-on-gold is barely better.
            $fill = imagecolorallocate($image, self::GOLD[0], self::GOLD[1], self::GOLD[2]);
            imagefilledrectangle($image, $margin, 64, $margin + $tabW, 64 + $tabH, $fill);
            $ink = imagecolorallocate($image, self::BG[0] + 4, self::BG[1] + 4, self::BG[2] + 4);
            imagettftext($image, $size, 0, $margin + $pad, 64 + $tabH - 15, $ink, $font, $text);
        }
    }

    /**
     * Greedy word wrap that returns at most $maxLines, ellipsising the last line.
     *
     * @return string[]
     */
    private static function wrap(string $text, float $size, string $font, int $maxWidth, int $maxLines): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return [];
        }

        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (self::textWidth($candidate, $size, $font) <= $maxWidth) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            // A single word longer than the line has to be cut to fit.
            while (self::textWidth($word, $size, $font) > $maxWidth && mb_strlen($word) > 1) {
                $word = mb_substr($word, 0, -1);
            }
            $current = $word;
            if (count($lines) === $maxLines) {
                break;
            }
        }
        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }

        // Anything left over is ellipsised rather than silently dropped.
        $rendered = implode(' ', $lines);
        if (mb_strlen($rendered) < mb_strlen($text) && $lines) {
            $last = array_pop($lines);
            while (mb_strlen($last) > 1 && self::textWidth($last . '…', $size, $font) > $maxWidth) {
                $last = mb_substr($last, 0, -1);
            }
            $lines[] = rtrim($last) . '…';
        }

        return array_slice($lines, 0, $maxLines);
    }

    private static function textWidth(string $text, float $size, string $font): int
    {
        if ($text === '') {
            return 0;
        }
        $box = imagettfbbox($size, 0, $font, $text);
        return $box ? (int) abs($box[4] - $box[0]) : 0;
    }

    private static function host(): string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        return $host === 'localhost' ? '' : $host;
    }

    /** Resolves a stored path (e.g. "webp/x.webp") to a readable absolute path. */
    private static function imagePath(string $stored): ?string
    {
        if (preg_match('#^(https?:)?//#i', $stored)) {
            return null; // Remote covers are not fetched, to avoid SSRF.
        }
        $path = UPLOADS_PATH . '/' . ltrim($stored, '/');
        return (is_file($path) && is_readable($path)) ? $path : null;
    }

    /** Shared shape for the renderer. */
    private static function compose(string $type, string $eyebrow, int $id, string $title, ?string $cover, array $bits, string $url): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'title' => trim($title),
            'subtitle' => trim(implode('  ·  ', array_map('strval', $bits))),
            'eyebrow' => $eyebrow,
            'cover' => $cover,
            'url' => $url,
        ];
    }

    /** Looks a row up by id or slug, always constrained to published content. */
    private static function find(PDO $pdo, string $table, string $columns, string $publishedWhere, ?int $id, ?string $slug, string $keyColumn = 'slug'): ?array
    {
        try {
            if ($id !== null && $id > 0) {
                $stmt = $pdo->prepare('SELECT ' . $columns . ' FROM `' . $table . '` WHERE id = ? AND ' . $publishedWhere . ' LIMIT 1');
                $stmt->execute([$id]);
            } elseif ($slug !== null && $slug !== '') {
                $stmt = $pdo->prepare('SELECT ' . $columns . ' FROM `' . $table . '` WHERE `' . $keyColumn . '` = ? AND ' . $publishedWhere . ' LIMIT 1');
                $stmt->execute([$slug]);
            } else {
                return null;
            }
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }
}
