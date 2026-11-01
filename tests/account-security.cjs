'use strict';
// Real cookie/password form lifecycle. Run the CLI fixture first, serially.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8771';
assert.equal(base, 'http://127.0.0.1:8771', 'Disposable local installation only.');
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'account-security-browser-fixture.json'), 'utf8'));
assert.equal(fixture.base, base); assert.equal(new URL(fixture.security_url).origin, base);
const endpoint = base + '/wp-admin/admin-post.php';
const oldPassword = 'qa-security-old-password';
// WordPress core's wp-login.php receives a magic-quoted password and does not
// unslash that field before wp_signon(). Keep this browser credential free of
// backslashes or quote characters so the test covers the theme's password
// lifecycle rather than wp-login.php's legacy magic-quote transport; internal
// spaces remain covered.
const newPassword = 'QA-new quoted inside space-password';

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const errors = []; const securityPosts = []; const securityAjax = [];
  try {
    async function person(name, userAgent) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce', userAgent });
      contexts.push(context); context.setDefaultTimeout(25000);
      await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
      const page = await context.newPage();
      page.on('pageerror', error => errors.push(name + ': ' + error.message));
      page.on('request', request => {
        if (request.method() !== 'POST') return;
        const fields = new URLSearchParams(request.postData() || '');
        if (fields.get('action') === 'wuai_account_security') securityPosts.push({ url: request.url(), operation: fields.get('operation'), method: request.method() });
        if (request.url().includes('admin-ajax.php') && /password/.test(request.postData() || '')) securityAjax.push(request.url());
      });
      return { name, context, page };
    }
    async function login(who, password = oldPassword) {
      const response = await who.page.goto(base + '/wp-login.php');
      assert.equal(response.headers()['x-wuai-qa-security-mail-blocked'], '1', 'Mail guard is active before password mutations.');
      await who.page.locator('#user_login').fill(fixture.login_prefix + who.name);
      await who.page.locator('#user_pass').fill(password);
      await Promise.all([who.page.waitForURL(url => !url.pathname.includes('wp-login.php')), who.page.locator('#wp-submit').click()]);
      await load(who);
    }
    async function load(who) {
      const response = await who.page.goto(fixture.security_url);
      assert.equal(response.headers()['x-wuai-qa-security-mail-blocked'], '1');
      assert.match(response.headers()['cache-control'] || '', /no-cache|no-store|private/);
      assert.equal(response.headers()['referrer-policy'], 'no-referrer');
      await who.page.locator('.sluyu-account-security').waitFor();
      assert.equal(await who.page.locator('.sluyu-security-form').count(), 2, 'Both operations are real POST forms.');
      return response;
    }
    function form(who, operation) {
      return who.page.locator('form.sluyu-security-form').filter({ has: who.page.locator('input[name="operation"][value="' + operation + '"]') });
    }
    async function fields(who, operation) {
      return form(who, operation).evaluate(element => Object.fromEntries(new FormData(element)));
    }
    async function state(who) {
      const nonce = await who.page.locator('#wuai-security-qa-nonce').inputValue();
      const response = await who.context.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'wuai_security_qa_state', _ajax_nonce: nonce } });
      assert.equal(response.status(), 200); const json = await response.json(); assert.equal(json.success, true); return json.data;
    }
    async function rejected(who, data, expected = 403) {
      const response = await who.context.request.post(endpoint, { form: { action: 'wuai_account_security', ...data }, maxRedirects: 0 });
      assert.equal(response.status(), expected, 'Malformed/stale/unauthorized form is rejected.');
      const html = await response.text();
      for (const secret of [oldPassword, newPassword, 'wrong-current-secret-do-not-echo']) assert.equal(html.includes(secret), false, 'Error response must not echo password fields.');
      assert.equal(response.headers().location, undefined, 'Failure does not issue a success redirect.');
      return html;
    }
    async function assertNoLeaks(who) {
      const html = await who.page.content();
      for (const secret of [oldPassword, newPassword]) assert.equal(html.includes(secret), false, 'Initial security HTML contains no password.');
      const cookie = (await who.context.cookies()).find(item => item.name.startsWith('wordpress_logged_in_'));
      assert.ok(cookie, 'Real signed-in browser cookie exists.');
      const decoded = decodeURIComponent(cookie.value); const parts = decoded.split('|');
      assert.equal(html.includes(decoded), false, 'Cookie value is not rendered.');
      if (parts[2]) assert.equal(html.includes(parts[2]), false, 'Raw session token is not rendered.');
      assert.equal(await who.page.locator('.sluyu-security-sessions [data-current="yes"]').count(), 1);
      assert.equal(await who.page.locator('.sluyu-security-sessions img').count(), 0, 'User-agent HTML cannot create markup.');
      assert.ok(await who.page.locator('.sluyu-account-security a[href*="profile.php"]').count(), 'Email/application credential link uses native profile.');
    }
    async function clickOperation(who, operation, password, nextPassword) {
      const target = form(who, operation); const details = who.page.locator('details').filter({ has: target });
      if (await details.getAttribute('open') === null) await details.locator('summary').click();
      await target.locator('[name="current_password"]').fill(password);
      if (nextPassword !== undefined) {
        await target.locator('[name="new_password"]').fill(nextPassword);
        await target.locator('[name="repeat_password"]').fill(nextPassword);
      }
      await target.locator('[name="confirm"]').check();
      assert.equal(await target.getAttribute('method'), 'post');
      assert.equal(await target.evaluate(element => element.classList.contains('sluyu-community-form')), false, 'Generic AJAX cannot intercept password form.');
      const responsePromise = who.page.waitForResponse(response => response.url() === endpoint && response.request().method() === 'POST');
      await Promise.all([who.page.waitForNavigation({ waitUntil: 'load' }), target.locator('[type="submit"]').click()]);
      const response = await responsePromise; assert.equal(response.status(), 303, 'Successful native POST uses redirect-after-post.');
      assert.equal(response.headers()['referrer-policy'], 'no-referrer');
      assert.equal(response.headers().location.includes(encodeURIComponent(password)), false, 'Password is never placed in redirect.');
    }
    async function assertSignedOut(who) {
      await who.page.goto(fixture.security_url);
      assert.equal(await who.page.locator('.sluyu-account-security').count(), 0, 'Revoked cookie cannot render own security page.');
      assert.equal(await who.page.locator('.sluyu-security-form').count(), 0);
    }

    const first = await person('owner', 'QA Security Primary <img src=x onerror=alert(1)>');
    const second = await person('owner', 'QA Security Second Browser');
    const other = await person('other', 'QA Security Different Account');
    const guest = await person('', 'QA Security Anonymous');
    const banned = await person('banned', 'QA Security Banned Account');
    await login(first); await login(second); await login(other); await login(banned); await load(first);
    await assertNoLeaks(first); await assertNoLeaks(second);
    assert.equal((await state(first)).sessions, 2, 'Two independent owner browser sessions are live.');
    assert.equal((await state(other)).sessions, 1);
    assert.equal((await state(first)).mail_attempts, 0);
    const original = await fields(first, 'revoke_others'); const passwordFields = await fields(first, 'change_password');
    const secondFields = await fields(second, 'revoke_others');
    const baseFields = { ...original, current_password: oldPassword, confirm: '1' };
    await rejected(first, { ...baseFields, _wuai_security_nonce: 'invalid' });
    await rejected(first, { ...baseFields, current_password: 'wrong-current-secret-do-not-echo' });
    await rejected(first, { ...baseFields, _wuai_security_nonce: passwordFields._wuai_security_nonce });
    await rejected(first, { ...baseFields, _wuai_security_nonce: secondFields._wuai_security_nonce });
    const malformed = { ...baseFields }; delete malformed.current_password; malformed['current_password[]'] = oldPassword;
    await rejected(first, malformed, 400);
    await rejected(guest, baseFields);
    const wrongMethod = await first.context.request.get(endpoint + '?action=wuai_account_security', { maxRedirects: 0 });
    assert.equal(wrongMethod.status(), 405);
    assert.equal((await state(first)).sessions, 2, 'All rejected requests leave both owner sessions intact.');
    assert.equal((await state(other)).sessions, 1, 'Rejected requests do not affect another account.');

    await clickOperation(banned, 'revoke_others', oldPassword);
    assert.equal((await state(banned)).current_valid, true, 'Community ban cannot prevent a user from securing their own account.');
    await clickOperation(first, 'revoke_others', oldPassword);
    assert.match(await first.page.locator('.sluyu-account-security').innerText(), /当时仅保留当前浏览器的登录会话/);
    const afterRevoke = await state(first);
    assert.equal(afterRevoke.sessions, 1); assert.equal(afterRevoke.current_valid, true);
    assert.equal((await state(other)).sessions, 1); await assertSignedOut(second);
    await rejected(second, { ...secondFields, current_password: oldPassword, confirm: '1' });
    await login(second); await load(first);
    assert.equal((await state(first)).sessions, 2, 'A later legitimate login creates a new session, not a permanently blocked device.');
    await assertNoLeaks(first);
    await first.page.setViewportSize({ width: 390, height: 844 });
    await first.page.screenshot({ path: path.join(output, 'account-security-mobile.png'), fullPage: true });
    assert.equal(await first.page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, 'Security page fits a narrow mobile viewport.');
    await first.page.setViewportSize({ width: 1440, height: 1000 });
    await first.page.screenshot({ path: path.join(output, 'account-security-desktop.png'), fullPage: true });
    const stalePasswordFields = await fields(second, 'change_password');
    await clickOperation(first, 'change_password', oldPassword, newPassword);
    assert.ok(new URL(first.page.url()).pathname.includes('wp-login.php'), 'Changing password signs the acting browser out to login.');
    assert.match(await first.page.locator('body').innerText(), /所有浏览器登录会话已清除/);
    assert.equal((await first.context.cookies()).some(item => item.name.startsWith('wordpress_logged_in_')), false, 'Acting browser auth cookie is cleared.');
    assert.equal((await first.page.content()).includes(newPassword), false, 'Success page does not render the new password.');
    await assertSignedOut(second);
    await rejected(second, { ...stalePasswordFields, current_password: oldPassword, new_password: 'not-used-password-123', repeat_password: 'not-used-password-123', confirm: '1' });
    await other.page.goto(fixture.security_url); assert.equal((await state(other)).current_valid, true, 'Different account remains signed in.');

    await first.page.goto(base + '/wp-login.php');
    await first.page.locator('#user_login').fill(fixture.login_prefix + 'owner'); await first.page.locator('#user_pass').fill(oldPassword);
    await Promise.all([first.page.waitForNavigation({ waitUntil: 'load' }), first.page.locator('#wp-submit').click()]);
    assert.ok(new URL(first.page.url()).pathname.includes('wp-login.php')); assert.ok(await first.page.locator('#login_error').count(), 'Old password no longer signs in.');
    await login(first, newPassword);
    const afterChange = await state(first);
    assert.equal(afterChange.sessions, 1); assert.equal(afterChange.current_valid, true); assert.equal(afterChange.mail_attempts, 1, 'One native password notification was attempted but intercepted.');
    await assertNoLeaks(first);
    assert.equal((await state(other)).mail_attempts, 0, 'Other account receives no password notification.');
    assert.deepEqual(securityAjax, [], 'No password fields go through the generic AJAX endpoint.');
    assert.ok(securityPosts.length >= 3 && securityPosts.every(post => post.url === endpoint && post.method === 'POST'));
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(output, 'account-security-browser-results.json'), JSON.stringify({ passed: true, realBrowserSessions: 2, checks: ['csrf-method-session-binding', 'wrong-password-and-shape', 'own-account-only', 'ban-preserves-self-security', 'revoke-keeps-current-browser', 'changed-password-kills-both-old-sessions', 'old-login-rejected-new-login-works', 'native-mail-blocked-once', 'token-and-password-not-rendered', 'no-password-ajax', 'mobile-desktop'], screenshots: ['account-security-mobile.png', 'account-security-desktop.png'], testedAt: new Date().toISOString() }, null, 2));
    console.log('PASS: real two-browser security lifecycle, native POST/nonce/current-password gates, current-session retention, all-session password logout, old/new sign-in, mail interception and responsive read-only session UI.');
  } finally {
    for (const context of contexts) await context.close();
    await browser.close();
  }
})().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
