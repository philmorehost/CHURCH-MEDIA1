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
 * @var string               $reference the reference the advertiser arrived with
 * @var string               $outcome   'paid' | 'failed' | 'pending'
 * @var string               $reason    what the gateway said, empty when it said nothing useful
 * @var int                  $attempts  how many payment attempts this advert now has on record
 * @var int                  $remaining online attempts left before bank transfer is the only option
 * @var bool                 $canRetry  whether a card payment may be attempted again at all
 * @var bool                 $manualEnabled
 * @var string               $manualInstructions the church's own bank details
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

  <?php
  /*
   * The message from whatever sent the advertiser here — a receipt that could not be read, a card payment
   * that could not be started.
   *
   * This page did not render it, and every refusal in the proof handler redirects to exactly here: so an
   * advertiser who chose a PDF or a 40MB photo was bounced back to a report with no explanation whatsoever,
   * and the sentence written for them was read by nobody. A flash message that no page displays is not a
   * message.
   */
  ?>
  <?php if ($msg = flash('advertise_error')): ?>
    <div style="margin-bottom:20px; padding:12px 16px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); color:#f87171; border-radius:8px; font-size:14px;"><?= e($msg) ?></div>
  <?php endif; ?>

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
        <?php if ($outcome === 'pending'): ?>
          <?php
          /*
           * A payment the gateway has taken but not yet confirmed — which is the normal shape of a bank
           * transfer, and of a card charge that is still settling. The honest thing to say is that it is not
           * confirmed *yet*, and to give the advertiser something to do about it: this page re-asks the
           * gateway every time it is loaded, so reloading it is the check.
           *
           * Not a failure, and deliberately not counted as one — the attempt stays pending, so it does not
           * consume one of the advertiser's two tries. See AdPayments, rule 3.
           */
          ?>
          Your payment has not been confirmed by the gateway yet. A bank transfer can take a minute or two
          to appear, so if you have just sent it, check again shortly.
        <?php elseif ($canRetry): ?>
          You can try the card payment again — you have
          <?= (int) $remaining ?> <?= $remaining === 1 ? 'attempt' : 'attempts' ?> left before bank transfer
          becomes the only option.
        <?php else: ?>
          Card payment has not worked after <?= (int) $attempts ?> attempts, so please pay by bank transfer.
        <?php endif; ?>
      </p>

      <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
        <?php if ($outcome === 'pending'): ?>
          <?php /* A plain link, not a POST: this page only re-asks the gateway and records what it answers,
                   every write on it is guarded to fire once, and a paid advert is only ever read — so
                   reloading it can never take a second payment or undo one. */ ?>
          <a class="btn btn-gold" href="/advertise/return?ref=<?= e(urlencode($reference)) ?>">Check payment again</a>
        <?php endif; ?>
        <?php if ($canRetry): ?>
          <?php /* A POST, because retrying creates a payment attempt. A GET that creates anything is a GET
                   that a mail client pre-fetches and a crawler follows. */ ?>
          <form method="post" action="/advertise/retry" style="display:inline;">
            <?= Csrf::field() ?>
            <input type="hidden" name="ref" value="<?= e($reference) ?>">
            <button type="submit" class="btn btn-gold">Try the card payment again</button>
          </form>
        <?php endif; ?>
        <a class="btn btn-ghost" href="/advertise?sent=1">Go to my advert</a>
      </div>
    <?php else: ?>
      <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
        <a class="btn btn-gold" href="/advertise?sent=1">Done</a>
        <a class="btn btn-ghost" href="/ad-manager">Open the Publisher Portal</a>
      </div>
    <?php endif; ?>
  </div>

  <?php
  /*
   * Bank transfer.
   *
   * Shown as the only route once the card attempts are used up, and as an alternative before that for
   * anyone who would rather not use a card. The instructions are the church's own — `settings` has carried
   * `manual_payment_instructions` since the ads screens were built, and printing it here is the first time
   * an advertiser has actually been shown it.
   */
  ?>
  <?php if ($outcome !== 'paid' && $manualEnabled): ?>
    <div class="glass-card" style="padding:26px; border-radius:12px; margin-top:20px;">
      <h2 style="font-size:18px; margin:0 0 10px;">
        <?= $canRetry ? 'Or pay by bank transfer' : 'Pay by bank transfer' ?>
      </h2>

      <div style="font-size:13.5px; color:var(--ink-dim); line-height:1.7; margin-bottom:18px;">
        <?php if (trim($manualInstructions) !== ''): ?>
          <?= nl2br(e($manualInstructions)) ?>
        <?php else: ?>
          Please contact the church office for the account to transfer to, then upload your receipt here.
        <?php endif; ?>
      </div>

      <p style="font-size:12.5px; color:var(--ink-faint); margin:0 0 14px;">
        Transfer <strong>₦<?= e(number_format((float) $ad['price'], 2)) ?></strong> and quote
        <code><?= e((string) $ad['payment_reference']) ?></code> as the reference, then upload the receipt
        or a screenshot of it below. Your advert is reviewed once the payment is confirmed.
      </p>

      <form method="post" action="/advertise/proof" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <input type="hidden" name="ref" value="<?= e($reference) ?>">
        <label for="payment_proof" style="display:block; font-size:12.5px; color:var(--ink-dim); margin-bottom:6px; font-weight:600;">
          Receipt or screenshot
        </label>
        <input type="file" id="payment_proof" name="payment_proof" accept="image/*" required
               style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit;">
        <p style="font-size:12px; color:var(--ink-faint); margin:8px 0 14px;">
          A photo or a screenshot is fine. Nothing is charged automatically — our team confirms the transfer.
        </p>
        <button type="submit" class="btn btn-outline">Upload my receipt</button>
      </form>
    </div>
  <?php endif; ?>
</div>
