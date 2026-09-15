<?php
declare(strict_types=1);

/**
 * GET /advertise/checkout — where the advertiser pays, without leaving the site.
 *
 * The route has already checked that this reference belongs to the session that created it. Everything
 * else here is about the payment being takeable *whatever the browser does*:
 *
 *  - **The hosted-checkout form is real markup and always present.** It is a plain POST, so it works with
 *    JavaScript off, with the gateway's script blocked, and with the script loaded but broken. The iframe
 *    is the upgrade, not the floor — which is the only shape that satisfies "if `inline.js` cannot be
 *    loaded the button must say so and fall back", because a fallback that is assembled by the same
 *    JavaScript that failed is not a fallback.
 *  - **Nothing here works without `advertise.js`.** This page renders the settings and the button, but the
 *    script that reads them and calls `PayhubPop.setup()`/`openIframe()` is `public/assets/js/advertise.js`,
 *    and this page did not load it. The result was not a degraded checkout — it was a dead one: the button
 *    sat disabled and the status stayed on "Loading the secure payment window…" for every advertiser, on
 *    every browser, permanently. The harness had asserted the *markup* and the payload, and neither of
 *    those is the thing that takes a payment.
 *  - **And the page must therefore never DEPEND on that script.** It shipped with the hosted-payment form
 *    hidden until JavaScript revealed it — so a missing `advertise.js` (404 on the live site, from a stale
 *    deployment) left the advertiser with a disabled button, a message that never changed, and no way to
 *    pay at all. The hosted form is now always rendered and always visible: a fallback that appears only
 *    when a script reveals it fails together with the script it was meant to cover for.
 *  - **And the page must therefore never DEPEND on that script.** It was shipped once with the hosted-payment
 *    form hidden until JavaScript showed it, which meant a missing `advertise.js` (404 on the live site, for
 *    a stale deployment) left an advertiser with a disabled button, a "loading" message that never changed,
 *    and no way to pay. The hosted form is now always rendered and always visible: a fallback that appears
 *    only when a script reveals it fails with the script it was meant to cover for.
 *  - **The server decides the amount, not the page.** `amountInKobo()` is called here from the advert row;
 *    nothing on this page can be edited to change what is charged, and the public key in the markup cannot
 *    be used to charge a different figure because the reference is what the server verifies.
 *  - **The public key is the only key here.** The secret key never reaches a browser — that separation is
 *    what keeps this application out of PCI scope, since the card form is drawn inside the gateway's own
 *    frame and no card detail is ever posted to this server.
 *
 * @var array<string, mixed> $ad       the advert row, with publisher_name/publisher_email joined in
 * @var string               $returnTo root-relative return URL, built from the route
 */

$reference = (string) $ad['payment_reference'];
$amount = (float) $ad['price'];

$metaTitle = 'Complete your payment';
$metaDescription = 'Pay for your advert on ' . setting('site_title') . '.';
// A checkout for one advertiser's one advert. There is nothing here for a crawler to index, and a
// search result pointing at it would be a link that takes a stranger to somebody's payment page.
$metaRobots = 'noindex, nofollow';

$inlineReady = Payhub::inlineReady();

/*
 * What the checkout script is given. `amount` is in KOBO — see Payhub::amountInKobo() — because the
 * gateway documents kobo and a naira figure here would charge a hundredth of the price. It is passed as
 * JSON in a data attribute rather than interpolated into a JavaScript literal, so no value can end the
 * attribute or the string it lands in.
 */
