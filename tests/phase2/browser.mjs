// Headless Chromium proof of the shell (docs/build-specs/sso-shell.md, "Proof" 9):
//   at 375 x 740 and 1280 x 800 — scrollWidth = viewport; the bottom tab bar (Home · Boards · Search · Me) on a phone and the sidebar on a
//   desktop; the offcanvas from Me; the home's one live link; the header search on a desktop and the magnifier on a phone; the placeholders
//   reached by HTMX (URL pushed, title, highlight, the screen stamped for the command bar); a board's placeholder behind its wall; the bell;
//   the command bar answering the fake kernel and following a navigate; a token minted and shown once, then revoked with the confirm; every
//   control >= 44 px; no console errors; every placeholder whole with JavaScript off; the web app manifest installable.
// Run through tests/phase2/run.sh (it starts the servers and passes the environment). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9101';
const SHOTS = process.env.SHOTS || '/tmp/bd-shots';
const KEY = process.env.ACTION_TOKEN_KEY;
const STATE = process.env.FAKE_KERNEL_STATE;
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.boards.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const urls = { get member() { return mint(26); }, get admin() { return mint(1); }, get viewer() { return mint(30); } };
const kernelState = (change) => { const s = JSON.parse(fs.readFileSync(STATE, 'utf8')); const n = change(s) || s; fs.writeFileSync(STATE, JSON.stringify(n)); };
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const board = execFileSync('php', [new URL('./seed.php', import.meta.url).pathname], { encoding: 'utf8' }).trim();

const browser = await chromium.launch();
async function session(signOn, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(signOn, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0 && r.right > 0 && r.left < window.innerWidth; }, sel);
const toggleMenu = (page) => page.evaluate(() => document.getElementById('mobile-collapse').click());
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]), ' + s + ' select, ' + s + ' textarea, ' + s + ' .app-tab')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);

