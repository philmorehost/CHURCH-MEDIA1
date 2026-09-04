<?php
declare(strict_types=1);

/**
 * Bible API Handler
 * Handles requests for scripture and switches between providers based on admin settings.
 */

header('Content-Type: application/json');

// 1. Load Settings
$pdo = Database::getInstance()->getConnection();
$row = $pdo->query('SELECT * FROM settings ORDER BY id ASC LIMIT 1')->fetch();
$source = $row['bible_source'] ?? 'keyless';
$apiKey = trim((string) ($row['bible_api_key'] ?? ''));

// 2. Get Parameters
$book = trim((string) ($_GET['book'] ?? ''));
$chapter = trim((string) ($_GET['chapter'] ?? ''));
$verse = trim((string) ($_GET['verse'] ?? '')); // Optional: specific verse within the chapter
$version = strtoupper(trim((string) ($_GET['version'] ?? 'KJV')));
$lang = strtolower(trim((string) ($_GET['lang'] ?? 'en')));

if ($book === '' || $chapter === '' || !ctype_digit($chapter)) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid book and chapter are required.']);
    exit;
}

// Bible text changes ~never, so we can cache aggressively. This header lets the
// browser/CDN serve repeat reads without hitting PHP at all (5 min), and the
// file cache below makes even brand-new sessions read straight from disk.
header('Cache-Control: public, max-age=300');

$cacheKey = implode('|', [$source, $version, $lang, $book, $chapter, $verse]);
$cached = bibleCacheGet($cacheKey);
if ($cached !== null) {
    echo $cached;
    exit;
}

try {
    if ($source === 'api_bible' && $apiKey !== '') {
        $payload = fetchApiBible($apiKey, $book, (int) $chapter, $verse, $version, $lang);
    } else {
        $payload = fetchKeyless($book, (int) $chapter, $verse, $version, $lang);
    }
    $body = json_encode($payload);
    bibleCacheSet($cacheKey, $body, 7 * 86400); // keep for 7 days
    echo $body;
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
}

function respond(array $payload): void
{
    echo json_encode($payload);
    exit;
}

/** Directory for the Bible response cache (storage/cache/bible). */
function bibleCacheDir(): string
{
    $dir = STORAGE_PATH . '/cache/bible';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** Read a cached payload (JSON body) for the given key, or null when stale/missing. */
function bibleCacheGet(string $key): ?string
{
    $path = bibleCacheDir() . '/' . md5($key) . '.json';
    if (!is_file($path)) {
        return null;
    }
    $data = @json_decode((string) @file_get_contents($path), true);
    if (!is_array($data) || !isset($data['expires'], $data['body'])) {
        return null;
    }
    if ((int) $data['expires'] < time()) {
        @unlink($path);
        return null;
    }
    return (string) $data['body'];
}

/** Store a payload body under the given key with a TTL in seconds. */
function bibleCacheSet(string $key, string $body, int $ttl): void
{
    $payload = json_encode(['expires' => time() + $ttl, 'body' => $body]);
    @file_put_contents(bibleCacheDir() . '/' . md5($key) . '.json', $payload, LOCK_EX);
}

/** Minimal HTTP GET with cURL (or stream fallback). Throws on failure. */
function httpRequest(string $url, array $headers = []): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            throw new RuntimeException('Bible provider returned HTTP ' . $code . ($err ? ' (' . $err . ')' : ''));
        }
        return (string) $body;
    }

    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => implode("\r\n", $headers) . "\r\n",
        'timeout' => 20,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('Bible provider could not be reached.');
    }
    return (string) $body;
}

/* ---------------------------------------------------------------------------
 * KEY-LESS provider (bible-api.com) — public-domain translations only
 * (KJV, WEB, BBE, etc.). No API key required.
 * ------------------------------------------------------------------------- */
