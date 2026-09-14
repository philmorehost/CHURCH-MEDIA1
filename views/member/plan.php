<?php
declare(strict_types=1);

/**
 * The whole reading plan, one checkbox per day.
 *
 * Every day is in a single form. Saving replaces the member's set of read days as a whole, which is
 * what makes unticking work at all, and it lets somebody who has been away tick a week in one go.
 * A form that covered only part of the plan would clear the rest the moment it was submitted.
 *
 * `$plan`, `$progress`, `$days` and `$read` come from the route in core/routes.php.
 */
$notice = flash('member_notice');
$error = flash('member_error');

$total = (int) $plan['days_count'];

/** Days with no passage are rest days, and saying so is clearer than an empty cell. */
$labelFor = static function (int $day) use ($days): string {
    $label = ReadingPlan::labelForDay($days[$day] ?? array());
    return $label !== '' ? $label : 'Rest day';
};
?>
<link rel="stylesheet" href="<?= asset('css/form.css') ?>">

<section class="form-page">
  <div class="form-card" style="max-width:720px;">

    <div class="form-banner">
      <div class="form-mark"><?= e(mb_substr(setting('site_title'), 0, 1)) ?></div>
      <div>
        <div class="form-eyebrow">Reading plan</div>
        <h1 class="form-title"><?= e((string) $plan['name']) ?></h1>
      </div>
    </div>

    <?php if ($notice): ?><div class="form-success" style="padding:14px 18px;margin-bottom:16px;"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>

    <p class="form-desc">
      <?= (int) $progress['done'] ?> of <?= $total ?> days read
      <?php if ($progress['streak'] > 1): ?>&middot; <strong><?= (int) $progress['streak'] ?> days in a row</strong><?php endif; ?>
    </p>

    <div style="height:10px;border-radius:6px;background:rgba(0,0,0,0.10);overflow:hidden;margin:0 0 18px;"
         role="progressbar" aria-valuenow="<?= (int) $progress['percent'] ?>" aria-valuemin="0" aria-valuemax="100">
      <div style="height:100%;width:<?= (int) $progress['percent'] ?>%;background:var(--gold,#d4af37);"></div>
    </div>

    <?php if ($plan['description'] ?? null): ?>
      <p class="form-desc"><?= nl2br(e((string) $plan['description'])) ?></p>
    <?php endif; ?>

    <form method="post" action="/member/plan">
      <?= Csrf::field() ?>
      <input type="hidden" name="do" value="days">

      <div style="max-height:60vh;overflow-y:auto;border:1px solid rgba(0,0,0,0.12);border-radius:10px;">
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
          <tbody>
            <?php for ($day = 1; $day <= $total; $day++): ?>
              <?php $isRead = isset($read[$day]); ?>
              <tr style="border-top:1px solid rgba(0,0,0,0.06);<?= $isRead ? 'background:rgba(212,175,55,0.10);' : '' ?>">
                <td style="padding:9px 10px;width:44px;vertical-align:top;">
                  <input type="checkbox" name="days[]" value="<?= $day ?>" id="day<?= $day ?>" <?= $isRead ? 'checked' : '' ?>>
                </td>
                <td style="padding:9px 10px;width:76px;vertical-align:top;white-space:nowrap;opacity:0.7;">Day <?= $day ?></td>
                <td style="padding:9px 10px;vertical-align:top;">
                  <label for="day<?= $day ?>" style="cursor:pointer;"><?= e($labelFor($day)) ?></label>
                </td>
              </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>

      <button type="submit" class="form-submit" style="margin-top:16px;"><span>Save my progress</span></button>
    </form>

    <p class="form-desc" style="margin-top:16px;">
      <a href="/member">Back to my account</a>
    </p>

  </div>
</section>
