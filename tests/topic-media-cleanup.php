<?php
// Serial, disposable CLI fixture only. Do not install this file in WordPress.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); $resolved = $root ? realpath($root) : false;
$sqlite = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site'); $mysql = realpath(dirname(__DIR__) . '/dist/checks-mysql/site');
$is_mysql = $resolved && $mysql && strcasecmp($resolved, $mysql) === 0 && getenv('WP_QA_ISOLATED_MYSQL') === '1';
if (!$resolved || !(($sqlite && strcasecmp($resolved, $sqlite) === 0) || $is_mysql) || !is_file($root . '/wp-load.php')) { throw new RuntimeException('Explicit disposable WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_topic_media_cleanup_delete') || !extension_loaded('gd')) { throw new RuntimeException('Integrated cleanup module and local PHP GD required.'); }
if ($is_mysql && (DB_HOST !== '127.0.0.1:3373' || DB_NAME !== 'wuai_isolated_qa' || $wpdb->prefix !== 'qa_' || !defined('WUAI_QA_NO_EXTERNAL_EFFECTS') || !WUAI_QA_NO_EXTERNAL_EFFECTS)) { throw new RuntimeException('Independent MariaDB safety boundary mismatch.'); }

function tmc_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function tmc_error($result, $code, $message) { tmc_check(is_wp_error($result) && $result->get_error_code() === 'topic_media_cleanup_' . $code, $message); }
class TMC_Die extends RuntimeException {}
function tmc_die_handler($message, $title = '', $args = []) { throw new TMC_Die(is_wp_error($message) ? $message->get_error_message() : (string) $message); }
function tmc_filter($hook, $callback, $priority = 10, $args = 1) { global $tmc_filters; add_filter($hook, $callback, $priority, $args); $tmc_filters[] = [$hook,$callback,$priority]; }
function tmc_unfilter($hook, $callback, $priority = 10) { remove_filter($hook, $callback, $priority); }
function tmc_add($state, $source) {
    $uid = get_current_user_id(); $guard = wuai_sluyu_topic_media_enter($uid); tmc_check(!is_wp_error($guard), 'Acquire author media guard.');
    $encoded = null; $lock = null;
    try {
        $lock = wuai_sluyu_lock('poll_' . $state['id']); tmc_check((bool) $lock, 'Acquire author topic guard.');
        $guard['post_id'] = $state['id']; $guard['post_lock'] = $lock;
        $row = wuai_sluyu_topic_media_permission($state['id'], $uid); tmc_check(!is_wp_error($row), 'Fixture author owns draft.');
        $encoded = wuai_sluyu_topic_media_reencode($source, 'fixture.png'); tmc_check(is_array($encoded), 'Re-encode local fixture.');
        return wuai_sluyu_topic_media_commit($state['id'], $uid, $row, wp_generate_uuid4(), $encoded, $guard);
    } finally { if (is_array($encoded) && is_file($encoded['path'])) { wp_delete_file($encoded['path']); } if ($lock) { wuai_sluyu_unlock($lock); } wuai_sluyu_topic_media_leave($guard); }
}
function tmc_fixture($uid) {
    global $tmc_posts, $tmc_source, $tmc_images;
    wp_set_current_user($uid);
    $state = wuai_sluyu_topic_media_operation('create', ['group'=>'0','request'=>wp_generate_uuid4(),'confirm_public'=>'1']); tmc_check(is_array($state), 'Create unique public-image fixture.');
    $tmc_posts[] = $state['id']; $state = tmc_add($state, $tmc_source); tmc_check(is_array($state), 'Commit fixture image.');
    $id = $state['items'][0]['id']; $image = wuai_sluyu_topic_media_attachment($id, $state['id'], $uid); tmc_check(is_array($image), 'Read exact fixture image.');
    $tmc_images[] = [$id,$state['id'],$uid];
    return [$state,$image];
}
function tmc_drop_topic($id) { if (get_post($id)) { tmc_check((bool) wp_delete_post($id, true), 'Clean completed fixture topic.'); } }
function tmc_count($uid, $expected, $message) { tmc_check(wuai_sluyu_topic_media_cleanup_retained_count($uid) === $expected, $message); }
function tmc_option_insert($name, $raw) { global $wpdb, $tmc_options; tmc_check($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name,option_value,autoload) VALUES (%s,%s,'no')", $name, $raw)) === 1, 'Insert isolated fixture option.'); $tmc_options[$name] = $raw; }
function tmc_option_delete($name, $raw) { global $wpdb, $tmc_options; $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND HEX(option_value)=HEX(%s)", $name, $raw)); unset($tmc_options[$name]); }
function tmc_user_fixture_release($uid) {
    global $users;
    tmc_check(in_array((int) $uid, $users, true), 'Only release a stopped local fixture account.');
    $handle = $GLOBALS['wuai_topic_media_cleanup_users'][$uid] ?? null;
    if ($handle) {
        global $wpdb; $active = $GLOBALS['wuai_topic_media_cleanup_accounts'][$uid] ?? null;
        tmc_check($active && $active['depth'] === 1 && $active['guard']['token'] === $handle['token'], 'Stopped fixture owns the exact cleanup fence.');
        tmc_check($wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", $active['guard']['name'])) === $handle['token'], 'Stopped fixture durable token still matches before maintenance release.');
        unset($GLOBALS['wuai_topic_media_cleanup_users'][$uid]); wuai_sluyu_topic_media_cleanup_leave($handle);
    }
    $economy = $GLOBALS['wuai_sluyu_user_deletion_economy'][$uid] ?? null;
    if ($economy && wuai_sluyu_economy_current_token() === $economy['token']) { wuai_sluyu_economy_leave($economy); }
    unset($GLOBALS['wuai_sluyu_user_deletion_economy'][$uid]);
    if (isset($GLOBALS['wuai_sluyu_user_deletion_locks'][$uid])) { wuai_sluyu_unlock($GLOBALS['wuai_sluyu_user_deletion_locks'][$uid]); unset($GLOBALS['wuai_sluyu_user_deletion_locks'][$uid]); }
}

$prefix = 'qa_topic_cleanup_' . substr(bin2hex(random_bytes(6)), 0, 10) . '_';
$users = []; $tmc_posts = []; $tmc_images = []; $tmc_filters = []; $tmc_options = []; $tmc_source = ''; $old_uid = get_current_user_id();
$mail = static function () { return true; }; add_filter('pre_wp_mail', $mail, PHP_INT_MAX);
$die = static function () { return 'tmc_die_handler'; }; add_filter('wp_die_handler', $die, PHP_INT_MAX);
try {
    foreach (['owner','other','admin','erase'] as $name) {
        $uid = wp_create_user($prefix . $name, 'qa-local-only', $prefix . $name . '@example.test'); tmc_check(!is_wp_error($uid), 'Create isolated cleanup account.');
        $users[$name] = (int) $uid; (new WP_User($uid))->set_role($name === 'admin' ? 'administrator' : 'subscriber');
    }
    $uid = $users['owner'];
    $tmc_source = wp_tempnam('qa-cleanup-source'); $canvas = imagecreatetruecolor(20, 20); imagefill($canvas, 0, 0, imagecolorallocate($canvas, 50, 140, 190)); imagepng($canvas, $tmc_source); imagedestroy($canvas);
    [$state,$image] = tmc_fixture($uid); $id = $image['id']; $post_id = $state['id'];
    tmc_count($uid, 1, 'One retained dedicated image.');
    tmc_check(wuai_sluyu_topic_media_cleanup_read($uid, $post_id, $id) === false, 'Uploads do not create deletion intent.');

    $guard = wuai_sluyu_topic_media_enter($uid); tmc_check(is_array($guard), 'Acquire author fence for reference test.'); $poll = null;
    try {
        $poll = wuai_sluyu_lock('poll_' . $post_id); tmc_check((bool) $poll, 'Acquire author poll reference test.'); $guard['post_id'] = $post_id; $guard['post_lock'] = $poll;
        tmc_error(wuai_sluyu_topic_media_cleanup_delete($id, $post_id, $uid, $guard), 'referenced', 'Author cannot delete an image still in the authoritative list.');
        tmc_check(get_post($id) && is_file($image['path']), 'Rejected author delete preserves record and file.');
    } finally { if ($poll) { wuai_sluyu_unlock($poll); } wuai_sluyu_topic_media_leave($guard); }

    $ordinary = wp_insert_attachment(['post_author'=>$uid,'post_status'=>'inherit','post_title'=>'Ordinary media','post_mime_type'=>'image/png']); tmc_check((bool) $ordinary, 'Create ordinary attachment.'); $tmc_posts[] = $ordinary;
    tmc_check(is_wp_error(wuai_sluyu_topic_media_cleanup_delete($ordinary, $post_id, $uid)) && get_post($ordinary), 'Cleanup helper never adopts ordinary media.');
    tmc_check((bool) wp_delete_attachment($ordinary, true), 'Native ordinary attachment deletion remains unchanged.');
    tmc_check(is_wp_error(wuai_sluyu_topic_media_cleanup_delete($id, $post_id, $users['other'])) && get_post($id), 'Foreign owner cannot redirect cleanup.');

    $name = wuai_sluyu_topic_media_cleanup_name($uid, $post_id, $id);
    $deny_intent = static function ($sql) use ($name) { return stripos($sql, 'INSERT IGNORE INTO ') === 0 && strpos($sql, $name) !== false ? 'SELECT 0 /* QA refused intent */' : $sql; };
    tmc_filter('query', $deny_intent, PHP_INT_MAX);
    tmc_error(wuai_sluyu_topic_media_cleanup_delete($id, $post_id, $uid), 'persist', 'Intent must persist and read back before any deletion.');
    tmc_unfilter('query', $deny_intent, PHP_INT_MAX);
    tmc_check(get_post($id) && is_file($image['path']), 'Failed intent persistence preserves database and file.');

    $veto_file = static function ($file) use ($image) { return wp_normalize_path($file) === wp_normalize_path($image['path']) ? '' : $file; };
    tmc_filter('wp_delete_file', $veto_file, 10);
    $seen_intent = false;
    $before_db = static function ($deleted_id) use ($id, $uid, $post_id, &$seen_intent) { if ((int) $deleted_id === $id) { $seen_intent = is_array(wuai_sluyu_topic_media_cleanup_read($uid, $post_id, $id)); } };
    tmc_filter('delete_attachment', $before_db, 0);
    tmc_check(wp_delete_attachment($id, true) === false, 'Native attachment deletion reports real file failure.');
    tmc_unfilter('delete_attachment', $before_db, 0);
    clean_post_cache($id);
    tmc_check($seen_intent && !get_post($id) && is_file($image['path']), 'Intent precedes core deletion and survives database-row loss plus file veto.');
    $record = wuai_sluyu_topic_media_cleanup_read($uid, $post_id, $id); tmc_check(is_array($record), 'Orphan file retains exact owner/path/hash tombstone.');
    tmc_count($uid, 1, 'Lost attachment row still consumes retained quota.');
    tmc_check(wp_delete_post($post_id, true) === false && get_post($post_id), 'Parent deletion blocks while orphan file cleanup is incomplete.');
    tmc_unfilter('wp_delete_file', $veto_file, 10);

    $original_bytes = file_get_contents($image['path']); file_put_contents($image['path'], 'changed bytes');
    try { tmc_error(wuai_sluyu_topic_media_cleanup_delete($id, $post_id, $uid), 'file', 'Retry refuses a replaced file even after attachment-row loss.'); }
    finally { file_put_contents($image['path'], $original_bytes); }
    $bad = $record['value']; $bad['marker']['file'] = '../../ordinary.png';
    tmc_error(wuai_sluyu_topic_media_cleanup_decode($record['name'], serialize($bad)), 'record', 'Traversal cannot become a retry path.');
    $bad = $record['value']; $bad['base'] .= '/different-root';
    tmc_error(wuai_sluyu_topic_media_cleanup_path(['value'=>$bad]), 'path', 'Root changes cannot redirect a tombstone.');
    tmc_check(wuai_sluyu_topic_media_cleanup_scope($uid, $post_id) === true, 'Scope retries a missing attachment row using only its durable intent.');
    tmc_check(!is_file($image['path']) && wuai_sluyu_topic_media_cleanup_read($uid, $post_id, $id) === false, 'Intent retires only after absent database row and file.');
    tmc_count($uid, 0, 'Confirmed retry releases retained quota.'); tmc_drop_topic($post_id);

    [$state,$image] = tmc_fixture($uid); $id = $image['id']; $post_id = $state['id'];
    $deny_row = static function ($sql) use ($id) { global $wpdb; return preg_match('/^DELETE\s+FROM\s+`?' . preg_quote($wpdb->posts, '/') . '`?\s+WHERE\s+`?ID`?\s*=\s*[\'"]?' . $id . '[\'"]?(?:\s|$)/i', $sql) ? $sql . ' AND 0=1' : $sql; };
    tmc_filter('query', $deny_row, PHP_INT_MAX - 1);
    tmc_error(wuai_sluyu_topic_media_cleanup_delete($id, $post_id, $uid), 'delete', 'Final core attachment-row deletion failure is retryable.');
    tmc_unfilter('query', $deny_row, PHP_INT_MAX - 1);
    clean_post_cache($id); tmc_check(get_post($id) && is_file($image['path']) && !metadata_exists('post', $id, wuai_sluyu_topic_media_marker()), 'Core may remove metadata but durable intent and public file survive.');
    tmc_count($uid, 1, 'A live row and its tombstone are counted once.');
    tmc_check(wuai_sluyu_topic_media_cleanup_delete($id, $post_id, $uid) === true, 'Retry tolerates only missing metadata from failed core deletion.'); tmc_drop_topic($post_id);

    [$state,$image] = tmc_fixture($uid); $id = $image['id']; $post_id = $state['id'];
    $veto_attachment = static function ($check, $post) use ($id) { return (int) $post->ID === $id ? false : $check; };
    tmc_filter('pre_delete_attachment', $veto_attachment, 5, 2);
    tmc_check(wp_delete_post($post_id, true) === false, 'Native topic deletion stops if attachment deletion is vetoed.');
    clean_post_cache($id); tmc_check(get_post($post_id) && (int) get_post_field('post_parent', $id) === $post_id && is_file($image['path']), 'Blocked topic deletion never reparents surviving attachment.');
    tmc_unfilter('pre_delete_attachment', $veto_attachment, 5);
    tmc_check((bool) wp_delete_post($post_id, true) && !get_post($id) && !is_file($image['path']), 'Native parent retry completes dedicated cleanup.');

    [$state,$image] = tmc_fixture($uid); $id = $image['id']; $post_id = $state['id'];
    $native_name = 'wuai_topic_media_native_' . $post_id; $native_token = wp_generate_uuid4(); tmc_option_insert($native_name, $native_token);
    tmc_error(wuai_sluyu_topic_media_cleanup_scope($uid, $post_id), 'fenced', 'Cleanup refuses an unfinished native topic save.');
    tmc_check(wp_delete_post($post_id, true) === false && get_post($id), 'Native deletion also respects the native fence.'); tmc_option_delete($native_name, $native_token);
    $media = wuai_sluyu_topic_media_enter($uid); tmc_check(is_array($media), 'Hold unrelated account media worker.');
    try { tmc_check(wp_delete_attachment($id, true) === false && get_post($id), 'Native deletion cannot steal another account media fence.'); }
    finally { wuai_sluyu_topic_media_leave($media); }

    $intent = wuai_sluyu_topic_media_cleanup_name($uid, $post_id, $id); $original = null;
    $replace_intent = static function ($file) use ($image, $uid, $post_id, $id, $intent, &$original) {
        if ($original === null && empty($GLOBALS['wuai_topic_media_cleanup_core'][$id]) && wp_normalize_path($file) === wp_normalize_path($image['path']) && !get_post($id)) {
            $original = wuai_sluyu_topic_media_cleanup_read($uid, $post_id, $id); global $wpdb;
            $changed = $original['value']; $changed['created']++;
            $wpdb->query($wpdb->prepare("UPDATE $wpdb->options SET option_value=%s WHERE option_name=%s", serialize($changed), $intent));
        }
        return $file;
    };
    tmc_filter('wp_delete_file', $replace_intent, 5);
    tmc_error(wuai_sluyu_topic_media_cleanup_delete($id, $post_id, $uid), 'changed', 'Intent replacement during cleanup fences unlink and retirement.');
    tmc_unfilter('wp_delete_file', $replace_intent, 5);
    tmc_check(is_file($image['path']) && is_array($original), 'Stale cleanup worker preserves the file.');
    global $wpdb; $wpdb->query($wpdb->prepare("UPDATE $wpdb->options SET option_value=%s WHERE option_name=%s", $original['raw'], $intent));
    tmc_check(wuai_sluyu_topic_media_cleanup_scope($uid, $post_id) === true, 'Verified intent can subsequently retry.'); tmc_drop_topic($post_id);

    [$state,$image] = tmc_fixture($uid); $id = $image['id']; $post_id = $state['id'];
    $keep_intent = static function ($sql) use ($uid, $post_id, $id) {
        global $wpdb; $name = wuai_sluyu_topic_media_cleanup_name($uid, $post_id, $id);
        return preg_match('/^DELETE\s+FROM\s+`?' . preg_quote($wpdb->options, '/') . '`?\s/i', $sql) && strpos($sql, $name) !== false ? $sql . ' AND 0=1' : $sql;
    };
    tmc_filter('query', $keep_intent, PHP_INT_MAX);
    tmc_error(wuai_sluyu_topic_media_cleanup_delete($id, $post_id, $uid), 'finish', 'Failed intent retirement is not reported as completed cleanup.');
    tmc_unfilter('query', $keep_intent, PHP_INT_MAX);
    tmc_check(!get_post($id) && !is_file($image['path']) && is_array(wuai_sluyu_topic_media_cleanup_read($uid, $post_id, $id)), 'Completed file deletion can safely retain its durable intent after database failure.');
    tmc_count($uid, 1, 'Unretired intent conservatively consumes quota.');
    tmc_check(wuai_sluyu_topic_media_cleanup_scope($uid, $post_id) === true, 'Retry safely retires an already-absent file and attachment.'); tmc_drop_topic($post_id);

    [$state,$image] = tmc_fixture($users['erase']); $id = $image['id']; $post_id = $state['id']; wp_set_current_user($users['admin']);
    $erase_uid = $users['erase']; $before_bundle = wuai_sluyu_topic_media_state($post_id)['raw']; $before_post = get_post($post_id, ARRAY_A); $before_image = get_post($id, ARRAY_A); $before_marker = wuai_sluyu_topic_media_raw($id, wuai_sluyu_topic_media_marker())['raw'];
    $unchanged_user_media = static function ($label) use ($erase_uid, $post_id, $id, $image, $before_bundle, $before_post, $before_image, $before_marker) {
        global $wpdb; clean_post_cache($post_id); clean_post_cache($id); clean_user_cache($erase_uid);
        tmc_check(get_userdata($erase_uid) && get_post($post_id, ARRAY_A) === $before_post && get_post($id, ARRAY_A) === $before_image && wuai_sluyu_topic_media_state($post_id)['raw'] === $before_bundle && wuai_sluyu_topic_media_raw($id, wuai_sluyu_topic_media_marker())['raw'] === $before_marker && is_file($image['path']) && hash_file('sha256', $image['path']) === $image['sha256'], $label . ': account/topic/list/attachment/file remain unchanged.');
        tmc_check(wuai_sluyu_topic_media_cleanup_read($erase_uid, $post_id, $id) === false && $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $wpdb->options WHERE option_name=%s", 'wuai_topic_media_lock_' . $erase_uid)) === null, $label . ': no cleanup intent or durable media fence created before economic authorization.');
    };
    tmc_error(wuai_sluyu_topic_media_cleanup_user_enter($erase_uid), 'authorization', 'Media deletion cannot borrow an unverified or absent community user/economy guard.');
    $unchanged_user_media('Missing community authorization');
    $economy_holder = wuai_sluyu_economy_enter('qa_cleanup_foreign_busy'); tmc_check(is_array($economy_holder) && $economy_holder['owner'], 'Hold disposable global economic mutex.');
    unset($GLOBALS['wuai_sluyu_economy_guard']); $stopped = false;
    try { try { wp_delete_user($erase_uid); } catch (TMC_Die $error) { $stopped = true; } tmc_check($stopped, 'Economic busy blocks account deletion.'); $unchanged_user_media('Economic busy'); }
    finally { tmc_user_fixture_release($erase_uid); $GLOBALS['wuai_sluyu_economy_guard'] = $economy_holder; wuai_sluyu_economy_leave($economy_holder); }
    $bounty_injected = false;
    $deny_bounty_preflight = static function ($sql) use ($erase_uid, &$bounty_injected) {
        if (!$bounty_injected && strpos($sql, 'SELECT question_id FROM ' . wuai_sluyu_table('bounties')) === 0 && strpos($sql, 'requester_id=' . $erase_uid . ' AND') !== false) { $bounty_injected = true; return 'SELECT question_id FROM ' . wuai_sluyu_table('bounties') . ' WHERE qa_cleanup_missing_preflight_column=1'; }
        return $sql;
    };
    tmc_filter('query', $deny_bounty_preflight, PHP_INT_MAX); $stopped = false; $show_errors = $wpdb->suppress_errors(true);
    try { try { wp_delete_user($erase_uid); } catch (TMC_Die $error) { $stopped = true; } tmc_check($stopped && $bounty_injected, 'Bounty deletion authorization rejection was exercised.'); $unchanged_user_media('Bounty preflight rejection'); }
    finally { $wpdb->suppress_errors($show_errors); tmc_unfilter('query', $deny_bounty_preflight, PHP_INT_MAX); tmc_user_fixture_release($erase_uid); }
    $veto_file = static function ($file) use ($image) { return wp_normalize_path($file) === wp_normalize_path($image['path']) ? '' : $file; };
    tmc_filter('wp_delete_file', $veto_file, 10); $stopped = false;
    try { wp_delete_user($users['erase']); } catch (TMC_Die $error) { $stopped = true; }
    tmc_check($stopped && get_userdata($users['erase']) && get_post($post_id) && is_file($image['path']), 'Account deletion blocks before core removes owner or topic after file failure.');
    tmc_unfilter('wp_delete_file', $veto_file, 10);
    tmc_check(!empty($GLOBALS['wuai_topic_media_cleanup_users'][$erase_uid]) && !empty($GLOBALS['wuai_topic_media_cleanup_accounts'][$erase_uid]), 'Post-authorization cleanup interruption retains the durable fence until stopped-worker inspection.');
    tmc_user_fixture_release($erase_uid);
    $stopped = false; try { wp_delete_user($users['erase'], $users['other']); } catch (TMC_Die $error) { $stopped = true; }
    tmc_check($stopped && get_userdata($users['erase']) && (int) get_post_field('post_author', $post_id) === $users['erase'], 'Managed topic reassignment is explicitly blocked without changing its owner.');
    tmc_check(wp_delete_user($users['erase']) && !get_userdata($users['erase']) && !is_file($image['path']), 'Account retry cleans orphan tombstone and then completes normal deletion.');

    tmc_count($uid, 0, 'Fixture owner has no retained dedicated files.');
    echo "PASS: durable pre-delete intents, orphan-file retry/quota, core DB failure recovery, exact-path checks, native deletion gates, two-phase user deletion with economy/bounty refusal before destructive cleanup, retained interruption fences, no reparenting and ordinary-media isolation.\n";
} finally {
    foreach ($tmc_filters as $filter) { remove_filter($filter[0], $filter[1], $filter[2]); }
    foreach ($tmc_options as $name=>$raw) { tmc_option_delete($name, $raw); }
    wp_set_current_user($users['admin'] ?? 0);
    foreach ($users as $uid) { tmc_user_fixture_release($uid); }
    foreach ($tmc_images as $entry) {
        [$id,$post_id,$uid] = $entry;
        if (get_post($id) || wuai_sluyu_topic_media_cleanup_read($uid, $post_id, $id)) {
            $result = wuai_sluyu_topic_media_cleanup_delete($id, $post_id, $uid);
            if (is_wp_error($result)) { fwrite(STDERR, 'Fixture cleanup retained for inspection: ' . $id . ' ' . $result->get_error_message() . "\n"); }
        }
    }
    foreach ($tmc_posts as $id) { if (get_post($id)) { try { wp_delete_post($id, true); } catch (TMC_Die $error) { fwrite(STDERR, 'Fixture topic retained: ' . $id . "\n"); } } }
    foreach ($users as $name=>$uid) {
        $user = get_userdata($uid);
        if ($user && $user->user_login === $prefix . $name) { try { wp_delete_user($uid); } catch (TMC_Die $error) { fwrite(STDERR, 'Fixture account retained: ' . $uid . ' ' . $error->getMessage() . "\n"); } }
    }
    if ($tmc_source && is_file($tmc_source)) { wp_delete_file($tmc_source); }
    remove_filter('wp_die_handler', $die, PHP_INT_MAX); remove_filter('pre_wp_mail', $mail, PHP_INT_MAX); wp_set_current_user($old_uid);
}
