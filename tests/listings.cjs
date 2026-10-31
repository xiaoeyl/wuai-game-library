'use strict';
// Run after listings-browser-fixture.php; only dedicated QA listing users mutate data.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8771';
assert.match(base, /^http:\/\/(127\.0\.0\.1|localhost):8771$/, 'Only use isolated listing fixtures.');
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'listings-fixture.json'), 'utf8'));
const url = (key, args = {}) => base + '/?' + new URLSearchParams({ sluyu_page: key, ...args });
const postUrl = record => new URL(record.url, base).href;
const marker = 'Browser pending listing ' + Date.now();
const revision = 'Browser revised listing ' + Date.now();

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  const contexts = []; const errors = [];
  try {
    async function contextFor(name) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' }); contexts.push(context);
      const page = await context.newPage(); page.on('pageerror', error => errors.push((name || 'guest') + ': ' + error.message));
      if (name) {
        await page.goto(base + '/wp-login.php');
        await page.locator('#user_login').fill(fixture.login_prefix + name); await page.locator('#user_pass').fill('qa-local-only');
        await Promise.all([page.waitForURL(location => !location.pathname.includes('wp-login.php')), page.locator('#wp-submit').click()]);
      }
      await page.goto(url('listings'));
      const nonce = await page.evaluate(() => wuaiSluyu.nonce);
      return { context, page, action: async (operation, data = {}) => {
        const response = await context.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'wuai_sluyu_community', nonce, operation, ...data } });
        return { status: response.status(), ...(await response.json()) };
      } };
    }
    async function clickAction(page, button, operation, reload, confirmation = false) {
      if (confirmation) page.once('dialog', dialog => dialog.accept());
      const responsePromise = page.waitForResponse(response => response.url().includes('/wp-admin/admin-ajax.php') && new URLSearchParams(response.request().postData() || '').get('operation') === operation).then(value => ({ value }), error => ({ error }));
      const loadPromise = reload ? page.waitForEvent('load').then(value => ({ value }), error => ({ error })) : null;
      try {
        await button.click(); const response = await responsePromise;
        if (response.error) throw response.error;
        assert.equal(response.value.status(), 200, operation + ' HTTP response');
        if (loadPromise) { const loaded = await loadPromise; if (loaded.error) throw loaded.error; }
        else {
          await page.waitForFunction(op => { const form = document.querySelector('form[data-operation="' + op + '"]'); return form && !form.dataset.busy && form.querySelector('.sluyu-form-status').textContent.length > 0; }, operation);
        }
      } catch (error) {
        const status = await page.locator('.sluyu-form-status, .sluyu-toast').allTextContents().catch(() => []);
        throw new Error(operation + ': ' + error.message + '\nUI status: ' + JSON.stringify(status));
      }
    }
    async function titles(page) { return page.locator('.sluyu-listings > article h2').allTextContents(); }
    async function approve(admin, record) {
      await admin.page.goto(base + '/wp-admin/post.php?post=' + record.id + '&action=edit');
      assert.equal(await admin.page.locator('input[name=wuai_listing_nonce]').count(), 1, 'Native listing editor exposes protected metadata nonce.');
      assert.equal(await admin.page.locator('#title').inputValue(), record.title);
      await admin.page.locator('select[name=wuai_listing_type]').selectOption('demand');
      await admin.page.locator('select[name=wuai_listing_category]').selectOption('service');
      await admin.page.locator('select[name=wuai_listing_state]').selectOption('open');
      await admin.page.locator('input[name=wuai_listing_deadline]').fill(fixture.deadline);
      await Promise.all([admin.page.waitForURL(location => location.pathname.endsWith('/post.php') && location.searchParams.has('message')), admin.page.locator('#publish').click()]);
      assert.match(await admin.page.locator('#post-status-display').innerText(), /已发布|Published/i);
    }
    const guest = await contextFor(); const owner = await contextFor('owner'); const writer = await contextFor('writer'); const other = await contextFor('other'); const admin = await contextFor('admin');
    const publicFilter = { listing_type: 'supply', listing_category: 'game', listing_state: 'open' };
    await guest.page.goto(url('listings', publicFilter));
    let allTitles = [];
    const pageCount = Math.ceil(fixture.public_total / 12);
    for (let page = 1; page <= pageCount; page++) {
      const list = await titles(guest.page); allTitles.push(...list);
      assert.equal(list.length, Math.min(12, fixture.public_total - (page - 1) * 12), 'Exact public filter page size.');
      if (page < pageCount) {
        await guest.page.locator('.sluyu-portal-pagination a.next').click();
        const next = new URL(guest.page.url());
        for (const [key, value] of Object.entries(publicFilter)) assert.equal(next.searchParams.get(key), value, 'Pagination retains ' + key);
      }
    }
    assert.deepEqual(allTitles, fixture.public_titles);
    assert.equal(new Set(allTitles).size, allTitles.length, 'No repeated listing across pages.');
    await owner.page.goto(url('listings', { scope: 'mine', listing_state: 'all' }));
    assert.equal((await titles(owner.page)).length, 12);
    let mineTitles = await titles(owner.page);
    await owner.page.locator('.sluyu-portal-pagination a.next').click();
    mineTitles.push(...await titles(owner.page));
    assert.equal(mineTitles.length, fixture.mine_total); assert.equal(new Set(mineTitles).size, fixture.mine_total);
    assert.equal(mineTitles.includes(fixture.posts['Private Secret'].title), false, 'Private posts excluded even from owner mine list.');
    assert.equal(mineTitles.includes(fixture.posts['Foreign Pending Secret'].title), false);
    assert.equal(mineTitles.includes(fixture.posts.Approval.title), true, 'Own pending is visible.');
    assert.equal(mineTitles.includes(fixture.posts.Draft.title), true, 'Own draft is visible.');
    await guest.page.goto(url('listings', { scope: 'mine' })); assert.equal(await guest.page.locator('.sluyu-login-gate').count(), 1);

    await writer.page.goto(url('po-infomation'));
    const submit = writer.page.locator('form[data-operation=listing_submit]');
    assert.equal(await submit.count(), 1, 'Legacy po-infomation alias opens listing-specific form.');
    assert.equal(await writer.page.locator('form[data-operation=submit]').count(), 0);
    await submit.locator('input[name=title]').fill(marker);
    await submit.locator('textarea[name=body]').fill('Browser-only harmless demand information. No payment or real trade is performed.');
    await submit.locator('select[name=listing_type]').selectOption('demand');
    await submit.locator('select[name=listing_category]').selectOption('other');
    await submit.locator('input[name=listing_deadline]').fill(fixture.deadline);
    await clickAction(writer.page, submit.locator('[type=submit]'), 'listing_submit', false);
    assert.match(await submit.locator('.sluyu-form-status').innerText(), /待审核/);
    assert.equal(await submit.locator('input[name=title]').inputValue(), '', 'Successful create resets form.');
    await writer.page.goto(url('listings', { scope: 'mine', listing_state: 'all' }));
    assert.deepEqual(await titles(writer.page), [marker]);
    assert.match(await writer.page.locator('.sluyu-listings > article').innerText(), /审核中/);
    const editUrl = await writer.page.locator('.sluyu-listings > article .sluyu-button-row a').getAttribute('href');
    const createdId = new URL(editUrl, base).searchParams.get('listing_id');
    assert.match(createdId, /^[1-9]\d*$/);
    await guest.page.goto(url('listings', { listing_type: 'demand', listing_category: 'other', listing_state: 'all' }));
    assert.equal((await guest.page.locator('.sluyu-portal-content').innerText()).includes(marker), false, 'Guest directory cannot expose new pending title.');
    await other.page.goto(url('submit', { kind: 'listing', listing_id: createdId }));
    assert.equal(await other.page.locator('form[data-operation=listing_resubmit]').count(), 0);
    assert.equal((await other.page.locator('.sluyu-portal-content').innerText()).includes(marker), false, 'Foreign editing form does not leak pending content.');
    assert.equal((await other.action('listing_status', { id: createdId, decision: 'resolved' })).success, false);

    await approve(admin, fixture.posts.Approval);
    await guest.page.goto(postUrl(fixture.posts.Approval));
    assert.equal(await guest.page.locator('.sluyu-listing-detail .sluyu-action-primary').count(), 1, 'Admin-approved listing has login contact CTA.');
    await owner.page.goto(postUrl(fixture.posts.Approval));
    await clickAction(owner.page, owner.page.locator('.sluyu-listing-detail [data-community-action=listing_status]'), 'listing_status', true, true);
    assert.match(await owner.page.locator('.sluyu-listing-detail .sluyu-state-pill').innerText(), /已解决/);
    await guest.page.goto(postUrl(fixture.posts.Approval));
    assert.equal(await guest.page.locator('.sluyu-listing-detail .sluyu-action-primary').count(), 0, 'Resolved closes contact CTA.');
    assert.equal((await owner.action('listing_status', { id: fixture.posts.Approval.id, decision: 'open' })).success, false, 'Direct reopen denied.');
    await owner.page.goto(url('submit', { kind: 'listing', listing_id: fixture.posts.Approval.id }));
    const resubmit = owner.page.locator('form[data-operation=listing_resubmit]');
    assert.match(await resubmit.locator('input[name=version]').inputValue(), /^[a-f0-9]{64}$/);
    await resubmit.locator('input[name=title]').fill(revision);
    await resubmit.locator('textarea[name=body]').fill('A reviewed listing update. Opening again must wait for a new administrator review.');
    await resubmit.locator('input[name=listing_deadline]').fill(fixture.deadline);
    // Different owner from the create flow: no arbitrary sleeps for the 20s rate limiter.
    await clickAction(owner.page, resubmit.locator('[type=submit]'), 'listing_resubmit', true);
    assert.equal(await owner.page.locator('form[data-operation=listing_resubmit] input[name=title]').inputValue(), revision);
    await owner.page.goto(url('listings', { scope: 'mine', listing_type: 'demand', listing_category: 'service', listing_state: 'all' }));
    assert.deepEqual(await titles(owner.page), [revision]);
    assert.match(await owner.page.locator('.sluyu-listings > article').innerText(), /审核中/);
    const hiddenResponse = await guest.page.goto(postUrl(fixture.posts.Approval));
    assert.equal(hiddenResponse.status(), 404, 'Resubmitted listing is no longer public.');
    assert.equal((await guest.page.locator('body').innerText()).includes(revision), false);

    await guest.page.goto(postUrl(fixture.posts.Expired));
    assert.match(await guest.page.locator('.sluyu-listing-detail .sluyu-state-pill').innerText(), /已过期/);
    assert.equal(await guest.page.locator('.sluyu-listing-detail .sluyu-action-primary').count(), 0, 'Stored open but past deadline closes CTA without cron.');
    await guest.page.goto(url('listings', { listing_type: 'demand', listing_category: 'material', listing_state: 'expired' }));
    assert.ok((await titles(guest.page)).includes(fixture.posts.Expired.title));
    await guest.page.goto(url('listings', publicFilter));
    await guest.page.screenshot({ path: path.join(output, 'listings-desktop.png'), fullPage: true, animations: 'disabled' });
    for (const width of [390, 320]) {
      await guest.page.setViewportSize({ width, height: 844 }); await owner.page.setViewportSize({ width, height: 844 });
      for (const target of [url('listings', publicFilter), postUrl(fixture.posts.Expired)]) {
        await guest.page.goto(target); assert.equal(await guest.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, target + ' at ' + width);
      }
      for (const target of [url('listings', { scope: 'mine', listing_state: 'all' }), url('po-infomation'), url('submit', { kind: 'listing', listing_id: fixture.posts.Approval.id })]) {
        await owner.page.goto(target); assert.equal(await owner.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, target + ' at ' + width);
      }
      if (width === 390) await owner.page.screenshot({ path: path.join(output, 'listings-submit-mobile.png'), fullPage: true, animations: 'disabled' });
    }
    assert.deepEqual(errors, []);
    console.log('PASS: listings browser create/pending isolation, native admin approval, resolved/review lifecycle, realtime expiry, ownership, exact filtered/mine pagination, legacy route and 320/390 layouts.');
  } finally { for (const context of contexts) await context.close(); await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
