'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'widgets-fixture.json'), 'utf8'));
const base = 'http://127.0.0.1:8771';
assert.equal(fixture.base, base); assert.equal(fixture.restored, false);
const url = key => base + '/?' + (key === 'page' ? 'page_id=' : 'p=') + fixture.posts[key];
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const errors = [];
  try {
    async function actor(login) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, permissions: ['clipboard-read', 'clipboard-write'], reducedMotion: 'reduce' }); contexts.push(context);
      await context.route('https://example.test/**', route => route.abort());
      const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message));
      if (login) { await page.goto(base + '/wp-login.php'); await page.locator('#user_login').fill(login); await page.locator('#user_pass').fill('qa-local-only'); await Promise.all([page.waitForURL(location => !location.pathname.includes('wp-login.php')), page.locator('#wp-submit').click()]); }
      return { context, page };
    }
    async function inspect(page, key, permitted) {
      const response = await page.goto(url(key)); assert.equal(response.status(), 200);
      const html = await response.text();
      assert.equal(html.includes('widget-browser-private.zip'), false, 'No widget emits external resource destination.');
      assert.equal(html.includes('widget-browser-code'), permitted, 'Code visibility follows authorization.');
      assert.equal(html.includes('widget-protected-body'), key === 'member' && permitted, 'Independent protected body stays gated.');
      if (key !== 'empty') assert.match(response.headers()['cache-control'] || '', /no-cache|no-store|private/i);
      assert.equal(await page.locator('.sluyu-widget').count(), 9, 'Configured sidebar and footer widgets render.');
      const duplicates = await page.locator('[id]').evaluateAll(items => { const ids = items.map(item => item.id); return [...new Set(ids.filter((id, index) => ids.indexOf(id) !== index))]; });
      assert.deepEqual(duplicates, [], 'Multiple download/TOC widgets never duplicate IDs.');
      if (key === 'password') { assert.equal(await page.locator('.sluyu-toc').count(), 0); assert.equal(await page.locator('a[href*="action=wuai_sluyu_download"]').count(), 0); }
      else {
        assert.equal(await page.locator('.sluyu-toc').count(), 2);
        assert.equal(await page.locator('.sluyu-footer .sluyu-toc').evaluate(item => getComputedStyle(item).display), 'block', 'Footer navigation styles do not turn a TOC into wrapping horizontal links.');
        const expected = key === 'empty' ? 0 : (key === 'member' ? (permitted ? 4 : 3) : 2);
        for (const toc of await page.locator('.sluyu-toc').all()) { assert.equal(await toc.locator('a').count(), expected); }
        const ids = await page.locator('.sluyu-toc').first().locator('a').evaluateAll(items => items.map(item => decodeURIComponent(item.hash.slice(1))));
        for (const id of ids) assert.equal(await page.evaluate(value => !!document.getElementById(value), id), true, 'Every TOC anchor resolves.');
        if (!permitted) assert.equal(await page.locator('.sluyu-toc').first().innerText().then(text => text.includes('Private widget heading')), false);
      }
      assert.match(await page.locator('#wuai_sluyu_service-804').innerText(), /QA WIDGET visible product/);
      assert.equal(await page.locator('#wuai_sluyu_service-804 .woocommerce-Price-currencySymbol').first().evaluate(item => getComputedStyle(item).display), 'inline', 'Currency symbol stays on the amount line.');
      assert.match(await page.locator('#wuai_sluyu_service-805').innerText(), /qa-widget-browser-public/);
    }
    const guest = await actor(); const reader = await actor('qa_widget_browser_reader');
    await inspect(guest.page, 'member', false);
    assert.equal(await guest.page.locator('#wuai_sluyu_service-801').innerText().then(text => text.includes('123')), false);
    await inspect(reader.page, 'member', true);
    assert.match(await reader.page.locator('#wuai_sluyu_service-801').innerText(), /123/);
    assert.equal(await reader.page.locator('a[href*="action=wuai_sluyu_download"]').count(), 3, 'Body and two download widgets use signed endpoints.');
    await reader.page.bringToFront(); const copy = reader.page.locator('#wuai_sluyu_service-805 [data-sluyu-copy]'); await copy.click();
    await reader.page.waitForFunction(() => document.querySelector('#wuai_sluyu_service-805 [data-sluyu-copy]').textContent === '\u5df2\u590d\u5236');
    assert.equal(await reader.page.evaluate(() => navigator.clipboard.readText()), 'qa-widget-browser-public');
    await reader.page.screenshot({ path: path.join(output, 'widgets-desktop.png'), fullPage: true, animations: 'disabled' });
    await inspect(guest.page, 'password', false); await inspect(reader.page, 'page', true); await inspect(guest.page, 'empty', false);
    for (const width of [390, 320]) {
      await reader.page.setViewportSize({ width, height: 844 });
      for (const key of ['member', 'page', 'password']) { await inspect(reader.page, key, key !== 'password'); assert.equal(await reader.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, key + ' at ' + width); }
      if (width === 390) { await reader.page.goto(url('member')); await reader.page.screenshot({ path: path.join(output, 'widgets-mobile.png'), fullPage: true, animations: 'disabled' }); }
    }
    assert.deepEqual(errors, []);
    console.log('PASS: real drag/drop widget configuration, 9 instances, multiple TOCs and signed download cards, member/password/privacy gates, public product/coupon clipboard, own account and 320/390 layouts.');
  } finally { for (const context of contexts) await context.close(); await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
