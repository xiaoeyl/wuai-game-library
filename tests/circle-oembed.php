<?php
// Core oEmbed integration only. Run serially on an explicitly selected disposable QA site.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT');
$resolved = $root ? realpath($root) : false;
$qa_roots = [
    realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site'),
    realpath(dirname(__DIR__) . '/dist/checks-mysql/site'),
];
$selected_qa = false;
foreach ($qa_roots as $candidate) {
    if ($candidate && $resolved && strcasecmp($candidate, $resolved) === 0) { $selected_qa = true; }
}
if (!$selected_qa || !is_file($resolved . '/wp-load.php')) {
    throw new RuntimeException('An explicitly selected disposable WP_QA_ROOT is required.');
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require $resolved . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_circle_review_held')) {
    throw new RuntimeException('Integrated local circle moderation is required.');
}

function cob_check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
function cob_as($uid) {
    wp_set_current_user(0);
    wp_set_current_user($uid);
    $_POST = [];
    $GLOBALS['current_screen'] = null;
}
function cob_rest($uid, $url, $method = 'GET', $context = null) {
    cob_as($uid);
    $request = new WP_REST_Request($method, '/oembed/1.0/embed');
    $request->set_param('url', $url);
    $request->set_param('maxwidth', 600);
    if ($context !== null) { $request->set_param('context', $context); }
    return rest_do_request($request);
}
function cob_no_leak($value, $markers, $label) {
    $text = is_string($value) ? $value : wp_json_encode($value);
    foreach ($markers as $marker) {
        cob_check(strpos((string) $text, $marker) === false, $label . ' does not expose ' . $marker . '.');
    }
    cob_check(strpos((string) $text, '<iframe') === false && strpos((string) $text, 'wp-embedded-content') === false, $label . ' contains no embed HTML.');
}

