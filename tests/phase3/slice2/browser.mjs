// Headless Chromium proof of slice 2 — the canvas (docs/build-specs/canvas.md "Proof" 3, 4, 5, 6, 7, 8, 10; canvas-core.md "Proving the
// canvas"): REAL input — page.mouse down/move/up for drags and the Transformer's handles, the keyboard for text and shortcuts, CDP touch
// events for one and two fingers — asserted on the island's own store (window.__boards, which only tests/dev_router.php puts on the page)
// and, independently, on the database (psql as the application's role on the scratch database). Two members in two contexts: the poll
// carries one's change to the other. Playwright comes from the kernel's web/node_modules (read only). Run through tests/phase3/slice2/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9101';
const SHOTS = process.env.SHOTS || '/tmp/bd-shots-s2';
const KEY = process.env.ACTION_TOKEN_KEY;
const seed = JSON.parse(fs.readFileSync(process.env.BD_S2_SEED || '/tmp/bd-s2-seed.json', 'utf8'));
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
fs.mkdirSync(SHOTS, { recursive: true });
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.boards.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const db = (sql) => execFileSync('psql', ['-X', '-q', '-At', '-h', process.env.DB_HOST, '-p', process.env.DB_PORT, '-U', process.env.DB_USER, '-d', process.env.DB_NAME, '-c', sql],
  { env: { ...process.env, PGPASSWORD: process.env.DB_PASSWORD } }).toString().trim();
