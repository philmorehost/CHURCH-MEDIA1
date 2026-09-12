# WhatsApp bridge

A localhost-only Node service that pairs a WhatsApp number the way WhatsApp Web does, so the admin
can read the members of a group and post into one.

**Read this before switching it on.**

---

## The risk, plainly

The official WhatsApp Cloud API cannot list group members and cannot post into a group. That is a
deliberate restriction. The only way around it is to speak to WhatsApp through an unofficial
library, and **that breaks WhatsApp's terms of service.**

What that means in practice:

- The paired number can be banned permanently, with no warning and no appeal.
- It can happen at **any** volume. Low volume reduces the chance of a *person* reporting the
  number — which is the other way numbers die — but it does not make pairing safe. The ban is
  triggered by the client, not by how much is sent.
- It can break whenever Meta changes its internals. Expect to update the library and occasionally
  re-pair.

So: **use a disposable number.** Never the church's published line, and never the same number as
the one on the official Cloud API channel. If that number is lost, the church should lose nothing.

## The three rules the code enforces

1. **Loopback only.** The service refuses to bind to anything but `127.0.0.1` / `::1` unless you
   explicitly override it. The shared token travels in a plain HTTP header, which is only private
   because the socket never leaves the machine.
2. **No token, no start.** It exits rather than run unauthenticated. An open bridge is a remote
   control for an arbitrary WhatsApp account.
3. **Groups only.** Reading members and posting to groups. The PHP side refuses a non-group id.

There are three more rules the code cannot enforce, which are yours:

- Do not use this to message the congregation one-to-one. That is what the official channel is for.
- Do not import a group and then start messaging everyone on it. Numbers imported from a group
  arrive **opted out** and stay that way until each person opts in.
- Do not raise the rate limits to get a broadcast out. They are there so a retry loop cannot turn
  this into a bulk sender by accident.

---

## Before you pair anything: dry run

The service starts without the WhatsApp library installed and without a number. In dry mode every
endpoint answers with canned data, so the PHP side and the admin screen can be tested end to end
without putting a real number at risk.

```bash
cd bridge
WA_BRIDGE_TOKEN="$(node -e 'console.log(require("crypto").randomBytes(32).toString("hex"))')" \
WA_BRIDGE_DRY=1 \
node server.js
```

Then paste the same token into **WhatsApp → Settings → Unofficial bridge** in the admin, set the
address to `http://127.0.0.1:8787`, and tick the box. `/health` reports `"mode": "dry"` so nothing
can quietly believe it is live, and the admin shows a warning to the same effect.

## Pairing for real

```bash
cd bridge
npm install                     # see the note below if the Baileys version is rejected
WA_BRIDGE_TOKEN="<the same token as in the admin>" node server.js --show-qr
```

Scan the printed QR code from the **disposable** phone: WhatsApp → Settings → Linked devices →
Link a device. The session is written to `storage/wa-bridge/` and survives restarts.

If you see `The linked device was removed`, the session was revoked from the phone. Re-pair with
`--show-qr`. This will happen occasionally; it is normal for an unofficial client.

### A note on the dependency version

`package.json` pins `@whiskeysockets/baileys` to a caret range that has not been verified against
npm from this machine. If `npm install` says the version does not exist, use:

```bash
npm install @whiskeysockets/baileys@latest pino qrcode-terminal
```

## Running it under a supervisor

It must stay running, and it must come back after a reboot. A systemd unit:

```ini
[Unit]
Description=Church Media WhatsApp bridge
After=network-online.target

[Service]
WorkingDirectory=/path/to/app/bridge
Environment=WA_BRIDGE_TOKEN=replace-me
Environment=WA_BRIDGE_MIN_GAP_MS=5000
Environment=WA_BRIDGE_MAX_PER_HOUR=30
ExecStart=/usr/bin/node server.js
Restart=always
RestartSec=10
User=www-data

[Install]
WantedBy=multi-user.target
```

## API

Every endpoint except `/health` needs `Authorization: Bearer <token>`.

| Endpoint | Does |
|---|---|
| `GET /health` | `{ok, service}`. With a token, adds mode, connection state, paired number and limits. |
| `GET /qr` | The pending pairing code, if any. Null once paired. |
| `GET /groups` | Every group the number is in, with participant counts. |
| `GET /groups/:jid/members` | Participants of one group. |
| `POST /send-group` | `{jid, text}`. Rate limited. |

`jid` is a group id ending in `@g.us`, e.g. `120363000000000001@g.us`.

### Rate limits

`WA_BRIDGE_MIN_GAP_MS` (default 5000) is the minimum gap between two sends, and
`WA_BRIDGE_MAX_PER_HOUR` (default 30) is a hard ceiling per rolling hour. Exceeding either returns
`429` with `retry_after_seconds`. Requests are refused rather than queued, so a caller cannot pile
up a backlog.

The PHP side has its own ceiling (`wa_daily_message_cap`). Both exist on purpose.

## Configuration

| Variable | Default | Notes |
|---|---|---|
| `WA_BRIDGE_TOKEN` | none | Required, 16+ characters. Must match the admin setting. |
| `WA_BRIDGE_HOST` | `127.0.0.1` | Refuses anything non-loopback without `WA_BRIDGE_ALLOW_REMOTE=1`. |
| `WA_BRIDGE_PORT` | `8787` | Must match the admin address. |
| `WA_BRIDGE_AUTH_DIR` | `../storage/wa-bridge` | Holds the paired session. See below. |
| `WA_BRIDGE_DRY` | off | Canned data, no library, nothing sent. |
| `WA_BRIDGE_MIN_GAP_MS` | `5000` | |
| `WA_BRIDGE_MAX_PER_HOUR` | `30` | |

## Where the session is kept

`storage/wa-bridge/` — deliberately not beside this file. On shared hosting the repository root is
often also the document root, so a credentials folder under `bridge/` would be fetchable at
`/bridge/auth/creds.json`. Those files are a live login for that number.

A `.htaccess` denying all access is committed in that directory, but **verify it works on your
host.** If Apache has `AllowOverride None`, the deny rule is ignored and the session is being served
over HTTP to anyone who asks. In that case move the bridge outside the document root and point
`WA_BRIDGE_AUTH_DIR` at the new location.

Check it yourself:

```bash
curl -i https://your-site/storage/wa-bridge/creds.json
```

A `403` or `404` is what you want. Anything else, stop and fix it.
