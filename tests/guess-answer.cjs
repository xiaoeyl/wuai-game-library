'use strict';
// Run serially after guess-answer-browser-fixture.php against the disposable site.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8771';
assert.equal(base, 'http://127.0.0.1:8771', 'Only the hard-guarded disposable fixture is allowed.');
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'guess-answer-fixture.json'), 'utf8'));
assert.equal(fixture.base, base);
const portal = (key, args = {}) => base + '/?' + new URLSearchParams({ sluyu_page: key, ...args });
const postUrl = record => base + '/?post_type=sluyu_topic&p=' + record.id;
const restUrl = record => base + '/?rest_route=' + encodeURIComponent('/wp/v2/sluyu_topic/' + record.id);
const records = Object.fromEntries(Object.entries(fixture.posts).map(([key, id]) => [key, { id, secretKey: key, version: fixture.versions[key] }]));
const allVersions = new Set(Object.values(fixture.versions));
function assertPrivateAbsent(text, label, except = []) {
  for (const [key, secret] of Object.entries(fixture.secrets)) {
    if (!except.includes(key)) assert.equal(text.includes(secret), false, label + ': no private fragment ' + key);
  }
  for (const key of ['_wuai_guess_config', 'answer_hash', 'prompt_hash']) assert.equal(text.includes(key), false, label + ': no internal ' + key);
  assert.doesNotMatch(text, /Fatal error|Uncaught (?:Error|Exception)/, label + ': no PHP fatal');
}
function assertPublicOnly(text, label) {
  assertPrivateAbsent(text, label);
  for (const version of allVersions) assert.equal(text.includes(version), false, label + ': no entitlement version outside the answer form');
  assert.equal(text.includes('sluyu-guess-body'), false, label + ': no private body container');
}

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const errors = [];
  try {
    async function session(name) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' }); contexts.push(context);
      const page = await context.newPage(); page.on('pageerror', error => errors.push({ account: name, url: page.url(), stack: error.stack || error.message }));
      if (process.env.WP_QA_DEBUG_JS === '1') {
        const client = await context.newCDPSession(page);
        await client.send('Runtime.enable'); await client.send('Debugger.enable');
        let editorResponses = 0;
        page.on('response', async response => {
          if (name !== 'admin' || !response.request().isNavigationRequest() || !new URL(response.url()).pathname.endsWith('/post.php')) return;
          const sequence = ++editorResponses;
          try { fs.writeFileSync(path.join(output, 'guess-editor-response-' + sequence + '.html'), await response.text()); } catch {}
        });
        client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => fs.appendFileSync(path.join(output, 'guess-runtime-errors.jsonl'), JSON.stringify({ account: name, ...exceptionDetails }) + '\n'));
        client.on('Debugger.scriptFailedToParse', async script => {
          try { const source = await client.send('Debugger.getScriptSource', { scriptId: script.scriptId }); fs.writeFileSync(path.join(output, 'guess-failed-script-' + name + '-' + script.scriptId + '.json'), JSON.stringify({ ...script, ...source }, null, 2)); } catch {}
        });
      }
      const loginPage = await page.goto(base + '/wp-login.php?redirect_to=' + encodeURIComponent(portal('account')));
      assert.equal(loginPage.headers()['x-wuai-guess-qa-mail-blocked'], '1', 'The guarded mail blocker must be active before browser mutations.');
      if (name !== 'guest') {
        await page.locator('#user_login').fill(fixture.loginPrefix + name);
        await page.locator('#user_pass').fill('qa-local-only');
        const loggedIn = page.waitForURL(url => !url.pathname.includes('wp-login.php')); loggedIn.catch(() => {});
        await Promise.all([loggedIn, page.locator('#wp-submit').click()]);
      }
      await page.goto(portal('account'));
      const nonce = await page.evaluate(() => window.wuaiSluyu.nonce);
      assert.equal(typeof nonce, 'string', name + ': frontend nonce available');
      return { name, context, page, nonce, action: async (operation, values = {}, method = 'post') => {
        const form = { action: 'wuai_sluyu_community', nonce, operation, ...values };
        const response = method === 'get'
          ? await context.request.get(base + '/wp-admin/admin-ajax.php', { params: form })
          : await context.request.post(base + '/wp-admin/admin-ajax.php', { form });
        const text = await response.text(); assertPrivateAbsent(text, name + ': ' + operation + ' response');
        return { status: response.status(), ...JSON.parse(text) };
      } };
    }
    async function visit(actor, record, allowed = false, expectedStatus = 200) {
      const response = await actor.page.goto(postUrl(record));
      assert.ok(response); assert.equal(response.status(), expectedStatus, actor.name + ': puzzle HTTP status');
      const html = await response.text(); assertPrivateAbsent(html, actor.name + ': raw puzzle HTML', allowed ? [record.secretKey] : []);
      const body = actor.page.locator('.sluyu-guess-body');
      if (allowed) {
        assert.ok(html.includes(fixture.secrets[record.secretKey]), 'Authorized fragment is server-rendered, not only a client toggle.');
        assert.equal(await body.count(), 1); assert.ok((await body.innerText()).includes(fixture.secrets[record.secretKey]));
      } else assert.equal(await body.count(), 0, actor.name + ': private body is not in the DOM');
      if (expectedStatus === 200) assert.match(response.headers()['cache-control'] || '', /no-cache|no-store|private/i, 'Puzzle pages cannot be publicly cached.');
      return response;
    }
    async function clickForm(actor, operation, status = 200, reload = false) {
      const page = actor.page; const form = page.locator('form[data-operation="' + operation + '"]');
      const pending = page.waitForResponse(response => response.url().includes('/wp-admin/admin-ajax.php') && new URLSearchParams(response.request().postData() || '').get('operation') === operation).then(value => ({ value }), error => ({ error }));
      const loaded = reload ? page.waitForEvent('load').then(value => ({ value }), error => ({ error })) : null;
      await form.locator('button[type=submit]').click();
      const response = await pending; if (response.error) throw response.error;
      assert.equal(response.value.status(), status, operation + ': real browser form status');
      // Reload actions can discard Chromium's response body; assert durable state instead.
      if (loaded) { const outcome = await loaded; if (outcome.error) throw outcome.error; }
      else {
        const text = await response.value.text(); assertPrivateAbsent(text, operation + ': form response');
        await page.waitForFunction(op => { const form = document.querySelector('form[data-operation="' + op + '"]'); return form && !form.dataset.busy && form.querySelector('.sluyu-form-status').textContent.length > 0; }, operation);
      }
    }
    async function balance(actor) {
      const page = await actor.context.newPage();
      try {
        const response = await page.goto(portal('gold')); assert.equal(response.status(), 200);
        assertPrivateAbsent(await response.text(), actor.name + ': points ledger');
        return Number((await page.locator('.sluyu-big-number').innerText()).trim());
      } finally { await page.close(); }
    }
    async function choose(actor, record, choice, status = 200, reload = true) {
      await visit(actor, record);
      const form = actor.page.locator('form[data-operation=guess_answer]');
      assert.equal(await form.locator('input[name=version]').inputValue(), record.version);
      await form.locator('input[name=choice][value="' + choice + '"]').check();
      await clickForm(actor, 'guess_answer', status, reload);
    }

    const guest = await session('guest'); const owner = await session('owner'); const reader = await session('reader');
    const wrong = await session('wrong'); const other = await session('other'); const admin = await session('admin');
    await admin.page.goto(base + '/wp-admin/');
    const qaNonce = await admin.page.locator('#wuai-guess-qa-nonce').inputValue();
    async function state(record) {
      const response = await admin.context.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'wuai_guess_qa_state', _ajax_nonce: qaNonce, id: String(record.id) } });
      assert.equal(response.status(), 200);
      const text = await response.text(); assertPrivateAbsent(text, 'Read-only QA accounting');
      const result = JSON.parse(text); assert.equal(result.success, true); return result.data;
    }
    function assertBalances(snapshot) {
      for (const [name, user] of Object.entries(snapshot.users)) assert.equal(user.points, fixture.initialPoints, name + ': guessing neither charges nor rewards points');
    }
    function ownEvents(snapshot, actor) { return snapshot.events.filter(event => Number(event.user_id) === fixture.users[actor.name]); }
    function assertEvent(event, actor, record) {
      assert.equal(Number(event.user_id), fixture.users[actor.name]); assert.equal(Number(event.target_id), record.id);
      assert.equal(event.event_type, 'guess'); assert.equal(Number(event.points), 0);
      assert.equal(event.event_key, 'guess:' + record.id + ':' + record.version);
      assert.doesNotMatch(event.payload, /North|South|East|choice|answer_hash|QA-GUESS-HIDDEN/);
    }
    async function nativeEditor(record, correct, body) {
      await admin.page.goto(base + '/wp-admin/post.php?post=' + record.id + '&action=edit');
      if (!(await admin.page.locator('form#post').count())) {
        // WordPress itself publishes this nonce-protected classic metabox loader URL.
        // Use its real editor form, not a QA mutation endpoint or an editor-mode filter.
        const loader = await admin.page.evaluate(() => window._wpMetaBoxUrl);
        assert.equal(typeof loader, 'string', 'Native block editor supplies its metabox loader URL.');
        const parsed = new URL(loader); assert.equal(parsed.origin, base); assert.equal(parsed.searchParams.get('post'), String(record.id));
        assert.ok(parsed.searchParams.get('meta-box-loader-nonce'));
        await admin.page.goto(parsed.href);
      }
      assert.equal(await admin.page.locator('input[name=wuai_guess_nonce]').count(), 1, 'Real editor contains the private-config nonce.');
      assert.equal(await admin.page.locator('input[name=wuai_guess_stamp]').count(), 1, 'Real editor contains optimistic config stamp.');
      assert.equal(await admin.page.locator('#title').inputValue(), fixture.submission.title);
      assert.equal(await admin.page.locator('select[name=wuai_topic_kind]').inputValue(), 'guess', 'Generic services editor must preserve the guess type.');
      const box = admin.page.locator('#wuai-guess-answer');
      if ((await box.getAttribute('class') || '').split(/\s+/).includes('closed')) await box.locator('.handlediv').click();
      await box.locator('textarea[name=wuai_guess_choices]').fill(fixture.choices);
      await box.locator('input[name=wuai_guess_correct]').fill(String(Number(correct) + 1));
      await box.locator('textarea[name=wuai_guess_body]').fill(body);
      if (await admin.page.locator('#content-html').count()) await admin.page.locator('#content-html').click();
      await admin.page.locator('#content').fill(fixture.submission.prompt);
      const saved = admin.page.waitForURL(url => url.pathname.endsWith('/post.php') && url.searchParams.has('message')).then(value => ({ value }), error => ({ error }));
      await admin.page.locator('#publish').click();
      const outcome = await saved; if (outcome.error) throw outcome.error;
      const snapshot = await state(record);
      assert.equal(snapshot.post.status, 'publish', 'Native editor publishes/updates through WordPress.');
      assert.equal(snapshot.post.kind, 'guess', 'Native review does not downgrade the puzzle into an ordinary topic.');
      assert.equal(snapshot.post.config_valid, true, 'Native editor saves a complete private config.');
      return snapshot;
    }

    for (const actor of [guest, reader, other]) {
      await visit(actor, records.seed);
      await visit(actor, records.draft, false, 404);
      await visit(actor, records.password);
    }
    await visit(owner, records.seed, true);
    assert.match(await owner.page.locator('.sluyu-guess').innerText(), /\u4f5c\u8005 \/ \u7f16\u8f91\u9884\u89c8/);
    assert.equal((await state(records.seed)).events.length, 0, 'Preview never creates a solved entitlement.');
    await guest.page.goto(portal('submit', { kind: 'guess' }));
    assert.equal(await guest.page.locator('.sluyu-login-gate').count(), 1);
    for (const args of [{ 'group[]': '0' }, { 'circle_id[]': '0' }, { group: '0', 'circle_id[]': '0' }, { group: '-1' }]) {
      await owner.page.goto(portal('submit', { kind: 'guess', ...args }));
      assert.equal(await owner.page.locator('form[data-operation=guess_submit]').count(), 0, 'Malformed group query cannot silently become an ungrouped submission.');
    }
    await owner.page.goto(portal('submit', { kind: 'guess' }));
    const submit = owner.page.locator('form[data-operation=guess_submit]');
    assert.equal(await submit.count(), 1); assert.equal(await owner.page.locator('form[data-operation=submit]').count(), 0);
    const privateBody = '<p>' + fixture.secrets.submitted + '</p><p>Original independent private fragment.</p>';
    await submit.locator('input[name=title]').fill(fixture.submission.title);
    await submit.locator('textarea[name=body]').fill(fixture.submission.prompt);
    await submit.locator('textarea[name=guess_choices]').fill(fixture.choices);
    await submit.locator('input[name=guess_correct]').fill(String(Number(fixture.correct) + 1));
    await submit.locator('textarea[name=guess_body]').fill(privateBody);
    await submit.locator('select[name=group]').selectOption('0');
    await clickForm(owner, 'guess_submit');
    assert.match(await submit.locator('.sluyu-form-status').innerText(), /\u5ba1\u6838/);
    assert.equal(await submit.locator('input[name=title]').inputValue(), '', 'Successful author submission resets the form.');
    await owner.page.goto(portal('account', { tab: 'submissions' }));
    const pendingRow = owner.page.locator('[data-account-list=submissions] .sluyu-compact-list > div').filter({ hasText: fixture.submission.title });
    assert.equal(await pendingRow.count(), 1); assert.match(await pendingRow.innerText(), /\u5ba1\u6838/);
    const id = Number(await pendingRow.locator('[data-community-action=withdraw_submission]').getAttribute('data-id'));
    assert.ok(Number.isInteger(id) && id > 0); assert.equal(await pendingRow.locator('a[href*="edit="]').count(), 0, 'Generic account editor cannot bypass answer versioning.');
    const submitted = { id, secretKey: 'submitted' }; records.submitted = submitted;
    let snapshot = await state(submitted); submitted.version = snapshot.post.version; allVersions.add(submitted.version);
    assert.equal(snapshot.post.status, 'pending'); assert.equal(snapshot.post.config_valid, true);
    assert.equal(snapshot.post.content, fixture.submission.prompt); assert.equal(snapshot.post.excerpt, ''); assertBalances(snapshot);
    await visit(guest, submitted, false, 404);
    const pendingRest = await guest.context.request.get(restUrl(submitted));
    assert.ok([401, 403, 404].includes(pendingRest.status()), 'Pending puzzle is not publicly readable through REST.');
    assertPrivateAbsent(await pendingRest.text(), 'Pending guest REST');

    const initialVersion = submitted.version;
    snapshot = await nativeEditor(submitted, fixture.correct, privateBody);
    assert.equal(snapshot.post.version, initialVersion, 'Publishing unchanged fields keeps the same puzzle version.');
    await visit(guest, submitted); await visit(owner, submitted, true); await visit(admin, submitted, true);
    assert.equal((await state(submitted)).events.length, 0, 'Author and administrator preview do not count as solving.');
    await visit(wrong, submitted);
    const answerForm = wrong.page.locator('form[data-operation=guess_answer]');
    assert.equal(await answerForm.locator('input[name=choice]').count(), 3);
    assert.equal(await answerForm.evaluate(form => form.checkValidity()), false);
    let answerRequests = 0;
    wrong.page.on('request', request => { if (request.url().includes('/admin-ajax.php') && new URLSearchParams(request.postData() || '').get('operation') === 'guess_answer') answerRequests++; });
    await answerForm.locator('button[type=submit]').click(); await wrong.page.waitForTimeout(100);
    assert.equal(answerRequests, 0, 'Required single-choice control prevents empty browser submission.');
    for (const [actor, values, method, status] of [
      [guest, {}, 'post', 401], [reader, { nonce: 'wrong-nonce' }, 'post', 403],
      [reader, {}, 'get', 405], [reader, { choice: '7' }, 'post', 400],
      [reader, { choice: '01' }, 'post', 400], [reader, { choice: '', 'choice[]': '1' }, 'post', 400],
      [reader, { version: 'stale-version' }, 'post', 400],
    ]) {
      const result = await actor.action('guess_answer', { id: String(id), choice: fixture.correct, version: submitted.version, ...values }, method);
      assert.equal(result.status, status); assert.equal(result.success, false);
    }
    snapshot = await state(submitted); assert.equal(snapshot.attempts.reader.failures, 0); assert.equal(snapshot.events.length, 0); assertBalances(snapshot);
    await answerForm.locator('input[name=choice][value="' + fixture.wrong + '"]').check();
    await clickForm(wrong, 'guess_answer', 400);
    assert.match(await answerForm.locator('.sluyu-form-status').innerText(), /\u6ca1\u6709\u731c\u5bf9/);
    assert.doesNotMatch(await answerForm.locator('.sluyu-form-status').innerText(), /South/);
    const cooldown = await wrong.action('guess_answer', { id: String(id), choice: fixture.correct, version: submitted.version });
    assert.equal(cooldown.status, 400); assert.equal(cooldown.success, false); assert.match(cooldown.data.message, /\u79d2\u540e\u518d/);
    snapshot = await state(submitted); assert.equal(snapshot.attempts.wrong.failures, 1); assert.equal(snapshot.events.length, 0); assertBalances(snapshot);
    await visit(wrong, submitted); assert.match(await wrong.page.locator('.sluyu-guess').innerText(), /\u8bf7\u7b49\u5f85/);

    assert.equal(await balance(reader), fixture.initialPoints);
    await choose(reader, submitted, fixture.correct);
    assert.ok((await reader.page.locator('.sluyu-guess-body').innerText()).includes(fixture.secrets.submitted));
    assert.match(await reader.page.locator('.sluyu-guess').innerText(), /\u5df2\u7b54\u5bf9\u5f53\u524d\u7248\u672c/);
    await visit(reader, submitted, true); await reader.page.reload();
    assert.ok((await reader.page.locator('.sluyu-guess-body').innerText()).includes(fixture.secrets.submitted), 'Reload retains the unique current-version entitlement.');
    for (let i = 0; i < 3; i++) {
      const repeated = await reader.action('guess_answer', { id: String(id), choice: fixture.correct, version: submitted.version });
      assert.equal(repeated.status, 200); assert.equal(repeated.success, true); assert.equal(repeated.data.solved, true);
    }
    snapshot = await state(submitted); assert.equal(ownEvents(snapshot, reader).length, 1); assertEvent(ownEvents(snapshot, reader)[0], reader, submitted); assertBalances(snapshot);
    assert.equal(await balance(reader), fixture.initialPoints);
    await visit(other, submitted); await visit(guest, submitted);
    assert.equal((await state(submitted)).attempts.other.failures, 0, 'Another account does not inherit attempts or entitlement.');
    await reader.page.screenshot({ path: path.join(output, 'guess-unlocked-desktop.png'), fullPage: true, animations: 'disabled' });
    await other.page.screenshot({ path: path.join(output, 'guess-locked-desktop.png'), fullPage: true, animations: 'disabled' });

    // The fixture ages one prior failure, so this tests expiry without sleeping 30 seconds.
    await choose(wrong, records.seed, fixture.correct);
    snapshot = await state(records.seed); assert.equal(snapshot.attempts.wrong.failures, 1); assert.equal(ownEvents(snapshot, wrong).length, 1); assertEvent(ownEvents(snapshot, wrong)[0], wrong, records.seed); assertBalances(snapshot);
    await visit(other, records.seed); assert.equal(await other.page.locator('form[data-operation=guess_answer]').count(), 0);
    const exhausted = await other.action('guess_answer', { id: String(records.seed.id), choice: fixture.correct, version: records.seed.version });
    assert.equal(exhausted.status, 400); assert.equal(exhausted.success, false); assert.match(exhausted.data.message, /5/);
    assert.equal(ownEvents(await state(records.seed), other).length, 0, 'A correct selection cannot bypass the five-failure limit.');

    async function alternateSurfaces(actors, record) {
      for (const actor of actors) {
        let headers = {};
        if (actor.name !== 'guest') {
          const nonceResponse = await actor.context.request.get(base + '/wp-admin/admin-ajax.php?action=rest-nonce');
          assert.equal(nonceResponse.status(), 200); const restNonce = (await nonceResponse.text()).trim();
          assert.match(restNonce, /^[a-z0-9]{10}$/i); headers = { 'X-WP-Nonce': restNonce };
          const identity = await actor.context.request.get(base + '/?rest_route=' + encodeURIComponent('/wp/v2/users/me'), { headers });
          assert.equal(identity.status(), 200, actor.name + ': REST nonce really authenticates this account');
          assert.equal((await identity.json()).id, fixture.users[actor.name]);
        }
        const response = await actor.context.request.get(restUrl(record), { headers });
        assert.equal(response.status(), 200); const text = await response.text(); assertPublicOnly(text, actor.name + ': authenticated REST');
        const data = JSON.parse(text); assert.equal(data.id, record.id); assert.equal(typeof data.content.rendered, 'string');
        assert.equal(data.content.rendered.includes('sluyu-guess'), false, 'REST renders only the public prompt, without a gate or hidden fragment.');
        if (actor.name === 'admin') {
          const edit = await actor.context.request.get(restUrl(record) + '&context=edit', { headers });
          assert.equal(edit.status(), 200); assertPublicOnly(await edit.text(), 'Administrator REST edit context');
        }
        const feed = await actor.context.request.get(base + '/?feed=rss2&post_type=sluyu_topic&author=' + fixture.users.owner);
        assert.equal(feed.status(), 200); const xml = await feed.text(); assert.match(xml, /<rss[\s>]/i);
        assert.ok(xml.includes(fixture.publicPrefix), 'The feed contains real fixture public content, not an empty error.');
        assertPublicOnly(xml, actor.name + ': author topic feed');
        const embed = await actor.context.request.get(postUrl(record) + '&embed=true');
        assert.equal(embed.status(), 200); const html = await embed.text(); assert.match(html, /<(?:html|body)\b/i);
        assert.ok(html.includes(fixture.submission.title), 'The embed is for the actual published puzzle.');
        assertPublicOnly(html, actor.name + ': embed');
      }
    }
    await alternateSurfaces([guest, reader, owner, admin], submitted);
    for (const actor of [guest, reader]) {
      const archive = await actor.page.goto(base + '/?author=' + fixture.users.owner + '&post_type=sluyu_topic');
      assert.equal(archive.status(), 200); assertPublicOnly(await archive.text(), actor.name + ': author archive');
      const search = await actor.page.goto(base + '/?s=' + encodeURIComponent(fixture.publicPrefix) + '&post_type=sluyu_topic');
      assert.equal(search.status(), 200); assertPublicOnly(await search.text(), actor.name + ': public title search');
      assert.ok(await actor.page.locator('.sluyu-grid .sluyu-card').count(), 'Public puzzle search has actual results.');
      await actor.page.goto(base + '/?s=' + encodeURIComponent(fixture.secrets.submitted) + '&post_type=sluyu_topic');
      // Search intentionally echoes its query; inspect actual results, not the search field.
      assert.equal(await actor.page.locator('.sluyu-grid .sluyu-card').count(), 0, 'The private fragment is not indexed in public search.');
    }
    for (const width of [390, 320]) {
      for (const actor of [guest, reader, other]) {
        await actor.page.setViewportSize({ width, height: 844 }); await visit(actor, submitted, actor === reader);
        assert.equal(await actor.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, actor.name + ': puzzle fits ' + width + 'px');
        if (width === 390 && actor !== guest) await actor.page.screenshot({ path: path.join(output, 'guess-' + (actor === reader ? 'unlocked' : 'locked') + '-mobile.png'), fullPage: true, animations: 'disabled' });
      }
      await owner.page.setViewportSize({ width, height: 844 }); await owner.page.goto(portal('submit', { kind: 'guess' }));
      assert.equal(await owner.page.locator('form[data-operation=guess_submit]').count(), 1);
      assert.equal(await owner.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Author submission fits ' + width + 'px');
      if (width === 390) await owner.page.screenshot({ path: path.join(output, 'guess-submit-mobile.png'), fullPage: true, animations: 'disabled' });
    }

    snapshot = await nativeEditor(submitted, fixture.correct, privateBody);
    assert.equal(snapshot.post.version, submitted.version, 'A native no-op save preserves earned access.');
    const oldVersion = submitted.version;
    const revisedBody = '<p>' + fixture.secrets.revised + '</p><p>Changed private fragment and correct choice.</p>';
    snapshot = await nativeEditor(submitted, fixture.revisedCorrect, revisedBody);
    assert.notEqual(snapshot.post.version, oldVersion, 'Admin answer/body changes rotate the puzzle version.');
    submitted.version = snapshot.post.version; submitted.secretKey = 'revised'; allVersions.add(submitted.version);
    assert.equal(snapshot.attempts.reader.failures, 0); assert.equal(snapshot.attempts.wrong.failures, 0, 'A new version gets a new attempt budget.');
    assert.equal(ownEvents(snapshot, reader).length, 1, 'The old earned event remains an audit record.');
    assert.equal(ownEvents(snapshot, reader)[0].event_key, 'guess:' + id + ':' + oldVersion);
    await visit(reader, submitted); await visit(other, submitted); await visit(guest, submitted);
    const stale = await reader.action('guess_answer', { id: String(id), choice: fixture.correct, version: oldVersion });
    assert.equal(stale.status, 400); assert.equal(stale.success, false); assert.match(stale.data.message, /\u7248\u672c/);
    await choose(reader, submitted, fixture.revisedCorrect);
    await visit(reader, submitted, true);
    snapshot = await state(submitted); assertBalances(snapshot);
    const readerEvents = ownEvents(snapshot, reader); assert.equal(readerEvents.length, 2);
    assert.equal(new Set(readerEvents.map(event => event.event_key)).size, 2, 'Distinct versions each have at most one durable entitlement.');
    assertEvent(readerEvents.find(event => event.event_key.endsWith(submitted.version)), reader, submitted);
    assert.equal(await balance(reader), fixture.initialPoints);
    const repeatedNew = await reader.action('guess_answer', { id: String(id), choice: fixture.revisedCorrect, version: submitted.version });
    assert.equal(repeatedNew.success, true); assert.equal(ownEvents(await state(submitted), reader).length, 2);
    await visit(other, submitted); await visit(guest, submitted);
    await alternateSurfaces([reader, admin], submitted);
    assert.deepEqual(errors, [], 'No browser JavaScript exceptions.');
    console.log('PASS: real author submission and native admin review; private guest/account isolation; single-choice required/wrong/cooldown/limit and aged cooldown; exact unique zero-point version-bound entitlements; refresh/idempotency; native no-op and answer-version invalidation; genuinely authenticated REST, feed/embed/search/archive isolation; no-cache and 320/390/1440 layouts.');
  } finally {
    for (const context of contexts) await context.close();
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
