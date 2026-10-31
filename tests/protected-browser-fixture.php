<?php
// CLI-only data for an isolated local browser run; never use a production database.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('Set WP_QA_ROOT to the disposable installation.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771') { throw new RuntimeException('Refusing to seed a non-QA site.'); }
if (!function_exists('wuai_sluyu_protected_validate')) { throw new RuntimeException('Integrate protected-content.php first.'); }

function protected_browser_check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}

$old_user = get_current_user_id(); $old_post = $_POST;
$users = []; $posts = []; $secrets = []; $versions = []; $urls = [];
$public_prefix = 'QA Protected Browser';
$secret_prefix = 'QA-HIDDEN-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 12);
$initial_points = 100; $cost = 30;
try {
    $_POST = []; wp_set_current_user(0);
    foreach (['owner', 'reader', 'other'] as $name) {
        $login = 'qa_protected_' . $name;
        $existing = get_user_by('login', $login);
        $id = $existing ? $existing->ID : wp_create_user($login, 'qa-local-only', $login . '@example.test');
        protected_browser_check(!is_wp_error($id) && $id, 'Create protected browser account.');
        $id = (int) $id; $users[$name] = $id;
        (new WP_User($id))->set_role('subscriber');
        $updated = wp_update_user(['ID'=>$id, 'display_name'=>'Protected Browser ' . ucfirst($name), 'description'=>'', 'user_pass'=>'qa-local-only']);
        protected_browser_check(!is_wp_error($updated), 'Reset dedicated account password.');
        foreach (['_wuai_banned_until', '_wuai_member_until', '_wuai_verified_title'] as $key) { delete_user_meta($id, $key); }
        $wpdb->delete(wuai_sluyu_table('events'), ['user_id'=>$id]);
        $wpdb->delete(wuai_sluyu_table('memberships'), ['user_id'=>$id]);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . wuai_sluyu_table('messages') . ' WHERE sender_id=%d OR recipient_id=%d', $id, $id));
        $owned = get_posts(['post_type'=>['post','sluyu_topic'], 'post_status'=>['publish','pending','draft','private','future','trash'], 'author'=>$id, 'numberposts'=>-1]);
        foreach ($owned as $post) {
            if (strpos($post->post_title, $public_prefix . ' ') === 0) { wp_delete_post($post->ID, true); }
        }
    }
    $old_circles = get_terms(['taxonomy'=>'sluyu_circle', 'hide_empty'=>false, 'wuai_include_pending'=>true, 'meta_query'=>[['key'=>'_wuai_circle_owner', 'value'=>$users['owner']]]]);
    protected_browser_check(!is_wp_error($old_circles), 'Find dedicated fixture circles.');
    foreach ($old_circles as $term) {
        if (strpos($term->name, $public_prefix . ' ') === 0) {
            $wpdb->delete(wuai_sluyu_table('memberships'), ['circle_id'=>$term->term_id]);
            wp_delete_term($term->term_id, 'sluyu_circle');
        }
    }
    $circle = wp_insert_term($public_prefix . ' Circle', 'sluyu_circle', ['slug'=>'qa-protected-browser-circle', 'description'=>'Dedicated local protected-body browser fixture.']);
    protected_browser_check(!is_wp_error($circle), 'Create dedicated protected circle.');
    $circle = (int) $circle['term_id'];
    update_term_meta($circle, '_wuai_circle_owner', $users['owner']);
    update_term_meta($circle, '_wuai_circle_status', 'publish');
    update_term_meta($circle, '_wuai_circle_join', 'member');
    update_term_meta($circle, '_wuai_circle_posting', 'members');

    foreach (['login','member','comment','circle','points','draft','password'] as $key) {
        $type = $key === 'circle' ? 'sluyu_topic' : 'post';
        $status = $key === 'draft' ? 'draft' : 'publish';
        $access = in_array($key, ['draft','password'], true) ? 'login' : $key;
        $id = wp_insert_post([
            'post_type'=>$type, 'post_status'=>$status, 'post_author'=>$users['owner'],
            'post_title'=>$public_prefix . ' ' . ucfirst($key),
            'post_content'=>'<h2>Public fixture summary</h2><p>This summary and its comments are public. The separate private fragment is not stored in this article body.</p>',
            'post_excerpt'=>in_array($key, ['login','points'], true) ? '' : 'Public browser fixture excerpt only.',
            'post_password'=>$key === 'password' ? 'qa-protected-password' : '',
            'comment_status'=>'open', 'ping_status'=>'closed',
        ], true);
        protected_browser_check(!is_wp_error($id) && $id, 'Create protected fixture: ' . $key);
        $id = (int) $id; $posts[$key] = $id; $urls[$key] = get_permalink($id);
        $secrets[$key] = $secret_prefix . '-' . strtoupper($key);
        $config = wuai_sluyu_protected_validate([
            'protected_body'=>'<h3>Private fixture section</h3><p>' . $secrets[$key] . '</p><p>This independent fragment is visible only to an eligible account.</p>',
            'access'=>$access, 'cost'=>(string) $cost,
        ]);
        protected_browser_check(is_array($config), 'Validate private fixture body.');
        protected_browser_check(update_post_meta($id, wuai_sluyu_protected_key(), wp_slash($config)) !== false, 'Save private fixture metadata.');
        $versions[$key] = $config['version'];
        if ($key === 'circle') {
            update_post_meta($id, '_wuai_topic_kind', 'topic');
            protected_browser_check(!is_wp_error(wp_set_object_terms($id, [$circle], 'sluyu_circle')), 'Attach the protected topic to its circle.');
        }
        protected_browser_check(strpos(get_post_field('post_content', $id), $secret_prefix) === false, 'No private body in public post_content.');
        protected_browser_check(strpos(get_post_field('post_excerpt', $id), $secret_prefix) === false, 'No private body in public excerpt.');
    }
    update_user_meta($users['reader'], '_wuai_member_until', time() + 7 * DAY_IN_SECONDS);
    protected_browser_check($wpdb->insert(wuai_sluyu_table('memberships'), [
        'circle_id'=>$circle, 'user_id'=>$users['reader'], 'status'=>'active',
        'created_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql'),
    ]) === 1, 'Seed active reader membership.');
    foreach ([[$users['reader'], 1], [$users['other'], 0], [0, 1]] as $comment) {
        $comment_id = wp_insert_comment([
            'comment_post_ID'=>$posts['comment'], 'user_id'=>$comment[0],
            'comment_author'=>'Protected browser comment',
            // A matching email on an anonymous comment must not unlock the other account.
            'comment_author_email'=>get_userdata($users['other'])->user_email,
            'comment_content'=>'Public local fixture comment, not a hidden fragment.',
            'comment_approved'=>$comment[1], 'comment_type'=>'comment',
        ]);
        protected_browser_check((bool) $comment_id, 'Create comment gate fixture.');
    }
    foreach ($users as $id) {
        protected_browser_check((bool) wuai_sluyu_event($id, 'qa-protected-browser-seed', 'qa', $initial_points, 0, 'Protected browser fixture points.'), 'Seed dedicated points ledger.');
        protected_browser_check(wuai_sluyu_points($id) === $initial_points, 'Fixture balance is exact.');
    }
    foreach (['login','member','comment','circle'] as $key) {
        protected_browser_check(wuai_sluyu_protected_state($posts[$key], $users['reader'])['allowed'], 'Eligible reader gate: ' . $key);
    }
    foreach (['member','comment','circle','points'] as $key) {
        protected_browser_check(!wuai_sluyu_protected_state($posts[$key], $users['other'])['allowed'], 'Ineligible other gate: ' . $key);
    }
    protected_browser_check(!wuai_sluyu_protected_state($posts['points'], $users['reader'])['purchased'], 'Reader starts without a purchase.');
    $manifest = [
        'base'=>home_url(), 'users'=>$users, 'posts'=>$posts, 'urls'=>$urls,
        'secrets'=>$secrets, 'versions'=>$versions, 'circle'=>$circle,
        'publicPrefix'=>$public_prefix, 'initialPoints'=>$initial_points, 'cost'=>$cost,
    ];
    $output = getenv('WP_QA_OUTPUT') ?: dirname($root);
    if (!is_dir($output)) { protected_browser_check(wp_mkdir_p($output), 'Create fixture output directory.'); }
    protected_browser_check(file_put_contents(rtrim($output, '/\\') . '/protected-browser-fixture.json', wp_json_encode($manifest, JSON_PRETTY_PRINT)) !== false, 'Write fixture manifest.');
    echo "PASS: dedicated protected-content browser users, five access modes, password/draft isolation and points ledger prepared.\n";
} finally { $_POST = $old_post; wp_set_current_user($old_user); }
