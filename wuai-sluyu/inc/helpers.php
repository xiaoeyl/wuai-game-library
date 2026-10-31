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

function wuai_sluyu_query($key, $default = '') {
    if (!isset($_GET[$key])) {
        if ($key==='group' && isset($_GET['circle_id']) && is_scalar($_GET['circle_id'])) { return sanitize_text_field(wp_unslash((string)$_GET['circle_id'])); }
        if ($key==='kind') {
            $route=get_query_var('sluyu_page');
            if (is_string($route) && isset(['write'=>'post','po-ask'=>'question','link-register'=>'link','po-infomation'=>'listing'][$route])) { return ['write'=>'post','po-ask'=>'question','link-register'=>'link','po-infomation'=>'listing'][$route]; }
        }
    }
    return isset($_GET[$key]) && is_scalar($_GET[$key]) ? sanitize_text_field(wp_unslash((string) $_GET[$key])) : $default;
}

function wuai_sluyu_page_number($key = 'pg') {
    return max(1, min(100000, absint(wuai_sluyu_query($key, '1'))));
}

function wuai_sluyu_parse_date($value, $format = 'Y-m-d') {
    if (!is_string($value) || !$value || strlen($value)>100 || strpos($value,"\0")!==false) { return false; }
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value, wp_timezone());
    return $date && $date->format($format) === $value ? $date : false;
}

function wuai_sluyu_image($post_id = 0, $size = 'wuai-sluyu-card') {
    $post_id = $post_id ?: get_the_ID();
    $url = get_the_post_thumbnail_url($post_id, $size);
    return $url ?: wuai_sluyu_asset('images/default.jpg');
}

function wuai_sluyu_excerpt($post_id = 0) {
    $post_id = $post_id ?: get_the_ID();
    if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($post_id)) { return ''; }
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
        ['茶话会', wuai_sluyu_portal_url('circle')],
        ['资讯', wuai_sluyu_portal_url('webxw')],
        ['游戏', wuai_sluyu_portal_url('pcgame')],
        ['图包', wuai_sluyu_portal_url('tuimg')],
        ['美图', wuai_sluyu_portal_url('xiezhenji')],
        ['合集', wuai_sluyu_portal_url('cosheji')],
        ['更多', wuai_sluyu_portal_url('more')],
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
    if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($post_id ?: get_the_ID())) { return; }
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
