// Headless Chromium proof of slice 8 — templates, duplicate and export (docs/build-specs/templates-export.md "Proof" 6): the board menu's Export entries (the PNG of the view, of the selection and of a frame
// made by the island and downloaded — read back as real PNGs of the right size —, the SVG of the board and of a frame from the server's door, the greyed entries on an empty board), each logged; the gallery at
// 375 and 1280 (no sideways scroll), Use opened in place and a board made from a template into a folder chosen through the record picker, Duplicate and Save as template from the settings page at 375,
// Publish for an admin only (with its confirm); no console error. Run through tests/phase3/slice8/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9101';
const SHOTS = process.env.SHOTS || '/tmp/bd-shots-s8';
const KEY = process.env.ACTION_TOKEN_KEY;
const seed = JSON.parse(fs.readFileSync(process.env.BD_S8_SEED, 'utf8'));
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const HOOK = process.env.BD_APP !== 'apache';
const RETRO = '7e000000-0000-4000-8000-000000000001';
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
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, isMobile: touch, hasTouch: touch, acceptDownloads: true });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error' && !(m.text().includes('status of 429') && (m.location()?.url ?? '').endsWith('/thumbnail'))) allErrors.push(`${member}@${viewport.width}: ${m.text()} ${m.location()?.url ?? ''}`); });
  page.on('pageerror', (e) => allErrors.push(`${member}@${viewport.width}: pageerror ${e.message}`));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const shot = (page, name) => page.screenshot({ path: `${SHOTS}/${name}.png` });
