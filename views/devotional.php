<?php
declare(strict_types=1);

/**
 * The public devotional page.
 *
 * $date comes from the route: null for "today", or a Y-m-d from a permalink.
 *
 * Today's entry is shown in full, with the days either side one click away, because what a
 * reader wants from this page is today's — not a list to search through. The archive list
 * underneath is for catching up, not for finding today.
 *
 * When a day has no entry the page says so plainly and offers the most recent one instead of
 * showing an empty shell, which is what a reader would otherwise read as a broken page.
 */
$requested = (string) (($date ?? null) ?: date('Y-m-d'));
$today = date('Y-m-d');
$entry = Devotional::forDate($requested);

$prevDay = date('Y-m-d', strtotime($requested . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($requested . ' +1 day'));
$isToday = $requested === $today;
$isFuture = $requested > $today;

$recent = Devotional::latest(14);
$fallback = null;
if ($entry === null) {
    foreach ($recent as $candidate) {
        $fallback = $candidate;
        break;
    }
}
?>

<article style="max-width:720px;margin:0 auto;padding:28px 18px 56px;">

  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
    <span style="font-size:12px;letter-spacing:0.08em;text-transform:uppercase;opacity:0.65;">
      <?= $isToday ? 'Today' : e(date('l', strtotime($requested))) ?>
    </span>
    <span style="font-size:13px;opacity:0.7;"><?= e(date('j F Y', strtotime($requested))) ?></span>
  </div>

  <?php if ($entry): ?>
    <h1 style="font-size:30px;line-height:1.2;margin:0 0 14px;"><?= e((string) $entry['title']) ?></h1>

    <?php if (!empty($entry['scripture_reference'])): ?>
      <p style="font-size:14px;letter-spacing:0.04em;text-transform:uppercase;opacity:0.7;margin:0 0 14px;">
        <?= e((string) $entry['scripture_reference']) ?>
      </p>
    <?php endif; ?>

    <?php if (!empty($entry['scripture_text'])): ?>
      <blockquote style="margin:0 0 22px;padding:14px 18px;border-left:3px solid var(--gold,#d4af37);background:rgba(212,175,55,0.07);border-radius:0 10px 10px 0;font-style:italic;line-height:1.65;">
        <?= nl2br(e((string) $entry['scripture_text'])) ?>
      </blockquote>
    <?php endif; ?>

    <?php if (trim((string) ($entry['body'] ?? '')) !== ''): ?>
      <div style="font-size:17px;line-height:1.75;"><?= nl2br(e((string) $entry['body'])) ?></div>
    <?php endif; ?>

    <?php if (!empty($entry['audio_path'])): ?>
      <audio controls preload="none" style="width:100%;margin-top:22px;">
        <source src="<?= e((string) uploadUrl((string) $entry['audio_path'])) ?>">
        Your browser does not support audio playback.
      </audio>
    <?php endif; ?>

  <?php else: ?>
    <h1 style="font-size:30px;line-height:1.2;margin:0 0 14px;">
      <?= $isFuture ? 'Not published yet' : 'No devotional for this day' ?>
    </h1>
    <p style="font-size:16px;line-height:1.7;opacity:0.8;">
      <?php if ($isFuture): ?>
        That day has not arrived yet. Today's devotional is the one to read.
      <?php else: ?>
        There is nothing written for <?= e(date('j F Y', strtotime($requested))) ?>.
      <?php endif; ?>
    </p>

    <?php if ($fallback): ?>
      <p style="font-size:16px;line-height:1.7;">
        The most recent one is
        <a href="/devotional/<?= e((string) $fallback['publish_on']) ?>" style="font-weight:600;"><?= e((string) $fallback['title']) ?></a>
        from <?= e(date('j F Y', strtotime((string) $fallback['publish_on']))) ?>.
      </p>
    <?php endif; ?>

    <?php if (!$isToday): ?>
      <p><a href="/devotional">Go to today's devotional</a></p>
    <?php endif; ?>
  <?php endif; ?>

  <div style="display:flex;justify-content:space-between;gap:12px;margin-top:36px;padding-top:18px;border-top:1px solid rgba(0,0,0,0.12);font-size:14px;">
    <span><a href="/devotional/<?= e($prevDay) ?>">&larr; <?= e(date('j M', strtotime($prevDay))) ?></a></span>
    <?php if ($nextDay <= $today): ?>
      <span><a href="/devotional/<?= e($nextDay) ?>"><?= e(date('j M', strtotime($nextDay))) ?> &rarr;</a></span>
    <?php endif; ?>
  </div>

  <?php if ($recent): ?>
    <h2 style="font-size:17px;margin:40px 0 10px;">Earlier devotionals</h2>
    <ul style="list-style:none;padding:0;margin:0;">
      <?php foreach ($recent as $item): ?>
        <?php if ((string) $item['publish_on'] === $requested) { continue; } ?>
        <li style="padding:10px 0;border-top:1px solid rgba(0,0,0,0.08);">
          <a href="/devotional/<?= e((string) $item['publish_on']) ?>" style="font-weight:600;text-decoration:none;color:inherit;">
            <?= e((string) $item['title']) ?>
          </a>
          <span style="opacity:0.7;font-size:13px;display:block;margin-top:2px;">
            <?= e(date('j F Y', strtotime((string) $item['publish_on']))) ?>
            <?php if (!empty($item['scripture_reference'])): ?> · <?= e((string) $item['scripture_reference']) ?><?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

</article>
