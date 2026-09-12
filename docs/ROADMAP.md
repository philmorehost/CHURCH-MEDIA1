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
- **New**: `api/og.php?type=sermon|reel|testimony|event&id=` generating a 1200×630 PNG
  (GD `imagettftext`), cached in `storage/cache/og/`, with church branding.
- **Modify**: `views/partials/layout-open.php` (`og:image` from the generator),
  `views/sermon-detail.php`, `views/event-detail.php`, `views/testimonies.php`.
- **App**: a Share action that emits the same card URL.
- **Verify**: each type renders, cache hit/miss, fallback when GD fonts are missing.

### 1.5 Prayer wall depth
- **DB**: `prayer_requests` gains `increment_count`, `is_anonymous`, `answered_at`,
  `answer_note`, `is_featured`. New `prayer_participants` (request_id, session_hash, created_at)
  so one person counts once.
- **Modify**: `api/prayer.php` (prayer counter, anonymous mode, answered flag),
  `views/prayer.php` (counter button, "Answered Prayers" wall, anonymous toggle),
  `admin/prayer.php` (mark answered, feature, moderate).
- **Verify**: counter de-dupes by session, anonymous requests hide the name publicly but
  not in admin, answered wall only shows approved entries.

### 1.6 Level-aware push targeting
- **DB**: `notifications` gains `target_level` VARCHAR(40) NULL, `target_unit_id` INT NULL.
- **Modify**: `admin/notifications.php` (pick **any** level from `Unit::levels()` — "everyone
  under Zone X"), `core/Pusher.php` (resolve audience via `Unit::subtreeIds()`).
- **Verify**: a notification aimed at a mid-level reaches every church beneath it, and a
  church admin cannot target outside their own subtree.

### 1.7 Backups & data export
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
