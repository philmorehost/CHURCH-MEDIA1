(function () {
  'use strict';

  /* ---------- copy-link buttons (used on the forms list) ---------- */
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-copy') || '';
      var done = function () {
        btn.textContent = 'Copied!';
        setTimeout(function () { btn.textContent = 'Copy'; }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done).catch(function () {});
      } else {
        var ta = document.createElement('textarea');
        ta.value = url;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(ta);
      }
    });
  });

  /* ---------- field builder (create/edit) ---------- */
  var container = document.getElementById('fieldsContainer');
  if (!container) { return; }

  var form = document.getElementById('formBuilder');
  var fieldsJson = document.getElementById('fieldsJson');
  var addBtn = document.getElementById('addFieldBtn');

  var TYPES = [
    ['text', 'Short text'],
    ['textarea', 'Paragraph'],
    ['email', 'Email'],
    ['phone', 'Phone number'],
    ['number', 'Number'],
    ['date', 'Date'],
    ['time', 'Time'],
    ['datetime', 'Date & time'],
    ['url', 'URL'],
    ['select', 'Dropdown'],
    ['radio', 'Multiple choice'],
    ['checkbox', 'Checkboxes'],
    ['cascade', 'Cascading dropdown'],
    ['church', 'Church (auto)'],
    ['image', 'Image upload'],
  ];
  var OPTION_TYPES = ['select', 'radio', 'checkbox'];
  var CASCADE_TYPES = ['cascade'];
  var CHURCH_TYPES = ['church'];
  var IMAGE_TYPES = ['image'];
  var TYPE_VALUES = TYPES.map(function (t) { return t[0]; });

  function makeRow(field) {
    field = field || {};
    var type = TYPE_VALUES.indexOf(field.field_type) !== -1 ? field.field_type : 'text';

    var row = document.createElement('div');
    row.className = 'form-field-row';
    row.draggable = true;

    var grip = document.createElement('div');
    grip.className = 'drag-grip';
    grip.title = 'Drag to reorder';
    grip.textContent = '⠿';
    row.appendChild(grip);

    var grid = document.createElement('div');
    grid.className = 'grid-field';

    var labelBox = document.createElement('div');
    var labelMini = document.createElement('div');
    labelMini.className = 'mini';
    labelMini.textContent = 'Label';
    var labelInput = document.createElement('input');
    labelInput.type = 'text';
    labelInput.className = 'ff-label';
    labelInput.placeholder = 'Question label';
    labelInput.value = field.label || '';
    labelBox.appendChild(labelMini);
    labelBox.appendChild(labelInput);

    var typeBox = document.createElement('div');
    var typeMini = document.createElement('div');
    typeMini.className = 'mini';
    typeMini.textContent = 'Type';
    var typeSelect = document.createElement('select');
    typeSelect.className = 'ff-type';
    TYPES.forEach(function (t) {
      var opt = document.createElement('option');
      opt.value = t[0];
      opt.textContent = t[1];
      if (t[0] === type) { opt.selected = true; }
      typeSelect.appendChild(opt);
    });
    typeBox.appendChild(typeMini);
    typeBox.appendChild(typeSelect);

    grid.appendChild(labelBox);
    grid.appendChild(typeBox);
    row.appendChild(grid);

    var placeholderWrap = document.createElement('div');
    placeholderWrap.className = 'ff-placeholder-wrap';
    var phMini = document.createElement('div');
    phMini.className = 'mini';
    phMini.textContent = 'Placeholder (optional)';
    var phInput = document.createElement('input');
    phInput.type = 'text';
    phInput.className = 'ff-placeholder';
    phInput.placeholder = 'Optional hint text';
    phInput.value = field.placeholder || '';
    placeholderWrap.appendChild(phMini);
    placeholderWrap.appendChild(phInput);
    row.appendChild(placeholderWrap);

    var optionsWrap = document.createElement('div');
    optionsWrap.className = 'ff-options-wrap';
    var optMini = document.createElement('div');
    optMini.className = 'mini';
    optMini.textContent = 'Options (one per line)';
    var optTextarea = document.createElement('textarea');
    optTextarea.className = 'ff-options';
    optTextarea.rows = 3;
    optTextarea.placeholder = 'Option 1\nOption 2\nOption 3';
    optTextarea.value = field.options || '';
    optionsWrap.appendChild(optMini);
    optionsWrap.appendChild(optTextarea);
    row.appendChild(optionsWrap);

    var churchNote = document.createElement('div');
    churchNote.className = 'ff-church-note';
    churchNote.style.display = 'none';
    churchNote.style.cssText = 'font-size:12.5px;color:var(--ink-dim);background:#ffffff08;border:1px dashed var(--border);border-radius:10px;padding:10px 12px;margin-top:6px;line-height:1.5;';
    churchNote.textContent = 'Auto-filled from your church list (Province > Zone > Area > Parish) — no options needed. Pick a label like "Where do you worship?".';
    row.appendChild(churchNote);

    var actions = document.createElement('div');
    actions.className = 'row-actions';

    var reqLabel = document.createElement('label');
    reqLabel.className = 'field-req';
    var reqInput = document.createElement('input');
    reqInput.type = 'checkbox';
    reqInput.className = 'ff-required';
    if (field.required) { reqInput.checked = true; }
    reqLabel.appendChild(reqInput);
    reqLabel.appendChild(document.createTextNode(' Required'));
    actions.appendChild(reqLabel);

    var btns = document.createElement('div');
    btns.className = 'btns';
    [['↑', 'ff-up', 'Move up'], ['↓', 'ff-down', 'Move down'], ['Remove', 'ff-remove', 'Remove field']].forEach(function (b) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn secondary sm ' + b[1];
      btn.textContent = b[0];
      btn.title = b[2];
      btn.addEventListener('click', function () {
        if (b[1] === 'ff-up') {
          var prev = row.previousElementSibling;
          if (prev) { row.parentNode.insertBefore(row, prev); }
        } else if (b[1] === 'ff-down') {
          var next = row.nextElementSibling;
          if (next) { row.parentNode.insertBefore(next, row); }
        } else {
          row.remove();
        }
      });
      btns.appendChild(btn);
    });
    actions.appendChild(btns);
    row.appendChild(actions);

    typeSelect.addEventListener('change', function () { updateVisibility(row); });
    updateVisibility(row);
    return row;
  }

  function updateVisibility(row) {
    var type = row.querySelector('.ff-type').value;
    var isOptionType = OPTION_TYPES.indexOf(type) !== -1;
    var isCascade = CASCADE_TYPES.indexOf(type) !== -1;
    var isChurch = CHURCH_TYPES.indexOf(type) !== -1;
    var isImage = IMAGE_TYPES.indexOf(type) !== -1;
    var optionsWrap = row.querySelector('.ff-options-wrap');
    var phWrap = row.querySelector('.ff-placeholder-wrap');
    var churchNote = row.querySelector('.ff-church-note');
    var optMini = optionsWrap.querySelector('.mini');
    var optTa = row.querySelector('.ff-options');
    optionsWrap.style.display = (isOptionType || isCascade) ? '' : 'none';
    phWrap.style.display = (isOptionType || isImage || isCascade || isChurch) ? 'none' : '';
    churchNote.style.display = isChurch ? '' : 'none';
    optMini.textContent = isCascade ? 'Paths (one full path per line, levels by >)' : 'Options (one per line)';
    optTa.placeholder = isCascade
      ? 'Lagos > Lagos Mainland > Somolu > LP63 YAYA\nOgun > Abeokuta > Idi-Aba > ABC Parish'
      : 'Option 1\nOption 2\nOption 3';
  }

  function enableDrag() {
    var dragging = null;
    container.addEventListener('dragstart', function (e) {
      dragging = e.target.closest('.form-field-row');
      if (dragging) {
        dragging.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', 'row'); } catch (err) {}
      }
    });
    container.addEventListener('dragend', function () {
      if (dragging) { dragging.classList.remove('dragging'); dragging = null; }
    });
    container.addEventListener('dragover', function (e) {
      e.preventDefault();
      if (!dragging) { return; }
      var row = e.target.closest('.form-field-row');
      if (!row || row === dragging) { return; }
      var rect = row.getBoundingClientRect();
      var after = (e.clientY - rect.top) > rect.height / 2;
      container.insertBefore(dragging, after ? row.nextSibling : row);
    });
    container.addEventListener('drop', function (e) { e.preventDefault(); });
  }
  enableDrag();

  function serialize() {
    var fields = [];
    container.querySelectorAll('.form-field-row').forEach(function (row) {
      fields.push({
        label: row.querySelector('.ff-label').value.trim(),
        type: row.querySelector('.ff-type').value,
        placeholder: row.querySelector('.ff-placeholder').value.trim(),
        options: row.querySelector('.ff-options').value.trim(),
        required: row.querySelector('.ff-required').checked,
      });
    });
    return fields;
  }

  addBtn.addEventListener('click', function () { container.appendChild(makeRow({})); });

  if (window.__FORM_FIELDS__ && window.__FORM_FIELDS__.length) {
    window.__FORM_FIELDS__.forEach(function (f) { container.appendChild(makeRow(f)); });
  } else {
    container.appendChild(makeRow({ label: '', field_type: 'text' }));
  }

  /* ---------- public / private access control ---------- */
  var visSelect = document.getElementById('visibility');
  var passwordWrap = document.getElementById('passwordWrap');
  var accessNote = document.getElementById('accessNote');
  if (visSelect && passwordWrap && accessNote) {
    function updateAccessUI() {
      var isPrivate = visSelect.value === 'private';
      passwordWrap.style.display = isPrivate ? '' : 'none';
      accessNote.style.display = isPrivate ? '' : 'none';
    }
    visSelect.addEventListener('change', updateAccessUI);
    updateAccessUI();
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    fieldsJson.value = JSON.stringify(serialize());
    form.submit();
  });
})();
