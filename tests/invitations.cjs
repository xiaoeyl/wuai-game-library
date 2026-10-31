'use strict';
// Run after invitations-browser-fixture.php. No remote mail or production sites.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8771';
assert.equal(base, 'http://127.0.0.1:8771', 'Only the guarded disposable invite fixture is allowed.');
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'invitations-fixture.json'), 'utf8'));
const portal = key => base + '/?sluyu_page=' + key;
const suffix = Date.now();
const usernames = Object.fromEntries(['optional', 'required', 'reuse', 'invalid', 'expired', 'revoked', 'missing', 'disabled', 'mutated'].map(key => [key, 'qa_inv_browser_reg_' + key + '_' + suffix]));
const adminUrl = base + '/wp-admin/admin.php?page=wuai-sluyu-invitations';
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const errors = [];
  try {
    async function contextFor(login) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' }); contexts.push(context);
      const page = await context.newPage(); page.on('pageerror', error => errors.push({ account: login || 'guest', url: page.url(), stack: error.stack || error.message }));
      if (login) {
        const response = await page.goto(base + '/wp-login.php?redirect_to=' + encodeURIComponent(portal('invitations')));
        assert.equal(response.headers()['x-wuai-qa-mail-blocked'], '1', 'QA mail blocker must be active before registrations.');
        await page.locator('#user_login').fill(login); await page.locator('#user_pass').fill('qa-local-only');
        await Promise.all([page.waitForURL(location => !location.pathname.includes('wp-login.php')), page.locator('#wp-submit').click()]);
        await page.goto(portal('invitations'));
      }
      return { context, page };
    }
    const admin = await contextFor(fixture.login_prefix + 'admin'); const owner = await contextFor(fixture.login_prefix + 'owner'); const other = await contextFor(fixture.login_prefix + 'other'); const banned = await contextFor(fixture.login_prefix + 'banned'); const guest = await contextFor();
    async function state() {
      await admin.page.goto(adminUrl);
      const nonce = await admin.page.locator('#wuai-invitation-qa-nonce').inputValue();
      const response = await admin.context.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'wuai_invitation_qa_state', _ajax_nonce: nonce } });
      const data = await response.json(); assert.equal(data.success, true); return data.data;
    }
    async function settings(enabled, required) {
      await admin.page.goto(adminUrl);
      const form = admin.page.locator('form').filter({ has: admin.page.locator('input[name=action][value=wuai_invitation_settings]') });
      await form.locator('input[name=enabled]').setChecked(enabled); await form.locator('input[name=required]').setChecked(required);
      await Promise.all([admin.page.waitForEvent('load'), form.locator('input[type=submit]').click()]);
      assert.equal(await admin.page.locator('input[name=enabled]').isChecked(), enabled);
      assert.equal(await admin.page.locator('input[name=required]').isChecked(), enabled && required);
    }
    async function register(key, code, expectedSuccess, removeRequired = false) {
      const response = await guest.page.goto(base + '/wp-login.php?action=register');
      assert.equal(response.headers()['x-wuai-qa-mail-blocked'], '1', 'Never submit when mail blocking is absent.');
      await guest.page.locator('#user_login').fill(usernames[key]); await guest.page.locator('#user_email').fill(usernames[key] + '@example.test');
      if (await guest.page.locator('#wuai_invitation_code').count()) {
        await guest.page.locator('#wuai_invitation_code').fill(code || '');
        if (removeRequired) await guest.page.locator('#wuai_invitation_code').evaluate(input => input.removeAttribute('required'));
      }
      await Promise.all([guest.page.waitForEvent('load'), guest.page.locator('#wp-submit').click()]);
      if (expectedSuccess) assert.equal(new URL(guest.page.url()).searchParams.get('checkemail'), 'registered', key + ' core registration succeeds.');
      else { assert.equal(new URL(guest.page.url()).searchParams.get('action'), 'register'); assert.equal(await guest.page.locator('#login_error').count(), 1, key + ' displays a core registration error.'); }
    }
    async function ajaxClick(page, button, operation, reload = false, confirm = false) {
      if (confirm) page.once('dialog', dialog => dialog.accept());
      const pending = page.waitForResponse(response => response.url().includes('/wp-admin/admin-ajax.php') && new URLSearchParams(response.request().postData() || '').get('operation') === operation).then(value => ({ value }), error => ({ error }));
      const load = reload ? page.waitForEvent('load').then(value => ({ value }), error => ({ error })) : null;
      await button.click(); const outcome = await pending; if (outcome.error) throw outcome.error; assert.equal(outcome.value.status(), 200);
      if (load) { const loaded = await load; if (loaded.error) throw loaded.error; }
      else await page.waitForFunction(op => { const form = document.querySelector('form[data-operation="' + op + '"]'); return form && !form.dataset.busy && form.querySelector('.sluyu-form-status').textContent; }, operation);
    }

    const beforeDenied = await state();
    const settingsNonce = await admin.page.locator('form:has(input[value="wuai_invitation_settings"]) [name=_wpnonce]').inputValue();
    const operationNonce = await admin.page.locator('form:has(input[value="wuai_invitation_admin_operation"]) [name=_wpnonce]').first().inputValue();
    for (const [action, nonce, extra] of [
      ['wuai_invitation_settings', settingsNonce, { enabled: '0', required: '1', days: '30' }],
      ['wuai_invitation_admin_operation', operationNonce, { operation: 'revoke', id: String(fixture.codes.valid.id) }],
    ]) {
      const params = { action, _wpnonce: nonce, ...extra };
      const wrongMethod = await admin.context.request.get(base + '/wp-admin/admin-post.php?' + new URLSearchParams(params), { maxRedirects: 0 });
      assert.equal(wrongMethod.status(), 405, action + ' refuses GET even with a valid nonce.');
      const invalid = await admin.context.request.post(base + '/wp-admin/admin-post.php', { form: { ...params, _wpnonce: 'invalid' }, maxRedirects: 0 });
      assert.equal(invalid.status(), 403);
      for (const person of [owner, banned]) {
        const denied = await person.context.request.post(base + '/wp-admin/admin-post.php', { form: params, maxRedirects: 0 });
        assert.equal(denied.status(), 403, action + ' rejects subscribers and banned administrators.');
      }
      const anonymous = await guest.context.request.post(base + '/wp-admin/admin-post.php', { form: params, maxRedirects: 0 });
      assert.ok(anonymous.status() >= 400, 'No anonymous admin operation handler.');
    }
    assert.deepEqual((await state()).invites, beforeDenied.invites, 'Rejected admin operations do not change any invitation.');
    assert.equal(await admin.page.locator('input[name=enabled]').isChecked(), true);
    assert.equal(await admin.page.locator('input[name=required]').isChecked(), false);
    await settings(true, false);
    await guest.page.goto(base + '/wp-login.php?action=register');
    assert.equal(await guest.page.locator('#wuai_invitation_code').getAttribute('required'), null, 'Optional mode does not require code in browser.');
    await register('optional', '', true);
    await settings(true, true);
    await register('missing', '', false, true);
    await register('invalid', 'SL-' + '0'.repeat(32), false);
    await register('expired', fixture.codes.expired.code, false);
    await register('revoked', fixture.codes.revoked.code, false);
    await register('required', fixture.codes.valid.code.toLowerCase(), true);
    const registered = await state(); const validRow = registered.invites.find(row => Number(row.id) === fixture.codes.valid.id);
    const newUser = registered.users.find(user => user.user_login === usernames.required);
    assert.ok(newUser); assert.equal(validRow.status, 'claimed'); assert.equal(Number(validRow.claimed_by), Number(newUser.ID));
    await register('reuse', fixture.codes.valid.code, false);
    await register('mutated', fixture.codes.mutated.code, true);
    const mutationState = await state(); const mutatedRow = mutationState.invites.find(row => Number(row.id) === fixture.codes.mutated.id);
    assert.equal(mutatedRow.status, 'reserved', 'Identity-filtered successful account must quarantine its code, not recycle it.');
    assert.ok(mutationState.users.find(user => user.user_login === usernames.mutated && user.user_email.endsWith('+filtered@example.test')));
    const newMember = await contextFor(usernames.required);
    assert.equal(await newMember.page.locator('.sluyu-invitations').count(), 1, 'Newly registered account can sign in and use invitation page.');
    assert.equal(await newMember.page.locator('.sluyu-compact-list [data-community-action=invite_revoke]').count(), 0);

    await owner.page.goto(portal('invitations'));
    const form = owner.page.locator('form[data-operation=invite_generate]');
    await ajaxClick(owner.page, form.locator('[type=submit]'), 'invite_generate');
    const receipt = await form.locator('.sluyu-form-status').innerText(); const code = receipt.match(/SL-[A-F0-9]{32}/)[0];
    assert.match(receipt, /此明文只显示本次/);
    const receiptResponse = await owner.context.request.get(portal('invitations'));
    assert.match(receiptResponse.headers()['cache-control'] || '', /no-cache|no-store/i);
    await owner.page.reload(); assert.equal((await owner.page.locator('.sluyu-invitations').innerText()).includes(code), false, 'Reload cannot recover plaintext code.');
    const hint = code.slice(-8); const ownRow = owner.page.locator('.sluyu-compact-list > div').filter({ hasText: hint });
    assert.equal(await ownRow.count(), 1); const issuedId = await ownRow.locator('[data-community-action=invite_revoke]').getAttribute('data-id');
    await other.page.goto(portal('invitations')); assert.equal((await other.page.locator('.sluyu-invitations').innerText()).includes(hint), false, 'Another account cannot see owner hints.');
    const nonce = await other.page.evaluate(() => wuaiSluyu.nonce);
    const forbidden = await other.context.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'wuai_sluyu_community', nonce, operation: 'invite_revoke', id: issuedId } });
    assert.equal((await forbidden.json()).success, false, 'Another account cannot revoke known invite ID.');
    await owner.page.screenshot({ path: path.join(output, 'invitations-owner-desktop.png'), fullPage: true, animations: 'disabled' });
    await ajaxClick(owner.page, ownRow.locator('[data-community-action=invite_revoke]'), 'invite_revoke', true, true);
    assert.match(await owner.page.locator('.sluyu-compact-list > div').filter({ hasText: hint }).innerText(), /已撤销/);
    for (const width of [390, 320]) {
      await owner.page.setViewportSize({ width, height: 844 }); await owner.page.goto(portal('invitations'));
      assert.equal(await owner.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Invitation page at ' + width);
      await guest.page.setViewportSize({ width, height: 844 }); await guest.page.goto(base + '/wp-login.php?action=register');
      assert.equal(await guest.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Core registration at ' + width);
      if (width === 390) await guest.page.screenshot({ path: path.join(output, 'invitations-register-mobile.png'), fullPage: true, animations: 'disabled' });
    }
    await settings(false, false);
    await guest.page.goto(base + '/wp-login.php?action=register'); assert.equal(await guest.page.locator('#wuai_invitation_code').count(), 0);
    await register('disabled', '', true);
    await owner.page.goto(portal('invitations')); assert.equal(await owner.page.locator('form[data-operation=invite_generate]').count(), 0);
    const final = await state(); assert.ok(final.mail_blocked > 0, 'All registration mail attempts were intercepted.'); assert.equal(final.registered, 1, 'Invitation toggles do not rewrite system registration setting.');
    for (const name of ['missing', 'invalid', 'expired', 'revoked', 'reuse']) assert.equal(final.users.some(user => user.user_login === usernames[name]), false, 'Failed flow creates no account: ' + name);
    assert.deepEqual(errors, []);
    console.log('PASS: real core registration optional/required/disabled, one-time claims/reuse/errors/expiry, identity-change quarantine, login, POST-only admin settings/actions with nonce/role/ban checks, one-time owner receipt, revoke/privacy and 320/390 layouts. All mail blocked.');
  } finally { for (const context of contexts) await context.close(); await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
