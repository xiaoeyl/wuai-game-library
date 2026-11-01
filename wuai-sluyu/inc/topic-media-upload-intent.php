<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Load after topic-media.php. These are trusted internal APIs, not AJAX gates.
 * arm($guard) BEFORE wp_handle_sideload(); record() immediately after success.
 * cancel($guard, $upload) permits only a confirmed pre-move upload error.
 * finish($record, $guard, $attachment_id) transfers responsibility only to an
 * exactly verified dedicated attachment; finish(..., 0) requires absent file.
 * rollback() deletes only the persisted, unreferenced, unchanged local file.
 * topic_media_leave() must skip its durable DELETE when release_allowed() is
 * false, but may release the ordinary shared lease. Never release at shutdown.
 * Exceptions, storage errors and unknown paths retain the account fence. This
 * module has no offline recovery, quota/tombstone adoption or privacy guarantee.
 */

function wuai_sluyu_topic_media_upload_error($code, $message) {
    return new WP_Error('topic_media_upload_' . $code, $message);
}

function wuai_sluyu_topic_media_upload_guard($guard, $owner = 0, $post_id = 0) {
    if (!is_array($guard) || !isset($guard['name'], $guard['token']) || !is_string($guard['name']) || !is_string($guard['token']) || !preg_match('/^wuai_topic_media_lock_([1-9][0-9]*)$/D', $guard['name'], $match) || strlen($guard['token']) > 128 || $guard['token'] === '' || ($owner && (int) $match[1] !== (int) $owner)) { return wuai_sluyu_topic_media_upload_error('fenced', '图片上传账号锁无效，未继续操作。'); }
    global $wpdb;
    $token = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", $guard['name']));
    if ($wpdb->last_error || !is_string($token) || !hash_equals($guard['token'], $token) || ($post_id && !wuai_sluyu_topic_media_guard_valid($post_id, $guard))) { return wuai_sluyu_topic_media_upload_error('fenced', '图片上传锁或审核状态已变化，请管理员核验。'); }
    return true;
}

function wuai_sluyu_topic_media_upload_arm($guard) {
    $valid = wuai_sluyu_topic_media_upload_guard($guard);
    if (is_wp_error($valid)) { return $valid; }
    if (!empty($GLOBALS['wuai_topic_media_upload_arms'])) { return wuai_sluyu_topic_media_upload_error('busy', '当前请求已有未完成的公开图片保存，不能再次移动文件。'); }
    $GLOBALS['wuai_topic_media_upload_arms'][$guard['name']] = ['token'=>$guard['token'], 'move_seen'=>false, 'record'=>''];
    return true;
}

function wuai_sluyu_topic_media_upload_release_allowed($guard) {
    $arm = $GLOBALS['wuai_topic_media_upload_arms'][$guard['name'] ?? ''] ?? null;
    return !$arm || !isset($guard['token']) || !is_string($guard['token']) || !hash_equals($arm['token'], $guard['token']);
}

add_filter('pre_move_uploaded_file', function ($move) {
    foreach ($GLOBALS['wuai_topic_media_upload_arms'] ?? [] as $name=>$arm) { $GLOBALS['wuai_topic_media_upload_arms'][$name]['move_seen'] = true; }
    return $move;
}, PHP_INT_MAX);

function wuai_sluyu_topic_media_upload_cancel($guard, $upload) {
    $valid = wuai_sluyu_topic_media_upload_guard($guard);
    if (is_wp_error($valid)) { return $valid; }
    $arm = $GLOBALS['wuai_topic_media_upload_arms'][$guard['name']] ?? null;
    if (!$arm || !hash_equals($arm['token'], $guard['token']) || $arm['move_seen'] || $arm['record'] !== '' || !is_array($upload) || !isset($upload['error']) || !is_string($upload['error']) || $upload['error'] === '' || isset($upload['file'])) { return wuai_sluyu_topic_media_upload_error('unknown', '尚不能确认没有公开文件，账号图片锁已保留，请管理员核验。'); }
    unset($GLOBALS['wuai_topic_media_upload_arms'][$guard['name']]);
    return true;
}

