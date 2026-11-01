<?php
// Dedicated local browser fixture; no email or public QA mutation endpoint.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); $expected = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
if (!$root || !$expected || strcasecmp((string) realpath($root), $expected) !== 0) { throw new RuntimeException('Dedicated WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REQUEST_URI'] = '/'; require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_bounty_submit') || !wuai_sluyu_economy_contract_ready()) { throw new RuntimeException('Integrate the local bounty/economy contract first.'); }
function bounty_browser_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$mu_dir = WP_CONTENT_DIR . '/mu-plugins'; if (!is_dir($mu_dir)) { wp_mkdir_p($mu_dir); }
$mu_file = $mu_dir . '/wuai-bounty-browser-qa.php'; $marker = 'WUAI_BOUNTY_BROWSER_QA_V1';
if (is_file($mu_file) && strpos(file_get_contents($mu_file), $marker) === false) { throw new RuntimeException('Refuse to overwrite an unrelated MU plugin.'); }
$plugin = <<<'PHP'
<?php
// WUAI_BOUNTY_BROWSER_QA_V1: disposable local QA only; never distribute.
if (!defined('ABSPATH') || !defined('WUAI_BOUNTY_QA_ROOT')) { return; }
if (strcasecmp((string) realpath(ABSPATH), WUAI_BOUNTY_QA_ROOT) !== 0 || home_url() !== 'http://127.0.0.1:8771') { return; }
if (PHP_SAPI !== 'cli' && (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true) || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8771')) { return; }
add_filter('pre_wp_mail', function () { return true; }, PHP_INT_MAX);
add_action('login_init', function () { if (!headers_sent()) { header('X-Wuai-Bounty-QA-Mail-Blocked: 1'); } });
add_action('admin_notices', function () { if (current_user_can('manage_options')) { echo '<input type="hidden" id="wuai-bounty-qa-nonce" value="' . esc_attr(wp_create_nonce('wuai_bounty_qa')) . '">'; } });
// Read only: raw state, both balances and negative event counts verify the real UI.
add_action('wp_ajax_wuai_bounty_qa_state', function () {
    if (!current_user_can('manage_options')) { wp_send_json_error(['message'=>'Admin only.'], 403); }
    check_ajax_referer('wuai_bounty_qa');
    $users = []; $ids = [];
    foreach (['owner','writer','winner','other','admin'] as $name) { $user = get_user_by('login', 'qa_bounty_browser_' . $name); if ($user) { $ids[] = (int) $user->ID; $balance = wuai_sluyu_economy_balance($user->ID); $users[$name] = ['id'=>(int) $user->ID,'balance'=>is_wp_error($balance) ? null : $balance]; } }
    if (!$ids) { wp_send_json_error(['message'=>'Fixture missing.'], 404); }
    global $wpdb; $in = implode(',', $ids);
    $rows = $wpdb->get_results('SELECT id,question_id,requester_id,amount,audience,targets,deadline,state,version,winner_id,accepted_comment,reason FROM ' . wuai_sluyu_table('bounties') . " WHERE requester_id IN ($in) ORDER BY id", ARRAY_A);
    $posts = $wpdb->get_results("SELECT ID,post_title,post_status,post_author FROM $wpdb->posts WHERE post_type='sluyu_topic' AND post_author IN ($in)", ARRAY_A);
    $events = $wpdb->get_results('SELECT id,user_id,event_type,event_key,target_id,points FROM ' . wuai_sluyu_table('events') . " WHERE user_id IN ($in) AND points<>0 ORDER BY id", ARRAY_A);
    $comments = $wpdb->get_results("SELECT comment_ID,comment_post_ID,user_id,comment_approved FROM $wpdb->comments WHERE comment_post_ID IN (SELECT question_id FROM " . wuai_sluyu_table('bounties') . " WHERE requester_id IN ($in)) ORDER BY comment_ID", ARRAY_A);
    wp_send_json_success(['users'=>$users,'bounties'=>$rows,'posts'=>$posts,'events'=>$events,'comments'=>$comments]);
});
PHP;
$plugin = str_replace("if (!defined('ABSPATH') || !defined('WUAI_BOUNTY_QA_ROOT')) { return; }", "if (!defined('ABSPATH')) { return; }\ndefine('WUAI_BOUNTY_QA_ROOT', " . var_export($expected, true) . ');', $plugin);
bounty_browser_check(file_put_contents($mu_file, $plugin) !== false, 'Write guarded disposable MU plugin.');
$mail = function () { return true; }; add_filter('pre_wp_mail', $mail, PHP_INT_MAX);
$old_user = get_current_user_id(); $old_post = $_POST; $users = []; $posts = [];
$prefix = 'QA Bounty Browser';
try {
    $_POST = [];
    foreach (['owner','writer','winner','other','admin'] as $name) {
        $login = 'qa_bounty_browser_' . $name; $user = get_user_by('login', $login); $uid = $user ? $user->ID : wp_create_user($login, 'qa-local-only', $login . '@example.test');
        bounty_browser_check(!is_wp_error($uid) && $uid, 'Create dedicated browser identity.'); $uid = (int) $uid; $users[$name] = $uid;
        (new WP_User($uid))->set_role($name === 'admin' ? 'administrator' : 'subscriber');
        wp_update_user(['ID'=>$uid,'display_name'=>'Bounty Browser ' . ucfirst($name),'user_pass'=>'qa-local-only']); delete_user_meta($uid, '_wuai_banned_until'); delete_transient('wuai_bounty_submit_' . $uid);
        $owned = get_posts(['post_type'=>'sluyu_topic','post_status'=>['publish','pending','draft','private','future','trash'],'author'=>$uid,'numberposts'=>-1]);
        foreach ($owned as $post) { if (strpos($post->post_title, $prefix . ' ') === 0) { wp_delete_post($post->ID, true); } }
        // A dedicated browser account can appear as a bounty winner even when
        // another fixture account owns the question. Remove every allocation
        // involving this QA identity so derived balances start at the seed.
        $wpdb->query($wpdb->prepare('DELETE FROM ' . wuai_sluyu_table('bounties') . ' WHERE requester_id=%d OR winner_id=%d', $uid, $uid));
        $wpdb->delete(wuai_sluyu_table('events'), ['user_id'=>$uid]);
        bounty_browser_check(!is_wp_error(wuai_sluyu_economy_event($uid, 'qa-bounty-browser-seed', 'qa', 300, 0, 'Bounty browser fixture points.')), 'Seed exact balance.');
    }
    update_option('wuai_sluyu_bounty_enabled', '1');
    foreach (['withdraw','expired'] as $key) {
        wp_set_current_user($users['owner']); delete_transient('wuai_bounty_submit_' . $users['owner']);
        $result = wuai_sluyu_bounty_submit(['amount'=>'30','days'=>'2','audience'=>'everyone','targets'=>'','request_key'=>wp_generate_uuid4(),'title'=>$prefix . ' ' . ucfirst($key),'body'=>'Dedicated local question for return and deadline testing.','group'=>'0','confirm'=>'1']);
        bounty_browser_check(!is_wp_error($result), 'Create ' . $key . ' fixture.'); $posts[$key] = (int) $result['id'];
        wp_set_current_user($users['admin']); wp_update_post(['ID'=>$posts[$key], 'post_status'=>'publish']);
        bounty_browser_check(wuai_sluyu_bounty_review($posts[$key], 'approve', '1') === true, 'Open fixture through real module review helper.');
        if ($key === 'expired') { $wpdb->update(wuai_sluyu_table('bounties'), ['deadline'=>time()-1], ['question_id'=>$posts[$key]]); }
    }
    delete_transient('wuai_bounty_submit_' . $users['writer']);
    $output = getenv('WP_QA_OUTPUT') ?: dirname($expected); if (!is_dir($output)) { wp_mkdir_p($output); }
    $manifest = ['base'=>home_url(),'users'=>$users,'posts'=>$posts,'loginPrefix'=>'qa_bounty_browser_','initialPoints'=>300,'amount'=>80,'title'=>$prefix . ' Submitted ' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),'prompt'=>'A public browser bounty question. The selected responder may receive the reserved points only after review and explicit owner acceptance.'];
    bounty_browser_check(file_put_contents(rtrim($output, '/\\') . '/bounties-fixture.json', wp_json_encode($manifest, JSON_PRETTY_PRINT)) !== false, 'Write bounty browser manifest.');
    echo "PASS: dedicated bounty browser accounts, exact point balances, return/expiry cases and guarded read-only state; no email sent.\n";
} finally { remove_filter('pre_wp_mail', $mail, PHP_INT_MAX); $_POST = $old_post; wp_set_current_user($old_user); }
