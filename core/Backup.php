<?php
declare(strict_types=1);

/**
 * Database and media backups.
 *
 * Shared by `cli/backup.php` (cron) and `admin/backup.php` (the Backups screen) so
 * a backup taken by hand is byte-for-byte the same kind of artefact as one taken
 * on schedule.
 *
 * Two things shape the design:
 *  - **`exec()` is usually disabled on shared hosting.** cPanel hosts — which this
 *    project targets — very often turn it off, which makes `mysqldump` unusable.
 *    So the PHP dump below is the *primary* path here and `mysqldump` is the
 *    optimisation, not the other way round. The PHP dump produces ordinary SQL
 *    that restores with the `mysql` client exactly like a `mysqldump` file.
 *  - **A dump is only worth having if it restores.** Every table is written as
 *    DROP + CREATE + batched INSERTs, foreign key checks are suspended around the
 *    load, and the whole file is written to a temp name then renamed, so an
 *    interrupted run can never leave a half-written archive that looks valid.
 *
 * Backups live in `storage/backups/`, which is outside the web root and blocked
 * from direct access by the storage .htaccess.
 */
final class Backup
{
    /** Extension used for finished archives. */
    public const SUFFIX = '.sql.gz';

    /** Tables are dumped in chunks so a large analytics table cannot exhaust memory. */
    private const ROWS_PER_INSERT = 250;

    /* ------------------------------------------------------------------ paths */

