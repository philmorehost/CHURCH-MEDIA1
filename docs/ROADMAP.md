# CHURCH-MEDIA1 — Feature Roadmap & Implementation Plan

> Scope: website (PHP 8.2, flat-file CMS) + Flutter app, across two churches.
> This document is the working plan. Tick items off as they ship.

---

## 0. How to read this plan

- Work is split into **phases**. Each phase is independently shippable and verifiable —
  no phase leaves the site in a half-finished state.
- **Effort** is measured in *build sessions* (one session = implement + verify + commit).
- **Cost** flags anything needing a paid account or per-use spend.
- Every phase ends with: `php -l` on touched files, a scratch-database test where the
  change is data-driven, `flutter analyze` clean if the app changed, and a commit + push.

---

## 1. Ground rules (apply to every phase)

### Conventions to follow
- New DB changes go into the `migrations()` array in `core/Database.php` keyed
  `YYYY_NN_description`, **and** into `installer/schema.sql` so fresh installs match.
- **Every table added from Phase 0 onward carries `tenant_id`** and every query against it
  is tenant-scoped. Anything genuinely global (e.g. the `unit_levels` seed defaults) stores
  `tenant_id = NULL` and is inherited by all tenants.
- Admin pages need **no route entry** — `/admin/foo` auto-loads `admin/foo.php`
  (segment must match `/^[a-z0-9_-]+$/`).
- Public views are rendered through `render()`; they must **never** require
  `views/partials/layout-open.php` / `layout-close.php` themselves.
- Admin pages set `$pageTitle` + `$activeNav` and require the admin layout partials.
  Add a nav entry to `admin/partials/layout-open.php` (`$navItems` or `$navItemsSystem`).
- Scope every list/action to the user's unit using the existing
  `Unit::scopeClause()` / `Unit::inScope()` / `Unit::inAssignableScope()` helpers, and
  label levels with `Unit::labelFor()` / `pluralFor()` — never hard-code "Parish".
- New service classes mirror `core/CpanelApi.php` (`configured()`, `ok/error` array
  returns, no exceptions escaping).
- New background work goes in `cli/` alongside `cli/media_worker.php`, driven by cron.
- Long-running/scheduled sends must be **resumable and idempotent** — assume the worker
  dies mid-batch.

### Security & privacy rules
- Secrets (SMS token, WhatsApp token, payment keys) are stored with
  `encryptSecret()` / read with `decryptSecret()`, are **masked** in every UI, and are
  never written to logs or exported.
- Every outbound message send is **audit-logged**: who, when, channel, target segment,
  recipient count, cost, and result.
- All send endpoints are CSRF-protected (`Csrf::valid()` / `Csrf::field()`) and
  rate-limited (`RateLimiter`).
- PII (phone numbers, emails) is treated as personal data: exports are logged, and the
  privacy policy text in `installer/schema.sql` is updated whenever a new data category
  is collected.
- Contacts carry an **opt-out flag that is honoured on every send path**.

---

## 2. Phase overview

