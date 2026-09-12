/* Small shared behaviours for the admin area. Loaded on every admin page. */
(function () {
  'use strict';

  /* "Select all" master checkbox for bulk-action tables (the comments queue).
     Delegated, so it keeps working as rows are filtered or re-rendered. */
  Array.prototype.forEach.call(document.querySelectorAll('[data-check-all]'), function (master) {
    var form = master.closest('form');
    if (!form) { return; }

    function boxes() {
      return Array.prototype.slice.call(form.querySelectorAll('input[type="checkbox"][name="ids[]"]'));
    }

    master.addEventListener('change', function () {
      boxes().forEach(function (box) { box.checked = master.checked; });
    });

    form.addEventListener('change', function (event) {
      var target = event.target;
      if (!target || target === master || target.type !== 'checkbox') { return; }
      var all = boxes();
      master.checked = all.length > 0 && all.every(function (box) { return box.checked; });
    });
  });
})();
