#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Checks the language catalogues in `lang/` against each other.
 *
 * Why this exists: `Lang::translate()` resolves requested → English → the key itself, and it silently
 * drops a value that is empty or not a string. That is the right behaviour at runtime — a missing label
 * must never break a page — but it means three whole classes of mistake are invisible on the site:
 *
 *  - a key that exists only outside English, which renders as a dotted key (`nav.home`) for every English
 *    reader and is a bug rather than a gap;
 *  - a key mapped to an empty string or a number, which silently falls back to English, so the file looks
 *    translated and is not;
 *  - a catalogue file that cannot be read or parsed, or has no `__name`, so the switcher offers a language
 *    as `YO` or does not offer it at all;
 *  - a `t('…')` call anywhere in the code whose key English does not have, which renders as the key itself
 *    — `nav.hom` in the middle of a header — because a typo is indistinguishable from a missing
 *    translation at runtime. This is the check that makes translating a page at a time safe;
 *  - a screen that never calls `t()` at all, so every word in it is fixed English and *none* of the checks
 *    above can see it: it has no keys to be missing, no keys to typo and none unused. That is the shape of
 *    the remaining work, and this tool could not count it — it had to be measured by hand. It is reported
 *    below as **coverage**, deliberately as a notice rather than a problem, because a partial migration is
 *    the design and a screen added in English tomorrow is not a defect today.
 *
 * A **missing** translation is deliberately not an error. A partial catalogue is the design: a volunteer
 * can translate thirty strings and stop, and every unlisted key stays English. This tool lists what is
 * left instead of failing on it. A catalogue key that no `t('…')` call mentions is reported as a notice
 * rather than an error, because the call may be dynamic — and because a key added ahead of the screen that
 * will use it is a reasonable thing to do.
 *
 * Run it after adding or editing anything in `lang/`, after adding a `t()` call, and before installing for
 * a church in a language other than English.
 *
 *   php cli/lang_check.php            summary per catalogue, interface coverage, plus any bad or unused key
 *   php cli/lang_check.php --missing  also list every untranslated key, and every screen not yet wired
 *
 * Exit codes: 0 the catalogues are sound, 1 a catalogue or a `t()` call has a problem.
 */

if (!defined('STDERR')) {
    $errStream = @fopen('php://stderr', 'wb');
    define('STDERR', $errStream ?: fopen('php://output', 'wb'));
}
if (!defined('STDOUT')) {
    $outStream = @fopen('php://stdout', 'wb');
    define('STDOUT', $outStream ?: fopen('php://output', 'wb'));
}

require __DIR__ . '/../bootstrap.php';

$showMissing = in_array('--missing', $argv ?? [], true);

/** @var array<int, string> $problems */
$problems = [];
/** @var array<string, array<string, string>> $catalogues */
$catalogues = [];
/** @var array<string, array<string, mixed>> $metas */
$metas = [];

$files = glob(LANG_PATH . '/*.php') ?: [];

if (!$files) {
    fwrite(STDERR, 'No catalogues found in ' . LANG_PATH . PHP_EOL);
    exit(1);
}

foreach ($files as $file) {
    $code = strtolower(basename($file, '.php'));

    // A file whose name is not a usable code can never be selected, so nothing in it is ever shown.
    if (preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/', $code) !== 1) {
        $problems[] = $code . '.php is not a usable locale code — Lang::available() skips it, so nothing in it is ever used.';
        continue;
    }

    try {
        // A parse error is catchable here: `require` throws ParseError, which is a Throwable.
        $data = require $file;
    } catch (Throwable $e) {
        $problems[] = $code . '.php could not be loaded: ' . $e->getMessage();
        continue;
    }

    /*
     * A key written twice in one file is invisible to every check above, because PHP keeps only the last
     * of two identical array keys — the catalogue simply has one entry and the first value is gone. Over a
     * hundred keys typed by hand, that is the mistake worth a whole check: the file has to be read as text
     * to find it.
     */
    preg_match_all("/^[ \t]*'([^']+)'[ \t]*=>/m", (string) file_get_contents($file), $literals);
    $seen = [];
    foreach ($literals[1] as $literal) {
        if (isset($seen[$literal])) {
            $problems[] = $code . ".php defines '" . $literal . "' twice. PHP keeps only the last one, so the first value is dead text in the file.";
        }
        $seen[$literal] = true;
    }

    if (!is_array($data)) {
        $problems[] = $code . '.php must return an array.';
        continue;
    }

    $clean = [];
    $meta = [];
    foreach ($data as $key => $value) {
        if (!is_string($key)) {
            $problems[] = $code . '.php has a key that is not a string (' . gettype($key) . '), which cannot be reached.';
            continue;
        }

        /*
         * Keys beginning with `__` describe the file rather than saying something to a reader, so they are
         * not text and are exempt from every rule about text: `__offered` is a boolean on purpose. They are
         * still checked, for the shapes `Lang` knows about.
         */
        if (str_starts_with($key, '__')) {
            $meta[$key] = $value;
            if ($key === '__name' && (!is_string($value) || trim($value) === '')) {
                $problems[] = $code . '.php has no __name, so the switcher would offer it as ' . strtoupper($code) . '.';
            }
            if ($key === '__offered' && !is_bool($value)) {
                $problems[] = $code . '.php: __offered is ' . gettype($value) . ', not true or false. Anything but false counts as offered, so a mistyped value cannot hide a language.';
            }
            continue;
        }

        if (!is_string($value)) {
            $problems[] = $code . ".php: '" . $key . "' is " . gettype($value) . ', not a string — Lang drops it, so the key silently falls back to English.';
            continue;
        }
        if (trim($value) === '') {
            $problems[] = $code . ".php: '" . $key . "' is empty — Lang drops it, so the key silently falls back to English.";
            continue;
        }
        $clean[$key] = $value;
    }

    $catalogues[$code] = $clean;
    $metas[$code] = $meta;
}

