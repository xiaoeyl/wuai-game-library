<?php
// Child process for economy-race.cjs. This endpoint is CLI-only and fixture-bound.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); $expected = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
if (!$root || !$expected || strcasecmp((string) realpath($root), $expected) !== 0) { throw new RuntimeException('Dedicated WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REQUEST_URI'] = '/'; require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_economy_enter') || !wuai_sluyu_economy_contract_ready()) { throw new RuntimeException('Integrated local economy contract required.'); }
$output = getenv('WP_QA_OUTPUT') ?: dirname($expected);
$manifest = $output . '/economy-race-fixture.json'; $mode = $argv[1] ?? '';
function economy_worker_write($value) { echo wp_json_encode($value) . "\n"; flush(); }
function economy_worker_command() { return trim((string) fgets(STDIN)); }
add_filter('pre_wp_mail', function () { return true; }, PHP_INT_MAX);
if ($mode === 'prepare') {
    if (is_file($manifest)) { throw new RuntimeException('Remove a previous fixture through the cleanup mode before starting a new run.'); }
    $login = 'qa_economy_race_' . strtolower(wp_generate_password(9, false, false));
    $uid = wp_create_user($login, 'qa-local-only', $login . '@example.test'); if (is_wp_error($uid)) { throw new RuntimeException('Create race account.'); }
    (new WP_User($uid))->set_role('subscriber');
    $seed = wuai_sluyu_economy_event((int) $uid, $login . ':seed', 'qa', 100, 0, 'Race fixture seed.'); if (is_wp_error($seed)) { throw new RuntimeException($seed->get_error_message()); }
    $data = ['uid'=>(int) $uid, 'login'=>$login, 'base'=>home_url(), 'old_enabled'=>get_option('wuai_sluyu_bounty_enabled', '0')];
    update_option('wuai_sluyu_bounty_enabled', '1');
    if (file_put_contents($manifest, wp_json_encode($data, JSON_PRETTY_PRINT)) === false) { throw new RuntimeException('Write race manifest.'); }
    economy_worker_write(['prepared'=>true, 'uid'=>(int) $uid]); exit;
}
if (!is_file($manifest)) { throw new RuntimeException('Prepare the race fixture first.'); }
$fixture = json_decode(file_get_contents($manifest), true); $user = get_userdata($fixture['uid'] ?? 0);
if (!$user || $user->user_login !== ($fixture['login'] ?? '') || strpos($user->user_login, 'qa_economy_race_') !== 0 || ($fixture['base'] ?? '') !== home_url()) { throw new RuntimeException('Dedicated race identity mismatch.'); }
$uid = (int) $user->ID; wp_set_current_user($uid);
if ($mode === 'holder') {
    $guard = wuai_sluyu_economy_enter('qa_race_holder'); if (is_wp_error($guard)) { economy_worker_write(['error'=>$guard->get_error_code()]); exit(2); }
    economy_worker_write(['ready'=>true]);
    try {
        if (economy_worker_command() !== 'charge') { throw new RuntimeException('Expected parent charge command.'); }
        $charge = wuai_sluyu_economy_event($uid, $user->user_login . ':charge', 'qa_spend', -80, 0, 'Concurrent spend wins the mutex.');
        economy_worker_write(is_wp_error($charge) ? ['error'=>$charge->get_error_code()] : ['charged'=>true]);
    } finally { wuai_sluyu_economy_leave($guard); }
    exit;
}
if ($mode === 'borrower') {
    $first = wuai_sluyu_bounty_submit(['amount'=>'80','days'=>'2','audience'=>'everyone','targets'=>'','request_key'=>wp_generate_uuid4(),'title'=>'QA Race Bounty','body'=>'This isolated reservation must not race past another spend.','group'=>'0','confirm'=>'1']);
    economy_worker_write(['first_error'=>is_wp_error($first) ? $first->get_error_code() : '', 'created'=>!is_wp_error($first)]);
    if (economy_worker_command() !== 'retry') { throw new RuntimeException('Expected parent retry command.'); }
    $second = wuai_sluyu_bounty_submit(['amount'=>'80','days'=>'2','audience'=>'everyone','targets'=>'','request_key'=>wp_generate_uuid4(),'title'=>'QA Race Bounty Retry','body'=>'The winner spent eighty; only twenty points remain.','group'=>'0','confirm'=>'1']);
    economy_worker_write(['second_error'=>is_wp_error($second) ? $second->get_error_code() : '', 'balance'=>wuai_sluyu_economy_balance($uid)]); exit;
}
if ($mode === 'crash_holder') {
    $guard = wuai_sluyu_economy_enter('qa_race_crash');
    economy_worker_write(is_wp_error($guard) ? ['error'=>$guard->get_error_code()] : ['ready'=>true]);
    if (is_wp_error($guard)) { exit(2); }
    // Intentionally no finally/shutdown release: the test parent kills this process.
    economy_worker_command(); exit(3);
}
if ($mode === 'probe') {
    $result = wuai_sluyu_economy_event($uid, $user->user_login . ':after_crash', 'qa_spend', -1, 0, 'Must remain blocked after crashed holder.');
    economy_worker_write(['error'=>is_wp_error($result) ? $result->get_error_code() : '', 'balance'=>wuai_sluyu_economy_balance($uid)]); exit;
}
if ($mode === 'state') {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT points FROM ' . wuai_sluyu_table('events') . ' WHERE user_id=%d ORDER BY id', $uid), ARRAY_A);
    $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . wuai_sluyu_table('bounties') . ' WHERE requester_id=%d', $uid));
    economy_worker_write(['balance'=>wuai_sluyu_economy_balance($uid), 'events'=>$rows, 'bounties'=>(int) $count]); exit;
}
if ($mode === 'cleanup') {
    // Only invoked after the parent has positively observed every worker exit.
    // This is NOT an application/admin recovery endpoint.
    global $wpdb;
    $lock = $wpdb->get_row('SELECT * FROM ' . wuai_sluyu_table('economy_mutex') . " WHERE name='global'", ARRAY_A);
    if ($lock) {
        if (($argv[2] ?? '') !== 'all-workers-stopped' || (int) $lock['actor_id'] !== $uid || !in_array($lock['purpose'], ['qa_race_holder','qa_race_crash'], true)) { throw new RuntimeException('Refuse to remove a non-fixture or potentially active lock.'); }
        $wpdb->delete(wuai_sluyu_table('economy_mutex'), ['name'=>'global','token'=>$lock['token'],'actor_id'=>$uid]);
    }
    $posts = get_posts(['post_type'=>'sluyu_topic','post_status'=>['publish','pending','draft','private','trash'],'author'=>$uid,'numberposts'=>-1]);
    foreach ($posts as $post) { wp_delete_post($post->ID, true); }
    $wpdb->delete(wuai_sluyu_table('bounties'), ['requester_id'=>$uid]);
    require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($uid); update_option('wuai_sluyu_bounty_enabled', $fixture['old_enabled']);
    unlink($manifest); economy_worker_write(['cleaned'=>true]); exit;
}
throw new RuntimeException('Unknown CLI mode.');
