<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Integration (load after topic-media.php):
 * - Delegate topic_media_delete_attachment() to cleanup_delete(id, post, uid, guard).
 * - Use cleanup_retained_count(uid), not the live attachment count, for quota.
 * - After abandon removes its list, call cleanup_scope(uid, post, guard), including
 *   when no attachment rows remain. This module does not trash the topic itself.
 * - Replace the old best-effort before_delete_post / delete_user cleanup actions
 *   with this module's blocking hooks. Native user reassignment with managed
 *   topics is refused; ordinary posts and media are not changed by these hooks.
 *   User deletion preflights at priority 0 without writes, then cleans at 2 only
 *   after community's priority-1 account/economy/bounty authorization succeeds.
 * - Native topic saves must reject wuai_topic_media_lock_OWNER. Both author and
 *   cleanup workers reject wuai_topic_media_native_ID. Crashed durable locks need
 *   stopped-worker inspection, never timeout/shutdown takeover.
 * cleanup_delete/scope are internal trusted APIs, not AJAX authorization gates.
 * A guard means author cleanup: exact lock/hold and absent list reference apply.
 * Tombstones contain local paths and must not be exposed in REST/public HTML.
 */

function wuai_sluyu_topic_media_cleanup_error($code, $message) {
    return new WP_Error('topic_media_cleanup_' . $code, $message);
}

function wuai_sluyu_topic_media_cleanup_name($uid, $post_id, $id) {
    return 'wuai_topic_media_cleanup_' . (int) $uid . '_' . (int) $post_id . '_' . (int) $id;
}

