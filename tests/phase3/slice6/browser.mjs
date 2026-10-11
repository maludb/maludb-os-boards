// Headless Chromium proof of slice 6 — comments, mentions and notifications (docs/build-specs/comments-notifications.md "Proof" 8): the Comment tool (a click on an item or on the
// empty canvas opens the rail's new-thread form with the anchor), "@" in the composer opens the record picker and the pick is inserted as @Name, the thread in the rail, the pin that
// appears for the writer at once and for another person within the 15 s poll, a pin's click, ?comment= flying to it, reply / resolve / Show resolved, the comments page, the bell,
// a notice that opens its board, my settings — at 375 × 740 and 1280 × 800, no sideways scroll, no console error. Under BD_APP=apache there is no test hook: the proof clicks an
// empty point and asserts on the database. Run through tests/phase3/slice6/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9101';
const SHOTS = process.env.SHOTS || '/tmp/bd-shots-s6';
const KEY = process.env.ACTION_TOKEN_KEY;
const seed = JSON.parse(fs.readFileSync(process.env.BD_S6_SEED, 'utf8'));
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
const open = async (page, board, query = '') => {
  await page.goto(`${BASE}/boards/${board}${query}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
  if (HOOK) await page.evaluate(() => window.__boards.ready());
};
const stageBox = (page) => page.locator('#board-stage').boundingBox();
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const roots = (board) => db(`SELECT id FROM comments WHERE board_id = '${board}' AND parent_comment_id IS NULL AND deleted_at IS NULL ORDER BY created_at`).split('\n').filter(Boolean);
const pins = (page) => page.locator('#board-pins button.board-pin[data-pin]').count();
const screenOf = (page, id) => page.evaluate((i) => window.__boards.screenOf(i), id);
/** A point on the stage where no item is (the corner below the items). */
const emptyPoint = async (page) => { const sb = await stageBox(page); return { x: sb.x + sb.width * 0.5, y: sb.y + sb.height - 90 }; };
const shot = (page, name) => page.screenshot({ path: `${SHOTS}/${name}.png` });
const B = seed.browser;

console.log('8. The Comment tool, the composer, the pin');
const P = await session(26, { width: 1280, height: 800 });
await open(P.page, B);
if (HOOK) await P.page.evaluate(() => window.__boards.setCamera(0, 0, 1));
ok(await P.page.locator('#board-tool-comment').isVisible() && !(await P.page.locator('#board-tool-comment').isDisabled()), 'the Comment tool is on the toolbar, enabled');
await P.page.click('#board-tool-comment');
ok(await P.page.locator('#board-tool-comment').evaluate((e) => e.classList.contains('active')), 'picking it turns it on');
let at;
if (HOOK) { const s = await screenOf(P.page, seed.browser_sticky); at = { x: s.x, y: s.y }; } else { at = await emptyPoint(P.page); }
await P.page.mouse.click(at.x, at.y);
await P.page.waitForSelector('#comment-new:not(.d-none)', { timeout: 6000 });
const where = await P.page.locator('#comment-new-where').innerText();
ok(HOOK ? where.includes("on 'Shipped late'") : where.includes('at a point'), `a click opens the rail's new-thread form: "${where}"`);
ok(await P.page.locator('#board-pin-new').count() === 1 && await P.page.evaluate(() => document.activeElement && document.activeElement.id === 'comment-form-field-text'), 'a marker shows where it will go; the text field has the focus');
const anchor = await P.page.evaluate(() => { const f = document.getElementById('comment-new-form'); const v = (n) => f.elements.namedItem(n).value; return { item: v('item'), x: v('x'), y: v('y') }; });
ok(HOOK ? anchor.item === seed.browser_sticky && anchor.x !== '' : anchor.item === '' && anchor.x !== '', `the form carries the anchor (${HOOK ? 'the sticky and an offset' : 'a point'})`);
ok(await P.page.evaluate(() => document.getElementById('board-tool-comment') && !document.getElementById('board-tool-comment').classList.contains('active')), 'the tool goes back to Select after the click');
// "@" opens the picker; the pick is inserted
await P.page.keyboard.type('Please see ');
await P.page.keyboard.type('@');
await P.page.waitForSelector('#record-picker.show', { timeout: 5000 });
await P.page.fill('#record-picker-search', 'Bea');
await P.page.waitForSelector('#record-picker-row-28', { timeout: 5000 });
ok((await P.page.locator('#record-picker-row-28').innerText()).includes('SMOKE Bea'), 'typing "@" opens the people-and-agents picker; searching "Bea" finds SMOKE Bea');
await P.page.click('#record-picker-row-28');
await P.page.waitForFunction(() => document.getElementById('comment-form-field-text').value.includes('@SMOKE Bea'), null, { timeout: 4000 });
const val = await P.page.inputValue('#comment-form-field-text');
ok(val === 'Please see @SMOKE Bea ', `the pick is inserted over the "@": "${val}"`);
await P.page.waitForFunction(() => !document.getElementById('record-picker').classList.contains('show') && document.activeElement && document.activeElement.id === 'comment-form-field-text', null, { timeout: 5000 });
await P.page.keyboard.type('about this.');
ok((await P.page.inputValue('#comment-form-field-text')).endsWith('@SMOKE Bea about this.'), 'typing goes on after the name (the focus came back)');
await shot(P.page, 'composer-1280');
const before = roots(B).length;
await P.page.click('#comment-new-form-send');
await P.page.waitForSelector('#comment-rail-thread .comment-thread', { timeout: 8000 });
const made = roots(B);
ok(made.length === before + 1 && db(`SELECT count(*) FROM comment_mentions WHERE comment_id = '${made[made.length - 1]}' AND kind = 'member' AND principal_id = 28`) === '1', 'Send posts the thread (HTMX into the rail): one comment, mentioning Bea');
const t1 = made[made.length - 1];
ok((await P.page.locator('#comment-rail-thread').innerText()).includes('about this.') && await P.page.locator(`#comment-thread-${t1}`).count() === 1, 'the rail shows the thread');
ok(await P.page.locator('#comment-new').evaluate((e) => e.classList.contains('d-none')) && await P.page.locator('#board-pin-new').count() === 0, 'the new-thread form closes and its marker goes');
const pinSel = `#pin-${t1}`;
ok(await until(async () => (await P.page.locator(pinSel).count()) === 1, 4000), 'the pin appears for the writer at once (not after the 15 s poll)');
ok(await P.page.locator(pinSel).isVisible(), 'and it is on the screen');
await shot(P.page, 'pin-1280');
// the thread's controls in the rail
await P.page.fill(`#comment-reply-${t1}`, 'A first reply.');
await P.page.click(`#comment-reply-form-${t1}-send`);
await P.page.waitForFunction((id) => document.getElementById('comment-thread-' + id)?.innerText.includes('A first reply.'), t1, { timeout: 6000 });
ok(db(`SELECT count(*) FROM comments WHERE parent_comment_id = '${t1}'`) === '1', 'a reply in the rail: the thread is re-rendered with it');
ok(await until(async () => (await P.page.locator(`${pinSel} .board-pin-count`).count()) === 1 && (await P.page.locator(`${pinSel} .board-pin-count`).innerText()) === '1', 4000), 'the pin\'s badge reads 1 reply');
ok(await P.page.locator('#comment-rail-list .comment-card').count() >= 1 && (await P.page.locator('#comment-rail-list').innerText()).includes('about this'), 'the rail lists the board\'s threads as cards');

console.log('   another person sees the pin within the poll; the bell; ?comment= flies to it');
const Bea = await session(28, { width: 1280, height: 800 });
await open(Bea.page, B);
ok(await until(async () => (await pins(Bea.page)) === 1, 20000, 250), 'Bea\'s canvas shows the pin (read by the 15 s poll)');
ok(await Bea.page.locator('#bell-count').count() === 1 && Number(await Bea.page.locator('#bell-count').innerText()) >= 1, 'Bea\'s bell shows what she was told');
// Priya posts another thread while Bea is looking: Bea's pin count follows within 15 s
const sb = await stageBox(P.page);
await P.page.click('#board-tool-comment');
await P.page.mouse.click(sb.x + 140, sb.y + sb.height - 120);
await P.page.waitForSelector('#comment-new:not(.d-none)');
await P.page.keyboard.type('A second thought.');
await P.page.click('#comment-new-form-send');
await P.page.waitForSelector('#comment-rail-thread .comment-thread', { timeout: 8000 });
const t2 = roots(B).pop();
ok(await until(async () => (await Bea.page.locator(`#pin-${t2}`).count()) === 1, 21000, 300), 'a second thread by Priya appears on Bea\'s canvas within the 15 s poll');
// ?comment= by the reply's id: fly to the thread's pin, open it, mark it
const reply = db(`SELECT id FROM comments WHERE parent_comment_id = '${t1}' LIMIT 1`);
await open(Bea.page, B, `?comment=${reply}`);
await Bea.page.waitForSelector(`#comment-rail-thread #comment-thread-${t1}`, { timeout: 12000 });
ok(await Bea.page.locator(`#pin-${t1}.board-pin-focused`).count() === 1, '?comment=<a reply> opens its THREAD in the rail and marks the thread\'s pin');
if (HOOK) {
  const pos = await Bea.page.evaluate((id) => { const el = document.getElementById('pin-' + id).getBoundingClientRect(); const s = document.getElementById('board-stage').getBoundingClientRect(); return { x: el.left - s.left, y: el.top - s.top, w: s.width, h: s.height }; }, t1);
  ok(pos.x > 0 && pos.x < pos.w && pos.y > 0 && pos.y < pos.h, 'the view is on the pin (it is inside the canvas)');
}
ok((await Bea.page.locator('#comment-rail-thread').innerText()).includes('about this.') && (await Bea.page.locator('#comment-rail-thread').innerText()).includes('A first reply.'), 'the rail shows both comments');
// a pin's click opens the other thread
await Bea.page.click(`#pin-${t2}`);
await Bea.page.waitForSelector(`#comment-rail-thread #comment-thread-${t2}`, { timeout: 6000 });
ok((await Bea.page.locator('#comment-rail-thread').innerText()).includes('A second thought.') && await Bea.page.locator(`#pin-${t2}.board-pin-focused`).count() === 1, 'a pin\'s click opens its thread in the rail');
// Resolve → the pin goes (resolved are hidden); Show resolved brings it back
await Bea.page.click(`#comment-resolve-${t2}`);
await Bea.page.waitForSelector(`#comment-resolved-${t2}`, { timeout: 6000 });
ok(await until(async () => (await Bea.page.locator(`#pin-${t2}`).count()) === 0, 5000), 'Resolve: the thread says Resolved and its pin leaves the canvas');
await Bea.page.check('#comment-rail-resolved');
ok(await until(async () => (await Bea.page.locator(`#pin-${t2}.board-pin-resolved`).count()) === 1, 6000), '"Show resolved" brings it back, dimmed');
await Bea.page.click(`#comment-reopen-${t2}`);
await Bea.page.waitForSelector(`#comment-resolve-${t2}`, { timeout: 6000 });
ok(db(`SELECT resolved_at IS NULL FROM comments WHERE id = '${t2}'`) === 't', 'Reopen puts it back');
// an owner who has nothing to say to a stranger: the share prompt in the rail
await P.page.click('#board-tool-comment');
await P.page.mouse.click(sb.x + 300, sb.y + sb.height - 120);
await P.page.waitForSelector('#comment-new:not(.d-none)');
await P.page.keyboard.type('Expert, what do you think? ');
await P.page.keyboard.type('@');
await P.page.waitForSelector('#record-picker.show');
await P.page.fill('#record-picker-search', 'Expert');
await P.page.waitForSelector('#record-picker-row-40');
await P.page.click('#record-picker-row-40');
await P.page.waitForFunction(() => !document.getElementById('record-picker').classList.contains('show') && document.activeElement && document.activeElement.id === 'comment-form-field-text', null, { timeout: 5000 });
await P.page.click('#comment-new-form-send');
await P.page.waitForSelector('#needs-share-40', { timeout: 8000 });
ok((await P.page.locator('#needs-share-40').innerText()).includes('SMOKE Expert cannot see this board. Share it at edit?') && await P.page.locator('#needs-share-40-share').isVisible(), 'a mentioned agent who cannot see the board: the rail asks "Share it at edit?"');
await P.page.click('#needs-share-40-share');
await P.page.waitForFunction(() => document.getElementById('needs-share-40')?.innerText.includes('can see this board now'), null, { timeout: 6000 });
ok(db(`SELECT level FROM board_permissions WHERE board_id = '${B}' AND principal_kind = 'agent' AND principal_id = 40`) === 'edit', 'Share posts board_share at edit; the prompt turns into a confirmation');
await shot(P.page, 'share-prompt-1280');

console.log('   the comments page, the notices, my settings');
await Bea.page.goto(`${BASE}/boards/${B}/comments`, { waitUntil: 'networkidle' });
ok(await Bea.page.locator(`#comment-card-${t1}`).count() === 1 && await overflow(Bea.page) === 0, 'the comments page lists the threads as cards');
await Bea.page.click(`#comment-open-${t1}`);
await Bea.page.waitForSelector(`#comment-slot-${t1} #comment-thread-${t1}`, { timeout: 6000 });
ok((await Bea.page.locator(`#comment-slot-${t1}`).innerText()).includes('A first reply.'), 'a card opens its thread inline');
await Bea.page.fill(`#comment-slot-${t1} #comment-reply-${t1}`, 'Bea answers on the page.');
await Bea.page.click(`#comment-slot-${t1} #comment-reply-form-${t1}-send`);
await Bea.page.waitForFunction((id) => document.getElementById('comment-slot-' + id)?.innerText.includes('Bea answers on the page.'), t1, { timeout: 6000 });
ok(db(`SELECT count(*) FROM comments WHERE parent_comment_id = '${t1}'`) === '2', 'a reply on the page: the thread inline is re-rendered');
await shot(Bea.page, 'comments-page-1280');
execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-X', '-q', '-d', process.env.DB_NAME, '-c', `INSERT INTO notifications (member_id, kind, title, body, board_id) VALUES (28, 'share', 'SMOKE Priya shared a board with you', 'You can open it to edit.', '${B}')`]);   // a second notice, so Mark all read has something to do after one is opened
await Bea.page.goto(`${BASE}/notifications`, { waitUntil: 'networkidle' });
const unreadBefore = Number(db(`SELECT count(*) FROM notifications WHERE member_id = 28 AND read_at IS NULL`));
ok(await Bea.page.locator('#notifications-list .notification-row.unread').count() >= 1 && await Bea.page.locator('#notifications-read-all').count() === 1, 'the notices page: unread rows marked, Mark all read');
const firstId = (await Bea.page.locator('#notifications-list .notification-row').first().getAttribute('id')).replace('notification-row-', '');
await shot(Bea.page, 'notifications-1280');
await Bea.page.click(`#notification-open-${firstId}`);
await Bea.page.waitForURL(/\/boards\/[0-9a-f-]{36}(\?comment=.*)?$/, { timeout: 10000 });
ok(db(`SELECT read_at IS NOT NULL FROM notifications WHERE id = ${firstId}`) === 't' && Number(db(`SELECT count(*) FROM notifications WHERE member_id = 28 AND read_at IS NULL`)) === unreadBefore - 1, 'a notice opens its board and is marked read');
await Bea.page.waitForSelector('#board-canvas.ready', { timeout: 15000 });
await Bea.page.goto(`${BASE}/notifications`, { waitUntil: 'networkidle' });
await Bea.page.click('#notifications-read-all');
await Bea.page.waitForSelector('#notice-banner', { timeout: 6000 });
ok(db(`SELECT count(*) FROM notifications WHERE member_id = 28 AND read_at IS NULL`) === '0' && await Bea.page.locator('#bell-count').count() === 0, 'Mark all read: nothing unread, the bell is empty');
await Bea.page.goto(`${BASE}/settings/`, { waitUntil: 'networkidle' });
await Bea.page.uncheck('#settings-form-field-kinds-share');
await Bea.page.check('#settings-form-field-digest');
await Bea.page.click('#settings-form-save-btn');
await Bea.page.waitForSelector('#notice-banner', { timeout: 6000 });
ok(db(`SELECT digest FROM notification_prefs WHERE member_id = 28`) === 't' && !db(`SELECT kinds FROM notification_prefs WHERE member_id = 28`).includes('share'), 'my settings: unchecking a kind and taking the digest saves');
await shot(Bea.page, 'settings-1280');
await Bea.page.check('#settings-form-field-text_enabled');
ok(await Bea.page.locator('#settings-form').innerText().then((t) => t.includes('verified phone')), 'the page says the kernel keeps the phone and the opt-out');
await Bea.ctx.close();

console.log('   a commenter: the tool, not the editing tools');
const Dana = await session(30, { width: 1280, height: 800 });
await open(Dana.page, B);
ok(await Dana.page.locator('#board-tool-comment').isVisible() && await Dana.page.locator('#board-tool-sticky').count() === 0, 'Dana (comment level) has the Comment tool and no Sticky');
await Dana.page.click('#board-tool-comment');
const ds = await stageBox(Dana.page);
await Dana.page.mouse.click(ds.x + 200, ds.y + ds.height - 100);
await Dana.page.waitForSelector('#comment-new:not(.d-none)', { timeout: 6000 });
await Dana.page.keyboard.type('From a commenter.');
await Dana.page.click('#comment-new-form-send');
await Dana.page.waitForSelector('#comment-rail-thread .comment-thread', { timeout: 8000 });
ok(db(`SELECT count(*) FROM comments c JOIN members m ON m.id = c.author_member_id WHERE c.board_id = '${B}' AND m.id = 30`) === '1', 'a commenter comments');
await Dana.ctx.close();
await P.ctx.close();

console.log('   375 × 740');
const M = await session(26, { width: 375, height: 740 });
await open(M.page, seed.phone);
ok(await M.page.locator('#board-tool-comment').isVisible() && (await M.page.locator('#board-tool-comment').boundingBox()).width >= 44, 'at 375 the Comment tool is on the bar (44 px), not behind More');
await M.page.click('#board-tool-comment');
const mb = await stageBox(M.page);
await M.page.mouse.click(mb.x + mb.width / 2, mb.y + mb.height / 2 - 160);
await M.page.waitForSelector('#comment-new:not(.d-none)', { timeout: 8000 });
ok(await M.page.evaluate(() => document.getElementById('board-rail').classList.contains('open')) && await overflow(M.page) === 0, 'the rail opens as the phone\'s sheet; no sideways scroll');
await M.page.fill('#comment-form-field-text', 'From a phone.');
await shot(M.page, 'phone-composer');
await M.page.click('#comment-new-form-send');
await M.page.waitForSelector('#comment-rail-thread .comment-thread', { timeout: 8000 });
const pr = roots(seed.phone);
ok(pr.length === 1 && await until(async () => (await pins(M.page)) === 1, 5000), 'a comment from the phone; its pin appears');
await shot(M.page, 'phone-pin');
for (const [name, url, sel] of [['comments', `/boards/${B}/comments`, '#board-comments-filters'], ['notifications', '/notifications', '#notifications-content'], ['settings', '/settings/', '#settings-form']]) {
  await M.page.goto(BASE + url, { waitUntil: 'networkidle' });
  ok(await M.page.locator(sel).isVisible() && await overflow(M.page) === 0, `${name} at 375: renders, no sideways scroll`);
  await shot(M.page, `phone-${name}`);
}
await M.page.goto(`${BASE}/boards/${B}/comments`, { waitUntil: 'networkidle' });
ok(await M.page.locator('#comments-filter-open').isVisible() && (await M.page.locator('#comments-filter-open').boundingBox()).height >= 36, 'the filters are tappable chips');
await M.page.click('#comments-filter-resolved');
await M.page.waitForFunction(() => location.search.includes('open=0'), null, { timeout: 5000 });
ok(true, 'a filter chip navigates (HTMX, URL pushed)');
ok(allErrors.length === 0, 'no console error at 375 or 1280' + (allErrors.length ? ': ' + allErrors.slice(0, 5).join(' | ') : ''));
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
