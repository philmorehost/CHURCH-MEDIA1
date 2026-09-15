<?php
declare(strict_types=1);

/**
 * GET /advertise/return — what actually happened to the payment.
 *
 * The route has already asked PayHub and written the answer down; this file only reports it. That
 * separation is deliberate: a page is a rendering of a decision, and the decision has to be made whether
 * or not anybody looks at this page — a webhook can arrive first, and an advertiser can close the tab
 * before the redirect ever lands.
 *
 * @var array<string, mixed> $ad        the advert row after the outcome was recorded
 * @var string               $outcome   'paid' | 'failed' | 'pending'
 * @var string               $reason    what the gateway said, empty when it said nothing useful
 * @var int                  $attempts  how many payment attempts this advert now has on record
 * @var int                  $remaining online attempts left before bank transfer is the only option
 */

$metaTitle = 'Payment result';
// One advertiser's own transaction. Nothing here belongs in a search result.
$metaRobots = 'noindex, nofollow';

$tint = [
    'paid' => ['#34d399', 'rgba(52,211,153,0.1)', 'rgba(52,211,153,0.3)'],
    'pending' => ['#fbbf24', 'rgba(251,191,36,0.1)', 'rgba(251,191,36,0.3)'],
    'failed' => ['#f87171', 'rgba(239,68,68,0.1)', 'rgba(239,68,68,0.3)'],
];
[$textColour, $bgColour, $borderColour] = $tint[$outcome] ?? $tint['failed'];

$headline = [
    'paid' => 'Payment received',
    'pending' => 'Payment not confirmed yet',
    'failed' => 'Payment was not completed',
][$outcome] ?? 'Payment was not completed';
?>
<div class="container section" style="max-width:640px; padding-top:40px; padding-bottom:60px;">
  <div class="glass-card" style="padding:32px; border-radius:12px; text-align:center;">

    <div style="font-size:44px; margin-bottom:10px;">
      <?= $outcome === 'paid' ? '✅' : ($outcome === 'pending' ? '⏳' : '⚠️') ?>
    </div>

    <h1 style="font-size:24px; margin:0 0 12px;"><?= e($headline) ?></h1>

    <div style="padding:12px 16px; background:<?= e($bgColour) ?>; border:1px solid <?= e($borderColour) ?>; color:<?= e($textColour) ?>; border-radius:8px; font-size:14px; text-align:left; margin-bottom:22px;">
      <?php if ($outcome === 'paid'): ?>
        Your advert <strong><?= e((string) $ad['title']) ?></strong> is paid for and is now waiting for our
        team to review it. Nothing else is needed from you.
      <?php elseif ($outcome === 'pending'): ?>
        The gateway has the payment but has not confirmed it yet. Your advert stays on file.
        <?php if ($reason !== ''): ?><br>Gateway: <?= e($reason) ?><?php endif; ?>
      <?php else: ?>
        Nothing has been charged.
        <?php if ($reason !== ''): ?><br>Gateway: <?= e($reason) ?><?php endif; ?>
      <?php endif; ?>
    </div>

    <table style="width:100%; font-size:13px; color:var(--ink-dim); border-collapse:collapse; text-align:left; margin-bottom:22px;">
      <tr>
        <td style="padding:7px 0; color:var(--ink-faint);">Advert</td>
        <td style="padding:7px 0; text-align:right;"><?= e((string) $ad['title']) ?></td>
      </tr>
      <tr>
        <td style="padding:7px 0; color:var(--ink-faint);">Amount</td>
        <td style="padding:7px 0; text-align:right;">₦<?= e(number_format((float) $ad['price'], 2)) ?></td>
      </tr>
      <tr>
        <td style="padding:7px 0; color:var(--ink-faint);">Reference</td>
        <td style="padding:7px 0; text-align:right;"><code><?= e((string) $ad['payment_reference']) ?></code></td>
      </tr>
      <tr>
        <td style="padding:7px 0; color:var(--ink-faint);">Payment attempts</td>
        <td style="padding:7px 0; text-align:right;"><?= (int) $attempts ?></td>
      </tr>
    </table>

    <?php if ($outcome !== 'paid'): ?>
      <p style="font-size:13.5px; color:var(--ink-dim); margin:0 0 18px;">
        <?php if ($remaining > 0): ?>
          You can try the card payment again — you have
          <?= (int) $remaining ?> <?= $remaining === 1 ? 'attempt' : 'attempts' ?> left before bank transfer
          becomes the only option.
        <?php else: ?>
          Card payment has not worked, so please pay by bank transfer and upload the receipt.
        <?php endif; ?>
      </p>

      <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
        <a class="btn btn-ghost" href="/advertise?sent=1">Go to my advert</a>
        <?php /* The retry route and the bank-transfer upload are 7h-4. Until they exist this is honest
                 about where things stand rather than offering a button that leads nowhere. */ ?>
        <a class="btn btn-outline" href="/ad-manager">Open the Publisher Portal</a>
      </div>
    <?php else: ?>
      <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
        <a class="btn btn-gold" href="/advertise?sent=1">Done</a>
        <a class="btn btn-ghost" href="/ad-manager">Open the Publisher Portal</a>
      </div>
    <?php endif; ?>
  </div>
</div>
