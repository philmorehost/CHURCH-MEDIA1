/*
 * /advertise — the advertiser's form, and the inline checkout that follows it.
 *
 * This file handles:
 * 1. Responsive form UI toggles (media type, payment method, duration package) and instant submit UX.
 * 2. Rock-solid inline checkout iframe popup modal that always connects to PayHub's secure gateway
 *    without relative 404 pathing errors.
 */
(function () {
  'use strict';

  var byId = function (id) { return document.getElementById(id); };

  // Inject common micro-animation CSS for spinner
  (function injectStyles() {
    if (byId('ph-advertise-styles')) { return; }
    var style = document.createElement('style');
    style.id = 'ph-advertise-styles';
    style.textContent = '@keyframes ph-spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(style);
  })();

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

    // Responsive submit UX: show instant loading spinner on form submit
    var form = byId('ad_form') || document.querySelector('form[action="/advertise"]');
    var submitBtn = byId('ad_submit_btn') || (form ? form.querySelector('button[type="submit"]') : null);
    if (form && submitBtn) {
      form.addEventListener('submit', function (e) {
        if (form.checkValidity && !form.checkValidity()) {
          return;
        }
        if (submitBtn.disabled) {
          e.preventDefault();
          return;
        }
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.85';
        submitBtn.style.cursor = 'wait';
        submitBtn.innerHTML = '<span style="display:inline-block; width:16px; height:16px; border:2px solid rgba(255,255,255,0.3); border-top-color:#fff; border-radius:50%; animation:ph-spin 0.8s linear infinite; vertical-align:middle; margin-right:8px;"></span> Processing &amp; Uploading Advert…';
      });
    }
  }

  /* ------------------------------------------------- the inline checkout page */

  /**
   * Opens a secure, self-contained PayHub checkout iframe modal with backdrop, loader, and postMessage handling.
   */
  function openPayhubModal(settings, onComplete, onDismiss) {
    // Clean up any existing overlay
    var existing = byId('payhub-checkout-overlay');
    if (existing && existing.parentNode) {
      existing.parentNode.removeChild(existing);
    }

    var cleanBase = (settings.gatewayBaseUrl || 'https://merchant.payhub.com.ng/').replace(/\/+$/, '') + '/';
    var checkoutUrl = cleanBase + 'checkout.php'
      + '?amount=' + encodeURIComponent(settings.amount / 100)
      + '&email=' + encodeURIComponent(settings.email || '')
      + '&ref=' + encodeURIComponent(settings.ref)
      + (settings.key ? '&key=' + encodeURIComponent(settings.key) + '&public_key=' + encodeURIComponent(settings.key) : '')
      + (settings.isTest ? '&test=1&mode=test' : '')
      + '&origin=' + encodeURIComponent(window.location.origin)
      + '&embed=1';

    var expectedOrigin;
    try {
      expectedOrigin = new URL(cleanBase).origin;
    } catch (e) {
      expectedOrigin = 'https://merchant.payhub.com.ng';
    }

    // Overlay
    var overlay = document.createElement('div');
    overlay.id = 'payhub-checkout-overlay';
    overlay.style.position = 'fixed';
    overlay.style.top = '0';
    overlay.style.left = '0';
    overlay.style.width = '100%';
    overlay.style.height = '100%';
    overlay.style.backgroundColor = 'rgba(0, 0, 0, 0.65)';
    overlay.style.backdropFilter = 'blur(6px)';
    overlay.style.webkitBackdropFilter = 'blur(6px)';
    overlay.style.zIndex = '999999';
    overlay.style.display = 'flex';
    overlay.style.alignItems = 'center';
    overlay.style.justifyContent = 'center';
    overlay.style.padding = '16px';
    overlay.style.boxSizing = 'border-box';

    // Container
    var container = document.createElement('div');
    container.id = 'payhub-checkout-container';
    container.style.width = '100%';
    container.style.maxWidth = '460px';
    container.style.height = '620px';
    container.style.maxHeight = '92vh';
    container.style.backgroundColor = '#0f172a';
    container.style.borderRadius = '20px';
    container.style.overflow = 'hidden';
    container.style.boxShadow = '0 25px 50px -12px rgba(0, 0, 0, 0.6)';
    container.style.position = 'relative';
    container.style.border = '1px solid rgba(255, 255, 255, 0.12)';

    // Loader
    var loader = document.createElement('div');
    loader.id = 'payhub-iframe-loader';
    loader.style.position = 'absolute';
    loader.style.top = '0';
    loader.style.left = '0';
    loader.style.width = '100%';
    loader.style.height = '100%';
    loader.style.display = 'flex';
    loader.style.flexDirection = 'column';
    loader.style.alignItems = 'center';
    loader.style.justifyContent = 'center';
    loader.style.background = '#0f172a';
    loader.style.color = '#f8fafc';
    loader.style.fontSize = '14px';
    loader.style.zIndex = '1';
    loader.style.gap = '14px';
    loader.innerHTML = '<div style="width:36px; height:36px; border:3px solid rgba(255,255,255,0.15); border-top-color:#f59e0b; border-radius:50%; animation:ph-spin 0.8s linear infinite;"></div><span>Connecting to PayHub secure gateway…</span>';

    // Close button
    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Close checkout window');
    closeBtn.innerHTML = '&times;';
    closeBtn.style.position = 'absolute';
    closeBtn.style.top = '16px';
    closeBtn.style.right = '16px';
    closeBtn.style.width = '34px';
    closeBtn.style.height = '34px';
    closeBtn.style.borderRadius = '50%';
    closeBtn.style.border = 'none';
    closeBtn.style.backgroundColor = 'rgba(255, 255, 255, 0.15)';
    closeBtn.style.color = '#f8fafc';
    closeBtn.style.fontSize = '22px';
    closeBtn.style.lineHeight = '1';
    closeBtn.style.cursor = 'pointer';
    closeBtn.style.zIndex = '10';
    closeBtn.style.display = 'flex';
    closeBtn.style.alignItems = 'center';
    closeBtn.style.justifyContent = 'center';

    function closeOverlay() {
      window.removeEventListener('message', onCheckoutMessage, false);
      document.removeEventListener('keydown', onKeyDown, false);
      if (overlay.parentNode) {
        overlay.parentNode.removeChild(overlay);
      }
      if (typeof onDismiss === 'function') {
        onDismiss();
      }
    }

    closeBtn.onclick = closeOverlay;

    function onKeyDown(e) {
      if (e.key === 'Escape' || e.keyCode === 27) {
        closeOverlay();
      }
    }
    document.addEventListener('keydown', onKeyDown, false);

    // Iframe
    var iframe = document.createElement('iframe');
    iframe.src = checkoutUrl;
    iframe.style.width = '100%';
    iframe.style.height = '100%';
    iframe.style.border = 'none';
    iframe.setAttribute('allow', 'clipboard-read; clipboard-write; payment');
    iframe.onload = function () {
      if (loader && loader.parentNode) {
        loader.style.opacity = '0';
        loader.style.transition = 'opacity 0.3s ease';
        setTimeout(function () {
          if (loader.parentNode) { loader.parentNode.removeChild(loader); }
        }, 300);
      }
    };

    function onCheckoutMessage(event) {
      if (expectedOrigin && event.origin !== expectedOrigin) { return; }
      if (event.source !== iframe.contentWindow) { return; }
      if (!event.data || event.data.type !== 'payhub_success') { return; }

      window.removeEventListener('message', onCheckoutMessage, false);
      document.removeEventListener('keydown', onKeyDown, false);
      if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }

      if (typeof onComplete === 'function') {
        onComplete(event.data.data);
      }
    }

    window.addEventListener('message', onCheckoutMessage, false);

    container.appendChild(loader);
    container.appendChild(closeBtn);
    container.appendChild(iframe);
    overlay.appendChild(container);
    document.body.appendChild(overlay);

    return true;
  }

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

    var handleSuccess = function (data) {
      var reference = (data && data.reference) ? data.reference : settings.ref;
      window.location.href = settings.returnUrl + '?ref=' + encodeURIComponent(reference);
    };

    var handleDismiss = function () {
      say('Payment window closed. Click the button above to reopen, or use the alternative option below.');
    };

    var openWindow = function () {
      try {
        var opened = openPayhubModal(settings, handleSuccess, handleDismiss);
        if (opened) {
          say('Finish your payment in the secure window. Nothing is charged until you confirm.');
          if (button) {
            button.disabled = false;
            button.textContent = 'Reopen the payment window';
          }
          return true;
        }
      } catch (e) {
        useFallback('The secure payment window could not be opened. Use the payment option below instead.');
      }
      return false;
    };

    if (button) {
      button.disabled = false;
      button.addEventListener('click', function (event) {
        event.preventDefault();
        openWindow();
      });
    }

    // Auto-open modal on page load for immediate seamless checkout experience
    openWindow();
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
