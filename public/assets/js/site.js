(function () {
  'use strict';

  // Mobile nav toggle
  var toggle = document.querySelector('[data-nav-toggle]');
  var links = document.querySelector('[data-nav-links]');
  if (toggle && links) {
    toggle.addEventListener('click', function () {
      var isOpen = !links.classList.contains('open');
      links.classList.toggle('open', isOpen);
      toggle.textContent = isOpen ? '✕' : '☰';
      if (!isOpen) { closeAllGroups(); }
    });
    links.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        links.classList.remove('open');
        toggle.textContent = '☰';
        closeAllGroups();
      });
    });
  }

  // Grouped nav: on small screens the caret button expands a group's children
  // (accordion - opening one closes the others). On desktop the groups open on
  // hover and the button is hidden, so this code simply never fires.
  var dropdownToggles = Array.prototype.slice.call(document.querySelectorAll('[data-nav-dropdown-toggle]'));

  function setGroup(button, open) {
    var wrap = button.closest('.nav-dropdown-wrap');
    if (!wrap) { return; }
    wrap.classList.toggle('is-open', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function closeAllGroups(except) {
    dropdownToggles.forEach(function (button) {
      if (button !== except) { setGroup(button, false); }
    });
  }

  dropdownToggles.forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      var wrap = button.closest('.nav-dropdown-wrap');
      var willOpen = !!wrap && !wrap.classList.contains('is-open');
      closeAllGroups(button);
      setGroup(button, willOpen);
    });
  });

  // Scroll-reveal
  var revealTargets = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && revealTargets.length) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('in');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.15 });
    revealTargets.forEach(function (el) { io.observe(el); });
  } else {
    revealTargets.forEach(function (el) { el.classList.add('in'); });
  }

  // Generic fetch-and-show-message form handler.
  // Usage: <form data-remote-form="/api/newsletter"> ... <div data-form-message></div>
  document.querySelectorAll('[data-remote-form]').forEach(function (form) {
    var endpoint = form.getAttribute('data-remote-form');
    var messageBox = form.querySelector('[data-form-message]');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var submitBtn = form.querySelector('button[type=submit]');
      var payload = {};
      new FormData(form).forEach(function (value, key) { payload[key] = value; });

      if (submitBtn) { submitBtn.disabled = true; }
      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
        .then(function (result) {
          if (!messageBox) { return; }
          messageBox.textContent = result.data.message || (result.ok ? 'Done.' : 'Something went wrong.');
          messageBox.className = 'form-message show ' + (result.ok && result.data.status === 'success' ? 'ok' : 'err');
          if (result.ok && result.data.status === 'success') {
            form.reset();
          }
        })
        .catch(function () {
          if (messageBox) {
            messageBox.textContent = 'Network error — please try again.';
            messageBox.className = 'form-message show err';
          }
        })
        .finally(function () {
          if (submitBtn) { submitBtn.disabled = false; }
        });
    });
  });
})();