function wuai_sluyu_topic_media_cleanup_decode($name, $raw) {
    if (!is_string($raw) || strlen($raw) > 16384 || !is_serialized($raw)) { return wuai_sluyu_topic_media_cleanup_error('record', '图片清理凭据无效，请联系管理员核验。'); }
    $v = @unserialize($raw, ['allowed_classes'=>false]);
    if (!is_array($v) || array_keys($v) !== ['schema','attachment','owner','post','slug','base','marker','created'] || $v['schema'] !== 1 || !is_int($v['attachment']) || $v['attachment'] < 1 || !is_int($v['owner']) || $v['owner'] < 1 || !is_int($v['post']) || $v['post'] < 1 || !is_int($v['created']) || $v['created'] < 1 || !is_string($v['slug']) || strlen($v['slug']) > 200 || !is_string($v['base']) || $v['base'] === '' || strlen($v['base']) > 4096 || strpos($v['base'], "\0") !== false || $name !== wuai_sluyu_topic_media_cleanup_name($v['owner'], $v['post'], $v['attachment'])) { return wuai_sluyu_topic_media_cleanup_error('record', '无法核验图片清理凭据的归属。'); }
    $m = $v['marker'];
    if (!is_array($m) || array_keys($m) !== ['schema','purpose','owner','post','request','file','mime','width','height','sha256'] || $m['schema'] !== 1 || $m['purpose'] !== 'topic-public' || $m['owner'] !== $v['owner'] || $m['post'] !== $v['post'] || !wuai_sluyu_topic_media_uuid($m['request']) || !is_string($m['file']) || !preg_match('~^(?:[0-9]{4}/[0-9]{2}/)?topic-public-[a-f0-9-]{36}\.(?:png|jpg)$~D', $m['file']) || !in_array($m['mime'], ['image/png','image/jpeg'], true) || !is_int($m['width']) || !is_int($m['height']) || min($m['width'], $m['height']) < 1 || max($m['width'], $m['height']) > 1600 || !is_string($m['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $m['sha256'])) { return wuai_sluyu_topic_media_cleanup_error('record', '无法核验待清理图片的文件标识。'); }
    return ['name'=>$name, 'raw'=>$raw, 'value'=>$v];
}

function wuai_sluyu_topic_media_cleanup_read($uid, $post_id, $id) {
    global $wpdb;
    $name = wuai_sluyu_topic_media_cleanup_name($uid, $post_id, $id);
    $rows = $wpdb->get_col($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", $name));
    if ($wpdb->last_error || count($rows) > 1) { return wuai_sluyu_topic_media_cleanup_error('storage', '无法读取并核验图片清理记录。'); }
    return $rows ? wuai_sluyu_topic_media_cleanup_decode($name, $rows[0]) : false;
}

function wuai_sluyu_topic_media_cleanup_records($uid, $post_id = 0) {
    global $wpdb;
    $prefix = 'wuai_topic_media_cleanup_' . (int) $uid . '_' . ($post_id ? (int) $post_id . '_' : '');
    $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name,option_value FROM $wpdb->options WHERE option_name LIKE %s ORDER BY option_id LIMIT 1001", $wpdb->esc_like($prefix) . '%'), ARRAY_A);
    if ($wpdb->last_error || count($rows) > 1000) { return wuai_sluyu_topic_media_cleanup_error('storage', '图片清理范围异常，请联系管理员核验。'); }
    $records = [];
    foreach ($rows as $row) {
        $record = wuai_sluyu_topic_media_cleanup_decode($row['option_name'], $row['option_value']);
        if (is_wp_error($record)) { return $record; }
        $id = $record['value']['attachment'];
        if (isset($records[$id])) { return wuai_sluyu_topic_media_cleanup_error('record', '同一附件存在冲突的清理凭据，请联系管理员处理。'); }
        $records[$id] = $record;
    }
    return $records;
}

function wuai_sluyu_topic_media_cleanup_live($uid, $post_id = 0) {
    global $wpdb;
    // A parent deletion must also notice an unexpectedly changed attachment owner.
    $scope = $post_id ? $wpdb->prepare('p.post_parent=%d', $post_id) : $wpdb->prepare('p.post_author=%d', $uid);
    $rows = $wpdb->get_results("SELECT p.ID,p.post_author,p.post_parent FROM $wpdb->posts p WHERE p.post_type='attachment' AND $scope AND " . $wpdb->prepare("(p.post_name LIKE %s OR EXISTS (SELECT 1 FROM $wpdb->postmeta m WHERE m.post_id=p.ID AND m.meta_key=%s)) ORDER BY p.ID LIMIT 1001", $wpdb->esc_like('topic-public-') . '%', wuai_sluyu_topic_media_marker()), ARRAY_A);
    return $wpdb->last_error || count($rows) > 1000 ? wuai_sluyu_topic_media_cleanup_error('storage', '无法安全核验已保留的图片列表。') : $rows;
}

function wuai_sluyu_topic_media_cleanup_retained_count($uid) {
    if ((int) $uid < 1) { return wuai_sluyu_topic_media_cleanup_error('owner', '请提供有效的图片所属账号。'); }
    $live = wuai_sluyu_topic_media_cleanup_live((int) $uid);
    $records = wuai_sluyu_topic_media_cleanup_records((int) $uid);
    if (is_wp_error($live)) { return $live; }
    if (is_wp_error($records)) { return $records; }
    $ids = array_fill_keys(array_keys($records), true);
    foreach ($live as $row) { $ids[(int) $row['ID']] = true; }
    return count($ids);
}

function wuai_sluyu_topic_media_cleanup_enter($uid, $guard = null) {
    global $wpdb;
    $uid = (int) $uid;
    if ($uid < 1 || is_multisite()) { return wuai_sluyu_topic_media_cleanup_error('owner', '图片清理需要有效账号，暂不支持多站点。'); }
    $active = $GLOBALS['wuai_topic_media_cleanup_accounts'][$uid] ?? null;
    $owns = !$active && $guard === null;
    if ($active) {
        if ($guard !== null && (($guard['name'] ?? '') !== $active['guard']['name'] || ($guard['token'] ?? '') !== $active['guard']['token'])) { return wuai_sluyu_topic_media_cleanup_error('busy', '此账号已有另一项图片清理操作，请稍后重试。'); }
        $guard = $active['guard'];
    } elseif ($guard === null) {
        $guard = wuai_sluyu_topic_media_enter($uid);
        if (is_wp_error($guard)) { return $guard; }
    }
    // Author guards are borrowed; native guards created here own their release.
    $token = is_array($guard) ? ($guard['token'] ?? '') : '';
    if (!is_string($token) || $token === '' || ($guard['name'] ?? '') !== 'wuai_topic_media_lock_' . $uid) { return wuai_sluyu_topic_media_cleanup_error('fenced', '图片清理的账号锁无效，未继续操作。'); }
    $actual = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", $guard['name']));
    if ($wpdb->last_error || !is_string($actual) || !hash_equals($token, $actual)) {
        if ($owns) { wuai_sluyu_topic_media_leave($guard); }
        return wuai_sluyu_topic_media_cleanup_error('fenced', '图片清理的账号锁已变化，未继续操作。');
    }
    if ($active) { $GLOBALS['wuai_topic_media_cleanup_accounts'][$uid]['depth']++; }
    else { $GLOBALS['wuai_topic_media_cleanup_accounts'][$uid] = ['guard'=>$guard, 'owns'=>$owns, 'depth'=>1]; }
    return ['uid'=>$uid, 'token'=>$token];
}

function wuai_sluyu_topic_media_cleanup_leave($handle) {
    $uid = $handle['uid']; $active = $GLOBALS['wuai_topic_media_cleanup_accounts'][$uid] ?? null;
    if (!$active || !hash_equals($active['guard']['token'], $handle['token'])) { return; }
    if (--$GLOBALS['wuai_topic_media_cleanup_accounts'][$uid]['depth'] > 0) { return; }
    unset($GLOBALS['wuai_topic_media_cleanup_accounts'][$uid]);
    if ($active['owns']) { wuai_sluyu_topic_media_leave($active['guard']); }
}

function wuai_sluyu_topic_media_cleanup_poll($post_id) {
    global $wpdb;
    $name = 'wuai_lock_poll_' . (int) $post_id;
    $owned = $GLOBALS['wuai_sluyu_request_locks'][$name] ?? '';
    $lock = $owned !== '' ? [$name, $owned] : wuai_sluyu_lock('poll_' . (int) $post_id);
    if (!$lock) { return wuai_sluyu_topic_media_cleanup_error('busy', '图片话题正在编辑或审核，请稍后重试。'); }
    $actual = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", $name));
    if ($wpdb->last_error || !is_string($actual) || !hash_equals($lock[1], $actual)) {
        if ($owned === '') { wuai_sluyu_unlock($lock); }
        return wuai_sluyu_topic_media_cleanup_error('fenced', '图片话题锁已变化，清理已停止。');
    }
    return ['lock'=>$lock, 'owns'=>$owned === ''];
}

function wuai_sluyu_topic_media_cleanup_unpoll($poll) {
    if (is_array($poll) && !empty($poll['owns'])) { wuai_sluyu_unlock($poll['lock']); }
}

function wuai_sluyu_topic_media_cleanup_fenced($handle, $post_id, $poll, $guard = null, $attachment = 0) {
    global $wpdb;
    $valid = $wpdb->get_var($wpdb->prepare("SELECT 1 WHERE EXISTS (SELECT 1 FROM $wpdb->options a WHERE a.option_name=%s AND HEX(a.option_value)=HEX(%s)) AND EXISTS (SELECT 1 FROM $wpdb->options p WHERE p.option_name=%s AND HEX(p.option_value)=HEX(%s)) AND NOT EXISTS (SELECT 1 FROM $wpdb->options n WHERE n.option_name=%s)", 'wuai_topic_media_lock_' . $handle['uid'], $handle['token'], $poll['lock'][0], $poll['lock'][1], 'wuai_topic_media_native_' . (int) $post_id));
    if ($wpdb->last_error || (string) $valid !== '1') { return wuai_sluyu_topic_media_cleanup_error('fenced', '图片锁或后台编辑锁已变化，清理已停止。'); }
    if ($guard !== null) {
        if (!wuai_sluyu_topic_media_guard_valid($post_id, $guard)) { return wuai_sluyu_topic_media_cleanup_error('fenced', '投稿清理锁或审核隔离标记已变化，未继续操作。'); }
        $row = wuai_sluyu_topic_media_state($post_id);
        if (is_wp_error($row) || !$row || $row['value']['owner'] !== $handle['uid'] || ($attachment && in_array((int) $attachment, array_column($row['value']['items'], 'id'), true))) { return wuai_sluyu_topic_media_cleanup_error('referenced', '图片仍在稿件列表中，或无法核验权威列表，未删除文件。'); }
    }
    return true;
}

function wuai_sluyu_topic_media_cleanup_path($record) {
    $v = $record['value']; $m = $v['marker'];
    $upload = wp_upload_dir(null, false); $base = empty($upload['error']) ? realpath($upload['basedir']) : false;
    if (!$base || wp_normalize_path($base) !== $v['base'] || !is_readable($base)) { return wuai_sluyu_topic_media_cleanup_error('path', '原本地上传目录不可用或已变化，未删除文件。'); }
    $path = $v['base']; $segments = explode('/', $m['file']);
    foreach ($segments as $index=>$segment) {
        $path .= '/' . $segment; clearstatcache(true, $path);
        if (is_link($path)) { return wuai_sluyu_topic_media_cleanup_error('path', '待清理路径包含符号链接，未删除任何文件。'); }
        if (!file_exists($path)) { return ['path'=>$v['base'] . '/' . $m['file'], 'exists'=>false]; }
        $real = realpath($path);
        if (!$real || wp_normalize_path($real) !== $path || !is_readable($path) || ($index < count($segments) - 1 && !is_dir($path))) { return wuai_sluyu_topic_media_cleanup_error('path', '无法精确核验待清理路径，未删除文件。'); }
    }
    if (!is_file($path) || filesize($path) > 4 * MB_IN_BYTES || wp_get_image_mime($path) !== $m['mime']) { return wuai_sluyu_topic_media_cleanup_error('file', '保留文件与专用图片记录不符，请联系管理员核验。'); }
    $hash = hash_file('sha256', $path);
    if (!is_string($hash) || !hash_equals($m['sha256'], $hash)) { return wuai_sluyu_topic_media_cleanup_error('file', '保留图片内容已变化，请联系管理员核验。'); }
    return ['path'=>$path, 'exists'=>true];
}

function wuai_sluyu_topic_media_cleanup_live_matches($record) {
    global $wpdb;
    $v = $record['value']; $id = $v['attachment']; $m = $v['marker'];
    $post = $wpdb->get_row($wpdb->prepare("SELECT ID,post_type,post_author,post_parent,post_name,post_mime_type FROM $wpdb->posts WHERE ID=%d", $id));
    if ($wpdb->last_error) { return wuai_sluyu_topic_media_cleanup_error('storage', '无法核验附件数据库记录。'); }
    if (!$post) { return true; }
    if ($post->post_type !== 'attachment' || (int) $post->post_author !== $v['owner'] || (int) $post->post_parent !== $v['post'] || $post->post_name !== $v['slug'] || $post->post_mime_type !== $m['mime']) { return wuai_sluyu_topic_media_cleanup_error('identity', '残留附件的身份或归属已变化，未删除文件。'); }
    // Core may have removed some metadata before its final posts DELETE failed.
    // Only a previously persisted tombstone permits missing metadata on retry.
    $expected = [wuai_sluyu_topic_media_marker()=>serialize($m), '_wp_attached_file'=>$m['file'], '_wp_attachment_metadata'=>serialize(['width'=>$m['width'],'height'=>$m['height'],'file'=>$m['file'],'sizes'=>[],'image_meta'=>[]])];
    $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM $wpdb->postmeta WHERE post_id=%d AND meta_key IN (%s,'_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes')", $id, wuai_sluyu_topic_media_marker()), ARRAY_A);
    if ($wpdb->last_error) { return wuai_sluyu_topic_media_cleanup_error('storage', '无法核验附件元数据。'); }
    $seen = [];
    foreach ($rows as $row) {
        if (isset($seen[$row['meta_key']]) || !isset($expected[$row['meta_key']]) || $expected[$row['meta_key']] !== $row['meta_value']) { return wuai_sluyu_topic_media_cleanup_error('identity', '残留附件元数据与清理凭据不符，未继续删除。'); }
        $seen[$row['meta_key']] = true;
    }
    return true;
}

function wuai_sluyu_topic_media_cleanup_prepare($id, $post_id, $uid) {
    $record = wuai_sluyu_topic_media_cleanup_read($uid, $post_id, $id);
    if (is_wp_error($record)) { return $record; }
    if ($record) { $valid = wuai_sluyu_topic_media_cleanup_live_matches($record); return is_wp_error($valid) ? $valid : $record; }
    $image = wuai_sluyu_topic_media_attachment($id, $post_id, $uid, false);
    if (is_wp_error($image)) { return $image; }
    $marker = wuai_sluyu_topic_media_raw($id, wuai_sluyu_topic_media_marker()); $post = get_post($id);
    $upload = wp_upload_dir(null, false); $base = empty($upload['error']) ? realpath($upload['basedir']) : false;
    if (!$post || !$base || is_wp_error($marker) || !$marker) { return wuai_sluyu_topic_media_cleanup_error('identity', '专用附件尚不能建立可核验的清理凭据。'); }
    $value = ['schema'=>1,'attachment'=>(int) $id,'owner'=>(int) $uid,'post'=>(int) $post_id,'slug'=>$post->post_name,'base'=>wp_normalize_path($base),'marker'=>$marker['value'],'created'=>time()];
    $name = wuai_sluyu_topic_media_cleanup_name($uid, $post_id, $id); $raw = serialize($value);
    $candidate = wuai_sluyu_topic_media_cleanup_decode($name, $raw);
    if (is_wp_error($candidate)) { return $candidate; }
    $path = wuai_sluyu_topic_media_cleanup_path($candidate);
    if (is_wp_error($path)) { return $path; }
    global $wpdb;
    $written = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name,option_value,autoload) VALUES (%s,%s,'no')", $name, $raw));
    $record = wuai_sluyu_topic_media_cleanup_read($uid, $post_id, $id);
    if ($written !== 1 || is_wp_error($record) || !$record || !hash_equals($raw, $record['raw'])) { return wuai_sluyu_topic_media_cleanup_error('persist', '清理凭据未确认保存，未删除文件或附件记录。'); }
    return $record;
}

function wuai_sluyu_topic_media_cleanup_current($record, $handle, $poll, $guard) {
    $v = $record['value'];
    $valid = wuai_sluyu_topic_media_cleanup_fenced($handle, $v['post'], $poll, $guard, $v['attachment']);
    if (is_wp_error($valid)) { return $valid; }
    $actual = wuai_sluyu_topic_media_cleanup_read($v['owner'], $v['post'], $v['attachment']);
    if (is_wp_error($actual) || !$actual || !hash_equals($record['raw'], $actual['raw'])) { return wuai_sluyu_topic_media_cleanup_error('changed', '图片清理凭据已变化，清理已停止。'); }
    return wuai_sluyu_topic_media_cleanup_live_matches($record);
}

function wuai_sluyu_topic_media_cleanup_unlink($record, $handle, $poll, $guard) {
    $valid = wuai_sluyu_topic_media_cleanup_current($record, $handle, $poll, $guard);
    if (is_wp_error($valid)) { return $valid; }
    $v = $record['value']; global $wpdb;
    $other = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_wp_attached_file' AND post_id<>%d AND HEX(meta_value)=HEX(%s) LIMIT 1", $v['attachment'], $v['marker']['file']));
    if ($wpdb->last_error || $other) { return wuai_sluyu_topic_media_cleanup_error('shared', '其他附件仍引用此文件，清理已停止。'); }
    $path = wuai_sluyu_topic_media_cleanup_path($record);
    if (is_wp_error($path) || !$path['exists']) { return is_wp_error($path) ? $path : true; }
    // Honor a deletion veto, but never let a filter redirect this exact unlink.
    $filtered = apply_filters('wp_delete_file', $path['path']);
    if (!is_string($filtered) || $filtered !== $path['path']) { return wuai_sluyu_topic_media_cleanup_error('unlink', '文件删除被拒绝或路径发生重定向，清理凭据已保留。'); }
    $valid = wuai_sluyu_topic_media_cleanup_current($record, $handle, $poll, $guard);
    $path = wuai_sluyu_topic_media_cleanup_path($record);
    if (is_wp_error($valid)) { return $valid; }
    if (is_wp_error($path)) { return $path; }
    $other = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_wp_attached_file' AND post_id<>%d AND HEX(meta_value)=HEX(%s) LIMIT 1", $v['attachment'], $v['marker']['file']));
    if ($wpdb->last_error || $other) { return wuai_sluyu_topic_media_cleanup_error('shared', '清理期间出现新的文件引用，未删除文件。'); }
    if ($path['exists'] && !@unlink($path['path'])) { return wuai_sluyu_topic_media_cleanup_error('unlink', '公开图片文件未能删除，仍占用保留额度，清理凭据可供重试。'); }
    clearstatcache(true, $path['path']);
    return !file_exists($path['path']) && !is_link($path['path']) ? true : wuai_sluyu_topic_media_cleanup_error('unlink', '尚未确认公开文件已删除，请联系管理员核验。');
}

function wuai_sluyu_topic_media_cleanup_delete($id, $post_id, $uid, $guard = null) {
    $id = (int) $id; $post_id = (int) $post_id; $uid = (int) $uid;
    if ($id < 1 || $post_id < 1) { return wuai_sluyu_topic_media_cleanup_error('identity', '图片清理必须提供准确的附件和话题编号。'); }
    $handle = wuai_sluyu_topic_media_cleanup_enter($uid, $guard);
    if (is_wp_error($handle)) { return $handle; }
    $poll = null;
    try {
        $poll = wuai_sluyu_topic_media_cleanup_poll($post_id);
        if (is_wp_error($poll)) { return $poll; }
        $valid = wuai_sluyu_topic_media_cleanup_fenced($handle, $post_id, $poll, $guard, $id);
        if (is_wp_error($valid)) { return $valid; }
        $record = wuai_sluyu_topic_media_cleanup_prepare($id, $post_id, $uid);
        if (is_wp_error($record)) { return $record; }
        $valid = wuai_sluyu_topic_media_cleanup_current($record, $handle, $poll, $guard);
        if (is_wp_error($valid)) { return $valid; }
        clean_post_cache($id);
        if (get_post($id)) {
            $GLOBALS['wuai_topic_media_cleanup_core'][$id] = true;
            $was_deleting = $GLOBALS['wuai_topic_media_deleting_attachment'][$id] ?? null;
            $GLOBALS['wuai_topic_media_deleting_attachment'][$id] = true;
            // Core deletes metadata before files. Its file deletion is deferred
            // to our exact tombstone path after the database result is checked.
            $file_calls = []; $exact_path = $record['value']['base'] . '/' . $record['value']['marker']['file'];
            $capture_file = static function ($file) use (&$file_calls) { $file_calls[] = $file; return $file; };
            $defer_file = static function ($file) use (&$file_calls, $exact_path) {
                $original = array_pop($file_calls);
                return is_string($original) && wp_normalize_path($original) === $exact_path ? '' : $file;
            };
            $delete_sql = static function ($sql) use ($id, $record, $handle, $poll, $guard) {
                global $wpdb;
                if (!preg_match('/^\s*DELETE\s+FROM\s+`?' . preg_quote($wpdb->posts, '/') . '`?\s/i', $sql)) { return $sql; }
                if (preg_match('/\bWHERE\s+`?ID`?\s*=\s*[\'"]?([0-9]+)[\'"]?\s*$/iD', $sql, $other) && (int) $other[1] !== $id) { return $sql; }
                if (preg_match('/^DELETE\s+FROM\s+`?' . preg_quote($wpdb->posts, '/') . '`?\s+WHERE\s+`?ID`?\s*=\s*[\'"]?' . $id . '[\'"]?\s*$/iD', $sql)) {
                    $valid = wuai_sluyu_topic_media_cleanup_current($record, $handle, $poll, $guard);
                    if (is_wp_error($valid)) { return $sql . ' AND 0=1'; }
                    $sql .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM $wpdb->options cleanup_account WHERE cleanup_account.option_name=%s AND HEX(cleanup_account.option_value)=HEX(%s)) AND EXISTS (SELECT 1 FROM $wpdb->options cleanup_poll WHERE cleanup_poll.option_name=%s AND HEX(cleanup_poll.option_value)=HEX(%s)) AND EXISTS (SELECT 1 FROM $wpdb->options cleanup_intent WHERE cleanup_intent.option_name=%s AND HEX(cleanup_intent.option_value)=HEX(%s)) AND NOT EXISTS (SELECT 1 FROM $wpdb->options cleanup_native WHERE cleanup_native.option_name=%s)", 'wuai_topic_media_lock_' . $handle['uid'], $handle['token'], $poll['lock'][0], $poll['lock'][1], $record['name'], $record['raw'], 'wuai_topic_media_native_' . $record['value']['post']);
                    if ($guard !== null) { $sql .= ' AND ' . wuai_sluyu_topic_media_hold_sql($record['value']['post']); }
                    return $sql;
                }
                // Unknown posts DELETE shapes are not allowed to escape fencing.
                // Core's read-back will report the retained row and intent.
                return $wpdb->prepare("DELETE FROM $wpdb->posts WHERE ID=%d AND 0=1 /* Unrecognized dedicated cleanup DELETE. */", $id);
            };
            add_filter('query', $delete_sql, PHP_INT_MAX);
            add_filter('wp_delete_file', $capture_file, PHP_INT_MIN);
            add_filter('wp_delete_file', $defer_file, PHP_INT_MAX);
            try { wp_delete_attachment($id, true); }
            finally {
                remove_filter('query', $delete_sql, PHP_INT_MAX);
                remove_filter('wp_delete_file', $capture_file, PHP_INT_MIN);
                remove_filter('wp_delete_file', $defer_file, PHP_INT_MAX);
                unset($GLOBALS['wuai_topic_media_cleanup_core'][$id]);
                if ($was_deleting === null) { unset($GLOBALS['wuai_topic_media_deleting_attachment'][$id]); }
                else { $GLOBALS['wuai_topic_media_deleting_attachment'][$id] = $was_deleting; }
            }
        }
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE ID=%d", $id));
        if ($wpdb->last_error || $exists) { return wuai_sluyu_topic_media_cleanup_error('delete', '附件数据库记录未能删除，清理凭据已保留，可稍后重试。'); }
        $removed = wuai_sluyu_topic_media_cleanup_unlink($record, $handle, $poll, $guard);
        if (is_wp_error($removed)) { return $removed; }
        $path = wuai_sluyu_topic_media_cleanup_path($record);
        if (is_wp_error($path) || $path['exists']) { return is_wp_error($path) ? $path : wuai_sluyu_topic_media_cleanup_error('unlink', '公开文件仍然存在，清理凭据已保留。'); }
        $valid = wuai_sluyu_topic_media_cleanup_current($record, $handle, $poll, $guard);
        if (is_wp_error($valid)) { return $valid; }
        // Derived LIMIT guards avoid reading the options DELETE target directly
        // on MySQL while fencing retirement against a paused stale worker.
        $retire_fence = $wpdb->prepare(" AND EXISTS (SELECT 1 FROM (SELECT option_value FROM $wpdb->options WHERE option_name=%s LIMIT 1) cleanup_retire_account WHERE HEX(cleanup_retire_account.option_value)=HEX(%s)) AND EXISTS (SELECT 1 FROM (SELECT option_value FROM $wpdb->options WHERE option_name=%s LIMIT 1) cleanup_retire_poll WHERE HEX(cleanup_retire_poll.option_value)=HEX(%s)) AND NOT EXISTS (SELECT 1 FROM (SELECT option_id FROM $wpdb->options WHERE option_name=%s LIMIT 1) cleanup_retire_native)", 'wuai_topic_media_lock_' . $handle['uid'], $handle['token'], $poll['lock'][0], $poll['lock'][1], 'wuai_topic_media_native_' . $post_id);
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND HEX(option_value)=HEX(%s)", $record['name'], $record['raw']) . $retire_fence);
        $remaining = wuai_sluyu_topic_media_cleanup_read($uid, $post_id, $id);
        wp_cache_delete($record['name'], 'options'); clean_post_cache($id);
        return $deleted === 1 && $remaining === false ? true : wuai_sluyu_topic_media_cleanup_error('finish', '文件已清理，但清理凭据未能移除，可安全重试。');
    } finally { wuai_sluyu_topic_media_cleanup_unpoll($poll); wuai_sluyu_topic_media_cleanup_leave($handle); }
}

function wuai_sluyu_topic_media_cleanup_scope($uid, $post_id = 0, $guard = null) {
    $uid = (int) $uid; $post_id = (int) $post_id;
    if ($guard !== null && !$post_id) { return wuai_sluyu_topic_media_cleanup_error('identity', '作者清理操作必须指定唯一且准确的话题。'); }
    $handle = wuai_sluyu_topic_media_cleanup_enter($uid, $guard);
    if (is_wp_error($handle)) { return $handle; }
    $scope_poll = null;
    try {
        if ($post_id) {
            $scope_poll = wuai_sluyu_topic_media_cleanup_poll($post_id);
            if (is_wp_error($scope_poll)) { return $scope_poll; }
            $valid = wuai_sluyu_topic_media_cleanup_fenced($handle, $post_id, $scope_poll, $guard);
            if (is_wp_error($valid)) { return $valid; }
        }
        $live = wuai_sluyu_topic_media_cleanup_live($uid, $post_id); $records = wuai_sluyu_topic_media_cleanup_records($uid, $post_id);
        if (is_wp_error($live)) { return $live; }
        if (is_wp_error($records)) { return $records; }
        $ids = [];
        foreach ($records as $id=>$record) { $ids[$id] = $record['value']['post']; }
        foreach ($live as $row) {
            if ((int) $row['post_author'] !== $uid) { return wuai_sluyu_topic_media_cleanup_error('identity', '专用子附件归属异常，已阻止删除所属话题。'); }
            $ids[(int) $row['ID']] = (int) $row['post_parent'];
        }
        foreach ($ids as $id=>$parent) {
            $result = wuai_sluyu_topic_media_cleanup_delete($id, $parent, $uid, $guard);
            if (is_wp_error($result)) { return $result; }
        }
        $live = wuai_sluyu_topic_media_cleanup_live($uid, $post_id); $records = wuai_sluyu_topic_media_cleanup_records($uid, $post_id);
        return !is_wp_error($live) && !is_wp_error($records) && !$live && !$records ? true : wuai_sluyu_topic_media_cleanup_error('remaining', '专用图片尚未清理完成，已阻止删除所属话题或账号。');
    } finally { wuai_sluyu_topic_media_cleanup_unpoll($scope_poll); wuai_sluyu_topic_media_cleanup_leave($handle); }
}

function wuai_sluyu_topic_media_cleanup_native_attachment($check, $post, $force) {
    if ($check !== null || !empty($GLOBALS['wuai_topic_media_cleanup_core'][$post->ID]) || !wuai_sluyu_topic_media_attachment_managed($post->ID)) { return $check; }
    $result = wuai_sluyu_topic_media_cleanup_delete((int) $post->ID, (int) $post->post_parent, (int) $post->post_author);
    if (is_wp_error($result)) { $GLOBALS['wuai_topic_media_cleanup_last_error'] = $result; return false; }
    return $post;
}
add_filter('pre_delete_attachment', 'wuai_sluyu_topic_media_cleanup_native_attachment', PHP_INT_MAX, 3);

function wuai_sluyu_topic_media_cleanup_native_post($check, $post, $force) {
    if ($check !== null || $post->post_type !== 'sluyu_topic' || !wuai_sluyu_topic_media_managed($post->ID)) { return $check; }
    if (isset($GLOBALS['wuai_topic_media_cleanup_posts'][$post->ID])) { return false; }
    $uid = (int) $post->post_author; $id = (int) $post->ID;
    $handle = wuai_sluyu_topic_media_cleanup_enter($uid);
    if (is_wp_error($handle)) { $GLOBALS['wuai_topic_media_cleanup_last_error'] = $handle; return false; }
    $poll = wuai_sluyu_topic_media_cleanup_poll($id);
    $result = is_wp_error($poll) ? $poll : wuai_sluyu_topic_media_cleanup_fenced($handle, $id, $poll);
    if (!is_wp_error($result)) { $result = wuai_sluyu_topic_media_cleanup_scope($uid, $id); }
    if (is_wp_error($result)) {
        wuai_sluyu_topic_media_cleanup_unpoll($poll); wuai_sluyu_topic_media_cleanup_leave($handle);
        $GLOBALS['wuai_topic_media_cleanup_last_error'] = $result; return false;
    }
    $GLOBALS['wuai_topic_media_cleanup_posts'][$id] = ['handle'=>$handle, 'poll'=>$poll];
    return null;
}
add_filter('pre_delete_post', 'wuai_sluyu_topic_media_cleanup_native_post', PHP_INT_MAX, 3);
add_action('deleted_post', function ($id) {
    $held = $GLOBALS['wuai_topic_media_cleanup_posts'][$id] ?? null;
    if (!$held) { return; }
    unset($GLOBALS['wuai_topic_media_cleanup_posts'][$id]);
    wuai_sluyu_topic_media_cleanup_unpoll($held['poll']); wuai_sluyu_topic_media_cleanup_leave($held['handle']);
}, PHP_INT_MAX);

function wuai_sluyu_topic_media_cleanup_user_preflight($uid, $reassign = null) {
    $uid = (int) $uid; global $wpdb;
    $media_busy = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $wpdb->options WHERE option_name=%s", 'wuai_topic_media_lock_' . $uid));
    if ($wpdb->last_error || $media_busy) { return wuai_sluyu_topic_media_cleanup_error('busy', '账号存在未完成的图片操作，已阻止删除账号。'); }
    $topics = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM $wpdb->posts p WHERE p.post_author=%d AND p.post_type='sluyu_topic' AND (p.post_name LIKE %s OR EXISTS (SELECT 1 FROM $wpdb->postmeta m WHERE m.post_id=p.ID AND m.meta_key=%s)) LIMIT 1001", $uid, $wpdb->esc_like('wuai-public-images-') . '%', wuai_sluyu_topic_media_key()));
    $failed = $wpdb->last_error || count($topics) > 1000;
    $live = wuai_sluyu_topic_media_cleanup_live($uid); $records = wuai_sluyu_topic_media_cleanup_records($uid);
    if ($failed || is_wp_error($live) || is_wp_error($records)) { return wuai_sluyu_topic_media_cleanup_error('identity', '图片归属无法核验，已阻止删除账号。'); }
    if (!$topics && !$live && !$records) { return ['needed'=>false]; }
    if ($reassign !== null) { return wuai_sluyu_topic_media_cleanup_error('reassign', '删除账号时不能重新分配受管公开图片话题，请先明确删除这些话题。'); }
    foreach ($live as $row) {
        $id = (int) $row['ID']; $parent = (int) $row['post_parent'];
        $valid = isset($records[$id]) ? wuai_sluyu_topic_media_cleanup_live_matches($records[$id]) : wuai_sluyu_topic_media_attachment($id, $parent, $uid, false);
        if (is_wp_error($valid)) { return $valid; }
        $topics[] = $parent;
    }
    foreach ($records as $record) { $topics[] = $record['value']['post']; }
    foreach (array_unique($topics) as $id) {
        $busy = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $wpdb->options WHERE option_name=%s", 'wuai_topic_media_native_' . (int) $id));
        if ($wpdb->last_error || $busy) { return wuai_sluyu_topic_media_cleanup_error('fenced', '话题后台保存尚未完成，已阻止删除账号。'); }
    }
    return ['needed'=>true];
}

function wuai_sluyu_topic_media_cleanup_user_enter($uid) {
    $uid = (int) $uid; global $wpdb;
    $shared = $GLOBALS['wuai_sluyu_user_deletion_locks'][$uid] ?? null;
    $economy = $GLOBALS['wuai_sluyu_user_deletion_economy'][$uid] ?? null;
    if (!empty($GLOBALS['wuai_topic_media_cleanup_accounts'][$uid]) || !is_array($shared) || count($shared) !== 2 || ($shared[0] ?? '') !== 'wuai_lock_user_' . $uid || !is_string($shared[1] ?? null) || ($GLOBALS['wuai_sluyu_request_locks'][$shared[0]] ?? null) !== $shared[1] || !is_array($economy) || !is_string($economy['token'] ?? null) || !function_exists('wuai_sluyu_economy_guard_valid') || !wuai_sluyu_economy_guard_valid($economy['token'])) { return wuai_sluyu_topic_media_cleanup_error('authorization', '删除账号尚未完成积分、悬赏或账号锁核验，未清理图片。'); }
    try { $token = bin2hex(random_bytes(32)); }
    catch (Throwable $error) { return wuai_sluyu_topic_media_cleanup_error('busy', '无法建立图片清理锁，未清理图片。'); }
    $name = 'wuai_topic_media_lock_' . $uid;
    $sql = $wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name,option_value,autoload) SELECT %s,%s,'no' WHERE EXISTS (SELECT 1 FROM (SELECT option_value FROM $wpdb->options WHERE option_name=%s LIMIT 1) cleanup_user_lease WHERE HEX(cleanup_user_lease.option_value)=HEX(%s)) AND ", $name, $token, $shared[0], $shared[1]) . wuai_sluyu_economy_fence($economy['token']);
    $written = $wpdb->query($sql);
    $actual = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", $name));
    if ($written !== 1 || $wpdb->last_error || !is_string($actual) || !hash_equals($token, $actual)) { return wuai_sluyu_topic_media_cleanup_error('fenced', '图片清理锁未确认建立，未继续删除账号。'); }
    // Community retains its own shared/economy locks. Only our durable media
    // fence is owned here, and survives every interruption after this handoff.
    $guard = ['name'=>$name,'token'=>$token,'shared'=>null];
    $GLOBALS['wuai_topic_media_cleanup_accounts'][$uid] = ['guard'=>$guard,'owns'=>true,'depth'=>1];
    return ['uid'=>$uid,'token'=>$token];
}

function wuai_sluyu_topic_media_cleanup_native_user_preflight($uid, $reassign = null) {
    $result = wuai_sluyu_topic_media_cleanup_user_preflight((int) $uid, $reassign);
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', ['response'=>409]); }
}
add_action('delete_user', 'wuai_sluyu_topic_media_cleanup_native_user_preflight', 0, 2);

function wuai_sluyu_topic_media_cleanup_native_user($uid, $reassign = null) {
    $uid = (int) $uid;
    $preflight = wuai_sluyu_topic_media_cleanup_user_preflight($uid, $reassign);
    if (is_wp_error($preflight)) { wp_die(esc_html($preflight->get_error_message()), '', ['response'=>409]); }
    if (!$preflight['needed']) { return; }
    $handle = wuai_sluyu_topic_media_cleanup_user_enter($uid);
    if (is_wp_error($handle)) { wp_die(esc_html($handle->get_error_message()), '', ['response'=>409]); }
    $GLOBALS['wuai_topic_media_cleanup_users'][$uid] = $handle;
    $result = wuai_sluyu_topic_media_cleanup_scope($uid);
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', ['response'=>409]); }
}
add_action('delete_user', 'wuai_sluyu_topic_media_cleanup_native_user', 2, 2);
add_action('deleted_user', function ($uid) {
    $held = $GLOBALS['wuai_topic_media_cleanup_users'][$uid] ?? null;
    if (!$held) { return; }
    unset($GLOBALS['wuai_topic_media_cleanup_users'][$uid]); wuai_sluyu_topic_media_cleanup_leave($held);
}, PHP_INT_MAX);
