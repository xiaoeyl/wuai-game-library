<?php
// CLI-only module integration. HTTP upload origin is tested by the browser suite.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); $resolved = $root ? realpath($root) : false;
$sqlite = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site'); $mysql = realpath(dirname(__DIR__) . '/dist/checks-mysql/site');
$is_mysql = $resolved && $mysql && strcasecmp($resolved, $mysql) === 0 && getenv('WP_QA_ISOLATED_MYSQL') === '1';
if (!$resolved || !(($sqlite && strcasecmp($resolved, $sqlite) === 0) || $is_mysql) || !is_file($root . '/wp-load.php')) { throw new RuntimeException('Explicit disposable WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_topic_media_operation') || !extension_loaded('gd')) { throw new RuntimeException('Integrated local module with PHP GD required.'); }
if ($is_mysql && (DB_HOST !== '127.0.0.1:3373' || DB_NAME !== 'wuai_isolated_qa' || $wpdb->prefix !== 'qa_' || !defined('WUAI_QA_NO_EXTERNAL_EFFECTS'))) { throw new RuntimeException('Independent MariaDB safety boundary mismatch.'); }
function tm_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function tm_error($result, $code, $message) { tm_check(is_wp_error($result) && $result->get_error_code() === 'topic_media_' . $code, $message); }
function tm_call($operation, $state, $values = [], $file = null) { return wuai_sluyu_topic_media_operation($operation, array_merge(['id'=>(string) $state['id'],'version'=>(string) $state['version'],'confirm_public'=>'1'], $values), $file); }
function tm_add($state, $path, $name = 'source.png') {
    $uid = get_current_user_id(); $guard = wuai_sluyu_topic_media_enter($uid); tm_check(!is_wp_error($guard), 'Acquire media guard.');
    $encoded = null; $post_lock = null;
    try {
        $post_lock = wuai_sluyu_lock('poll_' . $state['id']); tm_check((bool) $post_lock, 'Acquire exact topic lock for fixture commit.'); $guard['post_id'] = $state['id']; $guard['post_lock'] = $post_lock;
        $row = wuai_sluyu_topic_media_permission($state['id'], $uid); tm_check(!is_wp_error($row), 'Own draft permission.');
        $encoded = wuai_sluyu_topic_media_reencode($path, $name); tm_check(!is_wp_error($encoded), 'Re-encode fixture.');
        return wuai_sluyu_topic_media_commit($state['id'], $uid, $row, wp_generate_uuid4(), $encoded, $guard);
    } finally { if (is_array($encoded) && is_file($encoded['path'])) { wp_delete_file($encoded['path']); } if ($post_lock) { wuai_sluyu_unlock($post_lock); } wuai_sluyu_topic_media_leave($guard); }
}
$prefix = 'qa_topic_media_' . substr(bin2hex(random_bytes(6)), 0, 10) . '_'; $users = []; $posts = []; $files = []; $filters = []; $group = 0; $old_uid = get_current_user_id();
$mail = static function () { return true; }; add_filter('pre_wp_mail', $mail, PHP_INT_MAX);
try {
    foreach (['owner','other','admin'] as $name) { $uid = wp_create_user($prefix . $name, 'qa-local-only', $prefix . $name . '@example.test'); tm_check(!is_wp_error($uid), 'Create unique media fixture.'); $users[$name] = (int) $uid; (new WP_User($uid))->set_role($name === 'admin' ? 'administrator' : 'subscriber'); }
    $uid = $users['owner']; wp_set_current_user($uid); $request = wp_generate_uuid4();
    tm_error(wuai_sluyu_topic_media_operation('create', ['group'=>'0','request'=>$request,'confirm_public'=>'0']), 'confirm', 'Public disclosure requires affirmative confirmation.');
    tm_error(wuai_sluyu_topic_media_operation('create', ['group'=>[],'request'=>$request,'confirm_public'=>'1']), 'input', 'Nested fields rejected.');
    $state = wuai_sluyu_topic_media_operation('create', ['group'=>'0','request'=>$request,'confirm_public'=>'1']); tm_check(is_array($state), 'Create own draft.'); $posts[] = $state['id'];
    tm_check(get_post_status($state['id']) === 'draft' && !$state['items'], 'Empty draft never enters review.');
    wp_set_current_user($users['admin']); wp_update_post(['ID'=>$state['id'],'post_status'=>'publish']); clean_post_cache($state['id']); tm_check(get_post_status($state['id']) === 'draft', 'Native editor cannot publish an incomplete image draft.'); wp_set_current_user($uid);
    $again = wuai_sluyu_topic_media_operation('create', ['group'=>'0','request'=>$request,'confirm_public'=>'1']); tm_check($again['id'] === $state['id'], 'Idempotent request reuses the same draft.');
    tm_check(!user_can($uid, 'upload_files'), 'Dedicated subscriber uploads do not grant media-library permission.');
    tm_error(tm_call('submit', $state, ['title'=>'QA public pictures','body'=>'An explanatory paragraph.']), 'content', 'At least one picture is required.');
    tm_error(tm_call('upload', $state, ['request'=>wp_generate_uuid4()], ['name'=>'source.png','tmp_name'=>__FILE__,'size'=>100,'error'=>0]), 'origin', 'Public upload function rejects local server path.');
    $source = wp_tempnam('qa-topic-source'); $files[] = $source; $canvas = imagecreatetruecolor(2000, 1000); imagefill($canvas, 0, 0, imagecolorallocate($canvas, 41, 137, 183)); imagepng($canvas, $source); imagedestroy($canvas);
    $payload = 'QA-PRIVATE-ORIGINAL-METADATA-NOT-PUBLISHED'; file_put_contents($source, $payload . '<?php echo "never"; ?>', FILE_APPEND);
    $bad = wp_tempnam('qa-topic-bad'); $files[] = $bad; file_put_contents($bad, '<svg onload="alert(1)"></svg>');
    tm_error(wuai_sluyu_topic_media_reencode($bad, 'fake.png'), 'type', 'SVG disguised as image is rejected.');
    tm_error(wuai_sluyu_topic_media_reencode($source, 'image.gif'), 'type', 'GIF extension is rejected.');
    tm_error(wuai_sluyu_topic_media_reencode('https://example.test/source.png', 'source.png'), 'file', 'Remote fetch is not allowed.');
    $huge = wp_tempnam('qa-topic-huge'); $files[] = $huge; $handle = fopen($huge, 'wb'); ftruncate($handle, 4 * MB_IN_BYTES + 1); fclose($handle); tm_error(wuai_sluyu_topic_media_reencode($huge, 'huge.png'), 'size', 'Byte limit enforced before decode.');
    $encoded = wuai_sluyu_topic_media_reencode($source, 'source.png'); tm_check(is_array($encoded) && $encoded['width'] === 1600 && $encoded['height'] === 800, 'Aspect ratio retained and longest edge bounded, without crop.');
    tm_check(strpos(file_get_contents($encoded['path']), $payload) === false && strpos(file_get_contents($encoded['path']), '<?php') === false, 'Output contains only re-encoded pixels, not original trailing content.'); wp_delete_file($encoded['path']);
    $state = tm_add($state, $source); tm_check(is_array($state) && count($state['items']) === 1, 'Dedicated image stored and verified.'); $first = $state['items'][0]['id'];
    $image = wuai_sluyu_topic_media_attachment($first, $state['id'], $uid); tm_check(is_array($image) && is_file($image['path']), 'Image is genuinely saved under own dedicated parent.');
    tm_check(strpos(file_get_contents($image['path']), $payload) === false && $image['width'] === 1600, 'Published file excludes original bytes.');
    tm_check(get_post_meta($first, '_wp_attachment_metadata', true)['sizes'] === [], 'No extra original or thumbnail files created.');
    tm_check(wuai_sluyu_profile_image_id($uid, 'avatar') === 0, 'Topic image does not become an avatar.');
    $before_hold = wuai_sluyu_topic_media_state($state['id']); update_post_meta($state['id'], '_wuai_circle_review_hold', ['reason'=>'QA incomplete native review']);
    foreach (['upload','order','remove','submit','withdraw','abandon'] as $operation) {
        tm_error(tm_call($operation, $state, ['attachment'=>(string) $first,'order'=>(string) $first,'request'=>wp_generate_uuid4(),'title'=>'Held draft title','body'=>'Must not save held draft.']), 'review_held', 'Held draft refuses every author mutation.');
    }
    tm_check(wuai_sluyu_topic_media_state($state['id'])['raw'] === $before_hold['raw'] && is_file($image['path']) && get_post_status($state['id']) === 'draft', 'Held draft bundle/file/status stay intact.'); delete_post_meta($state['id'], '_wuai_circle_review_hold');
    $review_lock = wuai_sluyu_lock('poll_' . $state['id']); tm_check((bool) $review_lock, 'Hold shared topic review lock.');
    tm_error(tm_call('remove', $state, ['attachment'=>(string) $first]), 'busy', 'Image editor respects the topic review mutex.'); wuai_sluyu_unlock($review_lock);
    wp_set_current_user($users['other']); tm_error(tm_call('remove', $state, ['attachment'=>(string) $first]), 'permission', 'Other account cannot remove owner image.'); wp_set_current_user($uid);
    $ordinary = wp_insert_attachment(['post_author'=>$uid,'post_title'=>'Ordinary media without dedicated marker','post_status'=>'inherit','post_mime_type'=>'image/png']); $posts[] = $ordinary;
    tm_error(tm_call('remove', $state, ['attachment'=>(string) $ordinary]), 'remove', 'Foreign/ordinary attachment ID cannot be submitted for removal.');
    for ($i = 1; $i < 5; $i++) { $state = tm_add($state, $source); tm_check(is_array($state), 'Add bounded dedicated image.'); }
    tm_error(tm_call('upload', $state, ['request'=>wp_generate_uuid4()], null), 'capacity', 'Sixth image refused.');
    $ids = array_column($state['items'], 'id'); $reverse = array_reverse($ids); $version = $state['version'];
    $state = tm_call('order', $state, ['order'=>implode(',', $reverse)]); tm_check(array_column($state['items'], 'id') === $reverse, 'All five images can be reordered.');
    $stale = $state; $stale['version'] = $version; tm_error(tm_call('order', $stale, ['order'=>implode(',', $ids)]), 'version', 'Stale version cannot overwrite a newer order.');
    tm_error(tm_call('order', $state, ['order'=>implode(',', [$first,$first])]), 'order', 'Duplicate attachment references are rejected.');
    $bad_order = $reverse; $bad_order[0] = $ordinary; tm_error(tm_call('order', $state, ['order'=>implode(',', $bad_order)]), 'order', 'Cannot attach an ordinary media ID by reordering.');
    $held_injected = false; $frozen_state = wuai_sluyu_topic_media_state($state['id']);
    $late_hold = function ($sql) use (&$held_injected, $state) {
        if (!$held_injected && strpos($sql, 'UPDATE ') === 0 && strpos($sql, 'media_poll_lock') !== false && strpos($sql, 'media_post') !== false) { $held_injected = true; update_post_meta($state['id'], '_wuai_circle_review_hold', 'qa-late-hold'); }
        return $sql;
    };
    add_filter('query', $late_hold, 5); $filters[] = ['query',$late_hold,5];
    tm_error(tm_call('order', $state, ['order'=>implode(',', $ids)]), 'changed', 'Hold inserted immediately before bundle UPDATE fences the write.');
    remove_filter('query', $late_hold, 5); $filters = [];
    tm_check($held_injected && wuai_sluyu_topic_media_state($state['id'])['raw'] === $frozen_state['raw'], 'Derived LIMIT hold predicate preserves original bundle.'); delete_post_meta($state['id'], '_wuai_circle_review_hold');
    $token_injected = false; $replacement_lock = null;
    $replace_poll = function ($sql) use (&$token_injected, &$replacement_lock, $state) {
        if (!$token_injected && strpos($sql, 'UPDATE ') === 0 && strpos($sql, 'media_poll_lock') !== false && strpos($sql, 'media_post') !== false) {
            $token_injected = true; global $wpdb; $name = 'wuai_lock_poll_' . $state['id']; $token = time() . ':' . wp_generate_uuid4(); $wpdb->update($wpdb->options, ['option_value'=>$token], ['option_name'=>$name]); $replacement_lock = [$name,$token];
        }
        return $sql;
    };
    add_filter('query', $replace_poll, 5); $filters[] = ['query',$replace_poll,5];
    tm_error(tm_call('order', $state, ['order'=>implode(',', $ids)]), 'changed', 'Replaced poll token prevents a resumed old worker from writing.');
    remove_filter('query', $replace_poll, 5); $filters = [];
    global $wpdb;
    tm_check($token_injected && $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", $replacement_lock[0])) === $replacement_lock[1], 'Old worker finally does not unlock its successor.'); wuai_sluyu_unlock($replacement_lock);
    tm_check(wuai_sluyu_topic_media_state($state['id'])['raw'] === $frozen_state['raw'], 'Old poll token cannot update bundle even when no hold exists.');
    update_user_meta($uid, '_wuai_banned_until', -1); tm_error(tm_call('remove', $state, ['attachment'=>(string) $first]), 'auth', 'Banned account cannot modify public media.'); delete_user_meta($uid, '_wuai_banned_until');
    $shortcode_calls = 0; add_shortcode('qa_topic_injection', static function () use (&$shortcode_calls) { $shortcode_calls++; return '<img src="https://example.test/not-a-managed-image.png">'; });
    $body = 'Five re-encoded public images. [qa_topic_injection] [gallery ids="' . $ordinary . '"]';
    $state = tm_call('submit', $state, ['title'=>'QA public picture topic','body'=>$body]); tm_check(is_array($state) && $state['status'] === 'pending', 'Complete verified gallery enters review.' . (is_wp_error($state) ? ' ' . $state->get_error_code() . ': ' . $state->get_error_message() : ''));
    $rendered = apply_filters('the_content', get_post_field('post_content', $state['id']));
    tm_check($shortcode_calls === 0 && strpos($rendered, 'not-a-managed-image') === false && strpos($rendered, 'gallery-item') === false && strpos($rendered, '&#091;qa_topic_injection&#093;') !== false, 'Final content pipeline renders shortcode brackets literally, never an arbitrary image.');
    tm_check(trim($state['body']) === $body, 'Composer snapshot restores editable literal bracket text.'); remove_shortcode('qa_topic_injection');
    $before_hold = wuai_sluyu_topic_media_state($state['id']); update_post_meta($state['id'], '_wuai_circle_review_hold', ['reason'=>'QA incomplete pending review']);
    tm_error(tm_call('withdraw', $state), 'review_held', 'Held pending gallery cannot be withdrawn out of moderation.');
    tm_check(get_post_status($state['id']) === 'pending' && wuai_sluyu_topic_media_state($state['id'])['raw'] === $before_hold['raw'] && is_file($image['path']), 'Pending review hold preserves content/bundle/files.'); delete_post_meta($state['id'], '_wuai_circle_review_hold');
    tm_error(tm_call('remove', $state, ['attachment'=>(string) $first]), 'draft', 'Pending media cannot be replaced before an explicit withdrawal.');
    $status_injected = false;
    $late_status_hold = function ($sql) use (&$status_injected, $state) {
        global $wpdb;
        if (!$status_injected && preg_match('/^UPDATE\s+`?' . preg_quote($wpdb->posts, '/') . '`?\s+SET\s/is', $sql)) { $status_injected = true; update_post_meta($state['id'], '_wuai_circle_review_hold', 'qa-late-status-hold'); }
        return $sql;
    };
    add_filter('query', $late_status_hold, 5); $filters[] = ['query',$late_status_hold,5];
    tm_error(tm_call('withdraw', $state), 'post_write', 'Hold inserted just before native status SQL prevents a stale withdrawal.');
    remove_filter('query', $late_status_hold, 5); $filters = [];
    tm_check($status_injected && get_post_status($state['id']) === 'pending' && is_file($image['path']), 'Failed status CAS preserves pending content and files.'); delete_post_meta($state['id'], '_wuai_circle_review_hold');
    tm_check(is_wp_error(wuai_sluyu_account_submission_permission(get_post($state['id']), $uid, 'edit_submission')), 'Generic account editor cannot bypass gallery lifecycle.');
    wp_set_current_user($users['admin']);
    wp_update_post(['ID'=>$state['id'],'post_status'=>'future','post_date'=>wp_date('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),'post_date_gmt'=>gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS)]); clean_post_cache($state['id']);
    tm_check(get_post_status($state['id']) === 'draft', 'Dedicated image posts explicitly reject scheduled publication.');
    global $wpdb;
    $wpdb->update($wpdb->posts, ['post_status'=>'future','post_date_gmt'=>gmdate('Y-m-d H:i:s', time() - 60)], ['ID'=>$state['id']]); clean_post_cache($state['id']);
    do_action('publish_future_post', $state['id']); clean_post_cache($state['id']);
    tm_check(get_post_status($state['id']) === 'draft', 'Restored stale native cron event is cancelled before direct core publication.');
    update_user_meta($uid, '_wuai_banned_until', -1); wp_update_post(['ID'=>$state['id'],'post_status'=>'publish']); clean_post_cache($state['id']);
    tm_check(get_post_status($state['id']) === 'draft', 'Ungrouped native publication rechecks the original owner ban.'); delete_user_meta($uid, '_wuai_banned_until');
    wp_update_post(['ID'=>$state['id'],'post_status'=>'publish','post_date'=>current_time('mysql'),'post_date_gmt'=>current_time('mysql', true)]); clean_post_cache($state['id']);
    do_action('publish_future_post', $state['id']); clean_post_cache($state['id']); tm_check(get_post_status($state['id']) === 'publish', 'An already-published topic is not withdrawn by a stale scheduled event.');
    $gallery = wuai_sluyu_topic_media_gallery($state['id']); tm_check(substr_count($gallery, '<img ') === 5, 'Approved public post renders genuine images.');
    wp_update_post(['ID'=>$first,'post_author'=>$users['other'],'post_parent'=>$ordinary]); clean_post_cache($first);
    tm_check((int) get_post_field('post_author', $first) === $uid && (int) get_post_field('post_parent', $first) === $state['id'], 'Native reparent/owner mutation cannot turn a dedicated image into a deletion target.');
    $original_path = get_post_meta($first, '_wp_attached_file', true); update_post_meta($first, '_wp_attached_file', 'other-user/path.png'); tm_check(get_post_meta($first, '_wp_attached_file', true) === $original_path, 'Native path replacement is refused.');
    wp_set_current_user($uid); $state = wuai_sluyu_topic_media_snapshot($state['id']);
    tm_error(tm_call('remove', $state, ['attachment'=>(string) $first]), 'draft', 'Published images remain immutable to the author.');
    $state = tm_call('withdraw', $state); tm_check(is_array($state) && $state['status'] === 'draft' && $state['phase'] === 'draft', 'Explicit withdrawal creates editable draft requiring review.');
    tm_check(is_file($image['path']), 'Withdrawal does not falsely pretend the public image file became private.');
    $state = tm_call('remove', $state, ['attachment'=>(string) $first]); tm_check(is_array($state) && count($state['items']) === 4 && !is_file($image['path']) && !get_post($first), 'Removal deletes only verified dedicated image and its record.');
    tm_check((bool) get_post($ordinary), 'Ordinary media survives all dedicated cleanup.');

    $before = wuai_sluyu_topic_media_state($state['id']); $deny_save = function ($sql) use ($before) { if (strpos($sql, 'UPDATE ') === 0 && strpos($sql, "meta_id=" . $before['meta_id']) !== false && strpos($sql, 'media_lock') !== false) { return $sql . ' AND 0=1'; } return $sql; };
    add_filter('query', $deny_save, PHP_INT_MAX); $filters[] = ['query',$deny_save,PHP_INT_MAX];
    $result = tm_add($state, $source); tm_check(is_wp_error($result), 'Failed list persistence never reports upload success.');
    remove_filter('query', $deny_save, PHP_INT_MAX); $filters = []; tm_check(count(wuai_sluyu_topic_media_snapshot($state['id'])['items']) === 4, 'Failed binding does not publish an unlisted success.');
    $remove_id = $state['items'][0]['id']; $remove_file = wuai_sluyu_topic_media_attachment($remove_id, $state['id'], $uid);
    $deny_delete = function ($check, $post) use ($remove_id) { return (int) $post->ID === $remove_id ? false : $check; };
    add_filter('pre_delete_attachment', $deny_delete, PHP_INT_MAX, 2); $filters[] = ['pre_delete_attachment',$deny_delete,PHP_INT_MAX];
    tm_error(tm_call('remove', $state, ['attachment'=>(string) $remove_id]), 'cleanup_partial', 'List CAS success plus file cleanup failure reports the partial public-file outcome.');
    remove_filter('pre_delete_attachment', $deny_delete, PHP_INT_MAX); $filters = [];
    $state = wuai_sluyu_topic_media_snapshot($state['id']); tm_check(count($state['items']) === 3 && is_file($remove_file['path']) && get_post($remove_id), 'Unlisted orphan remains a dedicated retained attachment for a later verified cleanup.');
    $comment = wp_insert_comment(['comment_post_ID'=>$state['id'],'user_id'=>$users['other'],'comment_author'=>'QA trash lifecycle','comment_content'=>'Preserve this approved comment status for native restore.','comment_approved'=>1]); tm_check((bool) $comment, 'Create isolated trash comment.');
    $trash_hooks = 0; $trash_hook = static function ($id) use (&$trash_hooks, $state) { if ((int) $id === $state['id']) { $trash_hooks++; } }; add_action('trashed_post', $trash_hook, 100);
    $state = tm_call('abandon', $state); remove_action('trashed_post', $trash_hook, 100); tm_check(is_array($state) && $state['status'] === 'trash', 'Explicit abandonment cleans dedicated pictures and trashes only this draft.');
    tm_check($trash_hooks === 1 && get_post_meta($state['id'], '_wp_trash_meta_status', true) === 'draft' && (int) get_post_meta($state['id'], '_wp_trash_meta_time', true) > 0 && get_comment($comment)->comment_approved === 'post-trashed', 'Abandon uses core trash metadata, expiration time, comment lifecycle and final hook.');
    foreach ($ids as $id) { tm_check(!get_post($id), 'All original dedicated images are deleted after abandonment.'); }
    tm_check((bool) get_post($ordinary), 'Unrelated media remains after abandonment.');
    wp_set_current_user($users['admin']); tm_check((bool) wp_untrash_post($state['id']), 'Native restore runs full core lifecycle.');
    tm_check(get_post_status($state['id']) === 'draft' && get_comment($comment)->comment_approved === '1' && !metadata_exists('post', $state['id'], '_wp_trash_meta_time'), 'Native restore restores comment state and retires trash-expiry metadata.');
    tm_check((bool) wp_delete_post($state['id'], true), 'Restored abandoned draft may be permanently deleted.'); wp_set_current_user($uid);

    $shared_request = wp_generate_uuid4(); $collision = [];
    foreach (['owner','other'] as $name) {
        wp_set_current_user($users[$name]); $draft = wuai_sluyu_topic_media_operation('create', ['group'=>'0','request'=>$shared_request,'confirm_public'=>'1']); tm_check(is_array($draft), 'Same caller-chosen UUID is isolated by authenticated account.'); $posts[] = $draft['id'];
        $draft = tm_add($draft, $source); $draft = tm_call('submit', $draft, ['title'=>'Per-account permalink fixture','body'=>'Two different users cannot collide public image permalinks.']); tm_check(is_array($draft) && $draft['status'] === 'pending', 'Both isolated slugs may be reviewed.');
        wp_set_current_user($users['admin']); wp_update_post(['ID'=>$draft['id'],'post_status'=>'publish']); clean_post_cache($draft['id']);
        tm_check(get_post_status($draft['id']) === 'publish', 'Native editor publishes each valid independent gallery.'); $collision[] = ['slug'=>get_post_field('post_name', $draft['id']), 'url'=>get_permalink($draft['id'])];
    }
    tm_check($collision[0]['slug'] !== $collision[1]['slug'] && $collision[0]['url'] !== $collision[1]['url'], 'Same UUID from two subscribers cannot produce a duplicate published permalink.');
    wp_set_current_user($uid);

    $group_result = wp_insert_term('QA public image group ' . $prefix, 'sluyu_circle'); tm_check(!is_wp_error($group_result), 'Create isolated circle.'); $group = (int) $group_result['term_id'];
    update_term_meta($group, '_wuai_circle_status', 'publish'); update_term_meta($group, '_wuai_circle_join', 'member'); update_term_meta($group, '_wuai_circle_posting', 'everyone');
    tm_error(wuai_sluyu_topic_media_operation('create', ['group'=>(string) $group,'request'=>wp_generate_uuid4(),'confirm_public'=>'1']), 'circle', 'Member-only circle cannot imply private image upload.');
    update_term_meta($group, '_wuai_circle_join', 'open');
    $circle = wuai_sluyu_topic_media_operation('create', ['group'=>(string) $group,'request'=>wp_generate_uuid4(),'confirm_public'=>'1']); tm_check(is_array($circle), 'Public open circle permits dedicated image draft.'); $posts[] = $circle['id'];
    update_term_meta($group, '_wuai_circle_status', 'pending'); tm_error(tm_call('order', $circle, ['order'=>'1']), 'circle', 'Circle closure is rechecked during every action.'); update_term_meta($group, '_wuai_circle_status', 'publish');
    $second = wuai_sluyu_topic_media_operation('create', ['group'=>'0','request'=>wp_generate_uuid4(),'confirm_public'=>'1']); tm_check(is_array($second), 'Second active draft.'); $posts[] = $second['id'];
    $third = wuai_sluyu_topic_media_operation('create', ['group'=>'0','request'=>wp_generate_uuid4(),'confirm_public'=>'1']); tm_check(is_array($third), 'Third active draft.'); $posts[] = $third['id'];
    tm_error(wuai_sluyu_topic_media_operation('create', ['group'=>'0','request'=>wp_generate_uuid4(),'confirm_public'=>'1']), 'draft_limit', 'Fourth active draft rejected.');
    update_user_meta($uid, '_wuai_topic_media_daily', ['day'=>gmdate('Y-m-d'),'count'=>20]);
    tm_error(tm_call('upload', $second, ['request'=>wp_generate_uuid4()], null), 'budget', 'Daily attempts cannot be bypassed with malformed files.');
    echo "PASS: own public image drafts, strict pixel re-encoding, limits/idempotency, ownership/order/version checks, pending/published withdrawal lifecycle, native attachment immutability, exact cleanup, storage failure and circle restrictions. HTTP upload remains browser-tested only.\n";
} finally {
    foreach ($filters as $filter) { remove_filter($filter[0], $filter[1], $filter[2]); }
    wp_set_current_user($users['admin'] ?? 0);
    foreach ($posts as $id) { if (get_post($id)) { wp_delete_post($id, true); } }
    if ($group) { wp_delete_term($group, 'sluyu_circle'); }
    require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ($users as $name=>$uid) { $user = get_userdata($uid); if ($user && $user->user_login === $prefix . $name) { wp_delete_user($uid); } }
    foreach ($files as $file) { if (is_file($file)) { wp_delete_file($file); } }
    remove_filter('pre_wp_mail', $mail, PHP_INT_MAX); wp_set_current_user($old_uid);
}
