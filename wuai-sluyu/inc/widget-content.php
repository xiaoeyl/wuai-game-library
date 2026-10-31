<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_public_coupons($limit=30) {
    if (!class_exists('WooCommerce') || !class_exists('WC_Coupon')) { return []; }
    $codes=array_slice(array_unique(array_filter(array_map('trim',explode("\n",wuai_sluyu_option('public_coupon_codes',''))))),0,30);
    $coupons=[];
    foreach($codes as $code) {
        $coupon=new WC_Coupon($code);
        if (!$coupon->get_id() || get_post_status($coupon->get_id())!=='publish' || ($coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp()<=time())) { continue; }
        if ($coupon->get_usage_limit() && $coupon->get_usage_count()>=$coupon->get_usage_limit()) { continue; }
        $coupons[]=$coupon;
        if(count($coupons)>=max(1,min(30,(int)$limit)))break;
    }
    return $coupons;
}

function wuai_sluyu_extended_widget($mode,$count,$instance,$args) {
    if(!in_array($mode,['about','user','toc','download','products','coupons','listings'],true))return false;
    if($mode==='about') {
        $options=wuai_sluyu_get_options();
        $image=!empty($instance['image'])?$instance['image']:($options['site_logo']?:wp_get_attachment_image_url(get_theme_mod('custom_logo'),'full'));
        if(!$image)$image=wuai_sluyu_asset('images/logo.png');
        echo '<a href="'.esc_url(home_url('/')).'"><img class="sluyu-widget-brand" src="'.esc_url($image).'" alt="'.esc_attr(get_bloginfo('name')).'" loading="lazy"></a><p>'.esc_html($options['footer_description']).'</p><a class="sluyu-action-plain" href="'.esc_url(wuai_sluyu_portal_url('gyuwo')).'">了解本站</a>';
    } elseif($mode==='user') {
        $uid=get_current_user_id();
        if(!$uid) {
            echo '<p>登录后查看自己的积分、私信和会员状态。</p><div class="sluyu-button-row"><a class="sluyu-action-primary" href="'.esc_url(wp_login_url(wuai_sluyu_portal_url('account'))).'">登录账号</a>';
            if(get_option('users_can_register'))echo '<a class="sluyu-action-plain" href="'.esc_url(wp_registration_url()).'">注册账号</a>';
            echo '</div>';
        } else {
            $user=wp_get_current_user();
            echo '<div class="sluyu-profile-heading">'.get_avatar($uid,44).'<a href="'.esc_url(wuai_sluyu_portal_url('account')).'">'.esc_html($user->display_name).'</a></div><div class="sluyu-stat-grid"><div><strong>'.wuai_sluyu_points($uid).'</strong><span>我的积分</span></div><div><strong>'.wuai_sluyu_message_unread($uid).'</strong><span>未读私信</span></div></div><p>'.(wuai_sluyu_banned($uid)?'账号暂不能参与互动':(wuai_sluyu_member($uid)?'会员当前有效':'普通社区账号')).'</p><div class="sluyu-button-row"><a class="sluyu-action-plain" href="'.esc_url(wuai_sluyu_portal_url('account')).'">个人中心</a><a class="sluyu-action-plain" href="'.esc_url(wuai_sluyu_portal_url('directmessage')).'">查看私信</a></div>';
        }
    } elseif($mode==='toc' || $mode==='download') {
        $id=is_singular(['post','page','sluyu_topic']) && !wuai_sluyu_portal_key()?get_queried_object_id():0;
        if(!$id || post_password_required($id)) { echo '<p class="sluyu-muted">'.($id?'请先解锁文章密码。':'打开文章后显示此功能。').'</p>'; }
        elseif($mode==='toc') { echo '<div class="sluyu-toc-card" data-sluyu-toc-container hidden><nav class="sluyu-toc" aria-label="文章目录"></nav></div><p class="sluyu-muted sluyu-toc-fallback">有分节标题时自动生成目录。</p>'; }
        elseif(!wuai_sluyu_post_meta($id,'download_url') || wuai_sluyu_option('show_resource_box','1')!=='1') { echo '<p class="sluyu-muted">本文没有开放下载入口。</p>'; }
        else { wuai_sluyu_download_card($id,true,$args['widget_id']??wp_unique_id('widget-')); }
    } elseif($mode==='products') {
        if(!function_exists('wc_get_products')) { echo '<p class="sluyu-muted">商城尚未配置，暂没有可购买的商品。</p>'; }
        else {
            $products=wc_get_products(['status'=>'publish','visibility'=>'catalog','limit'=>$count,'orderby'=>'date','order'=>'DESC']); $shown=0;
            echo '<div class="sluyu-widget-products">';
            foreach($products as $product) {
                if(!$product->is_visible() || get_post_field('post_password',$product->get_id()))continue;
                $shown++;
                echo '<a href="'.esc_url($product->get_permalink()).'">'.wp_kses_post($product->get_image('woocommerce_thumbnail',['loading'=>'lazy','alt'=>''])).'<span><strong>'.esc_html($product->get_name()).'</strong><small>'.wp_kses_post($product->get_price_html()).'</small>'.(!$product->is_in_stock()?'<small>暂时缺货</small>':'').'</span></a>';
            }
            echo '</div>'; if(!$shown)echo '<p class="sluyu-muted">暂无公开商品。</p>';
            echo '<a class="sluyu-action-plain" href="'.esc_url(wuai_sluyu_portal_url('shop')).'">前往商城</a>';
        }
    } elseif($mode==='coupons') {
        $coupons=wuai_sluyu_public_coupons($count);
        foreach($coupons as $coupon) {
            $amount=$coupon->get_discount_type()==='percent'?$coupon->get_amount().'%':wp_strip_all_tags(wc_price($coupon->get_amount()));
            echo '<article class="sluyu-coupon sluyu-widget-coupon"><strong>'.esc_html($amount).' 优惠</strong><p><code>'.esc_html($coupon->get_code()).'</code></p><button type="button" class="sluyu-action-plain" data-sluyu-copy="'.esc_attr($coupon->get_code()).'">复制优惠码</button></article>';
        }
        if(!$coupons)echo '<p class="sluyu-muted">暂无站长公开发放的有效优惠券。</p>';
        echo '<p class="sluyu-muted">商品、金额及使用资格由结算时校验。</p><a href="'.esc_url(wuai_sluyu_portal_url('coupons')).'">全部公开优惠券 →</a>';
    } else {
        $query=new WP_Query(array_merge(wuai_sluyu_listing_query_args(['listing_state'=>'open']),['posts_per_page'=>$count,'paged'=>1,'no_found_rows'=>true]));
        echo '<div class="sluyu-widget-list">';
        foreach($query->posts as $post) { $listing=wuai_sluyu_listing_data($post->ID); if(!$listing)continue; echo '<a href="'.esc_url(get_permalink($post)).'"><span>'.esc_html($post->post_title).'<small>'.esc_html(wuai_sluyu_listing_types()[$listing['type']]).'</small></span></a>'; }
        echo '</div>'; if(!$query->post_count)echo '<p class="sluyu-muted">暂无有效期内的公开供求。</p>';
        echo '<a href="'.esc_url(wuai_sluyu_portal_url('listings')).'">供求广场 →</a>';
    }
    return true;
}