const dbNum = (sql) => Number(db(sql));
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const allErrors = [];
const browser = await chromium.launch();
async function session(member, viewport, opts = {}) {
  const touch = viewport.width < 600;
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: touch ? 2 : 1, isMobile: touch, hasTouch: touch, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    if (m.text().includes('status of 429') && (m.location()?.url ?? '').endsWith('/thumbnail')) return;      // the thumbnail's designed "not yet"
    errors.push(m.text()); allErrors.push(`${member}@${viewport.width}: ${m.text()}`);
  });
  page.on('pageerror', (e) => { errors.push('pageerror: ' + e.message); allErrors.push(`${member}@${viewport.width}: pageerror ${e.message}`); });
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const open = async (page, board, query = '') => {
  await page.goto(`${BASE}/boards/${board}${query}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
  await page.evaluate(() => window.__boards.ready());
};
const settle = async (page) => { await page.waitForFunction(() => !window.__boards.pending(), null, { timeout: 15000 }); await page.waitForTimeout(120); };
const snap = (page) => page.evaluate(() => window.__boards.snapshot());
const byText = (items, t) => items.find((i) => (i.data?.content?.[0]?.plain_text ?? '') === t);
const screenOf = (page, id) => page.evaluate((i) => window.__boards.screenOf(i), id);
const stageBox = (page) => page.locator('#board-stage').boundingBox();
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const drag = async (page, from, to, steps = 12) => {
  await page.mouse.move(from.x, from.y);
  await page.mouse.down();
  await page.mouse.move(to.x, to.y, { steps });
  await page.mouse.up();
};
/** A screen point over no item and clear of the bars: the top of the stage, right of the toolbar. */
const emptyPoint = async (page) => {
  const sb = await stageBox(page);
  return page.evaluate(({ sb }) => {
    const cam = window.__boards.camera();
    const items = window.__boards.snapshot();
    const W = sb.width, H = sb.height;
    for (let y = 30; y < H / 2; y += 25) for (let x = W - 60; x > 120; x -= 25) {
      const cx = cam.x + (x - W / 2) / cam.zoom, cy = cam.y + (y - H / 2) / cam.zoom;
      const hit = items.some((i) => { const r = Math.max(i.width, i.height) * 0.75; return Math.abs(cx - i.x) < r + 30 && Math.abs(cy - i.y) < r + 30; });
      if (!hit) return { x: sb.x + x, y: sb.y + y };
    }
    return { x: sb.x + W - 40, y: sb.y + 30 };
  }, { sb });
};
/** Select one item by a click, and wait for the rail's Item panel to show it (an older panel's buttons are never the ones clicked). */
const selectOnly = async (page, id) => {
  await page.mouse.click(...Object.values(await emptyPoint(page)));
  const at = await screenOf(page, id);
  await page.mouse.click(at.x, at.y);
  await page.waitForSelector(`#rail-item[data-ids="${id}"]`, { timeout: 5000 });
};
const ops = (sql) => db(`SELECT count(*) FROM board_ops WHERE board_id = '${seed.canvas}' ${sql}`);
const logs = (action) => dbNum(`SELECT count(*) FROM activity_log WHERE board_id = '${seed.canvas}' AND action = '${action}'`);
const item = (id) => { const r = db(`SELECT x, y, width, height, rotation, z, locked, style::text, plain_text FROM board_items WHERE id = '${id}'`).split('|'); return { x: +r[0], y: +r[1], width: +r[2], height: +r[3], rotation: +r[4], z: r[5], locked: r[6] === 't', style: JSON.parse(r[7] || '{}'), text: r[8] }; };
const runs = (t) => [{ type: 'text', text: { content: t, link: null }, plain_text: t }];

// ================================================================================================================================
// Under the real vhost (BD_APP=apache) there is no test hook — that is the point — so the proof drives the canvas by real input and asserts
// on the database alone: the bundle served and booted, a sticky drawn and typed, a drag, Ctrl+Z, a second member's view caught up by the poll.
if (process.env.BD_APP === 'apache') {
  console.log('3. under the real vhost (no test hook): draw, type, drag, undo, another member\'s poll');
  const P = await session(26, { width: 1280, height: 800 });
  await P.page.goto(`${BASE}/boards/${seed.canvas}`, { waitUntil: 'domcontentloaded' });
  await P.page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
  ok(await P.page.evaluate(() => typeof window.__boards === 'undefined') && !(await P.page.getAttribute('#board-canvas', 'data-test')), 'the island boots from the committed bundle; no test hook on the page');
  const sb = await stageBox(P.page);
  await P.page.click('#board-tool-sticky');
  await P.page.mouse.click(sb.x + sb.width / 2, sb.y + sb.height / 2);
  await P.page.waitForSelector('#board-text-editor', { timeout: 3000 });
  await P.page.keyboard.type('Through Apache');
  await P.page.mouse.click(sb.x + sb.width - 60, sb.y + 40);
  await P.page.waitForTimeout(1500);
  const id = db(`SELECT id FROM board_items WHERE board_id = '${seed.canvas}' AND plain_text = 'Through Apache' AND deleted_at IS NULL`);
  ok(id !== '' && logs('item.create') === 1 && logs('item.text') === 1, 'a sticky drawn and typed through the vhost: item.create and item.text');
  const before = item(id);
  await drag(P.page, { x: sb.x + sb.width / 2, y: sb.y + sb.height / 2 }, { x: sb.x + sb.width / 2 + 150, y: sb.y + sb.height / 2 + 50 });
  await P.page.waitForTimeout(1200);
  const moved = item(id);
  ok(Math.abs(moved.x - before.x - 150) < 2 && logs('item.move') === 1, 'a real drag: one item.move');
  await P.page.keyboard.press('Control+z');
  await P.page.waitForTimeout(1200);
  ok(item(id).x === before.x, 'Ctrl+Z undoes it');
  const B = await session(28, { width: 1280, height: 800 });
  await B.page.goto(`${BASE}/boards/${seed.canvas}`, { waitUntil: 'domcontentloaded' });
  await B.page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
  await P.page.click('#board-tool-sticky');
  await P.page.mouse.click(sb.x + 260, sb.y + 120);
  await P.page.keyboard.type('Seen by Bea');
  await P.page.mouse.click(sb.x + sb.width - 60, sb.y + 40);
  await P.page.waitForTimeout(1000);
  const t0 = Date.now();
  const got = await B.page.waitForResponse((r) => r.url().includes(`/boards/${seed.canvas}/ops?after=`) && r.status() === 200 && r.request().method() === 'GET', { timeout: 6000 })
    .then(async (r) => (await r.json()).batches.some((b) => b.ops.some((o) => o.op === 'update' && JSON.stringify(o).includes('Seen by Bea')))).catch(() => false);
  ok(got && Date.now() - t0 < 4000, `Bea's poll carries Priya's sticky through the vhost within poll_seconds (${Date.now() - t0} ms)`);
  ok(await overflow(P.page) === 0 && allErrors.length === 0, 'no console error, no sideways scroll' + (allErrors.length ? ': ' + allErrors.join(' | ') : ''));
  await browser.close();
  console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
  process.exit(failed ? 1 : 0);
}

console.log('3. Priya at 1280: draw, edit, move, resize, rotate, select, duplicate, copy, order, lock, undo, paste');
const P = await session(26, { width: 1280, height: 800 });
await open(P.page, seed.canvas);
ok(await P.page.evaluate(() => typeof window.__boards === 'object' && window.__boards.level() === 'full'), 'the canvas opens with the test hook (the dev router only) at full');
let sb = await stageBox(P.page);
await P.page.click('#board-tool-sticky');
await P.page.mouse.click(sb.x + sb.width / 2 - 150, sb.y + sb.height / 2);
await P.page.waitForSelector('#board-text-editor', { timeout: 3000 });
await P.page.keyboard.type('Hello canvas');
await P.page.mouse.click(...Object.values(await emptyPoint(P.page)));
await settle(P.page);
let items = await snap(P.page);
const s1 = byText(items, 'Hello canvas');
ok(!!s1 && s1.type === 'sticky_note' && s1.width === 200 && item(s1.id).text === 'Hello canvas', 'Sticky, a click → a sticky; typed in the overlay; a click away saves it (store and database)');
ok(logs('item.create') === 1 && logs('item.text') === 1 && await P.page.evaluate(() => document.querySelector('#board-tool-select').classList.contains('active')),
  'one item.create and one item.text — the tool back on Select');
await P.page.screenshot({ path: `${SHOTS}/desktop-sticky.png` });

// drag
let c = await screenOf(P.page, s1.id);
let moves = logs('item.move');
await drag(P.page, c, { x: c.x + 200, y: c.y + 100 });
await settle(P.page);
let r = item(s1.id);
ok(Math.abs(r.x - (s1.x + 200)) < 2 && Math.abs(r.y - (s1.y + 100)) < 2 && logs('item.move') === moves + 1, 'a real drag (mouse down, move, up) → ONE item.move batch on drop, the place in the database');
// resize with the Transformer's corner
c = await screenOf(P.page, s1.id);
ok(!!c.handles['bottom-right'] && !!c.handles.rotater && !c.handles['middle-right'], 'the Transformer on a sticky: corners and the rotater (its ratio kept)');
let updates = logs('item.update');
await drag(P.page, c.handles['bottom-right'], { x: c.handles['bottom-right'].x + 100, y: c.handles['bottom-right'].y + 100 });
await settle(P.page);
r = item(s1.id);
ok(r.width > 280 && r.width < 320 && Math.abs(r.width - r.height) < 1 && logs('item.update') === updates + 1, `resized by its corner handle to ${Math.round(r.width)} × ${Math.round(r.height)} — one batch on transform end`);
// rotate
c = await screenOf(P.page, s1.id);
await drag(P.page, c.handles.rotater, { x: c.x + 220, y: c.y + 4 }, 16);
await settle(P.page);
r = item(s1.id);
ok(Math.abs(Math.abs(r.rotation) - 90) < 6, `rotated with the rotater to ${Math.round(r.rotation)}°`);

// a second sticky (setup through the same path as a gesture), then shift-click and the marquee
const s2id = crypto.randomUUID();
await P.page.evaluate(([id, x, y]) => window.__boards.commit([{ op: 'create', item: { id, type: 'sticky_note', parent_id: null, x, y, width: 200, height: 200, rotation: 0, z: 'b00',
  style: { fillColor: 'light_blue' }, data: { content: [{ type: 'text', text: { content: 'Second', link: null }, plain_text: 'Second' }] }, start_item_id: null, end_item_id: null, locked: false, version: 1 } }]),
  [s2id, s1.x + 450, s1.y + 60]);
await settle(P.page);
await P.page.evaluate(([x, y]) => window.__boards.setCamera(x, y, 0.75), [s1.x + 425, s1.y + 130]);    // both in view
await P.page.mouse.click(...Object.values(await emptyPoint(P.page)));
c = await screenOf(P.page, s1.id);
let c2 = await screenOf(P.page, s2id);
await P.page.mouse.click(c.x, c.y);
await P.page.keyboard.down('Shift');
await P.page.mouse.click(c2.x, c2.y);
await P.page.keyboard.up('Shift');
ok((await P.page.evaluate(() => window.__boards.selection())).sort().join() === [s1.id, s2id].sort().join(), 'shift adds to the selection');
const e0 = await emptyPoint(P.page);
await P.page.mouse.click(e0.x, e0.y);
ok((await P.page.evaluate(() => window.__boards.selection())).length === 0, 'a click on the empty canvas clears it');
const left = Math.min(c.x - c.width / 2, c2.x - c2.width / 2) - 30, top = Math.min(c.y - c.height / 2, c2.y - c2.height / 2) - 30;
const right = Math.max(c.x + c.width / 2, c2.x + c2.width / 2) + 30, bottom = Math.max(c.y + c.height / 2, c2.y + c2.height / 2) + 30;
await drag(P.page, { x: left, y: top }, { x: right, y: bottom });
ok((await P.page.evaluate(() => window.__boards.selection())).sort().join() === [s1.id, s2id].sort().join(), 'a marquee around both selects both');
// Ctrl+D
let before = (await snap(P.page)).length;
let creates = logs('item.create');
await P.page.keyboard.press('Control+d');
await settle(P.page);
items = await snap(P.page);
const dups = items.filter((i) => i.id !== s1.id && i.id !== s2id && ['Hello canvas', 'Second'].includes(i.data?.content?.[0]?.plain_text));
ok(items.length === before + 2 && dups.length === 2 && dups.every((d) => { const o = d.data.content[0].plain_text === 'Second' ? item(s2id) : item(s1.id); return Math.abs(d.x - o.x - 24) < 0.01 && Math.abs(d.y - o.y - 24) < 0.01; })
  && logs('item.create') === creates + 1, 'Ctrl+D duplicates the selection, 24 further on, in ONE batch');
let deletes = logs('item.delete');
await P.page.keyboard.press('Delete');
await settle(P.page);
ok(dups.every((d) => !db(`SELECT 1 FROM board_items WHERE id = '${d.id}' AND deleted_at IS NULL`)) && logs('item.delete') === deletes + 1, 'Delete removes the selection (the copies): ONE item.delete batch');
// copy and paste
await selectOnly(P.page, s2id);
before = (await snap(P.page)).length;
await P.page.keyboard.press('Control+c');
await P.page.keyboard.press('Control+v');
await P.page.waitForTimeout(300);
await settle(P.page);
items = await snap(P.page);
const pasted = items.filter((i) => i.data?.content?.[0]?.plain_text === 'Second' && i.id !== s2id && Math.abs(i.x - item(s2id).x - 24) < 0.01 && Math.abs(i.y - item(s2id).y - 24) < 0.01);
ok(items.length === before + 1 && pasted.length >= 1, 'Ctrl+C, Ctrl+V: a copy 24 further on');
await P.page.keyboard.press('Backspace');
await settle(P.page);
ok(pasted.every((d) => !db(`SELECT 1 FROM board_items WHERE id = '${d.id}' AND deleted_at IS NULL`)), 'Backspace deletes too');
// front and back from the rail
await selectOnly(P.page, s1.id);
await P.page.click('#rail-front');
await settle(P.page);
items = await snap(P.page);
ok(items.every((i) => i.id === s1.id || i.z < items.find((x) => x.id === s1.id).z) && item(s1.id).z === items.find((x) => x.id === s1.id).z, 'Bring to front: its z after every other (byte order, in the store and the database)');
await P.page.waitForSelector(`#rail-item[data-ids="${s1.id}"] #rail-back`);
await P.page.click('#rail-back');
await settle(P.page);
items = await snap(P.page);
ok(items.every((i) => i.id === s1.id || i.z > items.find((x) => x.id === s1.id).z), 'Send to back: before every other');
// the rail's colour: one batch
let batches = Number(ops(''));
await P.page.waitForSelector('#rail-sticky-colors [data-value="pink"]');
await P.page.click('#rail-sticky-colors [data-value="pink"]');
await settle(P.page);
ok(item(s1.id).style.fillColor === 'pink' && Number(ops('')) === batches + 1, 'the rail\'s Properties: a colour is ONE batch through the island (proof 7)');
// lock
await P.page.waitForSelector('#rail-lock');
await P.page.click('#rail-lock');
await settle(P.page);
c = await screenOf(P.page, s1.id);
ok(item(s1.id).locked && Object.keys(c.handles).length === 0, 'Lock: locked in the database; the Transformer is gone');
const lockedAt = item(s1.id);
await drag(P.page, c, { x: c.x + 150, y: c.y + 150 });
await settle(P.page);
ok(item(s1.id).x === lockedAt.x && item(s1.id).y === lockedAt.y, 'a drag of a locked item does nothing');
await P.page.waitForSelector('#rail-unlock', { timeout: 5000 });
await P.page.click('#rail-unlock');
await settle(P.page);
ok(!item(s1.id).locked, 'Unlock from the rail');
// undo and redo my own change
c2 = await screenOf(P.page, s2id);
const at = item(s2id);
await drag(P.page, c2, { x: c2.x + 120, y: c2.y });
await settle(P.page);
const moved = item(s2id);
await P.page.keyboard.press('Control+z');
await settle(P.page);
const undone = item(s2id);
await P.page.keyboard.press('Control+Shift+z');
await settle(P.page);
const redone = item(s2id);
const zP = (await P.page.evaluate(() => window.__boards.camera())).zoom;
ok(Math.abs(moved.x - at.x - 120 / zP) < 2 && undone.x === at.x && Math.abs(redone.x - moved.x) < 0.01, 'Ctrl+Z undoes my move (a new batch: its inverse); Ctrl+Shift+Z redoes it');
await P.page.click('#board-undo');
await settle(P.page);
ok(item(s2id).x === at.x, 'the bottom bar\'s Undo too');
// pasting three lines of outside text: three stickies
const ep = await emptyPoint(P.page);
await P.page.mouse.move(ep.x, ep.y + 120);
before = (await snap(P.page)).length;
await P.page.evaluate(() => { const dt = new DataTransfer(); dt.setData('text/plain', 'Line one\nLine two\n\nLine three\n'); document.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true })); });
await settle(P.page);
items = await snap(P.page);
const lines = ['Line one', 'Line two', 'Line three'].map((t) => byText(items, t));
ok(items.length === before + 3 && lines.every(Boolean) && lines.every((l) => l.style.fillColor === 'light_yellow'), 'pasting three lines of text → three stickies (an empty line skipped), ONE batch');
await P.page.screenshot({ path: `${SHOTS}/desktop-after.png` });

