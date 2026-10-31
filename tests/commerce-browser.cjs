'use strict';
const assert=require('node:assert/strict');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base='http://127.0.0.1:8771';
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'}); const page=await context.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('qa_member_a');await page.locator('#user_pass').fill('qa-local-only');await page.locator('#wp-submit').click();await page.waitForURL(url=>!url.pathname.includes('wp-login.php'));
  await page.goto(base+'/?sluyu_page=vips');const productLink=page.locator('.sluyu-service-tile a').first();assert.equal(await productLink.count(),1);
  await productLink.click();await page.locator('.single_add_to_cart_button').click();
  await page.goto(base+'/?sluyu_page=carts');assert.equal(await page.locator('.sluyu-portal-hero h1').innerText(),'购物车');
  assert.match(await page.locator('.sluyu-portal-content').innerText(),/QA membership/);
  const checkout=page.locator('.wc-block-cart__submit-button,.checkout-button').first();await checkout.click();
  assert.ok(await page.locator('.wc-block-checkout,.woocommerce-checkout').count(),'Native checkout renders.');
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,'Checkout responsive.');
  await page.screenshot({path:path.resolve('dist/checks-v1.1.0/checkout-mobile.png'),fullPage:true,animations:'disabled'});
  assert.deepEqual(errors,[]);await context.close();
  console.log('PASS: actual membership product -> add to cart -> native WooCommerce cart -> checkout, responsive; no payment submitted.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
