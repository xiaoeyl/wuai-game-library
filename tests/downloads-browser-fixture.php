<?php
// Dedicated local-only download fixtures. Run immediately before downloads.cjs.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('Set WP_QA_ROOT to the disposable installation.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771') { throw new RuntimeException('Refusing to seed a non-QA site.'); }
if (!function_exists('wuai_sluyu_download_handoff')) { throw new RuntimeException('Integrate downloads.php first.'); }
function downloads_browser_check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
downloads_browser_check(wuai_sluyu_option('show_resource_box', '1') === '1', 'Enable resource cards on the disposable QA site.');
downloads_browser_check(!is_active_sidebar('sluyu-sidebar'), 'This regression requires the default empty sluyu-sidebar widget area.');
$old_user = get_current_user_id(); $old_post = $_POST;
$users = []; $posts = []; $codes = []; $versions = [];
$prefix = 'QA Downloads Browser'; $target = 'https://example.test/download-secret.zip';
$initial_points = 100; $cost = 25;
$code_prefix = 'QA-DL-CODE-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 10);
try {
    $_POST = []; wp_set_current_user(0);
    // Owner is only a fixture author; the browser logs in as reader and other.
    foreach (['owner','reader','other'] as $name) {
        $login = 'qa_downloads_' . $name; $existing = get_user_by('login', $login);
        $id = $existing ? $existing->ID : wp_create_user($login, 'qa-local-only', $login . '@example.test');
        downloads_browser_check(!is_wp_error($id) && $id, 'Create dedicated download account.');
        $id = (int) $id; $users[$name] = $id;
        (new WP_User($id))->set_role('subscriber');
        downloads_browser_check(!is_wp_error(wp_update_user(['ID'=>$id, 'display_name'=>'Downloads Browser ' . ucfirst($name), 'description'=>'', 'user_pass'=>'qa-local-only'])), 'Reset dedicated account password.');
        foreach (['_wuai_banned_until','_wuai_member_until','_wuai_verified_title'] as $key) { delete_user_meta($id, $key); }
        $wpdb->delete(wuai_sluyu_table('events'), ['user_id'=>$id]);
        $wpdb->delete(wuai_sluyu_table('memberships'), ['user_id'=>$id]);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . wuai_sluyu_table('messages') . ' WHERE sender_id=%d OR recipient_id=%d', $id, $id));
        $owned = get_posts(['post_type'=>['post','sluyu_topic'], 'post_status'=>['publish','pending','draft','private','future','trash'], 'author'=>$id, 'numberposts'=>-1]);
        foreach ($owned as $post) {
            if (strpos($post->post_title, $prefix . ' ') === 0) { wp_delete_post($post->ID, true); }
        }
    }
    $old_circles = get_terms(['taxonomy'=>'sluyu_circle', 'hide_empty'=>false, 'wuai_include_pending'=>true, 'meta_query'=>[['key'=>'_wuai_circle_owner', 'value'=>$users['owner']]]]);
    downloads_browser_check(!is_wp_error($old_circles), 'Find dedicated download circles.');
    foreach ($old_circles as $term) {
        if (strpos($term->name, $prefix . ' ') === 0) {
            $wpdb->delete(wuai_sluyu_table('memberships'), ['circle_id'=>$term->term_id]);
            wp_delete_term($term->term_id, 'sluyu_circle');
        }
    }
    $circle = wp_insert_term($prefix . ' Circle', 'sluyu_circle', ['slug'=>'qa-downloads-browser-circle', 'description'=>'Dedicated local download permission fixture.']);
    downloads_browser_check(!is_wp_error($circle), 'Create download circle.'); $circle = (int) $circle['term_id'];
    update_term_meta($circle, '_wuai_circle_status', 'publish');
    update_term_meta($circle, '_wuai_circle_owner', $users['owner']);
    update_term_meta($circle, '_wuai_circle_join', 'member');
    update_term_meta($circle, '_wuai_circle_posting', 'members');
    foreach (['public','public_no_code','login','member','comment','circle','points'] as $key) {
        $mode = $key === 'public_no_code' ? 'public' : $key;
        $id = wp_insert_post([
            'post_type'=>$key === 'circle' ? 'sluyu_topic' : 'post', 'post_status'=>'publish', 'post_author'=>$users['owner'],
            'post_title'=>$prefix . ' ' . ucfirst(str_replace('_', ' ', $key)),
            'post_content'=>'<h2>Public resource summary</h2><p>The external file URL is stored only in private download settings. Do not navigate to the external fixture destination.</p>',
            'comment_status'=>'open', 'ping_status'=>'closed',
        ], true);
        downloads_browser_check(!is_wp_error($id) && $id, 'Create resource: ' . $key);
        $id = (int) $id; $posts[$key] = $id;
        $codes[$key] = $key === 'public_no_code' ? '' : $code_prefix . '-' . strtoupper($key);
        $versions[$key] = wp_generate_uuid4();
        $bundle = ['url'=>$target, 'access'=>$mode, 'cost'=>$mode === 'points' ? $cost : 0, 'version'=>$versions[$key], 'password'=>$codes[$key]];
        downloads_browser_check(update_post_meta($id, '_wuai_download_settings', wp_slash($bundle)) !== false, 'Save private resource bundle.');
        downloads_browser_check(wuai_sluyu_download_config($id) === $bundle, 'Read private bundle through the real configuration helper.');
        downloads_browser_check(get_post_meta($id, '_wuai_sluyu_download_url', true) === $target, 'Compatibility metadata reads the bundled target.');
        if ($key === 'circle') {
            update_post_meta($id, '_wuai_topic_kind', 'topic');
            downloads_browser_check(!is_wp_error(wp_set_object_terms($id, [$circle], 'sluyu_circle')), 'Attach circle resource.');
        }
    }
    update_user_meta($users['reader'], '_wuai_member_until', time() + 7 * DAY_IN_SECONDS);
    downloads_browser_check($wpdb->insert(wuai_sluyu_table('memberships'), [
        'circle_id'=>$circle, 'user_id'=>$users['reader'], 'status'=>'active', 'created_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql'),
    ]) === 1, 'Seed the active circle reader.');
    foreach ([[$users['reader'],1],[$users['other'],0],[0,1]] as $comment) {
        downloads_browser_check((bool) wp_insert_comment([
            'comment_post_ID'=>$posts['comment'], 'user_id'=>$comment[0], 'comment_approved'=>$comment[1],
            'comment_author'=>'Downloads browser comment', 'comment_author_email'=>get_userdata($users['other'])->user_email,
            'comment_content'=>'Public permission fixture comment.', 'comment_type'=>'comment',
        ]), 'Seed approved, pending and anonymous comments.');
    }
    foreach (['reader','other'] as $name) {
        downloads_browser_check((bool) wuai_sluyu_event($users[$name], 'qa-downloads-browser-seed', 'qa', $initial_points, 0, 'Downloads browser fixture points.'), 'Seed dedicated points ledger.');
        downloads_browser_check(wuai_sluyu_points($users[$name]) === $initial_points, 'Exact fixture balance.');
    }
    foreach (['public','public_no_code','login','member','comment','circle'] as $key) {
        downloads_browser_check(wuai_sluyu_download_access($posts[$key], $users['reader']), 'Eligible reader: ' . $key);
    }
    downloads_browser_check(!wuai_sluyu_download_access($posts['points'], $users['reader']), 'Reader begins without a purchase.');

    // Issue an authentic, unexpired token first, then revoke membership before HTTP tests.
    wp_set_current_user($users['other']);
    update_user_meta($users['other'], '_wuai_member_until', time() + HOUR_IN_SECONDS);
    $revoked_handoff = wuai_sluyu_download_handoff($posts['member']);
    parse_str(wp_parse_url($revoked_handoff, PHP_URL_QUERY), $revoked_args);
    downloads_browser_check(wuai_sluyu_download_target($revoked_args['resource'], $revoked_args['expires'], $revoked_args['signature']) === $target, 'Issued member token originally resolves.');
    update_user_meta($users['other'], '_wuai_member_until', time() - 60);
    downloads_browser_check(is_wp_error(wuai_sluyu_download_target($revoked_args['resource'], $revoked_args['expires'], $revoked_args['signature'])), 'Member revocation invalidates the authentic token.');
    foreach (['member','comment','circle','points'] as $key) {
        downloads_browser_check(!wuai_sluyu_download_access($posts[$key], $users['other']), 'Other account remains ineligible: ' . $key);
    }
    $manifest = [
        'base'=>home_url(), 'users'=>$users, 'posts'=>$posts, 'codes'=>$codes, 'versions'=>$versions,
        'target'=>$target, 'circle'=>$circle, 'publicPrefix'=>$prefix, 'initialPoints'=>$initial_points, 'cost'=>$cost,
        'revokedHandoff'=>$revoked_handoff, 'revokedExpires'=>(int) $revoked_args['expires'],
    ];
    $output = getenv('WP_QA_OUTPUT') ?: dirname($root);
    if (!is_dir($output)) { downloads_browser_check(wp_mkdir_p($output), 'Create browser output directory.'); }
    downloads_browser_check(file_put_contents(rtrim($output, '/\\') . '/downloads-browser-fixture.json', wp_json_encode($manifest, JSON_PRETTY_PRINT)) !== false, 'Write download manifest.');
    echo "PASS: dedicated download reader/other, six access modes, default-sidebar fixtures, 100-point ledgers and authentic revoked-member token prepared. Run downloads.cjs now; token expires in five minutes.\n";
} finally { $_POST = $old_post; wp_set_current_user($old_user); }