| Phase | Theme | Ships | Effort | Cost | Status |
|---|---|---|---|---|---|
| **0** | **Tenancy foundation** — SaaS confirmed | `tenants` table, host/subdomain resolution in `bootstrap.php`, `tenant_id` on `settings` and **every new table from here on**, default-tenant backfill, per-tenant storage namespacing | 2–3 sessions | None | ✅ core shipped |
| **1** | Quick wins, no new vendors | Comments moderation, analytics dashboard, real RSVP + `.ics`, share cards, prayer wall depth, level-aware push targeting, backups & data export | 6–8 sessions | None | ✅ shipped (1.1–1.7) |
| **2** | **Messaging Hub — SMS** (explicit request) | Contacts address book, groups/segments, sender-ID management, compose + scheduling, templates, campaigns/history, wallet, worker, full guide | 8–10 sessions | Per-SMS (wallet) | ✅ shipped |
| **3** | Sermons: series + podcast | Sermon series, series pages, podcast RSS feed + Spotify/Apple submission | 3–4 sessions | None | ✅ shipped (3.1–3.6) |
| **4** | WhatsApp channel | Official Cloud API integration (templates, 24-h window, webhooks) | 5–7 sessions | Per-conversation | ✅ closed (4.1–4.3; 4.4 rejected) |
| **5** | Members & daily engagement | Member accounts, daily devotional, Bible reading plans + streaks, offline sermon downloads | 10–12 sessions | None | ✅ shipped (S1–S6) |
| **6** | Operations | Home cell finder, duty roster / service planning, newcomer follow-up automation, giving campaigns | 8–10 sessions | None | ✅ **complete** — 6a–6g shipped |
| **7** | Reach & platform | Multi-tenant onboarding, localisation, PWA, app widgets | 10–14 sessions | None | ⬜ **in progress** — 7a shipped (tenant-aware settings), 7b shipped (an account belongs to one church), 7c shipped (a unit belongs to one church), 7d-i shipped (worker tenancy plumbing), 7d-ii parts 1–2 and 4 shipped (the SMS worker, the sender-ID poller and the daily publisher report act as one church at a time), 7d-iii shipped (the SMS screens only touch their own church), 7d-iv shipped (the ads tables carry a church), 7d-v shipped (the public advert flow was driven over HTTP, clearing 7d-iv's verification debt), 7d-ii part 3 shipped (the devotional push and the reading reminder act as one church at a time), 7d-ii part 5 shipped (the follow-up and rota emails act as one church at a time), 7d-ii part 6 shipped (the WhatsApp broadcast acts as one church at a time), 7d-ii part 7 shipped (the roll-up and the backup were read, and neither needs a church) |

**Confirmed 2026-09-12:** multi-tenant **SaaS is a real goal**. That is why **Phase 0
(tenancy foundation) runs before everything else** — the SMS token, sender IDs, country
code, templates, groups, campaigns, analytics and member accounts are all *per-tenant*
from day one. Retrofitting tenancy after Phases 1–6 would mean rewriting every table and
screen they add.

---

## 3. Phase 0 & 1 — Tenancy foundation, then quick wins

### P0. Tenancy foundation (2–3 sessions)

Do this **before** any Phase 1/2 work so nothing has to be retrofitted later.

> **Status: core shipped.** `tenants` table (migration + schema), `core/Tenant.php`
> (resolution, host matching, onboarding, authorised session switching), tenant-aware
> `settings()`, bootstrap resolution, and a super-admin switcher in the admin sidebar.
> Verified with 25 assertions plus an upgrade simulation against a legacy database.
> Remaining for Phase 7: per-tenant upload/cache namespacing, branding UI, plan limits,
> self-service provisioning, and moving the older content tables onto `tenant_id`.

- **DB**: `tenants` (`id`, `name`, `slug` UNIQUE, `domain` NULL, `subdomain` NULL,
  `logo`, `primary_colour`, `is_active`, `plan`, `created_at`). `settings` gains `tenant_id`
  (NULL = global/default). Every table added from Phase 1 onward carries
  `tenant_id INT NOT NULL` with an index.
- **New**: `core/Tenant.php` — `current()`, `id()`, `resolveFromHost()`, `setCurrent()`,
  `all()`, `create()`, `forHost()`. Resolution order: super-admin switcher (session) →
  matched domain/subdomain → default tenant.
- **Modify**: `bootstrap.php` (resolve the tenant before anything else),
  `core/Database.php` (create + backfill the default tenant; attach `tenant_id` to
  `settings`), `core/helpers.php` (`setting()` reads the current tenant, falling back to
  global), `admin/settings.php` (tenant switcher for the super admin),
  `admin/partials/layout-open.php` (show which tenant you are in).
- **Media/storage**: namespace uploads and caches per tenant
  (`public/uploads/t{n}/…`, `storage/cache/t{n}/…`) so two churches can never collide.
- **Verify**: two tenants in a scratch database cannot see each other's settings, units,
  media or contacts; the default tenant keeps the existing site working unchanged;
  switching tenants in the admin changes what `setting()` returns.

### Phase 1 — Quick wins, no new vendors

### 1.1 Comments moderation queue
> **Status: shipped.** `post_comments` gained status/moderation/report columns and
> `comment_reports` + `comment_blocklist` tables; `core/CommentModeration.php` holds the
> rules; `admin/comments.php` is the queue (filters, search, flagged-first ordering, bulk
> approve/reject/spam/delete, word lists, mode switch); `api/comments.php` filters the public
> feed to approved comments, screens new ones and accepts reader reports. Moderation ships
> **off**, so nothing changed for the live site until it is switched on.
> Verified with 35 assertions plus an upgrade simulation. Still open: the reader-facing
> Report button in the feed UI, and a guide entry.

- **DB**: `post_comments` gains `status` ENUM('pending','approved','rejected','spam')
  DEFAULT `approved` (so existing comments are unaffected), `moderated_by`, `moderated_at`,
  `report_count`, `is_flagged`. New `comment_reports` table (comment_id, ip, reason, created_at).
- **New**: `admin/comments.php` — queue with filters (pending / flagged / spam / all),
  bulk approve/reject/delete, reason field, per-church scoping.
- **Modify**: `api/comments.php` (only return `approved`; expose a report endpoint),
  `public/assets/js/feed.js` (report button), `views/feed.php`.
- **Extras**: blocked-word list in settings, auto-flag on link-spam patterns, "first
  comment held for review" rule.
- **Verify**: scratch DB — post/approve/reject cycle, public API hides non-approved,
  scope isolation between two churches.

### 1.2 Analytics dashboard
> **Status: shipped.** `analytics_events` (raw, tenant-scoped, no IP addresses) and
> `analytics_daily` (nightly roll-up kept indefinitely); `core/Analytics.php`;
> `api/analytics.php` beacon + `public/assets/js/analytics.js`; server-side content views on
> the sermon/event routes and `api/post.php`; `admin/analytics.php` dashboard with a
> pure-SVG chart; `cli/analytics_rollup.php` for cron. 45 assertions passing.
> The Flutter app reports too, via `RCCGLP63/lib/services/analytics_beacon.dart`:
> `app_open` on launch, content views from the sermon and event screens, and search
> terms from the app's search. All of it sends `device=app`, which is what puts a hit
> in the app column instead of showing every visitor as web. The app deliberately never
> sends `page_view`, and `topPaths()` counts only `page_view`, so app activity cannot
> appear in "Most visited pages" — that panel stays a website measure.
- **DB**: `analytics_events` (`id`, `occurred_at`, `event` VARCHAR(60), `path`, `org_unit_id`,
  `post_id`, `sermon_id`, `event_id`, `device` ENUM('web','app'), `session_hash`,
  `referrer_host`, `country`, `meta` JSON) + indexes on `(occurred_at)`, `(event)`,
  `(org_unit_id)`. `analytics_daily` roll-up table (filled by the worker) so dashboards
  stay fast as data grows.
- **New**: `core/Analytics.php` (buffered, fire-and-forget `record()`; never blocks a request),
  `api/analytics.php` (POST beacon, rate-limited, no PII), `cli/analytics_rollup.php`
  (nightly aggregation + retention pruning), `admin/analytics.php`.
- **Modify**: `views/partials/layout-open.php` (beacon script), `api/post.php`
  (count views there instead of ad-hoc `post_views`). App screens report through
  `AnalyticsBeacon` (`device=app`).
- **Dashboard shows**: traffic + trends, top sermons/reels/testimonies, web vs app split,
  search terms, giving trend, newcomers trend, per-church comparison, date-range picker.
- **Verify**: scratch DB with synthetic events; assert roll-up maths and that the beacon
  adds < 5 ms.

### 1.3 Real RSVP + calendar
> **Status: shipped.** `events` gained `rsvp_mode` (legacy/off/external/internal),
> `max_capacity`, `allow_guests`, `waitlist_enabled` and `rsvp_closes_at`, plus the
> `event_rsvps` table; `core/Rsvp.php` holds the seat/waitlist/promotion logic;
> `api/rsvp.php` for the app and a server-side form POST on `/events/{slug}` for the web
> (works with JavaScript off); `api/calendar.php` serves the `.ics`;
> `admin/events.php` has capacity controls and a guest list with CSV export and door
> check-in. 71 assertions passing.
> `rsvp_mode` defaults to `legacy`, meaning “keep using rsvp_enabled/rsvp_url”, so every
> event that existed before behaves exactly as it did.
- **DB**: `event_rsvps` (`event_id`, `name`, `email`, `phone`, `guests`, `status`
  ENUM('going','maybe','declined','waitlist'), `token`, `created_at`) + `events.max_capacity`,
  `events.rsvp_mode` ENUM('off','external','internal'). Keep `rsvp_url` for `external`.
- **New**: `api/rsvp.php`, `views/event-detail.php` RSVP form, `.ics` download endpoint,
  "Add to Google Calendar" link, "Add to Calendar" in the app.
- **Modify**: `admin/events.php` (capacity, mode, attendee list, CSV export, check-in),
  `api/events.php` (return counts + `spots_left`), app `event_detail_screen.dart`.
- **Verify**: capacity edge cases (exactly full, over-booked, waitlist promotion),
  `.ics` validates, no double-RSVP from the same email.

### 1.4 Auto share cards
> **Status: shipped.** `core/ShareCard.php` renders a 1200×630 PNG with GD + TrueType —
> eyebrow tab, auto-shrinking 2–3 line title, one-line subtitle, and the site name and
> host in the footer. Cards are cached in `storage/cache/og/` under a hash of the visible
> text, so editing a title mints a new card and the superseded one is pruned; the whole
> directory is capped at 4000 files, oldest evicted first.
> `api/og.php` serves them with `ETag`/`304` and a `Cache-Control` day, and falls back to
> the row's cover image, then the logo, then a blank card. `views/partials/layout-open.php`
> prefers a view-supplied `$metaImage`, so `/sermons/{slug}` and `/events/{slug}` now show
> a real preview in WhatsApp, Facebook and X. 52 assertions passing, verified over HTTP.
> **Graceful degradation is the whole design**: no GD, no FreeType, no readable font, an
> unreadable cover or an unsupported format all step down to a simpler card or to the
> plain logo, and `generate()` returns `null` rather than ever throwing at a visitor.
> Only whitelisted types and published rows resolve, and remote cover URLs are refused, so
> the query string cannot be used to draw arbitrary text or to make the server fetch a
> third-party URL. Fonts are discovered from a list of common paths (overridable with the
> `og_font_path` setting); no font file is bundled in the repo.
> **Still open**: a Share action in the Flutter app, and cards for testimonies and reels
> (the generator already supports `testimony` and `post` — only the views need the wiring).
- **New**: `api/og.php?type=sermon|reel|testimony|event&id=` generating a 1200×630 PNG
  (GD `imagettftext`), cached in `storage/cache/og/`, with church branding.
- **Modify**: `views/partials/layout-open.php` (`og:image` from the generator),
  `views/sermon-detail.php`, `views/event-detail.php`, `views/testimonies.php`.
- **App**: a Share action that emits the same card URL.
- **Verify**: each type renders, cache hit/miss, fallback when GD fonts are missing.

### 1.5 Prayer wall depth
> **Status: shipped.** `prayer_requests` gained `tenant_id`, `increment_count`,
> `is_anonymous`, `is_featured`, `answered_at` and `answer_note`, plus the
> `prayer_participants` table. `core/PrayerWall.php` owns every rule about what a
> visitor may see; `views/prayer.php` is now three sections — the wall, a featured
> strip, and an **Answered Prayers** wall showing the team's answer note; and
> `admin/prayer.php` can mark a request answered with a note, reopen it, feature it,
> and correct a mistaken anonymity flag.
> **A prayer is counted once per visitor, ever.** The de-duplication is a unique key on
> `(request_id, session_hash)` with an `INSERT IGNORE`, not a read-then-write, so two
> clicks racing each other cannot double-count. The button renders already-pressed when
> the visitor has prayed before, and the count comes back from the server.
> **Privacy is the point of the class.** `is_anonymous` is the only flag the public
> shape trusts — a blank name also counts as anonymous — and `email`, `ip_address` and
> the raw `name` never leave `PrayerWall` on a public read, so the leak cannot be
> introduced by a careless edit to a view. The name is still stored and still shown to
> the pastoral team.
> A plain `POST /prayer` handles the form with JavaScript off, CSRF-protected and
> rate-limited; the page's remote form and the app both use `POST /api/prayer`.
> 75 assertions on a fresh install, 88 on an upgrade from a pre-1.5 database, plus an
> end-to-end HTTP pass over the API, the counter and the no-JS form.
>
> **Two bugs fixed along the way, both shipped and both serious:**
> 1. `RateLimiter::require()` did not exist, but three page routes called it — RSVP
>    (`/events/{slug}`), testimonies (`/testimonies`) and the new prayer form. Any
>    visitor without JavaScript submitting an RSVP, a testimony or a prayer request hit
>    a **fatal error** and lost what they had typed. The method now exists and renders a
>    themed `views/429.php` when a limit is tripped. Verified on all three real forms.
> 2. `recordPrayer()` was not tenant-scoped, so one church could bump another church's
>    counter by guessing a request id. Found by the multi-tenant assertions.
>
> **Also hardened:** a JSON body containing an array (`{"request_id":[15,14]}`) was
> silently coerced by PHP into the integer `1`, acting on an unrelated request. The API
> now rejects non-scalar input instead of coercing it.
- **DB**: `prayer_requests` gains `increment_count`, `is_anonymous`, `answered_at`,
  `answer_note`, `is_featured`. New `prayer_participants` (request_id, session_hash, created_at)
  so one person counts once.
- **Modify**: `api/prayer.php` (prayer counter, anonymous mode, answered flag),
  `views/prayer.php` (counter button, "Answered Prayers" wall, anonymous toggle),
  `admin/prayer.php` (mark answered, feature, moderate).
- **Verify**: counter de-dupes by session, anonymous requests hide the name publicly but
  not in admin, answered wall only shows approved entries.

### 1.6 Level-aware push targeting
> **Status: shipped.** `notifications` gained `target_unit_id` and `target_level`, and
> the picker now offers **every** level in the sender's scope — not just churches — so
> "everyone under Zone A" is a first-class choice. Each pick expands to its whole
> subtree, which is what makes a mid-level target mean what it says.
> `target_level` snapshots the unit's type at send time, because level names are
> configurable and may be renamed later; a notification still describes itself after a
> rename. `target_unit_id = NULL` means "everything in the sender's scope", which is how
> every notification sent before this landed is treated, so history reads correctly.
> A scoped admin **cannot** reach outside their subtree, even by posting a hand-crafted
> `unit_ids[]`: anything outside the scope is dropped, and picking the province above them
> resolves to just the part they govern. Verified with a Zone A admin picking the province,
> Zone B and a foreign church.
> Push goes to the churches plus the picked unit rather than to every intermediate level,
> since a device subscribes to the topic of the church it is browsing. Email now uses
> bound parameters instead of interpolated ids. The Sent table shows the target and how
> many units it reached. 50 assertions fresh, 55 on a pre-1.6 upgrade, plus a real
> logged-in send over HTTP.
- **DB**: `notifications` gains `target_level` VARCHAR(40) NULL, `target_unit_id` INT NULL.
- **Modify**: `admin/notifications.php` (pick **any** level from `Unit::levels()` — "everyone
  under Zone X"), `core/Pusher.php` (resolve audience via `Unit::subtreeIds()`).
- **Verify**: a notification aimed at a mid-level reaches every church beneath it, and a
  church admin cannot target outside their own subtree.

### 1.7 Backups & data export
> **Status: shipped.** `core/Backup.php` holds the whole thing, shared by the cron script
> `cli/backup.php` and the `admin/backup.php` screen, so a hand-taken backup is the same
> kind of artefact as a scheduled one.
> **`exec()` is usually disabled on shared hosting**, which makes `mysqldump` unusable —
> so the built-in PHP dumper is the *primary* path here and `mysqldump` is the
> optimisation. Both engines were verified end to end: each dump restores into a fresh
> empty database with **every one of 47 tables matching row-for-row**, and content with
> apostrophes, backslashes, newlines, emoji, `₦` and embedded semicolons round-trips
> byte-identically. If `mysqldump` returns anything that is not a dump — an error message,
> a permissions failure, empty output — the run falls back and says so rather than
> shipping a broken archive.
> Rows are paged by primary key so a large `analytics_events` table cannot exhaust
> memory, the archive is written to a temp name and renamed so an interrupted run can
> never leave a valid-looking half file, and a `media-*.txt` manifest records every
> uploaded file's path, size and timestamp — the SQL holds the rows, the manifest proves
> the files arrived.
> The screen shows which engine will run, whether gzip is available, how old the newest
> backup is (warns past a week), how much media is *not* in the archive, and the exact
> restore command. Downloads are re-authorised per request, are refused for anonymous
> visitors, and refuse any filename that is not one of our own — verified against path
> traversal, absolute paths and a traversal in the query string.
> 88 assertions fresh, 89 on a pre-1.7 upgrade, plus the CLI in every mode and a real
> logged-in run/download/delete/prune over HTTP with the download byte-for-byte identical
> to the file on disk.
>
> **Two bugs found by testing:** setting `MYSQL_PWD` to an *empty* string makes the client
> announce `using password: YES` and then fail against an account that genuinely has no
> password, so `mysqldump` never worked and every backup silently used the slow path; the
> password is now only set when there is one. And non-dump output was being accepted as a
> backup — a host with a broken `mysqldump` would have produced a file containing an error
> message. Both are fixed, and the suite now asserts *which* engine ran so a silent
> fallback can no longer pass as a success.
>
> **Not included:** uploaded media is listed, not copied. Moving gigabytes through PHP on
> every backup would time out and is better done with the host's own file tooling; the
> manifest is what makes that checkable. There is no in-app restore button — restoring is
> done from the command line or phpMyAdmin, because doing it from a web request while
> people are using the site is how you lose the data that arrived mid-import.
- **New**: `cli/backup.php` — `mysqldump` (or PHP-based dump fallback) + media manifest,
  written to `storage/backups/`, rotated (keep N days/weeks/months), optional off-site copy.
  `admin/backup.php` — list backups, download, run-now, restore instructions.
- **Modify**: `admin/settings.php` (schedule + retention settings), `admin/guide.php`.
- **Verify**: run a backup against the scratch DB, confirm the dump restores into an empty
  database and row counts match.

---

## 4. Phase 2 — Messaging Hub: Bulk SMS (explicit request)

Gateway: **PhilmoreSMS** — `https://app.philmoresms.com/api/`

| Endpoint | Method | Purpose |
|---|---|---|
| `balance.php` | POST `token` | Wallet balance |
| `sms.php` | POST `token`, `senderID`, `recipients`, `message` | Send bulk SMS |
| `senderID.php` | POST `token`, `senderID`, `message` | Register a sender ID |
| `check_senderID.php` | GET/POST | Sender ID status (`pending`/`approved`/`rejected`) |

Response contract: JSON with `error_code` — `000` success, `400` bad params,
`401` auth failure, `405` wrong method, `107` insufficient wallet balance,
`110` restricted words in the message.

Billing: 1 unit per 160 chars for the first segment, 1 unit per 153 chars after that
(recalculate for UCS-2 / non-GSM characters — 70 then 67 — so `₦` and emoji are estimated
correctly).

Recipient format: `2348012345678` — country code, **no** leading `0`, **no** `+`.

### 2.1 Core service — `core/Sms.php`
> **Status: shipped (Phase 2.0 — foundation).** `core/Sms.php` is complete: the six
> gateway calls, `normaliseMsisdn()` across eight countries, GSM-7 vs UCS-2 segment
> counting, `estimateUnits()`, `prepareRecipients()`, `senderIdProblem()`,
> `errorMessage()` and `errorMessage`/`isFatalCode()` classification. The whole schema
> is in place too — `sms_senders`, `sms_contacts`, `sms_groups`, `sms_group_members`,
> `sms_templates`, `sms_campaigns`, `sms_campaign_recipients`, `sms_messages_log`,
> `sms_wallet_log`, `sms_countries` — plus the phone columns on `users`,
> `newsletter_subscribers` and `device_tokens`, and every gateway setting.
> **304 assertions** (149 fresh, 155 upgrading a pre-Phase-2 database).
>
> **Cost correctness is the point.** A single `₦` or emoji drops the entire message from
> GSM-7 to UCS-2, which cuts a segment from 160 characters to 70 — so 160 characters of
> plain text costs 1 unit but the same length with one emoji costs 3. `segmentsFor()` is
> asserted on exactly that boundary, on the GSM-7 extended characters (`€` and friends)
> that occupy two septets each, and on emoji outside the basic multilingual plane, which
> take two UTF-16 units. Getting this wrong over- or under-charges every campaign.
> **Nothing sends until an admin connects a gateway**, and with no token every path fails
> cleanly with a reason rather than throwing or half-sending.
> **The token is encrypted at rest, masked in the UI, and allow-listed out of the log** —
> asserted by dumping every log row and checking the plaintext token and its ciphertext
> are both absent.
> **2026-09-12 corrections applied:** the sender-ID rule is ≤ 11 **alphanumeric** characters
> (letters and digits only), and numbers default to Nigeria (`234`).
> **Two bugs found by the tests:** PHP silently casts numeric string array keys to
> integers, so `array_keys()` on the country map returned `int 234` and `str_starts_with()`
> threw a `TypeError` on every number; and an explicitly international number (`+44…`)
> was rejected whenever it was not the default country, which would have stopped a church
> texting members abroad.
>
> **Remaining Phase 2 work** (each a coherent next step, in dependency order):
> - ~~`admin/sms.php` — the nine tabs (§2.6)~~ — **shipped.**
> - ~~Wiring the contact sources: a "sync from church data" pass~~ — **shipped** in
>   `SmsContacts::syncFromChurchData()`.
> - ~~A phone field on `admin/account.php` and `admin/users.php`~~ — **shipped**, with a
>   consent flag; see below.
> - ~~A `cli/sms_maintenance.php` for old log rows and stale claims~~ — **shipped**; see
>   below.
> - `admin/notifications.php` still resolves its audience inline; once `Notifier` has been in
>   use for a release, that screen can delegate its *delivery* to `Notifier::send()` too,
>   keeping Phase 1.6's tested audience logic and dropping the duplicated insert/push/email.
>   **Still open, and deliberately so** — no behavioural gain yet, only less duplication.
> - Live `balance.php` / `check_senderID.php` checks against the gateway, which need a
>   **rotated** token (the one pasted during planning was exposed in plaintext and must not
>   be reused). **Still open — blocked on the rotation, not on code.**
>
> **2026-09-12, Phase 2 close-out — two promises the software was not keeping.**
>
> **1. A phone number had nowhere to be entered.** `admin/account.php` and `admin/users.php`
> had no phone field at all, yet `syncFromChurchData()` reads `users.phone` for its "Church
> team" source and the composer's *send test to myself* button falls back to it. So a
> shipped, tested feature was inert: the team sync reported "0 found" and looked like
> missing data rather than a missing field.
>
> Adding the field raised a question the roadmap had not answered — a phone number on a
> staff profile is a *contact detail*, but permission to text it is a **different thing**,
> and the two have to be able to disagree. Two churches' teams overlap, people change
> numbers, and someone can be perfectly reachable by the church without consenting to bulk
> SMS. So `users.sms_consent` is a separate column (migration `2026_19_user_phone_consent`),
> the sync gates the team source on it exactly as it already gated newsletter subscribers,
> and **no source can opt anyone in merely by holding their number.** Both columns are also
> now in `installer/schema.sql`, so a fresh install gets them without depending on a later
> migration.
>
> Three smaller things fell out of doing this:
> - The **four near-identical UPDATE statements** in the user edit handler — one per
>   combination of the optional password and PIN fields — are now one statement assembled
>   from the parts that were actually filled in. Every new column previously had to be added
>   to all four, and a miss would have silently dropped a field on one path only.
> - Numbers are stored **normalised** (`2348031234567`) and displayed through
>   `Sms::prettyMsisdn()`, so one format is canonical and the user only ever sees the
>   readable one.
> - A rejected number is **shown back to the person** with the reason. Losing your typing on
>   a form that says only "invalid" is how people give up.
>
> **2. `sms_log_retention_days` was saved but never enforced.** The Settings tab offered
> "Keep gateway logs for 30 days" and stored the value; nothing anywhere read it. That is
> worse than not offering the control, because an admin sets it and stops worrying while the
> table grows without limit. `cli/sms_maintenance.php` is the part that keeps the promise:
> it prunes `sms_messages_log` and `sms_wallet_log` past the window, and releases recipient
> claims abandoned by a worker that died mid-batch.
>
> What it deliberately does **not** do: it never deletes a campaign or a recipient row. Those
> are the record of who was texted and what it cost, and "did we tell Ada about the March
> meeting?" is a question the church may need answered years later — the same reason
> `core/Backup.php` exists. Recipient rows go only when an admin deletes the campaign, which
> cascades. It also **reports** campaign tallies that disagree with their recipient rows
> rather than silently repairing them, because a wrong count is a symptom of something else;
> `--fix-counters` does the repair, and only on campaigns that have finished — never one
> still sending.
>
> Verified with **95 assertions** across two harnesses against a scratch database: 75 for the
> phone field and the maintenance script (including all four edit paths, the consent gate
> proving an unconsented number does *not* reach the address book, a dry run that changes
> nothing, a retention of `0` being refused rather than deleting everything, and a fresh
> claim being left alone while a stale one is released), plus 20 rendering every admin and
> SMS screen for regressions.

```
configured(): bool
balance(): array                       // ['ok','balance','raw','error']
send(array $msisdns, string $message, ?string $senderId = null): array
registerSenderId(string $id, string $sample): array
senderIdStatus(string $id): array
normaliseMsisdn(string $raw, string $country = '234'): ?string
segmentsFor(string $message): int       // GSM-7 vs UCS-2 aware
estimateUnits(array $msisdns, string $message): int
errorMessage(string $code): string      // friendly text for 000/400/401/405/107/110
```
- Normalises and de-dupes numbers, chunks large lists (configurable, default 100 per call),
  retries transient failures with backoff, and writes every attempt to the log.
- Never throws — returns `['ok' => bool, 'error' => string]` like `CpanelApi`.

### 2.2 Schema

Every table below carries `tenant_id INT NOT NULL` (Phase 0) unless noted.

- `sms_senders` — `id`, `tenant_id`, `sender_id` VARCHAR(11) UNIQUE per tenant,
  `status` ENUM('pending','approved','rejected'), `status_source` ENUM('gateway','manual')
  DEFAULT 'gateway', `sample_message`, `is_default`, `org_unit_id`, `submitted_by`,
  `last_checked_at`, `approved_at`, `rejection_note`, `created_at`.
- `sms_contacts` — `id`, `tenant_id`, `name`, `msisdn` VARCHAR(20) UNIQUE (normalised,
  no `+`), `country_code` VARCHAR(4) DEFAULT '234', `email`,
  `org_unit_id`, `source` ENUM('manual','newcomer','subscriber','team','testimony','registration','form','app','import'),
  `source_ref_id`, `tags` VARCHAR(255), `is_opted_out` TINYINT, `opt_out_at`, `notes`,
  `created_at`, `updated_at`.
- `sms_groups` — `id`, `name`, `slug`, `kind` ENUM('static','dynamic'), `rule` JSON NULL,
  `org_unit_id`, `created_by`, `created_at`.
- `sms_group_members` — `group_id`, `contact_id`, `added_at` (PK on the pair).
- `sms_templates` — `id`, `name`, `body`, `org_unit_id`, `created_at`.
- `sms_campaigns` — `id`, `title`, `message`, `sender_id`, `group_id` NULL, `status`
  ENUM('draft','scheduled','queued','sending','sent','partial','failed','cancelled'),
  `scheduled_at`, `started_at`, `finished_at`, `total_recipients`, `sent_count`,
  `failed_count`, `units_charged`, `created_by`, `org_unit_id`, `created_at`.
- `sms_campaign_recipients` — `campaign_id`, `contact_id`, `msisdn`, `status`
  ENUM('pending','sent','failed','skipped'), `error_code`, `error_note`, `sent_at`
  (unique on `campaign_id`+`msisdn` → idempotent retries).
- `sms_messages_log` — every raw gateway call: request summary (**no token**), response
  code, HTTP status, duration, `created_at`. Used by the diagnostics view.
- `sms_wallet_log` — periodic balance snapshots so you can see spend over time.

### 2.3 Sender ID lifecycle — self-service with auto-approval
> **Status: shipped (`cli/sms_sender_check.php`).** The poller checks `pending` rows and
> writes the result back, backing off by age: 15 minutes under 6 hours, 2 hours under a day,
> 6 hours under a week, then daily. A final status is never re-checked, so gateway calls
> settle to zero. A row whose `status_source` is `manual` is left alone, because the super
> admin set it deliberately — usually precisely because the gateway was unreachable.
> The status text is matched on the words rather than an exact string, since the gateway has
> answered "Approved", "approved." and "Sender ID approved" at different times. On approval
> the submitting church is told in-app, by email and by push, `media_team` included; if no
> sender ID is configured as default yet, the newly approved one becomes it, so a church can
> send without hunting through settings.
> **The Sender IDs tab is shipped too** — `admin/sms.php` carries a `sender-ids` tab (§2.6), so a
> church submits and tracks its own ID from the admin rather than from the CLI.
**Confirmed requirement:** church admins / editors / media team submit their own sender
ID; the system pushes it to the gateway, tracks the result, and flips it to approved
automatically — with a manual override for the super admin.

- **Submit** (`admin/sms.php` → Sender IDs): any user with role `admin`, `editor` or
  `media_team` may submit one. Validate **≤ 11 characters, alphanumeric only** (uppercased,
  no spaces or symbols), unique within the tenant. Store the sample message the gateway
  requires, then immediately `POST senderID.php`.
- **Track**: `cli/sms_sender_check.php` (cron, every 15 minutes) calls `check_senderID.php`
  for every non-final sender ID and writes back `status` + `last_checked_at`. Polling slows to
  hourly for IDs pending more than a day, and stops entirely once a status is final
  (`approved` / `rejected`).
- **Auto-approve**: when the gateway returns `approved`, the row flips to
  `status = 'approved'`, `status_source = 'gateway'`, `approved_at = NOW()`, and the
  **submitting church's media team is notified** — in-app always, plus email and (if enabled)
  push. Message: *"Your sender ID `XXXX` has been approved and is ready to use."*
- **Manual override**: the super admin can force a status from the Sender IDs screen (useful
  when the gateway is unreachable). This writes `status_source = 'manual'` and shows a caution
  badge, because the gateway still has the final say — a send from an ID the gateway has not
  approved comes back as `400`/`401` and is surfaced in Campaigns.
- **Guard rails**: a campaign cannot be queued with a sender ID that is not `approved`;
  rejected IDs display the gateway reason; each tenant has a configurable sender-ID cap.
- **Verify**: submit → row appears `pending` → a simulated `approved` gateway response flips
  the row and fires the notification → the ID becomes selectable in Compose; a manual
  override is recorded as `manual`; a non-approved ID is refused at queue time.

### 2.4 Multi-country numbers
Nigeria (`234`) is the default, but the script is sold to churches elsewhere, so numbers are
country-aware from the start.

- `sms_contacts.country_code`, defaulting from the per-tenant `sms_default_country`
  (itself default `234`), plus a `sms_countries` lookup seeded with an initial set
  (234, 233, 254, 27, 256, 255, 1, 44, …).
- `Sms::normaliseMsisdn($raw, $country)`: strips spaces, `+` and leading zeros, applies the
  country code when the number is local, and validates length per country — rejecting rather
  than silently mangling a number.
- Compose shows the country beside each recipient group and warns when a group spans multiple
  countries (different local-format rules).
- **Verify**: `0803…`, `+234803…`, `234803…` and `803…` all normalise to the same value for
  `234`; `+447…` normalises correctly under `44`; an invalid length is rejected with a clear
  per-row reason in the import dry-run.

### 2.5 Contact sources (wired to what already exists)

| Source | Table / field | Status |
|---|---|---|
| Newcomers | `newcomers.whatsapp_phone` | ✅ exists |
| Church team / admins | `users` — **needs `phone`** | ➕ add column |
| Newsletter subscribers | `newsletter_subscribers` — **needs `phone`** | ➕ add column |
| Testimonies | `testimonies.phone` | ✅ exists |
| Registrations | `pending_registrations.phone` | ✅ exists |
| Form submissions | phone-type fields in `form_submissions` payload | ✅ exists |
| App users | `device_tokens` — **needs `phone`** if we want SMS reach | ➕ add column |
| Manual / CSV | `sms_contacts` | ➕ new |
| Members (Phase 5) | `members` | 🔜 later |

Also add a **phone field to the user profile** (`admin/account.php` + `admin/users.php`)
so "send to church team" works, and make phone capture explicit + consented on the
newcomer and newsletter forms (opt-in checkbox wording added to the privacy policy).

### 2.6 Admin screens — `admin/sms.php` (tabbed, super-admin + scoped admins)

> **Status: shipped.** Nine tabs, each addressed by URL (`/admin/sms?tab=compose`) rather
> than by a JavaScript widget — the flat-file CMS has no tab component, and URL tabs let the
> guide link straight to the screen it describes, survive a reload, and work with JavaScript
> off. Each tab lives in `admin/partials/sms/<tab>.php` and owns its own POST handling, which
> must run before it echoes anything; the dispatcher captures it in an output buffer so a
> redirect after a successful action still works.
>
> Two things are narrower than the rest. Only the super admin may reach **Settings** or force
> a sender-ID status, and an asking-tab request from a church admin falls back to the
> dashboard rather than rendering. And every loader (`$loadGroup`, `$loadSender`,
> `$loadTemplate`, `$loadCampaign`) re-checks the unit scope rather than trusting the list
> filter — a church admin cannot open, or act on, another church's record by putting its id
> in the URL. A row with no unit is *shared* (head office), which is the same convention
> groups, senders and campaigns already use.
>
> Two deliberate design choices worth keeping:
> * **Compose is three steps, and the middle one is not optional.** "Check and continue"
>   resolves the audience for real, lists every excluded number *with its reason*, and works
>   the cost out by personalising the message for each recipient and counting the segments
>   that actually results in — a name with an accent can cost a second unit where everyone
>   else costs one. Then, and only then, "queue" re-resolves the audience before writing,
>   because someone may have opted out in between.
> * **Contacts export streams the current filter**, using the same direct
>   `fputcsv(fopen('php://output','w'))` pattern as `admin/newsletter.php`, so "export" means
>   "export this view" rather than "export everything".
>
> The CSV import parks the parsed file in the session under a token and shows a dry run
> first. `SmsContacts::parseCsv()` handles a BOM and `,` / `;` / tab delimiters;
> `importRows(..., dryRun: true)` reports `created` / `updated` / `invalid` / `duplicates`
> and the first 50 skipped rows with line numbers. A number already in the book is *updated*
> rather than duplicated — matching by number, so the same person typed two ways stays one
> contact. `syncFromChurchData()` pulls from users, newcomers, testimonies, event RSVPs,
> registrations, app devices and form phone fields, and takes newsletter subscribers **only
> where `sms_consent = 1`**.
>
> Verified with 124 assertions across two harnesses (16 tab renders + 108 write flows), all
> through real sessions with real CSRF tokens against a scratch database. The scoping block
> is the part to keep: it proves church B cannot see, export, open by id, delete by id, or
> overwrite the token of church A.
>
> Still outstanding, both noted above: a phone field on `admin/account.php` +
> `admin/users.php`, and optionally routing `admin/notifications.php`'s inline delivery
> through `Notifier::send()`.

1. **Dashboard** — live wallet balance (`balance.php`), units used this month, delivery
   success rate, cost estimate for the next campaign, recent campaigns, low-balance warning,
   gateway diagnostics (last error).
2. **Compose** — sender-ID picker (approved only), recipient picker
   (group / dynamic segment / unit subtree / manual paste / CSV upload), personalisation
   placeholders (`{name}`, `{church}`), **live segment + unit + cost counter**, opt-out
   footer preview, blocked-word pre-check, "send test to myself", schedule for later,
   and a final confirmation summary showing exactly how many contacts and units will be used.
3. **Contacts** — searchable address book: filter by source / unit / tag / opt-out status,
   add & edit single contact, bulk tag, bulk opt-out, CSV import (column mapping + dry-run
   preview + de-dupe report) and CSV export, and a one-click **Sync from church data**
   (newcomers, users, subscribers, testimonies, registrations).
4. **Groups & Segments** — static groups (hand-picked or pasted) and dynamic segments built
   with simple rule rows, e.g. *Newcomers not yet followed up*, *All team in Zone X*,
   *Subscribers who opted in*, *No attendance for 3 weeks*, *Birthday this month*.
5. **Sender IDs** — self-service submission (≤ 11 alphanumeric), status badge
   (`pending`/`approved`/`rejected`) with an auto-refresh indicator, "check status now"
   button, rejection reason, set default, per-church mapping, and a super-admin manual
   override.
6. **Templates** — reusable bodies with placeholders; insert into Compose in one click.
7. **Campaigns** — history with status, recipient/sent/failed counts, units charged, cost;
   drill-down to per-recipient status; **resend to failures only**; export; cancel a
   scheduled campaign.
8. **Settings** — **per tenant**: token (encrypted, masked, "test connection" button),
   default sender ID, sender display name, default country code, quiet hours (no sends
   outside e.g. 07:00–20:00), daily unit cap, sender-ID cap, per-church sending permission,
   opt-out footer text, log retention.
9. **Guide** — an on-page usage guide (numbered walkthrough: register sender ID → import
   contacts → build a group → compose → test → schedule → review results), plus a new
   section in `admin/guide.php` and a "what does this cost" worked example.

### 2.7 Worker — `cli/sms_worker.php`
> **Status: shipped.** `cli/sms_worker.php` is a thin wrapper around `core/SmsRunner.php`,
> and the queue mechanics live in `core/SmsCampaign.php`. One run promotes due campaigns,
> applies the quiet-hours and daily-cap gates, checks the wallet once, then claims and sends
> batches until the clock or the cap runs out. A lock file stops a slow run and the next cron
> tick overlapping — verified by holding the lock and confirming the second run declines.
> **Proving a retry never double-sends** was the point of the work, and three things do it:
> the `UNIQUE (campaign_id, msisdn)` key, a claim written *before* anything is sent, and a
> stale-claim timeout that retries an abandoned batch while a fresh claim is left alone.
> 330 assertions (164 fresh, 166 upgrading a pre-2.1 database) drive a scripted fake gateway
> through `Sms::setTransport()`, so the whole send loop runs offline.
>
> **Four bugs the tests found:**
> 1. `INSERT IGNORE` silently swallows a foreign-key violation, and the import counted that
>    as "already queued" — telling an admin their list was imported when nothing was saved.
>    A rejected row is now reported separately.
> 2. `pause()` left the campaign's counters a run behind, so the screen an admin reads to
>    decide what to do next showed stale numbers.
> 3. **`400` was treated as retryable.** It means "bad params" — a decision, not a blip — so
>    every such batch burned two sleeps and ~7 seconds before failing identically. Only a
>    transport failure (no gateway code at all) is retried now.
> 4. `refreshCounters()` overwrote the intended `queued` status with `sending`, so a campaign
>    that had not sent anything claimed to be mid-send. Claiming rows is now what marks a
>    campaign as started.
>
> **Two deliberate semantic choices**, both to stop the wrong button being pressed:
> a campaign where *nothing* went out reads `failed`; one where some did reads `partial`,
> because only `partial` is safe to offer as "resend to failures". And a shortfall pauses
> rather than fails — with the reason in `paused_reason` — so the recipients stay pending and
> a top-up resumes them.
>
> **On delivery receipts:** the gateway answers one code per API call, not per number, so
> "sent" means the gateway accepted it for delivery. It is not proof a handset received it,
> and no screen should say otherwise.
- Called by cron every minute. Claims a batch of `pending` recipients from `queued`
  campaigns, sends via `Sms::send()`, records per-recipient results, updates counters,
  and finishes the campaign when no rows remain.
- Checks the wallet first and **pauses** (status `partial`) with a clear admin alert when
  the balance is short — never half-charges silently.
- Respects quiet hours and the daily cap; resumable and idempotent.

### 2.8 Verification for Phase 2
- Unit-test `normaliseMsisdn()` (`0803…`, `+234803…`, `234803…`, `803…`), `segmentsFor()`
  for ASCII and `₦`/emoji, and `errorMessage()` for all six codes.
- Scratch-DB test: import → de-dupe → group → campaign → worker sends → counters correct →
  resend-failures-only produces no duplicate sends.
- `balance.php` and `check_senderID.php` against the live gateway with the **new** token.
- Confirm the token is unreadable in the DB, never rendered in full, and absent from logs.

---

## 5. Phase 3 — Sermon series & podcast

- **DB**: `sermon_series` (`id`, `title`, `slug`, `description`, `cover_image`, `org_unit_id`,
  `is_published`, `created_at`), `sermons.series_id` + `sermons.series_position`, and podcast
  fields on `sermons`: `audio_url`, `duration_seconds`, `episode_guid`, `is_explicit`.
- **New**: `admin/series.php`, `views/series.php`, `views/series-detail.php`,
  `api/series.php`, and `views/podcast.php` emitting a valid **RSS 2.0 + iTunes** feed with
  `<enclosure>` audio, `itunes:*` tags, cover art and GUIDs.
- **Modify**: `admin/sermons.php` (assign series + position, audio upload), `views/sermons.php`
  (series grouping + filter), `api/sermons.php`, app `sermons_screen.dart` +
  `sermon_detail_screen.dart` (series list, episode numbers, download).
- **Verify**: feed validates against the W3C RSS validator and Apple's podcast requirements;
  audio enclosure URL plays in a browser; series ordering is stable.

> **Status: shipped (3.1–3.4).** `sermon_series` plus the podcast columns on `sermons`
> (`series_id`, `series_position`, `audio_url`, `duration_seconds`, `episode_guid`,
> `is_explicit`) and `core/Series.php`, which is the single writer of the legacy `sermons.series`
> text column so the two can never disagree. Migration `2026_20_sermon_series` backfills existing
> free-text series into rows — one per (title, church), since two churches may legitimately use
> the same series name — and gives every sermon a stable `episode_guid`.
>
> `core/PodcastFeed.php` builds the RSS 2.0 + iTunes feed. `admin/series.php` manages series
> (delete keeps the sermons and says how many it will detach); `admin/sermons.php` files a sermon
> under a series with an episode number that auto-numbers when left blank, a duration that accepts
> `42 min` or `42:30`, an external audio link, and an explicit flag. Public `/series`,
> `/series/{slug}`, `/sermons?series=<slug>`, `/podcast` and `/podcast.xml`.
>
> **Verified:** 64 end-to-end assertions driving the real admin forms with a real session and
> CSRF, and 58 more on the feed — XML well-formedness through a real parser, enclosure byte
> lengths checked against the actual file size, RFC 822 dates, `&` and angle brackets surviving
> the round trip, the 304 revalidation path, empty and data-free rendering, and 404s. The feed was
> checked against Apple's documented requirements (JPEG artwork at 1400px+, `atom:link` self
> reference, `itunes:category`, honest `explicit`, per-item duration and episode number); it has
> **not** yet been submitted to Apple or Spotify, which is the real test. `audio/mpeg` enclosures
> for uploaded files are exercised; playback in a browser is not, because the harness audio is
> placeholder bytes rather than a real mp3.
>
> One real bug was caught by the suite rather than by review: renaming a series regenerated its
> slug, which would have broken every published link — search results, shared links and the feed.
> The slug is now created once and kept.
>
> **Status: shipped (3.5–3.6).** `api/series.php` lists published series and returns one series
> with its episodes already in episode order, going through `Series::all()` so tenant scoping
> stays in one place. An unknown or unpublished slug is a 404 rather than an empty list, which
> would look like a bug in the app.
>
> `api/sermons.php` now exposes `series_id`, `series_slug`, `series_position`, `duration_seconds`
> and `is_explicit` alongside the legacy `series` string, so the app gets series structure without
> breaking clients already in the field. `?series=` accepts a slug, and still accepts the old
> free-text name, so links that predate the series table keep working.
>
> **A real bug was fixed here, not just extended.** `audio_url` is both a column (the external
> link) and a response field (a playable URL). The old code did
> `$sermon['audio_url'] = uploadUrl($sermon['audio_path'])`, which overwrote the external link —
> so an episode published with only an external link reached the app with **no audio at all**.
> The column is now selected under a different name and the two are resolved deliberately, with
> an `audio_source` field saying which won. The website and the podcast feed were never affected.
>
> The app gained `series_screen.dart`, `series_detail_screen.dart`, a series filter bar on the
> sermons list, and episode number, duration and a download action on the sermon detail screen.
> `views/sermon-detail.php` gained the same: the episode number, the duration in words, a link
> back to the series, and a player that uses the external audio when present.
>
> **Verified:** 47 assertions over the API and the sermon page — publication and draft filtering,
> episode ordering, the `audio_url` resolution in all three cases (external only, upload only,
> both), 404s for unknown and unpublished series, and both the slug and legacy-text `?series=`
> filters. `flutter analyze` reports **no issues in any changed file and no error- or
> warning-severity diagnostics anywhere**; the 12 remaining lints are pre-existing `info`-level
> style notes in `bible_screen.dart`, `event_detail_screen.dart` and `home_screen.dart`.
>
> **Not verified:** the app was analysed but never run. The new screens compile and pass the
> analyzer, but no one has tapped through them on a device or emulator — layout and navigation
> behaviour on a real screen is still an open question. The feed has also still not been
> submitted to Apple or Spotify.

## 6. Phase 4 — WhatsApp channel

### Options

| Approach | Capability | Risk | Verdict |
|---|---|---|---|
| **Official — WhatsApp Cloud API** (Meta Graph) | Template messages approved in advance, free-form replies inside the 24-h customer service window, buttons/media/lists, delivery + read receipts via webhook | None contractual; needs Meta Business verification, a dedicated number, and per-conversation pricing | ✅ **Recommended backbone** |
| **WhatsApp Business app** (manual) | Broadcast lists (up to 256), quick replies, catalogue — human-driven | None; no automation | ✅ Fine as a stopgap, zero build |
| **Unofficial bridge** (whatsapp-web.js / Baileys / WPPConnect) | Free-form messages, no template approval, no per-message cost, group posting | **Violates WhatsApp's Terms of Service**; the number can be banned permanently at any time with no appeal; breaks whenever WhatsApp changes internals; you must keep a Node process + session store alive | ⚠️ Only ever on a **spare, disposable** number, never the ministry's main line, and never on a critical path |

### Decision — REVERSED: official channel only
The bridge was built, and then removed before it ever paired a number. The official Cloud API is
the whole of the WhatsApp channel.

- **Official Cloud API** = all messaging, member-facing and business-critical: welcome messages,
  event reminders, giving receipts, follow-ups. Compliant, auditable, deliverable.
- **Unofficial bridge — dropped.** It is the only way to read group participants or post into a
  group, which the Cloud API deliberately cannot do. But the ban is triggered by the *client*
  speaking to WhatsApp unofficially, not by message volume — keeping traffic low reduces the chance
  of a person *reporting* the number and does nothing about WhatsApp's own detection. The number at
  risk is the one the church communicates on, and losing it is worse than not having group tools.
- Removed in `2026_24_drop_wa_bridge`, which also drops the `wa_unofficial_enabled`,
  `wa_bridge_url` and `wa_bridge_token` settings and the stored bridge token. The `bridge/` service,
  the `Groups` admin tab and `core/WaBridge.php` are gone.
- If group tooling is ever wanted again, treat it as a **fresh decision** and re-read the risk
  column above. It is not unfinished work.

### Build outline (official first)
- **DB**: `wa_templates` (name, language, category, components JSON, status),
  `wa_conversations` (contact, last inbound/outbound, window expiry),
  `wa_messages` (direction, type, template, payload, `wa_message_id`, status, error),
  `wa_opt_ins`.
- **New**: `core/WhatsApp.php` (send template / send text / media, webhook verify +
  signature check), `api/wa-webhook.php` (inbound messages, delivery status, opt-in/out),
  `admin/whatsapp.php` (template manager, conversation inbox with the 24-h window indicator,
  broadcast composer reusing the Phase-2 contacts/groups, per-church number mapping),
  `cli/wa_worker.php`.
- **Reuse**: the whole contacts/groups/segments layer from Phase 2 — one audience, two channels.
- **Optional bridge (separate service)**: a small Node sidecar using a maintained unofficial
  library, bound to `127.0.0.1`, with its own token, session store, and a health check;
  PHP talks to it over HTTP. Behind `wa_unofficial_enabled` (default off).
### WhatsApp groups & number harvesting (confirmed enhancement)
Requested: *"pull phone numbers, church WhatsApp groups"*.
- **Bridge only** — the Cloud API cannot enumerate or post to groups.
- `admin/whatsapp.php` → Groups tab: list the groups the bridge has joined, show participant
  counts, and **import participants into `sms_contacts`** (source `whatsapp_group`) with a
  dry-run preview, de-dupe against existing contacts, and an explicit opt-in step — imported
  numbers start as `is_opted_out = 1` until they confirm, so a group scrape can never become
  unsolicited bulk SMS.
- Save a group as an `sms_groups` row so it can be a broadcast target on either channel.
- **Verify**: group import de-dupes against existing contacts; imported contacts default to
  opted-out and are excluded from campaigns until they opt in; a group post uses the bridge
  and records itself in the outbound audit log.

- **Verify**: webhook signature rejection test; template send in sandbox; inbound reply
  within the window does not require a template; 24-h expiry forces a template; bridge off
  by default.

> **Status: foundation shipped (4.1).** Migration `2026_21_whatsapp` creates `wa_templates`,
> `wa_conversations`, `wa_messages` and `wa_opt_ins`, plus the `wa_*` settings. `core/WhatsApp.php`
> covers the credential accessors with masking, the webhook handshake and signature check, the
> 24-hour window, and template / text / media sends through the Graph API.
> `api/wa-webhook.php` handles the handshake, verifies the signature, and files inbound messages,
> delivery statuses and opt-ins.
>
> **The channel is off by default** (`wa_enabled = 0`), as is the bridge
> (`wa_unofficial_enabled = 0`). Nothing can be sent from an install that has not been configured.
>
> Three decisions worth knowing about:
> - **`wa_messages.wa_message_id` is UNIQUE.** Meta retries a webhook delivery that does not get a
>   2xx. Without that key a retry would file the same inbound message twice and the conversation
>   would show it twice.
> - **The window expiry is stored, not derived.** `window_expires_at` is written as
>   `last_inbound_at + 24h`. Recomputing it from `last_inbound_at` on every read would mean a later
>   change to the rule retroactively re-opening conversations that had already closed.
> - **Inbound messages are `received`, not `delivered`.** They were delivered *to* us. Marking
>   them `delivered` made every delivery report wrong by counting our own inbox.
>
> **Verified:** 103 assertions. The signature suite is the point of most of them, and is written
> to distinguish *failing closed* from *being broken*: unsigned requests, a wrong digest, a digest
> without the `sha256=` prefix, a truncated digest, **a body altered after signing**, and a
> missing app secret are each refused — and the handshake refuses too when no verify token is
> configured rather than accepting anyone who guesses the URL. Also covered: Meta retry
> idempotency (the same delivery twice files one message), the window boundary at exactly 24
> hours (closed) and a minute either side, out-of-order delivery statuses not downgrading `read`,
> `FIELD()`-based status ranking, Meta error objects surfacing their own numeric code rather than
> the HTTP 400, button replies unwrapped into text, voice notes storing no empty body, and
> refusals never reaching the transport at all.
>
> **A real bug the suite caught:** the status rank used a 0-based array against MySQL's 1-based
> `FIELD()`, so every promotion by one step — including `sent` → `delivered` — was silently
> dropped with no error. Both sides now use `FIELD()`.
>
> **Not verified, and it cannot be from here:** nothing has been sent to a real Meta endpoint. The
> transport is a stub, so the Graph API payload *shapes* are asserted but their acceptance by Meta
> is not. The app has to be created, the business verified, a number registered, and the webhook
> URL subscribed before any of that is testable. The admin UI this note deferred to 4.2 is now
> built — see the 4.2–4.3 status below.

> **Status: shipped (4.2–4.3).** `admin/whatsapp.php` with five tabs — dashboard, inbox, broadcast,
> templates, settings — and a guide. `cli/wa_worker.php` works the broadcast queue.
>
> `wa_campaigns` and `wa_campaign_recipients` are **siblings of the SMS tables, not copies of them**,
> because four things genuinely differ and inheriting the SMS shape would carry rules that do not
> apply: there is no wallet (Meta bills per 24-hour conversation, not per message); consent is
> opt-**IN**; delivery is asynchronous and arrives by webhook; and rate limits are per-second with a
> low daily ceiling on a new number.
>
> **Consent is the centre of this.** Somebody who never opted in, or who opted out, is recorded as
> *skipped with the reason* rather than silently dropped — "I sent it to 400 people" and "it reached
> 120" must not look the same. A broadcast also cannot use an unapproved template; the composer
> refuses rather than letting Meta refuse it later.
>
> **Verified:** 151 assertions across the two suites (67 for broadcasts, 84 for the admin screens).
> The ones that carry the weight: nobody un-opted-in is queued and the reason is stored; queueing the
> same audience twice adds nobody; a second claim on the same batch takes nothing, so a message
> cannot go twice; an abandoned claim is released rather than stranding a recipient; delivery and
> read receipts from the webhook update the campaign and a late `delivered` does not downgrade a
> `read`; `reopenFailures` retries the transient failure and deliberately leaves the window-closed
> one alone; and a scoped admin cannot see, open, or cancel another church's broadcast — including by
> posting its id.
>
> **Two real bugs the suite caught, both in code I had just written:**
> - The campaign status update was nested inside the `wa_messages` row-count guard, so a campaign
>   recipient was never updated when the message row did not change. A broadcast report would have
>   shown every message as "sent" forever.
> - Leaving the worker loop early — cap reached, dry run, or a thrown send — released only the
>   current recipient, leaving the rest of the batch locked for ten minutes. The campaign would sit
>   looking stuck with nothing for the next cron run to do. A batch sweep now releases anything
>   claimed but unsent.
>
> **Not verified:** still nothing has been sent to or received from a real Meta endpoint. The worker's
> gates are tested against a real process (channel off, sending window, daily cap, `--dry-run`) and
> the send path is driven in-process with a stub, but Meta's acceptance of the payloads is untested.
>
> **Phase 4 is closed.** The optional Node bridge (4.4) was built and then removed, before it ever
> paired a number, at the project owner's direction: low volume is not the same as low risk, and a
> banned number is a banned church. Groups and number harvesting are therefore **out of scope
> permanently, not pending**. See "Decision — REVERSED" above.

## 7. Phase 5 — Members & daily engagement

- **Member accounts**: `members` table (name, email, phone, password_hash, `org_unit_id`,
  `is_verified`, `notification_prefs` JSON, `last_seen_at`); separate auth guard
  (`core/MemberAuth.php`) so member sessions never touch admin sessions; register/login/
  verify/reset flows; profile + notification preferences; giving history and receipts;
  saved posts and downloaded sermons; "my home cell"; then `members` becomes another
  `sms_contacts` / WhatsApp source.
- **Daily devotional**: `devotionals` (date, title, scripture reference, body, audio_url,
  `org_unit_id`), admin editor with a month view and "generate from a sermon" helper,
  public `/devotional` page, app home card, and a scheduled push at a set time.
- **Bible reading plans**: `reading_plans` + `reading_plan_days` + `member_plan_progress`
  (streak, last completed day), plan catalogue, day-by-day reader on top of the bundled
  offline KJV in `RCCGLP63/assets/bible/kjv.json`, streak counter and reminder notification.
- **Offline sermon downloads**: cache audio in app storage with a managed download list and
  size/wipe controls.
- **Verify**: reading progress survives app restart and works offline; devotional push
  respects quiet hours and preferences; member sessions cannot reach `/admin/*`.

> **Phase 5 is shipped (S1–S6).** Member accounts with their own auth guard, giving history, saved
> posts and home cell; the daily devotional with its push worker; Bible reading plans with streaks,
> the member flow and the evening reminder; and offline sermon downloads in both apps.
>
> **The switch panels are now honest.** Three notification preferences existed and controlled nothing
> before this phase: WhatsApp consent (S3), the devotional tick (S4b) and the reading-plan tick
> (S5b). Each is now wired to the worker it claims to govern. `Member::wantsNotification()` is the
> single shared check, so the devotional and the reading reminder cannot drift apart and start
> disagreeing about what a member switched off.
>
> **Not verified:** no Flutter build was run against a real device for the download feature. The
> download path was reasoned about and analysed, not exercised — it has never fetched an actual
> sermon file, so progress reporting, the `.part` rename and the size arithmetic are unproven against
> a real server. Running it once on a phone with a sermon is the first thing worth doing.
> `flutter analyze` is clean on both apps, which catches syntax and type errors and nothing else.
>
> **Also not verified this phase:** the reminder workers are tested at the decision layer — who is
> eligible and whether the claim is honoured — but nothing has been delivered through FCM to a real
> handset from these specific jobs.
>
> **Left for later, deliberately:** app login screens (the `device_tokens.member_id` binding they need
> was already done in S4b, so this is now UI alone). The `reading_plan`-style audit should be repeated
> for `notification_prefs` as more workers appear, because a preference that silently does nothing is
> harder to notice than a missing feature.

## 8. Phase 6 — Operations

- **Home cell finder**: leaf-level units gain `meeting_day`, `meeting_time`, `address`,
  `leader_name`, `leader_phone`, `capacity`; public "Find a cell near me" with a
  geolocation/dropdown fallback; app listing screen.
- **Duty roster / service planning**: `service_plans` + `service_roles` + `service_assignments`,
  per-service role slots (ushering, choir, media, children), invite + accept/decline,
  automatic SMS/WhatsApp reminder 24 h before (uses Phase 2/4), and a "who is serving
  this Sunday" view.
- **Newcomer follow-up automation**: `follow_up_sequences` + `follow_up_steps` (day offset,
  channel, template) and per-newcomer enrolment, driven by `cli/followup_worker.php`;
  pipeline dashboard with conversion (first visit → second visit → member) and stall alerts.
- **Giving campaigns**: `giving_campaigns` (goal, deadline, description, image) and
  `donations.campaign_id`; public progress bars on `/give`, per-campaign reporting and
  pledge tracking on top of the existing Payhub flow.
- **Verify**: each automation is idempotent; a member who opts out mid-sequence is removed
  immediately; campaign totals reconcile with `donations`.

> **6a shipped — home cell finder (website side).** The deepest level of whichever hierarchy the
> church has configured gains meeting day, time, address, leader name, leader phone, capacity, a
> phone-publication switch and a list/hide switch; `admin/home-cells.php` edits them, `/find-a-cell`
> publishes them, and `/api/cells` serves them.
>
> **The geolocation half of this bullet is not built, on purpose.** Sorting by distance needs
> coordinates for every meeting place. Asking each cell leader for a latitude and longitude is a
> burden most churches will not carry out, and a "nearest to me" that silently returns nothing is
> worse than a filter that always works. The filter is text plus branch of the hierarchy, both in the
> query string so a filtered view can be shared. Coordinates, if ever wanted, are two nullable
> columns behind the same list — additive, not a rework.
>
> **A leader's number is private by default**, and that is enforced in two places rather than one.
> The column exists so the church can ring the leader; publishing it is a separate per-cell choice,
> and `/api/cells` omits it on the same terms as the page — an API is a second public surface, and
> returning it there "for the app" would quietly undo the decision made on the web form.
>
> **Verified:** 42 assertions against a real database covering validation, day canonicalisation,
> phone normalisation, capacity bounds, the permission clearing itself when the number it belonged to
> is removed, and filtering by text, leader, address and ancestor branch. 25 assertions over HTTP on
> the finder, the API and the search filter, including that the number is absent from both surfaces
> when the box is unticked. 13 assertions driving a real admin login to confirm the list and the form
> render with no PHP notice. Both temp harnesses were removed afterwards and the database restored.
>
> **6b shipped — the app's cell list, in both apps.** A Home Cells entry in More, a search box, and
> a card per cell with the meeting day, address, leader and space. Tapping a card opens that church's
> media page, so somebody who has just found their cell can go straight on to listening to what it has
> posted. Tap-to-call and WhatsApp appear only when the church published the number.
>
> **The app searches rather than offering a dropdown.** The website can narrow by branch of the
> hierarchy because it has the width for a select; a picker listing hundreds of units is unusable on a
> phone, which is the same reasoning already recorded in `core/routes.php` about the member's home
> church. The endpoint accepts both filters, so nothing is lost if that changes.
>
> **Verified against the live endpoint, not the code.** The risk in a model/server pair is a key name
> that does not match, which produces a silently empty field rather than an error. So the contract was
> asserted directly: the JSON key set returned by `/api/cells` was compared to the keys
> `HomeCellInfo.fromJson` reads, and the `path_label` separator was checked to be the middle dot the
> app splits on. Nine assertions, all passing, on a real fixture that was then restored.
>
> **6c shipped — duty roster (the arranging half).** `service_plans` + `service_roles` +
> `service_assignments`, `core/ServiceRoster.php`, and `admin/roster.php`: add a service, add the
> roles it needs with a count, put people in them, and record who has answered. The list shows what
> is still to find per service, and the roster page shows it per role.
>
> **A person does not have to be a member.** Ushers and choir members frequently are not registered,
> so an assignment takes either a member or a typed name and number. Requiring sign-up before somebody
> can be put on a rota would make this unusable in an ordinary church. When a member *is* chosen their
> name and number are copied onto the row rather than joined at read time, so the roster still reads
> correctly after that member closes their account and the foreign key goes NULL — a roster is
> history, not a join table.
>
> **An invitation is not counted as covered.** “Still to find” counts confirmed people; anyone still
> to reply is shown separately. A number that read *invited* as *filled* would let a planner stop
> looking before anybody had agreed, and find out on the morning.
>
> **A decline is kept, not deleted.** The row stays on the page marked declined so the planner can see
> who said no and ask somebody else — which is the actual next action. A role that still has people on
> it cannot be deleted either; the planner is asked to move them first, because a cascade would drop
> them silently.
>
> **`Phone` was extracted to `core/Phone.php`** while doing this. Two unrelated features now collect a
> person's number, and a second copy of the normalisation is how the same number ends up stored two
> different ways — which only shows up later, when the SMS or WhatsApp feature fails to reach it.
>
> **Verified:** 72 domain assertions and 29 over HTTP driving a real admin login through the real
> forms — creating a service, adding a role, adding a person, accepting on their behalf, being refused
> when removing a role that still has somebody on it, and being blocked from another church's roster
> by guessing the id. Every fixture that sets up state now asserts the write actually landed, which is
> the fix for the mistake made twice earlier in this phase.
>
> **6d shipped — members answer their own invitations.** A registered member put on a service sees
> it on their account under “You’re serving” and can accept, decline, or change their mind. Somebody
> typed in by name cannot answer, which is exactly why their number is shown on the roster.
>
> **The ownership check is a WHERE clause, not a branch.** `respondAsMember` updates only where
> `a.member_id = ?`, so a guessed assignment id changes nothing and there is no code path where the
> wrong member's row is reachable. An owner check written in PHP would have to be repeated at every
> call site, and the second one would eventually be missed — this is the same reasoning as the home
> cell leader's number being omitted by the query rather than filtered afterwards.
>
> **Past and cancelled services are exempt from answering**, in the same query. An answer to last
> Sunday's rota is not useful, and quietly accepting it would be wrong.
>
> **One message for every refusal.** “That invitation is not available to answer” covers wrong owner,
> unknown id, past service and cancelled service alike. Distinguishing them would confirm which ids
> exist.
>
> **Verified:** 30 domain assertions and 17 over HTTP driving a real member sign-in — the invitation
> appearing on the dashboard, accepting it, changing their mind, being refused when answering another
> member's slot, and a POST without a CSRF token changing nothing.
>
> **6e shipped — rota notices and the day-before reminder.** `core/RosterNotifier.php` and
> `cli/roster_worker.php`: a member added to a service is emailed once, and everyone still on the
> rota is reminded the day before. `notified_at` and `reminded_at` are separate columns on purpose —
> one shared "last message sent" would let the reminder suppress the first notice, so a rota filled in
> on a Saturday would tell everybody about Sunday and then never mention it again.
>
> **Email only, and that is a deliberate deviation from this bullet's "SMS/WhatsApp".** An automatic
> text message spends the church's SMS wallet per message. Switching that on belongs in an explicit
> decision with a cost attached, not in the same change as a free email — so the channel is email,
> the seam is `setMailer()`, and adding SMS is a follow-up with its own setting rather than something
> that quietly starts billing.
>
> **A bug the tests caught, not the reviewer.** The first implementation reminded anyone on tomorrow's
> service — including somebody who had been added that morning, who therefore got "you have been asked
> to serve" immediately followed by "still waiting to hear from you". Two emails, the second reading
> as a reproach for not answering something sent a minute earlier. The reminder now requires the
> notice to have gone out on an *earlier day*.
>
> **A failed send is retried, not lost.** The claim is taken before sending so two runs cannot both
> send it, and released again if the mail fails — a duplicate email is merely annoying, while a notice
> that silently never arrives leaves a slot nobody knows about.
>
> **Verified:** 34 assertions against the real database with a substituted mail transport, so nothing
> was actually sent: who is and is not reachable (no account, suspended, no address, cancelled
> service, past service, beyond a 60-day horizon), the off switch and `--force`, a dry run marking
> nothing, running twice sending once, the reminder reaching tomorrow's rota but not next month's,
> wording changing with the member's answer, and a failed send being counted, released and retried.
> **Not verified:** no email has been delivered — there is no SMTP configured here, and the worker's
> own status line says so rather than pretending otherwise.
>
> **6g shipped — giving campaigns. This completes Phase 6.** `giving_campaigns` + `giving_pledges`,
> `donations.campaign_id`, `core/GivingCampaign.php`, `admin/campaigns.php`, campaign mode on the public
> giving page (`/give/c/{slug}`), campaign-aware gifts, and a Campaign column and filter on the
> donations report.
>
> **The raised figure always comes from `donations`; a pledge never adds to it.** A pledge is a promise,
> and a church that adds promises to the amount raised is reporting money it does not have. The two are
> shown side by side, the bar moves on confirmed gifts alone, and a pledge becomes money one way only —
> `recordPledge()` writes a real completed donation and stamps the pledge with its id.
>
> **That stamp is the whole double-count defence.** The pledge is claimed with a conditional UPDATE
> *before* the donation is written, so two clicks cannot produce two gifts — the same shape as the claim
> in the follow-up worker, and for the same reason: a fact recorded in a column survives an edit that a
> PHP check would not.
>
> **A closing date closes a campaign by itself.** `acceptsGifts()` is a date calculation rather than a
> switch the admin has to remember, because passing the deadline is what "closed" means to the person
> reading the page. A gift offered to a finished campaign is refused with a message rather than quietly
> recorded as general giving — the donor would otherwise believe they gave to a project, and the
> treasurer would have no way to tell them otherwise. The admin list flags a campaign that has ended but
> is still switched on, so it surfaces rather than sits.
>
> **Pending gifts are not counted.** A bank transfer with a receipt uploaded but not yet verified is
> money somebody has *claimed*, so it is reported separately. Same principle as the pledge.
>
> **The bar never rounds up to 100%.** `percent()` floors to one decimal, so 99.6% reads as 99.9% and
> not as fully funded — 100% is the moment a church stops asking and starts spending.
>
> **A bug caught before shipping: campaigns were not tenant-scoped.** The unique key is
> `(tenant_id, slug)`, so two churches on one database can both have a `building-fund` — and a lookup
> by slug alone would have served the wrong church's page, while an unfiltered list would have put
> another church's appeal on this church's giving page. `Devotional` filters `tenant_id` on every read
> and this now does the same. Worth noting how it was found: an assertion that a *different church's*
> campaign was absent from `/give` failed, and the honest reading was that the code was wrong rather
> than the test.
>
> **Two currencies are never added together**, which is the same mistake as counting pledges as income:
> the goal is in the campaign's currency and anything received in another is reported on its own line.
>
> **Verified:** 106 domain assertions against the real database — validation, slug generation and
> collision, the goal bound, the raised/pending/failed/multi-currency split, distinct donors, the pledge
> lifecycle including the double-record guard, cancel and delete rules, open/upcoming/closed/off status
> and day counting, the 100% floor, and that every total reconciles with a direct query over
> `donations`. Plus 69 HTTP assertions through real admin logins and a real public session: creating and
> editing a campaign, the campaign page and its progress bar at 7.5% and then 20%, giving towards it,
> a gift refused because the campaign closed, a pledge that leaves the bar untouched, recording it and
> watching the total move, the second press doing nothing, the donations report naming and filtering by
> campaign, deletion refused once gifts exist, a 404 for an unknown slug, and a scoped admin turned away
> from another branch's campaign. Both harnesses removed afterwards and the database restored.
> **Not verified:** no real payment has been taken. Payhub is unconfigured here, so every gift in these
> tests went through the gateway's sandbox fallback and was marked completed without a transaction.

> **6f shipped — newcomer follow-up automation.** `follow_up_sequences` + `follow_up_steps` +
> `follow_up_enrolments` + `follow_up_actions`, `core/FollowUp.php`, `core/FollowUpRunner.php`,
> `cli/followup_worker.php`, `admin/follow-up.php`, and a Follow-up section in the guide.
>
> **`newcomers` had nowhere to put an email address.** Only `whatsapp_phone` existed — `SmsContacts`
> had been selecting `NULL AS email` for newcomers since Phase 5. So this feature could not have
> reached a single visitor: every email step would have been created and then skipped, which is the
> worst kind of broken because it looks like it ran. `newcomers.email` came first for that reason.
>
> **A step is an email or a task, and both land in one table.** An email sends itself; a task is
> something a person has to do. Putting both in `follow_up_actions` means "what is outstanding for
> this visitor" is one question with one answer instead of a union of two tables. Most visitors leave
> a phone number and nothing else, so the tasks are the part of a sequence that works for everybody.
>
> **Idempotency is the unique key, not a PHP check.** `UNIQUE (enrolment_id, step_id)` means the row
> *is* the claim, written before sending. A cron that fires twice and an admin who double-clicks both
> lose the race in the database rather than in a branch a future edit could remove. `UNIQUE
> (newcomer_id, sequence_id)` does the same for enrolment.
>
> **One email per person per run**, deliberately. A church catching up on last month's visitors
> backdates `enrolled_on`, which makes every step up to today due at once; without the cap the visitor
> receives the whole sequence in a minute. The backlog drains over a few runs instead.
>
> **A stall is measured from a different clock in each state** — `created_at` for somebody never
> contacted (how long the church has had their name), `updated_at` for somebody already contacted (how
> long since anyone did anything). Two thresholds, because a first visit goes cold in three days while
> a fortnight of silence after contact is a different kind of problem.
>
> **No global on/off switch, deliberately.** Every other worker has one; this one's control is each
> sequence's own switch, which sits on the page an admin already uses. A second, less discoverable
> switch that did the same thing would be one more control that can be set wrong and then blamed on
> the software — the same reasoning as the `reading_plan`, WhatsApp-consent and devotional-tick
> controls that turned out to be dead.
>
> **Four bugs the tests caught, not a reviewer:**
> 1. `FollowUpRunner` passed the `due()` row straight to `render()`, but that query aliases the name to
>    `newcomer_name` — so every email would have gone out saying **"Dear ,"**. The one personal thing
>    in the message, missing, in a message that was actually sent.
> 2. A failed send wrote its action row, and the completion pass read *the existence of a row* as
>    "this step is done" — so the enrolment was silently closed, the retry never happened, and the
>    visitor dropped out of the sequence without anybody being told. Completion is now per channel: an
>    email counts only once `sent_at` is set, a task as soon as it is on somebody's list.
> 3. `admin/newcomers.php` collected an email error into `$errors` and then ran the INSERT anyway, so a
>    malformed address was reported **and saved** — leaving a sequence that looked like it was running
>    while every message bounced. Any error now stops the write.
> 4. A failed send was counted twice in `attempts` (once at claim, once at failure), so the retry limit
>    arrived at half the intended number of attempts.
>
> **A landmine found on the way, worth knowing:** `core/Database.php` carries a lock-out guard that
> promotes the lowest-id user back to super admin whenever no super admin exists — and **every migration
> re-runs on every bootstrap**, so this fires on any request. Clearing `is_super_admin` on the only
> admin is therefore undone immediately, and a "scoped admin" test fixture built that way silently
> stays a super admin, making every scope assertion pass for the wrong reason. Scoped fixtures must be
> a second user.
>
> **Verified:** 96 domain assertions (validation, ordering, idempotent enrolment, timeline arithmetic
> including backdating, per-channel completion, stop semantics, the pipeline and stall rules, and
> placeholder rendering); 60 runner assertions with a substituted mail transport covering who is and is
> not due, the one-email-per-run cap, draining to a steady state, the dry run writing nothing, a
> refusal being recorded and retried once the window passes, giving up after the attempt limit, and a
> one-step sequence finishing. Plus 49 HTTP assertions through real admin logins for both the hub, the
> editor, the visitor page, the switch-off path, and scope — including that a scoped admin is sent away
> from another church's sequence, visitor, and cannot enrol anybody into it. All three harnesses were
> removed afterwards and the database restored.
> **Not verified:** no email has actually been delivered — there is no SMTP configured here, and the
> worker's status line says so rather than pretending otherwise.
>
> **A finding worth carrying forward:** the first HTTP run showed the number leaking on three checks,
> and the cause was the test harness — a fixture helper called with a missing argument fatally errored
> before it saved, leaving the previous fixture's state in place. The lesson is not "write better
> fixtures" but "make a fixture assert that it applied", which the re-run now does. It is the second
> time in this project that a broken harness looked exactly like a broken feature.

## 9. Phase 7 — Reach & platform

> **7a shipped — the settings screen is tenant-aware.** This was the one item in this phase that was
> actively *wrong* rather than merely unbuilt, so it went first.
>
> **The bug:** `admin/settings.php` read and wrote `SELECT * FROM settings ORDER BY id ASC LIMIT 1`.
> That "first row" is the shared defaults row, which belongs to nobody. So on an installation serving
> two churches, the form showed every church the *platform defaults* — a church that had set its own
> name would see somebody else's in the box — and saving wrote to the shared row, changing **every**
> other church's site for any value they had not overridden themselves. Two more places had the same
> shape: `admin/ads.php` wrote the Payhub keys to the first row, and `api/bible.php` read the Bible
> source and api.bible token from it, so a church that pasted its own token was silently served the
> platform default and the setting appeared not to save.
>
> **The fix is small because `helpers.php` was already right.** `settings()` resolves config defaults →
> shared row → tenant row, and `settingSave()` writes the current tenant's row (creating it on first
> write). The screens now use those instead of talking to the table directly: the form reads
> `settings()` and writes `settingSave()`. Any column the resolution never supplied is filled with null
> first, so a field that is empty everywhere renders as an empty box rather than an undefined-index
> notice — which is what reading the resolved set rather than a full row would otherwise cause.
>
> **Reading the resolved set also fixes a display lie.** The form used to show the raw stored row; it
> now shows what the site actually uses, inherited values included.
>
> **`settings` carries `UNIQUE (tenant_id)`**, so a church has at most one row and re-saving updates it
> rather than accumulating rows. `tenant_id` is nullable and MySQL treats NULLs as distinct, which is
> what allows the single shared defaults row alongside per-church rows.
>
> **There is deliberately no screen for editing the shared defaults.** Changing every church's branding
> at once is a different act from editing one church's, and it deserves its own deliberate screen rather
> than a checkbox on a page full of name-and-logo fields. The installer writes that row; per-church rows
> override it.
>
> **Verified:** 47 domain assertions — resolution per church, that a value one church has not set still
> inherits, that saving writes that church's row only, clearing a field storing NULL rather than being
> skipped, `setCurrent()` being honoured only when the session was authorised and ignored for a
> deactivated church, and the no-tenant path where `settingSave()` must fall back to the shared row.
> Plus 35 HTTP assertions through a real super-admin login: the form showing the current church's name,
> saving as church A leaving church B's row and the shared row untouched, switching churches through the
> real switcher and the form following, saving as church B leaving A's work intact, the Payhub keys
> landing on the current church only, and the public site rendering the right name.
> Both harnesses removed afterwards; the settings and tenants tables are snapshotted and restored
> exactly, and the harness asserts its own restore landed.
>
> **7b shipped — an admin account belongs to one church.** The other half of 7a's problem, and the
> half that was a security boundary rather than a display bug.
>
> **The gap:** `users` was one of the two tables the SaaS work never reached — no `tenant_id` — and
> `Auth::attempt()` matched a username on its own. On an installation serving two churches that meant
> one church's admin could sign in on the other's site and use every screen their role allowed. And
> because `admin/users.php`'s list, editor, suspend and delete all took a user id straight off the URL
> or the form without asking whose it was, an administrator could suspend, rename or **delete** any
> account in the platform — including another church's staff — and `/admin/users` listed every account
> along with its email address and phone number.
>
> **The public half was quieter and worse.** `/forgot-password` and `/unblock` looked an account up by
> username with no church filter, so anyone could start a reset for another church's admin and have the
> OTP arrive branded with the *wrong* church's name, from `setting('site_title')`. It also answered
> "No admin account found", which is an enumeration oracle across the whole platform.
>
> **The fix is a column and a comparison.** Migration `2026_38_user_tenants` adds `users.tenant_id`
> (`INT NOT NULL DEFAULT 0`) and backfills 0 → the church the site actually serves, following the pattern
> `2026_14_prayer_wall` set for `prayer_requests`. `Auth::allowedOnTenant()` is then the single place
> that decides: a super admin is platform-wide, everybody else may sign in only where
> `users.tenant_id` equals `Tenant::id()`. It is consulted in `attempt()` — before a session is opened —
> and again in `requireLogin()`, so a session cannot outlive an account being moved to another church.
>
> **The backfill picks its target with the same order `resolve()` uses**, not simply `is_default = 1`.
> That distinction is a lockout waiting to happen: deactivate the default church by hand while another is
> active and the site resolves to the *other* one, so stamping every legacy account with the inactive
> church would shut every one of them out of the site being served. If nothing is active then
> `Tenant::id()` is null everywhere and no church would accept them, so the rows are deliberately left at
> 0 — the one state where 0 matches the null. Verified by driving all three states: a deactivated default
> beside an active church (the account follows the active one), the normal case (it follows the default),
> and nothing active at all (it stays 0 and is let in rather than refused).
>
> **0 means "no church assigned", and no tenant resolves to 0**, so an account that somehow escaped
> assignment is refused everywhere rather than let in everywhere. A refusal is the same generic
> "Invalid credentials" as a wrong password and is counted as a failed login: naming the church would
> turn the login form into a way to discover that a username exists somewhere else.
>
> **One safety net, chosen on purpose.** If `users.tenant_id` is absent altogether then
> `2026_38_user_tenants` has not run against that database, and `allowedOnTenant()` returns true —
> behaving as the code did before the migration is better than locking every admin out of a live site
> because a schema change did not land.
>
> **Every write path stamps the church as well as filtering on it.** `admin/users.php` sets `tenant_id`
> when creating an account, and its reads *and* writes carry `AND tenant_id = ?` — the filter is on the
> UPDATE and DELETE statements themselves, not only on the check before them. The installer's first
> admin, and `admin/registrations.php` when it approves a church registration, stamp
> `Tenant::id() ?? 0` too: the backfill treats 0 as "the default church", which is right for accounts
> that predate tenancy and wrong for one created while serving the second church.
>
> **The backfill doubles as the repair for the installer.** On a fresh install the first admin is
> created before any tenant exists; because every migration re-runs on every bootstrap, that account is
> stamped on the admin's first request after setup finishes.
>
> **Verified:** 41 domain assertions — the column and its type, nullability and default; the index;
> `installer/schema.sql` mirroring both; the backfill leaving no account unassigned and none pointing at
> a non-existent church; the `allowedOnTenant()` truth table across both churches, including the
> super-admin bypass, an unassigned account being refused, and a row without the column behaving as it
> did before; and `Auth::attempt()` end to end in both directions. Plus **39 HTTP assertions** through
> real logins and real requests: an admin of church 1 signing in on church 1 and being refused on church
> 2, and the mirror image for church 2 (reached by sending `Host: h7b-second.test`, matched through
> `tenants.domain`); each one's users screen listing only its own accounts and neither the other
> church's nor the owner's; and, for each of suspend, delete, the editor and a forged edit POST, that the
> action **is** refused across the boundary while the same action succeeds on the admin's own account —
> a refusal proves nothing unless the same request also proves it could have worked. Also that no OTP is
> issued for, and no unblock succeeds against, another church's account.
>
> **Also fixed while verifying, and disclosed rather than buried:** four allow-list checks in
> `admin/settings.php` read `$_POST['x']` in the true branch of a ternary whose condition was
> `$_POST['x'] ?? 'default'`. Because those particular defaults are themselves in the allow-list, a form
> posted without the field took the true branch and logged "Undefined array key" on every save. The
> value was the default either way, so it is a log-noise fix with no behaviour change, and it is proved
> by evaluating the four real expressions from the file with `$_POST` empty, with a bare
> `$_POST['hero_type']` read as the control that shows the detector can fail.
>
> **Not verified, and the limit of the above:** no browser was involved — the HTTP assertions drive the
> app over a socket, so nothing here confirms the pages *look* right, only that they respond correctly.
> No second church exists in production, so all of it ran against fixtures on the local database, which
> is snapshotted and restored exactly (1 user, 1 church, 1 ip rule before and after) with the harness
> asserting that its own restore landed.
>
> **Two things this deliberately does not do.** Usernames and emails are still globally unique, so two
> churches cannot both have an admin called `john` — login is by username alone, and making that
> per-church needs the login form to disambiguate. And deactivating a church now locks its admins out
> everywhere rather than only on its own domain, because `Tenant::resolve()` falls back to the default
> church when no host matches and the locked-out admin's own church is no longer the one being served.
>
> **Also noted, not changed:** `core/Database.php`'s lock-out guard promotes the lowest-id user to super
> admin whenever no super admin exists. On a multi-church installation that could hand platform-wide
> rights to whichever church owns the lowest id. The guard exists to prevent a total lockout, it only
> fires in a state where the platform has no owner at all, and changing it is exactly the kind of edit
> that can lock a live site out — so it is recorded here for a deliberate pass rather than touched in
> this one.

