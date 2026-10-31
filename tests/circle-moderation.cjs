'use strict';
// Run serially after circle-moderation-browser-fixture.php on the disposable site.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8771';
assert.equal(base, 'http://127.0.0.1:8771', 'Disposable local server only.');
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'circle-moderation-fixture.json'), 'utf8'));
assert.equal(fixture.base, base);
const portal = (key, args = {}) => base + '/?' + new URLSearchParams({ sluyu_page: key, ...args });
const postUrl = key => base + '/?post_type=sluyu_topic&p=' + fixture.posts[key];
const restUrl = key => base + '/?rest_route=' + encodeURIComponent('/wp/v2/sluyu_topic/' + fixture.posts[key]);
const settingsUrl = base + '/wp-admin/admin.php?page=wuai-circle-review-settings';
const reasons = { recommend: 'The public text is ready for site editorial review.', reject: 'Please add verifiable sources before submitting again.', recover: 'Verify the incomplete isolated browser review before publication.' };
function noPrivate(text, label, except = []) {
  for (const [key, value] of Object.entries(fixture.secrets)) if (!except.includes(key)) assert.equal(text.includes(value), false, label + ': no private ' + key);
  assert.doesNotMatch(text, /Fatal error|Uncaught (?:Error|Exception)|Warning:.*(?:circle-moderation|array)/, label + ': no PHP failure');
}
const last = record => record.audit.at(-1);
const bodyId = response => new URLSearchParams(response.request().postData() || '').get('id');

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const errors = [];
  try {
    async function session(name) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' }); contexts.push(context);
      const page = await context.newPage(); page.on('pageerror', error => errors.push({ account: name, url: page.url(), stack: error.stack || error.message }));
      const response = await page.goto(base + '/wp-login.php?redirect_to=' + encodeURIComponent(portal('account')));
      assert.equal(response.headers()['x-wuai-circle-moderation-qa-mail-blocked'], '1', 'Guarded mail blocker is active before browser mutations.');
      if (name !== 'guest') {
        await page.locator('#user_login').fill(fixture.loginPrefix + name); await page.locator('#user_pass').fill('qa-local-only');
        const loaded = page.waitForURL(url => !url.pathname.includes('wp-login.php')); loaded.catch(() => {});
        await Promise.all([loaded, page.locator('#wp-submit').click()]);
      }
      await page.goto(portal('account')); const nonce = await page.evaluate(() => window.wuaiSluyu.nonce);
      assert.equal(typeof nonce, 'string', name + ': genuine frontend nonce.');
      return { name, context, page, nonce, action: async (operation, values = {}, method = 'post') => {
        const form = { action: 'wuai_sluyu_community', operation, nonce, ...values };
        const result = method === 'get' ? await context.request.get(base + '/wp-admin/admin-ajax.php', { params: form }) : await context.request.post(base + '/wp-admin/admin-ajax.php', { form });
        const text = await result.text(); noPrivate(text, name + ': ' + operation + ' API'); return { status: result.status(), ...JSON.parse(text) };
      } };
    }
    const guest = await session('guest'); const owner = await session('owner'); const writer = await session('writer');
    const viewer = await session('viewer'); const other = await session('other'); const banned = await session('banned'); const admin = await session('admin');
    await admin.page.goto(base + '/wp-admin/'); const qaNonce = await admin.page.locator('#wuai-circle-moderation-qa-nonce').inputValue();
    async function state(key = '', group = '') {
      const response = await admin.context.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'wuai_circle_moderation_qa_state', _ajax_nonce: qaNonce, id: String(key ? fixture.posts[key] : 0), group: String(group ? fixture.circles[group] : 0) } });
      assert.equal(response.status(), 200); const text = await response.text(); noPrivate(text, 'Read-only QA state');
      const data = JSON.parse(text); assert.equal(data.success, true); return data.data;
    }
    function unchangedAccounts(snapshot) {
      assert.equal(snapshot.users.owner.native_publish, false); assert.equal(snapshot.users.owner.native_edit_others, false, 'Owner authority never grants native editing capabilities.');
      for (const user of Object.values(snapshot.users)) assert.equal(user.points, 0, 'Moderation does not charge or reward points.');
    }
    async function visitReview(actor, group = 'main', args = {}) {
      const response = await actor.page.goto(portal('circle-topics', { group: fixture.circles[group], ...args }));
      assert.equal(response.status(), 200); const html = await response.text(); noPrivate(html, actor.name + ': review source');
      assert.match(response.headers()['cache-control'] || '', /no-cache|no-store|private/i, 'Review lists are not publicly cacheable.');
      return html;
    }
    function reviewItem(actor, key) { return actor.page.locator('.sluyu-circle-review-item').filter({ has: actor.page.locator('input[name=id][value="' + fixture.posts[key] + '"]') }); }
    function reviewForm(actor, key) { return reviewItem(actor, key).locator('form[data-operation=circle_review]').filter({ has: actor.page.locator('select[name=decision]') }); }
    async function clickCommunity(actor, form, operation, id, success = true) {
      const responseReady = actor.page.waitForResponse(response => response.url().includes('/admin-ajax.php') && new URLSearchParams(response.request().postData() || '').get('operation') === operation && bodyId(response) === String(id));
      responseReady.catch(() => {});
      const loaded = success ? actor.page.waitForEvent('load') : null; if (loaded) loaded.catch(() => {});
      await form.locator('button[type=submit]').click(); const response = await responseReady;
      assert.equal(response.status(), success ? 200 : 400, operation + ': real form HTTP status');
      // The successful action reloads immediately; Chromium may discard its JSON body.
      if (loaded) await loaded;
      else {
        noPrivate(await response.text(), 'Rejected real form response');
        await form.locator('.sluyu-form-status').filter({ hasText: /\S/ }).waitFor();
        await actor.page.waitForFunction(({ id, operation }) => { const form = [...document.querySelectorAll('form[data-operation="' + operation + '"]')].find(form => form.querySelector('input[name=id]')?.value === String(id)); return form && !form.dataset.busy; }, { id, operation });
      }
    }
    async function decide(actor, key, decision, reason = '', group = 'main') {
      await visitReview(actor, group); const form = reviewForm(actor, key); assert.equal(await form.count(), 1, key + ': one scoped decision form.');
      const version = await form.locator('input[name=version]').inputValue(); assert.match(version, /^[a-f0-9]{64}$/);
      await form.locator('select[name=decision]').selectOption(decision); await form.locator('textarea[name=reason]').fill(reason);
      await clickCommunity(actor, form, 'circle_review', fixture.posts[key]); return version;
    }
    async function nativeEditor(key) {
      await admin.page.goto(base + '/wp-admin/post.php?post=' + fixture.posts[key] + '&action=edit');
      if (!(await admin.page.locator('form#post').count())) {
        const loader = await admin.page.evaluate(() => window._wpMetaBoxUrl); assert.equal(typeof loader, 'string');
        const url = new URL(loader); assert.equal(url.origin, base); assert.equal(url.searchParams.get('post'), String(fixture.posts[key])); assert.ok(url.searchParams.get('meta-box-loader-nonce'));
        await admin.page.goto(url.href);
      }
      assert.equal(await admin.page.locator('#title').inputValue(), fixture.titles[key]);
      assert.equal(await admin.page.locator('input[name=wuai_service_meta_nonce]').count(), 1, 'WordPress editor has the real theme nonce.');
      return admin.page.locator('form#post');
    }
    async function nativePublish(key, inspectPrivate = false) {
      await nativeEditor(key);
      if (inspectPrivate) {
        const html = await admin.page.content(); assert.ok(html.includes(fixture.secrets[key]), 'Trusted native editor can inspect the actual private fragment before final review.');
      }
      const saved = admin.page.waitForURL(url => url.pathname.endsWith('/post.php') && url.searchParams.has('message')); saved.catch(() => {});
      await Promise.all([saved, admin.page.locator('#publish').click()]);
      const snapshot = await state(key); assert.equal(snapshot.post.status, 'publish'); assert.equal(snapshot.post.held, false); assert.equal(snapshot.post.config_valid, true); return snapshot;
    }
    async function changeSetting(enabled) {
      await admin.page.goto(settingsUrl); const form = admin.page.locator('form').filter({ has: admin.page.locator('input[name=action][value=wuai_circle_review_settings]') });
      assert.equal(await form.count(), 1); const checkbox = form.locator('input[type=checkbox][name=owner_publish]');
      await checkbox.setChecked(enabled);
      const saved = admin.page.waitForURL(url => url.pathname.endsWith('/admin.php') && url.searchParams.get('page') === 'wuai-circle-review-settings'); saved.catch(() => {});
      const loaded = admin.page.waitForEvent('load'); loaded.catch(() => {});
      await form.locator('input[type=submit], button[type=submit]').click(); await loaded; await saved;
      assert.equal(await admin.page.locator('input[type=checkbox][name=owner_publish]').isChecked(), enabled);
      const snapshot = await state(); assert.equal(snapshot.settings.owner_publish, enabled ? '1' : '0'); unchangedAccounts(snapshot); return snapshot;
    }
    async function nativeBan(name, enabled) {
      await admin.page.goto(base + '/wp-admin/user-edit.php?user_id=' + fixture.users[name]);
      assert.equal(await admin.page.locator('input[name=wuai_user_moderation_nonce]').count(), 1, 'Real user editor moderation nonce exists.');
      await admin.page.locator('input[name=wuai_ban_forever]').setChecked(enabled);
      await admin.page.locator('input[name=wuai_ban_until]').fill('');
      await admin.page.locator('input[name=wuai_ban_reason]').fill(enabled ? 'Temporary isolated browser moderation test.' : '');
      const loaded = admin.page.waitForURL(url => url.pathname.endsWith('/user-edit.php') && url.searchParams.has('updated')); loaded.catch(() => {});
      await Promise.all([loaded, admin.page.locator('#submit').click()]); assert.equal((await state()).users[name].banned, enabled);
    }

    const initial = await state('', 'queue'); unchangedAccounts(initial); assert.equal(initial.settings.owner_publish, '0');
    assert.equal(initial.queues.owner.total, 13); assert.equal(initial.queues.owner.pages, 2); assert.equal(initial.queues.admin.total, 14); assert.equal(initial.queues.viewer.total, 0);
    const directSettingDenied = await owner.page.goto(settingsUrl); assert.equal(directSettingDenied.status(), 403, 'Circle ownership grants no native settings page access.');
    const editorUrl = base + '/wp-admin/post.php?post=' + fixture.posts.security + '&action=edit';
    const directEditorDenied = await owner.context.request.get(editorUrl, { maxRedirects: 0 });
    assert.ok([302, 403].includes(directEditorDenied.status()), 'Core denies editing; WooCommerce may redirect subscribers to their account first.');
    if (directEditorDenied.status() === 302) { const redirect = new URL(directEditorDenied.headers().location, base); assert.equal(redirect.origin, base); assert.equal(redirect.pathname.startsWith('/wp-admin/'), false); }
    await owner.page.goto(editorUrl); assert.equal(await owner.page.locator('form#post').count(), 0, 'Circle ownership never renders the native post editor.');
    assert.equal((await owner.page.content()).includes(fixture.titles.security), false, 'Denied editor does not reveal the pending title.');
    await visitReview(owner, 'queue'); assert.equal(await owner.page.locator('.sluyu-circle-review-item').count(), 12);
    const firstPage = await owner.page.locator('.sluyu-circle-review-item h3').allTextContents(); assert.equal(firstPage.includes(fixture.titles.queue_cross), false);
    const next = owner.page.locator('.sluyu-portal-pagination a.next'); assert.equal(await next.count(), 1, 'Owner has exactly a second page for 13 permitted items.');
    await next.click(); assert.equal(await owner.page.locator('.sluyu-circle-review-item').count(), 1); assert.equal(await owner.page.locator('.sluyu-portal-pagination a.next').count(), 0);
    const secondPage = await owner.page.locator('.sluyu-circle-review-item h3').allTextContents(); assert.equal(firstPage.some(title => secondPage.includes(title)), false); assert.equal(secondPage.includes(fixture.titles.queue_cross), false);
    const alias = await owner.page.goto(portal('circle-topics', { circle_id: fixture.circles.queue })); assert.equal(alias.status(), 200); assert.equal(await owner.page.locator('.sluyu-circle-review-item').count(), 12, 'Original circle_id URL alias reaches the same scoped queue.');
    for (const actor of [guest, viewer, other, banned]) {
      const html = await visitReview(actor, actor === banned ? 'banned' : 'main');
      assert.equal(await actor.page.locator('form[data-operation=circle_review]').count(), 0);
      assert.equal(html.includes(fixture.titles.security), false); assert.equal(html.includes(fixture.titles.cross), false, 'Denied list does not leak title or metadata.');
      if (actor === guest) assert.equal(await actor.page.locator('.sluyu-login-gate').count(), 1);
    }
    await owner.page.goto(portal('circle', { group: fixture.circles.main }));
    const managerLink = owner.page.locator('.sluyu-circle-heading a').filter({ hasText: /\u5f85\u5ba1|\u5ba1\u6838/ });
    assert.equal(await managerLink.count(), 1, 'Actual circle header exposes the scoped moderation page.'); await managerLink.click(); assert.ok(await owner.page.locator('.sluyu-circle-review-item').count());
    await visitReview(owner); const securityForm = reviewForm(owner, 'security'); const securityVersion = await securityForm.locator('input[name=version]').inputValue();
    for (const [actor, values, method, expected] of [
      [guest, {}, 'post', 401], [owner, { nonce: 'forged' }, 'post', 403], [owner, {}, 'get', 405],
      [viewer, {}, 'post', 400], [other, {}, 'post', 400], [owner, { version: '0'.repeat(64) }, 'post', 400],
      [owner, { id: String(fixture.posts.cross) }, 'post', 400], [owner, { group: String(fixture.circles.foreign) }, 'post', 400],
      [owner, { id: '', 'id[]': String(fixture.posts.security) }, 'post', 400], [owner, { group: '', 'group[]': String(fixture.circles.main) }, 'post', 400],
      [banned, { id: String(fixture.posts.banned), group: String(fixture.circles.banned) }, 'post', 403],
    ]) {
      const result = await actor.action('circle_review', { id: String(fixture.posts.security), group: String(fixture.circles.main), decision: 'approve', reason: '', version: securityVersion, ...values }, method);
      assert.equal(result.status, expected); assert.equal(result.success, false);
    }
    let snapshot = await state('security'); assert.equal(snapshot.post.status, 'pending'); assert.deepEqual(snapshot.post.audit, []); assert.equal(snapshot.post.notices.length, 0);
    await nativeBan('owner', true);
    try {
      const revoked = await owner.action('circle_review', { id: String(fixture.posts.security), group: String(fixture.circles.main), decision: 'approve', reason: '', version: securityVersion });
      assert.equal(revoked.status, 403); assert.equal(revoked.success, false, 'A genuinely banned owner cannot use the form opened before the ban.');
      const html = await visitReview(owner); assert.equal(html.includes(fixture.titles.security), false); assert.equal(await owner.page.locator('form[data-operation=circle_review]').count(), 0);
    } finally { await nativeBan('owner', false); }
    for (const actor of [guest, viewer]) {
      const pending = await actor.context.request.get(postUrl('security')); assert.equal(pending.status(), 404); noPrivate(await pending.text(), 'Pending public detail');
      const rest = await actor.context.request.get(restUrl('security')); assert.ok([401, 403, 404].includes(rest.status())); noPrivate(await rest.text(), 'Pending REST');
    }
    const readHelperDenied = await owner.context.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'wuai_circle_moderation_qa_state', _ajax_nonce: qaNonce, id: String(fixture.posts.security) } });
    assert.equal(readHelperDenied.status(), 403, 'Read-only accounting is still admin-only.');

    const recommendationVersion = await decide(owner, 'recommend', 'approve', reasons.recommend);
    snapshot = await state('recommend'); assert.equal(snapshot.post.status, 'pending'); assert.equal(last(snapshot.post).action, 'recommend'); assert.equal(last(snapshot.post).phase, 'applied'); assert.equal(snapshot.post.notices.length, 1); assert.equal(snapshot.post.held, false);
    const replay = await owner.action('circle_review', { id: String(fixture.posts.recommend), group: String(fixture.circles.main), decision: 'approve', reason: reasons.recommend, version: recommendationVersion });
    assert.equal(replay.success, true); snapshot = await state('recommend'); assert.equal(snapshot.post.audit.length, 1); assert.equal(snapshot.post.notices.length, 1);
    await owner.page.reload(); assert.ok((await reviewItem(owner, 'recommend').innerText()).includes(reasons.recommend), 'Reload shows the durable recommendation, not a fake published state.');
    await writer.page.goto(portal('message')); assert.ok((await writer.page.locator('body').innerText()).includes(reasons.recommend), 'Real author notification carries the review explanation.');

    await visitReview(owner); const rejectForm = reviewForm(owner, 'reject'); await rejectForm.locator('select[name=decision]').selectOption('reject'); await rejectForm.locator('textarea[name=reason]').fill('bad');
    await clickCommunity(owner, rejectForm, 'circle_review', fixture.posts.reject, false);
    assert.match(await rejectForm.locator('.sluyu-form-status').innerText(), /5|\u8bf4\u660e/); assert.equal((await state('reject')).post.audit.length, 0);
    await rejectForm.locator('textarea[name=reason]').fill(reasons.reject); await clickCommunity(owner, rejectForm, 'circle_review', fixture.posts.reject);
    snapshot = await state('reject'); assert.equal(snapshot.post.status, 'draft'); assert.equal(last(snapshot.post).action, 'reject'); assert.equal(snapshot.post.notices.length, 1); assert.equal(await reviewItem(owner, 'reject').count(), 0);
    await writer.page.goto(portal('account', { tab: 'submissions', edit: fixture.posts.reject }));
    const edit = writer.page.locator('form[data-operation=edit_submission]'); assert.equal(await edit.count(), 1); assert.equal(await edit.locator('input[name=title]').inputValue(), fixture.titles.reject, 'Rejected author can actually edit their retained draft.');

    snapshot = await nativePublish('recommend'); assert.equal(snapshot.post.notices.length, 2, 'Native editorial approval adds one truthful final notice after owner recommendation.');
    const published = await guest.page.goto(postUrl('recommend')); assert.equal(published.status(), 200); noPrivate(await published.text(), 'Native published ordinary topic');
    const publicRest = await guest.context.request.get(restUrl('recommend')); assert.equal(publicRest.status(), 200); const publicJson = await publicRest.text();
    assert.equal(publicJson.includes(reasons.recommend), false); assert.equal(publicJson.includes('_wuai_circle_review'), false, 'Private review audit is absent from public REST.');

    await visitReview(owner); const staleSettingVersion = await reviewForm(owner, 'publish').locator('input[name=version]').inputValue();
    await admin.page.goto(settingsUrl); assert.equal(await admin.page.locator('input[type=checkbox][name=owner_publish]').isChecked(), false, 'Owner publication requires a visibly unchecked explicit checkbox.');
    const settingForm = admin.page.locator('form').filter({ has: admin.page.locator('input[name=action][value=wuai_circle_review_settings]') });
    const oldSettingStamp = await settingForm.locator('input[name=stamp]').inputValue(); const settingNonce = await settingForm.locator('input[name=_wpnonce]').inputValue();
    for (const [actor, nonce] of [[owner, settingNonce], [admin, 'forged']]) {
      const result = await actor.context.request.post(base + '/wp-admin/admin-post.php', { form: { action: 'wuai_circle_review_settings', _wpnonce: nonce, owner_publish: '1', stamp: oldSettingStamp } });
      assert.equal(result.status(), 403); assert.equal((await state()).settings.owner_publish, '0', 'Unauthorized setting request cannot grant publication.');
    }
    await changeSetting(true);
    const staleSetting = await owner.action('circle_review', { id: String(fixture.posts.publish), group: String(fixture.circles.main), decision: 'approve', reason: '', version: staleSettingVersion });
    assert.equal(staleSetting.status, 400); assert.equal(staleSetting.success, false); assert.equal((await state('publish')).post.audit.length, 0, 'Authorization changes invalidate an already open review form.');
    const staleOption = await admin.context.request.post(base + '/wp-admin/admin-post.php', { form: { action: 'wuai_circle_review_settings', _wpnonce: settingNonce, owner_publish: '0', stamp: oldSettingStamp } });
    assert.equal(staleOption.status(), 200); assert.equal((await state()).settings.owner_publish, '1', 'Stale native setting stamp cannot undo a newer explicit grant.');
    await decide(owner, 'publish', 'approve', 'Ordinary scoped content approved.'); snapshot = await state('publish');
    assert.equal(snapshot.post.status, 'publish'); assert.equal(last(snapshot.post).action, 'publish'); assert.equal(snapshot.post.audit.length, 1); assert.equal(snapshot.post.notices.length, 1, 'No premature duplicate generic publication notice.'); unchangedAccounts(snapshot);
    await decide(owner, 'same_owner', 'approve'); assert.equal((await state('same_owner')).post.status, 'publish', 'One owner can approve a topic only when they manage every assigned circle.');
    for (const key of ['protected', 'guess', 'download', 'own_private']) {
      await visitReview(owner); const form = reviewForm(owner, key); assert.match(await form.locator('option[value=approve]').innerText(), /\u63a8\u8350|\u7ec8\u5ba1/);
      await form.locator('textarea[name=reason]').fill('Private content requires a trusted site editor.'); await clickCommunity(owner, form, 'circle_review', fixture.posts[key]);
      snapshot = await state(key); assert.equal(snapshot.post.status, 'pending'); assert.equal(snapshot.post.private, true); assert.equal(last(snapshot.post).action, 'recommend'); assert.equal(snapshot.post.notices.length, 1);
      const response = await guest.context.request.get(postUrl(key)); assert.equal(response.status(), 404); noPrivate(await response.text(), 'Unpublished private topic ' + key);
    }
    await nativePublish('protected', true);
    const privateRest = await guest.context.request.get(restUrl('protected')); assert.equal(privateRest.status(), 200); noPrivate(await privateRest.text(), 'Published private topic REST');
    for (const actor of [owner, admin]) {
      const nonceResponse = await actor.context.request.get(base + '/wp-admin/admin-ajax.php?action=rest-nonce'); assert.equal(nonceResponse.status(), 200); const restNonce = (await nonceResponse.text()).trim(); assert.match(restNonce, /^[a-z0-9]{10}$/i);
      const headers = { 'X-WP-Nonce': restNonce }; const identity = await actor.context.request.get(base + '/?rest_route=' + encodeURIComponent('/wp/v2/users/me'), { headers });
      assert.equal(identity.status(), 200); assert.equal((await identity.json()).id, fixture.users[actor.name], 'REST is genuinely authenticated, not downgraded to guest.');
      const response = await actor.context.request.get(restUrl('protected') + (actor === admin ? '&context=edit' : ''), { headers }); assert.equal(response.status(), 200); const text = await response.text();
      noPrivate(text, actor.name + ': authenticated REST'); assert.equal(text.includes('_wuai_circle_review'), false); assert.equal(text.includes('Private content requires a trusted site editor.'), false);
      if (actor === owner) { const cross = await actor.context.request.get(restUrl('cross'), { headers }); assert.ok([401, 403, 404].includes(cross.status())); assert.equal((await cross.text()).includes(fixture.titles.cross), false); }
    }

    await visitReview(owner); const staleContentVersion = await reviewForm(owner, 'stale').locator('input[name=version]').inputValue();
    await writer.page.goto(portal('account', { tab: 'submissions', edit: fixture.posts.stale })); const staleEdit = writer.page.locator('form[data-operation=edit_submission]');
    await staleEdit.locator('textarea[name=body]').fill('The author revised the public content after the circle owner opened their review page.');
    await clickCommunity(writer, staleEdit, 'edit_submission', fixture.posts.stale);
    const staleContent = await owner.action('circle_review', { id: String(fixture.posts.stale), group: String(fixture.circles.main), decision: 'approve', reason: '', version: staleContentVersion });
    assert.equal(staleContent.success, false); assert.equal(staleContent.status, 400); assert.equal((await state('stale')).post.status, 'pending');

    async function feed(actor, sort = 'latest', group = 'sort') {
      const response = await actor.page.goto(portal('circle', { group: fixture.circles[group], sort })); assert.equal(response.status(), 200); noPrivate(await response.text(), actor.name + ': circle feed');
      assert.equal(await actor.page.locator('select[name=sort]').inputValue(), sort); return actor.page.locator('.sluyu-feed > article');
    }
    async function sortThroughForm(actor, sort, expected) {
      const form = actor.page.locator('form.sluyu-filter-form').filter({ has: actor.page.locator('select[name=sort]') });
      assert.deepEqual(await form.locator('select[name=sort] option').evaluateAll(options => options.map(option => option.value)), ['latest', 'featured', 'likes', 'comments']);
      await form.locator('select[name=sort]').selectOption(sort); const loaded = actor.page.waitForEvent('load'); loaded.catch(() => {}); await form.locator('button').click(); await loaded;
      assert.equal(await actor.page.locator('select[name=sort]').inputValue(), sort);
      assert.deepEqual(await actor.page.locator('.sluyu-feed > article h2').allTextContents(), expected.map(key => fixture.titles[key]), 'Real GET sorting control selects exact live ' + sort + ' order.');
    }
    await feed(owner);
    const featureItem = owner.page.locator('.sluyu-feed > article').filter({ hasText: fixture.titles.sort_first }); const feature = featureItem.locator('form[data-operation=circle_feature]');
    assert.equal(await feature.count(), 1); const featureVersion = await feature.locator('input[name=version]').inputValue();
    await clickCommunity(owner, feature, 'circle_feature', fixture.posts.sort_first);
    snapshot = await state('sort_first'); assert.equal(snapshot.post.featured[String(fixture.circles.sort)], true); assert.equal(snapshot.post.featured[String(fixture.circles.sort_second)], false);
    const featureReplay = await owner.action('circle_feature', { id: String(fixture.posts.sort_first), group: String(fixture.circles.sort), enabled: '1', version: featureVersion }); assert.equal(featureReplay.success, true);
    for (const [actor, values, status] of [[viewer, {}, 400], [owner, { nonce: 'forged' }, 403], [banned, {}, 403], [owner, { group: String(fixture.circles.foreign) }, 400]]) {
      const result = await actor.action('circle_feature', { id: String(fixture.posts.sort_first), group: String(fixture.circles.sort), enabled: '0', version: featureVersion, ...values }); assert.equal(result.success, false); assert.equal(result.status, status);
    }
    await feed(viewer); assert.equal(await viewer.page.locator('form[data-operation=circle_feature]').count(), 0, 'Public readers never receive a feature mutation form.');
    await sortThroughForm(viewer, 'latest', ['sort_third', 'sort_second', 'sort_first']);
    await sortThroughForm(viewer, 'featured', ['sort_first']);
    await sortThroughForm(viewer, 'likes', ['sort_first', 'sort_second', 'sort_third']);
    await sortThroughForm(viewer, 'comments', ['sort_second', 'sort_third', 'sort_first']);
    await feed(owner, 'featured', 'sort_second'); assert.equal(await owner.page.locator('.sluyu-feed > article').count(), 0, 'Selected feature does not bleed into another assigned circle.');
    // Real likes, not QA writes, must invalidate popularity sorting immediately.
    for (const actor of [owner, viewer]) {
      await actor.page.goto(postUrl('sort_first')); const button = actor.page.locator('.sluyu-article-actions [data-sluyu-like]'); assert.equal(await button.getAttribute('aria-pressed'), 'true');
      const responseReady = actor.page.waitForResponse(response => response.url().includes('/admin-ajax.php') && new URLSearchParams(response.request().postData() || '').get('operation') === 'like'); responseReady.catch(() => {});
      await button.click(); assert.equal((await responseReady).status(), 200); await actor.page.waitForFunction(() => document.querySelector('.sluyu-article-actions [data-sluyu-like]')?.getAttribute('aria-pressed') === 'false');
    }
    await feed(viewer, 'likes'); assert.deepEqual(await viewer.page.locator('.sluyu-feed > article h2').allTextContents(), ['sort_second', 'sort_third', 'sort_first'].map(key => fixture.titles[key]));
    await feed(owner, 'featured'); const removeFeature = owner.page.locator('form[data-operation=circle_feature]'); assert.equal(await removeFeature.locator('input[name=enabled]').inputValue(), '0');
    await clickCommunity(owner, removeFeature, 'circle_feature', fixture.posts.sort_first); assert.equal((await state('sort_first')).post.featured[String(fixture.circles.sort)], false); assert.equal(await owner.page.locator('.sluyu-feed > article').count(), 0);
    await feed(owner); const refeature = owner.page.locator('.sluyu-feed > article').filter({ hasText: fixture.titles.sort_first }).locator('form[data-operation=circle_feature]');
    await clickCommunity(owner, refeature, 'circle_feature', fixture.posts.sort_first); assert.equal((await state('sort_first')).post.featured[String(fixture.circles.sort)], true);

    // A seeded interrupted transaction exposes no title in public surfaces and
    // is repaired through the actual editor recovery form, not a helper mutation.
    const heldNonceResponse = await owner.context.request.get(base + '/wp-admin/admin-ajax.php?action=rest-nonce'); assert.equal(heldNonceResponse.status(), 200);
    const heldRestNonce = (await heldNonceResponse.text()).trim(); assert.match(heldRestNonce, /^[a-z0-9]{10}$/i);
    for (const actor of [guest, viewer, owner]) {
      const response = await actor.page.goto(postUrl('held')); assert.equal(response.status(), 404); const text = await response.text(); noPrivate(text, 'Held singular'); assert.equal(text.includes(fixture.titles.held), false);
      const rest = await actor.context.request.get(restUrl('held'), actor === owner ? { headers: { 'X-WP-Nonce': heldRestNonce } } : {}); assert.equal(rest.status(), 404); assert.equal((await rest.text()).includes(fixture.titles.held), false);
    }
    const heldFeed = await guest.context.request.get(portal('circle', { group: fixture.circles.held })); assert.equal(heldFeed.status(), 200); assert.equal((await heldFeed.text()).includes(fixture.titles.held), false);
    for (const operation of ['edit_submission', 'withdraw_submission', 'delete_submission']) {
      const result = await writer.action(operation, { id: String(fixture.posts.held), version: '0'.repeat(64), title: fixture.titles.held, body: 'Attempted rewrite while isolated.' }); assert.equal(result.success, false); assert.equal(result.status, 400);
    }
    snapshot = await state('held'); assert.equal(snapshot.post.status, 'publish'); assert.equal(snapshot.post.held, true); assert.deepEqual(snapshot.post.audit, [], 'Author cannot replace or remove an interrupted review.');
    await visitReview(owner, 'held'); assert.equal(await owner.page.locator('input[name=decision][value=recover]').count(), 0); assert.ok((await owner.page.locator('body').innerText()).includes(fixture.titles.held), 'Owner can see its isolated item but cannot self-recover.');
    await visitReview(admin, 'held'); const recovery = admin.page.locator('form[data-operation=circle_review]').filter({ has: admin.page.locator('input[name=decision][value=recover]') }); assert.equal(await recovery.count(), 1);
    await recovery.locator('textarea[name=reason]').fill(reasons.recover); await clickCommunity(admin, recovery, 'circle_review', fixture.posts.held);
    snapshot = await state('held'); assert.equal(snapshot.post.status, 'pending'); assert.equal(snapshot.post.held, false); assert.equal(last(snapshot.post).action, 'recover'); assert.equal(snapshot.post.notices.length, 1);
    assert.equal((await guest.context.request.get(postUrl('held'))).status(), 404, 'Recovery restores review, never publishes.');

    await visitReview(owner, 'queue'); await owner.page.screenshot({ path: path.join(output, 'circle-review-desktop.png'), fullPage: true, animations: 'disabled' });
    await feed(viewer, 'comments'); await viewer.page.screenshot({ path: path.join(output, 'circle-sort-desktop.png'), fullPage: true, animations: 'disabled' });
    for (const width of [390, 320]) {
      for (const actor of [owner, viewer, guest]) {
        await actor.page.setViewportSize({ width, height: 844 });
        for (const url of [portal('circle-topics', { group: fixture.circles.queue }), portal('circle', { group: fixture.circles.sort, sort: 'featured' }), portal('circle', { group: fixture.circles.main })]) {
          const response = await actor.page.goto(url); assert.equal(response.status(), 200); noPrivate(await response.text(), actor.name + ': mobile page');
          assert.equal(await actor.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, actor.name + ': page fits ' + width + 'px');
        }
      }
      if (width === 390) {
        await visitReview(owner, 'queue'); await owner.page.screenshot({ path: path.join(output, 'circle-review-mobile.png'), fullPage: true, animations: 'disabled' });
        await feed(viewer, 'featured'); await viewer.page.screenshot({ path: path.join(output, 'circle-featured-mobile.png'), fullPage: true, animations: 'disabled' });
      }
    }
    await changeSetting(false); assert.equal((await state('', 'queue')).queues.owner.total, 13, 'Unrelated review operations did not corrupt scoped queue counts.');
    assert.deepEqual(errors, [], 'No frontend or native-admin JavaScript exceptions.');
    console.log('PASS: real scoped review/reject and native final review; explicit site-owner publication setting without capabilities; private recommendation boundary; nonce/ban/forged/stale protection; exact multi-circle rows/counts; per-circle feature and 4 live sorts; native likes; held recovery; 320/390/1440 layouts.');
  } finally {
    for (const context of contexts) await context.close();
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
