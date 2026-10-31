'use strict';
// Run membership-browser-fixture.php first. Only native Woo/admin actions mutate
// the order; the guarded MU endpoint exposes read-only fixture state for assertions.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8771';
assert.equal(base, 'http://127.0.0.1:8771', 'Only the isolated guarded membership fixture is allowed.');
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'membership-browser-fixture.json'), 'utf8'));
assert.equal(fixture.base, base);
for (const key of ['edit_url', 'resource_url']) assert.equal(new URL(fixture[key]).origin, base);
const adminPost = base + '/wp-admin/admin-post.php';
const ajaxUrl = base + '/wp-admin/admin-ajax.php';
const vipUrl = base + '/?sluyu_page=vips';
const postAction = request => new URLSearchParams(request.postData() || '').get('action');

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const errors = []; const dialogs = [];
  try {
    async function person(name) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
      contexts.push(context); context.setDefaultTimeout(25000);
      await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
      const page = await context.newPage(); page.on('pageerror', error => errors.push(name + ': ' + error.message));
      page.on('dialog', async dialog => { dialogs.push({ type: dialog.type(), message: dialog.message() }); await dialog.accept(); });
      if (name) {
        const response = await page.goto(base + '/wp-login.php');
        assert.equal(response.headers()['x-wuai-qa-membership-mail-blocked'], '1', 'Mail and gateway guard must be active before order mutations.');
        await page.locator('#user_login').fill(fixture.login_prefix + name);
        await page.locator('#user_pass').fill('qa-local-only');
        await Promise.all([page.waitForURL(url => !url.pathname.includes('wp-login.php')), page.locator('#wp-submit').click()]);
      }
      return { context, page };
    }
    const admin = await person('admin'); const reader = await person('reader'); const other = await person('other');
    const banned = await person('banned'); const limited = await person('limited'); const guest = await person('');
    async function edit() {
      const response = await admin.page.goto(fixture.edit_url);
      assert.equal(response.status(), 200);
      assert.equal(response.headers()['x-wuai-qa-membership-mail-blocked'], '1');
      await admin.page.locator('#wuai-membership-state .wuai-membership-diagnostic').waitFor();
      assert.equal(await admin.page.locator('button.do-api-refund').count(), 0, 'Fixture has no remote gateway refund control.');
    }
    await edit();
    const qaNonce = await admin.page.locator('#wuai-membership-qa-nonce').inputValue();
    async function state() {
      const response = await admin.context.request.post(ajaxUrl, { form: { action: 'wuai_membership_qa_state', _ajax_nonce: qaNonce } });
      assert.equal(response.status(), 200); const result = await response.json(); assert.equal(result.success, true); return result.data;
    }
    async function access(person, active) {
      const response = await person.page.goto(vipUrl);
      assert.equal(response.status(), 200);
      const status = await person.page.locator('.sluyu-commerce .sluyu-notice').innerText();
      assert.match(status, active ? /会员有效/ : /尚未开通 \/ 已到期/);
      const protectedResponse = await person.page.goto(fixture.resource_url);
      assert.equal(protectedResponse.status(), 200);
      const html = await protectedResponse.text();
      assert.equal(html.includes(fixture.secret), active, 'Server HTML respects the current account membership, including revocation.');
      assert.equal((await person.page.locator('body').innerText()).includes(fixture.secret), active, 'Member-only fragment matches the server decision.');
    }
    async function saveOrder() {
      await Promise.all([admin.page.waitForEvent('load'), admin.page.locator('button.save_order').click()]);
      await admin.page.locator('#wuai-membership-state').waitFor();
    }
    async function status(value) {
      await admin.page.locator('#order_status').selectOption('wc-' + value, { force: true });
      await saveOrder();
      assert.equal((await state()).order.status, value, 'Native Woo order save persisted status.');
    }
    async function retry() {
      const button = admin.page.locator('#wuai-membership-state button[form]');
      assert.equal(await button.evaluate(element => element.form.id), 'wuai-membership-retry-' + fixture.order, 'External form association prevents nested Woo edit form submission.');
      const pending = admin.page.waitForResponse(response => response.url() === adminPost && response.request().method() === 'POST' && postAction(response.request()) === 'wuai_membership_retry');
      const load = admin.page.waitForEvent('load'); await button.click();
      const response = await pending; assert.equal(response.status(), 302);
      const sent = new URLSearchParams(response.request().postData());
      assert.equal(sent.get('order_id'), String(fixture.order));
      assert.equal(sent.has('order_status'), false, 'Retry never submits unsaved status edits.');
      assert.equal(sent.has('customer_user'), false, 'Retry never submits unsaved customer edits.');
      await load;
      assert.match(await admin.page.locator('.wuai-membership-retry-result').innerText(), /已重新核对会员授权；未重复收款/);
    }
    async function denied(person, fields, expected = 403) {
      const response = await person.context.request.post(adminPost, { form: { action: 'wuai_membership_retry', order_id: fixture.order, ...fields }, maxRedirects: 0 });
      assert.equal(response.status(), expected);
    }
    let current = await state();
    assert.equal(current.order.status, 'pending'); assert.equal(current.order.queued, true);
    assert.equal(current.users.reader.dirty, true); assert.equal(current.users.reader.pending, true);
    assert.equal(await admin.page.locator('.wuai-membership-diagnostic').getAttribute('data-pending'), '1');
    assert.match(await admin.page.locator('.wuai-membership-queue').innerText(), /已排队重试/);
    await access(reader, false); await access(other, false);
    await guest.page.goto(fixture.resource_url); assert.equal((await guest.page.content()).includes(fixture.secret), false);
    const original = current;
    const nonce = await admin.page.locator('#wuai-membership-retry-' + fixture.order + ' [name=_wuai_membership_nonce]').inputValue();
    await denied(admin, { _wuai_membership_nonce: 'invalid' });
    await denied(admin, { _wuai_membership_nonce: nonce, order_id: fixture.decoy });
    await denied(reader, { _wuai_membership_nonce: nonce });
    await denied(other, { _wuai_membership_nonce: nonce });
    await denied(guest, { _wuai_membership_nonce: nonce });
    await denied(banned, { _wuai_membership_nonce: nonce });
    await denied(limited, { _wuai_membership_nonce: nonce });
    const wrongMethod = await admin.context.request.get(adminPost + '?action=wuai_membership_retry&order_id=' + fixture.order, { maxRedirects: 0 });
    assert.equal(wrongMethod.status(), 405);
    assert.deepEqual((await state()).users, original.users, 'Rejected nonce/method/role/order requests do not reconcile anyone.');
    const fixtureForbidden = await reader.context.request.post(ajaxUrl, { form: { action: 'wuai_membership_qa_state', _ajax_nonce: qaNonce } });
    assert.equal(fixtureForbidden.status(), 403, 'Even the local read-only fixture is administrator-only.');

    // An unsaved paid status must not be smuggled through the separate retry form.
    await admin.page.locator('#order_status').selectOption('wc-completed', { force: true });
    await retry(); current = await state();
    assert.equal(current.order.status, 'pending'); assert.equal(current.order.queued, false);
    assert.equal(current.users.reader.dirty, false); assert.equal(current.users.reader.pending, false);
    assert.equal(current.users.reader.until, 0); assert.equal(current.users.other.until, 0);
    assert.equal(await admin.page.locator('.wuai-membership-diagnostic').getAttribute('data-pending'), '0');
    await status('completed'); current = await state();
    const paidAt = Number(current.order.paid_at); const paidUntil = Number(current.users.reader.until);
    assert.ok(paidAt > 0); assert.equal(paidUntil, paidAt + 30 * 86400);
    assert.equal(current.order.recipient, fixture.users.reader); assert.equal(current.order.payment_method, ''); assert.equal(current.order.transaction, '');
    assert.match(await admin.page.locator('.wuai-membership-status').innerText(), /本单已授权/);
    await access(reader, true); await access(other, false);
    await retry(); assert.equal((await state()).users.reader.until, paidUntil, 'Repeated true admin retry does not renew or charge again.');

    await admin.page.locator('#select2-customer_user-container').click();
    await admin.page.locator('.select2-container--open input.select2-search__field').fill(fixture.login_prefix + 'other');
    const customerOption = admin.page.locator('.select2-results__option[role=option]').filter({ hasText: fixture.login_prefix + 'other@example.test' }).first();
    await customerOption.waitFor(); await customerOption.click();
    assert.equal(await admin.page.locator('#customer_user').inputValue(), String(fixture.users.other));
    await saveOrder(); current = await state();
    assert.equal(current.order.customer, fixture.users.other); assert.equal(current.order.recipient, fixture.users.other);
    assert.equal(current.users.reader.until, 0); assert.equal(current.users.other.until, paidUntil);
    assert.equal(Object.prototype.hasOwnProperty.call(current.users.reader.grants, fixture.order), false);
    assert.equal(Object.prototype.hasOwnProperty.call(current.users.other.grants, fixture.order), true);
    await access(reader, false); await access(other, true);
    await admin.page.screenshot({ path: path.join(output, 'membership-admin-transfer.png'), fullPage: true, animations: 'disabled' });

    // Native manual refund only; no gateway is available and api_refund is checked.
    await admin.page.locator('button.refund-items').click();
    const refundLine = admin.page.locator('#order_line_items tr.item input.refund_line_total').first();
    await refundLine.fill('1'); await refundLine.press('Tab');
    await admin.page.locator('#refund_reason').fill('Local browser regression - no gateway');
    const refundResponse = admin.page.waitForResponse(response => response.url().includes('/wp-admin/admin-ajax.php') && postAction(response.request()) === 'woocommerce_refund_line_items');
    const refundedLoad = admin.page.waitForEvent('load'); await admin.page.locator('button.do-manual-refund').click();
    const refunded = await refundResponse; assert.equal(refunded.status(), 200);
    assert.equal(new URLSearchParams(refunded.request().postData()).get('api_refund'), 'false', 'Native manual path cannot issue a real gateway refund.');
    await refundedLoad; current = await state();
    assert.equal(Number(current.order.refunded), 1); assert.equal(current.order.refunds.length, 1);
    assert.equal(current.users.other.until, 0); await access(reader, false); await access(other, false);
    assert.match(await admin.page.locator('.wuai-membership-status').innerText(), /本单未授权/);
    const deleteResponse = admin.page.waitForResponse(response => response.url().includes('/wp-admin/admin-ajax.php') && postAction(response.request()) === 'woocommerce_delete_refund');
    await admin.page.locator('tr.refund a.delete_refund').click(); assert.equal((await deleteResponse).status(), 200);
    await admin.page.locator('tr.refund').waitFor({ state: 'detached' });
    current = await state(); assert.equal(Number(current.order.refunded), 0); assert.equal(current.order.refunds.length, 0);
    assert.equal(current.users.other.until, paidUntil, 'Deleting the local refund restores exactly the original grant timestamp.');
    await access(reader, false); await access(other, true);
    await edit(); await status('refunded'); current = await state();
    assert.equal(current.users.other.until, 0); assert.equal(current.order.queued, false); await access(other, false);
    assert.equal(Number(current.order.refunded), 10, 'Woo creates a manual full refund when status changes to refunded.');
    assert.equal(current.order.refunds.length, 1);
    await status('completed'); current = await state();
    assert.equal(Number(current.order.refunded), 10, 'Changing status back does not erase Woo refund records.');
    assert.equal(current.users.other.until, 0, 'A retained refund must continue denying access even with a paid status.');
    await access(other, false);
    const deleteFullResponse = admin.page.waitForResponse(response => response.url().includes('/wp-admin/admin-ajax.php') && postAction(response.request()) === 'woocommerce_delete_refund');
    await admin.page.locator('tr.refund a.delete_refund').click(); assert.equal((await deleteFullResponse).status(), 200);
    await admin.page.locator('tr.refund').waitFor({ state: 'detached' });
    current = await state(); assert.equal(Number(current.order.refunded), 0); assert.equal(current.users.other.until, paidUntil);
    await access(other, true);
    for (const width of [390, 320]) {
      await other.page.setViewportSize({ width, height: 844 }); await other.page.goto(vipUrl);
      assert.equal(await other.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Membership status fits ' + width + 'px.');
      assert.match(await other.page.locator('.sluyu-commerce .sluyu-notice').innerText(), /会员有效/);
      if (width === 390) await other.page.screenshot({ path: path.join(output, 'membership-vip-mobile.png'), fullPage: true, animations: 'disabled' });
    }
    current = await state(); assert.ok(current.mail_blocked > 0, 'Native order emails are intercepted by the fixture.');
    assert.equal(current.order.transaction, ''); assert.equal(current.order.payment_method, '');
    assert.equal(dialogs.some(dialog => dialog.type === 'alert'), false, 'WooCommerce did not display an AJAX failure alert.');
    assert.deepEqual(errors, []);
    console.log('PASS: real Woo order paid/refunded state, native customer transfer, manual-only refund/delete, read-only server state + cross-user VIP/protected DOM, independent nonce POST retry/dirty recovery/idempotence, no saved-edit smuggling, guest/subscriber/limited/banned/order-bound nonce denial and 320/390 layouts; mail blocked, no payment gateway called.');
  } finally { for (const context of contexts) await context.close(); await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
