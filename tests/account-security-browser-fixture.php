<?php
// CLI-only fixture; the tightly scoped mail/read-only helper never ships.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('WP_QA_ROOT required.'); }
$expected_root = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
if (!$expected_root || strcasecmp((string) realpath($root), $expected_root) !== 0) { throw new RuntimeException('Only the explicit disposable checks-v1.1.0/site fixture is allowed.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_account_security_request')) { throw new RuntimeException('Integrated local account-security fixture required.'); }
function security_browser_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$resolved = realpath($root); security_browser_check($resolved && strcasecmp($resolved, (string) realpath(ABSPATH)) === 0, 'Resolved fixture installation must match WordPress.');
$output = getenv('WP_QA_OUTPUT') ?: dirname($root); if (!is_dir($output)) { wp_mkdir_p($output); }
$mu_dir = WP_CONTENT_DIR . '/mu-plugins'; if (!is_dir($mu_dir)) { wp_mkdir_p($mu_dir); }
$mu_file = $mu_dir . '/wuai-security-browser-qa.php'; $marker = 'WUAI_ACCOUNT_SECURITY_BROWSER_QA_V1';
if (is_file($mu_file) && strpos(file_get_contents($mu_file), $marker) === false) { throw new RuntimeException('Refusing to replace an unrelated MU plugin.'); }
$plugin = <<<'PHP'
<?php
// WUAI_ACCOUNT_SECURITY_BROWSER_QA_V1: isolated read-only test helper; never ship.
if(!defined('ABSPATH') || !defined('WUAI_SECURITY_QA_ROOT'))return;
if(strcasecmp((string)realpath(ABSPATH),WUAI_SECURITY_QA_ROOT)!==0 || home_url()!=='http://127.0.0.1:8771')return;
if(PHP_SAPI!=='cli' && (!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true) || ($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:8771'))return;
add_filter('pre_wp_mail',function($return,$attributes){
    foreach(['owner','other','banned']as$name){$email='qa_security_browser_'.$name.'@example.test';$recipients=is_array($attributes['to'])?$attributes['to']:explode(',',(string)$attributes['to']);if(in_array($email,array_map('trim',$recipients),true)){update_option('wuai_security_qa_mail_'.$name,(int)get_option('wuai_security_qa_mail_'.$name,0)+1,false);}}
    return true;
},PHP_INT_MAX,2);
add_action('init',function(){if(!headers_sent())header('X-Wuai-QA-Security-Mail-Blocked: 1');});
add_action('wp_footer',function(){if(is_user_logged_in() && preg_match('/^qa_security_browser_(owner|other|banned)$/D',wp_get_current_user()->user_login))echo '<input type="hidden" id="wuai-security-qa-nonce" value="'.esc_attr(wp_create_nonce('wuai_security_qa_state')).'">';});
add_action('wp_ajax_wuai_security_qa_state',function(){
    check_ajax_referer('wuai_security_qa_state');$user=wp_get_current_user();
    if(!preg_match('/^qa_security_browser_(owner|other|banned)$/D',$user->user_login,$match))wp_send_json_error(['message'=>'Fixture identity only'],403);
    $fixture=get_option('wuai_security_browser_fixture',[]);if(!isset($fixture['users'][$match[1]]) || (int)$fixture['users'][$match[1]]!==(int)$user->ID)wp_send_json_error(['message'=>'Fixture identity mismatch'],409);
    $sessions=wuai_sluyu_security_sessions_raw($user->ID);if(is_wp_error($sessions))wp_send_json_error(['message'=>'Session read failed'],409);
    wp_send_json_success(['user'=>(int)$user->ID,'sessions'=>count($sessions),'current_valid'=>WP_Session_Tokens::get_instance($user->ID)->verify(wp_get_session_token()),'mail_attempts'=>(int)get_option('wuai_security_qa_mail_'.$match[1],0)]);
});
PHP;
$plugin = str_replace("if(!defined('ABSPATH') || !defined('WUAI_SECURITY_QA_ROOT'))return;", "if(!defined('ABSPATH'))return;\ndefine('WUAI_SECURITY_QA_ROOT'," . var_export($resolved, true) . ');', $plugin);
security_browser_check(file_put_contents($mu_file, $plugin) !== false, 'Write local mail/read-only QA helper.');
$mail_block = function () { return true; }; add_filter('pre_wp_mail', $mail_block, PHP_INT_MAX);
$old_uid = get_current_user_id(); $users = [];
try {
    wp_set_current_user(0);
    foreach (['owner','other','banned'] as $name) {
        $login = 'qa_security_browser_' . $name; $existing = get_user_by('login', $login);
        if ($existing && get_user_meta($existing->ID, '_wuai_security_browser_fixture', true) !== 'v1') { throw new RuntimeException('Refusing to reset an account without this fixture marker.'); }
        $uid = $existing ? $existing->ID : wp_create_user($login, 'qa-security-old-password', $login . '@example.test');
        security_browser_check(!is_wp_error($uid) && $uid, 'Create dedicated browser user.'); $uid = (int) $uid; $users[$name] = $uid;
        update_user_meta($uid, '_wuai_security_browser_fixture', 'v1');
        (new WP_User($uid))->set_role('subscriber');
        security_browser_check(!is_wp_error(wp_update_user(['ID'=>$uid, 'display_name'=>'Security Browser ' . ucfirst($name), 'user_pass'=>'qa-security-old-password'])), 'Reset dedicated account password.');
        WP_Session_Tokens::get_instance($uid)->destroy_all(); delete_user_meta($uid, '_wuai_security_attempts'); delete_user_meta($uid, '_wuai_banned_until');
        if ($name === 'banned') { update_user_meta($uid, '_wuai_banned_until', -1); }
        update_option('wuai_security_qa_mail_' . $name, 0, false);
    }
    update_option('wuai_security_browser_fixture', ['users'=>$users], false);
    $manifest = ['base'=>home_url(), 'users'=>$users, 'login_prefix'=>'qa_security_browser_', 'security_url'=>wuai_sluyu_portal_url('account', ['tab'=>'security']), 'mu_plugin'=>$mu_file];
    security_browser_check(file_put_contents(rtrim($output, '/\\') . '/account-security-browser-fixture.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false, 'Write security browser manifest.');
    echo "PASS: dedicated owner/other/banned security accounts prepared; mail blocked and read-only own-session helper installed. Run account-security.cjs.\n";
} finally { remove_filter('pre_wp_mail', $mail_block, PHP_INT_MAX); wp_set_current_user($old_uid); }