> **7c shipped — an organisational unit belongs to one church.** The matching half of 7b, and the wider
> of the two: `org_units` was the other table the SaaS work never reached.
>
> **Why it mattered more than it sounds.** Units *are* admin scope — `Unit::scopeClause()` compares
> `org_unit_id`, `Unit::all()` feeds every picker, and `Unit::tree()` feeds the public site. While a
> unit belonged to nobody, on an installation serving two churches:
>   - the unit pickers on Users, Media, Events, Sermons, Prayer, Forms, Team and Newsletter offered
>     every church's units;
>   - `admin/units.php` listed, and could rename or **delete**, any church's hierarchy —
>     `Unit::delete()` took a bare id straight from the form with no check at all, and a delete cascades
>     to the children below it;
>   - `/unit/{slug}` and the app's `/api/unit`, `/api/feed`, `/api/media`, `/api/devices` and
>     `/api/devotional` each resolved a slug from the query string with no church filter, so one
>     church's unit page and app feed could be served on the other's site;
>   - the public home-cell finder, the units index and the public testimony form showed both churches';
>   - `HomeCell::save()` wrote cell details — including a leader's name and number — onto any unit by id;
>   - and the staff-by-unit reads (`SmsContacts`'s team source, `admin/notifications.php`) spanned
>     everyone, because the unit list they were filtered by was itself shared. `SmsContacts`'s team
>     source was the one staff read with no unit filter in its SQL at all, so that one is fixed by
>     adding the church to the query rather than by the unit work.
>
> **The fix.** Migration `2026_39_unit_tenants` adds `org_units.tenant_id` (`INT NOT NULL DEFAULT 0`,
> indexed) and backfills 0 → the church the site actually serves, using `Tenant::resolve()`'s own order
> exactly as `2026_38_user_tenants` does, and leaving the rows at 0 when nothing is active.
> `core/Unit.php` then filters `all()`, `find()`, `byType()`, `children()`, `findByName()` and
> `findByNameAnywhere()` by the church being served, stamps `create()`, and carries
> `AND tenant_id = ?` on the `UPDATE` and `DELETE` statements themselves rather than only on the check
> before them. Because `tree()`, `treeLight()`, `labelsById()` and `subtreeIds()` all sit on `all()`, and
> `ancestors()`/`path()`/`label()` sit on `find()`, ~85 call sites across 38 files came along untouched.
>
> **One new method replaced six hand-written filters.** `Unit::findBySlug()` is now the only place a
> slug becomes a unit, because a slug is not an identifier: `org_units.slug` carries a global UNIQUE
> key, so it identifies a row without saying whose it is.
>
> **Two worker fallbacks had to name a church explicitly.** `SmsRunner`'s "a campaign with no church,
> so tell everyone" and `cli/sms_sender_check.php`'s equivalent were `Unit::all()` inside a cron, where
> there is no host to resolve from and the ambient church is always the default one. Both now use the
> row's own church through the new `Unit::allForTenant()` — narrower and more correct at once.
>
> **`unit_levels` stays shared, deliberately.** It names the *shape* of the hierarchy, not the units in
> it, and both the count shown for "can I delete this level?" and the refusal in `levelDelete()` are
> platform-wide on purpose: a per-church count would show 0 and then refuse the delete.
>
> **A real bug the harness found, not the feature work.** `Unit::update()`'s cycle check read
> `in_array($id, subtreeIds($parentId))`, which is true for **every ordinary edit**, because a unit is
> always a descendant of the parent it already has. So the Units editor refused to rename anything
> unless its parent changed too, with the message "a unit cannot be nested inside itself or one of its
> own children". Reversed to `in_array($parentId, subtreeIds($id))`: the guard now refuses what it is
> for — a unit becoming its own descendant — and allows the rename. Only `admin/units.php` calls it, so
> the blast radius is exactly the screen that was broken.
>
> **Verified:** 74 domain assertions and 50 HTTP assertions. The domain half covers the column and the
> index, `schema.sql` mirroring both, the backfill, and every read and write in both directions —
> `all`/`find`/`byType`/`children`/`findByName`/`findByNameAnywhere`/`subtreeIds`/`labelsById`/`tree`,
> `create`/`update`/`delete`, and `HomeCell::all`/`find`/`save`/`published` — including a unit stamped 0
> being invisible everywhere. The HTTP half drives real screens over a socket with a chosen `Host:`
> header: the cell finder, the units index, `/unit/{slug}`, `/api/unit`, `/api/cells`, the Units and
> Users screens and both pickers, on both churches' hosts — and for rename and delete, that the action
> **is** refused across the boundary while the same request succeeds on the admin's own unit. Both
> cross-church write attempts are structurally valid requests, so the church is the only thing that can
> refuse them; the first version of the harness aimed an invalid one and proved nothing.
>
> **Not verified:** no browser again, so that the pages respond correctly is established but that they
> look right is not, and no second church exists in production. Everything ran against fixtures on the
> local database, snapshotted and restored exactly (2 units, 1 church, 1 account, 1 ip rule before and
> after) with the harness asserting that its own restore landed.
>
> **One consequence to know about.** `org_units.slug` is still globally unique, so two churches cannot
> both own `grace-zone` — the second silently gets `grace-zone-2` from the `uniqueSlug()` loop. Making
> slugs per-church means changing that key, which is its own job.

> **7d — PLANNED (audited 2026-09-14). 7d-i and the first half of 7d-ii are shipped.** The background
> workers read one church's settings while serving every church. The audit below is written down before
> the code, as a record of what was found.
>
> **First, a correction to what this document said after 7c.** It claimed `Devotional` and
> `GivingCampaign` "act on the default church alone" in a cron. That is wrong for the workers: the
> runners query their source tables with **no church filter at all**, so they do pick up every
> church's rows. (`GivingCampaign` has no worker whatsoever — it is a web screen.) The real fault is
> the opposite pairing: **each runner works for everyone and reads the settings of whoever is first.**
>
> **How it fails.** `Tenant::resolve()` skips the host lookup when `PHP_SAPI === 'cli'`, so in a cron
> `Tenant::id()` is always the **default** church. So at 07:00 on an install with two churches the
> devotional worker reads `setting('devotional_push_enabled')` — church 1's switch, so church 1
> turning it off stops church 2's too; selects the devotional text through `Devotional`, which filters
> `tenant_id` on **every** read, so church 2's members are sent **church 1's** devotional; and sends
> through `Pusher`, whose Firebase credentials come from the settings row, and the two churches have
> **different Firebase projects** — so church 2 may receive nothing at all rather than the wrong thing.
> `core/DevotionalPush.php` already carries a comment saying it assumes one tenant: "`device_tokens`
> has no `tenant_id` to filter on, and a cron run has no request host to resolve one from. True for
> every deployment so far."
>
> **The audit — what each worker reads that belongs to a church:**
>
> | Worker | Reads (tenant-scoped) | Rows it processes |
> |---|---|---|
> | `devotional_worker` → `DevotionalPush` | the on/off switch, the devotional text, `site_title`, the FCM credentials | every device, because `device_tokens` has **no `tenant_id`** |
> | `reading_worker` → `ReadingReminder` | the on/off switch, the plan/member reads | members of every church |
> | `roster_worker` → `RosterNotifier` | the on/off switch, `site_title`, SMTP | assignments of every church |
> | `followup_worker` → `FollowUpRunner` | SMTP, `site_title`, the church placeholders | enrolments of every church |
> | `sms_worker` → `SmsRunner`, `SmsCampaign` | **the gateway token**, the default sender ID, `site_title` | every church's queued and due campaigns (the queries carry no church filter) |
> | `sms_sender_check` | the gateway token, the default sender ID | every church's pending sender IDs |
> | `sms_maintenance` | the log retention days | the whole table |
> | `wa_worker` | stamps new conversation rows with the ambient church | every church's recipients |
> | `media_worker` | `site_title` in an email (cosmetic) | by media id |
> | `analytics_rollup` | — (aggregates rows that already carry their own `tenant_id`) | needs confirming while implementing |
> | `backup` | — (dumps the whole database) | n/a |
>
> **Two things make this worse than a mis-read setting.** The SMS one is the most damaging because the
> **gateway token is per-church**: a two-church install would send church 2's messages on church 1's
> account, or fail. And WAF-style: `device_tokens` cannot be attributed to a church at all today — only
> its `member_id` (→ `members.tenant_id`) or `org_unit_id` (the last church browsed) even hint at one.
>
> **What is missing is a way to ask "as church X".** `setting()`/`settings()` resolve for *the* church
> being served and take no church argument, and `Tenant::setCurrent()` is a *user* action — it writes
> the session behind an authorisation flag — so a worker must not use it.
>
> **7d-i shipped — the plumbing.** Two pieces, both in `core/Tenant.php`, plus a migration.
>
> **`Tenant::runAs(int $tenantId, callable $work)`** runs a block of work as one named church and puts
> the previous one back in a `finally`, so an exception inside the block cannot leave the process acting
> as the wrong church, and nesting unwinds in order. It is deliberately **not** `setCurrent()`: a cron
> must never touch a session. The override takes the first slot in `resolve()`, ahead of the session
> switcher and the host, because the caller is stating the subject of the work.
>
> **`runAs(0, …)` acts as no church at all**, which is the case a worker hits for a row that belongs to
> no church. That needs a separate `$overrideActive` flag rather than "is `$override` set": without it,
> a `tenant_id = 0` row would fall through to the ambient church — the default one, in a cron — and be
> sent under a church it does not belong to. That is the exact fault this stage exists to remove.
>
> **`Tenant::each(callable $work)`** is the only correct answer to "which churches does this run
> cover?": one pass per active church from a cron, and a single pass as the church being served from a
> web request, because a screen must never act on churches the person looking at it cannot see. It owns
> the `runAs()` wrapper, so no caller can forget the restore, and it **holds each church's failure**
> rather than throwing — a cron that stops at the first church with a problem stops being a schedule and
> becomes a complaint, since the other churches' reminders would silently not go out. Each pass returns
> `['ok' => bool, 'result' => mixed, 'error' => ?string]`.
>
> **`device_tokens.tenant_id`** (migration `2026_40_device_tenants`, mirrored in `installer/schema.sql`)
> with an index and an attribution ladder rather than a plain backfill: the signed-in member's own
> church first, then the church whose unit the device last reported, then the church the install serves.
> Each step is guarded on `tenant_id = 0`, so re-running it — which happens on every bootstrap — cannot
> overwrite an attribution the app has made since. 0 means "no church assigned", and a push to a device
> nobody can attribute reaches nobody rather than everybody: a church's notice arriving on another
> church's phones is worse than it not arriving.
> `api/devices.php` stamps the church on both the insert and the update path.
>
> **`settingFor()` was in the plan and was NOT written.** On implementing `runAs()`, a worker that wraps
> a whole pass in it reads every switch, credential and message substitution as that church already, so
> `settingFor()` would be a helper with no caller. An unused helper is worse than a missing one, because
> the next person cannot tell whether it is load-bearing. If a real need for "read one value as another
> church, outside a pass" turns up, it is a three-line wrapper over `runAs()`.
>
> **Verified (7d-i): 70 assertions** — 51 domain and 19 over HTTP. The domain half: the column, its type
> and default, the index, `schema.sql` mirroring both; the attribution ladder in all three cases and
> that a re-run leaves a set attribution alone; that `runAs()` takes effect inside and restores outside;
> that it restores after an exception; that nesting unwinds inner-then-outer; that a missing or
> deactivated church leaves the block with **no** church rather than a different one; that `runAs(0)` is
> the same; that `setting()` reads the church asked for inside a pass and the serving church outside;
> and that `each()` visits every active church in id order, skips a deactivated one, gives each pass its
> own church and its own settings, and holds one church's failure while the next still runs. The HTTP
> half drives the app's `POST /api/devices` for real — the insert path, then the update path for the
> same token — because that endpoint runs on every app launch and the change added a column and two bind
> parameters to both statements, so a miscount there would break every launch rather than one screen.
> Both harnesses snapshotted and restored exactly, asserting their own restore landed.
>
> **Not verified:** the member-bound branch of `api/devices.php` was not exercised — it needs a member
> session and a login flow — though its failure mode is benign (an unreadable `members.tenant_id` leaves
> the ambient church in place rather than picking a wrong one). `each()`'s web-request branch is also
> unexercised, because every runner is still called only from `cli/`; it is kept anyway as a guard,
> since without it a future "send now" button would loop every church.
>
> **7d-ii (part 1) shipped — the SMS worker.** The worker now runs one pass per church, through
> `Tenant::each()`. Recorded here because of why it went first: it is the one worker whose cross-church
> failure **costs money and misattributes who spoke**.
>
> **What the defect was.** `SmsCampaign::active()` asked for every church's queued campaigns, and
> `SmsRunner::run()` then worked them as whichever church happened to resolve — from cron, the default
> one. So a campaign belonging to church B was sent with church A's gateway token and sender ID, billed
> to church A's wallet, with church A's name dropped into `{church}` for church B's members, and the
> gateway log stamped with church A's `tenant_id`. The harness demonstrates exactly that: with
> `active()` deliberately un-scoped, **12 assertions fail**, and the captured gateway call shows all
> four fixture recipients — two churches' members — going out under one sender ID and one token.
>
> **Scoped:** `SmsCampaign::find()`, `active()` and `promoteDue()` now filter on `tenant_id`, and
> `unitsSentToday()` joins `sms_campaigns` so the daily cap is per church. `SmsRunner::run()` needed
> **no change at all** — inside a pass, `sms_token`, `sms_default_sender_id`, `sms_daily_unit_cap`,
> `sms_quiet_start`/`_end` and `site_title` already resolve for the pass's church, which is what 7d-i's
> plumbing bought.
>
> **`activeAll()` is new and deliberately un-scoped**, for `cli/sms_maintenance.php` — an operator tool
> that releases abandoned claims and must reach every campaign in flight. It is the only caller, and its
> docblock says plainly that nothing which sends may use it.
>
> **Two deliberate consequences, both worth knowing.**
> (1) `queuedFor()`'s clamp was `min(50, …)`, and `sms_maintenance.php` had long been passing
> `active(200)` and silently getting 50. The clamp is now `min(200, …)`, so that call site genuinely
> reaches 200. `active(50)` and the default `CAMPAIGNS_PER_RUN` are unaffected.
> (2) `cli/sms_worker.php` now exits **1** when a church's pass *threw*, so cron mails the operator, and
> still exits 0 for a church that was merely outside its sending window or short of wallet. It used to
> always exit 0.
>
> **The worker's pre-flight `Sms::configured()` guard was removed on purpose.** It asked the default
> church only, so a church with no token would have stopped every other church's messages before any
> pass ran. `SmsRunner::run()` checks the token itself, per pass, and reports it as a stop reason.
>
> **Not touched, and known:** the dashboard's 30-day and "this month" spend tiles
> (`admin/partials/sms/dashboard.php`) query `sms_campaign_recipients` with **no** church filter, so they
> are install-wide. The tiles that call `unitsSentToday()` are per church and now agree with the worker.
> Scoping those two raw queries belongs to the screens/settings pass, not this one.
>
> **Verified (7d-ii part 1): 62 assertions.** Fixtures: two churches, each with a token, a sender ID, a
> queued campaign with two members, and a scheduled campaign. A `Sms::setTransport()` fake stands in for
> the gateway, so nothing was bought. Asserted: each church saw only its own queue and `find()` could not
> reach the other's — each negation paired with the same call succeeding for the church that owns the row;
> `promoteDue()` promoted one church's due campaign and left the other's alone; one worker invocation sent
> both churches' campaigns with **each church's own sender ID and token**, `{church}` filled from that
> church's own `site_title`, to that church's own numbers only; both campaigns finished, every recipient
> went once and none was duplicated; `unitsSentToday()` counted 2 units for each church rather than 4 for
> one; every `sms_messages_log` row carried its own church's `tenant_id` and no row carried the wrong one;
> each church recorded its own `sms_wallet_log` snapshot; a second run sent nothing and promoted nothing;
> and with church two's cap deliberately already met, **church two paused while church one still sent**.
> The harness snapshotted and restored exactly, asserting its own restore landed, and the real
> `cli/sms_worker.php --status` was run as a subprocess with three churches (three blocks, no PHP error)
> and again after cleanup with one (one block, no heading — the single-church output is unchanged in shape).
>
> **Not verified:** no message reached a real gateway (a fake transport stands in, consistent with the
> standing caveat that no SMS has ever actually been delivered in this project); the worker was not run
> from a real cron; and `--status` was read but never rendered in a browser. There is still no second
> church in production, so every multi-church result here is from local fixtures.
>
> **7d-ii (part 2) shipped — the sender-ID poller.** `cli/sms_sender_check.php` now runs one pass per
> church. The pass itself moved to **`core/SmsSenderCheck.php`**, which is the same split
> `cli/sms_worker.php` already has from `core/SmsRunner.php` — and it was moved for a concrete reason,
> not tidiness: while the pass lived inside the script it could only be executed, never driven, so it
> could not be tested with a fake gateway at all. The script keeps the schedule, the reporting and the
> exit code.
>
> **What the defect was.** One unscoped query (`SELECT * FROM sms_senders WHERE status = 'pending' …`)
> collected **every** church's pending sender IDs, and each was then polled with whichever token was
> ambient — from cron, the default church's. So church B's sender ID was asked about on church A's
> account; the gateway answers about an ID that account does not own, and the row was marked
> **rejected permanently** on the strength of it, notifying church B's media team that their sender ID
> had been refused. The same pass would then write church B's sender ID into church A's
> `sms_default_sender_id`. The pre-flight `Sms::configured()` guard had the same shape as the one removed
> from the SMS worker in part 1: it asked the default church only, so one church with no token stopped
> every other church's sender IDs from ever being polled.
>
> **A second, subtler bug found while fixing the first, by the harness rather than by reading.**
> `sms_senders.tenant_id` is **nullable**, and NULL means "platform-wide" — the convention
> `admin/partials/sms/sender-ids.php` already uses. The first version of this fix scoped the platform
> church with `tenant_id <=> ?`, on the assumption that null-safe equality would also match the NULL rows.
> It does not: `NULL <=> 1` is **false**. `<=>` matches the shared rows only when the bound value is
> itself null, which is how the sender-IDs screen uses it — from a web request, where `Tenant::id()` can
> be null. A pass always names a concrete church, so the shared rows have to be asked for explicitly:
> `(tenant_id = ? OR tenant_id IS NULL)`. **The same mistake was in the `--list` block**, which is why the
> clause now lives in exactly one place (`SmsSenderCheck::scopedTo()`), used by both the due set and the
> listing.
>
> **Shared rows are polled once, not once per church.** They belong to the platform rather than to any one
> church, so they are polled during the platform church's pass (the seeded default, which is the church a
> cron already resolves to). Otherwise a three-church install would poll each shared row three times, with
> three different tokens, and could get three different answers, of which the last would win.
>
> **Verified (7d-ii part 2): 55 assertions.** Five fixture churches: one with a sender ID whose pass is
> made to throw, two ordinary, one with **no token at all**, plus a platform-wide row and a manually
> overridden row. Asserted: each pass sees only its own rows; **every gateway call carried the token of
> the church that owns the row** (the pair set is compared exactly); the untokened church's row was never
> polled and was left untouched rather than failed; the manual override was never polled and is still
> `manual`; the shared row was polled **exactly once**, by the platform church; a rejection quoted the
> gateway's own words; each approval wrote `sms_default_sender_id` into **its own** church and no other;
> a pass that threw was held (`ok = false`, surfaced on stderr, exit 1) while the churches after it still
> ran; the back-off window still holds (a row checked 5 minutes ago is not due, 20 minutes ago is);
> the listing groups by church and puts the shared row only under the platform church; and the untokened
> church was reported as skipped rather than silently ignored.
>
> The real script was also run as a subprocess **with cURL disabled**, so it exercises its own reporting,
> per-church labelling, summary line and exit code without a single packet leaving the machine: `4
> checked, 0 approved, 0 rejected, 0 still pending, 4 error(s)` — one per church with a token, and the
> untokened church excluded. Single-church output was re-checked byte for byte: with no token the script
> prints exactly `sms_sender_check: no SMS API token is configured; skipping.` on stderr with an empty
> stdout and exit 0, and the listing prints exactly `No sender IDs have been submitted yet.` with no
> heading.
>
> **The mutation check:** restoring the original unscoped query makes **15 assertions fail**, and the
> captured calls read `ALP001|token-PLATFORM BET001|token-PLATFORM FLT001|token-PLATFORM
> GAM001|token-PLATFORM SHR001|token-PLATFORM` — every church's row polled with one church's token, which
> is the defect stated in one line.
>
> **Not verified:** the labelled *success* line (`sms_sender_check: <church>: AAA111 approved.`) is
> exercised only for the failure variant reached through the disabled-cURL run and for the no-token skip
> path; injecting a fake gateway into the script itself is not possible without bootstrapping the app
> twice, which warns — so the one `foreach` that prints those lines is reviewed rather than executed. No
> real gateway was contacted and no email or push was delivered (fixture churches have no units, so
> `Notifier::send()` returns early with "There are no recipient units").
>
> **7d-ii — the rest, still to do.** Corrected by listing `cli/` instead of reciting from memory:
> there are eleven workers, not the six named here first, and the first version of this list missed
> `media_worker.php` and `reading_worker.php` entirely. Ordered by who gets hurt when the wrong church is
> used: `media_worker` (emails a publisher a daily report whose subject carries
> `setting('site_title')` — from cron, the default church's name — **shipped as part 4**), then
> `devotional_worker` and `reading_worker` (push to devices; both needed the new
> `device_tokens.tenant_id` — **shipped as part 3**), then `followup_worker` and `roster_worker` (email —
> **shipped as part 5**), then `wa_worker` (partly converted already — it stamps `Tenant::id()` on
> new conversations — **shipped as part 6**), and finally `analytics_rollup` and `backup`, which send
> nothing and needed reading rather than rewriting — **read in part 7, and neither needs changing.**
> `sms_maintenance` is already correct: it deliberately uses the un-scoped `activeAll()`.
>
> **Corrected, because the plan below was wrong:** the line that used to end this paragraph said "each of
> the rest becomes `Tenant::each(...)`". That was right for nine of the eleven and wrong for the last two.
> A backup is the whole database and `storage/backups` is one directory, so a pass per church would write
> N files that each restore half a schema; and the analytics roll-up already groups `analytics_daily` by
> `tenant_id` in one statement, so N passes would do the same work N times over. Wrapping either in
> `Tenant::each()` would be a regression — which is why part 7 read them instead.
>
> **Also outstanding, found while doing part 2:** `admin/partials/sms/sender-ids.php`'s `$loadSender()`
> guard, which was **fixed in 7d-iii below** — along with four identical instances of it.
>
> **7d-iii shipped — the SMS admin screens only touch the church being served.** Not a worker, but the
> same defect found in the screens while doing part 2 — and it turned out to be five instances of one
> mistake rather than the one.
>
> **What it was.** Every SMS screen loaded a single row by id and then checked the admin's **unit** scope
> only, treating a row with no unit as "shared with the whole church". Nothing ever checked the *church*.
> So `sms_senders`, `sms_templates`, `sms_campaigns` and `sms_groups` rows belonging to another church
> could be read, re-checked, renamed and deleted by posting their id — and an admin whose unit scope was
> empty (`$scopeUnitIds === []`, the non-super branch) was handed **any** row at all. Two docblocks
> asserted the protection that was not there, in as many words: "which is what stops church B managing
> church A's sender IDs by posting their ids" and "without it, a church admin could read another church's
> message and recipient list simply by putting its id in the URL".
>
> **The worst instance was not a delete.** `SmsContacts::findGroup()` fed `compose.php` and the WhatsApp
> broadcast screen, both of which resolve a `group_id` from the request and turn it into a **list of
> recipients**. A group id from another church resolved fine, so one church could send its message to
> another church's members. `compose.php` had a guard of its own, and it was the same unit-only check —
> which a unit-less group (`org_unit_id IS NULL`) skipped entirely.
>
> **The rule now lives in one place: `tenantScope()` in `core/helpers.php`.** It returns the clause and
> the parameters that go with it (`tenant_id = ?`, or `tenant_id IS NULL` when nothing resolves to a
> church) and every guard and listing is built from it, so the query that *lists* rows and the action that
> *acts on* one cannot disagree — which is exactly how this drifted.
>
> **Scoped:** the row lookups behind sender IDs, templates, campaigns and groups; `SmsContacts::findGroup`,
> `saveGroup` (ownership is proved before the UPDATE, because `rowCount()` cannot tell "not yours" from
> "no change") and `deleteGroup` (now returns false when it deleted nothing, which is what its `bool`
> return always claimed); the campaigns listing and its pagination count; and the dashboard's six recent
> campaigns, which showed the platform's newest regardless of whose they were. **Only a super admin skips
> the unit gate; nobody skips the church gate** — a super admin already has the church switcher.
>
> **Verified (7d-iii): 61 assertions.** Two fixture churches and three units, with rows in all four tables
> both unit-less and unit-bound. The real POST handlers were driven **one child process each** — they end
> in `redirect()`, which exits — with the partial `require`d, a faked session and CSRF token, a fake
> gateway transport, and the child acting as a chosen church through `Tenant::runAs()`. Asserted: another
> church's row survives delete, is not re-checked, is not cancelled and is not renamed, through the real
> handlers; the admin's own row is still deleted, checked and renamed (the paired positive control, without
> which every refusal could pass because the handler never ran); the unit gate still refuses a row inside
> the admin's own church but outside their unit; an admin with **no** unit scope cannot reach another
> church either; and the listing shows one church's rows. A fatal in any child is itself asserted, because
> a handler that never ran makes "it was refused" meaningless.
>
> **The mutation check:** making `tenantScope()` true for every row fails **15 assertions** — church A
> renamed church B's group (`"Hijacked by Alpha"`), deleted B's sender ID, template and campaign, and the
> campaign list showed B's campaign — while the positive controls kept passing, which is what shows the
> failures are the defect rather than blanket breakage.
>
> **Not verified:** no browser was used, so these screens were driven as handlers and never rendered; a
> super admin was not exercised (they get the unit bypass and the church gate only); and the analytics
> tiles this fix deliberately left alone are still install-wide.
>
> **Still outstanding, and separate:** `core/SmsCampaign.php:409` reads a campaign by id with no church
> filter, and the dashboard's 30-day / "this month" spend tiles query `sms_campaign_recipients` with none
> either. Both are reads on screens, so they belong with the rest of the settings/screens pass.
>
> **7d-iv shipped — the ads tables carry a church.** The enabler for `media_worker`, which cannot be
> converted without it. Same shape as the `device_tokens` column 7d-i added.
>
> **What it was.** `ad_publishers` and `ads` predate tenancy completely: no church column, no unit, no
> member to infer one from. So `cli/media_worker.php`'s daily "your advert performance" email read every
> publisher on the install and sent each one under whichever church's name happened to resolve. Its
> once-a-day stamp file is global as well, so **the first church to run in a day suppresses every other
> church's report** — and the report it does send quotes the default church's `site_title` and links to
> the default church's site. `media_post_items` has no church either, and that is fine: the video
> conversion is file work whose only setting is `ffmpeg_path`, a platform binary path, so that half needs
> no change at all.
>
> **What shipped:** `tenant_id INT NOT NULL DEFAULT 0` on both tables, indexed, with the same backfill
> ladder used for units and devices — an advert inherits its publisher's church, and an existing publisher
> can only be attributed to the church the install serves, because nothing else about it was ever
> recorded. 0 still means "no church", so an advertiser nobody can attribute is reached by nobody rather
> than by everybody.
>
> **The writers now stamp it.** The three inserts in `core/routes.php` carry it — and two lookups that
> were as dangerous as the worker itself were closed with it. "Email me my portal link" looked a
> publisher up **by email across every church**, so typing an address into one church's site emailed
> another church's publisher a link carrying the token that opens their Ad Manager. The `/advertise`
> find-or-create is scoped too, so an advertiser buying space on two churches gets an account on each;
> the alternative was one publisher row whose adverts belong to a church it does not advertise for. The
> token-based portal lookup is deliberately **not** scoped: a token is a credential, and an advert created
> through it takes the publisher's own church, so a publisher's adverts can never straddle two.
>
> **Verified (7d-iv): 15 assertions.** The columns, their type, nullability, default and index; that a
> fresh row really does start unattributed, which is what makes the stamping necessary rather than
> decorative; the backfill in all three of its states, re-run through a real bootstrap in a subprocess
> **because that is how it actually runs on every request**; that an advert follows its publisher rather
> than the install's church, which is the only assertion that can tell the two backfill steps apart; and
> that a re-run leaves a later attribution alone. The mutation check — neutering the inherit step — fails
> exactly that assertion (`got 1, expected 80`).
>
> **Not verified:** the three stamps in `core/routes.php` are lint-clean and pass the reference sweep but
> were **not driven**. The public `/advertise` flow needs a multipart upload and reaches the payment path,
> so driving it is the next stage's work rather than something to claim here. Nothing reads the new column
> yet, so no behaviour changed — which is also why this is safe to ship ahead of the worker conversion.
>
> **7d-ii part 4 shipped — the daily publisher report runs one pass per church.** The worker is
> `media_worker`, and its two halves turned out to have opposite answers.
>
> **The video conversion is deliberately *not* per church.** It is file work with no church of its own:
> the only setting it reads is `ffmpeg_path`, a platform binary path, and `media_post_items` has no church
> column. Running it once per church would re-query and re-skip the same rows N times, so it still runs
> once, and its docblock now says why rather than leaving the next reader to guess.
>
> **The report is.** It carries a church's name and links to that church's site, and it was sent under
> whichever church happened to resolve — the default one, from a cron. Worse, its once-a-day marker was a
> **single install-wide file**: the first church to run after 8am wrote it, and every other church's
> publishers got nothing for the rest of the day. The marker is now per church and per day, the publishers
> query is scoped (on the column 7d-iv added), and the whole job runs through `Tenant::each()`.
>
> **Also fixed, and it was live: the link in that email was dead.** `baseUrl()` is built from
> `$_SERVER['HTTP_HOST']`, which does not exist in a shell, so under a CLI cron every publisher report
> linked to `http://localhost/ad-manager?token=…`. It now falls back to the church being served —
> `tenants.domain`, which is what the site is actually reachable at — and **only when there is no Host**,
> so a web request is untouched.
>
> **How it is verified.** The selection moved into `core/PublisherReport.php`, the same way `SmsRunner` is
> split from `sms_worker`, and for the same reason: `Mailer` has no seam, so a test must never post mail,
> and splitting the choice from the sending is what lets the choice be asserted at all. **34 assertions:**
> the marker is per church and per day; marking one church does not mark another (the bug, as a single
> assertion); nothing is due before 8am and it is from 8am; each church is told about **its own**
> publishers under **its own** name and never mentions the other; a publisher with nothing approved is
> left out; a church with no advertisers gets an empty report rather than everybody else's; each church's
> portal link points at its own domain and none of them is localhost; a web request with a Host is
> unaffected by the fallback; and a church with no domain still gets `localhost` exactly as before. The
> real worker was then run twice as a subprocess — first run had a report due, second had none because the
> markers were written — with the harness **deleting its own markers first**, so that the worker made the
> decision rather than the harness arranging the answer.
>
> The mutation check — putting the install-wide marker back — fails **4 assertions**, the marker ones and
> nothing else.
>
> **Not verified:** the mail itself. There is no local SMTP, so `Mailer::send()` fails and the run reports
> `0 sent`; what is asserted is that the right church was *due* and that the right publishers were
> selected for it. Nothing was delivered — true of every email in this project so far.
>
> **Still owed from 7d-iv:** the three stamps in `core/routes.php` have now been carried across two
> stages without being driven. The public `/advertise` flow needs a multipart upload and reaches the
> payment path. That is a debt, not a decision, and it is the next stage's first job.
>
> **Still not tested against a second church** — there is none in production, and every claim in this
> audit is from reading the code, not from a run.

