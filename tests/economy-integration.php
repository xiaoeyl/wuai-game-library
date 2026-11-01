<?php
// Serial-only integration checks. Never load a production installation or mail.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); $expected = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
if (!$root || !$expected || strcasecmp((string) realpath($root), $expected) !== 0) { throw new RuntimeException('Dedicated local WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_economy_contract_ready') || !wuai_sluyu_economy_contract_ready()) { throw new RuntimeException('Reviewed local economy integration required.'); }
function econ_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function econ_balance($uid, $expected, $message) { econ_check(wuai_sluyu_points($uid) === $expected && wuai_sluyu_economy_balance($uid) === $expected, $message); }
function econ_capture($callback) { ob_start(); try { $callback(); return ob_get_contents(); } finally { ob_end_clean(); } }
econ_check(has_action('wpmu_delete_user', 'wuai_sluyu_multisite_user_deletion_block') === 1, 'Multisite deletion is registered at the fail-closed priority before core removes network data.');
$old_user = get_current_user_id(); $old_post = $_POST; $old_get = $_GET; $old_options = get_option('wuai_sluyu_options'); $old_enabled = get_option('wuai_sluyu_bounty_enabled', '0');
$prefix = 'qa_econ_' . strtolower(wp_generate_password(9, false, false)); $users = []; $posts = []; $comments = []; $terms = []; $filters = [];
$mail = static function () { return true; }; add_filter('pre_wp_mail', $mail, PHP_INT_MAX);
try {
    global $wpdb; $_POST = []; $_GET = [];
    foreach (['owner'=>'subscriber','reader'=>'subscriber','winner'=>'subscriber','admin'=>'administrator','doomed'=>'subscriber','survivor'=>'subscriber'] as $name=>$role) {
        $uid = wp_create_user($prefix . '_' . $name, 'qa-local-only', $prefix . '_' . $name . '@example.test');
        econ_check(!is_wp_error($uid), 'Create dedicated economic integration identity.');
        $users[$name] = (int) $uid; (new WP_User($uid))->set_role($role);
    }
    $owner = $users['owner']; $reader = $users['reader']; $winner = $users['winner']; $admin = $users['admin'];
    update_option('wuai_sluyu_options', array_merge((array) $old_options, ['checkin_points'=>10, 'makeup_points'=>30]));
    update_option('wuai_sluyu_bounty_enabled', '0');
    econ_check(!wuai_sluyu_bounty_enabled(), 'Administrator opt-in is required even when the integration contract is ready.');
    update_option('wuai_sluyu_bounty_enabled', '1');
    econ_check(get_option('wuai_sluyu_storage_version') === '5', 'Complete migration records schema five.');
    econ_check(wuai_sluyu_event($reader, $prefix . ':seed', 'qa', 500, 0, 'Seed') === 1, 'Legacy nonzero boundary reports one newly created row.');
    econ_check(wuai_sluyu_event($reader, $prefix . ':seed', 'qa', 500, 0, 'Seed') === false, 'Exact duplicate is false at legacy boundary, not truthy created=false.');
    econ_check(wuai_sluyu_event($reader, $prefix . ':seed', 'qa', 501, 0, 'Seed') === false, 'Conflicting event cannot reuse an existing key.');
    econ_check(wuai_sluyu_event($reader, $prefix . ':bad', 'qa', '5', 0, 'Invalid') === false, 'Scalar coercion cannot bypass nonzero writer.');
    econ_balance($reader, 500, 'Exact seed after duplicate and invalid operations.');

    wp_set_current_user($reader); $result = wuai_sluyu_checkin($reader);
    econ_check(!is_wp_error($result) && (int) get_user_meta($reader, '_wuai_sluyu_checkin_days', true) === 1, 'Direct check-in safely acquires user then economic lock.');
    econ_check(!is_wp_error(wuai_sluyu_checkin($reader)) && (int) get_user_meta($reader, '_wuai_sluyu_checkin_days', true) === 1, 'Repeated check-in advances counters once.');
    econ_balance($reader, 510, 'Repeated check-in credits once.');
    $account_lock = wuai_sluyu_lock('user_' . $reader); econ_check((bool) $account_lock, 'Acquire dispatcher account lock.');
    try { econ_check(!is_wp_error(wuai_sluyu_checkin($reader)), 'Nested check-in borrows only a request-owned account lock.'); }
    finally { wuai_sluyu_unlock($account_lock); }
    update_user_meta($reader, '_wuai_sluyu_checkin_date', current_datetime()->modify('-3 days')->format('Y-m-d'));
    $days = (int) get_user_meta($reader, '_wuai_sluyu_checkin_days', true);
    econ_check(!is_wp_error(wuai_sluyu_community_operation('makeup', $reader)), 'Makeup is one guarded negative event and counter update.');
    econ_check(is_wp_error(wuai_sluyu_community_operation('makeup', $reader)) && (int) get_user_meta($reader, '_wuai_sluyu_checkin_days', true) === $days + 2, 'Makeup duplicate cannot advance counters twice.');
    econ_balance($reader, 450, 'Two-day makeup debits exactly sixty.');
    update_user_meta($reader, 'description', 'QA completed profile'); $_POST = ['task'=>'profile'];
    econ_check(!is_wp_error(wuai_sluyu_community_operation('task', $reader)), 'Positive task reward uses the same economic guard.');
    econ_check(is_wp_error(wuai_sluyu_community_operation('task', $reader)), 'Task claim remains one-time.');
    econ_balance($reader, 470, 'Task adds twenty once.'); $_POST = [];

    $make_post = function ($type = 'post') use ($owner, $prefix, &$posts) {
        $id = wp_insert_post(['post_type'=>$type,'post_status'=>'publish','post_author'=>$owner,'post_title'=>$prefix . ' public resource','post_content'=>'Public local fixture content.','comment_status'=>'open'], true);
        econ_check(!is_wp_error($id) && $id, 'Create local public content.'); $posts[] = (int) $id; return (int) $id;
    };
    $protected = $make_post(); $download = $make_post(); $expensive = $make_post();
    foreach ([$protected=>30, $expensive=>450] as $id=>$cost) {
        update_post_meta($id, wuai_sluyu_protected_key(), ['body'=>'Private local fixture fragment.','access'=>'points','cost'=>$cost,'version'=>wp_generate_uuid4()]);
    }
    update_post_meta($download, '_wuai_download_settings', ['url'=>'https://example.com/resource','password'=>'','access'=>'points','cost'=>25,'version'=>wp_generate_uuid4()]);
    $lock_order = []; $watch = function ($sql) use (&$lock_order, $wpdb) {
        if (strpos($sql, 'INSERT INTO ' . wuai_sluyu_table('economy_mutex')) === 0) { $lock_order[] = 'economy'; }
        if (strpos($sql, "INSERT IGNORE INTO $wpdb->options") === 0 && strpos($sql, 'wuai_lock_submission_') !== false) { $lock_order[] = 'post'; }
        return $sql;
    };
    add_filter('query', $watch); $filters[] = ['query',$watch,10];
    $unlocked = wuai_sluyu_protected_unlock($protected, wuai_sluyu_protected_quote($protected), '1');
    remove_filter('query', $watch); $filters = [];
    econ_check(!is_wp_error($unlocked) && $unlocked['charged'] === 30 && $lock_order === ['economy','post'], 'Protected spend acquires economy before content and charges once.');
    econ_check(wuai_sluyu_protected_unlock($protected, wuai_sluyu_protected_quote($protected), '1')['charged'] === 0, 'Protected replay preserves existing entitlement.');
    $unlocked = wuai_sluyu_download_unlock($download, wuai_sluyu_download_quote($download), '1');
    econ_check(!is_wp_error($unlocked) && $unlocked['charged'] === 25, 'Download uses fenced negative-event writer.');
    econ_check(wuai_sluyu_download_unlock($download, wuai_sluyu_download_quote($download), '1')['charged'] === 0, 'Download replay cannot double debit.');
    econ_balance($reader, 415, 'Both independent entitlements have exact net balance.');
    econ_check(is_wp_error(wuai_sluyu_protected_unlock($expensive, wuai_sluyu_protected_quote($expensive), '1')), 'Insufficient complete balance cannot grant entitlement.');

    $blocked_write = function ($sql) { return strpos($sql, 'INSERT INTO ' . wuai_sluyu_table('events')) === 0 ? $sql . ' AND 1=0' : $sql; };
    add_filter('query', $blocked_write); $filters[] = ['query',$blocked_write,10];
    econ_check(wuai_sluyu_event($reader, $prefix . ':blocked', 'qa', 8, 0, 'Blocked') === false, 'WP_Error never escapes as a truthy legacy INSERT result.');
    remove_filter('query', $blocked_write); $filters = [];
    econ_balance($reader, 415, 'Failed positive write adds no phantom balance.');

    wuai_sluyu_event($owner, $prefix . ':owner_seed', 'qa', 200, 0, 'Owner seed'); wp_set_current_user($owner);
    $input = ['amount'=>'80','days'=>'2','audience'=>'everyone','targets'=>'','request_key'=>wp_generate_uuid4(),'title'=>$prefix . ' bounty','body'=>'Public non-cash integration fixture question.','group'=>'0','confirm'=>'1'];
    $created = wuai_sluyu_bounty_submit($input); econ_check(!is_wp_error($created), 'Create authority allocation for combined balance.'); $bounty = (int) $created['id']; $posts[] = $bounty;
    econ_balance($owner, 120, 'Existing reader exposes net reserved balance, not gross event balance.');
    $ledger = wuai_sluyu_economy_ledger($owner, 1, 30);
    econ_check(!is_wp_error($ledger) && $ledger['total'] === 2 && array_sum(array_column($ledger['items'], 'points')) === 120, 'Merged ledger includes seed and one reserve debit.');
    $first_page = wuai_sluyu_economy_ledger($owner, 1, 1); $second_page = wuai_sluyu_economy_ledger($owner, 2, 1);
    econ_check($first_page['total'] === 2 && $first_page['items'][0]['ledger_key'] !== $second_page['items'][0]['ledger_key'], 'Merged pagination uses total and unique derived keys.');
    $html = econ_capture(function () { wuai_sluyu_view_gold(); });
    econ_check(strpos($html, 'bounty:') !== false && strpos($html, '>120</h2>') !== false, 'Gold view shows authority reserve and net balance.');
    $post = get_post($bounty); econ_check(is_wp_error(wuai_sluyu_account_submission_permission($post, $owner, 'edit_submission')), 'Account editor refuses funded topic.');
    $html = econ_capture(function () use ($owner) { wuai_sluyu_account_render_list('submissions', $owner); });
    econ_check(strpos($html, 'data-operation="bounty_withdraw"') !== false && strpos($html, 'name="version"') !== false && strpos($html, 'name="confirm" value="1" required') !== false, 'Pending requester return form includes authoritative version and explicit confirmation.');
    wp_set_current_user($admin); $_POST = ['wuai_service_meta_nonce'=>wp_create_nonce('wuai_service_meta'),'wuai_topic_kind'=>'question','wuai_poll_choices'=>"A\nB"];
    wp_update_post(['ID'=>$bounty,'post_status'=>'publish']); $_POST = [];
    econ_check(get_post_meta($bounty, '_wuai_topic_kind', true) === 'bounty' && wuai_sluyu_bounty_row($bounty)['state'] === 'held', 'Native service fields cannot convert bounty and publication alone never opens it.');
    econ_check(!wuai_sluyu_circle_review_config_valid($bounty), 'Circle owner publishing cannot activate economic question.');
    $ordinary = $make_post('sluyu_topic'); update_post_meta($ordinary, '_wuai_topic_kind', 'topic');
    $_POST = ['wuai_service_meta_nonce'=>wp_create_nonce('wuai_service_meta'),'wuai_topic_kind'=>'question']; wp_update_post(['ID'=>$ordinary,'post_title'=>$prefix . ' ordinary']); $_POST = [];
    econ_check(get_post_meta($ordinary, '_wuai_topic_kind', true) === 'question', 'Ordinary service editing still works without an allocation.');
    $row = wuai_sluyu_bounty_row($bounty); econ_check(wuai_sluyu_bounty_review($bounty, 'approve', (string) $row['version']) === true, 'Separate admin economic review opens published held row.');
    $answer = wp_insert_comment(['comment_post_ID'=>$bounty,'user_id'=>$winner,'comment_author'=>'QA winner','comment_content'=>'A useful isolated answer.','comment_approved'=>1,'comment_type'=>'comment','comment_parent'=>0,'comment_date'=>current_time('mysql'),'comment_date_gmt'=>current_time('mysql',true)]); $comments[] = (int) $answer;
    $row = wuai_sluyu_bounty_row($bounty); wp_set_current_user($owner); $_POST = ['id'=>(string) $answer];
    econ_check(is_wp_error(wuai_sluyu_community_operation('accept', $owner)), 'Ordinary acceptance never distributes a bounty.'); $_POST = [];
    econ_check(!is_wp_error(wuai_sluyu_bounty_accept((string) $answer, (string) $row['version'])), 'Explicit owner award atomically assigns existing reservation.');
    econ_balance($owner, 120, 'Award does not debit requester twice.'); econ_balance($winner, 80, 'Award becomes recipient spendable balance.');
    update_option('wuai_sluyu_bounty_enabled', '0');
    econ_check(!wuai_sluyu_bounty_enabled() && !is_wp_error(wuai_sluyu_bounty_accept((string) $answer, (string) $row['version'])), 'Closing creation does not erase or freeze an existing completed allocation.');
    update_option('wuai_sluyu_bounty_enabled', '1');
    $row = wuai_sluyu_bounty_row($bounty);
    $wpdb->update($wpdb->postmeta, ['meta_value'=>'question'], ['post_id'=>$bounty,'meta_key'=>'_wuai_topic_kind']); clean_post_cache($bounty);
    econ_check(wuai_sluyu_bounty_managed($bounty) && is_wp_error(wuai_sluyu_account_submission_permission(get_post($bounty), $owner, 'withdraw_submission')), 'Authority allocation protects a malformed/altered kind too.');
    $_POST = ['id'=>(string) $answer]; econ_check(is_wp_error(wuai_sluyu_community_operation('accept', $owner)), 'Altered kind cannot bypass ordinary accept restriction.'); $_POST = [];
    update_post_meta($bounty, '_wuai_topic_kind', 'bounty');
    econ_check(wuai_sluyu_bounty_row($bounty)['state'] === 'awarded', 'Terminal award survives type repair.');

    delete_transient('wuai_bounty_submit_' . $owner); $input['request_key'] = wp_generate_uuid4(); $input['amount'] = '50'; $input['title'] .= ' expiring';
    $created = wuai_sluyu_bounty_submit($input); econ_check(!is_wp_error($created), 'Create expiring reservation.'); $expiring = (int) $created['id']; $posts[] = $expiring;
    $wpdb->update(wuai_sluyu_table('bounties'), ['deadline'=>time()-1], ['question_id'=>$expiring]);
    econ_check(wuai_sluyu_points_available($owner) === 120 && wuai_sluyu_bounty_row($expiring)['state'] === 'refunded', 'User entry reconciles due allocation before returning spendable balance.');

    wuai_sluyu_event($owner, 'bookmark:' . $ordinary, 'bookmark', 7, $ordinary, 'Dirty type still has monetary effect');
    wuai_sluyu_event($reader, 'follow:' . $owner, 'follow', 9, $owner, 'Foreign nonzero follow must remain');
    wuai_sluyu_event($owner, 'follow:' . $reader, 'follow', 0, $reader, '');
    $_POST = ['id'=>(string) $ordinary]; econ_check(is_wp_error(wuai_sluyu_community_operation('bookmark', $owner)), 'Interaction cancellation refuses to erase nonzero dirty row.'); $_POST = [];
    $erased = wuai_sluyu_erase_personal_data(get_userdata($owner)->user_email);
    econ_check($erased['items_retained'] && wuai_sluyu_has_event($owner, 'bookmark:' . $ordinary) && wuai_sluyu_has_event($reader, 'follow:' . $owner) && !wuai_sluyu_has_event($owner, 'follow:' . $reader), 'Privacy clears zero interactions only and discloses retained allocations.');
    econ_balance($owner, 127, 'Privacy preserves requester allocation and nonzero event.'); econ_balance($reader, 424, 'Foreign dirty follow survives targeted erasure.');

    $schema_fault = static function () { return '4'; }; add_filter('pre_option_wuai_sluyu_storage_version', $schema_fault); $filters[] = ['pre_option_wuai_sluyu_storage_version',$schema_fault,10];
    econ_check(is_wp_error(wuai_sluyu_points($owner)) && is_wp_error(wuai_sluyu_economy_ledger($owner)), 'Incomplete schema never falls back to gross event balance or history.');
    econ_check(wuai_sluyu_event($owner, $prefix . ':schema_fault', 'qa', 10, 0, 'Must not write') === false, 'Incomplete migration blocks nonzero INSERT.');
    foreach ([function () { wuai_sluyu_view_gold(); }, function () { wuai_sluyu_view_account_extended(); }, function () { wuai_sluyu_view_mission(); }, function () { wuai_sluyu_extended_widget('user', 1, [], []); }] as $render) {
        $html = econ_capture($render);
        econ_check(strpos($html, wuai_sluyu_points_label(new WP_Error('qa'))) !== false, 'Every balance display renders an unavailable label without WP_Error coercion.');
    }
    econ_check(is_wp_error(wuai_sluyu_bounty_export(get_userdata($owner)->user_email)), 'Failed allocation export is not a fabricated empty completed report.');
    wp_set_current_user($reader); econ_check(is_wp_error(wuai_sluyu_download_unlock($download, wuai_sluyu_download_quote($download), '1')), 'Incomplete storage refuses spend path instead of fallback.');
    remove_filter('pre_option_wuai_sluyu_storage_version', $schema_fault); $filters = [];

    $doomed = $users['doomed']; $survivor = $users['survivor']; wuai_sluyu_event($doomed, $prefix . ':delete_seed', 'qa', 40, 0, 'Retain until actual deletion.');
    wuai_sluyu_event($survivor, 'follow:' . $doomed, 'follow', 5, $doomed, 'Do not erase foreign nonzero event.'); wp_set_current_user($admin);
    $deny_native_delete = function ($sql) use ($doomed, $wpdb) {
        if (preg_match('/^DELETE\s+FROM\s+`?' . preg_quote($wpdb->users, '/') . '`?\s+WHERE\s/is', $sql) && preg_match('/`?ID`?\s*=\s*\'?' . $doomed . '\'?\s*$/D', $sql)) { return $sql . ' AND 1=0'; }
        return $sql;
    };
    $die_handler = static function () { return static function ($message) { throw new RuntimeException('qa-delete-denied:' . wp_strip_all_tags((string) $message)); }; };
    add_filter('query', $deny_native_delete); $filters[] = ['query',$deny_native_delete,10]; add_filter('wp_die_handler', $die_handler); $filters[] = ['wp_die_handler',$die_handler,10];
    require_once ABSPATH . 'wp-admin/includes/user.php'; $stopped = false;
    try { wp_delete_user($doomed, $admin); } catch (RuntimeException $error) { if (strpos($error->getMessage(), 'qa-delete-denied:') !== 0) { throw $error; } $stopped = true; }
    foreach ($filters as $filter) { remove_filter($filter[0], $filter[1], $filter[2]); } $filters = [];
    econ_check($stopped && (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->users WHERE ID=%d", $doomed)) === $doomed, 'Failed core users DELETE is not trusted merely because deleted_user fires.');
    econ_balance($doomed, 40, 'Unconfirmed account deletion retains original points events.');
    econ_check(!empty($GLOBALS['wuai_sluyu_user_deletion_economy'][$doomed]), 'Partial native deletion retains durable economic guard for inspection.');
    // The injected request is stopped and all fixture code is serial; release
    // only this test-owned guard before retrying native deletion, never a UI path.
    wuai_sluyu_economy_leave($GLOBALS['wuai_sluyu_user_deletion_economy'][$doomed]); unset($GLOBALS['wuai_sluyu_user_deletion_economy'][$doomed]);
    wuai_sluyu_unlock($GLOBALS['wuai_sluyu_user_deletion_locks'][$doomed]); unset($GLOBALS['wuai_sluyu_user_deletion_locks'][$doomed]);
    econ_check(wp_delete_user($doomed, $admin), 'Native deletion retries after fixture storage recovery.');
    econ_balance($survivor, 5, 'Deleting another user preserves foreign nonzero follow balance.');
    echo "PASS: complete balance and merged history, duplicate-safe legacy events, guarded check-in/makeup/tasks/unlocks, authoritative bounty editor/account/accept boundaries, due refunds, zero-only privacy, incomplete-schema fail-closed displays and raw-verified native deletion. Mail blocked.\n";
} finally {
    foreach ($filters as $filter) { remove_filter($filter[0], $filter[1], $filter[2]); }
    $_POST = []; $_GET = []; wp_set_current_user($users['admin'] ?? 0);
    foreach ($GLOBALS['wuai_sluyu_user_deletion_economy'] ?? [] as $uid=>$guard) { if (in_array((int) $uid, $users, true)) { wuai_sluyu_economy_leave($guard); unset($GLOBALS['wuai_sluyu_user_deletion_economy'][$uid]); } }
    foreach ($GLOBALS['wuai_sluyu_user_deletion_locks'] ?? [] as $uid=>$lock) { if (in_array((int) $uid, $users, true)) { wuai_sluyu_unlock($lock); unset($GLOBALS['wuai_sluyu_user_deletion_locks'][$uid]); } }
    foreach ($comments as $id) { wp_delete_comment($id, true); }
    foreach ($posts as $id) { wp_delete_post($id, true); }
    foreach ($terms as $id) { wp_delete_term($id, 'sluyu_circle'); }
    $guard = wuai_sluyu_economy_enter('qa_integration_cleanup');
    if (!is_wp_error($guard)) { try { foreach ($users as $uid) { $wpdb->delete(wuai_sluyu_table('bounties'), ['requester_id'=>$uid]); } } finally { wuai_sluyu_economy_leave($guard); } }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $name=>$uid) { $user = get_userdata($uid); if ($user && $user->user_login === $prefix . '_' . $name) { delete_transient('wuai_bounty_submit_' . $uid); wp_delete_user($uid); } }
    if ($old_options === false) { delete_option('wuai_sluyu_options'); } else { update_option('wuai_sluyu_options', $old_options); }
    update_option('wuai_sluyu_bounty_enabled', $old_enabled); remove_filter('pre_wp_mail', $mail, PHP_INT_MAX); $_POST = $old_post; $_GET = $old_get; wp_set_current_user($old_user);
}
