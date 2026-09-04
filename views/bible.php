<?php
/**
 * Bible Page View — YouVersion-style reader.
 * The full book list is rendered server-side. Results are cached in the
 * browser (sessionStorage) and on the server, so every read feels instant.
 */
$bibleBooks = [
    'Genesis', 'Exodus', 'Leviticus', 'Numbers', 'Deuteronomy',
    'Joshua', 'Judges', 'Ruth', '1 Samuel', '2 Samuel',
    '1 Kings', '2 Kings', '1 Chronicles', '2 Chronicles',
    'Ezra', 'Nehemiah', 'Esther', 'Job', 'Psalms', 'Proverbs',
    'Ecclesiastes', 'Song of Solomon', 'Isaiah', 'Jeremiah', 'Lamentations',
    'Ezekiel', 'Daniel', 'Hosea', 'Joel', 'Amos', 'Obadiah', 'Jonah',
    'Micah', 'Nahum', 'Habakkuk', 'Zephaniah', 'Haggai', 'Zechariah', 'Malachi',
    'Matthew', 'Mark', 'Luke', 'John', 'Acts', 'Romans',
    '1 Corinthians', '2 Corinthians', 'Galatians', 'Ephesians', 'Philippians',
    'Colossians', '1 Thessalonians', '2 Thessalonians', '1 Timothy', '2 Timothy',
    'Titus', 'Philemon', 'Hebrews', 'James', '1 Peter', '2 Peter',
    '1 John', '2 John', '3 John', 'Jude', 'Revelation',
];
?>

<!-- Hero -->
<section class="bible-hero">
  <div class="bible-hero-inner">
    <p class="eyebrow">The Word of God</p>
    <h1 class="bible-hero-title">Holy Bible</h1>
  </div>
</section>

<div class="container bible-body">
  <div class="bible-wrap">

    <!-- Search panel (hidden after a successful search; reopen via locate icon) -->
    <form id="bible-search" class="bible-panel" autocomplete="off">
      <div class="bible-panel-head">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 6.5C10 4.5 6.5 4 3 4v14c3.5 0 7 .5 9 2.5 2-2 5.5-2.5 9-2.5V4c-3.5 0-7 .5-9 2.5Z"/>
          <path d="M12 6.5v14"/>
        </svg>
        <h2>Find a Passage</h2>
      </div>

      <div class="bible-controls">
        <div class="form-field bible-c-version">
          <label for="bible-version">Version</label>
          <select id="bible-version">
            <option value="KJV">KJV</option>
            <option value="NIV">NIV</option>
            <option value="NLT">NLT</option>
            <option value="NKJV">NKJV</option>
          </select>
        </div>
        <div class="form-field bible-c-lang">
          <label for="bible-lang">Language</label>
          <select id="bible-lang">
            <option value="en">English</option>
            <option value="es">Español</option>
            <option value="fr">Français</option>
            <option value="yo">Yorùbá</option>
            <option value="ig">Igbo</option>
            <option value="ha">Hausa</option>
          </select>
        </div>
        <div class="form-field bible-c-book">
          <label for="bible-book">Book</label>
          <select id="bible-book">
            <?php foreach ($bibleBooks as $b): ?><option value="<?= e($b) ?>"><?= e($b) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-field bible-c-chapter">
          <label for="bible-chapter">Chapter</label>
          <input type="number" id="bible-chapter" value="1" min="1" inputmode="numeric">
        </div>
        <div class="form-field bible-c-verse">
          <label for="bible-verse">Verse</label>
          <input type="number" id="bible-verse" min="1" inputmode="numeric" placeholder="All">
        </div>
        <button type="submit" class="btn btn-gold bible-go">Read Passage</button>
      </div>
      <p class="form-note">Leave <strong>Verse</strong> empty to read the whole chapter from verse 1 — or enter a verse number to jump straight to it.</p>
    </form>

    <!-- Reading area -->
    <section class="bible-reader" id="bible-reader">
      <div class="bible-reader-head">
        <div class="bible-ref">
          <h2 id="bible-ref">Choose a passage</h2>
          <span class="bible-tag d-none" id="bible-tag"></span>
        </div>
        <div class="bible-reader-actions">
          <button id="btn-copy" type="button" class="btn btn-ghost btn-sm btn-icon d-none" title="Copy passage" aria-label="Copy passage">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="9" y="9" width="11" height="11" rx="2"/>
              <path d="M5 15V5a2 2 0 0 1 2-2h10"/>
            </svg>
          </button>
          <button id="btn-locate" type="button" class="btn btn-ghost btn-locate d-none" title="Open a new passage" aria-label="Open a new passage">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <circle cx="12" cy="12" r="3"/>
              <path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9L17 7M7 17l-2.1 2.1"/>
            </svg>
          </button>
        </div>
      </div>

      <div id="bible-content" class="bible-content">
        <p class="bible-empty">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 6.5C10 4.5 6.5 4 3 4v14c3.5 0 7 .5 9 2.5 2-2 5.5-2.5 9-2.5V4c-3.5 0-7 .5-9 2.5Z"/>
            <path d="M12 6.5v14"/>
          </svg>
          Select a book, chapter, and verse above to begin reading.
        </p>
      </div>

      <div class="bible-reader-nav d-none" id="bible-reader-nav">
        <button id="btn-prev" type="button" class="btn btn-ghost btn-nav">← Previous</button>
        <button id="btn-next" type="button" class="btn btn-gold btn-nav">Next →</button>
      </div>
    </section>

  </div>
