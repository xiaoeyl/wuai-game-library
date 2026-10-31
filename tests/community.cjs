'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.WP_QA_URL || 'http://127.0.0.1:8771';
assert.match(base, /^http:\/\/(127\.0\.0\.1|localhost):8771$/, 'Only use the isolated community fixture.');
const output = path.resolve(process.env.WP_QA_OUTPUT || 'dist/checks-v1.1.0');
const fixture = JSON.parse(fs.readFileSync(path.join(output, 'fixture.json'), 'utf8'));
const url = key => base + '/?sluyu_page=' + encodeURIComponent(key);
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE });
  try {
    const guest = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    const page = await guest.newPage(); const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    for (const key of fixture.routes) {
      const response = await page.goto(url(key));
      assert.equal(response.status(), 200, key);
      assert.equal(await page.locator('.sluyu-portal-hero h1').count(), 1, key);
      assert.equal((await page.locator('body').innerText()).includes('Fatal error'), false, key);
    }
    for (const key of ['member','login','protected']) {
      const html = await (await guest.request.get(base + '/?p=' + fixture.posts[key])).text();
      assert.equal(html.includes(key + '-secret.zip'), false, 'Anonymous download isolation: ' + key);
      assert.equal(html.includes(key + '-secret-code'), false, 'Anonymous code isolation: ' + key);
    }
    await page.goto(url('more')); await page.screenshot({ path: path.join(output, 'services-desktop.png'), fullPage: true });
    await page.goto(url('tools'));
    const tool = page.locator('[data-local-tool=text]');
    await tool.locator('textarea').fill('A\nA\nB'); await tool.locator('select').selectOption('dedupe'); await tool.locator('button').click();
    assert.equal(await tool.locator('output').innerText(), 'A\nB');
    await tool.locator('select').selectOption('json'); await tool.locator('textarea').fill('<script>'); await tool.locator('button').click();
    assert.match(await tool.locator('output').innerText(), /处理失败/);
    await page.locator('[data-local-tool=password] button').click(); assert.equal((await page.locator('[data-local-tool=password] output').innerText()).length, 20);
    await page.locator('[data-local-tool=qr] textarea').fill('https://example.test/'); await page.locator('[data-local-tool=qr] button').click();
    assert.match(await page.locator('[data-qr-download]').getAttribute('href'), /^data:image\/png/);
    await page.goto(url('document')); await page.locator('[data-help-search]').fill('忘记密码'); assert.equal(await page.locator('.sluyu-faq:visible').count(), 1);
    const token = await page.evaluate(() => wuaiSluyu.nonce);
    let response = await guest.request.post(base + '/wp-admin/admin-ajax.php', { form: { action:'wuai_sluyu_community',nonce:token,operation:'checkin' } });
    assert.equal(response.status(),401);
    async function member(login) {
      const context=await browser.newContext({reducedMotion:'reduce'}); const p=await context.newPage();
      await p.goto(base+'/wp-login.php'); await p.locator('#user_login').fill(login); await p.locator('#user_pass').fill('qa-local-only');
      await Promise.all([p.waitForURL(location=>!location.pathname.includes('wp-login.php')),p.locator('#wp-submit').click()]); await p.goto(url('account'));
      const nonce=await p.evaluate(()=>wuaiSluyu.nonce);
      return { context, page:p, action:async(operation, data={})=>{ const r=await context.request.post(base+'/wp-admin/admin-ajax.php',{form:{action:'wuai_sluyu_community',nonce,operation,...data}}); return {status:r.status(),...(await r.json())}; } };
    }
    const a=await member('qa_member_a'), b=await member('qa_member_b'), c=await member('qa_member_c');
    let data=await a.action('checkin'); assert.equal(data.success,true,JSON.stringify(data)); const days=data.data.days, points=data.data.points;
    const repeated=await Promise.all(Array.from({length:4},()=>a.action('checkin'))); for(const result of repeated){ assert.equal(result.data.days,days); assert.equal(result.data.points,points); }
    assert.equal((await a.action('task',{task:'daily'})).success,true); assert.equal((await a.action('task',{task:'daily'})).success,false);
    assert.equal((await a.action('task',{task:'profile'})).success,false);
    assert.equal((await a.action('profile',{display_name:'测试昵称 A',description:'我的测试介绍',user_url:'https://example.test'})).success,true);
    assert.equal((await a.action('task',{task:'profile'})).success,true);
    assert.equal((await a.action('profile',{display_name:'',description:'x'})).success,false);
    assert.equal((await a.action('bookmark',{id:fixture.posts.article})).data.selected,true);
    for(const key of ['draft','protected'])assert.equal((await a.action('bookmark',{id:fixture.posts[key]})).success,false);
    assert.equal((await a.action('follow',{id:fixture.users.qa_member_b})).data.selected,true);
    assert.equal((await a.action('follow',{id:fixture.users.qa_member_a})).success,false);
    assert.equal((await a.action('vote',{id:fixture.posts.poll,choice:99})).success,false);
    assert.equal((await a.action('vote',{id:fixture.posts.poll,choice:0})).success,true);
    assert.equal((await a.action('vote',{id:fixture.posts.poll,choice:1})).success,false);
    assert.equal((await b.action('accept',{id:fixture.comment})).success,false);
    assert.equal((await a.action('accept',{id:fixture.comment})).success,true);
    assert.equal((await a.action('message',{recipient:'qa_member_b',body:'PRIVATE-QA-ONLY <script>alert(1)</script>'})).success,true);
    await b.page.goto(url('directmessage')); assert.match(await b.page.locator('.sluyu-portal-content').innerText(), /PRIVATE-QA-ONLY/);
    await c.page.goto(url('directmessage')+'&peer='+fixture.users.qa_member_a); assert.equal((await c.page.locator('.sluyu-portal-content').innerText()).includes('PRIVATE-QA-ONLY'),false);
    assert.equal((await b.action('read_messages',{id:fixture.users.qa_member_a})).success,true);
    assert.equal((await a.action('verify',{title:'测试认证',body:'可公开核验的测试证明材料'})).success,true);
    assert.equal((await a.action('submit',{kind:'poll',title:'待审投票',body:'仅为测试，应该不会在公开圈子中显示',choices:'红色\n蓝色'})).success,true);
    await page.goto(url('circle')); assert.equal((await page.locator('.sluyu-portal-content').innerText()).includes('待审投票'),false);
    const wrongNonce=await a.action('checkin',{nonce:'bad'}); assert.equal(wrongNonce.status,403);
    await a.page.goto(url('account')); assert.match(await a.page.locator('.sluyu-portal-content').innerText(), /QA article/);
    await a.page.goto(url('mission')); await a.page.screenshot({path:path.join(output,'checkin-desktop.png'),fullPage:true});
    for(const width of [390,320]) {
      await page.setViewportSize({width,height:844});
      for(const key of ['more','tools','circle','document','vips']) {
        await page.goto(url(key)); assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,key+' at '+width);
      }
      await a.page.setViewportSize({width,height:844});
      for(const key of ['account','mission','task','gold','directmessage','verify','submit']) {
        await a.page.goto(url(key)); assert.equal(await a.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,key+' at '+width);
      }
      if(width===390){await a.page.goto(url('mission')); await a.page.screenshot({path:path.join(output,'checkin-mobile.png'),fullPage:true});}
    }
    assert.deepEqual(errors,[]);
    for(const ctx of [a.context,b.context,c.context,guest])await ctx.close();
    console.log('PASS: '+fixture.routes.length+' routes, mobile 320/390, tools, check-in idempotency, task claims, profiles, bookmark privacy, follows, votes, accepted answers, private-message isolation, pending submissions, nonce/auth checks.');
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
