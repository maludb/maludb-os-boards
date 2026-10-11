// Headless Chromium proof of slice 1 (docs/build-specs/boards-folders.md "Proof" 9): at 375 x 740 and 1280 x 800 — the cards, a new board from
// the form, the settings' swatches, the share page's pickers (a person chosen through the modal and shared), everyone's level asking first,
// the star inline, a folder, the trash; no horizontal scroll, every control at least 44 px, no console errors; with JavaScript off every form
// saves (a picker pre-selected by the query string, as the design system's record-picker rule says).
// Run through tests/phase3/slice1/run.sh. Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';

const BASE = 'http://127.0.0.1:9101';
const SHOTS = process.env.SHOTS || '/tmp/bd-shots-s1';
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.boards.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + s + ' select, ' + s + ' .board-swatch')]
  .filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44); }).map((a) => (a.id || a.className.split(' ')[0] || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);
/** Do something that posts over HTMX and wait for that answer to land (never for a banner an earlier answer left on the page). */
const posted = async (page, path, action) => {
  const [res] = await Promise.all([page.waitForResponse((r) => r.url().endsWith(path) && r.request().method() === 'POST'), action()]);
  const loc = res.headers()['hx-location'];
  if (loc) { const to = JSON.parse(loc).path.split('?')[0]; await page.waitForResponse((r) => r.url().includes(to) && r.request().method() === 'GET').catch(() => {}); }
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(150);
};
const pick = async (page, field, q, label) => {
  await page.click(`#${field}-open`);
  await page.waitForSelector('#record-picker.show');
  await page.fill('#record-picker-search', q);
  await page.waitForSelector(`#record-picker-results .record-picker-row[data-label="${label}"]`);
  await page.click(`#record-picker-results .record-picker-row[data-label="${label}"]`);
  await page.waitForSelector('#record-picker', { state: 'hidden' });
};

let board = '';
// ---- phone: Priya ----
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  console.log('375 x 740 — Priya, a Member');
  await page.click('#tab-boards');
  await page.waitForSelector('#boards-cards');
  ok(await page.isVisible('#boards-add-btn') && await overflow(page) === 0, 'the Boards tab: the list with New board in its header, no sideways scroll');
  await page.click('#boards-add-btn');
  await page.waitForURL(BASE + '/boards/new');
  await page.fill('#board-form-field-title', 'SMOKE Browser board');
  await page.fill('#board-form-field-description', 'Made at 375 px.');
  const smallF = await smallControls(page, '#board-form');
  ok(smallF.length === 0, 'every control on the new-board form is at least 44 px' + (smallF.length ? ': ' + smallF.join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-new-board.png`, fullPage: true });
  await page.click('#board-form-save-btn');
  await page.waitForURL(/\/boards\/[0-9a-f-]{36}$/);
  board = page.url().split('/').pop();
  await page.waitForSelector('#nav-stub-line');
  ok((await page.locator('#nav-stub-line').innerText()).includes('slice 2') && (await page.locator('.page-header-title').innerText()).includes('SMOKE Browser board'), 'saved: it lands on the board (the canvas is slice 2\'s), named');
  await page.goto(BASE + '/boards', { waitUntil: 'networkidle' });
  ok(await page.isVisible(`#board-card-${board}`) && await overflow(page) === 0, 'the list shows its card, no sideways scroll');
  const card = await page.locator(`#board-card-${board}`).boundingBox();
  ok(card.width <= 375 && card.width >= 320, `the card takes the phone's width (${Math.round(card.width)} px)`);
  const smallL = await smallControls(page, '#boards-list-content');
  ok(smallL.length === 0, 'every control on the list is at least 44 px' + (smallL.length ? ': ' + smallL.slice(0, 6).join(', ') : ''));
  await posted(page, '/boards/favorite.php', () => page.click(`#board-card-${board}-star button`));
  await page.waitForSelector(`#board-card-${board}-star button[aria-pressed="true"]`);
  ok(page.url() === BASE + '/boards', 'the star turns on in place, the page stays');
  await page.screenshot({ path: `${SHOTS}/phone-boards.png`, fullPage: true });
  await page.goto(BASE + `/boards/${board}/settings`, { waitUntil: 'networkidle' });
  ok(await overflow(page) === 0, 'no sideways scroll on the settings');
  await page.click('.board-swatch:has(input[value="#fff9db"])');
  await page.selectOption('#board-form-field-background', 'grid');
  await posted(page, '/boards/save.php', () => page.click('#board-form-save-btn'));
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved') && await page.isChecked('input[name="background_color"][value="#fff9db"]'), 'a swatch and Grid saved, back on the settings with the notice');
  const smallS = await smallControls(page, '#board-settings-content');
  ok(smallS.length === 0, 'every control on the settings is at least 44 px' + (smallS.length ? ': ' + smallS.slice(0, 6).join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-settings.png`, fullPage: true });
  await page.click('#board-settings-share');
  await page.waitForURL(BASE + `/boards/${board}/share`);
  await pick(page, 'share-add-member', 'bea', 'SMOKE Bea');
  ok((await page.locator('#share-add-member-open').innerText()).includes('SMOKE Bea'), 'the member picker: searched "bea", chose SMOKE Bea');
  await posted(page, '/boards/share-set.php', () => page.click('#share-add-member-btn'));
  await page.waitForSelector('#share-row-member-28');
  ok((await page.inputValue('#share-row-member-28-level')) === 'edit', 'Shared: her row, at edit (the default for a person)');
  await pick(page, 'share-add-department', 'sales', 'Sales');
  await page.selectOption('#share-add-department-level', 'comment');
  await posted(page, '/boards/share-set.php', () => page.click('#share-add-department-btn'));
  await page.waitForSelector('#share-row-department-8');
  ok((await page.inputValue('#share-row-department-8-level')) === 'comment', 'the department picker: Sales, at comment');
  await posted(page, '/boards/share-set.php', () => page.selectOption('#share-row-member-28-level', 'view'));
  ok((await page.inputValue('#share-row-member-28-level')) === 'view', 'changing a row\'s level saves on change');
  await posted(page, '/boards/access-set.php', () => page.selectOption('#share-everyone-level', 'view'));
  await page.waitForSelector('#share-everyone-confirm');
  ok((await page.locator('#share-everyone-confirm-text').innerText()).includes('will be able to view this board'), 'widening everyone\'s level asks first');
  await posted(page, '/boards/access-set.php', () => page.click('#share-everyone-confirm-btn'));
  await page.waitForSelector('#share-everyone-confirm', { state: 'detached' });       // the answer is HX-Location: the share page follows
  ok((await page.inputValue('#share-everyone-level')) === 'view' && (await page.locator('#share-everyone-confirm').count()) === 0, '…Confirm saves it');
  ok(await overflow(page) === 0, 'no sideways scroll on the share page');
  const smallSh = await smallControls(page, '#board-share-content');
  ok(smallSh.length === 0, 'every control on the share page is at least 44 px' + (smallSh.length ? ': ' + smallSh.slice(0, 6).join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-share.png`, fullPage: true });
  page.once('dialog', (d) => d.accept());
  await posted(page, '/boards/share-remove.php', () => page.click('#share-row-member-28-remove'));
  await page.waitForSelector('#share-row-member-28', { state: 'detached' });
  ok(true, 'Remove (with the confirm) takes her row away');
  await page.goto(BASE + '/folders/new', { waitUntil: 'networkidle' });
  await page.fill('#folder-form-field-name', 'SMOKE Browser folder');
  await page.click('#folder-form-save-btn');
  await page.waitForURL(/\/folders\/\d+/);
  ok((await page.locator('#boards-empty').innerText()).includes('File a board here'), 'a new folder, empty, says how to file a board');
  await page.goto(BASE + `/boards/${board}/settings`, { waitUntil: 'networkidle' });
  await pick(page, 'board-settings-folder-add-field', 'browser', 'SMOKE Browser folder');
  await posted(page, '/folders/add.php', () => page.click('#board-settings-folder-add-btn'));
  await page.waitForSelector('.folder-chip');
  ok((await page.locator('#board-settings-folders').innerText()).includes('SMOKE Browser folder'), 'filed from the settings: the folder\'s chip');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: Marco, a Boards admin ----
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800 — Marco, a Boards admin');
  await page.goto(BASE + '/boards', { waitUntil: 'networkidle' });
  ok(await page.isVisible(`#board-card-${board}`) && await overflow(page) === 0, 'Priya\'s board, open to everyone at view, is on his list; no sideways scroll');
  ok((await page.locator(`#board-card-${board} .level-badge`).innerText()) === 'Full', 'a Boards admin holds it at Full');
  await page.click('#boards-filter-mine');
  await page.waitForURL(/owner=me/);
  await page.waitForSelector('#boards-cards');
  ok(!(await page.isVisible(`#board-card-${board}`)) && (await page.locator('#boards-filter-mine[aria-current="true"]').count()) === 1, 'Mine: not Priya\'s board (the chip marked)');
  await page.click('#boards-filter-all');
  await page.waitForURL(BASE + '/boards');
  await page.fill('#boards-filter-q', 'browser');
  await page.press('#boards-filter-q', 'Enter');
  await page.waitForURL(/q=browser/);
  ok(await page.isVisible(`#board-card-${board}`), 'the search finds it by its title');
  await page.screenshot({ path: `${SHOTS}/desktop-boards.png` });
  await page.goto(BASE + `/boards/${board}/share`, { waitUntil: 'networkidle' });
  ok(await page.isVisible('#share-owner-transfer') && await overflow(page) === 0, 'the share page at 1280 with Transfer');
  await page.screenshot({ path: `${SHOTS}/desktop-share.png`, fullPage: true });
  await page.goto(BASE + '/folders', { waitUntil: 'networkidle' });
  ok(await overflow(page) === 0, 'no sideways scroll on the folders');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: every form saves ----
{
  const { ctx, page } = await session(26, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off — Priya');
  await page.goto(BASE + '/boards/new', { waitUntil: 'load' });
  await page.fill('#board-form-field-title', 'SMOKE No script');
  await page.click('#board-form-save-btn');
  await page.waitForURL(/\/boards\/[0-9a-f-]{36}$/);
  const nb = page.url().split('/').pop();
  ok(nb.length === 36, 'the new-board form saves without JavaScript');
  await page.goto(BASE + `/boards/${nb}/settings`, { waitUntil: 'load' });
  await page.fill('#board-form-field-title', 'SMOKE No script, renamed');
  await page.click('#board-form-save-btn');
  await page.waitForURL(/notice=saved/);
  ok((await page.inputValue('#board-form-field-title')) === 'SMOKE No script, renamed', 'the settings save');
  await page.goto(BASE + `/boards/${nb}/share`, { waitUntil: 'load' });
  await page.selectOption('#share-everyone-level', 'comment');
  await page.click('#share-everyone-form button');                          // the <noscript> Save — real markup when scripting is off
  await page.waitForSelector('#share-everyone-confirm-btn');
  await page.click('#share-everyone-confirm-btn');
  await page.waitForURL(/notice=access/);
  ok((await page.inputValue('#share-everyone-level')) === 'comment', 'everyone\'s level: the question, then Confirm, saves');
  await page.goto(BASE + '/folders/new', { waitUntil: 'load' });
  await page.fill('#folder-form-field-name', 'SMOKE No-script folder');
  await page.click('#folder-form-save-btn');
  await page.waitForURL(/\/folders\/\d+\?notice=created/);
  const fid = page.url().match(/\/folders\/(\d+)/)[1];
  ok(Number(fid) > 0, 'a folder saves');
  await page.goto(BASE + `/boards/new?folder=${fid}`, { waitUntil: 'load' });
  ok((await page.inputValue('#board-form-field-folder')) === fid, 'the new-board form pre-selects the folder from the query string (the picker needs JavaScript)');
  await page.fill('#board-form-field-title', 'SMOKE Filed without script');
  await page.click('#board-form-save-btn');
  await page.waitForURL(/\/boards\/[0-9a-f-]{36}$/);
  await page.goto(BASE + `/folders/${fid}`, { waitUntil: 'load' });
  ok((await page.locator('#boards-grid').innerText()).includes('SMOKE Filed without script'), '…and the board lands in it');
  await page.goto(BASE + `/boards/${nb}/settings`, { waitUntil: 'load' });
  await page.click('#board-settings-delete');
  await page.waitForURL(/notice=deleted/);
  await page.goto(BASE + '/trash', { waitUntil: 'load' });
  await page.click(`#trash-card-${nb}-restore`);
  await page.waitForURL(/notice=restored/);
  ok((await page.locator('#notice-banner').innerText()).includes('Brought back'), 'delete and restore save');
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
