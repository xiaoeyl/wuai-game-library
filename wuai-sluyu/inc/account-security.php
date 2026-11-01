<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_security_error($code, $message, $status = 400, $reauth = false) {
    return new WP_Error('security_' . $code, $message, ['status'=>$status, 'reauth'=>$reauth]);
}

function wuai_sluyu_security_transport() {
    if (is_ssl()) { return true; }
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    return in_array($host, ['localhost','127.0.0.1','::1'], true) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true);
}

function wuai_sluyu_security_user($uid) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->users WHERE ID=%d", $uid));
    if ($wpdb->last_error || !$row || !is_string($row->user_pass) || !$row->user_pass) { return wuai_sluyu_security_error('identity', '无法核验你的账号，请重新登录后再继续。', 403, true); }
    return $row;
}

function wuai_sluyu_security_same_sql($column, $value) {
    global $wpdb;
    // HEX compares ASCII hex digits, not the case-insensitive site collation.
    // It is available in MySQL and the supported WordPress SQLite adapter.
    return $wpdb->prepare('HEX(' . $column . ')=HEX(%s)', $value);
}

function wuai_sluyu_security_session_snapshot($uid) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT umeta_id,meta_value FROM $wpdb->usermeta WHERE user_id=%d AND meta_key='session_tokens' ORDER BY umeta_id", $uid), ARRAY_A);
    if ($wpdb->last_error || count($rows) > 1) { return wuai_sluyu_security_error('session_storage', '无法核验你的登录会话记录，请联系管理员检查。', 409); }
    if (!$rows) { return ['id'=>0, 'raw'=>'', 'sessions'=>[]]; }
    $raw = $rows[0]['meta_value'];
    if (!is_string($raw) || strlen($raw) > 2 * MB_IN_BYTES || !is_serialized($raw)) { return wuai_sluyu_security_error('session_storage', '你的登录会话记录需要管理员检查。', 409); }
    $sessions = @unserialize($raw, ['allowed_classes'=>false]);
    if (!is_array($sessions) || count($sessions) > 10000) { return wuai_sluyu_security_error('session_storage', '你的登录会话记录需要管理员检查。', 409); }
    foreach ($sessions as $key=>$session) {
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) { return wuai_sluyu_security_error('session_storage', '你的登录会话记录需要管理员检查。', 409); }
        if (is_int($session)) { $session = ['expiration'=>$session]; }
        if (!is_array($session) || !isset($session['expiration']) || !is_int($session['expiration']) || $session['expiration'] < 1 || (isset($session['login']) && (!is_int($session['login']) || $session['login'] < 0)) || (isset($session['ua']) && !is_string($session['ua']))) { return wuai_sluyu_security_error('session_storage', '你的登录会话记录需要管理员检查。', 409); }
        $sessions[$key] = $session;
    }
    return ['id'=>(int) $rows[0]['umeta_id'], 'raw'=>$raw, 'sessions'=>$sessions];
}

function wuai_sluyu_security_sessions_raw($uid) {
    $snapshot = wuai_sluyu_security_session_snapshot($uid);
    return is_wp_error($snapshot) ? $snapshot : $snapshot['sessions'];
}

