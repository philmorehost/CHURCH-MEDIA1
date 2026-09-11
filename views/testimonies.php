<?php
declare(strict_types=1);

$s = settings();

$metaTitle = 'Testimonies & Praise Reports';
$metaDescription = 'Read inspiring testimonies of God\'s goodness and share your own praise report with our church community.';
$path = '/testimonies';

$pdo = Database::getInstance()->getConnection();

/*
 * Church units for the parish dropdown.
 * IMPORTANT: `org_units` has no `is_active` column (see the 2026_08_org_units
 * migration in core/Database.php) — filtering on it raises
 * "Unknown column 'is_active'" and crashed /admin/testimonies.
 */
$units = [];
try {
    $units = $pdo->query('SELECT id, name FROM org_units ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) {
    $units = [];
}

// Published testimonies, newest first.
$testimonies = [];
try {
    $testimonies = $pdo->query("SELECT t.*, u.name AS unit_name FROM testimonies t LEFT JOIN org_units u ON u.id = t.unit_id WHERE t.status = 'approved' ORDER BY COALESCE(t.approved_at, t.submitted_at) DESC, t.id DESC")->fetchAll();
} catch (Throwable $e) {
    $testimonies = [];
}

// Summary numbers used by the hero and the filter toolbar.
$totalCount = count($testimonies);
$photoCount = 0;
$parishNames = [];
foreach ($testimonies as $t) {
    if (!empty($t['media_url'])) {
        $photoCount++;
    }
    if (!empty($t['unit_name'])) {
        $parishNames[(string) $t['unit_name']] = true;
    }
}
$parishCount = count($parishNames);

$featured = $testimonies[0] ?? null;
$rest = array_slice($testimonies, 1);

/*
 * Renders one testimony card. $featured makes it the wide card at the top of
 * the grid. Cards carry data-parish / data-text so the toolbar can filter them
 * in the browser without another round trip.
 */
$renderTestimony = static function (array $t, bool $featured = false): void {
    $when = (string) ($t['approved_at'] ?: $t['submitted_at']);
    $text = trim((string) $t['content']);
    $long = mb_strlen($text) > 240;
    $unit = trim((string) ($t['unit_name'] ?? '')) ?: 'Our Church Family';
    $haystack = mb_strtolower(implode(' ', [(string) $t['title'], $text, (string) $t['name'], $unit]));
    ?>
    <article class="tst-card<?= $featured ? ' tst-featured' : '' ?>"
             data-parish="<?= e($unit) ?>"
             data-text="<?= e($haystack) ?>">
      <?php if (!empty($t['media_url'])): ?>
        <div class="tst-media">
          <img src="<?= e(uploadUrl($t['media_url'])) ?>" alt="<?= e($t['title']) ?>" loading="lazy">
        </div>
      <?php else: ?>
        <div class="tst-quote" aria-hidden="true">&rdquo;</div>
      <?php endif; ?>
      <div class="tst-body">
        <div class="tst-meta">
          <span class="tst-chip"><?= e($unit) ?></span>
          <span class="tst-date"><?= e($when !== '' ? date('M j, Y', strtotime($when)) : '') ?></span>
        </div>
        <h3><?= e($t['title']) ?></h3>
        <p class="tst-text<?= $long ? ' is-clamped' : '' ?>"><?= nl2br(e($text)) ?></p>
        <?php if ($long): ?>
          <button type="button" class="tst-more">Read more</button>
        <?php endif; ?>
        <div class="tst-author">
          <div class="tst-avatar"><?= e(mb_substr((string) $t['name'], 0, 1)) ?></div>
          <div>
            <strong><?= e($t['name']) ?></strong>
            <span>Verified testimony</span>
          </div>
        </div>
      </div>
    </article>
    <?php
};

/*
 * Do NOT require partials/layout-open.php or layout-close.php here.
 * render() (core/helpers.php) already buffers this view and wraps it in the
 * site layout, so including them in the view printed the header and the footer
 * a second time inside the page body.
 */
?>

<style>
  /* ===== /testimonies — scoped styles built on the site's design tokens ===== */
  .tst-hero{position:relative; overflow:hidden; text-align:center; padding:120px 24px 70px;
    background:radial-gradient(1100px 460px at 50% -12%, rgba(232,185,95,.16), transparent 62%), var(--bg-0);}
  .tst-mark{width:90px; height:90px; margin:0 auto 20px; border-radius:26px; display:flex; align-items:center; justify-content:center;
    font-size:40px; background:linear-gradient(135deg,var(--gold-soft),var(--gold)); box-shadow:0 18px 50px rgba(232,185,95,.32);}
  .tst-hero h1{font-size:clamp(34px,6vw,56px); margin:0 0 14px; color:var(--ink);}
  .tst-hero .eyebrow{display:inline-block; margin-bottom:12px;}
  .tst-hero-scripture{color:var(--ink-dim); font-size:16px; max-width:640px; margin:0 auto 30px; line-height:1.75; font-style:italic;}
  .tst-hero-scripture .tst-ref{font-style:normal; color:var(--gold-soft); white-space:nowrap;}
  .tst-hero-actions{display:flex; gap:14px; justify-content:center; flex-wrap:wrap;}
  .tst-hero-actions .btn{min-width:200px;}
  .tst-stats{display:flex; gap:12px; justify-content:center; flex-wrap:wrap; margin-top:40px;}
  .tst-stat{min-width:140px; padding:16px 24px; border:1px solid var(--border); border-radius:16px; background:#ffffff06;}
  .tst-stat b{display:block; color:var(--gold-soft); font-size:22px; line-height:1.2;}
  .tst-stat span{font-size:11px; letter-spacing:.07em; text-transform:uppercase; color:var(--ink-faint);}
  .tst-trust{margin:18px 0 0; font-size:12.5px; color:var(--ink-faint);}

  .tst-section{padding:76px 0;}
  @media (max-width:768px){.tst-section{padding:52px 0;}}
  .tst-section-head{max-width:640px; margin:0 auto 36px; text-align:center;}
  .tst-section-head h2{font-size:clamp(26px,3.6vw,38px); margin:0 0 8px; color:var(--ink);}
  .tst-section-head p{color:var(--ink-dim); margin:0; font-size:14.5px;}

  .tst-flash{margin:24px auto; padding:16px 20px; border-radius:14px; font-size:14px;}
  .tst-flash.ok{background:#5fe0a418; border:1px solid #5fe0a444; color:#b6f5d8;}
  .tst-flash.err{background:#ff6b6b18; border:1px solid #ff6b6b44; color:#ffb3b3;}

  .tst-toolbar{display:flex; flex-direction:column; gap:16px; align-items:center; margin:0 auto 18px; max-width:900px;}
  .tst-search{position:relative; width:100%; max-width:520px;}
  .tst-search input{width:100%; padding:13px 16px 13px 42px; border-radius:999px; border:1px solid var(--border);
    background:#ffffff08; color:var(--ink); font-size:14px; font-family:inherit;}
  .tst-search input:focus{outline:none; border-color:var(--gold);}
  .tst-search svg{position:absolute; left:16px; top:50%; transform:translateY(-50%); color:var(--ink-faint);}
  .tst-chips{display:flex; gap:10px; flex-wrap:wrap; justify-content:center;}
  .tst-chips .chip{background:none; font-family:inherit;}
  .tst-chips .chip:hover{border-color:#e8b95f55; color:var(--ink);}
  .tst-result-count{text-align:center; font-size:12.5px; color:var(--ink-faint); margin:0 0 26px;}

  .tst-grid{display:grid; grid-template-columns:repeat(3,1fr); gap:22px;}
  @media (max-width:960px){.tst-grid{grid-template-columns:repeat(2,1fr);}}
  @media (max-width:640px){.tst-grid{grid-template-columns:1fr;}}

  .tst-card{display:flex; flex-direction:column; overflow:hidden; border-radius:18px;
    background:linear-gradient(160deg,var(--panel-solid),#141224); border:1px solid var(--border);
    transition:transform .25s ease, border-color .25s ease, box-shadow .25s ease;}
  .tst-card:hover{transform:translateY(-4px); border-color:#e8b95f44; box-shadow:0 18px 44px rgba(0,0,0,.35);}
  .tst-card[hidden]{display:none;}

  .tst-media{height:210px; overflow:hidden; background:#0f0d1c;}
  .tst-media img{width:100%; height:100%; object-fit:cover;}
  .tst-quote{height:210px; display:flex; align-items:center; justify-content:center; font-family:var(--serif);
    font-size:64px; line-height:1; color:#e8b95f55;
    background:radial-gradient(420px 220px at 50% 0%, rgba(232,185,95,.14), transparent 70%), #141224;}

  .tst-body{display:flex; flex-direction:column; gap:12px; padding:24px; flex:1;}
  .tst-meta{display:flex; align-items:center; justify-content:space-between; gap:10px;}
  .tst-chip{padding:4px 11px; border-radius:999px; background:var(--gold-dim); color:var(--gold-soft);
    font-size:11px; font-weight:700;}
  .tst-date{font-size:11.5px; color:var(--ink-faint); white-space:nowrap;}
  .tst-card h3{margin:0; font-size:18px; line-height:1.35; color:var(--ink); font-family:var(--serif);}
  .tst-text{margin:0; flex:1; color:var(--ink-dim); font-size:14px; line-height:1.65; overflow-wrap:anywhere;}
  .tst-text.is-clamped{display:-webkit-box; -webkit-line-clamp:5; -webkit-box-orient:vertical; overflow:hidden;}
  .tst-more{align-self:flex-start; background:none; border:0; padding:0; cursor:pointer;
    font-family:inherit; font-size:12.5px; font-weight:700; color:var(--gold-soft);}
  .tst-more:hover{text-decoration:underline;}
  .tst-author{display:flex; align-items:center; gap:10px; border-top:1px solid var(--border-soft); padding-top:14px;}
  .tst-avatar{width:36px; height:36px; flex:0 0 36px; border-radius:50%; display:flex; align-items:center; justify-content:center;
    background:linear-gradient(135deg,var(--gold),#c98a3d); color:#1a1530; font-weight:700; font-size:15px;}
  .tst-author strong{display:block; font-size:13px; color:var(--ink);}
  .tst-author span{font-size:11px; color:var(--ink-faint);}

  .tst-featured{grid-column:1/-1; display:grid; grid-template-columns:minmax(0,1.02fr) minmax(0,1fr);}
  .tst-featured .tst-media, .tst-featured .tst-quote{height:100%; min-height:340px;}
  .tst-featured .tst-body{padding:34px;}
  .tst-featured h3{font-size:clamp(21px,2.4vw,27px);}
  .tst-featured .tst-text{font-size:15px;}
  .tst-featured .tst-text.is-clamped{-webkit-line-clamp:7;}
  @media (max-width:860px){
    .tst-featured{grid-template-columns:1fr;}
    .tst-featured .tst-media, .tst-featured .tst-quote{height:230px; min-height:0;}
  }

  .tst-emptybox{max-width:640px; margin:0 auto; padding:56px 28px; text-align:center;}
  .tst-emptybox[hidden]{display:none;}
  .tst-emptybox .ic{font-size:42px; margin-bottom:14px;}
  .tst-more-wrap{text-align:center; margin-top:36px;}
  .tst-more-wrap[hidden]{display:none;}

  .tst-steps{display:grid; grid-template-columns:repeat(3,1fr); gap:16px; max-width:900px; margin:0 auto 40px;}
  @media (max-width:760px){.tst-steps{grid-template-columns:1fr;}}
  .tst-step{display:flex; gap:14px; align-items:flex-start; padding:20px; border:1px solid var(--border); border-radius:16px; background:#ffffff06;}
  .tst-stepnum{flex:0 0 30px; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center;
    background:var(--gold); color:#1a1530; font-weight:800; font-size:13px;}
  .tst-step b{display:block; font-size:14px; color:var(--ink); margin-bottom:2px;}
  .tst-step p{margin:0; font-size:12.5px; color:var(--ink-dim); line-height:1.5;}

  .tst-formcard{max-width:820px; margin:0 auto; padding:38px 34px;}
  @media (max-width:640px){.tst-formcard{padding:26px 20px;}}
  .tst-legend{margin:0 0 18px; padding-bottom:10px; border-bottom:1px solid var(--border-soft);
    font-size:11.5px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; color:var(--gold-soft);}
  .tst-legend:not(:first-child){margin-top:30px;}
  .tst-req{color:var(--danger);}
  .tst-count{font-size:11.5px; color:var(--ink-faint); text-align:right; margin-top:6px;}
  .tst-preview{display:none; margin-top:14px;}
  .tst-preview img{max-width:220px; border-radius:12px; border:1px solid var(--border);}
</style>

<section class="tst-hero">
  <div class="tst-mark" aria-hidden="true">�</div>
  <span class="eyebrow">Praise Reports</span>
  <h1>Testimonies of Faith &amp; Victory</h1>
  <p class="tst-hero-scripture">“They overcame him by the blood of the Lamb and by the word of their testimony.” <span class="tst-ref">Revelation 12:11</span></p>
  <div class="tst-hero-actions">
    <a href="#share" class="btn btn-gold">Share Your Testimony</a>
    <a href="#testimonies" class="btn btn-ghost">Read Praise Reports</a>
  </div>
  <div class="tst-stats">
    <div class="tst-stat"><b><?= number_format($totalCount) ?></b><span>Praise Reports</span></div>
    <div class="tst-stat"><b><?= number_format($parishCount) ?></b><span>Parishes &amp; Units</span></div>
  </div>
  <p class="tst-trust">✓ Every submission is reviewed by our ministry team before it is published</p>
</section>

<?php if ($success = flash('testimony_success')): ?>
  <div class="container"><div class="tst-flash ok"><strong>Praise God!</strong> <?= e($success) ?></div></div>
<?php endif; ?>

<?php if ($error = flash('testimony_error')): ?>
  <div class="container"><div class="tst-flash err"><?= e($error) ?></div></div>
<?php endif; ?>

<section class="tst-section" id="testimonies">
  <div class="container">
    <div class="tst-section-head reveal">
      <span class="eyebrow">Recent Praise Reports</span>
      <h2>What God Has Done</h2>
      <p>Real stories from our church family — healing, provision, restoration and grace.</p>
    </div>

    <?php if (!$testimonies): ?>
      <div class="glass-card tst-emptybox">
        <div class="ic">🙌</div>
        <h3>No testimonies published yet</h3>
        <p style="color:var(--ink-dim); margin-bottom:22px;">Be the first to share what God has done for you — your story could strengthen someone's faith today.</p>
        <a href="#share" class="btn btn-gold">Submit A Testimony</a>
      </div>
    <?php else: ?>

      <?php if ($totalCount > 1): ?>
        <div class="tst-toolbar">
          <div class="tst-search">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" id="tstSearch" placeholder="Search a testimony, a name or a parish…" aria-label="Search testimonies">
          </div>
          <?php if ($parishCount > 1): ?>
            <div class="tst-chips" id="tstChips">
              <button type="button" class="chip active" data-parish="">All</button>
              <?php foreach (array_keys($parishNames) as $parishName): ?>
                <button type="button" class="chip" data-parish="<?= e($parishName) ?>"><?= e($parishName) ?></button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <p class="tst-result-count" id="tstCount"></p>
      <?php endif; ?>

      <div class="tst-grid" id="tstGrid">
        <?php $renderTestimony($featured, true); ?>
        <?php foreach ($rest as $t) { $renderTestimony($t); } ?>
      </div>

      <div class="glass-card tst-emptybox" id="tstNoResults" hidden>
        <div class="ic">🔍</div>
        <h3>No testimonies match your search</h3>
        <p style="color:var(--ink-dim); margin:0;">Try a different word, or clear the filters to see every report.</p>
      </div>

      <?php if ($totalCount > 9): ?>
        <div class="tst-more-wrap" id="tstMoreWrap" hidden>
          <button type="button" class="btn btn-outline" id="tstMore">Load more testimonies</button>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</section>

<section class="tst-section" id="share">
  <div class="container">
    <div class="tst-section-head reveal">
      <span class="eyebrow">Give Glory To God</span>
      <h2>Share Your Praise Report</h2>
      <p>Your story can encourage and strengthen someone else's faith. Every submission is reviewed by our team before it is published.</p>
    </div>

    <div class="tst-steps reveal">
      <div class="tst-step">
        <span class="tst-stepnum">1</span>
        <div><b>Write it down</b><p>Tell us what God did for you, in your own words.</p></div>
      </div>
      <div class="tst-step">
        <span class="tst-stepnum">2</span>
        <div><b>We review it</b><p>Our ministry team reads every submission first.</p></div>
      </div>
      <div class="tst-step">
        <span class="tst-stepnum">3</span>
        <div><b>It builds faith</b><p>Your report is published to glorify God and encourage others.</p></div>
      </div>
    </div>

    <div class="glass-card tst-formcard reveal">
      <form method="post" action="/testimonies" enctype="multipart/form-data">
        <?= Csrf::field() ?>

        <p class="tst-legend">1 · About you</p>

        <div class="grid grid-2">
          <div class="form-field">
            <label for="name">Your full name <span class="tst-req">*</span></label>
            <input type="text" id="name" name="name" required placeholder="e.g. Sister Mercy Okon">
          </div>
          <div class="form-field">
            <label for="email">Email address <small>(optional)</small></label>
            <input type="email" id="email" name="email" placeholder="you@example.com">
          </div>
        </div>

        <div class="grid grid-2">
          <div class="form-field">
            <label for="phone">Phone number <small>(optional)</small></label>
            <input type="text" id="phone" name="phone" placeholder="+234 800 000 0000">
          </div>
          <div class="form-field">
            <label for="unit_id">Parish / church unit <small>(optional)</small></label>
            <select id="unit_id" name="unit_id">
              <option value="">— Select parish/unit —</option>
              <?php foreach ($units as $u): ?>
                <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <p class="tst-legend">2 · Your testimony</p>

        <div class="form-field">
          <label for="title">Testimony title <span class="tst-req">*</span></label>
          <input type="text" id="title" name="title" required maxlength="255" placeholder="e.g. Divine Healing and Miraculous Job Breakthrough">
        </div>

        <div class="form-field">
          <label for="content">Your testimony / praise report <span class="tst-req">*</span></label>
          <textarea id="content" name="content" rows="8" required placeholder="Describe what God did for you in detail — when it happened, what changed, and how it built your faith…"></textarea>
          <div class="tst-count"><span id="tstChars">0</span> characters</div>
        </div>

        <p class="tst-legend">3 · Add a photo (optional)</p>

        <div class="form-field">
          <label for="media">Upload a photo or image proof <small>(max 8MB)</small></label>
          <input type="file" id="media" name="media" accept="image/*">
          <div class="tst-preview" id="tstPreview"><img id="tstPreviewImg" alt="Preview of the photo you selected"></div>
        </div>

        <button type="submit" class="btn btn-gold btn-block" style="margin-top:10px;">Submit Praise Report</button>
        <p class="form-note" style="text-align:center; margin:14px 0 0;">By submitting, you agree that our team may publish your testimony on this website and in the church app.</p>
      </form>
    </div>
  </div>
</section>

<script>
  (function () {
    'use strict';

    var grid = document.getElementById('tstGrid');
    if (!grid) { return; }

    var cards = Array.prototype.slice.call(grid.querySelectorAll('.tst-card'));
    var search = document.getElementById('tstSearch');
    var chips = Array.prototype.slice.call(document.querySelectorAll('#tstChips .chip'));
    var countEl = document.getElementById('tstCount');
    var noResults = document.getElementById('tstNoResults');
    var moreWrap = document.getElementById('tstMoreWrap');
    var moreBtn = document.getElementById('tstMore');
    var PAGE = 9;
    var shown = PAGE;
    var activeParish = '';

    function apply() {
      var q = search ? search.value.trim().toLowerCase() : '';
      var matches = 0;

      cards.forEach(function (card) {
        var text = card.getAttribute('data-text') || '';
        var parish = card.getAttribute('data-parish') || '';
        var hit = (!q || text.indexOf(q) !== -1) && (!activeParish || parish === activeParish);

        if (hit) { matches++; }
        card.hidden = !(hit && matches <= shown);
      });

      if (countEl) {
        countEl.textContent = matches === cards.length
          ? 'Showing all ' + matches + ' testimonies'
          : 'Showing ' + Math.min(matches, shown) + ' of ' + matches + ' testimonies';
      }
      if (noResults) { noResults.hidden = matches !== 0; }
      if (moreWrap) { moreWrap.hidden = matches <= shown; }
    }

    if (search) {
      search.addEventListener('input', function () { shown = PAGE; apply(); });
    }

    chips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        chips.forEach(function (c) { c.classList.remove('active'); });
        chip.classList.add('active');
        activeParish = chip.getAttribute('data-parish') || '';
        shown = PAGE;
        apply();
      });
    });

    if (moreBtn) {
      moreBtn.addEventListener('click', function () { shown += PAGE; apply(); });
    }

    // Expand / collapse a long testimony.
    grid.addEventListener('click', function (event) {
      var btn = event.target && event.target.closest ? event.target.closest('.tst-more') : null;
      if (!btn) { return; }
      var text = btn.parentNode.querySelector('.tst-text');
      if (!text) { return; }
      var clamped = text.classList.toggle('is-clamped');
      btn.textContent = clamped ? 'Read more' : 'Show less';
    });

    // Live character count + photo preview on the submission form.
    var content = document.getElementById('content');
    var chars = document.getElementById('tstChars');
    if (content && chars) {
      var syncCount = function () { chars.textContent = content.value.length.toLocaleString(); };
      content.addEventListener('input', syncCount);
      syncCount();
    }

    var media = document.getElementById('media');
    var preview = document.getElementById('tstPreview');
    var previewImg = document.getElementById('tstPreviewImg');
    if (media && preview && previewImg) {
      media.addEventListener('change', function () {
        var file = media.files && media.files[0];
        if (!file) { preview.style.display = 'none'; return; }
        previewImg.src = URL.createObjectURL(file);
        preview.style.display = 'block';
      });
    }

    apply();
  })();
</script>

