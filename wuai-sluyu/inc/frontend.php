<?php
if (!defined('ABSPATH')) {
    exit;
}

function wuai_sluyu_icon($name) {
    return '<i class="b2font b2-' . esc_attr($name) . '" aria-hidden="true"></i>';
}

function wuai_sluyu_page_link($option, $slugs = []) {
    $options = wuai_sluyu_get_options();
    if (!empty($options[$option])) {
        return $options[$option];
    }
    foreach ($slugs as $slug) {
        $page = get_page_by_path($slug);
        if ($page && $page->post_status === 'publish') {
            return get_permalink($page);
        }
    }
    return '';
}

function wuai_sluyu_service_attributes($url, $panel) {
    if ($url) {
        return 'href="' . esc_url($url) . '"';
    }
    return 'href="#sluyu-dialog" data-sluyu-panel="' . esc_attr($panel) . '"';
}

function wuai_sluyu_banner($image, $url, $class = '') {
    if (!$image) {
        return;
    }
    echo '<div class="sluyu-reference-banner ' . esc_attr($class) . '">';
    if ($url) {
        echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">';
    }
    echo '<img src="' . esc_url($image) . '" alt="社群交流" loading="lazy">';
    if ($url) {
        echo '</a>';
    }
    echo '</div>';
}

function wuai_sluyu_like_count($post_id) {
    $query = new WP_User_Query(['meta_key' => '_wuai_sluyu_like_' . absint($post_id), 'meta_value' => '1', 'number' => 1, 'count_total' => true, 'fields' => 'ID']);
    return (int) $query->get_total();
}

function wuai_sluyu_frontend_action() {
    check_ajax_referer('wuai_sluyu', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => '请先登录后再操作。', 'login' => wp_login_url()], 401);
    }
    $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
    $user_id = get_current_user_id();
    if ($operation === 'checkin') {
        $today = wp_date('Y-m-d');
        $previous = get_user_meta($user_id, '_wuai_sluyu_checkin_date', true);
        if ($previous !== $today) {
            $days = (int) get_user_meta($user_id, '_wuai_sluyu_checkin_days', true) + 1;
            update_user_meta($user_id, '_wuai_sluyu_checkin_date', $today);
            update_user_meta($user_id, '_wuai_sluyu_checkin_days', $days);
        }
        wp_send_json_success(['message' => $previous === $today ? '今天已经签到啦。' : '签到成功，明天再来！', 'days' => (int) get_user_meta($user_id, '_wuai_sluyu_checkin_days', true)]);
    }
    if ($operation === 'like') {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish' || post_password_required($post)) {
            wp_send_json_error(['message' => '这篇文章暂时无法操作。'], 403);
        }
        $key = '_wuai_sluyu_like_' . $post_id;
        $liked = get_user_meta($user_id, $key, true) !== '1';
        if ($liked) {
            update_user_meta($user_id, $key, '1');
        } else {
            delete_user_meta($user_id, $key);
        }
        wp_send_json_success(['liked' => $liked, 'count' => wuai_sluyu_like_count($post_id), 'message' => $liked ? '感谢你的喜欢' : '已取消喜欢']);
    }
    wp_send_json_error(['message' => '无效的操作。'], 400);
}
add_action('wp_ajax_wuai_sluyu_frontend', 'wuai_sluyu_frontend_action');
add_action('wp_ajax_nopriv_wuai_sluyu_frontend', 'wuai_sluyu_frontend_action');

add_action('template_redirect', function () {
    if (!is_singular('post') || is_preview() || current_user_can('edit_posts')) {
        return;
    }
    $post = get_queried_object();
    if ($post instanceof WP_Post && $post->post_status === 'publish' && !post_password_required($post)) {
        update_post_meta($post->ID, '_wuai_sluyu_views', (int) get_post_meta($post->ID, '_wuai_sluyu_views', true) + 1);
    }
});

function wuai_sluyu_download_card($post_id, $compact = false) {
    $options = wuai_sluyu_get_options();
    $url = wuai_sluyu_post_meta($post_id, 'download_url');
    if (!$url || $options['show_resource_box'] !== '1' || post_password_required($post_id)) {
        return;
    }
    $code = wuai_sluyu_post_meta($post_id, 'download_password');
    $version = wuai_sluyu_post_meta($post_id, 'version');
    $platform = wuai_sluyu_post_meta($post_id, 'platform');
    ?>
    <section class="sluyu-resource-card<?php echo $compact ? ' is-compact' : ''; ?>"<?php if (!$compact) : ?> id="download-box"<?php endif; ?>>
        <div class="sluyu-resource-permissions"><h3><?php echo wuai_sluyu_icon('download-cloud-line1'); ?> 下载权限</h3><dl><div><dt>游客</dt><dd>免费下载</dd></div><div><dt>注册用户</dt><dd>免费下载</dd></div></dl><p>此资源由作者公开分享</p></div>
        <div class="sluyu-resource-info"><h2><?php echo esc_html($options['download_title']); ?></h2><dl>
            <?php if ($version) : ?><div><dt>版本</dt><dd><?php echo esc_html($version); ?></dd></div><?php endif; ?>
            <?php if ($platform) : ?><div><dt>运行平台</dt><dd><?php echo esc_html($platform); ?></dd></div><?php endif; ?>
            <div><dt>下载方式</dt><dd>网盘下载</dd></div><?php if ($code) : ?><div><dt>提取码</dt><dd><?php echo esc_html($code); ?></dd></div><?php endif; ?>
        </dl><p class="sluyu-download-access"><?php echo wuai_sluyu_icon('check-line'); ?> 您已获得下载权限</p>
        <div class="sluyu-download-actions"><a class="sluyu-action-primary" href="<?php echo esc_url($url); ?>" target="_blank" rel="nofollow noopener"><?php echo wuai_sluyu_icon('download-cloud-line'); ?> 立即下载</a>
            <button type="button" class="sluyu-action-plain" data-sluyu-copy="<?php echo esc_attr($code ?: $url); ?>" data-copy-success="已复制"><?php echo $code ? '复制提取码' : '复制链接'; ?></button>
        </div><p class="sluyu-download-note">下载前请阅读文章说明，链接失效可在评论区反馈。</p></div>
    </section>
    <?php
}

function wuai_sluyu_comment($comment, $args, $depth) {
    ?>
    <li <?php comment_class('sluyu-comment-item'); ?> id="comment-<?php comment_ID(); ?>">
        <div class="sluyu-comment-body" id="div-comment-<?php comment_ID(); ?>">
            <?php echo get_avatar($comment, 40); ?>
            <div class="sluyu-comment-detail"><div class="sluyu-comment-byline"><strong><?php comment_author_link(); ?></strong><time datetime="<?php echo esc_attr(get_comment_date('c')); ?>"><?php echo esc_html(get_comment_date('Y-m-d H:i')); ?></time></div>
            <?php if ($comment->comment_approved === '0') : ?><p class="sluyu-comment-pending">你的评论正在等待审核。</p><?php endif; ?>
            <div class="sluyu-comment-text"><?php comment_text(); ?></div>
            <?php comment_reply_link(array_merge($args, ['depth' => $depth, 'max_depth' => $args['max_depth'], 'reply_text' => '回复', 'before' => '<div class="sluyu-comment-reply">', 'after' => '</div>'])); ?>
            </div>
        </div>
    <?php
}