function wuai_sluyu_security_identity($uid) {
    if (!$uid || $uid !== get_current_user_id() || !wuai_sluyu_security_transport()) { return wuai_sluyu_security_error('identity', '请通过 HTTPS 登录你自己的账号后再继续。', 403, true); }
    if (is_multisite()) { return wuai_sluyu_security_error('multisite', '请通过 WordPress 原生个人资料页管理此多站点账号。本主题未启用跨站点网络的账号安全管理。', 409); }
    $user = wuai_sluyu_security_user($uid); if (is_wp_error($user)) { return $user; }
    clean_user_cache($uid); wp_cache_delete($uid, 'user_meta');
    $manager = WP_Session_Tokens::get_instance($uid);
    if (get_class($manager) !== 'WP_User_Meta_Session_Tokens') { return wuai_sluyu_security_error('session_provider', '本站使用了其他登录会话管理方式，请通过 WordPress 个人资料页管理账号安全，或联系管理员。', 409); }
    $cookie = isset($_COOKIE[LOGGED_IN_COOKIE]) && is_string($_COOKIE[LOGGED_IN_COOKIE]) && strlen($_COOKIE[LOGGED_IN_COOKIE]) <= 4096 ? $_COOKIE[LOGGED_IN_COOKIE] : '';
    $parsed = $cookie !== '' ? wp_parse_auth_cookie($cookie, 'logged_in') : false;
    if (!$parsed || !ctype_digit((string) $parsed['expiration']) || (int) $parsed['expiration'] < time() || $parsed['username'] !== $user->user_login || !is_string($parsed['token']) || !$parsed['token']) { return wuai_sluyu_security_error('session', '当前浏览器的登录会话已失效，请重新登录。', 403, true); }
    $snapshot = wuai_sluyu_security_session_snapshot($uid); if (is_wp_error($snapshot)) { return $snapshot; } $sessions = $snapshot['sessions'];
    $key = hash('sha256', $parsed['token']);
    if (!isset($sessions[$key]) || $sessions[$key]['expiration'] < time() || (int) wp_validate_auth_cookie($cookie, 'logged_in') !== $uid) { return wuai_sluyu_security_error('session', '当前浏览器的登录会话已失效，请重新登录。', 403, true); }
    return ['user'=>$user, 'token'=>$parsed['token'], 'key'=>$key, 'session'=>$sessions[$key], 'sessions'=>$sessions, 'snapshot'=>$snapshot, 'manager'=>$manager];
}

function wuai_sluyu_security_snapshot_sql($uid, $identity, $lock) {
    global $wpdb;
    return $wpdb->prepare("EXISTS (SELECT 1 FROM $wpdb->usermeta security_session WHERE security_session.umeta_id=%d AND security_session.user_id=%d AND security_session.meta_key='session_tokens' AND ", $identity['snapshot']['id'], $uid) . wuai_sluyu_security_same_sql('security_session.meta_value', $identity['snapshot']['raw']) . $wpdb->prepare(") AND (SELECT COUNT(*) FROM $wpdb->usermeta security_count WHERE security_count.user_id=%d AND security_count.meta_key='session_tokens')=1 AND EXISTS (SELECT 1 FROM $wpdb->options security_lock WHERE security_lock.option_name=%s AND security_lock.option_value=%s)", $uid, $lock['name'], $lock['token']);
}

function wuai_sluyu_security_revoke_cas($uid, $identity, $lock) {
    // Short-circuit only this manager write. A deleted/changed native session
    // bundle must never be recreated from the request's stale metadata cache.
    $called = false; $written = false;
    $write = static function ($check, $id, $key, $value) use ($uid, $identity, $lock, &$called, &$written) {
        if ((int) $id !== $uid || $key !== 'session_tokens') { return $check; }
        $called = true;
        $desired = [$identity['key']=>$identity['session']];
        if ($check !== null || !is_array($value) || $value !== wp_unslash($desired)) { return false; }
        global $wpdb;
        // The row predicate performs the complete session CAS without reading
        // the update target through a nested MySQL subquery.
        $result = $wpdb->query($wpdb->prepare("UPDATE $wpdb->usermeta SET meta_value=%s WHERE umeta_id=%d AND user_id=%d AND meta_key='session_tokens' AND ", serialize($desired), $identity['snapshot']['id'], $uid) . wuai_sluyu_security_same_sql('meta_value', $identity['snapshot']['raw']) . $wpdb->prepare(" AND EXISTS (SELECT 1 FROM $wpdb->users security_user WHERE security_user.ID=%d AND ", $uid) . wuai_sluyu_security_same_sql('security_user.user_pass', $identity['user']->user_pass) . $wpdb->prepare(") AND EXISTS (SELECT 1 FROM $wpdb->options security_lock WHERE security_lock.option_name=%s AND security_lock.option_value=%s)", $lock['name'], $lock['token']));
        $written = $result !== false; wp_cache_delete($uid, 'user_meta');
        return $written;
    };
    $no_delete = static function ($check, $id, $key) use ($uid) { return (int) $id === $uid && $key === 'session_tokens' ? false : $check; };
    add_filter('update_user_metadata', $write, PHP_INT_MAX, 4);
    add_filter('delete_user_metadata', $no_delete, PHP_INT_MAX, 3);
    try { $identity['manager']->destroy_others($identity['token']); }
    catch (Throwable $error) { $written = false; }
    finally { remove_filter('update_user_metadata', $write, PHP_INT_MAX); remove_filter('delete_user_metadata', $no_delete, PHP_INT_MAX); }
    return $called && $written;
}

