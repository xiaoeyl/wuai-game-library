'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'protected-browser-fixture.json'), 'utf8'));
const base = 'http://127.0.0.1:8771';
assert.equal(fixture.base, base, 'Use only the dedicated local fixture.');
const published = ['login', 'member', 'comment', 'circle', 'points'];
const allKeys = [...published, 'draft', 'password'];
const portal = key => base + '/?sluyu_page=' + encodeURIComponent(key);
const postUrl = key => key === 'circle'
  ? base + '/?post_type=sluyu_topic&p=' + fixture.posts[key]
  : base + '/?p=' + fixture.posts[key];
const restUrl = key => base + '/?rest_route=' + encodeURIComponent('/wp/v2/' + (key === 'circle' ? 'sluyu_topic' : 'posts') + '/' + fixture.posts[key]);
const privateMetaKey = '_wuai_protected_content';

function assertPrivateAbsent(text, label, except = []) {
  for (const key of allKeys) {
    if (!except.includes(key)) assert.equal(text.includes(fixture.secrets[key]), false, label + ': no private fragment for ' + key);
    assert.equal(text.includes(fixture.versions[key]), false, label + ': no internal entitlement version for ' + key);
  }
  assert.equal(text.includes(privateMetaKey), false, label + ': no private metadata bundle');
  assert.doesNotMatch(text, /Fatal error|Uncaught (?:Error|Exception)/, label + ': no PHP fatal');
}

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const errors = [];
  async function session(name) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    contexts.push(context);
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(name + ': ' + error.message));
    if (name !== 'guest') {
      await page.goto(base + '/wp-login.php');
      await page.locator('#user_login').fill('qa_protected_' + name);
      await page.locator('#user_pass').fill('qa-local-only');
      const loggedIn = page.waitForURL(url => !url.pathname.includes('wp-login.php'));
      loggedIn.catch(() => {});
      await Promise.all([loggedIn, page.locator('#wp-submit').click()]);
    }
    await page.goto(portal('account'));
    const nonce = await page.evaluate(() => window.wuaiSluyu.nonce);
    assert.equal(typeof nonce, 'string', name + ': frontend nonce loaded');
    return {
      name, context, page, nonce,
      action: async (values = {}, method = 'post') => {
        const form = { action: 'wuai_sluyu_community', operation: 'protected_unlock', nonce, id: String(fixture.posts.points), ...values };
        const response = method === 'get'
          ? await context.request.get(base + '/wp-admin/admin-ajax.php', { params: form })
          : await context.request.post(base + '/wp-admin/admin-ajax.php', { form });
        const text = await response.text();
        assertPrivateAbsent(text, name + ' unlock API');
        return { status: response.status(), ...JSON.parse(text) };
      },
    };
  }
  async function visit(actor, key, allowed) {
    const response = await actor.page.goto(postUrl(key));
    assert.ok(response, actor.name + ': article response exists');
    assert.equal(response.status(), key === 'draft' ? 404 : 200, actor.name + ': ' + key + ' status');
    const html = await response.text();
    assertPrivateAbsent(html, actor.name + ' raw ' + key, allowed ? [key] : []);
    if (allowed) {
      assert.ok(html.includes(fixture.secrets[key]), actor.name + ': allowed body is server-rendered');
      assert.ok((await actor.page.locator('.sluyu-protected-body').innerText()).includes(fixture.secrets[key]), actor.name + ': allowed fragment displayed');
    } else {
      assert.equal(await actor.page.locator('.sluyu-protected-body').count(), 0, actor.name + ': no hidden body element');
    }
    if (published.includes(key)) {
      assert.match(response.headers()['cache-control'] || '', /no-cache|no-store|private/i, actor.name + ': protected article cannot use a public cache');
      assert.equal(await actor.page.locator('.sluyu-protected-content').count(), 1, actor.name + ': one protected section');
    }
    return response;
  }
  async function balance(actor) {
    const page = await actor.context.newPage();
    try {
      const response = await page.goto(portal('gold'));
      assert.equal(response.status(), 200);
      const html = await response.text();
      assertPrivateAbsent(html, actor.name + ' points ledger');
      return Number((await page.locator('.sluyu-big-number').innerText()).trim());
    } finally { await page.close(); }
  }
  async function denied(actor, values, status = 400) {
    const before = await balance(actor);
    const result = await actor.action(values);
    assert.equal(result.status, status, actor.name + ': invalid unlock status');
    assert.equal(result.success, false, actor.name + ': invalid unlock rejected');
    assert.equal(await balance(actor), before, actor.name + ': rejection does not debit points');
  }
  try {
    const guest = await session('guest');
    for (const key of allKeys) await visit(guest, key, false);
    const reader = await session('reader');
    const other = await session('other');
    const owner = await session('owner');
    for (const key of ['login', 'member', 'comment', 'circle']) {
      await visit(reader, key, true);
      await visit(other, key, key === 'login');
    }
    for (const key of published) {
      await visit(owner, key, true);
      assert.match(await owner.page.locator('.sluyu-protected-content').innerText(), /\u4f5c\u8005 \/ \u7f16\u8f91\u9884\u89c8/);
    }
    assert.equal(await balance(owner), fixture.initialPoints, 'Owner preview never charges.');
    for (const actor of [reader, other]) {
      await visit(actor, 'draft', false);
      await visit(actor, 'password', false);
    }

    await visit(reader, 'points', false);
    const form = reader.page.locator('form[data-operation=protected_unlock]');
    const quote = await form.locator('input[name=quote]').inputValue();
    assert.match(quote, /^[a-f0-9]{64}$/, 'Quote is an opaque HMAC, not the private version.');
    assert.equal(quote.includes(fixture.versions.points), false);
    assert.match(await reader.page.locator('.sluyu-protected-content').innerText(), new RegExp(String(fixture.cost) + '\\s*\\u79ef\\u5206'));
    assert.equal(await balance(reader), fixture.initialPoints, 'Merely opening the confirmation does not charge.');
    let unlockRequests = 0;
    reader.page.on('request', request => {
      if (request.url().includes('/admin-ajax.php') && (request.postData() || '').includes('operation=protected_unlock')) unlockRequests++;
    });
    const consent = form.locator('input[name=confirm_unlock]');
    assert.equal(await consent.isChecked(), false, 'Consent starts unchecked.');
    assert.equal(await form.evaluate(element => element.checkValidity()), false);
    await form.locator('button[type=submit]').click();
    await reader.page.waitForTimeout(150);
    assert.equal(unlockRequests, 0, 'Native required checkbox prevents a request without consent.');
    assert.equal(await reader.page.locator('.sluyu-protected-body').count(), 0);
    assert.equal(await balance(reader), fixture.initialPoints, 'Unconfirmed click does not debit points.');
    await denied(reader, { quote });
    await denied(reader, { quote, confirm_unlock: '0' });
    const tampered = (quote[0] === 'a' ? 'b' : 'a') + quote.slice(1);
    await denied(reader, { quote: tampered, confirm_unlock: '1' });
    await denied(reader, { quote, confirm_unlock: '1', nonce: 'wrong-nonce' }, 403);
    await denied(other, { quote, confirm_unlock: '1' });
    const getAttempt = await reader.action({ quote, confirm_unlock: '1' }, 'get');
    assert.equal(getAttempt.status, 405, 'GET never charges or unlocks.');
    const anonymousAttempt = await guest.action({ quote, confirm_unlock: '1' });
    assert.equal(anonymousAttempt.status, 401, 'Anonymous callers cannot unlock.');
    assert.equal(await balance(reader), fixture.initialPoints);

    await reader.page.screenshot({ path: path.join(output, 'protected-locked-desktop.png'), fullPage: true, animations: 'disabled' });
    await consent.check();
    assert.equal(await form.evaluate(element => element.checkValidity()), true);
    const unlockResponseReady = reader.page.waitForResponse(response => response.url().includes('/admin-ajax.php') && (response.request().postData() || '').includes('operation=protected_unlock'));
    const unlockedPageReady = reader.page.waitForEvent('load');
    unlockResponseReady.catch(() => {}); unlockedPageReady.catch(() => {});
    const [unlockResponse] = await Promise.all([unlockResponseReady, unlockedPageReady, form.locator('button[type=submit]').click()]);
    // Reload can discard Chromium's response body; verify the durable UI and ledger instead.
    assert.equal(unlockResponse.status(), 200, 'The real confirmation form succeeds.');
    assert.equal(unlockRequests, 1, 'Exactly one browser confirmation request is sent.');
    assert.ok((await reader.page.locator('.sluyu-protected-body').innerText()).includes(fixture.secrets.points));
    assert.match(await reader.page.locator('.sluyu-protected-content').innerText(), /\u4e0d\u4f1a\u91cd\u590d\u6263\u5206/);
    assert.equal(await balance(reader), fixture.initialPoints - fixture.cost);
    await visit(reader, 'points', true);
    await reader.page.reload();
    assert.ok((await reader.page.locator('.sluyu-protected-body').innerText()).includes(fixture.secrets.points), 'Refresh retains entitlement.');
    await reader.page.screenshot({ path: path.join(output, 'protected-unlocked-desktop.png'), fullPage: true, animations: 'disabled' });
    for (let i = 0; i < 3; i++) {
      const repeated = await reader.action({ quote, confirm_unlock: '1' });
      assert.equal(repeated.status, 200);
      assert.equal(repeated.success, true);
      assert.equal(repeated.data.charged, 0, 'Repeated API calls are idempotent.');
    }
    assert.equal(await balance(reader), fixture.initialPoints - fixture.cost);
    await reader.page.goto(portal('gold'));
    const debit = reader.page.locator('tbody tr').filter({ hasText: fixture.publicPrefix + ' Points' });
    assert.equal(await debit.count(), 1, 'The purchase has exactly one ledger row.');
    assert.equal((await debit.locator('td').last().innerText()).trim(), '-' + fixture.cost);
    await visit(other, 'points', false);
    await visit(guest, 'points', false);
    assert.equal(await balance(other), fixture.initialPoints, 'Another account never inherits the purchase or charge.');
    assert.equal(await balance(owner), fixture.initialPoints);

    // REST must really authenticate the owner/reader; plain cookie requests become guests without X-WP-Nonce.
    for (const actor of [guest, reader, owner]) {
      let headers = {};
      if (actor.name !== 'guest') {
        const nonceResponse = await actor.context.request.get(base + '/wp-admin/admin-ajax.php?action=rest-nonce');
        assert.equal(nonceResponse.status(), 200);
        const restNonce = (await nonceResponse.text()).trim();
        assert.match(restNonce, /^[a-z0-9]{10}$/i);
        headers = { 'X-WP-Nonce': restNonce };
        const identity = await actor.context.request.get(base + '/?rest_route=' + encodeURIComponent('/wp/v2/users/me'), { headers });
        assert.equal(identity.status(), 200, actor.name + ': REST authentication is real');
        assert.equal((await identity.json()).id, fixture.users[actor.name]);
      }
      for (const key of published) {
        const response = await actor.context.request.get(restUrl(key), { headers });
        assert.equal(response.status(), 200, actor.name + ': REST ' + key);
        const text = await response.text();
        assertPrivateAbsent(text, actor.name + ' authenticated REST ' + key);
        const record = JSON.parse(text);
        assert.equal(record.id, fixture.posts[key]);
        assert.ok(record.content && typeof record.content.rendered === 'string');
        assert.equal(record.content.rendered.includes('sluyu-protected-body'), false, 'REST returns only the public content.');
        assert.equal(record.content.rendered.includes('sluyu-protected-content'), false, 'REST does not append a gate or quote.');
      }
      for (const suffix of ['', '&post_type=sluyu_topic']) {
        const feed = await actor.context.request.get(base + '/?feed=rss2&author=' + fixture.users.owner + suffix);
        assert.equal(feed.status(), 200, actor.name + ': author feed exists');
        const xml = await feed.text();
        assert.match(xml, /<rss[\s>]/i);
        assert.ok(xml.includes(fixture.publicPrefix), 'Feed contains the fixture public summaries.');
        assertPrivateAbsent(xml, actor.name + ' feed');
      }
      const archive = await actor.page.goto(base + '/?author=' + fixture.users.owner);
      assert.equal(archive.status(), 200);
      assertPrivateAbsent(await archive.text(), actor.name + ' author excerpts');
      assert.ok(await actor.page.locator('.sluyu-grid .sluyu-card').count(), 'Public author cards exist.');
      const search = await actor.page.goto(base + '/?s=' + encodeURIComponent(fixture.publicPrefix));
      assert.equal(search.status(), 200);
      assertPrivateAbsent(await search.text(), actor.name + ' public search excerpts');
      assert.ok(await actor.page.locator('.sluyu-grid .sluyu-card').count(), 'Public title search has results.');
      for (const key of ['login', 'points']) {
        await actor.page.goto(base + '/?s=' + encodeURIComponent(fixture.secrets[key]));
        // The query is deliberately echoed in the search form/title, so inspect results, not all HTML.
        assert.equal(await actor.page.locator('.sluyu-grid .sluyu-card').count(), 0, actor.name + ': hidden body is not indexed by search');
      }
      const embed = await actor.context.request.get(postUrl('points') + '&embed=true');
      assert.equal(embed.status(), 200);
      assertPrivateAbsent(await embed.text(), actor.name + ' embedded excerpt');
    }

    for (const width of [390, 320]) {
      for (const actor of [reader, other]) {
        await actor.page.setViewportSize({ width, height: 844 });
        for (const key of ['member', 'comment', 'circle', 'points']) {
          await visit(actor, key, actor.name === 'reader');
          assert.equal(await actor.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, actor.name + ': ' + key + ' fits width ' + width);
        }
        if (width === 390) {
          await actor.page.screenshot({ path: path.join(output, 'protected-' + (actor.name === 'reader' ? 'unlocked' : 'locked') + '-mobile.png'), fullPage: true, animations: 'disabled' });
        }
      }
    }
    assert.deepEqual(errors, [], 'No page JavaScript exceptions.');
    console.log('PASS: five protected-body gates; real unchecked/confirmed purchase UI; exact single debit, refresh and repeated API idempotency; cross-account isolation; genuinely authenticated REST, feed, search, author/embed excerpt isolation; no-cache and responsive 320/390/1440 screenshots.');
  } finally {
    for (const context of contexts) await context.close();
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