$fallback = Lang::FALLBACK;
$english = $catalogues[$fallback] ?? null;

if ($english === null) {
    fwrite(STDERR, 'lang/' . $fallback . '.php is missing. It is the catalogue every other one falls back to.' . PHP_EOL);
    exit(1);
}

/** @var array<string, string> $englishKeys */
$englishKeys = $english;

/**
 * A key that only exists outside English renders as a dotted key for every English reader — the one case
 * where a gap is a defect rather than an untranslated string.
 */
foreach ($catalogues as $code => $keys) {
    if ($code === $fallback) {
        continue;
    }
    foreach (array_keys($keys) as $key) {
        if (!array_key_exists($key, $englishKeys)) {
            $problems[] = $code . ".php defines '" . $key . "', which " . $fallback . '.php does not. English readers would see that key itself.';
        }
    }
}

/**
 * Every `t('key')` in the code, against the keys English actually has.
 *
 * This is the check that keeps a page-at-a-time migration honest. At runtime a typo and a missing
 * translation look identical — both render the key — so a mistyped `t('nav.hom')` would ship as the word
 * `nav.hom` in the header, and nothing else in this tool would notice, because English is not *missing* a
 * key that was never spelled correctly anywhere.
 */
$scanRoots = ['views', 'admin', 'api', 'core'];
/** @var array<string, array<int, string>> $usage key => the places it is asked for */
$usage = [];
/** @var array<int, string> $dynamic places a key could not be read, so nothing can be checked */
$dynamic = [];
/** @var array<string, array<int, string>> $scanned php files per root, for the coverage report */
$scanned = [];
/** @var array<string, bool> $wired files that ask for at least one translation */
$wired = [];

foreach ($scanRoots as $dir) {
    $root = ROOT_PATH . '/' . $dir;
    if (!is_dir($root)) {
        continue;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', substr($file->getPathname(), strlen(ROOT_PATH) + 1));
        $scanned[$dir][] = $path;
        $lines = explode("\n", (string) file_get_contents($file->getPathname()));

        foreach ($lines as $number => $line) {
            /*
             * A literal single-quoted key, which is every call in this codebase by convention — and the
             * quote has to be the whole argument. Without the trailing `,` or `)` the pattern also matches
             * `t('nav.' . $key)` and registers a key called `nav.`, which then fails the "English has no
             * such key" check as though somebody had mistyped a key.
             */
            if (preg_match_all("/\\bt\\(\\s*'([^']+)'\\s*[,\\)]/u", $line, $hits) > 0) {
                $wired[$path] = true;
                foreach ($hits[1] as $key) {
                    $usage[$key][] = $path . ':' . ($number + 1);
                }
            }

            // A key built at runtime cannot be checked, and saying so is better than staying silent.
            // Single-quoted pattern: the alternative is three levels of escaping around the quote.
            if (preg_match('/\bt\(\s*(?:\$|\'[^\']*\'|\"[^\"]*\")\s*\./', $line) === 1) {
                $dynamic[] = $path . ':' . ($number + 1);
            }
        }
    }
}

ksort($usage);

foreach (array_keys($usage) as $key) {
    if (!array_key_exists($key, $englishKeys)) {
        $where = implode(', ', array_slice($usage[$key], 0, 3));
        $more = count($usage[$key]) > 3 ? ' and ' . (count($usage[$key]) - 3) . ' more' : '';
        $problems[] = "t('" . $key . "') is used at " . $where . $more . ', but ' . $fallback . ".php has no such key — it renders as the key itself.";
    }
}

