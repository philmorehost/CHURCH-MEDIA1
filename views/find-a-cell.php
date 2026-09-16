<?php
declare(strict_types=1);

/**
 * The public home cell finder.
 *
 * Filtering is by text and by branch of the hierarchy, and both live in the query string so a
 * filtered view can be shared — "these are the cells in Area 3" is a link somebody can send.
 *
 * Deliberately no geolocation. Sorting by distance needs coordinates for every meeting place, and
 * collecting a latitude and longitude for each cell is a burden most churches will not carry out —
 * a "nearest to me" that silently returns nothing useful is worse than a plain, reliable filter.
 * If the church later wants a map, coordinates are an additive change: two nullable columns and a
 * distance sort behind the same list.
 */

$leafLabel = Unit::labelFor(Unit::leafType());
$leafPlural = strtolower(Unit::pluralFor(Unit::leafType()));
$parentType = Unit::parentType(Unit::leafType());

$query = trim((string) ($_GET['q'] ?? ''));
$areaId = (int) ($_GET['area'] ?? 0);
$areaId = $areaId > 0 ? $areaId : null;

$areas = $parentType !== null ? Unit::byType($parentType) : [];
$areaLabels = Unit::labelsById();
$parentLabel = $parentType !== null ? Unit::labelFor($parentType) : '';

$cells = HomeCell::search($query, $areaId);
$totalListed = count(HomeCell::published());

$metaTitle = 'Find a Home Cell';
$metaDescription = $totalListed > 0
    ? 'Find a home cell near you — ' . $totalListed . ' midweek gatherings across our ' . $leafPlural
        . ', with meeting days, addresses and leaders.'
    : 'Home cells are the small midweek gatherings where our people meet, pray and grow together.';
?>
<link rel="stylesheet" href="<?= asset('css/units.css') ?>">
<link rel="stylesheet" href="<?= asset('css/cells.css') ?>">

<div class="units-page">
  <header class="units-hero">
    <p class="units-eyebrow"><?= e(setting('site_title')) ?></p>
    <h1>Find a Home Cell</h1>
    <p class="units-sub">
      A home cell is a small group that meets during the week to pray, study and look out for one
      another. Everyone is welcome at any of them — you do not have to be invited.
    </p>
  </header>

  <?php if ($totalListed === 0): ?>
    <p class="units-sub" style="text-align:center;padding:40px 0;">
      No home cells have been listed yet. In the meantime, please
      <a href="/contact">get in touch</a> and we will help you find your people.
    </p>
  <?php else: ?>
    <form class="cells-filter" method="get" action="/find-a-cell">
      <div class="cells-filter-field">
        <label for="q">Search</label>
        <input type="text" id="q" name="q" value="<?= e($query) ?>"
               placeholder="Cell leader, street, or <?= e(strtolower($leafLabel)) ?>">
      </div>

      <?php if ($areas): ?>
        <div class="cells-filter-field">
          <label for="area"><?= e($parentLabel) ?></label>
          <select id="area" name="area">
            <option value="">All <?= e(strtolower(Unit::pluralFor($parentType))) ?></option>
            <?php foreach ($areas as $area): ?>
              <option value="<?= (int) $area['id'] ?>" <?= $areaId === (int) $area['id'] ? 'selected' : '' ?>>
                <?= e($areaLabels[(int) $area['id']] ?? $area['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <div class="cells-filter-actions">
        <button class="btn" type="submit">Show cells</button>
        <?php if ($query !== '' || $areaId !== null): ?>
          <a class="btn secondary" href="/find-a-cell">Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <p class="cells-count">
      <?php if (!$cells): ?>
        No home cells match that. Try a different search, or
        <a href="/contact">ask us</a> — there is almost certainly one closer than the list suggests.
      <?php else: ?>
        <?= count($cells) ?> home cell<?= count($cells) === 1 ? '' : 's' ?>
        <?php if ($query !== ''): ?>matching “<?= e($query) ?>”<?php endif; ?>
        <?php if ($areaId !== null && isset($areaLabels[$areaId])): ?>
          in <?= e($areaLabels[$areaId]) ?>
        <?php endif; ?>
      <?php endif; ?>
    </p>

    <div class="cells-grid">
      <?php foreach ($cells as $cell): ?>
        <?php
        $address = trim((string) ($cell['meeting_address'] ?? ''));
        $leader = trim((string) ($cell['leader_name'] ?? ''));
        $phone = !empty($cell['leader_phone_public']) ? trim((string) ($cell['leader_phone'] ?? '')) : '';
        $capacity = HomeCell::capacityLabel($cell['capacity'] ?? null);
        ?>
        <article class="cell-card">
          <h2 class="cell-name"><?= e((string) $cell['name']) ?></h2>
          <p class="cell-path"><?= e((string) $cell['path_label']) ?></p>

          <p class="cell-when">
            <span class="cell-label">Meets</span>
            <?= e((string) $cell['meeting_day']) ?><?php if (trim((string) ($cell['meeting_time'] ?? '')) !== ''): ?>,
              <?= e((string) $cell['meeting_time']) ?><?php endif; ?>
          </p>

          <?php if ($address !== ''): ?>
            <p class="cell-where"><span class="cell-label">Where</span><?= e($address) ?></p>
          <?php endif; ?>

          <?php if ($leader !== ''): ?>
            <p class="cell-leader"><span class="cell-label">Leader</span><?= e($leader) ?></p>
          <?php endif; ?>

          <?php if ($phone !== ''): ?>
            <p class="cell-contact">
              <a href="tel:<?= e($phone) ?>"><?= e(Phone::display($phone)) ?></a>
              <a class="cell-wa" href="https://wa.me/<?= e($phone) ?>" target="_blank" rel="noopener">WhatsApp</a>
            </p>
          <?php endif; ?>

          <?php if ($capacity !== ''): ?>
            <p class="cell-capacity"><?= e($capacity) ?></p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>

    <p class="cells-foot">
      Cannot see one that suits you? <a href="/contact">Message us</a> and we will connect you with the
      nearest cell — including one that may not be listed publicly.
    </p>
  <?php endif; ?>
</div>