function wuai_sluyu_security_lock($uid) {
    $user_lock = wuai_sluyu_lock('user_' . $uid);
    if (!$user_lock) { return wuai_sluyu_security_error('busy', '账号正在处理其他操作，请稍后重试。', 409); }
    try { $token = bin2hex(random_bytes(32)); }
    catch (Throwable $error) { wuai_sluyu_unlock($user_lock); return wuai_sluyu_security_error('busy', '无法生成安全操作标识，本次未作任何更改。', 409); }
    global $wpdb; $name = 'wuai_security_lock_' . $uid;
    $written = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name,option_value,autoload) VALUES (%s,%s,'no')", $name, $token));
    if ($written !== 1) { wuai_sluyu_unlock($user_lock); return wuai_sluyu_security_error('busy', '另一项安全操作正在进行，或需要管理员检查。本次未作任何更改。', 409); }
    // No lease takeover: a paused password worker must not resume after a new one.
    return ['name'=>$name, 'token'=>$token, 'user_lock'=>$user_lock];
}

function wuai_sluyu_security_locked($lock) {
    global $wpdb;
    $token = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", $lock['name']));
    return !$wpdb->last_error && is_string($token) && hash_equals($lock['token'], $token);
}

function wuai_sluyu_security_unlock($lock) {
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", $lock['name'], $lock['token']));
    wuai_sluyu_unlock($lock['user_lock']);
}

function wuai_sluyu_security_budget($uid) {
    global $wpdb;
    $key = '_wuai_security_attempts';
    $rows = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM $wpdb->usermeta WHERE user_id=%d AND meta_key=%s", $uid, $key));
    if ($wpdb->last_error || count($rows) > 1) { return wuai_sluyu_security_error('rate_storage', '无法核验安全操作的频率限制，请稍后重试。', 409); }
    $budget = $rows ? @unserialize($rows[0], ['allowed_classes'=>false]) : ['started'=>time(), 'count'=>0];
    if (!is_array($budget) || array_keys($budget) !== ['started','count'] || !is_int($budget['started']) || !is_int($budget['count']) || $budget['count'] < 0) { return wuai_sluyu_security_error('rate_storage', '安全操作的频率限制记录需要管理员检查。', 409); }
    if ($budget['started'] + 15 * MINUTE_IN_SECONDS < time()) { $budget = ['started'=>time(), 'count'=>0]; }
    if ($budget['count'] >= 8) { return wuai_sluyu_security_error('rate', '密码核验次数过多，请等待 15 分钟后再试。', 429); }
    $budget['count']++; update_user_meta($uid, $key, $budget);
    $saved = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM $wpdb->usermeta WHERE user_id=%d AND meta_key=%s", $uid, $key));
    return !$wpdb->last_error && count($saved) === 1 && $saved[0] === serialize($budget) ? true : wuai_sluyu_security_error('rate_storage', '无法保存安全操作的频率限制记录，本次未进行密码核验。', 409);
}

function wuai_sluyu_security_clear_browser() {
    wp_clear_auth_cookie();
    unset($_COOKIE[LOGGED_IN_COOKIE], $_COOKIE[AUTH_COOKIE], $_COOKIE[SECURE_AUTH_COOKIE]);
    wp_set_current_user(0);
}

