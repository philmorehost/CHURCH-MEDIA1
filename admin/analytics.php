<?php
declare(strict_types=1);

/**
 * Analytics dashboard.
 *
 * Reads raw events for the selected range (indexed, fine at church-site volumes)
 * and the lifetime roll-ups for the all-time line, so numbers survive raw-event
 * pruning. Counts that already live in their own tables — giving, newcomers,
 * attendance, likes, saves, comments — are read from those tables rather than
 * duplicated into analytics, so nothing is double counted here.
 */

Auth::requireRole('admin', 'editor');

$pdo = Database::getInstance()->getConnection();
$user = Auth::user();

// Scope: a church admin sees only their own branch of the hierarchy.
$unitIds = null;
if (empty($user['is_super_admin'])) {
    $own = (int) ($user['org_unit_id'] ?? 0);
    $unitIds = $own > 0 ? Unit::subtreeIds($own) : [];
}
$unitLabels = Unit::labelsById();

// The collection switch and retention live here rather than buried in Settings —
// this is where the effect is visible.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (($_POST['verb'] ?? '') === 'save_analytics_settings') {
        settingSave([
            'analytics_enabled' => isset($_POST['analytics_enabled']) ? 1 : 0,
            'analytics_retention_days' => max(7, min(3650, (int) ($_POST['analytics_retention_days'] ?? 180))),
        ]);
        flash('success', 'Analytics settings saved.');
        redirect('/admin/analytics');
    }
}

/* ---- date range ---- */
$presets = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
$range = (string) ($_GET['range'] ?? '30');
if (!array_key_exists($range, $presets) && $range !== 'custom') {
    $range = '30';
}

