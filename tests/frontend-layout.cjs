'use strict';
// Requires a disposable local WordPress site with the fixture IDs in WP_LAYOUT_FIXTURE.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8767';
assert.match(base, /^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/, 'This test only modifies a local fixture site.');
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.0.7');
const fixture = JSON.parse(fs.readFileSync(process.env.WP_LAYOUT_FIXTURE || path.join(output, 'fixture.json'), 'utf8'));

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE, headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1790, height: 1000 }, permissions: ['clipboard-read', 'clipboard-write'] });
    const page = await context.newPage();
    const errors = [], assetErrors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('response', r => { if (r.status() >= 400 && r.url().includes('/themes/wuai-sluyu/')) assetErrors.push(r.url()); });
    await page.goto(base + '/?p=' + fixture.article);
    await page.evaluate(() => document.fonts.ready);
    assert.equal(await page.locator('.sluyu-breadcrumb').count(), 1);
    assert.equal(await page.locator('.sluyu-article-banner img').count(), 1);
    assert.equal(await page.locator('.sluyu-featured').count(), 0, 'Reference article layout does not add a duplicate cover.');
    assert.equal(await page.locator('.sluyu-footer-column').count(), 3);
    assert.equal(await page.locator('.sluyu-footer-column a').count(), 6);
    assert.equal(await page.locator('.sluyu-reference-rail .sluyu-rail-item').count(), 9);
    assert.equal(await page.locator('.sluyu-reference-rail').evaluate(e => e.getBoundingClientRect().width), 40);
    assert.equal(await page.locator('.sluyu-single-layout').evaluate(e => Math.round(e.getBoundingClientRect().width)), 1250);
    assert.equal(await page.locator('.sluyu-toc a').count(), 4);
    await page.screenshot({ path: path.join(output, 'article-desktop.png') });
    await page.locator('.sluyu-jump-download').click();
    await page.waitForFunction(() => document.querySelector('#download-box').getBoundingClientRect().top < 200);
    await page.locator('#download-box [data-sluyu-copy]').click();
    assert.equal(await page.evaluate(() => navigator.clipboard.readText()), 'test');
    await page.locator('.sluyu-article-actions [data-sluyu-bookmark]').click();
    assert.equal(await page.locator('.sluyu-article-actions [data-sluyu-bookmark]').getAttribute('aria-pressed'), 'true');
    await page.reload();
    assert.equal(await page.locator('.sluyu-article-actions [data-sluyu-bookmark]').getAttribute('aria-pressed'), 'true');
    await page.locator('.sluyu-article-actions [data-sluyu-like]').click();
    await page.locator('#sluyu-dialog:not([hidden])').waitFor();
    assert.equal(await page.locator('#sluyu-dialog-title').innerText(), '个人中心');
    await page.keyboard.press('Escape');
    const railSearch = page.locator('.sluyu-reference-rail [data-sluyu-panel="search"]');
    await railSearch.click();
    await page.locator('.sluyu-dialog input[name=s]').fill('布局测试');
    assert.equal(await page.locator('.sluyu-dialog input[name=s]').inputValue(), '布局测试');
    await page.keyboard.press('Escape');
    assert.equal(await railSearch.evaluate(e => e === document.activeElement), true, 'Dialog restores keyboard focus.');
    await page.locator('.sluyu-reference-rail [data-sluyu-panel=qrcode]').click();
    assert.equal(await page.locator('[data-sluyu-qr]').evaluate(canvas => canvas.width), 320);
    await page.locator('[data-sluyu-close]').click();
    await page.locator('.sluyu-footer-column [data-sluyu-panel=help]').click();
    assert.match(await page.locator('.sluyu-dialog-body').innerText(), /立即下载/);
    await page.keyboard.press('Escape');
    await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));
    await page.screenshot({ path: path.join(output, 'footer-desktop.png') });
    assert.equal(await page.locator('a[href*="/wp-admin"]').count(), 0, 'Public page does not expose backend links.');
    const protectedResponse = await context.request.get(base + '/?p=' + fixture.protected);
    const protectedHtml = await protectedResponse.text();
    assert.equal(protectedHtml.includes('protected-secret.zip'), false);
    assert.equal(protectedHtml.includes('never-leak'), false);
    assert.match(protectedHtml, /post-password-form/);

    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 844 });
      await page.goto(base + '/?p=' + fixture.article);
      assert.equal(await page.locator('.sluyu-reference-rail').isVisible(), false);
      assert.equal(await page.locator('.sluyu-mobile-nav').isVisible(), true);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'No mobile horizontal overflow at ' + width);
      await page.locator('.sluyu-mobile-nav [data-sluyu-panel=search]').click();
      assert.equal(await page.locator('.sluyu-dialog input').isVisible(), true);
      await page.keyboard.press('Escape');
      if (width === 390) {
        await page.screenshot({ path: path.join(output, 'article-mobile.png'), fullPage: true });
      }
    }

    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill('qa_subscriber');
    await page.locator('#user_pass').fill('qa-local-only');
    await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);
    await page.goto(base + '/?p=' + fixture.article);
    const token = await page.evaluate(() => wuaiSluyu.nonce);
    async function action(operation, extra = {}) {
      return context.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'wuai_sluyu_frontend', nonce: token, operation, ...extra } });
    }
    let response = await action('checkin');
    const first = await response.json();
    assert.equal(first.success, true);
    response = await action('checkin');
    assert.equal((await response.json()).data.days, first.data.days, 'Repeated check-in does not add days.');
    response = await action('like', { post_id: fixture.article });
    const liked = await response.json();
    assert.equal(liked.success, true);
    response = await action('like', { post_id: fixture.article });
    const toggled = await response.json();
    assert.notEqual(toggled.data.liked, liked.data.liked);
    for (const post_id of [fixture.draft, fixture.private, fixture.protected]) {
      response = await action('like', { post_id });
      assert.equal(response.status(), 403, 'Cannot act on hidden/protected posts.');
    }
    response = await action('checkin', { nonce: 'invalid' });
    assert.equal(response.status(), 403);
    assert.deepEqual(errors, []);
    assert.deepEqual(assetErrors, []);
    console.log('PASS: footer/rail geometry, article/TOC/download, clipboard/bookmark/dialog/QR, mobile, check-in idempotency, like permissions, protected-resource isolation, no console/asset errors.');
    await context.close();
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
