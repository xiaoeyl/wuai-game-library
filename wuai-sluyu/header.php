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
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
    <style>:root{--sluyu-pink:<?php echo esc_html($options['primary_color']); ?>;}</style>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="sluyu-header">
    <div class="sluyu-container sluyu-header-inner">
        <?php if (has_custom_logo()) : ?>
            <div class="sluyu-logo"><?php the_custom_logo(); ?></div>
        <?php else : ?>
            <a class="sluyu-logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php bloginfo('name'); ?>"><img src="<?php echo esc_url(wuai_sluyu_asset('images/logo.png')); ?>" alt="<?php bloginfo('name'); ?>"></a>
        <?php endif; ?>
        <nav class="sluyu-nav" aria-label="主导航">
            <?php foreach ($nav_items as $item) : ?>
                <a href="<?php echo esc_url($item[1]); ?>" class="<?php echo esc_attr(sanitize_title($item[0])); ?>"><?php echo esc_html($item[0]); ?></a>
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