function wuai_sluyu_account_security_request($method, $post) {
    if ($method !== 'POST' || !is_array($post)) { return wuai_sluyu_security_error('method', '安全设置必须在登录后通过 POST 表单提交。', 405); }
    foreach (['operation','_wuai_security_nonce','current_password','confirm'] as $key) { if (!isset($post[$key]) || !is_string($post[$key])) { return wuai_sluyu_security_error('input', '安全表单字段格式不正确，必须为单个文本值。'); } }
    $operation = wp_unslash($post['operation']); $uid = get_current_user_id();
    if (!in_array($operation, ['change_password','revoke_others'], true) || wp_unslash($post['confirm']) !== '1') { return wuai_sluyu_security_error('input', '请选择支持的安全操作，并勾选确认。'); }
    $current = wp_unslash($post['current_password']);
    if ($current === '' || strlen($current) > 4096) { return wuai_sluyu_security_error('password', '请输入当前密码，并保留密码原有的空格等空白字符。', 403); }
    $new = '';
    if ($operation === 'change_password') {
        if (!isset($post['new_password'], $post['repeat_password']) || !is_string($post['new_password']) || !is_string($post['repeat_password'])) { return wuai_sluyu_security_error('input', '请输入新密码并再次确认；两个字段都必须为单个文本值。'); }
        $new = wp_unslash($post['new_password']); $repeat = wp_unslash($post['repeat_password']);
        // WordPress bcrypt hashing trims boundary ASCII whitespace. Reject it
        // explicitly rather than silently setting a different password.
        if (strlen($new) < 12 || strlen($new) > 128 || trim($new) !== $new || preg_match('/[\x00-\x1f\x7f]/', $new)) { return wuai_sluyu_security_error('new_password', '新密码长度须为 12–128 字节，不得包含控制字符，首尾不能有 ASCII 空白字符；中间的空格会原样保留。'); }
        if (!hash_equals($new, $repeat)) { return wuai_sluyu_security_error('confirmation', '两次输入的新密码不一致，本次未作任何更改。'); }
    }
    if (!$uid || !wuai_sluyu_security_transport()) { return wuai_sluyu_security_error('identity', '请通过 HTTPS 登录你自己的账号后再继续。', 403, true); }
    $lock = wuai_sluyu_security_lock($uid); if (is_wp_error($lock)) { return $lock; }
    try {
        $identity = wuai_sluyu_security_identity($uid); if (is_wp_error($identity)) { return $identity; }
        if (!wp_verify_nonce(wp_unslash($post['_wuai_security_nonce']), 'wuai_account_security:' . $uid . ':' . $operation)) { return wuai_sluyu_security_error('nonce', '此表单已过期或属于另一个登录会话，请重新打开账号安全页。', 403); }
        $budget = wuai_sluyu_security_budget($uid); if (is_wp_error($budget)) { return $budget; }
        // Passing a user ID to legacy WordPress MD5 verification can rehash and
        // write the password before our final CAS. Verification must be read-only.
        if (!wp_check_password($current, $identity['user']->user_pass, 0)) { return wuai_sluyu_security_error('password', '当前密码不正确，本次未作任何更改。', 403); }
        if ($operation === 'change_password' && wp_check_password($new, $identity['user']->user_pass, 0)) { return wuai_sluyu_security_error('reuse', '请设置一个与当前密码不同的新密码。'); }
        $fresh = wuai_sluyu_security_identity($uid);
        if (is_wp_error($fresh) || !hash_equals($identity['user']->user_pass, $fresh['user']->user_pass) || !wuai_sluyu_security_locked($lock)) { return wuai_sluyu_security_error('changed', '核验期间账号或登录会话发生了变化，请重新登录后再试。', 409, true); }
        if ($operation === 'revoke_others') {
            $written = wuai_sluyu_security_revoke_cas($uid, $fresh, $lock); wp_cache_delete($uid, 'user_meta');
            $sessions = wuai_sluyu_security_sessions_raw($uid); $after = wuai_sluyu_security_user($uid);
            if (is_wp_error($sessions) || is_wp_error($after) || !hash_equals($fresh['user']->user_pass, $after->user_pass) || !isset($sessions[$fresh['key']])) {
                wuai_sluyu_security_clear_browser();
                return wuai_sluyu_security_error('revoke_unverified', '无法确认登录会话是否已撤销。本浏览器已退出登录，请重新登录；如问题持续，请联系管理员。', 409, true);
            }
            if (!$written || count($sessions) !== 1 || $sessions[$fresh['key']] !== $fresh['session'] || !wuai_sluyu_security_locked($lock)) { return wuai_sluyu_security_error('revoke_unverified', '无法确认其他浏览器的登录会话是否已撤销，请勿认为它们已经退出。请重试或联系管理员。', 409); }
            delete_user_meta($uid, '_wuai_security_attempts');
            return ['operation'=>$operation, 'redirect'=>wuai_sluyu_portal_url('account', ['tab'=>'security', 'security_notice'=>'sessions_revoked'])];
        }
        // Core sends its normal notification. Do not let its automatic cookie
        // renewal keep this browser signed in after a password change.
        $block_cookies = static function () { return false; };
        $expected_hash = '';
        $password_data = static function ($data, $update, $id) use ($uid, &$expected_hash) {
            if (!$update || (int) $id !== $uid || !isset($data['user_pass']) || !is_string($data['user_pass'])) { return $data; }
            $expected_hash = $data['user_pass'];
            // wp_update_user normally rewrites an entire earlier profile snapshot.
            // Only password/reset-key columns belong to this security operation.
            return ['user_pass'=>$expected_hash, 'user_activation_key'=>''];
        };
        $password_meta = static function ($meta, $user, $update) use ($uid) { return $update && (int) $user->ID === $uid ? [] : $meta; };
        $password_contacts = static function ($methods, $user) use ($uid) { return $user && (int) $user->ID === $uid ? [] : $methods; };
        $password_fence = static function ($sql) use ($uid, $fresh, $lock) {
            global $wpdb;
            if (!preg_match('/^UPDATE\s+`?' . preg_quote($wpdb->users, '/') . '`?\s+SET\s/is', $sql) || strpos($sql, 'wuai_security_password_write') === false || !preg_match('/\bWHERE\s+`?ID`?\s*=\s*(?:\'' . $uid . '\'|"' . $uid . '"|' . $uid . ')\s*$/D', $sql)) { return $sql; }
            // A native password reset need not use our lock. Its new hash must
            // not be overwritten by this already-verified but now stale request.
            foreach (['user_pass','user_email','user_login','user_activation_key'] as $column) { $sql .= ' AND ' . wuai_sluyu_security_same_sql($column, $fresh['user']->$column); }
            return $sql . ' AND ' . wuai_sluyu_security_snapshot_sql($uid, $fresh, $lock);
        };
        $tag_password_write = static function ($sql) use ($uid, &$expected_hash) {
            global $wpdb;
            if ($expected_hash === '' || !preg_match('/^UPDATE\s+`?' . preg_quote($wpdb->users, '/') . '`?\s+SET\s/is', $sql) || !preg_match('/\bWHERE\s+`?ID`?\s*=\s*(?:\'' . $uid . '\'|"' . $uid . '"|' . $uid . ')\s*$/D', $sql) || strpos($sql, $wpdb->prepare('%s', $expected_hash)) === false) { return $sql; }
            return preg_replace('/\bSET\s/i', 'SET /* wuai_security_password_write */ ', $sql, 1);
        };
        $notification_pending = false;
        $capture_email = static function ($email, $user) use ($uid, &$notification_pending) { if ((int) ($user['ID'] ?? 0) === $uid) { $notification_pending = true; } return $email; };
        $mail_guard = static function ($pre) use ($uid, $fresh, &$notification_pending, &$expected_hash) {
            if (!$notification_pending) { return $pre; } $notification_pending = false;
            $stored = wuai_sluyu_security_user($uid);
            return $expected_hash !== '' && !hash_equals($fresh['user']->user_pass, $expected_hash) && !is_wp_error($stored) && hash_equals($expected_hash, $stored->user_pass) ? $pre : false;
        };
        add_filter('send_auth_cookies', $block_cookies, PHP_INT_MAX);
        add_filter('query', $tag_password_write, PHP_INT_MAX - 1);
        add_filter('query', $password_fence, PHP_INT_MAX);
        add_filter('wp_pre_insert_user_data', $password_data, PHP_INT_MAX, 3);
        add_filter('password_change_email', $capture_email, PHP_INT_MAX, 2);
        add_filter('insert_user_meta', $password_meta, PHP_INT_MAX, 3);
        add_filter('insert_custom_user_meta', $password_meta, PHP_INT_MAX, 3);
        add_filter('user_contactmethods', $password_contacts, PHP_INT_MAX, 2);
        add_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX);
        try { $updated = wp_update_user(['ID'=>$uid, 'user_pass'=>$new]); }
        catch (Throwable $error) { $updated = wuai_sluyu_security_error('password_write', '密码修改操作被中断。'); }
        finally { remove_filter('send_auth_cookies', $block_cookies, PHP_INT_MAX); remove_filter('query', $tag_password_write, PHP_INT_MAX - 1); remove_filter('query', $password_fence, PHP_INT_MAX); remove_filter('wp_pre_insert_user_data', $password_data, PHP_INT_MAX); remove_filter('password_change_email', $capture_email, PHP_INT_MAX); remove_filter('insert_user_meta', $password_meta, PHP_INT_MAX); remove_filter('insert_custom_user_meta', $password_meta, PHP_INT_MAX); remove_filter('user_contactmethods', $password_contacts, PHP_INT_MAX); remove_filter('pre_wp_mail', $mail_guard, PHP_INT_MAX); }
        clean_user_cache($uid); wp_cache_delete($uid, 'user_meta');
        $after = wuai_sluyu_security_user($uid);
        if (!is_wp_error($after) && hash_equals($fresh['user']->user_pass, $after->user_pass)) {
            $still_valid = wuai_sluyu_security_identity($uid);
            if (is_wp_error($still_valid)) { wuai_sluyu_security_clear_browser(); }
            return wuai_sluyu_security_error('password_write', '新密码未保存，尚未确认密码修改成功。请重试或联系管理员。', 409, is_wp_error($still_valid));
        }
        $verified = !is_wp_error($updated) && $updated === $uid && !is_wp_error($after) && wp_check_password($new, $after->user_pass, 0) && wuai_sluyu_security_locked($lock);
        try { WP_Session_Tokens::get_instance($uid)->destroy_all(); }
        catch (Throwable $error) { $verified = false; }
        wp_cache_delete($uid, 'user_meta'); $sessions = wuai_sluyu_security_sessions_raw($uid);
        wuai_sluyu_security_clear_browser();
        if (!$verified) { return wuai_sluyu_security_error('password_unverified', '无法完整核验密码修改结果，本浏览器已退出登录。请勿认为旧密码仍可使用；请尝试登录或重置密码，并联系管理员。密码未回滚。', 409, true); }
        if (is_wp_error($sessions) || $sessions !== []) { return wuai_sluyu_security_error('password_sessions', '新密码已保存，本浏览器已退出登录，但无法确认其他登录会话是否已全部清除。请联系管理员；密码未回滚。', 409, true); }
        delete_user_meta($uid, '_wuai_security_attempts');
        return ['operation'=>$operation, 'redirect'=>add_query_arg('wuai_security_notice', 'password_changed', wp_login_url(wuai_sluyu_portal_url('account', ['tab'=>'security'])))];
    } finally { wuai_sluyu_security_unlock($lock); }
}

