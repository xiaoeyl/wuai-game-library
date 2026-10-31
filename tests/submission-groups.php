<?php
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771') { throw new RuntimeException('Disposable localhost QA only.'); }
function sg_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function sg_posts($uid) { return get_posts(['post_type'=>'sluyu_topic','post_status'=>['draft','pending','publish'],'author'=>$uid,'numberposts'=>-1,'fields'=>'ids']); }
function sg_submit($uid, $group, $title) {
    wp_set_current_user($uid); $_POST = wp_slash(['kind'=>'topic','title'=>$title,'body'=>'A safe circle-specific submission fixture.','group'=>$group]);
    return wuai_sluyu_community_operation('submit', $uid);
}
$previous_user = get_current_user_id(); $previous_post = $_POST; $previous_get = $_GET;
$prefix = 'qa_submission_group_' . strtolower(wp_generate_password(8, false, false));
$users = []; $circle = 0; $query_filter = null;
try {
    foreach (['author','other'] as $name) {
        $uid = wp_create_user($prefix . '_' . $name, 'qa-local-only', $prefix . '_' . $name . '@example.test');
        sg_check(!is_wp_error($uid), 'Create dedicated test account.'); $users[$name] = (int) $uid;
    }
    $author = $users['author']; $other = $users['other'];
    $term = wp_insert_term($prefix, 'sluyu_circle'); sg_check(!is_wp_error($term), 'Create legacy-compatible public circle.'); $circle = (int) $term['term_id'];
    update_term_meta($circle, '_wuai_circle_owner', $author); update_term_meta($circle, '_wuai_circle_join', 'open'); update_term_meta($circle, '_wuai_circle_posting', 'members');
    foreach (['bad','-1','1.5','01',[$circle],str_repeat('9',20)] as $value) {
        $result = sg_submit($author, $value, $prefix . ' malformed group');
        sg_check(is_wp_error($result) && !sg_posts($author), 'Malformed group never silently creates an ungrouped submission.');
    }
    $result = sg_submit($other, $circle, $prefix . ' ineligible');
    sg_check(is_wp_error($result) && !sg_posts($other), 'Nonmember cannot create a circle-specific submission.');
    foreach ([(string)$circle, '999999999', [$circle], '-1'] as $value) {
        wp_set_current_user($other); $_GET = ['kind'=>'topic','group'=>$value]; ob_start(); wuai_sluyu_view_submit(); $html = ob_get_clean();
        sg_check(strpos($html, 'data-operation="submit"') === false, 'Ineligible or malformed preselected circle does not render an ungrouped submission form.');
        sg_check(strpos($html, 'sluyu_page=circles') !== false, 'Blocked submit page offers an explicit circle directory link.');
    }
    wp_set_current_user($author); $_GET = ['kind'=>'topic','group'=>(string)$circle]; ob_start(); wuai_sluyu_view_submit(); $html = ob_get_clean();
    sg_check(strpos($html, 'data-operation="submit"') !== false && preg_match('/value="' . $circle . '"\s+selected/', $html), 'Eligible author retains the intended selected circle.');
    global $wpdb;
    $hits = 0; $stages = [];
    $observe = function ($new, $old, $post) use ($author, &$stages) { if ((int)$post->post_author === $author && $post->post_type === 'sluyu_topic') { $stages[] = $new; } };
    add_action('transition_post_status', $observe, 100, 3);
    $query_filter = function ($query) use ($wpdb, &$hits) {
        if (preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?' . preg_quote($wpdb->term_relationships, '/') . '`?\s/i', ltrim($query))) { $hits++; return 'SELECT 1'; }
        return $query;
    };
    add_filter('query', $query_filter, 999);
    try { $failed = sg_submit($author, $circle, $prefix . ' relationship failure'); }
    finally { remove_filter('query', $query_filter, 999); $query_filter = null; remove_action('transition_post_status', $observe, 100); }
    sg_check($hits > 0 && is_wp_error($failed) && $failed->get_error_code() === 'submission_group', 'A silently lost term-relationship write is detected.');
    $posts = sg_posts($author); sg_check(count($posts) === 1 && get_post_status($posts[0]) === 'draft', 'Incomplete circle assignment remains a recoverable draft.');
    sg_check(!in_array('pending', $stages, true) && !in_array('publish', $stages, true), 'No temporary review/public state before complete configuration.');
    sg_check(!wp_get_object_terms($posts[0], 'sluyu_circle', ['fields'=>'ids']), 'The fault really prevented the circle relationship, not just the success response.');
    sg_check(!(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . wuai_sluyu_table('messages') . ' WHERE recipient_id=%d AND kind=%s', $author, 'notice')), 'Incomplete submission emits no misleading review notice.');
    $result = sg_submit($author, $circle, $prefix . ' complete'); sg_check(!is_wp_error($result) && !empty($result['reset']), 'Healthy retry can submit a complete circle topic.');
    $pending = get_posts(['post_type'=>'sluyu_topic','post_status'=>'pending','author'=>$author,'numberposts'=>1]);
    sg_check(count($pending) === 1 && array_map('intval',wp_get_object_terms($pending[0]->ID,'sluyu_circle',['fields'=>'ids'])) === [$circle], 'Review queue only receives the correctly assigned topic.');
    $ungrouped = sg_submit($other, '0', $prefix . ' explicitly ungrouped');
    sg_check(!is_wp_error($ungrouped), 'Explicitly selecting ungrouped remains supported.');
    echo "PASS: strict group input, eligible preselection, no silent fallback, staged drafts, exact taxonomy-write failure detection, notices and ordinary grouped/ungrouped recovery.\n";
} finally {
    if ($query_filter) { remove_filter('query', $query_filter, 999); }
    $_POST = []; wp_set_current_user(0);
    foreach ($users as $uid) { foreach (sg_posts($uid) as $post_id) { wp_delete_post($post_id, true); } }
    if ($circle) { wp_delete_term($circle, 'sluyu_circle'); }
    require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ($users as $uid) { wp_delete_user($uid); }
    $_POST = $previous_post; $_GET = $previous_get; wp_set_current_user($previous_user);
}