// ================================================================================================================================
console.log('4. two editors: Priya and Bea');
const B = await session(28, { width: 1280, height: 800 });
await open(B.page, seed.canvas);
ok((await snap(B.page)).length === (await snap(P.page)).length, 'Bea opens the board: the same items');
await P.page.click('#board-tool-sticky');
sb = await stageBox(P.page);
const spot = await emptyPoint(P.page);
await P.page.mouse.click(spot.x, spot.y + 200);
await P.page.waitForSelector('#board-text-editor');
await P.page.keyboard.type('From Priya');
await P.page.mouse.click(...Object.values(await emptyPoint(P.page)));
await settle(P.page);
const pSeq = dbNum(`SELECT max(seq) FROM board_ops WHERE board_id = '${seed.canvas}'`);
let t0 = Date.now();
await B.page.evaluate((s) => window.__boards.waitFor(s), pSeq);
let dt = Date.now() - t0;
ok(dt <= 3500 && !!byText(await snap(B.page), 'From Priya'), `Bea's view shows Priya's sticky within poll_seconds + 0.5 s (${dt} ms)`);
// both change the same sticky at once: Priya its place, Bea its colour
const target = s2id;
const bt = await screenOf(B.page, target);
await B.page.mouse.click(bt.x, bt.y);
await B.page.waitForSelector('#rail-sticky-colors [data-value="green"]');
const pt = await screenOf(P.page, target);
const xBefore = item(target).x;
await Promise.all([
  drag(P.page, pt, { x: pt.x + 90, y: pt.y }),
  B.page.click('#rail-sticky-colors [data-value="green"]'),
]);
await Promise.all([settle(P.page), settle(B.page)]);
const last = dbNum(`SELECT max(seq) FROM board_ops WHERE board_id = '${seed.canvas}'`);
await Promise.all([P.page.evaluate((s) => window.__boards.waitFor(s), last), B.page.evaluate((s) => window.__boards.waitFor(s), last)]);
await Promise.all([P.page.waitForTimeout(3500), B.page.waitForTimeout(3500)]);
const inP = (await snap(P.page)).find((i) => i.id === target);
const inB = (await snap(B.page)).find((i) => i.id === target);
const inDb = item(target);
const zP2 = (await P.page.evaluate(() => window.__boards.camera())).zoom;
ok(Math.abs(inDb.x - xBefore - 90 / zP2) < 2 && inDb.style.fillColor === 'green', 'both changed the same sticky at once — its x and its colour: both survive in the database (last writer wins per property)');
ok(inP.x === inDb.x && inB.x === inDb.x && inP.style.fillColor === 'green' && inB.style.fillColor === 'green', 'and both views agree with it');
// Bea overwrites Priya's newer text
const tgt = s1.id;
const bc = await screenOf(B.page, tgt);
await B.page.mouse.dblclick(bc.x, bc.y);
await B.page.waitForSelector('#board-text-editor');
const v = (await snap(P.page)).find((i) => i.id === tgt).version;
await P.page.evaluate(([id, content, v]) => window.__boards.commit([{ op: 'update', id, fields: { data: { content } }, base_version: v }]), [tgt, runs('Priya newer'), v]);
await settle(P.page);
await B.page.keyboard.press('Control+a');
await B.page.keyboard.type('Bea wrote this');
await B.page.mouse.click(...Object.values(await emptyPoint(B.page)));
await settle(B.page);
await B.page.waitForSelector('#board-toast-replaced', { timeout: 5000 }).catch(() => null);
const toast = await B.page.locator('#board-toast-replaced').innerText().catch(() => '');
ok(toast.includes("You replaced SMOKE Priya's edit") && toast.includes('Undo') && item(tgt).text === 'Bea wrote this', `Bea overwrote Priya's newer text: "${toast.replace(/\s+/g, ' ').replace('×', '').trim()}"`);
await B.page.click('#board-toast-replaced .board-toast-action');
await settle(B.page);
ok(item(tgt).text === 'Priya newer', 'the toast\'s Undo puts Priya\'s text back');
await B.page.screenshot({ path: `${SHOTS}/desktop-bea.png` });

