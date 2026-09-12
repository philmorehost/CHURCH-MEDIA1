#!/usr/bin/env node
/**
 * WhatsApp bridge — a localhost-only sidecar for the Church Media admin.
 *
 * WHY THIS EXISTS
 * The official WhatsApp Cloud API cannot list the members of a group and cannot post into one.
 * That is a deliberate restriction on Meta's side, so the only way to do either is to speak to
 * WhatsApp the way the phone app and WhatsApp Web do, through an unofficial library (Baileys).
 *
 * THIS BREAKS WHATSAPP'S TERMS OF SERVICE.
 * A number that pairs here can be banned permanently, without warning and without appeal, and it
 * can happen at any message volume — the ban is triggered by the client, not by how much is sent.
 * Keeping volume low reduces the chance of *people* reporting the number, which is the other way
 * numbers die, but it does not make pairing safe. Treat the paired number as disposable: never the
 * church's published line, and never the same number as the official Cloud API one.
 *
 * The rate limits below are not there to make it safe. They are there so that a bug, a retry loop
 * or an impatient admin cannot turn this into a bulk sender by accident.
 *
 * DESIGN RULES (all three are load-bearing)
 *   1. Bind to loopback. A shared token over plain HTTP is only private because the socket never
 *      leaves the machine.
 *   2. Refuse to run without a token. An unauthenticated bridge is a remote-control for an
 *      arbitrary WhatsApp account.
 *   3. Read group members and post into groups. Nothing else. In particular this must never be
 *      used to message the congregation one-to-one — that is what the official channel is for.
 *
 * Run with WA_BRIDGE_DRY=1 to start without pairing or even installing the WhatsApp library. That
 * mode answers every endpoint with canned data, which is how the PHP side and the admin screens
 * get tested without putting a real number at risk. /health reports the mode so nothing can quietly
 * believe it is live.
 */

import http from 'node:http';
import crypto from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));

const CFG = {
  host: process.env.WA_BRIDGE_HOST || '127.0.0.1',
  port: Number.parseInt(process.env.WA_BRIDGE_PORT || '8787', 10),
  token: process.env.WA_BRIDGE_TOKEN || '',
  // Paired-session material lives under the app's storage directory, not beside this file.
  // On shared hosting the repository root often *is* the document root, so a credentials folder
  // here would be fetchable at /bridge/auth/creds.json. Anything holding a live session is a
  // login for that number, so it belongs somewhere with a deny rule in front of it.
  authDir: process.env.WA_BRIDGE_AUTH_DIR || path.join(HERE, '..', 'storage', 'wa-bridge'),
  dry: process.env.WA_BRIDGE_DRY === '1',
  allowRemote: process.env.WA_BRIDGE_ALLOW_REMOTE === '1',
  // Minimum gap between two sends, and a hard ceiling per rolling hour.
  minGapMs: Number.parseInt(process.env.WA_BRIDGE_MIN_GAP_MS || '5000', 10),
  maxPerHour: Number.parseInt(process.env.WA_BRIDGE_MAX_PER_HOUR || '30', 10),
  showQr: process.argv.includes('--show-qr'),
  maxBodyBytes: 64 * 1024,
};

/* ------------------------------------------------------------------ *
 * Fail-closed start-up checks
 * ------------------------------------------------------------------ */

function isLoopback(host) {
  return host === '127.0.0.1' || host === '::1' || host === 'localhost';
}

if (!CFG.token || CFG.token.length < 16) {
  console.error('WA_BRIDGE_TOKEN is missing or shorter than 16 characters.');
  console.error('This service can send WhatsApp messages. Refusing to run without a token.');
  console.error('Generate one with:  node -e "console.log(require(\'crypto\').randomBytes(32).toString(\'hex\'))"');
  process.exit(1);
}

if (!isLoopback(CFG.host) && !CFG.allowRemote) {
  console.error(`Refusing to bind to ${CFG.host}: the bridge may only listen on loopback.`);
  console.error('Set WA_BRIDGE_ALLOW_REMOTE=1 to override, and make sure the port is firewalled.');
  process.exit(1);
}
if (!isLoopback(CFG.host)) {
  console.warn(`WARNING: binding to ${CFG.host}, not loopback. The token will cross the network in cleartext.`);
}

