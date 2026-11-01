'use strict';
// Local-only, read-only route/navigation checks; saving is covered by csf-admin.cjs.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8771';
assert.match(base, /^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/);
const out = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.6.0/admin-navigation');
fs.mkdirSync(out, { recursive: true });
const settings = base + '/wp-admin/admin.php?page=wuai-sluyu';
function slug(title) { return encodeURIComponent(title.replace(/&/g, '').replace(/ /g, '-')).replace(/%[0-9A-F]{2}/g, value => value.toLowerCase()); }
const tabId = (parent, child) => slug(parent) + '/' + slug(child);
(async () => {
    const browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE, headless: true });
    try {
        const context = await browser.newContext({ viewport: { width: 1500, height: 1080 }, reducedMotion: 'reduce' });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(base + '/wp-login.php');
        await page.locator('#user_login').fill('qa_admin');
        await page.locator('#user_pass').fill('qa-local-only');
        await Promise.all([page.waitForURL(url => !url.pathname.includes('wp-login.php')), page.locator('#wp-submit').click()]);
        await page.goto(settings);
        const panel = page.locator('.wuai-csf-settings');
        await panel.waitFor();
        assert.deepEqual(await panel.locator('.csf-nav-options > ul > li > a').allTextContents(), ['全局&功能', '页面&显示', '文章&列表', '功能&权限', '用户&互动', '支付&付费', '商城&商品', '社区&论坛', '扩展&增强']);
        assert.equal(await panel.locator('.csf-nav-options > ul > li > ul > li > a').count(), 29);
        const links = await panel.locator('.csf-nav-options > ul > li > ul > li > a').evaluateAll(items => items.map(item => ({ href: item.getAttribute('href'), title: item.textContent })));
        for (const link of links) {
            await page.evaluate(hash => { window.location.hash = hash; }, link.href);
            await panel.locator('.csf-section:not(.hidden)').filter({ has: page.locator('.csf-section-title h3').filter({ hasText: link.title }) }).waitFor();
            assert.equal(await panel.locator('.csf-section:not(.hidden)').count(), 1, link.title);
        }
        // Bookmarks from the released flat sidebar must activate the new child.
        for (const [old, parent, child] of [
            ['在线更新', '扩展&增强', '文档&更新'], ['备份与恢复', '扩展&增强', '备份&导入'],
            ['基础设置/站点信息', '全局&功能', '站点信息'], ['首页布局/栏目与广告', '页面&显示', '首页配置'],
            ['支付&付费/积分与签到', '用户&互动', '积分与签到'],
        ]) {
            await page.goto(settings + '#tab=' + old);
            await panel.locator('.csf-section:not(.hidden)').filter({ has: page.locator('.csf-section-title h3').filter({ hasText: child }) }).waitFor();
            assert.equal(new URL(page.url()).hash, '#tab=' + tabId(parent, child), old);
        }
        await page.goto(settings + '#tab=%E6%89%A9%E5%B1%95%E5%A2%9E%E5%BC%BA%2F%E5%A4%87%E4%BB%BD%E5%AF%BC%E5%85%A5');
        await panel.locator('.csf-section:not(.hidden)').filter({ has: page.locator('.csf-section-title h3').filter({ hasText: '备份&导入' }) }).waitFor();
        await page.goto(settings + '#tab=unknown-legacy');
        await panel.locator('.csf-section:not(.hidden)').filter({ has: page.locator('.csf-section-title h3').filter({ hasText: '主题概览' }) }).waitFor();
        await page.waitForFunction(() => Number(getComputedStyle(document.querySelector('.csf-section:not(.hidden)')).opacity) > 0.99);
        await page.screenshot({ path: path.join(out, 'admin-overview-desktop.png'), fullPage: true });
        await page.goto(settings + '#tab=' + tabId('社区&论坛', '圈子&审核'));
        await panel.locator('[data-module="circles"]').waitFor({ state: 'visible' });
        await page.screenshot({ path: path.join(out, 'admin-circles-desktop.png'), fullPage: true });
        for (const target of ['wuai-sluyu-services', 'wuai-sluyu-announcements', 'wuai-sluyu-invitations', 'wuai-circle-review-settings', 'wuai-sluyu-bounty-review']) {
            const response = await context.request.get(base + '/wp-admin/admin.php?page=' + target);
            assert.equal(response.status(), 200, target);
            assert.doesNotMatch(await response.text(), /Fatal error|There has been a critical error/);
        }
        await page.goto(settings + '#tab=' + tabId('页面&显示', '小工具&菜单'));
        await panel.locator('[data-module="widgets"]').waitFor({ state: 'visible' });
        assert.equal(await panel.locator('[data-module="widgets"] a[href$="widgets.php"]').count(), 1);
        for (const width of [390, 320]) {
            await page.setViewportSize({ width, height: 844 });
            await page.reload();
            const menu = panel.locator('.csf-header .csf-menu');
            await menu.focus();
            await page.keyboard.press('Enter');
            assert.equal(await menu.getAttribute('aria-expanded'), 'true');
            await page.screenshot({ path: path.join(out, 'admin-menu-' + width + '.png'), fullPage: true });
            await page.keyboard.press('Escape');
            assert.equal(await menu.getAttribute('aria-expanded'), 'false');
            await page.waitForFunction(() => document.querySelector('.csf-nav-options').getBoundingClientRect().left >= innerWidth);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, width + ': no horizontal overflow');
            await page.screenshot({ path: path.join(out, 'admin-widgets-' + width + '.png'), fullPage: true });
        }
        assert.deepEqual(errors, [], 'No JavaScript exceptions');
        await context.close();
        console.log('PASS: 9 groups / 29 live sections, real management routes, legacy/encoded/missing bookmarks, accessible mobile menu, 320/390/1500 layouts.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