// ================================================================================================================================
console.log('5. a commenter, the outline, item=');
const opensBefore = dbNum(`SELECT count(*) FROM activity_log WHERE board_id = '${seed.canvas}' AND action = 'board.open' AND actor_member_id = 30`);
const D = await session(30, { width: 1280, height: 800 });
await open(D.page, seed.canvas);
ok(await D.page.evaluate(() => window.__boards.level()) === 'comment' && !(await D.page.isVisible('#board-tool-sticky')) && await D.page.isVisible('#board-tool-hand'),
  'Dana (a Viewer: comment) sees no drawing tools — Select and Hand only');
const topId = await D.page.evaluate(() => window.__boards.snapshot().sort((x, y) => (x.z < y.z ? 1 : -1))[0].id);    // nothing covers the highest
const dc = await screenOf(D.page, topId);
await D.page.mouse.click(dc.x, dc.y);
const dsel = await screenOf(D.page, topId);
ok((await D.page.evaluate(() => window.__boards.selection())).join() === topId && Object.keys(dsel.handles).length === 0, 'she selects an item — no Transformer');
const dAt = item(topId);
await drag(D.page, dc, { x: dc.x + 100, y: dc.y + 100 });
await D.page.mouse.dblclick(dc.x, dc.y);
await D.page.keyboard.press('Delete');
await D.page.waitForTimeout(400);
ok(!(await D.page.isVisible('#board-text-editor')) && item(topId).x === dAt.x && item(topId).text === dAt.text && !!db(`SELECT 1 FROM board_items WHERE id = '${topId}' AND deleted_at IS NULL`),
  'a drag, a double-click, Delete: nothing changes');
