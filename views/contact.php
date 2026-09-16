<?php
declare(strict_types=1);
$metaTitle = 'Contact';
$s = settings();
// The easy spam check: a sum to answer, issued fresh per page load and checked on the server.
$captcha = contactCaptchaIssue();
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
        <div class="form-field">
          <label for="captcha">Spam check — what is <span data-captcha-question><?= e($captcha['question']) ?></span>?</label>
          <input type="text" id="captcha" name="captcha" inputmode="numeric" autocomplete="off" required>
          <input type="hidden" name="captcha_token" value="<?= e($captcha['nonce']) ?>">
        </div>
        <button class="btn btn-gold btn-block" type="submit">Send Message</button>
      </form>

      <div>
        <div class="glass-card" style="padding:28px; margin-bottom:20px;">
          <h3 style="font-size:16px;">Get in Touch</h3>
          <?php if ($s['address'] ?? null): ?><p style="color:var(--ink-dim);">📍 <?= e($s['address']) ?></p><?php endif; ?>
          <?php if (!empty($s['contact_phone'])): ?>
            <?php
              $phones = array_filter(array_map('trim', preg_split('/[,\n]+/', (string) $s['contact_phone']) ?: []));
            ?>
            <?php foreach ($phones as $phone): ?>
              <p style="color:var(--ink-dim); margin-bottom:6px;">📞 <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>" style="color:var(--gold-soft);"><?= e($phone) ?></a></p>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php if ($s['contact_email'] ?? null): ?><p style="color:var(--ink-dim); margin:0;">✉️ <a href="mailto:<?= e($s['contact_email']) ?>" style="color:var(--gold-soft);"><?= e($s['contact_email']) ?></a></p><?php endif; ?>
          <?php
            // WhatsApp uses the number the church publishes as its contact phone, falling back to the
            // super admin's own profile only when that box is blank. Reading the setting first is what
            // makes the button appear for every church that has filled in "Contact Phone" - the field
            // they actually maintain - instead of depending on a phone number on a user record that
            // nobody remembers to fill in.
            $waRaw = '';
            $waParts = array_filter(array_map('trim', preg_split('/[,\n]+/', (string) ($s['contact_phone'] ?? '')) ?: []));
            if ($waParts) {
                $waRaw = (string) reset($waParts); // the first of however many are listed
            }
            if ($waRaw === '') {
                try {
                    $waRow = Database::getInstance()->getConnection()->query(
                        "SELECT phone FROM users WHERE is_super_admin = 1 AND phone IS NOT NULL AND phone <> '' ORDER BY id ASC LIMIT 1"
                    );
                    $waRaw = trim((string) $waRow->fetchColumn());
                } catch (Throwable $e) {
                    $waRaw = '';
                }
            }
            // wa.me wants country code and digits only. The SMS normaliser already knows how to turn a
            // local number into that, and is the same routine the member forms trust.
            $waNumber = $waRaw !== ''
                ? (string) (Sms::normaliseMsisdn($waRaw) ?? preg_replace('/[^0-9]/', '', $waRaw))
                : '';
          ?>
          <?php if ($waNumber !== ''): ?>
            <a class="btn btn-whatsapp" href="https://wa.me/<?= e($waNumber) ?>?text=<?= e(rawurlencode('Hello ' . ($s['site_title'] ?? '') . ', I found you on your website.')) ?>"
               target="_blank" rel="noopener"
               aria-label="Chat with us on WhatsApp"
               style="margin-top:16px;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false">
                  <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.347-.347.52-.52.174-.174.232-.298.347-.497.116-.199.058-.372-.029-.52-.087-.15-.664-1.601-.91-2.19-.24-.575-.484-.5-.664-.509-.172-.009-.37-.011-.568-.011-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                </svg>
                Chat on WhatsApp
            </a>
          <?php endif; ?>
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
