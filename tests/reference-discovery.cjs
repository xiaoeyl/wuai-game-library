'use strict';
// Anonymous GET-only UI regression. Root schedules fixture and suite serially.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const workspace = path.resolve(__dirname, '..');
const base = 'http://127.0.0.1:8771';
const output = fs.realpathSync(path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0'));
const dist = fs.realpathSync(path.join(workspace, 'dist'));
const site = fs.realpathSync(path.join(workspace, 'dist/checks-v1.1.0/site'));
function within(child, parent) {
  const relative = path.relative(parent, child);
  return relative === '' || (!relative.startsWith('..' + path.sep) && relative !== '..' && !path.isAbsolute(relative));
}
assert.ok(within(output, dist) && !within(output, site), 'QA output stays in ignored dist, outside HTTP root.');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'reference-discovery-browser-fixture.json'), 'utf8'));
assert.equal(fixture.marker, 'WUAI_DISCOVERY_BROWSER_V1');
assert.equal(fixture.state, 'ready');
assert.equal(fixture.base, base);
assert.equal(fs.realpathSync(fixture.root).toLowerCase(), site.toLowerCase());
assert.equal(fixture.credentials_in_manifest, false);
assert.equal(fixture.mail_intercepted, true);
for (const value of [fixture.tags_url, fixture.channel_url, ...fixture.expected_tags.map(item => item.url)]) {
  assert.equal(new URL(value).origin, base, 'Fixture URLs stay local.');
}
function withQuery(url, values) {
  const result = new URL(url);
  for (const [name, value] of Object.entries(values)) result.searchParams.set(name, value);
  return result.href;
}
const tagsUrl = withQuery(fixture.tags_url, { tag_search: fixture.tag_prefix, unrelated: 'must-not-carry' });
const channelUrl = withQuery(fixture.channel_url, { archiveSearch: fixture.search, post_order: 'new' });
const randomUrl = withQuery(fixture.channel_url, { archiveSearch: fixture.search, post_order: 'random', seed: fixture.random_seed });
const expectedTags = new Map(fixture.expected_tags.map(item => [new URL(item.url).href, item]));
const expectedTitles = new Set(fixture.public_posts.map(item => item.title));

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const errors = []; const mutations = [];
  const screenshots = [];
  try {
    context.setDefaultTimeout(20000);
    await context.route('**/*', route => {
      const request = route.request(); const parsed = new URL(request.url());
      if (!['GET', 'HEAD'].includes(request.method())) {
        mutations.push(request.method() + ' ' + parsed.pathname);
        return route.abort();
      }
      return parsed.origin === base ? route.continue() : route.abort();
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    async function assertPage(response) {
      assert.ok(response, 'Navigation returned a response.');
      assert.equal(response.status(), 200);
      const html = await response.text();
      assert.doesNotMatch(html, /Fatal error|Uncaught (?:Error|Exception)|WordPress database error/);
      await page.locator('.sluyu-portal-content').waitFor();
      return html;
    }
    async function go(url) { return assertPage(await page.goto(url, { waitUntil: 'domcontentloaded' })); }
    async function tagsOnPage() {
      const links = await page.locator('.sluyu-tag-directory a').evaluateAll(items => items.map(item => ({ href: item.href, text: item.textContent.trim() })));
      for (const item of links) {
        const expected = expectedTags.get(item.href);
        assert.ok(expected, 'Only expected public tag archives are rendered.');
        assert.ok(item.text.includes(expected.name));
        assert.match(item.text, /\b1\s*篇$/u, 'Displayed count excludes nonpublic relationships.');
      }
      return links.map(item => item.href);
    }
    async function cards() {
      const titles = await page.locator('.sluyu-channel-grid .sluyu-card h3 a').allTextContents();
      return titles.map(title => title.trim());
    }
    function assertChannelTitles(titles) {
      assert.ok(titles.length > 0);
      for (const title of titles) assert.ok(expectedTitles.has(title), 'Only matching public in-category cards are rendered.');
      assert.equal(titles.includes(fixture.outside_title), false);
      for (const title of fixture.hidden_post_titles) assert.equal(titles.includes(title), false);
    }
    async function nextPage(kind, order) {
      const current = Number(new URL(page.url()).searchParams.get('pg') || '1');
      const links = await page.locator('.sluyu-portal-content .sluyu-portal-pagination a').evaluateAll(items => items.map(item => item.href));
      for (const href of links) {
        const url = new URL(href); assert.equal(url.origin, base);
        assert.equal(url.searchParams.has('unrelated'), false, 'Pagination drops unrelated current-request arguments.');
        if (kind === 'tags') assert.equal(url.searchParams.get('tag_search'), fixture.tag_prefix);
        else {
          assert.equal(url.searchParams.get('archiveSearch'), fixture.search);
          assert.equal(url.searchParams.get('post_order'), order);
          assert.equal(url.searchParams.has('sort'), false, 'Canonical links do not preserve a conflicting old sort.');
          if (order === 'random') assert.equal(url.searchParams.get('seed'), fixture.random_seed);
        }
      }
      const target = links.find(href => Number(new URL(href).searchParams.get('pg') || '1') === current + 1);
      if (!target) return false;
      const link = page.locator('.sluyu-portal-content .sluyu-portal-pagination a');
      const index = links.indexOf(target);
      const response = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
      response.catch(() => {});
      await link.nth(index).click();
      await assertPage(await response);
      return true;
    }
    async function collectChannel(url, order) {
      await go(url); const result = [];
      do {
        const values = await cards(); assertChannelTitles(values); result.push(...values);
      } while (await nextPage('channel', order));
      assert.equal(result.length, fixture.channel_total);
      assert.equal(new Set(result).size, fixture.channel_total, 'Pagination has neither repeated nor omitted cards.');
      assert.deepEqual(new Set(result), expectedTitles);
      return result;
    }

    const tagHtml = await go(tagsUrl);
    for (const name of fixture.hidden_tag_names) assert.equal(tagHtml.includes(name), false, 'Private-only tag names are absent from HTML.');
    const firstTags = await tagsOnPage(); assert.equal(firstTags.length, 24);
    assert.equal(await nextPage('tags'), true);
    const secondTags = await tagsOnPage(); assert.equal(secondTags.length, 4);
    assert.deepEqual(new Set([...firstTags, ...secondTags]), new Set(expectedTags.keys()));
    const tagForm = page.locator('.sluyu-portal-content form').filter({ has: page.locator('input[name="tag_search"]') });
    await tagForm.locator('input[name="tag_search"]').fill(fixture.hidden_tag_names[0]);
    let navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' }); navigation.catch(() => {});
    await tagForm.locator('button').click(); await assertPage(await navigation);
    assert.equal(await page.locator('.sluyu-tag-directory a').count(), 0, 'Searching a private-only name cannot discover a tag.');
    assert.match(await page.locator('.sluyu-portal-content').innerText(), /没有找到/u);
    await go(tagsUrl);
    const mixedTag = fixture.expected_tags.find(item => item.name.endsWith('-mixed'));
    assert.ok(mixedTag && expectedTags.has(new URL(mixedTag.url).href));

    await go(fixture.channel_url);
    const channelForm = page.locator('.sluyu-portal-content form').filter({ has: page.locator('input[name="archiveSearch"]') });
    await channelForm.locator('input[name="archiveSearch"]').fill(fixture.search);
    await channelForm.locator('select[name="post_order"]').selectOption('new');
    navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' }); navigation.catch(() => {});
    await channelForm.locator('button').click(); await assertPage(await navigation);
    assert.equal(new URL(page.url()).searchParams.get('archiveSearch'), fixture.search);
    assert.equal(new URL(page.url()).searchParams.get('post_order'), 'new');
    const latest = await collectChannel(page.url(), 'new');
    const randomized = await collectChannel(randomUrl, 'random');
    assert.deepEqual(await collectChannel(randomUrl, 'random'), randomized, 'Identical random seed repeats the complete order.');
    assert.equal(new Set(latest).size, new Set(randomized).size);

    await go(withQuery(fixture.channel_url, { archiveSearch: fixture.search, sort: 'new', unrelated: 'must-not-carry' }));
    assert.equal(await nextPage('channel', 'new'), true, 'Old sort input generates canonical post_order pagination.');
    const malformed = new URL(fixture.channel_url); malformed.searchParams.set('archiveSearch[]', fixture.search);
    await go(malformed.href);
    assert.equal(await page.locator('.sluyu-channel-grid .sluyu-card').count(), 0, 'Array search parameters never broaden the result to all posts.');
    assert.equal(await page.locator('.sluyu-portal-content [role="alert"]').count(), 1);

    for (const width of [320, 390, 1440]) {
      await page.setViewportSize({ width, height: width < 500 ? 844 : 1000 });
      for (const [name, url] of [['tags', tagsUrl], ['channel', channelUrl], ['random', randomUrl]]) {
        await go(url);
        if (name === 'tags') await tagsOnPage(); else assertChannelTitles(await cards());
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, name + ' has no horizontal overflow at ' + width);
        const filename = 'reference-discovery-' + name + '-' + width + '.png';
        await page.screenshot({ path: path.join(output, filename), fullPage: true, animations: 'disabled' });
        screenshots.push(filename);
      }
    }
    assert.deepEqual(errors, [], 'No uncaught browser JavaScript errors.');
    assert.deepEqual(mutations, [], 'The browser suite performs only read-only GET/HEAD requests.');
    const result = { passed: true, run: fixture.run, tags: fixture.tag_total, channelPosts: fixture.channel_total,
      widths: [320, 390, 1440], checks: ['public-tag-counts-and-privacy', 'tag-search-and-pagination', 'scoped-channel-search',
        'canonical-pagination', 'stable-random-order', 'strict-array-input', 'responsive-layout', 'no-js-errors', 'get-only'],
      screenshots, testedAt: new Date().toISOString() };
    fs.writeFileSync(path.join(output, 'reference-discovery-browser-results.json'), JSON.stringify(result, null, 2) + '\n');
    console.log('PASS: real anonymous tags search/privacy/counts/pagination, scoped archiveSearch, canonical pagination and stable random seed, 320/390/1440 layouts, no JS errors or browser mutations.');
  } finally { await context.close(); await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
