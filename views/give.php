<?php
declare(strict_types=1);
$metaTitle = 'Give';
$s = settings();

// Campaign mode: the same page, reached through /give/c/{slug}. One view rather than two, so the
// payment flow, the forms and the validation cannot drift apart between "give" and "give to this".
$campaign = isset($campaign) && is_array($campaign) ? $campaign : null;
$campaignProgress = $campaign !== null ? GivingCampaign::progressFor($campaign) : null;
$openCampaigns = $campaign === null ? GivingCampaign::open(null, 12) : array();
$giveAction = $campaign !== null ? '/give/c/' . $campaign['slug'] : '/give';
$currencySymbol = '₦';

/*
 * Online giving is offered only when the gateway can actually take a payment *and* verify it afterwards —
 * `Payhub::configured()` requires the switch and the secret key, not just one of them.
 *
 * The page used to offer the online tab unconditionally. On an install with no gateway configured, a giver
 * who chose it was sent down a path whose last line marked the donation completed and thanked them for
 * money that was never taken. The tab now appears only when it can work, and `POST /give` refuses the
 * online method outright if it arrives anyway.
 */
$onlineGiving = Payhub::configured();
?>

<div class="container section" style="max-width:880px; padding-top:40px; padding-bottom:80px;">
  <div style="text-align:center; margin-bottom:36px;">
    <span class="eyebrow" style="color:var(--gold-soft); font-weight:700; text-transform:uppercase; letter-spacing:1px; font-size:12px;">Generosity &amp; Faith</span>
    <?php if ($campaign !== null): ?>
      <h1 style="margin:8px 0 12px; font-size:32px;"><?= e((string) $campaign['title']) ?></h1>
      <?php if (trim((string) $campaign['summary']) !== ''): ?>
        <p style="color:var(--ink-dim); max-width:640px; margin:0 auto; font-size:15px; line-height:1.6;"><?= e((string) $campaign['summary']) ?></p>
      <?php endif; ?>
    <?php else: ?>
      <h1 style="margin:8px 0 12px; font-size:32px;">Online Giving &amp; Donations</h1>
      <p style="color:var(--ink-dim); max-width:640px; margin:0 auto; font-size:15px; line-height:1.6;">
        "Each of you should give what you have decided in your heart to give, not reluctantly or under compulsion, for God loves a cheerful giver." — 2 Corinthians 9:7
      </p>
    <?php endif; ?>
  </div>

  <?php if ($msg = flash('give_success')): ?>
    <div class="alert success" style="margin-bottom:24px; padding:16px 20px; background:rgba(16,185,129,0.12); border:1px solid rgba(16,185,129,0.3); color:#34d399; border-radius:10px; font-size:14px; line-height:1.5;"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if ($msg = flash('give_error')): ?>
    <div class="alert error" style="margin-bottom:24px; padding:16px 20px; background:rgba(239,68,68,0.12); border:1px solid rgba(239,68,68,0.3); color:#f87171; border-radius:10px; font-size:14px;"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if ($msg = flash('pledge_ok')): ?>
    <div class="alert success" style="margin-bottom:24px; padding:16px 20px; background:rgba(16,185,129,0.12); border:1px solid rgba(16,185,129,0.3); color:#34d399; border-radius:10px; font-size:14px; line-height:1.5;"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if ($msg = flash('pledge_error')): ?>
    <div class="alert error" style="margin-bottom:24px; padding:16px 20px; background:rgba(239,68,68,0.12); border:1px solid rgba(239,68,68,0.3); color:#f87171; border-radius:10px; font-size:14px;"><?= e($msg) ?></div>
  <?php endif; ?>

  <?php if ($campaign !== null): ?>
    <?php
    // The bar moves on money received and nothing else. Pledges are shown beside it, never added in:
    // a church that reports promises as income is reporting money it does not have.
    $raised = (float) $campaignProgress['raised'];
    $goal = (float) $campaignProgress['goal'];
    $percent = (float) $campaignProgress['percent'];
    $daysLeft = GivingCampaign::daysLeft($campaign);
    ?>
    <div class="card glass-card" style="padding:26px; border-radius:16px; margin-bottom:28px;">
      <?php if ($goal > 0): ?>
        <div style="display:flex; justify-content:space-between; align-items:baseline; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
          <div style="font-size:26px; font-weight:800; color:var(--gold-soft);"><?= $currencySymbol ?><?= number_format($raised, 2) ?></div>
          <div style="color:var(--ink-dim); font-size:14px;">of <?= $currencySymbol ?><?= number_format($goal, 2) ?> <span style="opacity:0.75;">(<?= rtrim(rtrim(number_format($percent, 1), '0'), '.') ?>%)</span></div>
        </div>
        <div style="height:12px; border-radius:999px; background:rgba(255,255,255,0.08); overflow:hidden;">
          <div style="height:100%; width:<?= $percent ?>%; background:linear-gradient(90deg,var(--gold),var(--gold-soft)); border-radius:999px;"></div>
        </div>
        <p style="margin:10px 0 0; color:var(--ink-dim); font-size:13px;">
          <?php if ((float) $campaignProgress['remaining'] <= 0): ?>
            The target has been reached — thank you. Gifts are still welcome.
          <?php else: ?>
            <?= $currencySymbol ?><?= number_format((float) $campaignProgress['remaining'], 2) ?> still needed
          <?php endif; ?>
          <?php if ((int) $campaignProgress['donors'] > 0): ?>
            · <?= (int) $campaignProgress['donors'] ?> giver<?= (int) $campaignProgress['donors'] === 1 ? '' : 's' ?>
          <?php endif; ?>
          <?php if ($daysLeft !== null && $daysLeft >= 0): ?>
            · <?= $daysLeft === 0 ? 'ends today' : $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left' ?>
          <?php endif; ?>
        </p>
      <?php else: ?>
        <p style="margin:0; color:var(--ink-dim); font-size:14px;">
          <?= $currencySymbol ?><?= number_format($raised, 2) ?> given so far
          <?php if ((int) $campaignProgress['donors'] > 0): ?> by <?= (int) $campaignProgress['donors'] ?> giver<?= (int) $campaignProgress['donors'] === 1 ? '' : 's' ?><?php endif; ?>.
        </p>
      <?php endif; ?>

      <?php if ((float) $campaignProgress['pledged'] > 0): ?>
        <p style="margin:14px 0 0; padding-top:12px; border-top:1px solid var(--border-soft); color:var(--ink-dim); font-size:13px;">
          In addition, <strong style="color:var(--gold-soft);"><?= $currencySymbol ?><?= number_format((float) $campaignProgress['pledged'], 2) ?></strong>
          has been promised and not yet given — <em>pledges are not counted above</em>, because a promise is not money.
        </p>
      <?php endif; ?>

      <?php if (GivingCampaign::status($campaign) === 'closed'): ?>
        <p style="margin:14px 0 0; padding-top:12px; border-top:1px solid var(--border-soft); color:var(--ink-dim); font-size:13px;">
          This campaign has closed and is no longer taking gifts. The totals above are final.
        </p>
      <?php elseif (GivingCampaign::status($campaign) === 'upcoming'): ?>
        <p style="margin:14px 0 0; padding-top:12px; border-top:1px solid var(--border-soft); color:var(--ink-dim); font-size:13px;">
          This campaign opens on <?= e(date('j F Y', strtotime((string) $campaign['starts_on']))) ?>.
        </p>
      <?php endif; ?>
    </div>

    <?php if (trim((string) $campaign['description']) !== ''): ?>
      <div class="card glass-card" style="padding:24px; border-radius:16px; margin-bottom:28px; line-height:1.7; font-size:15px; color:var(--ink-dim); white-space:pre-line;"><?= e((string) $campaign['description']) ?></div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($campaign === null && $openCampaigns): ?>
    <div style="margin-bottom:36px;">
      <h2 style="font-size:20px; margin:0 0 6px;">Give to a project</h2>
      <p style="color:var(--ink-dim); font-size:14px; margin:0 0 16px;">These are the campaigns currently open. Every gift below is recorded against the project it was given to.</p>
      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:16px;">
        <?php foreach ($openCampaigns as $c): ?>
          <?php
          $cRaised = (float) $c['progress']['raised'];
          $cGoal = (float) $c['progress']['goal'];
          $cPercent = (float) $c['progress']['percent'];
          ?>
          <a href="/give/c/<?= e((string) $c['slug']) ?>" class="glass-card" style="display:block; padding:18px; border-radius:14px; text-decoration:none; color:inherit;">
            <strong style="display:block; font-size:15px; margin-bottom:4px;"><?= e((string) $c['title']) ?></strong>
            <?php if (trim((string) $c['summary']) !== ''): ?>
              <span style="display:block; color:var(--ink-dim); font-size:13px; line-height:1.5; margin-bottom:10px;"><?= e((string) $c['summary']) ?></span>
            <?php endif; ?>
            <?php if ($cGoal > 0): ?>
              <div style="height:8px; border-radius:999px; background:rgba(255,255,255,0.08); overflow:hidden; margin-bottom:8px;">
                <div style="height:100%; width:<?= $cPercent ?>%; background:linear-gradient(90deg,var(--gold),var(--gold-soft));"></div>
              </div>
              <span style="color:var(--ink-dim); font-size:12.5px;">
                <?= $currencySymbol ?><?= number_format($cRaised, 0) ?> of <?= $currencySymbol ?><?= number_format($cGoal, 0) ?>
              </span>
            <?php else: ?>
              <span style="color:var(--ink-dim); font-size:12.5px;"><?= $currencySymbol ?><?= number_format($cRaised, 0) ?> given so far</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($campaign !== null && !GivingCampaign::acceptsGifts($campaign)): ?>
    <div class="card glass-card" style="padding:32px; border-radius:16px; text-align:center;">
      <p style="margin:0 0 14px; color:var(--ink-dim);">This campaign is not taking gifts at the moment.</p>
      <a class="btn btn-gold" href="/give" style="padding:12px 22px;">Give to the church instead</a>
    </div>
  <?php else: ?>

  <!-- Giving Card Container -->
  <div class="card glass-card" style="padding:32px; border-radius:16px; margin-bottom:40px;">
    <!-- Giving Method Toggle Buttons -->
    <div style="display:flex; gap:12px; margin-bottom:28px; border-bottom:1px solid var(--border); padding-bottom:16px; flex-wrap:wrap;">
      <?php if ($onlineGiving): ?>
        <button type="button" id="tab_online_btn" class="btn btn-gold" onclick="switchGivingMethod('online')" style="flex:1; min-width:180px; padding:12px 16px; font-weight:600;">💳 Online Payment (Payhub)</button>
      <?php endif; ?>
      <button type="button" id="tab_manual_btn" class="btn <?= $onlineGiving ? 'secondary' : 'btn-gold' ?>" onclick="switchGivingMethod('manual_bank')" style="flex:1; min-width:180px; padding:12px 16px; font-weight:600;">🏦 Manual Bank Transfer</button>
    </div>

    <?php if (!$onlineGiving): ?>
      <p style="margin:0 0 18px; padding:12px 14px; border-radius:10px; background:rgba(212,175,55,0.10); border:1px solid rgba(212,175,55,0.25); font-size:13.5px;">
        Card payment is not available at the moment, so bank transfer is the way to give right now. Your
        receipt is verified by the finance team before it is counted.
      </p>
    <?php endif; ?>

    <?php if ($campaign !== null): ?>
      <p style="margin:0 0 18px; padding:12px 14px; border-radius:10px; background:rgba(212,175,55,0.10); border:1px solid rgba(212,175,55,0.25); font-size:13.5px;">
        Your gift is being given towards <strong><?= e((string) $campaign['title']) ?></strong> and will be counted on its progress bar once it is confirmed.
      </p>
    <?php endif; ?>

    <!-- ONLINE PAYMENT FORM -->
    <form id="form_online_give" method="post" action="/give"<?= $onlineGiving ? '' : ' style="display:none;"' ?>>
      <?= Csrf::field() ?>
      <input type="hidden" name="payment_method" value="online">
      <?php if ($campaign !== null): ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
      <?php endif; ?>

      <!-- Category & Preset Amount selection -->
      <div style="margin-bottom:20px;">
        <label for="online_category" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Giving Category *</label>
        <select id="online_category" name="category" required style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:#1a1728; color:inherit; font-size:14px;">
          <option value="Tithe">Tithe</option>
          <option value="Offering">Offering</option>
          <option value="Missions">Missions &amp; Outreach</option>
          <option value="Building Fund">Building &amp; Development Fund</option>
          <option value="Special Seed">Special Seed / Faith Pledge</option>
          <option value="Thanksgiving">Thanksgiving</option>
          <option value="Other">Other Contribution</option>
        </select>
      </div>

      <!-- Quick Amount Chips -->
      <div style="margin-bottom:20px;">
        <label style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Select Amount (NGN)</label>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
          <button type="button" class="btn secondary sm" onclick="setGiveAmount('online', 1000)" style="padding:6px 14px; font-size:13px;">₦1,000</button>
          <button type="button" class="btn secondary sm" onclick="setGiveAmount('online', 2500)" style="padding:6px 14px; font-size:13px;">₦2,500</button>
          <button type="button" class="btn secondary sm" onclick="setGiveAmount('online', 5000)" style="padding:6px 14px; font-size:13px;">₦5,000</button>
          <button type="button" class="btn secondary sm" onclick="setGiveAmount('online', 10000)" style="padding:6px 14px; font-size:13px;">₦10,000</button>
          <button type="button" class="btn secondary sm" onclick="setGiveAmount('online', 25000)" style="padding:6px 14px; font-size:13px;">₦25,000</button>
          <button type="button" class="btn secondary sm" onclick="setGiveAmount('online', 50000)" style="padding:6px 14px; font-size:13px;">₦50,000</button>
        </div>
        <input type="number" id="online_amount" name="amount" min="100" step="100" required placeholder="Enter Custom Amount (₦)" style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:15px; font-weight:600;">
      </div>

      <!-- Donor Details -->
      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:20px;">
        <div>
          <label for="online_name" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Full Name</label>
          <input type="text" id="online_name" name="donor_name" placeholder="John Doe (Optional)" style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:14px;">
        </div>
        <div>
          <label for="online_email" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Email Address *</label>
          <input type="email" id="online_email" name="donor_email" required placeholder="your.email@example.com" style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:14px;">
        </div>
        <div>
          <label for="online_phone" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Phone Number</label>
          <input type="tel" id="online_phone" name="donor_phone" placeholder="+234..." style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:14px;">
        </div>
      </div>

      <!-- Payment Description / Purpose -->
      <div style="margin-bottom:24px;">
        <label for="online_desc" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Payment Description / Giving Purpose / Note</label>
        <textarea id="online_desc" name="description" rows="2" placeholder="e.g. Tithe for the month of September, or Special thanksgiving offering..." style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:14px; line-height:1.5;"></textarea>
      </div>

      <button type="submit" class="btn btn-gold" style="width:100%; padding:14px; font-size:16px; font-weight:700;">Proceed to Payhub Payment Gateway 💳</button>
    </form>

    <!-- MANUAL BANK TRANSFER FORM -->
    <form id="form_manual_give" method="post" action="/give" enctype="multipart/form-data" style="display:<?= $onlineGiving ? 'none' : 'block' ?>;">
      <?= Csrf::field() ?>
      <input type="hidden" name="payment_method" value="manual_bank">
      <?php if ($campaign !== null): ?>
        <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
      <?php endif; ?>

      <!-- Bank Details Box -->
      <div style="background:rgba(212,175,55,0.08); border:1px solid rgba(212,175,55,0.25); border-radius:12px; padding:20px; margin-bottom:24px;">
        <strong style="color:var(--gold); font-size:15px; display:block; margin-bottom:8px;">🏦 Church Bank Account Details</strong>
        <div style="white-space:pre-line; color:var(--ink); font-size:14px; line-height:1.6; font-family:monospace;">
          <?= e(setting('manual_payment_instructions') ?: "Bank Name: GTBank\nAccount Name: " . setting('site_title') . "\nAccount Number: 0123456789") ?>
        </div>
      </div>

      <div style="margin-bottom:20px;">
        <label for="manual_category" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Giving Category *</label>
        <select id="manual_category" name="category" required style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:#1a1728; color:inherit; font-size:14px;">
          <option value="Tithe">Tithe</option>
          <option value="Offering">Offering</option>
          <option value="Missions">Missions &amp; Outreach</option>
          <option value="Building Fund">Building &amp; Development Fund</option>
          <option value="Special Seed">Special Seed / Faith Pledge</option>
          <option value="Thanksgiving">Thanksgiving</option>
          <option value="Other">Other Contribution</option>
        </select>
      </div>

      <div style="margin-bottom:20px;">
        <label for="manual_amount" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Transferred Amount (NGN) *</label>
        <input type="number" id="manual_amount" name="amount" min="100" step="100" required placeholder="Amount transferred (₦)" style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:15px; font-weight:600;">
      </div>

      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:20px;">
        <div>
          <label for="manual_name" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Full Name *</label>
          <input type="text" id="manual_name" name="donor_name" required placeholder="John Doe" style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:14px;">
        </div>
        <div>
          <label for="manual_email" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Email Address *</label>
          <input type="email" id="manual_email" name="donor_email" required placeholder="your.email@example.com" style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:14px;">
        </div>
        <div>
          <label for="manual_phone" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Phone Number</label>
          <input type="tel" id="manual_phone" name="donor_phone" placeholder="+234..." style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:14px;">
        </div>
      </div>

      <div style="margin-bottom:20px;">
        <label for="manual_desc" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Payment Description / Giving Purpose / Note</label>
        <textarea id="manual_desc" name="description" rows="2" placeholder="e.g. Bank transfer for September Tithe and Building Fund..." style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:14px; line-height:1.5;"></textarea>
      </div>

      <div style="margin-bottom:24px;">
        <label for="receipt_file" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Upload Bank Transfer Receipt Proof * (Image or PDF)</label>
        <input type="file" id="receipt_file" name="receipt_file" accept="image/*,application/pdf" required style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:14px;">
      </div>

      <button type="submit" class="btn btn-gold" style="width:100%; padding:14px; font-size:16px; font-weight:700;">Submit Bank Transfer Receipt 🧾</button>
    </form>
  </div>

  <?php if ($campaign !== null): ?>
    <?php
    // A pledge form only on a campaign. A promise needs a project to be a promise about something,
    // and offering it on the general giving page would invite people to promise money to nothing in
    // particular — which the church then has to chase without knowing what for.
    ?>
    <div class="card glass-card" style="padding:26px; border-radius:16px; margin-bottom:40px;">
      <h2 style="font-size:18px; margin:0 0 6px;">Pledge to give later</h2>
      <p style="color:var(--ink-dim); font-size:13.5px; margin:0 0 18px; line-height:1.6;">
        If you would like to give towards <strong><?= e((string) $campaign['title']) ?></strong> but the money is
        coming later, you can promise it here. <strong>A pledge is not counted as money received</strong> —
        it is recorded separately so the church can plan, and it appears in the totals only when the gift
        actually arrives.
      </p>
      <form method="post" action="<?= e($giveAction) ?>" id="form_pledge">
        <?= Csrf::field() ?>
        <input type="hidden" name="pledge" value="1">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:16px;">
          <div>
            <label for="pledge_name" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Your Name *</label>
            <input type="text" id="pledge_name" name="donor_name" required maxlength="150" style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:14px;">
          </div>
          <div>
            <label for="pledge_email" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Email Address</label>
            <input type="email" id="pledge_email" name="donor_email" maxlength="190" placeholder="So we can thank you" style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:14px;">
          </div>
          <div>
            <label for="pledge_amount" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Amount Pledged (<?= $currencySymbol ?>) *</label>
            <input type="number" id="pledge_amount" name="amount" min="1" step="100" required style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:15px; font-weight:600;">
          </div>
          <div>
            <label for="pledge_when" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">When you expect to give it</label>
            <input type="date" id="pledge_when" name="promised_on" style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:14px;">
          </div>
        </div>
        <div style="margin-bottom:18px;">
          <label for="pledge_note" style="display:block; margin-bottom:8px; font-weight:600; font-size:14px;">Anything to add</label>
          <input type="text" id="pledge_note" name="note" maxlength="255" placeholder="Optional" style="width:100%; padding:12px 14px; border-radius:8px; border:1px solid var(--border-soft); background:rgba(255,255,255,0.05); color:inherit; font-size:14px;">
        </div>
        <button type="submit" class="btn secondary" style="padding:12px 22px; font-weight:600;">Record my pledge</button>
      </form>
    </div>
  <?php endif; ?>

  <!-- Impact Cards -->
  <div class="grid grid-3">
    <div class="glass-card" style="padding:26px;">
      <h3 style="font-size:16px; margin-top:0;">Tithes &amp; Offerings</h3>
      <p style="color:var(--ink-dim); font-size:13.5px; margin-bottom:0;">Sustaining the ongoing ministry, worship services, and community care across all our churches.</p>
    </div>
    <div class="glass-card" style="padding:26px;">
      <h3 style="font-size:16px; margin-top:0;">Missions &amp; Evangelism</h3>
      <p style="color:var(--ink-dim); font-size:13.5px; margin-bottom:0;">Empowering local and international outreach, youth rallies, and spreading the Gospel.</p>
    </div>
    <div class="glass-card" style="padding:26px;">
      <h3 style="font-size:16px; margin-top:0;">Building &amp; Media Fund</h3>
      <p style="color:var(--ink-dim); font-size:13.5px; margin-bottom:0;">Developing worship centers, digital broadcasting, mobile app, and media infrastructure.</p>
    </div>
  </div>

  <?php endif; ?><?php /* the campaign-has-closed branch opened above */ ?>
</div>

<script>
function switchGivingMethod(method) {
  var formOnline = document.getElementById('form_online_give');
  var formManual = document.getElementById('form_manual_give');
  var btnOnline = document.getElementById('tab_online_btn');
  var btnManual = document.getElementById('tab_manual_btn');

  // The online tab does not exist when the gateway is not configured, so every reference to it is guarded:
  // setting className on null is the kind of error that takes the whole page's script down with it.
  if (!formOnline || !formManual || !btnManual) {
    return;
  }

  if (method === 'manual_bank' || !btnOnline) {
    formOnline.style.display = 'none';
    formManual.style.display = 'block';
    if (btnOnline) { btnOnline.className = 'btn secondary'; }
    btnManual.className = 'btn btn-gold';
  } else {
    formOnline.style.display = 'block';
    formManual.style.display = 'none';
    btnOnline.className = 'btn btn-gold';
    btnManual.className = 'btn secondary';
  }
}

function setGiveAmount(type, val) {
  var input = document.getElementById(type + '_amount');
  if (input) {
    input.value = val;
  }
}
</script>
