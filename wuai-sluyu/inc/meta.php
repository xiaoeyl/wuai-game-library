<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('add_meta_boxes', function () {
    add_meta_box('wuai_sluyu_resource', '游戏资源信息', 'wuai_sluyu_resource_box', ['post', 'page'], 'side', 'default');
});

function wuai_sluyu_resource_box($post) {
    wp_nonce_field('wuai_sluyu_resource', 'wuai_sluyu_resource_nonce');
    $fields = [
        'version' => '版本号',
        'platform' => '运行平台',
        'download_url' => '网盘下载地址',
        'download_password' => '网盘提取码',
        'title_badge' => '标题徽章（如：热门）',
    ];
    echo '<div class="wuai-sluyu-meta-box">';
    foreach ($fields as $key => $label) {
        $value = get_post_meta($post->ID, '_wuai_sluyu_' . $key, true);
        printf('<p><label for="wuai_sluyu_%1$s">%2$s</label><input type="text" id="wuai_sluyu_%1$s" name="wuai_sluyu_%1$s" value="%3$s" class="widefat"></p>', esc_attr($key), esc_html($label), esc_attr($value));
    }
    $badge_color = get_post_meta($post->ID, '_wuai_sluyu_title_badge_color', true) ?: '#ef0053';
    printf('<p><label for="wuai_sluyu_title_badge_color">标题徽章颜色</label><input type="color" id="wuai_sluyu_title_badge_color" name="wuai_sluyu_title_badge_color" value="%s" class="widefat" style="height:36px;padding:2px"></p>', esc_attr($badge_color));
    $featured = get_post_meta($post->ID, '_wuai_sluyu_featured', true);
    printf('<p><label><input type="checkbox" name="wuai_sluyu_featured" value="1" %s> 首页推荐</label></p>', checked($featured, '1', false));
    echo '</div>';
}

add_action('save_post', function ($post_id) {
    if (!isset($_POST['wuai_sluyu_resource_nonce']) || !wp_verify_nonce($_POST['wuai_sluyu_resource_nonce'], 'wuai_sluyu_resource')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    foreach (['version', 'platform', 'download_url', 'download_password', 'title_badge'] as $key) {
        if (isset($_POST['wuai_sluyu_' . $key])) {
            $value = wp_unslash($_POST['wuai_sluyu_' . $key]);
            update_post_meta($post_id, '_wuai_sluyu_' . $key, $key === 'download_url' ? esc_url_raw($value, ['http', 'https']) : sanitize_text_field($value));
        }
    }
    if (isset($_POST['wuai_sluyu_title_badge_color'])) {
        $color = sanitize_hex_color(wp_unslash($_POST['wuai_sluyu_title_badge_color']));
        update_post_meta($post_id, '_wuai_sluyu_title_badge_color', $color ?: '#ef0053');
    }
    update_post_meta($post_id, '_wuai_sluyu_featured', isset($_POST['wuai_sluyu_featured']) ? '1' : '0');
});
