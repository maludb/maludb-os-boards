// Headless Chromium proof of slice 4 — connectors and frames (docs/build-specs/connectors-frames.md "Proof" 1–6, 9): real pointer events (a
// side handle dragged to a shape, an end handle dragged onto an item, a frame drawn, a sticky dropped in and out, the Transformer's handle),
// asserted on the island's own view (window.__boards — route(), frameOrder(), snapshot()) and on the database. Bea's second context sees the
// connector follow (by the poll: live updates are off in this scratch database). Under BD_APP=apache there is no test hook: the story is
// asserted on the database alone. Run through tests/phase3/slice4/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9101';
const SHOTS = process.env.SHOTS || '/tmp/bd-shots-s4';
const KEY = process.env.ACTION_TOKEN_KEY;
const seed = JSON.parse(fs.readFileSync(process.env.BD_S4_SEED, 'utf8'));
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
const db = (sql) => execFileSync('psql', ['-X', '-q', '-At', '-h', process.env.DB_HOST, '-p', process.env.DB_PORT, '-U', process.env.DB_USER, '-d', process.env.DB_NAME, '-c', sql],
  { env: { ...process.env, PGPASSWORD: process.env.DB_PASSWORD } }).toString().trim();
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const until = async (fn, ms = 5000, every = 50) => { const end = Date.now() + ms; while (Date.now() < end) { const v = await fn(); if (v) return v; await sleep(every); } return null; };
const allErrors = [];
const browser = await chromium.launch();
async function session(member, viewport) {
  const touch = viewport.width < 600;
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, isMobile: touch, hasTouch: touch });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error' && !(m.text().includes('status of 429') && (m.location()?.url ?? '').endsWith('/thumbnail'))) allErrors.push(`${member}@${viewport.width}: ${m.text()}`); });
  page.on('pageerror', (e) => allErrors.push(`${member}@${viewport.width}: pageerror ${e.message}`));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page };
}
const open = async (page, board) => {
  await page.goto(`${BASE}/boards/${board}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
  if (HOOK) await page.evaluate(() => window.__boards.ready());
};
const settle = async (page) => { if (HOOK) await page.waitForFunction(() => !window.__boards.pending(), null, { timeout: 15000 }); await sleep(150); };
const stageBox = (page) => page.locator('#board-stage').boundingBox();
const drag = async (page, from, to, steps = 12) => { await page.mouse.move(from.x, from.y); await page.mouse.down(); await page.mouse.move(to.x, to.y, { steps }); await page.mouse.up(); };
const item = (id) => { const r = db(`SELECT coalesce(parent_id::text,''), coalesce(start_item_id::text,''), coalesce(end_item_id::text,''), data::text, deleted_at IS NOT NULL,
  (bbox[1])[0], (bbox[1])[1], (bbox[0])[0], (bbox[0])[1], style::text, x, y, width, height FROM board_items WHERE id = '${id}'`).split('|');
  return { parent: r[0] || null, start: r[1] || null, end: r[2] || null, data: JSON.parse(r[3] || '{}'), deleted: r[4] === 't', x1: +r[5], y1: +r[6], x2: +r[7], y2: +r[8],
    style: JSON.parse(r[9] || '{}'), x: +r[10], y: +r[11], width: +r[12], height: +r[13] }; };
const ops = (board) => Number(db(`SELECT count(*) FROM board_ops WHERE board_id = '${board}'`));
const lastOp = (board) => JSON.parse(db(`SELECT summary::text FROM board_ops WHERE board_id = '${board}' ORDER BY seq DESC LIMIT 1`) || '{}');
/** Board coordinates → page pixels (the view's camera, the stage's place on the page). */
const toPage = async (page, x, y) => { const sb = await stageBox(page); const c = await page.evaluate(() => window.__boards.camera());
  return { x: sb.x + sb.width / 2 + (x - c.x) * c.zoom, y: sb.y + sb.height / 2 + (y - c.y) * c.zoom }; };

if (!HOOK) {
  console.log('1–5. under the real vhost (no test hook): a connector and a frame by real input, asserted on the database');
  const P = await session(26, { width: 1280, height: 800 });
  await open(P.page, seed.draw);
  const sb = await stageBox(P.page);
  const n0 = Number(db(`SELECT count(*) FROM board_items WHERE board_id = '${seed.draw}' AND type = 'connector' AND deleted_at IS NULL`));
  await P.page.click('#board-tool-connector');
  // the camera fits the two items: drag from the middle of the sticky (left) to the middle of the shape (right)
  const st = item(seed.sticky), sh = item(seed.shape);
  const ext = { x1: Math.min(st.x1, sh.x1), y1: Math.min(st.y1, sh.y1), x2: Math.max(st.x2, sh.x2), y2: Math.max(st.y2, sh.y2) };
  const zoom = Math.min((sb.width - 96) / (ext.x2 - ext.x1), (sb.height - 96) / (ext.y2 - ext.y1), 1);
  const at = (x, y) => ({ x: sb.x + sb.width / 2 + (x - (ext.x1 + ext.x2) / 2) * zoom, y: sb.y + sb.height / 2 + (y - (ext.y1 + ext.y2) / 2) * zoom });
  await drag(P.page, at((st.x1 + st.x2) / 2, (st.y1 + st.y2) / 2), at((sh.x1 + sh.x2) / 2, (sh.y1 + sh.y2) / 2));
  await sleep(1200);
  const k = db(`SELECT id FROM board_items WHERE board_id = '${seed.draw}' AND type = 'connector' AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1`);
  ok(Number(db(`SELECT count(*) FROM board_items WHERE board_id = '${seed.draw}' AND type = 'connector' AND deleted_at IS NULL`)) === n0 + 1 && item(k).start === seed.sticky && item(k).end === seed.shape,
    'the Connector tool, sticky → shape, through the vhost: one connector, joined');
  await open(P.page, seed.frames);
  await P.page.click('#board-tool-frame');
  await drag(P.page, { x: sb.x + sb.width - 300, y: sb.y + 80 }, { x: sb.x + sb.width - 120, y: sb.y + 200 });
  await sleep(300);
  await P.page.keyboard.type('Made through Apache');
  await P.page.keyboard.press('Enter');
  await sleep(1200);
  ok(db(`SELECT count(*) FROM board_items WHERE board_id = '${seed.frames}' AND type = 'frame' AND data->>'title' = 'Made through Apache'`) === '1', 'the Frame tool: a frame drawn and titled in place');
  ok(allErrors.length === 0, 'no console error' + (allErrors.length ? ': ' + allErrors.slice(0, 3).join(' | ') : ''));
  await browser.close();
  console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
  process.exit(failed ? 1 : 0);
}

// ================================================================================================================================
console.log('1. a connector drawn from a side handle');
const P = await session(26, { width: 1280, height: 800 });
const B = await session(28, { width: 1280, height: 800 });
await open(P.page, seed.draw);
await P.page.evaluate(() => window.__boards.setCamera(0, 60, 1));
const st = await P.page.evaluate((id) => window.__boards.screenOf(id), seed.sticky);
await P.page.mouse.move(st.x, st.y);                                  // hover: the side handles appear
await P.page.mouse.move(st.x + 20, st.y);
await sleep(100);
const handle = { x: st.x + st.width / 2 + 14, y: st.y };              // the right side's handle, 14 out
const sh = await P.page.evaluate((id) => window.__boards.screenOf(id), seed.shape);
let n = ops(seed.draw);
await drag(P.page, handle, { x: sh.x, y: sh.y });
await settle(P.page);
let k = await P.page.evaluate(() => window.__boards.selection()[0]);
let kc = item(k);
ok(ops(seed.draw) === n + 1 && kc.start === seed.sticky && kc.end === seed.shape && kc.data.startSnap === 'right' && kc.data.shape === 'elbowed' && kc.style.endStrokeCap === 'arrow',
  'from the sticky\'s right handle to the shape (real pointer events): one batch — joined, snapped right, elbowed, an arrow at the end');
let route = await P.page.evaluate((id) => window.__boards.route(id), k);
const orth = (pl) => pl.every((p, i) => i === 0 || Math.abs(p.x - pl[i - 1].x) < 0.01 || Math.abs(p.y - pl[i - 1].y) < 0.01);
ok(route && route.shape === 'elbowed' && orth(route.polyline) && route.polyline.length <= 6, `the elbowed route has only horizontal and vertical segments (${route?.polyline.length} points)`);
await open(B.page, seed.draw);
await B.page.evaluate(() => window.__boards.setCamera(0, 60, 1));
const endBefore = route.points.slice(-2);
const shNow = await P.page.evaluate((id) => window.__boards.screenOf(id), seed.shape);
await P.page.mouse.click(shNow.x, shNow.y);
await drag(P.page, shNow, { x: shNow.x + 80, y: shNow.y + 140 });
await settle(P.page);
route = await P.page.evaluate((id) => window.__boards.route(id), k);
ok(Math.abs(route.points[route.points.length - 1] - endBefore[1]) > 100 || Math.abs(route.points[route.points.length - 2] - endBefore[0]) > 60,
  'the shape moved: the connector\'s rendered end follows it');
const followed = await until(async () => { const r = await B.page.evaluate((id) => window.__boards.route(id), k); return r && JSON.stringify(r.points) === JSON.stringify(route.points) ? r : null; }, 6000, 100);
ok(!!followed, 'and on Bea\'s canvas too (her store, by the poll)');
// Properties: curved, straight, arrowheads
const mid = await P.page.evaluate((id) => { const r = window.__boards.route(id); const pl = r.polyline; return pl[Math.floor(pl.length / 2)]; }, k);
const midPage = await toPage(P.page, (route.polyline[1].x + route.polyline[2].x) / 2, (route.polyline[1].y + route.polyline[2].y) / 2);
await P.page.mouse.click(midPage.x, midPage.y);
await P.page.waitForSelector(`#rail-item[data-ids="${k}"] #rail-shape-curved`, { timeout: 5000 });
ok((await P.page.evaluate(() => window.__boards.selection())).join() === k, 'a click on its line selects the connector (no Transformer — its ends decide)');
await P.page.click('#rail-shape-curved');
await settle(P.page);
ok(item(k).data.shape === 'curved' && (await P.page.evaluate((id) => window.__boards.route(id).shape, k)) === 'curved', 'Properties → Curved: one batch, drawn as a curve');
await P.page.waitForSelector(`#rail-item[data-ids="${k}"] #rail-shape-straight`);
await P.page.click('#rail-shape-straight');
await settle(P.page);
await P.page.waitForSelector(`#rail-item[data-ids="${k}"] #rail-start-filled_triangle`);
await P.page.click('#rail-start-filled_triangle');
await settle(P.page);
await P.page.waitForSelector(`#rail-item[data-ids="${k}"] #rail-stroke-dashed`);
await P.page.click('#rail-stroke-dashed');
await settle(P.page);
kc = item(k);
ok(kc.data.shape === 'straight' && kc.style.startStrokeCap === 'filled_triangle' && kc.style.strokeStyle === 'dashed', 'straight, a filled arrowhead at the start, dashed — each one batch');
route = await P.page.evaluate((id) => window.__boards.route(id), k);
const cap = await toPage(P.page, (route.points[0] + route.points[2]) / 2, (route.points[1] + route.points[3]) / 2);
await P.page.mouse.dblclick(cap.x, cap.y);
await P.page.waitForSelector('#board-caption-editor', { timeout: 3000 });
await P.page.keyboard.type('leads to');
await P.page.keyboard.press('Enter');
await settle(P.page);
ok(JSON.stringify(item(k).data.captions) === JSON.stringify([{ content: 'leads to', position: 0.5 }]), 'a double-click on the line: its caption typed in place');
await P.page.screenshot({ path: `${SHOTS}/desktop-connector.png` });

console.log('2. a free end, attached later');
await P.page.evaluate(() => window.__boards.setCamera(0, -40, 1));             // room above the sticky for a free end
await P.page.mouse.click((await stageBox(P.page)).x + 300, (await stageBox(P.page)).y + (await stageBox(P.page)).height - 40);
const st2 = await P.page.evaluate((id) => window.__boards.screenOf(id), seed.sticky);
await P.page.mouse.move(st2.x, st2.y);
await P.page.mouse.move(st2.x, st2.y - 10);
await sleep(100);
const topHandle = { x: st2.x, y: st2.y - st2.height / 2 - 14 };
const empty = { x: st2.x, y: st2.y - st2.height / 2 - 160 };
await drag(P.page, topHandle, empty);
await settle(P.page);
const free = await P.page.evaluate(() => window.__boards.selection()[0]);
const fc = item(free);
ok(fc.start === seed.sticky && fc.end === null && fc.data.startSnap === 'top' && typeof fc.data.endPoint?.x === 'number', 'from the top handle into empty space: a free end, its point stored');
const fr = await P.page.evaluate((id) => window.__boards.route(id), free);
const endHandle = await toPage(P.page, fr.points[fr.points.length - 2], fr.points[fr.points.length - 1]);
const shp = await P.page.evaluate((id) => window.__boards.screenOf(id), seed.shape);
await drag(P.page, endHandle, { x: shp.x, y: shp.y });
await settle(P.page);
const fc2 = item(free);
ok(fc2.end === seed.shape && fc2.data.endPoint === undefined, 'its end handle dragged onto the shape: attached, the free point cleared');

console.log('3. a deleted item takes its connectors');
await P.page.evaluate(() => window.__boards.setCamera(100, 100, 0.75));        // the moved shape and both connectors in view
const shp3 = await P.page.evaluate((id) => window.__boards.screenOf(id), seed.shape);
await P.page.mouse.click(shp3.x, shp3.y);
n = ops(seed.draw);
await P.page.keyboard.press('Delete');
await settle(P.page);
ok(ops(seed.draw) === n + 1 && item(seed.shape).deleted && item(k).deleted && item(free).deleted && lastOp(seed.draw).types?.connector === 2,
  'Delete the shape: its two connectors go in the same batch');
await P.page.keyboard.press('Control+z');
await settle(P.page);
ok(!item(seed.shape).deleted && !item(k).deleted && !item(free).deleted && item(k).end === seed.shape, 'Ctrl+Z: all three back, joined');

console.log('4. a frame: drawn, filled, moved, resized, emptied');
await open(P.page, seed.draw);
await P.page.evaluate(() => window.__boards.setCamera(0, 600, 1));
let sb = await stageBox(P.page);
await P.page.click('#board-tool-frame');
const fA = { x: sb.x + sb.width / 2 - 250, y: sb.y + 150 }, fB = { x: sb.x + sb.width / 2 + 250, y: sb.y + 450 };
await drag(P.page, fA, fB);
await P.page.waitForSelector('#board-frame-title-editor', { timeout: 3000 });
await P.page.keyboard.press('Control+a');
await P.page.keyboard.type('Went well');
await P.page.keyboard.press('Enter');
await settle(P.page);
const frame = db(`SELECT id FROM board_items WHERE board_id = '${seed.draw}' AND type = 'frame' AND data->>'title' = 'Went well' AND deleted_at IS NULL`);
ok(frame !== '' && Math.abs(item(frame).width - 500) < 2 && Math.abs(item(frame).height - 300) < 2, 'the Frame tool: a 500 × 300 frame drawn, titled "Went well" in place');
// a new sticky beside it, then dropped in
await P.page.click('#board-tool-sticky');
await P.page.mouse.click(sb.x + sb.width / 2 + 330, sb.y + 300);            // beside the frame, inside the stage
await P.page.keyboard.type('Drop me in');
await P.page.mouse.click(sb.x + 30, sb.y + sb.height - 30);
await settle(P.page);
const s = db(`SELECT id FROM board_items WHERE board_id = '${seed.draw}' AND plain_text = 'Drop me in'`);
let sp = await P.page.evaluate((id) => window.__boards.screenOf(id), s);
await drag(P.page, sp, { x: sb.x + sb.width / 2 - 100, y: sb.y + 330 });
await settle(P.page);
let si = item(s);
const fi = item(frame);
ok(si.parent === frame && Math.abs(si.x - ((si.x1 + si.x2) / 2 - fi.x1)) < 0.5 && Math.abs(si.y - ((si.y1 + si.y2) / 2 - fi.y1)) < 0.5,
  'a sticky dropped in: its frame set, its position frame-relative');
// drag the frame by its title
n = ops(seed.draw);
const titleAt = { x: fA.x + 20, y: fA.y - 12 };
await P.page.mouse.click(titleAt.x, titleAt.y);
await drag(P.page, titleAt, { x: titleAt.x + 60, y: titleAt.y + 40 });
await settle(P.page);
const si2 = item(s);
ok(ops(seed.draw) === n + 1 && Math.abs(si2.x1 - si.x1 - 60) < 1 && Math.abs(si2.y1 - si.y1 - 40) < 1 && si2.x === si.x && JSON.parse(db(`SELECT summary::text FROM board_ops WHERE board_id = '${seed.draw}' ORDER BY seq DESC LIMIT 1`)).items === 1,
  'the frame dragged by its title: the sticky\'s absolute box moves with it — ONE row in the batch (the frame\'s)');
// resize the frame from its left edge: the sticky stays where it is
const fs1 = await P.page.evaluate((id) => window.__boards.screenOf(id), frame);
if (!fs1?.handles?.['middle-left']) console.log('     (the frame is not held: selection', JSON.stringify(await P.page.evaluate(() => window.__boards.selection())), 'frame', frame, ')');
await drag(P.page, fs1.handles['middle-left'], { x: fs1.handles['middle-left'].x - 80, y: fs1.handles['middle-left'].y });
await settle(P.page);
const si3 = item(s);
ok(Math.abs(item(frame).width - fi.width - 80) < 2 && Math.abs(si3.x1 - si2.x1) < 0.5 && Math.abs(si3.y1 - si2.y1) < 0.5, 'the frame resized from its left: the sticky does not move');
sp = await P.page.evaluate((id) => window.__boards.screenOf(id), s);
await P.page.mouse.click(sp.x, sp.y);
await drag(P.page, sp, { x: sp.x - 300, y: sp.y });                      // out past the frame's left edge, still on the stage
await settle(P.page);
ok(item(s).parent === null && Math.abs(item(s).x - (item(s).x1 + item(s).x2) / 2) < 0.5, 'dragged out: absolute again');
await P.page.screenshot({ path: `${SHOTS}/desktop-frame.png` });

console.log('5. deleting a frame asks');
const back = await P.page.evaluate((id) => window.__boards.screenOf(id), s);
await drag(P.page, back, { x: back.x + 300, y: back.y });                 // in again
await settle(P.page);
const titleNow = async () => { const f = item(frame); return toPage(P.page, f.x1 + 8, f.y1 - 10); };     // where the title is now
await (async (t) => P.page.mouse.click(t.x, t.y))(await titleNow());
await P.page.keyboard.press('Delete');
await P.page.waitForSelector('#board-ask-frame-delete', { timeout: 3000 });
ok((await P.page.locator('#board-ask-frame-delete').innerText()).includes('Delete its contents too?'), 'Delete on a frame that holds things: "Delete its contents too?"');
await P.page.click('#board-ask-keep');
await settle(P.page);
ok(item(frame).deleted && !item(s).deleted && item(s).parent === null, 'Keep them: the frame goes, the sticky stays where it stood');
await P.page.keyboard.press('Control+z');
await settle(P.page);
ok(!item(frame).deleted && item(s).parent === frame, 'undone: the frame back, the sticky in it');
await (async (t) => P.page.mouse.click(t.x, t.y))(await titleNow());
await P.page.keyboard.press('Delete');
await P.page.waitForSelector('#board-ask-frame-delete');
await P.page.click('#board-ask-all');
await settle(P.page);
ok(item(frame).deleted && item(s).deleted, 'Delete all: the frame and what it holds');
await P.page.keyboard.press('Control+z');
await settle(P.page);
ok(!item(frame).deleted && !item(s).deleted, 'undone: both back');

console.log('6. the navigator and the minimap');
await open(P.page, seed.frames);
ok(JSON.stringify(await P.page.evaluate(() => window.__boards.frameOrder())) === JSON.stringify(seed.order), 'the frames in reading order: top to bottom, then left to right');
await P.page.click('#board-rail-tab-frames');
await P.page.waitForSelector('#rail-frames-list');
await P.page.click('#rail-frame-1');
await sleep(500);
const fa = item(seed.order[1]);
let cam = await P.page.evaluate(() => window.__boards.camera());
ok(Math.abs(cam.x - (fa.x1 + fa.x2) / 2) < 2 && cam.zoom > 1, `the Frames panel: a click flies to Alpha and fits it (${Math.round(cam.zoom * 100)} %)`);
await P.page.click('#board-frame-next');
await sleep(500);
const fb = item(seed.order[2]);
cam = await P.page.evaluate(() => window.__boards.camera());
ok(Math.abs(cam.x - (fb.x1 + fb.x2) / 2) < 2, 'Next: Beta');
await P.page.click('#board-frame-next');
await sleep(500);
cam = await P.page.evaluate(() => window.__boards.camera());
const fc0 = item(seed.order[0]);
ok(Math.abs(cam.x - (fc0.x1 + fc0.x2) / 2) < 2 && Math.abs(cam.y - ((fc0.y1 - 30) + fc0.y2) / 2) < 2, 'Next again: round to Charlie, the first');
await P.page.click('#board-frame-prev');
await sleep(500);
cam = await P.page.evaluate(() => window.__boards.camera());
ok(Math.abs(cam.x - (fb.x1 + fb.x2) / 2) < 2, 'Prev: back to Beta');
const mm = await P.page.locator('#board-minimap').boundingBox();
const before6 = await P.page.evaluate(() => window.__boards.camera());
await P.page.mouse.click(mm.x + 20, mm.y + 20);
const after6 = await P.page.evaluate(() => window.__boards.camera());
ok(mm.width === 160 && mm.height === 120 && (Math.abs(after6.x - before6.x) > 100 || Math.abs(after6.y - before6.y) > 100), 'the minimap (160 × 120): a click jumps the view there');
await P.page.screenshot({ path: `${SHOTS}/desktop-navigator.png` });

console.log('9. 375 × 740');
const M = await session(26, { width: 375, height: 740 });
await open(M.page, seed.phone);
ok(!(await M.page.isVisible('#board-minimap')) && !(await M.page.isVisible('#board-frame-nav')), 'no minimap and no Prev / Next at 375 — the Frames panel is the way around');
await M.page.tap('#board-menu-btn');
await M.page.tap('#board-menu-frames');
await M.page.waitForSelector('#rail-frames-list', { timeout: 5000 });
ok(await M.page.evaluate(() => document.getElementById('board-rail').classList.contains('open')), 'the board\'s menu → Frames: the panel as a bottom sheet');
await M.page.screenshot({ path: `${SHOTS}/phone-frames.png` });
await M.page.tap('#rail-frame-1');
await sleep(600);
const pf = item(seed.phone_frames[1]);
const pcam = await M.page.evaluate(() => window.__boards.camera());
ok(Math.abs(pcam.y - ((pf.y1 - 30) + pf.y2) / 2) < 2 && !(await M.page.evaluate(() => document.getElementById('board-rail').classList.contains('open'))), 'a tap flies to the frame and the sheet steps aside');
ok(await M.page.evaluate(() => document.documentElement.scrollWidth - innerWidth) === 0, 'no sideways scroll');
ok(allErrors.length === 0, 'no console error at 375 or 1280' + (allErrors.length ? ': ' + allErrors.slice(0, 5).join(' | ') : ''));
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