> **7d-v shipped — the public advert flow was driven over HTTP.** This is the debt from 7d-iv, not a new
> feature: the three church stamps in `core/routes.php` had been carried across two stages without ever
> being executed. They now are. There is no second church in production and the local dev server is one
> host, so the harness speaks HTTP over a raw socket and sets an arbitrary `Host:` header
> (`ad-alpha.test`, `ad-beta.test`, then none) — which is how `Tenant::resolve()` is reached for a second
> church from one server. Three multipart submissions were made with a real 1x1 PNG, a free
> `ad_durations` fixture (so the flow stays offline) and a real CSRF token from a real session:
>
> 1. `POST /advertise` on `ad-alpha.test` — one publisher created, stamped with the alpha church.
> 2. `POST /ad-manager?action=request_token` for that address on `ad-beta.test` — the address is **not**
>    found; asked on `ad-alpha.test` it **is** found. The pair is what makes the negative meaningful.
> 3. The same address on `ad-beta.test` — a **second** publisher, one per church, and the second advert
>    stamped with the beta church and filed against that church's own publisher row.
> 4. `POST /advertise` with no church-bearing `Host` — lands on the church being served (tenant 1).
> 5. `SELECT COUNT(*) FROM ads a JOIN ad_publishers p ON p.id = a.publisher_id WHERE a.tenant_id <> p.tenant_id`
>    is `0`, so no advert can be filed against another church's account within one request.
>
> **Verified (7d-v): 25 assertions, 0 failures**, plus two mutation checks. Removing the church filter
> from the publisher lookup by email &rarr; **5 failures**, the headline being *"there are now two accounts
> for that address, one per church — found 1"*, which is exactly the cross-church leak that fix closed.
> Binding `0` instead of the publisher's church on the public ad insert &rarr; **4 failures**, including the
> orphan query reporting `1 mismatched`.
>
> **Two harness defects found while chasing those failures, both worth remembering.** The rate limiter
> names its counter file after a **hash** of `action:key`, so a `*advertise_submit*` glob matches nothing
> and the counter silently carried over between runs — the sixth submission inside the window was
> throttled, and the assertions after it failed for a reason that had nothing to do with the code under
> test. And the harness never asserted that the **third** submission was accepted, so those two failures
> surfaced only as "no publisher was created". A negative assertion is worth exactly as much as the
> acceptance control standing next to it.
>
> **Also found, and fixed here:** 246 rate-limit counter files were **tracked** in the repo.
> `.gitignore` declares `/storage/cache/ratelimit/` ignored, but the files had been committed before that
> rule existed and were never removed from the index, so every deployment's counters churned in git.
> They are now untracked; the files stay on disk and are regenerated on demand. Two smaller instances of
> the same class — `storage/cache/bible/*` (5 files) and `storage/logs/media_worker.log` — are **still
> tracked** and still churn. They were left alone deliberately rather than widening this commit.
>
> **Not verified:** no payment is taken (the free package keeps the flow offline and Payhub is
> unconfigured), no email is delivered (no SMTP), and no browser was used — "the handler behaves
> correctly" is established, "the page looks right" is not.