await D.page.waitForTimeout(3500);
ok(dbNum(`SELECT count(*) FROM activity_log WHERE board_id = '${seed.canvas}' AND action = 'board.open' AND actor_member_id = 30`) === opensBefore + 1,
  'board.open once per open — the poll is never logged (proof 7)');
const D375 = await session(30, { width: 375, height: 740 });
await D375.page.goto(`${BASE}/boards/${seed.canvas}/outline`, { waitUntil: 'networkidle' });
const lineCount = await D375.page.locator('#board-outline-body .outline-item').count();
ok(lineCount === dbNum(`SELECT count(*) FROM board_items WHERE board_id = '${seed.canvas}' AND deleted_at IS NULL`) && await overflow(D375.page) === 0, `the outline at 375: every item (${lineCount}), no sideways scroll`);
await D375.page.screenshot({ path: `${SHOTS}/phone-outline.png`, fullPage: true });
await open(D.page, seed.canvas, `?item=${s1.id}`);
const cam = await D.page.evaluate(() => window.__boards.camera());
const s1now = item(s1.id);
ok((await D.page.evaluate(() => window.__boards.selection())).join() === s1.id && Math.abs(cam.x - s1now.x) < 2 && Math.abs(cam.y - s1now.y) < 2, '?item= selects it and flies there');

