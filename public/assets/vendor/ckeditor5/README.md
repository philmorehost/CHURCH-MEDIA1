# CKEditor 5 — vendored

`ckeditor.js` is the unmodified official prebuilt **CKEditor 5 Classic editor build**.

| | |
|---|---|
| Version | **44.3.0** |
| Package | `@ckeditor/ckeditor5-build-classic` (scoped — see the warning below) |
| Source | `https://registry.npmjs.org/@ckeditor/ckeditor5-build-classic/-/ckeditor5-build-classic-44.3.0.tgz` → `package/build/ckeditor.js` |
| Licence | **GPL-2.0-or-later, or a commercial licence from CKSource** — see `LICENSE.md`, shipped beside it |

## Why it is vendored rather than loaded from a CDN

`bootstrap.php` sends `script-src 'self'` on the public site and `script-src 'self' 'unsafe-inline'`
in the admin. **A CDN `<script src="https://cdn.ckeditor.com/...">` is blocked by that policy in
production**, and adding a third-party host to `script-src` would mean loosening the CSP that was
deliberately tightened in Phase 1.1 — for an admin page that can publish content. Hosting the file
ourselves needs no policy change and removes the dependency on the CDN being reachable from the
church's network.

The build has **no separate stylesheet**: v44 injects its own CSS at runtime, which `style-src 'unsafe-inline'`
already permits.

## Updating

    curl -L -o ck5.tgz https://registry.npmjs.org/@ckeditor/ckeditor5-build-classic/-/ckeditor5-build-classic-<version>.tgz
    tar -xzf ck5.tgz
    cp package/build/ckeditor.js public/assets/vendor/ckeditor5/ckeditor.js
    cp package/LICENSE.md       public/assets/vendor/ckeditor5/LICENSE.md

Then update the version in this file and in `core/News.php` (which pins it in the script tag).

Do **not** commit `ckeditor.js.map` (7 MB) or `build/translations/` (every language, unused).

## Two things to decide (flagged in the roadmap, not decided here)

1. **The licence.** The GPL build means the GPL applies to the combined work if this software is
   *distributed* — and this repository is public. A commercial licence from CKSource, or an
   MIT-licensed editor, are the alternatives. That is a business decision, not a code one.
2. **`licenseKey: 'GPL'`** is passed in the config, and CKEditor may still attempt a licence check that
   `connect-src 'self'` blocks. It reports this in the console; the editor works. Unverified here because
   no browser has been used.
## Line endings

This repository has `core.autocrlf` on, so `git` rewrites these files to CRLF on checkout: the bytes on
disk are **not** bit-identical to the release tarball. That is harmless for a script, but it is why a
Subresource Integrity hash cannot be added for this file - the hash would not match after a fresh clone.
The editor is served from this site's own origin, so SRI buys nothing here anyway.