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

  /* Sidebar groups behave as an accordion: opening one closes every other, so the rail never
     grows into a long scroll and the open section is never ambiguous. Clicking the open group's
     own heading closes it, which is how you get back to a short rail.

     The group containing the current page is already open in the markup, so nothing here has to
     decide that on load - it only keeps aria-expanded honest and swaps the .is-open class. */
  var navGroups = Array.prototype.slice.call(document.querySelectorAll('[data-nav-group]'));

  navGroups.forEach(function (group) {
    var toggle = group.querySelector('[data-nav-toggle]');
    if (!toggle) { return; }

    toggle.addEventListener('click', function () {
      var willOpen = !group.classList.contains('is-open');

      navGroups.forEach(function (other) {
        var open = other === group && willOpen;
        other.classList.toggle('is-open', open);
        var button = other.querySelector('[data-nav-toggle]');
        if (button) { button.setAttribute('aria-expanded', open ? 'true' : 'false'); }
      });
    });
  });
})();
