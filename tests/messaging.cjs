'use strict';
// Run after tests/messaging.php, without another suite mutating the same QA users.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8771';
assert.match(base, /^http:\/\/(127\.0\.0\.1|localhost):8771$/, 'Only use the isolated messaging fixture.');
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const url = (key, args = {}) => base + '/?' + new URLSearchParams({ sluyu_page: key, ...args });
const marker = 'BROWSER-PRIVATE-A-B-' + Date.now();

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const errors = [];
  try {
    async function member(login) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
      contexts.push(context);
      const page = await context.newPage(); page.on('pageerror', error => errors.push(login + ': ' + error.message));
      await page.goto(base + '/wp-login.php');
      await page.locator('#user_login').fill(login); await page.locator('#user_pass').fill('qa-local-only');
      await Promise.all([page.waitForURL(location => !location.pathname.includes('wp-login.php')), page.locator('#wp-submit').click()]);
      await page.goto(url('directmessage'));
      const nonce = await page.evaluate(() => wuaiSluyu.nonce);
      return { context, page, action: async (operation, data = {}) => {
        const response = await context.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'wuai_sluyu_community', nonce, operation, ...data } });
        return { status: response.status(), ...(await response.json()) };
      } };
    }
    async function peerId(page, name) {
      const href = await page.locator('.sluyu-message-thread header a').filter({ hasText: name }).getAttribute('href');
      const id = new URL(href, base).searchParams.get('peer');
      assert.match(id, /^[1-9][0-9]*$/, 'Fixture peer ID exists for ' + name);
      return id;
    }
    async function reloadAction(page, locator, operation, confirmation = false) {
      if (confirmation) page.once('dialog', dialog => dialog.accept());
      function requestOperation(request) {
        const body = request.postData() || '';
        const contentType = request.headers()['content-type'] || '';
        if (contentType.includes('multipart/form-data')) {
          const match = body.match(/name="operation"\r?\n\r?\n([^\r\n]+)/);
          return match ? match[1] : '';
        }
        return new URLSearchParams(body).get('operation');
      }
      const observed = [];
      function observeResponse(response) {
        if (!response.url().includes('/wp-admin/admin-ajax.php')) return;
        observed.push({ operation: requestOperation(response.request()), status: response.status(), contentType: response.request().headers()['content-type'] || '' });
      }
      page.on('response', observeResponse);
      const responsePromise = page.waitForResponse(response => {
        if (!response.url().includes('/wp-admin/admin-ajax.php')) return false;
        return requestOperation(response.request()) === operation;
      }).then(value => ({ value }), error => ({ error }));
      // Handle rejection immediately: an AJAX/assertion failure must not leave a
      // navigation waiter that later masks the real failure when the page closes.
      const navigationPromise = page.waitForEvent('load').then(value => ({ value }), error => ({ error }));
      try {
        await locator.click();
        const responseOutcome = await responsePromise;
        if (responseOutcome.error) throw responseOutcome.error;
        const response = responseOutcome.value;
        // Successful actions reload immediately, so Chromium may discard the
        // response body before a protocol read. Verify HTTP + the resulting UI.
        assert.equal(response.status(), 200, operation + ' HTTP ' + response.status());
        const navigationOutcome = await navigationPromise;
        if (navigationOutcome.error) throw navigationOutcome.error;
      } catch (error) {
        const status = await page.locator('.sluyu-form-status, .sluyu-toast').allTextContents().catch(() => []);
        throw new Error(operation + ' failed: ' + error.message + '\nObserved AJAX: ' + JSON.stringify(observed) + '\nUI status: ' + JSON.stringify(status));
      } finally {
        page.off('response', observeResponse);
      }
    }
    async function preference(member, value) {
      await member.page.goto(url('directmessage'));
      await member.page.locator('.sluyu-message-settings > summary').click();
      const form = member.page.locator('form[data-operation=message_preferences]');
      await form.locator('select[name=receive]').selectOption(value);
      await reloadAction(member.page, form.locator('[type=submit]'), 'message_preferences');
      assert.equal(await member.page.locator('select[name=receive]').inputValue(), value);
    }
    const a = await member('qa_messages_a'); const b = await member('qa_messages_b'); const c = await member('qa_messages_c');
    const ids = { a: await peerId(b.page, 'Message User A'), b: await peerId(a.page, 'Message User B'), c: await peerId(b.page, 'Message User C') };
    assert.equal(await b.page.locator('.sluyu-message-thread').count(), 2, 'Fixture has one row per conversation, not one per message.');
    assert.match(await b.page.locator('.sluyu-message-thread').first().innerText(), /New day resets daily cap/);
    for (const who of [a, b, c]) assert.equal((await who.action('message_preferences', { receive: 'all' })).success, true);
    assert.equal((await a.action('message_block', { id: ids.b, decision: 'unblock' })).success, true);
    assert.equal((await b.action('message_block', { id: ids.a, decision: 'unblock' })).success, true);

    await preference(b, 'none');
    const rejectedNone = await c.action('message', { id: ids.b, body: 'Must not deliver while receiver paused.' });
    assert.equal(rejectedNone.success, false);
    await preference(b, 'following');
    const rejectedFollowing = await c.action('message', { id: ids.b, body: 'Must not deliver without recipient follow.' });
    assert.equal(rejectedFollowing.success, false);
    assert.equal(rejectedFollowing.data.message, rejectedNone.data.message, 'Preferences do not disclose account availability.');
    await b.page.goto(url('directmessage', { peer: ids.a }));
    await reloadAction(b.page, b.page.locator('[data-community-action=message_block][data-decision=block]').first(), 'message_block', true);
    assert.equal((await a.action('message', { id: ids.b, body: 'Must not deliver while blocked.' })).success, false);
    assert.equal((await b.action('message', { id: ids.a, body: 'Blocked relation is symmetric.' })).success, false);
    await reloadAction(b.page, b.page.locator('[data-community-action=message_block][data-decision=unblock]').first(), 'message_block', true);
    await preference(b, 'all');

    await a.page.goto(url('directmessage'));
    const composer = a.page.locator('form[data-operation=message]');
    await composer.locator('input[name=recipient]').fill('qa_messages_b');
    await composer.locator('textarea[name=body]').fill(marker);
    // This is the only successful HTTP send per fixture user; no rate-limit sleeps are needed.
    await reloadAction(a.page, composer.locator('[type=submit]'), 'message');
    await b.page.goto(url('directmessage'));
    assert.equal(await b.page.locator('.sluyu-message-thread').count(), 2);
    const thread = b.page.locator('.sluyu-message-thread').filter({ hasText: marker });
    assert.equal(await thread.count(), 1, 'Latest sent body appears once in aggregated inbox.');
    assert.match(await thread.innerText(), /未读/);
    await b.page.screenshot({ path: path.join(output, 'messaging-inbox-desktop.png'), fullPage: true, animations: 'disabled' });
    await b.page.goto(url('directmessage', { peer: ids.a }));
    assert.equal(await b.page.locator('form[data-operation=message] input[name=recipient]').count(), 0, 'Peer composer does not expose login names.');
    assert.equal(await b.page.locator('form[data-operation=message] input[name=id]').inputValue(), ids.a);
    const message = b.page.locator('article.sluyu-message').filter({ hasText: marker });
    const messageId = await message.locator('[data-community-action=message_delete]').getAttribute('data-id');
    assert.equal((await c.action('message_delete', { id: messageId })).success, false, 'Another account cannot delete a known message ID.');
    await c.page.goto(url('directmessage', { peer: ids.a }));
    assert.equal((await c.page.locator('.sluyu-portal-content').innerText()).includes(marker), false);
    assert.equal((await c.page.locator('.sluyu-portal-content').innerText()).includes('PAGINATED-MESSAGE-'), false);
    await reloadAction(b.page, b.page.locator('[data-community-action=read_messages]').first(), 'read_messages');
    assert.match(await b.page.locator('.sluyu-state-pill').first().innerText(), /未读 0/);

    const firstIds = await b.page.locator('article.sluyu-message [data-community-action=message_delete]').evaluateAll(nodes => nodes.map(node => node.dataset.id));
    assert.equal(firstIds.length, 20, 'First conversation page is bounded.');
    await b.page.locator('.sluyu-portal-pagination a.next').click();
    assert.equal(new URL(b.page.url()).searchParams.get('peer'), ids.a, 'Pagination preserves peer context.');
    const secondIds = await b.page.locator('article.sluyu-message [data-community-action=message_delete]').evaluateAll(nodes => nodes.map(node => node.dataset.id));
    assert.ok(secondIds.length > 0 && !secondIds.some(id => firstIds.includes(id)), 'Conversation pages do not overlap.');

    for (const width of [390, 320]) {
      await b.page.setViewportSize({ width, height: 844 });
      for (const target of [url('directmessage'), url('directmessage', { peer: ids.a }), url('message')]) {
        await b.page.goto(target);
        assert.equal(await b.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, target + ' at ' + width);
      }
      await b.page.goto(url('directmessage', { peer: ids.a }));
      await b.page.locator('.sluyu-message-settings > summary').click();
      assert.equal(await b.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Expanded settings at ' + width);
      if (width === 390) await b.page.screenshot({ path: path.join(output, 'messaging-thread-mobile.png'), fullPage: true, animations: 'disabled' });
    }
    await b.page.setViewportSize({ width: 1440, height: 1000 });

    await a.page.goto(url('directmessage', { peer: ids.b }));
    let ownMessage = a.page.locator('article.sluyu-message').filter({ hasText: marker });
    a.page.once('dialog', dialog => dialog.dismiss());
    await ownMessage.locator('[data-community-action=message_delete]').click();
    assert.equal(await ownMessage.count(), 1, 'Cancelling deletion preserves the message.');
    await reloadAction(a.page, ownMessage.locator('[data-community-action=message_delete]'), 'message_delete', true);
    assert.equal(await a.page.locator('article.sluyu-message').filter({ hasText: marker }).count(), 0);
    await b.page.goto(url('directmessage', { peer: ids.a }));
    assert.equal(await b.page.locator('article.sluyu-message').filter({ hasText: marker }).count(), 1, 'Sender deletion does not recall recipient copy.');
    await reloadAction(b.page, b.page.locator('article.sluyu-message').filter({ hasText: marker }).locator('[data-community-action=message_delete]'), 'message_delete', true);
    assert.equal(await b.page.locator('article.sluyu-message').filter({ hasText: marker }).count(), 0);
    b.page.once('dialog', dialog => dialog.dismiss());
    await b.page.locator('[data-community-action=conversation_delete]').click();
    assert.ok(await b.page.locator('article.sluyu-message').count(), 'Cancelling entire-conversation deletion preserves messages.');
    await reloadAction(b.page, b.page.locator('[data-community-action=conversation_delete]'), 'conversation_delete', true);
    assert.equal(await b.page.locator('article.sluyu-message').count(), 0);
    await a.page.goto(url('directmessage', { peer: ids.b }));
    assert.ok(await a.page.locator('article.sluyu-message').count(), 'Recipient conversation deletion preserves sender history.');
    await b.page.goto(url('directmessage'));
    assert.equal(await b.page.locator('.sluyu-message-thread').count(), 1, 'Deleting one conversation leaves unrelated peers intact.');
    assert.match(await b.page.locator('.sluyu-message-thread').innerText(), /Message User C/);

    const guest = await browser.newContext({ reducedMotion: 'reduce' }); contexts.push(guest);
    const guestPage = await guest.newPage();
    await guestPage.goto(url('directmessage', { peer: ids.a }));
    assert.equal(await guestPage.locator('.sluyu-login-gate').count(), 1);
    assert.equal((await guestPage.locator('body').innerText()).includes(marker), false);
    assert.deepEqual(errors, []);
    console.log('PASS: messaging browser send/aggregation, preferences/blocks, peer pagination, unread state, confirmed single/conversation deletion, cross-account isolation and 320/390 responsive layouts.');
  } finally {
    for (const context of contexts) await context.close();
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