$inlineSettings = [
    'key' => Payhub::publicKey(),
    'email' => (string) ($ad['publisher_email'] ?? ''),
    'amount' => Payhub::amountInKobo($amount),
    'ref' => $reference,
    'returnUrl' => $returnTo,
];
?>
<div class="container section" style="max-width:640px; padding-top:40px; padding-bottom:60px;">

  <?php if ($msg = flash('advertise_error')): ?>
    <div style="margin-bottom:20px; padding:12px 16px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); color:#f87171; border-radius:8px; font-size:14px;"><?= e($msg) ?></div>
  <?php endif; ?>

  <div style="text-align:center; margin-bottom:28px;">
    <span class="eyebrow" style="color:var(--gold-soft); font-weight:700; text-transform:uppercase; letter-spacing:1px; font-size:13px;">Step 2 of 2</span>
    <h1 style="margin:8px 0 10px; font-size:28px;">Complete your payment</h1>
    <p style="color:var(--ink-dim); font-size:14.5px; margin:0;">
      <?= e((string) $ad['title']) ?>
    </p>
  </div>

  <div class="glass-card" style="padding:28px; border-radius:12px; text-align:center;">

    <div style="font-size:13px; color:var(--ink-faint); margin-bottom:6px;">Amount due</div>
    <div style="font-size:34px; font-weight:800; color:var(--gold-soft); margin-bottom:20px;">
      ₦<?= e(number_format($amount, 2)) ?>
    </div>

    <div style="font-size:12.5px; color:var(--ink-faint); margin-bottom:24px;">
      Reference <code style="color:var(--ink-dim);"><?= e($reference) ?></code>
    </div>

    <?php if ($inlineReady): ?>
      <?php /* The settings the checkout script reads. `data-payhub` holds JSON, added with e() so a quote
               in an email address cannot break out of the attribute. */ ?>
      <div id="payhub-inline" data-payhub="<?= e((string) json_encode($inlineSettings, JSON_UNESCAPED_SLASHES)) ?>"></div>

      <button type="button" id="payhub-pay" class="btn btn-gold" disabled
              style="padding:13px 30px; font-size:16px; font-weight:700;">
        Pay ₦<?= e(number_format($amount, 2)) ?> securely
      </button>

      <p id="payhub-status" style="font-size:12.5px; color:var(--ink-faint); margin:14px 0 0;">
        Loading the secure payment window…
      </p>
    <?php else: ?>
      <p style="font-size:13.5px; color:var(--ink-dim); margin:0 0 4px;">
        Card payment opens on PayHub's own secure page. Use the button below — the payment is the same, and
        our team confirms your advert as soon as it is seen.
      </p>
    <?php endif; ?>

    <?php /* Loaded only when the inline checkout can actually run. The route's CSP exception is scoped to
             this gateway origin and to this page, so nothing else on the site can load it. */ ?>
    <?php if ($inlineReady): ?>
      <?php /* Order matters: both scripts are deferred, and deferred scripts run in document order, so
               inline.js defines PayhubPop before advertise.js asks for it. */ ?>
      <script src="<?= e(Payhub::INLINE_SCRIPT) ?>" defer></script>
    <?php endif; ?>
  </div>

  <?php /*
   * The alternative route, and it is ALWAYS on the page.
   *
   * It used to be hidden until JavaScript revealed it, which made the entire page depend on
   * public/assets/js/advertise.js arriving — and on the live site it did not arrive (404, from a stale
   * deployment), so every advertiser was left with a disabled button, a "loading" message that never
   * changed, and no way to pay at all. A fallback that only appears when a script says so is not a
   * fallback; it is a second thing that breaks with the first.
   *
   * It is a plain POST with no JavaScript in it, so it works with scripting off, with the gateway's script
   * blocked, and with our own script missing entirely — which is exactly the case that stranded the live
   * site. The button above stays the primary action and gets the inline window when it can.
   */
  ?>
  <div id="payhub-fallback" class="glass-card" style="padding:24px; border-radius:12px; margin-top:20px;">
    <h2 style="font-size:17px; margin:0 0 8px;"><?= $inlineReady ? 'Or pay on the gateway\'s own page' : 'Pay on the gateway\'s own page' ?></h2>
    <p style="font-size:13.5px; color:var(--ink-dim); margin:0 0 16px;">
      The same payment, for the same reference, on PayHub's secure page rather than inside this one. Use this
      if the payment window above does not open — it always works.
    </p>
    <form method="post" action="/advertise/hosted">
      <?= Csrf::field() ?>
      <input type="hidden" name="ref" value="<?= e($reference) ?>">
      <button type="submit" class="btn btn-outline" style="padding:11px 24px;">Continue to secure checkout →</button>
    </form>
  </div>

  <div style="text-align:center; margin-top:26px;">
    <a href="/advertise" style="color:var(--ink-faint); font-size:13px;">Cancel and go back</a>
  </div>
</div>

<?php /*
 * The script that drives the checkout. Without this line the page is inert: nothing reads the settings
 * above, nothing calls the gateway, and the advertiser is left looking at a disabled button and a
 * "loading" message that will never change. It loads after the page's own markup so that it also runs on
 * a church whose inline checkout is unavailable, where it reveals the hosted-payment fallback.
 */ ?>
<script src="<?= asset('js/advertise.js') ?>" defer></script>
