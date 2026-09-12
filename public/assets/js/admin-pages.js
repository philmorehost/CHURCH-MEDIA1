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
        // The picker's buttons and rows are draggable, and an image is natively draggable,
        // so grabbing the preview would start an image drag and hijack reordering.
        img.draggable = false;
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

  function buildColumnsEditor(data) {
    var wrap = el('div', 'cms-cols-editor');
    var colsCount = Math.min(4, Math.max(1, parseInt(data.layout_columns || (Array.isArray(data.columns) ? data.columns.length : 3), 10) || 3));

    var topRow = el('div', 'cms-cols-ctrls', { style: 'display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; gap:12px; flex-wrap:wrap;' });
    var layoutLabel = el('label', 'cms-label', { style: 'margin:0;' });
    layoutLabel.textContent = 'Columns Per Row (Max 4): ';
    var colSelect = selectInput(colsCount, [[1, '1 Column (Full Width)'], [2, '2 Columns'], [3, '3 Columns'], [4, '4 Columns']]);
    colSelect.style.width = 'auto';
    colSelect.style.marginBottom = '0';
    layoutLabel.appendChild(colSelect);

    var addCardBtn = el('button', 'btn secondary sm', { type: 'button' });
    addCardBtn.textContent = '+ Add Card Item';

    topRow.appendChild(layoutLabel);
    topRow.appendChild(addCardBtn);

    var grid = el('div', 'cms-cols-grid cols-' + colsCount, { style: 'display:grid; gap:12px; margin-bottom:12px;' });
    grid.dataset.layoutColumns = colsCount;

    colSelect.addEventListener('change', function () {
      var n = parseInt(colSelect.value, 10);
      grid.className = 'cms-cols-grid cols-' + n;
      grid.dataset.layoutColumns = n;
    });

    function createCardNode(colData) {
      colData = colData || { heading: '', body: '', link: '', image: '', alt: '' };
      var card = el('div', 'cms-col-card', { draggable: 'true', style: 'border:1px solid var(--border); border-radius:10px; padding:12px; background:#0f0d1f; cursor:grab;' });

      var cardGrip = el('div', 'card-grip', { style: 'display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; padding-bottom:6px; border-bottom:1px solid #1f1b3a;' });
      var gripTitle = el('span', null, { style: 'font-size:11px; font-weight:700; color:var(--gold-soft); text-transform:uppercase;' });
      gripTitle.textContent = '⠿ Card Item';
      var delBtn = el('button', 'btn sm danger', { type: 'button', style: 'padding:2px 8px; font-size:11px;' });
      delBtn.textContent = '✕ Remove';
      delBtn.addEventListener('click', function () { card.remove(); });
      cardGrip.appendChild(gripTitle);
      cardGrip.appendChild(delBtn);

      var h = textInput(colData.heading, 'Card heading');
      h.className += ' col-heading';
      var b = areaInput(colData.body);
      b.className += ' col-body';
      var l = textInput(colData.link, 'Optional button link (e.g. /contact)');
      l.className += ' col-link';
      var a = textInput(colData.alt, 'Describe the image for screen readers');
      a.className += ' col-alt';
      // Reuse the same uploader the hero and image blocks use, so cards get the identical
      // compression, the same 8MB guard and the same cms/ folder (which the page delete
      // already sweeps for orphaned files).
      var img = imageField(colData.image);
      img.classList.add('col-image');

      card.appendChild(cardGrip);
      card.appendChild(fieldLabel('Card Image (Optional)', img, ''));
      card.appendChild(fieldLabel('Card Heading', h, ''));
      card.appendChild(fieldLabel('Card Text / Description', b, ''));
      card.appendChild(fieldLabel('Card Link (Optional)', l, ''));
      card.appendChild(fieldLabel('Image Description (Optional, for screen readers)', a, ''));

      card.addEventListener('dragstart', function (e) {
        e.stopPropagation();
        card.classList.add('dragging-card');
        try { e.dataTransfer.setData('text/plain', 'card'); } catch (err) {}
      });
      card.addEventListener('dragend', function (e) {
        e.stopPropagation();
        card.classList.remove('dragging-card');
      });

      return card;
    }

    grid.addEventListener('dragover', function (e) {
      var draggingCard = grid.querySelector('.dragging-card');
      if (!draggingCard) return;
      e.preventDefault();
      var afterElement = getDragAfterElement(grid, e.clientY);
      if (afterElement == null) {
        grid.appendChild(draggingCard);
      } else {
        grid.insertBefore(draggingCard, afterElement);
      }
    });

    function getDragAfterElement(container, y) {
      var draggableElements = Array.prototype.slice.call(container.querySelectorAll('.cms-col-card:not(.dragging-card)'));
      return draggableElements.reduce(function (closest, child) {
        var box = child.getBoundingClientRect();
        var offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
          return { offset: offset, element: child };
        } else {
          return closest;
        }
      }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    addCardBtn.addEventListener('click', function () {
      grid.appendChild(createCardNode());
    });

    var initialCols = Array.isArray(data.columns) ? data.columns : [];
    if (initialCols.length === 0) {
      grid.appendChild(createCardNode({ heading: 'Feature 1', body: 'Description details...', link: '' }));
      grid.appendChild(createCardNode({ heading: 'Feature 2', body: 'Description details...', link: '' }));
      grid.appendChild(createCardNode({ heading: 'Feature 3', body: 'Description details...', link: '' }));
    } else {
      initialCols.forEach(function (c) {
        grid.appendChild(createCardNode(c));
      });
    }

    wrap.appendChild(topRow);
    wrap.appendChild(grid);
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
      body.appendChild(buildColumnsEditor(data));
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
      var grid = row.querySelector('.cms-cols-grid');
      out.layout_columns = grid ? parseInt(grid.dataset.layoutColumns || '3', 10) : 3;
      row.querySelectorAll('.cms-col-card').forEach(function (col) {
        var h = (col.querySelector('.col-heading') || {}).value || '';
        var b = (col.querySelector('.col-body') || {}).value || '';
        var l = (col.querySelector('.col-link') || {}).value || '';
        var a = (col.querySelector('.col-alt') || {}).value || '';
        var imgNode = col.querySelector('.col-image .img-path');
        var img = imgNode ? imgNode.value : '';
        // A card carrying only a photo is still a card, so the image counts here too -
        // otherwise a photo-only card would be thrown away on save.
        if (h.trim() || b.trim() || l.trim() || img) {
          out.columns.push({ heading: h, body: b, link: l, image: img, alt: a });
        }
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
