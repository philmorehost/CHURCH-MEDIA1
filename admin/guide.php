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
  <div class="guide-toc">
    <a href="#roles">Roles &amp; Permissions</a>
    <a href="#dashboard">Dashboard</a>
    <a href="#media">Media &amp; Reels</a>
    <a href="#pinned">Pinned Reels</a>
    <a href="#comments">Comments</a>
    <a href="#events">Events</a>
    <a href="#sermons">Sermons</a>
    <a href="#team">Team</a>
    <a href="#prayer">Prayer Wall</a>
    <a href="#newsletter">Newsletter</a>
    <a href="#forms">Forms</a>
    <a href="#notifications">Notifications</a>
    <a href="#attendance">Attendance</a>
    <a href="#newcomers">Newcomers</a>
    <a href="#pages">Pages</a>
    <a href="#units">Units</a>
    <a href="#security">Security</a>
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
      <tr><td><strong>Admin</strong></td><td>Full content management for their church (media, events, sermons, team, prayer, newsletter, forms), manage their church's users, and use Notifications.</td></tr>
      <tr><td><strong>Editor</strong></td><td>Create and edit content (media, events, sermons, team, forms, prayer) but cannot manage users or site settings.</td></tr>
      <tr><td><strong>Media Team</strong></td><td>Post and manage media &amp; reels only.</td></tr>
      <tr><td><strong>Super Admin</strong></td><td>Everything above across the whole organisation — plus Units, Site Settings, Pages, Users, and Firebase push. Marked with a <span class="pill super">SUPER</span> badge in this guide.</td></tr>
    </table>
    <p><strong>Isolation:</strong> a parish admin only sees their own parish's posts, events, sermons, team, forms, prayer requests, and newsletter subscribers — even if they log in at the organisation level. Unassigned records (e.g. visitor prayer requests) are only visible to the super admin, who can assign them to the right church.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="dashboard">Dashboard (<code>/admin</code>)</h2>
    <p>Your landing page. It shows your church's key numbers (media posts, upcoming events, sermons, new prayer requests, newsletter subscribers, blocked IPs) and your latest posts. It also shows <strong>📍 My Unit</strong> so you always know which church you're managing, plus any <strong>🔔 Notifications</strong> sent to your church.</p>
    <p>With the growth tools enabled you'll also see a <strong>Newcomers (7 days)</strong> stat and a <strong>Recent Newcomers</strong> card with tap-to-chat WhatsApp links and follow-up status badges — so the follow-up queue is visible the moment you log in.</p>
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
    <h2 id="sermons">Sermons (<code>/admin/sermons</code>)</h2>
    <p>Upload sermon audio, attach a YouTube video, add speaker/series/scripture reference, and publish. Sermons appear on the site and app's Sermons tab.</p>
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
    <h2 id="newsletter">Newsletter (<code>/admin/newsletter</code>)</h2>
    <p>View email subscribers (from the site footer signup), export them as CSV, and remove subscribers. Subscribers are scoped to your church; unassigned ones can be assigned by the super admin.</p>
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
    <p>Manage the <strong>Province → Zone → Area → Parish</strong> hierarchy. Create parishes/zones/areas/provinces, and each one gets its own public directory page and media roll-up. Super-admin only.</p>
    <ul>
      <li><strong>⬆ Import Churches (CSV)</strong> — bulk-add the whole hierarchy from a CSV with columns <code>Province, Zone, Area, Parish</code> (one church per row; Parish optional). Use the <strong>⬇ Download sample CSV</strong> template so you get the exact format, then fill it in. Names are stored in <strong>CAPS</strong> and existing units are matched automatically, so there are no duplicates.</li>
      <li><strong>🏷 Name Corrections</strong> — review church name corrections flagged from the registration page. <em>Approve</em> automatically renames the church to the suggested spelling; <em>Reject</em> makes no changes.</li>
    </ul>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="registrations">Registrations (<code>/admin/registrations</code>) <span class="pill super">SUPER</span></h2>
    <p>Churches register their admin at the public <strong>/register</strong> page. They pick their <strong>Province → Zone → Area</strong> from the church list, then <strong>type their Parish church name</strong> (auto-CAPS; existing parishes appear as suggestions as they type).</p>
    <p>Here you simply <strong>review → edit if needed → approve</strong>. Approving creates the admin account (a brand-new parish name is created under the chosen Area automatically) and emails the applicant. Rejecting records an optional reason and emails them. Every step is safe — no data is ever deleted.</p>
    <p><strong>Roles &amp; corporate email:</strong> each registrant picks their role (<em>Church Admin / Editor / Media Team</em>). As soon as a Zone/Area is picked or a Parish is typed, the form <strong>suggests two usernames</strong> from the church name + role (e.g. <code>SANCTUARY OF PRAISE</code> + admin → <code>sopadmin</code> / <code>sop.admin</code>). The chosen username doubles as the <strong>corporate email local-part</strong>. If <strong>Settings → Corporate Email (cPanel)</strong> is enabled, Approve automatically creates that mailbox (e.g. <code>sopadmin@domain</code>) using the password the registrant entered, and if they gave an <strong>alternative email</strong>, it is added as a forwarder.</p>
    <p><strong>Instant password strength:</strong> the register page measures strength on every keystroke using <strong>cPanel's 0–100 scale</strong> — <span style="color:#ff6b6b;">red = weak (&lt;65)</span>, <span style="color:#e8b95f;">amber = fair (65–79)</span>, <span style="color:#5fe0a4;">green = strong (80+)</span> — and suggests a stronger password based on what was typed. Weak passwords are <strong>blocked at submission</strong> (cPanel minimum strength <strong>65</strong>), so cPanel email creation never fails later. If the password is the only problem, the page <strong>keeps every other field</strong>, auto-scrolls to and highlights the password section, and tells them why — they only fix that one part. After submitting, applicants see a <strong>“Submission received”</strong> screen with a <strong>WhatsApp</strong> link (from Settings → Contact Phone) for instant review &amp; approval. Use <strong>Settings → 🔌 Test cPanel connection</strong> to verify your API token, and <strong>✉ Create email</strong> on an approved registration to retry a failed mailbox.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="security">Security (<code>/admin/security</code>) <span class="pill role">ADMIN</span></h2>
    <p>Review login attempts and security events, and block suspicious IPs. Helps you keep your church's admin area safe.</p>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 id="settings">Settings (<code>/admin/settings</code>) <span class="pill super">SUPER</span></h2>
    <p>Site-wide configuration — name, tagline, hero content, contact details, social links, live stream link, giving URL, footer &amp; SEO, Bible source, and <strong>Email (SMTP)</strong> settings used for all outgoing mail (newsletters, notifications, security alerts).</p>
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