// ================================================================================================================================
console.log('6. the thumbnail');
await open(P.page, seed.thumb);
const thumbWait = P.page.waitForResponse((res) => res.url().endsWith(`/boards/${seed.thumb}/thumbnail`) && res.request().method() === 'POST', { timeout: 20000 }).catch(() => null);
await P.page.click('#board-tool-sticky');
sb = await stageBox(P.page);
await P.page.mouse.click(sb.x + sb.width / 2, sb.y + sb.height / 2);
await P.page.keyboard.type('Preview me');
await P.page.mouse.click(...Object.values(await emptyPoint(P.page)));
const tres = await thumbWait;
ok(!!tres && tres.status() === 200 && !!db(`SELECT 1 FROM boards b JOIN attachments a ON a.id = b.thumbnail_attachment_id WHERE b.id = '${seed.thumb}' AND a.record_type = 'board_thumbnail'`),
  'the island posts a thumbnail after an edit (480 × 300 PNG), stored as the board\'s');
ok(await P.page.evaluate(() => window.__boards.thumbnail()) === 429, 'a second within five minutes: 429');

// ================================================================================================================================
console.log('8. 375 × 740 with touch');
const M = await session(26, { width: 375, height: 740 });
await open(M.page, seed.phone);
ok(await overflow(M.page) === 0, 'the canvas at 375: no sideways scroll');
const cdp = await M.ctx.newCDPSession(M.page);
const touch = async (type, points) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: points.map((p, k) => ({ x: p.x, y: p.y, id: k + 1 })) });
await M.page.tap('#board-tool-sticky');
sb = await stageBox(M.page);
await M.page.touchscreen.tap(sb.x + sb.width / 2, sb.y + sb.height / 2 - 40);
await M.page.waitForSelector('#board-text-editor', { timeout: 3000 });
await M.page.keyboard.type('Thumb');
await M.page.touchscreen.tap(sb.x + 40, sb.y + 30);
await settle(M.page);
let mi = await snap(M.page);
const phoneSticky = byText(mi, 'Thumb');
ok(mi.length === 1 && !!phoneSticky, 'tap the Sticky tool, tap the canvas → a sticky (typed with the keyboard)');
let pc = await screenOf(M.page, phoneSticky.id);
await M.page.touchscreen.tap(sb.x + 40, sb.y + 30);
await touch('touchStart', [pc]);
await M.page.waitForTimeout(700);
await touch('touchEnd', []);
await M.page.waitForTimeout(300);
ok((await M.page.evaluate(() => window.__boards.selection())).join() === phoneSticky.id && await M.page.evaluate(() => document.getElementById('board-rail').classList.contains('open')),
  'a long press selects it and opens the Item panel (a bottom sheet)');