    public static function dir(): string
    {
        $dir = STORAGE_PATH . '/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /** True when the directory exists and is writable by the web user. */
    public static function writable(): bool
    {
        $dir = self::dir();
        return is_dir($dir) && is_writable($dir);
    }

    /** Whether gzip is available; without it archives are plain .sql. */
    public static function canGzip(): bool
    {
        return function_exists('gzopen');
    }

    public static function suffix(): string
    {
        return self::canGzip() ? self::SUFFIX : '.sql';
    }

    /**
     * Locates a mysqldump binary, or null when there is none to use.
     *
     * Never assumed: on the hosts this project runs on `exec` is frequently
     * disabled, in which case the PHP dump handles everything.
     */
    public static function mysqldumpPath(): ?string
    {
        if (!self::canExec()) {
            return null;
        }
        $candidates = [
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            '/opt/lampp/bin/mysqldump',
        ];
        foreach ($candidates as $path) {
            if (@is_file($path) && @is_executable($path)) {
                return $path;
            }
        }
        // Last resort: ask the shell, which also covers custom installs.
        $found = @shell_exec('command -v mysqldump 2>/dev/null');
        if (is_string($found) && trim($found) !== '' && @is_executable(trim($found))) {
            return trim($found);
        }
        return null;
    }

    private static function canExec(): bool
    {
        if (!function_exists('exec') && !function_exists('shell_exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        foreach (['exec', 'shell_exec'] as $fn) {
            if (in_array($fn, $disabled, true)) {
                continue;
            }
            return true;
        }
        return false;
    }

    /* ------------------------------------------------------------------ create */

    /**
     * Writes one backup: the SQL dump, then a media manifest.
     *
     * @param bool $includeMedia    also write the media manifest
     * @param bool $useMysqldump    try mysqldump first; false forces the built-in dumper
     *                              (useful when a host's mysqldump is broken or too slow)
     * @return array{ok:bool,file?:string,name?:string,bytes?:int,method?:string,error?:string,warnings?:array<int,string>,pruned?:int}
     */
    public static function run(bool $includeMedia = true, bool $useMysqldump = true): array
    {
        if (!self::writable()) {
            return ['ok' => false, 'error' => 'The backup folder (storage/backups) is not writable.'];
        }

        $stamp = date('Y-m-d_His');
        $name = 'backup-' . $stamp . self::suffix();
        $final = self::dir() . '/' . $name;
        // A unique temp name, so two overlapping runs cannot clobber each other.
        $temp = $final . '.' . bin2hex(random_bytes(4)) . '.part';

        $warnings = [];
        $method = 'php';

        try {
            $pdo = Database::getInstance()->getConnection();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not connect to the database: ' . $e->getMessage()];
        }

        try {
            self::$gzipped = self::canGzip();
            $handle = self::$gzipped ? @gzopen($temp, 'wb9') : @fopen($temp, 'wb');
            if (!$handle) {
                return ['ok' => false, 'error' => 'Could not open the backup file for writing.'];
            }

            self::write($handle, self::prelude($pdo, $includeMedia, $name));

            // Prefer mysqldump only when it is actually usable, and fall back if it
            // produces anything that is not a dump — a disabled exec, a missing
            // binary or a permissions error would otherwise yield a broken archive.
            $dumpPath = $useMysqldump ? self::mysqldumpPath() : null;
            if ($dumpPath !== null) {
                $body = self::dumpWithMysqldump($pdo, $dumpPath);
                if ($body !== null) {
                    self::write($handle, $body);
                    $method = 'mysqldump';
                } else {
                    $warnings[] = 'mysqldump produced nothing usable, so the built-in dumper was used instead.';
                }
            }
            if ($method === 'php') {
                self::dumpWithPdo($pdo, $handle);
            }

            // Closing the file restores the checks the prelude suspended. mysqldump
            // only ever does this inside a /*!40014 ... */ conditional comment, so
            // writing it plainly here makes the tail of the dump unambiguous.
            self::write($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n\n-- End of backup\n");
            self::close($handle);
        } catch (Throwable $e) {
            @unlink($temp);
            return ['ok' => false, 'error' => 'The dump failed: ' . $e->getMessage()];
        }

        if (!@is_file($temp) || @filesize($temp) === 0) {
            @unlink($temp);
            return ['ok' => false, 'error' => 'The dump produced an empty file.'];
        }

        // A media manifest lists what files should exist, so a restore can be
        // checked against reality. Sizes and timestamps only — hashing an entire
        // media library on every backup would be far too slow.
        if ($includeMedia) {
            try {
                $manifest = self::mediaManifest();
                self::writeTextFile(self::dir() . '/media-' . $stamp . '.txt', $manifest);
            } catch (Throwable $e) {
                $warnings[] = 'The media manifest could not be written: ' . $e->getMessage();
            }
        }

        if (!@rename($temp, $final)) {
            @unlink($temp);
            return ['ok' => false, 'error' => 'The finished backup could not be moved into place.'];
        }
        @chmod($final, 0640);

        $bytes = (int) @filesize($final);

        // An off-site copy is a plain directory copy. Point it at a mounted disk, a
        // synced folder, or anything else the host can already write to; anything
        // that needs credentials belongs in the host's own tooling, not here.
        $offsite = trim((string) setting('backup_offsite_path', ''));
        if ($offsite !== '') {
            $copy = self::copyOffsite($final, $offsite);
            if (!$copy['ok']) {
                $warnings[] = 'Off-site copy failed: ' . $copy['error'];
            }
        }

        $pruned = self::prune();

        return [
            'ok' => true,
            'file' => $final,
            'name' => $name,
            'bytes' => $bytes,
            'method' => $method,
            'warnings' => $warnings,
            'pruned' => $pruned,
        ];
    }

    /** Header comments: when, from where, and what the file is. */
    private static function prelude(PDO $pdo, bool $includeMedia, string $name): string
    {
        $db = 'unknown';
        $server = 'unknown';
        try {
            $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            $server = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (Throwable $e) {
            // A dump is still worth attempting if these probes fail.
        }

        $config = @include CONFIG_PATH . '/database.php';
        $user = is_array($config) ? (string) ($config['username'] ?? 'user') : 'user';
        $unzipped = str_ends_with($name, '.gz') ? 'zcat ' . $name . ' | ' : '';

        $lines = [
            '-- Church Media backup',
            '-- Created : ' . date('c'),
            '-- Database: ' . $db . ' (server ' . $server . ', PHP ' . PHP_VERSION . ')',
            '-- Site    : ' . setting('site_title', ''),
            '--',
            '-- Restore with:  ' . $unzipped . 'mysql -u ' . $user . ' -p ' . $db,
            $includeMedia ? '-- A media manifest was written alongside this file (media-*.txt).' : '-- No media manifest was requested.',
            '',
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS=0;',
            '',
        ];
        return implode("\n", $lines) . "\n";
    }

    /* ------------------------------------------------------------- dump engines */

    /**
     * Shells out to mysqldump. Returns null when it is unusable.
     *
     * Returns null on *any* output that is not a dump — an error message, a notice
     * on stderr, empty output. The caller falls back to the built-in dumper, which
     * is the safe outcome; accepting a partial or error-only file as a backup would
     * be worse than having no mysqldump at all.
     */
    private static function dumpWithMysqldump(PDO $pdo, string $binary): ?string
    {
        $config = require CONFIG_PATH . '/database.php';
        $database = (string) ($config['database'] ?? '');
        if ($database === '') {
            return null;
        }

        // The password goes through an environment variable rather than the command
        // line, so it can never appear in the process list.
        //
        // Only set it when there is actually a password: an empty MYSQL_PWD makes the
        // client announce "using password: YES" and then fail against an account that
        // genuinely has no password — which is exactly how the default XAMPP install
        // is configured, and easy to hit on a host too.
        $password = (string) ($config['password'] ?? '');
        $env = '';
        if ($password !== '') {
            $env = PHP_OS_FAMILY === 'Windows'
                // cmd.exe needs `set "VAR=value"`; `set VAR="value"` would store the
                // quotes as part of the value.
                ? 'set "MYSQL_PWD=' . $password . '" && '
                : 'MYSQL_PWD=' . escapeshellarg($password) . ' ';
        }

        $host = (string) ($config['host'] ?? 'localhost');
        $port = (int) ($config['port'] ?? 3306);
        $user = (string) ($config['username'] ?? '');

        // --single-transaction keeps InnoDB consistent without locking anyone out;
        // --skip-lock-tables and --no-tablespaces avoid privileges a shared host
        // often does not grant.
        $command = $env . escapeshellarg($binary)
            . ' --host=' . escapeshellarg($host)
            . ' --port=' . $port
            . ' --user=' . escapeshellarg($user)
            . ' --single-transaction --quick --skip-lock-tables --no-tablespaces'
            . ' --default-character-set=utf8mb4 --routines --events'
            . ' ' . escapeshellarg($database) . ' 2>&1';

        $output = @shell_exec($command);
        if (!is_string($output) || !str_contains($output, 'CREATE TABLE')) {
            return null;
        }
        return "\n-- Dumped with mysqldump\n" . $output . "\n";
    }

    /**
     * The built-in dumper. Walks every table, writes DROP + CREATE + batched
     * INSERTs, and pages through rows by primary key so a large table is read in
     * bounded chunks instead of being loaded whole.
     */
    private static function dumpWithPdo(PDO $pdo, $handle): void
    {
        $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $table = (string) $table;
            $quoted = self::quoteIdentifier($table);

            $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
            if (!$create) {
                continue;
            }

            self::write($handle, "\n--\n-- Table structure and data for {$quoted}\n--\n");
            self::write($handle, "DROP TABLE IF EXISTS {$quoted};\n");
            self::write($handle, $create[1] . ";\n\n");

            self::dumpTableRows($pdo, $handle, $quoted);
        }
    }

    private static function dumpTableRows(PDO $pdo, $handle, string $quoted): void
    {
        $primaryKey = self::singleColumnPrimaryKey($pdo, $quoted);
        $columns = null;
        $batch = [];
        $written = 0;

        // Keyset paging on the primary key; a plain scan when there is not one.
        $lastKey = null;
        do {
            if ($primaryKey !== null) {
                $pk = self::quoteIdentifier($primaryKey);
                $sql = $lastKey === null
                    ? 'SELECT * FROM ' . $quoted . ' ORDER BY ' . $pk . ' LIMIT ' . self::ROWS_PER_INSERT
                    : 'SELECT * FROM ' . $quoted . ' WHERE ' . $pk . ' > ' . $pdo->quote((string) $lastKey) . ' ORDER BY ' . $pk . ' LIMIT ' . self::ROWS_PER_INSERT;
            } else {
                $sql = 'SELECT * FROM ' . $quoted . ' LIMIT ' . self::ROWS_PER_INSERT . ' OFFSET ' . $written;
            }

            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                if ($columns === null) {
                    $columns = array_keys($row);
                }
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (is_int($value) || is_float($value)) {
                        $values[] = (string) $value;
                    } elseif (is_bool($value)) {
                        $values[] = $value ? '1' : '0';
                    } else {
                        // Blob columns in particular must survive byte-for-byte, and
                        // PDO::quote escapes using the live connection charset.
                        $values[] = $pdo->quote((string) $value);
                    }
                }
                $batch[] = '(' . implode(', ', $values) . ')';
                $written++;
                if ($primaryKey !== null) {
                    $lastKey = $row[$primaryKey];
                }
            }

            if ($batch) {
                self::writeInserts($handle, $quoted, $columns ?? [], $batch);
                $batch = [];
            }
        } while (count($rows) === self::ROWS_PER_INSERT);

        if ($batch) {
            self::writeInserts($handle, $quoted, $columns ?? [], $batch);
        }
    }

    /** @param array<int, string> $batch */
    private static function writeInserts($handle, string $quoted, array $columns, array $batch): void
    {
        $columnList = $columns
            ? ' (' . implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)) . ')'
            : '';
        self::write($handle, 'INSERT INTO ' . $quoted . $columnList . " VALUES\n" . implode(",\n", $batch) . ";\n");
    }

    /**
     * The primary key column, but only when it is a single column — a composite key
     * has no single value to page on, so the caller falls back to OFFSET paging.
     */
    private static function singleColumnPrimaryKey(PDO $pdo, string $quoted): ?string
    {
        try {
            $keys = $pdo->query('SHOW KEYS FROM ' . $quoted . ' WHERE Key_name = \'PRIMARY\'')->fetchAll();
        } catch (Throwable $e) {
            return null;
        }
        if (count($keys) !== 1) {
            return null;
        }
        return (string) $keys[0]['Column_name'];
    }

    /* ------------------------------------------------------------------- media */

    /**
     * A manifest of the uploaded files: relative path, size and modification time.
     *
     * The SQL dump already contains every database row that references a file; this
     * is what lets someone confirm the files themselves arrived intact.
     */
    public static function mediaManifest(): string
    {
        $root = UPLOADS_PATH;
        $lines = [
            '# Church Media — media manifest',
            '# Generated: ' . date('c'),
            '# Format: <relative path> <tab> <bytes> <tab> <modified ISO-8601>',
            '',
        ];

        $count = 0;
        $bytes = 0;
        if (is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            $paths = [];
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $paths[] = $file->getPathname();
                }
            }
            sort($paths);
            foreach ($paths as $path) {
                $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
                $size = (int) @filesize($path);
                $lines[] = $relative . "\t" . $size . "\t" . date('c', (int) @filemtime($path));
                $count++;
                $bytes += $size;
            }
        }

        $lines[] = '';
        $lines[] = '# ' . $count . ' file(s), ' . number_format($bytes) . ' bytes';
        return implode("\n", $lines) . "\n";
    }

    private static function writeTextFile(string $path, string $contents): void
    {
        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.part';
        if (@file_put_contents($temp, $contents) === false) {
            @unlink($temp);
            throw new RuntimeException('could not write ' . basename($path));
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('could not move ' . basename($path) . ' into place');
        }
    }

    /* --------------------------------------------------------------- off-site */

    /**
     * Copies a finished archive to another directory. Deliberately not an FTP or S3
     * client: anything needing credentials belongs in the host's own tooling, and a
     * plain copy covers a mounted disk, a NAS, or a synced folder.
     *
     * @return array{ok:bool,error?:string}
     */
    private static function copyOffsite(string $file, string $destination): array
    {
        $destination = rtrim($destination, "/\\");
        if (!is_dir($destination)) {
            if (!@mkdir($destination, 0755, true)) {
                return ['ok' => false, 'error' => 'the folder ' . $destination . ' does not exist and could not be created'];
            }
        }
        if (!is_writable($destination)) {
            return ['ok' => false, 'error' => 'the folder ' . $destination . ' is not writable'];
        }
        if (!@copy($file, $destination . '/' . basename($file))) {
            return ['ok' => false, 'error' => 'the copy to ' . $destination . ' failed'];
        }
        return ['ok' => true];
    }

    /* -------------------------------------------------------------------- list */

    /**
     * Backups newest first, plus the media manifests written alongside them.
     *
     * @return array<int, array{name:string,path:string,bytes:int,modified:int,kind:string,readable_size:string}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*') ?: [] as $path) {
            $name = basename($path);
            // Skip half-written files and stray temp parts.
            if (!is_file($path) || str_ends_with($name, '.part') || str_starts_with($name, '.')) {
                continue;
            }
            $isSql = str_ends_with($name, '.sql') || str_ends_with($name, '.sql.gz');
            $isManifest = str_starts_with($name, 'media-') && str_ends_with($name, '.txt');
            if (!$isSql && !$isManifest) {
                continue;
            }
            $bytes = (int) @filesize($path);
            $out[] = [
                'name' => $name,
                'path' => $path,
                'bytes' => $bytes,
                'modified' => (int) @filemtime($path),
                'kind' => $isSql ? 'database' : 'manifest',
                'readable_size' => self::humanSize($bytes),
            ];
        }
        usort($out, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);
        return $out;
    }

    /** Locates a backup by name, refusing anything that tries to escape the folder. */
    public static function find(string $name): ?string
    {
        $name = basename($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }
        // Only the two shapes this class actually writes.
        if (!preg_match('/^(backup-[0-9_\-]+\.sql(\.gz)?|media-[0-9_\-]+\.txt)$/', $name)) {
            return null;
        }
        $path = self::dir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    public static function delete(string $name): bool
    {
        $path = self::find($name);
        return $path !== null && @unlink($path);
    }

    /**
     * Keeps the newest N database backups (and the manifests beside them).
     *
     * A retention of 0 or less disables pruning, which is what an admin who copies
     * backups elsewhere by hand would want.
     *
     * @return int how many files were removed
     */
    public static function prune(?int $keep = null): int
    {
        $keep ??= (int) setting('backup_retention_days', 14);
        if ($keep <= 0) {
            return 0;
        }

        $removed = 0;
        $databases = array_values(array_filter(self::all(), static fn(array $f): bool => $f['kind'] === 'database'));
        foreach (array_slice($databases, $keep) as $old) {
            if (self::delete($old['name'])) {
                $removed++;
            }
        }

        // Manifests are only useful next to a database backup, so prune them on the
        // same count rather than letting them accumulate for ever.
        $manifests = array_values(array_filter(self::all(), static fn(array $f): bool => $f['kind'] === 'manifest'));
        foreach (array_slice($manifests, $keep) as $old) {
            if (self::delete($old['name'])) {
                $removed++;
            }
        }

        return $removed;
    }

    /* ----------------------------------------------------------------- restore */

    /** The exact command that restores a given archive, for the guide and the UI. */
    public static function restoreCommand(string $name, string $database = ''): string
    {
        $config = @include CONFIG_PATH . '/database.php';
        $db = $database !== '' ? $database : (string) (is_array($config) ? ($config['database'] ?? 'your_database') : 'your_database');
        $user = (string) (is_array($config) ? ($config['username'] ?? 'your_user') : 'your_user');
        $path = self::dir() . '/' . basename($name);

        // A .gz needs decompressing on the way in; a plain .sql does not.
        $reader = str_ends_with($name, '.gz') ? 'zcat ' : '';
        return 'mysql -u ' . $user . ' -p ' . $db . ' < ' . $reader . $path;
    }

    /**
     * A pre-restore safety copy: whatever is in the database right now, saved under
     * a name that says what it is. Restoring is destructive, so this is the file you
     * reach for when the archive turns out to be the wrong one.
     */
    public static function runPreRestore(): array
    {
        $result = self::run(false);
        if ($result['ok'] && isset($result['file'])) {
            $renamed = self::dir() . '/backup-prerestore-' . date('Y-m-d_His') . self::suffix();
            if (@rename((string) $result['file'], $renamed)) {
                $result['file'] = $renamed;
                $result['name'] = basename($renamed);
            }
        }
        return $result;
    }

    /**
     * Loads one of our dumps back into a database.
     *
     * Destructive: the dump begins each table with DROP TABLE, so whatever is there
     * is replaced. Callers should take a safety copy first.
     *
     * @return array{ok:bool,statements:int,errors:array<int,string>}
     */
    public static function restoreInto(PDO $pdo, string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'statements' => 0, 'errors' => ['No such backup file.']];
        }

        $raw = (string) @file_get_contents($path);
        if ($raw === '') {
            return ['ok' => false, 'statements' => 0, 'errors' => ['The backup file is empty.']];
        }

        if (str_ends_with($path, '.gz')) {
            $decoded = @gzdecode($raw);
            if ($decoded === false) {
                return ['ok' => false, 'statements' => 0, 'errors' => ['The backup file could not be decompressed.']];
            }
            $raw = $decoded;
        }

        $statements = self::splitStatements($raw);
        if ($statements === []) {
            return ['ok' => false, 'statements' => 0, 'errors' => ['The backup contains no statements.']];
        }

        $errors = [];
        $ran = 0;
        foreach ($statements as $index => $statement) {
            try {
                $pdo->exec($statement);
                $ran++;
            } catch (Throwable $e) {
                // Keep going rather than abandoning a half-loaded database: a dump may
                // legitimately contain a statement a newer server no longer needs.
                if (count($errors) < 10) {
                    $errors[] = 'Statement ' . ($index + 1) . ': ' . $e->getMessage();
                }
            }
        }

        return ['ok' => $errors === [], 'statements' => $ran, 'errors' => $errors];
    }

    /**
     * Splits a dump into statements on the semicolons that are actually statement
     * terminators — never one inside a string, an identifier or a comment. A naive
     * `explode(';', $sql)` corrupts any row containing a semicolon, a quote or a
     * newline, which is most of them on a real church site.
     *
     * @return array<int, string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;          // the open ' " or ` delimiter, if any
        $lineComment = false;
        $blockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $buffer .= "\n";
                }
                continue;
            }

            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                }
                continue;
            }

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== '`') {
                    // A backslash escape: the next character is part of the string.
                    if ($next !== '') {
                        $buffer .= $next;
                        $i++;
                    }
                    continue;
                }
                if ($char === $quote) {
                    // A doubled quote is an escaped quote, not the end of the string.
                    if ($next === $quote) {
                        $buffer .= $next;
                        $i++;
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            // Outside any string or comment.
            if ($char === '-' && $next === '-') {
                $lineComment = true;
                $i++;
                continue;
            }
            if ($char === '#') {
                $lineComment = true;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /* --------------------------------------------------------------- utilities */

    /** Whether the open dump handle is a gzip stream. Set once per run. */
    private static bool $gzipped = false;

    /** Writes a chunk to the open dump handle. */
    private static function write($handle, string $chunk): void
    {
        if (self::$gzipped) {
            gzwrite($handle, $chunk);
            return;
        }
        fwrite($handle, $chunk);
    }

    private static function close($handle): void
    {
        if (self::$gzipped) {
            gzclose($handle);
            self::$gzipped = false;
            return;
        }
        fclose($handle);
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
