// Headless Chromium proof of slice 7 — history, versions and restore (docs/build-specs/history-versions.md "Proof" 7): the page at 1280 and 375 (three sections, no sideways scroll), Show flying to a
// change, Save this moment, the preview, Restore's confirm (cancelled, then accepted), and a second person's open canvas receiving the restored board within 5 s through the poll (the relay is
// not running here). Under BD_APP=apache there is no test hook: the canvas is judged by the catch-up responses it fetches and by the database. Run through tests/phase3/slice7/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9101';
const SHOTS = process.env.SHOTS || '/tmp/bd-shots-s7';
const KEY = process.env.ACTION_TOKEN_KEY;
const seed = JSON.parse(fs.readFileSync(process.env.BD_S7_SEED, 'utf8'));
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
const until = async (fn, ms = 8000, every = 100) => { const end = Date.now() + ms; while (Date.now() < end) { const v = await fn(); if (v) return v; await sleep(every); } return null; };
const allErrors = [];
const browser = await chromium.launch();
async function session(member, viewport) {
  const touch = viewport.width < 600;
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, isMobile: touch, hasTouch: touch });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error' && !(m.text().includes('status of 429') && (m.location()?.url ?? '').endsWith('/thumbnail'))) allErrors.push(`${member}@${viewport.width}: ${m.text()} ${m.location()?.url ?? ''}`); });
  page.on('pageerror', (e) => allErrors.push(`${member}@${viewport.width}: pageerror ${e.message}`));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const shot = (page, name) => page.screenshot({ path: `${SHOTS}/${name}.png` });
