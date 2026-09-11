(function () {
  'use strict';

  /* ---------- Church name correction flag toggle ---------- */
  var flagToggle = document.querySelector('[data-flag-toggle]');
  var flagForm = document.querySelector('[data-flag-form]');
  if (flagToggle && flagForm) {
    flagToggle.addEventListener('click', function () {
      var hidden = flagForm.style.display === 'none';
      flagForm.style.display = hidden ? '' : 'none';
      flagToggle.textContent = hidden ? 'Close correction form' : '🏷 Church name wrong? Report the correct spelling';
    });
  }

  /* ---------- cascading church-location dropdowns ----------
     The container carries data-units (the nested org tree) and data-old (the
     previous branch, if the page re-rendered after an error). The server renders
     one <select data-unit-select data-depth="i"> per level above the church,
     so any number of levels works. The chosen branch is posted as JSON ids in
     [data-unit-path]; the church itself is typed into [data-unit-leaf] and is
     auto-CAPS'd, matching an existing church when possible (id reused) or
     staying blank for creation on approval. */
  document.querySelectorAll('[data-units]').forEach(function (root) {
    var nodes = [];
    try { nodes = JSON.parse(root.getAttribute('data-units') || '[]'); } catch (e) { nodes = []; }
    var old = {};
    try { old = JSON.parse(root.getAttribute('data-old') || '{}'); } catch (e) { old = {}; }

    var selects = Array.prototype.slice.call(root.querySelectorAll('[data-unit-select]'))
      .sort(function (a, b) { return (parseInt(a.getAttribute('data-depth'), 10) || 0) - (parseInt(b.getAttribute('data-depth'), 10) || 0); });
    var leafInput = root.querySelector('[data-unit-leaf]');
    var leafList = root.querySelector('[data-unit-leaf-list]');
    var hidPath = root.querySelector('[data-unit-path]');
    var hidLeafId = root.querySelector('[data-unit-leaf-id]');
    var hidLeafName = root.querySelector('[data-unit-leaf-name]');

    function findNode(list, id) {
      for (var i = 0; i < list.length; i++) {
        if (list[i].id === id) { return list[i]; }
        var r = findNode(list[i].children || [], id);
        if (r) { return r; }
      }
      return null;
    }
    function optionsAt(depth) {
      if (!depth) { return nodes; }
      var parentId = parseInt(selects[depth - 1].value, 10) || 0;
      if (!parentId) { return []; }
      var parent = findNode(nodes, parentId);
      return parent ? (parent.children || []) : [];
    }
    function fill(sel, opts, label) {
      sel.innerHTML = '';
      var ph = document.createElement('option');
      ph.value = '';
      ph.textContent = 'Select ' + label + '…';
      sel.appendChild(ph);
      opts.forEach(function (o) {
        var opt = document.createElement('option');
        opt.value = String(o.id);
        opt.textContent = o.name;
        sel.appendChild(opt);
      });
    }
    /* Every select from `depth` down is rebuilt; deeper ones fall back to empty
       because their parent is now unset. */
    function rebuildFrom(depth) {
      for (var d = depth; d < selects.length; d++) {
        fill(selects[d], optionsAt(d), selects[d].getAttribute('data-label') || 'option');
      }
      sync();
    }
    function chain() {
      var ids = [];
      for (var d = 0; d < selects.length; d++) {
        var id = parseInt(selects[d].value, 10) || 0;
        if (!id) { break; }
        ids.push(id);
      }
      return ids;
    }
    function leafOptions() {
      var ids = chain();
      if (ids.length !== selects.length) { return []; }
      var parent = findNode(nodes, ids[ids.length - 1]);
      return parent ? (parent.children || []) : [];
    }
    function sync() {
      if (hidPath) { hidPath.value = JSON.stringify(chain()); }
      if (!leafInput) { return; }
      if (leafList) {
        leafList.innerHTML = '';
        leafOptions().forEach(function (c) {
          var opt = document.createElement('option');
          opt.value = c.name;
          leafList.appendChild(opt);
        });
      }
      syncLeaf();
    }
    function syncLeaf() {
      if (!leafInput || !hidLeafName) { return; }
      var name = (leafInput.value || '').trim().toUpperCase();
      var match = null;
      leafOptions().forEach(function (c) { if (c.name === name) { match = c; } });
      if (hidLeafId) { hidLeafId.value = match ? String(match.id) : ''; }
      hidLeafName.value = name;
    }

    // Build every select, restoring a previously chosen branch when the server
    // re-rendered the page (error re-render, or the admin review form).
    var restore = [];
    try { restore = JSON.parse(old.unit_path || '[]') || []; } catch (e) { restore = []; }
    for (var d = 0; d < selects.length; d++) {
      fill(selects[d], optionsAt(d), selects[d].getAttribute('data-label') || 'option');
      var want = parseInt(restore[d], 10) || 0;
      if (want) { selects[d].value = String(want); }
    }
    if (leafInput && old.parish_name) { leafInput.value = String(old.parish_name); }
    sync();

    selects.forEach(function (sel, depth) {
      sel.addEventListener('change', function () { rebuildFrom(depth + 1); });
    });
    if (leafInput) {
      leafInput.addEventListener('input', function () {
        leafInput.value = leafInput.value.toUpperCase();
        syncLeaf();
      });
      leafInput.addEventListener('change', syncLeaf);
    }
  });

  /* ---------- smart username/email suggestions (church name + role) ----------
     As soon as a Zone/Area is picked or a Parish name is typed, suggest two
     usernames derived from the church name, e.g. "SANCTUARY OF PRAISE" + admin
     -> "sopadmin" and "sop.admin". Clicking one fills the username field. */
  document.querySelectorAll('[data-units]').forEach(function (root) {
    var usernameInput = root.querySelector('[data-username]');
    var suggestBox = root.querySelector('[data-suggestions]');
    var roleSelect = root.querySelector('[data-role]');
    var unitSelects = Array.prototype.slice.call(root.querySelectorAll('[data-unit-select]'))
      .sort(function (a, b) { return (parseInt(a.getAttribute('data-depth'), 10) || 0) - (parseInt(b.getAttribute('data-depth'), 10) || 0); });
    var parishInput = root.querySelector('[data-unit-leaf]');
    if (!usernameInput || !suggestBox) { return; }

    var nodes = [];
    try { nodes = JSON.parse(root.getAttribute('data-units') || '[]'); } catch (e) { nodes = []; }

    function findNodeById(list, id) {
      for (var i = 0; i < list.length; i++) {
        if (list[i].id === id) { return list[i]; }
        var r = findNodeById(list[i].children || [], id);
        if (r) { return r; }
      }
      return null;
    }
    function selectedName(sel) {
      var id = parseInt(sel ? sel.value : '0', 10) || 0;
      if (!id) { return ''; }
      var n = findNodeById(nodes, id);
      return n ? n.name : '';
    }
    function currentChurchName() {
      if (parishInput && parishInput.value.trim()) { return parishInput.value.trim(); }
      // Fall back to the deepest level the registrant has picked so far.
      for (var i = unitSelects.length - 1; i >= 0; i--) {
        var name = selectedName(unitSelects[i]);
        if (name) { return name; }
      }
      return '';
    }
    function suggestPrefix(name) {
      name = (name || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
      if (!name) { return 'church'; }
      var words = name.split(/\s+/);
      if (words.length > 1) {
        return words.map(function (w) { return w.charAt(0); }).join('');
      }
      return name.slice(0, 3);
    }
    function updateSuggestions() {
      var church = currentChurchName();
      var prefix = suggestPrefix(church);
      var role = roleSelect ? roleSelect.value : 'admin';
      var suffix = { admin: 'admin', editor: 'editor', media_team: 'media' }[role] || 'admin';
      var names = [prefix + suffix, prefix + '.' + suffix];
      var btns = suggestBox.querySelectorAll('[data-suggestion]');
      btns.forEach(function (btn, i) {
        var name = names[i] || '';
        btn.textContent = name;
        btn.onclick = function () { if (usernameInput) { usernameInput.value = name; } };
      });
      suggestBox.style.display = church ? '' : 'none';
    }
    function wire() {
      unitSelects.forEach(function (sel) { sel.addEventListener('change', updateSuggestions); });
      if (parishInput) {
        parishInput.addEventListener('input', updateSuggestions);
        parishInput.addEventListener('change', updateSuggestions);
      }
      if (roleSelect) { roleSelect.addEventListener('change', updateSuggestions); }
      updateSuggestions();
    }
    wire();
  });

  /* ---------- instant password strength meter + suggestion ----------
     Runs on every keystroke (no debounce / no lazy load). Red = weak,
     amber = fair, green = strong. When weak, a stronger password is
     suggested based on what they've typed so far; clicking Use fills it. */
  var passwordInput = document.querySelector('[data-password]');
  var confirmInput = document.querySelector('[data-confirm]');
  var strengthFill = document.querySelector('[data-strength-fill]');
  var strengthLabel = document.querySelector('[data-strength-label]');
  var suggestionWrap = document.querySelector('[data-password-suggestion]');
  var suggestionText = document.querySelector('[data-suggestion-text]');
  var suggestionUse = document.querySelector('[data-suggestion-use]');
  var matchWrap = document.querySelector('[data-password-match]');
  if (passwordInput && strengthFill) {
    var COLORS = { weak: '#ff6b6b', fair: '#e8b95f', strong: '#5fe0a4' };
    // cPanel-style 0-100 score; cPanel's default minimum strength is 65.
    function strengthInfo(pw) {
      var score = 0;
      [8, 10, 12, 14, 16, 18, 20].forEach(function (t) { if (pw.length >= t) { score += 10; } });
      if (/[A-Z]/.test(pw)) { score += 15; }
      if (/[a-z]/.test(pw)) { score += 15; }
      if (/[0-9]/.test(pw)) { score += 15; }
      if (/[^A-Za-z0-9]/.test(pw)) { score += 15; }
      score = Math.min(100, score);
      var level = score < 65 ? 'weak' : (score < 80 ? 'fair' : 'strong');
      return { score: score, pct: score, level: level };
    }
    function suggestPassword(pw) {
      var clean = (pw || '').toLowerCase().replace(/[^a-z0-9]/g, '');
      if (!clean) {
        var nmEl = document.querySelector('[name="name"]');
        clean = ((nmEl && nmEl.value) || 'church').toLowerCase().replace(/[^a-z0-9]/g, '');
      }
      if (clean.length < 4) { clean = clean + 'church'; }
      var word = clean.charAt(0).toUpperCase() + clean.slice(1);
      var sug = word + '@' + new Date().getFullYear();
      while (sug.length < 12) { sug += '!'; }
      return sug;
    }
    function updateMeter() {
      var pw = passwordInput.value || '';
      var info = strengthInfo(pw);
      var labels = {
        weak: 'Too weak — cPanel needs 65+. Add a number and a symbol.',
        fair: 'Meets cPanel minimum (65).',
        strong: 'Strong password ✓ (85+)',
      };
      strengthFill.style.width = pw ? info.pct + '%' : '0%';
      strengthFill.style.background = COLORS[info.level];
      if (strengthLabel) {
        strengthLabel.textContent = pw ? labels[info.level] : 'Enter a strong password — cPanel requires strength 65+ (mix uppercase, lowercase, numbers & symbols).';
        strengthLabel.style.color = pw ? COLORS[info.level] : '';
      }
      if (suggestionWrap && suggestionText) {
        if (pw && info.level === 'weak') {
          suggestionText.textContent = suggestPassword(pw);
          suggestionWrap.style.display = '';
        } else {
          suggestionWrap.style.display = 'none';
        }
      }
      if (matchWrap && confirmInput) {
        var c = confirmInput.value || '';
        if (!c) {
          matchWrap.style.display = 'none';
        } else if (c === pw) {
          matchWrap.textContent = '✓ Passwords match';
          matchWrap.style.color = '#5fe0a4';
          matchWrap.style.display = '';
        } else {
          matchWrap.textContent = '✗ Passwords do not match yet';
          matchWrap.style.color = '#ff6b6b';
          matchWrap.style.display = '';
        }
      }
    }
    if (suggestionUse) {
      suggestionUse.addEventListener('click', function () {
        var sug = suggestionText.textContent || suggestPassword(passwordInput.value);
        passwordInput.value = sug;
        if (confirmInput) { confirmInput.value = sug; }
        updateMeter();
      });
    }
    passwordInput.addEventListener('input', updateMeter);
    if (confirmInput) { confirmInput.addEventListener('input', updateMeter); }
    updateMeter();
  }

  /* ---------- weak-password recovery: jump straight to the password field ----------
     After a rejection caused by the password, all other fields are preserved
     (server-side keepFormOld) and we scroll to + focus the password field with
     a brief red highlight, so the registrant only fixes that one section. */
  var focusRoot = document.querySelector('[data-focus-password]');
  var pwField = focusRoot ? focusRoot.querySelector('[data-password]') : null;
  if (focusRoot && pwField) {
    requestAnimationFrame(function () {
      var y = pwField.getBoundingClientRect().top + (window.pageYOffset || document.documentElement.scrollTop) - 24;
      window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
      pwField.focus();
      pwField.classList.add('pw-error');
      setTimeout(function () { pwField.classList.remove('pw-error'); }, 2400);
    });
  }
})();
