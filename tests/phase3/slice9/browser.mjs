// Headless Chromium proof of slice 9 — search and the admin pages (docs/build-specs/agents-search.md "Proof" 9): search at 375 and 1280 (the box, the kind chips, the board filter through the record picker, the marked
// words, "Nothing found", a result opening the canvas flown to and selecting the item), the agents page and the dispatches page at both widths (the cards, the filters, Retry from a failed row), a Member refused;
// no sideways scroll, no console error. Run through tests/phase3/slice9/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9101';
const SHOTS = process.env.SHOTS || '/tmp/bd-shots-s9';
const KEY = process.env.ACTION_TOKEN_KEY;
const seed = JSON.parse(fs.readFileSync(process.env.BD_S9_SEED, 'utf8'));
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
const allErrors = [];
const browser = await chromium.launch();
async function session(member, viewport) {
  const touch = viewport.width < 600;
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, isMobile: touch, hasTouch: touch });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error') allErrors.push(`${member}@${viewport.width}: ${m.text()} ${m.location()?.url ?? ''}`); });
  page.on('pageerror', (e) => allErrors.push(`${member}@${viewport.width}: pageerror ${e.message}`));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const shot = (page, name) => page.screenshot({ path: `${SHOTS}/${name}.png` });
const pick = async (page, field, q, label) => {
  await page.click(`#${field}-open`);
  await page.waitForSelector('#record-picker.show');
  await page.fill('#record-picker-search', q);
  await page.waitForSelector(`#record-picker-results .record-picker-row[data-label="${label}"]`);
  await page.click(`#record-picker-results .record-picker-row[data-label="${label}"]`);
  await page.waitForSelector('#record-picker', { state: 'hidden' });
};
const go = async (page, path) => { await page.goto(BASE + path, { waitUntil: 'networkidle' }); await sleep(200); };

for (const [w, h] of [[375, 740], [1280, 800]]) {
  console.log(`9. Search at ${w} × ${h}`);
  const P = await session(26, { width: w, height: h });
  await go(P.page, '/search');
  ok(await P.page.locator('#search-prompt').isVisible() && await P.page.locator('#search-form-field-q').isVisible(), 'the empty search asks for two characters and has the box');
  await P.page.fill('#search-form-field-q', 'okapi');
  await Promise.all([P.page.waitForURL(/q=okapi/), P.page.click('#search-submit')]);
  await P.page.waitForSelector('#search-results');
  const card = `#search-result-item-${seed.search_sticky}`;
  ok(await P.page.locator(card).isVisible() && (await P.page.locator(`${card} mark`).first().innerText()).toLowerCase() === 'okapi', 'a sticky\'s word finds its item, the matched word marked');
  ok(await P.page.locator(`#search-result-item-${seed.phone_sticky}`).isVisible(), '…on every board Priya may open');
  ok((await overflow(P.page)) <= 0, 'no sideways scroll');
  await shot(P.page, `search-${w}`);
  // the comment kind
  await P.page.click('#search-filter-kind-comment');
  await P.page.waitForURL(/kind=comment/);
  await go(P.page, '/search?q=tapir&kind=comment');
  ok(await P.page.locator(`#search-result-comment-${seed.search_comment}`).isVisible() && await P.page.locator(`#search-result-item-${seed.search_sticky}`).count() === 0, 'the Comments chip: only comments');
  // the board filter through the record picker
  await go(P.page, '/search?q=okapi');
  await pick(P.page, 'search-form-field-board', 'Search browser', 'SMOKE Search browser');
  await Promise.all([P.page.waitForURL(new RegExp(`board=${seed.search}`)), P.page.click('#search-submit')]);
  ok(await P.page.locator(`#search-result-item-${seed.search_sticky}`).isVisible() && await P.page.locator(`#search-result-item-${seed.phone_sticky}`).count() === 0, 'the board filter (the record picker) narrows to one board');
  // nothing found
  await go(P.page, '/search?q=zzzqqqxx');
  ok(await P.page.locator('#search-empty').isVisible() && (await P.page.locator('#search-empty').innerText()).includes('Try fewer words.'), '"Nothing found" with the tip "Try fewer words."');
  // a result opens the canvas, flown to and selecting the item
  await go(P.page, '/search?q=okapi&board=' + seed.search);
  await Promise.all([P.page.waitForURL(/\/boards\/.*item=/), P.page.click(`#search-result-item-${seed.search_sticky}-link`)]);
  await P.page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
  if (HOOK) {
    await P.page.evaluate(() => window.__boards.ready());
    await sleep(500);
    const sel = await P.page.evaluate(() => window.__boards.selection());
    ok(sel.join() === seed.search_sticky, 'a result opens the board with the item selected');
  } else {
    ok(P.page.url().includes('item=' + seed.search_sticky), 'a result opens the board at the item');
  }
  // a Member without the right
  await P.ctx.close();
}

