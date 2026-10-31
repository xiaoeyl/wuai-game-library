<?php
if (!defined('ABSPATH')) { exit; }
get_header();
$portal_key = wuai_sluyu_portal_key();
$portal_pages = wuai_sluyu_portal_pages();
$portal_info = isset($portal_pages[$portal_key]) ? $portal_pages[$portal_key] : [wuai_sluyu_channel_info($portal_key)[0], '发现值得收藏的内容', 'apps-line'];
?>
<main class="sluyu-container sluyu-main sluyu-portal">
    <header class="sluyu-portal-hero"><div><p class="sluyu-eyebrow">EXPLORE / <?php echo esc_html(strtoupper($portal_key)); ?></p><h1><?php echo esc_html($portal_info[0]); ?></h1><p><?php echo esc_html($portal_info[1]); ?></p></div><a class="sluyu-action-plain" href="<?php echo esc_url(wuai_sluyu_portal_url('more')); ?>">全部服务 <?php echo wuai_sluyu_icon('arrow-right-s-line'); ?></a></header>
    <div class="sluyu-portal-layout"><nav class="sluyu-portal-nav" aria-label="站内服务">
        <?php foreach (['account','mission','task','gold','circle','circles','newsflashes','links','document','directmessage','message','verify','vips','shop','orders','tools','submit'] as $nav_key) : ?><a href="<?php echo esc_url(wuai_sluyu_portal_url($nav_key)); ?>" <?php if ($nav_key === $portal_key) echo 'aria-current="page"'; ?>><?php echo wuai_sluyu_icon($portal_pages[$nav_key][2]); ?><span><?php echo esc_html($portal_pages[$nav_key][0]); ?></span></a><?php endforeach; ?>
    </nav><div class="sluyu-portal-content">
        <?php $native_commerce = false;
        if (is_page() && !post_password_required()) {
            $custom = get_post_field('post_content', get_queried_object_id());
            if ($custom && !has_shortcode($custom,'sluyu_portal')) {
                $native_commerce = in_array($portal_key,['carts','orders','shop'],true) && (has_shortcode($custom,'woocommerce_cart') || has_shortcode($custom,'woocommerce_my_account') || has_block('woocommerce/cart',$custom) || has_block('woocommerce/product-collection',$custom));
                echo '<div class="sluyu-panel sluyu-entry">' . apply_filters('the_content', $custom) . '</div>';
            }
        } ?>
        <?php if (is_page() && post_password_required()) { echo get_the_password_form(); } elseif (!$native_commerce) { wuai_sluyu_render_portal($portal_key); } ?>
    </div></div>
</main>
<?php get_footer(); ?>
