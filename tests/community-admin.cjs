'use strict';
const assert=require('node:assert/strict');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base='http://127.0.0.1:8771';
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
  const page=await context.newPage();
  await page.goto(base+'/wp-login.php'); await page.locator('#user_login').fill('qa_admin'); await page.locator('#user_pass').fill('qa-local-only');
  await Promise.all([page.waitForURL(/wp-admin/),page.locator('#wp-submit').click()]);
  await page.goto(base+'/wp-admin/admin.php?page=wuai-sluyu-services');
  assert.equal(await page.locator('h1').innerText(),'站内服务管理');
  const nonce=await page.locator('[name=_wpnonce]').inputValue();
  const before=await (await context.request.get(base+'/?rest_route=/wp/v2/pages&per_page=100')).json();
  let response=await context.request.post(base+'/wp-admin/admin-post.php',{form:{action:'wuai_sluyu_install_pages',_wpnonce:nonce}});
  assert.equal(response.status(),200);
  const first=await (await context.request.get(base+'/?rest_route=/wp/v2/pages&per_page=100')).json(); assert.ok(first.length>=before.length);
  response=await context.request.post(base+'/wp-admin/admin-post.php',{form:{action:'wuai_sluyu_install_pages',_wpnonce:nonce}}); assert.equal(response.status(),200);
  const second=await (await context.request.get(base+'/?rest_route=/wp/v2/pages&per_page=100')).json(); assert.equal(second.length,first.length,'Install is idempotent.');
  for(const old of before)assert.deepEqual(second.find(item=>item.id===old.id).content,old.content,'Existing pages are unchanged.');
  for(const key of ['account','circle','document','mission','links']){await page.goto(base+'/?sluyu_page='+key);assert.equal(await page.locator('.sluyu-portal-hero').count(),1,key);}
  await page.goto(base+'/wp-admin/widgets.php'); assert.match(await page.locator('body').innerText(),/首页侧栏|小工具/);
  await page.goto(base+'/wp-admin/admin.php?page=wuai-sluyu'); await page.locator('.csf-nav a').filter({hasText:/^用户&互动$/}).click(); await page.locator('.csf-nav a').filter({hasText:/^积分与签到$/}).click();
  assert.equal(await page.locator('[name="wuai_sluyu_options[checkin_points]"]').isVisible(),true);
  await page.waitForFunction(()=>getComputedStyle(document.querySelector('[name="wuai_sluyu_options[checkin_points]"]').closest('.csf-section')).opacity==='1');
  await page.screenshot({path:path.resolve('dist/checks-v1.1.0/services-admin.png'),fullPage:true});
  const subscriber=await browser.newContext();const p=await subscriber.newPage();
  await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill('qa_member_a');await p.locator('#user_pass').fill('qa-local-only');await p.locator('#wp-submit').click();await p.waitForURL(url=>!url.pathname.includes('wp-login.php'),{waitUntil:'domcontentloaded'});
  response=await subscriber.request.post(base+'/wp-admin/admin-post.php',{form:{action:'wuai_sluyu_install_pages',_wpnonce:nonce}});assert.equal(response.status(),403);
  response=await context.request.post(base+'/wp-admin/admin-post.php',{form:{action:'wuai_sluyu_install_pages',_wpnonce:'bad'}});assert.equal(response.status(),403);
  await subscriber.close();await context.close();
  console.log('PASS: service management, CSF fields, widgets, idempotent page/category initialization, preservation of existing content and admin/nonce guards.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