/* ------------------------------------------------------------------ *
 * Rate limiting — a second line of defence behind the PHP-side cap
 * ------------------------------------------------------------------ */

const sendLog = [];

function checkRateLimit() {
  const now = Date.now();
  while (sendLog.length > 0 && now - sendLog[0] > 3_600_000) {
    sendLog.shift();
  }
  if (sendLog.length >= CFG.maxPerHour) {
    const waitMs = 3_600_000 - (now - sendLog[0]);
    return { ok: false, status: 429, waitSec: Math.ceil(waitMs / 1000), why: `hourly ceiling of ${CFG.maxPerHour} sends reached` };
  }
  const last = sendLog[sendLog.length - 1];
  if (last && now - last < CFG.minGapMs) {
    return { ok: false, status: 429, waitSec: Math.ceil((CFG.minGapMs - (now - last)) / 1000), why: `sends must be ${CFG.minGapMs}ms apart` };
  }
  return { ok: true };
}

/* ------------------------------------------------------------------ *
 * State shared with the HTTP layer
 * ------------------------------------------------------------------ */

const state = {
  connected: false,
  paired: false,
  number: null,
  qr: null,
  lastError: null,
  startedAt: new Date().toISOString(),
  connectedAt: null,
};

/* ------------------------------------------------------------------ *
 * WhatsApp adapter — the only part that talks to the library
 * ------------------------------------------------------------------ */

let adapter;

function dryAdapter() {
  const groups = [
    { jid: '120363000000000001@g.us', name: 'Dry-run Youth Group', participants: 3 },
    { jid: '120363000000000002@g.us', name: 'Dry-run Choir', participants: 2 },
  ];
  const members = {
    '120363000000000001@g.us': [
      { jid: '2348000000001@s.whatsapp.net', number: '2348000000001', admin: 'superadmin' },
      { jid: '2348000000002@s.whatsapp.net', number: '2348000000002', admin: null },
      { jid: '2348000000003@s.whatsapp.net', number: '2348000000003', admin: null },
    ],
    '120363000000000002@g.us': [
      { jid: '2348000000004@s.whatsapp.net', number: '2348000000004', admin: 'admin' },
      { jid: '2348000000005@s.whatsapp.net', number: '2348000000005', admin: null },
    ],
  };

  state.connected = true;
  state.paired = true;
  state.number = '2348000000000';
  state.connectedAt = new Date().toISOString();

  return {
    mode: 'dry',
    async listGroups() {
      return groups;
    },
    async listMembers(jid) {
      return members[jid] || [];
    },
    async sendToGroup(jid, text) {
      const group = groups.find((g) => g.jid === jid);
      if (!group) {
        throw new Error(`No such group: ${jid}`);
      }
      console.log(`[dry] would send ${text.length} chars to "${group.name}"`);
      return { id: `DRY-${crypto.randomUUID()}` };
    },
    async shutdown() {},
  };
}

