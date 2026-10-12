// Headless Chromium proof of slice 10 — home, admin, usage, settings and Purge now (docs/build-specs/home-admin.md "Proof" 7): the home at 375 (one column of cards, no sideways scroll) and 1280, the first run, the admin's page,
// usage as cards on a phone and a table on a desktop with the amber and red bars, the settings form saved by hand and refused on its field, the Purge now confirm (dismissed, then accepted: the board and its file gone);
// a Member refused; no console error. Run through tests/phase3/slice10/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9101';
const SHOTS = process.env.SHOTS || '/tmp/bd-shots-s10';
const KEY = process.env.ACTION_TOKEN_KEY;
const seed = JSON.parse(fs.readFileSync(process.env.BD_S10_SEED, 'utf8'));
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
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const allErrors = [];
const browser = await chromium.launch();
async function session(member, viewport) {
  const touch = viewport.width < 600;
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, isMobile: touch, hasTouch: touch });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error' && !/status of 422/.test(m.text())) allErrors  // a refusal the proof asks for is a 422 the browser logs
    .push(`${member}@${viewport.width}: ${m.text()} ${m.location()?.url ?? ''}`); });
  page.on('pageerror', (e) => allErrors.push(`${member}@${viewport.width}: pageerror ${e.message}`));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const shot = (page, name) => page.screenshot({ path: `${SHOTS}/${name}.png` });
const go = async (page, path) => { await page.goto(BASE + path, { waitUntil: 'networkidle' }); await sleep(200); };
const cardWidths = (page, sel) => page.evaluate((s) => [...document.querySelectorAll(s)].map((e) => Math.round(e.getBoundingClientRect().width)), sel);

for (const [w, h] of [[375, 740], [1280, 800]]) {
  console.log(`10. The home at ${w} × ${h}`);
  const P = await session(26, { width: w, height: h });
  await go(P.page, '/');
  ok(await P.page.locator('#home-section-recent').isVisible() && await P.page.locator('#home-section-favourites').isVisible() && await P.page.locator('#home-section-shared').isVisible() && await P.page.locator('#home-section-team-folders').isVisible(), 'the four sections are shown');
  ok(await P.page.locator('#home-new-board').isVisible() && await P.page.locator('#home-new-from-template').isVisible(), 'New board and New from template are shown');
  ok((await P.page.locator('#home-section-recent .board-card').count()) === 8, 'Recent holds eight cards (Priya has more than eight)');
  ok((await P.page.locator('#home-mentions').innerText()).includes('1 mention waiting'), 'the mentions line: "1 mention waiting"');
  ok((await overflow(P.page)) <= 0, 'no sideways scroll');
  if (w === 375) {
    const widths = await cardWidths(P.page, '#home-section-recent .board-card');
    ok(widths.every((x) => x > 300 && x <= 375), 'one column of cards on a phone (widths ' + [...new Set(widths)].join(',') + ')');
    const btn = await P.page.locator('#home-new-board').boundingBox();
    ok(btn.height >= 44, `New board is ${Math.round(btn.height)} px tall`);
  } else {
    const xs = await P.page.evaluate(() => [...new Set([...document.querySelectorAll('#home-section-recent .board-card')].map((e) => Math.round(e.getBoundingClientRect().left)))]);
    ok(xs.length >= 2, 'several columns of cards on a desktop (' + xs.length + ')');
  }
  await shot(P.page, `home-${w}`);
  await P.page.click('#home-section-recent-all');
  await P.page.waitForURL(BASE + '/boards');
  ok(true, 'See all opens the boards list');
  await go(P.page, '/');
  await Promise.all([P.page.waitForURL(/\/boards\/new/), P.page.click('#home-new-board')]);
  ok(true, 'New board opens the form');
  await P.ctx.close();
}

console.log('10. The first run');
{
  const B = await session(28, { width: 375, height: 740 });
  await go(B.page, '/');
  ok(await B.page.locator('#home-first-run').isVisible() && (await B.page.locator('#home-first-run').innerText()).includes('Make your first board'), 'Bea has no board: "Make your first board"');
  ok((await B.page.locator('#home-section-templates .template-card').count()) === 6, 'the six shipped templates are cards');
  ok((await overflow(B.page)) <= 0, 'no sideways scroll on the first run');
  await shot(B.page, 'home-first-run-375');
  await B.ctx.close();
}

