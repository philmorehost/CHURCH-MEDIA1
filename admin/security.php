<?php
declare(strict_types=1);

Auth::requireRole('admin');
$pdo = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $do = $_POST['do'] ?? '';

    if ($do === 'add_ip') {
        $ip = trim($_POST['ip_address'] ?? '');
        $type = in_array($_POST['type'] ?? '', ['whitelist', 'blacklist'], true) ? $_POST['type'] : 'blacklist';
        $reason = trim($_POST['reason'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $pdo->prepare('INSERT INTO ip_rules (ip_address, type, reason) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE type = ?, reason = ?, expires_at = NULL')
                ->execute([$ip, $type, $reason, $type, $reason]);
            flash('success', "IP $ip added to the $type.");
        } else {
            flash('error', 'Enter a valid IP address.');
        }
    } elseif ($do === 'delete_ip') {
        $pdo->prepare('DELETE FROM ip_rules WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
    } elseif ($do === 'set_country') {
        $code = strtoupper(trim($_POST['country_code'] ?? ''));
        $name = trim($_POST['country_name'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['whitelisted', 'not_specified', 'blacklisted'], true) ? $_POST['status'] : 'not_specified';
        if (preg_match('/^[A-Z]{2}$/', $code) && $name !== '') {
            $pdo->prepare('INSERT INTO country_rules (country_code, country_name, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE country_name = ?, status = ?')
                ->execute([$code, $name, $status, $name, $status]);
            flash('success', "$name ($code) marked as $status.");
        } else {
            flash('error', 'Enter a valid 2-letter country code and name.');
        }
    } elseif ($do === 'delete_country') {
        $pdo->prepare('DELETE FROM country_rules WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
    }
    redirect('/admin/security');
}

$logs = $pdo->query('SELECT * FROM security_logs ORDER BY created_at DESC LIMIT 50')->fetchAll();
$ipRules = $pdo->query('SELECT * FROM ip_rules ORDER BY is_auto_whitelisted DESC, created_at DESC LIMIT 100')->fetchAll();
$countryRules = $pdo->query("SELECT * FROM country_rules WHERE status != 'not_specified' ORDER BY country_name ASC")->fetchAll();

$pageTitle = 'Security Center';
$activeNav = 'security';
require __DIR__ . '/partials/layout-open.php';
?>

<div class="card">
  <h2>IP Access Rules</h2>
  <p class="sub">👑 = auto-whitelisted after repeated successful logins. Manual blocks never expire until removed here.</p>
  <form method="post" class="row three" style="align-items:flex-end;">
    <?= Csrf::field() ?>
    <input type="hidden" name="do" value="add_ip">
    <div><label>IP Address</label><input type="text" name="ip_address" placeholder="203.0.113.5" required></div>
    <div><label>Type</label>
      <select name="type"><option value="blacklist">Blacklist</option><option value="whitelist">Whitelist</option></select>
    </div>
    <div><label>Reason</label><input type="text" name="reason" placeholder="Optional"></div>
    <div style="grid-column:1/-1;"><button class="btn sm" type="submit">Add Rule</button></div>
  </form>
  <?php if (!$ipRules): ?>
    <div class="empty">No IP rules yet.</div>
  <?php else: ?>
    <table>
      <tr><th>IP</th><th>Type</th><th>Sessions</th><th>Reason</th><th>Expires</th><th></th></tr>
      <?php foreach ($ipRules as $rule): ?>
      <tr>
        <td><?= e($rule['ip_address']) ?> <?php if ($rule['is_auto_whitelisted']): ?><span class="crown" title="Auto-whitelisted">👑</span><?php endif; ?></td>
        <td><?= $rule['type'] === 'whitelist' ? '<span class="badge ok">whitelist</span>' : '<span class="badge fail">blacklist</span>' ?></td>
        <td><?= (int) $rule['successful_session_count'] ?></td>
        <td><?= e($rule['reason'] ?: '—') ?></td>
        <td><?= $rule['expires_at'] ? e(date('M j, Y g:i A', strtotime($rule['expires_at']))) : 'never' ?></td>
        <td>
          <form method="post" style="display:inline;">
            <?= Csrf::field() ?><input type="hidden" name="do" value="delete_ip"><input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
            <button type="submit" class="btn danger sm">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Country Rules</h2>
  <p class="sub">Whitelist/blacklist by country. Detection uses a CDN header (e.g. Cloudflare) or the geoip extension when present — otherwise this list is simply not enforced.</p>
  <form method="post" class="row three" style="align-items:flex-end;">
    <?= Csrf::field() ?>
    <input type="hidden" name="do" value="set_country">
    <div><label>Code (2-letter)</label><input type="text" name="country_code" maxlength="2" placeholder="NG" required></div>
    <div><label>Country Name</label><input type="text" name="country_name" placeholder="Nigeria" required></div>
    <div><label>Status</label>
      <select name="status"><option value="whitelisted">Whitelisted</option><option value="blacklisted">Blacklisted</option></select>
    </div>
    <div style="grid-column:1/-1;"><button class="btn sm" type="submit">Save</button></div>
  </form>
  <?php if (!$countryRules): ?>
    <div class="empty">No country rules set — all countries allowed by default.</div>
  <?php else: ?>
    <table>
      <tr><th>Country</th><th>Code</th><th>Status</th><th></th></tr>
      <?php foreach ($countryRules as $c): ?>
      <tr>
        <td><?= e($c['country_name']) ?></td>
        <td><?= e($c['country_code']) ?></td>
        <td><?= $c['status'] === 'whitelisted' ? '<span class="badge ok">whitelisted</span>' : '<span class="badge fail">blacklisted</span>' ?></td>
        <td>
          <form method="post" style="display:inline;">
            <?= Csrf::field() ?><input type="hidden" name="do" value="delete_country"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <button type="submit" class="btn danger sm">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Recent Login Activity</h2>
  <?php if (!$logs): ?>
    <div class="empty">No activity logged yet.</div>
  <?php else: ?>
    <table>
      <tr><th>Event</th><th>Username</th><th>IP</th><th>When</th></tr>
      <?php foreach ($logs as $log): ?>
      <tr>
        <td>
          <?php if ($log['event_type'] === 'successful_login'): ?><span class="badge ok">success</span>
          <?php elseif ($log['event_type'] === 'failed_login'): ?><span class="badge fail">failed</span>
          <?php else: ?><span class="badge warn">blocked</span><?php endif; ?>
        </td>
        <td><?= e($log['username_attempted'] ?? '—') ?></td>
        <td><?= e($log['ip_address']) ?></td>
        <td><?= e(timeAgo($log['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
