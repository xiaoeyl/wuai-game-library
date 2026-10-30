<?php
if (!defined('ABSPATH')) {
    exit;
}

define('WUAI_SLUYU_VERSION', '1.0.0');
define('WUAI_SLUYU_DIR', get_stylesheet_directory());
define('WUAI_SLUYU_URI', get_stylesheet_directory_uri());

require_once WUAI_SLUYU_DIR . '/inc/helpers.php';
require_once WUAI_SLUYU_DIR . '/inc/meta.php';
require_once WUAI_SLUYU_DIR . '/inc/admin-options.php';
require_once WUAI_SLUYU_DIR . '/inc/github-updater.php';

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('wuai-sluyu', get_stylesheet_uri(), [], WUAI_SLUYU_VERSION);
    wp_enqueue_style('wuai-sluyu-theme', WUAI_SLUYU_URI . '/assets/css/theme.css', ['wuai-sluyu'], WUAI_SLUYU_VERSION);
    wp_enqueue_script('wuai-sluyu', WUAI_SLUYU_URI . '/assets/js/theme.js', [], WUAI_SLUYU_VERSION, true);
    wp_localize_script('wuai-sluyu', 'wuaiSluyu', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wuai_sluyu'),
    ]);
});

add_action('after_setup_theme', function () {
    add_theme_support('post-thumbnails');
    add_theme_support('title-tag');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('custom-logo', ['height' => 56, 'width' => 180, 'flex-height' => true, 'flex-width' => true]);
    register_nav_menus([
        'primary' => '主导航',
        'footer' => '页脚导航',
    ]);
});

add_filter('body_class', function ($classes) {
    $classes[] = 'wuai-sluyu-site';
    return $classes;
});

add_filter('excerpt_length', function () {
    return 38;
});

add_filter('excerpt_more', function () {
    return '…';
});

add_action('init', function () {
    add_image_size('wuai-sluyu-card', 475, 534, true);
    add_image_size('wuai-sluyu-wide', 960, 240, true);
    if (wuai_sluyu_option('legacy_html_links', '1')) {
        add_rewrite_rule('^([0-9]+)\\.html$', 'index.php?p=$matches[1]', 'top');
        add_rewrite_rule('^([a-z0-9-]+)\\.html$', 'index.php?pagename=$matches[1]', 'top');
    }
});

add_filter('post_link', function ($url, $post) {
    if ($post instanceof WP_Post && $post->post_type === 'post' && wuai_sluyu_option('legacy_html_links', '1')) {
        return home_url('/' . $post->ID . '.html');
    }
    return $url;
}, 10, 2);

add_filter('page_link', function ($url, $post_id) {
    if (wuai_sluyu_option('legacy_html_links', '1')) {
        $post = get_post($post_id);
        if ($post instanceof WP_Post && $post->post_name) {
            return home_url('/' . $post->post_name . '.html');
        }
    }
    return $url;
}, 10, 2);

add_action('after_switch_theme', function () {
    flush_rewrite_rules();
});