> **7d-ii part 3 shipped — the devotional push and the reading reminder act as one church at a time.**
> Two runners, `core/DevotionalPush.php` and `core/ReadingReminder.php`, and their two cron scripts. Both
> had the same defect: one run for the whole install, reading the switch, the sending window, the entry and
> the sending credentials of whichever church resolved first — the default one, from a cron — and pushing
> it to every device in the install. A cron skips the host step in `Tenant::resolve()`, so "whichever church
> resolved first" was never the church whose devotional had been written for the day.
>
> - `dueToday()`, `devices()`, `audienceSize()` and the claim in the devotional runner are scoped to
>   `devotionals.tenant_id` / `device_tokens.tenant_id`; the same four in the reading reminder, whose
>   `targets()` also scopes its join on `reading_plans.tenant_id`.
> - The token prune — the one place these runners **delete** — is scoped too, so a device id from another
>   church's list cannot be removed by a pass that should never have seen it.
> - Both cron scripts run `Tenant::each()`, and a pass that throws is held, reported on STDERR and turned
>   into a non-zero exit at the end, so one church cannot stop the others. Both `--status` modes report per
>   church, with the install-wide `Pusher::configured()` line printed **once** rather than repeated inside
>   every church's block as though it were a per-church fact.
> - A church with the notification switched off now stops only itself. Before this, one church switching it
>   off in Settings stopped the whole install's devotional.
>
> **Verified (7d-ii part 3): 54 assertions, 0 failures.** Two fixture churches with their own devotional for
> today, their own plans, members and devices, plus a device with `tenant_id = 0` and a member of one church
> on the other church's plan. Everything is a dry run or a direct read — **no FCM request is made and no
> push is sent**: `targets()` and `audienceSize()` are pure, the claims are called through reflection, and
> the CLI workers are only ever invoked with `--dry-run`, which by design claims nothing.
>
> Mutation checks, each reverted and re-verified green:
> - Reverting `DevotionalPush::dueToday()` and `devices()` to church-blind (keeping the bind parameter, so
>   PDO cannot throw on a placeholder mismatch) → **8 failures**, the one that names the defect being *"each
>   church is sent its own devotional — alpha got 41, beta got 41"*, plus the unattributable device being
>   reached by every pass.
> - Making `ReadingPlan::find()` church-blind → **1 failure** (the assertion that pins the mechanism).
> - Breaking **both** that and the `targets()` join → **4 failures**, including a cross-church reminder.
>
> **One mutation was not detected, and that is the interesting result.** Breaking the `targets()` join
> *alone* changed nothing: with `ReadingPlan::find()` scoped, `nextDay()` cannot resolve another church's
> plan and returns `null`, so the member is skipped there instead. Each guard is independently sufficient.
> The **harness** was weak, not the code — the assertion could not tell which of the two was holding the
> line — so a second assertion was added that drives `ReadingPlan::nextDay()` directly for a borrowed plan
> from the wrong church (`null`) and from the church that owns it (day 1). The comment in `targets()` now
> says both guards are load-bearing and that neither may be removed on the grounds that the other covers it,
> because that reasoning is how a pair becomes one, and then none.
>
> **Not verified:** the push itself. `Pusher` is install-wide — one `config/firebase.php` and one service
> account — this harness makes no FCM request, and a dry run claims nothing by design. What is established
> is *who would be reached and with which church's entry*, not that a notification arrives. No device
> received anything.

