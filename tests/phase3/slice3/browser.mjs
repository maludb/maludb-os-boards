// Headless Chromium proof of slice 3 — live (docs/build-specs/live.md "Proof" 8–13): two and three people on one board through the front door
// ($BD_BASE — Apache carrying the deploy vhost's /live line), with the poll held off (poll_seconds 60) so what arrives arrives by the socket.
// Real input in the browsers; scripted sockets (Node's own WebSocket) to fill a room; the relay itself stopped and started again. Asserted on
// the island's own view (window.__boards — only tests/dev_router.php puts it on a page) and on the database. Under BD_APP=apache there is no
// test hook (that is the point): the same story is asserted on the page's DOM and the socket's frames.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync, spawn } from 'node:child_process';

const BASE = process.env.BD_BASE || 'http://127.0.0.1:9107';
const SHOTS = process.env.SHOTS || '/tmp/bd-shots-s3';
const KEY = process.env.ACTION_TOKEN_KEY;
const STATE = process.env.BD_DEV_STATE;
const seed = JSON.parse(fs.readFileSync(process.env.BD_S3_SEED, 'utf8'));
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const HOOK = process.env.BD_APP !== 'apache';
fs.mkdirSync(SHOTS, { recursive: true });
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.boards.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const ticket = (member, board, level) => {
  const body = `${member}.board:${board}.${level}.${Math.floor(Date.now() / 1000) + 60}.${crypto.randomBytes(8).toString('hex')}`;
  return body + '.' + crypto.createHmac('sha256', process.env.BOARDS_LIVE_KEY).update(body).digest('hex');
};
const pg = (sql) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-X', '-q', '-At', '-d', process.env.DB_NAME, '-c', sql]).toString().trim();
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const until = async (fn, ms = 5000, every = 25) => { const end = Date.now() + ms; while (Date.now() < end) { const v = await fn(); if (v) return v; await sleep(every); } return null; };
const allErrors = [];
const browser = await chromium.launch();
async function session(member, viewport) {
  const touch = viewport.width < 600;
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, isMobile: touch, hasTouch: touch });
  const page = await ctx.newPage();
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    if (m.text().includes('status of 429') && (m.location()?.url ?? '').endsWith('/thumbnail')) return;        // the preview's designed "not yet"
    if (/WebSocket connection to .* failed/.test(m.text()) && relayDown) return;                                // the relay stopped on purpose
    if (/ERR_NAME_NOT_RESOLVED/.test(m.text()) && member === 30) return;                                         // signed out: the launcher's test name
    allErrors.push(`${member}@${viewport.width}: ${m.text()}`);
  });
  page.on('pageerror', (e) => allErrors.push(`${member}@${viewport.width}: pageerror ${e.message}`));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page };
}
let relayDown = false;
const open = async (page, board) => {
  await page.goto(`${BASE}/boards/${board}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
  await page.waitForSelector('#board-canvas[data-live="live"]', { timeout: 10000 });
};
const settle = (page) => page.waitForFunction(() => !window.__boards.pending(), null, { timeout: 15000 });
const stageBox = (page) => page.locator('#board-stage').boundingBox();
const presence = (page) => page.evaluate(() => window.__boards.presence());
const db = (sql) => execFileSync('psql', ['-X', '-q', '-At', '-h', process.env.DB_HOST, '-p', process.env.DB_PORT, '-U', process.env.DB_USER, '-d', process.env.DB_NAME, '-c', sql],
  { env: { ...process.env, PGPASSWORD: process.env.DB_PASSWORD } }).toString().trim();
const relayPid = () => Number(fs.readFileSync(`${STATE}/relay.pid`, 'utf8'));
const stopRelay = async () => { relayDown = true; try { process.kill(relayPid(), 'SIGTERM'); } catch { /* gone */ } await sleep(300); };
const startRelay = async () => {
  const log = fs.openSync(`${STATE}/relay.log`, 'a');
  const child = spawn(`${process.cwd()}/mcp/venv/bin/python`, ['-I', `${process.cwd()}/live/relay.py`], { env: process.env, stdio: ['ignore', log, log], detached: true });
  child.unref();
  fs.writeFileSync(`${STATE}/relay.pid`, String(child.pid));
  await until(() => { try { execFileSync('bash', ['-c', '(exec 3<>/dev/tcp/127.0.0.1/9106)'], { stdio: 'ignore' }); return true; } catch { return false; } }, 10000, 100);
  relayDown = false;
};
const runs = (t) => [{ type: 'text', text: { content: t, link: null }, plain_text: t }];

pg('UPDATE bd_settings SET poll_seconds = 60');           // the poll held off: what arrives, arrives by the socket

if (!HOOK) {
  // ================================================================================================================================
  console.log('8–11. under the real vhost (no test hook): presence, a live commit, the relay down and back');
  const P = await session(26, { width: 1280, height: 800 });
  const B = await session(28, { width: 1280, height: 800 });
  await open(P.page, seed.browser);
  const frames = [];
  B.page.on('websocket', (ws) => ws.on('framereceived', (f) => { try { frames.push([Date.now(), JSON.parse(f.payload)]); } catch { /* not ours */ } }));
  await open(B.page, seed.browser);
  ok(await until(() => B.page.locator('#board-presence .board-avatar[data-member="26"]').count()), 'Bea sees Priya\'s avatar');
  const sb = await stageBox(P.page);
  await P.page.click('#board-tool-sticky');
  await P.page.mouse.click(sb.x + sb.width / 2, sb.y + sb.height / 2);
  const t0 = Date.now();
  await P.page.keyboard.type('Live through Apache');
  await P.page.mouse.click(sb.x + sb.width - 60, sb.y + 40);
  const got = await until(() => frames.find(([at, m]) => m.t === 'ops' && JSON.stringify(m.ops ?? []).includes('Live through Apache')), 5000);
  ok(!!got, `Priya's text reaches Bea's socket as a committed batch (${got ? got[0] - t0 : '—'} ms after typing began)`);
  await stopRelay();
  ok(!!(await until(() => B.page.isVisible('#board-live-banner'), 10000)) && await B.page.getAttribute('#board-canvas', 'data-live') === 'polling', 'the relay stopped: "Live updates paused", the canvas polls');
  await startRelay();
  ok(!!(await until(async () => (await B.page.getAttribute('#board-canvas', 'data-live')) === 'live', 20000, 200)) && !(await B.page.isVisible('#board-live-banner')), 'the relay back: live again without a reload, the banner gone');
  ok(allErrors.length === 0, 'no console error' + (allErrors.length ? ': ' + allErrors.slice(0, 4).join(' | ') : ''));
  pg('UPDATE bd_settings SET poll_seconds = 3');
  await browser.close();
  console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
  process.exit(failed ? 1 : 0);
}

// ================================================================================================================================
console.log('8. Priya and Bea, live, the poll held off');
const P = await session(26, { width: 1280, height: 800 });
const B = await session(28, { width: 1280, height: 800 });
await open(P.page, seed.browser);
await open(B.page, seed.browser);
ok(await P.page.evaluate(() => window.__boards.live()) === 'live' && await B.page.getAttribute('#board-canvas', 'data-poll-seconds') === '60', 'both canvases live (the poll set to 60 s)');
ok(!!(await until(() => B.page.locator('#board-presence .board-avatar[data-member="26"]').count())) && !!(await until(() => P.page.locator('#board-presence .board-avatar[data-member="28"]').count())),
  'each sees the other\'s avatar in the top bar');
const sid = crypto.randomUUID();
await P.page.evaluate(([id]) => window.__boards.commit([{ op: 'create', item: { id, type: 'sticky_note', parent_id: null, x: 0, y: 0, width: 200, height: 200, rotation: 0, z: 'a1',
  style: { fillColor: 'light_yellow' }, data: { content: [{ type: 'text', text: { content: 'Shared', link: null }, plain_text: 'Shared' }] }, start_item_id: null, end_item_id: null, locked: false, version: 1 } }]), [sid]);
await settle(P.page);
ok(!!(await until(() => B.page.evaluate((id) => window.__boards.snapshot().some((i) => i.id === id), sid), 1000)), 'Priya\'s new sticky on Bea\'s canvas within 1 s, by the socket');
for (const pg_ of [P.page, B.page]) await pg_.evaluate(() => window.__boards.setCamera(0, 0, 1));
let sb = await stageBox(P.page);
let t0 = Date.now();
await P.page.mouse.move(sb.x + 200, sb.y + 150);
await P.page.mouse.move(sb.x + 260, sb.y + 170, { steps: 4 });
const cur = await until(async () => { const p = (await presence(B.page)).peers.find((x) => x.member_id === 26 && x.cursor); return p && Math.abs(p.cursor.x - (260 - sb.width / 2)) < 3 ? p : null; }, 1000, 10);
ok(!!cur && Date.now() - t0 < 500 && cur.name === 'SMOKE Priya', `Bea sees Priya's cursor with her name within 500 ms of a move (${Date.now() - t0} ms)`);
const pc = await P.page.evaluate((id) => window.__boards.screenOf(id), sid);
await P.page.mouse.click(pc.x, pc.y);
ok(!!(await until(async () => ((await presence(B.page)).peers.find((x) => x.member_id === 26)?.selection ?? []).includes(sid), 1000)), 'her selection reaches Bea (outlined in her colour)');
await P.page.mouse.move(pc.x, pc.y);
await P.page.mouse.down();
await P.page.mouse.move(pc.x + 60, pc.y + 20, { steps: 5 });
await P.page.mouse.move(pc.x + 120, pc.y + 40, { steps: 5 });
const ghost = await until(async () => ((await presence(B.page)).peers.find((x) => x.member_id === 26)?.dragging ?? 0) > 0, 1000);
await B.page.screenshot({ path: `${SHOTS}/desktop-ghost.png` });
const before = (await B.page.evaluate((id) => window.__boards.snapshot().find((i) => i.id === id), sid)).x;
t0 = Date.now();
await P.page.mouse.up();
ok(!!ghost, 'the ghost of her drag shows on Bea\'s canvas while it lasts');
const moved = await until(() => B.page.evaluate(([id, x0]) => { const i = window.__boards.snapshot().find((it) => it.id === id); return i && i.x !== x0 ? i.x : null; }, [sid, before]), 1000, 10);
ok(moved !== null && Math.abs(moved - before - 120) < 2 && Date.now() - t0 < 1000, `the sticky's new place on Bea's canvas within 1 s of the drop (${Date.now() - t0} ms)`);
ok(!!(await until(async () => ((await presence(B.page)).peers.find((x) => x.member_id === 26)?.dragging ?? 1) === 0, 1000)), 'and the ghost is gone');

console.log('9. the soft lock');
const pc2 = await P.page.evaluate((id) => window.__boards.screenOf(id), sid);
await P.page.mouse.dblclick(pc2.x, pc2.y);
await P.page.waitForSelector('#board-text-editor');
await P.page.keyboard.type(' — Priya typing');
ok(!!(await until(async () => (await presence(B.page)).peers.find((x) => x.member_id === 26)?.editing === sid, 1000)), 'Bea\'s canvas knows Priya is editing the sticky ("Priya is editing" on it)');
await B.page.screenshot({ path: `${SHOTS}/desktop-soft-lock.png` });
const bc = await B.page.evaluate((id) => window.__boards.screenOf(id), sid);
await B.page.mouse.dblclick(bc.x, bc.y);
ok(await B.page.isVisible('#board-text-editor') && (await B.page.locator('#board-toast-soft-lock').innerText().catch(() => '')).includes('Priya is editing this'),
  'Bea opens it anyway: the editor opens and warns her (the soft lock — last writer wins)');
await B.page.keyboard.press('Escape');
await P.page.keyboard.press('Escape');
await settle(P.page);
ok(!!(await until(async () => (await presence(B.page)).peers.find((x) => x.member_id === 26)?.editing === null, 1000)), 'Priya closes her editor: the lock is gone');

console.log('10. an agent works on the board');
const RUN = 9301;
const ks = JSON.parse(fs.readFileSync(process.env.FAKE_KERNEL_STATE, 'utf8'));
ks.facts = { ...(ks.facts ?? {}), [RUN]: { valid: true, is_agent: true, member_id: 40, run_id: RUN, request_id: 'r-' + RUN, endpoints: [{ id: 1, application: 'boards' }] } };
fs.writeFileSync(process.env.FAKE_KERNEL_STATE, JSON.stringify(ks));
const rp = `40.${Math.floor(Date.now() / 1000) + 300}.${RUN}`;
const runTok = rp + '.' + crypto.createHmac('sha256', KEY).update('run:' + rp).digest('hex');
const res = await fetch(`${BASE}/items/create.php`, { method: 'POST', headers: { Accept: 'application/json', 'X-Action-Token': runTok,
  'X-Action-Relay': crypto.createHmac('sha256', process.env.ACTIONS_RELAY_KEY).update(runTok).digest('hex'), 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams([['board', seed.browser], ['type', 'sticky_note'], ['texts[]', 'Agent one'], ['texts[]', 'Agent two'], ['texts[]', 'Agent three'], ['x', '700'], ['y', '100']]) });
const aj = await res.json();
ok(res.status === 200 && aj.items?.length === 3, 'the Expert\'s item_create_batch (an action token, JSON): three stickies');
const seen = await Promise.all([P.page, B.page].map((pg_) => until(() => pg_.evaluate((ids) => ids.every((id) => window.__boards.snapshot().some((i) => i.id === id)), aj.items), 1500)));
const ag = await until(async () => (await presence(B.page)).agents.find((a) => a.member_id === 40), 1500);
const box = JSON.parse(db(`SELECT summary->'bbox' FROM board_ops WHERE seq = ${Number(aj.record_id)}`));
const [bx, by] = [(box[0] + box[2]) / 2, (box[1] + box[3]) / 2];
ok(seen.every(Boolean) && !!ag && ag.name === 'SMOKE Expert' && Math.abs(ag.x - bx) < 1 && Math.abs(ag.y - by) < 1,
  `both canvases show the stickies, and the agent's avatar and cursor at the batch's box (${ag ? Math.round(ag.x) + ', ' + Math.round(ag.y) : '—'})`);
ok(!!(await until(() => B.page.locator('#board-presence .board-avatar.agent[data-member="40"]').count(), 1000)), 'the agent in the top bar too, marked AI');
await B.page.screenshot({ path: `${SHOTS}/desktop-agent.png` });
ok(!!(await until(async () => !(await presence(B.page)).agents.some((a) => a.member_id === 40), 7000, 200)), 'gone again after five seconds');

console.log('11. the relay down and back');
pg('UPDATE bd_settings SET poll_seconds = 3');
await open(P.page, seed.browser);
await open(B.page, seed.browser);
const loadedAt = Number(db(`SELECT last_seq FROM boards WHERE id = '${seed.browser}'`));     // Bea's state holds everything up to here
await stopRelay();
t0 = Date.now();
const paused = await until(async () => (await P.page.isVisible('#board-live-banner')) && (await B.page.isVisible('#board-live-banner')), 10000, 100);
ok(!!paused && await B.page.evaluate(() => window.__boards.live()) === 'polling', `both show "Live updates paused" within 10 s (${Date.now() - t0} ms) and poll`);
const during = [];
for (let k = 0; k < 3; k++) {
  const id = crypto.randomUUID();
  during.push(id);
  await P.page.evaluate(([i, n]) => window.__boards.commit([{ op: 'create', item: { id: i, type: 'sticky_note', parent_id: null, x: -500 + n * 220, y: 600, width: 200, height: 200, rotation: 0, z: 'b' + n,
    style: { fillColor: 'pink' }, data: {}, start_item_id: null, end_item_id: null, locked: false, version: 1 } }]), [id, k]);
}
await settle(P.page);
ok(!!(await until(() => B.page.evaluate((ids) => ids.every((id) => window.__boards.snapshot().some((i) => i.id === id)), during), 5000, 100)), 'Priya keeps editing; Bea gets it by the poll');
await startRelay();
const back = await until(async () => (await B.page.evaluate(() => window.__boards.live())) === 'live' && (await P.page.evaluate(() => window.__boards.live())) === 'live', 20000, 200);
ok(!!back && !(await B.page.isVisible('#board-live-banner')), 'the relay back: both reconnect, the banner gone');
const after = await P.page.evaluate(() => { const id = crypto.randomUUID(); return window.__boards.commit([{ op: 'create', item: { id, type: 'sticky_note', parent_id: null, x: 400, y: 600,
  width: 200, height: 200, rotation: 0, z: 'b9', style: {}, data: {}, start_item_id: null, end_item_id: null, locked: false, version: 1 } }]).then((a) => a.seq); });
await B.page.evaluate((s) => window.__boards.waitFor(s), after);
const seqs = await B.page.evaluate(() => window.__boards.appliedSeqs());
const since = db(`SELECT string_agg(seq::text, ',' ORDER BY seq) FROM board_ops WHERE board_id = '${seed.browser}' AND seq > ${loadedAt}`).split(',').map(Number);
ok(new Set(seqs).size === seqs.length && since.length >= 4 && since.every((s) => seqs.includes(s)),
  `caught up: every one of the ${since.length} batches since Bea's page loaded applied once — none missed, none twice (appliedSeqs)`);

console.log('13. 375 × 740: a crowded room');
const M = await session(26, { width: 375, height: 740 });
const others = [1, 27, 28, 30, 31];
for (const m of others) await fetch(mint(m), { redirect: 'manual' });             // each one signed on (an open session for the relay's check)
const socks = others.map((m) => new WebSocket(`${BASE.replace('http', 'ws')}/live?ticket=${encodeURIComponent(ticket(m, seed.phone, 'view'))}&client=bot${m}`));
await until(() => socks.every((s) => s.readyState === 1), 5000);
const pinger = setInterval(() => socks.forEach((s) => s.readyState === 1 && s.send('{"t":"ping"}')), 3000);
await open(M.page, seed.phone);
const strip = await until(async () => { const t = await M.page.locator('#board-presence').innerText(); return /\+3/.test(t) ? t : null; }, 3000);
ok(!!strip && await M.page.locator('#board-presence .board-avatar:not(.more)').count() === 2, `five others here: the strip shows two and "+3"`);
ok(await M.page.evaluate(() => document.documentElement.scrollWidth - innerWidth) === 0, 'no sideways scroll');
await M.page.screenshot({ path: `${SHOTS}/phone-presence.png` });
clearInterval(pinger);
socks.forEach((s) => s.close());

console.log('12. signed out at the kernel');
const D = await session(30, { width: 1280, height: 800 });
await open(D.page, seed.browser);
ok(!!(await until(() => B.page.locator('#board-presence .board-avatar[data-member="30"]').count(), 2000)), 'Dana joins: Bea sees her');
// the redirect itself is the proof (a route does not catch the browser's follow-up of a server's redirect, so the launcher's name is never
// fetched here): the 4403 reloads the page, and the reload answers 302 to the launcher
let sentAt = 0;
D.page.on('response', (r) => { if (r.status() === 302 && (r.headers().location ?? '').startsWith('https://app.example.invalid/launcher')) sentAt = Date.now(); });
const code = execFileSync('php', ['tests/phase3/slice3/lib.php', 'signout', '30']).toString().trim();
t0 = Date.now();
await until(() => sentAt > 0, 9000, 50);
ok(code === '204' && sentAt > 0 && sentAt - t0 < 8000, `Dana signed out at the kernel (the sign-out notice): her socket closed at the next check, the page reloaded and was sent to the launcher (${sentAt ? sentAt - t0 : '—'} ms)`);
ok(!!(await until(async () => !(await B.page.locator('#board-presence .board-avatar[data-member="30"]').count()), 3000)), 'and she leaves Bea\'s presence strip');

ok(allErrors.length === 0, 'no console error at 375 or 1280' + (allErrors.length ? ': ' + allErrors.slice(0, 5).join(' | ') : ''));
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
