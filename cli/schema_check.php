#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Applies installer/schema.sql to a scratch database and checks the result, then drops it.
 * The live database is never touched.
 *
 * Why this exists: the app imports schema.sql on every boot as an auto-heal step, and it
 * deliberately treats a failure as a notice rather than a fatal, so that a schema problem can
 * never take a running site down. The cost of that safety is that a broken schema.sql is
 * invisible — every page still renders, every test still passes. A `--` comment whose following
 * lines are not also prefixed with `--` is parsed as SQL, and that is exactly the failure this
 * catches. Run it after touching schema.sql, and before installing for a new church.
 *
 *   php cli/schema_check.php
 *
 * Exit codes: 0 the schema is good, 1 the schema has a problem, 2 could not reach the database
 * or the account lacks CREATE DATABASE.
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

$configPath = dirname(__DIR__) . '/config/database.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "schema_check: config/database.php not found; nothing to check against.\n");
    exit(2);
}

$cfg = require $configPath;
$host = (string) ($cfg['host'] ?? '127.0.0.1');
$port = (int) ($cfg['port'] ?? 3306);
$user = (string) ($cfg['username'] ?? '');
$pass = (string) ($cfg['password'] ?? '');
$charset = (string) ($cfg['charset'] ?? 'utf8mb4');
$dbName = (string) ($cfg['database'] ?? '');
$scratch = ($dbName !== '' ? $dbName : 'churchmedia') . '_schemacheck';

$schemaPath = dirname(__DIR__) . '/installer/schema.sql';
if (!is_file($schemaPath)) {
    fwrite(STDERR, "schema_check: installer/schema.sql not found.\n");
    exit(2);
}

$options = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC);

// Connecting without a database name is what lets us create the scratch one.
try {
    $server = new PDO("mysql:host={$host};port={$port}", $user, $pass, $options);
} catch (Throwable $e) {
    fwrite(STDERR, "schema_check: cannot reach MySQL, or the account is not authorised for it.\n");
    fwrite(STDERR, '  ' . $e->getMessage() . "\n");
    exit(2);
}

try {
    $server->exec('DROP DATABASE IF EXISTS `' . $scratch . '`');
    $server->exec('CREATE DATABASE `' . $scratch . '` DEFAULT CHARACTER SET ' . $charset);
} catch (Throwable $e) {
    fwrite(STDERR, "schema_check: could not create the scratch database `{$scratch}`.\n");
    fwrite(STDERR, "  On shared hosting the app account often cannot create databases; run this locally,\n");
    fwrite(STDERR, "  or grant the account CREATE and rerun.\n");
    fwrite(STDERR, '  ' . $e->getMessage() . "\n");
    exit(2);
}

$failed = array();

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$scratch};charset={$charset}", $user, $pass, $options);
    Database::importSqlFile($pdo, $schemaPath);

    echo "schema.sql applied to a fresh database with no errors\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo 'tables created: ' . count($tables) . "\n";

    // The installer's own later steps read and write these before any migration has run, so
    // they have to come from this file rather than from the migration chain.
    $core = array('tenants', 'settings', 'users', 'org_units');
    foreach ($core as $table) {
        if (!in_array($table, $tables, true)) {
            $failed[] = "core table `{$table}` was not created";
            printf("  %-20s MISSING\n", $table);
            continue;
        }
        printf("  %-20s ok\n", $table);
    }

    // Then every table this file claims to create must exist afterwards. The list is read out
    // of schema.sql instead of hard-coded, so it stays honest as the file grows rather than
    // drifting the moment somebody adds a table. The WhatsApp-era tables (wa_campaigns,
    // wa_opt_ins, wa_messages and friends) are deliberately not here: they are created by the
    // migration chain, which is what actually runs on an installed site.
    preg_match_all('/CREATE TABLE IF NOT EXISTS `([a-z0-9_]+)`/i', (string) file_get_contents($schemaPath), $matches);
    $declared = array_values(array_unique($matches[1]));
    printf("tables declared by this file: %d\n", count($declared));

    foreach ($declared as $table) {
        if (!in_array($table, $tables, true)) {
            $failed[] = "`{$table}` is declared in schema.sql but was not created";
            printf("  %-20s DECLARED BUT MISSING\n", $table);
        }
    }

    if ($failed === array()) {
        echo "every declared table was created\n";
    }

    $check = function (string $table, string $index, int $columns) use ($pdo, &$failed): void {
        // A table that failed to create has no indexes; the missing-table report already covers it.
        $rows = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $pdo->quote($index))->fetchAll();
        if (count($rows) !== $columns) {
            $failed[] = "`{$table}` index `{$index}` covers " . count($rows) . " column(s), expected {$columns}";
            printf("  %-16s index %s: %d column(s), expected %d\n", $table, $index, count($rows), $columns);
            return;
        }
        printf("  %-16s index %s on %s\n", $table, $index, implode(', ', array_column($rows, 'Column_name')));
    };

    // Two devotionals for one day would both be shown, so this key is doing real work.
    $check('devotionals', 'uniq_devotional_day', 3);
    // The retry gate counts failed attempts for one advert, which is a lookup on exactly these two
    // columns. A composite key is the easiest thing for a naive statement splitter to mangle, so it is
    // checked rather than assumed.
    $check('ad_payments', 'idx_ad_payment_attempt', 2);
    // The gateway's own reference is how a payment is found after the fact — every verify and every webhook
    // looks a transaction up by it, so it is indexed rather than scanned.
    $check('ad_payments', 'idx_ad_payment_gateway_ref', 1);
    $check('donations', 'idx_donation_gateway_ref', 1);
} catch (Throwable $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n";
    $failed[] = 'the import threw';
}

try {
    $server->exec('DROP DATABASE IF EXISTS `' . $scratch . '`');
    echo "scratch database dropped\n";
} catch (Throwable $e) {
    fwrite(STDERR, "schema_check: could not drop the scratch database `{$scratch}`; it can be dropped by hand.\n");
}

if ($failed !== array()) {
    fwrite(STDERR, "\n" . count($failed) . " problem(s):\n");
    foreach ($failed as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }
    exit(1);
}

echo "schema_check: installer/schema.sql is good\n";
exit(0);
