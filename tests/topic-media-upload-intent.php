<?php
// Serial disposable CLI fixture. Never install in a production theme/MU plugin.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); $resolved = $root ? realpath($root) : false;
$sqlite = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site'); $mysql = realpath(dirname(__DIR__) . '/dist/checks-mysql/site');
$is_mysql = $resolved && $mysql && strcasecmp($resolved, $mysql) === 0 && getenv('WP_QA_ISOLATED_MYSQL') === '1';
if (!$resolved || !(($sqlite && strcasecmp($resolved, $sqlite) === 0) || $is_mysql) || !is_file($root . '/wp-load.php')) { throw new RuntimeException('Explicit disposable WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/user.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_topic_media_upload_arm') || !function_exists('wuai_sluyu_topic_media_cleanup_delete') || !extension_loaded('gd')) { throw new RuntimeException('Integrated raw-upload/cleanup module and PHP GD required.'); }
if ($is_mysql && (DB_HOST !== '127.0.0.1:3373' || DB_NAME !== 'wuai_isolated_qa' || $wpdb->prefix !== 'qa_' || !defined('WUAI_QA_NO_EXTERNAL_EFFECTS') || !WUAI_QA_NO_EXTERNAL_EFFECTS)) { throw new RuntimeException('Independent MariaDB safety boundary mismatch.'); }

