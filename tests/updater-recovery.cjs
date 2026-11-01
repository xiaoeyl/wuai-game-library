'use strict';
// Deterministic browser recovery checks; all updater AJAX is intercepted locally.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8771';
assert.match(base, /^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/);
const job = (phase, age = 200) => ({ id: 'qa-recovery-job-123', phase, percent: phase === 'complete' ? 100 : 20, message: '测试进程状态', target: '1.6.1', updated_at: Math.floor(Date.now() / 1000) - age });
const release = { current: '1.6.0', latest: '1.6.1', available: true, installable: true, channel: '正式版', checked_at: '本地测试', notes: '测试版本', url: 'https://github.com/xiaoeyl/wuai-game-library/releases/tag/v1.6.1' };
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
    try {
        const context = await browser.newContext();
        const login = await context.newPage();
        await login.goto(base + '/wp-login.php');
        await login.locator('#user_login').fill('qa_admin');
        await login.locator('#user_pass').fill('qa-local-only');
        await Promise.all([login.waitForURL(url => !url.pathname.includes('wp-login.php')), login.locator('#wp-submit').click()]);
        await login.close();
        const errors = [];
        async function scenario(name, snapshot, remembered = '', statusCode = 200) {
            const page = await context.newPage();
            const calls = [];
            page.on('pageerror', error => errors.push(name + ': ' + error.message));
            await page.addInitScript(id => {
                sessionStorage.removeItem('wuai-update-job');
                if (id) sessionStorage.setItem('wuai-update-job', id);
            }, remembered);
            await page.route('**/wp-admin/admin-ajax.php', async route => {
                const action = new URLSearchParams(route.request().postData() || '').get('action');
                if (!action || !action.startsWith('wuai_sluyu_')) return route.continue();
                calls.push(action);
                assert.notEqual(action, 'wuai_sluyu_abandon_update', 'Browser must never cancel a server job');
                assert.notEqual(action, 'wuai_sluyu_install_update', 'Recovery must never automatically install');
                if (action === 'wuai_sluyu_update_status') {
                    return route.fulfill({ status: statusCode, contentType: 'application/json', body: JSON.stringify(statusCode === 200 ? { success: true, data: snapshot } : { success: false, data: { message: '当前账号没有更新主题的权限。' } }) });
                }
                assert.equal(action, 'wuai_sluyu_check_update');
                return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: release }) });
            });
            const hash = encodeURIComponent('扩展增强').toLowerCase() + '/' + encodeURIComponent('文档更新').toLowerCase();
            await page.goto(base + '/wp-admin/admin.php?page=wuai-sluyu#tab=' + hash);
            await page.locator('.wuai-update:not([data-state="idle"]):not([data-state="checking"])').waitFor();
            return { page, calls };
        }
        for (const [name, snapshot] of [
            ['abandoned 20%', { job: { ...job('error', 10000), message: '上次进程结束' }, active: false, current: '1.6.0' }],
            ['no job', { job: [], active: false, current: '1.6.0' }],
            ['old terminal', { job: job('complete', 999), active: false, current: '1.6.0' }],
        ]) {
            const { page, calls } = await scenario(name, snapshot);
            await page.locator('.wuai-update[data-state="available"]').waitFor();
            assert.equal(await page.locator('.wuai-update-progress-wrap').isVisible(), false, name);
            assert.equal(await page.locator('.wuai-update-install').isDisabled(), false, name);
            assert.ok(calls.includes('wuai_sluyu_check_update'));
            await page.close();
        }
        for (const [name, snapshot] of [
            ['slow worker', { job: job('downloading', 350), active: true, current: '1.6.0' }],
            ['no record yet', { job: [], active: true, current: '1.6.0' }],
            ['disk header replaced', { job: job('installing'), active: true, current: '1.6.1' }],
            ['terminal cleanup', { job: job('complete'), active: true, current: '1.6.1' }],
        ]) {
            const { page, calls } = await scenario(name, snapshot);
            await page.locator('.wuai-update[data-state="installing"]').waitFor();
            assert.equal(await page.locator('.wuai-update-check').isDisabled(), true, name);
            assert.equal(await page.locator('.wuai-update').getAttribute('aria-busy'), 'true');
            await page.waitForFunction(() => document.querySelector('.wuai-update-check').disabled);
            assert.equal(calls.includes('wuai_sluyu_check_update'), false, name + ': no check during live install');
            assert.equal(Number(await page.locator('.wuai-update-progress').getAttribute('aria-valuenow')) < 100, true, name + ': no premature success');
            await page.close();
        }
        for (const phase of ['complete', 'error']) {
            const { page, calls } = await scenario('same-session ' + phase, { job: job(phase), active: false, current: '1.6.0' }, 'qa-recovery-job-123');
            await page.locator('.wuai-update[data-state="' + phase + '"]').waitFor();
            assert.equal(await page.evaluate(() => sessionStorage.getItem('wuai-update-job')), null);
            assert.equal(calls.includes('wuai_sluyu_check_update'), false);
            await page.close();
        }
        const denied = await scenario('permission denied', {}, '', 403);
        await denied.page.locator('.wuai-update[data-state="error"]').waitFor();
        assert.equal(await denied.page.locator('.wuai-update').getAttribute('aria-busy'), 'false');
        assert.equal(denied.calls.filter(action => action === 'wuai_sluyu_update_status').length, 1, 'No endless permission retry');
        await denied.page.close();
        assert.deepEqual(errors, []);
        await context.close();
        console.log('PASS: stale 20%, old terminal, live slow worker, record-less lock, matching header, cleanup, session results, permission denial; no cancellation or auto-install.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
