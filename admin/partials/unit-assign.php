<?php
declare(strict_types=1);
/**
 * Compact "assign / move to a church" control used on admin list pages.
 * Expected variables:
 *   $assignAction       (string)  POST url, e.g. '/admin/prayer?action=reassign'
 *   $reassignId         (int)     the record id
 *   $assignableUnits    (array)   [['id','name','type'], ...]
 *   $reassignUnitId     (int|null) current unit id of the record
 *   $showUnassignedOnly (bool)    when true only render for unassigned records
 *
 * Unassigned records render an inline dropdown + "Assign" button. Assigned
 * records render a compact "⇄ Move" button that reveals the same dropdown
 * (pre-selected to the current church) + "Move" button.
 */
$assignableUnits = $assignableUnits ?? [];
$reassignUnitId = $reassignUnitId ?? null;
$showUnassignedOnly = $showUnassignedOnly ?? true;
$isUnassigned = empty($reassignUnitId);
?>
<?php if ((!$showUnassignedOnly || $isUnassigned) && $assignableUnits): ?>
  <span class="unit-assign-wrap">
    <?php if (!$isUnassigned): ?>
      <button type="button" class="btn secondary sm" data-unit-move-btn>⇄ Move</button>
    <?php endif; ?>
    <form method="post" action="<?= e($assignAction) ?>" class="unit-assign" <?= $isUnassigned ? '' : 'hidden' ?>>
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= (int) $reassignId ?>">
      <select name="org_unit_id" required>
        <option value=""><?= $isUnassigned ? 'Assign to…' : 'Move to…' ?></option>
        <?php foreach ($assignableUnits as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= $reassignUnitId === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name'] . ' (' . $u['type'] . ')') ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn secondary sm" type="submit"><?= $isUnassigned ? 'Assign' : 'Move' ?></button>
    </form>
  </span>
  <script>
    (function () {
      if (window.__unitAssignBound) { return; }
      window.__unitAssignBound = true;
      document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-unit-move-btn]') : null;
        if (!btn) { return; }
        var wrap = btn.closest('.unit-assign-wrap');
        if (!wrap) { return; }
        btn.hidden = true;
        var form = wrap.querySelector('.unit-assign');
        if (form) { form.hidden = false; }
      });
    })();
  </script>
<?php endif; ?>