</div>

<style>
  /* Hero */
  .bible-hero{position:relative; overflow:hidden; padding:56px 24px 44px; text-align:center;
    background:
      radial-gradient(ellipse 70% 55% at 18% -10%, #4a2f8a55, transparent 60%),
      radial-gradient(ellipse 60% 50% at 100% 0%, #8a3f6b33, transparent 60%),
      linear-gradient(180deg, var(--bg-1), var(--bg-0));}
  .bible-hero::before{content:""; position:absolute; inset:0; opacity:.5; pointer-events:none;
    background-image:radial-gradient(#ffffff22 1px, transparent 1px); background-size:34px 34px;
    mask-image:radial-gradient(ellipse 70% 60% at 50% 30%, black, transparent);}
  .bible-hero-inner{position:relative; z-index:2; max-width:720px; margin:0 auto;}
  .bible-hero .eyebrow{display:inline-block; margin-bottom:12px;}
  .bible-hero-title{font-size:clamp(34px,5.5vw,52px); margin:0;}

  /* Body + wrap */
  .bible-body{padding:32px 0 96px;}
  .bible-wrap{max-width:860px; margin:0 auto;}

  /* Search panel */
  .bible-panel{background:var(--panel-solid); border:1px solid var(--border); border-radius:var(--radius); padding:26px 26px 12px; margin-bottom:30px; box-shadow:0 30px 70px -40px #000000cc;}
  /* Keep native dropdown popups readable on the dark theme (white text on white
     background otherwise) by forcing a dark color-scheme + option colors. */
  .bible-controls input,.bible-controls select{color-scheme:dark;}
  .bible-controls select option{background:var(--panel-solid); color:var(--ink);}
  .bible-panel-head{display:flex; align-items:center; gap:10px; margin-bottom:18px;}
  .bible-panel-head svg{width:22px; height:22px; color:var(--gold-soft);}
  .bible-panel-head h2{margin:0; font-size:20px;}
  .bible-controls{display:grid; grid-template-columns:repeat(12,1fr); gap:14px; align-items:end; margin-bottom:4px;}
  .bible-controls .form-field{margin-bottom:0;}
  .bible-c-version{grid-column:span 2;}
  .bible-c-lang{grid-column:span 2;}
  .bible-c-book{grid-column:span 4;}
  .bible-c-chapter{grid-column:span 2;}
  .bible-c-verse{grid-column:span 2;}
  .bible-go{grid-column:1 / -1; margin-top:14px;}
  .bible-panel .form-note{margin-top:14px;}
  @media (max-width:860px){
    .bible-c-version,.bible-c-lang{grid-column:span 6;}
    .bible-c-book{grid-column:span 12;}
    .bible-c-chapter,.bible-c-verse{grid-column:span 6;}
  }
  @media (max-width:520px){
    .bible-c-version,.bible-c-lang,.bible-c-book,.bible-c-chapter,.bible-c-verse{grid-column:span 12;}
  }

  /* Reader */
  .bible-reader{background:var(--panel-solid); border:1px solid var(--border); border-radius:var(--radius); padding:30px 34px; box-shadow:0 30px 70px -45px #000000cc;}
  .bible-reader-head{display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-bottom:22px; padding-bottom:16px; border-bottom:1px solid var(--border-soft);}
  .bible-ref{display:flex; align-items:center; gap:12px; flex-wrap:wrap;}
  .bible-ref h2{margin:0; font-size:26px;}
  .bible-tag{font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--gold-soft); background:var(--gold-dim); border-radius:999px; padding:5px 12px; white-space:nowrap;}
  .bible-reader-actions{display:flex; align-items:center; gap:10px;}
  .btn-icon{width:40px; height:40px; padding:0; border-radius:50%; display:inline-flex; align-items:center; justify-content:center;}
  .btn-icon svg{width:18px; height:18px;}
  .btn-locate{width:42px; height:42px; padding:0; border-radius:50%; display:inline-flex; align-items:center; justify-content:center;}
  .btn-locate svg{width:20px; height:20px;}
  .bible-reader-actions .btn.copied{color:var(--success); border-color:var(--success);}
  .bible-content{min-height:320px; line-height:1.9; font-size:1.1rem;}
  .bible-verse{margin-bottom:.6rem;}
  .verse-num{font-weight:700; font-size:.78rem; color:var(--ink-faint); margin-right:.6rem; vertical-align:super;}
  .bible-empty{color:var(--ink-faint); text-align:center; padding:70px 20px; margin:0; display:flex; flex-direction:column; align-items:center; gap:14px;}
  .bible-empty svg{width:44px; height:44px; opacity:.5;}
  .bible-error{color:var(--danger); text-align:center; padding:50px 20px; margin:0;}
  .bible-loading{color:var(--ink-dim); text-align:center; padding:60px 0; margin:0;}
  .bible-spin{width:34px; height:34px; margin:0 auto 14px; border:3px solid var(--border); border-top-color:var(--gold); border-radius:50%; animation:bible-spin .8s linear infinite;}
  @keyframes bible-spin{to{transform:rotate(360deg);}}
  .bible-reader-nav{display:flex; flex-wrap:nowrap; gap:10px; margin-top:26px; padding-top:20px; border-top:1px solid var(--border-soft);}
  .btn-nav{flex:1 1 0; min-width:0; min-height:48px; white-space:nowrap; justify-content:center; padding:0 14px;}
  @media (max-width:520px){
    .bible-reader-nav{gap:8px;}
    .btn-nav{font-size:13.5px; padding:0 10px;}
  }
  @media (max-width:520px){
    .bible-hero{padding:40px 18px 32px;}
    .bible-reader{padding:22px 18px;}
    .bible-reader-head{flex-direction:column; align-items:flex-start;}
    .bible-content{font-size:1.05rem;}
  }
  .d-none{display:none !important;}
</style>

<script>
(function () {
  const searchEl = document.getElementById('bible-search');
  const locateBtn = document.getElementById('btn-locate');
  const copyBtn = document.getElementById('btn-copy');
  const readerNav = document.getElementById('bible-reader-nav');
  const contentEl = document.getElementById('bible-content');
  const refEl = document.getElementById('bible-ref');
  const tagEl = document.getElementById('bible-tag');
  const PREFIX = 'bible:';
  let lastData = null;
  let lastParams = null;

  function currentParams() {
    return {
      version: document.getElementById('bible-version').value,
      lang: document.getElementById('bible-lang').value,
      book: document.getElementById('bible-book').value,
      chapter: document.getElementById('bible-chapter').value,
      verse: document.getElementById('bible-verse').value,
    };
  }

  function keyOf(p) {
    return PREFIX + [p.version, p.lang, p.book, p.chapter, p.verse || ''].join('|');
  }

  function cacheGet(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function cacheSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }

  function openSearch() {
    searchEl.classList.remove('d-none');
    locateBtn.classList.add('d-none');
    searchEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const book = document.getElementById('bible-book');
    if (book) book.focus({ preventScroll: true });
  }

  function render(data, p) {
    if (data.error || !Array.isArray(data.verses)) {
      contentEl.innerHTML = data.error
        ? '<p class="bible-error">' + esc(data.error) + '</p>'
        : '<p class="bible-error">No content found for this selection.</p>';
      refEl.textContent = 'Choose a passage';
      tagEl.classList.add('d-none');
      readerNav.classList.add('d-none');
      copyBtn.classList.add('d-none');
      locateBtn.classList.add('d-none');
      return false;
    }

    const ref = data.reference || (p.book + ' ' + p.chapter + (p.verse ? ':' + p.verse : ''));
    refEl.textContent = ref;
    tagEl.textContent = data.translation || '';
    tagEl.classList.toggle('d-none', !data.translation);

    if (data.verses.length) {
      contentEl.innerHTML = data.verses.map(v =>
        '<div class="bible-verse"><span class="verse-num">' + esc(v.verse) + '</span>' + esc(v.text) + '</div>'
      ).join('');
    } else {
      contentEl.innerHTML = '<p class="bible-empty">No content found for this selection.</p>';
    }

    lastData = data;
    lastParams = p;
    readerNav.classList.remove('d-none');
    copyBtn.classList.remove('d-none');
    return true;
  }

  async function search(ev) {
    if (ev) ev.preventDefault();
    const p = currentParams();
    const key = keyOf(p);

    // Instant render from the in-session cache (no network at all).
    const cached = cacheGet(key);
    if (cached) {
      try { render(JSON.parse(cached), p); } catch (e) {}
    } else {
      contentEl.innerHTML = '<div class="bible-loading"><div class="bible-spin"></div>Loading scripture…</div>';
    }

    try {
      const res = await fetch('/api/bible.php?' + new URLSearchParams(p));
      const data = await res.json();
      cacheSet(key, JSON.stringify(data));
      const ok = render(data, p);
      if (ok) {
        searchEl.classList.add('d-none');
        locateBtn.classList.remove('d-none');
        const reader = document.getElementById('bible-reader');
        if (reader) reader.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    } catch (e) {
      if (!cacheGet(key)) {
        contentEl.innerHTML = '<p class="bible-error">An error occurred while fetching the Bible text. Please try again.</p>';
      }
    }
  }

  function navChapter(delta) {
    const ch = document.getElementById('bible-chapter');
    const cur = parseInt(ch.value, 10) || 1;
    ch.value = Math.max(1, cur + delta);
    search();
  }

  copyBtn.addEventListener('click', async () => {
    if (!lastData || !lastParams) return;
    const ref = lastData.reference || (lastParams.book + ' ' + lastParams.chapter);
    const version = lastData.translation || lastParams.version;
    const lines = lastData.verses.map(v => (v.verse + ' ' + v.text).trim()).join('\n');
    try {
      await navigator.clipboard.writeText(ref + ' (' + version + ')\n\n' + lines + '\n');
      copyBtn.classList.add('copied');
      setTimeout(() => copyBtn.classList.remove('copied'), 1600);
    } catch (e) {}
  });

  searchEl.addEventListener('submit', search);
  locateBtn.addEventListener('click', openSearch);
  document.getElementById('btn-prev').addEventListener('click', () => navChapter(-1));
  document.getElementById('btn-next').addEventListener('click', () => navChapter(1));
})();

function esc(str) {
  return String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
</script>

