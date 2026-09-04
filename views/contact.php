<?php
declare(strict_types=1);
$metaTitle = 'Contact';
$s = settings();
?>

<section class="section" style="padding-top:56px;">
  <div class="container" style="max-width:980px;">
    <div class="section-head">
      <span class="eyebrow">Reach Out</span>
      <h2>Contact Us</h2>
      <p>Questions, prayer needs, or just want to say hello — we'd love to hear from you.</p>
    </div>

    <div class="grid grid-2" style="gap:36px; align-items:start;">
      <form class="glass-card" style="padding:28px;" data-remote-form="/api/contact">
        <div data-form-message class="form-message"></div>
        <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off">
        <div class="form-field">
          <label for="name">Name</label>
          <input type="text" id="name" name="name" required>
        </div>
        <div class="form-field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" required>
        </div>
        <div class="form-field">
          <label for="subject">Subject</label>
          <input type="text" id="subject" name="subject" placeholder="How can we help?">
        </div>
        <div class="form-field">
          <label for="message">Message</label>
          <textarea id="message" name="message" required></textarea>
        </div>
        <button class="btn btn-gold btn-block" type="submit">Send Message</button>
      </form>

      <div>
        <div class="glass-card" style="padding:28px; margin-bottom:20px;">
          <h3 style="font-size:16px;">Get in Touch</h3>
          <?php if ($s['address'] ?? null): ?><p style="color:var(--ink-dim);">📍 <?= e($s['address']) ?></p><?php endif; ?>
          <?php if ($s['contact_phone'] ?? null): ?><p style="color:var(--ink-dim);">📞 <a href="tel:<?= e($s['contact_phone']) ?>" style="color:var(--gold-soft);"><?= e($s['contact_phone']) ?></a></p><?php endif; ?>
          <?php if ($s['contact_email'] ?? null): ?><p style="color:var(--ink-dim); margin:0;">✉️ <a href="mailto:<?= e($s['contact_email']) ?>" style="color:var(--gold-soft);"><?= e($s['contact_email']) ?></a></p><?php endif; ?>
        </div>
        <?php $serviceTimes = $s['service_times'] ? (json_decode((string) $s['service_times'], true) ?: []) : []; ?>
        <?php if ($serviceTimes): ?>
        <div class="glass-card" style="padding:28px;">
          <h3 style="font-size:16px;">Service Times</h3>
          <?php foreach ($serviceTimes as $st): ?>
            <p style="color:var(--ink-dim); margin-bottom:8px;"><strong style="color:var(--ink);"><?= e($st['label']) ?>:</strong> <?= e($st['time']) ?></p>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
