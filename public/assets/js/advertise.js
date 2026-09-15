/*
 * /advertise — the advertiser's form, and the inline checkout that follows it.
 *
 * This file exists because the page used to carry its JavaScript inline, in a <script> block and in
 * `onclick`/`onchange` attributes. On the public site the Content-Security-Policy is `script-src 'self'`,
 * which blocks inline script AND inline event handlers — so in production none of it ran: the free/paid
 * notice never appeared, choosing "Manual Bank Transfer" never revealed the bank details or the proof
 * upload, and switching to "Video Ad" never changed what the file picker would accept. All of it worked
 * on a developer's machine, because the policy is only sent when the site is not running locally, which is
 * exactly the configuration nobody tests in.
 *
 * So the behaviour lives here, under `script-src 'self'`, and the markup carries `data-` attributes
 * instead of handlers. Nothing below is required for the form to be submittable: with JavaScript off the
 * server still validates every field, still refuses an online payment it cannot verify, and the checkout
 * page still offers the hosted payment route as a plain form.
 */
(function () {
  'use strict';

  var byId = function (id) { return document.getElementById(id); };

  /* ------------------------------------------------------------------ the form */

  function toggleMediaType(type) {
    var input = byId('media_file');
    if (!input) { return; }
    input.accept = type === 'video'
      ? 'video/mp4,video/quicktime,video/webm'
      : 'image/*';
  }

  function togglePaymentMethod(method) {
    var details = byId('manual_details');
    if (details) {
      details.style.display = method === 'manual' ? 'block' : 'none';
    }
  }

  function updatePaymentOptions() {
    var select = byId('duration_id');
    if (!select) { return; }

    var option = select.options[select.selectedIndex];
    if (!option) { return; }

    var isFree = option.getAttribute('data-free') === '1';
    var freeNotice = byId('free_notice');
    var paidOptions = byId('paid_options');

    if (freeNotice) { freeNotice.style.display = isFree ? 'block' : 'none'; }
    if (paidOptions) { paidOptions.style.display = isFree ? 'none' : 'block'; }

    if (!isFree) {
      /* Whatever is actually checked wins, rather than assuming online: a church that takes no card
         payments renders no online radio at all. */
      var checked = document.querySelector('input[name="payment_method"]:checked');
      togglePaymentMethod(checked && checked.value === 'manual' ? 'manual' : 'online');
    }
  }

  function initForm() {
    var select = byId('duration_id');
    if (select) { select.addEventListener('change', updatePaymentOptions); }

    Array.prototype.forEach.call(
      document.querySelectorAll('input[name="payment_method"]'),
      function (radio) {
        radio.addEventListener('change', function () { togglePaymentMethod(radio.value); });
      }
    );

    Array.prototype.forEach.call(
      document.querySelectorAll('input[name="media_type"]'),
      function (radio) {
        radio.addEventListener('change', function () { toggleMediaType(radio.value); });
      }
    );

    updatePaymentOptions();
  }

  /* ------------------------------------------------- the inline checkout page */

  /*
   * Runs on /advertise/checkout, where the element carrying the settings is present.
   *
   * The order matters: the hosted-checkout form is already on the page and already works, and this
   * upgrades it to the iframe. If anything below fails — the script never loaded, the gateway is not
   * configured, the browser refuses the frame — the reader is left with a working form and a sentence
   * telling them so, rather than a button that appears to do nothing.
   */
  function initCheckout() {
    var node = byId('payhub-inline');
    if (!node) { return; }

    var status = byId('payhub-status');
    var button = byId('payhub-pay');
    var fallback = byId('payhub-fallback');

    var say = function (message) {
      if (status) { status.textContent = message; }
    };

    var useFallback = function (message) {
      if (button) { button.disabled = true; }
      if (fallback) { fallback.hidden = false; }
      say(message);
    };

    /*
     * PayHub's inline script declares `const PayhubPop = { … }` at the top level of the file.
     *
     * A top-level `const` creates a global LEXICAL binding — it is NOT a property of `window`. So
     * `window.PayhubPop` is undefined even when the script has loaded perfectly. The check here used to
     * require `window.PayhubPop`, so it announced "the secure payment window could not be loaded" on every
     * browser, while the gateway's own object sat there fully usable one scope away. The payment window
     * never opened, and the advertiser was sent to the fallback for a reason that was simply untrue.
     *
     * `typeof` is used because a bare reference to an undeclared name throws a ReferenceError; a loaded
     * `const` resolves normally. The try/catch covers the one remaining case — the binding exists but has
     * not been initialised yet (its "temporal dead zone") — which in practice means our script ran first,
     * and the ordering in the markup is what prevents that.
     */
    var pop = null;
    try {
      if (typeof PayhubPop !== 'undefined' && PayhubPop !== null) { pop = PayhubPop; }
    } catch (error) {
      pop = null;
    }
    // A future version might assign it to `window` instead; accept that too rather than guess.
    if (!pop && window.PayhubPop) { pop = window.PayhubPop; }

    if (!pop || typeof pop.setup !== 'function') {
      useFallback('The secure payment window could not be loaded, so the button above will not work. Use the payment option below instead.');
      return;
    }

    var settings;
    try {
      settings = JSON.parse(node.getAttribute('data-payhub') || '{}');
    } catch (error) {
      useFallback('The secure payment window could not be prepared. Use the payment option below instead.');
      return;
    }

    if (!settings.key || !settings.amount || !settings.ref) {
      useFallback('The secure payment window could not be prepared. Use the payment option below instead.');
      return;
    }

    var handler = pop.setup({
      key: settings.key,
      email: settings.email,
      amount: settings.amount,
      ref: settings.ref,
      /* The reference the gateway hands back is used when it gives one, and ours when it does not —
         both are ours, and the server verifies whichever arrives rather than trusting either. */
      callback: function (response) {
        var reference = (response && response.reference) ? response.reference : settings.ref;
        window.location.href = settings.returnUrl + '?ref=' + encodeURIComponent(reference);
      },
      onClose: function () {
        /* Closing the window is not the same as failing: the reader may have paid and then shut the
           tab. The return page asks the gateway, so this goes there rather than assuming. */
        window.location.href = settings.returnUrl + '?ref=' + encodeURIComponent(settings.ref) + '&closed=1';
      }
    });

    /*
     * The window opens immediately rather than waiting for a click.
     *
     * The advertiser has just submitted the form and chosen to pay by card. Making them press a second
     * button that only opens the thing they already asked for is a step that exists for no reason, and it
     * is a step they can walk away from believing the payment has already failed. The button below is kept
     * as the way to reopen the window after it is closed.
     */
    var openOnce = function () {
      try {
        handler.openIframe();
        return true;
      } catch (error) {
        useFallback('The secure payment window could not be opened, so the button above will not work. Use the payment option below instead.');
        return false;
      }
    };

    if (openOnce()) {
      /*
       * The hosted form stays hidden while the frame is open.
       *
       * Revealing it here too would put two payment routes on screen at once, and the second one asks the
       * advertiser to leave for the gateway's own page having already been shown a working payment window.
       * It is a fallback, so it appears when something has fallen back.
       */
      say('Finish your payment in the secure window. Nothing is charged until you confirm.');

      if (button) {
        button.disabled = false;
        button.textContent = 'Reopen the payment window';
        button.addEventListener('click', function (event) {
          event.preventDefault();
          openOnce();
        });
      }
    }
  }

  function start() {
    initForm();
    initCheckout();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