// ---- phone: a Member (Priya) ----
{
  const { ctx, page, errors } = await session(urls.member, { width: 375, height: 740 });
  console.log('375 x 740 — a Member');
  ok(page.url() === BASE + '/', 'signed on and landed on Home (' + page.url() + ')');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the home (scrollWidth ${o.sw} = ${o.iw})`);
  ok(await shown(page, '#tab-bar') && !(await shown(page, '#left-sidenav')), 'the bottom tab bar is shown on a phone; the sidebar is not');
  const box = await page.locator('#tab-bar').boundingBox();
  ok(Math.abs(box.y + box.height - 740) < 1 && box.height >= 44, `the tab bar sits on the bottom edge (bottom ${box.y + box.height}, height ${box.height})`);
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.y + bar.height <= box.y + 1 && bar.y > 400, `the command bar sits just above the tab bar (bar bottom ${Math.round(bar.y + bar.height)} <= tab top ${Math.round(box.y)})`);
  const tabs = (await page.locator('#tab-bar .app-tab').allInnerTexts()).map((t) => t.trim());
  ok(tabs.join(',') === 'Home,Boards,Search,Me', `four tabs: ${tabs.join(', ')}`);
  const small = await smallControls(page, '#tab-bar');
  ok(small.length === 0, 'every tab is at least 44 x 44 px' + (small.length ? ': ' + small.join(', ') : ''));
  const homeStub = fs.readFileSync(new URL('../../html/index.php', import.meta.url), 'utf8').includes('render_nav_stub(');       // slice 10 built the home: the page stands in for the placeholder
  ok(homeStub ? (await shown(page, '#nav-stub') && (await page.locator('#nav-stub-line').innerText()).startsWith('Built by slice 10')) : await shown(page, '#home-content'),
    homeStub ? 'the home is slice 10\'s placeholder' : 'the home (slice 10) is there');
  const btn = await page.locator(homeStub ? '#home-boards-link' : '#home-new-board').boundingBox();
  ok(btn.height >= 44 && btn.x >= 0 && btn.x + btn.width <= 375, `its one live link, ${homeStub ? 'Boards' : 'New board'}, is ${Math.round(btn.height)} px tall and inside the screen`);
  ok((await page.locator('#header-role-badge').innerText()).includes('Member'), 'the header badge reads Member');
  ok(await shown(page, '#header-search-link') && !(await shown(page, '#header-search')), 'on a phone the header carries the search magnifier, not the box');
  await page.screenshot({ path: `${SHOTS}/phone-home.png` });
  await page.click('#tab-me');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(400);
  const items = await page.evaluate(() => [...document.querySelectorAll('#shell-menu .nxl-item[id^="nav-"]')].map((li) => li.id.slice(4)));
  ok(await shown(page, '#left-sidenav') && items.join(',') === 'boards,templates,folders,trash,notifications,my-settings,tokens,activity',
    'Me opens the sidebar: Boards · Templates · Folders · Trash, then Me — no Admin for a Member (' + items.join(', ') + ')');
  await page.screenshot({ path: `${SHOTS}/phone-sidebar.png` });
  await page.evaluate(() => { const l = document.querySelector('.navbar-content'); l.scrollTop = l.scrollHeight; });
  const lastAfter = await page.locator('#shell-menu .nxl-item:last-child .nxl-link').boundingBox();
  ok(lastAfter.y + lastAfter.height <= 740 - 56, `the last menu item can be scrolled clear of the bars (bottom ${Math.round(lastAfter.y + lastAfter.height)} <= ${740 - 56})`);
  await toggleMenu(page);
  await page.waitForTimeout(400);
  ok(!(await shown(page, '#left-sidenav')), 'the menu button closes it again');
  await page.click('#tab-boards');
  await page.waitForURL(BASE + '/boards');
  await page.waitForSelector('#boards-list-content');
  ok((await page.title()).startsWith('Boards'), 'the Boards tab opens Boards over HTMX (slice 1\'s list since it shipped), title "' + (await page.title()) + '"');
  ok(await page.evaluate(() => document.querySelector('#tab-boards').classList.contains('active') && !document.querySelector('#tab-home').classList.contains('active') && document.getElementById('page-content').dataset.screen === 'boards-list'),
    'the tab is highlighted and the screen stamped boards-list for the command bar');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on Boards');
  await page.goto(BASE + '/boards/' + board, { waitUntil: 'networkidle' });
  if (await page.locator('#board-canvas').count()) {          // slice 2 built the canvas: its top bar names the board and her level
    ok((await page.locator('#board-title').innerText()).includes('SMOKE Q4 retro') && (await page.locator('#board-level-badge .level-badge').innerText()) === 'Owner',
      'a board\'s page (the canvas since slice 2) names the board and her level (Owner)');
  } else {
    ok((await page.locator('#nav-stub-line').innerText()).includes('Built by slice 2') && (await page.locator('.page-header-title').innerText()).includes('SMOKE Q4 retro') && (await page.locator('.level-badge').innerText()) === 'Owner',
      'a board\'s page names the board, her level (Owner) and the slice that builds the canvas');
  }
  for (const p of ['/activity', '/settings/tokens/', '/search', '/notifications', '/templates', '/folders', '/trash', `/boards/${board}/share`]) {
    await page.goto(BASE + p, { waitUntil: 'networkidle' });
    ok((await overflow(page)).sw === 375, 'no sideways scroll on ' + p.replace(board, '{board}'));
  }
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  const smallT = await smallControls(page, '#page-content');
  ok(smallT.length === 0, 'every control on My tokens is at least 44 px' + (smallT.length ? ': ' + smallT.slice(0, 6).join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-tokens.png`, fullPage: true });
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok(!(await shown(page, '#assistant-send-btn')), 'the Send button is hidden while the bar is idle');
  await page.fill('#assistant-input', 'Make a board for the Q4 retro');
  ok(await shown(page, '#assistant-send-btn'), 'and shown once something is typed');
  await page.click('#assistant-send-btn');
  await page.waitForSelector('#assistant-reply-text');
  ok((await page.locator('#assistant-reply-text').innerText()).includes('Hello from the fake expert'), 'the command bar shows the expert\'s reply above the bar');
  await page.screenshot({ path: `${SHOTS}/phone-assistant.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: the super-admin ----
{
  const { ctx, page, errors } = await session(urls.admin, { width: 1280, height: 800 });
  console.log('1280 x 800 — the super-admin');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll (scrollWidth ${o.sw} = ${o.iw})`);
  ok(!(await shown(page, '#tab-bar')), 'the tab bar is hidden on a desktop');
  ok(await shown(page, '#left-sidenav') && await shown(page, '#nav-boards') && await shown(page, '#nav-admin'), 'the sidebar is shown, with Boards … and Admin');
  ok(await shown(page, '#header-search') && !(await shown(page, '#header-search-link')), 'the header carries the search box on a desktop');
  const nav = await page.locator('#left-sidenav').boundingBox();
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.x >= nav.width - 1 && bar.y + bar.height >= 780, `the command bar sits at the bottom, right of the sidebar (x ${Math.round(bar.x)}, sidebar ${Math.round(nav.width)})`);
  ok((await page.locator('#header-role-badge').innerText()).includes('Super-admin'), 'the badge reads Super-admin');
  ok((await page.locator('#bell').count()) === 1 && (await page.locator('#bell-count').count()) === 0, 'the bell, with no count (nothing unread)');
  await page.screenshot({ path: `${SHOTS}/desktop-home.png` });
  await page.fill('#header-search', 'retro');
  await page.press('#header-search', 'Enter');
  await page.waitForURL(/\/search\?q=retro/);
  // the page is slice 9's placeholder until it ships (the suite follows the slices); built, it is the search itself with the words in both boxes
  const searchBuilt = !fs.readFileSync(new URL('../../html/search.php', import.meta.url), 'utf8').includes('render_nav_stub(');
  ok((await page.title()).startsWith('Search') && (await page.inputValue('#header-search')) === 'retro'
    && (searchBuilt ? (await page.inputValue('#search-form-field-q')) === 'retro' : (await page.locator('#nav-stub-line').innerText()).includes('Built by slice 9')),
    'the header box searches: /search?q=retro — ' + (searchBuilt ? 'slice 9\'s search, the words kept in both boxes' : 'slice 9\'s placeholder, the words kept in the box'));
  await page.click('#nav-templates .nxl-link');
  await page.waitForURL(BASE + '/templates');
  await page.waitForSelector('#templates-content');
  const tplStub = fs.readFileSync(new URL('../../html/templates/index.php', import.meta.url), 'utf8').includes('render_nav_stub(');   // slice 8 built it: the gallery stands in for the placeholder
  ok((await page.title()).startsWith('Templates') && (tplStub ? (await page.locator('#nav-stub-line').innerText()).includes('Built by slice 8') : (await page.locator('#templates-grid, #templates-empty').count()) === 1),
    'HTMX navigation: /templates pushed, the title set, ' + (tplStub ? 'the placeholder names slice 8' : 'the gallery (slice 8) is there'));
  ok(await page.evaluate(() => document.querySelector('#nav-templates .nxl-link').classList.contains('active') && !document.querySelector('#nav-boards .nxl-link').classList.contains('active') && document.getElementById('page-content').dataset.screen === 'templates'),
    'the sidebar highlights Templates and the screen is stamped');
  await page.click('#nav-admin .nxl-link');
  await page.waitForURL(BASE + '/admin');
  await page.waitForSelector('#admin-content');
  const adminStub = fs.readFileSync(new URL('../../html/admin/index.php', import.meta.url), 'utf8').includes('render_nav_stub(');
  ok(adminStub ? (await page.locator('#nav-stub-line').innerText()).includes('Built by slice 10') : (await page.locator('#admin-section-usage').count()) === 1, adminStub ? 'Admin: slice 10\'s placeholder' : 'Admin: the page (slice 10) is there');
  await page.goBack();
  await page.waitForURL(BASE + '/templates');
  ok((await page.title()).startsWith('Templates'), 'the browser back button returns to Templates');
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  await page.fill('#token-form-field-label', 'SMOKE proof token');
  await page.click('#token-form-save-btn');
  await page.waitForSelector('#tokens-minted');
  ok((await page.locator('#tokens-minted-value').innerText()).startsWith('mcp_'), 'minting a token shows it once (mcp_…)');
  await page.screenshot({ path: `${SHOTS}/desktop-tokens.png` });
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  ok((await page.locator('#tokens-minted').count()) === 0, 'and never again');
  page.once('dialog', (d) => d.accept());
  await page.click('[id^="token-"][id$="-revoke"]');
  await page.waitForSelector('#token-list .badge:has-text("revoked")');
  ok((await page.locator('#token-list .badge:has-text("revoked")').count()) === 1 && await shown(page, '#notice-banner'), 'revoking it (with the confirm) marks it revoked, with the notice');
  kernelState((s) => { s.chat = { run_id: 9, status: 'succeeded', finished: true, reply: 'Opening your trail.', actions: [], navigate: '/activity' }; return s; });
  await page.fill('#assistant-input', 'show me what I did');
  await page.click('#assistant-send-btn');
  await page.waitForURL(BASE + '/activity');
  await page.waitForSelector('#activity-table');
  ok(page.url() === BASE + '/activity' && (await page.locator('#activity-tbody').innerText()).includes('You made the MCP token'), 'a navigate answer from the command bar opened My trail, which lists the token just made');
  kernelState((s) => { delete s.chat; return s; });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: every placeholder still owed renders (the slices' shipped screens are their own suites') ----
{
  const { ctx, page } = await session(urls.admin, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  const html = new URL('../../html/', import.meta.url).pathname;
  const stub = (f) => fs.existsSync(html + f) && fs.readFileSync(html + f, 'utf8').includes('render_nav_stub(');
  const all = [['/', 'index.php'], [`/boards/${board}`, 'boards/view.php'], [`/boards/${board}/outline`, 'boards/outline.php'], [`/boards/${board}/comments`, 'boards/comments.php'],
    [`/boards/${board}/history`, 'boards/history.php'], ['/templates', 'templates/index.php'], [`/templates/${board}`, 'templates/view.php'], ['/notifications', 'notifications/index.php'],
    ['/settings/', 'settings/index.php'], ['/search', 'search.php'], ['/admin', 'admin/index.php'], ['/admin/usage', 'admin/usage.php'], ['/admin/settings', 'admin/settings.php'],
    ['/admin/agents', 'admin/agents.php'], ['/admin/dispatches', 'admin/dispatches.php']];
  const pages = all.filter(([, f]) => stub(f)).map(([u]) => u);
  const bad = [];
  for (const p of pages) {
    const r = await page.goto(BASE + p, { waitUntil: 'load' });
    if (!(r.status() === 200 && (await page.locator('#nav-stub-line').count()) === 1 && (await page.locator('#tab-bar').count()) === 1)) { bad.push(p + ' ' + r.status()); }
  }
  ok(bad.length === 0, `all ${pages.length} placeholders still owed render whole without JavaScript` + (bad.length ? ': ' + bad.join(', ') : ''));
  await page.goto(BASE + '/', { waitUntil: 'load' });
  ok((await page.locator('#tab-boards').getAttribute('href')) === '/boards' && (await page.locator('#tab-me').getAttribute('href')) === '/activity', 'the tabs are plain links (Me opens My trail)');
  ok((await page.locator('#header-search-form').getAttribute('action')) === '/search' && (await page.locator(fs.readFileSync(new URL('../../html/index.php', import.meta.url), 'utf8').includes('render_nav_stub(') ? '#home-boards-link' : '#home-new-board').getAttribute('href')) === (fs.readFileSync(new URL('../../html/index.php', import.meta.url), 'utf8').includes('render_nav_stub(') ? '/boards' : '/boards/new'), 'the search box is a plain GET form; the home\'s link is a plain link');
  await ctx.close();
}

// ---- installable: the manifest, the icons, no service worker ----
{
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 } });
  const page = await ctx.newPage();
  await page.goto(urls.member, { waitUntil: 'networkidle' });
  console.log('installable');
  const href = await page.getAttribute('link[rel="manifest"]', 'href');
  const res = await page.request.get(BASE + href);
  const m = await res.json();
  ok(res.status() === 200 && /manifest\+json|application\/json/.test(res.headers()['content-type'] || ''), 'the manifest is served (' + res.headers()['content-type'] + ')');
  ok(m.display === 'standalone' && m.start_url === '/' && m.name === 'Boards', 'standalone, start_url /, named Boards');
  const sizes = [];
  for (const i of m.icons) { const r = await page.request.get(BASE + i.src); sizes.push(r.status() === 200 && i.sizes); }
  ok(sizes.includes('192x192') && sizes.includes('512x512'), 'the 192 and 512 px icons are served');
  const sw = await page.evaluate(async () => (await navigator.serviceWorker?.getRegistrations?.() || []).length);
  ok(sw === 0, 'no service worker registered (none in version 1)');
  const html = await page.content();
  ok(html.includes('name="theme-color"') && html.includes('rel="apple-touch-icon"') && html.includes('name="viewport"'), 'theme-color, apple-touch-icon and viewport are set');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
