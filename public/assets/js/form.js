(function () {
  'use strict';

  document.querySelectorAll('.form-upload input[type="file"]').forEach(function (input) {
    input.addEventListener('change', function () {
      var label = input.closest('.form-upload');
      if (!label) { return; }
      var count = label.querySelector('[data-upload-count]');
      if (!count) { return; }
      var files = input.files ? input.files.length : 0;
      if (files === 0) {
        count.textContent = 'No files chosen';
      } else if (files === 1) {
        count.textContent = input.files[0].name;
      } else {
        count.textContent = files + ' images chosen';
      }
    });
  });

  /* ---------- cascading dropdowns (dropdown in dropdown lists) ----------
     Each .form-cascade carries data-cascade = JSON array of full paths, e.g.
     [["Lagos","Somolu","LP63 YAYA"],["Ogun","Abeokuta","ABC Parish"]].
     We render a chain of <select>s where each one is filtered by the previous,
     and write the chosen full path ("A > B > C") into the hidden input. */
  document.querySelectorAll('.form-cascade[data-cascade]').forEach(function (el) {
    var paths = [];
    try { paths = JSON.parse(el.getAttribute('data-cascade') || '[]'); } catch (e) { paths = []; }
    var hidden = el.querySelector('input[type="hidden"]');
    var wrap = el.querySelector('.cascade-selects');
    if (!hidden || !wrap || !paths.length) { return; }
    var old = (el.getAttribute('data-old') || '').split(' > ').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
    var levels = 0;
    paths.forEach(function (p) { if (p.length > levels) { levels = p.length; } });

    function currentSelections() {
      var out = [];
      wrap.querySelectorAll('select').forEach(function (s) { out.push(s.value || ''); });
      return out;
    }
    function uniqueAt(index, prefix) {
      var seen = [], out = [];
      paths.forEach(function (p) {
        if (index >= p.length) { return; }
        for (var k = 0; k < index; k++) { if (p[k] !== prefix[k]) { return; } }
        if (seen.indexOf(p[index]) === -1) { seen.push(p[index]); out.push(p[index]); }
      });
      return out;
    }
    function build() {
      var prefix = currentSelections();
      wrap.innerHTML = '';
      for (var i = 0; i < levels; i++) {
        var opts = uniqueAt(i, prefix);
        if (!opts.length) { break; }
        var select = document.createElement('select');
        select.className = 'cascade-select';
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = 'Select…';
        select.appendChild(ph);
        opts.forEach(function (o) {
          var opt = document.createElement('option');
          opt.value = o;
          opt.textContent = o;
          select.appendChild(opt);
        });
        var desired = (prefix[i] && opts.indexOf(prefix[i]) !== -1) ? prefix[i] : ((old[i] && opts.indexOf(old[i]) !== -1) ? old[i] : '');
        if (desired) { select.value = desired; }
        select.addEventListener('change', build);
        wrap.appendChild(select);
        prefix[i] = select.value || '';
        if (!prefix[i]) { break; }
      }
      var chosen = [];
      wrap.querySelectorAll('select').forEach(function (s) { if (s.value) { chosen.push(s.value); } });
      hidden.value = chosen.join(' > ');
    }
    build();
  });
})();