function fetchKeyless(string $book, int $chapter, string $verse, string $version, string $lang): array
{
    // bible-api.com only carries public-domain texts; the Nigerian languages are
    // not available here and need the API.Bible provider.
    $langNames = ['yo' => 'Yorùbá', 'ig' => 'Igbo', 'ha' => 'Hausa'];
    if (isset($langNames[$lang])) {
        throw new RuntimeException('The ' . $langNames[$lang] . ' Bible is only available through the API.Bible provider. Please enable API.Bible in Admin → Settings.');
    }

    $query = $book . ' ' . $chapter;
    if ($verse !== '' && ctype_digit($verse)) {
        $query .= ':' . $verse;
    }

    $translation = 'web';
    if ($lang === 'es') {
        $translation = 'rvr1960'; // Spanish Reina-Valera 1960 (only Spanish option here)
    } else {
        $translation = match ($version) {
            'KJV' => 'kjv',
            'WEB' => 'web',
            'BBE' => 'bbe',
            'YLT' => 'ylt',
            'ASV' => 'asv',
            'DARBY' => 'darby',
            default => 'web',
        };
    }

    $url = 'https://bible-api.com/' . rawurlencode($query) . '?translation=' . $translation;
    $decoded = json_decode(httpRequest($url), true);

    $verses = [];
    foreach (($decoded['verses'] ?? []) as $v) {
        if (isset($v['verse'], $v['text'])) {
            $verses[] = ['verse' => (string) $v['verse'], 'text' => $v['text']];
        }
    }

    return [
        'provider'    => 'keyless',
        'reference'   => $decoded['reference'] ?? ($book . ' ' . $chapter),
        'translation' => $decoded['translation_name'] ?? $translation,
        'verses'      => $verses,
        'copyright'   => 'Public domain',
    ];
}

/* ---------------------------------------------------------------------------
 * API.BIBLE provider (scripture.api.bible) — NIV / NLT / NKJV etc.
 * Resolves the bible ID dynamically from the account, so any version granted
 * to the free tier works, and maps to the requested language when available.
 * ------------------------------------------------------------------------- */
function fetchApiBible(string $apiKey, string $book, int $chapter, string $verse, string $version, string $lang): array
{
    $headers = ['Authorization: Bearer ' . $apiKey];

    $bibleId = apiBibleResolveId($apiKey, $version, $lang, $headers);
    if ($bibleId === null) {
        throw new RuntimeException("No matching '" . $version . "' Bible found for the configured API.Bible account.");
    }

    $bookId = bookToOsisId($book);
    if ($bookId === null) {
        throw new RuntimeException("Unknown book: '" . $book . "'");
    }

    $base = 'https://api.scripture.api.bible/v1/bibles/' . $bibleId;

    if ($verse !== '' && ctype_digit($verse)) {
        $endpoint = $base . '/verses/' . $bookId . '.' . $chapter . '.' . $verse;
    } else {
        $endpoint = $base . '/chapters/' . $bookId . '.' . $chapter;
    }

    $url = $endpoint . '?content-type=json&include-verse-numbers=true';
    $decoded = json_decode(httpRequest($url, $headers), true);
    $data = $decoded['data'] ?? [];

    $verses = [];
    if (isset($data['content'])) {
        $nodes = json_decode((string) $data['content'], true);
        if (is_array($nodes)) {
            extractApiBibleVerses($nodes, $verses);
        }
    }

    return [
        'provider'    => 'api_bible',
        'reference'   => $data['reference'] ?? ($book . ' ' . $chapter),
        'translation' => $data['abbreviation'] ?? $version,
        'verses'      => $verses,
        'copyright'   => $data['copyright'] ?? '',
    ];
}

/** Fetch the account's Bible list and pick the best match for version + language. */
function apiBibleResolveId(string $apiKey, string $version, string $lang, array $headers): ?string
{
    // Resolving hits the /bibles endpoint, so cache the result per account+version
    // for 24h — removes a whole round-trip from every chapter fetch after the first.
    $cacheKey = 'bibles|' . md5($apiKey) . '|' . $version . '|' . $lang;
    $cached = bibleCacheGet($cacheKey);
    if ($cached !== null) {
        return $cached === '' ? null : $cached;
    }

    $decoded = json_decode(httpRequest('https://api.scripture.api.bible/v1/bibles', $headers), true);
    $bibles = $decoded['data'] ?? [];
    $id = null;

    if (is_array($bibles) && $bibles !== []) {
        // ISO 639-3 codes (API.Bible's language ids). Nigerian languages included.
        $langMap = [
            'en' => 'eng', 'es' => 'spa', 'fr' => 'fra', 'de' => 'deu', 'pt' => 'por',
            'it' => 'ita', 'ru' => 'rus', 'yo' => 'yor', 'ig' => 'ibo', 'ha' => 'hau',
        ];
        $langId = $langMap[$lang] ?? 'eng';

        // 1) Exact version + language match
        foreach ($bibles as $b) {
            if (strcasecmp((string) ($b['abbreviation'] ?? ''), $version) === 0 && ($b['language']['id'] ?? '') === $langId) {
                $id = $b['id'] ?? null;
                break;
            }
        }
        // 2) Any bible in the requested language (language takes priority over version)
        if ($id === null) {
            foreach ($bibles as $b) {
                if (($b['language']['id'] ?? '') === $langId) {
                    $id = $b['id'] ?? null;
                    break;
                }
            }
        }
        // 3) Exact version match in any language
        if ($id === null) {
            foreach ($bibles as $b) {
                if (strcasecmp((string) ($b['abbreviation'] ?? ''), $version) === 0) {
                    $id = $b['id'] ?? null;
                    break;
                }
            }
        }
        // 4) First available bible
        if ($id === null) {
            $id = $bibles[0]['id'] ?? null;
        }
    }

    bibleCacheSet($cacheKey, (string) ($id ?? ''), 24 * 3600);
    return $id;
}

