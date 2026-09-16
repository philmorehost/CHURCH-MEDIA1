/* Anonymous analytics beacon.
 *
 * Fire-and-forget: it never blocks the page, never throws, and sends no
 * personal data. The server derives the visitor identity from its own rotating
 * device hash and stores no IP address.
 *
 * Pages opt in to richer events by putting data attributes on the element:
 *   <div data-analytics-view="sermon" data-analytics-id="42" data-analytics-unit="3">
 *   <video data-analytics-play="sermon" data-analytics-id="42">
 */
(function () {
  'use strict';

  var script = document.currentScript || document.querySelector('script[data-analytics-endpoint]');
  if (!script) { return; }

  var endpoint = script.getAttribute('data-analytics-endpoint') || '/api/analytics';
  var enabled = script.getAttribute('data-analytics-enabled') !== '0';
  if (!enabled) { return; }

  // Respect a browser-level "do not track" signal.
  if (navigator.doNotTrack === '1' || window.doNotTrack === '1') { return; }

  function send(payload) {
    try {
      var body = JSON.stringify(payload);
      if (navigator.sendBeacon && navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }))) {
        return;
      }
      fetch(endpoint, {
        method: 'POST',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: body
      }).catch(function () { /* analytics must never surface an error */ });
    } catch (e) { /* ignore */ }
  }

  function base(event, extra) {
    var payload = {
      event: event,
      path: location.pathname,
      referrer: document.referrer || '',
      device: 'web'
    };
    if (extra) {
      Object.keys(extra).forEach(function (key) {
        if (extra[key] !== null && extra[key] !== undefined && extra[key] !== '') {
          payload[key] = extra[key];
        }
      });
    }
    return payload;
  }

  /* ---- one page view per load ---- */
  send(base('page_view'));

  /* ---- content views: the first element carrying a view marker wins ---- */
  var viewMarker = document.querySelector('[data-analytics-view]');
  if (viewMarker) {
    var viewType = viewMarker.getAttribute('data-analytics-view');
    var eventName = viewType === 'post' ? 'post_view' : viewType + '_view';
    if (['post_view', 'sermon_view', 'event_view', 'testimony_view'].indexOf(eventName) !== -1) {
      send(base(eventName, {
        entity_type: viewType,
        entity_id: viewMarker.getAttribute('data-analytics-id'),
        org_unit_id: viewMarker.getAttribute('data-analytics-unit')
      }));
    }
  }

  /* ---- video plays, once per element ---- */
  Array.prototype.forEach.call(document.querySelectorAll('[data-analytics-play]'), function (el) {
    var fired = false;
    function onPlay() {
      if (fired) { return; }
      fired = true;
      send(base('video_play', {
        entity_type: el.getAttribute('data-analytics-play'),
        entity_id: el.getAttribute('data-analytics-id'),
        org_unit_id: el.getAttribute('data-analytics-unit')
      }));
    }
    el.addEventListener('play', onPlay, { once: true });
    el.addEventListener('playing', onPlay, { once: true });
  });

  /* ---- searches, debounced so typing does not send a dozen events ---- */
  var searchInput = document.querySelector('[data-analytics-search]');
  if (searchInput) {
    var timer = null;
    var lastSent = '';
    searchInput.addEventListener('input', function () {
      var term = (searchInput.value || '').trim();
      if (term.length < 3 || term === lastSent) { return; }
      clearTimeout(timer);
      timer = setTimeout(function () {
        lastSent = term;
        send(base('search', { meta: term.slice(0, 120) }));
      }, 1200);
    });
  }
})();