> **7d-ii part 5 shipped — the follow-up and rota emails act as one church at a time.** Four files:
> `core/FollowUpRunner.php`, `core/RosterNotifier.php` and their two cron scripts. Both queues were read
> across the whole install, so one run used whichever church resolved first — the default one, from a cron
> — for the site name in every message and for every church's sequences and rotas alike.
>
> - This stage had a **mail seam** (`FollowUpRunner::setMailer()`, `RosterNotifier::setMailer()`), so the
>   harness substitutes the transport and drives the **real** run paths instead of only dry runs. Nothing
>   left the machine, and what would have been sent was captured and asserted on.
> - The strongest assertion uses `{{church}}`: both runners render it from `setting('site_title')`, the
>   church being served. So *"the message alpha's pass produced says Alpha Chapel"* is a direct test that
>   the pass was made as the right church — and under mutation it failed by naming the other one.
> - `due()` is scoped on `follow_up_sequences.tenant_id`; `targets()` on `service_plans.tenant_id`.
>   `service_roles` and `service_assignments` carry no church of their own — they are reached through the
>   plan. `newcomers` carries **no `tenant_id` at all**, only a unit, so the sequence is the only church
>   the follow-up rules can use, and it is the right one: the sequence owns the words that get sent.
> - Both writes that matter are scoped: `RosterNotifier::claim()`/`release()` join through to the plan, and
>   the follow-up's retry `UPDATE` carries an `EXISTS` on the sequence's church. A notice claimed for the
>   wrong church would be marked sent and then never sent.
> - `FollowUp::closeFinished()` gained an optional church — it had one caller, and without it the first
>   church's pass closed every other church's finished enrolments.
> - The switch is per church, so one church turning rota messages off no longer silences the rest.
>
> **Verified (7d-ii part 5): 49 assertions, 0 failures**, and **10 failures** under mutation (both queues
> made church-blind again). The one that names the defect: *"and it went to alpha's own visitor"* — under
> mutation alpha's pass emailed **beta's** visitor, and beta's own step was marked as done by alpha's run.
>
> **Three defects found by fixtures that had nothing to do with tenancy, and fixed here:**
>
> 1. **A visitor with no email address was queued for email steps.** The runner tried to send to an empty
>    string, the mail server refused, and the attempt was recorded as a failure, retried five times and
>    left sitting on the Follow-up page as a broken sequence. `due()` now requires an address *for email
>    steps only* — a task step still comes through, which is the documented point (a visitor who left only
>    a phone number is the common case).
> 2. **`followup_worker --status` printed zeros for three of its lines, always.** They were called with a
>    `null` user, and `Unit::scopeClause(null, …)` is `1 = 0` — "see nothing". A status command that
>    reports a confident zero regardless of reality is worse than no status command. It now passes a
>    super-admin scope (no unit filter) and narrows by church where the helper can take one.
> 3. **`roster_worker --dry-run` always said "Nothing due right now".** It tested `sent`, and a dry run
>    increments only `notices`/`reminders` — so the one mode whose whole purpose is to say what *would*
>    happen said nothing would.
>
> **Not verified:** a real send. `Mailer::configured()` is **no** on this machine (no `smtp_host`), so
> every message in this stage went to a captured closure rather than to an SMTP server. What is established
> is which church's queue produced which message, to which address, with which church's name in it. No
> email was delivered.

