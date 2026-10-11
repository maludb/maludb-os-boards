// Headless Chromium proof of slice 5 — pen, images and files (docs/build-specs/pen-files.md "Proof" 1–5, 9, 10): real pointer and touch input
// (a 500-point stroke, the highlighter over a sticky — its blend read back from the canvas's pixels —, the eraser across two strokes), the
// file picker, a drop and a paste, the image sizes the canvas asks for as it zooms (window.__boards.imageSizes()), the SVG that must never
// run, the Properties panel. Under BD_APP=apache there is no test hook: a stroke and an upload through the real vhost, asserted on the
// database. Run through tests/phase3/slice5/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9101';
const SHOTS = process.env.SHOTS || '/tmp/bd-shots-s5';
const KEY = process.env.ACTION_TOKEN_KEY;
const FIX = new URL('../../fixtures/', import.meta.url).pathname;
const seed = JSON.parse(fs.readFileSync(process.env.BD_S5_SEED, 'utf8'));
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
const pg = (sql) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-X', '-q', '-At', '-v', 'ON_ERROR_STOP=1', '-d', process.env.DB_NAME, '-c', sql]).toString().trim();
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const until = async (fn, ms = 8000, every = 100) => { const end = Date.now() + ms; while (Date.now() < end) { const v = await fn(); if (v) return v; await sleep(every); } return null; };
const allErrors = [];
const evil = [];
const sandboxed = [];
let expected422 = 0;
const browser = await chromium.launch();
async function session(member, viewport) {
  const touch = viewport.width < 600;
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, isMobile: touch, hasTouch: touch, acceptDownloads: true });
  const page = await ctx.newPage();
  page.on('console', (m) => {
    if (/evil/.test(m.text())) evil.push(`console: ${m.text()}`);
    if (/Blocked script execution in '[^']*\/files\/\d+\?inline=1' because the document's frame is sandboxed/.test(m.text())) { sandboxed.push(m.text()); return; }
    // the refusals this proof provokes on purpose: the forged stroke (422), the executable named .png (422)
    if (m.type() === 'error' && /status of 422/.test(m.text()) && expected422 > 0) { expected422--; return; }
    if (m.type() === 'error' && !(m.text().includes('status of 429') && (m.location()?.url ?? '').endsWith('/thumbnail'))) allErrors.push(`${member}@${viewport.width}: ${m.text()}`);
  });
  page.on('pageerror', (e) => allErrors.push(`${member}@${viewport.width}: pageerror ${e.message}`));
  page.on('dialog', async (d) => { evil.push(`dialog: ${d.message()}`); await d.dismiss(); });
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page };
}
const open = async (page, board) => {
  await page.goto(`${BASE}/boards/${board}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
  if (HOOK) await page.evaluate(() => window.__boards.ready());
};
const settle = async (page) => { if (HOOK) await page.waitForFunction(() => !window.__boards.pending(), null, { timeout: 20000 }); await sleep(150); };
const stageBox = (page) => page.locator('#board-stage').boundingBox();
const items = (board, type) => db(`SELECT id FROM board_items WHERE board_id = '${board}' AND type = '${type}' AND deleted_at IS NULL ORDER BY created_at`).split('\n').filter(Boolean);
const item = (id) => { const r = db(`SELECT data::text, style::text, deleted_at IS NOT NULL, width, height, (bbox[1])[0], (bbox[1])[1], (bbox[0])[0], (bbox[0])[1], type FROM board_items WHERE id = '${id}'`).split('|');
  return { data: JSON.parse(r[0] || '{}'), style: JSON.parse(r[1] || '{}'), deleted: r[2] === 't', width: +r[3], height: +r[4], cx: (+r[5] + +r[7]) / 2, cy: (+r[6] + +r[8]) / 2, type: r[9] }; };
const ops = (board) => Number(db(`SELECT count(*) FROM board_ops WHERE board_id = '${board}'`));
const lastOp = (board) => JSON.parse(db(`SELECT summary::text FROM board_ops WHERE board_id = '${board}' ORDER BY seq DESC LIMIT 1`) || '{}');
const toPage = async (page, x, y) => { const sb = await stageBox(page); const c = await page.evaluate(() => window.__boards.camera());
  return { x: sb.x + sb.width / 2 + (x - c.x) * c.zoom, y: sb.y + sb.height / 2 + (y - c.y) * c.zoom }; };
const pickFiles = async (page, files) => {
  const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.click('#board-tool-image')]);
  await chooser.setFiles(files);
};
/** A 500-point stroke with the mouse, along a wave from a to b (page pixels). */
const stroke = async (page, a, b, n = 500) => {
  await page.mouse.move(a.x, a.y);
  await page.mouse.down();
  for (let k = 1; k <= n; k++) { const t = k / n; await page.mouse.move(a.x + (b.x - a.x) * t, a.y + (b.y - a.y) * t + Math.sin(t * Math.PI * 6) * 30); }
  await page.mouse.up();
};

if (!HOOK) {
  console.log('1, 3. under the real vhost (no test hook): a stroke and an upload by real input, asserted on the database');
  const P = await session(26, { width: 1280, height: 800 });
  await open(P.page, seed.draw);
  const sb = await stageBox(P.page);
  const n0 = items(seed.draw, 'drawing').length;
  await P.page.click('#board-tool-pen');
  await stroke(P.page, { x: sb.x + 200, y: sb.y + 120 }, { x: sb.x + 600, y: sb.y + 160 }, 120);
  ok(await until(() => items(seed.draw, 'drawing').length === n0 + 1), 'the pen through the vhost: one drawing');
  await open(P.page, seed.files);
  await pickFiles(P.page, [FIX + 'photo-3000x2000.jpg']);
  const img = await until(() => items(seed.files, 'image')[0], 15000);
  ok(!!img && Math.abs(item(img).width - 800) < 0.5, 'the picker through the vhost: the photograph uploaded and placed 800 wide');
  const att = img ? item(img).data.attachment_id : 0;
  const res = await P.page.evaluate(async (u) => { const r = await fetch(u); return { status: r.status, type: r.headers.get('content-type'), csp: r.headers.get('content-security-policy') }; }, `/files/${att}?size=256`);
  ok(res.status === 200 && res.type === 'image/jpeg', 'its 256 thumbnail through the vhost\'s /files/{id} rewrite');
  ok(allErrors.length === 0, 'no console error' + (allErrors.length ? ': ' + allErrors.slice(0, 3).join(' | ') : ''));
  await browser.close();
  console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
  process.exit(failed ? 1 : 0);
}

// ================================================================================================================================
console.log('1. the pen');
const P = await session(26, { width: 1280, height: 800 });
await open(P.page, seed.draw);
await P.page.evaluate(() => window.__boards.setCamera(0, 0, 1));
let sb = await stageBox(P.page);
await P.page.click('#board-tool-pen');
ok(await P.page.isVisible('#board-pen-menu') && await P.page.locator('#board-pen-width-4').evaluate((e) => e.classList.contains('active')), 'the Pen: its menu opens — three widths (medium on), the colours');
let n = ops(seed.draw);
await stroke(P.page, { x: sb.x + 400, y: sb.y + 420 }, { x: sb.x + 820, y: sb.y + 480 });      // clear of the pen menu beside the toolbar
await settle(P.page);
let d1 = items(seed.draw, 'drawing');
let dr = d1.length ? item(d1[d1.length - 1]) : null;
const max0 = await P.page.evaluate(() => window.__boards.drawingMaxPoints());
ok(ops(seed.draw) === n + 1 && d1.length === 1 && dr.data.tool === 'pen' && dr.style.strokeWidth === 4 && dr.style.strokeColor === '#1a1a1a'
  && dr.data.points.length / 2 >= 2 && dr.data.points.length / 2 <= max0 && dr.data.points.length / 2 < 500,
  `a 500-point stroke (real mouse moves): ONE batch, one drawing simplified to ${dr ? dr.data.points.length / 2 : '?'} points (≤ ${max0})`);
ok(dr && dr.data.points.every((v) => Math.abs(v * 10 - Math.round(v * 10)) < 1e-6) && Math.min(...dr.data.points.filter((_, k) => k % 2 === 0)) >= 1.99
  && Math.abs(dr.width - (Math.max(...dr.data.points.filter((_, k) => k % 2 === 0)) + 2)) < 0.2, 'its points relative to its top-left, to 0.1; its box their bounds + half the stroke');
pg('UPDATE bd_settings SET drawing_max_points = 10 WHERE id = 1');
await open(P.page, seed.draw);
await P.page.evaluate(() => window.__boards.setCamera(0, 0, 1));
await P.page.click('#board-tool-pen');
await stroke(P.page, { x: sb.x + 400, y: sb.y + 520 }, { x: sb.x + 820, y: sb.y + 540 });
await settle(P.page);
d1 = items(seed.draw, 'drawing');
dr = item(d1[d1.length - 1]);
ok(d1.length === 2 && dr.data.points.length / 2 <= 10 && dr.data.points.length / 2 >= 2, `drawing_max_points at 10: the next stroke simplified to ${dr.data.points.length / 2} points`);
expected422++;
const forged = await P.page.evaluate(async (board) => {
  const m = document.getElementById('board-canvas').dataset;
  const pts = Array.from({ length: 22 }, (_, k) => k * 3);
  const r = await fetch(`/boards/${board}/ops`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': m.csrf },
    body: JSON.stringify({ client_id: 'forger', ops: [{ op: 'create', item: { id: crypto.randomUUID(), type: 'drawing', parent_id: null, x: 0, y: 0, width: 70, height: 70, rotation: 0, z: 'b0',
      style: { strokeColor: '#1a1a1a', strokeWidth: 4 }, data: { tool: 'pen', points: pts }, locked: false } }] }) });
  return { status: r.status, body: await r.text() };
}, seed.draw);
ok(forged.status === 422 && forged.body.includes('A drawing holds at most 10 points.') && items(seed.draw, 'drawing').length === 2, 'a forged 11-point stroke: refused by the database (422 "A drawing holds at most 10 points.")');
pg('UPDATE bd_settings SET drawing_max_points = 2000 WHERE id = 1');
// the highlighter over the sticky: 24 wide, 40 %, multiplied
await open(P.page, seed.draw);
await P.page.evaluate(() => window.__boards.setCamera(0, 0, 1));
await P.page.click('#board-tool-highlighter');
await P.page.click('#board-pen-color-1971c2');
const st = await P.page.evaluate((id) => window.__boards.screenOf(id), seed.sticky);
await P.page.mouse.move(st.x - 90, st.y + 60);
await P.page.mouse.down();
for (let k = 1; k <= 30; k++) await P.page.mouse.move(st.x - 90 + k * 6, st.y + 60);
await P.page.mouse.up();
await settle(P.page);
const hl = item(items(seed.draw, 'drawing').at(-1));
ok(hl.data.tool === 'highlighter' && hl.style.strokeWidth === 24 && hl.style.strokeColor === '#1971c2', 'the Highlighter (its blue): a drawing with data.tool highlighter, 24 wide');
await P.page.mouse.move(sb.x + 5, sb.y + 5);
await P.page.evaluate(() => window.__boards.setCamera(window.__boards.camera().x, window.__boards.camera().y, 1));
const px = await P.page.evaluate(({ x, y }) => {
  const r = document.getElementById('board-stage').getBoundingClientRect();
  const cv = document.querySelectorAll('#board-stage canvas')[0];
  const ratio = cv.width / r.width;
  return [...cv.getContext('2d').getImageData(Math.round((x - r.left) * ratio), Math.round((y - r.top) * ratio), 1, 1).data];
}, { x: st.x, y: st.y + 60 });
// over the sticky (#fff9b1), #1971c2 multiplied at 40 % → ≈ (163, 193, 160); a normal blend would be ≈ (163, 195, 184)
ok(Math.abs(px[0] - 163) <= 10 && Math.abs(px[1] - 193) <= 10 && Math.abs(px[2] - 160) <= 10, `its pixels over the sticky: rgb(${px.slice(0, 3).join(', ')}) — multiplied at 40 % (≈ 163, 193, 160), not laid over`);
await P.page.screenshot({ path: `${SHOTS}/desktop-pen.png` });

console.log('2. the eraser');
await P.page.keyboard.press('Escape');
await P.page.click('#board-tool-pen');
await stroke(P.page, { x: sb.x + 450, y: sb.y + 120 }, { x: sb.x + 800, y: sb.y + 120 }, 40);
await settle(P.page);
await stroke(P.page, { x: sb.x + 450, y: sb.y + 200 }, { x: sb.x + 800, y: sb.y + 200 }, 40);
await settle(P.page);
const two = items(seed.draw, 'drawing').slice(-2);
await P.page.click('#board-tool-eraser');
n = ops(seed.draw);
await P.page.mouse.move(sb.x + 620, sb.y + 60);
await P.page.mouse.down();
await P.page.mouse.move(sb.x + 620, sb.y + 260, { steps: 8 });
await P.page.mouse.up();
await settle(P.page);
ok(ops(seed.draw) === n + 1 && two.every((id) => item(id).deleted) && lastOp(seed.draw).types?.drawing === 2 && !item(seed.sticky).deleted,
  'the eraser across two strokes: both deleted in ONE batch (the sticky under the wave untouched)');
await P.page.keyboard.press('Control+z');
await settle(P.page);
ok(two.every((id) => !item(id).deleted), 'Ctrl+Z: both back');
// a drawing's Properties: its colour, its width (the box grows with it)
await P.page.click('#board-tool-select');
const w0 = item(two[0]);
const tl = await P.page.evaluate((id) => { const it = window.__boards.snapshot().find((i) => i.id === id); return it; }, two[0]);
const firstPt = await toPage(P.page, w0.cx - w0.width / 2 + tl.data.points[0], w0.cy - w0.height / 2 + tl.data.points[1]);
await P.page.mouse.click(firstPt.x, firstPt.y);
await P.page.waitForSelector(`#rail-item[data-ids="${two[0]}"] #rail-drawing-colors`, { timeout: 5000 });
ok((await P.page.locator('#rail-item-title').innerText()).startsWith('Drawing'), 'a click on its line selects the drawing — Properties: "Drawing"');
await P.page.click('#rail-drawing-colors [data-value="#e03131"]');
await settle(P.page);
await P.page.waitForSelector(`#rail-item[data-ids="${two[0]}"] #rail-drawing-width-8`);
await P.page.click('#rail-drawing-width-8');
await settle(P.page);
const w1 = item(two[0]);
ok(w1.style.strokeColor === '#e03131' && w1.style.strokeWidth === 8 && Math.abs(w1.width - w0.width - 4) < 0.2 && Math.abs(w1.height - w0.height - 4) < 0.2,
  'its colour red and its width thick — the box 4 larger each way, the stroke still inside it');

console.log('3. images: the picker and the sizes the canvas asks for');
await open(P.page, seed.files);
await P.page.evaluate(() => window.__boards.setCamera(0, 0, 1));
await pickFiles(P.page, [FIX + 'photo-3000x2000.jpg']);
const photo = await until(() => items(seed.files, 'image')[0], 15000);
const ph = photo ? item(photo) : null;
ok(!!ph && Math.abs(ph.width - 800) < 0.5 && Math.abs(ph.height - 533.33) < 0.5 && Math.abs(ph.cx) < 1 && Math.abs(ph.cy) < 1 && ph.data.mime_type === 'image/jpeg'
  && (await P.page.evaluate(() => window.__boards.selection())).join() === photo, 'the picker: the photograph uploaded, placed 800 × 533 at the view\'s centre, selected');
await settle(P.page);
const sizeAt = async (zoom) => {
  await P.page.evaluate((z) => window.__boards.setCamera(0, 0, z), zoom);
  await sleep(300);
  await P.page.evaluate(() => window.__boards.setCamera(window.__boards.camera().x, window.__boards.camera().y, window.__boards.camera().zoom));
  return (await P.page.evaluate(() => window.__boards.imageSizes()))[photo];
};
const s1 = await sizeAt(1), s025 = await sizeAt(0.25), s3 = await sizeAt(3);
ok(s1 === 1024 && s025 === 256 && s3 === 2048, `zooming asks the door for the size on screen: 100 % → ${s1}, 25 % → ${s025}, 300 % → ${s3}`);
const loaded = await P.page.evaluate(async (att) => { const out = {}; for (const s of [256, 1024, 2048]) { const r = await fetch(`/files/${att}?size=${s}`); out[s] = r.status; } return out; }, ph.data.attachment_id);
ok(loaded[256] === 200 && loaded[1024] === 200 && loaded[2048] === 200, 'and each size is there');
await P.page.evaluate(() => window.__boards.setCamera(0, 0, 0.5));
await pickFiles(P.page, [FIX + 'anim.gif', FIX + 'shape.webp']);
const imgs = await until(() => { const l = items(seed.files, 'image'); return l.length === 3 ? l : null; }, 20000);
const gif = imgs ? item(imgs[1]) : null, webp = imgs ? item(imgs[2]) : null;
ok(!!imgs && gif.width === 480 && gif.height === 320 && webp.width === 600 && Math.abs(webp.cx - gif.cx - (240 + 24 + 300)) < 1 && Math.abs(webp.cy - gif.cy) < 1,
  'two at once (GIF and WebP): each at its own size (never enlarged), side by side 24 apart');

console.log('4. files');
await pickFiles(P.page, [FIX + 'two-pages.pdf', FIX + 'notes.docx']);
const docs = await until(() => { const l = items(seed.files, 'document'); return l.length === 2 ? l : null; }, 20000);
const pdf = docs ? item(docs[0]) : null, docx = docs ? item(docs[1]) : null;
ok(!!docs && pdf.data.preview === true && Math.max(pdf.width, pdf.height) === 800 && docx.data.preview === false && docx.width === 320 && docx.height === 120,
  'the PDF: its first page at up to 800; the DOCX: a 320 × 120 card');
await sleep(400);
const ds = await P.page.evaluate(() => window.__boards.imageSizes());
ok([256, 1024, 2048].includes(ds[docs[0]]) && ds[docs[1]] === undefined, 'the PDF\'s page asked for by size; the card asks for nothing');
const before = items(seed.files, 'image').length + items(seed.files, 'document').length;
fs.writeFileSync('/tmp/bd5-setup.png', Buffer.concat([Buffer.from('MZ'), Buffer.alloc(58, 0x90), Buffer.from([64, 0, 0, 0]), Buffer.from('PE\0\0'), Buffer.alloc(300)]));
expected422++;
await pickFiles(P.page, ['/tmp/bd5-setup.png']);
const refusal = await until(async () => (await P.page.isVisible('#board-toast-upload')) ? P.page.locator('#board-toast-upload').innerText() : null, 8000);
ok(refusal && refusal.includes('Boards keeps images and documents, not application/x-dosexec.') && items(seed.files, 'image').length + items(seed.files, 'document').length === before,
  'an executable named .png: the door\'s sentence in a toast, nothing placed');
fs.unlinkSync('/tmp/bd5-setup.png');
// a file's Properties: its title, open and download
await P.page.evaluate((id) => window.__boards.setCamera(0, 0, 0.5), null);
const dcard = await P.page.evaluate((id) => window.__boards.screenOf(id), docs[1]);
await P.page.mouse.click(dcard.x, dcard.y);
await P.page.waitForSelector(`#rail-item[data-ids="${docs[1]}"] #rail-file-links`, { timeout: 5000 });
const href = await P.page.getAttribute('#rail-file-open', 'href');
const dhref = await P.page.getAttribute('#rail-file-download', 'href');
ok(href === `/files/${docx.data.attachment_id}` && dhref === `/files/${docx.data.attachment_id}?download=1` && (await P.page.locator('#rail-file-facts').innerText()).startsWith('932 B · application/vnd.openxmlformats'),
  'its Properties: "Open original" and "Download" through the door, its size and type');
await P.page.fill('#rail-file-title', 'Retro notes.docx');
await P.page.press('#rail-file-title', 'Enter');
await P.page.locator('#rail-file-title').evaluate((e) => e.dispatchEvent(new Event('change', { bubbles: true })));
await settle(P.page);
ok(await until(() => item(docs[1]).data.title === 'Retro notes.docx'), 'its title renamed in Properties — one update');
const [download] = await Promise.all([P.page.waitForEvent('download'), P.page.click('#rail-file-download')]);
ok(download.suggestedFilename() === 'notes.docx', 'Download: the original, by its stored name');
await P.page.screenshot({ path: `${SHOTS}/desktop-files.png` });

console.log('5. the SVG');
await pickFiles(P.page, [FIX + 'evil.svg']);
const svgId = await until(() => items(seed.files, 'image').find((id) => item(id).data.mime_type === 'image/svg+xml'), 15000);
await sleep(600);
ok(!!svgId && item(svgId).width === 400 && item(svgId).height === 300 && (await P.page.evaluate(() => window.__boards.imageSizes()))[svgId] === 0,
  'drawn on the canvas from its original (an <img>: no size asked), 400 × 300 from its viewBox');
const svgAtt = item(svgId).data.attachment_id;
await P.page.evaluate((u) => new Promise((res) => { const f = document.createElement('iframe'); f.src = u; f.onload = res; f.style.width = '200px'; document.body.appendChild(f); setTimeout(res, 3000); }), `/files/${svgAtt}?inline=1`);
const Q = await P.ctx.newPage();
Q.on('dialog', async (d) => { evil.push(`dialog: ${d.message()}`); await d.dismiss(); });
Q.on('console', (m) => { if (/evil/.test(m.text())) evil.push(`console: ${m.text()}`); });
const [svgDownload] = await Promise.all([Q.waitForEvent('download', { timeout: 8000 }).catch(() => null), Q.goto(`${BASE}/files/${svgAtt}`).catch(() => null)]);
await sleep(800);
ok(svgDownload?.suggestedFilename() === 'evil.svg', 'opened directly: a download (attachment), never a page');
ok(evil.length === 0 && sandboxed.length >= 1, `its script and its onload never ran — no dialog, no console line; in a frame the browser said it blocked them (${sandboxed.length})` + (evil.length ? ': ' + evil.join(' | ') : ''));
await Q.close();

console.log('9. a drop and a paste');
await open(P.page, seed.drop);
await P.page.evaluate(() => window.__boards.setCamera(0, 0, 1));
sb = await stageBox(P.page);
const webpB64 = fs.readFileSync(FIX + 'shape.webp').toString('base64');
const dropAt = { x: sb.x + 300, y: sb.y + 250 };
await P.page.evaluate(({ b64, x, y }) => {
  const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
  const dt = new DataTransfer();
  dt.items.add(new File([bytes], 'dropped.webp', { type: 'image/webp' }));
  const el = document.getElementById('board-stage');
  el.dispatchEvent(new DragEvent('dragover', { dataTransfer: dt, clientX: x, clientY: y, bubbles: true, cancelable: true }));
  el.dispatchEvent(new DragEvent('drop', { dataTransfer: dt, clientX: x, clientY: y, bubbles: true, cancelable: true }));
}, { b64: webpB64, ...dropAt });
const dropped = await until(() => items(seed.drop, 'image')[0], 15000);
const want = await P.page.evaluate(({ x, y }) => { const r = document.getElementById('board-stage').getBoundingClientRect(); const c = window.__boards.camera();
  return { x: c.x + (x - r.left - r.width / 2) / c.zoom, y: c.y + (y - r.top - r.height / 2) / c.zoom }; }, dropAt);
ok(!!dropped && Math.abs(item(dropped).cx - want.x) < 1 && Math.abs(item(dropped).cy - want.y) < 1 && item(dropped).data.title === 'dropped.webp',
  'a file dropped on the canvas: uploaded and centred at the drop point');
const pasteAt = { x: sb.x + 700, y: sb.y + 300 };
await P.page.mouse.move(pasteAt.x, pasteAt.y);
await P.page.evaluate((b64) => {
  const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
  const dt = new DataTransfer();
  dt.items.add(new File([bytes], 'pasted.webp', { type: 'image/webp' }));
  document.getElementById('board-stage').dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
}, webpB64);
const pasted = await until(() => items(seed.drop, 'image').find((id) => item(id).data.title === 'pasted.webp'), 15000);
const wantP = await P.page.evaluate(({ x, y }) => { const r = document.getElementById('board-stage').getBoundingClientRect(); const c = window.__boards.camera();
  return { x: c.x + (x - r.left - r.width / 2) / c.zoom, y: c.y + (y - r.top - r.height / 2) / c.zoom }; }, pasteAt);
ok(!!pasted && Math.abs(item(pasted).cx - wantP.x) < 1.5 && Math.abs(item(pasted).cy - wantP.y) < 1.5, 'an image pasted: uploaded and centred at the pointer');

console.log('10. 375 × 740');
const M = await session(26, { width: 375, height: 740 });
await open(M.page, seed.phone);
await M.page.evaluate(() => window.__boards.setCamera(0, 0, 1));
await M.page.tap('#board-tool-more');
await M.page.tap('#board-tool-pen');
await M.page.waitForSelector('#board-pen-menu:not(.d-none)', { timeout: 3000 });
ok(await M.page.evaluate(() => { const r = document.getElementById('board-pen-menu').getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; }),
  'at 375 the pen menu sits above the bar, inside the screen (a stroke elsewhere closes it)');
const mb = await stageBox(M.page);
const cdp = await M.ctx.newCDPSession(M.page);
const touch = (type, pts) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: pts.map((p, k) => ({ x: p.x, y: p.y, id: k + 1 })) });
const n2 = items(seed.phone, 'drawing').length;
await touch('touchStart', [{ x: mb.x + 60, y: mb.y + 150 }]);
for (let k = 1; k <= 40; k++) await touch('touchMove', [{ x: mb.x + 60 + k * 6, y: mb.y + 150 + Math.sin(k / 4) * 30 }]);
await touch('touchEnd', []);
await settle(M.page);
ok(items(seed.phone, 'drawing').length === n2 + 1, 'a finger draws with the pen (one finger draws; two would pinch)');
ok(await M.page.evaluate(() => document.documentElement.scrollWidth - innerWidth) === 0 && await M.page.evaluate(() =>
  [...document.querySelectorAll('#board-tool-later .board-tool')].filter((b) => b.getClientRects().length).every((b) => b.getBoundingClientRect().width >= 44)),
  'More shows the pen, highlighter, eraser and image as 44 px targets; no sideways scroll');
await M.page.screenshot({ path: `${SHOTS}/phone-pen.png` });
ok(allErrors.length === 0, 'no console error at 375 or 1280' + (allErrors.length ? ': ' + allErrors.slice(0, 5).join(' | ') : ''));
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
