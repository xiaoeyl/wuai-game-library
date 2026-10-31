'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const source = path.resolve(process.env.WP_QA_SOURCE || path.join(__dirname, '..'));
const theme = path.join(source, 'wuai-sluyu');
const css = fs.readFileSync(path.join(theme, 'assets/css/theme.css'), 'utf8');
const controller = fs.readFileSync(path.join(theme, 'assets/js/page-loader.js'), 'utf8');
const out = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.0.9');
fs.mkdirSync(out, { recursive: true });
// A deliberately pending deferred script also keeps document.fonts.ready pending.
process.env.PW_TEST_SCREENSHOT_NO_FONTS_READY = '1';
const php = `define('ABSPATH', __DIR__); define('WUAI_SLUYU_DIR', $argv[1]);
function wuai_sluyu_option($key, $default = '') { global $argv; return $key === 'enable_loader' ? $argv[2] : '吾爱游戏资源'; }
function get_bloginfo($key) { return '吾爱游戏资源'; }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function wp_print_inline_script_tag($text, $attrs) { echo '<script id="'.$attrs['id'].'">'.$text.'</script>'; }
require WUAI_SLUYU_DIR.'/template-parts/page-loader.php';`;
function markup(enabled = '1') { return execFileSync('php', ['-r', php, theme, enabled], { encoding: 'utf8' }); }
const enabledMarkup = markup();
assert.equal(markup('0').trim(), '', 'Disabling the option removes both loader and controller');
assert.equal((enabledMarkup.match(/id="wuai-sluyu-loader-js"/g) || []).length, 1);

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE, headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    let html = '', waiting = [];
    async function release() { for (const done of waiting.splice(0)) await done(); }
    await context.route('http://wuai-loader.test/**', async route => {
      const url = route.request().url();
      if (url.endsWith('/block.js') || url.endsWith('/slow.svg')) {
        await new Promise(resolve => waiting.push(async () => {
          await route.fulfill({ contentType: url.endsWith('.js') ? 'application/javascript' : 'image/svg+xml', body: url.endsWith('.js') ? '' : '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>' }).catch(() => {});
          resolve();
        }));
      } else await route.fulfill({ contentType: 'text/html', body: html });
    });
    async function fixture({ block = false, slow = false, script = true, enabled = true, title = '' } = {}) {
      await release();
      let loader = enabled ? enabledMarkup : markup('0');
      if (!script) loader = loader.replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '');
      if (title) loader = loader.replace('吾爱游戏资源', title);
      html = '<!doctype html><html><head><meta charset="utf-8"><style>' + css + '</style></head><body data-sluyu-loader="' + (enabled ? '1' : '0') + '">' + loader + '<main><h1>网站内容</h1><button id="usable" onclick="this.textContent=\'已点击\'">页面按钮</button></main>' + (slow ? '<img src="/slow.svg">' : '') + (block ? '<script defer src="/block.js"></script>' : '') + '</body></html>';
      await page.goto('http://wuai-loader.test/', { waitUntil: block ? 'commit' : 'domcontentloaded' });
    }
    async function centered() {
      await page.locator('.sluyu-loader-orbit').waitFor();
      const positions = await page.evaluate(() => {
        const s = document.querySelector('.sluyu-loader-orbit').getBoundingClientRect();
        const t = document.querySelector('.sluyu-page-loader strong').getBoundingClientRect();
        return { spinnerX: s.x + s.width / 2, titleX: t.x + t.width / 2, centerY: (Math.min(s.top, t.top) + Math.max(s.bottom, t.bottom)) / 2, width: innerWidth, height: innerHeight, titleWidth: t.width };
      });
      assert.ok(Math.abs(positions.spinnerX - positions.width / 2) < 2, JSON.stringify(positions));
      assert.ok(Math.abs(positions.titleX - positions.width / 2) < 2, 'Title centered with spinner');
      assert.ok(Math.abs(positions.centerY - positions.height / 2) < 7, 'Whole loader vertically centered');
      assert.ok(positions.titleWidth <= positions.width - 48);
    }
    await fixture({ block: true });
    await centered();
    await page.screenshot({ path: path.join(out, 'loader-fixed-desktop.png') });
    await page.locator('#usable').click();
    assert.equal(await page.locator('#usable').innerText(), '已点击', 'Overlay never intercepts clicks');
    await release();
    await page.locator('.sluyu-page-loader').waitFor({ state: 'detached' });

    await page.setViewportSize({ width: 390, height: 844 });
    await fixture({ block: true });
    await centered();
    await page.screenshot({ path: path.join(out, 'loader-fixed-mobile.png') });
    await release();
    await fixture({ block: true, title: '这是非常长的站点名称'.repeat(8) });
    await centered();
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await release();

    await fixture({ slow: true });
    await page.locator('.sluyu-page-loader').waitFor({ state: 'detached' });
    assert.equal(await page.evaluate(() => document.readyState), 'interactive', 'Loader closes before slow images/window.load');
    await release();
    await fixture({ block: true });
    await page.locator('.sluyu-page-loader').waitFor({ state: 'detached', timeout: 3000 });
    assert.equal(await page.evaluate(() => document.readyState), 'interactive', 'Blocked defer bundle does not trap users');
    await release();

    await fixture({ script: false });
    await page.evaluate(controller);
    await page.locator('.sluyu-page-loader').waitFor({ state: 'detached', timeout: 1000 });
    await fixture({ block: true, script: false });
    await page.locator('.sluyu-page-loader').waitFor({ state: 'hidden', timeout: 3000 });
    assert.equal(await page.locator('.sluyu-page-loader').count(), 1, 'CSS escape works with missing controller');
    await release();

    await page.emulateMedia({ reducedMotion: 'reduce' });
    await fixture({ block: true });
    assert.equal(await page.locator('.sluyu-loader-orbit').evaluate(el => getComputedStyle(el).animationName), 'none');
    await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })));
    await page.locator('.sluyu-page-loader').waitFor({ state: 'detached' });
    await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    assert.equal(await page.locator('.sluyu-page-loader').count(), 0);
    await release();
    await page.emulateMedia({ reducedMotion: 'no-preference' });

    await fixture();
    for (let i = 0; i < 3; i++) {
      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.locator('.sluyu-page-loader').waitFor({ state: 'detached', timeout: 1000 });
    }
    await page.goto('http://wuai-loader.test/other');
    await page.goBack({ waitUntil: 'domcontentloaded' });
    await page.locator('.sluyu-page-loader').waitFor({ state: 'detached', timeout: 1000 });
    await fixture({ enabled: false });
    assert.equal(await page.locator('.sluyu-page-loader, #wuai-sluyu-loader-js').count(), 0);

    html = '<html><head><style>' + css + '</style></head><body>' + enabledMarkup + '<h1>无脚本可访问</h1></body></html>';
    const nojs = await browser.newContext({ javaScriptEnabled: false });
    const nojsPage = await nojs.newPage();
    await nojsPage.route('http://wuai-loader.test/**', route => route.fulfill({ contentType: 'text/html', body: html }));
    await nojsPage.goto('http://wuai-loader.test/');
    assert.equal(await nojsPage.locator('.sluyu-page-loader').isVisible(), false);
    assert.equal(await nojsPage.locator('h1').isVisible(), true);
    await nojs.close();
    assert.deepEqual(errors, []);
    await page.unrouteAll({ behavior: 'wait' });
    console.log('PASS: desktop/mobile centering, long title, no blocked clicks, DOM ready vs slow assets, 2-second fallback, late/missing/disabled JS, reduced motion, history cache, repeated refresh and setting off.');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
