'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const script = fs.readFileSync(path.join(__dirname, '../wuai-sluyu/assets/js/theme.js'), 'utf8');
const picture = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="80"><rect width="120" height="80" fill="pink"/></svg>');

async function main() {
  const browser = await chromium.launch({
    headless: true,
    ...(process.env.BROWSER_EXECUTABLE ? { executablePath: process.env.BROWSER_EXECUTABLE } : {}),
  });
  try {
    const page = await browser.newPage();
    let content = '';
    await page.route('http://wuai-theme.test/**', route => {
      if (route.request().url().endsWith('/missing.png')) return route.fulfill({ status: 404, body: '' });
      return route.fulfill({ contentType: 'text/html', body: content });
    });
    async function fixture(markup, enabled = '1') {
      content = `<!doctype html><html><head><style>
        .sluyu-lightbox{display:none;position:fixed;inset:0;background:#222c;z-index:100}
        .sluyu-lightbox.is-open{display:flex;align-items:center;justify-content:center}
        .sluyu-lightbox button{position:absolute;right:16px;top:16px}
        img{width:120px;height:80px}button,a{display:inline-block;padding:12px}
      </style></head><body data-sluyu-lightbox="${enabled}">${markup}<script>${script}</script></body></html>`;
      await page.goto('http://wuai-theme.test/', { waitUntil: 'load' });
    }
    async function isOpen() {
      return page.locator('.sluyu-lightbox.is-open').count();
    }

    await fixture(`<h1>Homepage title</h1><input aria-label="Search"><a href="#end">Navigation</a><article class="sluyu-card"><a href="#end"><img src="${picture}"></a></article><p id="end">Blank area</p>`);
    await page.locator('h1').click();
    await page.locator('input').click();
    await page.locator('p').click();
    await page.locator('article img').click();
    assert.equal(await page.locator('.sluyu-lightbox').count(), 0, 'The homepage must not create a preview overlay.');
    assert.equal(new URL(page.url()).hash, '#end', 'Card links must still navigate.');

    const article = `<button id="focus-return">Focus source</button><h1>Article title</h1>
      <figure class="sluyu-featured"><img id="featured" data-sluyu-lightbox="1" src="${picture}" alt="Featured"></figure>
      <div class="sluyu-entry" data-sluyu-lightbox="1"><p>Article paragraph</p>
        <img id="inline" data-sluyu-lightbox="1" src="${picture}" alt="Inline">
        <a href="${picture}"><img id="media-link" data-sluyu-lightbox="1" src="${picture}"></a>
        <a href="#details"><img id="custom-link" data-sluyu-lightbox="1" src="${picture}"></a>
        <img id="missing-source" data-sluyu-lightbox="1"><img id="broken" data-sluyu-lightbox="1" src="/missing.png">
      </div><p id="details">Details</p>`;
    await fixture(article);
    await page.locator('h1').click();
    await page.locator('.sluyu-entry p').click();
    assert.equal(await isOpen(), 0, 'Text and container markers must not trigger the preview.');
    await page.locator('#focus-return').focus();
    await page.locator('#inline').dispatchEvent('click');
    assert.equal(await isOpen(), 1);
    assert.equal(await page.locator('.sluyu-lightbox img').getAttribute('src'), picture);
    assert.equal(await page.locator('.sluyu-lightbox').getAttribute('aria-hidden'), 'false');
    await page.locator('.sluyu-lightbox img').click();
    assert.equal(await isOpen(), 1, 'Clicking the preview image must leave it open.');
    await page.locator('.sluyu-lightbox button').click();
    assert.equal(await isOpen(), 0, 'The close button must not reopen the overlay through bubbling.');
    assert.equal(await page.locator('.sluyu-lightbox img').getAttribute('src'), null);
    assert.equal(await page.locator('#focus-return').evaluate(el => el === document.activeElement), true);

    await page.locator('#featured').click();
    assert.equal(await isOpen(), 1);
    await page.locator('.sluyu-lightbox').click({ position: { x: 8, y: 8 } });
    assert.equal(await isOpen(), 0, 'Clicking the backdrop must close the preview.');
    await page.locator('#media-link').click();
    assert.equal(await isOpen(), 1);
    await page.keyboard.press('Escape');
    assert.equal(await isOpen(), 0, 'Escape must close the preview.');
    await page.locator('#custom-link').click();
    assert.equal(await isOpen(), 0);
    assert.equal(new URL(page.url()).hash, '#details', 'Custom image links must keep their destination.');
    for (const id of ['missing-source', 'broken']) {
      await page.locator('#' + id).click();
      assert.equal(await isOpen(), 0, id + ' must not open an empty preview.');
    }
    await page.locator('#inline').click();
    await page.locator('.sluyu-lightbox img').evaluate(el => el.dispatchEvent(new Event('error')));
    assert.equal(await isOpen(), 0, 'A failed preview load must close the overlay.');
    await fixture(article, '0');
    await page.locator('#inline').click();
    assert.equal(await page.locator('.sluyu-lightbox').count(), 0, 'Disabling previews must leave images untouched.');

    await page.setViewportSize({ width: 390, height: 844 });
    await fixture(article);
    await page.locator('#inline').click();
    assert.equal(await isOpen(), 1);
    await page.locator('.sluyu-lightbox button').click();
    assert.equal(await isOpen(), 0, 'The mobile close control must work.');
    console.log('PASS: ordinary clicks, card links, article previews, close/backdrop/Escape, focus, broken sources, disabled previews and mobile.');
  } finally {
    await browser.close();
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