function wuai_sluyu_account_security_handler() {
    nocache_headers(); header('Referrer-Policy: no-referrer');
    $result = wuai_sluyu_account_security_request($_SERVER['REQUEST_METHOD'] ?? '', $_POST);
    if (is_wp_error($result)) {
        $data = $result->get_error_data(); $url = !empty($data['reauth']) ? wp_login_url(wuai_sluyu_portal_url('account', ['tab'=>'security'])) : wuai_sluyu_portal_url('account', ['tab'=>'security']);
        wp_die(esc_html($result->get_error_message()) . '<p><a href="' . esc_url($url) . '">返回账号安全 / 重新登录</a></p>', '账号安全', ['response'=>$data['status'] ?? 400]);
    }
    wp_safe_redirect($result['redirect'], 303); exit;
}
add_action('admin_post_wuai_account_security', 'wuai_sluyu_account_security_handler');
add_action('admin_post_nopriv_wuai_account_security', 'wuai_sluyu_account_security_handler');

function wuai_sluyu_security_sessions($page = 1) {
    $identity = wuai_sluyu_security_identity(get_current_user_id()); if (is_wp_error($identity)) { return $identity; }
    $rows = [];
    foreach ($identity['sessions'] as $key=>$session) {
        if ($session['expiration'] < time()) { continue; }
        $rows[] = ['current'=>hash_equals($identity['key'], $key), 'login'=>$session['login'] ?? 0, 'expiration'=>$session['expiration'], 'agent'=>mb_substr($session['ua'] ?? '暂无浏览器信息', 0, 240)];
    }
    usort($rows, static function ($a, $b) { return ($b['current'] <=> $a['current']) ?: ($b['login'] <=> $a['login']) ?: ($b['expiration'] <=> $a['expiration']); });
    $total = count($rows); $pages = max(1, (int) ceil($total / 50)); $page = max(1, min($pages, absint($page)));
    return ['items'=>array_slice($rows, ($page - 1) * 50, 50), 'total'=>$total, 'page'=>$page, 'pages'=>$pages];
}

