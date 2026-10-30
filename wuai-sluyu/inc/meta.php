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
    ];
    echo '<div class="wuai-sluyu-meta-box">';
    foreach ($fields as $key => $label) {
        $value = get_post_meta($post->ID, '_wuai_sluyu_' . $key, true);
        printf('<p><label for="wuai_sluyu_%1$s">%2$s</label><input type="text" id="wuai_sluyu_%1$s" name="wuai_sluyu_%1$s" value="%3$s" class="widefat"></p>', esc_attr($key), esc_html($label), esc_attr($value));
    }
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
    foreach (['version', 'platform', 'download_url', 'download_password'] as $key) {
        if (isset($_POST['wuai_sluyu_' . $key])) {
            update_post_meta($post_id, '_wuai_sluyu_' . $key, sanitize_text_field(wp_unslash($_POST['wuai_sluyu_' . $key])));
        }
    }
    update_post_meta($post_id, '_wuai_sluyu_featured', isset($_POST['wuai_sluyu_featured']) ? '1' : '0');
});