await M.page.waitForSelector('#rail-sticky-colors', { timeout: 5000 }).catch(() => null);
ok(await M.page.isVisible('#rail-sticky-colors'), 'the sheet shows its Properties');
await M.page.screenshot({ path: `${SHOTS}/phone-sheet.png` });
await M.page.tap('#board-rail-close');
await M.page.waitForTimeout(300);
let cam0 = await M.page.evaluate(() => window.__boards.camera());
const a = { x: sb.x + 60, y: sb.y + 60 };
await touch('touchStart', [a]);
for (let k = 1; k <= 8; k++) await touch('touchMove', [{ x: a.x + k * 10, y: a.y + k * 5 }]);
await touch('touchEnd', []);
let cam1 = await M.page.evaluate(() => window.__boards.camera());
ok(Math.abs((cam0.x - cam1.x) * cam0.zoom - 80) < 3 && Math.abs((cam0.y - cam1.y) * cam0.zoom - 40) < 3, 'one finger on the empty canvas pans');
const mid = { x: sb.x + sb.width / 2, y: sb.y + sb.height / 2 };
cam0 = cam1;
await touch('touchStart', [{ x: mid.x - 40, y: mid.y }, { x: mid.x + 40, y: mid.y }]);
for (let k = 1; k <= 10; k++) await touch('touchMove', [{ x: mid.x - 40 - k * 8, y: mid.y }, { x: mid.x + 40 + k * 8, y: mid.y }]);
await touch('touchEnd', []);
cam1 = await M.page.evaluate(() => window.__boards.camera());
ok(cam1.zoom / cam0.zoom > 2.5 && cam1.zoom / cam0.zoom < 3.5, `two fingers apart: a pinch zooms (${Math.round(cam0.zoom * 100)}% → ${Math.round(cam1.zoom * 100)}%)`);
cam0 = cam1;
await touch('touchStart', [{ x: mid.x - 50, y: mid.y }, { x: mid.x + 50, y: mid.y }]);
for (let k = 1; k <= 8; k++) await touch('touchMove', [{ x: mid.x - 50 + k * 10, y: mid.y + k * 5 }, { x: mid.x + 50 + k * 10, y: mid.y + k * 5 }]);
await touch('touchEnd', []);
cam1 = await M.page.evaluate(() => window.__boards.camera());
ok(Math.abs((cam0.x - cam1.x) * cam0.zoom - 80) < 4 && Math.abs(cam1.zoom - cam0.zoom) < 0.01, 'two fingers together: a pan, the zoom kept');
// every tool shown is a 44 px target — those in the bar, then (More opened) those behind it (slice 4's connector and frame live there)
const targets44 = () => M.page.evaluate(() => { const r = document.getElementById('board-toolbar').getBoundingClientRect(); const shown = [...document.querySelectorAll('#board-toolbar .board-tool:not([disabled])')].filter((b) => b.getClientRects().length > 0);
  return shown.length > 0 && shown.every((b) => b.getBoundingClientRect().width >= 44 && b.getBoundingClientRect().height >= 44) && r.bottom <= innerHeight ? shown.length : 0; });
