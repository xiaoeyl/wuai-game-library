<?php
// CLI-only. The parent runs this serially against the disposable WordPress QA site.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771') { throw new RuntimeException('Local QA only.'); }
if (!function_exists('wuai_sluyu_protected_unlock')) { throw new RuntimeException('Integrate inc/protected-content.php before testing.'); }
function protected_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function protected_save($uid, $id, $access, $body, $cost = 10, $extra = []) {
    wp_set_current_user($uid);
    return wuai_sluyu_protected_save($id, array_merge(['access'=>$access,'protected_body'=>$body,'cost'=>(string) $cost,'stamp'=>wuai_sluyu_protected_stamp($id)], $extra));
}
function protected_rest($uid, $method, $route, $input = []) {
    wp_set_current_user($uid); $request = new WP_REST_Request($method, $route); $request->set_body_params($input);
    return rest_do_request($request);
}
function protected_context($id, $mode = 'single') {
    $query = new WP_Query(); $query->is_singular = $mode === 'single'; $query->is_single = $mode === 'single';
    $query->is_feed = $mode === 'feed'; $query->is_embed = $mode === 'embed';
    $query->in_the_loop = true; $query->queried_object = get_post($id); $query->queried_object_id = $id;
    $GLOBALS['wp_query'] = $query; $GLOBALS['wp_the_query'] = $query; $GLOBALS['post'] = get_post($id);
}
$old_user = get_current_user_id(); $old_get = $_GET; $old_post = $_POST; $old_query = $GLOBALS['wp_query']; $old_the_query = $GLOBALS['wp_the_query']; $old_global_post = $GLOBALS['post'] ?? null;
$users = []; $posts = []; $terms = []; $comments = [];
$prefix = 'qa_protected_' . strtolower(wp_generate_password(8, false, false));
$secret = 'PRIVATE-BODY-' . wp_generate_password(10, false, false);
try {
    wp_set_current_user(0);
    foreach (['owner'=>'subscriber','reader'=>'subscriber','other'=>'subscriber','editor'=>'editor','author'=>'author'] as $name=>$role) {
        $id = wp_create_user($prefix . '_' . $name, 'qa-local-only', $prefix . '_' . $name . '@example.test');
        protected_check(!is_wp_error($id), 'Create dedicated protected-content user.');
        $users[$name] = (int) $id; (new WP_User($id))->set_role($role);
    }
    $owner = $users['owner']; $reader = $users['reader']; $other = $users['other']; $editor = $users['editor']; $author = $users['author'];
    $make = function ($type = 'post', $status = 'publish', $extra = []) use (&$posts, $owner, $prefix) {
        $id = wp_insert_post(array_merge(['post_type'=>$type,'post_status'=>$status,'post_author'=>$owner,'post_title'=>$prefix . ' public title','post_content'=>'This is the public summary only.','comment_status'=>'open'], $extra), true);
        protected_check(!is_wp_error($id), 'Create protected-content fixture.'); $posts[] = (int) $id; return (int) $id;
    };
    $login = $make(); $member = $make(); $comment = $make(); $circle_post = $make('sluyu_topic');
    $points = $make(); $points_two = $make(); $draft = $make('post', 'draft'); $password = $make('post', 'publish', ['post_password'=>'locked']);
    $question = $make('sluyu_topic'); update_post_meta($question, '_wuai_topic_kind', 'question');
    $poll = $make('sluyu_topic'); update_post_meta($poll, '_wuai_topic_kind', 'poll'); update_post_meta($poll, '_wuai_poll_choices', ['A','B']);
    $author_post = $make('post', 'publish', ['post_author'=>$author]);
    $circle = wp_insert_term($prefix . '_circle', 'sluyu_circle', ['description'=>'Protected circle fixture.']);
    protected_check(!is_wp_error($circle), 'Create protected circle.'); $circle = (int) $circle['term_id']; $terms[] = $circle;
    update_term_meta($circle, '_wuai_circle_status', 'publish'); update_term_meta($circle, '_wuai_circle_join', 'open'); update_term_meta($circle, '_wuai_circle_posting', 'members'); update_term_meta($circle, '_wuai_circle_owner', $owner);
    wp_set_object_terms($circle_post, [$circle], 'sluyu_circle');
    $body = '<h2>Hidden section</h2><p>' . $secret . ' and a safe <strong>formatted paragraph</strong>.</p><script>alert(1)</script><img src="https://example.test/private.png"><a href="javascript:alert(2)">invalid protocol</a>[qa_protected_probe]';
    foreach ([$login=>'login',$member=>'member',$comment=>'comment',$circle_post=>'circle',$points=>'points',$points_two=>'points',$draft=>'login',$password=>'points',$author_post=>'login'] as $id=>$mode) {
        protected_check(protected_save($editor, $id, $mode, $body, 30) === true, 'Trusted editor saves private fragment: ' . $mode);
        protected_check(strpos(get_post_field('post_content', $id), $secret) === false && strpos(get_post_field('post_excerpt', $id), $secret) === false, 'Secret is never copied into public content or excerpt.');
    }
    protected_check(is_wp_error(protected_save($editor, $question, 'login', $body)), 'Question protection refused, accepted-answer content must not be misrepresented as private.');
    protected_check(is_wp_error(protected_save($editor, $poll, 'login', $body)), 'Poll protection refused.');
    protected_check(is_wp_error(protected_save($author, $author_post, 'login', 'Cannot modify the private configuration.')), 'Native author cannot edit private config on own article.');
    wp_set_current_user($author); protected_check(!current_user_can('edit_post_meta', $author_post, wuai_sluyu_protected_key()), 'Protected meta auth callback excludes author.');
    wp_set_current_user($editor); protected_check(current_user_can('edit_post_meta', $author_post, wuai_sluyu_protected_key()), 'Trusted editor may manage protected meta.');
    $config = wuai_sluyu_protected_config($login);
    protected_check(strpos($config['body'], '<script') === false && strpos($config['body'], '<img') === false && strpos($config['body'], 'javascript:') === false && strpos($config['body'], '[qa_protected_probe]') === false, 'Only safe basic HTML persists; shortcode brackets inert.');
    protected_check(wuai_sluyu_protected_html($config['body']) === $config['body'], 'Canonical shortcode escaping is stable across repeated sanitization.');
    protected_check(wuai_sluyu_protected_html('&#91;qa_protected_probe&#93;') === '&#091;qa_protected_probe&#093;', 'Legacy bracket entities normalize to the same inert representation.');
    protected_check(!wuai_sluyu_protected_state($login, 0)['allowed'] && wuai_sluyu_protected_state($login, $reader)['allowed'], 'Login gate requires a real logged-in account.');
    protected_check(!wuai_sluyu_protected_state($member, $reader)['allowed'], 'Ordinary reader cannot access member fragment.');
    update_user_meta($reader, '_wuai_member_until', time() + HOUR_IN_SECONDS);
    protected_check(wuai_sluyu_protected_state($member, $reader)['allowed'], 'Valid membership grants access.');
    update_user_meta($reader, '_wuai_member_until', time() - 1);
    protected_check(!wuai_sluyu_protected_state($member, $reader)['allowed'], 'Expired membership revokes access.');
    foreach ([[$reader,0],[$other,1],[0,1]] as $values) {
        $id = wp_insert_comment(['comment_post_ID'=>$comment,'user_id'=>$values[0],'comment_author'=>'QA','comment_author_email'=>get_userdata($reader)->user_email,'comment_content'=>'Protected comment fixture.','comment_approved'=>$values[1],'comment_type'=>'comment']); $comments[] = (int) $id;
    }
    protected_check(!wuai_sluyu_protected_state($comment, $reader)['allowed'], 'Pending own, approved foreign and anonymous same-email comments do not unlock.');
    wp_set_current_user($editor); wp_set_comment_status($comments[0], 'approve');
    protected_check(wuai_sluyu_protected_state($comment, $reader)['allowed'], 'Own approved comment unlocks.');
    wp_set_comment_status($comments[0], 'hold'); protected_check(!wuai_sluyu_protected_state($comment, $reader)['allowed'], 'Comment moderation reversal revokes access.');
    protected_check(!wuai_sluyu_protected_state($circle_post, $reader)['allowed'], 'Nonmember cannot view circle fragment.');
    $wpdb->replace(wuai_sluyu_table('memberships'), ['circle_id'=>$circle,'user_id'=>$reader,'status'=>'active','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
    protected_check(wuai_sluyu_protected_state($circle_post, $reader)['allowed'], 'Active circle member can view.');
    $wpdb->update(wuai_sluyu_table('memberships'), ['status'=>'blocked'], ['circle_id'=>$circle,'user_id'=>$reader]);
    protected_check(!wuai_sluyu_protected_state($circle_post, $reader)['allowed'], 'Blocked circle member cannot view.');
    $wpdb->update(wuai_sluyu_table('memberships'), ['status'=>'active'], ['circle_id'=>$circle,'user_id'=>$reader]); update_term_meta($circle, '_wuai_circle_join', 'member');
    protected_check(!wuai_sluyu_protected_state($circle_post, $reader)['allowed'], 'Expired membership cannot access member-only circle even with active record.');
    update_user_meta($reader, '_wuai_member_until', time() + HOUR_IN_SECONDS);
    protected_check(wuai_sluyu_protected_state($circle_post, $reader)['allowed'], 'Valid membership restores circle access.');
    update_term_meta($circle, '_wuai_circle_status', 'pending'); protected_check(!wuai_sluyu_protected_state($circle_post, $reader)['allowed'], 'Pending circle never unlocks via filtered term omission.'); update_term_meta($circle, '_wuai_circle_status', 'publish');
    protected_check(!wuai_sluyu_protected_state($draft, $reader, true)['allowed'], 'Foreign draft cannot be previewed by reader.');
    protected_check(!wuai_sluyu_protected_state($draft, $owner)['allowed'] && wuai_sluyu_protected_state($draft, $owner, true)['allowed'], 'Own draft requires explicit preview mode.');
    protected_check(!wuai_sluyu_protected_state($password, $owner, true)['allowed'] && !wuai_sluyu_protected_state($password, $editor, true)['allowed'], 'Password articles never bypass protection, even for previews.');
    update_user_meta($owner, '_wuai_banned_until', -1); protected_check(!wuai_sluyu_protected_state($login, $owner, true)['allowed'], 'Banned owner cannot preview.'); delete_user_meta($owner, '_wuai_banned_until');

    wp_set_current_user($reader); wuai_sluyu_event($reader, 'qa-protected-seed', 'qa', 100, 0, 'Fixture points.');
    $quote = wuai_sluyu_protected_quote($points);
    protected_check(strlen($quote) === 64 && strpos($quote, wuai_sluyu_protected_config($points)['version']) === false, 'Public confirmation uses opaque quote, never internal entitlement version.');
    $balance = wuai_sluyu_points($reader);
    $locked_view = wuai_sluyu_protected_view($points);
    protected_check(strpos($locked_view, $secret) === false && strpos($locked_view, 'confirm_unlock') !== false && strpos($locked_view, '30 积分') !== false, 'Locked view shows title/price/explicit consent without secret.');
    protected_check(strpos($locked_view, '不保证内容永久在线') !== false && strpos($locked_view, '没有自动退款') !== false, 'Confirmation discloses availability and noncash refund boundaries.');
    protected_check(wuai_sluyu_points($reader) === $balance, 'Reading confirmation never charges.');
    protected_check(is_wp_error(wuai_sluyu_protected_unlock($points, $quote, '0')), 'No consent means no debit.');
    protected_check(is_wp_error(wuai_sluyu_protected_unlock($points, 'bad', '1')), 'Forged quote denied.');
    $lock = wuai_sluyu_lock('user_' . $reader);
    protected_check(is_wp_error(wuai_sluyu_protected_unlock($points, $quote, '1')), 'Direct helper respects already-held user accounting lock.'); wuai_sluyu_unlock($lock);
    $lock = wuai_sluyu_protected_post_lock($points);
    protected_check(is_wp_error(wuai_sluyu_protected_unlock($points, $quote, '1')), 'Content lock prevents racing configuration write.'); wuai_sluyu_unlock($lock);
    $result = wuai_sluyu_protected_unlock($points, $quote, '1');
    protected_check(!is_wp_error($result) && $result['charged'] === 30 && wuai_sluyu_points($reader) === 70, 'Single atomic debit grants current-version entitlement.');
    protected_check(wuai_sluyu_protected_state($points, $reader)['purchased'] && strpos(wuai_sluyu_protected_view($points), $secret) !== false, 'Actual ledger entitlement enables reading.');
    $repeat = wuai_sluyu_protected_unlock($points, $quote, '1');
    protected_check(!is_wp_error($repeat) && $repeat['charged'] === 0 && wuai_sluyu_points($reader) === 70, 'Repeat confirmation is idempotent.');
    $event_key = wuai_sluyu_protected_event_key($points, wuai_sluyu_protected_config($points));
    $ledger = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . wuai_sluyu_table('events') . ' WHERE user_id=%d AND event_key=%s', $reader, $event_key));
    protected_check(count($ledger) === 1 && (int) $ledger[0]->points === -30 && $ledger[0]->event_type === 'unlock' && (int) $ledger[0]->target_id === $points, 'One immutable unique ledger row is both the exact debit and entitlement.');
    wp_set_current_user($other); protected_check(!wuai_sluyu_protected_state($points)['allowed'], 'Purchase is per user.');
    protected_check(is_wp_error(wuai_sluyu_protected_unlock($points, wuai_sluyu_protected_quote($points), '1')) && wuai_sluyu_points($other) === 0, 'Insufficient funds cannot create entitlement or negative balance.');
    wp_set_current_user($reader); $stale_quote = wuai_sluyu_protected_quote($points_two);
    protected_check(protected_save($editor, $points_two, 'points', $body, 80) === true, 'Price edit saved under same post lock.');
    wp_set_current_user($reader); protected_check(is_wp_error(wuai_sluyu_protected_unlock($points_two, $stale_quote, '1')), 'Stale price confirmation rejected.');
    protected_check(is_wp_error(wuai_sluyu_protected_unlock($points_two, wuai_sluyu_protected_quote($points_two), '1')), 'Second purchase cannot overspend remaining balance.');
    $old_version = wuai_sluyu_protected_config($points)['version'];
    protected_check(protected_save($editor, $points, 'points', $body . '<p>Corrected content.</p>', 35) === true, 'Editorial correction preserves entitlement by default.');
    protected_check(wuai_sluyu_protected_config($points)['version'] === $old_version && wuai_sluyu_protected_state($points, $reader)['purchased'], 'Existing reader never charged again for routine edit or repricing.');
    protected_check(protected_save($editor, $points, 'points', $body, 20, ['reset_version'=>'1']) === true, 'Explicit new paid edition rotates entitlement version.');
    wp_set_current_user($reader); protected_check(!wuai_sluyu_protected_state($points)['purchased'], 'Previous edition entitlement does not silently cover explicit new edition.');
    $lock = wuai_sluyu_lock('user_' . $reader); $_POST = ['id'=>$points,'quote'=>wuai_sluyu_protected_quote($points),'confirm_unlock'=>'1'];
    try { $result = wuai_sluyu_community_operation('protected_unlock', $reader); } finally { wuai_sluyu_unlock($lock); }
    protected_check(!is_wp_error($result) && $result['charged'] === 20 && wuai_sluyu_points($reader) === 50, 'Core dispatch reuses caller-held user lock without deadlock and charges new edition only once.');
    update_user_meta($reader, '_wuai_banned_until', -1); protected_check(!wuai_sluyu_protected_state($points)['allowed'] && is_wp_error(wuai_sluyu_protected_unlock($points, $quote, '1')), 'Ban overrides purchased entitlement.'); delete_user_meta($reader, '_wuai_banned_until');
    wp_set_current_user($editor); wp_update_post(['ID'=>$points,'post_status'=>'draft']);
    wp_set_current_user($reader); protected_check(!wuai_sluyu_protected_state($points)['allowed'] && is_wp_error(wuai_sluyu_protected_unlock($points, $quote, '1')), 'Withdrawal denies purchased content without creating another debit.');
    wp_set_current_user($editor); wp_update_post(['ID'=>$points,'post_status'=>'publish']);

    wp_set_current_user($owner); $_POST = ['kind'=>'topic','title'=>'QA private fragment submission','body'=>'A public submission summary.','protected_body'=>$secret . ' new submission only.','access'=>'login','cost'=>'0'];
    $submitted = wuai_sluyu_community_operation('submit', $owner);
    protected_check(!is_wp_error($submitted), 'Subscriber may submit protected fragment for review.');
    $pending = get_posts(['post_type'=>'sluyu_topic','post_status'=>'pending','author'=>$owner,'numberposts'=>-1]);
    $found = false;
    foreach ($pending as $post) { if ($post->post_title === 'QA private fragment submission') { $posts[] = $post->ID; $found = $post; } }
    protected_check($found && strpos($found->post_content, $secret) === false && wuai_sluyu_protected_config($found->ID), 'Pending submission stores secret only in private metadata.');
    $base = ['post_status'=>'pending','meta_input'=>['_wuai_poll_choices'=>['One','Two']]];
    foreach (['poll','question','verify'] as $kind) { protected_check(is_wp_error(apply_filters('wuai_sluyu_submission', $base, $kind, $owner)), 'Unsupported protected submission rejected: ' . $kind); }
    $_POST = ['protected_body'=>['bad'],'access'=>'login']; protected_check(is_wp_error(apply_filters('wuai_sluyu_submission', $base, 'post', $owner)), 'Nested secret field rejected.');
    protected_check(is_wp_error(wuai_sluyu_protected_validate(['protected_body'=>$body,'access'=>'points','cost'=>'-1'])), 'Negative cost rejected.');
    protected_check(is_wp_error(wuai_sluyu_protected_validate(['protected_body'=>$body,'access'=>'points','cost'=>['30']])), 'Nested cost rejected.');
    protected_check(is_wp_error(protected_save($editor, $login, 'login', $body, 10, ['stamp'=>'stale'])), 'Stale private-metabox save cannot overwrite newer config.');

    $ran = 0; add_shortcode('qa_protected_probe', function () use (&$ran) { $ran++; return 'SHORTCODE-EXECUTED'; });
    wp_set_current_user($reader); protected_context($login);
    $rendered = apply_filters('the_content', get_post_field('post_content', $login));
    protected_check(strpos($rendered, $secret) !== false && $ran === 0 && strpos($rendered, 'SHORTCODE-EXECUTED') === false, 'Authorized singular appends safe fragment without executing hidden shortcode.');
    $excerpt = get_the_excerpt($login); protected_check(strpos($excerpt, $secret) === false, 'Excerpt inside authorized single context never reveals hidden body.');
    wp_set_current_user(0); $rendered = apply_filters('the_content', get_post_field('post_content', $login)); protected_check(strpos($rendered, $secret) === false, 'Guest singular cannot reveal protected body.');
    wp_set_current_user($reader);
    foreach (['archive','feed','embed'] as $mode) { protected_context($login, $mode); protected_check(strpos(apply_filters('the_content', get_post_field('post_content', $login)), $secret) === false, 'Nonsingular rendering remains public: ' . $mode); }
    protected_context($login);
    foreach ([$reader,$editor,0] as $uid) {
        $response = protected_rest($uid, 'GET', '/wp/v2/posts/' . $login);
        protected_check($response->get_status() === 200, 'REST public summary remains available.');
        $json = wp_json_encode($response->get_data());
        protected_check(strpos($json, $secret) === false && strpos($json, wuai_sluyu_protected_key()) === false && strpos($json, wuai_sluyu_protected_config($login)['version']) === false, 'REST never includes private body/config/version, even with authorized singular globals.');
    }
    wp_set_current_user($reader); $search = new WP_Query(['s'=>$secret,'post_type'=>['post','sluyu_topic'],'post_status'=>'publish','fields'=>'ids']);
    protected_check(!$search->posts, 'Default search does not index private fragments.');
    $public_content = get_post_field('post_content', $login); protected_check(strpos($public_content, $secret) === false, 'Theme/plugin removal leaves only public post content.');
    protected_check((int) ($GLOBALS['wuai_sluyu_protected_rest_depth'] ?? 0) === 0, 'Nested REST suppression scope is released.');
    echo "PASS: private-only body storage, role/meta checks, login/member/comment/circle gates, explicit versioned atomic points unlocks, idempotency/locks, moderated submissions and REST/feed/search/excerpt isolation.\n";
} finally {
    $_POST = []; wp_set_current_user(0); remove_shortcode('qa_protected_probe');
    $GLOBALS['wp_query'] = $old_query; $GLOBALS['wp_the_query'] = $old_the_query; $GLOBALS['post'] = $old_global_post;
    foreach ($comments as $id) { wp_delete_comment($id, true); }
    foreach ($posts as $id) { wp_delete_post($id, true); }
    foreach ($terms as $id) { wp_delete_term($id, 'sluyu_circle'); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $id) { wp_delete_user($id); }
    $_POST = $old_post; $_GET = $old_get; wp_set_current_user($old_user);
}