/** Recursively walk API.Bible's JSON content tree and collect verses in order. */
function extractApiBibleVerses(array $nodes, array &$verses): void
{
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $type = (string) ($node['type'] ?? '');
        if ($type === 'verse') {
            $text = collectApiBibleText($node['items'] ?? []);
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            if ($text !== '') {
                $verses[] = ['verse' => (string) ($node['number'] ?? count($verses) + 1), 'text' => $text];
            }
        } else {
            extractApiBibleVerses($node['items'] ?? [], $verses);
        }
    }
}

function collectApiBibleText(array $items): string
{
    $text = '';
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = (string) ($item['type'] ?? '');
        if ($type === 'text') {
            $text .= (string) ($item['text'] ?? '');
        } elseif ($type === 'verse') {
            $text .= collectApiBibleText($item['items'] ?? []);
        } elseif ($type === 'note') {
            continue; // footnotes aren't verse text
        } else {
            $text .= collectApiBibleText($item['items'] ?? []);
        }
    }
    return $text;
}

/** Map a full book name ("Genesis", "1 John") to its API.Bible OSIS book ID. */
function bookToOsisId(string $book): ?string
{
    $map = [
        'genesis' => 'GEN', 'exodus' => 'EXO', 'leviticus' => 'LEV', 'numbers' => 'NUM', 'deuteronomy' => 'DEU',
        'joshua' => 'JOS', 'judges' => 'JDG', 'ruth' => 'RUT', '1 samuel' => '1SA', '2 samuel' => '2SA',
        '1 kings' => '1KI', '2 kings' => '2KI', '1 chronicles' => '1CH', '2 chronicles' => '2CH',
        'ezra' => 'EZR', 'nehemiah' => 'NEH', 'esther' => 'EST', 'job' => 'JOB', 'psalms' => 'PSA', 'psalm' => 'PSA',
        'proverbs' => 'PRO', 'ecclesiastes' => 'ECC', 'song of solomon' => 'SNG', 'song of songs' => 'SNG', 'songs' => 'SNG',
        'isaiah' => 'ISA', 'jeremiah' => 'JER', 'lamentations' => 'LAM', 'ezekiel' => 'EZK', 'daniel' => 'DAN',
        'hosea' => 'HOS', 'joel' => 'JOL', 'amos' => 'AMO', 'obadiah' => 'OBA', 'jonah' => 'JON', 'micah' => 'MIC',
        'nahum' => 'NAM', 'habakkuk' => 'HAB', 'zephaniah' => 'ZEP', 'haggai' => 'HAG', 'zechariah' => 'ZEC',
        'malachi' => 'MAL', 'matthew' => 'MAT', 'mark' => 'MRK', 'luke' => 'LUK', 'john' => 'JHN', 'acts' => 'ACT',
        'romans' => 'ROM', '1 corinthians' => '1CO', '2 corinthians' => '2CO', 'galatians' => 'GAL', 'ephesians' => 'EPH',
        'philippians' => 'PHP', 'colossians' => 'COL', '1 thessalonians' => '1TH', '2 thessalonians' => '2TH',
        '1 timothy' => '1TI', '2 timothy' => '2TI', 'titus' => 'TIT', 'philemon' => 'PHM', 'hebrews' => 'HEB',
        'james' => 'JAS', '1 peter' => '1PE', '2 peter' => '2PE', '1 john' => '1JN', '2 john' => '2JN', '3 john' => '3JN',
        'jude' => 'JUD', 'revelation' => 'REV', 'revelations' => 'REV',
    ];
    return $map[strtolower(trim($book))] ?? null;
}

