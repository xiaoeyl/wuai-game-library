<?php
if (PHP_SAPI !== 'cli') { exit(1); }
$root=getenv('WP_QA_ROOT');
if(!$root || !is_file($root.'/wp-load.php'))throw new RuntimeException('Set WP_QA_ROOT.');
$_SERVER['HTTP_HOST']='127.0.0.1:8771'; $_SERVER['REQUEST_URI']='/';
require $root.'/wp-load.php';
if(home_url()!=='http://127.0.0.1:8771' || !class_exists('WooCommerce'))throw new RuntimeException('Disposable local WooCommerce required.');
function check_commerce($condition,$message) { if(!$condition)throw new RuntimeException($message); }
$user=get_user_by('login','qa_member_c'); $uid=$user->ID;
foreach (['_wuai_membership_state','_wuai_membership_sync','_wuai_membership_grants','_wuai_member_until'] as $key) { delete_user_meta($uid,$key); }
$product=new WC_Product_Simple(); $product->set_name('QA membership'); $product->set_status('publish'); $product->set_virtual(true); $product->set_regular_price('10'); $product->update_meta_data('_wuai_membership_days',30); $product->save();
wp_set_current_user(0);
check_commerce(!apply_filters('woocommerce_add_to_cart_validation',true,$product->get_id()),'Guests cannot buy memberships.');
wp_set_current_user($uid);
check_commerce(apply_filters('woocommerce_add_to_cart_validation',true,$product->get_id()),'A logged-in user can buy.');
update_user_meta($uid,'_wuai_banned_until',-1);
check_commerce(!apply_filters('woocommerce_add_to_cart_validation',true,$product->get_id()),'Banned accounts cannot buy.'); delete_user_meta($uid,'_wuai_banned_until');
$orders=[];
function qa_order($uid,$product,$days) {
    $order=wc_create_order(['customer_id'=>$uid]); $item_id=$order->add_product($product,1); $item=$order->get_item($item_id); $item->add_meta_data('_wuai_membership_days',$days,true); $item->save(); $order->calculate_totals(); $order->save(); return $order;
}
$first=qa_order($uid,$product,30); $orders[]=$first;
wuai_sluyu_membership_order_changed($first->get_id()); check_commerce(!wuai_sluyu_member($uid),'Unpaid order must not grant membership.');
$first->payment_complete('qa-local-transaction'); $first=wc_get_order($first->get_id());
$until=(int)get_user_meta($uid,'_wuai_member_until',true); check_commerce($until>=time()+29*DAY_IN_SECONDS,'Paid order grants membership.');
wuai_sluyu_membership_order_changed($first->get_id()); check_commerce($until===(int)get_user_meta($uid,'_wuai_member_until',true),'Repeated webhook is idempotent.');
$second=qa_order($uid,$product,10); $orders[]=$second; $second->payment_complete('qa-local-renewal');
check_commerce((int)get_user_meta($uid,'_wuai_member_until',true)===$until+10*DAY_IN_SECONDS,'Renewal adds only its own duration.');
$second->update_status('refunded'); check_commerce((int)get_user_meta($uid,'_wuai_member_until',true)===$until,'Refund revokes only corresponding grant.');
$first->update_status('cancelled'); check_commerce(!wuai_sluyu_member($uid),'Cancellation revokes original grant.');
$lifetime=qa_order($uid,$product,-1); $orders[]=$lifetime; $lifetime->payment_complete('qa-local-lifetime');
check_commerce((int)get_user_meta($uid,'_wuai_member_until',true)===-1,'Lifetime membership works.');
$lifetime->update_status('refunded'); check_commerce(!wuai_sluyu_member($uid),'Lifetime refunds revoke access.');
$partial=qa_order($uid,$product,30); $orders[]=$partial; $partial->payment_complete('qa-local-partial');
$refund=wc_create_refund(['order_id'=>$partial->get_id(),'amount'=>1,'reason'=>'QA partial refund','refund_payment'=>false]);
check_commerce(!is_wp_error($refund),'Local partial refund created.');
check_commerce(!wuai_sluyu_member($uid),'Conservative policy: partial refund also revokes the order grant.');
$lock=wuai_sluyu_lock('qa-test'); check_commerce((bool)$lock,'First lock acquired.'); check_commerce(!wuai_sluyu_lock('qa-test'),'Second lock cannot overwrite.'); wuai_sluyu_unlock($lock);
$lock=wuai_sluyu_lock('qa-test'); check_commerce((bool)$lock,'Released lock can be reacquired.'); wuai_sluyu_unlock($lock);
$options=wuai_sluyu_get_options(); $options['membership_products']=(string)$product->get_id(); update_option('wuai_sluyu_options',$options);
$coupon=new WC_Coupon(); $coupon->set_code('qa-public-10'); $coupon->set_discount_type('percent'); $coupon->set_amount(10); $coupon->save();
$options['public_coupon_codes']='qa-public-10'; update_option('wuai_sluyu_options',$options);
ob_start(); wuai_sluyu_view_commerce('vips'); $html=ob_get_clean(); check_commerce(strpos($html,'QA membership')!==false,'Published membership plan visible.');
ob_start(); wuai_sluyu_view_commerce('coupons'); $html=ob_get_clean(); check_commerce(strpos($html,'qa-public-10')!==false,'Explicit public coupon visible.');
WC_Install::create_pages();
update_option('woocommerce_coming_soon','no');
echo "PASS: real WooCommerce orders, pending/payment/renewal/idempotency/refund/cancellation/lifetime, guest/banned guard, coupon display and atomic locks. No external payments performed.\n";
