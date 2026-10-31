<?php
// CLI-only integration suite; the parent runs it serially on the disposable QA site.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771') { throw new RuntimeException('Local QA only.'); }
if (!function_exists('wuai_sluyu_comment_permission')) { throw new RuntimeException('Integrate inc/community-permissions.php before testing.'); }
function perm_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function perm_rest($uid, $method, $path, $data = []) {
    wp_set_current_user($uid);
    $request = new WP_REST_Request($method, $path); $request->set_body_params($data);
    return rest_do_request($request);
}
$old_user = get_current_user_id(); $old_post = $_POST;
$users = []; $posts = []; $comments = []; $terms = [];
$prefix = 'qa_perms_' . strtolower(wp_generate_password(8, false, false));
try {
    wp_set_current_user(0);
    foreach (['subscriber'=>'subscriber','author'=>'author','editor'=>'editor'] as $key=>$role) {
        $id = wp_create_user($prefix . '_' . $key, 'qa-local-only', $prefix . '_' . $key . '@example.test');
        perm_check(!is_wp_error($id), 'Create isolated permission user.');
        $users[$key] = (int) $id; (new WP_User($id))->set_role($role);
    }
    $subscriber = $users['subscriber']; $author = $users['author']; $editor = $users['editor'];
    foreach (wuai_sluyu_service_post_types() as $type) {
        $obj = get_post_type_object($type);
        perm_check($obj->cap->create_posts === 'wuai_manage_service_content' && $obj->cap->publish_posts === 'wuai_manage_service_content', 'Service registration isolates native write capabilities: ' . $type);
        $id = wp_insert_post(['post_type'=>$type,'post_status'=>'publish','post_author'=>$author,'post_title'=>'QA native ' . $type,'post_content'=>'Published service fixture.','comment_status'=>'open'], true);
        perm_check(!is_wp_error($id), 'Create service fixture.'); $posts[] = (int) $id; $service[$type] = (int) $id;
        wp_set_current_user($author);
        perm_check(!current_user_can($obj->cap->create_posts) && !current_user_can('edit_post', $id) && !current_user_can('delete_post', $id), 'Author cannot use native service writes: ' . $type);
        perm_check(perm_rest($author, 'POST', '/wp/v2/' . $type, ['title'=>'Unauthorized','content'=>'Unauthorized native content.','status'=>'publish'])->get_status() === 403, 'REST service create denied for author: ' . $type);
        perm_check(perm_rest($author, 'POST', '/wp/v2/' . $type . '/' . $id, ['title'=>'Unauthorized change'])->get_status() === 403, 'REST service update denied for author: ' . $type);
        perm_check(perm_rest($author, 'DELETE', '/wp/v2/' . $type . '/' . $id, ['force'=>true])->get_status() === 403, 'REST service delete denied for author: ' . $type);
        perm_check(perm_rest($subscriber, 'POST', '/wp/v2/' . $type, ['title'=>'Unauthorized subscriber','content'=>'No native access.','status'=>'publish'])->get_status() === 403, 'Subscriber cannot use native service create: ' . $type);
        wp_set_current_user($editor);
        perm_check(current_user_can($obj->cap->create_posts) && current_user_can('edit_post', $id), 'Trusted editor retains service management: ' . $type);
        wp_set_current_user(0);
    }
    $topic = $service['sluyu_topic'];
    update_post_meta($topic, '_wuai_topic_kind', 'poll'); update_post_meta($topic, '_wuai_poll_choices', ['One','Two']);
    wuai_sluyu_event($subscriber, 'vote:' . $topic, 'vote', 0, $topic, '0');
    perm_check(perm_rest($author, 'POST', '/wp/v2/sluyu_topic/' . $topic, ['title'=>'Changed voted question','status'=>'publish'])->get_status() === 403, 'Author cannot rewrite a voted poll through REST.');
    perm_check(get_the_title($topic) === 'QA native sluyu_topic' && wuai_sluyu_has_event($subscriber, 'vote:' . $topic), 'Denied mutation preserves poll and ballots.');
    $created = perm_rest($editor, 'POST', '/wp/v2/sluyu_news', ['title'=>'QA trusted editor create','content'=>'Trusted service publishing.','status'=>'publish']);
    perm_check($created->get_status() === 201, 'Trusted editor can publish services.'); $posts[] = (int) $created->get_data()['id'];
    $ordinary = perm_rest($author, 'POST', '/wp/v2/posts', ['title'=>'QA ordinary author post','content'=>'Ordinary WordPress author rights remain.','status'=>'publish']);
    perm_check($ordinary->get_status() === 201, 'Normal author retains ordinary post publishing.');
    $ordinary_id = (int) $ordinary->get_data()['id']; $posts[] = $ordinary_id;
    perm_check(perm_rest($author, 'POST', '/wp/v2/posts/' . $ordinary_id, ['title'=>'QA ordinary author changed'])->get_status() === 200, 'Unbanned author can still edit ordinary post.');
    wp_set_current_user($subscriber); $_POST = ['kind'=>'topic','title'=>'QA front-end pending','body'=>'Moderated frontend submission remains allowed.'];
    $front = wuai_sluyu_community_operation('submit', $subscriber);
    perm_check(!is_wp_error($front), 'Subscriber frontend submission remains allowed.');
    $front_posts = get_posts(['post_type'=>'sluyu_topic','post_status'=>'pending','author'=>$subscriber,'numberposts'=>-1]);
    perm_check(count($front_posts) === 1, 'Frontend creates only pending service content.');
    foreach ($front_posts as $post) { $posts[] = (int) $post->ID; }

    wp_set_current_user(0); $_POST = [];
    $circle = wp_insert_term($prefix . '_circle', 'sluyu_circle', ['description'=>'Permission test circle.']);
    perm_check(!is_wp_error($circle), 'Create isolated permission circle.');
    $circle = (int) $circle['term_id']; $terms[] = $circle;
    update_term_meta($circle, '_wuai_circle_status', 'publish'); update_term_meta($circle, '_wuai_circle_posting', 'members'); update_term_meta($circle, '_wuai_circle_join', 'open');
    wp_set_object_terms($topic, [$circle], 'sluyu_circle');
    perm_check(is_wp_error(wuai_sluyu_comment_permission(['comment_post_ID'=>$topic], 0)), 'Guest blocked from members-only circle comments.');
    perm_check(is_wp_error(wuai_sluyu_comment_permission(['comment_post_ID'=>$topic], $subscriber)), 'Nonmember blocked from circle comments.');
    $denied = perm_rest($subscriber, 'POST', '/wp/v2/comments', ['post'=>$topic,'content'=>'Cannot comment outside membership.']);
    perm_check($denied->get_status() === 403, 'REST comment create enforces circle membership.');
    $wpdb->replace(wuai_sluyu_table('memberships'), ['circle_id'=>$circle,'user_id'=>$subscriber,'status'=>'active','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
    perm_check(wuai_sluyu_comment_permission(['comment_post_ID'=>$topic], $subscriber) === true, 'Active member may comment.');
    $allowed = perm_rest($subscriber, 'POST', '/wp/v2/comments', ['post'=>$topic,'content'=>'QA active member successful REST comment.']);
    perm_check($allowed->get_status() === 201, 'Active member REST comment succeeds.');
    $comment_id = (int) $allowed->get_data()['id']; $comments[] = $comment_id;
    $wpdb->update(wuai_sluyu_table('memberships'), ['status'=>'blocked'], ['circle_id'=>$circle,'user_id'=>$subscriber]);
    update_term_meta($circle, '_wuai_circle_posting', 'everyone');
    perm_check(is_wp_error(wuai_sluyu_comment_permission(['comment_post_ID'=>$topic], $subscriber)), 'Blocked member cannot bypass everyone-posting circle.');
    perm_check(perm_rest($subscriber, 'POST', '/wp/v2/comments', ['post'=>$topic,'content'=>'QA blocked REST comment.'])->get_status() === 403, 'Blocked member REST create denied.');
    $request = new WP_REST_Request('POST', '/wp/v2/comments/' . $comment_id); $request->set_param('id', $comment_id);
    perm_check(is_wp_error(apply_filters('rest_preprocess_comment', ['comment_content'=>'Blocked edit.'], $request)), 'REST update filter resolves original comment post.');
    perm_check(is_wp_error(apply_filters('rest_pre_insert_comment', ['comment_post_ID'=>$topic], $request)), 'REST insert filter uses same gate.');
    perm_check(is_wp_error(wp_update_comment(['comment_ID'=>$comment_id,'comment_content'=>'Direct blocked edit.'], true)), 'Core comment updates enforce participation.');
    perm_check(is_wp_error(apply_filters('pre_comment_approved', 1, ['comment_post_ID'=>$topic])), 'Core new-comment approval enforces participation.');
    $wpdb->update(wuai_sluyu_table('memberships'), ['status'=>'active'], ['circle_id'=>$circle,'user_id'=>$subscriber]);
    update_term_meta($circle, '_wuai_circle_join', 'member');
    perm_check(is_wp_error(wuai_sluyu_comment_permission(['comment_post_ID'=>$topic], $subscriber)), 'Expired membership blocks comments.');
    update_user_meta($subscriber, '_wuai_member_until', time() + HOUR_IN_SECONDS);
    perm_check(wuai_sluyu_comment_permission(['comment_post_ID'=>$topic], $subscriber) === true, 'Valid membership restores comments.');
    update_term_meta($circle, '_wuai_circle_status', 'pending');
    perm_check(is_wp_error(wuai_sluyu_comment_permission(['comment_post_ID'=>$topic], $subscriber)), 'Pending circle is checked even when hidden from public term queries.');
    update_term_meta($circle, '_wuai_circle_status', 'publish');
    update_user_meta($subscriber, '_wuai_banned_until', -1);
    perm_check(perm_rest($subscriber, 'POST', '/wp/v2/comments', ['post'=>$ordinary_id,'content'=>'Banned outside circles.'])->get_status() === 403, 'Global ban covers ordinary REST comments.');
    perm_check(is_wp_error(wuai_sluyu_comment_permission(['comment_post_ID'=>$ordinary_id], $subscriber)), 'Global ban covers ordinary comment helper.');
    update_user_meta($author, '_wuai_banned_until', -1); wp_set_current_user($author);
    perm_check(!current_user_can('edit_posts') && !current_user_can('publish_posts') && !current_user_can('edit_post', $ordinary_id), 'Banned author loses native write capabilities.');
    perm_check(perm_rest($author, 'POST', '/wp/v2/posts', ['title'=>'Blocked','content'=>'Banned content creation.'])->get_status() === 403, 'Banned native ordinary post creation denied.');
    perm_check(perm_rest($author, 'POST', '/wp/v2/posts/' . $ordinary_id, ['title'=>'Blocked edit'])->get_status() === 403, 'Banned native ordinary post editing denied.');
    perm_check(is_wp_error(wp_update_post(['ID'=>$ordinary_id,'post_title'=>'Blocked raw update'], true)), 'Core article update also aborts for banned actor.');
    perm_check(get_the_title($ordinary_id) === 'QA ordinary author changed', 'Banned update never damages original data.');
    update_user_meta($editor, '_wuai_banned_until', -1); wp_set_current_user($editor);
    perm_check(!current_user_can('wuai_manage_service_content'), 'Ban overrides trusted-editor capability.');
    perm_check(!current_user_can('manage_categories') && !current_user_can('edit_term',$circle) && !current_user_can('delete_term',$circle), 'Banned editor cannot bypass circle restrictions through native taxonomy management.');
    perm_check(perm_rest($editor, 'POST', '/wp/v2/sluyu_circle', ['name'=>'Banned editor circle'])->get_status() === 403, 'Banned editor cannot create native REST circles.');
    perm_check(perm_rest($editor, 'POST', '/wp/v2/sluyu_news', ['title'=>'Blocked editor','content'=>'Must not publish.'])->get_status() === 403, 'Banned editor cannot publish service content.');
    echo "PASS: native service CPT capabilities, REST create/edit/delete isolation, retained ordinary author rights, moderated frontend submission, cross-entry comment membership/ban gates and core update rejection.\n";
} finally {
    wp_set_current_user(0); $_POST = [];
    foreach ($comments as $id) { wp_delete_comment($id, true); }
    foreach ($posts as $id) { wp_delete_post($id, true); }
    foreach ($terms as $id) { wp_delete_term($id, 'sluyu_circle'); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $id) { wp_delete_user($id); }
    $_POST = $old_post; wp_set_current_user($old_user);
}
