(function () {
  'use strict';

  var form = document.getElementById('mediaForm');
  var csrfInput = form ? form.querySelector('input[name="_csrf"]') : null;
  var csrfToken = csrfInput ? csrfInput.value : '';

  /* ---------- shared ---------- */
  function esc(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function ytId(url) {
    var m;
    m = url.match(/youtube\.com\/watch\?[^&\s]*&?v=([a-zA-Z0-9_-]{6,})/);
    if (m) { return m[1]; }
    m = url.match(/youtube\.com\/(?:embed|shorts|live|v)\/([a-zA-Z0-9_-]{6,})/);
    if (m) { return m[1]; }
    m = url.match(/youtu\.be\/([a-zA-Z0-9_-]{6,})/);
    return m ? m[1] : null;
  }

  /* ---------- upload pane: previews + auto cover capture ---------- */
  var mediaInput = document.getElementById('media');
  var previewBox = document.getElementById('mediaPreview');
  var videoCovers = new Map(); // file index -> captured/replaced cover Blob

  function captureFrame(file, atSeconds) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var video = document.createElement('video');
      video.muted = true;
      video.playsInline = true;
      video.preload = 'auto';
      video.src = url;
      video.addEventListener('loadeddata', function () {
        try { video.currentTime = Math.min(atSeconds, (video.duration || atSeconds) / 2); } catch (e) {}
      });
      video.addEventListener('seeked', function () {
        try {
          var w = video.videoWidth || 320;
          var h = video.videoHeight || 568;
          var canvas = document.createElement('canvas');
          canvas.width = w;
          canvas.height = h;
          canvas.getContext('2d').drawImage(video, 0, 0, w, h);
          URL.revokeObjectURL(url);
          canvas.toBlob(function (b) { b ? resolve(b) : reject(new Error('frame unavailable')); }, 'image/jpeg', 0.85);
        } catch (e) { reject(e); }
      });
      video.addEventListener('error', function () { URL.revokeObjectURL(url); reject(new Error('video unreadable')); });
    });
  }

  function renderPreviews(files) {
    previewBox.innerHTML = '';
    videoCovers.clear();
    Array.prototype.forEach.call(files, function (file, i) {
      var isVideo = file.type.indexOf('video/') === 0;
      var tile = document.createElement('div');
      tile.className = 'preview-tile' + (isVideo ? ' is-video' : '');
      var img = document.createElement('img');
      if (isVideo) {
        captureFrame(file, 0.5).then(function (blob) {
          videoCovers.set(i, blob);
          img.src = URL.createObjectURL(blob);
          img.classList.add('has-cover');
        }).catch(function () {
          img.style.display = 'none';
        });
      } else {
        img.src = URL.createObjectURL(file);
      }
      tile.appendChild(img);

      var badge = document.createElement('span');
      badge.className = 'tile-badge';
      badge.textContent = isVideo ? 'Cover' : 'Image';
      tile.appendChild(badge);

      if (isVideo) {
        var label = document.createElement('span');
        label.className = 'tile-label';
        label.textContent = 'Auto cover · tap to replace';
        tile.appendChild(label);
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.hidden = true;
        input.addEventListener('change', function () {
          if (!input.files.length) { return; }
          captureFrame(input.files[0], 0).then(function (blob) {
            videoCovers.set(i, blob);
            img.src = URL.createObjectURL(blob);
            img.classList.add('has-cover');
            label.textContent = 'Custom cover set';
          }).catch(function () {});
        });
        tile.appendChild(input);
        tile.addEventListener('click', function (e) {
          if (e.target.tagName === 'IMG' || e.target === tile) { input.click(); }
        });
      }
      previewBox.appendChild(tile);
    });
    if (files.length) { previewBox.style.display = 'flex'; }
  }

  if (mediaInput) {
    mediaInput.addEventListener('change', function () { renderPreviews(mediaInput.files); });
  }

  /* ---------- youtube preview ---------- */
  var ytUrl = document.getElementById('youtube_url');
  var ytPreview = document.getElementById('ytPreview');
  var ytCoverInput = document.getElementById('youtube_cover');
  var ytCoverPreview = document.getElementById('ytCoverPreview');

  if (ytUrl) {
    ytUrl.addEventListener('input', function () {
      var id = ytId(ytUrl.value.trim());
      if (id) {
        ytPreview.innerHTML = '<img src="https://i.ytimg.com/vi/' + esc(id) + '/hqdefault.jpg" alt="YouTube preview">'
          + '<div>Will play as a vertical reel (YouTube Shorts render best).</div>';
      } else {
        ytPreview.innerHTML = '';
      }
    });
  }

  if (ytCoverInput) {
    ytCoverInput.addEventListener('change', function () {
      ytCoverPreview.innerHTML = ytCoverInput.files.length
        ? '<img src="' + URL.createObjectURL(ytCoverInput.files[0]) + '" alt="Cover preview">'
        : '';
    });
  }

  /* ---------- progress ---------- */
  var progressWrap = document.getElementById('progressWrap');
  var progressBar = document.getElementById('progressBar');
  var progressLabel = document.getElementById('progressLabel');

  function setProgress(pct, label) {
    progressWrap.hidden = false;
    progressBar.style.width = Math.max(2, Math.min(100, pct)) + '%';
    progressLabel.textContent = label;
  }

  function backgroundProcess(pendingIds) {
    if (!pendingIds || !pendingIds.length) { return; }
    pendingIds.forEach(function (id) {
      var fd = new FormData();
      fd.append('_csrf', csrfToken);
      fd.append('id', String(id));
      try {
        if (navigator.sendBeacon) {
          navigator.sendBeacon('/admin/media?action=process', fd);
        } else {
          fetch('/admin/media?action=process', { method: 'POST', body: fd }).catch(function () {});
        }
      } catch (e) {}
    });
  }

  /* ---------- submit ---------- */
  if (!form) { return; }
  var publishBtn = document.getElementById('publishBtn');

  form.addEventListener('submit', function (e) {
    var rawUrl = ytUrl ? ytUrl.value.trim() : '';
    var id = ytId(rawUrl);

    if (rawUrl !== '' && !id) {
      alert("That YouTube link doesn't look valid.");
      e.preventDefault();
      return;
    }
    if (rawUrl === '' && !mediaInput.files.length) {
      alert('Paste a YouTube link or choose a photo/video.');
      e.preventDefault();
      return;
    }

    /* Everything is valid. If the file field is empty or a link is present,
       let the browser submit natively (POST /admin/media?action=create) so
       posting still works even if this script later throws. */
    if (rawUrl !== '' || mediaInput.files.length === 0) {
      return;
    }

    e.preventDefault();

    var fd = new FormData(form);
    videoCovers.forEach(function (blob, i) { fd.append('cover_' + i, blob, 'cover_' + i + '.jpg'); });

    publishBtn.disabled = true;
    setProgress(0, 'Uploading… 0%');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/media?action=upload');
    xhr.upload.addEventListener('progress', function (ev) {
      if (ev.lengthComputable) {
        var pct = Math.round((ev.loaded / ev.total) * 100);
        setProgress(pct, 'Uploading… ' + pct + '%');
      }
    });
    xhr.addEventListener('load', function () {
      var data;
      try { data = JSON.parse(xhr.responseText); } catch (err) { data = null; }
      if (data && data.status === 'success') {
        setProgress(100, 'Published! Optimizing video crops in the background…');
        backgroundProcess(data.pending);
        setTimeout(function () { window.location.href = '/admin/media?processed=1'; }, 1500);
      } else {
        publishBtn.disabled = false;
        setProgress(100, (data && data.message) || 'Upload failed.');
      }
    });
    xhr.addEventListener('error', function () {
      publishBtn.disabled = false;
      setProgress(100, 'Network error while uploading.');
    });
    xhr.send(fd);
  });
})();
