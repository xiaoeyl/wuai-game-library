'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'downloads-browser-fixture.json'), 'utf8'));
const base = 'http://127.0.0.1:8771';
assert.equal(fixture.base, base, 'Only test the dedicated localhost fixture.');
assert.equal(fixture.target, 'https://example.test/download-secret.zip');
const keys = Object.keys(fixture.posts);
const portal = key => base + '/?sluyu_page=' + key;
const postUrl = key => base + '/?' + (key === 'circle' ? 'post_type=sluyu_topic&' : '') + 'p=' + fixture.posts[key];
const handoffSelector = '.sluyu-download-actions a.sluyu-action-primary';

function assertNoSecrets(text, label, allowedCodes = []) {
  assert.equal(text.includes(fixture.target), false, label + ': no external destination in the body');
  assert.equal(text.includes('download-secret.zip'), false, label + ': no encoded or abbreviated destination');
  assert.equal(text.includes('_wuai_download_settings'), false, label + ': no private configuration bundle');
  for (const key of keys) {
    if (fixture.codes[key] && !allowedCodes.includes(key)) assert.equal(text.includes(fixture.codes[key]), false, label + ': no unauthorized extraction code for ' + key);
    assert.equal(text.includes(fixture.versions[key]), false, label + ': no entitlement version for ' + key);
  }
  assert.doesNotMatch(text, /Fatal error|Uncaught (?:Error|Exception)/, label + ': no PHP fatal');
}
function localHandoff(value, key, checkTime = true) {
  const url = new URL(value);
  assert.equal(url.origin, base, 'Never request an external download destination.');
  assert.equal(url.pathname, '/wp-admin/admin-post.php');
  assert.equal(url.searchParams.get('action'), 'wuai_sluyu_download');
  assert.equal(url.searchParams.get('resource'), String(fixture.posts[key]));
  assert.match(url.searchParams.get('signature') || '', /^[a-f0-9]{64}$/);
  assert.match(url.searchParams.get('expires') || '', /^[0-9]+$/);
  if (checkTime) {
    const remaining = Number(url.searchParams.get('expires')) - Math.floor(Date.now() / 1000);
    assert.ok(remaining > 0 && remaining <= 305, 'The signed handoff lasts no more than five minutes.');
  }
  assertNoSecrets(value, 'Signed handoff');
  return url;
}

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const errors = []; const externalRequests = [];
  async function session(name) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce', permissions: ['clipboard-read','clipboard-write'] });
    contexts.push(context);
    await context.route('https://example.test/**', route => { externalRequests.push(route.request().url()); return route.abort(); });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(name + ': ' + error.message));
    if (name !== 'guest') {
      await page.goto(base + '/wp-login.php');
      await page.locator('#user_login').fill('qa_downloads_' + name);
      await page.locator('#user_pass').fill('qa-local-only');
      const loggedIn = page.waitForURL(url => !url.pathname.includes('wp-login.php'));
      loggedIn.catch(() => {});
      await Promise.all([loggedIn, page.locator('#wp-submit').click()]);
    }
    await page.goto(portal('account'));
    const nonce = await page.evaluate(() => window.wuaiSluyu.nonce);
    return {
      name, context, page, nonce,
      action: async (values = {}, method = 'post') => {
        const form = { action: 'wuai_sluyu_community', operation: 'download_unlock', nonce, id: String(fixture.posts.points), ...values };
        const response = method === 'get'
          ? await context.request.get(base + '/wp-admin/admin-ajax.php', { params: form })
          : await context.request.post(base + '/wp-admin/admin-ajax.php', { form });
        const text = await response.text();
        assertNoSecrets(text, name + ' unlock API');
        return { status: response.status(), ...JSON.parse(text) };
      },
    };
  }
  async function handoff(actor, value, expectedStatus = 302) {
    const url = new URL(value);
    assert.equal(url.origin, base, 'API requests may only target localhost.');
    assert.equal(url.pathname, '/wp-admin/admin-post.php');
    // APIRequestContext does not use page routing; maxRedirects is mandatory here.
    const response = await actor.context.request.get(url.href, { maxRedirects: 0 });
    assert.equal(response.status(), expectedStatus, actor.name + ': signed handoff status');
    assert.equal(new URL(response.url()).origin, base, 'The external Location was not followed.');
    assert.match(response.headers()['cache-control'] || '', /no-cache|no-store|private/i);
    assert.equal(response.headers()['referrer-policy'], 'no-referrer');
    assertNoSecrets(await response.text(), actor.name + ' handoff response body');
    if (expectedStatus === 302) assert.equal(response.headers().location, fixture.target, 'Only the 302 Location discloses the saved destination.');
    else assert.equal(response.headers().location, undefined, 'Invalid handoff cannot redirect.');
    return response;
  }
  async function visit(actor, key, allowed) {
    const response = await actor.page.goto(postUrl(key));
    assert.equal(response.status(), 200, actor.name + ': ' + key);
    const html = await response.text();
    assertNoSecrets(html, actor.name + ' article ' + key, allowed ? [key] : []);
    assert.match(response.headers()['cache-control'] || '', /no-cache|no-store|private/i, 'Account-specific resource cards are not publicly cached.');
    const page = actor.page;
    assert.equal(await page.locator('#download-box').count(), 1, 'Exactly one body download card.');
    if (allowed) {
      assert.equal(await page.locator('.sluyu-resource-card').count(), 2, 'Body and default sidebar share the resource-card renderer for posts and topics.');
      assert.equal(await page.locator('.sluyu-resource-card.is-compact').count(), 1);
      assert.equal(await page.locator('.sluyu-resource-locked').count(), 0);
      if (fixture.codes[key]) assert.ok(html.includes(fixture.codes[key]), 'Authorized account can read the extraction code.');
      const links = await page.locator(handoffSelector).evaluateAll(items => items.map(item => item.href));
      assert.equal(links.length, 2);
      for (const value of links) localHandoff(value, key);
      assert.equal(await page.locator('form[data-operation=download_unlock]').count(), 0);
      return links[0];
    }
    assert.equal(await page.locator(handoffSelector).count(), 0, 'Ineligible accounts never receive a handoff.');
    assert.equal(await page.locator('.sluyu-resource-card').count(), 0);
    assert.equal(await page.locator('.sluyu-resource-locked').count(), 2);
    assert.equal(await page.locator('#download-box [data-sluyu-copy],#download-sidebar [data-sluyu-copy]').count(), 0, 'No code to copy while locked.');
    const confirms = page.locator('form[data-operation=download_unlock]');
    const canConfirm = key === 'points' && actor.name !== 'guest';
    assert.equal(await confirms.count(), canConfirm ? 1 : 0, 'Only one body confirmation, never a duplicate sidebar form.');
    if (canConfirm) {
      assert.equal(await page.locator('#download-box form[data-operation=download_unlock]').count(), 1);
      assert.equal(await page.locator('#download-sidebar form').count(), 0);
      assert.equal(await page.locator('#download-sidebar a[href="#download-box"]').count(), 1);
    }
    return '';
  }
  async function balance(actor) {
    const response = await actor.context.request.get(portal('gold'));
    assert.equal(response.status(), 200);
    const html = await response.text(); assertNoSecrets(html, actor.name + ' ledger');
    const match = html.match(/class="sluyu-big-number"\s*>\s*(-?\d+)\s*</);
    assert.ok(match, 'The authenticated ledger balance is rendered.');
    return Number(match[1]);
  }
  async function denied(actor, values, expectedStatus = 400) {
    const before = await balance(actor);
    const result = await actor.action(values);
    assert.equal(result.status, expectedStatus);
    assert.equal(result.success, false);
    assert.equal(await balance(actor), before, 'Rejected confirmation never charges.');
  }
  async function copyFromBody(actor, expected) {
    await actor.page.bringToFront();
    const button = actor.page.locator('#download-box [data-sluyu-copy]');
    assert.equal(await button.getAttribute('data-sluyu-copy'), expected);
    await button.click();
    await actor.page.waitForFunction(() => document.querySelector('#download-box [data-sluyu-copy]').textContent === '\u5df2\u590d\u5236');
    assert.equal(await actor.page.evaluate(() => navigator.clipboard.readText()), expected, 'The existing copy extraction-code action remains functional.');
  }
  try {
    const reader = await session('reader'); const other = await session('other');
    assert.ok(fixture.revokedExpires > Math.floor(Date.now() / 1000), 'Rerun downloads-browser-fixture.php immediately before this test; the revoked token must still be unexpired.');
    localHandoff(fixture.revokedHandoff, 'member');
    await handoff(other, fixture.revokedHandoff, 403);
    const guest = await session('guest');

    const guestPublic = await visit(guest, 'public', true);
    await copyFromBody(guest, fixture.codes.public);
    await handoff(guest, guestPublic);
    await handoff(reader, guestPublic, 403);
    const noCode = await visit(guest, 'public_no_code', true);
    await copyFromBody(guest, noCode);
    await handoff(guest, noCode);
    for (const key of ['login','member','comment','circle','points']) await visit(guest, key, false);
    for (const key of ['public','public_no_code','login','member','comment','circle']) {
      const link = await visit(reader, key, true);
      await handoff(reader, link);
      if (['public','login'].includes(key)) await handoff(other, link, 403);
    }
    for (const key of ['public','login']) {
      const link = await visit(other, key, true);
      await handoff(other, link);
    }
    for (const key of ['member','comment','circle','points']) await visit(other, key, false);

    const valid = localHandoff(await visit(reader, 'public', true), 'public');
    for (const change of [
      url => url.searchParams.set('expires', String(Number(url.searchParams.get('expires')) - 1)),
      url => url.searchParams.set('expires', '1'),
      url => url.searchParams.set('expires', String(Math.floor(Date.now() / 1000) + 3600)),
      url => url.searchParams.set('signature', (url.searchParams.get('signature')[0] === 'a' ? 'b' : 'a') + url.searchParams.get('signature').slice(1)),
      url => url.searchParams.set('resource', String(fixture.posts.public_no_code)),
      url => { url.searchParams.delete('expires'); url.searchParams.set('expires[]', '1'); },
    ]) {
      const tampered = new URL(valid); change(tampered); await handoff(reader, tampered.href, 403);
    }
    assert.equal(await balance(reader), fixture.initialPoints, 'Viewing and requesting non-paid downloads does not debit points.');
    await visit(reader, 'points', false);
    await reader.page.screenshot({ path: path.join(output, 'downloads-locked-desktop.png'), fullPage: true, animations: 'disabled' });
    const form = reader.page.locator('form[data-operation=download_unlock]');
    const quote = await form.locator('input[name=quote]').inputValue();
    assert.match(quote, /^[a-f0-9]{64}$/);
    assertNoSecrets(quote, 'Points confirmation quote');
    const consent = form.locator('input[name=confirm_unlock]');
    assert.equal(await consent.isChecked(), false);
    assert.equal(await form.evaluate(element => element.checkValidity()), false);
    let unlockRequests = 0;
    reader.page.on('request', request => {
      if (request.url().includes('/admin-ajax.php') && (request.postData() || '').includes('operation=download_unlock')) unlockRequests++;
    });
    await form.locator('button[type=submit]').click();
    await reader.page.waitForTimeout(150);
    assert.equal(unlockRequests, 0, 'Unchecked required consent does not send a request.');
    assert.equal(await balance(reader), fixture.initialPoints);
    await denied(reader, { quote });
    await denied(reader, { quote, confirm_unlock: '0' });
    await denied(reader, { quote: (quote[0] === 'a' ? 'b' : 'a') + quote.slice(1), confirm_unlock: '1' });
    await denied(reader, { quote, confirm_unlock: '1', nonce: 'bad-nonce' }, 403);
    await denied(other, { quote, confirm_unlock: '1' });
    assert.equal((await reader.action({ quote, confirm_unlock: '1' }, 'get')).status, 405);
    assert.equal((await guest.action({ quote, confirm_unlock: '1' })).status, 401);
    assert.equal(await balance(reader), fixture.initialPoints);

    await reader.page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await reader.page.locator('form[data-operation=download_unlock]').count(), 1, 'Mobile has no duplicate hidden-sidebar confirmation.');
    assert.equal(await reader.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Locked mobile resource fits.');
    await reader.page.screenshot({ path: path.join(output, 'downloads-locked-mobile.png'), fullPage: true, animations: 'disabled' });
    await consent.check();
    const responseReady = reader.page.waitForResponse(response => response.url().includes('/admin-ajax.php') && (response.request().postData() || '').includes('operation=download_unlock'));
    const reloadReady = reader.page.waitForEvent('load');
    responseReady.catch(() => {}); reloadReady.catch(() => {});
    const [confirmed] = await Promise.all([responseReady, reloadReady, form.locator('button[type=submit]').click()]);
    // Do not read an auto-reloaded Chromium response body; verify durable state instead.
    assert.equal(confirmed.status(), 200);
    assert.equal(unlockRequests, 1);
    assert.equal(await reader.page.locator('form[data-operation=download_unlock]').count(), 0);
    assert.equal(await reader.page.locator('.sluyu-resource-card').count(), 2);
    assert.equal(await balance(reader), fixture.initialPoints - fixture.cost, 'Confirmed mobile form debits exactly 25 points.');
    let paid = await visit(reader, 'points', true);
    await handoff(reader, paid);
    await handoff(other, paid, 403);
    await handoff(guest, paid, 403);
    await copyFromBody(reader, fixture.codes.points);
    await reader.page.reload();
    assert.equal(await reader.page.locator('form[data-operation=download_unlock]').count(), 0, 'Purchase survives refresh.');
    await reader.page.screenshot({ path: path.join(output, 'downloads-unlocked-mobile.png'), fullPage: true, animations: 'disabled' });
    for (let i = 0; i < 3; i++) {
      const repeated = await reader.action({ quote, confirm_unlock: '1' });
      assert.equal(repeated.status, 200); assert.equal(repeated.success, true); assert.equal(repeated.data.charged, 0);
    }
    assert.equal(await balance(reader), fixture.initialPoints - fixture.cost, 'Repeated unlocks do not double charge.');
    assert.equal(await balance(other), fixture.initialPoints);
    await reader.page.goto(portal('gold'));
    const debit = reader.page.locator('tbody tr').filter({ hasText: fixture.publicPrefix + ' Points' });
    assert.equal(await debit.count(), 1, 'Exactly one download debit appears in the ledger.');
    assert.equal((await debit.locator('td').last().innerText()).trim(), '-' + fixture.cost);
    await reader.page.setViewportSize({ width: 1440, height: 1000 });
    paid = await visit(reader, 'points', true);
    await reader.page.screenshot({ path: path.join(output, 'downloads-unlocked-desktop.png'), fullPage: true, animations: 'disabled' });
    await visit(other, 'points', false); await visit(guest, 'points', false);

    for (const actor of [guest, reader, other]) {
      let headers = {};
      if (actor.name !== 'guest') {
        const token = (await (await actor.context.request.get(base + '/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
        assert.match(token, /^[a-z0-9]{10}$/i); headers = { 'X-WP-Nonce': token };
        const me = await actor.context.request.get(base + '/?rest_route=' + encodeURIComponent('/wp/v2/users/me'), { headers });
        assert.equal(me.status(), 200); assert.equal((await me.json()).id, fixture.users[actor.name]);
      }
      for (const key of keys) {
        const endpoint = '/wp/v2/' + (key === 'circle' ? 'sluyu_topic' : 'posts') + '/' + fixture.posts[key];
        const response = await actor.context.request.get(base + '/?rest_route=' + encodeURIComponent(endpoint), { headers });
        assert.equal(response.status(), 200);
        assertNoSecrets(await response.text(), actor.name + ' REST ' + key);
      }
      const feed = await actor.context.request.get(base + '/?feed=rss2&author=' + fixture.users.owner);
      assert.equal(feed.status(), 200); const xml = await feed.text();
      assert.match(xml, /<rss[\s>]/i); assert.ok(xml.includes(fixture.publicPrefix)); assertNoSecrets(xml, actor.name + ' feed');
      const search = await actor.page.goto(base + '/?s=' + encodeURIComponent(fixture.publicPrefix));
      assert.equal(search.status(), 200); assertNoSecrets(await search.text(), actor.name + ' public search');
      assert.ok(await actor.page.locator('.sluyu-grid .sluyu-card').count());
    }
    for (const width of [390, 320]) {
      for (const actor of [reader, other]) {
        await actor.page.setViewportSize({ width, height: 844 });
        for (const key of ['public','member','circle','points']) {
          await visit(actor, key, actor.name === 'reader' || key === 'public');
          assert.equal(await actor.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, actor.name + ': ' + key + ' fits ' + width);
        }
      }
    }
    assert.deepEqual(externalRequests, [], 'The external destination was never requested by the browser.');
    assert.deepEqual(errors, [], 'No JavaScript exceptions.');
    console.log('PASS: six download gates; raw URL/code isolation; same-account five-minute no-follow handoff, tampering and live membership revocation; real mobile confirmation, exact debit and idempotency; default sidebar parity and one confirmation form; extraction-code/temporary-entry clipboard; authenticated REST/feed/search privacy and responsive screenshots. No external download was requested.');
  } finally {
    for (const context of contexts) await context.close();
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