$unused = array_keys(array_diff_key($englishKeys, $usage));

// ---------------------------------------------------------------- report

fwrite(STDOUT, 'Language catalogues — ' . LANG_PATH . PHP_EOL . PHP_EOL);

$codes = array_keys($catalogues);
sort($codes);

foreach ($codes as $code) {
    $keys = $catalogues[$code];
    $missing = array_diff_key($englishKeys, $keys);

    // Padded by characters, not bytes: "Yorùbá" is 8 bytes for 6 characters, so sprintf's %-14s would
    // leave the column ragged in exactly the entries most likely to be read.
    $name = (string) ($metas[$code]['__name'] ?? strtoupper($code));
    $pad = str_repeat(' ', max(1, 14 - mb_strlen($name)));

    $line = sprintf(
        '  %-6s %s%3d key%s',
        $code,
        $name . $pad,
        count($keys),
        count($keys) === 1 ? '' : 's'
    );

    if ($code === $fallback) {
        $line .= '   (the source catalogue)';
    } elseif (($metas[$code]['__offered'] ?? true) === false) {
        $line .= '   not offered to visitors yet';
    } elseif ($missing === []) {
        $line .= '   complete';
    } else {
        $line .= '   ' . count($missing) . ' untranslated, served in ' . $fallback;
    }

    fwrite(STDOUT, $line . PHP_EOL);

    if ($showMissing && $missing !== []) {
        foreach (array_keys($missing) as $key) {
            fwrite(STDOUT, '           - ' . $key . PHP_EOL);
        }
    }
}

fwrite(STDOUT, PHP_EOL);
fwrite(STDOUT, sprintf(
    'Keys asked for in code: %d of %d declared%s' . PHP_EOL,
    count(array_intersect_key($englishKeys, $usage)),
    count($englishKeys),
    $unused === [] ? '' : ', ' . count($unused) . ' unused'
));

if ($unused !== [] && $showMissing) {
    foreach ($unused as $key) {
        fwrite(STDOUT, '    unused: ' . $key . PHP_EOL);
    }
}

/*
 * Interface coverage — how much of the interface asks for a translation at all.
 *
 * Reported for `views/` and `admin/` only: they are where a reader's text lives. `api/` and `core/` are
 * scanned for keys above, but counting them as screens would report a backlog that is not one.
 *
 * Never a problem and never a non-zero exit. A file that does not call `t()` yet is the remaining work
 * stated plainly, not a fault — see the note in this file's header.
 */
$uiRoots = ['views', 'admin'];
$coverage = [];

foreach ($uiRoots as $dir) {
    $all = $scanned[$dir] ?? [];
    if ($all === []) {
        continue;
    }
    $done = array_filter($all, static fn (string $p): bool => isset($wired[$p]));
    $coverage[$dir] = [
        'total' => count($all),
        'done' => count($done),
        'todo' => array_values(array_diff($all, array_keys($done))),
    ];
}

if ($coverage !== []) {
    fwrite(STDOUT, PHP_EOL);
    fwrite(STDOUT, 'Interface coverage — screens that ask for a translation at all' . PHP_EOL);

    foreach ($coverage as $dir => $report) {
        fwrite(STDOUT, sprintf(
            '  %-6s %3d of %3d file(s)%s' . PHP_EOL,
            $dir,
            $report['done'],
            $report['total'],
            $report['todo'] === [] ? '   all wired' : '   ' . count($report['todo']) . ' still fixed English'
        ));
    }

    if ($showMissing) {
        foreach ($coverage as $dir => $report) {
            foreach ($report['todo'] as $path) {
                fwrite(STDOUT, '           - ' . $path . PHP_EOL);
            }
        }
    }
}

if ($dynamic !== []) {
    fwrite(STDOUT, count($dynamic) . ' call(s) build a key at runtime and cannot be checked ('
        . implode(', ', array_slice($dynamic, 0, 3)) . (count($dynamic) > 3 ? ', …' : '') . ')' . PHP_EOL);
}

fwrite(STDOUT, PHP_EOL);

if ($problems === []) {
    fwrite(STDOUT, '0 problems' . PHP_EOL);
    exit(0);
}

foreach ($problems as $problem) {
    fwrite(STDOUT, '  ! ' . $problem . PHP_EOL);
}

fwrite(STDOUT, PHP_EOL . count($problems) . ' problem' . (count($problems) === 1 ? '' : 's') . PHP_EOL);
exit(1);
