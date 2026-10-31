<?php
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('WP_QA_ROOT'); if(!$root || !is_file($root.'/wp-load.php'))throw new RuntimeException('WP_QA_ROOT required.');
$_SERVER['HTTP_HOST']='127.0.0.1:8771'; $_SERVER['REQUEST_URI']='/'; require $root.'/wp-load.php';
if(home_url()!=='http://127.0.0.1:8771' || !class_exists('WooCommerce'))throw new RuntimeException('Disposable local WooCommerce QA required.');
function wg_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function wg_render($mode,$extra=[]){$widget=new Wuai_Sluyu_Widget();ob_start();$widget->widget(['before_widget'=>'<section class="sluyu-widget">','after_widget'=>'</section>','before_title'=>'<h2>','after_title'=>'</h2>','widget_id'=>'qa-'.wp_unique_id()],array_merge(['mode'=>$mode,'count'=>10],$extra));return ob_get_clean();}
function wg_context($post_id){$query=new WP_Query();$query->is_singular=true;$query->is_single=true;$query->queried_object=get_post($post_id);$query->queried_object_id=$post_id;$GLOBALS['wp_query']=$query;$GLOBALS['wp_the_query']=$query;$GLOBALS['post']=get_post($post_id);}
$old_user=get_current_user_id();$old_get=$_GET;$old_post=$_POST;$old_query=$GLOBALS['wp_query'];$old_the_query=$GLOBALS['wp_the_query'];$old_global_post=$GLOBALS['post']??null;$old_options=get_option('wuai_sluyu_options');
$prefix='qa_widget_'.strtolower(wp_generate_password(8,false,false));$users=[];$posts=[];$products=[];$coupons=[];
try{
    foreach(['owner','reader']as$name){$uid=wp_create_user($prefix.'_'.$name,'qa-local-only',$prefix.'_'.$name.'@example.test');wg_check(!is_wp_error($uid),'Create widget user.');$users[$name]=(int)$uid;}
    $owner=$users['owner'];$reader=$users['reader'];$options=wuai_sluyu_get_options();$options['show_resource_box']='1';
    foreach(['valid','expired','used','draft','secret']as$name){$coupon=new WC_Coupon();$coupon->set_code($prefix.'_'.$name);$coupon->set_discount_type('percent');$coupon->set_amount(10);if($name==='expired')$coupon->set_date_expires(time()-60);if($name==='used'){$coupon->set_usage_limit(1);$coupon->set_usage_count(1);}$coupon->save();$coupons[]=$coupon->get_id();if($name==='draft')wp_update_post(['ID'=>$coupon->get_id(),'post_status'=>'draft']);}
    $options['public_coupon_codes']=implode("\n",array_map(function($name)use($prefix){return $prefix.'_'.$name;},['valid','expired','used','draft','valid']));update_option('wuai_sluyu_options',$options);
    wp_set_current_user(0);$html=wg_render('coupons');
    wg_check(count(wuai_sluyu_public_coupons())===1 && strpos($html,$prefix.'_valid')!==false,'Only one valid allowlisted coupon is displayed.');
    foreach(['expired','used','draft','secret']as$name)wg_check(strpos($html,$prefix.'_'.$name)===false,'Private/unavailable coupons are not enumerated: '.$name);
    ob_start();wuai_sluyu_view_commerce('coupons');$page=ob_get_clean();wg_check(strpos($page,$prefix.'_valid')!==false && strpos($page,$prefix.'_draft')===false,'Coupon page shares the same publication eligibility.');
    foreach(['visible','hidden','draft','private','password']as$name){$product=new WC_Product_Simple();$product->set_name($prefix.' product '.$name);$product->set_status(in_array($name,['draft','private'],true)?$name:'publish');$product->set_regular_price('10');$product->set_catalog_visibility($name==='hidden'?'hidden':'visible');$product->save();$products[]=$product->get_id();if($name==='password')wp_update_post(['ID'=>$product->get_id(),'post_password'=>'locked']);}
    $html=wg_render('products');wg_check(strpos($html,$prefix.' product visible')!==false,'Published catalog product displayed.');
    foreach(['hidden','draft','private','password']as$name)wg_check(strpos($html,$prefix.' product '.$name)===false,'Noncatalog product hidden: '.$name);
    foreach(['active','pending','private','expired','resolved']as$name){$id=wp_insert_post(['post_type'=>'sluyu_listing','post_author'=>$owner,'post_status'=>in_array($name,['pending','private'],true)?$name:'publish','post_title'=>$prefix.' listing '.$name,'post_content'=>'Safe local listing summary.','meta_input'=>['_wuai_listing_type'=>'demand','_wuai_listing_category'=>'game','_wuai_listing_state'=>$name==='resolved'?'resolved':'open','_wuai_listing_deadline'=>$name==='expired'?time()-60:time()+DAY_IN_SECONDS]]);$posts[]=$id;}
    $html=wg_render('listings');wg_check(strpos($html,$prefix.' listing active')!==false,'Active public listing displayed.');foreach(['pending','private','expired','resolved']as$name)wg_check(strpos($html,$prefix.' listing '.$name)===false,'Unavailable listing hidden: '.$name);
    $resource=wp_insert_post(['post_type'=>'post','post_author'=>$owner,'post_status'=>'publish','post_title'=>$prefix.' resource','post_content'=>'<h2>Public heading</h2><p>Public summary.</p>']);$posts[]=$resource;
    update_post_meta($resource,'_wuai_download_settings',['url'=>'https://example.test/widget-only-secret.zip','password'=>'widget-private-code','access'=>'login','cost'=>0,'version'=>wp_generate_uuid4()]);
    wg_context($resource);$html=wg_render('download');wg_check(strpos($html,'https://example.test/widget-only-secret.zip')===false && strpos($html,'widget-private-code')===false && strpos($html,'wuai_sluyu_download')===false,'Guest download widget exposes neither target, code nor handoff.');
    wp_set_current_user($reader);$html=wg_render('download');wg_check(strpos($html,'widget-private-code')!==false && strpos($html,'action=wuai_sluyu_download')!==false && strpos($html,'widget-only-secret.zip')===false,'Eligible widget uses the same signed handoff as article cards.');
    $bundle=get_post_meta($resource,'_wuai_download_settings',true);$bundle['access']='points';$bundle['cost']=25;update_post_meta($resource,'_wuai_download_settings',$bundle);
    $html=wg_render('download').wg_render('download');wg_check(strpos($html,'data-operation="download_unlock"')===false && substr_count($html,'href="#download-box"')===2,'Multiple download widgets link to the one body confirmation.');preg_match_all('/id="(download-sidebar-[^"]+)"/',$html,$ids);wg_check(count($ids[1])===2 && count(array_unique($ids[1]))===2,'Multiple locked widget instances have unique IDs.');
    $html=wg_render('toc');wg_check(strpos($html,'class="sluyu-toc"')!==false && strpos($html,'Public heading')===false,'TOC is built only from the authorized rendered DOM.');
    wp_update_post(['ID'=>$resource,'post_password'=>'locked']);wg_context($resource);wg_check(strpos(wg_render('toc'),'class="sluyu-toc"')===false && strpos(wg_render('download'),'action=wuai_sluyu_download')===false,'Password-protected article has neither TOC nor download entitlement widget.');
    wuai_sluyu_event($reader,'widget-balance','qa',137);wuai_sluyu_event($owner,'widget-owner-balance','qa',864);
    $wpdb->insert(wuai_sluyu_table('messages'),['sender_id'=>$owner,'recipient_id'=>$reader,'kind'=>'message','body'=>'Private body not rendered in widget.','created_at'=>current_time('mysql')]);
    wp_set_current_user($reader);$html=wg_render('user');wg_check(strpos($html,'<strong>137</strong>')!==false && strpos($html,'<strong>1</strong>')!==false && strpos($html,'<strong>864</strong>')===false && strpos($html,'Private body')===false,'Profile widget uses only current account balance/unread count and no message body.');
    wp_set_current_user(0);$html=wg_render('user');wg_check(strpos($html,$prefix.'_reader')===false && strpos($html,'137')===false && strpos($html,'wp-login.php')!==false,'Anonymous widget never includes account data.');
    $widget=new Wuai_Sluyu_Widget();$safe=$widget->update(['mode'=>['bad'],'title'=>['bad'],'count'=>['bad'],'image'=>['bad'],'url'=>['bad']],[]);wg_check($safe['mode']==='checkin' && $safe['title']==='' && $safe['count']===1,'Malformed native widget inputs do not cause array-key errors.');
    wg_check(count(Wuai_Sluyu_Widget::modes())===19,'Nineteen widget modes cover captured reference widget categories.');
    foreach(array_keys(Wuai_Sluyu_Widget::modes())as$mode)wg_check(strpos(wg_render($mode),'<h2>')!==false,'Every widget renders safely: '.$mode);
    echo "PASS: 19 widget modes, published catalog products, allowlisted coupon parity, open listings, current-user isolation, signed download and unique widget IDs, locked TOC and malformed admin inputs.\n";
}finally{
    $_POST=[];wp_set_current_user(0);update_option('wuai_sluyu_options',$old_options);
    foreach($products as$id){$product=wc_get_product($id);if($product)$product->delete(true);}foreach(array_merge($posts,$coupons)as$id)wp_delete_post($id,true);
    require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$uid)wp_delete_user($uid);
    $GLOBALS['wp_query']=$old_query;$GLOBALS['wp_the_query']=$old_the_query;$GLOBALS['post']=$old_global_post;$_GET=$old_get;$_POST=$old_post;wp_set_current_user($old_user);
}