const inBar = await targets44();
const moreOpen = (on) => M.page.waitForFunction((v) => document.getElementById('board-toolbar').classList.contains('show-later') === v, on, { timeout: 3000 }).catch(() => null);
// a click, not a tap: right after the CDP pinch above, Chromium's gesture detector turns no tap into a click (a synthetic-touch artefact —
// a fresh page's tap opens More); what is proven here is the targets' size
await M.page.click('#board-tool-more');
await moreOpen(true);
const withMore = await targets44();
await M.page.click('#board-tool-more');
await moreOpen(false);
ok(await overflow(M.page) === 0 && inBar > 0 && withMore > inBar, `the bottom bar's tools are 44 px targets, and those behind More (${inBar} → ${withMore}); still no sideways scroll`);
await M.page.screenshot({ path: `${SHOTS}/phone-canvas.png` });

// ================================================================================================================================
console.log('10. JavaScript off, console errors');
const N = await session(26, { width: 375, height: 740 }, { javaScriptEnabled: false });
await N.page.goto(`${BASE}/boards/${seed.canvas}`, { waitUntil: 'domcontentloaded' });
const link = await N.page.locator('#board-noscript-outline').getAttribute('href');
ok(link === `/boards/${seed.canvas}/outline` && await N.page.isVisible('#board-noscript-outline'), 'without JavaScript the canvas page offers the outline');
await N.page.click('#board-noscript-outline');
ok((await N.page.locator('#board-outline-body .outline-item').count()) > 5, 'and the outline reads without JavaScript');
ok(allErrors.length === 0, 'no console error at 375 or 1280' + (allErrors.length ? ': ' + allErrors.slice(0, 5).join(' | ') : ''));
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