function tmu_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function tmu_error($result, $code, $message) { tmu_check(is_wp_error($result) && $result->get_error_code() === 'topic_media_upload_' . $code, $message); }
function tmu_filter($hook, $callback, $priority = 10, $accepted = 1) { global $tmu_filters; add_filter($hook, $callback, $priority, $accepted); $tmu_filters[] = [$hook,$callback,$priority]; }
function tmu_guard($uid, $post_id) {
    $guard = wuai_sluyu_topic_media_enter($uid); tmu_check(is_array($guard), 'Acquire exact account media fence.');
    $poll = wuai_sluyu_lock('poll_' . $post_id);
    if (!$poll) { wuai_sluyu_topic_media_leave($guard); throw new RuntimeException('Acquire exact topic media fence.'); }
    $guard['post_id'] = $post_id; $guard['post_lock'] = $poll;
    $GLOBALS['tmu_guards'][] = $guard;
    return $guard;
}
function tmu_upload($guard, $source) {
    $encoded = wuai_sluyu_topic_media_reencode($source, 'fixture.png'); tmu_check(is_array($encoded), 'Encode fixture pixels.');
    $GLOBALS['tmu_temp'][] = $encoded['path'];
    tmu_check(wuai_sluyu_topic_media_upload_arm($guard) === true, 'Arm before public sideload.');
    $file = ['name'=>'topic-public-' . wp_generate_uuid4() . '.png','tmp_name'=>$encoded['path'],'type'=>$encoded['mime'],'size'=>filesize($encoded['path']),'error'=>UPLOAD_ERR_OK];
    $upload = wp_handle_sideload($file, ['test_form'=>false,'mimes'=>['png'=>'image/png']]); tmu_check(!isset($upload['error']), 'Move exact fixture into public uploads.');
    $GLOBALS['tmu_public'][] = ['path'=>$upload['file'],'sha256'=>$encoded['sha256']];
    return [$upload,$encoded,wp_generate_uuid4()];
}
function tmu_release($guard) { wuai_sluyu_unlock($guard['post_lock']); wuai_sluyu_topic_media_leave($guard); }
function tmu_fence_exists($guard) { global $wpdb; return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", $guard['name'])) === $guard['token']; }

$prefix = 'qa_raw_upload_' . substr(bin2hex(random_bytes(5)), 0, 10) . '_'; $users = []; $posts = []; $tmu_guards = []; $tmu_filters = []; $tmu_public = []; $tmu_temp = []; $intents = []; $frozen_arms = []; $source = ''; $old_uid = get_current_user_id();
$mail = static function () { return true; }; add_filter('pre_wp_mail', $mail, PHP_INT_MAX);
try {
    foreach (['insert','intent','normal','cancel','wired'] as $case) {
        $uid = wp_create_user($prefix . $case, 'qa-local-only', $prefix . $case . '@example.test'); tmu_check(!is_wp_error($uid), 'Create isolated raw-upload account.');
        $users[$case] = (int) $uid; (new WP_User($uid))->set_role('subscriber'); wp_set_current_user($uid);
        $state = wuai_sluyu_topic_media_operation('create', ['group'=>'0','request'=>wp_generate_uuid4(),'confirm_public'=>'1']); tmu_check(is_array($state), 'Create isolated image topic.'); $posts[$case] = $state['id'];
    }
    $source = wp_tempnam('qa-raw-image'); $canvas = imagecreatetruecolor(24, 24); imagefill($canvas, 0, 0, imagecolorallocate($canvas, 50, 150, 190)); imagepng($canvas, $source); imagedestroy($canvas);

    $uid = $users['insert']; $post_id = $posts['insert']; wp_set_current_user($uid); $guard = tmu_guard($uid, $post_id);
    [$upload,$encoded,$request] = tmu_upload($guard, $source);
    $record = wuai_sluyu_topic_media_upload_record($upload, $encoded, $uid, $post_id, $request, $guard); tmu_check(is_array($record), 'Persist raw intent before attachment insertion.'); $intents[$record['name']] = $record['raw'];
    tmu_check(!wuai_sluyu_topic_media_upload_release_allowed($guard), 'Public move remains armed until bound or rolled back.');
    tmu_error(wuai_sluyu_topic_media_upload_finish($record, $guard), 'remaining', 'Existing public file cannot be mistaken for finished rollback.');
    $deny_insert = static function ($sql) { global $wpdb; return preg_match('/^INSERT\s+INTO\s+`?' . preg_quote($wpdb->posts, '/') . '`?\s/i', $sql) && strpos($sql, 'topic-public-') !== false ? 'SELECT 0 /* QA attachment insert denied */' : $sql; };
    tmu_filter('query', $deny_insert, PHP_INT_MAX);
    $GLOBALS['wuai_topic_media_creating'] = true;
    try { $attachment = wp_insert_attachment(['post_author'=>$uid,'post_parent'=>$post_id,'post_status'=>'inherit','post_name'=>pathinfo($upload['file'], PATHINFO_FILENAME),'post_title'=>'QA raw insertion failure','post_mime_type'=>'image/png'], $upload['file'], $post_id, true); }
    finally { unset($GLOBALS['wuai_topic_media_creating']); remove_filter('query', $deny_insert, PHP_INT_MAX); }
    tmu_check(is_wp_error($attachment) || !$attachment, 'Inject attachment INSERT failure after the public file moved.');
    $veto = static function ($file) use ($upload) { return wp_normalize_path($file) === wp_normalize_path($upload['file']) ? '' : $file; }; tmu_filter('wp_delete_file', $veto, 10);
    tmu_error(wuai_sluyu_topic_media_upload_rollback($record, $guard), 'rollback', 'Failed insertion plus file veto keeps raw intent and account fence.'); remove_filter('wp_delete_file', $veto, 10);
    tmu_release($guard);
    tmu_check(tmu_fence_exists($guard) && is_file($upload['file']) && is_array(wuai_sluyu_topic_media_upload_read($record['name'])), 'Normal finally leaves durable fence after unknown public-file outcome.');
    tmu_check(is_wp_error(wuai_sluyu_topic_media_enter($uid)), 'A subsequent upload cannot bypass the retained durable account fence.');
    $frozen_arms += $GLOBALS['wuai_topic_media_upload_arms']; $GLOBALS['wuai_topic_media_upload_arms'] = [];

    $uid = $users['intent']; $post_id = $posts['intent']; wp_set_current_user($uid); $guard = tmu_guard($uid, $post_id);
    [$upload,$encoded,$request] = tmu_upload($guard, $source);
    $deny_intent = static function ($sql) { return stripos($sql, 'INSERT IGNORE INTO ') === 0 && strpos($sql, 'wuai_topic_media_raw_') !== false ? 'SELECT 0 /* QA raw intent insert denied */' : $sql; }; tmu_filter('query', $deny_intent, PHP_INT_MAX);
    tmu_error(wuai_sluyu_topic_media_upload_record($upload, $encoded, $uid, $post_id, $request, $guard), 'persist', 'Failed durable raw intent freezes instead of guessing file deletion.'); remove_filter('query', $deny_intent, PHP_INT_MAX);
    tmu_error(wuai_sluyu_topic_media_upload_cancel($guard, ['error'=>'simulated failure']), 'unknown', 'A post-move error cannot disarm the raw-upload safety fence.'); tmu_release($guard);
    tmu_check(tmu_fence_exists($guard) && is_file($upload['file']) && !wuai_sluyu_topic_media_upload_release_allowed($guard), 'Intent write failure leaves the file and durable lock for explicit administrator inspection.');
    $frozen_arms += $GLOBALS['wuai_topic_media_upload_arms']; $GLOBALS['wuai_topic_media_upload_arms'] = [];

    $uid = $users['normal']; $post_id = $posts['normal']; wp_set_current_user($uid); $guard = tmu_guard($uid, $post_id);
    // Separate requests are simulated only after observing the previous freezes.
    $held_arms = $GLOBALS['wuai_topic_media_upload_arms']; $GLOBALS['wuai_topic_media_upload_arms'] = [];
    try {
        [$upload,$encoded,$request] = tmu_upload($guard, $source);
        $record = wuai_sluyu_topic_media_upload_record($upload, $encoded, $uid, $post_id, $request, $guard); tmu_check(is_array($record), 'Persist independent raw intent.'); $intents[$record['name']] = $record['raw'];
        $redirect = static function ($file) use ($source) { return $source; }; tmu_filter('wp_delete_file', $redirect, 10);
        tmu_error(wuai_sluyu_topic_media_upload_rollback($record, $guard), 'rollback', 'A delete filter cannot redirect unlink to a different local file.'); remove_filter('wp_delete_file', $redirect, 10);
        tmu_check(is_file($source) && is_file($upload['file']), 'Redirect rejection preserves both files.');
        $bytes = file_get_contents($upload['file']); file_put_contents($upload['file'], 'replacement');
        try { tmu_error(wuai_sluyu_topic_media_upload_rollback($record, $guard), 'file', 'File identity replacement blocks rollback.'); } finally { file_put_contents($upload['file'], $bytes); }
        tmu_check(wuai_sluyu_topic_media_upload_rollback($record, $guard) === true && !is_file($upload['file']), 'Confirmed exact unreferenced rollback retires intent.');
        tmu_check(wuai_sluyu_topic_media_upload_release_allowed($guard), 'Successful rollback disarms only its matching account token.'); tmu_release($guard); tmu_check(!tmu_fence_exists($guard), 'Successful rollback permits normal durable release.');
    } finally { $GLOBALS['wuai_topic_media_upload_arms'] += $held_arms; }

    $uid = $users['cancel']; $post_id = $posts['cancel']; wp_set_current_user($uid); $guard = tmu_guard($uid, $post_id);
    $held_arms = $GLOBALS['wuai_topic_media_upload_arms']; $GLOBALS['wuai_topic_media_upload_arms'] = [];
    try {
        tmu_check(wuai_sluyu_topic_media_upload_arm($guard) === true, 'Arm pre-move error case.');
        tmu_check(wuai_sluyu_topic_media_upload_cancel($guard, ['error'=>'Fixture rejected before moving']) === true && wuai_sluyu_topic_media_upload_release_allowed($guard), 'Only confirmed pre-move error explicitly cancels protection.'); tmu_release($guard);
    } finally { $GLOBALS['wuai_topic_media_upload_arms'] += $held_arms; }
    $guard = tmu_guard($uid, $post_id);
    [$upload,$encoded,$request] = tmu_upload($guard, $source);
    $record = wuai_sluyu_topic_media_upload_record($upload, $encoded, $uid, $post_id, $request, $guard); tmu_check(is_array($record), 'Persist raw intent for verified attachment handoff.'); $intents[$record['name']] = $record['raw'];
    $v = $record['value']; $marker = ['schema'=>1,'purpose'=>'topic-public','owner'=>$uid,'post'=>$post_id,'request'=>$request,'file'=>$v['file'],'mime'=>$v['mime'],'width'=>$v['width'],'height'=>$v['height'],'sha256'=>$v['sha256']];
    $GLOBALS['wuai_topic_media_creating'] = true;
    try { $attachment = wp_insert_attachment(['post_author'=>$uid,'post_parent'=>$post_id,'post_status'=>'inherit','post_name'=>pathinfo($upload['file'], PATHINFO_FILENAME),'post_title'=>'QA verified raw binding','post_mime_type'=>$v['mime'],'meta_input'=>[wuai_sluyu_topic_media_marker()=>$marker]], $upload['file'], $post_id, true); }
    finally { unset($GLOBALS['wuai_topic_media_creating']); }
    tmu_check(is_int($attachment) && $attachment > 0, 'Create exact dedicated attachment.');
    $GLOBALS['wuai_topic_media_write_attachment'] = $attachment;
    try { wp_update_attachment_metadata($attachment, ['width'=>$v['width'],'height'=>$v['height'],'file'=>$v['file'],'sizes'=>[],'image_meta'=>[]]); }
    finally { unset($GLOBALS['wuai_topic_media_write_attachment']); }
    tmu_error(wuai_sluyu_topic_media_upload_rollback($record, $guard), 'referenced', 'Raw rollback never deletes a file already adopted by an attachment.');
    tmu_check(wuai_sluyu_topic_media_upload_finish($record, $guard, $attachment) === true && wuai_sluyu_topic_media_upload_release_allowed($guard), 'Verified dedicated attachment retires raw intent before later list handling.');
    tmu_release($guard); tmu_check(!tmu_fence_exists($guard), 'Verified handoff permits durable account release.');

    $uid = $users['wired']; $post_id = $posts['wired']; wp_set_current_user($uid); $guard = tmu_guard($uid, $post_id);
    $encoded = wuai_sluyu_topic_media_reencode($source, 'wired.png'); tmu_check(is_array($encoded), 'Encode integrated commit fixture.'); $tmu_temp[] = $encoded['path']; $request = wp_generate_uuid4();
    $row = wuai_sluyu_topic_media_permission($post_id, $uid); tmu_check(is_array($row), 'Read integrated commit draft snapshot.');
    $seen_path = ''; $capture_upload = static function ($upload) use (&$seen_path, $encoded) {
        if (is_array($upload) && isset($upload['file'])) { $seen_path = $upload['file']; $GLOBALS['tmu_public'][] = ['path'=>$seen_path,'sha256'=>$encoded['sha256']]; }
        return $upload;
    };
    $wired_veto = static function ($file) use (&$seen_path) { return $seen_path !== '' && wp_normalize_path($file) === wp_normalize_path($seen_path) ? '' : $file; };
    tmu_filter('wp_handle_upload', $capture_upload, PHP_INT_MAX); tmu_filter('query', $deny_insert, PHP_INT_MAX); tmu_filter('wp_delete_file', $wired_veto, 10);
    try { $result = wuai_sluyu_topic_media_commit($post_id, $uid, $row, $request, $encoded, $guard); }
    finally { remove_filter('wp_handle_upload', $capture_upload, PHP_INT_MAX); remove_filter('query', $deny_insert, PHP_INT_MAX); remove_filter('wp_delete_file', $wired_veto, 10); }
    tmu_check(is_wp_error($result) && $seen_path !== '' && is_file($seen_path), 'Actual topic_media_commit reaches attachment INSERT failure and vetoed raw-file rollback.');
    $record = wuai_sluyu_topic_media_upload_read('wuai_topic_media_raw_' . $uid . '_' . $post_id . '_' . $request);
    tmu_check(is_array($record) && !wuai_sluyu_topic_media_upload_release_allowed($guard), 'Actual commit persisted intent and kept its release protection armed.'); $intents[$record['name']] = $record['raw'];
    tmu_check(wuai_sluyu_topic_media_state($post_id)['raw'] === $row['raw'], 'Failed integrated commit did not append an unbound image to the authoritative list.');
    tmu_release($guard); tmu_check(tmu_fence_exists($guard) && is_wp_error(wuai_sluyu_topic_media_enter($uid)), 'Actual commit plus ordinary leave freezes subsequent uploads after rollback failure.');
    $frozen_arms += $GLOBALS['wuai_topic_media_upload_arms']; $GLOBALS['wuai_topic_media_upload_arms'] = [];
    echo "PASS: raw-upload helpers and actual commit wiring, attachment INSERT plus rollback veto, intent INSERT failure, retained account fences, no redirected unlink, exact file identity, verified attachment handoff/rollback and pre-move cancellation.\n";
} finally {
    foreach ($tmu_filters as $filter) { remove_filter($filter[0], $filter[1], $filter[2]); }
    // Local-only stopped-worker cleanup: exact fixture paths/hashes, never an API
    // recovery shortcut. Failed identity checks intentionally leave evidence.
    foreach ($tmu_public as $file) {
        clearstatcache(true, $file['path']);
        if (is_file($file['path']) && !is_link($file['path']) && hash_file('sha256', $file['path']) === $file['sha256']) { @unlink($file['path']); }
    }
    global $wpdb;
    foreach ($intents as $name=>$raw) { $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND HEX(option_value)=HEX(%s)", $name, $raw)); }
    foreach ($tmu_guards as $guard) {
        unset($GLOBALS['wuai_topic_media_upload_arms'][$guard['name']]);
        wuai_sluyu_unlock($guard['post_lock']); wuai_sluyu_topic_media_leave($guard);
    }
    foreach ($posts as $id) { if (get_post($id)) { wp_delete_post($id, true); } }
    foreach ($users as $case=>$uid) { $user = get_userdata($uid); if ($user && $user->user_login === $prefix . $case) { wp_delete_user($uid); } }
    foreach ($tmu_temp as $file) { if (is_file($file)) { wp_delete_file($file); } }
    if ($source && is_file($source)) { wp_delete_file($source); }
    remove_filter('pre_wp_mail', $mail, PHP_INT_MAX); wp_set_current_user($old_uid);
}
