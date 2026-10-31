'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = 'http://127.0.0.1:8771';
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'circles-fixture.json'), 'utf8'));
const portal = (key, args = '') => base + '/?sluyu_page=' + key + args;
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(portal('circle', '&group=' + fixture.open));
    assert.match(await page.locator('.sluyu-circle-heading').innerText(), /QA-CIRCLE-Open/);
    assert.equal(await page.locator('.sluyu-circle-members').count(), 0);
    const malformed = await page.goto(portal('circle', '&group[]=bad&kind[]=bad&pg[]=bad'));
    assert.equal(malformed.status(), 200);
    assert.equal((await page.locator('body').innerText()).includes('Fatal error'), false);
    await page.goto(base + '/?p=' + fixture.poll);
    assert.equal(await page.locator('.sluyu-poll form').count(), 0, 'Guests see results, not an active ballot.');
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill('qa_circle_participant'); await page.locator('#user_pass').fill('qa-local-only');
    await Promise.all([page.waitForURL(url => !url.pathname.includes('wp-login.php')), page.locator('#wp-submit').click()]);
    await page.goto(portal('circle', '&group=' + fixture.open));
    await Promise.all([page.waitForEvent('load'), page.locator('[data-community-action=circle_join]').click()]);
    assert.match(await page.locator('.sluyu-circle-heading').innerText(), /已加入/);
    page.once('dialog', dialog => dialog.accept());
    await Promise.all([page.waitForEvent('load'), page.locator('[data-community-action=circle_leave]').click()]);
    assert.equal(await page.locator('[data-community-action=circle_join]').count(), 1);
    await page.goto(portal('submit', '&kind=poll&group=' + fixture.circle));
    assert.equal(await page.locator('select[name=group]').inputValue(), String(fixture.circle));
    await page.locator('select[name=poll_mode]').selectOption('multiple');
    assert.equal(await page.locator('[data-poll-multiple]').isVisible(), true);
    await page.locator('select[name=kind]').selectOption('post');
    assert.equal(await page.locator('[data-poll-field]').isVisible(), false);
    assert.equal(await page.locator('input[name=poll_deadline]').isDisabled(), true);
    await page.goto(base + '/?p=' + fixture.poll);
    await page.locator('.sluyu-poll input[value="0"]').check();
    await page.locator('.sluyu-poll input[value="2"]').check();
    await Promise.all([page.waitForEvent('load'), page.locator('.sluyu-poll button[type=submit]').click()]);
    assert.match(await page.locator('.sluyu-poll').innerText(), /1 人参与/);
    assert.equal(await page.locator('.sluyu-poll input[type=checkbox]').count(), 0);
    assert.equal((await page.locator('.sluyu-poll').innerText()).match(/1 票/g).length, 2);
    await page.screenshot({ path: path.join(output, 'poll-multiple-desktop.png'), fullPage: true, animations: 'disabled' });
    await page.goto(portal('circles'));
    await page.screenshot({ path: path.join(output, 'circles-desktop.png'), fullPage: true, animations: 'disabled' });
    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 844 });
      for (const url of [portal('circles'), portal('circle', '&group=' + fixture.circle), portal('submit', '&kind=poll'), base + '/?p=' + fixture.poll]) {
        await page.goto(url);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, url + ' at ' + width);
      }
      if (width === 390) await page.screenshot({ path: path.join(output, 'poll-multiple-mobile.png'), fullPage: true, animations: 'disabled' });
    }
    await page.goto(portal('account')); const nonce = await page.evaluate(() => wuaiSluyu.nonce);
    const response = await context.request.get(base + '/wp-admin/admin-ajax.php', { params: { action: 'wuai_sluyu_community', operation: 'circle_join', id: String(fixture.open), nonce } });
    assert.equal(response.status(), 405, 'GET cannot mutate community state.');
    assert.deepEqual(errors, []);
    await context.close();
    console.log('PASS: browser circle join/leave, member group preselection, multi-choice FormData, results/voted UI, query shape safety, POST-only mutations and responsive 320/390/1440 layouts.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