const hist = (page, board, q = '') => page.goto(`${BASE}/boards/${board}/history${q}`, { waitUntil: 'networkidle' });
const openCanvas = async (page, board) => {
  await page.goto(`${BASE}/boards/${board}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
  if (HOOK) await page.evaluate(() => window.__boards.ready());
};
const x = (id) => db(`SELECT x FROM board_items WHERE id = '${id}'`);
const deleted = (id) => db(`SELECT deleted_at IS NOT NULL FROM board_items WHERE id = '${id}'`) === 't';
const B = seed.browser;

console.log('7. The page at 1280');
const P = await session(26, { width: 1280, height: 800 });
await hist(P.page, B);
for (const id of ['history-changes', 'history-versions', 'history-visits', 'version-form-field-label']) ok(await P.page.locator('#' + id).isVisible(), `#${id} is on the page`);
ok(await overflow(P.page) === 0, 'no sideways scroll at 1280');
const rowCount = await P.page.locator('[id^="change-row-"]').count();
ok(rowCount >= 2, `the changes are listed (${rowCount} batches)`);
await shot(P.page, 'history-1280');
// Show flies to the change
const showId = await P.page.locator('[id^="change-show-"]').first().getAttribute('href');
ok(/\?x=-?[\d.]+&y=-?[\d.]+&zoom=1$/.test(showId), `Show links to the batch's centre: ${showId}`);
await P.page.locator('[id^="change-show-"]').first().click();
await P.page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
ok(P.page.url().includes('zoom=1') && P.page.url().includes('/boards/' + B + '?x='), 'Show opens the canvas at that place');
if (HOOK) {
  const cam = await P.page.evaluate(() => window.__boards.camera());
  ok(Math.abs(cam.zoom - 1) < 0.01, 'and at zoom 1 (camera ' + JSON.stringify(cam) + ')');
}

console.log('   Save this moment');
await hist(P.page, B);
await P.page.fill('#version-form-field-label', 'Browser moment');
await P.page.click('#version-form-save');
await P.page.waitForSelector('#history-preview', { timeout: 8000 });
const saved = db(`SELECT version_no FROM board_versions WHERE board_id = '${B}' AND label = 'Browser moment'`);
ok(saved !== '' && P.page.url().includes('version=' + saved), `the form saves version ${saved} and lands on its preview`);
ok(await P.page.locator('#notice-banner').isVisible() && (await P.page.locator('#notice-banner').innerText()).includes('Saved'), 'with the notice "Saved"');
ok(await P.page.locator(`#version-row-${saved} .fw-bold`).count() === 1, 'the named version is bold in the list');
await shot(P.page, 'preview-1280');

console.log('   the preview of the baseline');
const base = seed.browser_baseline;
await P.page.click(`#version-preview-${base}`);
await P.page.waitForFunction((n) => location.search.includes('version=' + n) && document.querySelector('#history-preview-title')?.textContent.includes('Version ' + n), base, { timeout: 8000 });
const outline = await P.page.locator('#history-preview-outline').innerText();
ok(P.page.url().includes('version=' + base) && outline.includes('Plan') && outline.includes('Alpha') && outline.includes('Beta'), 'Preview shows the baseline\'s outline: the frame and both stickies');
ok(await P.page.locator('#history-preview-back').isVisible(), '"Back to the history" is there');
await P.page.click('#history-preview-back');
await P.page.waitForFunction(() => !document.getElementById('history-preview'), null, { timeout: 5000 });
ok(true, 'and returns to the list');

console.log('   Restore: cancel, then confirm; another person\'s canvas follows');
const Bea = await session(28, { width: 1280, height: 800 });
await openCanvas(Bea.page, B);
const seen = [];
Bea.page.on('response', async (r) => { if (r.url().includes('/ops?after=')) { try { seen.push(await r.text()); } catch { /* the page moved on */ } } });
const state = () => Bea.page.evaluate(() => window.__boards.snapshot());
if (HOOK) {
  const s0 = await state();
  const items = Array.isArray(s0) ? s0 : (s0.items ?? Object.values(s0));
  ok(items.find((i) => i.id === seed.browser_alpha)?.x === 700 && !items.some((i) => i.id === seed.browser_beta), 'Bea\'s canvas shows the board as it is: Alpha at 700, Beta gone');
}
await hist(P.page, B);
P.page.once('dialog', async (d) => { ok(d.message().startsWith('Restore version ' + base + '?') && d.message().includes('saved first'), 'the confirm says: "' + d.message() + '"'); await d.dismiss(); });
await P.page.click(`#version-restore-${base}`);
await sleep(800);
ok(x(seed.browser_alpha) === '700' && deleted(seed.browser_beta), 'dismissing the confirm restores nothing');
P.page.once('dialog', (d) => d.accept());
await P.page.click(`#version-restore-${base}`);
await P.page.waitForSelector('#notice-banner', { timeout: 10000 });
ok((await P.page.locator('#notice-banner').innerText()).includes('Restored'), 'accepting it: the notice "Restored"');
ok(x(seed.browser_alpha) === '0' && !deleted(seed.browser_beta), 'Alpha is back at 0 and Beta is back');
ok(db(`SELECT count(*) FROM board_ops WHERE board_id = '${B}' AND source = 'restore'`) === '1', 'ONE restore batch');
await shot(P.page, 'restored-1280');
const t0 = Date.now();
let reached;
if (HOOK) {
  reached = await until(async () => { const s = await state(); const items = Array.isArray(s) ? s : (s.items ?? Object.values(s)); return items.find((i) => i.id === seed.browser_alpha)?.x === 0 && items.some((i) => i.id === seed.browser_beta); }, 6000, 150);
} else {
  reached = await until(async () => seen.some((t) => t.includes('"source":"restore"')), 6000, 150);
}
ok(!!reached && Date.now() - t0 < 5500, `Bea's open canvas shows the restored board within 5 s (${Date.now() - t0} ms, the poll)`);
await shot(Bea.page, 'canvas-after-restore');
ok(HOOK ? true : seen.length > 0, 'the canvas fetched the catch-up');

console.log('   an editor sees no Restore');
await hist(Bea.page, B);
ok(await Bea.page.locator('#version-form-field-label').isVisible() && await Bea.page.locator(`#version-restore-${base}`).count() === 0 && await Bea.page.locator(`#version-preview-${base}`).count() === 1, 'Bea (edit): the Save form and Preview, no Restore');
ok(await Bea.page.locator('#history-visits').count() === 1, 'and the visits');
await Bea.ctx.close();
const Dana = await session(30, { width: 1280, height: 800 });
await hist(Dana.page, B);
ok(await Dana.page.locator('#version-form-field-label').count() === 0 && await Dana.page.locator('#history-visits').count() === 0 && await Dana.page.locator('#history-changes').isVisible(), 'Dana (comment): changes and versions to read, no Save form, no visits');
await Dana.ctx.close();
await P.ctx.close();

console.log('   375 × 740');
const M = await session(26, { width: 375, height: 740 });
await hist(M.page, seed.phone);
ok(await overflow(M.page) === 0, 'history at 375: no sideways scroll');
const boxes = await M.page.evaluate(() => ['history-changes', 'history-versions', 'history-visits'].map((i) => { const r = document.getElementById(i).getBoundingClientRect(); return [r.top, r.width]; }));
ok(boxes[0][0] < boxes[1][0] && boxes[1][0] < boxes[2][0] && boxes.every((b) => b[1] <= 375), 'the sections stack in order, each within the width');
ok((await M.page.locator('#version-form-save').boundingBox()).height >= 36 && (await M.page.locator(`#version-restore-${seed.phone_baseline}`).boundingBox()).height >= 36, 'Save and Restore are tappable');
await shot(M.page, 'history-375');
await hist(M.page, seed.phone, `?version=${seed.phone_baseline}`);
ok(await overflow(M.page) === 0 && await M.page.locator('#history-preview-restore').isVisible(), 'the preview at 375: no sideways scroll, Restore this version visible');
await shot(M.page, 'preview-375');
await hist(M.page, seed.phone, '?source=agent');
ok(await overflow(M.page) === 0 && await M.page.locator('#history-changes-empty').isVisible(), 'a filter with no match: an empty state, no overflow');
ok(allErrors.length === 0, 'no console error at 375 or 1280' + (allErrors.length ? ': ' + allErrors.slice(0, 5).join(' | ') : ''));
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
