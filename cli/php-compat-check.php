<?php
declare(strict_types=1);

/**
 * Guard against PHP 8 syntax reaching a PHP 7 codebase.
 *
 * The product is installed on shared hosting, where the PHP version is chosen
 * by the host and cannot be changed. The source therefore has to stay inside
 * the PHP 7.4 grammar even though it is developed on 8.x. That mismatch has
 * already shipped twice: a `match` expression broke /podcast.xml, and a `mixed`
 * parameter broke it again the moment the first fix landed.
 *
 * Neither mistake is visible on a PHP 8 development machine. Worse, most of
 * them are not even parse errors: PHP 7 reads `mixed`, `never` and `static` as
 * ordinary class names, so the file loads and only explodes at runtime, on
 * whichever code path happens to touch it first.
 *
 * Run this before every push, and before packaging:
 *
 *     php cli/php-compat-check.php
 *
 * Exits 0 when clean, 1 when it finds something, so it can gate a deploy.
 */

$root  = dirname(__DIR__);
$quiet = in_array('--quiet', $argv, true);

/**
 * Constructs that do not exist before PHP 8. Matching is done against source
 * with comments and string literals blanked out, so prose that merely mentions
 * `match` or `mixed` - including the notes that explain this very problem -
 * does not trip the check.
 */
$rules = [
    'match expression'                  => '/(?<!->)(?<!::)(?<!function )\bmatch\s*\(/',
    'mixed parameter type'              => '/\bmixed\s+&?\$/',
    'mixed return type'                 => '/\)\s*:\s*mixed\b/',
    'never return type'                 => '/\)\s*:\s*never\b/',
    'static return type'                => '/\)\s*:\s*static\b/',
    'nullable-object operator'          => '/\?->/',
    'enum declaration'                  => '/\benum\s+[A-Za-z_]\w*\s*[:{]/',
    'readonly property'                 => '/\breadonly\s+/',
    'attribute'                         => '/#\[/',
    'promoted constructor property'     => '/__construct\s*\([^;{]*\b(?:public|protected|private)\b[^;{]*\$/',
    'union type in a parameter'         => '/(?<!\|)\b[A-Za-z_]\w*\s*\|\s*[A-Za-z_]\w*(?!\|)\s+&?\$/',
    'union type in a return type'       => '/\)\s*:\s*[A-Za-z_]\w*\s*\|\s*[A-Za-z_]\w*(?!\|)/',
    'first-class callable'              => '/[A-Za-z_]\w*\s*\(\s*\.\.\.\s*\)/',
    'array_is_list (needs a polyfill)'  => '/\barray_is_list\s*\(/',
    'get_debug_type (needs a polyfill)' => '/\bget_debug_type\s*\(/',
    'trailing comma in a signature'     => '/function\s+[A-Za-z_]*\s*\([^()]*,\s*\)/',
];

/** Directories that are not shipped PHP, so they are not scanned. */
$skip = ['mobile', 'storage', 'vendor', 'node_modules', '.git', '.vscode'];

/**
 * Blank out comments and string literals while preserving line numbering, so
 * the rules above cannot match documentation and a reported line number still
 * points at the real line in the original file.
 */
function stripNoise(string $code): string
{
    $blank = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML];
    $out = '';

    foreach (token_get_all($code) as $token) {
        if (is_string($token)) {
            $out .= $token;
            continue;
        }
        if (in_array($token[0], $blank, true)) {
            $out .= str_repeat("\n", substr_count($token[1], "\n"));
            continue;
        }
        $out .= $token[1];
    }

    return $out;
}

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $entry) {
    if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
        continue;
    }
    $name = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
    $top  = strstr($name, '/', true);
    if ($top !== false && in_array($top, $skip, true)) {
        continue;
    }
    if (strpos($name, 'public/uploads/') === 0) {
        continue;
    }
    $files[$name] = $entry->getPathname();
}
ksort($files);

$findings = [];

foreach ($files as $name => $path) {
    $source = file_get_contents($path);
    if ($source === false) {
        continue;
    }
    $stripped = stripNoise($source);

    foreach ($rules as $label => $pattern) {
        if (!preg_match_all($pattern, $stripped, $hits, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($hits[0] as $hit) {
            // Turn the offset in the stripped copy back into a real line number.
            $line = substr_count(substr($stripped, 0, $hit[1]), "\n") + 1;
            $findings[] = [
                'where' => $name . ':' . $line,
                'label' => $label,
                'text'  => trim(preg_replace('/\s+/', ' ', $hit[0])),
            ];
        }
    }
}

// The PHP 8 string functions are fine to use, but only while these polyfills
// exist. If someone tidies them away, every caller breaks at once.
$polyfills = (string) file_get_contents($root . '/core/helpers.php');
foreach (['str_starts_with', 'str_contains', 'str_ends_with'] as $function) {
    if (strpos($polyfills, "function_exists('" . $function . "')") === false) {
        $findings[] = [
            'where' => 'core/helpers.php',
            'label' => 'missing polyfill',
            'text'  => $function . '() is not back-filled for PHP 7',
        ];
    }
}

printf("PHP compatibility check - %d files scanned, running on PHP %s\n", count($files), PHP_VERSION);
printf("The shipped floor is PHP 7.4; anything above that must be polyfilled in core/helpers.php.\n\n");

if ($findings === []) {
    if (!$quiet) {
        echo "Clean. No PHP 8 syntax found.\n";
    }
    exit(0);
}

foreach ($findings as $finding) {
    printf("  %-34s %s\n", $finding['where'], $finding['label']);
    if (!$quiet) {
        printf("  %-34s -> %s\n", '', $finding['text']);
    }
}

printf("\n%d problem(s). These parse or resolve differently on PHP 7 and will fail on the host.\n", count($findings));
exit(1);