> **7d-ii part 6 shipped — the WhatsApp broadcast acts as one church at a time.** Two files:
> `core/WaCampaign.php` and `cli/wa_worker.php`.
>
> This one has the clearest harm of the batch, because **the credentials are per church**: `WhatsApp`
> reads its number ID, access token and app secret from `setting('wa_*')`, so each church sends from its
> own Meta number. A cron run was reading every church's campaign queue and sending it through whichever
> church resolved first — the default one — which is one church's congregation broadcast to from another
> church's WhatsApp number, and it would be billed to that number's account.
>
> - The gates were hoisted **above** the tenant loop, which was the whole bug: the quiet-hours window, the
>   daily cap and the `WhatsApp::problem()` pre-flight all read per-church settings, so the default
>   church's settings were deciding for everybody — and one church with no number configured stopped every
>   other church's queue from being worked at all. Every gate now lives inside the pass.
> - `find()` and `active()` are scoped on `wa_campaigns.tenant_id`. `find()` is what `--campaign=ID`
>   resolves through, so an operator naming another church's campaign now finds nothing — as does
>   `admin/partials/whatsapp/broadcast.php`, which used the same call and could be handed a foreign id.
> - `claim()` and `releaseStale()` carry the guard too, on the recipients' own `tenant_id`. That is the
>   quiet failure of the pair: a pass that claims rows it should not have seen locks another church's
>   campaign, which then sits looking stuck while that church's own cron finds nothing to do.
> - `--status` reports per church, and the run's final line is the run total only. There is no honest
>   install-wide "today" figure, because each church has its own daily ceiling — each church's own line
>   says it instead.
>
> **Verified (7d-ii part 6): 35 assertions, 0 failures**, under two separate mutations:
>
> - The two reads made church-blind → **7 failures**, the one that names the defect being *"alpha's queue
>   holds only alpha's campaign — 5,6"*: alpha's pass was handed both churches' campaigns. The
>   `--campaign` case failed too, with alpha's pass finding beta's named campaign.
> - The two write guards made church-blind → **6 failures** on their own: *"alpha claims none of beta's
>   recipients"* returned beta's rows, and *"alpha releases none of beta's stale claims"* released 2. So
>   each pair is load-bearing independently and neither may be dropped on the grounds that the other
>   covers it.
>
> **Everything ran with `--dry-run`**, which claims a batch, prints what it would send and releases it
> again. **No Meta request was made and no message was sent** to anybody.
>
> **Not verified:** an actual broadcast. No template was submitted to Meta, no approval state was
> exercised, and no delivery webhook (`applyStatus()`) was driven — the counts asserted here are the
> worker's own bookkeeping, not Meta's answers.

