'use strict';
// Serial browser regression after bounties-browser-fixture.php.
const assert = require('node:assert/strict');
const fs = require('node:fs'); const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = 'http://127.0.0.1:8771';
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'bounties-fixture.json'), 'utf8'));
assert.equal(fixture.base, base);
const portal = (key, args = {}) => base + '/?' + new URLSearchParams({ sluyu_page: key, ...args });
const postUrl = id => base + '/?post_type=sluyu_topic&p=' + id;
// WordPress registers the review screen as an admin.php?page=... submenu;
// follow that canonical URL rather than treating the menu slug as a path.
const reviewUrl = id => base + '/wp-admin/admin.php?' + new URLSearchParams({ page: 'wuai-sluyu-bounty-review', question: String(id) });
function clean(text) { assert.doesNotMatch(text, /Fatal error|Uncaught (?:Error|Exception)/); }
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const errors = [];
  try {
    async function session(name) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' }); contexts.push(context);
      const page = await context.newPage(); page.on('pageerror', error => errors.push(name + ': ' + error.message));
      const login = await page.goto(base + '/wp-login.php'); assert.equal(login.headers()['x-wuai-bounty-qa-mail-blocked'], '1', 'Never mutate without the local mail blocker.');
      if (name !== 'guest') {
        await page.locator('#user_login').fill(fixture.loginPrefix + name); await page.locator('#user_pass').fill('qa-local-only');
        const ready = page.waitForURL(url => !url.pathname.includes('wp-login.php')); ready.catch(() => {});
        await Promise.all([ready, page.locator('#wp-submit').click()]);
      }
      await page.goto(portal('account')); const nonce = await page.evaluate(() => window.wuaiSluyu.nonce);
      return { name, context, page, nonce, action: async (operation, data = {}) => {
        const response = await context.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'wuai_sluyu_community', nonce, operation, ...data } });
        const text = await response.text(); clean(text); return { status: response.status(), ...JSON.parse(text) };
      } };
    }
    async function clickForm(actor, form, operation, reload = true) {
      const pending = actor.page.waitForResponse(response => response.url().includes('/admin-ajax.php') && new URLSearchParams(response.request().postData() || '').get('operation') === operation).then(value => ({ value }), error => ({ error }));
      const ready = reload ? actor.page.waitForEvent('load').then(value => ({ value }), error => ({ error })) : null;
      await form.locator('button[type=submit]').click(); const response = await pending; if (response.error) throw response.error;
      assert.equal(response.value.status(), 200);
      if (ready) { const loaded = await ready; if (loaded.error) throw loaded.error; }
      else clean(await response.value.text());
    }
    async function balance(actor) {
      const page = await actor.context.newPage();
      try { const response = await page.goto(portal('gold')); assert.equal(response.status(), 200); clean(await response.text()); return Number((await page.locator('.sluyu-big-number').innerText()).trim()); }
      finally { await page.close(); }
    }
    const guest = await session('guest'); const owner = await session('owner'); const writer = await session('writer');
    const winner = await session('winner'); const other = await session('other'); const admin = await session('admin');
    await admin.page.goto(base + '/wp-admin/'); const qaNonce = await admin.page.locator('#wuai-bounty-qa-nonce').inputValue();
    async function state() {
      const response = await admin.context.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'wuai_bounty_qa_state', _ajax_nonce: qaNonce } });
      assert.equal(response.status(), 200); const data = await response.json(); assert.equal(data.success, true); return data.data;
    }
    async function nativePublish(id) {
      await admin.page.goto(base + '/wp-admin/post.php?post=' + id + '&action=edit');
      if (!(await admin.page.locator('form#post').count())) {
        const loader = await admin.page.evaluate(() => window._wpMetaBoxUrl); assert.equal(typeof loader, 'string');
        const parsed = new URL(loader); assert.equal(parsed.origin, base); assert.ok(parsed.searchParams.get('meta-box-loader-nonce')); await admin.page.goto(loader);
      }
      assert.equal(await admin.page.locator('select[name=wuai_topic_kind]').inputValue(), 'bounty', 'Shared service editor preserves funded bounty type.');
      assert.equal(await admin.page.locator('#title').inputValue(), fixture.title);
      const published = admin.page.waitForURL(url => url.pathname.endsWith('/post.php') && url.searchParams.has('message')); published.catch(() => {});
      await Promise.all([published, admin.page.locator('#publish').click()]);
    }
    async function approveComment(id) {
      await admin.page.goto(base + '/wp-admin/comment.php?action=editcomment&c=' + id);
      await admin.page.locator('input[name=comment_status][value="1"]').check();
      const saved = admin.page.waitForEvent('load'); saved.catch(() => {});
      await Promise.all([saved, admin.page.locator('#save').click()]);
      assert.equal((await state()).comments.find(comment => Number(comment.comment_ID) === id).comment_approved, '1', 'Real native comment moderation approves the answer.');
    }
    await guest.page.goto(portal('submit', { kind: 'bounty' })); assert.equal(await guest.page.locator('.sluyu-login-gate').count(), 1);
    await writer.page.goto(portal('submit', { kind: 'bounty' }));
    const form = writer.page.locator('form[data-operation=bounty_submit]'); assert.equal(await form.count(), 1);
    await form.locator('[name=title]').fill(fixture.title); await form.locator('[name=body]').fill(fixture.prompt);
    await form.locator('[name=amount]').fill(String(fixture.amount)); await form.locator('[name=days]').fill('2');
    await form.locator('[name=audience]').selectOption('someone'); await form.locator('[name=targets]').fill(String(fixture.users.winner));
    const requestKey = await form.locator('[name=request_key]').inputValue();
    assert.equal(await form.locator('[name=confirm]').isChecked(), false); assert.equal(await form.evaluate(element => element.checkValidity()), false);
    assert.equal(await balance(writer), fixture.initialPoints);
    await form.locator('[name=confirm]').check(); await clickForm(writer, form, 'bounty_submit');
    let snapshot = await state(); const post = snapshot.posts.find(post => post.post_title === fixture.title); assert.ok(post); const id = Number(post.ID);
    let row = snapshot.bounties.find(row => Number(row.question_id) === id); assert.ok(row); assert.equal(row.state, 'held'); assert.equal(post.post_status, 'pending');
    assert.equal(snapshot.users.writer.balance, fixture.initialPoints - fixture.amount); assert.equal(snapshot.users.winner.balance, fixture.initialPoints);
    assert.equal(await balance(writer), fixture.initialPoints - fixture.amount, 'Gold page already subtracts pending reservations.');
    const replay = await writer.action('bounty_submit', { amount: String(fixture.amount), days: '2', audience: 'someone', targets: String(fixture.users.winner), request_key: requestKey, title: fixture.title, body: fixture.prompt, group: '0', confirm: '1' });
    assert.equal(replay.success, true); assert.equal(replay.data.id, id); assert.equal((await state()).bounties.filter(row => Number(row.question_id) === id).length, 1);
    const pending = await guest.page.goto(postUrl(id)); assert.equal(pending.status(), 404);
    const pendingRest = await guest.context.request.get(base + '/?rest_route=' + encodeURIComponent('/wp/v2/sluyu_topic/' + id)); assert.ok([401, 403, 404].includes(pendingRest.status()));
    await nativePublish(id);
    snapshot = await state(); row = snapshot.bounties.find(row => Number(row.question_id) === id); assert.equal(row.state, 'held', 'Native publish is not economic activation.');
    await winner.page.goto(postUrl(id)); assert.equal(await winner.page.locator('.sluyu-bounty').getAttribute('data-bounty-state'), 'held');
    await admin.page.goto(reviewUrl(id));
    const review = admin.page.locator('form').filter({ has: admin.page.locator('input[name=action][value=wuai_bounty_review]') });
    const reviewed = admin.page.waitForEvent('load'); reviewed.catch(() => {}); await Promise.all([reviewed, review.locator('button[value=approve]').click()]);
    snapshot = await state(); row = snapshot.bounties.find(row => Number(row.question_id) === id); assert.equal(row.state, 'open');
    const openVersion = String(row.version);
    await winner.page.goto(postUrl(id)); const answerText = 'QA browser approved answer: the reserved points should move only once.';
    await winner.page.locator('#comment').fill(answerText);
    const answered = winner.page.waitForEvent('load'); answered.catch(() => {}); await Promise.all([answered, winner.page.locator('#submit').click()]);
    snapshot = await state(); const comment = snapshot.comments.find(comment => Number(comment.comment_post_ID) === id && Number(comment.user_id) === fixture.users.winner); assert.ok(comment);
    const commentId = Number(comment.comment_ID); await approveComment(commentId);
    const unknown = await other.action('bounty_accept', { id: String(commentId), version: openVersion, confirm: '1' }); assert.equal(unknown.success, false);
    const adminPick = await admin.action('bounty_accept', { id: String(commentId), version: openVersion, confirm: '1' }); assert.equal(adminPick.success, false, 'Site admin may review but not select requester winner.');
    const unconfirmed = await writer.action('bounty_accept', { id: String(commentId), version: openVersion }); assert.equal(unconfirmed.success, false);
    await writer.page.goto(postUrl(id));
    const accept = writer.page.locator('form[data-operation=bounty_accept]').filter({ has: writer.page.locator('input[name=id][value="' + commentId + '"]') });
    assert.equal(await accept.count(), 1); await accept.locator('[name=confirm]').check();
    await clickForm(writer, accept, 'bounty_accept');
    snapshot = await state(); row = snapshot.bounties.find(row => Number(row.question_id) === id);
    assert.equal(row.state, 'awarded'); assert.equal(Number(row.winner_id), fixture.users.winner); assert.equal(Number(row.accepted_comment), commentId);
    assert.equal(snapshot.users.writer.balance, fixture.initialPoints - fixture.amount); assert.equal(snapshot.users.winner.balance, fixture.initialPoints + fixture.amount);
    assert.equal(snapshot.events.filter(event => Number(event.user_id) === fixture.users.writer && Number(event.points) < 0).length, 0, 'No duplicate event debit for the allocation.');
    assert.equal(snapshot.events.filter(event => Number(event.user_id) === fixture.users.winner).length, 1, 'Only original seed event; credit is derived from the one bounty row.');
    for (let index = 0; index < 3; index++) { const result = await writer.action('bounty_accept', { id: String(commentId), version: openVersion, confirm: '1' }); assert.equal(result.success, true); }
    assert.equal(await balance(writer), fixture.initialPoints - fixture.amount); assert.equal(await balance(winner), fixture.initialPoints + fixture.amount);
    await winner.page.goto(portal('gold')); const awardRows = winner.page.locator('tbody tr').filter({ hasText: '\u91c7\u7eb3\u56de\u7b54\u60ac\u8d4f\u5956\u52b1' }); assert.equal(await awardRows.count(), 1, 'Derived award is visible exactly once in merged gold ledger.');
    assert.match(await awardRows.innerText(), new RegExp(String(fixture.amount)));
    await writer.page.reload(); assert.equal(await writer.page.locator('.sluyu-bounty').getAttribute('data-bounty-state'), 'awarded');
    const invalidReturn = await writer.action('bounty_withdraw', { id: String(id), version: String(row.version) }); assert.equal(invalidReturn.success, false);

    // Real native moderation can hide an answer but cannot claw back an award.
    await admin.page.goto(base + '/wp-admin/edit-comments.php?p=' + id);
    const nativeComment = admin.page.locator('#comment-' + commentId); assert.equal(await nativeComment.count(), 1);
    const trashLink = nativeComment.locator('.trash a'); const href = await trashLink.getAttribute('href'); assert.ok(href);
    // Core emits `comment.php` relative to the current wp-admin directory;
    // resolving against the bare site origin incorrectly drops `/wp-admin/`.
    const trashUrl = new URL(href, admin.page.url()); assert.equal(trashUrl.origin, base); assert.ok(trashUrl.pathname.endsWith('/wp-admin/comment.php')); assert.ok(trashUrl.searchParams.get('_wpnonce'));
    await admin.page.goto(trashUrl.href);
    assert.equal(await balance(winner), fixture.initialPoints + fixture.amount, 'Comment removal preserves earned points.');
    await writer.page.goto(postUrl(id)); assert.equal(await writer.page.locator('[data-bounty-answer-available]').getAttribute('data-bounty-answer-available'), '0');
    const ownerStart = (await state()).users.owner.balance;
    await owner.page.goto(postUrl(fixture.posts.withdraw));
    await clickForm(owner, owner.page.locator('form[data-operation=bounty_withdraw]'), 'bounty_withdraw');
    assert.equal((await state()).bounties.find(row => Number(row.question_id) === fixture.posts.withdraw).state, 'refunded');
    assert.equal((await state()).users.owner.balance, ownerStart + 30);
    const beforeExpiry = (await state()).users.owner.balance;
    await guest.page.goto(postUrl(fixture.posts.expired));
    assert.equal((await state()).bounties.find(row => Number(row.question_id) === fixture.posts.expired).state, 'refunded', 'Real page read settles elapsed unawarded reservation without cron.');
    assert.ok([beforeExpiry, beforeExpiry + 30].includes((await state()).users.owner.balance), 'Expiry returns once, whether the preceding owner page or this detail view performs lazy reconciliation.');
    assert.equal((await state()).users.owner.balance, fixture.initialPoints, 'Both fixture reservations return exactly once.');
    await guest.page.screenshot({ path: path.join(output, 'bounty-desktop.png'), fullPage: true, animations: 'disabled' });
    for (const width of [390, 320]) {
      for (const actor of [guest, writer, winner]) {
        await actor.page.setViewportSize({ width, height: 844 }); await actor.page.goto(postUrl(id));
        assert.equal(await actor.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, actor.name + ': bounty fits ' + width);
      }
      await owner.page.setViewportSize({ width, height: 844 }); await owner.page.goto(portal('submit', { kind: 'bounty' }));
      assert.equal(await owner.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Bounty form fits ' + width);
      if (width === 390) await owner.page.screenshot({ path: path.join(output, 'bounty-submit-mobile.png'), fullPage: true, animations: 'disabled' });
    }
    assert.deepEqual(errors, []);
    console.log('PASS: real author pending reservation, replay, native site publication plus explicit bounty review, native answer/moderation, owner-only confirmed single allocation, exact both-party balances/merged ledger, no event double-debit, idempotency, comment deletion, withdrawal, expiry and 320/390 layouts.');
  } finally { for (const context of contexts) await context.close(); await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
