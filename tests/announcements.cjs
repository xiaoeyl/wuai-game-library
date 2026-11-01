'use strict';
// Real admin-post configuration and anonymous rendering; no test mutation API.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const workspace = path.resolve(__dirname, '..');
const base = 'http://127.0.0.1:8771';
const site = fs.realpathSync(path.join(workspace, 'dist/checks-v1.1.0/site'));
const dist = fs.realpathSync(path.join(workspace, 'dist'));
const output = fs.realpathSync(path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0'));
function inside(child, parent) {
  const relative = path.relative(parent, child);
  return relative === '' || (relative !== '..' && !relative.startsWith('..' + path.sep) && !path.isAbsolute(relative));
}
assert.ok(inside(output, dist) && !inside(output, site));
const manifest = fs.readFileSync(path.join(output, 'announcements-browser-fixture.json'), 'utf8');
const fixture = JSON.parse(manifest);
assert.equal(fixture.marker, 'WUAI_ANNOUNCEMENTS_BROWSER_V1'); assert.equal(fixture.state, 'ready');
assert.equal(fixture.base, base); assert.equal(fs.realpathSync(fixture.root).toLowerCase(), site.toLowerCase());
assert.equal(fixture.credentials_in_manifest, false);
for (const url of [fixture.page_url, fixture.admin_url, fixture.post_url]) assert.equal(new URL(url).origin, base);
const secret = 'qa-local-only';
assert.equal(manifest.includes(secret), false, 'No testing password is stored in the manifest.');
const expectedPosts = new Map(fixture.public_posts.map(item => [item.id, item]));
const expectedTitles = fixture.expected_order.map(id => expectedPosts.get(id).title);
const queryUrl = (url, values) => { const parsed = new URL(url); for (const [key, value] of Object.entries(values)) parsed.searchParams.set(key, value); return parsed.href; };

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const pageErrors = []; const consoleErrors = []; const unexpectedWrites = []; const browserWrites = []; const screenshots = [];
  let isolatedHeartbeats = 0;
  try {
    async function actor(name) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
      contexts.push(context); context.setDefaultTimeout(25000);
      await context.route('**/*', route => {
        const request = route.request(); const url = new URL(request.url());
        if (url.origin !== base) {
          // Neutral placeholder resources avoid network-error console noise while
          // keeping external scripts, images, fonts and trackers off the wire.
          const contentType = { script: 'application/javascript', stylesheet: 'text/css', image: 'image/svg+xml' }[request.resourceType()] || 'text/plain';
          const body = request.resourceType() === 'image' ? '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>' : '';
          return route.fulfill({ status: 200, contentType, body });
        }
        if (!['GET', 'HEAD'].includes(request.method())) {
          const fields = new URLSearchParams(request.postData() || '');
          // Core Heartbeat is not part of announcements and must not write to
          // the shared fixture while a slow screenshot or browser is running.
          if (name !== 'guest' && request.method() === 'POST' && url.pathname === '/wp-admin/admin-ajax.php' && fields.get('action') === 'heartbeat') {
            isolatedHeartbeats++;
            return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ 'wp-auth-check': true, server_time: Math.floor(Date.now() / 1000) }) });
          }
          const permitted = name !== 'guest' && request.method() === 'POST' && (url.pathname === '/wp-login.php' || (url.pathname === '/wp-admin/admin-post.php' && fields.get('action') === 'wuai_sluyu_announcements'));
          if (!permitted) { unexpectedWrites.push(name + ': ' + url.pathname); return route.abort(); }
          browserWrites.push(name + ': ' + url.pathname);
        }
        return route.continue();
      });
      const page = await context.newPage();
      page.on('pageerror', error => pageErrors.push(name + ': ' + error.message));
      page.on('console', message => { if (message.type() === 'error') consoleErrors.push(name + ': ' + message.text()); });
      return { name, page, context };
    }
    function clean(html) { assert.doesNotMatch(html, /Fatal error|Uncaught (?:Error|Exception)|WordPress database error/); assert.equal(html.includes(secret), false, 'No testing password is rendered.'); }
    function mailGuard(response) { assert.equal(response.headers()['x-wuai-announcements-qa-mail-blocked'], '1', 'Isolated mail guard is active.'); }
    async function navigate(who, url) {
      const response = await who.page.goto(url, { waitUntil: 'domcontentloaded' });
      assert.ok(response); assert.equal(response.status(), 200); mailGuard(response);
      const html = await response.text(); clean(html); return html;
    }
    async function login(who) {
      await navigate(who, queryUrl(base + '/wp-login.php', { redirect_to: who.name === 'admin' ? fixture.admin_url : fixture.page_url }));
      await who.page.locator('#user_login').fill(fixture.users[who.name].login);
      await who.page.locator('#user_pass').fill(secret);
      const ready = who.page.waitForURL(url => !url.pathname.includes('wp-login.php')); ready.catch(() => {});
      await Promise.all([ready, who.page.locator('#wp-submit').click()]);
      assert.equal(new URL(who.page.url()).origin, base);
      assert.equal((await who.context.cookies()).some(cookie => cookie.name.startsWith('wordpress_logged_in_')), true, 'Real WordPress login succeeds.');
    }
    const guest = await actor('guest'); const admin = await actor('admin'); const reader = await actor('reader');
    await login(admin); await login(reader);
    const adminForm = () => admin.page.locator('form').filter({ has: admin.page.locator('input[name="action"][value="wuai_sluyu_announcements"]') });
    async function settings() {
      await navigate(admin, fixture.admin_url);
      const form = adminForm(); await form.waitFor();
      return { enabled: await form.locator('input[name="enabled"]').isChecked(), ids: await form.locator('textarea[name="ids"]').inputValue(), nonce: await form.locator('input[name="_wpnonce"]').inputValue() };
    }
    function normalized(value) { return value.split(/[\s,]+/).filter(Boolean).map(Number); }
    async function unchanged(expected) {
      const current = await settings(); assert.equal(current.enabled, expected.enabled); assert.deepEqual(normalized(current.ids), normalized(expected.ids));
    }
    async function rejected(who, fields, status, method = 'POST') {
      const options = { maxRedirects: 0 };
      const response = method === 'GET'
        ? await who.context.request.get(queryUrl(fixture.post_url, fields), options)
        : await who.context.request.post(fixture.post_url, { ...options, form: fields });
      assert.equal(response.status(), status); mailGuard(response);
      assert.equal(response.headers().location, undefined, 'Rejected write cannot masquerade as successful redirect.');
      clean(await response.text());
    }
    async function publicPage(url = fixture.page_url) {
      await navigate(guest, url);
      // Search inputs may echo excluded titles; unrelated preserved widgets may
      // legitimately list a public topic or an unselected public post.
      const announcementHtml = (await guest.page.locator('.sluyu-portal-content .sluyu-announcement, #' + fixture.widget.id).evaluateAll(items => items.map(item => item.innerHTML))).join('\n');
      for (const title of [...fixture.private_titles, fixture.unlisted_title]) assert.equal(announcementHtml.includes(title), false, 'Hidden or unselected content stays out of announcement cards and the real widget.');
      return guest.page.locator('.sluyu-portal-content .sluyu-announcement h2 a').allTextContents();
    }
    const baseline = await settings();
    assert.equal(baseline.enabled, false); assert.deepEqual(normalized(baseline.ids), fixture.initial_ids);
    assert.deepEqual(await publicPage(), []);
    const deniedAdmin = await reader.context.request.get(fixture.admin_url, { maxRedirects: 0 });
    assert.equal(deniedAdmin.status(), 403); mailGuard(deniedAdmin); clean(await deniedAdmin.text());
    const valid = { action: 'wuai_sluyu_announcements', _wpnonce: baseline.nonce, enabled: '1', ids: fixture.selection_order.join('\n') };
    await rejected(admin, valid, 405, 'GET'); await unchanged(baseline);
    await rejected(admin, { ...valid, _wpnonce: 'invalid-qa-nonce' }, 403); await unchanged(baseline);
    await rejected(reader, valid, 403); await unchanged(baseline);
    await rejected(admin, { ...valid, ids: String(fixture.rejected_id) }, 400); await unchanged(baseline);
    const arrayIds = { ...valid, 'ids[]': String(fixture.rejected_id) }; delete arrayIds.ids;
    await rejected(admin, arrayIds, 400); await unchanged(baseline);
    await settings(); const form = adminForm();
    await form.locator('input[name="enabled"]').check();
    await form.locator('textarea[name="ids"]').fill(fixture.selection_order.join('\n'));
    const postResponse = admin.page.waitForResponse(response => response.url() === fixture.post_url && response.request().method() === 'POST'); postResponse.catch(() => {});
    const saved = admin.page.waitForURL(url => url.pathname.endsWith('/admin.php') && url.searchParams.get('saved') === '1'); saved.catch(() => {});
    await Promise.all([saved, form.locator('#submit').click()]);
    const posted = await postResponse; assert.equal(posted.status(), 302); mailGuard(posted);
    assert.equal(await admin.page.locator('.notice-success').count(), 1, 'Successful real form submission displays the saved notice.');
    const active = await settings(); assert.equal(active.enabled, true); assert.deepEqual(normalized(active.ids), fixture.selection_order);
    const first = (await publicPage()).map(value => value.trim()); assert.deepEqual(first, expectedTitles.slice(0, 12));
    const widget = guest.page.locator('#' + fixture.widget.id); await widget.waitFor();
    assert.deepEqual((await widget.locator('.sluyu-widget-list a').allTextContents()).map(text => text.replace(/\d{4}-\d{2}-\d{2}/g, '').trim()), expectedTitles.slice(0, 3));
    assert.equal(await widget.locator('p a').getAttribute('href'), fixture.page_url);
    const pageLinks = await guest.page.locator('.sluyu-portal-content .sluyu-portal-pagination a').evaluateAll(items => items.map(item => item.href));
    const secondPage = pageLinks.find(url => new URL(url).searchParams.get('pg') === '2'); assert.ok(secondPage);
    assert.deepEqual((await publicPage(secondPage)).map(value => value.trim()), expectedTitles.slice(12));
    await publicPage();
    const searchForm = guest.page.locator('.sluyu-portal-content form').filter({ has: guest.page.locator('input[name="announcement_search"]') });
    const target = expectedPosts.get(fixture.expected_order[0]);
    await searchForm.locator('input[name="announcement_search"]').fill(target.title);
    const searched = guest.page.waitForNavigation({ waitUntil: 'domcontentloaded' }); searched.catch(() => {});
    await searchForm.locator('button').click(); const searchResponse = await searched; assert.equal(searchResponse.status(), 200); mailGuard(searchResponse); clean(await searchResponse.text());
    assert.deepEqual((await guest.page.locator('.sluyu-announcement h2 a').allTextContents()).map(value => value.trim()), [target.title]);
    assert.equal(await guest.page.locator('#' + fixture.widget.id + ' .sluyu-widget-list a').count(), 3, 'Widget ignores page search query.');
    const impossible = await publicPage(queryUrl(fixture.page_url, { announcement_search: fixture.private_titles[0] })); assert.deepEqual(impossible, []);
    assert.deepEqual(await publicPage(queryUrl(fixture.page_url, { announcement_search: fixture.unlisted_title })), []);
    const malformed = new URL(fixture.page_url); malformed.searchParams.set('announcement_search[]', 'bad');
    await publicPage(malformed.href); assert.equal(await guest.page.locator('.sluyu-portal-content [role="alert"]').count(), 1);
    const malformedPage = new URL(fixture.page_url); malformedPage.searchParams.set('pg[]', '2');
    await publicPage(malformedPage.href); assert.equal(await guest.page.locator('.sluyu-portal-content [role="alert"]').count(), 1);
    await publicPage(queryUrl(fixture.page_url, { announcement_search: fixture.search, unrelated: 'must-not-carry' }));
    for (const href of await guest.page.locator('.sluyu-portal-content .sluyu-portal-pagination a').evaluateAll(items => items.map(item => item.href))) {
      const parsed = new URL(href); assert.equal(parsed.searchParams.get('announcement_search'), fixture.search); assert.equal(parsed.searchParams.has('unrelated'), false);
    }
    await unchanged(active);
    for (const width of [320, 390, 1440]) {
      await guest.page.setViewportSize({ width, height: width < 500 ? 844 : 1000 });
      await publicPage();
      assert.equal(await guest.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Announcement page/widget has no overflow at ' + width);
      assert.equal(await guest.page.locator('#' + fixture.widget.id).count(), 1);
      const filename = 'announcements-' + width + '.png';
      await guest.page.screenshot({ path: path.join(output, filename), fullPage: true, animations: 'disabled' }); screenshots.push(filename);
    }
    await unchanged(active);
    const settingsForm = adminForm(); await settingsForm.locator('input[name="enabled"]').uncheck();
    const disableResponse = admin.page.waitForResponse(response => response.url() === fixture.post_url && response.request().method() === 'POST'); disableResponse.catch(() => {});
    const disabled = admin.page.waitForURL(url => url.searchParams.get('saved') === '1'); disabled.catch(() => {});
    await Promise.all([disabled, settingsForm.locator('#submit').click()]);
    const disabledPost = await disableResponse; assert.equal(disabledPost.status(), 302); mailGuard(disabledPost);
    assert.deepEqual(await publicPage(), []); assert.equal(await guest.page.locator('#' + fixture.widget.id + ' .sluyu-widget-list a').count(), 0);
    assert.equal((await settings()).enabled, false, 'Real admin form can disable a stale list.');
    assert.equal((await guest.context.cookies()).some(cookie => cookie.name.startsWith('wordpress_logged_in_')), false, 'All public assertions remain anonymous.');
    assert.deepEqual(pageErrors, [], 'No uncaught JavaScript errors.');
    assert.deepEqual(consoleErrors, [], 'No browser console errors.');
    assert.deepEqual(unexpectedWrites, [], 'No unrequested browser writes, especially during anonymous GETs.');
    assert.deepEqual(browserWrites, ['admin: /wp-login.php', 'reader: /wp-login.php', 'admin: /wp-admin/admin-post.php', 'admin: /wp-admin/admin-post.php'], 'Only two explicit logins and two explicit configuration submits reach the server.');
    fs.writeFileSync(path.join(output, 'announcements-browser-results.json'), JSON.stringify({ passed: true, run: fixture.run,
      publicNotices: 15, widgetCount: 1, widths: [320, 390, 1440], screenshots, isolatedHeartbeats,
      checks: ['native-admin-post-enable-reorder-disable', 'method-nonce-role-shape-guards', 'new-private-selection-rejected',
        'anonymous-search-pagination-privacy', 'stale-hidden-records-filtered', 'real-footer-widget', 'get-settings-unchanged', 'no-js-console-errors'],
      testedAt: new Date().toISOString() }, null, 2) + '\n');
    console.log('PASS: real announcements admin POST/nonce/role controls, enable/order/disable, anonymous search/paging and private isolation, actual footer widget, 320/390/1440 and no JS/console errors.');
  } finally { for (const context of contexts) await context.close(); await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