$firstDay = Analytics::firstDay();
if ($range === 'custom') {
    $from = date('Y-m-d', (int) strtotime((string) ($_GET['from'] ?? '-30 day')));
    $to = date('Y-m-d', (int) strtotime((string) ($_GET['to'] ?? 'today')));
} else {
    $from = date('Y-m-d', (int) strtotime('-' . ((int) $range - 1) . ' day'));
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

/* ---- headline numbers ---- */
$counts = Analytics::counts($from, $to, $unitIds);
$visitors = Analytics::visitors($from, $to, $unitIds);
$series = Analytics::timeseries($from, $to, 'page_view', $unitIds);
$devices = Analytics::deviceSplit($from, $to, $unitIds);
$lifetime = Analytics::lifetime($unitIds);

$contentViews = $counts['post_view'] + $counts['sermon_view'] + $counts['event_view'] + $counts['testimony_view'];

// Counts that already exist in their own tables.
$unitFilter = '';
$unitParams = [];
if ($unitIds !== null) {
    if (!$unitIds) {
        $unitFilter = ' AND 1 = 0';
    } else {
        $unitFilter = ' AND org_unit_id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')';
        $unitParams = array_map('intval', $unitIds);
    }
}
$scalar = static function (string $sql, array $params) use ($pdo): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
};
$newGiving = $scalar(
    'SELECT COUNT(*) FROM donations WHERE DATE(created_at) BETWEEN ? AND ?' . $unitFilter,
    array_merge([$from, $to], $unitParams)
);
$newNewcomers = $scalar(
    'SELECT COUNT(*) FROM newcomers WHERE DATE(created_at) BETWEEN ? AND ?' . $unitFilter,
    array_merge([$from, $to], $unitParams)
);
$newComments = $scalar(
    'SELECT COUNT(*) FROM post_comments c JOIN media_posts p ON p.id = c.media_post_id WHERE DATE(c.created_at) BETWEEN ? AND ?'
    . ($unitIds === null ? '' : ($unitIds ? ' AND p.org_unit_id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')' : ' AND 1 = 0')),
    array_merge([$from, $to], $unitParams)
);

/* ---- lists ---- */
$resolveTitles = static function (array $rows, string $table, string $column) use ($pdo): array {
    $ids = array_map(static fn (array $r): int => (int) $r['entity_id'], $rows);
    if (!$ids) {
        return [];
    }
    $titles = [];
    try {
        $stmt = $pdo->query('SELECT id, `' . $column . '` AS label FROM `' . $table . '` WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        foreach ($stmt->fetchAll() as $row) {
            $titles[(int) $row['id']] = (string) ($row['label'] ?? '');
        }
    } catch (Throwable $e) {
        // Content may have been deleted since — labels simply fall back to ids.
    }
    return $titles;
};

$topGroups = [
    ['type' => 'post', 'table' => 'media_posts', 'column' => 'caption', 'label' => 'Reels & posts', 'link' => '/admin/media?action=edit&id='],
    ['type' => 'sermon', 'table' => 'sermons', 'column' => 'title', 'label' => 'Sermons', 'link' => '/admin/sermons?action=edit&id='],
    ['type' => 'event', 'table' => 'events', 'column' => 'title', 'label' => 'Events', 'link' => '/admin/events?action=edit&id='],
    ['type' => 'testimony', 'table' => 'testimonies', 'column' => 'title', 'label' => 'Testimonies', 'link' => null],
];
$tops = [];
foreach ($topGroups as $group) {
    $rows = Analytics::topEntities($from, $to, $group['type'], 5, $unitIds);
    $tops[] = $group + ['rows' => $rows, 'titles' => $resolveTitles($rows, $group['table'], $group['column'])];
}

$topPaths = Analytics::topPaths($from, $to, 10, $unitIds);
$topSearches = Analytics::topSearches($from, $to, 10, $unitIds);
$topReferrers = Analytics::topReferrers($from, $to, 8, $unitIds);
$byUnit = Analytics::byUnit($from, $to, 12);

/* ---- the bar chart (pure SVG, no JS library) ---- */
$renderBars = static function (array $data): string {
    $values = array_values($data);
    $max = max(1, $values ? max($values) : 1);
    $n = max(1, count($data));
    $w = 1000.0;
    $h = 150.0;
    $gap = 2.0;
    $barW = max(1.0, ($w - $gap * ($n - 1)) / $n);

    $bars = '';
    $i = 0;
    foreach ($data as $day => $value) {
        $value = (int) $value;
        $barH = $value > 0 ? max(2.0, round($value / $max * $h, 2)) : 0.0;
        $x = round($i * ($barW + $gap), 2);
        $y = round($h - $barH, 2);
        $bars .= '<rect x="' . $x . '" y="' . $y . '" width="' . round($barW, 2) . '" height="' . $barH . '" rx="1.5" fill="url(#barGrad)">'
            . '<title>' . e((string) $day) . ': ' . $value . '</title></rect>';
        $i++;
    }

    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" style="width:100%;height:150px;display:block;" role="img" aria-label="Daily page views">'
        . '<defs><linearGradient id="barGrad" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0%" stop-color="#e8b95f"/><stop offset="100%" stop-color="#e8b95f33"/>'
        . '</linearGradient></defs>' . $bars . '</svg>';
};

$pct = static function (int $part, int $whole): string {
    return $whole > 0 ? round($part / $whole * 100) . '%' : '—';
};

$pageTitle = 'Analytics';
$activeNav = 'analytics';
require __DIR__ . '/partials/layout-open.php';
?>

<?php if (!Analytics::enabled()): ?>
  <div class="alert error" style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
    <span><strong>Analytics is switched off</strong>, so nothing is being recorded. Turn it on to start collecting traffic data.</span>
    <a class="btn sm" href="#analytics-settings">Show me where</a>
  </div>
<?php endif; ?>

<?php if ($firstDay === null): ?>
  <div class="alert success">
    <strong>Analytics is live and waiting for its first visitor.</strong>
    Nothing has been recorded yet — this page fills in as people use the site and the app.
  </div>
<?php endif; ?>

<div class="btn-row" style="margin-bottom:18px;flex-wrap:wrap;">
  <?php foreach ($presets as $key => $label): ?>
    <a class="btn <?= $range === $key ? '' : 'secondary' ?> sm" href="/admin/analytics?range=<?= e($key) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
  <form method="get" action="/admin/analytics" style="display:flex;gap:8px;align-items:flex-end;">
    <input type="hidden" name="range" value="custom">
    <div><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <button class="btn secondary sm" type="submit">Apply</button>
  </form>
</div>

<div class="card">
  <h2>Overview</h2>
  <p class="sub"><?= e(date('M j, Y', (int) strtotime($from))) ?> to <?= e(date('M j, Y', (int) strtotime($to))) ?><?= $unitIds === null ? ' — the whole organisation' : ' — your churches only' ?>.</p>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;">
    <?php
    $cards = [
        ['Visitors', number_format($visitors), 'Distinct devices'],
        ['Page views', number_format($counts['page_view']), number_format($counts['page_view'] / max(1, $visitors), 1) . ' per visitor'],
        ['Content views', number_format($contentViews), 'Reels, sermons, events'],
        ['Searches', number_format($counts['search']), 'People finding things'],
        ['Video plays', number_format($counts['video_play']), 'Played in the browser'],
        ['App opens', number_format($counts['app_open']), $pct($counts['app_open'], $counts['page_view'] + $counts['app_open']) . ' of traffic'],
    ];
    foreach ($cards as [$label, $value, $hint]):
    ?>
      <div style="background:var(--panel-2,#1c1a33);border:1px solid var(--border);border-radius:12px;padding:14px;">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-faint);"><?= e($label) ?></div>
        <div style="font-size:24px;font-weight:700;color:var(--gold-soft);margin:4px 0 2px;"><?= e($value) ?></div>
        <div style="font-size:11.5px;color:var(--ink-faint);"><?= e($hint) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <h2 style="font-size:14px;">Daily page views</h2>
  <?= $renderBars($series) ?>
  <div style="display:flex;justify-content:space-between;font-size:11.5px;color:var(--ink-faint);margin-top:6px;">
    <span><?= e(date('M j', (int) strtotime($from))) ?></span>
    <span>peak <?= number_format($series ? max($series) : 0) ?> in a day</span>
    <span><?= e(date('M j', (int) strtotime($to))) ?></span>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px;">
  <?php foreach ($tops as $group): ?>
    <div class="card" style="margin:0;">
      <h2 style="font-size:14px;">Top <?= e($group['label']) ?></h2>
      <?php if (!$group['rows']): ?>
        <p class="sub" style="margin:0;">No views recorded in this period.</p>
      <?php else: ?>
        <table>
          <?php foreach ($group['rows'] as $row): ?>
            <tr>
              <td style="padding-left:0;">
                <?php $title = $group['titles'][(int) $row['entity_id']] ?? ''; ?>
                <?php if ($group['link']): ?>
                  <a href="<?= e($group['link'] . (int) $row['entity_id']) ?>" style="color:var(--gold-soft);">
                    <?= e($title !== '' ? mb_substr($title, 0, 50) : '#' . (int) $row['entity_id']) ?>
                  </a>
                <?php else: ?>
                  <?= e($title !== '' ? mb_substr($title, 0, 50) : '#' . (int) $row['entity_id']) ?>
                <?php endif; ?>
              </td>
              <td style="text-align:right;width:60px;padding-right:0;color:var(--ink-dim);"><?= number_format((int) $row['n']) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="card" style="margin:0;">
    <h2 style="font-size:14px;">Most visited pages</h2>
    <?php if (!$topPaths): ?>
      <p class="sub" style="margin:0;">Nothing yet.</p>
    <?php else: ?>
      <table>
        <?php foreach ($topPaths as $row): ?>
          <tr>
            <td style="padding-left:0;"><code style="font-size:12px;"><?= e((string) $row['path']) ?></code></td>
            <td style="text-align:right;width:60px;padding-right:0;color:var(--ink-dim);"><?= number_format((int) $row['n']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="card" style="margin:0;">
    <h2 style="font-size:14px;">What people searched for</h2>
    <p class="sub" style="margin-bottom:12px;">The clearest signal of content you do not have yet.</p>
    <?php if (!$topSearches): ?>
      <p class="sub" style="margin:0;">No searches recorded in this period.</p>
    <?php else: ?>
      <table>
        <?php foreach ($topSearches as $row): ?>
          <tr>
            <td style="padding-left:0;"><?= e((string) $row['term']) ?></td>
            <td style="text-align:right;width:60px;padding-right:0;color:var(--ink-dim);"><?= number_format((int) $row['n']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="card" style="margin:0;">
    <h2 style="font-size:14px;">Where visitors come from</h2>
    <?php if (!$topReferrers): ?>
      <p class="sub" style="margin:0;">Mostly direct visits, or nothing recorded yet.</p>
    <?php else: ?>
      <table>
        <?php foreach ($topReferrers as $row): ?>
          <tr>
            <td style="padding-left:0;"><?= e((string) $row['referrer_host']) ?></td>
            <td style="text-align:right;width:60px;padding-right:0;color:var(--ink-dim);"><?= number_format((int) $row['n']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <h2 style="font-size:14px;margin-top:18px;">Web vs app</h2>
    <table>
      <tr>
        <td style="padding-left:0;">Website</td>
        <td style="text-align:right;color:var(--ink-dim);"><?= number_format($devices['web']) ?> (<?= $pct($devices['web'], $devices['web'] + $devices['app']) ?>)</td>
      </tr>
      <tr>
        <td style="padding-left:0;">Mobile app</td>
        <td style="text-align:right;color:var(--ink-dim);"><?= number_format($devices['app']) ?> (<?= $pct($devices['app'], $devices['web'] + $devices['app']) ?>)</td>
      </tr>
    </table>
  </div>

  <?php if ($byUnit): ?>
    <div class="card" style="margin:0;">
      <h2 style="font-size:14px;">Per church</h2>
      <p class="sub" style="margin-bottom:12px;">Views attributed to a church, so you can see which ones are being found.</p>
      <table>
        <?php foreach ($byUnit as $row): ?>
          <tr>
            <td style="padding-left:0;"><?= e((string) ($unitLabels[(int) $row['org_unit_id']] ?? ('Unit ' . (int) $row['org_unit_id']))) ?></td>
            <td style="text-align:right;width:70px;padding-right:0;color:var(--ink-dim);"><?= number_format((int) $row['n']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>

  <div class="card" style="margin:0;">
    <h2 style="font-size:14px;">Meanwhile, in this period</h2>
    <p class="sub" style="margin-bottom:12px;">Read from their own tables, not from analytics — so nothing is counted twice.</p>
    <table>
      <tr><td style="padding-left:0;">Gifts recorded</td><td style="text-align:right;padding-right:0;color:var(--ink-dim);"><?= number_format($newGiving) ?></td></tr>
      <tr><td style="padding-left:0;">Newcomers added</td><td style="text-align:right;padding-right:0;color:var(--ink-dim);"><?= number_format($newNewcomers) ?></td></tr>
      <tr><td style="padding-left:0;">Comments posted</td><td style="text-align:right;padding-right:0;color:var(--ink-dim);"><?= number_format($newComments) ?></td></tr>
    </table>
  </div>

  <div class="card" style="margin:0;">
    <h2 style="font-size:14px;">All time</h2>
    <p class="sub" style="margin-bottom:12px;">From the daily roll-ups, which are kept even after raw events are pruned.</p>
    <table>
      <?php foreach (Analytics::EVENTS as $event => $label): ?>
        <tr>
          <td style="padding-left:0;"><?= e($label) ?></td>
          <td style="text-align:right;padding-right:0;color:var(--ink-dim);"><?= number_format($lifetime[$event] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<div class="card" id="analytics-settings" style="margin-top:18px;">
  <h2>Analytics settings</h2>
  <form method="post" action="/admin/analytics">
    <?= Csrf::field() ?>
    <input type="hidden" name="verb" value="save_analytics_settings">
    <div class="checkbox-row">
      <input type="checkbox" id="analytics_enabled" name="analytics_enabled" value="1" <?= Analytics::enabled() ? 'checked' : '' ?>>
      <label for="analytics_enabled" style="margin:0;">Collect anonymous traffic analytics</label>
    </div>
    <label for="analytics_retention_days">Keep raw events for (days)</label>
    <input type="number" id="analytics_retention_days" name="analytics_retention_days" min="7" max="3650" value="<?= (int) Analytics::retentionDays() ?>">
    <p class="sub" style="margin-top:-6px;">Daily roll-ups behind “All time” are kept regardless, so long-range trends are never lost.</p>
    <div class="btn-row"><button class="btn" type="submit">Save</button></div>
  </form>

  <h2 style="margin-top:22px;">How this works</h2>
  <ul style="color:var(--ink-dim);font-size:13px;line-height:1.7;margin:0;padding-left:18px;">
    <li><strong>No personal data.</strong> No IP address is stored — visitors are identified by the same rotating device hash the rest of the site uses. Referrers keep only the host, never the full URL.</li>
    <li><strong>Crawlers and link previews are ignored.</strong> A sermon link shared in WhatsApp does not count as a visit.</li>
    <li><strong>Browsers asking not to be tracked are honoured</strong> — the beacon does not send at all for them.</li>
    <li><strong>Raw events are kept for <?= (int) Analytics::retentionDays() ?> days</strong>, then pruned. The daily roll-ups behind “All time” are kept indefinitely.</li>
    <li><strong>Run the roll-up nightly</strong> so long-range history is preserved: <code>php cli/analytics_rollup.php</code> from cron.</li>
    <li>Turn collection on or off in <a href="/admin/settings" style="color:var(--gold-soft);">Settings</a>, and mention analytics in your privacy policy if you have not already.</li>
  </ul>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
