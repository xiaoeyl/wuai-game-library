'use strict';
// Real subscriber multipart uploads; no request stubbing or upload-origin bypass.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8771';
assert.equal(base, 'http://127.0.0.1:8771');
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'topic-media-browser-fixture.json'), 'utf8'));
assert.equal(fixture.base, base); assert.equal(new URL(fixture.composer_url).origin, base);
const endpoint = base + '/wp-admin/admin-ajax.php';

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const errors = []; const uploads = [];
  try {
    async function person(name) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' }); contexts.push(context); context.setDefaultTimeout(30000);
      await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
      const page = await context.newPage(); page.on('pageerror', error => errors.push(name + ': ' + error.message)); page.on('dialog', dialog => dialog.accept());
      if (name) {
        const response = await page.goto(base + '/wp-login.php'); assert.equal(response.headers()['x-wuai-qa-topic-media'], '1', 'Fixture mail guard required.');
        await page.locator('#user_login').fill(fixture.login_prefix + name); await page.locator('#user_pass').fill('qa-local-only');
        await Promise.all([page.waitForURL(url => !url.pathname.includes('wp-login.php')), page.locator('#wp-submit').click()]);
      }
      return { page, context };
    }
    const owner = await person('owner'); const other = await person('other'); const guest = await person(''); const admin = await person('admin');
    await owner.page.goto(fixture.composer_url);
    const root = owner.page.locator('.sluyu-topic-media-composer');
    await root.waitFor(); assert.match(await root.innerText(), /上传后图片直链立即公开/);
    await root.locator('[data-media-create]').click(); assert.match(await root.locator('[data-media-status]').innerText(), /请先确认/);
    await root.locator('[data-media-confirm]').check();
    async function ajaxByClick(button) {
      const promise = owner.page.waitForResponse(response => response.url() === endpoint && response.request().method() === 'POST');
      await button.click(); const response = await promise; const json = await response.json(); assert.equal(json.success, true, json.data && json.data.message); return json.data;
    }
    let state = await ajaxByClick(root.locator('[data-media-create]'));
    assert.equal(state.status, 'draft'); assert.equal(state.items.length, 0); assert.equal(new URL(owner.page.url()).searchParams.get('draft'), String(state.id));
    const editorUrl = owner.page.url();
    const config = await root.evaluate(element => JSON.parse(element.dataset.config));
    async function api(person, operation, values = {}) {
      const response = await person.context.request.post(endpoint, { form: { action: 'wuai_topic_media', nonce: config.nonce, operation, id: String(state.id), version: String(state.version), confirm_public: '1', ...values } });
      return { response, json: await response.json() };
    }
    let denied = await api(other, 'remove', { attachment: '1' }); assert.equal(denied.json.success, false, 'Foreign-session nonce/ownership gate rejects.');
    denied = await api(guest, 'create', { group: '0', request: '75d310f2-4474-4491-9d80-9cc5e70d0c87' }); assert.equal(denied.response.status() >= 400, true, 'Guest cannot upload.');
    await root.locator('[data-media-title]').fill('QA 浏览器公开图片话题'); await root.locator('[data-media-body]').fill('真实浏览器上传后重新编码并送审，图片从上传时即公开。');
    const malformed = await owner.context.request.post(endpoint, { form: { action: 'wuai_topic_media', nonce: config.nonce, operation: 'upload', id: String(state.id), version: String(state.version), confirm_public: '1', 'request[]': 'nested' } });
    assert.equal((await malformed.json()).success, false, 'Nested upload fields fail closed.');
    for (let i = 0; i < 2; i++) {
      const promise = owner.page.waitForResponse(response => response.url() === endpoint && response.request().method() === 'POST');
      await root.locator('[data-media-file]').setInputFiles({ name: 'picture-' + i + '.png', mimeType: 'image/png', buffer: fs.readFileSync(fixture.source) });
      const response = await promise; assert.match(response.request().headers()['content-type'], /^multipart\/form-data;/);
      const json = await response.json(); assert.equal(json.success, true, json.data && json.data.message); state = json.data;
      await root.locator('[data-media-status]').filter({ hasText: '操作已保存' }).waitFor(); assert.equal(state.items.length, i + 1); uploads.push(state.items[i].url);
    }
    assert.equal(await root.locator('[data-media-list] img').count(), 2);
    assert.equal(await root.locator('[data-media-progress]').isHidden(), true, 'Processing is not left at a fake 100% success indicator.');
    for (const url of uploads) {
      const response = await guest.context.request.get(url); assert.equal(response.status(), 200, 'Image really is public before topic approval, as disclosed.');
      const bytes = await response.body(); assert.equal(bytes.includes(Buffer.from('QA-TOPIC-ORIGINAL-BYTES-MUST-NOT-SURVIVE')), false, 'Uploaded file excludes original trailing bytes.');
      assert.equal(bytes.includes(Buffer.from('<?php')), false);
    }
    const originalIds = state.items.map(item => item.id);
    state = await ajaxByClick(root.locator('[data-media-list] li').first().getByRole('button', { name: '下移', exact: true }));
    assert.deepEqual(state.items.map(item => item.id), originalIds.slice().reverse(), 'Keyboard/mobile button reorder persists.');
    await owner.page.reload(); await root.waitFor(); await root.locator('[data-media-confirm]').check();
    assert.equal(await root.locator('[data-media-list] img').first().getAttribute('src'), uploads[1], 'Order persists across reload.');
    await root.locator('[data-media-title]').fill('QA 浏览器公开图片话题'); await root.locator('[data-media-body]').fill('真实浏览器上传后重新编码并送审，图片从上传时即公开。');
    await owner.page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await owner.page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, 'Composer fits narrow mobile viewport.');
    await owner.page.screenshot({ path: path.join(output, 'topic-media-mobile.png'), fullPage: true });
    state = await ajaxByClick(root.locator('[data-media-submit]')); assert.equal(state.status, 'pending');
    assert.equal(await root.locator('[data-media-file]').isDisabled(), true, 'Pending images cannot be swapped.');
    assert.equal(await root.locator('[data-media-submit]').isHidden(), true);
    denied = await api(owner, 'remove', { attachment: String(state.items[0].id) }); assert.equal(denied.json.success, false, 'Pending direct API mutation is also rejected.');
    await admin.page.goto(base + '/wp-admin/post.php?post=' + state.id + '&action=edit');
    if (!(await admin.page.locator('form#post').count())) {
      const loader = await admin.page.evaluate(() => window._wpMetaBoxUrl);
      assert.equal(typeof loader, 'string'); const parsed = new URL(loader);
      assert.equal(parsed.origin, base); assert.ok(parsed.searchParams.get('meta-box-loader-nonce'));
      await admin.page.goto(loader);
    }
    const publish = admin.page.locator('#publish');
    if (await publish.count()) {
      await Promise.all([admin.page.waitForNavigation({ waitUntil: 'load' }), publish.click()]);
    } else { throw new Error('Expected native classic editor publish button for service topic.'); }
    await guest.page.goto(base + '/?p=' + state.id); await guest.page.locator('.sluyu-topic-gallery').waitFor();
    assert.equal(await guest.page.locator('.sluyu-topic-gallery img').count(), 2, 'Approved topic renders actual images to guests.');
    await guest.page.screenshot({ path: path.join(output, 'topic-media-published.png'), fullPage: true });
    await owner.page.goto(editorUrl); await root.waitFor(); await root.locator('[data-media-confirm]').check();
    state = await ajaxByClick(root.locator('[data-media-withdraw]')); assert.equal(state.status, 'draft'); assert.equal(state.phase, 'draft');
    assert.equal((await guest.context.request.get(uploads[0])).status(), 200, 'Withdrawal does not pretend public files are private.');
    const removedUrl = state.items[0].url;
    state = await ajaxByClick(root.locator('[data-media-list] li').first().getByRole('button', { name: '删除', exact: true }));
    assert.equal(state.items.length, 1); assert.equal((await guest.context.request.get(removedUrl)).status(), 404, 'Explicit removal deletes real stored file.');
    state = await ajaxByClick(root.locator('[data-media-abandon]')); assert.equal(state.status, 'trash');
    for (const url of uploads) assert.equal((await guest.context.request.get(url)).status(), 404, 'Abandonment cleans both original dedicated files.');
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(output, 'topic-media-browser-results.json'), JSON.stringify({ passed: true, checks: ['real-subscriber-multipart', 'public-disclosure-gate', 'guest-preapproval-public-file', 'pixel-reencode-no-original', 'mobile-persistent-reorder', 'pending-immutable', 'native-admin-publish', 'dedicated-withdraw', 'real-file-removal'], screenshots: ['topic-media-mobile.png','topic-media-published.png'], testedAt: new Date().toISOString() }, null, 2));
    console.log('PASS: real public topic image upload, re-encoding, public disclosure, reorder, review, withdrawal, removal and mobile UI.');
  } finally { for (const context of contexts) await context.close(); await browser.close(); }
})().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
