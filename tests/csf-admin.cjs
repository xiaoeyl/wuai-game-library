// Integration checks against an isolated WordPress installation. Never use a live site.
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const base = process.env.WP_QA_URL || 'http://127.0.0.1:8767';
assert.match(base, /^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/, 'Use an isolated local WordPress site.');
const output = process.env.WP_QA_OUTPUT || path.resolve('dist/checks-v1.0.6');
fs.mkdirSync(output, { recursive: true });

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE, headless: true });
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(base + '/wp-login.php');
        await page.locator('#user_login').fill(process.env.WP_QA_USER || 'qa_admin');
        await page.locator('#user_pass').fill(process.env.WP_QA_PASSWORD || 'qa-local-only');
        await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);
        const settings = base + '/wp-admin/admin.php?page=wuai-sluyu';
        await page.goto(settings);
        const panel = page.locator('.csf-options.wuai-csf-settings');
        await panel.waitFor();
        assert.equal(await page.locator('.wuai-admin-wrap').count(), 0);
        const nav = panel.locator('.csf-nav-options');
        async function tab(parent, child) {
            const parentLink = nav.locator('a').filter({ hasText: new RegExp('^' + parent + '$') });
            if (!child || !(await nav.locator('a').filter({ hasText: new RegExp('^' + child + '$') }).isVisible())) await parentLink.click();
            if (child) await nav.locator('a').filter({ hasText: new RegExp('^' + child + '$') }).click();
        }
        const field = id => panel.locator('[name="wuai_sluyu_options[' + id + ']"]');
        const exportUrl = await panel.locator('.csf-export').getAttribute('href');
        const exported = async () => {
            const response = await context.request.get(exportUrl);
            assert.equal(response.status(), 200);
            return response.json();
        };
        const baseline = await exported();
        assert.equal(await field('site_title').inputValue(), baseline.site_title, 'Existing options survive framework initialization.');
        async function save() {
            const responsePromise = page.waitForResponse(response => response.url().includes('admin-ajax.php') && response.request().postData()?.includes('csf_wuai_sluyu_options_ajax_save'));
            await panel.locator('.csf-header .csf-save').click();
            const response = await responsePromise;
            const data = await response.json();
            assert.equal(data.success, true, JSON.stringify(data));
            await page.waitForFunction(() => !document.querySelector('.csf-options').classList.contains('csf-saving'));
        }
        await tab('基础设置', '站点信息');
        await page.screenshot({ path: path.join(output, 'csf-desktop.png'), fullPage: true });
        await field('site_title').fill('CSF 保存持久化测试');
        await save();
        await page.reload();
        assert.equal(await field('site_title').inputValue(), 'CSF 保存持久化测试');
        await tab('文章与资源');
        const switcher = field('show_post_meta').locator('..');
        if (await switcher.evaluate(el => el.classList.contains('csf--active'))) await switcher.click();
        await save();
        await page.reload();
        assert.equal(await field('show_post_meta').inputValue(), '0', 'A disabled switch stays disabled after reload.');
        await tab('文章与资源');
        await field('show_post_meta').locator('..').click();
        await save();
        assert.equal((await exported()).show_post_meta, '1');

        await tab('界面美化', '颜色与布局');
        const picker = panel.locator('.csf-field-color').first();
        await picker.locator('.wp-color-result').click();
        assert.equal(await picker.locator('.iris-picker').isVisible(), true, 'Native WordPress color picker opens.');
        await field('primary_color').fill('#234abc');
        await field('primary_color').dispatchEvent('change');
        await save();
        assert.equal((await exported()).primary_color, '#234abc');

        await tab('基础设置', '站点信息');
        await field('site_logo').locator('..').locator('.csf--button').click();
        await page.locator('.media-modal').waitFor({ state: 'visible' });
        await page.locator('.media-modal-close').click();
        await page.locator('.media-modal').waitFor({ state: 'hidden' });

        const nonce = await panel.locator('[name="csf_options_noncewuai_sluyu_options"]').inputValue();
        const backupNonce = await panel.locator('.csf-import').getAttribute('data-nonce');
        async function ajax(form) {
            return context.request.post(base + '/wp-admin/admin-ajax.php', { form });
        }
        let result = await ajax({ action: 'csf_wuai_sluyu_options_ajax_save', data: JSON.stringify({ csf_options_noncewuai_sluyu_options: 'invalid', wuai_sluyu_options: { site_title: 'invalid nonce' } }) });
        assert.equal((await result.json()).success, false);
        result = await ajax({ action: 'csf-import', unique: 'blogname', nonce: backupNonce, data: '{"site_title":"cannot write arbitrary options"}' });
        assert.equal(result.status(), 403);

        const subscriber = await browser.newContext();
        const loginPage = await subscriber.newPage();
        await loginPage.goto(base + '/wp-login.php');
        await loginPage.locator('#user_login').fill('qa_subscriber');
        await loginPage.locator('#user_pass').fill('qa-local-only');
        await Promise.all([loginPage.waitForURL(url=>!url.pathname.includes('wp-login.php')), loginPage.locator('#wp-submit').click()]);
        for (const form of [
            { action: 'csf_wuai_sluyu_options_ajax_save', data: JSON.stringify({ csf_options_noncewuai_sluyu_options: nonce, wuai_sluyu_options: { site_title: 'subscriber denied' } }) },
            { action: 'csf-import', unique: 'wuai_sluyu_options', nonce: backupNonce, data: JSON.stringify(baseline) },
            { action: 'csf-reset', unique: 'wuai_sluyu_options', nonce: backupNonce },
        ]) {
            const response = await subscriber.request.post(base + '/wp-admin/admin-ajax.php', { form });
            assert.equal(response.status(), 403);
        }
        assert.equal((await subscriber.request.get(exportUrl)).status(), 403);
        await subscriber.close();

        await tab('备份与恢复');
        await panel.locator('.wuai-csf-history-action[data-operation="backup"]').click();
        await page.waitForURL(/settings-history=backup/);
        await tab('基础设置', '站点信息');
        await field('site_title').fill('恢复前的临时标题');
        await save();
        await page.reload();
        await tab('备份与恢复');
        page.once('dialog', dialog => dialog.accept());
        await panel.locator('.wuai-csf-history-action[data-operation="restore"]').first().click();
        await page.waitForURL(/settings-history=restore/);
        assert.equal((await exported()).site_title, 'CSF 保存持久化测试');

        const imported = { ...baseline, site_title: '<script>bad()</script>安全标题', enable_particles: '0', primary_color: 'invalid', injected_key: 'discard' };
        result = await ajax({ action: 'csf-import', unique: 'wuai_sluyu_options', nonce: backupNonce, data: JSON.stringify(imported) });
        assert.equal((await result.json()).success, true);
        const sanitized = await exported();
        assert.equal(sanitized.site_title.includes('<'), false);
        assert.equal(sanitized.enable_particles, '0');
        assert.equal(sanitized.primary_color, '#ef0053');
        assert.equal('injected_key' in sanitized, false);
        await page.reload();
        await tab('备份与恢复');
        page.once('dialog', dialog => dialog.accept());
        await Promise.all([page.waitForNavigation(), panel.locator('.csf-field-backup .csf-reset').click()]);
        assert.equal((await exported()).site_title, '吾爱游戏资源');
        await tab('备份与恢复');
        await panel.locator('.csf-import-data').fill(JSON.stringify(baseline));
        page.once('dialog', dialog => dialog.accept());
        await Promise.all([page.waitForNavigation(), panel.locator('.csf-import').click()]);
        assert.deepEqual(await exported(), baseline, 'Export/import round-trip preserves all settings.');

        await page.reload();
        await panel.locator('.csf-search input').fill('热搜关键词');
        await field('hot_words').waitFor({ state: 'visible' });
        assert.equal(await field('footer_text').isVisible(), false);
        await panel.locator('.csf-search input').fill('');
        await tab('基础设置', '站点信息');
        await page.setViewportSize({ width: 390, height: 844 });
        await page.reload();
        await panel.locator('.csf-header .csf-menu').click();
        assert.equal(await nav.evaluate(el => el.classList.contains('show')), true);
        await nav.locator('a').filter({ hasText: /^SEO 设置$/ }).click();
        assert.equal(await nav.evaluate(el => el.classList.contains('show')), false);
        await field('seo_description').waitFor({ state: 'visible' });
        await page.waitForFunction(() => document.querySelector('.csf-nav-options').getBoundingClientRect().left >= window.innerWidth);
        await page.waitForFunction(() => Number(getComputedStyle(document.querySelector('.csf-content')).opacity) > 0.99);
        await page.screenshot({ path: path.join(output, 'csf-mobile.png'), fullPage: true });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, 'No horizontal overflow on mobile.');
        assert.deepEqual(errors, [], 'No JavaScript runtime errors.');
        console.log('PASS: actual CSF, retained settings, AJAX persistence, switches, colors, media, permissions/nonces, search, backup/restore/import/reset, mobile menu.');
        await context.close();
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