for (const [w, h] of [[375, 740], [1280, 800]]) {
  console.log(`10. The admin pages at ${w} × ${h}`);
  const M = await session(27, { width: w, height: h });
  await go(M.page, '/admin');
  ok(await M.page.locator('#admin-section-usage').isVisible() && await M.page.locator('#admin-section-trash').isVisible() && await M.page.locator('#admin-section-agents').isVisible() && await M.page.locator('#admin-section-active').isVisible() && await M.page.locator('#admin-section-settings').isVisible(), 'the admin\'s page: Usage, the trash, Agents, Most active, Settings');
  ok(await M.page.locator(`#trash-row-${seed.trashed_browser}`).isVisible() && await M.page.locator(`#trash-purge-${seed.trashed_browser}`).isVisible(), 'a deleted board is listed with Purge now');
  ok((await overflow(M.page)) <= 0, 'no sideways scroll on the admin page');
  await shot(M.page, `admin-${w}`);
  await go(M.page, '/admin/usage');
  if (w === 375) {
    ok(await M.page.locator(`#usage-card-${seed.amber}`).isVisible() && !(await M.page.locator('#usage-table').isVisible()), 'usage on a phone: cards, no table');
  } else {
    ok(await M.page.locator(`#usage-row-${seed.amber}`).isVisible() && !(await M.page.locator('#usage-cards').isVisible()), 'usage on a desktop: a table, no cards');
  }
  const kind = w === 375 ? 'card' : 'row';
  const status = (id) => M.page.locator(`#usage-${kind}-${id}-bar`).getAttribute('data-status');
  ok((await status(seed.amber)) === 'warning' && (await status(seed.red)) === 'danger', 'the 4,100-item board has an amber bar, the 9,500 one a red bar');
  const fill = await M.page.locator(`#usage-${kind}-${seed.red}-bar .progress-bar`).evaluate((e) => getComputedStyle(e).backgroundColor);
  ok(fill !== 'rgba(0, 0, 0, 0)', 'the bar is drawn (' + fill + ')');
  ok((await overflow(M.page)) <= 0, 'no sideways scroll on usage');
  await shot(M.page, `usage-${w}`);
  await go(M.page, '/admin/usage?owner_left=1');
  ok(await M.page.locator(`#usage-${kind}-${seed.left}-left`).isVisible() && await M.page.locator(`#usage-${kind}-${seed.left}-transfer`).isVisible() && (await M.page.locator(`#usage-${kind}-${seed.amber}-link`).count()) === 0, 'owner_left=1: only the board whose owner has left, with the left badge and Transfer');
  await Promise.all([M.page.waitForURL(/\/share/), M.page.click(`#usage-${kind}-${seed.left}-transfer`)]);
  ok(await M.page.locator('#share-owner-transfer').isVisible(), 'Transfer opens the share page at the owner\'s card');
  await go(M.page, '/admin/usage');
  await M.page.click('#usage-sort-idle');
  await M.page.waitForURL(/sort=idle/);
  const first = await M.page.locator(w === 375 ? '#usage-cards [id^="usage-card-"][id$="-link"]' : '#usage-table tbody tr td a').first().innerText();
  ok(first === 'SMOKE Browser idle', 'the Idle sort puts the board idle 200 days first (' + first + ')');
  await go(M.page, '/admin/settings');
  ok(await M.page.locator('#settings-form').isVisible() && (await overflow(M.page)) <= 0, 'the settings form fits');
  await shot(M.page, `settings-${w}`);
  if (w === 375) {
    await M.page.fill('#settings-form-field-trash_days', '45');
    await M.page.fill('#settings-form-field-business_name', 'SMOKE Browser Co');
    await M.page.click('#settings-form-field-default_everyone_level-view');
    await M.page.click('#settings-form-save-btn');
    await M.page.waitForSelector('#notice-banner');
    ok((await M.page.locator('#notice-banner').innerText()).includes('Saved') && db('SELECT trash_days || business_name || default_everyone_level FROM bd_settings') === '45SMOKE Browser Coview', 'saving by hand: the banner, and the row holds the values');
    ok((await M.page.locator('#settings-form-field-trash_days').inputValue()) === '45', 'the form shows what was saved');
    await M.page.fill('#settings-form-field-item_soft_budget', '20000');
    await M.page.click('#settings-form-save-btn');
    await M.page.waitForSelector('#flash:not(:empty), .alert-danger');
    ok((await M.page.content()).includes('The soft budget must be below the hard cap.') && db('SELECT item_soft_budget FROM bd_settings') === '4000', 'a soft budget above the hard cap is refused in words and nothing changes');
    db("UPDATE bd_settings SET trash_days = 30, business_name = NULL, default_everyone_level = 'none'");
  }
  await M.ctx.close();
}

console.log('10. Purge now');
{
  const M = await session(27, { width: 1280, height: 800 });
  await go(M.page, '/admin');
  const id = seed.trashed_browser;
  ok(fs.existsSync(seed.trashed_browser_file), 'the board\'s file is in the store');
  let msg = '';
  M.page.once('dialog', async (d) => { msg = d.message(); await d.dismiss(); });
  await M.page.click(`#trash-purge-${id}`);
  await sleep(500);
  ok(msg === 'Purge SMOKE Trashed in the browser for good, with its 2 items and files? This cannot be undone.' && await M.page.locator(`#trash-row-${id}`).isVisible() && db(`SELECT count(*) FROM boards WHERE id = '${id}'`) === '1', 'the confirm asks in words; dismissing it purges nothing: ' + msg);
  await shot(M.page, 'purge-confirm-1280');
  M.page.once('dialog', async (d) => { await d.accept(); });
  await M.page.click(`#trash-purge-${id}`);
  await M.page.waitForSelector('#notice-banner');
  ok((await M.page.locator('#notice-banner').innerText()).includes('Purged for good') && await M.page.locator(`#trash-row-${id}`).count() === 0 && db(`SELECT count(*) FROM boards WHERE id = '${id}'`) === '0' && !fs.existsSync(seed.trashed_browser_file),
    'accepting it purges the board and unlinks its file; the page says so');
  await M.ctx.close();
  const P = await session(26, { width: 375, height: 740 });
  const code = async (path) => (await P.ctx.request.get(BASE + path, { headers: { Accept: 'text/html' }, failOnStatusCode: false })).status();
  ok((await code('/admin')) === 403 && (await code('/admin/usage')) === 403 && (await code('/admin/settings')) === 403, 'a Member is refused the admin, usage and settings pages (403)');
  ok(!(await P.page.locator('#nav-admin').count()), 'and has no Admin in the menu');
  await P.ctx.close();
}
await browser.close();
ok(allErrors.length === 0, 'no console error' + (allErrors.length ? ': ' + allErrors.slice(0, 5).join(' | ') : ''));
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
