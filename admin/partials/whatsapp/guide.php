<?php
declare(strict_types=1);

/**
 * WhatsApp → Guide.
 *
 * Written for whoever inherits this, not for the person who built it. The two things that
 * surprise people are the 24-hour window and the difference between the official API and the
 * bridge, so those get the most space.
 */
?>

<div class="card">
  <h2 style="margin-top:0;">What this does</h2>
  <p style="color:var(--ink-dim);font-size:14px;line-height:1.75;">
    It connects the church's WhatsApp number to the website, using Meta's official
    <strong>WhatsApp Cloud API</strong> — the same mechanism businesses use for order updates and
    appointment reminders. Two things become possible that were not before: the church can send
    approved messages to a group of people, and messages people send to the church arrive in the
    <strong>Inbox</strong> where somebody can answer them.
  </p>
</div>

<div class="card">
  <h2 style="margin-top:0;">The 24-hour rule — the one thing to understand</h2>
  <p style="color:var(--ink-dim);font-size:14px;line-height:1.75;">
    WhatsApp's rule, not ours: <strong>a free-form reply is only allowed within 24 hours of the
    person's last message to you.</strong>
  </p>
  <p style="color:var(--ink-dim);font-size:14px;line-height:1.75;">
    Inside that window you can write anything, in the Inbox, like a normal chat. Outside it, the
    only thing that may be sent is a <strong>message template</strong> that Meta has already
    approved — pre-written wording, with blanks for the specifics.
  </p>
  <p style="color:var(--ink-dim);font-size:14px;line-height:1.75;">
    The Inbox tells you which state each conversation is in, and changes the reply box to match.
    If it says the window has closed, typing a free reply would be refused by WhatsApp — so the
    screen does not offer one.
  </p>
  <p style="color:var(--ink-dim);font-size:14px;line-height:1.75;">
    The practical consequence: <strong>you cannot start a conversation with somebody who has not
    messaged you, except with a template.</strong> Invitations, announcements and reminders all
    have to be approved templates.
  </p>
</div>

<div class="card">
  <h2 style="margin-top:0;">Templates</h2>
  <p style="color:var(--ink-dim);font-size:14px;line-height:1.75;">
    A template is written once, submitted to Meta, and reviewed — usually within minutes to a day.
    It is sent by <strong>name and language</strong>; the wording lives at Meta, not on this site.
  </p>
  <p style="color:var(--ink-dim);font-size:14px;line-height:1.75;">
    The <strong>Templates</strong> tab is a local register of what has been approved. It does not
    submit anything — that happens in Meta's dashboard. Recording it here is what lets the Inbox
    offer it and lets the conversation show what was sent afterwards.
  </p>
  <table>
    <tr><th>Category</th><th>Use it for</th></tr>
    <tr><td><strong>UTILITY</strong></td><td>Confirmations, reminders, receipts — anything the person is expecting.</td></tr>
    <tr><td><strong>MARKETING</strong></td><td>Invitations, announcements, promotions. Harder to get approved and can be blocked by the recipient.</td></tr>
    <tr><td><strong>AUTHENTICATION</strong></td><td>One-time codes. Not used by this site.</td></tr>
  </table>
  <p style="color:var(--ink-dim);font-size:14px;line-height:1.75;">
    Use <code>{{1}}</code>, <code>{{2}}</code> and so on for the parts that change — a name, a date,
    a time. The Inbox asks for one value per line when it sends one.
  </p>
</div>

<div class="card">
  <h2 style="margin-top:0;">Setting it up</h2>
  <ol style="color:var(--ink-dim);font-size:14px;line-height:2;padding-left:22px;">
    <li>Create a <strong>Meta Business</strong> account and verify the business. This takes days, so start it early.</li>
    <li>Create a <strong>Meta app</strong> of type Business, and add the WhatsApp product to it.</li>
    <li>Register a phone number. It must not already be in use on a normal WhatsApp account — deregister it from the phone app first.</li>
    <li>Copy the <strong>Phone number ID</strong>, <strong>Business account ID</strong>, <strong>access token</strong> and <strong>app secret</strong> into <strong>Settings</strong> here.</li>
    <li>Create a <strong>System User</strong> token so it does not expire. A temporary token will stop working within a day.</li>
    <li>Type the webhook address from <strong>Settings</strong> into Meta → WhatsApp → Configuration, with the same verify token, and subscribe to <code>messages</code>.</li>
    <li>Send a test message <em>to</em> the church's number from a phone, and check it appears in the <strong>Inbox</strong>.</li>
    <li>Only then tick <strong>WhatsApp channel enabled</strong>.</li>
  </ol>
</div>

<div class="card">
  <h2 style="margin-top:0;">The unofficial bridge — read before switching it on</h2>
  <p style="color:var(--ink-dim);font-size:14px;line-height:1.75;">
    The official API cannot read the members of a WhatsApp group, and cannot post into one. That is
    a deliberate restriction. If that capability is wanted, it needs a separate small service using
    a non-official library.
  </p>
  <p style="color:var(--ink-dim);font-size:14px;line-height:1.75;">
    <strong>This violates WhatsApp's terms of service.</strong> The number can be banned
    permanently, with no appeal and no warning, and the bridge breaks whenever WhatsApp changes its
    internals. Because of that it is off by default and must obey three rules:
  </p>
  <ul style="color:var(--ink-dim);font-size:14px;line-height:2;padding-left:22px;">
    <li>It runs on a <strong>separate, disposable SIM</strong> — never the church's main number.</li>
    <li>It is used <strong>only</strong> for reading group participants and posting into groups. It must never message the congregation.</li>
    <li>It is bound to <code>127.0.0.1</code> and is never reachable from the internet.</li>
  </ul>
  <p style="color:var(--ink-dim);font-size:14px;line-height:1.75;">
    If that number is banned, the church must lose nothing. Numbers imported from a group start
    <strong>opted out</strong> and are not messaged until they opt in — a group list is people who
    joined a group, not people who agreed to receive messages.
  </p>
</div>

<div class="card">
  <h2 style="margin-top:0;">Privacy and consent</h2>
  <ul style="color:var(--ink-dim);font-size:14px;line-height:2;padding-left:22px;">
    <li>Somebody messaging the church is recorded as having opted in. That is the strongest consent there is.</li>
    <li>Anyone who opts out is not messaged, and the Inbox will not let you send to them. That is deliberate.</li>
    <li>Message history is kept for the retention period set under <strong>Settings</strong>.</li>
    <li>Access tokens and the app secret are stored encrypted and shown only masked. They are never displayed in full.</li>
  </ul>
</div>
