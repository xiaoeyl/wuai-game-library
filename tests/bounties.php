<?php
// Serial-only, dedicated local integration suite. No schema changes are made here.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); $expected = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
if (!$root || !$expected || strcasecmp((string) realpath($root), $expected) !== 0) { throw new RuntimeException('Dedicated WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REQUEST_URI'] = '/'; require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_bounty_submit') || !wuai_sluyu_economy_contract_ready()) { throw new RuntimeException('Integrate and migrate the local economy contract first.'); }
function bounty_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function bounty_assert_balance($uid, $expected, $message) { bounty_check(wuai_sluyu_economy_balance($uid) === $expected, $message); bounty_check(wuai_sluyu_points($uid) === $expected, 'Shared points integration: ' . $message); }
$old_user = get_current_user_id(); $old_post = $_POST; $old_get = $_GET; $old_enabled = get_option('wuai_sluyu_bounty_enabled', '0');
$prefix = 'qa_bounty_' . strtolower(wp_generate_password(9, false, false)); $users = []; $posts = []; $comments = []; $terms = []; $callbacks = [];
$mail = function () { return true; }; add_filter('pre_wp_mail', $mail, PHP_INT_MAX);
try {
    $_POST = []; $_GET = [];
    foreach (['owner'=>'subscriber','winner'=>'subscriber','other'=>'subscriber','admin'=>'administrator','doomed_owner'=>'subscriber','doomed_winner'=>'subscriber'] as $name=>$role) {
        $id = wp_create_user($prefix . '_' . $name, 'qa-local-only', $prefix . '_' . $name . '@example.test'); bounty_check(!is_wp_error($id), 'Create isolated bounty user.');
        (new WP_User($id))->set_role($role); $users[$name] = (int) $id;
    }
    $owner = $users['owner']; $winner = $users['winner']; $other = $users['other']; $admin = $users['admin'];
    bounty_check(!is_wp_error(wuai_sluyu_economy_event($owner, $prefix . ':seed', 'qa', 1000, 0, 'Isolated bounty test seed.')), 'Seed spendable points.');
    $make = function ($extra = []) use ($owner, $prefix, &$posts) {
        wp_set_current_user($owner); delete_transient('wuai_bounty_submit_' . $owner);
        $input = array_merge(['amount'=>'60', 'days'=>'2', 'audience'=>'everyone', 'targets'=>'', 'request_key'=>wp_generate_uuid4(), 'title'=>$prefix . ' question', 'body'=>'A public non-cash bounty test question.', 'group'=>'0', 'confirm'=>'1'], $extra);
        $result = wuai_sluyu_bounty_submit($input);
        if (!is_wp_error($result)) { $posts[] = (int) $result['id']; }
        return [$result, $input];
    };
    $open = function ($id) use ($admin) {
        wp_set_current_user($admin); $_POST = []; wp_update_post(['ID'=>$id, 'post_status'=>'publish']);
        $row = wuai_sluyu_bounty_row($id); bounty_check($row['state'] === 'held', 'Native publish alone cannot activate reserved awards.');
        bounty_check(wuai_sluyu_bounty_review($id, 'approve', (string) $row['version']) === true, 'Explicit site administrator review opens the bounty.');
        return wuai_sluyu_bounty_row($id);
    };
    $answer = function ($id, $uid, $approved = 1, $extra = []) use (&$comments) {
        $comment = wp_insert_comment(array_merge(['comment_post_ID'=>$id, 'user_id'=>$uid, 'comment_author'=>'QA responder', 'comment_author_email'=>get_userdata($uid)->user_email, 'comment_content'=>'A useful public local test answer.', 'comment_type'=>'comment', 'comment_parent'=>0, 'comment_approved'=>$approved, 'comment_date'=>current_time('mysql'), 'comment_date_gmt'=>current_time('mysql', true)], $extra));
        bounty_check((bool) $comment, 'Create native answer.'); $comments[] = (int) $comment; return (int) $comment;
    };
    update_option('wuai_sluyu_bounty_enabled', '0'); [$disabled] = $make(); bounty_check(is_wp_error($disabled), 'Disabled by default, no reservation.');
    update_option('wuai_sluyu_bounty_enabled', '1');
    foreach ([['amount'=>'0'], ['amount'=>'-1'], ['amount'=>'1.5'], ['amount'=>'1000001'], ['days'=>'1'], ['days'=>'30'], ['days'=>'2.0'], ['confirm'=>'0'], ['group'=>['0']], ['audience'=>'someone','targets'=>(string) $owner], ['audience'=>'someone','targets'=>$winner . ',' . $winner], ['audience'=>'everyone','targets'=>(string) $winner]] as $invalid) {
        [$result] = $make($invalid); bounty_check(is_wp_error($result), 'Malformed terms never reserve points.');
    }
    bounty_assert_balance($owner, 1000, 'Invalid forms leave balance unchanged.');
    [$first, $first_input] = $make(); bounty_check(!is_wp_error($first), 'Create valid held reservation.'); $id = (int) $first['id'];
    $row = wuai_sluyu_bounty_row($id); bounty_check($row['state'] === 'held' && get_post_status($id) === 'pending', 'Pending review occupies points immediately.');
    bounty_assert_balance($owner, 940, 'Pending reserve reduces available points.');
    wp_set_current_user($owner); $repeat = wuai_sluyu_bounty_submit($first_input); bounty_check(!is_wp_error($repeat) && $repeat['id'] === $id, 'Same request key replays same post.');
    bounty_check(is_wp_error(wuai_sluyu_bounty_submit(array_merge($first_input, ['amount'=>'61']))), 'A request key cannot silently change terms.');
    bounty_assert_balance($owner, 940, 'Replay cannot double reserve.');
    $bad_spend = wuai_sluyu_economy_event($owner, $prefix . ':overspend', 'qa_spend', -950, 0, 'Must fail.'); bounty_check(is_wp_error($bad_spend), 'Other economic operations cannot spend reserved points.');
    wp_set_current_user($other); bounty_check(is_wp_error(wuai_sluyu_bounty_review($id, 'approve', '1')), 'Nonadmin cannot activate bounty.');
    $row = $open($id); $c1 = $answer($id, $winner); $c2 = $answer($id, $other); $self = $answer($id, $owner); $pending = $answer($id, $winner, 0);
    wp_set_current_user($admin); bounty_check(is_wp_error(wuai_sluyu_bounty_accept($c1, (string) $row['version'])), 'Admin cannot choose winner for requester.');
    wp_set_current_user($owner);
    foreach ([$self, $pending] as $cid) { bounty_check(is_wp_error(wuai_sluyu_bounty_accept($cid, (string) $row['version'])), 'No self-answer or unmoderated awards.'); }
    bounty_check(is_wp_error(wuai_sluyu_bounty_accept($c1, '1')), 'Stale version cannot award.');
    $block_award = function ($query) { return strpos($query, 'UPDATE ' . wuai_sluyu_table('bounties') . " SET state='awarded'") === 0 ? 'SELECT 0' : $query; };
    add_filter('query', $block_award); $callbacks[] = ['query',$block_award,10];
    bounty_check(is_wp_error(wuai_sluyu_bounty_accept($c1, (string) $row['version'])), 'CAS failure grants neither winner points nor extra requester debit.');
    remove_filter('query', $block_award); $callbacks = [];
    bounty_assert_balance($owner, 940, 'Failed award preserves reservation.'); bounty_assert_balance($winner, 0, 'Failed award has no half credit.');
    $ban_at_cas = function ($query) use ($winner, $wpdb) {
        if (strpos($query, 'UPDATE ' . wuai_sluyu_table('bounties') . " SET state='awarded'") === 0) {
            $wpdb->insert($wpdb->usermeta, ['user_id'=>$winner,'meta_key'=>'_wuai_banned_until','meta_value'=>'-1']);
        }
        return $query;
    };
    add_filter('query', $ban_at_cas); $callbacks[] = ['query',$ban_at_cas,10];
    bounty_check(is_wp_error(wuai_sluyu_bounty_accept($c1, (string) $row['version'])), 'A ban inserted after PHP eligibility checks is rejected by the award SQL itself.');
    remove_filter('query', $ban_at_cas); $callbacks = []; delete_user_meta($winner, '_wuai_banned_until'); clean_user_cache($winner);
    bounty_assert_balance($winner, 0, 'Late authoritative ban cannot create a stale-cache award.');
    bounty_check(!is_wp_error(wuai_sluyu_bounty_accept($c1, (string) $row['version'])), 'Single CAS awards existing allocation.');
    bounty_assert_balance($owner, 940, 'Award does not debit requester twice.'); bounty_assert_balance($winner, 60, 'Winner receives exact reserved amount.');
    bounty_check(!is_wp_error(wuai_sluyu_bounty_accept($c1, (string) $row['version'])), 'Repeated same award is idempotent.');
    bounty_check(is_wp_error(wuai_sluyu_bounty_accept($c2, (string) $row['version'])), 'Competing selection cannot replace winner.');
    bounty_check(is_wp_error(wuai_sluyu_bounty_withdraw($id, (string) wuai_sluyu_bounty_row($id)['version'])), 'Award cannot be withdrawn/refunded.');
    $events = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . wuai_sluyu_table('events') . ' WHERE user_id IN (%d,%d) AND points<>0', $owner, $winner));
    bounty_check((int) $events === 1, 'Award does not append debit/credit events.');
    $ledger_owner = wuai_sluyu_economy_ledger($owner); $ledger_winner = wuai_sluyu_economy_ledger($winner);
    bounty_check(!is_wp_error($ledger_owner) && !is_wp_error($ledger_winner), 'Merged derived ledgers available.');
    bounty_check(array_sum(array_map('intval', array_column($ledger_owner['items'], 'points'))) === 940 && array_sum(array_map('intval', array_column($ledger_winner['items'], 'points'))) === 60, 'Ledger sums reconcile with both balances.');
    wp_delete_comment($c1, true); bounty_assert_balance($winner, 60, 'Deleting accepted comment cannot undo earned allocation.');
    wp_delete_post($id, true); bounty_assert_balance($owner, 940, 'Deleting question cannot restore awarded debit.'); bounty_assert_balance($winner, 60, 'Deleting question cannot revoke counterparty credit.');

    [$targeted] = $make(['audience'=>'someone','targets'=>(string) $winner, 'amount'=>'40']); $target_id = (int) $targeted['id']; $target_row = $open($target_id);
    $foreign_answer = $answer($target_id, $other); $valid_answer = $answer($target_id, $winner);
    wp_set_current_user($owner); bounty_check(is_wp_error(wuai_sluyu_bounty_accept($foreign_answer, (string) $target_row['version'])), 'Non-target cannot win even if a native comment exists.');
    bounty_check(!is_wp_error(wuai_sluyu_bounty_accept($valid_answer, (string) $target_row['version'])), 'Selected responder may win.');
    bounty_assert_balance($owner, 900, 'Second awarded reservation exact.'); bounty_assert_balance($winner, 100, 'Two awards accumulated exactly.');

    $block_reserve = function ($query) { return strpos($query, 'INSERT INTO ' . wuai_sluyu_table('bounties') . ' (question_id,requester_id,') === 0 ? 'SELECT 0' : $query; };
    add_filter('query', $block_reserve); $callbacks[] = ['query',$block_reserve,10];
    [$reserve_failed] = $make(['amount'=>'25']); bounty_check(is_wp_error($reserve_failed), 'Failed allocation INSERT does not grant a pending funded bounty.');
    remove_filter('query', $block_reserve); $callbacks = []; bounty_assert_balance($owner, 900, 'Failed reservation never debits requester.');
    $block_queue = function ($empty, $postarr) use ($prefix) { return isset($postarr['post_status'],$postarr['post_title']) && $postarr['post_status'] === 'pending' && $postarr['post_title'] === $prefix . ' queue failure' ? true : $empty; };
    add_filter('wp_insert_post_empty_content', $block_queue, 99, 2); $callbacks[] = ['wp_insert_post_empty_content',$block_queue,99];
    [$queue_failed] = $make(['title'=>$prefix . ' queue failure','amount'=>'25']); bounty_check(is_wp_error($queue_failed), 'Failure after reserve before pending is reported.');
    remove_filter('wp_insert_post_empty_content', $block_queue, 99); $callbacks = []; bounty_assert_balance($owner, 900, 'Verified queue failure returns the same reservation instead of a compensating event.');
    $orphans = get_posts(['post_type'=>'sluyu_topic','post_status'=>['draft','pending'],'author'=>$owner,'numberposts'=>-1]);
    foreach ($orphans as $orphan) { if (strpos($orphan->post_title, $prefix . ' ') === 0 && !in_array((int) $orphan->ID, $posts, true)) { $posts[] = (int) $orphan->ID; } }

    [$withdraw] = $make(['amount'=>'75']); $withdraw_id = (int) $withdraw['id']; $withdraw_row = wuai_sluyu_bounty_row($withdraw_id);
    bounty_assert_balance($owner, 825, 'Additional held reservation visible.');
    wp_set_current_user($owner); bounty_check(!is_wp_error(wuai_sluyu_bounty_withdraw($withdraw_id, (string) $withdraw_row['version'])), 'Dedicated withdrawal returns reservation.');
    bounty_check(!is_wp_error(wuai_sluyu_bounty_withdraw($withdraw_id, (string) $withdraw_row['version'])), 'Withdrawal replay cannot double return.');
    bounty_assert_balance($owner, 900, 'Withdrawn amount restored once.');
    [$expiry] = $make(['amount'=>'25']); $expiry_id = (int) $expiry['id'];
    $wpdb->update(wuai_sluyu_table('bounties'), ['deadline'=>time()-1], ['question_id'=>$expiry_id]);
    bounty_check(wuai_sluyu_bounty_reconcile($expiry_id)['state'] === 'refunded', 'Pending review also expires and returns reservation.');
    bounty_assert_balance($owner, 900, 'Expiry returns exact reservation.');

    [$edited] = $make(['amount'=>'35']); $edited_id = (int) $edited['id']; $open($edited_id);
    wp_set_current_user($admin); $_POST = []; wp_update_post(['ID'=>$edited_id, 'post_content'=>'Changed public terms must close unawarded funds.']);
    bounty_check(wuai_sluyu_bounty_row($edited_id)['state'] === 'refunded', 'Native public-term edit atomically returns unawarded reservation first.');
    bounty_assert_balance($owner, 900, 'Term edit has exact return.');
    [$slashes] = $make(['amount'=>'5','body'=>'The public path C:\\Games\\Title stays literal; review does not rewrite it.']); bounty_check(!is_wp_error($slashes), 'Create a question with literal backslashes.'); $slashes_id = (int) $slashes['id'];
    $slashes_row = $open($slashes_id); bounty_check($slashes_row['state'] === 'open', 'Native no-op publication does not double-unslash and falsely refund immutable terms.');
    wp_set_current_user($owner); bounty_check(!is_wp_error(wuai_sluyu_bounty_withdraw($slashes_id, (string) $slashes_row['version'])), 'Return literal-path fixture.'); bounty_assert_balance($owner, 900, 'Literal-path lifecycle has exact return.');
    [$reject] = $make(['amount'=>'15']); $reject_id = (int) $reject['id'];
    wp_set_current_user($admin); bounty_check(wuai_sluyu_bounty_review($reject_id, 'reject', '1') === true, 'Review rejection returns held funds.');
    bounty_assert_balance($owner, 900, 'Review rejection has exact return.');
    [$refund_fail] = $make(['amount'=>'10']); $refund_id = (int) $refund_fail['id'];
    $block_refund = function ($query) { return strpos($query, 'UPDATE ' . wuai_sluyu_table('bounties') . " SET state='refunded'") === 0 ? 'SELECT 0' : $query; };
    wp_set_current_user($admin); add_filter('query', $block_refund); $callbacks[] = ['query',$block_refund,10];
    bounty_check(is_wp_error(wuai_sluyu_bounty_review($refund_id, 'reject', '1')), 'Failed refund CAS cannot claim funds were returned.');
    bounty_assert_balance($owner, 890, 'Failed refund remains conservatively reserved.');
    remove_filter('query', $block_refund); $callbacks = [];
    bounty_check(wuai_sluyu_bounty_review($refund_id, 'reject', '1') === true, 'A later retry performs the one authoritative refund.');
    bounty_assert_balance($owner, 900, 'Refund retry returns exact allocation once.');

    $guard = wuai_sluyu_economy_enter('qa_fencing'); bounty_check(!is_wp_error($guard), 'Acquire durable mutex.');
    $shape = ['question_id'=>$edited_id, 'requester_id'=>$other, 'request_key'=>wp_generate_uuid4(), 'request_hash'=>str_repeat('a',64), 'amount'=>1, 'audience'=>'everyone', 'targets'=>'[]', 'deadline'=>time()+2*DAY_IN_SECONDS, 'prompt_hash'=>str_repeat('b',64), 'state'=>'held', 'version'=>1, 'reserved_at'=>current_time('mysql')];
    bounty_check(is_wp_error(wuai_sluyu_economy_allocation_insert($shape, $owner, 1, $guard['token'])), 'Internal reservation helper binds requester to the checked account.');
    $shape['requester_id'] = $owner; $shape['amount'] = 2;
    bounty_check(is_wp_error(wuai_sluyu_economy_allocation_insert($shape, $owner, 1, $guard['token'])), 'Internal reservation helper binds amount to the checked reserve.');
    $nested = wuai_sluyu_economy_enter('qa_nested'); bounty_check(!is_wp_error($nested) && !$nested['owner'] && $nested['token'] === $guard['token'], 'Internal calls borrow same token.');
    bounty_check(wuai_sluyu_economy_leave($nested), 'Borrowed leave does not release owner.');
    bounty_check(wuai_sluyu_economy_guard_valid($guard['token']), 'Owner mutex remains held.');
    $replacement = str_repeat('a', 64); if ($replacement === $guard['token']) { $replacement = str_repeat('b', 64); }
    $wpdb->update(wuai_sluyu_table('economy_mutex'), ['token'=>$replacement], ['name'=>'global','token'=>$guard['token']]);
    $fenced = wuai_sluyu_economy_event($owner, $prefix . ':fenced', 'qa_spend', -1, 0, 'Stale worker must not write.');
    bounty_check(is_wp_error($fenced), 'Stale resumed worker cannot write after manual token replacement.');
    bounty_check(!wuai_sluyu_economy_leave($guard), 'Stale holder cannot delete a new lock.');
    $wpdb->delete(wuai_sluyu_table('economy_mutex'), ['name'=>'global','token'=>$replacement]);
    bounty_assert_balance($owner, 900, 'Fencing failure cannot charge points.');
    $circle = wp_insert_term($prefix . '_funded_circle', 'sluyu_circle'); bounty_check(!is_wp_error($circle), 'Create dedicated funded circle.'); $circle = (int) $circle['term_id']; $terms[] = $circle;
    update_term_meta($circle, '_wuai_circle_owner', $owner); update_term_meta($circle, '_wuai_circle_status', 'publish'); update_term_meta($circle, '_wuai_circle_posting', 'members');
    $wpdb->insert(wuai_sluyu_table('memberships'), ['circle_id'=>$circle,'user_id'=>$winner,'status'=>'active','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
    [$circle_submission] = $make(['amount'=>'20','group'=>(string) $circle]); bounty_check(!is_wp_error($circle_submission), 'Reserve circle question.'); $circle_id = (int) $circle_submission['id']; $circle_row = $open($circle_id); $circle_answer = $answer($circle_id, $winner);
    $block_at_cas = function ($query) use ($circle, $winner, $wpdb) {
        if (strpos($query, 'UPDATE ' . wuai_sluyu_table('bounties') . " SET state='awarded'") === 0) { $wpdb->update(wuai_sluyu_table('memberships'), ['status'=>'blocked'], ['circle_id'=>$circle,'user_id'=>$winner]); }
        return $query;
    };
    wp_set_current_user($owner); add_filter('query', $block_at_cas); $callbacks[] = ['query',$block_at_cas,10];
    bounty_check(is_wp_error(wuai_sluyu_bounty_accept($circle_answer, (string) $circle_row['version'])), 'Circle kick after PHP eligibility is rejected by raw SQL snapshot predicates.');
    remove_filter('query', $block_at_cas); $callbacks = [];
    bounty_check(!is_wp_error(wuai_sluyu_bounty_withdraw($circle_id, (string) $circle_row['version'])), 'Return rejected late-circle-change reservation.');
    bounty_assert_balance($owner, 900, 'Late circle eligibility rejection has no hidden extra debit.');
    $export = wuai_sluyu_bounty_export(get_userdata($winner)->user_email); bounty_check(count($export['data']) === 2, 'Winner allocation export covers both awards.');
    bounty_check(wuai_sluyu_bounty_erase(get_userdata($winner)->user_email)['items_retained'], 'Privacy retention explicitly protects counterparty balances.');
    $doomed_owner = $users['doomed_owner']; $doomed_winner = $users['doomed_winner'];
    bounty_check(!is_wp_error(wuai_sluyu_economy_event($doomed_owner, $prefix . ':deletion_seed', 'qa', 100, 0, 'Deletion identity fixture.')), 'Seed disposable deletion requester.');
    wp_set_current_user($doomed_owner);
    $deletion = wuai_sluyu_bounty_submit(['amount'=>'50','days'=>'2','audience'=>'someone','targets'=>(string) $doomed_winner,'request_key'=>wp_generate_uuid4(),'title'=>$prefix . ' deleted requester','body'=>'The counterparty allocation must survive native account deletion.','group'=>'0','confirm'=>'1']);
    bounty_check(!is_wp_error($deletion), 'Create deletion fixture reservation.'); $deletion_id = (int) $deletion['id']; $posts[] = $deletion_id;
    $deletion_row = $open($deletion_id); $deletion_comment = $answer($deletion_id, $doomed_winner);
    wp_set_current_user($doomed_owner); bounty_check(!is_wp_error(wuai_sluyu_bounty_accept($deletion_comment, (string) $deletion_row['version'])), 'Award deletion fixture.');
    wp_set_current_user($admin); require_once ABSPATH . 'wp-admin/includes/user.php';
    bounty_check(wp_delete_user($doomed_owner, $admin), 'Native requester delete/reassignment completes under integrated economy guard.');
    bounty_assert_balance($doomed_winner, 50, 'Requester deletion cannot erase recipient credit.');
    bounty_check(wuai_sluyu_bounty_row($deletion_id)['requester_id'] === $doomed_owner, 'Native author reassignment cannot transfer financial ownership.');
    bounty_check(wp_delete_user($doomed_winner), 'Native winner deletion completes under integrated economy guard.');
    bounty_check(wuai_sluyu_bounty_row($deletion_id)['state'] === 'awarded' && wuai_sluyu_bounty_row($deletion_id)['winner_id'] === $doomed_winner, 'Deleting recipient retains immutable allocated identity, never refunds requester.');
    echo "PASS: pending reservations, exact single-CAS awards/returns, unique replay, no half-credit, target/date/role/approval gates, SQL fencing, derived ledgers, native edits/deletes and privacy retention.\n";
} finally {
    foreach ($callbacks as $callback) { remove_filter($callback[0], $callback[1], $callback[2]); }
    $_POST = []; wp_set_current_user($users['admin'] ?? 0);
    $active = $GLOBALS['wuai_sluyu_economy_guard'] ?? null; if ($active) { wuai_sluyu_economy_leave($active); }
    foreach ($comments as $id) { wp_delete_comment($id, true); }
    foreach ($posts as $id) { wp_delete_post($id, true); }
    foreach ($terms as $id) { wp_delete_term($id, 'sluyu_circle'); }
    foreach ($users as $id) { $wpdb->delete(wuai_sluyu_table('bounties'), ['requester_id'=>$id]); }
    require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ($users as $id) { delete_transient('wuai_bounty_submit_' . $id); wp_delete_user($id); }
    update_option('wuai_sluyu_bounty_enabled', $old_enabled); remove_filter('pre_wp_mail', $mail, PHP_INT_MAX); $_POST = $old_post; $_GET = $old_get; wp_set_current_user($old_user);
}
