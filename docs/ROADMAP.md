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

| Phase | Theme | Ships | Effort | Cost |
|---|---|---|---|---|
| **0** | **Tenancy foundation** — SaaS confirmed | `tenants` table, host/subdomain resolution in `bootstrap.php`, `tenant_id` on `settings` and **every new table from here on**, default-tenant backfill, per-tenant storage namespacing | 2–3 sessions | None |
| **1** | Quick wins, no new vendors | Comments moderation, analytics dashboard, real RSVP + `.ics`, share cards, prayer wall depth, level-aware push targeting, backups & data export | 6–8 sessions | None |
| **2** | **Messaging Hub — SMS** (explicit request) | Contacts address book, groups/segments, sender-ID management, compose + scheduling, templates, campaigns/history, wallet, worker, full guide | 8–10 sessions | Per-SMS (wallet) |
| **3** | Sermons: series + podcast | Sermon series, series pages, podcast RSS feed + Spotify/Apple submission | 3–4 sessions | None |
| **4** | WhatsApp channel | Official Cloud API integration (templates, 24-h window, webhooks); optional quarantined unofficial bridge behind a flag | 5–7 sessions | Per-conversation |
| **5** | Members & daily engagement | Member accounts, daily devotional, Bible reading plans + streaks, offline sermon downloads | 10–12 sessions | None |
| **6** | Operations | Home cell finder, duty roster / service planning, newcomer follow-up automation, giving campaigns | 8–10 sessions | None |
| **7** | Reach & platform | Multi-tenant onboarding, localisation, PWA, app widgets | 10–14 sessions | None |

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
> Still open: the Flutter app does not yet send `app_open`/`device=app`, so the
> web-vs-app split reads as all-web until that one-line client change lands.
- **DB**: `analytics_events` (`id`, `occurred_at`, `event` VARCHAR(60), `path`, `org_unit_id`,
  `post_id`, `sermon_id`, `event_id`, `device` ENUM('web','app'), `session_hash`,
  `referrer_host`, `country`, `meta` JSON) + indexes on `(occurred_at)`, `(event)`,
  `(org_unit_id)`. `analytics_daily` roll-up table (filled by the worker) so dashboards
  stay fast as data grows.
- **New**: `core/Analytics.php` (buffered, fire-and-forget `record()`; never blocks a request),
  `api/analytics.php` (POST beacon, rate-limited, no PII), `cli/analytics_rollup.php`
  (nightly aggregation + retention pruning), `admin/analytics.php`.
- **Modify**: `views/partials/layout-open.php` (beacon script), `api/post.php`
  (count views there instead of ad-hoc `post_views`), app screens to send `device=app`.
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
> Still to build: the Sender IDs tab itself, where a church submits one.
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

### Decision — CONFIRMED: build both
**Both** are in scope. The arrangement:
- **Official Cloud API** = all *member-facing* and *business-critical* messaging: welcome
  messages, event reminders, giving receipts, follow-ups. Compliant, auditable, deliverable.
- **Unofficial bridge** = a small Node sidecar on a **separate sacrificial SIM**, for what the
  Cloud API simply cannot do — **reading group participants and posting into groups**.
  Feature-flagged (`wa_unofficial_enabled`, default **off**), bound to `127.0.0.1`, with a
  documented kill-switch and a health check.
- **Never** mix them on the same number, and never let an unofficial path message the
  congregation. If the bridge's number is banned, the church must lose nothing.

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
> URL subscribed before any of that is testable. There is also no admin UI yet — templates,
> conversations and the composer are 4.2.
>
> **Still to build in this phase:** `admin/whatsapp.php` (template manager, conversation inbox with
> the window indicator, broadcast composer reusing the Phase 2 contacts/groups, per-church number
> mapping), `cli/wa_worker.php`, and the optional Node bridge for groups and number harvesting.

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
> **Still to build in this phase:** the optional Node bridge for groups and number harvesting
> (4.4). Everything else in Phase 4 is done.

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
  offline KJV in `mobile/assets/bible/kjv.json`, streak counter and reminder notification.
- **Offline sermon downloads**: cache audio in app storage with a managed download list and
  size/wipe controls.
- **Verify**: reading progress survives app restart and works offline; devotional push
  respects quiet hours and preferences; member sessions cannot reach `/admin/*`.

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

## 9. Phase 7 — Reach & platform

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
| 1 | WhatsApp | **Both** — official Cloud API + quarantined unofficial bridge | Phase 4 builds both; the bridge is flag-gated (default off) on a spare SIM and owns groups + number harvesting only |
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
