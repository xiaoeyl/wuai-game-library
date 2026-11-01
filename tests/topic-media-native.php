<?php
// Serial disposable CLI regression tests. Never install in a live WordPress site.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); $resolved = $root ? realpath($root) : false;
$sqlite = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site'); $mysql = realpath(dirname(__DIR__) . '/dist/checks-mysql/site');
$is_mysql = $resolved && $mysql && strcasecmp($resolved, $mysql) === 0 && getenv('WP_QA_ISOLATED_MYSQL') === '1';
if (!$resolved || !(($sqlite && strcasecmp($resolved, $sqlite) === 0) || $is_mysql) || !is_file($root . '/wp-load.php')) { throw new RuntimeException('Explicit disposable WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_topic_media_native_query')) { throw new RuntimeException('Integrated local native media guard required.'); }
if ($is_mysql && (DB_HOST !== '127.0.0.1:3373' || DB_NAME !== 'wuai_isolated_qa' || $wpdb->prefix !== 'qa_' || !defined('WUAI_QA_NO_EXTERNAL_EFFECTS'))) { throw new RuntimeException('Independent MariaDB safety boundary mismatch.'); }

class TMN_Die extends RuntimeException {}
function tmn_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function tmn_die_handler($message, $title = '', $args = []) { throw new TMN_Die((string) $message); }
function tmn_filter($hook, $callback, $priority = 10, $args = 1) { global $tmn_filters; add_filter($hook, $callback, $priority, $args); $tmn_filters[] = [$hook,$callback,$priority]; }
function tmn_denied($callback, $message) { try { $callback(); } catch (TMN_Die $error) { return; } throw new RuntimeException($message); }
function tmn_fence($id) { global $wpdb; return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", 'wuai_topic_media_native_' . $id)); }
function tmn_fixture_release($id) {
    // Only this single-process test's stopped request may release its exact token.
    global $tmn_posts; tmn_check(in_array((int) $id, $tmn_posts, true), 'Never unlock a non-fixture post.');
    $guard = $GLOBALS['wuai_topic_media_native'][$id] ?? null;
    if (!$guard) { return; }
    tmn_check(tmn_fence($id) === $guard['token'], 'Failed request retains exact durable fence.');
    wuai_sluyu_topic_media_native_end($id); tmn_check(tmn_fence($id) === null, 'Fixture exact-token cleanup.');
}
function tmn_post($id) { clean_post_cache($id); return get_post($id); }
function tmn_author_hold_release($uid) {
    global $wpdb, $users;
    tmn_check(in_array((int) $uid, $users, true), 'Only a stopped fixture account may be maintained.');
    $name = 'wuai_topic_media_lock_' . $uid; $token = $GLOBALS['wuai_topic_media_author_holds'][$name] ?? '';
    if ($token === '') { return; }
    tmn_check($wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", $name)) === $token, 'Author interruption retains its precise account fence.');
    tmn_check($wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND HEX(option_value)=HEX(%s)", $name, $token)) === 1, 'Stopped fixture exact-token maintenance.');
    unset($GLOBALS['wuai_topic_media_author_holds'][$name]);
}
function tmn_author_save($id, $fields) {
    $guard = wuai_sluyu_topic_media_enter(get_current_user_id()); tmn_check(!is_wp_error($guard), 'Author fixture fence acquired.'); $poll = null;
    try {
        $poll = wuai_sluyu_lock('poll_' . $id); tmn_check((bool) $poll, 'Author fixture poll acquired.');
        $guard['post_id'] = $id; $guard['post_lock'] = $poll;
        return wuai_sluyu_topic_media_post_update($id, wuai_sluyu_topic_media_state($id), $guard, $fields);
    } finally { if ($poll) { wuai_sluyu_unlock($poll); } wuai_sluyu_topic_media_leave($guard); }
}

$tmn_filters = []; $tmn_posts = []; $users = []; $comments = []; $old_uid = get_current_user_id(); $checks = 0;
$prefix = 'qa_media_native_' . substr(bin2hex(random_bytes(6)), 0, 10);
tmn_filter('pre_wp_mail', static function () { return true; }, PHP_INT_MAX);
tmn_filter('wp_die_handler', static function () { return 'tmn_die_handler'; }, PHP_INT_MAX);
try {
    $uid = wp_create_user($prefix, 'qa-local-only', $prefix . '@example.test'); tmn_check(!is_wp_error($uid), 'Create unique fixture owner.'); $uid = (int) $uid; $users[] = $uid;
    (new WP_User($uid))->set_role('administrator'); wp_set_current_user($uid);
    $state = wuai_sluyu_topic_media_operation('create', ['group'=>'0','request'=>wp_generate_uuid4(),'confirm_public'=>'1']);
    tmn_check(is_array($state), 'Create dedicated draft.'); $id = (int) $state['id']; $tmn_posts[] = $id;
    $effects = ['transition'=>0,'save'=>0,'meta'=>0];
    tmn_filter('transition_post_status', static function ($new, $old, $post) use ($id, &$effects) { if ((int) $post->ID === $id) { $effects['transition']++; } }, PHP_INT_MAX, 3);
    tmn_filter('save_post', static function ($post_id) use ($id, &$effects) { if ((int) $post_id === $id) { $effects['save']++; } }, PHP_INT_MAX);
    tmn_filter('added_post_meta', static function ($meta_id, $post_id, $key) use ($id, &$effects) { if ((int) $post_id === $id && $key === '_qa_native_should_not_run') { $effects['meta']++; } }, PHP_INT_MAX, 3);
    $unchanged = static function ($before, $message) use (&$effects) { tmn_check($before === $effects, $message); };
    $args = static function ($title) use ($id) { return ['ID'=>$id,'post_title'=>$title,'meta_input'=>['_qa_native_should_not_run'=>wp_generate_uuid4()]]; };

    tmn_check(wp_update_post(['ID'=>$id,'post_title'=>'Native baseline 100%'], true) === $id, 'Native exact SQL accepts literal percent.');
    tmn_check(tmn_fence($id) === null, 'Normal native save releases after core hooks.'); $checks++;
    $before = $effects;
    $invalid = wp_update_post(['ID'=>$id,'post_date'=>'2026-02-31 00:00:00','post_date_gmt'=>'2026-02-31 00:00:00','edit_date'=>true], true);
    tmn_check(is_wp_error($invalid) && $invalid->get_error_code() === 'invalid_date' && tmn_fence($id) === null, 'Core invalid-date rejection does not strand a native save fence.');
    $unchanged($before, 'Invalid date has no core mutation hooks.'); $checks++;
    $empty_veto = static function ($empty, $postarr) use ($id) { return (int) ($postarr['ID'] ?? 0) === $id ? true : $empty; };
    tmn_filter('wp_insert_post_empty_content', $empty_veto, 20, 2);
    $invalid = wp_update_post(['ID'=>$id,'post_title'=>'Must not be saved'], true);
    remove_filter('wp_insert_post_empty_content', $empty_veto, 20);
    tmn_check(is_wp_error($invalid) && $invalid->get_error_code() === 'empty_content' && tmn_fence($id) === null, 'Explicit empty-content veto releases an unstarted save fence.');
    $unchanged($before, 'Empty-content cancellation has no core mutation hooks.'); $checks++;
    wp_update_post(['ID'=>$id,'post_status'=>'publish'], true);
    tmn_check(tmn_post($id)->post_status === 'draft', 'Incomplete gallery is demoted before final validation.'); $checks++;

    $before = $effects;
    $late_status = static function ($data, $postarr) use ($id) { if ((int) ($postarr['ID'] ?? 0) === $id) { $data['post_status'] = 'publish'; } return $data; };
    tmn_filter('wp_insert_post_data', $late_status, PHP_INT_MAX, 2);
    tmn_denied(static function () use ($args) { wp_update_post($args('Forbidden late status'), true); }, 'Late status rewrite must stop core.');
    remove_filter('wp_insert_post_data', $late_status, PHP_INT_MAX); $unchanged($before, 'Late status cannot run transition/save/meta_input.');
    tmn_check(tmn_post($id)->post_status === 'draft', 'Late status rewrite cannot publish.'); tmn_fixture_release($id); $checks++;

    $late_owner = static function ($data, $postarr) use ($id) { if ((int) ($postarr['ID'] ?? 0) === $id) { $data['post_author'] = '1'; $data['post_password'] = 'changed'; } return $data; };
    tmn_filter('wp_insert_post_data', $late_owner, PHP_INT_MAX, 2); $before = $effects;
    tmn_denied(static function () use ($args) { wp_update_post($args('Forbidden late identity'), true); }, 'Final identity must match original.');
    remove_filter('wp_insert_post_data', $late_owner, PHP_INT_MAX); $unchanged($before, 'Late identity has no core effects.'); tmn_fixture_release($id); $checks++;

    foreach (['wp_update_post','wp_insert_post'] as $entry) {
        $injected = false; $before = $effects;
        $advance = static function ($data, $postarr) use ($id, &$injected) {
            if (!$injected && (int) ($postarr['ID'] ?? 0) === $id) {
                $injected = true; global $wpdb;
                $wpdb->query($wpdb->prepare("UPDATE $wpdb->posts SET post_title=%s WHERE ID=%d", 'Intervening authoritative title', $id)); clean_post_cache($id);
            }
            return $data;
        };
        tmn_filter('wp_insert_post_data', $advance, 20, 2);
        $input = wp_slash(get_post($id, ARRAY_A)); $input['post_title'] = 'Stale prepared title'; $input['meta_input'] = ['_qa_native_should_not_run'=>'blocked'];
        tmn_denied(static function () use ($entry, $input) { call_user_func($entry, $input, true); }, 'Stale native data must not use a recaptured snapshot.');
        remove_filter('wp_insert_post_data', $advance, 20); $unchanged($before, 'Stale native write stops all downstream core effects.');
        tmn_check($injected && tmn_post($id)->post_title === 'Intervening authoritative title', 'Intervening data survives stale write.'); tmn_fixture_release($id); $checks++;
        wp_update_post(['ID'=>$id,'post_title'=>'Reset native baseline ' . $entry], true);
    }

    $injected = false; $before = $effects;
    $early_advance = static function ($value) use ($id, &$injected) {
        if (!$injected && !empty($GLOBALS['wuai_topic_media_native'][$id])) { $injected = true; global $wpdb; $wpdb->query($wpdb->prepare("UPDATE $wpdb->posts SET post_content=%s WHERE ID=%d", 'Changed during sanitize_post', $id)); clean_post_cache($id); }
        return $value;
    };
    tmn_filter('pre_post_content', $early_advance, 5);
    tmn_denied(static function () use ($args) { wp_update_post($args('Stale before empty_content'), true); }, 'Early sanitizer race must fail CAS.');
    remove_filter('pre_post_content', $early_advance, 5); $unchanged($before, 'Early sanitizer race does not trigger save hooks.');
    tmn_check($injected && tmn_post($id)->post_content === 'Changed during sanitize_post', 'Pre-empty snapshot was frozen.'); tmn_fixture_release($id); $checks++;

    foreach (['select','comment','predicate'] as $mode) {
        $before = $effects; $injected = false;
        $rewrite = static function ($sql) use ($id, $mode, &$injected) {
            $origin = wuai_sluyu_topic_media_db_origin(); $call = $origin['caller'] ?? [];
            if (!$injected && ($call['function'] ?? '') === 'update' && (int) ($call['args'][2]['ID'] ?? 0) === $id && ($origin['parent']['function'] ?? '') === 'wp_insert_post') {
                $injected = true; return $mode === 'select' ? 'SELECT 0' : ($mode === 'comment' ? '/* unknown SQL plugin */ ' . $sql : $sql . ' AND 0=1');
            }
            return $sql;
        };
        tmn_filter('query', $rewrite, 5);
        tmn_denied(static function () use ($args) { wp_update_post($args('Rewritten core update'), true); }, 'Replaced native SQL must not return core success.');
        remove_filter('query', $rewrite, 5); tmn_check($injected, 'Core target rewrite was exercised.'); $unchanged($before, 'Rewritten SQL does not run transition/save/meta_input.'); tmn_fixture_release($id); $checks++;
    }

    $injected = false; $before = $effects;
    $hold = static function ($sql) use ($id, &$injected) {
        $origin = wuai_sluyu_topic_media_db_origin();
        if (!$injected && ($origin['caller']['function'] ?? '') === 'wuai_sluyu_topic_media_post_cas') { $injected = true; update_post_meta($id, '_wuai_circle_review_hold', 'qa-native-zero-cas'); }
        return $sql;
    };
    tmn_filter('query', $hold, 5);
    tmn_denied(static function () use ($args) { wp_update_post($args('Must fail actual CAS'), true); }, 'Zero CAS must stop core.');
    remove_filter('query', $hold, 5); $unchanged($before, 'Zero CAS stops all core effects.'); tmn_check($injected, 'Late hold was injected.');
    delete_post_meta($id, '_wuai_circle_review_hold'); tmn_fixture_release($id); $checks++;

    $guard = wuai_sluyu_topic_media_enter($uid); tmn_check(!is_wp_error($guard), 'Paused author owns durable account fence.');
    $poll_name = 'wuai_lock_poll_' . $id; $expired = (time() - 1000) . ':' . wp_generate_uuid4();
    $wpdb->query($wpdb->prepare("INSERT INTO $wpdb->options (option_name,option_value,autoload) VALUES (%s,%s,'no')", $poll_name, $expired));
    try { $before = $effects; tmn_denied(static function () use ($args) { wp_update_post($args('Steal expired poll'), true); }, 'Durable author fence must defeat lease expiry.'); $unchanged($before, 'Busy author does not cause native effects.'); tmn_check(tmn_fence($id) === null, 'No native fence acquired over author.'); }
    finally { $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND HEX(option_value)=HEX(%s)", $poll_name, $expired)); wuai_sluyu_topic_media_leave($guard); } $checks++;

    $before = $effects; tmn_denied(static function () use ($id) { wp_publish_post($id); }, 'Direct wp_publish_post must fail closed.');
    $unchanged($before, 'Direct publication cannot invoke core transition/save.'); tmn_check(tmn_post($id)->post_status === 'draft', 'Direct publication leaves draft.'); $checks++;

    // Fixed timestamps make this a real zero-affected-row UPDATE on MySQL.
    $original = tmn_post($id); $freeze = static function ($data, $postarr) use ($id, $original) {
        if ((int) ($postarr['ID'] ?? 0) === $id) { foreach (wuai_sluyu_topic_media_post_columns() as $key) { $data[$key] = wp_slash($original->$key); } } return $data;
    };
    tmn_filter('wp_insert_post_data', $freeze, PHP_INT_MAX, 2); $before = $effects;
    tmn_check(wp_update_post(['ID'=>$id], true) === $id && tmn_fence($id) === null, 'Exact persisted native no-op succeeds.');
    tmn_check($effects['save'] === $before['save'] + 1, 'Exact no-op executes core save once.'); remove_filter('wp_insert_post_data', $freeze, PHP_INT_MAX); $checks++;

    foreach (['rewrite','zero'] as $mode) {
        $injected = false; $before = $effects;
        $fault = static function ($sql) use ($id, $mode, &$injected) {
            $origin = wuai_sluyu_topic_media_db_origin();
            if (!$injected && ($origin['caller']['function'] ?? '') === 'wuai_sluyu_topic_media_post_cas') {
                $injected = true; if ($mode === 'rewrite') { return 'SELECT 1'; } update_post_meta($id, '_wuai_circle_review_hold', 'qa-author-zero-cas');
            }
            return $sql;
        };
        tmn_filter('query', $fault, 5); $result = tmn_author_save($id, ['post_title'=>'Forbidden author CAS ' . $mode]);
        remove_filter('query', $fault, 5); tmn_check($injected && is_wp_error($result), 'Author invalid CAS returns an error.'); $unchanged($before, 'Author invalid CAS stops transition/save/meta effects.');
        delete_post_meta($id, '_wuai_circle_review_hold'); $checks++;
    }

    $comment = wp_insert_comment(['comment_post_ID'=>$id,'comment_author'=>'QA native fixture','comment_content'=>'Native lifecycle comment','comment_approved'=>'1','user_id'=>$uid]); $comments[] = $comment;
    $slug = tmn_post($id)->post_name; $observed = [];
    foreach (['wp_trash_post','trash_post_comments','trashed_post_comments','untrash_post','untrash_post_comments','untrashed_post_comments'] as $hook) {
        tmn_filter($hook, static function ($post_id) use ($id, $hook, &$observed) {
            if ((int) $post_id !== $id) { return; } $observed[] = $hook; tmn_check(tmn_fence($id) !== null, 'Native fence spans ' . $hook);
        }, 5);
    }
    $veto = static function ($check, $post) use ($id) { return (int) $post->ID === $id ? false : $check; };
    tmn_filter('pre_trash_post', $veto, 20, 2);
    tmn_check(wp_trash_post($id) === false && tmn_fence($id) === null && tmn_post($id)->post_status === 'draft' && !metadata_exists('post', $id, '_wp_trash_meta_time'), 'Explicit pre-trash veto precedes fence and every trash mutation.');
    remove_filter('pre_trash_post', $veto, 20); $checks++;
    tmn_check((bool) wp_trash_post($id), 'Native trash completes.');
    tmn_check(tmn_fence($id) === null && tmn_post($id)->post_name === $slug && get_comment($comment)->comment_approved === 'post-trashed', 'Trash finishes comments and keeps dedicated slug.');
    tmn_filter('pre_untrash_post', $veto, 20, 2);
    tmn_check(wp_untrash_post($id) === false && tmn_fence($id) === null && tmn_post($id)->post_status === 'trash' && get_comment($comment)->comment_approved === 'post-trashed', 'Explicit pre-untrash veto leaves existing lifecycle state and no new fence.');
    remove_filter('pre_untrash_post', $veto, 20); $checks++;
    tmn_check((bool) wp_untrash_post($id), 'Native untrash completes.');
    tmn_check(tmn_fence($id) === null && tmn_post($id)->post_status === 'draft' && get_comment($comment)->comment_approved === '1', 'Untrash restores comments before releasing.');
    tmn_check(count($observed) === 6, 'Every outer lifecycle hook was covered.'); $checks++;

    $interrupt = static function ($post_id) use ($id) { if ((int) $post_id === $id) { throw new TMN_Die('qa interrupted comment trash'); } };
    tmn_filter('trash_post_comments', $interrupt, 1);
    tmn_denied(static function () use ($id) { wp_trash_post($id); }, 'Interrupted trash must leave fence.');
    remove_filter('trash_post_comments', $interrupt, 1);
    tmn_check(tmn_fence($id) !== null && tmn_post($id)->post_status === 'trash', 'Post-save outer interruption retains durable fence.');
    tmn_fixture_release($id); wp_untrash_post($id); $checks++;
    $abandon = wuai_sluyu_topic_media_operation('create', ['group'=>'0','request'=>wp_generate_uuid4(),'confirm_public'=>'1']);
    tmn_check(is_array($abandon), 'Create separate interrupted author-abandon fixture.'); $abandon_id = $abandon['id']; $tmn_posts[] = $abandon_id;
    $interrupt_author = static function ($post_id) use ($abandon_id) { if ((int) $post_id === $abandon_id) { throw new RuntimeException('qa author interrupted trash comments'); } };
    tmn_filter('trash_post_comments', $interrupt_author, 1);
    $stopped = wuai_sluyu_topic_media_operation('abandon', ['id'=>(string) $abandon_id,'version'=>(string) $abandon['version'],'confirm_public'=>'1']);
    remove_filter('trash_post_comments', $interrupt_author, 1);
    $held = $GLOBALS['wuai_topic_media_author_holds']['wuai_topic_media_lock_' . $uid] ?? '';
    tmn_check(is_wp_error($stopped) && tmn_post($abandon_id)->post_status === 'trash' && $held !== '', 'Interrupted author abandon is reported as incomplete and retains its account hold.');
    tmn_check($wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", 'wuai_topic_media_lock_' . $uid)) === $held, 'Ordinary operation finally preserves exact durable author fence.');
    tmn_check(is_wp_error(wuai_sluyu_topic_media_enter($uid)), 'Partial author trash cannot be followed by another image operation.');
    tmn_denied(static function () use ($abandon_id) { wp_untrash_post($abandon_id); }, 'Native restore must wait for stopped-worker author maintenance.');
    tmn_author_hold_release($uid); wp_untrash_post($abandon_id); $checks++;
    echo 'PASS topic-media native CAS/lifecycle checks=' . $checks . PHP_EOL;
} finally {
    foreach ($tmn_filters as $entry) { remove_filter($entry[0], $entry[1], $entry[2]); }
    foreach ($users as $uid) { tmn_author_hold_release($uid); }
    foreach ($tmn_posts as $id) { tmn_fixture_release($id); }
    foreach ($comments as $id) { if (get_comment($id)) { wp_delete_comment($id, true); } }
    foreach ($tmn_posts as $id) { if (get_post($id)) { wp_delete_post($id, true); } }
    foreach ($users as $uid) { if (get_userdata($uid)) { wp_delete_user($uid); } }
    wp_set_current_user($old_uid);
}
