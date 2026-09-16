<?php
declare(strict_types=1);

Auth::requireRole('admin');
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    exit('Only the super admin can manage backups.');
}

$pdo = Database::getInstance()->getConnection();
$errors = [];
$action = (string) ($_GET['action'] ?? 'list');

/* ---------------------------------------------------------------- download */

// Streamed before any layout output, and re-checked for authorisation here rather
// than trusting the link, so a backup can never be fetched by someone who is not
// the super admin.
if ($action === 'download') {
    $name = (string) ($_GET['file'] ?? '');
    $path = Backup::find($name);

    if ($path === null) {
        flash('error', 'That backup could not be found.');
        redirect('/admin/backup');
    }

    // Discard anything already buffered so the archive arrives byte-for-byte.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/* ------------------------------------------------------------------ actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'run') {
        // A backup can take a while on a large media library.
        @set_time_limit(900);
        $result = Backup::run(true);

        if (!$result['ok']) {
            flash('error', 'Backup failed: ' . ($result['error'] ?? 'unknown error'));
        } else {
            $message = 'Backup written: ' . $result['name'] . ' (' . Backup::humanSize((int) $result['bytes']) . ')';
            $message .= $result['method'] === 'mysqldump'
                ? ' — used mysqldump.'
                : ' — used the built-in PHP dumper.';
            if (!empty($result['pruned'])) {
                $message .= ' Pruned ' . (int) $result['pruned'] . ' old file(s).';
            }
            foreach ($result['warnings'] ?? [] as $warning) {
                flash('error', 'Warning: ' . $warning);
            }
            flash('success', $message);
        }
    } elseif ($do === 'delete') {
        $name = (string) ($_POST['file'] ?? '');
        if (Backup::delete($name)) {
            flash('success', 'Deleted ' . basename($name) . '.');
        } else {
            flash('error', 'That backup could not be deleted.');
        }
    } elseif ($do === 'prune') {
        $removed = Backup::prune();
        flash('success', $removed === 0
            ? 'Nothing to prune — you are within the retention setting.'
            : 'Pruned ' . $removed . ' old file(s).');
    }

    redirect('/admin/backup');
}

/* -------------------------------------------------------------------- state */

$backups = Backup::all();
$databases = array_values(array_filter($backups, static fn(array $b): bool => $b['kind'] === 'database'));
$manifests = array_values(array_filter($backups, static fn(array $b): bool => $b['kind'] === 'manifest'));

$retention = (int) setting('backup_retention_days', 14);
$offsite = (string) setting('backup_offsite_path', '');
$writable = Backup::writable();
$dumper = Backup::mysqldumpPath();
$totalBytes = 0;
foreach ($databases as $b) {
    $totalBytes += $b['bytes'];
}

// The uploads folder, so the screen can say how much is *not* in the SQL dump.
$mediaBytes = 0;
$mediaFiles = 0;
if (is_dir(UPLOADS_PATH)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(UPLOADS_PATH, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (file_exists((string) $file) && $file->isFile()) {
            $mediaBytes += (int) $file->getSize();
            $mediaFiles++;
        }
    }
}

$lastBackup = $databases[0] ?? null;
$ageDays = $lastBackup !== null ? (int) floor((time() - $lastBackup['modified']) / 86400) : null;

$pageTitle = 'Backups';
$activeNav = 'backup';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (!$writable): ?>
  <div class="alert error">
    <strong>storage/backups is not writable.</strong>
    Create the folder and give the web user permission to write to it, or backups will keep failing:
    <code>mkdir -p storage/backups &amp;&amp; chmod 755 storage/backups</code>
  </div>
<?php endif; ?>

<?php if ($lastBackup === null): ?>
  <div class="alert error">No backup has ever been taken. Take one now, then set a cron job so it happens without you.</div>
<?php elseif ($ageDays !== null && $ageDays >= 7): ?>
  <div class="alert error">The newest backup is <?= (int) $ageDays ?> days old. That is a long time to lose — check that the scheduled job is still running.</div>