$old_user = get_current_user_id();
$old_post = $_POST;
$old_get = $_GET;
$old_screen = $GLOBALS['current_screen'] ?? null;
$old_global_post = $GLOBALS['post'] ?? null;
$old_rewrite = $GLOBALS['wp_rewrite'];
$old_topic_type = get_post_type_object('sluyu_topic');
$old_permalink = get_option('permalink_structure', null);
$old_rules = get_option('rewrite_rules', null);
$users = [];
$posts = [];
$attachment = 0;
$prefix = 'qa_circle_oembed_' . strtolower(wp_generate_password(8, false, false));
$title_marker = 'COB-TITLE-' . wp_generate_uuid4();
$author_marker = 'COB-AUTHOR-' . wp_generate_uuid4();
$thumbnail_marker = 'COB-THUMBNAIL-' . wp_generate_uuid4();
$markers = [$title_marker, $author_marker, $thumbnail_marker];
$block_mail = static function () { return false; };
$block_http = static function () { return new WP_Error('cob_no_external_http', 'No HTTP requests are allowed during this fixture.'); };
add_filter('pre_wp_mail', $block_mail, PHP_INT_MAX);
add_filter('pre_http_request', $block_http, PHP_INT_MAX, 3);
try {
    // Plain CPT query permalinks are not discoverable by core url_to_postid().
    // Use genuine pretty links without touching web-server rewrite files.
    $GLOBALS['wp_rewrite'] = clone $old_rewrite;
    $GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
    $topic_type = clone $old_topic_type;
    $topic_type->rewrite = array_merge([
        'slug' => 'topic',
        'with_front' => true,
        'pages' => true,
        'feeds' => (bool) $topic_type->has_archive,
        'ep_mask' => EP_PERMALINK,
    ], (array) $topic_type->rewrite);
    if (!$topic_type->has_archive) { $topic_type->rewrite['feeds'] = false; }
    $GLOBALS['wp_post_types']['sluyu_topic'] = $topic_type;
    $topic_type->add_rewrite_rules();
    flush_rewrite_rules(false);
    cob_check(get_option('permalink_structure') === '/%postname%/', 'The standard-link fixture uses genuine pretty permalinks.');
    foreach (['author' => 'subscriber', 'editor' => 'editor'] as $name => $role) {
        $uid = wp_create_user($prefix . '_' . $name, 'qa-local-only', $prefix . '_' . $name . '@example.test');
        cob_check(!is_wp_error($uid), 'Create dedicated oEmbed fixture user.');
        $users[$name] = (int) $uid;
        (new WP_User($uid))->set_role($role);
    }
    $author = $users['author'];
    $editor = $users['editor'];
    wp_update_user(['ID' => $author, 'display_name' => $author_marker]);
    cob_as($editor);
    cob_check(wuai_sluyu_circle_review_editor(), 'The edit-context control uses a complete trusted site editor.');
    $topic = wp_insert_post([
        'post_type' => 'sluyu_topic',
        'post_author' => $author,
        'post_status' => 'publish',
        'post_title' => $title_marker,
        'post_name' => $prefix,
        'post_content' => 'Public fixture prompt for the local oEmbed isolation regression.',
        'post_excerpt' => 'Public fixture excerpt for the local oEmbed control.',
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'meta_input' => ['_wuai_topic_kind' => 'topic'],
    ], true);
    cob_check(!is_wp_error($topic) && $topic, 'Create the published topic control.');
    $topic = (int) $topic;
    $posts[] = $topic;
    // A database-only image fixture makes thumbnail exposure observable without filesystem writes.
    $uploads = wp_get_upload_dir();
    cob_check(empty($uploads['error']) && realpath($uploads['basedir']), 'The isolated WordPress uploads directory exists.');
    $upload_base = wp_normalize_path(realpath($uploads['basedir']));
    $qa_base = wp_normalize_path($resolved);
    cob_check(stripos($upload_base . '/', $qa_base . '/') === 0, 'The thumbnail virtual path stays inside this explicitly selected QA site.');
    $thumbnail_relative = 'qa-fixtures/' . $thumbnail_marker . '.png';
    $thumbnail_file = $upload_base . '/' . $thumbnail_relative;
    cob_check(!file_exists($thumbnail_file), 'The random thumbnail path is nonexistent; no real media is borrowed.');
    $thumbnail_url = trailingslashit($uploads['baseurl']) . $thumbnail_relative;
    $attachment = wp_insert_attachment([
        'post_author' => $editor,
        'post_status' => 'inherit',
        'post_title' => $thumbnail_marker,
        'post_mime_type' => 'image/png',
        'guid' => $thumbnail_url,
    ], false, $topic, true);
    cob_check(!is_wp_error($attachment) && $attachment, 'Create database-only thumbnail attachment.');
    $attachment = (int) $attachment;
    update_post_meta($attachment, '_wp_attached_file', $thumbnail_relative);
    update_post_meta($attachment, '_wp_attachment_metadata', ['width' => 1200, 'height' => 630, 'file' => $thumbnail_relative, 'sizes' => []]);
    cob_check(wp_normalize_path(get_attached_file($attachment)) === $thumbnail_file && wp_attachment_is_image($attachment), 'Core recognizes the virtual image entirely within the selected QA upload root.');
    update_post_meta($topic, '_thumbnail_id', $attachment);
    $urls = ['id_shortlink' => home_url('/?p=' . $topic), 'standard_permalink' => get_permalink($topic)];
    cob_check(strpos($urls['standard_permalink'], '?') === false && (int) url_to_postid($urls['standard_permalink']) === $topic, 'The standard URL resolves through real core rewrite rules.');

    foreach ([0, $author, $editor] as $uid) {
        foreach ($urls as $kind => $url) {
            foreach (['GET', 'HEAD'] as $method) {
                $response = cob_rest($uid, $url, $method);
                cob_check($response->get_status() === 200, 'Unheld ' . $kind . ' oEmbed ' . $method . ' returns 200 for role ' . $uid . '.');
                $data = $response->get_data();
                cob_check($data['title'] === $title_marker && $data['author_name'] === $author_marker, 'Unheld oEmbed genuinely returns the title and author markers.');
                cob_check(strpos($data['thumbnail_url'] ?? '', $thumbnail_marker) !== false && strpos($data['html'] ?? '', '<iframe') !== false, 'Unheld oEmbed genuinely returns the thumbnail and iframe.');
            }
            cob_as($uid);
            $data = get_oembed_response_data_for_url($url, ['width' => 600]);
            cob_check(is_object($data) && $data->title === $title_marker && strpos($data->thumbnail_url, $thumbnail_marker) !== false, 'Unheld core URL discovery has a positive data/thumbnail control.');
        }
        cob_as($uid);
        $direct = get_oembed_response_data($topic, 600);
        cob_check(is_array($direct) && $direct['title'] === $title_marker && $direct['author_name'] === $author_marker, 'Unheld core direct-ID helper has a positive control.');
        cob_check(is_post_embeddable($topic), 'Unheld public topic is embeddable.');
        cob_check(strpos(get_post_embed_html(600, 400, $topic), $title_marker) !== false, 'Unheld direct HTML helper has a positive title control.');
    }

    cob_as($editor);
    update_post_meta($topic, wuai_sluyu_circle_review_hold_key(), wp_generate_uuid4());
    cob_check(get_post_status($topic) === 'publish' && wuai_sluyu_circle_review_held($topic), 'Quarantine keeps a genuinely published core post status.');
    foreach ([0, $author, $editor] as $uid) {
        foreach ($urls as $kind => $url) {
            foreach (['GET', 'HEAD'] as $method) {
                foreach ([null, 'view', 'edit'] as $context) {
                    $response = cob_rest($uid, $url, $method, $context);
                    cob_check($response->get_status() === 404, 'Held ' . $kind . ' oEmbed ' . $method . ' returns 404 even for trusted editor/default or supplied context.');
                    cob_no_leak($response->get_data(), $markers, 'Held REST oEmbed response');
                }
            }
            cob_as($uid);
            cob_check((int) apply_filters('oembed_request_post_id', $topic, $url) === 0, 'oEmbed ID discovery masks held topics before data generation.');
            $data = get_oembed_response_data_for_url($url, ['width' => 600]);
            cob_check(!$data, 'Core local URL discovery does not bypass quarantine.');
            cob_no_leak($data, $markers, 'Held core URL helper');
        }
        cob_as($uid);
        $direct = get_oembed_response_data($topic, 600);
        cob_check(!$direct, 'Core direct-ID oEmbed data remains hidden without WP_Query or REST.');
        cob_no_leak($direct, $markers, 'Held core direct-ID helper');
        cob_check(!is_post_embeddable($topic), 'Held topic is not embeddable even for its author or trusted editor.');
        $html = get_post_embed_html(600, 400, $topic);
        cob_check(!$html, 'Core direct HTML helper returns no held title/link/iframe.');
        cob_no_leak($html, $markers, 'Held core HTML helper');
    }

    cob_as($editor);
    delete_post_meta($topic, wuai_sluyu_circle_review_hold_key());
    foreach ($urls as $kind => $url) {
        $response = cob_rest(0, $url);
        cob_check($response->get_status() === 200 && $response->get_data()['title'] === $title_marker, 'Clearing hold restores ordinary ' . $kind . ' oEmbed without stale denial.');
    }
    echo "PASS: anonymous/author/trusted-editor oEmbed shortlink and canonical-link controls, held GET/HEAD/context denial, title/author/thumbnail/iframe non-disclosure, core direct-ID/URL/HTML helper isolation, and release recovery.\n";
} finally {
    // Restore options and the original untouched global before cleanup can fail.
    if ($old_permalink === null) { delete_option('permalink_structure'); }
    else { update_option('permalink_structure', $old_permalink); }
    if ($old_rules === null) { delete_option('rewrite_rules'); }
    else { update_option('rewrite_rules', $old_rules); }
    $GLOBALS['wp_rewrite'] = $old_rewrite;
    $GLOBALS['wp_post_types']['sluyu_topic'] = $old_topic_type;
    $_POST = [];
    $GLOBALS['current_screen'] = null;
    cob_as($users['editor'] ?? 0);
    if ($attachment && !is_wp_error($attachment)) {
        // Remove all filesystem coordinates before calling core attachment deletion.
        delete_post_meta($attachment, '_wp_attached_file');
        delete_post_meta($attachment, '_wp_attachment_metadata');
        delete_post_meta($attachment, '_wp_attachment_backup_sizes');
        wp_delete_attachment($attachment, true);
    }
    foreach ($posts as $id) { wp_delete_post($id, true); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $uid) { wp_delete_user($uid); }
    remove_filter('pre_wp_mail', $block_mail, PHP_INT_MAX);
    remove_filter('pre_http_request', $block_http, PHP_INT_MAX);
    wp_set_current_user($old_user);
    $_POST = $old_post;
    $_GET = $old_get;
    $GLOBALS['current_screen'] = $old_screen;
    $GLOBALS['post'] = $old_global_post;
}