async function baileysAdapter() {
  let lib;
  try {
    lib = await import('@whiskeysockets/baileys');
  } catch (err) {
    console.error('Could not load @whiskeysockets/baileys.');
    console.error('Run "npm install" inside bridge/ first. Underlying error: ' + err.message);
    process.exit(1);
  }

  const makeWASocket = lib.default;
  const { useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, Browsers } = lib;

  let pino;
  try {
    pino = (await import('pino')).default;
  } catch {
    // A logger is optional; silence is better than refusing to start over it.
    pino = () => ({ level: 'silent', child: () => ({ level: 'silent', trace() {}, debug() {}, info() {}, warn() {}, error() {}, fatal() {} }) });
  }

  const { state: authState, saveCreds } = await useMultiFileAuthState(CFG.authDir);
  const { version } = await fetchLatestBaileysVersion();

  let sock = null;
  let closedByUs = false;

  async function connect() {
    sock = makeWASocket({
      version,
      auth: authState,
      browser: Browsers.ubuntu('Chrome'),
      // The library is chatty at trace level and the log would dwarf everything else.
      logger: typeof pino === 'function' ? pino({ level: 'silent' }) : pino({ level: 'silent' }),
      markOnlineOnConnect: false,
      syncFullHistory: false,
    });

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', (update) => {
      const { connection, lastDisconnect, qr } = update;

      if (qr) {
        state.qr = qr;
        state.paired = false;
        console.log('Not paired yet. Scan the QR code with the disposable phone:');
        import('qrcode-terminal')
          .then((mod) => (mod.default || mod).generate(qr, { small: true }))
          .catch(() => console.log('(install qrcode-terminal to see the code rendered here)'));
      }

      if (connection === 'open') {
        state.connected = true;
        state.paired = true;
        state.qr = null;
        state.lastError = null;
        state.connectedAt = new Date().toISOString();
        const id = sock.user?.id || '';
        state.number = id ? String(id).split(':')[0].split('@')[0] : null;
        console.log(`Connected as ${state.number || 'unknown number'}`);
      }

      if (connection === 'close') {
        state.connected = false;
        const code = lastDisconnect?.error?.output?.statusCode;
        const loggedOut = code === DisconnectReason?.loggedOut;

        if (loggedOut) {
          // The session was revoked from the phone. Re-pairing is the only cure, and it needs a
          // human with the handset, so this is not something to retry in a loop.
          state.paired = false;
          state.lastError = 'The linked device was removed. Re-pair with --show-qr.';
          console.error(state.lastError);
          return;
        }

        state.lastError = `Connection closed (code ${code ?? 'unknown'}); reconnecting.`;
        console.warn(state.lastError);
        if (!closedByUs) {
          setTimeout(() => connect().catch((e) => console.error('Reconnect failed: ' + e.message)), 5000);
        }
      }
    });

    return sock;
  }

  await connect();

  return {
    mode: 'live',
    async listGroups() {
      const all = await sock.groupFetchAllParticipating();
      return Object.values(all || {})
        .map((g) => ({
          jid: g.id,
          name: g.subject || '',
          participants: (g.participants || []).length,
        }))
        .sort((a, b) => a.name.localeCompare(b.name));
    },
    async listMembers(jid) {
      const meta = await sock.groupMetadata(jid);
      return (meta.participants || []).map((p) => ({
        jid: p.id,
        // A participant id can carry a ":device" suffix; the phone number is what comes before it.
        number: String(p.id).split('@')[0].split(':')[0],
        admin: p.admin === 'admin' || p.admin === 'superadmin' ? p.admin : null,
      }));
    },
    async sendToGroup(jid, text) {
      const sent = await sock.sendMessage(jid, { text });
      return { id: sent?.key?.id || null };
    },
    async shutdown() {
      closedByUs = true;
      try {
        await sock?.end(undefined);
      } catch {
        /* shutting down anyway */
      }
    },
  };
}

/* ------------------------------------------------------------------ *
 * HTTP
 * ------------------------------------------------------------------ */

function sendJson(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
    'Cache-Control': 'no-store',
  });
  res.end(body);
}

