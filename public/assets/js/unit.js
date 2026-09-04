(function () {
  'use strict';

  var grid = document.getElementById('unitGrid');
  if (!grid) { return; }
  var slug = grid.getAttribute('data-slug');
  var shuffle = true;
  var countEl = document.getElementById('unitCount');
  var shuffleBtn = document.getElementById('unitShuffle');
  var activeCat = '';

  var chips = document.querySelectorAll('.unit-chip');
  if (chips.length) {
    chips.forEach(function (ch) {
      ch.addEventListener('click', function () {
        var slug = ch.getAttribute('data-category') || '';
        activeCat = (activeCat === slug) ? '' : slug;
        chips.forEach(function (c) { c.classList.toggle('active', (c.getAttribute('data-category') || '') === activeCat); });
        load();
      });
    });
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function tileHtml(post) {
    var items = post.media_items || [];
    if (!items.length) { return ''; }
    var first = items[0];
    var isVideo = first.type === 'video';
    var thumb = isVideo ? (first.thumbnail_url || '') : (first.file_url || '');
    var badge = isVideo ? '<span class="tile-play">▶</span>' : '';
    var chip = post.post_type === 'vertical_reel' ? '<span class="tile-type">Reel</span>' : (post.post_type === 'carousel' ? '<span class="tile-type">Album</span>' : '');
    var pin = post.is_pinned ? '<span class="tile-type tile-pin">📌 Pinned</span>' : '';
    var cap = escapeHtml(post.caption || '');
    return '<a class="unit-tile" href="/feed?post=' + encodeURIComponent(post.id) + '" title="' + cap + '">' +
      (thumb ? '<img src="' + escapeHtml(thumb) + '" alt="" loading="lazy">' : '<span class="tile-empty">♪</span>') +
      badge + chip + pin + '</a>';
  }

  function load() {
    grid.innerHTML = '<div class="unit-loading">Loading media…</div>';
    var qs = 'shuffle=' + (shuffle ? '1' : '0') + '&per_page=100';
    if (activeCat) { qs += '&category=' + encodeURIComponent(activeCat); }
    // empty slug = global gallery (all media), otherwise a unit gallery
    var endpoint = slug ? ('/api/unit.php?slug=' + encodeURIComponent(slug)) : '/api/media';
    // The unit endpoint already has a query string, so join with '&' — using
    // '?' again would corrupt the slug (e.g. ?slug=x?shuffle=1) and make the
    // API return "unit not found", which surfaced as "No media found.".
    var url = endpoint + (slug ? '&' : '?') + qs;
    fetch(url)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || data.status !== 'success') {
          grid.innerHTML = '<div class="unit-empty">No media found.</div>';
          return;
        }
        var posts = data.data || [];
        if (countEl) { countEl.textContent = posts.length + ' post' + (posts.length === 1 ? '' : 's'); }
        if (!posts.length) { grid.innerHTML = '<div class="unit-empty">No media in this unit yet.</div>'; return; }
        grid.innerHTML = posts.map(tileHtml).join('');
      })
      .catch(function () { grid.innerHTML = '<div class="unit-empty">Could not load media.</div>'; });
  }

  if (shuffleBtn) {
    shuffleBtn.addEventListener('click', function () {
      shuffle = !shuffle;
      shuffleBtn.textContent = shuffle ? '🔀 Shuffle: On' : '🔀 Shuffle: Off';
      load();
    });
  }
  load();
})();
