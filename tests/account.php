<?php
// CLI-only account regression suite. Run serially against the disposable QA site.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('WP_QA_ROOT must name the disposable installation.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771') { throw new RuntimeException('Local QA only.'); }
if (!function_exists('wuai_sluyu_view_account_extended')) { throw new RuntimeException('Integrate inc/account.php before running this test.'); }
function account_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function account_action($uid, $operation, $data = []) {
    wp_set_current_user($uid); $_POST = wp_slash($data);
    return wuai_sluyu_community_operation($operation, $uid);
}
function account_edit($uid, $id, $data = []) {
    return account_action($uid, 'edit_submission', array_merge(['id'=>$id,'title'=>'QA account revised','body'=>'Updated safe content for moderation.','version'=>wuai_sluyu_account_submission_version($id)], $data));
}
function account_render($uid, $query) {
    wp_set_current_user($uid); $_GET = $query; ob_start(); wuai_sluyu_view_account_extended(); return ob_get_clean();
}

$users = []; $posts = []; $comments = []; $created_users = [];
$old_user = get_current_user_id(); $old_post = $_POST; $old_get = $_GET;
$prefix = 'qa_account_' . strtolower(wp_generate_password(8, false, false));
try {
    foreach (['owner','other','fan'] as $name) {
        $id = wp_create_user($prefix . '_' . $name, 'qa-local-only', $prefix . '_' . $name . '@example.test');
        account_check(!is_wp_error($id), 'Create dedicated account fixture user.');
        (new WP_User($id))->set_role('subscriber'); $users[$name] = (int) $id; $created_users[] = (int) $id;
    }
    $owner = $users['owner']; $other = $users['other']; $fan = $users['fan'];
    $make_post = function ($type, $status, $author, $title, $extra = []) use (&$posts) {
        $id = wp_insert_post(array_merge(['post_type'=>$type,'post_status'=>$status,'post_author'=>$author,'post_title'=>$title,'post_content'=>'Private fixture source content.'], $extra), true);
        account_check(!is_wp_error($id), 'Create dedicated account fixture post.'); $posts[] = (int) $id; return (int) $id;
    };
    for ($i = 0; $i < 25; $i++) { $make_post('post', 'draft', $owner, 'QA own draft ' . $i); }
    $own_public = $make_post('post', 'publish', $owner, 'QA own public title');
    $foreign_draft = $make_post('post', 'draft', $other, 'FOREIGN-DRAFT-PRIVATE-TITLE');
    $public = $make_post('post', 'publish', $other, 'QA public bookmark');
    $protected = $make_post('post', 'publish', $other, 'PASSWORD-PRIVATE-TITLE', ['post_password'=>'test-secret']);
    $ticket = $make_post('sluyu_ticket', 'pending', $owner, 'QA own pending ticket');
    $replied = $make_post('sluyu_ticket', 'pending', $owner, 'QA handled ticket');
    update_post_meta($replied, '_wuai_ticket_reply', 'Already handled.');
    $verified = $make_post('sluyu_verify', 'publish', $owner, 'QA approved verification');
    $private = $make_post('post', 'private', $owner, 'QA administrator private');
    $future = $make_post('post', 'future', $owner, 'QA scheduled', ['post_date'=>date('Y-m-d H:i:s', time() + DAY_IN_SECONDS),'post_date_gmt'=>gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)]);
    $product = $make_post('product', 'draft', $owner, 'QA protected product');
    $poll = $make_post('sluyu_topic', 'publish', $owner, 'QA immutable poll');
    update_post_meta($poll, '_wuai_topic_kind', 'poll'); update_post_meta($poll, '_wuai_poll_choices', ['A','B']); update_post_meta($poll, '_wuai_poll_mode', 'single');
    $poll_empty = $make_post('sluyu_topic', 'publish', $owner, 'QA empty poll');
    update_post_meta($poll_empty, '_wuai_topic_kind', 'poll'); update_post_meta($poll_empty, '_wuai_poll_choices', ['A','B']); update_post_meta($poll_empty, '_wuai_poll_mode', 'single');
    $post_private_total = 25 + 8;
    wp_set_current_user($owner);
    $first = wuai_sluyu_account_page_data('submissions', $owner, 1, 12);
    $second = wuai_sluyu_account_page_data('submissions', $owner, 2, 12);
    account_check($first['total'] === $post_private_total, 'Submission count includes only own supported types and allowed states.');
    account_check(count($first['items']) === 12 && count($second['items']) === 12 && $first['pages'] === 3, 'Submission pagination counts actual records.');
    account_check(!array_intersect(wp_list_pluck($first['items'], 'ID'), wp_list_pluck($second['items'], 'ID')), 'Submission pages do not overlap.');
    account_check(wuai_sluyu_account_page_data('submissions', $other)['total'] === 0, 'Account query helper refuses another user.');
    account_check(wuai_sluyu_account_page_data('comments', 0)['total'] === 0, 'Anonymous comments never bind to an account.');

    for ($i = 0; $i < 14; $i++) {
        $id = $make_post('post', 'publish', $other, 'QA bookmark ' . $i);
        wuai_sluyu_event($owner, 'bookmark:' . $id, 'bookmark', 0, $id);
    }
    foreach ([$public,$protected,$foreign_draft] as $id) { wuai_sluyu_event($owner, 'bookmark:' . $id, 'bookmark', 0, $id); }
    $bookmarks = wuai_sluyu_account_page_data('bookmarks', $owner, 2, 12);
    account_check($bookmarks['total'] === 17 && count($bookmarks['items']) === 5 && $bookmarks['pages'] === 2, 'Bookmarks retain accurate counts including unavailable entries.');
    $html = account_render($owner, ['tab'=>'bookmarks']);
    account_check(strpos($html, 'FOREIGN-DRAFT-PRIVATE-TITLE') === false && strpos($html, 'PASSWORD-PRIVATE-TITLE') === false, 'Unavailable bookmark titles remain private.');
    account_check(strpos($html, 'remove_bookmark') !== false && strpos($html, 'tab=bookmarks') !== false, 'Bookmark cleanup and tab-preserving pagination render.');
    wuai_sluyu_event($other, 'bookmark:' . $foreign_draft, 'bookmark', 0, $foreign_draft);
    account_check(!is_wp_error(account_action($owner, 'remove_bookmark', ['id'=>$foreign_draft])), 'Stale bookmark can be removed.');
    account_check(wuai_sluyu_has_event($other, 'bookmark:' . $foreign_draft), 'Bookmark removal never touches other users.');
    wuai_sluyu_event($owner, 'qa-points', 'qa', 75, 0, 'Account test points.');
    $points_before = wuai_sluyu_points($owner);

    wuai_sluyu_event($owner, 'follow:' . $other, 'follow', 0, $other);
    wuai_sluyu_event($fan, 'follow:' . $owner, 'follow', 0, $owner);
    $following = wuai_sluyu_account_page_data('following', $owner);
    $followers = wuai_sluyu_account_page_data('followers', $owner);
    account_check($following['total'] === 1 && (int) $following['items'][0]->user_id === $other, 'Following uses outgoing relationships.');
    account_check($followers['total'] === 1 && (int) $followers['items'][0]->user_id === $fan, 'Followers use incoming relationships.');
    update_user_meta($other, '_wuai_banned_until', -1);
    account_check(!is_wp_error(account_action($owner, 'remove_follow', ['id'=>$other])), 'Can remove an unavailable followed account.');
    delete_user_meta($other, '_wuai_banned_until');
    account_check(wuai_sluyu_has_event($fan, 'follow:' . $owner), 'Removing a follow cannot alter fans.');

    foreach ([[$public,$owner,'0','OWN PENDING COMMENT'],[$public,$owner,'1','OWN APPROVED COMMENT'],[$foreign_draft,$owner,'1','OWN PRIVATE-PARENT COMMENT'],[$public,$other,'1','OTHER COMMENT'],[$public,0,'1','ANONYMOUS COMMENT']] as $case) {
        $id = wp_insert_comment(['comment_post_ID'=>$case[0],'user_id'=>$case[1],'comment_approved'=>$case[2],'comment_content'=>$case[3],'comment_author'=>'QA account','comment_type'=>'comment']); $comments[] = (int) $id;
    }
    wp_set_current_user($owner);
    $comment_data = wuai_sluyu_account_page_data('comments', $owner);
    account_check($comment_data['total'] === 3, 'Only actual account user_id comments, including own pending.');
    $html = account_render($owner, ['tab'=>'comments']);
    account_check(strpos($html, 'OWN PENDING COMMENT') !== false && strpos($html, 'OTHER COMMENT') === false && strpos($html, 'ANONYMOUS COMMENT') === false, 'Comment rendering isolates authors.');
    account_check(strpos($html, 'FOREIGN-DRAFT-PRIVATE-TITLE') === false, 'Own comment does not expose private parent title.');
    account_check(strpos(account_render($other, ['tab'=>'submissions','edit'=>$own_public]), 'QA own public title') === false, 'Editor refuses another author without leaking title.');
    account_check(strpos(account_render(0, ['tab'=>'submissions','edit'=>$own_public]), 'QA own public title') === false, 'Guest sees login gate, not own post data.');
    account_check(is_wp_error(account_edit($other, $own_public)), 'Non-owner cannot edit published post.');
    account_check(is_wp_error(account_action($other, 'withdraw_submission', ['id'=>$own_public])), 'Non-owner cannot withdraw a post.');
    account_check(is_wp_error(account_action($owner, 'delete_submission', ['id'=>$own_public])), 'Public post cannot be directly deleted.');
    foreach ([$verified,$replied,$private,$future,$product] as $id) {
        foreach (['edit_submission','withdraw_submission','delete_submission'] as $operation) {
            account_check(is_wp_error(account_action($owner, $operation, ['id'=>$id])), 'Processed, administrator-managed and commerce records cannot mutate: ' . $operation);
        }
    }
    $version = wuai_sluyu_account_submission_version($own_public);
    wp_update_post(['ID'=>$own_public,'post_title'=>'QA changed elsewhere']);
    account_check(is_wp_error(account_edit($owner, $own_public, ['version'=>$version])), 'Stale editor version cannot overwrite another change.');
    account_check(is_wp_error(account_edit($owner, $own_public, ['title'=>['bad'],'body'=>['bad']])), 'Malformed field shapes rejected.');
    account_check(is_wp_error(account_edit($owner, $own_public, ['body'=>'<script>bad</script>'])), 'Markup-only short body rejected.');
    update_post_meta($own_public, '_wuai_sluyu_download_url', 'https://example.test/retained.zip');
    $result = account_edit($owner, $own_public, ['title'=>'QA safely revised','body'=>'<p>Safe edited content.</p><script>alert(1)</script>','post_status'=>'publish','post_author'=>$other,'meta_input'=>['_wuai_sluyu_download_url'=>'https://attacker.test/']]);
    account_check(!is_wp_error($result), 'Own public post can be edited.');
    $edited = get_post($own_public);
    account_check($edited->post_status === 'pending' && (int) $edited->post_author === $owner, 'Public edits re-enter moderation and preserve ownership.');
    account_check(strpos($edited->post_content, '<script>') === false, 'Unsafe script markup removed.');
    account_check(get_post_meta($own_public, '_wuai_sluyu_download_url', true) === 'https://example.test/retained.zip', 'Arbitrary metadata input ignored.');
    account_check(!is_wp_error(account_edit($owner, $poll_empty, ['choices'=>'Replaced','kind'=>'topic','poll_mode'=>'pk'])), 'Unvoted poll text may be corrected for moderation.');
    account_check(get_post_meta($poll_empty, '_wuai_poll_choices', true) === ['A','B'] && get_post_meta($poll_empty, '_wuai_topic_kind', true) === 'poll' && get_post_meta($poll_empty, '_wuai_poll_mode', true) === 'single', 'Poll meaning settings cannot be replaced by author payload.');
    wuai_sluyu_event($fan, 'vote:' . $poll, 'vote', 0, $poll, '0');
    account_check(is_wp_error(account_edit($owner, $poll)), 'Voted poll question cannot be edited.');
    account_check(!is_wp_error(account_action($owner, 'withdraw_submission', ['id'=>$poll])), 'Voted poll can be withdrawn.');
    account_check(is_wp_error(account_action($owner, 'delete_submission', ['id'=>$poll])), 'Voted poll evidence cannot be deleted.');
    account_check(wuai_sluyu_has_event($fan, 'vote:' . $poll), 'Voting evidence remains intact.');
    account_check(!is_wp_error(account_edit($owner, $ticket)), 'Unprocessed private ticket can be resubmitted.');
    account_check(get_post_status($ticket) === 'pending', 'Edited ticket stays private and pending.');
    $verify_draft=$make_post('sluyu_verify','draft',$owner,'QA duplicate verification');
    account_check(is_wp_error(account_edit($owner,$verify_draft)),'Withdrawn verification cannot be resubmitted while another approved application exists.');
    $verify_latest=$make_post('sluyu_verify','publish',$owner,'QA current verification');
    wp_update_post(['ID'=>$verified,'post_status'=>'draft']);
    account_check(get_user_meta($owner,'_wuai_verified_title',true)==='QA current verification','Revoking an older application preserves the remaining approved badge.');
    account_check(!is_wp_error(account_action($owner, 'withdraw_submission', ['id'=>$own_public])), 'Pending content can be withdrawn.');
    account_check(get_post_status($own_public) === 'draft', 'Withdrawn post is a draft.');
    account_check(!is_wp_error(account_action($owner, 'delete_submission', ['id'=>$own_public])), 'Only own non-public content can be deleted.');
    account_check(!get_post($own_public) || get_post_status($own_public) === 'trash', 'Deletion removes draft from active content.');
    account_check(wuai_sluyu_points($owner) === $points_before, 'Account edits and cleanup preserve points ledger.');
    update_user_meta($owner, '_wuai_banned_until', -1);
    account_check(is_wp_error(account_action($owner, 'remove_bookmark', ['id'=>$public])), 'Banned account mutation rejected even at dispatch layer.');
    delete_user_meta($owner, '_wuai_banned_until');
    $html = account_render($owner, ['tab'=>['invalid'],'pg'=>['bad']]);
    account_check(strpos($html, 'data-operation="profile"') !== false && strpos($html, 'data-account-list="bookmarks"') !== false, 'Malformed query falls back to overview and preserves profile/bookmark experience.');
    echo "PASS: account tabs/pagination, owned comments, followers, private record isolation, stale-write checks, moderated edits, immutable polls, withdrawal/deletion and ledger preservation.\n";
} finally {
    $_POST = []; wp_set_current_user(0);
    foreach ($comments as $id) { wp_delete_comment($id, true); }
    foreach ($posts as $id) { wp_delete_post($id, true); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($created_users as $id) { wp_delete_user($id); }
    $_POST = $old_post; $_GET = $old_get; wp_set_current_user($old_user);
}
