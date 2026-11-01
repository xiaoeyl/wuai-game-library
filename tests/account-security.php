<?php
// CLI-only integration tests. Real signed WordPress cookies are still required.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('WP_QA_ROOT required.'); }
$expected_root = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
if (!$expected_root || strcasecmp((string) realpath($root), $expected_root) !== 0) { throw new RuntimeException('Only the explicit disposable checks-v1.1.0/site fixture is allowed.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'POST';
require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_account_security_request')) { throw new RuntimeException('Integrated local account-security fixture required.'); }
function sec_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function sec_error($result, $code, $message) { sec_check(is_wp_error($result) && $result->get_error_code() === 'security_' . $code, $message); }
function sec_user($uid, $password) {
    wp_set_current_user(0); WP_Session_Tokens::get_instance($uid)->destroy_all();
    wp_set_password($password, $uid); delete_user_meta($uid, '_wuai_security_attempts');
    clean_user_cache($uid); wp_cache_delete($uid, 'user_meta');
}
function sec_cookie($uid, $token = '') {
    wp_set_current_user($uid); $expires = time() + DAY_IN_SECONDS;
    $token = $token ?: WP_Session_Tokens::get_instance($uid)->create($expires);
    $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($uid, $expires, 'logged_in', $token);
    return $token;
}
function sec_input($operation, $current, $new = '') {
    return wp_slash(['operation'=>$operation, '_wuai_security_nonce'=>wp_create_nonce('wuai_account_security:' . get_current_user_id() . ':' . $operation), 'current_password'=>$current, 'new_password'=>$new, 'repeat_password'=>$new, 'confirm'=>'1']);
}
function sec_call($operation, $current, $new = '') { return wuai_sluyu_account_security_request('POST', sec_input($operation, $current, $new)); }
$old_uid = get_current_user_id(); $original_cookies = $_COOKIE; $old_post = $_POST; $mail = []; $mail_decisions = []; $filters = [];
$mail_block = function ($return, $attributes) use (&$mail) { $mail[] = $attributes; return true; };
add_filter('pre_wp_mail', $mail_block, PHP_INT_MAX, 2);
$mail_observe = function ($pre) use (&$mail_decisions) { $mail_decisions[] = $pre; return $pre; };
$mail_watch = function ($email) use ($mail_observe) { remove_filter('pre_wp_mail', $mail_observe, PHP_INT_MAX); add_filter('pre_wp_mail', $mail_observe, PHP_INT_MAX); return $email; };
add_filter('password_change_email', $mail_watch, 1);
$password = 'QA-old "slash\\ inside"-password'; $new = 'QA-new "slash\\ preserved"-password'; $users = []; $login_prefix = 'qa_sec_' . substr(bin2hex(random_bytes(8)), 0, 12) . '_';
try {
    foreach (['owner','other'] as $name) {
        $login = $login_prefix . $name; sec_check(!get_user_by('login', $login), 'Never overwrite an existing account.');
        $uid = wp_create_user($login, $password, $login . '@example.test');
        sec_check(!is_wp_error($uid) && $uid, 'Create dedicated security fixture user.'); $uid = (int) $uid; $users[$name] = $uid;
        (new WP_User($uid))->set_role('subscriber'); delete_user_meta($uid, '_wuai_banned_until'); sec_user($uid, $password);
    }
    $owner = $users['owner']; $other = $users['other']; $mail = [];
    sec_cookie($owner); $before = wuai_sluyu_security_user($owner)->user_pass;
    sec_error(wuai_sluyu_account_security_request('GET', sec_input('revoke_others', $password)), 'method', 'GET cannot mutate sessions.');
    $input = sec_input('revoke_others', $password); $input['current_password'] = ['nested'];
    sec_error(wuai_sluyu_account_security_request('POST', $input), 'input', 'Nested password input is rejected.');
    $input = sec_input('change_password', $password, $new); $input['repeat_password'] = ['nested'];
    sec_error(wuai_sluyu_account_security_request('POST', $input), 'input', 'Nested confirmation is rejected.');
    $input = sec_input('revoke_others', $password); $input['_wuai_security_nonce'] = 'not-a-nonce';
    sec_error(wuai_sluyu_account_security_request('POST', $input), 'nonce', 'Nonce required for security mutation.');
    $input = sec_input('change_password', $password, $new); $input['operation'] = 'revoke_others';
    sec_error(wuai_sluyu_account_security_request('POST', $input), 'nonce', 'Nonce is operation-bound.');
    sec_error(sec_call('revoke_others', 'wrong-current-password'), 'password', 'Incorrect current password does not revoke sessions.');
    sec_check(hash_equals($before, wuai_sluyu_security_user($owner)->user_pass), 'Invalid requests preserve the stored password.');
    foreach ([str_repeat('a', 11), str_repeat('b', 129), ' ' . $new, $new . ' ', $new . "\n", 'abcdefghijkl' . "\0" . 'mnop'] as $invalid) {
        sec_error(sec_call('change_password', $password, $invalid), 'new_password', 'Invalid byte length, boundary whitespace or control bytes rejected without trimming.');
    }
    $input = sec_input('change_password', $password, $new); $input['repeat_password'] .= 'x';
    sec_error(wuai_sluyu_account_security_request('POST', $input), 'confirmation', 'Password confirmation must match exactly.');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
    sec_error(sec_call('revoke_others', $password), 'identity', 'Nonlocal cleartext connections cannot perform security changes.'); $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    sec_error(sec_call('change_password', $password, $password), 'reuse', 'Existing password cannot be reused.');

    sec_user($owner, $password); $current = sec_cookie($owner); $old = $_COOKIE[LOGGED_IN_COOKIE];
    WP_Session_Tokens::get_instance($owner)->destroy($current); wp_cache_delete($owner, 'user_meta');
    sec_error(sec_call('revoke_others', $password), 'session', 'A revoked cookie is rejected even when current user was already populated.');
    sec_cookie($owner); $stale_cookie = $_COOKIE[LOGGED_IN_COOKIE]; get_userdata($owner);
    global $wpdb;
    $wpdb->update($wpdb->users, ['user_pass'=>wp_hash_password('QA-native-reset-before-operation')], ['ID'=>$owner]);
    sec_error(sec_call('revoke_others', $password), 'session', 'Fresh identity reads reject a cookie signed for an older cached password.');

    sec_user($owner, $password); $first = sec_cookie($owner); $first_input = sec_input('revoke_others', $password);
    $second = sec_cookie($owner); sec_error(wuai_sluyu_account_security_request('POST', $first_input), 'nonce', 'A nonce from another session is rejected.');
    sec_cookie($owner, $first); $owner_cookie = $_COOKIE[LOGGED_IN_COOKIE];
    $other_token = sec_cookie($other); $other_cookie = $_COOKIE[LOGGED_IN_COOKIE];
    wp_set_current_user($owner); $_COOKIE[LOGGED_IN_COOKIE] = $other_cookie;
    sec_error(sec_call('revoke_others', $password), 'session', 'A foreign cookie cannot act as the populated current user.');
    $_COOKIE[LOGGED_IN_COOKIE] = $owner_cookie;
    update_user_meta($owner, '_wuai_banned_until', -1);
    $input = sec_input('revoke_others', $password); $input['user_id'] = (string) $other;
    $result = wuai_sluyu_account_security_request('POST', $input);
    sec_check(is_array($result) && $result['operation'] === 'revoke_others', 'Banned users can secure themselves; forged user_id is ignored.');
    sec_check(WP_Session_Tokens::get_instance($owner)->verify($first) && !WP_Session_Tokens::get_instance($owner)->verify($second), 'Other-session revocation preserves exactly this browser.');
    sec_check(WP_Session_Tokens::get_instance($other)->verify($other_token), 'Another account sessions remain untouched.');
    delete_user_meta($owner, '_wuai_banned_until');
    sec_check(!is_wp_error(sec_call('revoke_others', $password)), 'Repeating revoke on one remaining session is safe.');

    $sessions = wuai_sluyu_security_sessions_raw($owner); $raw_before = $sessions;
    $sessions[hash('sha256', $first)]['ua'] = '<img src=x onerror=alert(1)> fixture browser';
    for ($i = 0; $i < 54; $i++) { $sessions[hash('sha256', 'qa-extra-browser-' . $i)] = ['expiration'=>time() + HOUR_IN_SECONDS, 'login'=>time() - $i - 20, 'ua'=>'QA extra browser ' . $i]; }
    update_user_meta($owner, 'session_tokens', $sessions); wp_cache_delete($owner, 'user_meta');
    $page1 = wuai_sluyu_security_sessions(1); $page2 = wuai_sluyu_security_sessions(2);
    sec_check(count($page1['items']) === 50 && count($page2['items']) === 5 && $page1['total'] === 55 && $page1['items'][0]['current'], 'Session list paginates at 50 with the current browser first.');
    foreach ($page1['items'] as $item) { sec_check(array_keys($item) === ['current','login','expiration','agent'], 'Public session rows contain no token, verifier, IP or extra metadata.'); }
    ob_start(); wuai_sluyu_view_account_security(); $html = ob_get_clean();
    sec_check(strpos($html, '<img src=x') === false && strpos($html, '&lt;img src=x') !== false, 'User-controlled browser details are escaped.');
    sec_check(strpos($html, $first) === false && strpos($html, hash('sha256', $first)) === false && strpos($html, $password) === false, 'Session UI does not disclose cookies, verifiers or passwords.');
    sec_check(strpos($html, 'sluyu-community-form') === false && strpos($html, 'method="post"') !== false, 'Password operations use native POST rather than generic AJAX.');
    update_user_meta($owner, 'session_tokens', $raw_before); wp_cache_delete($owner, 'user_meta');

    delete_user_meta($owner, '_wuai_security_attempts');
    for ($i = 0; $i < 8; $i++) { sec_error(sec_call('revoke_others', 'wrong-current-password'), 'password', 'Bounded failed password checks.'); }
    sec_error(sec_call('revoke_others', $password), 'rate', 'Current password cannot bypass the rate limit.'); delete_user_meta($owner, '_wuai_security_attempts');
    $lock = wuai_sluyu_security_lock($owner); sec_check(is_array($lock), 'Acquire account security lock.');
    sec_error(sec_call('revoke_others', $password), 'busy', 'Parallel security changes are serialized.'); wuai_sluyu_security_unlock($lock);
    $external_lock = wuai_sluyu_lock('user_' . $owner); sec_check((bool) $external_lock, 'Acquire shared account lock.');
    sec_error(sec_call('revoke_others', $password), 'busy', 'Account deletion/profile operation lock is respected.'); wuai_sluyu_unlock($external_lock);

    $second = WP_Session_Tokens::get_instance($owner)->create(time() + DAY_IN_SECONDS);
    $deny_sessions = function ($check, $id, $key) use ($owner) { return (int) $id === $owner && $key === 'session_tokens' ? false : $check; };
    add_filter('update_user_metadata', $deny_sessions, PHP_INT_MAX, 3); $filters[] = ['update_user_metadata', $deny_sessions, PHP_INT_MAX];
    sec_error(sec_call('revoke_others', $password), 'revoke_unverified', 'Failed session persistence is not reported as success.');
    sec_check(WP_Session_Tokens::get_instance($owner)->verify($second), 'Failure fixture leaves the other browser alive.');
    remove_filter('update_user_metadata', $deny_sessions, PHP_INT_MAX); $filters = [];
    sec_check(!is_wp_error(sec_call('revoke_others', $password)), 'Retry after storage recovery succeeds.');

    $injected = false;
    $native_revoke = function ($sql) use ($owner, &$injected) {
        global $wpdb;
        if (!$injected && strpos($sql, "UPDATE $wpdb->usermeta SET meta_value=") === 0 && strpos($sql, 'security_user') !== false) { $injected = true; $wpdb->delete($wpdb->usermeta, ['user_id'=>$owner, 'meta_key'=>'session_tokens']); }
        return $sql;
    };
    add_filter('query', $native_revoke, 5); $filters[] = ['query', $native_revoke, 5];
    sec_error(sec_call('revoke_others', $password), 'revoke_unverified', 'Native revocation just before CAS cannot be recreated from cached sessions.');
    remove_filter('query', $native_revoke, 5); $filters = [];
    sec_check($injected && wuai_sluyu_security_sessions_raw($owner) === [] && get_current_user_id() === 0, 'Deleted session row stays deleted; this browser is signed out.');

    sec_user($owner, $password); $current = sec_cookie($owner); $new_native = hash('sha256', 'qa-native-login-new-session'); $injected = false;
    $native_login = function ($sql) use ($owner, $new_native, &$injected) {
        global $wpdb;
        if (!$injected && strpos($sql, "UPDATE $wpdb->usermeta SET meta_value=") === 0 && strpos($sql, 'security_user') !== false) {
            $injected = true; $snapshot = wuai_sluyu_security_session_snapshot($owner); $sessions = $snapshot['sessions'];
            $sessions[$new_native] = ['expiration'=>time() + DAY_IN_SECONDS, 'login'=>time(), 'ua'=>'QA native concurrent login'];
            $wpdb->update($wpdb->usermeta, ['meta_value'=>serialize($sessions)], ['umeta_id'=>$snapshot['id']]);
        }
        return $sql;
    };
    add_filter('query', $native_login, 5); $filters[] = ['query', $native_login, 5];
    sec_error(sec_call('revoke_others', $password), 'revoke_unverified', 'Native login changing the complete bundle fences out a stale revoke.');
    remove_filter('query', $native_login, 5); $filters = [];
    sec_check($injected && isset(wuai_sluyu_security_sessions_raw($owner)[$new_native]), 'Concurrent new browser session is not overwritten by a stale snapshot.');
    sec_check(!is_wp_error(sec_call('revoke_others', $password)), 'Refreshing the snapshot permits a later explicit revoke.');

    $identity = wuai_sluyu_security_identity($owner); $guard = wuai_sluyu_security_lock($owner);
    sec_check(is_array($identity) && is_array($guard), 'Capture current identity and lock before manager fallback test.');
    $replacement_sessions = [$new_native=>['expiration'=>time() + DAY_IN_SECONDS, 'login'=>time(), 'ua'=>'Only the concurrent new browser remains']];
    $wpdb->update($wpdb->usermeta, ['meta_value'=>serialize($replacement_sessions)], ['umeta_id'=>$identity['snapshot']['id']]); wp_cache_delete($owner, 'user_meta');
    try { sec_check(wuai_sluyu_security_revoke_cas($owner, $identity, $guard) === false, 'Missing current session cannot use destroy_others fallback DELETE.'); }
    finally { wuai_sluyu_security_unlock($guard); }
    sec_check(wuai_sluyu_security_sessions_raw($owner) === $replacement_sessions, 'A concurrent replacement browser survives the native destroy_all fallback path.');

    sec_user($owner, $password); $wpdb->update($wpdb->users, ['user_pass'=>md5($password)], ['ID'=>$owner]); clean_user_cache($owner); sec_cookie($owner);
    $md5 = wuai_sluyu_security_user($owner)->user_pass; $check_ids = [];
    $password_ids = function ($valid, $plain, $hash, $uid) use (&$check_ids) { $check_ids[] = $uid; return $valid; };
    add_filter('check_password', $password_ids, PHP_INT_MAX, 4); $filters[] = ['check_password', $password_ids, PHP_INT_MAX];
    sec_check(!is_wp_error(sec_call('revoke_others', $password)), 'Legacy MD5 verification can revoke without implicit password upgrade.');
    remove_filter('check_password', $password_ids, PHP_INT_MAX); $filters = [];
    sec_check(hash_equals($md5, wuai_sluyu_security_user($owner)->user_pass) && $check_ids && count(array_filter($check_ids)) === 0, 'Read-only verification passes no user ID, avoiding WP6 MD5 rehash side effects.');
    sec_user($owner, $password); sec_cookie($owner);

    $before = wuai_sluyu_security_user($owner)->user_pass;
    $deny_password = function ($data, $update, $uid) use ($owner, $before) { if ($update && (int) $uid === $owner) { $data['user_pass'] = $before; } return $data; };
    add_filter('wp_pre_insert_user_data', $deny_password, PHP_INT_MAX, 3); $filters[] = ['wp_pre_insert_user_data', $deny_password, PHP_INT_MAX];
    $mail_decisions = [];
    sec_error(sec_call('change_password', $password, $new), 'password_write', 'Core returning an ID without a persisted new hash is not success.');
    sec_check($mail_decisions === [false], 'Unchanged failed password update suppresses the misleading native success notification.');
    sec_check(hash_equals($before, wuai_sluyu_security_user($owner)->user_pass) && get_current_user_id() === $owner, 'Unchanged password leaves the current valid session intact.');
    remove_filter('wp_pre_insert_user_data', $deny_password, PHP_INT_MAX); $filters = [];
    sec_check(apply_filters('send_auth_cookies', true) === true, 'Temporary cookie filter is removed after a failed password update.');

    $injected = false; $before = wuai_sluyu_security_user($owner)->user_pass;
    $password_logout = function ($data, $update, $uid) use ($owner, &$injected) {
        if ($update && (int) $uid === $owner && !$injected) { $injected = true; global $wpdb; $wpdb->delete($wpdb->usermeta, ['user_id'=>$owner, 'meta_key'=>'session_tokens']); }
        return $data;
    };
    add_filter('wp_pre_insert_user_data', $password_logout, 5, 3); $filters[] = ['wp_pre_insert_user_data', $password_logout, 5];
    $mail_decisions = [];
    sec_error(sec_call('change_password', $password, $new), 'password_write', 'Native logout between identity verification and password write blocks the change.');
    sec_check($mail_decisions === [false], 'Session-CAS rejection does not send a password-changed notification.');
    remove_filter('wp_pre_insert_user_data', $password_logout, 5); $filters = [];
    sec_check($injected && hash_equals($before, wuai_sluyu_security_user($owner)->user_pass) && wuai_sluyu_security_sessions_raw($owner) === [] && get_current_user_id() === 0, 'Revoked session remains gone and cannot authorize a new password.');

    sec_user($owner, $password); sec_cookie($owner); $original_email = wuai_sluyu_security_user($owner)->user_email; $injected = false;
    $native_email = function ($data, $update, $uid) use ($owner, &$injected) {
        if ($update && (int) $uid === $owner && !$injected) { $injected = true; global $wpdb; $wpdb->update($wpdb->users, ['user_email'=>'qa-concurrent-email@example.test', 'display_name'=>'Concurrent profile edit'], ['ID'=>$owner]); }
        return $data;
    };
    add_filter('wp_pre_insert_user_data', $native_email, 5, 3); $filters[] = ['wp_pre_insert_user_data', $native_email, 5];
    $mail_decisions = [];
    sec_error(sec_call('change_password', $password, $new), 'password_write', 'Concurrent email change blocks this password operation rather than overwriting recovery identity.');
    sec_check($mail_decisions === [false], 'Email-CAS rejection does not send a password-changed notification.');
    remove_filter('wp_pre_insert_user_data', $native_email, 5); $filters = [];
    $fresh_user = wuai_sluyu_security_user($owner);
    sec_check($injected && $fresh_user->user_email === 'qa-concurrent-email@example.test' && $fresh_user->display_name === 'Concurrent profile edit', 'Native email/display name are not overwritten by wp_update_user old snapshot.');
    $wpdb->update($wpdb->users, ['user_email'=>$original_email], ['ID'=>$owner]); clean_user_cache($owner); sec_cookie($owner); delete_user_meta($owner, '_wuai_security_attempts');

    $concurrent = 'QA-native-reset-wins-race'; $injected = false;
    $native_reset = function ($data, $update, $uid) use ($owner, $concurrent, &$injected) {
        if ($update && (int) $uid === $owner && !$injected) { $injected = true; global $wpdb; $wpdb->update($wpdb->users, ['user_pass'=>wp_hash_password($concurrent)], ['ID'=>$owner]); }
        return $data;
    };
    add_filter('wp_pre_insert_user_data', $native_reset, 5, 3); $filters[] = ['wp_pre_insert_user_data', $native_reset, 5];
    $mail_decisions = [];
    sec_error(sec_call('change_password', $password, $new), 'password_unverified', 'Concurrent native reset fences out the stale password change.');
    sec_check($mail_decisions === [false], 'Stale password-CAS rejection suppresses the theme operation notification.');
    remove_filter('wp_pre_insert_user_data', $native_reset, 5); $filters = [];
    sec_check($injected && wp_check_password($concurrent, wuai_sluyu_security_user($owner)->user_pass, $owner), 'The native reset password is not overwritten.');
    sec_check(get_current_user_id() === 0 && wuai_sluyu_security_sessions_raw($owner) === [], 'Unverifiable changed identity signs this browser out and clears sessions.');

    sec_user($owner, $password); sec_cookie($owner); $second = WP_Session_Tokens::get_instance($owner)->create(time() + DAY_IN_SECONDS);
    $deny_delete = function ($check, $id, $key) use ($owner) { return (int) $id === $owner && $key === 'session_tokens' ? false : $check; };
    add_filter('delete_user_metadata', $deny_delete, PHP_INT_MAX, 3); $filters[] = ['delete_user_metadata', $deny_delete, PHP_INT_MAX];
    $mail = []; $mail_decisions = []; sec_error(sec_call('change_password', $password, $new), 'password_sessions', 'Saved password plus failed session clearing reports the partial result, never rolls back.');
    remove_filter('delete_user_metadata', $deny_delete, PHP_INT_MAX); $filters = [];
    sec_check(wp_check_password($new, wuai_sluyu_security_user($owner)->user_pass, $owner) && !wp_check_password($password, wuai_sluyu_security_user($owner)->user_pass, $owner), 'New exact password persists after partial logout failure.');
    sec_check(get_current_user_id() === 0 && !isset($_COOKIE[LOGGED_IN_COOKIE]), 'Partial logout failure still clears the current browser.');
    sec_check(count($mail) === 1, 'Native password notification is attempted once, with delivery blocked in QA.');
    sec_check($mail_decisions === [true], 'Persisted password retains the native notification even if clearing other sessions fails.');
    sec_check(strpos(wp_json_encode($mail), $password) === false && strpos(wp_json_encode($mail), $new) === false, 'Notification does not contain old/new plaintext passwords.');
    sec_check(apply_filters('send_auth_cookies', true) === true, 'Temporary cookie filter is removed after partial session failure.');

    sec_user($owner, $password); $one = sec_cookie($owner); $two = WP_Session_Tokens::get_instance($owner)->create(time() + DAY_IN_SECONDS); $old_cookie = $_COOKIE[LOGGED_IN_COOKIE]; $mail = []; $mail_decisions = [];
    $result = sec_call('change_password', $password, $new);
    sec_check(is_array($result) && $result['operation'] === 'change_password' && strpos($result['redirect'], 'wp-login.php') !== false, 'Verified password change redirects to sign-in.');
    sec_check(wuai_sluyu_security_sessions_raw($owner) === [] && get_current_user_id() === 0 && !isset($_COOKIE[LOGGED_IN_COOKIE]), 'Successful password change destroys all session tokens and clears this browser.');
    sec_check(wp_validate_auth_cookie($old_cookie, 'logged_in') === false, 'Old cookie cannot authenticate after the password change.');
    sec_check(wp_check_password($new, wuai_sluyu_security_user($owner)->user_pass, $owner), 'Interior spaces, quotes and backslashes are stored without sanitizing or double-unslashing.');
    sec_check(count($mail) === 1 && strpos(wp_json_encode($result), $new) === false, 'One native notification attempt and no password in the returned redirect/result.');
    sec_check($mail_decisions === [true], 'Successful verified password change preserves native notification behavior.');
    sec_check(WP_Session_Tokens::get_instance($other)->verify($other_token), 'Password change affects only the acting account.');
    sec_check(apply_filters('send_auth_cookies', true) === true, 'Normal WordPress cookie behavior restored after success.');
    echo "PASS: cookie-bound own-account security, POST/nonces, password byte handling, current password/rate limits, fresh identity, CAS native-reset fencing, session privacy/pagination, failed storage/partial results, native notifications and all-browser logout. Mail blocked.\n";
} finally {
    foreach ($filters as $filter) { remove_filter($filter[0], $filter[1], $filter[2]); }
    require_once ABSPATH . 'wp-admin/includes/user.php'; wp_set_current_user(0);
    foreach ($users as $name=>$uid) { $user = get_userdata($uid); if ($user && $user->user_login === $login_prefix . $name) { wp_delete_user($uid); } }
    remove_filter('pre_wp_mail', $mail_block, PHP_INT_MAX); remove_filter('pre_wp_mail', $mail_observe, PHP_INT_MAX); remove_filter('password_change_email', $mail_watch, 1); $_COOKIE = $original_cookies; $_POST = $old_post; wp_set_current_user($old_uid);
}
