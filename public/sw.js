/*
 * Service worker for the public site.
 *
 * Three decisions, each of which is the difference between a useful offline experience and a support
 * call nobody can debug:
 *
 * 1. **Admin and API responses are never cached, and never served from cache.** A cached `/admin` page
 *    is one person's signed-in page, stored on the device, shown to the next person who opens the app.
 *    A cached API response is a POST answered with yesterday's body. The allow-list below is by path,
 *    and anything not on it is passed straight through to the network untouched.
 *
 * 2. **Navigations are network-first, falling back to a cached copy, then to the offline page.**
 *    Cache-first would show people a page from last week while the network was right there, which for a
 *    church site means a service time that has changed or an event that has been cancelled. The cached
 *    copy is the fallback, not the preference — and only for the exact URL that was visited before.
 *
 * 3. **Static assets are cache-first**, because their names carry a version when they change
 *    (`?v=` on the CSS and JS that the layout stamps from the file's mtime), so a new version is a new
 *    URL and never has to be revalidated.
 *
 * The cache name carries a version. Changing anything in here means changing it too, which is what
 * makes the old cache get deleted on activate rather than being kept alongside the new one for ever.
 */

var CACHE = 'church-site-v2';

/* Fetched at install so the offline page is there the first time it is needed, rather than only after
   the visitor has happened to visit it. */
var PRECACHE = ['/offline', '/assets/css/site.css', '/assets/logo.png', '/assets/app_icon.png'];

/* Path prefixes this worker will handle at all. Everything else is the network's business. */
function isHandled(url) {
  if (url.origin !== self.location.origin) {
    return false;
  }

  /*
   * Never touched, and the list is longer than it first looks like it needs to be. `/admin` and
   * `/member` are signed-in areas: a cached page there is one person's page, stored on the device, shown
   * to whoever opens the app next. `/ad-manager` carries a publisher's token in the URL, so caching it
   * would leave that token on disk. `/api` and `/installer` change state.
   */
  var never = ['/admin', '/api', '/installer', '/member', '/ad-manager'];
  for (var i = 0; i < never.length; i++) {
    if (url.pathname === never[i] || url.pathname.indexOf(never[i] + '/') === 0) {
      return false;
    }
  }
  return true;
}

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE).then(function (cache) {
      /* Individually, so one missing file cannot fail the whole install and leave the app with no
         service worker at all. */
      return Promise.all(PRECACHE.map(function (path) {
        return cache.add(path).catch(function () { /* not fatal */ });
      }));
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (names) {
      return Promise.all(names.map(function (name) {
        if (name !== CACHE) {
          return caches.delete(name);
        }
        return null;
      }));
    }).then(function () {
      return self.clients.claim();
    })
  );
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  var url = new URL(request.url);

  if (request.method !== 'GET' || !isHandled(url)) {
    return;
  }

  /* A navigation: the network is the truth, the cache is the fallback. */
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).then(function (response) {
        if (response && response.ok) {
          var copy = response.clone();
          caches.open(CACHE).then(function (cache) { cache.put(request, copy); });
        }
        return response;
      }).catch(function () {
        return caches.match(request).then(function (cached) {
          return cached || caches.match('/offline');
        });
      })
    );
    return;
  }

  /* A static asset: cache-first, and only ever store a response that is actually OK. Storing a 404 or
     a 500 here would make the failure permanent until the cache version changed. */
  if (url.pathname.indexOf('/assets/') === 0) {
    event.respondWith(
      caches.match(request).then(function (cached) {
        if (cached) {
          return cached;
        }
        return fetch(request).then(function (response) {
          if (response && response.ok && response.type === 'basic') {
            var copy = response.clone();
            caches.open(CACHE).then(function (cache) { cache.put(request, copy); });
            return response;
          }
          return response;
        }).catch(function () {
          /*
           * Offline, and no exact match. This is the case that decides whether the offline page is
           * readable or a wall of unstyled text: the layout requests `/assets/css/site.css?v=1234`
           * (asset() stamps the file's version), while the precache above stores the bare
           * `/assets/css/site.css`, so without this the stylesheet of the one page that exists to be
           * shown with no connection is the one thing that cannot be found.
           *
           * Only ever reached when the network has already failed, so it cannot serve a stale asset
           * while a fresh one is available. */
          return caches.match(request, { ignoreSearch: true });
        });
      })
    );
  }
});