const open = async (page, board, q = '') => {
  await page.goto(`${BASE}/boards/${board}${q}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
  if (HOOK) await page.evaluate(() => window.__boards.ready());
  await sleep(300);
};
const menu = async (page) => { if (!(await page.locator('#board-menu').isVisible())) { await page.click('#board-menu-btn'); await page.waitForSelector('#board-menu.show'); } };
const closeMenu = async (page) => { if (await page.locator('#board-menu').isVisible()) { await page.keyboard.press('Escape'); await page.waitForSelector('#board-menu.show', { state: 'hidden' }); } };
const disabled = (page, id) => page.evaluate((i) => document.getElementById(i)?.classList.contains('disabled') ?? null, id);
const png = (buf) => ({ magic: buf.subarray(0, 8).toString('hex') === '89504e470d0a1a0a', w: buf.readUInt32BE(16), h: buf.readUInt32BE(20) });
const download = async (page, click) => { const [dl] = await Promise.all([page.waitForEvent('download'), click()]); const path = await dl.path(); return { name: dl.suggestedFilename(), buf: fs.readFileSync(path) }; };
const exports = (board) => db(`SELECT after::text FROM activity_log WHERE action = 'board.export' AND board_id = '${board}' ORDER BY id`).split('\n').filter(Boolean).map((l) => JSON.parse(l));
const pick = async (page, field, q, label) => {
  await page.click(`#${field}-open`);
  await page.waitForSelector('#record-picker.show');
  await page.fill('#record-picker-search', q);
  await page.waitForSelector(`#record-picker-results .record-picker-row[data-label="${label}"]`);
  await page.click(`#record-picker-results .record-picker-row[data-label="${label}"]`);
  await page.waitForSelector('#record-picker', { state: 'hidden' });
};

console.log('6. Export from the canvas (1280 × 800)');
const P = await session(26, { width: 1280, height: 800 });
await open(P.page, seed.empty);
await menu(P.page);
ok(await P.page.locator('#export-menu').isVisible() && await P.page.locator('#export-png-view').isVisible() && await P.page.locator('#export-svg-board').isVisible(), 'the board menu has the Export group: PNG of the view, the selection, a frame; SVG of the board and of a frame');
ok(await disabled(P.page, 'export-png-selection') === true && await disabled(P.page, 'export-png-frame') === true && await disabled(P.page, 'export-svg-frame') === true && await disabled(P.page, 'export-png-view') === false,
  'on an empty board with nothing selected: the selection and frame entries are greyed, the view is not');
const es = await download(P.page, () => P.page.click('#export-svg-board'));
ok(es.name === 'SMOKE Export empty.svg' && es.buf.toString().startsWith('<svg') && es.buf.toString().includes('data-items="0"'), `the SVG of an empty board downloads as an empty <svg>: ${es.name}`);
await closeMenu(P.page);

await open(P.page, seed.canvas);
await menu(P.page);
await shot(P.page, 'export-menu-1280');
const stage = await P.page.locator('#board-stage').boundingBox();
const pv = await download(P.page, () => P.page.click('#export-png-view'));
const pi = png(pv.buf);
ok(pi.magic && pv.buf.length > 1000 && pv.name === 'SMOKE Export canvas — view.png', `PNG of the view: a PNG of ${pv.buf.length} bytes named ${pv.name}`);
ok(Math.abs(pi.w - stage.width * 2) <= 2 && Math.abs(pi.h - stage.height * 2) <= 2, `at twice the screen's pixels: ${pi.w} × ${pi.h} for a stage of ${Math.round(stage.width)} × ${Math.round(stage.height)}`);
fs.writeFileSync(`${SHOTS}/export-view.png`, pv.buf);
await until(() => exports(seed.canvas).length >= 1);
let lg = exports(seed.canvas);
ok(lg.length === 1 && lg[0].format === 'png' && lg[0].scope === 'view' && lg[0].items >= 4, 'and logged as board.export: png, view, items ' + lg[0]?.items);
await closeMenu(P.page);

// the selection: select all, then the menu enables the entry
await P.page.locator('#board-stage').focus();
await P.page.keyboard.press('Control+a');
await sleep(300);
await menu(P.page);
ok(await disabled(P.page, 'export-png-selection') === false, 'with a selection the entry is enabled');
const ps = await download(P.page, () => P.page.click('#export-png-selection'));
const si = png(ps.buf);
ok(si.magic && ps.name === 'SMOKE Export canvas — selection.png' && si.w === 3496 && si.h === 1296, `PNG of the selection: everything and 24 around, at 2 px a unit: ${si.w} × ${si.h} (3496 × 1296)`);
fs.writeFileSync(`${SHOTS}/export-selection.png`, ps.buf);
await until(() => exports(seed.canvas).length >= 2);
ok(exports(seed.canvas)[1]?.scope === 'selection', 'logged with scope selection');
await closeMenu(P.page);

// a frame: the view centred in it
await open(P.page, seed.canvas, '?x=0&y=0&zoom=0.5');
await menu(P.page);
ok(await disabled(P.page, 'export-png-frame') === false && await disabled(P.page, 'export-svg-frame') === false && await disabled(P.page, 'export-png-selection') === true, 'with the view centred in the frame Plan: the frame entries are enabled, the selection one is not');
const pf = await download(P.page, () => P.page.click('#export-png-frame'));
const fi = png(pf.buf);
ok(fi.magic && pf.name === 'SMOKE Export canvas — Plan.png' && fi.w === 1600 && fi.h === 1200, `PNG of a frame: the frame's box at 2 px a unit: ${fi.w} × ${fi.h} (1600 × 1200), named for the frame`);
fs.writeFileSync(`${SHOTS}/export-frame.png`, pf.buf);
await until(() => exports(seed.canvas).length >= 3);
ok(exports(seed.canvas)[2]?.scope === 'frame', 'logged with scope frame');
await menu(P.page);                                                // choosing an entry closed the menu
const href = await P.page.getAttribute('#export-svg-frame', 'href');
ok(href === `/boards/${seed.canvas}/export?format=svg&frame=${seed.canvas_frame}`, 'the SVG-of-a-frame link points at that frame: ' + href);
const fsvg = await download(P.page, () => P.page.click('#export-svg-frame'));
const ft = fsvg.buf.toString();
ok(fsvg.name === 'SMOKE Export canvas — Plan.svg' && ft.startsWith('<svg') && ft.includes('data-frame="' + seed.canvas_frame + '"') && ft.includes('>Alpha<') && !ft.includes('>Gamma<'), `the frame's SVG from the server: ${fsvg.name}, Alpha in it, Gamma not`);
await closeMenu(P.page);
await menu(P.page);
const bsvg = await download(P.page, () => P.page.click('#export-svg-board'));
ok(bsvg.name === 'SMOKE Export canvas.svg' && bsvg.buf.toString().includes('>Gamma<') && bsvg.buf.toString().includes('data-items="5"'), `the board's SVG: ${bsvg.name}, all five items`);
await closeMenu(P.page);
lg = exports(seed.canvas);
ok(lg.length === 5 && lg[3].format === 'svg' && lg[3].scope === 'frame' && lg[4].scope === 'board', 'every export is logged: three PNGs and two SVGs');
// the PNG is the picture of the board: sample pixels (the sticky Gamma's yellow, the white page)
const px = await P.page.evaluate(async (b64) => {
  const img = new Image(); img.src = 'data:image/png;base64,' + b64; await img.decode();
  const c = document.createElement('canvas'); c.width = img.width; c.height = img.height; const g = c.getContext('2d'); g.drawImage(img, 0, 0);
  const at = (x, y) => Array.from(g.getImageData(x, y, 1, 1).data);
  return { corner: at(2, 2), alpha: at(400, 400), mid: at(Math.floor(img.width / 2), Math.floor(img.height / 2)) };
}, pf.buf.toString('base64'));
ok(px.corner[3] === 255 && px.corner[0] > 240 && px.corner[1] > 240 && px.corner[2] > 240, 'the frame\'s PNG is opaque: the page colour first, then the frame (corner ' + px.corner.join(',') + ')');
ok(px.alpha[0] > 240 && px.alpha[1] > 200 && px.alpha[2] < 220 && px.alpha[2] > 100, 'and Alpha\'s yellow sticky is in it (' + px.alpha.join(',') + ')');
await P.ctx.close();

console.log('   the same menu, read-only (Dana, view) and at 375');
const D = await session(30, { width: 375, height: 740 });
await open(D.page, seed.canvas);
await menu(D.page);
ok(await D.page.locator('#export-menu').isVisible() && await D.page.locator('#board-menu-duplicate').count() === 0 && await D.page.locator('#board-menu-template').count() === 0,
  'a Viewer exports (boards.export) but is offered no Duplicate or Save as template');
ok(await overflow(D.page) === 0, 'no sideways scroll at 375');
await shot(D.page, 'export-menu-375');
const dp = await download(D.page, () => D.page.click('#export-png-view'));
ok(png(dp.buf).magic, 'and her PNG of the view downloads');
await D.ctx.close();

console.log('   the gallery (1280 and 375)');
const G = await session(26, { width: 1280, height: 800 });
await G.page.goto(BASE + '/templates?scope=shipped', { waitUntil: 'networkidle' });
ok(await G.page.locator('#templates-grid').isVisible() && await G.page.locator('[id^="template-card-"][id$="-link"]').count() === 6 && await overflow(G.page) === 0, 'six shipped cards at 1280, no sideways scroll');
await shot(G.page, 'gallery-1280');
await G.page.click('#template-filter-category-planning');
await G.page.waitForFunction(() => document.querySelectorAll('[id^="template-card-"][id$="-link"]').length === 2);
ok(G.page.url().includes('category=planning') && await G.page.locator('#template-filter-category-planning[aria-current="true"]').count() === 1, 'the category chip filters to the two planning templates');
await G.page.click('#template-filter-scope-business');
await G.page.waitForSelector('#templates-empty');
ok(await G.page.locator('#templates-empty').isVisible(), '…and the business\'s scope has none yet: an empty state');
await G.page.goto(BASE + '/templates', { waitUntil: 'networkidle' });
// Use, in place
ok(!(await G.page.locator(`#template-use-form-${RETRO}`).isVisible()), 'the Use form is closed on the card');
await G.page.click(`#template-use-open-${RETRO}`);
await G.page.waitForSelector(`#template-use-form-${RETRO}`, { state: 'visible' });
ok(await G.page.locator(`#template-form-field-title-${RETRO}`).inputValue() === 'Retrospective', 'Use opens the form in place: the title is the template\'s');
await G.page.fill(`#template-form-field-title-${RETRO}`, 'SMOKE Browser retro');
await Promise.all([G.page.waitForURL(/\/boards\/[0-9a-f-]{36}$/), G.page.click(`#template-use-btn-${RETRO}`)]);
await G.page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
const madeId = G.page.url().split('/').pop();
ok(db(`SELECT title || '|' || owner_member_id || '|' || item_count FROM boards WHERE id = '${madeId}'`) === 'SMOKE Browser retro|26|7', 'Use made Priya a board of her own with 7 items, and landed on its canvas');
// the template's page, the folder through the picker
await G.page.goto(BASE + '/templates/' + RETRO, { waitUntil: 'networkidle' });
ok(await G.page.locator('#template-outline').innerText().then((t) => t.includes('Went well') && t.includes('What went well?')) && await overflow(G.page) === 0, 'the template\'s page shows its outline');
await shot(G.page, 'template-1280');
await pick(G.page, 'template-form-field-folder', 'SMOKE Browser', 'SMOKE Browser folder');
await G.page.fill('#template-form-field-title', 'SMOKE Browser retro 2');
await Promise.all([G.page.waitForURL(/\/boards\/[0-9a-f-]{36}$/), G.page.click(`#template-use-btn-${RETRO}`)]);
await G.page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
const made2 = G.page.url().split('/').pop();
ok(db(`SELECT count(*) FROM board_folder_entries WHERE folder_id = ${seed.folder} AND board_id = '${made2}'`) === '1', 'a folder chosen through the record picker: the new board is filed there');
await G.page.goto(BASE + '/templates/' + RETRO, { waitUntil: 'networkidle' });
await Promise.all([G.page.waitForURL(/\/boards\/[0-9a-f-]{36}$/), G.page.click('#template-open')]);
await G.page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
ok(await G.page.locator('#board-level-badge').innerText().then((t) => /Can view/.test(t)), 'Open on the canvas: the shipped template, at "Can view" for a Member');
await G.ctx.close();

const M = await session(26, { width: 375, height: 740 });
await M.page.goto(BASE + '/templates', { waitUntil: 'networkidle' });
ok(await overflow(M.page) === 0 && await M.page.locator('#templates-grid').isVisible(), 'the gallery at 375: no sideways scroll');
await shot(M.page, 'gallery-375');
await M.page.click(`#template-use-open-${RETRO}`);
ok(await M.page.locator(`#template-use-btn-${RETRO}`).isVisible() && await overflow(M.page) === 0 && (await M.page.locator(`#template-use-btn-${RETRO}`).boundingBox()).height >= 36, 'Use at 375: the form fits and its button is tappable');
await shot(M.page, 'gallery-use-375');
await M.page.goto(BASE + '/templates/' + RETRO, { waitUntil: 'networkidle' });
ok(await overflow(M.page) === 0, 'a template\'s page at 375: no sideways scroll');
await shot(M.page, 'template-375');

console.log('   Duplicate, Save as template and Publish from the settings page');
await M.page.goto(`${BASE}/boards/${seed.phone}/settings`, { waitUntil: 'networkidle' });
ok(await M.page.locator('#board-actions').isVisible() && await M.page.locator('#board-duplicate-btn').isVisible() && await M.page.locator('#board-template-btn').isVisible() && await M.page.locator('#board-publish-form').count() === 0 && await overflow(M.page) === 0,
  'Priya (a Member) at 375: Duplicate and Save as template, no Publish, no sideways scroll');
await shot(M.page, 'settings-375');
await M.page.fill('#template-form-field-title', 'SMOKE Browser copy');
await Promise.all([M.page.waitForURL(/\/boards\/[0-9a-f-]{36}$/), M.page.click('#board-duplicate-btn')]);
await M.page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
ok(db(`SELECT count(*) FROM boards WHERE title = 'SMOKE Browser copy' AND owner_member_id = 26 AND duplicated_from = '${seed.phone}'`) === '1', 'Duplicate made the copy and landed on it');
await M.page.goto(`${BASE}/boards/${seed.phone}/settings`, { waitUntil: 'networkidle' });
await M.page.fill('#template-form-field-title-save', 'SMOKE Browser template');
await M.page.selectOption('#template-form-field-category-save', 'workshop');
await Promise.all([M.page.waitForURL(/\/templates\/[0-9a-f-]{36}/), M.page.click('#board-template-btn')]);
await M.page.waitForSelector('#notice-banner');
ok((await M.page.locator('#notice-banner').innerText()).includes('Saved as a template of yours') && await M.page.locator('#template-scope').innerText() === 'Mine'
  && db(`SELECT template_scope || '|' || template_category FROM boards WHERE title = 'SMOKE Browser template'`) === 'personal|workshop', 'Save as template: a personal template of workshop, the page says "Mine" with its notice');
await shot(M.page, 'template-saved-375');
await M.ctx.close();

const A = await session(27, { width: 375, height: 740 });
await A.page.goto(`${BASE}/boards/${seed.phone}/settings`, { waitUntil: 'networkidle' });
ok(await A.page.locator('#board-publish-form').isVisible(), 'Marco (a Boards admin) is offered Publish for the business');
await A.page.fill('#template-form-field-title-publish', 'SMOKE Browser published');
A.page.once('dialog', async (d) => { ok(d.message().includes('Every Member will see it'), 'the confirm: "' + d.message() + '"'); await d.dismiss(); });
await A.page.click('#board-publish-btn');
await sleep(600);
ok(db(`SELECT count(*) FROM boards WHERE title = 'SMOKE Browser published'`) === '0', 'dismissing the confirm publishes nothing');
A.page.once('dialog', (d) => d.accept());
await Promise.all([A.page.waitForURL(/\/templates\/[0-9a-f-]{36}/), A.page.click('#board-publish-btn')]);
await A.page.waitForSelector('#notice-banner');
ok((await A.page.locator('#notice-banner').innerText()).includes('Published') && await A.page.locator('#template-scope').innerText() === "The business's" && await overflow(A.page) === 0, 'accepting it: the business\'s template, with the notice');
await A.ctx.close();
const B = await session(28, { width: 1280, height: 800 });
await B.page.goto(BASE + '/templates?scope=business', { waitUntil: 'networkidle' });
ok((await B.page.locator('[id^="template-card-"][id$="-link"]').allInnerTexts()).includes('SMOKE Browser published'), 'Bea sees it in the gallery under The business\'s');
await B.ctx.close();
ok(allErrors.length === 0, 'no console error at 375 or 1280' + (allErrors.length ? ': ' + allErrors.slice(0, 5).join(' | ') : ''));
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
