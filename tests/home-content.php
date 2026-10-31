<?php
// Template regression checks with isolated WordPress fixtures; no site writes.
define('ABSPATH', __DIR__);
define('WUAI_SLUYU_URI', 'https://example.test/wp-content/themes/wuai-sluyu');
require __DIR__ . '/../wuai-sluyu/inc/helpers.php';

$fixture_options = [];
$fixture_terms = [];
$fixture_posts = [];
$current_post = null;

function get_option($key, $default = false) {
    return $key === 'wuai_sluyu_options' ? $GLOBALS['fixture_options'] : $default;
}
function wuai_sluyu_get_options() {
    return ['hot_words' => '', 'hero_video' => '', 'hero_image' => '', 'hero_title' => 'Test site', 'hero_notice' => ''];
}
function get_term_by($field, $value, $taxonomy) {
    foreach ($GLOBALS['fixture_terms'] as $term) {
        if ($term->$field === $value) return $term;
    }
    return false;
}
function get_categories($args) { return array_slice($GLOBALS['fixture_terms'], 0, $args['number']); }
function is_wp_error($value) { return false; }
function home_url($path = '/') { return 'https://example.test' . $path; }
function get_category_link($id) { return home_url('/category/' . $id . '/'); }
function get_permalink($id = 0) { return $id ? home_url('/' . $id . '.html') : ''; }
function wp_login_url() { return home_url('/login/'); }
function is_user_logged_in() { return true; }
function get_edit_profile_url() { return home_url('/profile/'); }
function get_header() {}
function get_footer() {}
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function trailingslashit($text) { return rtrim($text, '/') . '/'; }
function sanitize_hex_color($text) { return $text; }
function get_the_ID() { return $GLOBALS['current_post']['id']; }
function the_permalink() { echo esc_url(get_permalink(get_the_ID())); }
function the_title() { echo esc_html(get_the_title(get_the_ID())); }
function fixture_post($id) {
    foreach ($GLOBALS['fixture_posts'] as $post) {
        if ($post['id'] === $id) return $post;
    }
    return [];
}
function get_the_title($id) { return fixture_post($id)['title']; }
function get_the_date($format, $id = 0) { return '10月30日'; }
function get_the_category($id) {
    $post = fixture_post($id);
    return array_values(array_filter($GLOBALS['fixture_terms'], function ($term) use ($post) {
        return $term->term_id === $post['cat'];
    }));
}
function get_post_meta($id, $key, $single) { return ''; }
function get_the_post_thumbnail_url($id, $size) { return home_url('/cover-' . $id . '.jpg'); }
function get_the_excerpt($id) { return 'Published article excerpt'; }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wp_trim_words($text, $count, $more) { return $text; }
function wp_reset_postdata() { $GLOBALS['current_post'] = null; }

class WP_Query {
    private $posts;
    private $position = -1;
    public function __construct($args) {
        // An unrestricted fixture query exposes drafts, like an authorized preview.
        $this->posts = array_values(array_filter($GLOBALS['fixture_posts'], function ($post) use ($args) {
            return (!isset($args['post_status']) || $post['status'] === $args['post_status'])
                && (!isset($args['cat']) || $post['cat'] === $args['cat']);
        }));
        $this->posts = array_slice($this->posts, 0, $args['posts_per_page']);
    }
    public function have_posts() { return $this->position + 1 < count($this->posts); }
    public function the_post() { $GLOBALS['current_post'] = $this->posts[++$this->position]; }
}

function render_home($options, $terms, $posts) {
    $GLOBALS['fixture_options'] = $options;
    $GLOBALS['fixture_terms'] = $terms;
    $GLOBALS['fixture_posts'] = $posts;
    ob_start();
    include __DIR__ . '/../wuai-sluyu/front-page.php';
    return ob_get_clean();
}
function check($result, $message) {
    if (!$result) throw new RuntimeException($message);
}
function main_content($html) {
    return substr($html, strpos($html, '</aside>') + strlen('</aside>'));
}

$empty = render_home([], [], []);
check(strpos($empty, '暂无已发布文章') !== false, 'Empty sites need an honest empty state.');
check(strpos($empty, '<article') === false, 'Empty sites must not show article cards.');
check(strpos($empty, 'sluyu-demo-card') === false, 'Demo cards must not return.');
check(strpos($empty, '今日更新') === false, 'Fabricated update dates must not return.');

$game = (object) ['term_id' => 2, 'name' => '游戏资源'];
$art = (object) ['term_id' => 3, 'name' => '图包'];
$uncategorized = (object) ['term_id' => 1, 'name' => 'Uncategorized'];
$empty_category = render_home(['sections' => '游戏资源'], [$game], []);
check(strpos($empty_category, '<h2>游戏资源</h2>') !== false, 'An existing empty category must keep its heading.');
check(strpos($empty_category, '<article') === false, 'Empty categories must not get filler cards.');

$posts = [
    ['id' => 10, 'cat' => 2, 'title' => 'REAL GAME', 'status' => 'publish'],
    ['id' => 11, 'cat' => 3, 'title' => 'REAL ART', 'status' => 'publish'],
    ['id' => 12, 'cat' => 2, 'title' => 'PRIVATE TITLE', 'status' => 'private'],
    ['id' => 13, 'cat' => 2, 'title' => 'DRAFT TITLE', 'status' => 'draft'],
    ['id' => 14, 'cat' => 2, 'title' => 'FUTURE TITLE', 'status' => 'future'],
    ['id' => 15, 'cat' => 2, 'title' => 'TRASH TITLE', 'status' => 'trash'],
];
$published = render_home(['sections' => "图包\n游戏资源"], [$game, $art], $posts);
$main = main_content($published);
check(substr_count($main, '<article') === 2, 'Only published articles from configured categories should appear.');
check(strpos($main, 'REAL ART') < strpos($main, 'REAL GAME'), 'Category order must follow the saved settings.');
check(strpos($main, '/11.html') !== false && strpos($main, '/10.html') !== false, 'Cards must link to real posts.');
check(strpos($main, 'sluyu-card-overlay') !== false, 'Image categories must retain their card layout.');
foreach (['PRIVATE TITLE', 'DRAFT TITLE', 'FUTURE TITLE', 'TRASH TITLE'] as $hidden_title) {
    check(strpos($published, $hidden_title) === false, 'Unpublished content leaked: ' . $hidden_title);
}
check(strpos($published, '<h2>最新文章</h2>') !== false, 'The sidebar must accurately describe its content.');
check($GLOBALS['current_post'] === null, 'Secondary queries must restore post data.');

$single_category = main_content(render_home(['sections' => '游戏资源'], [$game, $art], $posts));
check(strpos($single_category, 'REAL ART') === false, 'Other categories must not fill a configured section.');
$fallback = render_home([], [$uncategorized], [
    ['id' => 1, 'cat' => 1, 'title' => 'Hello world!', 'status' => 'publish'],
]);
check(strpos(main_content($fallback), 'Hello world!') !== false, 'Existing posts must remain visible when preset categories do not exist.');
echo "PASS: empty sites, empty categories, published content, category order, post links, status filtering and fallback.\n";