function wuai_sluyu_security_form($operation, $title) {
    $uid = get_current_user_id();
    $field_style = 'display:block;width:100%;max-width:100%;min-width:0;margin-top:8px;padding:11px 13px;border:1px solid #e4e1e7;border-radius:7px;background:#fcfbfd;color:#302c37;font:inherit;box-sizing:border-box';
    echo '<details class="sluyu-faq sluyu-security-operation"><summary>' . esc_html($title) . '</summary><form class="sluyu-security-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:grid;gap:14px;margin-top:18px"><input type="hidden" name="action" value="wuai_account_security"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
    wp_nonce_field('wuai_account_security:' . $uid . ':' . $operation, '_wuai_security_nonce', false);
    echo '<label>当前密码<input type="password" name="current_password" autocomplete="current-password" maxlength="4096" style="' . esc_attr($field_style) . '" required></label>';
    if ($operation === 'change_password') {
        echo '<p>新密码长度须为 12–128 字节，不得包含控制字符，首尾不能有 ASCII 空白字符；中间的空格会原样保留。密码不会回显，也不会出现在操作结果的网址中。修改成功后，所有浏览器都会退出登录，包括当前浏览器。</p><label>新密码<input type="password" name="new_password" autocomplete="new-password" maxlength="128" style="' . esc_attr($field_style) . '" required></label><label>再次输入新密码<input type="password" name="repeat_password" autocomplete="new-password" maxlength="128" style="' . esc_attr($field_style) . '" required></label>';
    } else { echo '<p>保留当前浏览器的登录会话，退出账号当前记录的其他浏览器会话。此操作不会撤销应用密码、API 凭证或第三方身份服务的登录会话。</p>'; }
    echo '<label><input type="checkbox" name="confirm" value="1" required style="width:auto"> 我确认对自己的账号执行此安全操作。</label><button class="sluyu-action-primary" type="submit" style="max-width:100%;white-space:normal">' . esc_html($title) . '</button></form></details>';
}

