<?php
declare(strict_types=1);
$metaTitle = 'Give';
$s = settings();
?>

<div class="container section" style="max-width:880px; padding-top:40px; padding-bottom:80px;">
  <div style="text-align:center; margin-bottom:36px;">
    <span class="eyebrow" style="color:var(--gold-soft); font-weight:700; text-transform:uppercase; letter-spacing:1px; font-size:12px;">Generosity &amp; Faith</span>
    <h1 style="margin:8px 0 12px; font-size:32px;">Online Giving &amp; Donations</h1>
    <p style="color:var(--ink-dim); max-width:640px; margin:0 auto; font-size:15px; line-height:1.6;">
      "Each of you should give what you have decided in your heart to give, not reluctantly or under compulsion, for God loves a cheerful giver." — 2 Corinthians 9:7
    </p>
  </div>

  <?php if ($msg = flash('give_success')): ?>
    <div class="alert success" style="margin-bottom:24px; padding:16px 20px; background:rgba(16,185,129,0.12); border:1px solid rgba(16,185,129,0.3); color:#34d399; border-radius:10px; font-size:14px; line-height:1.5;"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if ($msg = flash('give_error')): ?>
    <div class="alert error" style="margin-bottom:24px; padding:16px 20px; background:rgba(239,68,68,0.12); border:1px solid rgba(239,68,68,0.3); color:#f87171; border-radius:10px; font-size:14px;"><?= e($msg) ?></div>
  <?php endif; ?>

  <!-- Giving Card Container -->
  <div class="card glass-card" style="padding:32px; border-radius:16px; margin-bottom:40px;">
    <!-- Giving Method Toggle Buttons -->
    <div style="display:flex; gap:12px; margin-bottom:28px; border-bottom:1px solid var(--border); padding-bottom:16px; flex-wrap:wrap;">
      <button type="button" id="tab_online_btn" class="btn btn-gold" onclick="switchGivingMethod('online')" style="flex:1; min-width:180px; padding:12px 16px; font-weight:600;">💳 Online Payment (Payhub)</button>
      <button type="button" id="tab_manual_btn" class="btn secondary" onclick="switchGivingMethod('manual_bank')" style="flex:1; min-width:180px; padding:12px 16px; font-weight:600;">🏦 Manual Bank Transfer</button>
    </div>

    <!-- ONLINE PAYMENT FORM -->
    <form id="form_online_give" method="post" action="/give">
      <?= Csrf::field() ?>
      <input type="hidden" name="payment_method" value="online">

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
    <form id="form_manual_give" method="post" action="/give" enctype="multipart/form-data" style="display:none;">
      <?= Csrf::field() ?>
      <input type="hidden" name="payment_method" value="manual_bank">

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

  <!-- Impact Cards -->
  <div class="grid grid-3">
    <div class="glass-card" style="padding:26px;">
      <h3 style="font-size:16px; margin-top:0;">Tithes &amp; Offerings</h3>
      <p style="color:var(--ink-dim); font-size:13.5px; margin-bottom:0;">Sustaining the ongoing ministry, worship services, and community care across all our parishes.</p>
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
</div>

<script>
function switchGivingMethod(method) {
  var formOnline = document.getElementById('form_online_give');
  var formManual = document.getElementById('form_manual_give');
  var btnOnline = document.getElementById('tab_online_btn');
  var btnManual = document.getElementById('tab_manual_btn');

  if (method === 'manual_bank') {
    formOnline.style.display = 'none';
    formManual.style.display = 'block';
    btnOnline.className = 'btn secondary';
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
