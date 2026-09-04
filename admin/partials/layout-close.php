    </div>
  </div>
</div>
<script>
(function () {
  var toggle = document.querySelector('[data-admin-toggle]');
  var sidebar = document.querySelector('[data-admin-sidebar]');
  var overlay = document.querySelector('[data-admin-overlay]');
  if (!toggle || !sidebar) { return; }
  function close() {
    sidebar.classList.remove('open');
    if (overlay) { overlay.classList.remove('show'); }
    toggle.textContent = '☰';
  }
  toggle.addEventListener('click', function () {
    var open = sidebar.classList.toggle('open');
    if (overlay) { overlay.classList.toggle('show', open); }
    toggle.textContent = open ? '✕' : '☰';
  });
  if (overlay) { overlay.addEventListener('click', close); }
  sidebar.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', close); });
})();
</script>
</body>
</html>