function wuai_sluyu_view_account_security() {
    if (!wuai_sluyu_require_login()) { return; }
    $sessions = wuai_sluyu_security_sessions(wuai_sluyu_query('session_page', '1'));
    echo '<section class="sluyu-panel sluyu-account-security"><h2>账号安全</h2><p>这里的安全操作仅影响你当前登录的账号。社区禁言或发帖限制不会阻止你保护自己的账号。</p>';
    if (is_wp_error($sessions)) { echo '<p class="sluyu-notice">' . esc_html($sessions->get_error_message()) . '</p><p><a href="' . esc_url(get_edit_profile_url(get_current_user_id())) . '">打开 WordPress 个人资料页</a></p></section>'; return; }
    if (wuai_sluyu_query('security_notice') === 'sessions_revoked') { echo '<p class="sluyu-notice" role="status">上次操作已核验当时仅保留当前浏览器的登录会话。下方显示最新的只读记录；之后重新登录仍会产生新会话。</p>'; }
    wuai_sluyu_security_form('change_password', '修改密码并退出所有浏览器');
    wuai_sluyu_security_form('revoke_others', '退出其他浏览器会话');
    echo '<h3>当前浏览器登录会话（' . (int) $sessions['total'] . '）</h3><p>此处为只读记录，每页最多 50 条。浏览器信息由设备自行提供，不代表已核验的身份或位置。不会显示会话令牌或 IP 地址。</p><div class="sluyu-security-sessions">';
    foreach ($sessions['items'] as $item) {
        echo '<article class="sluyu-panel" data-current="' . ($item['current'] ? 'yes' : 'no') . '" style="overflow-wrap:anywhere"><strong>' . ($item['current'] ? '当前浏览器' : '其他浏览器会话') . '</strong><p class="sluyu-security-agent">' . esc_html($item['agent']) . '</p><p>登录时间：' . esc_html($item['login'] ? wp_date('Y-m-d H:i', $item['login']) : '暂无记录') . '；到期时间：' . esc_html(wp_date('Y-m-d H:i', $item['expiration'])) . '</p></article>';
    }
    echo '</div>';
    if ($sessions['pages'] > 1) { echo '<nav class="sluyu-button-row" aria-label="浏览器会话分页">'; if ($sessions['page'] > 1) { echo '<a href="' . esc_url(wuai_sluyu_portal_url('account', ['tab'=>'security', 'session_page'=>$sessions['page'] - 1])) . '">上一页会话</a>'; } echo '<span>第 ' . (int) $sessions['page'] . ' / ' . (int) $sessions['pages'] . ' 页</span>'; if ($sessions['page'] < $sessions['pages']) { echo '<a href="' . esc_url(wuai_sluyu_portal_url('account', ['tab'=>'security', 'session_page'=>$sessions['page'] + 1])) . '">下一页会话</a>'; } echo '</nav>'; }
    echo '<h3>邮箱与第三方登录</h3><p>更换邮箱请使用 WordPress 原生个人资料页，并按照发送到新邮箱的确认邮件完成操作。本主题不会用未经确认的地址替换你的邮箱。</p><p><a href="' . esc_url(get_edit_profile_url(get_current_user_id())) . '">在 WordPress 中管理邮箱和应用密码</a> &middot; <a href="' . esc_url(wp_lostpassword_url()) . '">重置密码</a></p><p>本主题未配置双重验证或社交账号登录，不会模拟绑定或验证成功。密码修改通知遵循本站的 WordPress 邮件设置，不保证送达。</p></section>';
}

add_action('wuai_sluyu_account_profile_extra', function ($uid) { if ($uid === get_current_user_id()) { echo '<p><a href="' . esc_url(wuai_sluyu_portal_url('account', ['tab'=>'security'])) . '">账号安全：密码与浏览器会话</a></p>'; } });
add_action('template_redirect', function () { if (wuai_sluyu_portal_key() === 'account' && wuai_sluyu_query('tab') === 'security') { nocache_headers(); header('Referrer-Policy: no-referrer'); } }, 0);
add_filter('login_message', function ($message) { return isset($_GET['wuai_security_notice']) && is_string($_GET['wuai_security_notice']) && $_GET['wuai_security_notice'] === 'password_changed' ? $message . '<p class="message">密码修改已核验，所有浏览器登录会话已清除。请使用新密码登录；邮件通知不保证送达。</p>' : $message; });
