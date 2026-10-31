<?php
if (!defined('ABSPATH')) {
    exit;
}

function wuai_sluyu_option($key, $default = '') {
    $options = get_option('wuai_sluyu_options', []);
    if (array_key_exists($key, (array) $options)) {
        return $options[$key];
    }
    if (function_exists('zib_get_option')) {
        $value = zib_get_option('wuai_sluyu_' . $key);
        if ($value !== '' && $value !== null) {
            return $value;
        }
    }
    return $default;
}

function wuai_sluyu_asset($path) {
    return trailingslashit(WUAI_SLUYU_URI) . 'assets/' . ltrim($path, '/');
}

function wuai_sluyu_image($post_id = 0, $size = 'wuai-sluyu-card') {
    $post_id = $post_id ?: get_the_ID();
    $url = get_the_post_thumbnail_url($post_id, $size);
    return $url ?: wuai_sluyu_asset('images/default.jpg');
}

function wuai_sluyu_excerpt($post_id = 0) {
    $post_id = $post_id ?: get_the_ID();
    $excerpt = get_the_excerpt($post_id);
    if (!$excerpt) {
        $excerpt = get_post_field('post_content', $post_id);
    }
    return wp_trim_words(wp_strip_all_tags($excerpt), 42, '…');
}

function wuai_sluyu_post_meta($post_id = 0, $key = '', $default = '') {
    $value = get_post_meta($post_id ?: get_the_ID(), '_wuai_sluyu_' . $key, true);
    return $value !== '' ? $value : $default;
}

function wuai_sluyu_nav_fallback() {
    return [
        ['首页', home_url('/')],
        ['茶话会', get_permalink(get_option('page_for_posts')) ?: home_url('/forums/')],
        ['资讯', get_category_link(get_cat_ID('资讯')) ?: home_url('/category/news/')],
        ['游戏', get_category_link(get_cat_ID('游戏资源')) ?: home_url('/category/games/')],
        ['图包', get_category_link(get_cat_ID('图包')) ?: home_url('/category/image/')],
        ['美图', get_category_link(get_cat_ID('美图')) ?: home_url('/category/photo/')],
        ['合集', get_category_link(get_cat_ID('合集')) ?: home_url('/category/collection/')],
        ['更多', home_url('/more/')],
    ];
}

function wuai_sluyu_section_categories() {
    $raw = wuai_sluyu_option('sections', "图包\n美图\n游戏资源\n资讯");
    $names = array_values(array_filter(array_map('trim', preg_split('/\r?\n|,/', (string) $raw))));
    $sections = [];
    foreach (array_slice($names, 0, 4) as $name) {
        $term = get_term_by('name', $name, 'category');
        if ($term && !is_wp_error($term)) {
            $sections[] = $term;
        }
    }
    if (!$sections) {
        $sections = get_categories(['hide_empty' => false, 'number' => 4]);
    }
    return $sections;
}

function wuai_sluyu_render_card($post_id = 0, $style = 'standard') {
    $post_id = $post_id ?: get_the_ID();
    $cats = get_the_category($post_id);
    $cat = $cats ? $cats[0] : null;
    $featured = get_post_meta($post_id, '_wuai_sluyu_featured', true);
    $title_badge = wuai_sluyu_post_meta($post_id, 'title_badge');
    $title_badge_color = sanitize_hex_color(wuai_sluyu_post_meta($post_id, 'title_badge_color', '#ef0053')) ?: '#ef0053';
    ?>
    <article class="sluyu-card sluyu-card-<?php echo esc_attr($style); ?><?php echo $featured ? ' is-featured' : ''; ?>">
        <div class="sluyu-card-media">
            <a href="<?php echo esc_url(get_permalink($post_id)); ?>">
                <img src="<?php echo esc_url(wuai_sluyu_image($post_id)); ?>" alt="<?php echo esc_attr(get_the_title($post_id)); ?>" loading="lazy">
            </a>
            <?php if ($featured) : ?><span class="sluyu-card-badge">推荐</span><?php endif; ?>
            <?php if (wuai_sluyu_post_meta($post_id, 'download_url')) : ?><span class="sluyu-card-download">下载</span><?php endif; ?>
        </div>
        <div class="sluyu-card-body">
            <h3><?php if ($title_badge) : ?><span class="sluyu-title-badge" style="--badge-color:<?php echo esc_attr($title_badge_color); ?>"><?php echo esc_html($title_badge); ?></span><?php endif; ?><a href="<?php echo esc_url(get_permalink($post_id)); ?>"><?php echo esc_html(get_the_title($post_id)); ?></a></h3>
            <?php if ($style !== 'overlay') : ?><p><?php echo esc_html(wuai_sluyu_excerpt($post_id)); ?></p><?php endif; ?>
            <div class="sluyu-card-meta"><span><?php echo esc_html($cat ? $cat->name : '资源分享'); ?></span><time><?php echo esc_html(get_the_date('m月d日', $post_id)); ?></time></div>
        </div>
    </article>
    <?php
}
