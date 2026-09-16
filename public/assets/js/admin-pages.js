(function () {
  'use strict';

  var builder = document.getElementById('cmsBuilder');
  if (!builder) { return; }

  var form = document.getElementById('pageForm');
  var contentField = document.getElementById('pageContent');
  var csrfToken = form ? (form.querySelector('input[name="_csrf"]') || {}).value : '';

  var SECTION_LABELS = { hero: 'Banner', text: 'Text', columns: 'Cards', image: 'Image', quote: 'Quote', cta: 'Call to action' };

  var FIELDS = {
    hero: [
      { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
      { key: 'title', label: 'Headline', kind: 'text' },
      { key: 'subtitle', label: 'Subtitle', kind: 'textarea' },
      { key: 'alt', label: 'Image description (alt)', kind: 'text' }
    ],
    text: [
      { key: 'heading', label: 'Heading', kind: 'text' },
      { key: 'body', label: 'Body', kind: 'textarea' },
      { key: 'align', label: 'Align', kind: 'select', options: [['left', 'Left'], ['center', 'Centered']] }
    ],
    columns: [
      { key: 'heading', label: 'Section heading', kind: 'text' },
      { key: 'eyebrow', label: 'Eyebrow', kind: 'text' }
    ],
    image: [
      { key: 'alt', label: 'Alt text', kind: 'text' },
      { key: 'caption', label: 'Caption', kind: 'text' }
    ],
    quote: [
      { key: 'quote', label: 'Quote', kind: 'textarea' },
      { key: 'source', label: 'Source (who said it)', kind: 'text' }
    ],
    cta: [
      { key: 'title', label: 'Title', kind: 'text' },
      { key: 'subtitle', label: 'Subtitle', kind: 'text' },
      { key: 'label', label: 'Button label', kind: 'text' },
      { key: 'url', label: 'Button link', kind: 'text' }
    ]
  };
  var HAS_IMAGE = { hero: true, image: true };

  function esc(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function el(tag, className, attrs) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (attrs) {
      Object.keys(attrs).forEach(function (k) { node.setAttribute(k, attrs[k]); });
    }
    return node;
  }

  function textInput(value, placeholder) {
    var i = el('input', 'cms-input', { type: 'text', value: value == null ? '' : value, placeholder: placeholder || '' });
    return i;
  }

  function areaInput(value) {
    var t = el('textarea', 'cms-input');
    t.value = value == null ? '' : value;
    t.rows = 3;
    return t;
  }

  function selectInput(value, options) {
    var s = el('select', 'cms-input');
    options.forEach(function (opt) {
      var o = el('option', null, { value: opt[0] });
      o.textContent = opt[1];
      if (String(value) === String(opt[0])) { o.selected = true; }
      s.appendChild(o);
    });
    return s;
  }

  function fieldLabel(label, node, key) {
    var wrap = el('div', 'cms-field');
    if (label) {
      var lab = el('label', 'cms-label');
      lab.textContent = label;
      wrap.appendChild(lab);
    }
    node.dataset.key = key;
    wrap.appendChild(node);
    return wrap;
  }

  function imageField(currentPath) {
    var wrap = el('div', 'cms-img-field');
    var hidden = el('input', null, { type: 'hidden', value: currentPath || '' });
    hidden.className = 'img-path';
    var preview = el('div', 'cms-img-preview');
    var fileInput = el('input', null, { type: 'file', accept: 'image/*' });
    fileInput.hidden = true;
    var pickBtn = el('button', 'btn sm secondary', { type: 'button' });
    pickBtn.textContent = 'Choose image';
    var clearBtn = el('button', 'btn sm danger', { type: 'button' });
    clearBtn.textContent = 'Remove';
    var status = el('span', 'cms-img-status');
    status.textContent = 'Auto-compressed to WebP';

    function showPreview() {
      var path = hidden.value;
      preview.innerHTML = '';
      if (path) {
        var img = el('img');
        img.src = path.indexOf('http') === 0 ? path : '/uploads/' + path;
        img.alt = '';
        preview.appendChild(img);
        clearBtn.hidden = false;
      } else {
        var ph = el('span', 'cms-img-empty');
        ph.textContent = 'No image';
        preview.appendChild(ph);
        clearBtn.hidden = true;
      }
    }

    pickBtn.addEventListener('click', function () { fileInput.click(); });
    clearBtn.addEventListener('click', function () { hidden.value = ''; showPreview(); });
    fileInput.addEventListener('change', function () {
      if (!fileInput.files.length) { return; }
      status.textContent = 'Uploading…';
      var fd = new FormData();
      fd.append('_csrf', csrfToken);
      fd.append('image', fileInput.files[0]);
      fetch('/admin/pages?action=upload_image', { method: 'POST', body: fd })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (data) {
          if (data && data.status === 'success') {
            hidden.value = data.path;
            status.textContent = 'Uploaded · compressed to WebP';
            showPreview();
          } else {
            status.textContent = (data && data.message) || 'Upload failed.';
          }
        })
        .catch(function () { status.textContent = 'Upload failed.'; });
    });

    wrap.appendChild(hidden);
    wrap.appendChild(preview);
    wrap.appendChild(fileInput);
    var actions = el('div', 'cms-img-actions');
    actions.appendChild(pickBtn);
    actions.appendChild(clearBtn);
    wrap.appendChild(actions);
    wrap.appendChild(status);
    showPreview();
    return wrap;
  }

  function buildColumnsEditor(cols) {
    var wrap = el('div', 'cms-cols');
    var list = el('div', 'cms-cols-list');
    function addColumn(col) {
      col = col || { heading: '', body: '' };
      var card = el('div', 'cms-col');
      var h = textInput(col.heading, 'Card heading');
      h.className += ' col-heading';
      var b = areaInput(col.body);
      b.className += ' col-body';
      var del = el('button', 'btn sm danger', { type: 'button' });
      del.textContent = 'Remove card';
      del.addEventListener('click', function () { card.remove(); });
      card.appendChild(fieldLabel('Card heading', h, ''));
      card.appendChild(fieldLabel('Card text', b, ''));
      card.appendChild(del);
      list.appendChild(card);
    }
    (Array.isArray(cols) ? cols : []).forEach(addColumn);
    var addBtn = el('button', 'btn secondary sm', { type: 'button' });
    addBtn.textContent = '+ Add card';
    addBtn.addEventListener('click', function () { addColumn(); });
    wrap.appendChild(list);
    wrap.appendChild(addBtn);
    return wrap;
  }

  function buildRow(type, data) {
    data = data || {};
    var row = el('div', 'cms-section');
    row.dataset.type = type;
    row.draggable = true;

    var head = el('div', 'cms-section-head');
    var grip = el('span', 'grip', { title: 'Drag to reorder' });
    grip.textContent = '⠿';
    var badge = el('span', 'type-badge');
    badge.textContent = SECTION_LABELS[type] || type;
    var spacer = el('span', 'spacer');
    var up = el('button', 'btn sm secondary', { type: 'button', title: 'Move up' });
    up.textContent = '↑';
    var down = el('button', 'btn sm secondary', { type: 'button', title: 'Move down' });
    down.textContent = '↓';
    var del = el('button', 'btn sm danger', { type: 'button', title: 'Remove section' });
    del.textContent = '✕';
    up.addEventListener('click', function () {
      if (row.previousElementSibling && row.previousElementSibling.classList.contains('cms-section')) {
        builder.insertBefore(row, row.previousElementSibling);
      }
    });
    down.addEventListener('click', function () {
      if (row.nextElementSibling && row.nextElementSibling.classList.contains('cms-section')) {
        builder.insertBefore(row.nextElementSibling, row);
      }
    });
    del.addEventListener('click', function () {
      if (confirm('Remove this section?')) { row.remove(); }
    });
    head.appendChild(grip);
    head.appendChild(badge);
    head.appendChild(spacer);
    head.appendChild(up);
    head.appendChild(down);
    head.appendChild(del);

    var body = el('div', 'cms-section-body');
    (FIELDS[type] || []).forEach(function (f) {
      var node;
      if (f.kind === 'textarea') { node = areaInput(data[f.key]); }
      else if (f.kind === 'select') { node = selectInput(data[f.key], f.options); }
      else { node = textInput(data[f.key], f.placeholder || ''); }
      body.appendChild(fieldLabel(f.label, node, f.key));
    });
    if (HAS_IMAGE[type]) {
      var imgLabel = el('label', 'cms-label');
      imgLabel.textContent = type === 'hero' ? 'Background image' : 'Image';
      body.appendChild(imgLabel);
      body.appendChild(imageField(data.image));
    }
    if (type === 'columns') {
      body.appendChild(buildColumnsEditor(data.columns));
    }

    row.appendChild(head);
    row.appendChild(body);
    return row;
  }

  function readSection(row) {
    var type = row.dataset.type;
    var out = { type: type };
    (FIELDS[type] || []).forEach(function (f) {
      var node = row.querySelector('[data-key="' + f.key + '"]');
      out[f.key] = node ? (f.kind === 'textarea' ? node.value : node.value.trim()) : '';
    });
    if (HAS_IMAGE[type]) {
      var hidden = row.querySelector('.img-path');
      if (hidden && hidden.value) { out.image = hidden.value; }
    }
    if (type === 'columns') {
      out.columns = [];
      row.querySelectorAll('.cms-col').forEach(function (col) {
        var h = (col.querySelector('.col-heading') || {}).value || '';
        var b = (col.querySelector('.col-body') || {}).value || '';
        if (h.trim() || b.trim()) { out.columns.push({ heading: h, body: b }); }
      });
    }
    return out;
  }

  function serialize() {
    var arr = [];
    builder.querySelectorAll('.cms-section').forEach(function (row) { arr.push(readSection(row)); });
    contentField.value = JSON.stringify(arr);
  }

  function init() {
    var raw = contentField.value.trim();
    var sections = [];
    if (raw) {
      try { var d = JSON.parse(raw); if (Array.isArray(d)) { sections = d; } } catch (e) {}
    }
    sections.forEach(function (s) {
      if (s && typeof s === 'object' && SECTION_LABELS[s.type]) { builder.appendChild(buildRow(s.type, s)); }
    });
  }

  /* drag-and-drop reordering */
  var dragging = null;
  builder.addEventListener('dragstart', function (e) {
    dragging = e.target.closest('.cms-section');
    if (dragging) {
      dragging.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', 'section'); } catch (err) {}
    }
  });
  builder.addEventListener('dragend', function () {
    if (dragging) { dragging.classList.remove('dragging'); dragging = null; }
  });
  builder.addEventListener('dragover', function (e) {
    e.preventDefault();
    if (!dragging) { return; }
    var section = e.target.closest('.cms-section');
    if (!section || section === dragging) { return; }
    var rect = section.getBoundingClientRect();
    var after = (e.clientY - rect.top) > rect.height / 2;
    builder.insertBefore(dragging, after ? section.nextSibling : section);
  });
  builder.addEventListener('drop', function (e) { e.preventDefault(); });

  var addBtn = document.getElementById('cmsAddBtn');
  var addType = document.getElementById('cmsAddType');
  if (addBtn && addType) {
    addBtn.addEventListener('click', function () {
      builder.appendChild(buildRow(addType.value, {}));
    });
  }

  if (form) {
    form.addEventListener('submit', function () { serialize(); });
  }

  init();
})();
