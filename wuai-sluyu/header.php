<?php
if (!defined('ABSPATH')) {
    exit;
}
$options = wuai_sluyu_get_options();
$nav_items = [];
$locations = get_nav_menu_locations();
if (!empty($locations['primary'])) {
    $items = wp_get_nav_menu_items($locations['primary']);
    foreach ((array) $items as $item) {
        if ((int) $item->menu_item_parent === 0) {
            $nav_items[] = [$item->title, $item->url];
        }
    }
}
$nav_items = $nav_items ?: wuai_sluyu_nav_fallback();
$logo_url = !empty($options['site_logo']) ? $options['site_logo'] : '';
$seo_description = !empty($options['seo_description']) ? $options['seo_description'] : $options['site_description'];
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if (!empty($seo_description)) : ?><meta name="description" content="<?php echo esc_attr($seo_description); ?>"><?php endif; ?>
    <?php if (!empty($options['seo_keywords'])) : ?><meta name="keywords" content="<?php echo esc_attr($options['seo_keywords']); ?>"><?php endif; ?>
    <?php if (!empty($options['site_icon'])) : ?><link rel="icon" href="<?php echo esc_url($options['site_icon']); ?>">
    <?php endif; ?>
    <?php if (!empty($options['enable_og'])) : ?><meta property="og:title" content="<?php echo esc_attr(wp_get_document_title()); ?>"><meta property="og:description" content="<?php echo esc_attr($seo_description); ?>"><meta property="og:url" content="<?php echo esc_url(get_permalink() ?: home_url('/')); ?>"><meta property="og:type" content="website"><?php endif; ?>
    <?php wp_head(); ?>
    <style>:root{--sluyu-pink:<?php echo esc_html($options['primary_color']); ?>;--wuai-pink:<?php echo esc_html($options['primary_color']); ?>;--sluyu-accent:<?php echo esc_html($options['accent_color']); ?>;--sluyu-bg:<?php echo esc_html($options['background_color']); ?>;--sluyu-radius:<?php echo absint($options['border_radius']); ?>px;--sluyu-content-width:<?php echo absint($options['content_width']); ?>px;}</style>
</head>
<body <?php body_class(); ?> data-sluyu-lightbox="<?php echo esc_attr(wuai_sluyu_option('enable_lightbox', '1')); ?>" data-sluyu-loader="<?php echo esc_attr(wuai_sluyu_option('enable_loader', '1')); ?>" data-sluyu-particles="<?php echo esc_attr(wuai_sluyu_option('enable_particles', '0')); ?>" data-sluyu-glass="<?php echo esc_attr(wuai_sluyu_option('glass_cards', '1')); ?>">
<?php wp_body_open(); ?>
<?php if (wuai_sluyu_option('enable_loader', '1')) : ?><div class="sluyu-page-loader wuai-sluyu-loader" aria-hidden="true"><span class="sluyu-loader-orbit"></span><strong><?php echo esc_html($options['site_title'] ?: get_bloginfo('name')); ?></strong></div><?php endif; ?>
<?php if (wuai_sluyu_option('enable_particles', '0')) : ?><div class="sluyu-particles wuai-sluyu-particles" aria-hidden="true"></div><?php endif; ?>
<header class="sluyu-header">
    <div class="sluyu-container sluyu-header-inner">
        <?php if ($logo_url) : ?>
            <a class="sluyu-logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php bloginfo('name'); ?>"><img src="<?php echo esc_url($logo_url); ?>" alt="<?php bloginfo('name'); ?>"></a>
        <?php elseif (has_custom_logo()) : ?>
            <div class="sluyu-logo"><?php the_custom_logo(); ?></div>
        <?php else : ?>
            <a class="sluyu-logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php bloginfo('name'); ?>"><img src="<?php echo esc_url(wuai_sluyu_asset('images/logo.png')); ?>" alt="<?php bloginfo('name'); ?>"></a>
        <?php endif; ?>
        <nav class="sluyu-nav" aria-label="主导航">
            <?php foreach ($nav_items as $item) : ?>
                <?php $nav_icons = ['首页' => '⌂', '茶话会' => '◉', '资讯' => '▤', '游戏' => '🎮', '图包' => '✦', '美图' => '▣', '合集' => '✿', '更多' => '☷']; ?>
                <a href="<?php echo esc_url($item[1]); ?>" class="<?php echo esc_attr(sanitize_title($item[0])); ?>"><span class="sluyu-nav-icon" aria-hidden="true"><?php echo esc_html($nav_icons[$item[0]] ?? ''); ?></span><?php echo esc_html($item[0]); ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sluyu-header-actions">
            <form class="sluyu-header-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
                <span>文章</span><input type="search" name="s" placeholder="搜索" value="<?php echo esc_attr(get_search_query()); ?>"><button type="submit" aria-label="搜索">⌕</button>
            </form>
            <?php if (is_user_logged_in()) : ?>
                <a class="sluyu-login" href="<?php echo esc_url(get_edit_profile_url()); ?>"><?php echo esc_html(wp_get_current_user()->display_name); ?></a>
            <?php else : ?>
                <a class="sluyu-login" href="<?php echo esc_url(wp_login_url()); ?>">登录 | 注册</a>
            <?php endif; ?>
            <button class="sluyu-menu-button" type="button" aria-label="展开菜单" aria-expanded="false">☰</button>
        </div>
    </div>
</header>
