(function () {
  'use strict';

  var scroller = document.getElementById('feedScroller');
  var loadingEl = document.getElementById('feedLoading');
  var template = document.getElementById('feedSlideTemplate');
  if (!scroller || !template) { return; }

  var endpoint = scroller.getAttribute('data-endpoint');
  var state = { page: 1, hasMore: true, loading: false, category: '', view: 'all', seenIds: new Set(), seenPosts: new Set() };
  var muted = false; // default sound ON — videos are not muted by default
  var currentVideo = null;
  var activeSlideEl = null;
  var commentPost = { id: null, slide: null };
  var deepPostId = (function () {
    try {
      var n = parseInt(new URLSearchParams(window.location.search).get('post') || '', 10);
      return n > 0 ? n : null;
    } catch (e) { return null; }
  })();

  var slideObserver = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      var slide = entry.target;
      if (entry.isIntersecting && entry.intersectionRatio >= 0.6) {
        activeSlideEl = slide;
        activateMedia(slide, true);
        pingView(slide.getAttribute('data-post-id'));
      } else {
        activateMedia(slide, false);
        if (activeSlideEl === slide) { activeSlideEl = null; }
      }
    });
  }, { root: scroller, threshold: [0, 0.6] });

  var sentinelObserver = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) { loadPage(); }
    });
  }, { root: scroller, threshold: 0.1 });

  /* ---------- helpers ---------- */
  function formatCount(n) {
    n = Number(n) || 0;
    if (n >= 1000000) { return (n / 1000000).toFixed(1) + 'M'; }
    if (n >= 1000) { return (n / 1000).toFixed(1) + 'K'; }
    return String(n);
  }

  function formatPostedAt(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) { return ''; }
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var h = d.getHours() % 12 || 12;
    var ampm = d.getHours() >= 12 ? 'PM' : 'AM';
    var min = ('0' + d.getMinutes()).slice(-2);
    return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear() + ' · ' + h + ':' + min + ' ' + ampm;
  }

  function timeAgo(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) { return ''; }
    var s = Math.floor((Date.now() - d.getTime()) / 1000);
    if (s < 5) { return 'just now'; }
    if (s < 60) { return s + 's ago'; }
    var m = Math.floor(s / 60);
    if (m < 60) { return m + 'm ago'; }
    var h = Math.floor(m / 60);
    if (h < 24) { return h + 'h ago'; }
    var days = Math.floor(h / 24);
    if (days < 7) { return days + 'd ago'; }
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function youtubeEmbed(id, playing, isMuted) {
    return 'https://www.youtube.com/embed/' + encodeURIComponent(id) +
      '?autoplay=' + (playing ? 1 : 0) +
      '&mute=' + (isMuted ? 1 : 0) +
      '&playsinline=1&loop=1&rel=0&iv_load_policy=3' +
      '&playlist=' + encodeURIComponent(id);
  }

  function setYoutube(iframe, id, playing, isMuted) {
    if (!iframe || !id) { return; }
    iframe.src = youtubeEmbed(id, playing, isMuted);
  }

  function playVideo(video) {
    if (currentVideo && currentVideo !== video) { currentVideo.pause(); }
    video.muted = muted;
    var p = video.play();
    if (p) {
      p.catch(function () {
        // Autoplay-with-sound is blocked by the browser autoplay policy.
        // Fall back to muted so the reel still plays; the first tap anywhere
        // (unlockAudio) turns sound on for the whole session.
        if (!video.muted) {
          video.muted = true;
          muted = true;
          updateMuteButtons();
          video.play().catch(function () {});
        }
      });
    }
    currentVideo = video;
  }

  function activateMedia(slide, playing) {
    if (!slide) { return; }
    var video = slide.querySelector('video');
    var yt = slide.querySelector('iframe.reel-youtube');
    if (video) {
      if (playing) { playVideo(video); } else { video.pause(); }
    }
    if (yt) { setYoutube(yt, yt.dataset.videoId, playing, muted); }
  }

  function pingView(postId) {
    if (!postId || state.seenIds.has(postId + ':viewed')) { return; }
    state.seenIds.add(postId + ':viewed');
    fetch('/api/post?id=' + encodeURIComponent(postId)).catch(function () {});
  }

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json(); });
  }

  /* ---------- media rendering ---------- */
  function buildMedia(post, slide, mediaEl, dotsEl, onLikeDouble) {
    var items = post.media_items && post.media_items.length ? post.media_items : [];
    if (!items.length) { return; }
    var activeIndex = 0;

    function render(index) {
      mediaEl.innerHTML = '';
      dotsEl.innerHTML = '';
      var item = items[index];
      if (!item) { return; }

      if (items.length > 1) {
        for (var d = 0; d < items.length; d++) {
          var dot = document.createElement('span');
          if (d === index) { dot.className = 'on'; }
          dotsEl.appendChild(dot);
        }
      }

      if (item.type === 'video' && item.source === 'youtube') {
        var id = ((item.file_url || '').match(/\/embed\/([a-zA-Z0-9_-]+)/) || [])[1];
        var iframe = document.createElement('iframe');
        iframe.className = 'reel-youtube';
        iframe.dataset.videoId = id;
        iframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('frameborder', '0');
        iframe.src = youtubeEmbed(id, false, muted);
        mediaEl.appendChild(iframe);
      } else if (item.type === 'video') {
        var v = document.createElement('video');
        v.src = item.file_url;
        v.loop = true;
        v.muted = muted;
        v.playsInline = true;
        v.preload = 'auto';
        if (item.thumbnail_url) { v.poster = item.thumbnail_url; }
        mediaEl.appendChild(v);
        if (slide === activeSlideEl) { playVideo(v); }
      } else {
        var img = document.createElement('img');
        img.src = item.file_url || item.thumbnail_url || '';
        img.alt = item.alt_text || '';
        img.loading = 'lazy';
        mediaEl.appendChild(img);
      }
    }

    render(activeIndex);

    if (items.length > 1) {
      var left = document.createElement('div');
      var right = document.createElement('div');
      [left, right].forEach(function (zone, i) {
        zone.style.cssText = 'position:absolute;top:0;bottom:0;width:38%;z-index:3;' + (i === 0 ? 'left:0;' : 'right:0;');
        mediaEl.appendChild(zone);
        zone.addEventListener('click', function (e) {
          e.stopPropagation();
          activeIndex = (activeIndex + (i === 0 ? items.length - 1 : 1)) % items.length;
          render(activeIndex);
        });
      });
    }

    attachTap(mediaEl, function () { triggerLike(post, slide); });
  }

  /* double-tap = like. Single tap is intentionally a no-op: sound is controlled
     by the always-visible speaker button so videos can autoplay unmuted. */
  function attachTap(el, onDouble) {
    var timer = null;
    el.addEventListener('click', function () {
      if (timer) {
        clearTimeout(timer);
        timer = null;
        onDouble();
        return;
      }
      timer = setTimeout(function () { timer = null; }, 280);
    });
  }

  function toggleMute(slide) {
    muted = !muted;
    var video = slide.querySelector('video');
    var yt = slide.querySelector('iframe.reel-youtube');
    if (video) {
      video.muted = muted;
      // Re-engage playback after unmuting so the browser applies the new audio
      // state immediately (not just on the next autoplay).
      if (!muted) { video.play().catch(function () {}); }
    }
    if (yt) { setYoutube(yt, yt.dataset.videoId, true, muted); }
    updateMuteButtons();
    var badge = slide.querySelector('.reel-mute-badge');
    if (badge) { badge.textContent = muted ? '🔇 Muted' : '🔊 Sound on'; }
    slide.classList.add('show-hint');
    setTimeout(function () { slide.classList.remove('show-hint'); }, 1400);
  }

  function updateMuteButtons() {
    var btns = scroller.querySelectorAll('.reel-mute-btn');
    for (var i = 0; i < btns.length; i++) {
      btns[i].textContent = muted ? '🔇' : '🔊';
      btns[i].setAttribute('aria-label', muted ? 'Unmute' : 'Mute');
    }
  }

  /* One-time unlock: browsers only allow sound after the user has interacted
     with the page, so a reel may have fallen back to muted autoplay. The first
     tap/touch/click/keypress anywhere turns sound on and keeps it on. */
  function unlockAudio() {
    document.removeEventListener('pointerdown', unlockAudio);
    document.removeEventListener('touchstart', unlockAudio);
    document.removeEventListener('click', unlockAudio);
    document.removeEventListener('keydown', unlockAudio);
    if (!muted) { return; }
    muted = false;
    updateMuteButtons();
    var slide = activeSlideEl;
    var video = slide && slide.querySelector('video');
    if (video && video.muted) {
      video.muted = false;
      video.play().catch(function () {});
    }
    var yt = slide && slide.querySelector('iframe.reel-youtube');
    if (yt && yt.dataset.videoId) { setYoutube(yt, yt.dataset.videoId, true, false); }
  }
  document.addEventListener('pointerdown', unlockAudio);
  document.addEventListener('touchstart', unlockAudio);
  document.addEventListener('click', unlockAudio);
  document.addEventListener('keydown', unlockAudio);
  function burstLike(slide) {
    var burst = slide.querySelector('.reel-heart-burst');
    burst.classList.remove('burst');
    void burst.offsetWidth;
    burst.classList.add('burst');
    setTimeout(function () { burst.classList.remove('burst'); }, 450);
  }

  function triggerLike(post, slide) {
    if (post.liked_by_viewer) { return; }
    doLike(post, slide);
  }

  function doLike(post, slide) {
    postJson('/api/like', { post_id: post.id }).then(function (data) {
      if (data.status !== 'success') { return; }
      post.liked_by_viewer = data.liked;
      post.likes_count = data.likes_count;
      var likeBtn = slide.querySelector('.reel-like');
      likeBtn.classList.toggle('liked', data.liked);
      likeBtn.querySelector('.like-count').textContent = formatCount(data.likes_count);
      if (data.liked) {
        likeBtn.classList.remove('pop');
        void likeBtn.offsetWidth;
        likeBtn.classList.add('pop');
        burstLike(slide);
      }
    }).catch(function () {});
  }

  function doSave(post, slide) {
    postJson('/api/save', { post_id: post.id }).then(function (data) {
      if (data.status !== 'success') { return; }
      post.saved_by_viewer = data.saved;
      post.saves_count = data.saves_count;
      slide.querySelector('.reel-save').classList.toggle('saved', data.saved);
    }).catch(function () {});
  }

  function share(post) {
    var url = window.location.origin + '/feed?post=' + post.id;
    if (navigator.share) {
      navigator.share({ title: post.caption || 'Check this out', url: url }).catch(function () {});
    } else if (navigator.clipboard) {
      navigator.clipboard.writeText(url).then(function () { alert('Link copied.'); });
    }
  }

  /* ---------- comment sheet (Instagram-style) ---------- */
  var sheet = document.getElementById('commentSheet');
  var sheetList = document.getElementById('commentList');
  var sheetForm = document.getElementById('commentForm');
  var sheetName = document.getElementById('commentName');
  var sheetMessage = document.getElementById('commentMessage');
  var replyBar = document.getElementById('commentReplyBar');
  var replyLabel = document.getElementById('commentReplyLabel');
  var replyCancel = document.getElementById('commentReplyCancel');
  var emojiBtn = document.getElementById('commentEmojiBtn');
  var emojiPicker = document.getElementById('commentEmojiPicker');
  var imageInput = document.getElementById('commentImage');
  var imagePreview = document.getElementById('commentImagePreview');
  var imagePreviewImg = document.getElementById('commentImagePreviewImg');
  var imageRemove = document.getElementById('commentImageRemove');
  var replyTo = null;
  var selectedImage = null;

  var EMOJIS = ['😂','😍','😊','🙏','❤️','🔥','👍','👏','🙌','😮','🥰','😢','😎','🤣','💯','🎉','✝️','💒','😇','🤗','😅','🥹','😴','🤔','✨','💖','🕊️','🎶'];

  function mediaUrl(path) {
    if (!path) { return ''; }
    if (/^https?:\/\//i.test(path)) { return path; }
    return window.location.origin + '/uploads/' + path.replace(/^\/+/, '');
  }

  // Real-time comments: light polling while the sheet is open.
  var commentPollTimer = null;
  var commentSig = '';

  function fetchComments() {
    if (!commentPost.id) { return Promise.resolve([]); }
    return fetch('/api/comments?post_id=' + encodeURIComponent(commentPost.id))
      .then(function (r) { return r.json(); })
      .then(function (data) { return data.data || []; });
  }

  function commentSignature(list) {
    return JSON.stringify((list || []).map(function (c) {
      var replies = (c.replies || []).map(function (r) { return r.id + ':' + (r.message || '').length; });
      return [c.id, (c.message || '').length, (c.reply_count || 0), replies.join(',')];
    }));
  }

  function commentTotal(list) {
    return (list || []).reduce(function (acc, c) {
      return acc + 1 + ((c.replies || []).length);
    }, 0);
  }

  function updateReelCommentCount(n) {
    if (!commentPost.slide) { return; }
    var countEl = commentPost.slide.querySelector('.comment-count');
    if (!countEl) { return; }
    countEl.dataset.count = String(n);
    countEl.textContent = formatCount(n);
  }

  function pollComments() {
    if (sheet.hidden || !commentPost.id) { return; }
    fetchComments().then(function (list) {
      var sig = commentSignature(list);
      if (sig === commentSig) { return; }
      var nearBottom = sheetList.scrollHeight - sheetList.scrollTop - sheetList.clientHeight < 60;
      commentSig = sig;
      renderComments(list);
      updateReelCommentCount(commentTotal(list));
      if (nearBottom) { sheetList.scrollTop = sheetList.scrollHeight; }
    }).catch(function () {});
  }

  function startCommentPolling() {
    stopCommentPolling();
    commentPollTimer = setInterval(pollComments, 4000);
  }

  function stopCommentPolling() {
    if (commentPollTimer) { clearInterval(commentPollTimer); commentPollTimer = null; }
  }

  function openComments(post, slide) {
    commentPost = { id: post.id, slide: slide };
    replyTo = null;
    selectedImage = null;
    commentSig = '';
    updateReplyBar();
    clearImagePreview();
    sheet.hidden = false;
    sheetList.innerHTML = '<div class="feed-loading">Loading comments…</div>';
    sheetName.value = localStorage.getItem('reel_comment_name') || '';
    fetchComments().then(function (list) {
      commentSig = commentSignature(list);
      renderComments(list);
    }).catch(function () { sheetList.innerHTML = '<div class="comment-empty">Could not load comments.</div>'; });
    startCommentPolling();
  }

  function renderComments(list) {
    if (!list.length) {
      sheetList.innerHTML = '<div class="comment-empty">Be the first to leave a comment. 💬</div>';
      return;
    }
    sheetList.innerHTML = '';
    list.forEach(function (c) {
      sheetList.appendChild(buildCommentNode(c, false));
    });
  }

  function buildCommentNode(c, isReply) {
    var item = document.createElement('div');
    item.className = 'comment-item' + (isReply ? ' comment-reply' : '');
    item.dataset.id = String(c.id);

    var head = document.createElement('div');
    head.className = 'c-row';

    var avatar = document.createElement('div');
    avatar.className = 'c-avatar';
    avatar.textContent = (c.name || '?').charAt(0).toUpperCase();
    head.appendChild(avatar);

    var body = document.createElement('div');
    body.className = 'c-body';

    var meta = document.createElement('div');
    meta.className = 'c-meta';
    meta.innerHTML = '<span class="c-name">' + escapeHtml(c.name || 'Anonymous') + '</span>'
      + '<span class="c-time">' + escapeHtml(timeAgo(c.created_at)) + '</span>';
    body.appendChild(meta);

    if (c.message) {
      var msg = document.createElement('div');
      msg.className = 'c-message';
      msg.textContent = c.message;
      body.appendChild(msg);
    }

    if (c.image_path) {
      var imgWrap = document.createElement('div');
      imgWrap.className = 'c-image';
      var img = document.createElement('img');
      img.src = mediaUrl(c.image_path);
      img.alt = 'comment image';
      img.loading = 'lazy';
      img.addEventListener('click', function () { window.open(img.src, '_blank'); });
      imgWrap.appendChild(img);
      body.appendChild(imgWrap);
    }

    var actions = document.createElement('div');
    actions.className = 'c-actions';
    var likeBtn = document.createElement('button');
    likeBtn.type = 'button';
    likeBtn.className = 'c-like' + (c.liked ? ' liked' : '');
    likeBtn.dataset.commentId = String(c.id);
    likeBtn.innerHTML = '<span class="c-like-icon">' + (c.liked ? '♥' : '♡') + '</span>'
      + '<span class="c-like-count">' + formatCount(c.likes_count || 0) + '</span>';
    var replyBtn = document.createElement('button');
    replyBtn.type = 'button';
    replyBtn.className = 'c-reply';
    replyBtn.textContent = 'Reply';
    replyBtn.dataset.replyTo = String(c.id);
    replyBtn.dataset.replyName = c.name || 'Anonymous';
    actions.appendChild(likeBtn);
    actions.appendChild(replyBtn);
    body.appendChild(actions);

    item.appendChild(body);

    if (!isReply && c.replies && c.replies.length) {
      var repliesWrap = document.createElement('div');
      repliesWrap.className = 'c-replies';
      c.replies.forEach(function (r) {
        repliesWrap.appendChild(buildCommentNode(r, true));
      });
      item.appendChild(repliesWrap);
    }

    return item;
  }

  function updateReplyBar() {
    if (!replyTo) {
      if (replyBar) { replyBar.hidden = true; }
      if (replyLabel) { replyLabel.textContent = ''; }
    } else {
      if (replyBar) { replyBar.hidden = false; }
      if (replyLabel) { replyLabel.textContent = 'Replying to ' + (replyTo.name || 'this comment'); }
      sheetMessage.focus();
    }
  }

  function clearImagePreview() {
    selectedImage = null;
    if (imageInput) { imageInput.value = ''; }
    if (imagePreview) { imagePreview.hidden = true; imagePreviewImg.src = ''; }
  }

  // Emoji picker
  if (emojiBtn && emojiPicker) {
    emojiPicker.innerHTML = EMOJIS.map(function (e) {
      return '<button type="button" class="c-emoji" data-emoji="' + e + '">' + e + '</button>';
    }).join('');
    emojiBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      emojiPicker.hidden = !emojiPicker.hidden;
    });
    emojiPicker.addEventListener('click', function (e) {
      var btn = e.target.closest('.c-emoji');
      if (!btn) { return; }
      var em = btn.getAttribute('data-emoji');
      var start = sheetMessage.selectionStart || sheetMessage.value.length;
      var end = sheetMessage.selectionEnd || sheetMessage.value.length;
      sheetMessage.value = sheetMessage.value.slice(0, start) + em + sheetMessage.value.slice(end);
      sheetMessage.focus();
      var pos = start + em.length;
      sheetMessage.setSelectionRange(pos, pos);
      emojiPicker.hidden = true;
    });
    document.addEventListener('click', function (e) {
      if (!emojiPicker.hidden && !e.target.closest('.comment-emoji-picker') && e.target !== emojiBtn) {
        emojiPicker.hidden = true;
      }
    });
  }

  // Image attach
  if (imageInput) {
    imageInput.addEventListener('change', function () {
      var f = imageInput.files && imageInput.files[0];
      if (!f) { return; }
      if (f.size > 10 * 1024 * 1024) { alert('Image too large — keep it under 10MB.'); imageInput.value = ''; return; }
      selectedImage = f;
      imagePreviewImg.src = URL.createObjectURL(f);
      imagePreview.hidden = false;
    });
  }
  if (imageRemove) {
    imageRemove.addEventListener('click', clearImagePreview);
  }
  if (replyCancel) {
    replyCancel.addEventListener('click', function () { replyTo = null; updateReplyBar(); });
  }

  // Delegated like + reply actions on the comment list
  sheetList.addEventListener('click', function (e) {
    var like = e.target.closest('.c-like');
    if (like) {
      var cid = parseInt(like.getAttribute('data-comment-id'), 10);
      if (!cid) { return; }
      postJson('/api/comments', { action: 'like', comment_id: cid })
        .then(function (data) {
          if (!data || data.status !== 'success') { return; }
          like.classList.toggle('liked', !!data.data.liked);
          like.querySelector('.c-like-icon').textContent = data.data.liked ? '♥' : '♡';
          like.querySelector('.c-like-count').textContent = formatCount(data.data.likes_count);
        })
        .catch(function () {});
      return;
    }
    var rep = e.target.closest('.c-reply');
    if (rep) {
      replyTo = { id: parseInt(rep.getAttribute('data-reply-to'), 10), name: rep.getAttribute('data-reply-name') };
      updateReplyBar();
    }
  });

  sheetForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var message = sheetMessage.value.trim();
    var name = sheetName.value.trim();
    if ((!message && !selectedImage) || !commentPost.id) { return; }
    if (name) { localStorage.setItem('reel_comment_name', name); }

    var done = function (data) {
      if (!data || data.status !== 'success') {
        alert((data && (data.message || (data.data && data.data.message))) || 'Could not post comment.');
        return;
      }
      sheetMessage.value = '';
      clearImagePreview();
      replyTo = null;
      updateReplyBar();
      fetchComments().then(function (list) {
        commentSig = commentSignature(list);
        renderComments(list);
        updateReelCommentCount(commentTotal(list));
        sheetList.scrollTop = sheetList.scrollHeight;
      });
    };
    var fail = function () { alert('Could not post comment.'); };

    var payload = { post_id: commentPost.id, name: name, message: message };
    if (replyTo) { payload.parent_id = replyTo.id; }

    if (selectedImage) {
      var fd = new FormData();
      fd.append('post_id', String(commentPost.id));
      fd.append('name', name);
      fd.append('message', message);
      if (replyTo) { fd.append('parent_id', String(replyTo.id)); }
      fd.append('image', selectedImage);
      fetch('/api/comments', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(done)
        .catch(fail);
    } else {
      postJson('/api/comments', payload).then(done).catch(fail);
    }
  });

  sheet.querySelectorAll('[data-close-comments]').forEach(function (el) {
    el.addEventListener('click', function () {
      sheet.hidden = true;
      stopCommentPolling();
      replyTo = null;
      clearImagePreview();
      if (emojiPicker) { emojiPicker.hidden = true; }
    });
  });

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  /* ---------- slide build ---------- */
  function buildSlide(post) {
    var node = template.content.cloneNode(true);
    var slide = node.querySelector('.reel-slide');
    slide.setAttribute('data-post-id', post.id);

    var mediaEl = node.querySelector('.reel-media');
    var dotsEl = node.querySelector('.reel-dots');

    buildMedia(post, slide, mediaEl, dotsEl);

    // author row
    var avatar = slide.querySelector('.reel-avatar');
    var author = post.author_name || 'Church';
    avatar.textContent = author.charAt(0).toUpperCase();
    slide.querySelector('.reel-username').textContent = '@' + (post.author_username || author.toLowerCase().replace(/\s+/g, '.'));
    slide.querySelector('.reel-author-name').textContent = author;

    // tappable church (parish) link → unit page
    var unit = post.unit || [];
    var churchEl = node.querySelector('.reel-church');
    if (churchEl && unit.length) {
      var parish = unit[unit.length - 1];
      var a = document.createElement('a');
      a.className = 'reel-church-link';
      a.href = '/unit/' + encodeURIComponent(parish.slug || '');
      a.textContent = '📍 ' + (parish.name || '');
      a.title = post.unit_label || parish.name;
      churchEl.appendChild(a);
    } else if (churchEl) {
      churchEl.style.display = 'none';
    }

    // caption + more toggle
    var capText = node.querySelector('.reel-text');
    capText.textContent = post.caption || '';
    var caption = node.querySelector('.reel-caption');
    if ((post.caption || '').length > 110) {
      caption.classList.add('has-more');
      caption.classList.remove('open');
      node.querySelector('.reel-more').textContent = 'more';
    }
    node.querySelector('.reel-more').addEventListener('click', function () {
      var open = caption.classList.toggle('open');
      node.querySelector('.reel-more').textContent = open ? 'less' : 'more';
    });

    // date / time + feed info
    var dateEl = node.querySelector('.reel-date');
    if (dateEl) { dateEl.textContent = formatPostedAt(post.created_at); }
    var pinEl = node.querySelector('.reel-pinned');
    if (pinEl) {
      if (post.is_pinned) {
        pinEl.textContent = '📌 Pinned';
        pinEl.hidden = false;
      } else {
        pinEl.hidden = true;
      }
    }
    var catsEl = node.querySelector('.reel-cats');
    if (catsEl) {
      var cats = post.categories || [];
      cats.forEach(function (c) {
        var chip = document.createElement('span');
        chip.className = 'reel-cat';
        chip.textContent = c.name || '';
        catsEl.appendChild(chip);
      });
      if (!cats.length) { catsEl.style.display = 'none'; }
    }

    // follow
    var followBtn = node.querySelector('.reel-follow');
    var uname = '@' + (post.author_username || '');
    var followed = false;
    try { followed = (localStorage.getItem('reel_following') || '').split(',').indexOf(uname) !== -1; } catch (e) {}
    if (followed) { followBtn.classList.add('following'); followBtn.textContent = 'Following'; }
    followBtn.addEventListener('click', function () {
      var list = [];
      try { list = (localStorage.getItem('reel_following') || '').split(',').filter(Boolean); } catch (e) {}
      var idx = list.indexOf(uname);
      if (idx !== -1) {
        list.splice(idx, 1);
        followBtn.classList.remove('following');
        followBtn.textContent = 'Follow';
      } else {
        list.push(uname);
        followBtn.classList.add('following');
        followBtn.textContent = 'Following';
      }
      try { localStorage.setItem('reel_following', list.join(',')); } catch (e) {}
    });

    // counts
    var likeCount = node.querySelector('.like-count');
    var commentCount = node.querySelector('.comment-count');
    likeCount.textContent = formatCount(post.likes_count || 0);
    commentCount.textContent = formatCount(post.comments_count || 0);
    commentCount.dataset.count = String(post.comments_count || 0);
    var likeBtn = node.querySelector('.reel-like');
    if (post.liked_by_viewer) { likeBtn.classList.add('liked'); }
    var saveBtn = node.querySelector('.reel-save');
    if (post.saved_by_viewer) { saveBtn.classList.add('saved'); }

    likeBtn.addEventListener('click', function () { doLike(post, slide); });
    saveBtn.addEventListener('click', function () { doSave(post, slide); });
    node.querySelector('.reel-comment').addEventListener('click', function () { openComments(post, slide); });
    node.querySelector('.reel-share').addEventListener('click', function () { share(post); });
    node.querySelector('.reel-more-actions').addEventListener('click', function () { share(post); });

    // Always-visible sound toggle so users know how to get audio on a reel.
    var muteBtn = node.querySelector('.reel-mute-btn');
    if (muteBtn) {
      muteBtn.textContent = muted ? '🔇' : '🔊';
      muteBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        toggleMute(slide);
      });
    }

    slideObserver.observe(slide);
    return node;
  }

  /* ---------- pagination + filters ---------- */
  function loadPage() {
    if (state.loading || !state.hasMore) { return; }
    state.loading = true;
    if (loadingEl) { loadingEl.style.display = 'flex'; }

    var url = endpoint + '?page=' + state.page + '&per_page=6';
    if (state.category) { url += '&category=' + encodeURIComponent(state.category); }
    if (state.view === 'saved') { url += '&saved=1'; }

    fetch(url)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (loadingEl) { loadingEl.remove(); loadingEl = null; }
        var oldSentinel = scroller.querySelector('.feed-sentinel');
        if (oldSentinel) { sentinelObserver.unobserve(oldSentinel); oldSentinel.remove(); }

        (data.data || []).forEach(function (post) {
          var key = String(post.id);
          if (state.seenPosts.has(key)) { return; }
          state.seenPosts.add(key);
          scroller.appendChild(buildSlide(post));
        });

        trackNewest(data.data || []);

        state.hasMore = !!data.has_more;
        state.page += 1;

        /* Guarantee the top slide is playing when the feed is first shown
           (fresh access, tab switch, or category switch). */
        if (!deepPostId && state.page === 2) {
          var top = scroller.querySelector('.reel-slide');
          if (top) {
            scroller.scrollTop = 0;
            setTimeout(function () { activateMedia(top, true); }, 60);
          }
        }

        if (state.hasMore) {
          var sentinel = document.createElement('div');
          sentinel.className = 'feed-sentinel';
          sentinel.style.height = '1px';
          scroller.appendChild(sentinel);
          sentinelObserver.observe(sentinel);
        } else if (scroller.children.length) {
          var end = document.createElement('div');
          end.className = 'feed-end';
          end.innerHTML = '<div>You\'re all caught up ✨</div>';
          scroller.appendChild(end);
        } else {
          var empty = document.createElement('div');
          empty.className = 'feed-end';
          empty.innerHTML = '<div>' + (state.view === 'saved' ? 'No saved reels yet. Tap the bookmark to save one.' : 'No posts in this category yet.') + '</div>';
          scroller.appendChild(empty);
        }
      })
      .finally(function () { state.loading = false; });
  }

  function resetAndLoad() {
    state.page = 1;
    state.hasMore = true;
    state.seenIds = new Set();
    state.seenPosts = new Set();
    newestPostId = 0;
    if (newPostsPill) { newPostsPill.hidden = true; }
    scroller.querySelectorAll('.reel-slide, .feed-sentinel, .feed-end').forEach(function (el) { el.remove(); });
    loadingEl = document.getElementById('feedLoading');
    if (!loadingEl) {
      loadingEl = document.createElement('div');
      loadingEl.id = 'feedLoading';
      loadingEl.className = 'feed-loading';
      loadingEl.textContent = 'Loading reels…';
      scroller.appendChild(loadingEl);
    }
    loadPage();
  }

  document.querySelectorAll('.reels-tabs .tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('.reels-tabs .tab').forEach(function (t) { t.classList.remove('active'); });
      tab.classList.add('active');
      state.view = tab.dataset.view === 'saved' ? 'saved' : 'all';
      resetAndLoad();
    });
  });

  document.querySelectorAll('.reels-chips .chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      document.querySelectorAll('.reels-chips .chip').forEach(function (c) { c.classList.remove('active'); });
      chip.classList.add('active');
      state.category = chip.getAttribute('data-category') || '';
      resetAndLoad();
    });
  });

  /* ---------- deep link: /feed?post=ID opens the exact post first ---------- */
  function loadDeepPost(id) {
    fetch('/api/post?id=' + encodeURIComponent(id))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (loadingEl) { loadingEl.remove(); loadingEl = null; }
        if (data.status === 'success' && data.data) {
          var post = data.data;
          state.seenPosts.add(String(post.id));
          var deepNode = buildSlide(post);
          scroller.appendChild(deepNode);
          var slide = deepNode.querySelector('.reel-slide');
          requestAnimationFrame(function () {
            scroller.scrollTop = slide ? slide.offsetTop : 0;
            setTimeout(function () { activateMedia(slide, true); }, 120);
          });
        }
        deepPostId = null;
        loadPage();
      })
      .catch(function () { deepPostId = null; loadPage(); });
  }

  /* ---------- live "new posts" indicator ---------- */
  var newPostsPill = document.getElementById('newPostsPill');
  var newestPostId = 0;
  var feedPollTimer = null;

  function trackNewest(posts) {
    (posts || []).forEach(function (p) {
      var pid = Number(p.id);
      if (pid > newestPostId) { newestPostId = pid; }
    });
  }

  function pollForNewPosts() {
    if (state.view === 'saved' || deepPostId || state.loading) { return; }
    if (!scroller.querySelector('.reel-slide')) { return; }
    var url = endpoint + '?page=1&per_page=1';
    if (state.category) { url += '&category=' + encodeURIComponent(state.category); }
    fetch(url)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var first = (data.data || [])[0];
        if (first && Number(first.id) > newestPostId && newPostsPill) {
          newPostsPill.hidden = false;
        }
      })
      .catch(function () {});
  }

  function startFeedPolling() {
    stopFeedPolling();
    feedPollTimer = setInterval(pollForNewPosts, 25000);
  }

  function stopFeedPolling() {
    if (feedPollTimer) { clearInterval(feedPollTimer); feedPollTimer = null; }
  }

  if (newPostsPill) {
    newPostsPill.addEventListener('click', function () {
      newPostsPill.hidden = true;
      newestPostId = 0;
      resetAndLoad();
    });
  }

  function start() {
    if (deepPostId) {
      loadDeepPost(deepPostId);
    } else {
      loadPage();
    }
    startFeedPolling();
  }

  start();
})();