function wuai_sluyu_topic_media_upload_read($name) {
    global $wpdb;
    if (!is_string($name) || !preg_match('/^wuai_topic_media_raw_[1-9][0-9]*_[1-9][0-9]*_[a-f0-9-]{36}$/D', $name)) { return wuai_sluyu_topic_media_upload_error('record', '原始图片保存凭据名称无效。'); }
    $rows = $wpdb->get_col($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", $name));
    if ($wpdb->last_error || count($rows) > 1) { return wuai_sluyu_topic_media_upload_error('storage', '无法核验原始图片保存凭据，账号图片锁继续保留。'); }
    if (!$rows) { return false; }
    $raw = $rows[0]; $v = is_string($raw) && strlen($raw) <= 16384 && is_serialized($raw) ? @unserialize($raw, ['allowed_classes'=>false]) : false;
    if (!is_array($v) || array_keys($v) !== ['schema','owner','post','request','base','file','mime','width','height','size','sha256','fence','created'] || $v['schema'] !== 1 || !is_int($v['owner']) || $v['owner'] < 1 || !is_int($v['post']) || $v['post'] < 1 || !wuai_sluyu_topic_media_uuid($v['request']) || !is_string($v['base']) || $v['base'] === '' || strlen($v['base']) > 4096 || strpos($v['base'], "\0") !== false || !is_string($v['file']) || !preg_match('~^(?:[0-9]{4}/[0-9]{2}/)?topic-public-[a-f0-9-]{36}\.(?:png|jpg)$~D', $v['file']) || !in_array($v['mime'], ['image/png','image/jpeg'], true) || !is_int($v['width']) || !is_int($v['height']) || min($v['width'], $v['height']) < 1 || max($v['width'], $v['height']) > 1600 || !is_int($v['size']) || $v['size'] < 1 || $v['size'] > 4 * MB_IN_BYTES || !is_string($v['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $v['sha256']) || !is_string($v['fence']) || !preg_match('/^[a-f0-9]{64}$/D', $v['fence']) || !is_int($v['created']) || $v['created'] < 1 || $name !== 'wuai_topic_media_raw_' . $v['owner'] . '_' . $v['post'] . '_' . $v['request']) { return wuai_sluyu_topic_media_upload_error('record', '原始图片保存凭据不完整，不能自动删除文件。'); }
    return ['name'=>$name,'raw'=>$raw,'value'=>$v];
}

function wuai_sluyu_topic_media_upload_path($record) {
    $v = $record['value']; $upload = wp_upload_dir(null, false); $base = empty($upload['error']) ? realpath($upload['basedir']) : false;
    if (!$base || wp_normalize_path($base) !== $v['base'] || !is_readable($base)) { return wuai_sluyu_topic_media_upload_error('path', '原本地上传目录不可用或已变化，不能确认文件状态。'); }
    $path = $v['base']; $segments = explode('/', $v['file']);
    foreach ($segments as $index=>$segment) {
        $path .= '/' . $segment; clearstatcache(true, $path);
        if (is_link($path)) { return wuai_sluyu_topic_media_upload_error('path', '图片路径包含符号链接，未删除任何文件。'); }
        if (!file_exists($path)) { return ['path'=>$v['base'] . '/' . $v['file'], 'exists'=>false]; }
        $real = realpath($path);
        if (!$real || wp_normalize_path($real) !== $path || !is_readable($path) || ($index < count($segments) - 1 && !is_dir($path))) { return wuai_sluyu_topic_media_upload_error('path', '图片路径不能精确核验，未删除任何文件。'); }
    }
    $dimensions = @getimagesize($path);
    if (!is_file($path) || filesize($path) !== $v['size'] || !$dimensions || (int) $dimensions[0] !== $v['width'] || (int) $dimensions[1] !== $v['height'] || wp_get_image_mime($path) !== $v['mime']) { return wuai_sluyu_topic_media_upload_error('file', '公开文件与重编码图片的尺寸或类型不符，请管理员核验。'); }
    $hash = hash_file('sha256', $path);
    return is_string($hash) && hash_equals($v['sha256'], $hash) ? ['path'=>$path,'exists'=>true] : wuai_sluyu_topic_media_upload_error('file', '公开文件内容已变化，不能自动删除。');
}

function wuai_sluyu_topic_media_upload_record($upload, $encoded, $owner, $post_id, $request, $guard) {
    $valid = wuai_sluyu_topic_media_upload_guard($guard, $owner, $post_id);
    if (is_wp_error($valid)) { return $valid; }
    $arm = $GLOBALS['wuai_topic_media_upload_arms'][$guard['name']] ?? null;
    if (!$arm || !hash_equals($arm['token'], $guard['token']) || $arm['record'] !== '' || !is_array($upload) || isset($upload['error']) || !isset($upload['file']) || !is_string($upload['file']) || !is_array($encoded) || !isset($encoded['mime'], $encoded['width'], $encoded['height'], $encoded['sha256']) || !in_array($encoded['mime'], ['image/png','image/jpeg'], true) || !is_int($encoded['width']) || !is_int($encoded['height']) || min($encoded['width'], $encoded['height']) < 1 || max($encoded['width'], $encoded['height']) > 1600 || !is_string($encoded['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $encoded['sha256']) || !wuai_sluyu_topic_media_uuid($request)) { return wuai_sluyu_topic_media_upload_error('record', '公开图片保存状态不明，账号图片锁已保留。'); }
    $dirs = wp_upload_dir(null, false); $base = empty($dirs['error']) ? realpath($dirs['basedir']) : false; $absolute = wp_normalize_path($upload['file']);
    if (!$base || strpos($absolute, trailingslashit(wp_normalize_path($base))) !== 0) { return wuai_sluyu_topic_media_upload_error('path', '公开图片不在已核验的本地上传目录内，未猜测删除路径。'); }
    $base = wp_normalize_path($base); $relative = substr($absolute, strlen($base) + 1); clearstatcache(true, $absolute);
    if (!preg_match('~^(?:[0-9]{4}/[0-9]{2}/)?topic-public-[a-f0-9-]{36}\.(?:png|jpg)$~D', $relative) || !is_file($absolute) || is_link($absolute)) { return wuai_sluyu_topic_media_upload_error('path', '公开图片的专用文件名或路径无法核验。'); }
    $name = 'wuai_topic_media_raw_' . (int) $owner . '_' . (int) $post_id . '_' . $request;
    $v = ['schema'=>1,'owner'=>(int) $owner,'post'=>(int) $post_id,'request'=>$request,'base'=>$base,'file'=>$relative,'mime'=>$encoded['mime'],'width'=>$encoded['width'],'height'=>$encoded['height'],'size'=>(int) filesize($absolute),'sha256'=>$encoded['sha256'],'fence'=>hash('sha256', $guard['token']),'created'=>time()];
    $raw = serialize($v); $record = ['name'=>$name,'raw'=>$raw,'value'=>$v]; $path = wuai_sluyu_topic_media_upload_path($record);
    if (is_wp_error($path) || !$path['exists'] || $v['size'] < 1 || $v['size'] > 4 * MB_IN_BYTES) { return is_wp_error($path) ? $path : wuai_sluyu_topic_media_upload_error('file', '公开图片文件缺失或超过大小限制。'); }
    global $wpdb;
    $GLOBALS['wuai_topic_media_upload_arms'][$guard['name']]['record'] = $name;
    $written = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name,option_value,autoload) VALUES (%s,%s,'no')", $name, $raw));
    $stored = wuai_sluyu_topic_media_upload_read($name);
    if ($written !== 1 || is_wp_error($stored) || !$stored || !hash_equals($raw, $stored['raw'])) { return wuai_sluyu_topic_media_upload_error('persist', '公开图片的清理凭据未确认保存，账号图片操作已冻结，请管理员核验。'); }
    return $stored;
}

function wuai_sluyu_topic_media_upload_current($record, $guard) {
    if (!is_array($record) || !isset($record['name'], $record['raw']) || !is_string($record['raw'])) { return wuai_sluyu_topic_media_upload_error('record', '公开图片凭据参数无效。'); }
    $stored = wuai_sluyu_topic_media_upload_read($record['name']);
    if (is_wp_error($stored) || !$stored || !hash_equals($record['raw'], $stored['raw'])) { return wuai_sluyu_topic_media_upload_error('changed', '公开图片保存凭据已变化，账号图片锁继续保留。'); }
    $valid = wuai_sluyu_topic_media_upload_guard($guard, $stored['value']['owner'], $stored['value']['post']);
    if (is_wp_error($valid)) { return $valid; }
    $arm = $GLOBALS['wuai_topic_media_upload_arms'][$guard['name']] ?? null;
    if (!$arm || !hash_equals($arm['token'], $guard['token']) || $arm['record'] !== $stored['name'] || !hash_equals($stored['value']['fence'], hash('sha256', $guard['token']))) { return wuai_sluyu_topic_media_upload_error('fenced', '当前请求不持有此公开图片保存凭据，不能继续操作。'); }
    return $stored;
}

function wuai_sluyu_topic_media_upload_unreferenced($record) {
    global $wpdb;
    $other = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_wp_attached_file' AND HEX(meta_value)=HEX(%s) LIMIT 1", $record['value']['file']));
    return !$wpdb->last_error && !$other ? true : wuai_sluyu_topic_media_upload_error('referenced', '已有附件引用此公开文件，未执行原始文件回滚。');
}

function wuai_sluyu_topic_media_upload_finish($record, $guard, $attachment_id = 0) {
    $record = wuai_sluyu_topic_media_upload_current($record, $guard);
    if (is_wp_error($record)) { return $record; }
    $v = $record['value']; $path = wuai_sluyu_topic_media_upload_path($record);
    if (is_wp_error($path)) { return $path; }
    if ($attachment_id) {
        $image = wuai_sluyu_topic_media_attachment((int) $attachment_id, $v['post'], $v['owner']);
        if (is_wp_error($image) || !$path['exists'] || $image['file'] !== $v['file'] || $image['request'] !== $v['request'] || $image['mime'] !== $v['mime'] || $image['width'] !== $v['width'] || $image['height'] !== $v['height'] || !hash_equals($v['sha256'], $image['sha256'])) { return wuai_sluyu_topic_media_upload_error('binding', '专用附件未精确接管此公开文件，账号图片锁继续保留。'); }
    } else {
        $unreferenced = wuai_sluyu_topic_media_upload_unreferenced($record);
        if (is_wp_error($unreferenced)) { return $unreferenced; }
        if ($path['exists']) { return wuai_sluyu_topic_media_upload_error('remaining', '公开文件仍存在，不能撤销保存凭据或释放账号图片锁。'); }
    }
    global $wpdb;
    $fence = $wpdb->prepare(" AND EXISTS (SELECT 1 FROM (SELECT option_value FROM $wpdb->options WHERE option_name=%s LIMIT 1) raw_account WHERE HEX(raw_account.option_value)=HEX(%s)) AND EXISTS (SELECT 1 FROM (SELECT option_value FROM $wpdb->options WHERE option_name=%s LIMIT 1) raw_poll WHERE HEX(raw_poll.option_value)=HEX(%s)) AND NOT EXISTS (SELECT 1 FROM (SELECT option_id FROM $wpdb->options WHERE option_name=%s LIMIT 1) raw_native)", $guard['name'], $guard['token'], $guard['post_lock'][0], $guard['post_lock'][1], 'wuai_topic_media_native_' . $v['post']);
    $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND HEX(option_value)=HEX(%s)", $record['name'], $record['raw']) . $fence . ' AND ' . wuai_sluyu_topic_media_hold_sql($v['post']));
    $stored = wuai_sluyu_topic_media_upload_read($record['name']); wp_cache_delete($record['name'], 'options');
    if ($deleted !== 1 || $stored !== false) { return wuai_sluyu_topic_media_upload_error('finish', '文件状态已核验，但保存凭据未确认移除，账号图片锁继续保留。'); }
    unset($GLOBALS['wuai_topic_media_upload_arms'][$guard['name']]);
    return true;
}

function wuai_sluyu_topic_media_upload_rollback($record, $guard) {
    $record = wuai_sluyu_topic_media_upload_current($record, $guard);
    if (is_wp_error($record)) { return $record; }
    $unreferenced = wuai_sluyu_topic_media_upload_unreferenced($record); $path = wuai_sluyu_topic_media_upload_path($record);
    if (is_wp_error($unreferenced)) { return $unreferenced; }
    if (is_wp_error($path)) { return $path; }
    if ($path['exists']) {
        $filtered = apply_filters('wp_delete_file', $path['path']);
        if (!is_string($filtered) || $filtered !== $path['path']) { return wuai_sluyu_topic_media_upload_error('rollback', '原始公开文件删除被拒绝或路径被重定向，账号图片锁和凭据已保留。'); }
        $fresh = wuai_sluyu_topic_media_upload_current($record, $guard); $path = wuai_sluyu_topic_media_upload_path($record); $unreferenced = wuai_sluyu_topic_media_upload_unreferenced($record);
        if (is_wp_error($fresh)) { return $fresh; }
        if (is_wp_error($path)) { return $path; }
        if (is_wp_error($unreferenced)) { return $unreferenced; }
        if ($path['exists'] && !@unlink($path['path'])) { return wuai_sluyu_topic_media_upload_error('rollback', '原始公开文件未能删除，账号图片操作已冻结，请管理员停机核验。'); }
        clearstatcache(true, $path['path']);
    }
    return wuai_sluyu_topic_media_upload_finish($record, $guard);
}