> **7d-ii part 7 shipped — the last two workers were read, and neither needs changing.** No product
> change: this stage's job was to answer "do these two need the same treatment?" and the answer is no, for
> a reason each worth keeping.
>
> - **`cli/analytics_rollup.php` must not be one pass per church.** `Analytics::rollup()` computes
>   `analytics_daily` with `GROUP BY tenant_id` and inserts the `tenant_id` it grouped on, so one pass
>   separates the churches and N passes would do the same work N times. Verified rather than assumed: two
>   fixture churches with **three and five** events of the same kind and the same day produced two
>   separate rows holding exactly those counts, with nothing filed as `tenant_id = 0`, and a second pass
>   left the figures unchanged. The dashboard reads were checked the same way — `Analytics::counts()`
>   under each church returned its own traffic and not the other's.
> - **`cli/backup.php` must not be one pass per church either.** A database backup is the whole database
>   and `storage/backups` is one directory: N passes would write N files that each restore half a schema.
>   `core/Backup.php` contains no `tenant_id`, no `Tenant::` and no per-church pass at all, which is
>   asserted rather than asserted-about. `Backup::run()` and `Backup::prune()` were **not executed** — a
>   reading job must not begin by letting a worker delete real backups.
>
> **Verified (7d-ii part 7): 16 assertions, 0 failures.** No mutation check, because there is no new
> guard to mutate: the property under test is that the existing `GROUP BY tenant_id` is what keeps the
> churches apart, and the two fixture counts are what show it.
>
> **Found here, and it belongs to 7e:** `backup_retention_days`, `backup_offsite_path` and
> `analytics_retention_days` are stored in the per-church settings row but applied install-wide — a cron
> resolves to the default church and uses that value for the one shared backup directory. They are
> **platform** settings that happen to live beside the per-church ones, so a church admin must not be able
> to change them; that is a whitelist decision. Both workers now carry a comment saying why they are not
> per-church, so the next reader does not "fix" them into N half-dumps.

> **Not built yet in Phase 7 (beyond 7d):** letting a church admin edit its own branding
> (`admin/settings.php` is super-admin only, and letting one in needs a decision about which fields are
> theirs — name, logo, service times — and which belong to the platform: SMTP, the Payment Gateway, SMS
> credentials, backups and the licence key). Then self-service tenant provisioning with plan limits,
> per-tenant upload/cache namespacing, and tenant-scoped analytics and reporting.
>
> **"7d-ii part 1 shipped" must not be read as Phase 7 being nearly done.** That commit is one worker of
> eleven. Phase 7's own scope line is *multi-tenant onboarding, localisation, PWA, app widgets*, and of
> those, localisation (`lang/` + a `t()` helper + Flutter ARB), the PWA (manifest, service worker, offline
> page) and the app widgets/shortcuts have not been started at all. 7d-ii and 7e come first because they
> are correctness rather than features: until every worker runs one pass per church, onboarding a second
> church is not safe, whatever else is finished.

- **Multi-tenant SaaS** — *the foundation is already built in Phase 0*. Phase 7 finishes the
  job: tenant-aware settings UI, per-tenant branding (logo, colours, domain) applied across
  the public site and the app, self-service tenant provisioning with plan limits,
  per-tenant admin accounts, and tenant-scoped analytics and reporting. This is what makes
  onboarding a third, fourth and fifth church cheap — and it is the foundation for the second
  church's app.
- **Localisation**: `lang/` message catalogues + a `t()` helper for PHP, `intl`/ARB files
  for Flutter; ship English first, then Yoruba / Igbo / Hausa; Bible translation selector.
- **PWA**: web app manifest, service worker with cache-first shell + stale-while-revalidate
  for API reads, offline fallback page, install prompt.
- **App widgets & shortcuts**: verse of the day and next-service widgets, plus deep links
  from shared sermon/reel URLs straight into the app.

---

## 10. Cross-cutting concerns (build these once, early)

| Concern | Where |
|---|---|
| Outbound message audit log | One `outbound_messages_log` writer used by SMS + WhatsApp + email + push |
| Audience resolver | One service that turns a group/segment/unit-subtree into a recipient list — reused by every channel |
| Placeholder renderer | `{name}`, `{church}`, `{first_name}` — one implementation, shared |
| Quiet hours + daily caps | Enforced centrally in the workers, not per screen |
| Opt-out registry | One `is_opted_out` concept honoured by every channel |
| Masked-secret UI component | One helper so no screen can accidentally render a full token |
| Retry/backoff + idempotency | Shared worker base class so every `cli/*` job is safe to re-run |

---

## 11. Decisions — confirmed 2026-09-12

| # | Decision | Answer | Effect on the plan |
|---|---|---|---|
| 1 | WhatsApp | **Official Cloud API only** — *superseded, see §6* | The unofficial bridge was built, then removed before it paired a number. Phase 4 ships the official channel; groups and number harvesting are permanently out of scope |
| 2 | Sender IDs | **Self-service.** Admin / editor / media team submit one (≤ 11 alphanumeric); it is pushed to the gateway automatically; the system polls and auto-approves; the super admin can also force a status | New §2.3 + `cli/sms_sender_check.php` + approval notification |
| 3 | Numbers | **Nigeria (`234`) default, other countries supported** for churches that buy the script | New §2.4 + `sms_countries` lookup and a per-tenant default country |
| 4 | Multi-tenant | **Yes — building it as SaaS** | **Phase 0 (tenancy foundation) runs first**; `tenant_id` on every new table |
| 5 | Order | **Happy with 1 → 7**, plus extra enhancements (pull phone numbers, WhatsApp groups) | Order unchanged; enhancements folded into §2.4 and Phase 4 |

To settle at build time (not blockers): the quiet-hours window, the monthly unit cap, and the
first sender ID value to register.

---

## 12. Recommended immediate next step

**Phase 0 first.** The tenancy foundation is 2–3 sessions and it is the difference between
adding SaaS support now and rewriting Phases 1–6 later. It ships with the existing site
behaving exactly as it does today (one default tenant).

Then **Phase 1** — pure upside: no vendors, no recurring cost. Start with **1.1 comments
moderation** and **1.2 analytics**, because analytics immediately starts collecting the data
that makes every later decision (what to promote, who to text, which sermon to repeat)
measurable.

Then **Phase 2 (SMS)** in full, since it is the explicit request and it unlocks the reminder,
follow-up and roster automation in Phase 6 for free.

---

## Appendix A — PhilmoreSMS error codes

| Code | Meaning | What the UI should say |
|---|---|---|
| `000` | Success — billed action completed | Sent |
| `400` | Bad parameters / invalid structure (e.g. empty phone list) | "Check the recipient list and message body." |
| `401` | Authentication failed — invalid developer token | "SMS token rejected — update it in Settings → SMS." |
| `405` | Wrong HTTP method (endpoint requires POST) | "Gateway request error — please retry." |
| `107` | Insufficient wallet balance | "Wallet balance is too low — top up PhilmoreSMS, then resume." |
| `110` | Message contains restricted/blocklisted words | "Message blocked for restricted words — please rephrase." |

## Appendix B — Cost maths helper

Units per message = `ceil(len / segment_size)` where `segment_size` is **160** for the
first segment and **153** for subsequent segments in GSM-7; for messages containing
non-GSM characters (e.g. `₦`, curly quotes, emoji) the whole message is UCS-2, so the sizes
become **70 / 67**. The Compose screen must show the live segment count and projected units
before the user presses send, and the worker must re-check the wallet balance before each
batch.
