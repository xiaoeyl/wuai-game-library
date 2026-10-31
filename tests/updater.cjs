'use strict';
// Requires updater-fixture.php and disposable packages in LOCAL WordPress.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8767';
assert.match(base, /^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/);
const out = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.0.8');
fs.mkdirSync(out, { recursive: true });
(async () => {
    const browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE, headless: true });
    try {
        const context = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        let interruptPollOnce = false;
        if (process.env.WP_QA_POLL_URL) {
            assert.match(process.env.WP_QA_POLL_URL, /^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/);
            await page.route('**/wp-admin/admin-ajax.php', async route => {
                const body = route.request().postData() || '';
                if (!body.includes('action=wuai_sluyu_update_status')) return route.continue();
                if (interruptPollOnce) { interruptPollOnce = false; return route.abort(); }
                const response = await context.request.post(process.env.WP_QA_POLL_URL + '/wp-admin/admin-ajax.php', {
                    data: body, headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });
                return route.fulfill({ response });
            });
        }
        async function login(p, user) {
            await p.goto(base + '/wp-login.php');
            await p.locator('#user_login').fill(user);
            await p.locator('#user_pass').fill('qa-local-only');
            await Promise.all([p.waitForURL(/wp-admin/), p.locator('#wp-submit').click()]);
        }
        await login(page, 'qa_admin');
        await page.goto(base + '/wp-admin/admin.php?page=wuai-sluyu');
        const nonce = await page.evaluate(() => wuaiUpdater.nonce);
        async function ajax(action, data = {}, ctx = context) {
            return ctx.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'wuai_sluyu_' + action, nonce, ...data } });
        }
        async function mode(name, data = {}) {
            const r = await context.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'wuai_qa_mode', nonce, mode: name, ...data } });
            const json = await r.json(); assert.equal(json.success, true); return json.data;
        }
        async function check() { return (await ajax('check_update', { force: 1 })).json(); }
        async function install() { return (await ajax('install_update', { version: '99.0.0', job: 'qa-job-' + Date.now() })).json(); }
        await mode('current', { channel: 'stable' });
        let result = await check();
        assert.equal(result.success, true); assert.equal(result.data.available, false);
        const baseline = (await mode('current')).options;
        const cached = await mode('current');
        await ajax('check_update');
        assert.equal((await mode('current')).calls, cached.calls, 'Automatic checks use cache');
        await check();
        assert.equal(Number((await mode('current')).calls), Number(cached.calls) + 1, 'Manual check bypasses cache');
        assert.equal((await ajax('check_update', { nonce: 'invalid' })).status(), 403);
        const subscriber = await browser.newContext();
        await login(await subscriber.newPage(), 'qa_subscriber');
        for (const action of ['check_update', 'install_update', 'update_status']) assert.equal((await ajax(action, {}, subscriber)).status(), 403);
        await subscriber.close();
        for (const name of ['missing', 'network', 'limited']) {
            await mode(name); result = await check(); assert.equal(result.success, false, name); assert.ok(result.data.message);
        }
        await mode('no-asset'); result = await check();
        assert.equal(result.data.available, true); assert.equal(result.data.installable, false); assert.match(result.data.package_error, /ZIP/);
        await mode('update', { channel: 'beta' }); result = await check();
        assert.equal(result.data.latest, '99.0.0-beta.2');
        await mode('update', { channel: 'stable' }); await check();
        await page.reload();
        await page.locator('.csf-nav-options a').filter({ hasText: /^在线更新$/ }).click();
        await page.locator('.wuai-update[data-state="available"]').waitFor();
        assert.equal(await page.locator('.wuai-update-install').isVisible(), true);
        assert.equal(await page.evaluate(() => window.unsafeRelease), undefined, 'Release HTML is displayed as text');
        await page.screenshot({ path: path.join(out, 'update-available-desktop.png'), fullPage: true });
        const url = page.url();
        await mode('current');
        await page.locator('.wuai-update-check').click();
        await page.locator('.wuai-update[data-state="current"]').waitFor();
        assert.equal(page.url(), url, 'Check stays on current settings page');
        await page.screenshot({ path: path.join(out, 'update-current-desktop.png'), fullPage: true });
        await mode('network'); await page.locator('.wuai-update-check').click();
        await page.locator('.wuai-update[data-state="error"]').waitFor();
        await page.screenshot({ path: path.join(out, 'update-error.png'), fullPage: true });
        await mode('update');
        await page.locator('.wuai-update-check').click();
        await page.locator('.wuai-update[data-state="available"]').waitFor();
        await page.setViewportSize({ width: 390, height: 844 });
        await page.keyboard.press('Escape');
        await page.waitForFunction(() => document.querySelector('.csf-nav-options').getBoundingClientRect().left >= innerWidth);
        await page.screenshot({ path: path.join(out, 'update-mobile.png'), fullPage: true });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
        await page.setViewportSize({ width: 1500, height: 1000 });
        await mode('busy'); result = await install(); assert.equal(result.success, false); assert.equal(result.data.busy, true);
        await mode('stale'); result = await (await ajax('update_status')).json(); assert.equal(result.data.job.phase, 'error');
        for (const name of ['download-fail', 'digest', 'bad-directory', 'missing-file', 'bad-version', 'requirements', 'install-fail']) {
            await mode(name); result = await install();
            assert.equal(result.data.job.phase, 'error', name + ': ' + JSON.stringify(result));
            assert.equal(result.data.current, '1.0.8', name + ': old theme survives failed install');
            assert.deepEqual((await mode(name)).options, baseline, name + ': options retained');
        }
        await mode('slow');
        await page.locator('.wuai-update-check').click();
        await page.locator('.wuai-update[data-state="available"]').waitFor();
        let submissions = 0;
        page.on('request', req => { if ((req.postData() || '').includes('action=wuai_sluyu_install_update')) submissions++; });
        interruptPollOnce = !!process.env.WP_QA_POLL_URL;
        await page.locator('.wuai-update-install').evaluate(button => { button.click(); button.click(); });
        if (process.env.WP_QA_POLL_URL) {
            await page.locator('.wuai-update-progress[aria-valuenow="20"]').waitFor();
            assert.equal(await page.locator('.wuai-update-install').isDisabled(), true);
            await page.screenshot({ path: path.join(out, 'update-progress.png'), fullPage: true });
            await page.reload();
        }
        await page.locator('.wuai-update[data-state="complete"]').waitFor({ timeout: 90000 });
        assert.equal(submissions, 1, 'Double click submits once');
        assert.equal(await page.locator('.wuai-update-progress').getAttribute('aria-valuenow'), '100');
        assert.match(await page.locator('.wuai-update-current').textContent(), /99\.0\.0/);
        const finished = await mode('current');
        for (const phase of ['checking', 'downloading', 'unpacking', 'installing', 'complete']) assert.ok(finished.phases.includes(phase), phase);
        assert.deepEqual(finished.options, baseline);
        assert.ok(Object.values(finished.backups).some(b => b.reason === '在线更新至 99.0.0'));
        await page.screenshot({ path: path.join(out, 'update-complete.png'), fullPage: true });
        await page.reload();
        assert.match(await page.locator('.wuai-update-current').textContent(), /99\.0\.0/);
        await page.locator('.wuai-update[data-state="complete"]').waitFor();
        await page.unrouteAll({ behavior: 'wait' });
        assert.deepEqual(errors, []);
        console.log('PASS: real WP checks/cache/channels/auth/errors; real downloads/digest/package validation; failed replacement rollback; in-page install, progress, double-click, version and options retained; desktop/mobile.');
    } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
