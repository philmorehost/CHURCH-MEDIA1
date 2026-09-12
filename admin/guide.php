<?php
declare(strict_types=1);

Auth::requireLogin();

$pageTitle = 'Admin Guide';
$activeNav = 'guide';
require __DIR__ . '/partials/layout-open.php';
?>
<style>
  .guide-toc{display:flex; flex-wrap:wrap; gap:8px; margin:14px 0 4px;}
  .guide-toc a{font-size:12.5px; color:var(--ink-dim); border:1px solid var(--border); border-radius:999px; padding:6px 12px; text-decoration:none; transition:.2s;}
  .guide-toc a:hover{color:var(--gold-soft); border-color:var(--gold);}
  .guide h2{margin:0 0 10px;}
  .guide h3{margin:22px 0 6px; font-size:16px;}
  .guide p, .guide li{color:var(--ink-dim); font-size:14px; line-height:1.8;}
  .guide code{background:var(--panel-2); border:1px solid var(--border); border-radius:6px; padding:1px 6px; font-size:12.5px; color:var(--gold-soft);}
  .guide .pill{display:inline-block; font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:20px; vertical-align:middle; margin-left:6px;}
  .pill.super{background:var(--gold-soft); color:#3a2a08;}
  .pill.role{background:#6fb3ff22; color:var(--info);}
  .guide table{width:100%; border-collapse:collapse; margin:10px 0;}
  .guide table th,.guide table td{text-align:left; padding:8px 10px; border-bottom:1px solid var(--border); font-size:13px;}
</style>

<div class="card" style="margin-bottom:18px;">
  <h2 style="margin:0 0 6px;">📖 Admin Guide</h2>
  <p class="sub">Everything you can do in the admin panel — how to manage your church's content, your account, and the site. Use the links below to jump to a section.</p>
  <p class="sub">The sidebar is grouped, so only one group is open at a time — click a group heading to open it and close the one you were in. Each screen below also carries a badge showing the lowest role that can open it, and <span class="pill super">SUPER</span> means super admin only.</p>
  <div class="guide-toc">
    <a href="#roles">Roles &amp; Permissions</a>
    <a href="#dashboard">Dashboard</a>
    <a href="#analytics">Analytics</a>
    <a href="#ads">Ads Management</a>
    <a href="#donations">Donations &amp; Giving</a>
    <a href="#media">Media &amp; Reels</a>
    <a href="#pinned">Pinned Reels</a>
    <a href="#comments">Comments</a>
    <a href="#events">Events</a>
    <a href="#sermons">Sermons</a>
    <a href="#series">Series &amp; Podcast</a>
    <a href="#team">Team</a>
    <a href="#prayer">Prayer Wall</a>
    <a href="#testimonies">Testimonies</a>
    <a href="#newsletter">Newsletter</a>
    <a href="#sms">SMS Messaging</a>
    <a href="#whatsapp">WhatsApp</a>
    <a href="#forms">Forms</a>
    <a href="#notifications">Notifications</a>
    <a href="#attendance">Attendance</a>
    <a href="#newcomers">Newcomers</a>
    <a href="#pages">Pages</a>
    <a href="#units">Units</a>
    <a href="#unit-levels">Unit Levels</a>
    <a href="#registrations">Registrations</a>
    <a href="#security">Security</a>
    <a href="#backup">Backups</a>
    <a href="#settings">Settings</a>
    <a href="#users">Users</a>
    <a href="#firebase">Push (Firebase)</a>
    <a href="#account">My Account</a>
  </div>
</div>

<div class="guide">
  <div class="card" style="margin-bottom:18px;">
    <h2 id="roles">Roles &amp; Permissions</h2>
    <p>Every account has a role that controls what they can see and do. You'll usually only see <strong>your own church's</strong> content — each parish is fully isolated from every other parish.</p>
    <table>
      <tr><th>Role</th><th>What they can do</th></tr>
      <tr><td><strong>Admin</strong></td><td>Full content management for their church (media, events, sermons, team, prayer, newsletter, forms), manage their church's users, use Notifications, and send <strong>SMS</strong> to their own church.</td></tr>
      <tr><td><strong>Editor</strong></td><td>Create and edit content (media, events, sermons, team, forms, prayer) but cannot manage users or site settings. May also compose and send <strong>SMS</strong> for their church.</td></tr>
      <tr><td><strong>Media Team</strong></td><td>Post and manage media &amp; reels only, plus <strong>SMS</strong> for their church.</td></tr>
      <tr><td><strong>Super Admin</strong></td><td>Everything above across the whole organisation — plus Units, Site Settings, Pages, Users, Firebase push, and the <strong>SMS gateway settings</strong> (the API token, sending hours and caps). Marked with a <span class="pill super">SUPER</span> badge in this guide.</td></tr>
    </table>
    <p><strong>Isolation:</strong> a parish admin only sees their own parish's posts, events, sermons, team, forms, prayer requests, newsletter subscribers and <strong>SMS contacts</strong> — even if they log in at the organisation level. Unassigned records (e.g. visitor prayer requests) are only visible to the super admin, who can assign them to the right church.</p>
    <p>Within SMS specifically, a contact, group, sender ID or campaign with <strong>no church attached is treated as shared</strong> — head office records — so every parish admin can see them. Anything attached to another parish is invisible, and opening it by its id is refused as well as hidden.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="dashboard">Dashboard (<code>/admin</code>)</h2>
    <p>Your landing page. It shows your church's key numbers (media posts, upcoming events, sermons, new prayer requests, newsletter subscribers, blocked IPs) and your latest posts. It also shows <strong>📍 My Unit</strong> so you always know which church you're managing, plus any <strong>🔔 Notifications</strong> sent to your church.</p>
    <p>With the growth tools enabled you'll also see a <strong>Newcomers (7 days)</strong> stat and a <strong>Recent Newcomers</strong> card with tap-to-chat WhatsApp links and follow-up status badges — so the follow-up queue is visible the moment you log in.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="analytics">Analytics (<code>/admin/analytics</code>) <span class="pill role">ADMIN/EDITOR</span></h2>
    <p>How many people are reading and watching, where they came from, and what they searched for. Pick a range — <strong>Last 7 / 30 / 90 days</strong>, or a custom window — and everything on the page follows it.</p>
    <ul>
      <li><strong>Overview</strong> — page views, app opens and the rest of the headline counts, each with its share of traffic.</li>
      <li><strong>Daily page views</strong> — the shape of the period, so you can see which days actually landed.</li>
      <li><strong>Top sermons, reels, events and testimonies</strong> — what is being opened, ranked.</li>
      <li><strong>Most visited pages</strong> — which pages people land on.</li>
      <li><strong>What people searched for</strong> — the clearest signal of content you do not have yet. A search that returned nothing is a request.</li>
      <li><strong>Where visitors come from</strong> — the referring site, host only, never the full link.</li>
      <li><strong>Web vs app</strong> — how much of your audience is in the mobile app rather than the website.</li>
      <li><strong>Per church</strong> — the same numbers broken down across your churches.</li>
      <li><strong>Meanwhile, in this period</strong> and <strong>All time</strong> — giving, newcomers and other activity shown alongside the traffic.</li>
    </ul>
    <p><strong>What is deliberately not counted.</strong> Search engine crawlers, link-preview fetchers (whatever builds a WhatsApp or Telegram preview) and uptime monitors are filtered out, so the numbers are people. <strong>No IP address is stored</strong> — visitors are told apart by the same rotating one-way device hash the rest of the site uses, which cannot be traced back to a person. Browsers that ask not to be tracked are honoured and simply do not report at all.</p>
    <p><strong>Settings</strong> at the foot of the page switch collection on or off and set how long raw events are kept (7–3650 days). Turning collection off stops recording immediately; the numbers you already have stay. The daily roll-ups behind <strong>All time</strong> are kept indefinitely, so shortening the retention window never loses long-range trends.</p>
    <p><strong>Run the nightly roll-up</strong> (<code>php cli/analytics_rollup.php</code> from cron) so long-range history is preserved.</p>
    <p><em>The app reports separately from the website.</em> Expect the "App opens" figure and the web-vs-app split to stay low until people are running a recent build of the app — the website's own traffic will always dominate it.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="donations">Donations &amp; Giving (<code>/admin/donations</code>) <span class="pill admin">ADMIN</span></h2>
    <p>Track, manage, and audit all online giving, tithes, offerings, special seed pledges, and manual bank transfer receipts.</p>
    <ul>
      <li><strong>Giving KPI Summary:</strong> Live totals for completed online payments, verified bank transfers, and pending bank receipts.</li>
      <li><strong>Search &amp; Filters:</strong> Filter giving records by donor name, email, phone, reference, giving category, payment method (Online Payhub vs Bank Transfer), or payment status.</li>
      <li><strong>Bank Transfer Receipt Verification:</strong> Review uploaded bank transfer receipt images/PDFs and verify or mark transfers as completed or failed.</li>
      <li><strong>CSV Export:</strong> Download full CSV reports of all giving transactions for financial auditing.</li>
    </ul>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="ads">Ads Management (<code>/admin/ads</code>) <span class="pill super">SUPER</span></h2>
    <p>Monetize and manage vertical 9:16 display advertisements for the website and Mobile App. The gateway keys live on a separate screen, reached from <strong>System → Payment Gateway</strong> in the sidebar (or <code>/admin/ads?action=settings</code>).</p>
    <ul>
      <li><strong>Ad Gateway Settings</strong> (<strong>System → Payment Gateway</strong> <span class="pill super">SUPER</span>): Configure Payhub Online Gateway keys (`payhub_public_key`, `payhub_secret_key`) and Manual Bank Transfer details for advertiser checkout.</li>
      <li><strong>Packages &amp; Pricing:</strong> Create and edit ad duration packages with custom pricing and display frequencies (every 5m, 10m, 15m, 30m, once daily). Free packages default to once daily.</li>
      <li><strong>Review &amp; Approval:</strong> Review advertiser submissions, view uploaded bank transfer receipts, and approve/reject campaigns.</li>
      <li><strong>Publisher Ad Manager Portal:</strong> Approved publishers receive a secure link via email to track their ad impressions, clicks, CTR, and create additional ads.</li>
      <li><strong>Daily Performance Emails:</strong> Publishers automatically receive daily summary report emails of their ad statistics.</li>
    </ul>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="media">Media &amp; Reels (<code>/admin/media</code>)</h2>
    <p>The heart of the app — full-screen vertical reels. You can:</p>
    <ul>
      <li><strong>Create a post</strong> by uploading photos/videos. Videos play instantly and are automatically cropped to 9:16 in the background.</li>
      <li><strong>Add categories</strong> (Worship, Sermon Clip, etc.) so visitors can filter the feed.</li>
      <li><strong>Edit any item on a post</strong>: replace an image or video, set a new video cover, reorder items (↑/↓), delete a single item, or add new media to an existing post. Changes appear in the feed immediately.</li>
      <li><strong>Replace a YouTube item with an upload</strong> — older YouTube posts show as a tappable thumbnail that opens YouTube externally, and you can swap them for a directly-played MP4 so reels always swipe smoothly.</li>
      <li><strong>Bulk select &amp; delete</strong> — tick the checkbox on multiple posts (or use “Select all” in the header), then delete them all at once. Out-of-scope posts are skipped automatically.</li>
      <li><strong>Reprocess videos</strong> if a conversion is ever stuck (the cron note at the bottom of Settings covers the safety net).</li>
    </ul>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="pinned">Pinned Reels (<code>/admin/media</code>)</h2>
    <p>Feature a reel at the top of the feed so it is seen first. Pinned reels work across the whole platform while respecting each church's isolation.</p>
    <ul>
      <li><strong>How to pin:</strong> in the media list, click <strong>📌 Pin</strong> on any published post. It immediately jumps to the top of the feed, your church's unit page, and its province/zone pages.</li>
      <li><strong>Up to 3 per church:</strong> each church can have 3 active pinned posts at a time. To pin another, <strong>Unpin</strong> one first — pinned rows show a “📌 pinned” badge with the expiry time and an Unpin button.</li>
      <li><strong>Auto-expiry:</strong> a pin lasts <strong>3 days</strong>, then it automatically drops back into the normal feed order — no manual cleanup needed. (Unpinning early frees the slot immediately.)</li>
      <li><strong>Scope:</strong> admins/editors can only pin their own church's reels; the super admin can pin across the whole organisation. Only published posts can be pinned.</li>
      <li><strong>Visitors see it:</strong> pinned reels are marked with a “📌 Pinned” badge on the website and the mobile app.</li>
    </ul>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="comments">Comments (Instagram-style)</h2>
    <p>Reels support the full comment experience on both the website and the app:</p>
    <ul>
      <li><strong>Threaded replies</strong> — tap Reply on any comment to respond.</li>
      <li><strong>Emoji picker</strong> in the composer.</li>
      <li><strong>Image attachments</strong> that are automatically compressed to the smallest webp on upload.</li>
      <li><strong>Comment likes</strong> (♥) with per-visitor deduplication.</li>
      <li><strong>Live updates</strong> — comments refresh in real time while the sheet is open.</li>
    </ul>
    <p>Comments are anonymous (optional name), so no moderation is required from your side — but spam protection is built in via rate limiting.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="events">Events (<code>/admin/events</code>)</h2>
    <p>Add upcoming events with date/time, location, description, cover image, and optional RSVP link. Publish to show them on the website and app. Only your church's events appear here.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="sermons">Sermons (<code>/admin/sermons</code>) <span class="pill role">ADMIN/EDITOR</span></h2>
    <p>Upload sermon audio, attach a YouTube video, add speaker/series/scripture reference, and publish. Sermons appear on the site and app's Sermons tab. Attaching a sermon to a <strong>series</strong> and giving it an <strong>episode number</strong> also puts it in your podcast feed — see the next section.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="series">Sermon Series &amp; Podcast (<code>/admin/series</code>) <span class="pill role">ADMIN/EDITOR</span></h2>
    <p>A <strong>series</strong> is a named run of messages — "Walking in Grace, parts 1–8". Grouping sermons into one does two things: listeners get the next message without hunting for it, and the series becomes a <strong>podcast feed</strong> people can subscribe to.</p>
    <ul>
      <li><strong>Create a series</strong> with a name, description and cover image. The name becomes the <strong>podcast title</strong>, so keep it recognisable — this is what listeners see in Spotify and Apple Podcasts.</li>
      <li><strong>Cover art should be square.</strong> Podcast directories require it, and a wide image gets cropped.</li>
      <li><strong>Attach sermons</strong> to the series and give each one an <strong>episode number</strong>. The episode count on the series list is worked out from the sermons themselves, so it can never drift out of step with reality.</li>
      <li><strong>A sermon with no episode number is flagged</strong>, and the series list shows how many. That matters because a missing number is exactly what makes a feed list episodes by date instead of in the order you meant.</li>
      <li><strong>Unpublished series</strong> are hidden from the website and left out of the feed, but their sermons are not touched — you can take a series down without losing anything.</li>
      <li><strong>+ Episode</strong> on any series jumps straight to the sermon form with that series already selected.</li>
    </ul>
    <p><strong>Subscribing:</strong> your feed lives at <code>/podcast.xml</code>. Paste that address into Spotify for Podcasters, Apple Podcasts or any other directory <strong>once</strong> — after that every new sermon published into a series appears there on its own. There is also a friendly <code>/podcast</code> page to share with people who are not podcast apps.</p>
    <p><strong>Two things to know before you submit:</strong> a feed with no episodes is <em>rejected</em> by the directories, so <strong>publish at least one sermon with audio first</strong>; and only sermons that have an audio file are listed, because a video-only sermon has nothing for a podcast app to play.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="team">Team (<code>/admin/team</code>)</h2>
    <p>Manage your church's leadership and ministry team — name, role, photo, and bio. Published members show on the site's About/Team section.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="prayer">Prayer Wall (<code>/admin/prayer</code>)</h2>
    <p>View prayer requests submitted from the public Prayer page. Mark them <em>new / prayed / archived</em>, toggle public visibility to feature them on the site, and delete spam. Requests with no church are marked <strong>Unassigned</strong> — the super admin can assign them to the right parish.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="testimonies">Testimonies &amp; Praise Reports (<code>/admin/testimonies</code>) <span class="pill role">ADMIN/EDITOR</span></h2>
    <p>Members submit testimonies from the public site and they arrive here as <strong>Pending</strong> — nothing is published until you approve it.</p>
    <ul>
      <li><strong>Approve</strong> publishes it to the website and records when it was approved. <strong>Reject</strong> takes it out of the queue without deleting it, and <strong>Delete</strong> removes it for good (it asks first).</li>
      <li><strong>Filter buttons</strong> show live counts for Pending, Approved and Rejected, so the size of the queue is visible the moment you open the page.</li>
      <li><strong>Edit anything</strong> before publishing — name, email, phone, church, title and the testimony text itself. Correcting a spelling is usually better than rejecting somebody's testimony.</li>
    </ul>
    <p>Approved testimonies appear on the public site and are counted in Analytics, so you can see which ones people actually open.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="newsletter">Newsletter (<code>/admin/newsletter</code>)</h2>
    <p>View email subscribers (from the site footer signup), export them as CSV, and remove subscribers. Subscribers are scoped to your church; unassigned ones can be assigned by the super admin.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="sms">SMS Messaging (<code>/admin/sms</code>) <span class="pill role">ADMIN/EDITOR/MEDIA</span></h2>
    <p>Text messages to your members, newcomers and team. The screen is split into nine tabs:</p>
    <ul>
      <li><strong>Dashboard</strong> — wallet balance, units used this month, how many messages the gateway accepted, and anything the queue is still working through. Press <em>Check balance now</em> to read it live.</li>
      <li><strong>Compose</strong> — write a message, choose who gets it, and send. <strong>Nothing is charged until the second screen.</strong> “Check and continue” resolves the audience for real, tells you exactly how many people were left out and why, and works out the true cost — person by person, because a name with an accent in it can cost a second unit where everyone else costs one. Use <em>Send a test to myself</em> first; it costs one unit.</li>
      <li><strong>Contacts</strong> — the address book. <em>Sync from church data</em> pulls in every phone number the site already holds (team, newcomers, RSVPs, testimonies, registrations, app installs) in one click; newsletter subscribers are only included where the subscriber explicitly agreed to text messages. You can also import a CSV — the importer shows a dry run and every rejected row <em>before</em> it writes anything — or add people one at a time.</li>
      <li><strong>Groups &amp; Segments</strong> — a <em>group</em> is a list you keep (the choir, this term's workers). A <em>segment</em> is a rule that recalculates itself every time you send, so “joined in the last 30 days” is never stale.</li>
      <li><strong>Sender IDs</strong> — the name your messages arrive from. Up to <strong>11 letters and numbers</strong>, no spaces. Your provider reviews it; the status updates itself and you are told the moment it is approved. <strong>Nothing can be sent until one is approved.</strong></li>
      <li><strong>Templates</strong> — saved wording you can reuse, with placeholders like <code>{first_name}</code>.</li>
      <li><strong>Campaigns</strong> — the full history. Open one to see every recipient and what happened to each; resend only to the failures, resume a paused campaign, or cancel one that has not finished.</li>
      <li><strong>Settings</strong> <span class="pill super">SUPER</span> — the API token (encrypted, and never shown again), the sending window, daily unit caps, and the opt-out footer. <strong>Leave the token field blank to keep the one already stored.</strong></li>
      <li><strong>Guide</strong> — the same walkthrough in more detail, which also knows which steps you have already finished.</li>
    </ul>
    <p><strong>What “sent” means.</strong> It means your SMS provider accepted the message for delivery — not that a particular handset received it. The gateway answers once per batch rather than once per number, so the failure list is the closest thing to the truth for an individual person. A campaign that runs out of credit <em>pauses</em> rather than failing silently, and resumes once you top up.</p>
    <p><strong>Honouring STOP.</strong> Anyone who replies STOP is marked as opted out and is skipped by every campaign from then on, even if you select them directly or they are in a group you choose. This is not optional — it protects your sender ID from being blocked by the networks.</p>
    <p><strong>Sending needs the cron worker.</strong> Nothing goes out on its own. If a campaign sits on <em>queued</em> and does not move, the cron entry is the first thing to check; the exact lines to add are shown under the Settings tab.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="whatsapp">WhatsApp (<code>/admin/whatsapp</code>) <span class="pill role">ADMIN/EDITOR/MEDIA</span></h2>
    <p>Message your members on WhatsApp through <strong>Meta's official Business API</strong> — the channel WhatsApp itself provides, on your own verified business number. Nothing here uses a personal WhatsApp account or an unofficial sender, so your number cannot be banned for using it.</p>
    <p>The screen is split into six tabs:</p>
    <ul>
      <li><strong>Dashboard</strong> — whether the channel is connected and what still needs doing, plus the webhook address to give Meta.</li>
      <li><strong>Inbox</strong> — every conversation in one place, so a message from a member gets an answer. Anyone who can use the messaging screens can read and reply here, because a member's message that nobody answers is worse than no inbox at all.</li>
      <li><strong>Broadcast</strong> — send one approved template to a set of people, using the same audiences as SMS (everyone, a group, a church, or a saved segment).</li>
      <li><strong>Templates</strong> — the wording of the messages you are allowed to send. Meta reviews each one before it can be used.</li>
      <li><strong>Settings</strong> <span class="pill super">SUPER</span> — your Phone number ID, access token, app secret and verify token. The token and secret are encrypted and never shown again; <strong>leave a field blank to keep the value already stored</strong>.</li>
      <li><strong>Guide</strong> — the same walkthrough with the Meta setup steps in order.</li>
    </ul>
    <h3>The 24-hour rule — the thing that confuses everyone</h3>
    <p>WhatsApp allows a free-form reply <strong>only within 24 hours</strong> of that person's last message to you. Inside that window you can type anything back from the Inbox. <strong>Outside it, only an approved template can be sent</strong> — which is why Broadcast offers templates and nothing else. This is WhatsApp's rule, not a limitation of your site, and it exists to stop businesses messaging people who never asked.</p>
    <p><strong>A broadcast only reaches people who opted in.</strong> Someone counts as opted in if they are on the opt-in list, or if they have messaged you at all. Anyone who has opted out is skipped permanently, and everyone skipped is counted as skipped rather than messaged anyway.</p>
    <p><strong>How to switch it on</strong> <span class="pill super">SUPER</span>: create a Meta app and a WhatsApp Business account, verify your business, register the number, then paste the credentials into the Settings tab and give Meta the webhook address it shows. Until the credentials are saved the channel is <strong>off</strong>, and every tab says so at the top rather than failing silently. The Guide tab lists the steps in order.</p>
    <p><em>Broadcasts are sent by their own cron worker</em> (<code>php cli/wa_worker.php</code>), separate from SMS. If a broadcast sits on <em>queued</em> and does not move, the cron entry is the first thing to check. Sending is paced and capped per day so a large list cannot get your number rate-limited.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="forms">Forms (<code>/admin/forms</code>)</h2>
    <p>Build custom public forms with a drag-and-drop field builder (text, email, phone, number, <strong>date</strong>, <strong>time</strong>, <strong>date &amp; time</strong>, URL, select, radio, checkbox, image upload). Date/time fields open a native calendar/clock picker so respondents select instead of typing. Share the generated link, view responses in the panel, export to CSV, and close/delete forms. Each form belongs to your church.</p>
    <p><strong>Right after creating a form</strong> you land on a green “Form created — share this link!” banner with a one-click <strong>Copy</strong> button, so you can share it immediately (no need to go back to the list). Everything you create — forms, media/reels, sermons, events, and team members — is <strong>automatically assigned to your church</strong> the moment you create it. If your account has no Home Church set, creation is blocked with clear instructions instead of silently making unassigned content.</p>
    <ul>
      <li><strong>Cascading dropdown</strong> field type — build a <em>dropdown inside dropdowns</em> where each choice filters the next (e.g. <code>Province &gt; Zone &gt; Area &gt; Parish</code>). Type one full path per line with levels separated by <code>&gt;</code>, e.g. <code>Lagos &gt; Lagos Mainland &gt; Somolu &gt; LP63 YAYA</code>.</li>
      <li><strong>Church (auto)</strong> field type — automatically builds the same cascading dropdown from every church you've added in Units (Province → Zone → Area → Parish). No typing needed: respondents pick their parish and it always stays in sync with your church list.</li>
      <li><strong>Shareable CSV</strong> — on a form's Responses page, <em>Generate shareable CSV</em> saves the responses on the server and gives you a link (Google-Forms style). Anyone with the link can view/download it; you can copy, remove, or regenerate links at any time. The same <strong>🔗 Save &amp; Share Link</strong> is available on Newcomers and Attendance.</li>
      <li><strong>Public / Private</strong> — under <em>Access control</em> you choose who can open a form. <strong>Public</strong> = anyone with the link can open &amp; fill it. <strong>Private</strong> = a password is also required, so only people with the link <u>and</u> the password can open it — share the link and password <strong>separately</strong>. The <strong>Expiry</strong> field stops responses automatically on the given date/time; leave it blank to <em>never expire</em>.</li>
    </ul>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="notifications">Notifications (<code>/admin/notifications</code>)</h2>
    <p>Provinces can broadcast announcements to <strong>all churches, one church, or selected churches</strong>. Recipients see them on their admin dashboard and receive an email (via the SMTP configured in Settings). <span class="pill super">SUPER</span> admins can reach the whole organisation; other admins can only notify within their own unit.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="attendance">Attendance (<code>/admin/attendance</code>) <span class="pill role">ADMIN/EDITOR</span></h2>
    <p>Track your youth church's growth service by service. Record the <strong>date</strong>, <strong>service</strong> (Sunday Worship, Youth Service, etc.), <strong>topic</strong>, <strong>bible text</strong>, and attendance split by <strong>gender — Males and Females</strong>, plus optional notes.</p>
    <ul>
      <li><strong>Growth cards</strong> at the top summarise services logged, total attendance, and the male/female split.</li>
      <li><strong>Growth Trend chart</strong> shows total attendance per period — toggle <strong>Weekly</strong> (last 12 weeks) or <strong>Monthly</strong> (last 12 months).</li>
      <li><strong>Attendance vs. Newcomers</strong> chart pairs monthly attendance with newcomers added, so you can see whether growth in the service is translating into the follow-up funnel.</li>
      <li><strong>⬇ Export CSV</strong> downloads every record in your scope, or <strong>🔗 Save &amp; Share Link</strong> saves it on the server and gives you a shareable link.</li>
      <li>Each row has a <strong>+ Newcomer</strong> shortcut that jumps to the Newcomers form with that service pre-selected.</li>
    </ul>
    <p>Attendance is private — only logged-in admins/editors of your church can see it; it is never shown on the public site.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="newcomers">Newcomers (<code>/admin/newcomers</code>) <span class="pill role">ADMIN/EDITOR</span></h2>
    <p>Capture first-time guests so you can follow them up. Enter their <strong>name</strong>, <strong>WhatsApp phone number</strong>, <strong>address</strong>, and <strong>gender</strong> (Male/Female — the church tracks the Youth church by gender), optionally link them to the <strong>attended service</strong>, and set their <strong>follow-up status</strong>.</p>
    <ul>
      <li><strong>Status workflow:</strong> New → Contacted → Followed Up → Returned → Inactive. Change it <strong>instantly from the list</strong> with the inline colour-coded dropdown — no need to open Edit.</li>
      <li><strong>WhatsApp tap-to-chat</strong> — every phone number is a <code>wa.me</code> link, so one tap opens the chat to follow up.</li>
      <li><strong>Status filter buttons</strong> at the top show live counts and let you focus on, say, everyone still “New”.</li>
      <li><strong>⬇ Export CSV</strong> exports the current filtered list (or everyone), or <strong>🔗 Save &amp; Share Link</strong> saves it on the server and gives you a shareable link.</li>
      <li>Add newcomers straight from an attendance row via the <strong>+ Newcomer</strong> shortcut.</li>
    </ul>
    <p>Newcomers are private too — only your church's admins/editors can see them.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="pages">Pages (<code>/admin/pages</code>) <span class="pill super">SUPER</span></h2>
    <p>Manage the site's content pages (About, Privacy Policy, and any new pages) with a visual section builder (hero, text, columns, image, quote, CTA). This is organisation-wide, so only the super admin can edit pages.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="units">Units (<code>/admin/units</code>) <span class="pill super">SUPER</span></h2>
    <p>Manage your <strong>church hierarchy</strong> — by default <strong>Province → Zone → Area → Parish</strong>, but every level name is yours to change on the <strong>Unit Levels</strong> screen below. Each unit gets its own public directory page and media roll-up. Super-admin only.</p>
    <ul>
      <li><strong>⬆ Import Churches (CSV)</strong> — bulk-add the whole hierarchy from a CSV with one column per level, named after your levels (e.g. <code>Provinces, Zones, Areas, Parishes</code>; one row per church). Everything above the deepest level is required; the deepest level (the church itself) is optional. Use <strong>⬇ Download sample CSV</strong> so you get the exact column format, then fill it in. Names are stored in <strong>CAPS</strong> and existing units are matched automatically, so there are no duplicates.</li>
      <li><strong>⬇ Export Units (CSV)</strong> — download a complete CSV with one column per level plus ID, Level, Name, Slug, Full Hierarchy and Created At. The level columns match the import format, so an export can be edited and imported straight back (available to Super Admin, and to Church Admins scoped to their own units).</li>
      <li><strong>🏷 Name Corrections</strong> — review church name corrections flagged from the registration page. <em>Approve</em> automatically renames the church to the suggested spelling; <em>Reject</em> makes no changes.</li>
    </ul>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="unit-levels">Unit Levels (<code>/admin/unit-levels</code>) <span class="pill super">SUPER</span></h2>
    <p>Define the <strong>depth and naming</strong> of your church hierarchy — the one place that shapes how churches are organised everywhere on the site and in the app.</p>
    <ul>
      <li><strong>Rename a level</strong> — change <em>Province</em> to <em>Region</em>, or <em>Parish</em> to <em>Branch</em>. The new name is used immediately in the Units admin, the CSV template, the registration form, notifications, the public directory, and the mobile app. Existing units keep their data — only the label changes.</li>
      <li><strong>Add a level</strong> — new levels are appended at the bottom; move them with <strong>↑</strong> / <strong>↓</strong> to place them. Use this if your structure needs more than four depths.</li>
      <li><strong>Remove a level</strong> — only possible while no unit uses it, so you can never lose churches by mistake.</li>
    </ul>
    <p><strong>Depth 1</strong> is the top of the tree and the <strong>deepest</strong> depth is where the churches themselves live — that deepest level is the one typed in by name on the registration form, and the level used for “Find Your …”, notification targeting and per-church isolation.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="registrations">Registrations (<code>/admin/registrations</code>) <span class="pill super">SUPER</span></h2>
    <p>Churches register their admin at the public <strong>/register</strong> page. They pick their location from the cascading dropdowns — one per level above the church — then <strong>type their church name</strong> (auto-CAPS; existing churches appear as suggestions as they type). Because the dropdowns are built from your configured levels, the form always matches the hierarchy you have set up.</p>
    <p>Here you simply <strong>review → edit if needed → approve</strong>. Approving creates the admin account (a brand-new church name is created under the last chosen level automatically) and emails the applicant. Rejecting records an optional reason and emails them. Every step is safe — no data is ever deleted.</p>
    <p><strong>Roles &amp; corporate email:</strong> each registrant picks their role (<em>Church Admin / Editor / Media Team</em>). As soon as a level is picked or a church name is typed, the form <strong>suggests two usernames</strong> from the church name + role (e.g. <code>SANCTUARY OF PRAISE</code> + admin → <code>sopadmin</code> / <code>sop.admin</code>). The chosen username doubles as the <strong>corporate email local-part</strong>. If <strong>Settings → Corporate Email (cPanel)</strong> is enabled, Approve automatically creates that mailbox (e.g. <code>sopadmin@domain</code>) using the password the registrant entered, and if they gave an <strong>alternative email</strong>, it is added as a forwarder.</p>
    <p><strong>Instant password strength:</strong> the register page measures strength on every keystroke using <strong>cPanel's 0–100 scale</strong> — <span style="color:#ff6b6b;">red = weak (&lt;65)</span>, <span style="color:#e8b95f;">amber = fair (65–79)</span>, <span style="color:#5fe0a4;">green = strong (80+)</span> — and suggests a stronger password based on what was typed. Weak passwords are <strong>blocked at submission</strong> (cPanel minimum strength <strong>65</strong>), so cPanel email creation never fails later. If the password is the only problem, the page <strong>keeps every other field</strong>, auto-scrolls to and highlights the password section, and tells them why — they only fix that one part. After submitting, applicants see a <strong>“Submission received”</strong> screen with a <strong>WhatsApp</strong> link (from Settings → Contact Phone) for instant review &amp; approval. Use <strong>Settings → 🔌 Test cPanel connection</strong> to verify your API token, and <strong>✉ Create email</strong> on an approved registration to retry a failed mailbox.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="security">Security &amp; Account Unblocking (<code>/admin/security</code>, <code>/unblock</code>, <code>/forgot-password</code>) <span class="pill role">ADMIN</span></h2>
    <p>Review login attempts and security events, block suspicious IPs, and maintain account recovery mechanisms.</p>
    <ul>
      <li><strong>Security Unblock PIN:</strong> Every user sets a 4–6 digit PIN during registration or in <em>My Account</em>. If an IP or account is blocked due to failed login attempts, the user can restore access directly via <strong>/unblock</strong> using their credentials + PIN without waiting for manual intervention.</li>
      <li><strong>Email OTP Password Reset:</strong> Users who forget their password can request a 6-digit OTP sent to their primary and backup emails via <strong>/forgot-password</strong>. If email access is lost, they can fall back to resetting their password using their Security Unblock PIN.</li>
      <li><strong>Automatic MD5 Re-hashing:</strong> Existing passwords modified directly via phpMyAdmin in MD5 format are automatically detected on login and upgraded to Argon2id cryptographic hashes.</li>
    </ul>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="backup">Backups (<code>/admin/backup</code>) <span class="pill super">SUPER</span></h2>
    <p>Everything on the site lives in one database, and this is where you keep a copy of it. Use it before any major change.</p>
    <ul>
      <li><strong>Backups</strong> — how many are stored, how big they are, and how old the newest one is. If the newest backup is surprisingly old the page says so plainly, because that is usually the first sign the scheduled job has stopped running.</li>
      <li><strong>Stored Backups</strong> — each archive with its size, when it was taken, and a <strong>Manifest</strong> link. Keep a copy somewhere other than the server: a backup stored only on the machine it is backing up is not a backup.</li>
      <li><strong>The manifest is the part people forget.</strong> An archive holds the database only. The manifest lists the uploaded media that is <em>not</em> inside it — how many files and how much space — so you know exactly what to copy separately. A database restored without its uploads comes back with every image missing.</li>
      <li><strong>Retention</strong> — the newest backups are kept and older ones pruned on the schedule, according to the retention setting (change it in Settings). <em>Apply retention now</em> runs the pruning immediately.</li>
      <li><strong>Restoring</strong> — the page gives you the exact command, built from your own filenames, rather than a generic example: download the archive you intend to restore, check its size is plausible (a 0&nbsp;KB file is not a backup), then run the command or import the file through phpMyAdmin.</li>
      <li><strong>Media files are not in the archive</strong> — the database is. Uploaded images and video live in the uploads folder and are backed up separately; the page lists what to copy and where.</li>
      <li><strong>Scheduling</strong> — the page shows the cron line that keeps backups running without anyone remembering.</li>
    </ul>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="settings">Settings (<code>/admin/settings</code>) <span class="pill super">SUPER</span></h2>
    <p>Site-wide configuration — name, tagline, General Overseer Declaration, hero background media, contact details (supports multiple comma/newline-separated phone numbers), social links (Facebook, Instagram, YouTube, TikTok, X / Twitter), live stream link, giving URL, footer &amp; SEO, Bible source, and <strong>Email (SMTP)</strong> settings used for all outgoing mail (newsletters, notifications, security alerts).</p>
    <ul>
      <li><strong>General Overseer (G.O.) Declaration:</strong> Set a prophetic word or annual theme banner at the top of the website. Choose between an animated <strong>scrolling marquee</strong> or a bold <strong>static announcement banner</strong>.</li>
      <li><strong>Homepage Hero Background:</strong> Choose between an animated gradient, background image, <strong>uploaded MP4/WebM background video</strong>, or a <strong>YouTube video background link</strong>.</li>
      <li><strong>Multiple Contact Phone Numbers:</strong> Separate multiple phone numbers with commas or newlines; they automatically render as individual clickable <code>tel:</code> links on the contact page.</li>
      <li><strong>Social Media Links:</strong> Add URLs for Facebook, Instagram, YouTube, TikTok, and X (Twitter) with clean inline SVG icons rendered in the website footer.</li>
      <li><strong>On-Demand Media Worker:</strong> Trigger background video conversion and daily publisher ad reports immediately with the <strong>▶ Run Media Worker Now</strong> button.</li>
    </ul>
    <p>It also includes the <strong>Mobile App Download Button</strong> — enable it, paste your Google Play link, and choose whether it shows on <code>all</code> pages or only selected ones (e.g. <code>/, /feed, /events</code>). A floating “Get it on Google Play” button then appears on the left edge of the public site.</p>
    <p><strong>Admin &amp; App Only Mode</strong> (same card): optionally nudge <strong>Android phone</strong> visitors to the app to boost adoption. Choose <em>Off</em> (default), a small <em>dismissible banner</em> (light touch), a <em>“Get the App” landing page</em> (with a “Continue to website” link), or <em>Force</em> (send straight to the Play link). iPhone and desktop visitors always keep the website, and search engines are never redirected — so SEO is unaffected.</p>
    <p>The <strong>Video Conversion (Cron Job)</strong> card explains how to keep the 9:16 video conversion running automatically on your server. Super-admin only.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="users">Users (<code>/admin/users</code>) <span class="pill role">ADMIN</span></h2>
    <p>Create and manage accounts for your church's team. Set name, username, email, role (admin/editor/media team), password, and <strong>Home Unit</strong> (the church they manage). The very first super-admin account can't be deleted or edited by others.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="firebase">Push Notifications (<code>/admin/firebase</code>) <span class="pill super">SUPER</span></h2>
    <p>Turn on real push notifications for the mobile app using Firebase. The Firebase page has a step-by-step wizard with a live status check — upload your service-account key, save your Project ID, and send a test push. Super-admin only.</p>
  </div>

  <div class="card">
    <h2 id="account">My Account (<code>/admin/account</code>)</h2>
    <p>Update your own name, email, and password, and see which church/unit you manage. If you ever need access to another church, ask the super admin to assign it in Users.</p>
    <p><strong>Tip:</strong> after any important change, use the <strong>↗ View Website</strong> link in the sidebar to preview your church's public site and app.</p>
  </div>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