for (const [w, h] of [[375, 740], [1280, 800]]) {
  console.log(`   the admin pages at ${w} × ${h}`);
  // the earlier proofs of this suite wrote many dispatches; the fixtures of the seed are brought to the top of the newest-first list
  db(`UPDATE agent_dispatches SET created_at = now() + interval '1 hour', status = CASE id WHEN ${seed.search_failed} THEN 'failed' ELSE status END, run_id = CASE id WHEN ${seed.search_failed} THEN NULL ELSE run_id END WHERE id IN (${seed.search_answered}, ${seed.search_failed}, ${seed.search_awaiting_approval})`);
  const M = await session(27, { width: w, height: h });
  await go(M.page, '/admin/agents');
  ok(await M.page.locator('#agent-card-42').isVisible() && await M.page.locator('#agent-card-40').isVisible() && await M.page.locator('#admin-agents-os').isVisible(), 'the agents page: a card per agent and the note with the OS link');
  ok((await M.page.locator('#agent-card-42').innerText()).includes('SMOKE Facilitator') && (await M.page.locator('#agent-card-42-boards').innerText()).includes('boards it can open'), 'the Facilitator\'s card says which boards it can open');
  ok((await overflow(M.page)) <= 0, 'no sideways scroll on the agents page');
  await shot(M.page, `admin-agents-${w}`);
  await go(M.page, '/admin/dispatches');
  ok(await M.page.locator(`#dispatch-row-${seed.search_answered}`).isVisible() && await M.page.locator(`#dispatch-row-${seed.search_failed}`).isVisible() && await M.page.locator(`#dispatch-row-${seed.search_awaiting_approval}`).isVisible(), 'the dispatches page: a row each');
  ok((await M.page.locator(`#dispatch-status-${seed.search_failed}`).innerText()) === 'Failed' && (await M.page.locator(`#dispatch-status-${seed.search_awaiting_approval}`).innerText()) === 'Waiting for approval' && (await M.page.locator(`#dispatch-status-${seed.search_answered}`).innerText()) === 'Answered',
    'statuses in words: Failed, Waiting for approval, Answered');
  ok(await M.page.locator(`#dispatch-retry-${seed.search_failed}`).isVisible() && await M.page.locator(`#dispatch-retry-${seed.search_answered}`).count() === 0, 'Retry only on a failed one or one waiting');
  ok((await overflow(M.page)) <= 0, 'no sideways scroll on the dispatches page');
  await shot(M.page, `admin-dispatches-${w}`);
  await M.page.click('#dispatch-filter-status-failed');
  await M.page.waitForURL(/status=failed/);
  await M.page.waitForSelector(`#dispatch-row-${seed.search_failed}`);
  ok(await M.page.locator(`#dispatch-row-${seed.search_answered}`).count() === 0, 'the Failed chip narrows the list');
  await pick(M.page, 'dispatch-filter-agent', 'Facilitator', 'SMOKE Facilitator');
  await Promise.all([M.page.waitForURL(/agent=42/), M.page.click('#dispatch-filter-apply')]);
  ok(await M.page.locator(`#dispatch-row-${seed.search_failed}`).isVisible(), 'the agent filter (the record picker) keeps it');
  if (w === 375) {
    await go(M.page, '/admin/dispatches?status=failed');
    await M.page.click(`#dispatch-retry-${seed.search_failed}`);
    await M.page.waitForSelector('#notice-banner');
    ok((await M.page.locator('#notice-banner').innerText()).includes('Back in the queue') && db(`SELECT status FROM agent_dispatches WHERE id = ${seed.search_failed}`) === 'sent', 'Retry puts the failed one back in the queue: the banner and the row (sent)');
    await shot(M.page, 'admin-dispatches-retried-375');
  }
  await M.ctx.close();
}

console.log('   a Member');
const B = await session(28, { width: 1280, height: 800 });
const code = async (path) => (await B.ctx.request.get(BASE + path, { headers: { Accept: 'text/html' }, failOnStatusCode: false })).status();
ok((await code('/admin/agents')) === 403 && (await code('/admin/dispatches')) === 403, 'a Member is refused both admin pages (403)');
await go(B.page, '/search?q=okapi');
ok(await B.page.locator(`#search-result-item-${seed.search_sticky}`).isVisible(), 'but searches (Bea sees the board shared with her)');
await B.ctx.close();
await browser.close();
ok(allErrors.length === 0, 'no console error' + (allErrors.length ? ': ' + allErrors.slice(0, 5).join(' | ') : ''));
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
