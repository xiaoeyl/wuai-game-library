'use strict';
// Anonymous real HTTP suite. Local fixture changes use CLI, never an HTTP helper.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const workspace = path.resolve(__dirname, '..');
const base = 'http://127.0.0.1:8771';
// Optional real PHP router transport, preserving the tested site's public origin.
// The default PHP dev server bypasses WordPress for missing *.html files.
const transport = process.env.WP_QA_TRANSPORT_URL || base;
assert.match(transport, /^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/);
function transportUrl(url) { const value = new URL(url, base); return transport + value.pathname + value.search; }
const output = fs.realpathSync(path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0'));
const site = fs.realpathSync(path.join(workspace, 'dist/checks-v1.1.0/site'));
const dist = fs.realpathSync(path.join(workspace, 'dist'));
function within(child, parent) { const relative = path.relative(parent, child); return relative === '' || (relative !== '..' && !relative.startsWith('..' + path.sep) && !path.isAbsolute(relative)); }
assert.ok(within(output, dist) && !within(output, site), 'Output stays in ignored dist outside HTTP root.');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'reference-aliases-browser-fixture.json'), 'utf8'));
assert.equal(fixture.marker, 'WUAI_REFERENCE_ALIASES_BROWSER_V1');
assert.equal(fixture.state, 'ready'); assert.equal(fixture.mode, 'pretty'); assert.equal(fixture.available, true);
assert.equal(fixture.base, base); assert.equal(fs.realpathSync(fixture.root).toLowerCase(), site.toLowerCase());
assert.equal(fixture.credentials_in_manifest, false); assert.equal(fixture.mail_intercepted, true); assert.equal(Object.keys(fixture.routes).length, 13);
const php = process.env.PHP_EXECUTABLE || fixture.php;
assert.ok(typeof php === 'string' && fs.existsSync(php), 'Explicit local PHP executable required for CLI-only fixture transitions.');
function mode(name) {
  const options = fixture.php_ini ? ['-c', fixture.php_ini] : [];
  const result = execFileSync(php, [...options, path.join(__dirname, 'reference-aliases-browser-fixture.php'), name], {
    cwd: workspace, encoding: 'utf8', timeout: 120000, windowsHide: true,
    env: { ...process.env, WP_QA_ROOT: site, WP_QA_OUTPUT: output }
  });
  assert.match(result, /PASS aliases browser fixture/, 'CLI transition must explicitly report success.');
}
function query(url, values) { const result = new URL(url, base); for (const [key, value] of Object.entries(values)) result.searchParams.set(key, String(value)); return result.href; }
const documents = fixture.routes['document/xzsnbs'];
const documentTitles = new Set(documents.items.map(item => item.title));
const docUrl = query('/document/', { group: documents.term_id, doc_search: fixture.search });
const aliasDocUrl = query('/document/xzsnbs/', { doc_search: fixture.search });

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const errors = []; const mutations = []; const screenshots = [];
  let available = true; let pretty = true;
  try {
    async function context(javaScriptEnabled = true) {
      const value = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce', javaScriptEnabled });
      contexts.push(value); value.setDefaultTimeout(20000);
      await value.route('**/*', async route => {
        const request = route.request(); const url = new URL(request.url());
        if (!['GET', 'HEAD'].includes(request.method())) { mutations.push(request.method() + ' ' + url.pathname); return route.abort(); }
        if (url.origin !== base) return route.abort();
        if (transport === base) return route.continue();
        const response = await route.fetch({ url: transportUrl(url.href), headers: { ...request.headers(), host: new URL(base).host }, maxRedirects: 0 });
        return route.fulfill({ response });
      });
      return value;
    }
    const guest = await context(); const page = await guest.newPage(); page.on('pageerror', error => errors.push(error.message));
    async function http(url, status = 200) {
      const response = await guest.request.get(transportUrl(url), { headers: { host: new URL(base).host }, maxRedirects: 0 });
      assert.equal(response.status(), status, `${url} HTTP status`);
      assert.equal(response.headers()['x-wuai-aliases-qa-mail-blocked'], '1', 'Exact fixture mail guard is active.');
      const html = await response.text(); assert.doesNotMatch(html, /Fatal error|Uncaught (?:Error|Exception)|WordPress database error/);
      for (const title of fixture.hidden_titles) assert.ok(!html.includes(title), 'Hidden fixture title must not enter HTTP output.');
      return html;
    }
    async function go(url, target = page) {
      const response = await target.goto(url, { waitUntil: 'domcontentloaded' }); assert.ok(response); assert.equal(response.status(), 200);
      assert.equal(response.headers()['x-wuai-aliases-qa-mail-blocked'], '1');
      await target.locator('.sluyu-portal-content').waitFor(); return response;
    }
    function scopedTitles(html, item) { for (const publicItem of item.items.slice(-1)) assert.ok(html.includes(publicItem.title), 'Alias returns its actual public fixture.'); }
    for (const [route, item] of Object.entries(fixture.routes)) {
      scopedTitles(await http('/' + route + '/'), item);
      scopedTitles(await http('/' + route + '.html'), item);
      await http(query('/' + route + '/', { 'pg[]': '1' }), 400);
    }
    for (const route of ['/links/not-a-reference-category/', '/circle/not-a-reference-circle/', '/document/999999999.html', '/newsflashes/unknown/']) await http(route, 404);
    const protectedOverride = query('/links/tuijzd/', { p: fixture.private_id, preview: 'true', post_status: 'private', sluyu_page: 'account' });
    const overridden = await http(protectedOverride); scopedTitles(overridden, fixture.routes['links/tuijzd']);
    await http(query('/circle/sscosplay/', { group: fixture.routes['circle/square'].term_id }), 400);
    await http(query('/circle/sscosplay/', { 'group[]': '1' }), 400);
    await http(query('/circle/sscosplay/', { sort: 'random' }), 400);
    scopedTitles(await http(query('/circle/sscosplay/', { kind: 'topic', sort: 'latest' })), fixture.routes['circle/sscosplay']);
    mode('--unavailable'); available = false;
    try {
      await http('/links/tuijzd/', 404); await http('/links/tuijzd.html', 404);
      await http('/circle/sscosplay/', 404); await http('/circle/sscosplay.html', 404);
    } finally { mode('--available'); available = true; }

    async function visibleDocs(target) {
      const result = target.locator('.sluyu-portal-content section').filter({ has: target.getByRole('heading', { name: '已发布文档', exact: true }) });
      const titles = (await result.locator('a').allTextContents()).map(value => value.trim());
      for (const title of titles) assert.ok(documentTitles.has(title), 'Search stays inside actual public document taxonomy.');
      return titles;
    }
    await go(query(docUrl, { unrelated: 'must-not-carry' }));
    const first = await visibleDocs(page); assert.equal(first.length, 12);
    const secondHref = await page.getByRole('link', { name: '下一页', exact: true }).getAttribute('href');
    const parsedNext = new URL(secondHref, base); assert.equal(parsedNext.searchParams.get('doc_search'), fixture.search); assert.equal(parsedNext.searchParams.get('group'), String(documents.term_id)); assert.equal(parsedNext.searchParams.get('pg'), '2'); assert.equal(parsedNext.searchParams.has('unrelated'), false);
    await go(parsedNext.href); const second = await visibleDocs(page); assert.equal(second.length, 1); assert.equal(new Set([...first, ...second]).size, 13);
    assert.equal(await page.getByRole('heading', { name: '基础使用提示', exact: true }).count(), 1, 'FAQ is independently retained.');
    await go(docUrl); await page.locator('input[name="doc_search"]').fill(fixture.search + ' no-match');
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('input[name="doc_search"]').press('Enter')]);
    assert.equal(new URL(page.url()).searchParams.get('group'), String(documents.term_id), 'GET search form retains category.');
    assert.equal((await visibleDocs(page)).length, 0); assert.equal(await page.getByRole('heading', { name: '基础使用提示', exact: true }).count(), 1);
    await go(aliasDocUrl); assert.equal((await visibleDocs(page)).length, 12);
    const aliasNext = new URL(await page.getByRole('link', { name: '下一页', exact: true }).getAttribute('href'), base);
    assert.equal(aliasNext.pathname, '/document/xzsnbs/'); assert.equal(aliasNext.searchParams.get('doc_search'), fixture.search);

    const nojs = await context(false); const staticPage = await nojs.newPage();
    await go(docUrl, staticPage); assert.equal((await visibleDocs(staticPage)).length, 12);
    await staticPage.locator('input[name="doc_search"]').fill(fixture.search + ' no-match');
    await Promise.all([staticPage.waitForNavigation({ waitUntil: 'domcontentloaded' }), staticPage.locator('input[name="doc_search"]').press('Enter')]);
    assert.equal((await visibleDocs(staticPage)).length, 0, 'Document search is server-side with JavaScript disabled.');
    await go(aliasDocUrl, staticPage); const staticNext = await staticPage.getByRole('link', { name: '下一页', exact: true }).getAttribute('href');
    await go(new URL(staticNext, base).href, staticPage); assert.equal((await visibleDocs(staticPage)).length, 1, 'Alias pagination is server-side without JavaScript.');

    for (const width of [320, 390, 1440]) {
      await page.setViewportSize({ width, height: width < 500 ? 844 : 1000 });
      for (const [name, url] of [['documents', docUrl], ['legacy-documents', aliasDocUrl], ['legacy-circle', base + '/circle/sscosplay/'], ['legacy-links', base + '/links/tuijzd/']]) {
        await go(url); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `${name} fits ${width}px`);
        const file = path.join(output, `reference-aliases-${name}-${width}.png`); await page.screenshot({ path: file, fullPage: true }); screenshots.push(file);
      }
    }

    mode('--plain'); pretty = false;
    try {
      scopedTitles(await http(query('/', { wuai_reference_alias: 'links/tuijzd' })), fixture.routes['links/tuijzd']);
      scopedTitles(await http(query('/index.php', { wuai_reference_alias: 'links/tuijzd' })), fixture.routes['links/tuijzd']);
      await http(query('/not-home/', { wuai_reference_alias: 'links/tuijzd' }), 404);
      await http(query('/', { 'wuai_reference_alias[]': 'links/tuijzd' }), 404);
      const plainDoc = query('/', { sluyu_page: 'document', group: documents.term_id, doc_search: fixture.search });
      await go(plainDoc); assert.equal((await visibleDocs(page)).length, 12);
      const plainNext = new URL(await page.getByRole('link', { name: '下一页', exact: true }).getAttribute('href'), base);
      assert.equal(plainNext.searchParams.get('sluyu_page'), 'document'); assert.equal(plainNext.searchParams.get('group'), String(documents.term_id));
      await go(plainNext.href); assert.equal((await visibleDocs(page)).length, 1);
      const plainAlias = query('/', { wuai_reference_alias: 'document/xzsnbs', doc_search: fixture.search });
      await go(plainAlias, staticPage); assert.equal((await visibleDocs(staticPage)).length, 12);
      const plainAliasNext = new URL(await staticPage.getByRole('link', { name: '下一页', exact: true }).getAttribute('href'), base);
      assert.equal(plainAliasNext.searchParams.get('wuai_reference_alias'), 'document/xzsnbs'); await go(plainAliasNext.href, staticPage); assert.equal((await visibleDocs(staticPage)).length, 1);
    } finally { mode('--pretty'); pretty = true; }
    assert.deepEqual(mutations, [], 'All browser traffic is GET/HEAD.'); assert.deepEqual(errors, [], 'No browser page errors.');
    fs.writeFileSync(path.join(output, 'reference-aliases-browser-results.json'), JSON.stringify({ pass: true, routes: 13, suffixes: ['/', '.html'], widths: [320, 390, 1440], javaScriptDisabled: true, plainPermalinks: true, screenshots }, null, 2));
    console.log('PASS reference aliases HTTP: 13 routes + .html; real missing/private 404; arrays 400; query overrides; docs search/paging without JS; mobile/desktop; plain permalink gates.');
  } finally {
    if (!available) mode('--available'); if (!pretty) mode('--pretty');
    for (const value of contexts) await value.close(); await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