<?php endif; ?>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
    <div>
      <h2>Backups</h2>
      <p class="sub" style="max-width:640px;">
        A backup is a full SQL dump of the database (<strong><?= Backup::humanSize($totalBytes) ?> across <?= count($databases) ?> file(s)</strong>)
        plus a manifest listing every uploaded media file. Dumps are written to <code>storage/backups</code>,
        which is outside the web root.
      </p>
    </div>
    <form method="post" style="margin:0;">
      <?= Csrf::field() ?><input type="hidden" name="do" value="run">
      <button class="btn" type="submit" <?= $writable ? '' : 'disabled' ?>>Back up now</button>
    </form>
  </div>

  <table style="margin-top:18px;">
    <tr><th>Setting</th><th>Value</th></tr>
    <tr>
      <td>Dump engine</td>
      <td>
        <?php if ($dumper !== null): ?>
          <span class="badge ok">mysqldump</span>
          <div><small style="color:var(--ink-faint);"><?= e($dumper) ?> — falls back to the built-in dumper if it returns nothing.</small></div>
        <?php else: ?>
          <span class="badge warn">built-in PHP dumper</span>
          <div><small style="color:var(--ink-faint);">
            <?php if (!function_exists('exec') && !function_exists('shell_exec')): ?>
              <code>exec()</code> is disabled on this host, which is normal on shared hosting. This dumper needs nothing but the database connection.
            <?php else: ?>
              No <code>mysqldump</code> binary was found, so this dumper is used. It needs nothing but the database connection.
            <?php endif; ?>
          </small></div>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <td>Compression</td>
      <td><?= Backup::canGzip() ? '<span class="badge ok">gzip</span> archives end in <code>.sql.gz</code>' : '<span class="badge warn">none</span> zlib is unavailable, archives are plain <code>.sql</code>' ?></td>
    </tr>
    <tr>
      <td>Kept</td>
      <td><?= $retention > 0 ? 'The newest <strong>' . $retention . '</strong> backup(s)' : '<strong>Everything</strong> — pruning is off' ?> <a href="/admin/settings" style="color:var(--gold-soft);margin-left:6px;">change</a></td>
    </tr>
    <tr>
      <td>Off-site copy</td>
      <td>
        <?php if ($offsite !== ''): ?>
          <code><?= e($offsite) ?></code>
        <?php else: ?>
          <span class="badge warn">not configured</span>
          <div><small style="color:var(--ink-faint);">A backup on the same server will not survive that server. See the notes below.</small></div>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <td>Media folder</td>
      <td><?= number_format($mediaFiles) ?> file(s), <?= Backup::humanSize($mediaBytes) ?> — <strong>not</strong> included in the archive; listed in the manifest so it can be copied separately.</td>
    </tr>
  </table>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;">
    <h2>Stored Backups</h2>
    <?php if ($retention > 0): ?>
      <form method="post" style="margin:0;">
        <?= Csrf::field() ?><input type="hidden" name="do" value="prune">
        <button class="btn secondary sm" type="submit">Apply retention now</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$databases): ?>
    <div class="empty">No backups yet. Use <strong>Back up now</strong> above.</div>
  <?php else: ?>
    <table>
      <tr><th>File</th><th>Size</th><th>Taken</th><th>Manifest</th><th></th></tr>
      <?php foreach ($databases as $b): ?>
        <?php
          // Pair each dump with the manifest written in the same second.
          $stamp = (string) preg_replace('/^backup-|\.sql(\.gz)?$/', '', $b['name']);
          $hasManifest = false;
          foreach ($manifests as $m) {
              if (str_contains($m['name'], $stamp)) {
                  $hasManifest = true;
                  break;
              }
          }
        ?>
        <tr>
          <td><code style="font-size:12px;"><?= e($b['name']) ?></code></td>
          <td><?= e($b['readable_size']) ?></td>
          <td><?= e(date('M j, Y g:i A', $b['modified'])) ?></td>
          <td><?= $hasManifest ? '<span class="badge ok">yes</span>' : '<span class="badge warn">no</span>' ?></td>
          <td style="white-space:nowrap;">
            <a class="btn secondary sm" href="/admin/backup?action=download&amp;file=<?= urlencode($b['name']) ?>">Download</a>
            <form method="post" onsubmit="return confirm('Delete <?= e($b['name']) ?>? This cannot be undone.');" style="display:inline;">
              <?= Csrf::field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="file" value="<?= e($b['name']) ?>">
              <button type="submit" class="btn danger sm">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Restoring a Backup</h2>
  <p class="sub">Restoring replaces everything currently in the database. Take a fresh backup immediately before you do it.</p>

  <?php if ($lastBackup !== null): ?>
    <p>For the newest backup, the exact command is:</p>
    <pre style="background:#0f0d1f;border:1px solid var(--border);border-radius:10px;padding:14px;overflow:auto;font-size:12.5px;"><?= e(Backup::restoreCommand($lastBackup['name'])) ?></pre>
    <p class="sub" style="font-size:12px;">
      The database password is requested interactively — it is deliberately not written into this page.
      On Windows/XAMPP swap <code>zcat</code> for <code>gzip -dc</code>, or use phpMyAdmin → Import.
    </p>
  <?php endif; ?>

  <h3 style="font-size:14px;margin:18px 0 8px;">Before restoring</h3>
  <ol style="color:var(--ink-dim);font-size:13px;line-height:1.9;padding-left:20px;">
    <li>Take a <strong>fresh backup of the current database</strong> first, so a wrong archive is recoverable.</li>
    <li>Put the site into maintenance if people are using it — restoring under live traffic will lose whatever arrives mid-import.</li>
    <li>Download the archive you intend to restore, and check its size is plausible. A 0&nbsp;KB file is not a backup.</li>
    <li>Restore with the command above, or import the file through phpMyAdmin.</li>
    <li>Check the site loads, then compare row counts against the manifest notes.</li>
  </ol>

  <h3 style="font-size:14px;margin:18px 0 8px;">About media files</h3>
  <p class="sub" style="font-size:13px;">
    The archive contains the <em>database only</em>. Uploaded images, video and sermon audio live in
    <code>public/uploads</code> (<?= number_format($mediaFiles) ?> file(s), <?= Backup::humanSize($mediaBytes) ?>)
    and are listed in the <code>media-*.txt</code> manifest written beside each backup. Copy that folder
    separately with your host's file manager, and compare it against the manifest to be sure nothing is missing.
  </p>

  <h3 style="font-size:14px;margin:18px 0 8px;">Scheduling it</h3>
  <p class="sub" style="font-size:13px;">
    In cPanel → Cron Jobs, run this once a day. The full path is shown on the
    <a href="/admin/settings" style="color:var(--gold-soft);">Settings</a> page for your media worker; use the same
    PHP path with this script instead:
  </p>
  <pre style="background:#0f0d1f;border:1px solid var(--border);border-radius:10px;padding:14px;overflow:auto;font-size:12.5px;">php <?= e(ROOT_PATH) ?>/cli/backup.php</pre>
  <p class="sub" style="font-size:12px;">
    Add <code>--no-media</code> to skip the manifest and finish faster. Old copies are pruned automatically
    according to the retention setting.
  </p>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
