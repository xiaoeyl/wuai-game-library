<?php
if (!defined('ABSPATH')) { exit; }

add_action('after_setup_theme', function () { add_theme_support('woocommerce'); });
add_action('template_redirect', function () {
    // Use the actual cart page so WooCommerce loads its cart scripts and session context.
    if (function_exists('wc_get_cart_url') && wuai_sluyu_portal_key() === 'carts' && !is_cart() && wc_get_page_id('cart') > 0) {
        wp_safe_redirect(wc_get_cart_url()); exit;
    }
}, 5);

function wuai_sluyu_view_commerce($key) {
    if (!class_exists('WooCommerce')) {
        echo '<section class="sluyu-panel"><h2>商城服务尚未配置</h2><p>商品、购物车、订单和优惠券使用 WooCommerce 的真实业务数据。站长需先安装并启用 WooCommerce，设置商品、结算页与支付渠道。</p><p>当前没有可支付商品，也不会生成模拟订单或假付款成功页面。</p><a class="sluyu-action-plain" href="' . esc_url(wuai_sluyu_portal_url('document')) . '">查看帮助</a></section>';
        return;
    }
    echo '<section class="sluyu-panel sluyu-commerce">';
    if ($key === 'shop') { echo do_shortcode('[products limit="12" columns="3" paginate="true" orderby="date" order="DESC"]'); }
    elseif ($key === 'carts') { echo do_shortcode('[woocommerce_cart]'); }
    elseif ($key === 'orders') {
        if (wuai_sluyu_require_login()) {
            $orders=wc_get_orders(['customer_id'=>get_current_user_id(),'limit'=>20,'page'=>wuai_sluyu_page_number(),'paginate'=>true]);
            echo '<h2>我的订单</h2><div class="sluyu-compact-list">';
            foreach($orders->orders as $order) { echo '<div><a href="' . esc_url($order->get_view_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a><span>' . wp_kses_post($order->get_formatted_order_total()) . '</span><small>' . esc_html(wc_get_order_status_name($order->get_status())) . '</small></div>'; }
            if(!$orders->orders)wuai_sluyu_empty('暂无订单。'); echo '</div>'; wuai_sluyu_portal_pagination($orders->max_num_pages,'orders');
            echo '<p><a href="' . esc_url(wc_get_page_permalink('myaccount')) . '">管理订单、下载和收货地址 →</a></p>';
        }
    } elseif ($key === 'coupons') {
        echo '<h2>可领取的优惠券</h2><p>复制优惠码，在购物车或结算页使用。实际优惠、适用商品和使用资格由结算时校验。</p>';
        $count=0;
        foreach(wuai_sluyu_public_coupons() as $coupon) {
            $code=$coupon->get_code();
            $count++;
            echo '<article class="sluyu-coupon"><h3>' . esc_html($coupon->get_discount_type()==='percent'?$coupon->get_amount().'%':wp_strip_all_tags(wc_price($coupon->get_amount()))) . ' 优惠</h3><code>' . esc_html($code) . '</code><p>' . esc_html($coupon->get_description()) . '</p><button class="sluyu-action-plain" type="button" data-sluyu-copy="' . esc_attr($code) . '">复制优惠码</button></article>';
        }
        if(!$count)wuai_sluyu_empty('当前没有公开发放的有效优惠券。');
    } elseif ($key === 'vips') {
        echo '<h2>会员计划</h2>';
        if(is_user_logged_in()) { $until=(int)get_user_meta(get_current_user_id(),'_wuai_member_until',true); echo '<p class="sluyu-notice">当前状态：' . (wuai_sluyu_member()?'会员有效 · '.esc_html($until===-1?'长期有效':wp_date('Y-m-d H:i',$until).' 到期'):'尚未开通 / 已到期') . '</p>'; }
        $ids=array_filter(array_map('absint',preg_split('/[,\s]+/',wuai_sluyu_option('membership_products','')))); $count=0;
        echo '<div class="sluyu-service-grid">';
        foreach(array_slice($ids,0,12) as $id) {
            $product=wc_get_product($id); $days=(int)get_post_meta($id,'_wuai_membership_days',true);
            if(!$product || !$product->is_type('simple') || $product->get_status()!=='publish' || !$product->is_purchasable() || !$product->is_in_stock() || ($days!==-1 && $days<1))continue;
            $count++;
            echo '<article class="sluyu-service-tile"><h3>' . esc_html($product->get_name()) . '</h3><strong class="sluyu-big-number">' . wp_kses_post($product->get_price_html()) . '</strong><p>' . ($days===-1?'长期有效':$days.' 天') . ' · 可下载标记为会员可用的资源</p><div>' . wp_kses_post($product->get_short_description()) . '</div><a class="sluyu-action-primary" href="' . esc_url(get_permalink($id)) . '">查看与购买</a></article>';
        } echo '</div>';
        if(!$count)wuai_sluyu_empty('站长尚未配置可购买的会员商品。');
        echo '<p><a href="' . esc_url(wuai_sluyu_portal_url('vipjiesangsa')) . '">会员购买须知 →</a></p>';
    }
    echo '</section>';
}

add_action('woocommerce_product_options_general_product_data', function () {
    woocommerce_wp_text_input(['id'=>'_wuai_membership_days','label'=>'吾爱会员有效天数','type'=>'number','description'=>'仅简单商品。0=普通商品，-1=长期会员，正整数=有效天数。请设为虚拟商品。','desc_tip'=>true,'custom_attributes'=>['min'=>'-1','max'=>'36500','step'=>'1']]);
});
add_action('woocommerce_admin_process_product_object', function ($product) {
    if (isset($_POST['_wuai_membership_days'])) { $product->update_meta_data('_wuai_membership_days', max(-1,min(36500,(int)$_POST['_wuai_membership_days']))); }
});
add_filter('woocommerce_add_to_cart_validation', function ($valid, $product_id) {
    if ((int)get_post_meta($product_id,'_wuai_membership_days',true) !== 0 && (!is_user_logged_in() || wuai_sluyu_banned(get_current_user_id()))) {
        wc_add_notice('请先使用正常状态的账号登录后购买会员。','error'); return false;
    }
    return $valid;
},10,2);
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_key, $values) {
    $days=(int)get_post_meta($item->get_product_id(),'_wuai_membership_days',true);
    if ($days) { $item->add_meta_data('_wuai_membership_days',$days,true); }
},10,3);
add_action('woocommerce_check_cart_items', function () {
    if(is_user_logged_in() && !wuai_sluyu_banned(get_current_user_id()))return;
    if(!WC()->cart)return;
    foreach(WC()->cart->get_cart() as $item) { if((int)get_post_meta($item['product_id'],'_wuai_membership_days',true)) { wc_add_notice('会员订单必须登录后结算。','error'); break; } }
});

function wuai_sluyu_membership_order_changed($order_id) {
    return wuai_sluyu_membership_sync($order_id);
}
add_action('woocommerce_order_status_changed','wuai_sluyu_membership_order_changed');
add_action('woocommerce_payment_complete','wuai_sluyu_membership_order_changed');
add_action('woocommerce_order_refunded','wuai_sluyu_membership_order_changed');
add_action('wuai_sluyu_retry_membership','wuai_sluyu_membership_order_changed');

function wuai_sluyu_resource_access($post_id) {
    return wuai_sluyu_download_access($post_id);
}
