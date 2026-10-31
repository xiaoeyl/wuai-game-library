<?php
// CLI-only fixture; its read-only, mail-blocking MU helper never ships in the theme.
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('WP_QA_ROOT');if(!$root || !is_file($root.'/wp-load.php'))throw new RuntimeException('WP_QA_ROOT required.');
$_SERVER['HTTP_HOST']='127.0.0.1:8771';$_SERVER['REQUEST_URI']='/';require $root.'/wp-load.php';
if(home_url()!=='http://127.0.0.1:8771' || !class_exists('WooCommerce') || !function_exists('wuai_sluyu_membership_admin_box') || !function_exists('wuai_sluyu_protected_validate'))throw new RuntimeException('Integrated local WooCommerce/theme fixture required.');
function membership_browser_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$resolved=realpath($root);membership_browser_check($resolved && strcasecmp($resolved,(string)realpath(ABSPATH))===0,'Resolved fixture installation must match loaded WordPress.');
$output=getenv('WP_QA_OUTPUT')?:dirname($root);if(!is_dir($output))wp_mkdir_p($output);
$mu_dir=WP_CONTENT_DIR.'/mu-plugins';if(!is_dir($mu_dir))wp_mkdir_p($mu_dir);
$mu_file=$mu_dir.'/wuai-membership-browser-qa.php';$marker='WUAI_MEMBERSHIP_BROWSER_QA_V1';
if(is_file($mu_file) && strpos(file_get_contents($mu_file),$marker)===false)throw new RuntimeException('Refusing to replace an unrelated MU plugin.');
$plugin=<<<'PHP'
<?php
// WUAI_MEMBERSHIP_BROWSER_QA_V1: isolated local browser state only; never ship.
if(!defined('ABSPATH') || !defined('WUAI_MEMBERSHIP_QA_ROOT'))return;
if(strcasecmp((string)realpath(ABSPATH),WUAI_MEMBERSHIP_QA_ROOT)!==0 || home_url()!=='http://127.0.0.1:8771')return;
if(PHP_SAPI!=='cli' && (!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true) || ($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:8771'))return;
add_filter('pre_wp_mail',function(){update_option('wuai_membership_qa_mail_count',(int)get_option('wuai_membership_qa_mail_count',0)+1,false);return true;},PHP_INT_MAX);
add_action('init',function(){if(!headers_sent())header('X-Wuai-QA-Membership-Mail-Blocked: 1');});
// Even a mistaken gateway button cannot issue a payment/refund in this fixture.
add_filter('woocommerce_available_payment_gateways','__return_empty_array',PHP_INT_MAX);
add_filter('woocommerce_payment_gateways','__return_empty_array',PHP_INT_MAX);
add_action('admin_notices',function(){if(current_user_can('manage_options'))echo '<input id="wuai-membership-qa-nonce" type="hidden" value="'.esc_attr(wp_create_nonce('wuai_membership_qa')).'">';});
add_action('wp_ajax_wuai_membership_qa_state',function(){
    if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Admin only'],403);
    check_ajax_referer('wuai_membership_qa');$fixture=get_option('wuai_membership_browser_fixture',[]);
    if(!is_array($fixture) || empty($fixture['order']) || empty($fixture['users']))wp_send_json_error(['message'=>'Fixture missing'],409);
    $order=wuai_sluyu_membership_fresh_order($fixture['order']);
    if(!$order || $order->get_meta('_wuai_membership_browser_fixture')!=='v1')wp_send_json_error(['message'=>'Fixture order missing'],409);
    $users=[];foreach(['reader','other']as$name){$uid=absint($fixture['users'][$name]);$user=get_userdata($uid);if(!$user || $user->user_login!=='qa_membership_browser_'.$name)wp_send_json_error(['message'=>'Fixture identity mismatch'],409);$state=wuai_sluyu_membership_state($uid);$dirty=wuai_sluyu_membership_raw($uid,'_wuai_membership_sync');$users[$name]=['id'=>$uid,'until'=>wuai_sluyu_membership_until($uid),'grants'=>is_wp_error($state)?null:$state['grants'],'dirty'=>!empty($dirty['value']),'pending'=>wuai_sluyu_membership_pending($uid)];}
    wp_send_json_success(['order'=>['id'=>$order->get_id(),'customer'=>(int)$order->get_customer_id(),'recipient'=>(int)$order->get_meta('_wuai_membership_user'),'status'=>$order->get_status(),'paid_at'=>$order->get_date_paid()?$order->get_date_paid()->getTimestamp():0,'total'=>$order->get_total(),'refunded'=>$order->get_total_refunded(),'transaction'=>$order->get_transaction_id(),'payment_method'=>$order->get_payment_method(),'refunds'=>array_map(function($refund){return $refund->get_id();},$order->get_refunds()),'queued'=>(bool)wp_next_scheduled('wuai_sluyu_retry_membership',[$order->get_id()])],'users'=>$users,'mail_blocked'=>(int)get_option('wuai_membership_qa_mail_count',0)]);
});
PHP;
$plugin=str_replace("if(!defined('ABSPATH') || !defined('WUAI_MEMBERSHIP_QA_ROOT'))return;","if(!defined('ABSPATH'))return;\ndefine('WUAI_MEMBERSHIP_QA_ROOT',".var_export($resolved,true).");",$plugin);
membership_browser_check(file_put_contents($mu_file,$plugin)!==false,'Write isolated mail/state helper.');
$mail_block=function(){return true;};add_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
$old_user=get_current_user_id();$old_post=$_POST;$users=[];$login_prefix='qa_membership_browser_';
try{
    $_POST=[];wp_set_current_user(0);
    $previous=get_option('wuai_membership_browser_fixture',[]);
    if(is_array($previous)){
        foreach(['order','decoy']as$key){$id=absint($previous[$key]??0);$old=$id?wc_get_order($id):false;if($old && $old->get_meta('_wuai_membership_browser_fixture')==='v1')$old->delete(true);if($id){wp_clear_scheduled_hook('wuai_sluyu_retry_membership',[$id]);foreach(['wuai_membership_pending_users_','wuai_membership_issue_','wuai_membership_retry_owner_']as$prefix)delete_option($prefix.$id);}}
        $product_id=absint($previous['product']??0);if($product_id && get_post_meta($product_id,'_wuai_membership_browser_fixture',true)==='v1'){$old=wc_get_product($product_id);if($old)$old->delete(true);}
        $post_id=absint($previous['post']??0);if($post_id && get_post_meta($post_id,'_wuai_membership_browser_fixture',true)==='v1')wp_delete_post($post_id,true);
    }
    foreach(['reader','other','admin','banned','limited']as$name){
        $login=$login_prefix.$name;$existing=get_user_by('login',$login);$id=$existing?$existing->ID:wp_create_user($login,'qa-local-only',$login.'@example.test');
        membership_browser_check(!is_wp_error($id) && $id,'Create dedicated membership account.');$id=(int)$id;$users[$name]=$id;
        $user=new WP_User($id);$user->set_role(in_array($name,['admin','banned'],true)?'administrator':'subscriber');if($name==='limited')$user->add_cap('manage_woocommerce');
        membership_browser_check(!is_wp_error(wp_update_user(['ID'=>$id,'display_name'=>'Membership Browser '.ucfirst($name),'user_pass'=>'qa-local-only'])),'Reset dedicated account.');
        foreach(['_wuai_member_until','_wuai_membership_grants','_wuai_membership_state','_wuai_membership_sync','_wuai_banned_until']as$key)delete_user_meta($id,$key);
        if($name==='banned')update_user_meta($id,'_wuai_banned_until',-1);
    }
    wp_set_current_user($users['admin']);
    $product=new WC_Product_Simple();$product->set_name('QA Membership Browser Plan');$product->set_status('publish');$product->set_virtual(true);$product->set_regular_price('10');$product->set_tax_status('none');$product->update_meta_data('_wuai_membership_days',30);$product->update_meta_data('_wuai_membership_browser_fixture','v1');$product->save();
    $make_order=function($uid)use($product){$order=wc_create_order(['customer_id'=>$uid]);$order->set_created_via('wuai-membership-browser-qa');$order->set_payment_method('');$order->set_billing_email('qa-membership-local@example.test');$order->update_meta_data('_wuai_membership_browser_fixture','v1');$item=new WC_Order_Item_Product();$item->set_product($product);$item->set_quantity(1);$item->set_total('10');$item->add_meta_data('_wuai_membership_days',30,true);$order->add_item($item);$order->calculate_totals();$order->save();return $order;};
    $order=$make_order($users['reader']);$decoy=$make_order($users['other']);
    membership_browser_check(wuai_sluyu_membership_commit($users['reader'],[])===true,'Initial empty reader grant bundle.');
    membership_browser_check(wuai_sluyu_membership_mark($users['reader'],$order->get_id()),'Seed a recoverable pending marker.');
    membership_browser_check(wuai_sluyu_membership_pending_users($order->get_id(),[$users['reader']])===[$users['reader']],'Seed independent pending checkpoint.');
    wp_clear_scheduled_hook('wuai_sluyu_retry_membership',[$order->get_id()]);wp_schedule_single_event(time()+HOUR_IN_SECONDS,'wuai_sluyu_retry_membership',[$order->get_id()]);
    $secret='QA-MEMBERSHIP-PRIVATE-'.str_replace('-','',wp_generate_uuid4());
    $post=wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_author'=>$users['admin'],'post_title'=>'QA Membership Browser Resource','post_content'=>'<p>Local membership browser fixture. The private member fragment is stored separately.</p>','comment_status'=>'closed','ping_status'=>'closed'],true);
    membership_browser_check(!is_wp_error($post),'Create member-gated browser resource.');update_post_meta($post,'_wuai_membership_browser_fixture','v1');
    $config=wuai_sluyu_protected_validate(['protected_body'=>'<p>'.$secret.'</p>','access'=>'member']);membership_browser_check(is_array($config),'Validate member-only fragment.');update_post_meta($post,wuai_sluyu_protected_key(),wp_slash($config));
    $record=['users'=>$users,'order'=>$order->get_id(),'decoy'=>$decoy->get_id(),'product'=>$product->get_id(),'post'=>(int)$post];update_option('wuai_membership_browser_fixture',$record,false);update_option('wuai_membership_qa_mail_count',0,false);
    $manifest=$record+['base'=>home_url(),'login_prefix'=>$login_prefix,'edit_url'=>$order->get_edit_order_url(),'resource_url'=>get_permalink($post),'secret'=>$secret,'mu_plugin'=>$mu_file];
    membership_browser_check(file_put_contents(rtrim($output,'/\\').'/membership-browser-fixture.json',wp_json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))!==false,'Write membership browser manifest.');
    echo "PASS: isolated pending order, reader/other/admin/denied accounts, member-only fragment and read-only state helper prepared; mail blocked and payment gateways disabled. Run membership.cjs.\n";
}finally{remove_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);$_POST=$old_post;wp_set_current_user($old_user);}
