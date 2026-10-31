'use strict';
// Run after profile-media.php. This suite uses real multipart uploads and rate limits.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8771';
assert.match(base, /^http:\/\/(127\.0\.0\.1|localhost):8771$/, 'Only use the disposable profile-media fixture.');
const endpoint = base + '/wp-admin/admin-ajax.php';
const accountUrl = base + '/?sluyu_page=account&tab=overview';
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const marker = 'QA-PROFILE-ORIGINAL-PAYLOAD-MUST-NOT-SURVIVE';

function operationOf(request) {
  const body = request.postData() || '';
  if ((request.headers()['content-type'] || '').includes('multipart/form-data')) {
    const match = body.match(/name="operation"\r?\n\r?\n([^\r\n]+)/);
    return match ? match[1] : '';
  }
  return new URLSearchParams(body).get('operation');
}

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const members = []; const errors = []; const lastUpload = new Map();
  try {
    async function newContext() {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
      context.setDefaultTimeout(20000); contexts.push(context);
      return context;
    }
    async function member(login) {
      const context = await newContext(); const page = await context.newPage();
      page.on('pageerror', error => errors.push(login + ': ' + error.message));
      await page.goto(base + '/wp-login.php');
      await page.locator('#user_login').fill(login); await page.locator('#user_pass').fill('qa-local-only');
      await Promise.all([page.waitForURL(url => !url.pathname.includes('wp-login.php')), page.locator('#wp-submit').click()]);
      await page.goto(accountUrl);
      const nonce = await page.evaluate(() => wuaiSluyu.nonce);
      const result = { login, context, page, nonce, authorUrl: base + '/?author_name=' + encodeURIComponent(login) };
      members.push(result); return result;
    }
    function imageDetails(page, kind) {
      return page.locator('.sluyu-profile-media details').filter({ has: page.locator('form[data-operation="profile_image"] input[name="image_kind"][value="' + kind + '"]') });
    }
    async function openDetails(page, kind) {
      const details = imageDetails(page, kind);
      if (await details.getAttribute('open') === null) await details.locator('summary').click();
      return details;
    }
    function imageForm(page, kind) {
      return imageDetails(page, kind).locator('form[data-operation="profile_image"]');
    }
    async function previewUrl(page, kind) {
      const image = imageDetails(page, kind).locator('.sluyu-profile-media-preview');
      await image.waitFor({ state: 'attached' });
      return image.getAttribute('src');
    }
    async function action(page, button, operation, options = {}) {
      const expected = options.status || 200;
      const form = await button.evaluate(element => ({ operation: element.form && element.form.dataset.operation, enctype: element.form && element.form.enctype, nonceReady: !!(window.wuaiSluyu && window.wuaiSluyu.nonce), imageKind: element.form && new FormData(element.form).get('image_kind') }));
      assert.equal(form.operation, operation, 'Submitted form identifies the expected operation.');
      assert.equal(form.nonceReady, true, 'Frontend has a nonce for the submitted form.');
      if (options.multipart) assert.equal(form.enctype, 'multipart/form-data');
      const observed = [];
      const observe = response => {
        const request = response.request();
        if (!response.url().startsWith(endpoint) || request.method() !== 'POST') return;
        observed.push({ operation: operationOf(request) || '(body unavailable)', contentType: request.headers()['content-type'] || '', status: response.status() });
      };
      page.on('response', observe);
      const responsePromise = page.waitForResponse(response => {
        const request = response.request();
        if (!response.url().startsWith(endpoint) || request.method() !== 'POST') return false;
        // Chromium may omit multipart postData when files are present. During
        // this one form action, match the actual multipart POST, not its body.
        return options.multipart ? /^multipart\/form-data;\s*boundary=/.test(request.headers()['content-type'] || '') : operationOf(request) === operation;
      })
        .then(value => ({ value }), error => ({ error }));
      const loadPromise = options.reload ? page.waitForEvent('load').then(value => ({ value }), error => ({ error })) : null;
      try {
        await button.click();
        const outcome = await responsePromise;
        if (outcome.error) throw outcome.error;
        const response = outcome.value;
        assert.equal(response.status(), expected, operation + ' HTTP status');
        assert.match(response.request().headers()['content-type'] || '', options.multipart ? /^multipart\/form-data;\s*boundary=/ : /^application\/x-www-form-urlencoded/, operation + ' actual browser encoding');
        // Reloading success handlers can discard the response body in Chromium.
        // Verify status/encoding, then the resulting DOM instead of reading JSON.
        if (loadPromise) { const loaded = await loadPromise; if (loaded.error) throw loaded.error; }
        return response;
      } catch (error) {
        const status = await page.locator('.sluyu-form-status, .sluyu-toast').allTextContents().catch(() => []);
        throw new Error(operation + ' failed: ' + error.message + '\nForm: ' + JSON.stringify(form) + '\nObserved AJAX: ' + JSON.stringify(observed) + '\nUI status: ' + JSON.stringify(status));
      } finally { page.off('response', observe); }
    }
    async function waitUploadWindow(person) {
      const remaining = 16000 - (Date.now() - (lastUpload.get(person.login) || 0));
      if (remaining > 0) await new Promise(resolve => setTimeout(resolve, remaining));
    }
    async function upload(person, kind, file, extra = {}) {
      await waitUploadWindow(person);
      await openDetails(person.page, kind);
      const form = imageForm(person.page, kind);
      if (Object.keys(extra).length) await form.evaluate((element, fields) => {
        Object.entries(fields).forEach(([name, value]) => {
          const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = String(value); element.appendChild(input);
        });
      }, extra);
      await form.locator('input[type=file]').setInputFiles(file);
      await action(person.page, form.locator('button[type=submit]'), 'profile_image', { multipart: true, reload: true });
      lastUpload.set(person.login, Date.now());
      return previewUrl(person.page, kind);
    }
    async function apiAction(person, operation, data = {}) {
      const response = await person.context.request.post(endpoint, { form: { action: 'wuai_sluyu_community', nonce: person.nonce, operation, ...data } });
      return { status: response.status(), ...(await response.json()) };
    }
    async function publicImage(page, selector, expected, width, height) {
      const image = page.locator(selector).first();
      assert.equal(await image.getAttribute('src'), expected);
      await image.evaluate(element => element.decode());
      const dimensions = await image.evaluate(element => [element.naturalWidth, element.naturalHeight]);
      assert.deepEqual(dimensions, [width, height]);
    }
    async function remove(person, kind) {
      await openDetails(person.page, kind);
      const form = imageDetails(person.page, kind).locator('form[data-operation="remove_profile_image"]');
      await action(person.page, form.locator('button[type=submit]'), 'remove_profile_image', { reload: true });
      assert.equal(await imageDetails(person.page, kind).locator('.sluyu-profile-media-preview').count(), 0);
    }

    const owner = await member('qa_profile_media_owner');
    const other = await member('qa_profile_media_other');
    const guestContext = await newContext(); const guest = await guestContext.newPage();
    guest.on('pageerror', error => errors.push('guest: ' + error.message));
    assert.match(await owner.page.locator('.sluyu-profile-media').innerText(), /上传后会公开显示，任何访客均可查看/);
    assert.match(await owner.page.locator('.sluyu-profile-media').innerText(), /请勿上传证件、私密照片/);
    assert.equal(await imageForm(owner.page, 'avatar').getAttribute('enctype'), 'multipart/form-data');
    assert.equal(await imageForm(owner.page, 'avatar').locator('button[type=submit]').isDisabled(), false, 'HTTP PHP process enables GD.');
    assert.equal(await owner.page.locator('.sluyu-profile-media-preview').count(), 0, 'Run the CLI fixture first to clean profile media.');
    assert.equal(await other.page.locator('.sluyu-profile-media-preview').count(), 0, 'Second fixture account starts clean.');

    const profile = owner.page.locator('form[data-operation="profile"]');
    await action(owner.page, profile.locator('button[type=submit]'), 'profile');
    await profile.locator('.sluyu-form-status').filter({ hasText: '资料已保存' }).waitFor();
    const encoded = await owner.page.evaluate(() => {
      const canvas = document.createElement('canvas'); canvas.width = 640; canvas.height = 480;
      const context = canvas.getContext('2d'); context.fillStyle = '#ee709c'; context.fillRect(0, 0, 640, 480);
      context.fillStyle = '#fff4e7'; context.fillRect(140, 80, 300, 320);
      return canvas.toDataURL('image/png').split(',')[1];
    });
    const png = Buffer.concat([Buffer.from(encoded, 'base64'), Buffer.from(marker + '<?php echo "never-original"; ?>')]);
    const pngFile = { name: 'qa-profile.png', mimeType: 'image/png', buffer: png };
    const ownerAvatar = await upload(owner, 'avatar', pngFile);
    assert.match(ownerAvatar, /\/uploads\/.*profile-avatar-.*\.png$/);
    const downloaded = await guestContext.request.get(ownerAvatar);
    assert.equal(downloaded.status(), 200, 'Public image URL is usable.');
    const saved = await downloaded.body();
    assert.equal(saved.includes(Buffer.from(marker)), false, 'Public upload has no original trailing metadata.');
    assert.equal(saved.includes(Buffer.from('<?php')), false, 'Public upload has no original executable payload.');
    assert.equal(await owner.page.locator('.sluyu-profile-heading img.avatar').first().getAttribute('src'), ownerAvatar, 'Personal center displays updated avatar.');
    await guest.goto(owner.authorUrl);
    await publicImage(guest, '.sluyu-profile-heading img.avatar', ownerAvatar, 320, 320);
    const peerHref = await guest.locator('.sluyu-filter-tabs a').filter({ hasText: '发私信' }).getAttribute('href');
    const ownerId = new URL(peerHref, base).searchParams.get('peer');
    assert.match(ownerId || '', /^[1-9]\d*$/, 'Public author resolves a real owner ID.');

    const otherAvatar = await upload(other, 'avatar', pngFile, { user_id: ownerId, id: ownerId });
    assert.notEqual(otherAvatar, ownerAvatar, 'Forged user ID still stores an image only for the authenticated account.');
    await guest.goto(owner.authorUrl);
    await publicImage(guest, '.sluyu-profile-heading img.avatar', ownerAvatar, 320, 320);
    await guest.goto(other.authorUrl);
    await publicImage(guest, '.sluyu-profile-heading img.avatar', otherAvatar, 320, 320);
    const ownerCover = await upload(owner, 'cover', pngFile);
    assert.match(ownerCover, /\/uploads\/.*profile-cover-.*\.png$/);
    const forgedRemoval = await apiAction(other, 'remove_profile_image', { image_kind: 'cover', user_id: ownerId, id: ownerId });
    assert.equal(forgedRemoval.status, 200); assert.equal(forgedRemoval.success, true);
    assert.equal((await guestContext.request.get(ownerCover)).status(), 200, 'Cross-account removal request cannot delete the owner cover.');
    await guest.goto(owner.authorUrl);
    await publicImage(guest, '.sluyu-profile-cover', ownerCover, 640, 213);
    await publicImage(guest, '.sluyu-profile-heading img.avatar', ownerAvatar, 320, 320);
    await guest.screenshot({ path: path.join(output, 'profile-media-author-desktop.png'), fullPage: true, animations: 'disabled' });
    for (const width of [390, 320]) {
      await guest.setViewportSize({ width, height: 844 }); await guest.goto(owner.authorUrl);
      assert.equal(await guest.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Public profile fits ' + width);
      await owner.page.setViewportSize({ width, height: 844 }); await owner.page.goto(accountUrl);
      await openDetails(owner.page, 'avatar'); await openDetails(owner.page, 'cover');
      assert.equal(await owner.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Upload forms fit ' + width);
      if (width === 390) await owner.page.screenshot({ path: path.join(output, 'profile-media-mobile.png'), fullPage: true, animations: 'disabled' });
    }

    await waitUploadWindow(other);
    await openDetails(other.page, 'avatar');
    const invalid = imageForm(other.page, 'avatar');
    await invalid.locator('input[type=file]').setInputFiles({ name: 'fake.png', mimeType: 'image/png', buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>window.profileMediaUnsafe=1</script></svg>') });
    await action(other.page, invalid.locator('button[type=submit]'), 'profile_image', { multipart: true, status: 400 });
    lastUpload.set(other.login, Date.now());
    await invalid.locator('.sluyu-form-status').filter({ hasText: '仅支持扩展名与真实格式一致' }).waitFor();
    assert.equal(await previewUrl(other.page, 'avatar'), otherAvatar, 'Rejected fake image leaves existing avatar unchanged.');
    assert.equal(await other.page.evaluate(() => typeof window.profileMediaUnsafe), 'undefined', 'Rejected SVG never executes.');
    await invalid.locator('input[type=file]').setInputFiles(pngFile);
    await action(other.page, invalid.locator('button[type=submit]'), 'profile_image', { multipart: true, status: 400 });
    await invalid.locator('.sluyu-form-status').filter({ hasText: '15 秒' }).waitFor();
    assert.equal(await previewUrl(other.page, 'avatar'), otherAvatar, 'Immediate retry respects real rate limit.');

    await guest.goto(owner.authorUrl); const guestNonce = await guest.evaluate(() => wuaiSluyu.nonce);
    const anonymous = await guestContext.request.post(endpoint, { multipart: { action: 'wuai_sluyu_community', operation: 'profile_image', nonce: guestNonce, image_kind: 'avatar', profile_image: pngFile } });
    assert.equal(anonymous.status(), 401, 'Anonymous real multipart upload rejected.');
    const badNonce = await other.context.request.post(endpoint, { form: { action: 'wuai_sluyu_community', operation: 'remove_profile_image', image_kind: 'avatar', nonce: 'invalid' } });
    assert.equal(badNonce.status(), 403, 'Bad nonce cannot remove image.');

    await remove(owner, 'cover');
    await guest.goto(owner.authorUrl); assert.equal(await guest.locator('.sluyu-profile-cover').count(), 0, 'Removed cover disappears publicly.');
    assert.equal((await guestContext.request.get(ownerCover)).status(), 404, 'Dedicated old cover file is deleted.');
    await remove(owner, 'avatar');
    await guest.goto(owner.authorUrl);
    assert.notEqual(await guest.locator('.sluyu-profile-heading img.avatar').first().getAttribute('src'), ownerAvatar, 'Public avatar returns to default.');
    assert.equal((await guestContext.request.get(ownerAvatar)).status(), 404, 'Dedicated old avatar file is deleted.');
    await guest.goto(other.authorUrl);
    await publicImage(guest, '.sluyu-profile-heading img.avatar', otherAvatar, 320, 320);
    await other.page.goto(accountUrl); await remove(other, 'avatar');
    assert.deepEqual(errors, []);
    console.log('PASS: real multipart and ordinary form encoding, public-upload disclosure, avatar/cover rendering, pixel-only output, malformed-image rejection, actual 15-second throttling, cross-account target isolation, guest/nonce rejection, safe removal and 320/390 layouts.');
  } finally {
    // Cleanup through the normal authenticated API, never by bypassing production checks.
    for (const person of members) {
      for (const kind of ['avatar', 'cover']) {
        await person.context.request.post(endpoint, { form: { action: 'wuai_sluyu_community', nonce: person.nonce, operation: 'remove_profile_image', image_kind: kind } }).catch(() => {});
      }
    }
    for (const context of contexts) await context.close();
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
