#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Reference sweep — checks that every literal target this codebase names actually exists.
 *
 * Nothing here is a substitute for running the site; it catches the class of mistake that a
 * linter cannot see and a page test will not reach:
 *
 *   1. a require/include whose file was renamed or deleted
 *   2. render('view') with no views/view.php
 *   3. an admin nav entry pointing at a screen that does not exist
 *   4. a public nav entry pointing at a URL no route serves
 *   5. a guide table-of-contents link with no matching section id
 *   6. an endpoint the mobile app calls that the server does not have
 *
 * Run from the repo root:  php cli/reference-sweep.php
 * Exits 1 on any finding, so it can gate a deploy.
 *
 * **Watch the counters.** A pattern that matches nothing still prints "0 problems", which is a
 * clean bill of health for a check that never ran. The expected magnitudes are printed with the
 * results so a wrong-shaped pattern is obvious rather than reassuring. Both the nav and route
 * patterns were wrong the first time this was written, for exactly that reason.
 */

if (!defined('STDERR')) {
    $errStream = @fopen('php://stderr', 'wb');
    define('STDERR', $errStream ?: fopen('php://output', 'wb'));
}
if (!defined('STDOUT')) {
    $outStream = @fopen('php://stdout', 'wb');
    define('STDOUT', $outStream ?: fopen('php://output', 'wb'));
}

$root = realpath(dirname(__DIR__));
if ($root === false) {
    fwrite(STDERR, "reference-sweep: cannot resolve the project root.\n");
    exit(2);
}

$problems = array();
$short = static function (string $path) use ($root): string {
    return ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
};

/**
 * A file's source with its comments removed.
 *
 * Matching raw text finds words inside doc blocks: this file's own explanation of what it looks
 * for made it report two missing views the first time it ran. String literals are deliberately
 * kept, because the targets it looks for — `'devotional'`, `'/admin/media'` — are exactly that,
 * which is why the fix is to drop comments rather than to tokenise the matches.
 */
$code = static function (string $path): string {
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
            continue;
        }
        $out .= $token;
    }
    return $out;
};

/* --------------------------------------------------------------- source files */

$phpFiles = array();
$walker = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walker as $file) {
    if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    foreach (array('/.git/', '/.venv/', '/vendor/', '/node_modules/') as $skip) {
        if (strpos($path, $skip) !== false) {
            continue 2;
        }
    }
    $phpFiles[] = $path;
}

/* 1. literal require/include targets */

$requireCount = 0;
foreach ($phpFiles as $path) {
    $source = $code($path);
    if (!preg_match_all('/\b(?:require|include)(?:_once)?\s*(?:\(\s*)?__DIR__\s*\.\s*\'([^\']+)\'/', $source, $matches)) {
        continue;
    }
    foreach ($matches[1] as $relative) {
        $requireCount++;
        if (!is_file(dirname($path) . $relative)) {
            $problems[] = 'missing include: ' . $short($path) . ' -> ' . $relative;
        }
    }
}

/* 2. render('view') -> views/view.php */

$renderCount = 0;
foreach ($phpFiles as $path) {
    $source = $code($path);
    if (!preg_match_all('/\brender\(\s*\'([a-z0-9_\/-]+)\'/', $source, $matches)) {
        continue;
    }
    foreach ($matches[1] as $view) {
        $renderCount++;
        if (!is_file($root . '/views/' . $view . '.php')) {
            $problems[] = 'missing view: ' . $view . ' (from ' . $short($path) . ')';
        }
    }
}

/* 3. admin nav hrefs -> admin/<segment>.php */

$adminNavCount = 0;
$adminLayout = $code($root . '/admin/partials/layout-open.php');
if (preg_match_all("/'href'\s*=>\s*'\/admin\/([a-z0-9_-]+)/", $adminLayout, $matches)) {
    foreach (array_unique($matches[1]) as $segment) {
        $adminNavCount++;
        if (!is_file($root . '/admin/' . $segment . '.php')) {
            $problems[] = 'admin nav points at a missing screen: /admin/' . $segment;
        }
    }
}

/* 4. the route table, extracted once for the checks below */

$routes = $code($root . '/core/routes.php');
$routePaths = array();
if (preg_match_all("/\\\$router->(?:get|post)\(\s*'([^']+)'/", $routes, $matches)) {
    $routePaths = array_values(array_unique($matches[1]));
}

/* 5. public nav hrefs -> a route, or a real file under public/ */

$publicNavCount = 0;
$publicLayout = $code($root . '/views/partials/layout-open.php');
if (preg_match_all("/'href'\s*=>\s*'(\/[a-z0-9_\-\/]*)'/", $publicLayout, $matches)) {
    foreach (array_unique($matches[1]) as $url) {
        $publicNavCount++;
        $trimmed = rtrim($url, '/');
        if ($trimmed === '' || in_array($trimmed, $routePaths, true)) {
            continue;
        }
        if (is_file($root . '/public' . $trimmed . '.php') || is_file($root . '/public' . $trimmed)) {
            continue;
        }
        $problems[] = 'public nav points at an unrouted url: ' . $url;
    }
}

/* 6. guide table-of-contents anchors -> an id on the same page */

$anchorCount = 0;
$guide = $code($root . '/admin/guide.php');
if (preg_match_all('/href="#([a-z0-9_-]+)"/', $guide, $links)) {
    preg_match_all('/id="([a-z0-9_-]+)"/', $guide, $ids);
    $existing = array_flip($ids[1]);
    foreach (array_unique($links[1]) as $anchor) {
        $anchorCount++;
        if (!isset($existing[$anchor])) {
            $problems[] = 'guide contents link #' . $anchor . ' has no matching section';
        }
    }
}

/* 7. endpoints the mobile apps call */

$apiCount = 0;
$endpoints = array();
foreach (array($root . '/RCCGLP63/lib', $root . '/LIVINGWORD/lib') as $appDir) {
    if (!is_dir($appDir)) {
        continue;
    }
    $dartWalker = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS));
    foreach ($dartWalker as $file) {
        if (!$file->isFile() || substr($file->getFilename(), -5) !== '.dart') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (preg_match_all("/'\/api\/([a-z0-9_-]+)/", $source, $found)) {
            foreach ($found[1] as $endpoint) {
                $endpoints[$endpoint] = true;
            }
        }
    }
}
foreach (array_keys($endpoints) as $endpoint) {
    $apiCount++;
    if (!is_file($root . '/api/' . $endpoint . '.php')) {
        $problems[] = 'the mobile app calls a missing endpoint: /api/' . $endpoint;
    }
}

/* ------------------------------------------------------------------- report */

printf("php files scanned   : %d\n", count($phpFiles));
printf("literal includes    : %d\n", $requireCount);
printf("render() targets    : %d\n", $renderCount);
printf("admin nav targets   : %d\n", $adminNavCount);
printf("public nav targets  : %d\n", $publicNavCount);
printf("guide toc anchors   : %d\n", $anchorCount);
printf("routes in the table : %d\n", count($routePaths));
printf("app /api endpoints  : %d\n", $apiCount);

echo "\n";
if ($problems === array()) {
    echo "0 problems\n";
    exit(0);
}

fwrite(STDERR, count($problems) . " problem(s):\n");
foreach ($problems as $problem) {
    fwrite(STDERR, '  - ' . $problem . "\n");
}
exit(1);