/** Constant-time compare, so the token cannot be walked out one character at a time. */
function authorised(req) {
  const header = String(req.headers.authorization || '');
  if (!header.startsWith('Bearer ')) {
    return false;
  }
  const given = Buffer.from(header.slice(7));
  const want = Buffer.from(CFG.token);
  return given.length === want.length && crypto.timingSafeEqual(given, want);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > CFG.maxBodyBytes) {
        reject(new Error('Request body too large.'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

async function route(req, res, url) {
  if (url.pathname === '/health') {
    // Liveness is open, so a monitor needs no secret to tell the process is up. Nothing beyond
    // that is: the paired number identifies the account, and the error history describes the
    // session, so both wait for the token. On shared hosting another tenant's process can reach
    // loopback, which is exactly the caller this is keeping the details away from.
    const payload = {
      ok: true,
      service: 'churchmedia-wa-bridge',
    };

    if (authorised(req)) {
      Object.assign(payload, {
        mode: adapter ? adapter.mode : 'starting',
        connected: state.connected,
        paired: state.paired,
        number: state.number,
        last_error: state.lastError,
        started_at: state.startedAt,
        connected_at: state.connectedAt,
        limits: { min_gap_ms: CFG.minGapMs, max_per_hour: CFG.maxPerHour },
      });
    }

    return sendJson(res, 200, payload);
  }

  if (!adapter) {
    return sendJson(res, 503, { ok: false, error: 'The bridge is still starting. Try again in a moment.' });
  }

  if (url.pathname === '/qr') {
    return sendJson(res, 200, { ok: true, qr: state.qr, paired: state.paired, number: state.number });
  }

  if (url.pathname === '/groups') {
    const groups = await adapter.listGroups();
    return sendJson(res, 200, { ok: true, groups });
  }

  const membersMatch = url.pathname.match(/^\/groups\/([^/]+)\/members$/);
  if (membersMatch) {
    const jid = decodeURIComponent(membersMatch[1]);
    if (!jid.endsWith('@g.us')) {
      return sendJson(res, 400, { ok: false, error: 'That is not a group id.' });
    }
    const members = await adapter.listMembers(jid);
    return sendJson(res, 200, { ok: true, jid, members });
  }

  if (url.pathname === '/send-group' && req.method === 'POST') {
    let payload;
    try {
      payload = JSON.parse(await readBody(req));
    } catch (err) {
      return sendJson(res, 400, { ok: false, error: 'Body must be JSON: ' + err.message });
    }

    // Validation runs before the rate limiter on purpose. A rejected payload never reaches
    // WhatsApp, so making the caller wait five seconds to be told its group id was malformed
    // would be noise. The limiter guards the send, not the request.
    const jid = String(payload?.jid || '');
    const text = String(payload?.text || '');
    if (!jid.endsWith('@g.us')) {
      return sendJson(res, 400, { ok: false, error: 'jid must be a group id ending in @g.us.' });
    }
    if (text.trim() === '') {
      return sendJson(res, 400, { ok: false, error: 'text is empty.' });
    }
    if (text.length > 4096) {
      return sendJson(res, 400, { ok: false, error: 'text is longer than 4096 characters.' });
    }
    if (!state.connected) {
      return sendJson(res, 503, { ok: false, error: 'The bridge is not connected to WhatsApp.' });
    }

    const limit = checkRateLimit();
    if (!limit.ok) {
      // Deliberately refused rather than queued: the caller should back off, not pile up.
      return sendJson(res, limit.status, {
        ok: false,
        error: `Refused by the bridge rate limit: ${limit.why}.`,
        retry_after_seconds: limit.waitSec,
      });
    }

    try {
      const result = await adapter.sendToGroup(jid, text);
      sendLog.push(Date.now());
      // The message body is deliberately not logged. Group content is other people's.
      console.log(`sent ${text.length} chars to ${jid}`);
      return sendJson(res, 200, { ok: true, id: result.id });
    } catch (err) {
      console.error('send-group failed: ' + err.message);
      return sendJson(res, 502, { ok: false, error: err.message });
    }
  }

  return sendJson(res, 404, { ok: false, error: 'Unknown endpoint.' });
}

const server = http.createServer((req, res) => {
  let url;
  try {
    url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  } catch {
    return sendJson(res, 400, { ok: false, error: 'Bad request URI.' });
  }

  // /health is open so a monitor can tell the process is alive. Everything else needs the token,
  // and the check happens before routing so a typo cannot expose a new endpoint by accident.
  if (url.pathname !== '/health' && !authorised(req)) {
    return sendJson(res, 401, { ok: false, error: 'Unauthorised.' });
  }

  route(req, res, url).catch((err) => {
    console.error('Unhandled: ' + (err && err.stack ? err.stack : err));
    sendJson(res, 500, { ok: false, error: 'Internal bridge error.' });
  });
});

server.listen(CFG.port, CFG.host, async () => {
  console.log(`WhatsApp bridge listening on http://${CFG.host}:${CFG.port}`);
  console.log(`Rate limits: ${CFG.minGapMs}ms between sends, ${CFG.maxPerHour} per hour.`);
  if (CFG.dry) {
    console.warn('DRY RUN: no WhatsApp library loaded, no number paired, nothing will be sent.');
  }
  try {
    adapter = CFG.dry ? dryAdapter() : await baileysAdapter();
    console.log(`Bridge ready in ${adapter.mode} mode.`);
  } catch (err) {
    console.error('Bridge could not start: ' + (err && err.message ? err.message : err));
    process.exit(1);
  }
});

async function shutdown(signal) {
  console.log(`${signal} received, shutting down.`);
  server.close();
  if (adapter) {
    await adapter.shutdown();
  }
  process.exit(0);
}

process.on('SIGINT', () => shutdown('SIGINT'));
process.on('SIGTERM', () => shutdown('SIGTERM'));
