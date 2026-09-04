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

  /* ---------- Province > Zone > Area cascade + Parish suggestions ----------
     Each container carries data-units (nested org tree) and data-old (previous
     selection, if the page re-rendered after an error). The parish input is
     auto-CAPS'd and, when it matches an existing parish under the chosen Area,
     its id is stored (reuse) — otherwise it stays empty (a new parish created
     on approval). */
  document.querySelectorAll('[data-units]').forEach(function (root) {
    var nodes = [];
    try { nodes = JSON.parse(root.getAttribute('data-units') || '[]'); } catch (e) { nodes = []; }
    var old = {};
    try { old = JSON.parse(root.getAttribute('data-old') || '{}'); } catch (e) { old = {}; }

    var selProvince = root.querySelector('[data-province]');
    var selZone = root.querySelector('[data-zone]');
    var selArea = root.querySelector('[data-area]');
    var parishInput = root.querySelector('[data-parish]');
    var parishList = root.querySelector('[data-parish-list]');
    var hidProvince = root.querySelector('[data-province-id]');
    var hidZone = root.querySelector('[data-zone-id]');
    var hidArea = root.querySelector('[data-area-id]');
    var hidParishId = root.querySelector('[data-parish-id]');
    var hidParishName = root.querySelector('[data-parish-name]');
    if (!selProvince || !selZone || !selArea) { return; }

    function childrenOf(parentId) {
      if (!parentId) { return nodes; }
      for (var i = 0; i < nodes.length; i++) {
        var found = findNode(nodes[i], parentId);
        if (found) { return found.children || []; }
      }
      return [];
    }
    function findNode(node, id) {
      if (node.id === id) { return node; }
      for (var i = 0; i < (node.children || []).length; i++) {
        var r = findNode(node.children[i], id);
        if (r) { return r; }
      }
      return null;
    }
    function parishesOf(areaId) {
      return childrenOf(areaId).filter(function (n) { return n.type === 'parish'; });
    }
    function fill(sel, opts, placeholder) {
      sel.innerHTML = '';
      var ph = document.createElement('option');
      ph.value = '';
      ph.textContent = placeholder;
      sel.appendChild(ph);
      opts.forEach(function (o) {
        var opt = document.createElement('option');
        opt.value = String(o.id);
        opt.textContent = o.name;
        sel.appendChild(opt);
      });
    }
    function sync() {
      var pid = parseInt(selProvince.value, 10) || 0;
      var zid = parseInt(selZone.value, 10) || 0;
      var aid = parseInt(selArea.value, 10) || 0;
      if (hidProvince) { hidProvince.value = pid ? String(pid) : ''; }
      if (hidZone) { hidZone.value = zid ? String(zid) : ''; }
      if (hidArea) { hidArea.value = aid ? String(aid) : ''; }

      if (parishList && parishInput) {
        parishList.innerHTML = '';
        parishesOf(aid).forEach(function (p) {
          var opt = document.createElement('option');
          opt.value = p.name;
          parishList.appendChild(opt);
        });
        syncParish();
      }
    }
    function syncParish() {
      if (!parishInput) { return; }
      var aid = parseInt(selArea.value, 10) || 0;
      var name = (parishInput.value || '').trim().toUpperCase();
      var match = null;
      parishesOf(aid).forEach(function (p) { if (p.name === name) { match = p; } });
      if (hidParishId) { hidParishId.value = match ? String(match.id) : ''; }
      if (hidParishName) { hidParishName.value = name; }
    }

    function buildZone() {
      var pid = parseInt(selProvince.value, 10) || 0;
      fill(selZone, childrenOf(pid), 'Select Zone…');
      if (old.zone_id) { selZone.value = String(old.zone_id); }
      buildArea();
    }
    function buildArea() {
      var zid = parseInt(selZone.value, 10) || 0;
      fill(selArea, childrenOf(zid), 'Select Area…');
      if (old.area_id) { selArea.value = String(old.area_id); }
      sync();
    }

    // Province list is static (top of tree).
    fill(selProvince, nodes, 'Select Province…');
    if (old.province_id) { selProvince.value = String(old.province_id); }

    // Pre-fill from server-side "old" values (re-render after an error, or the
    // admin review form loading an existing registration).
    if (old.parish_name) {
      // wait until areas are built to set the parish value
    }
    buildZone();
    if (old.parish_name && parishInput) { parishInput.value = old.parish_name; }
    syncParish();

    selProvince.addEventListener('change', function () { old.zone_id = 0; old.area_id = 0; old.parish_id = 0; old.parish_name = ''; buildZone(); });
    selZone.addEventListener('change', function () { old.area_id = 0; old.parish_id = 0; old.parish_name = ''; buildArea(); });
    selArea.addEventListener('change', function () { old.parish_id = 0; old.parish_name = ''; sync(); });

    if (parishInput) {
      parishInput.addEventListener('input', function () {
        parishInput.value = parishInput.value.toUpperCase();
        syncParish();
      });
      parishInput.addEventListener('change', syncParish);
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
    var selProvince = root.querySelector('[data-province]');
    var selZone = root.querySelector('[data-zone]');
    var selArea = root.querySelector('[data-area]');
    var parishInput = root.querySelector('[data-parish]');
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
      return selectedName(selArea) || selectedName(selZone) || selectedName(selProvince);
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
      if (selProvince) { selProvince.addEventListener('change', updateSuggestions); }
      if (selZone) { selZone.addEventListener('change', updateSuggestions); }
      if (selArea) { selArea.addEventListener('change', updateSuggestions); }
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
