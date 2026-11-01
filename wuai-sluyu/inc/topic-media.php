<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_topic_media_key() { return '_wuai_topic_media'; }
function wuai_sluyu_topic_media_marker() { return '_wuai_topic_public_image'; }
function wuai_sluyu_topic_media_error($code, $message) { return new WP_Error('topic_media_' . $code, $message); }
function wuai_sluyu_topic_media_managed($id) {
    $post = get_post($id);
    return $post && (metadata_exists('post', $id, wuai_sluyu_topic_media_key()) || strpos($post->post_name, 'wuai-public-images-') === 0);
}
function wuai_sluyu_topic_media_review_private($id) { return wuai_sluyu_topic_media_managed($id); }
function wuai_sluyu_topic_media_uuid($value) { return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value); }

function wuai_sluyu_topic_media_raw($id, $key) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_value FROM $wpdb->postmeta WHERE post_id=%d AND meta_key=%s ORDER BY meta_id", $id, $key), ARRAY_A);
    if ($wpdb->last_error || count($rows) > 1) { return wuai_sluyu_topic_media_error('storage', '图片关联记录无法核验，请联系管理员；不要重复上传。'); }
    if (!$rows) { return false; }
    $raw = $rows[0]['meta_value'];
    if (!is_string($raw) || strlen($raw) > 65536 || !is_serialized($raw)) { return wuai_sluyu_topic_media_error('storage', '图片关联记录异常，请联系管理员。'); }
    $value = @unserialize($raw, ['allowed_classes'=>false]);
    return is_array($value) ? ['meta_id'=>(int) $rows[0]['meta_id'], 'raw'=>$raw, 'value'=>$value] : wuai_sluyu_topic_media_error('storage', '图片关联记录异常，请联系管理员。');
}

function wuai_sluyu_topic_media_state($id) {
    $row = wuai_sluyu_topic_media_raw($id, wuai_sluyu_topic_media_key());
    if (is_wp_error($row) || !$row) { return $row; }
    $s = $row['value'];
    if (array_keys($s) !== ['schema','owner','group','request','version','phase','items'] || $s['schema'] !== 1 || !is_int($s['owner']) || $s['owner'] < 1 || !is_int($s['group']) || $s['group'] < 0 || !wuai_sluyu_topic_media_uuid($s['request']) || !is_int($s['version']) || $s['version'] < 1 || !in_array($s['phase'], ['draft','submitted','abandoned'], true) || !is_array($s['items']) || count($s['items']) > 5 || array_values($s['items']) !== $s['items']) { return wuai_sluyu_topic_media_error('state', '图片稿件状态异常，请联系管理员核验。'); }
    $ids = []; $keys = [];
    foreach ($s['items'] as $item) {
        if (!is_array($item) || array_keys($item) !== ['id','request'] || !is_int($item['id']) || $item['id'] < 1 || !wuai_sluyu_topic_media_uuid($item['request']) || isset($ids[$item['id']]) || isset($keys[$item['request']])) { return wuai_sluyu_topic_media_error('state', '图片列表异常，请联系管理员核验。'); }
        $ids[$item['id']] = true; $keys[$item['request']] = true;
    }
    return $row;
}

function wuai_sluyu_topic_media_enter($uid) {
    $shared = wuai_sluyu_lock('user_' . $uid); if (!$shared) { return wuai_sluyu_topic_media_error('busy', '账号正在处理其他操作，请稍后重试。'); }
    try { $token = bin2hex(random_bytes(32)); } catch (Throwable $error) { wuai_sluyu_unlock($shared); return wuai_sluyu_topic_media_error('busy', '安全标识生成失败，未执行操作。'); }
    global $wpdb; $name = 'wuai_topic_media_lock_' . $uid;
    $written = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name,option_value,autoload) VALUES (%s,%s,'no')", $name, $token));
    if ($written !== 1) { wuai_sluyu_unlock($shared); return wuai_sluyu_topic_media_error('busy', '另一项图片操作仍在执行或需要管理员检查，请勿重复上传。'); }
    // A crashed worker leaves this per-account fence. Never steal it by age or
    // release it in shutdown: an interrupted write needs maintenance inspection.
    return ['name'=>$name, 'token'=>$token, 'shared'=>$shared];
}
function wuai_sluyu_topic_media_leave($guard) {
    global $wpdb;
    $hold = $GLOBALS['wuai_topic_media_author_holds'][$guard['name']] ?? '';
    if ((!is_string($hold) || !hash_equals($guard['token'], $hold)) && wuai_sluyu_topic_media_upload_release_allowed($guard)) {
        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND HEX(option_value)=HEX(%s)", $guard['name'], $guard['token']));
    }
    wuai_sluyu_unlock($guard['shared']);
}
function wuai_sluyu_topic_media_fence($guard) {
    global $wpdb;
    $sql = $wpdb->prepare("EXISTS (SELECT 1 FROM $wpdb->options media_lock WHERE media_lock.option_name=%s AND HEX(media_lock.option_value)=HEX(%s))", $guard['name'], $guard['token']);
    if (!empty($guard['post_id'])) {
        if (empty($guard['post_lock']) || !is_array($guard['post_lock']) || $guard['post_lock'][0] !== 'wuai_lock_poll_' . (int) $guard['post_id']) { return '0=1'; }
        $sql .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM $wpdb->options media_poll_lock WHERE media_poll_lock.option_name=%s AND HEX(media_poll_lock.option_value)=HEX(%s))", $guard['post_lock'][0], $guard['post_lock'][1]);
        $sql .= $wpdb->prepare(" AND NOT EXISTS (SELECT 1 FROM $wpdb->options media_native_lock WHERE media_native_lock.option_name=%s)", 'wuai_topic_media_native_' . (int) $guard['post_id']);
    }
    return $sql;
}
function wuai_sluyu_topic_media_hold_sql($id) {
    global $wpdb;
    // LIMIT prevents MySQL from merging this derived target-table read into
    // the UPDATE (1093). Verify the bounded form on each supported DB adapter.
    return $wpdb->prepare("NOT EXISTS (SELECT 1 FROM (SELECT meta_id FROM $wpdb->postmeta WHERE post_id=%d AND meta_key='_wuai_circle_review_hold' LIMIT 1) media_hold)", $id);
}
function wuai_sluyu_topic_media_guard_valid($id, $guard) {
    if ((int) ($guard['post_id'] ?? 0) !== (int) $id) { return false; }
    global $wpdb;
    $valid = $wpdb->get_var('SELECT 1 WHERE ' . wuai_sluyu_topic_media_fence($guard) . ' AND ' . wuai_sluyu_topic_media_hold_sql($id));
    return !$wpdb->last_error && (string) $valid === '1';
}

function wuai_sluyu_topic_media_identity_sql($id, $row, $old) {
    global $wpdb;
    $sql = '1=1';
    foreach (wuai_sluyu_topic_media_post_columns() as $key) {
        $sql .= in_array($key, ['post_author','post_parent','menu_order'], true) ? $wpdb->prepare(' AND `' . $key . '`=%d', $old->$key) : $wpdb->prepare(' AND HEX(`' . $key . '`)=HEX(%s)', (string) $old->$key);
    }
    if ($row) {
        $sql .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM $wpdb->postmeta media_state WHERE media_state.meta_id=%d AND media_state.post_id=%d AND media_state.meta_key=%s AND HEX(media_state.meta_value)=HEX(%s))", $row['meta_id'], $id, wuai_sluyu_topic_media_key(), $row['raw']);
    } else {
        $sql .= $wpdb->prepare(" AND NOT EXISTS (SELECT 1 FROM $wpdb->postmeta media_state WHERE media_state.post_id=%d AND media_state.meta_key=%s)", $id, wuai_sluyu_topic_media_key());
    }
    return $sql;
}

function wuai_sluyu_topic_media_audience_sql($id, $row) {
    global $wpdb;
    if (!$row) { return '0=1'; }
    $uid = $row['value']['owner']; $group = $row['value']['group'];
    $sql = $wpdb->prepare("EXISTS (SELECT 1 FROM $wpdb->users media_author WHERE media_author.ID=%d) AND NOT EXISTS (SELECT 1 FROM $wpdb->usermeta media_ban WHERE media_ban.user_id=%d AND media_ban.meta_key='_wuai_banned_until' AND (media_ban.meta_value='-1' OR CAST(media_ban.meta_value AS SIGNED)>%d))", $uid, $uid, time());
    $sql .= $wpdb->prepare(" AND NOT EXISTS (SELECT 1 FROM $wpdb->postmeta media_private WHERE media_private.post_id=%d AND (media_private.meta_key IN ('_wuai_protected_content','_wuai_guess_config','_wuai_download_settings','_wuai_sluyu_download_url') OR (media_private.meta_key='_wuai_topic_kind' AND media_private.meta_value<>'topic')))", $id);
    $sql .= $wpdb->prepare(" AND NOT EXISTS (SELECT 1 FROM $wpdb->term_relationships media_rel INNER JOIN $wpdb->term_taxonomy media_tax ON media_tax.term_taxonomy_id=media_rel.term_taxonomy_id WHERE media_rel.object_id=%d AND media_tax.taxonomy='sluyu_circle' AND media_tax.term_id<>%d)", $id, $group);
    if ($group) {
        $sql .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM $wpdb->term_relationships media_rel INNER JOIN $wpdb->term_taxonomy media_tax ON media_tax.term_taxonomy_id=media_rel.term_taxonomy_id INNER JOIN $wpdb->terms media_term ON media_term.term_id=media_tax.term_id WHERE media_rel.object_id=%d AND media_tax.taxonomy='sluyu_circle' AND media_tax.term_id=%d AND (EXISTS (SELECT 1 FROM $wpdb->termmeta media_public WHERE media_public.term_id=media_term.term_id AND media_public.meta_key='_wuai_circle_status' AND media_public.meta_value='publish') OR (NOT EXISTS (SELECT 1 FROM $wpdb->termmeta media_status WHERE media_status.term_id=media_term.term_id AND media_status.meta_key='_wuai_circle_status') AND media_term.slug NOT LIKE 'wuai-circle-request-%%' AND media_term.slug NOT REGEXP %s)))", $id, $group, wuai_sluyu_circle_old_request_pattern());
        $sql .= $wpdb->prepare(" AND NOT EXISTS (SELECT 1 FROM $wpdb->termmeta media_circle WHERE media_circle.term_id=%d AND ((media_circle.meta_key='_wuai_circle_status' AND media_circle.meta_value<>'publish') OR (media_circle.meta_key='_wuai_circle_join' AND media_circle.meta_value NOT IN ('','open')) OR (media_circle.meta_key='_wuai_circle_posting' AND media_circle.meta_value NOT IN ('','everyone','all'))))", $group);
        $sql .= $wpdb->prepare(' AND NOT EXISTS (SELECT 1 FROM ' . wuai_sluyu_table('memberships') . " media_membership WHERE media_membership.circle_id=%d AND media_membership.user_id=%d AND media_membership.status='blocked')", $group, $uid);
    }
    if (function_exists('wuai_sluyu_bounty_exists')) { $sql .= $wpdb->prepare(' AND NOT EXISTS (SELECT 1 FROM ' . wuai_sluyu_table('bounties') . ' media_bounty WHERE media_bounty.question_id=%d)', $id); }
    return $sql;
}

function wuai_sluyu_topic_media_post_columns() {
    return ['post_author','post_date','post_date_gmt','post_content','post_content_filtered','post_title','post_excerpt','post_status','post_type','comment_status','ping_status','post_password','post_name','to_ping','pinged','post_modified','post_modified_gmt','post_parent','menu_order','post_mime_type','guid'];
}
function wuai_sluyu_topic_media_post_sql($id, $data) {
    global $wpdb; $fields = []; $values = [];
    if (!$data || !preg_match('/^[a-zA-Z0-9_]+$/D', $wpdb->posts) || ($wpdb->field_types['ID'] ?? '%s') !== '%d') { return false; }
    foreach ($data as $key=>$value) {
        $format = in_array($key, ['post_author','post_parent','menu_order'], true) ? '%d' : '%s';
        if (!in_array($key, wuai_sluyu_topic_media_post_columns(), true) || !is_scalar($value) || ($wpdb->field_types[$key] ?? '%s') !== $format) { return false; }
        $fields[] = '`' . $key . '` = ' . $format; $values[] = $value;
    }
    $values[] = $id;
    // Match wpdb::update exactly. Unsupported adapter/field-format changes fail
    // closed; the normal query filter has already removed prepare's % escape.
    return $wpdb->remove_placeholder_escape($wpdb->prepare('UPDATE `' . $wpdb->posts . '` SET ' . implode(', ', $fields) . ' WHERE `ID` = %d', $values));
}
function wuai_sluyu_topic_media_db_origin() {
    global $wpdb;
    $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 14);
    foreach ($trace as $index=>$frame) {
        if (($frame['object'] ?? null) === $wpdb && ($frame['function'] ?? '') === 'query') {
            if (($trace[$index + 1]['object'] ?? null) === $wpdb && ($trace[$index + 1]['function'] ?? '') === 'query') { continue; }
            $caller = $trace[$index + 1] ?? [];
            // wpdb::update has replaced its arguments with process_fields()
            // descriptors before query filters inspect the call stack.
            if (($caller['object'] ?? null) === $wpdb && ($caller['function'] ?? '') === 'update') {
                foreach ([1,2] as $position) {
                    foreach (($caller['args'][$position] ?? []) as $key=>$value) {
                        if (is_array($value) && array_key_exists('value', $value) && isset($value['format'])) { $caller['args'][$position][$key] = $value['value']; }
                    }
                }
            }
            return ['caller'=>$caller, 'parent'=>$trace[$index + 2] ?? []];
        }
    }
    return [];
}
// Execute before core's taxonomy, transitions and save hooks. Trusted query
// filters must preserve this exact SQL; hostile plugins with DB access are not
// a security boundary. A true MySQL no-op needs both the original predicate and
// every desired persisted field, not just a zero affected-row count.
function wuai_sluyu_topic_media_post_cas($sql, $predicate, $id = 0, $data = []) {
    global $wpdb;
    $expected = $wpdb->remove_placeholder_escape($sql . ' AND ' . $predicate); $seen = false;
    $verify = static function ($query) use ($expected, &$seen) {
        $origin = wuai_sluyu_topic_media_db_origin();
        if (($origin['caller']['function'] ?? '') !== 'wuai_sluyu_topic_media_post_cas') { return $query; }
        if (!is_string($query) || !hash_equals($expected, $query)) { throw new RuntimeException('topic_media_post_sql'); }
        $seen = true; return $query;
    };
    add_filter('query', $verify, PHP_INT_MAX);
    try { $written = $wpdb->query($expected); }
    finally { remove_filter('query', $verify, PHP_INT_MAX); }
    if (!$seen || $written === false) { return false; }
    if ($written === 1) { return true; }
    if ($written !== 0 || !$id || !wuai_sluyu_topic_media_post_sql($id, $data)) { return false; }
    $check = $wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE ID=%d AND ", $id) . $predicate;
    foreach ($data as $key=>$value) {
        $check .= in_array($key, ['post_author','post_parent','menu_order'], true) ? $wpdb->prepare(' AND `' . $key . '`=%d', $value) : $wpdb->prepare(' AND HEX(`' . $key . '`)=HEX(%s)', (string) $value);
    }
    $actual = $wpdb->get_var($check);
    return !$wpdb->last_error && (string) $actual === (string) $id;
}

function wuai_sluyu_topic_media_post_update($id, $row, $guard, $fields, $trash = false) {
    if (!wuai_sluyu_topic_media_guard_valid($id, $guard)) { return wuai_sluyu_topic_media_error('fenced', '审核标记或话题锁已变化，稿件未写入。'); }
    clean_post_cache($id); $old = get_post($id); if (!$old) { return wuai_sluyu_topic_media_error('missing', '稿件已不存在。'); }
    $fenced = false; $executing = false; $expected = false; $final = [];
    $prepare = static function ($target, $data) use ($id, $fields, $old, &$expected, &$final) {
        if ((int) $target !== (int) $id) { return; }
        foreach (['post_author','post_type','post_name','post_status','post_password','post_title','post_content','post_excerpt'] as $key) {
            if (!array_key_exists($key, $data) || (string) $data[$key] !== (string) ($fields[$key] ?? $old->$key)) { throw new RuntimeException('topic_media_post_data'); }
        }
        $expected = wuai_sluyu_topic_media_post_sql($id, $data); $final = $data;
        if (!$expected) { throw new RuntimeException('topic_media_post_format'); }
    };
    $filter = static function ($sql) use ($id, $row, $guard, $fields, $old, &$fenced, &$executing, &$expected, &$final) {
        global $wpdb;
        if ($executing) { return $sql; }
        $origin = wuai_sluyu_topic_media_db_origin(); $call = $origin['caller'] ?? [];
        if (($call['object'] ?? null) !== $wpdb || ($call['function'] ?? '') !== 'update' || ($call['args'][0] ?? '') !== $wpdb->posts) { return $sql; }
        $where = $call['args'][2] ?? [];
        $core_input = $origin['parent']['args'][0] ?? null;
        $core_id = is_array($core_input) ? ($core_input['ID'] ?? 0) : 0;
        if (($origin['parent']['function'] ?? '') === 'wp_insert_post' && is_scalar($core_id) && (int) $core_id === (int) $id && (!is_array($where) || array_keys($where) !== ['ID'] || !is_scalar($where['ID']) || (int) $where['ID'] !== (int) $id)) { throw new RuntimeException('topic_media_post_where'); }
        if (!is_array($where) || !isset($where['ID']) || !is_scalar($where['ID']) || (int) $where['ID'] !== (int) $id) { return $sql; }
        if (($origin['parent']['function'] ?? '') === 'wp_add_trashed_suffix_to_post_name_for_post') { return 'SELECT 0 /* Dedicated media slug stays immutable. */'; }
        if (array_keys($call['args'][1] ?? []) === ['comment_count']) { return $sql; }
        if (($origin['parent']['function'] ?? '') !== 'wp_insert_post' || !$expected || $fenced || !is_string($sql) || !hash_equals($expected, $sql)) { throw new RuntimeException('topic_media_post_shape'); }
        $executing = true;
        try { $fenced = wuai_sluyu_topic_media_post_cas($sql, wuai_sluyu_topic_media_identity_sql($id, $row, $old) . ' AND ' . wuai_sluyu_topic_media_fence($guard) . ' AND ' . wuai_sluyu_topic_media_hold_sql($id) . ' AND ' . wuai_sluyu_topic_media_audience_sql($id, $row), $id, $final); }
        finally { $executing = false; }
        if (!$fenced) { throw new RuntimeException('topic_media_post_cas'); }
        return 'SELECT 1 /* Dedicated media post CAS already committed. */';
    };
    add_action('pre_post_update', $prepare, PHP_INT_MAX, 2);
    add_filter('query', $filter, PHP_INT_MAX);
    $trash_started = false;
    $begin_trash = static function ($target) use ($id, $guard, &$trash_started) {
        if ((int) $target !== (int) $id) { return; }
        $trash_started = true;
        $GLOBALS['wuai_topic_media_author_holds'][$guard['name']] = $guard['token'];
    };
    if ($trash) { add_action('wp_trash_post', $begin_trash, PHP_INT_MIN); }
    $GLOBALS['wuai_topic_media_author_update'][$id] = true;
    try { $saved = $trash ? wp_trash_post($id) : wp_update_post(wp_slash(array_merge(['ID'=>$id], $fields)), true); }
    catch (RuntimeException $error) { $saved = wuai_sluyu_topic_media_error('post_write', '稿件版本或写入条件已变化，未执行保存与状态通知，请刷新核对。'); }
    finally { remove_action('pre_post_update', $prepare, PHP_INT_MAX); remove_filter('query', $filter, PHP_INT_MAX); if ($trash) { remove_action('wp_trash_post', $begin_trash, PHP_INT_MIN); } unset($GLOBALS['wuai_topic_media_author_update'][$id]); }
    clean_post_cache($id); $actual = get_post($id); $stored = wuai_sluyu_topic_media_state($id);
    if (!$fenced || is_wp_error($saved) || !$saved || !$actual || !wuai_sluyu_topic_media_guard_valid($id, $guard)) { return wuai_sluyu_topic_media_error('post_write', '稿件变更未核验成功，请刷新核对。'); }
    if (is_wp_error($stored) || !$stored || !hash_equals($row['raw'], $stored['raw'])) { return wuai_sluyu_topic_media_error('post_write', '稿件图片版本已变化，未确认原操作完成。'); }
    foreach ($fields as $key=>$value) { if ((string) $actual->$key !== (string) $value) { return wuai_sluyu_topic_media_error('post_write', '稿件变更未核验成功，请刷新核对。'); } }
    foreach (['post_author','post_type','post_name','post_status','post_password','post_title','post_content','post_excerpt'] as $key) { if (!array_key_exists($key, $fields) && (string) $actual->$key !== (string) $old->$key) { return wuai_sluyu_topic_media_error('post_write', '稿件其他字段在操作期间变化，请刷新核对。'); } }
    if ($trash_started && ($GLOBALS['wuai_topic_media_author_holds'][$guard['name']] ?? null) === $guard['token']) { unset($GLOBALS['wuai_topic_media_author_holds'][$guard['name']]); }
    return true;
}

function wuai_sluyu_topic_media_public_group($group, $uid) {
    if (!$group) { return true; }
    clean_term_cache($group, 'sluyu_circle'); wp_cache_delete($group, 'term_meta');
    if (!wuai_sluyu_circle($group) || !wuai_sluyu_circle_can_post($group, $uid)) { return false; }
    $join = get_term_meta($group, '_wuai_circle_join', true); $posting = get_term_meta($group, '_wuai_circle_posting', true);
    return in_array($join, ['', 'open'], true) && in_array($posting, ['', 'everyone', 'all'], true);
}
function wuai_sluyu_topic_media_permission($id, $uid, $draft = true) {
    clean_user_cache($uid); wp_cache_delete($uid, 'user_meta'); clean_post_cache($id);
    $post = get_post($id); $row = wuai_sluyu_topic_media_state($id);
    if (!$uid || $uid !== get_current_user_id() || !get_userdata($uid) || wuai_sluyu_banned($uid) || !$post || is_wp_error($row) || !$row || $post->post_type !== 'sluyu_topic' || (int) $post->post_author !== $uid || $row['value']['owner'] !== $uid || $post->post_password !== '' || get_post_meta($id, '_wuai_topic_kind', true) !== 'topic') { return wuai_sluyu_topic_media_error('permission', '只能管理本人正常状态的公开图片话题，不支持私密或其他类型稿件。'); }
    if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($id)) { return wuai_sluyu_topic_media_error('review_held', '此图片话题的审核尚未完成，请站点管理员核验后再修改、撤回或删除。'); }
    foreach (['_wuai_protected_content','_wuai_guess_config','_wuai_download_settings','_wuai_sluyu_download_url'] as $key) { if (metadata_exists('post', $id, $key)) { return wuai_sluyu_topic_media_error('private', '此稿件包含受限配置，图片上传已停止；已上传图片直链仍可能公开。请联系管理员。'); } }
    if (function_exists('wuai_sluyu_bounty_exists')) { $bounty = wuai_sluyu_bounty_exists($id); if ($bounty !== false) { return wuai_sluyu_topic_media_error('bounty', '悬赏记录存在或无法核验，不支持添加公开图片。'); } }
    $group = $row['value']['group']; $groups = wp_get_object_terms($id, 'sluyu_circle', ['fields'=>'ids','wuai_include_pending'=>true]);
    if (is_wp_error($groups) || array_map('intval', $groups) !== ($group ? [$group] : []) || !wuai_sluyu_topic_media_public_group($group, $uid)) { return wuai_sluyu_topic_media_error('circle', '原圈子权限或发布位置已变化，暂不能修改图片；不会自动转为未分组。'); }
    if ($draft && ($post->post_status !== 'draft' || $row['value']['phase'] !== 'draft')) { return wuai_sluyu_topic_media_error('draft', '请先通过图片编辑器撤回为草稿，再修改图片并重新审核。'); }
    if (!$draft && !in_array($post->post_status, ['draft','pending','publish'], true)) { return wuai_sluyu_topic_media_error('status', '此稿件当前不能继续操作，请联系管理员。'); }
    return $row;
}

function wuai_sluyu_topic_media_save($id, $before, $value, $guard) {
    global $wpdb;
    if ((int) ($guard['post_id'] ?? 0) !== (int) $id) { return wuai_sluyu_topic_media_error('fenced', '图片稿件缺少准确话题锁，未保存列表。'); }
    clean_post_cache($id);
    if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($id)) { return wuai_sluyu_topic_media_error('review_held', '审核隔离标记已变化，图片列表未保存，请管理员核验。'); }
    $value['version'] = $before['value']['version'] + 1; $raw = serialize($value);
    $written = $wpdb->query($wpdb->prepare("UPDATE $wpdb->postmeta SET meta_value=%s WHERE meta_id=%d AND post_id=%d AND meta_key=%s AND HEX(meta_value)=HEX(%s) AND EXISTS (SELECT 1 FROM $wpdb->posts media_post WHERE media_post.ID=%d AND media_post.post_type='sluyu_topic' AND media_post.post_status='draft' AND media_post.post_author=%d AND media_post.post_password='') AND ", $raw, $before['meta_id'], $id, wuai_sluyu_topic_media_key(), $before['raw'], $id, $value['owner']) . wuai_sluyu_topic_media_fence($guard) . ' AND ' . wuai_sluyu_topic_media_hold_sql($id));
    wp_cache_delete($id, 'post_meta'); $saved = wuai_sluyu_topic_media_state($id);
    return $written === 1 && !is_wp_error($saved) && $saved && hash_equals($raw, $saved['raw']) ? $saved : wuai_sluyu_topic_media_error('changed', '稿件或图片列表已变化，保存未确认成功。请刷新核对，勿重复上传。');
}

function wuai_sluyu_topic_media_quota($uid, $attempt = false) {
    global $wpdb;
    $limit = max(5, min(100, (int) get_option('wuai_topic_media_retained_limit', 50)));
    $count = wuai_sluyu_topic_media_cleanup_retained_count($uid);
    if (is_wp_error($count) || !is_int($count)) { return wuai_sluyu_topic_media_error('quota', '无法核验已有图片与未完成清理数量，暂不接受上传。'); }
    if ((int) $count >= $limit) { return wuai_sluyu_topic_media_error('quota', '已达到本站公开图片保留上限（' . $limit . ' 张，含已发布及未清理图片），请先删除不再需要的图片。'); }
    if (!$attempt) { return true; }
    $key = '_wuai_topic_media_daily'; $day = gmdate('Y-m-d');
    $rows = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM $wpdb->usermeta WHERE user_id=%d AND meta_key=%s", $uid, $key));
    if ($wpdb->last_error || count($rows) > 1) { return wuai_sluyu_topic_media_error('budget', '无法核验上传额度，请联系管理员。'); }
    $budget = $rows ? @unserialize($rows[0], ['allowed_classes'=>false]) : ['day'=>$day, 'count'=>0];
    if (!is_array($budget) || array_keys($budget) !== ['day','count'] || !is_string($budget['day']) || !is_int($budget['count']) || $budget['count'] < 0) { return wuai_sluyu_topic_media_error('budget', '上传额度记录异常，请联系管理员。'); }
    if ($budget['day'] !== $day) { $budget = ['day'=>$day, 'count'=>0]; }
    if ($budget['count'] >= 20) { return wuai_sluyu_topic_media_error('budget', '每天最多尝试上传 20 张图片（UTC 日切），请明天再试。'); }
    $budget['count']++; update_user_meta($uid, $key, $budget);
    $actual = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM $wpdb->usermeta WHERE user_id=%d AND meta_key=%s", $uid, $key));
    return !$wpdb->last_error && count($actual) === 1 && $actual[0] === serialize($budget) ? true : wuai_sluyu_topic_media_error('budget', '上传额度未保存，未继续处理图片。');
}

function wuai_sluyu_topic_media_reencode($path, $name) {
    if (!is_string($path) || strpos($path, '://') !== false || !is_file($path) || !is_readable($path) || !is_string($name)) { return wuai_sluyu_topic_media_error('file', '请选择有效的本地图片文件。'); }
    $size = filesize($path); $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)); $types = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
    if (!$size || $size > min(4 * MB_IN_BYTES, wp_max_upload_size())) { return wuai_sluyu_topic_media_error('size', '每张图片最多 4 MB，且不能超过服务器上传限制。'); }
    $mime = wp_get_image_mime($path); $dimensions = @getimagesize($path);
    if (!isset($types[$ext]) || $types[$ext] !== $mime || !$dimensions || ($dimensions['mime'] ?? '') !== $mime) { return wuai_sluyu_topic_media_error('type', '仅接受真实格式与扩展名一致的 JPEG、PNG、WebP；不支持 SVG、GIF、音视频或附件。'); }
    $width = (int) $dimensions[0]; $height = (int) $dimensions[1];
    if ($width < 16 || $height < 16 || max($width, $height) > 8192 || $width * $height > 8000000) { return wuai_sluyu_topic_media_error('dimensions', '图片至少 16×16，最长边不超过 8192，总像素不超过 800 万。'); }
    $decoder = ['image/jpeg'=>'imagecreatefromjpeg','image/png'=>'imagecreatefrompng','image/webp'=>'imagecreatefromwebp'][$mime];
    if (!extension_loaded('gd') || !function_exists($decoder) || !function_exists('imagepng') || !function_exists('imagejpeg')) { return wuai_sluyu_topic_media_error('gd', '服务器暂不能安全重新编码此格式，不会保存原图；请联系管理员启用 PHP GD。'); }
    $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
    if ($limit > 0 && memory_get_usage(true) + $width * $height * 9 + 32 * MB_IN_BYTES > $limit) { return wuai_sluyu_topic_media_error('memory', '图片解码内存不足，请缩小图片。'); }
    $source = @$decoder($path); if (!$source) { return wuai_sluyu_topic_media_error('decode', '图片解码失败，请重新导出静态图片。'); }
    $canvas = null; $temp = '';
    try {
        if (imagesx($source) !== $width || imagesy($source) !== $height) { return wuai_sluyu_topic_media_error('decode', '图片尺寸与解码结果不符。'); }
        $scale = min(1, 1600 / max($width, $height)); $w = max(1, (int) floor($width * $scale)); $h = max(1, (int) floor($height * $scale));
        $canvas = imagecreatetruecolor($w, $h); if (!$canvas) { return wuai_sluyu_topic_media_error('decode', '无法创建图片画布。'); }
        imagealphablending($canvas, false); imagesavealpha($canvas, true); imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        if (!imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height)) { return wuai_sluyu_topic_media_error('resize', '图片缩放失败。'); }
        require_once ABSPATH . 'wp-admin/includes/file.php'; $temp = wp_tempnam('wuai-public-image');
        if (!$temp) { return wuai_sluyu_topic_media_error('temp', '无法创建安全临时文件。'); }
        $output = $mime === 'image/jpeg' ? 'image/jpeg' : 'image/png';
        $ok = $output === 'image/jpeg' ? imagejpeg($canvas, $temp, 88) : imagepng($canvas, $temp, 7);
        if (!$ok || !filesize($temp) || filesize($temp) > 4 * MB_IN_BYTES || wp_get_image_mime($temp) !== $output) { return wuai_sluyu_topic_media_error('encode', '重新编码失败或输出过大，请缩小图片。'); }
        $result = ['path'=>$temp, 'mime'=>$output, 'width'=>$w, 'height'=>$h, 'sha256'=>hash_file('sha256', $temp)]; $temp = ''; return $result;
    } finally { if ($canvas) { imagedestroy($canvas); } imagedestroy($source); if ($temp && is_file($temp)) { wp_delete_file($temp); } }
}

function wuai_sluyu_topic_media_attachment($id, $post_id, $uid, $verify_file = true) {
    clean_post_cache($id); $post = get_post($id); $marker = wuai_sluyu_topic_media_raw($id, wuai_sluyu_topic_media_marker());
    if (!$post || $post->post_type !== 'attachment' || ($verify_file && $post->post_status !== 'inherit') || (int) $post->post_author !== $uid || (int) $post->post_parent !== $post_id || is_wp_error($marker) || !$marker) { return wuai_sluyu_topic_media_error('attachment', '图片不存在或归属已变化，不会操作其他媒体文件。'); }
    $m = $marker['value'];
    if (array_keys($m) !== ['schema','purpose','owner','post','request','file','mime','width','height','sha256'] || $m['schema'] !== 1 || $m['purpose'] !== 'topic-public' || $m['owner'] !== $uid || $m['post'] !== $post_id || !wuai_sluyu_topic_media_uuid($m['request']) || !is_string($m['file']) || !preg_match('~^(?:[0-9]{4}/[0-9]{2}/)?topic-public-[a-f0-9-]{36}\.(?:png|jpg)$~D', $m['file']) || !in_array($m['mime'], ['image/jpeg','image/png'], true) || !is_int($m['width']) || !is_int($m['height']) || min($m['width'], $m['height']) < 1 || max($m['width'], $m['height']) > 1600 || !is_string($m['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $m['sha256'])) { return wuai_sluyu_topic_media_error('attachment', '专用图片记录异常，请管理员检查，未删除任何文件。'); }
    $upload = wp_upload_dir(); $base = realpath($upload['basedir']); $path = $upload['basedir'] . '/' . $m['file']; $resolved = realpath($path);
    $metadata = get_post_meta($id, '_wp_attachment_metadata', true);
    $expected = ['width'=>$m['width'], 'height'=>$m['height'], 'file'=>$m['file'], 'sizes'=>[], 'image_meta'=>[]];
    if (!$base || get_post_meta($id, '_wp_attached_file', true) !== $m['file'] || wp_normalize_path(get_attached_file($id)) !== wp_normalize_path($path) || metadata_exists('post', $id, '_wp_attachment_backup_sizes') || $metadata !== $expected || $post->post_mime_type !== $m['mime'] || ($resolved && strpos(wp_normalize_path($resolved), trailingslashit(wp_normalize_path($base))) !== 0)) { return wuai_sluyu_topic_media_error('attachment', '图片路径或元数据已变化，不能通过前台删除或发布。'); }
    if ($verify_file && (!$resolved || !is_file($resolved) || filesize($resolved) > 4 * MB_IN_BYTES || wp_get_image_mime($resolved) !== $m['mime'] || !hash_equals($m['sha256'], hash_file('sha256', $resolved)))) { return wuai_sluyu_topic_media_error('attachment', '图片文件缺失或内容已改变，请先移除并重新上传。'); }
    return $m + ['id'=>$id, 'path'=>$path, 'url'=>trailingslashit($upload['baseurl']) . $m['file']];
}

function wuai_sluyu_topic_media_delete_attachment($id, $post_id, $uid, $guard = null) {
    return wuai_sluyu_topic_media_cleanup_delete($id, $post_id, $uid, $guard);
}

function wuai_sluyu_topic_media_snapshot($id, $row = null) {
    $row = $row ?: wuai_sluyu_topic_media_state($id); if (is_wp_error($row) || !$row) { return wuai_sluyu_topic_media_error('state', '图片稿件无法读取。'); }
    $s = $row['value']; $post = get_post($id); $items = [];
    foreach ($s['items'] as $item) { $image = wuai_sluyu_topic_media_attachment($item['id'], $id, $s['owner']); $items[] = ['id'=>$item['id'], 'url'=>is_wp_error($image) ? '' : $image['url'], 'valid'=>!is_wp_error($image)]; }
    return ['id'=>$id, 'version'=>$s['version'], 'phase'=>$s['phase'], 'status'=>$post ? $post->post_status : 'missing', 'title'=>$post ? $post->post_title : '', 'body'=>$post ? html_entity_decode(wp_strip_all_tags(str_replace('</p>', "\n", $post->post_content)), ENT_QUOTES, 'UTF-8') : '', 'items'=>$items, 'group'=>$s['group'], 'url'=>wuai_sluyu_portal_url('submit', ['kind'=>'topic-images','draft'=>$id])];
}

function wuai_sluyu_topic_media_create($uid, $input, $guard) {
    if (!wuai_sluyu_topic_media_uuid($input['request'] ?? '') || !isset($input['group']) || !is_string($input['group']) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $input['group'])) { return wuai_sluyu_topic_media_error('input', '草稿标识或圈子参数无效。'); }
    $group = (int) $input['group']; if (!wuai_sluyu_topic_media_public_group($group, $uid)) { return wuai_sluyu_topic_media_error('circle', '仅支持当前可投稿的开放公开圈子。'); }
    global $wpdb; $slug = 'wuai-public-images-' . $uid . '-' . $input['request'];
    $existing = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type='sluyu_topic' AND post_author=%d AND post_name=%s LIMIT 1", $uid, $slug));
    if ($wpdb->last_error) { return wuai_sluyu_topic_media_error('storage', '无法核验先前草稿，请稍后重试。'); }
    if ($existing) { $row = wuai_sluyu_topic_media_permission((int) $existing, $uid, false); return is_wp_error($row) || $row['value']['group'] !== $group ? wuai_sluyu_topic_media_error('replay', '先前草稿已变化，请从个人中心继续。') : wuai_sluyu_topic_media_snapshot((int) $existing, $row); }
    $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_author=%d AND post_type='sluyu_topic' AND post_status='draft' AND post_name LIKE %s", $uid, $wpdb->esc_like('wuai-public-images-') . '%'));
    if ($wpdb->last_error || (int) $count >= 3) { return wuai_sluyu_topic_media_error('draft_limit', '最多保留 3 份图片草稿，请先继续或放弃已有草稿。'); }
    $quota = wuai_sluyu_topic_media_quota($uid); if (is_wp_error($quota)) { return $quota; }
    $value = ['schema'=>1, 'owner'=>$uid, 'group'=>$group, 'request'=>$input['request'], 'version'=>1, 'phase'=>'draft', 'items'=>[]];
    $id = wp_insert_post(['post_type'=>'sluyu_topic','post_status'=>'draft','post_author'=>$uid,'post_name'=>$slug,'post_title'=>'公开图片话题草稿','post_content'=>'','comment_status'=>'open','meta_input'=>['_wuai_topic_kind'=>'topic',wuai_sluyu_topic_media_key()=>$value]], true);
    if (is_wp_error($id) || !$id) { return wuai_sluyu_topic_media_error('create', '草稿未创建，请稍后重试。'); }
    if ($group) { $GLOBALS['wuai_topic_media_assigning'] = (int) $id; try { wp_set_object_terms($id, [$group], 'sluyu_circle'); } finally { unset($GLOBALS['wuai_topic_media_assigning']); } }
    $row = wuai_sluyu_topic_media_permission((int) $id, $uid);
    if (is_wp_error($row) || $row['value'] !== $value) { return wuai_sluyu_topic_media_error('create', '草稿 #' . $id . ' 的关联信息未完整保存，未接受上传；请管理员核对。'); }
    return wuai_sluyu_topic_media_snapshot((int) $id, $row);
}

function wuai_sluyu_topic_media_commit($id, $uid, $before, $request, $encoded, $guard) {
    if (!wuai_sluyu_topic_media_guard_valid($id, $guard)) { return wuai_sluyu_topic_media_error('fenced', '图片处理期间话题锁或审核状态已变化，未保存公开文件。'); }
    $fresh = wuai_sluyu_topic_media_permission($id, $uid);
    if (is_wp_error($fresh) || !hash_equals($before['raw'], $fresh['raw']) || count($fresh['value']['items']) >= 5) { return wuai_sluyu_topic_media_error('changed', '处理图片时草稿已变化，未加入图片。'); }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $name = 'topic-public-' . wp_generate_uuid4() . ($encoded['mime'] === 'image/jpeg' ? '.jpg' : '.png');
    $file = ['name'=>$name,'tmp_name'=>$encoded['path'],'type'=>$encoded['mime'],'size'=>filesize($encoded['path']),'error'=>UPLOAD_ERR_OK];
    $armed = wuai_sluyu_topic_media_upload_arm($guard);
    if (is_wp_error($armed)) { return $armed; }
    $upload = wp_handle_sideload($file, ['test_form'=>false,'mimes'=>['jpg'=>'image/jpeg','png'=>'image/png']]);
    if (is_array($upload) && isset($upload['error'])) {
        $cancelled = wuai_sluyu_topic_media_upload_cancel($guard, $upload);
        return is_wp_error($cancelled) ? $cancelled : wuai_sluyu_topic_media_error('upload', '重编码图片未能保存，请稍后重试。');
    }
    $record = wuai_sluyu_topic_media_upload_record($upload, $encoded, $uid, $id, $request, $guard);
    if (is_wp_error($record)) { return $record; }
    $relative = $record['value']['file'];
    $marker = ['schema'=>1,'purpose'=>'topic-public','owner'=>$uid,'post'=>$id,'request'=>$request,'file'=>$relative,'mime'=>$encoded['mime'],'width'=>$encoded['width'],'height'=>$encoded['height'],'sha256'=>$encoded['sha256']];
    $GLOBALS['wuai_topic_media_creating'] = true;
    try { $attachment = wp_insert_attachment(['post_author'=>$uid,'post_parent'=>$id,'post_name'=>pathinfo($name, PATHINFO_FILENAME),'post_title'=>'公开话题图片','post_status'=>'inherit','post_mime_type'=>$encoded['mime'],'meta_input'=>[wuai_sluyu_topic_media_marker()=>$marker]], $upload['file'], $id, true); }
    finally { unset($GLOBALS['wuai_topic_media_creating']); }
    if (is_wp_error($attachment) || !$attachment) {
        $rollback = wuai_sluyu_topic_media_upload_rollback($record, $guard);
        return is_wp_error($rollback) ? $rollback : wuai_sluyu_topic_media_error('attachment', '图片记录未能保存，公开文件已确认回滚，未加入稿件。');
    }
    $metadata = ['width'=>$encoded['width'],'height'=>$encoded['height'],'file'=>$relative,'sizes'=>[],'image_meta'=>[]];
    $GLOBALS['wuai_topic_media_write_attachment'] = (int) $attachment;
    try { wp_update_attachment_metadata($attachment, $metadata); }
    finally { unset($GLOBALS['wuai_topic_media_write_attachment']); }
    $verified = wuai_sluyu_topic_media_attachment((int) $attachment, $id, $uid);
    if (is_wp_error($verified)) { return wuai_sluyu_topic_media_error('partial', '图片 #' . $attachment . ' 的记录未核验成功，可能存在公开孤立文件；账号图片操作已冻结，请管理员停机核验，勿重复上传。'); }
    $finished = wuai_sluyu_topic_media_upload_finish($record, $guard, (int) $attachment);
    if (is_wp_error($finished)) { return $finished; }
    $value = $before['value']; $value['items'][] = ['id'=>(int) $attachment, 'request'=>$request];
    $saved = wuai_sluyu_topic_media_save($id, $before, $value, $guard);
    if (is_wp_error($saved)) {
        $cleanup = wuai_sluyu_topic_media_delete_attachment((int) $attachment, $id, $uid, $guard);
        return is_wp_error($cleanup) ? wuai_sluyu_topic_media_error('partial', '图片 #' . $attachment . ' 未能加入列表，清理也未确认；公开文件可能仍存在并计入上限，请管理员核对。') : $saved;
    }
    return wuai_sluyu_topic_media_snapshot($id, $saved);
}

function wuai_sluyu_topic_media_operation($operation, $input, $file = null) {
    $uid = get_current_user_id();
    if (!$uid || !get_userdata($uid) || wuai_sluyu_banned($uid) || is_multisite()) { return wuai_sluyu_topic_media_error('auth', '请使用正常状态账号登录；暂不支持多站点媒体。'); }
    if (!is_array($input)) { return wuai_sluyu_topic_media_error('input', '表单格式无效。'); }
    foreach ($input as $field) { if (!is_string($field)) { return wuai_sluyu_topic_media_error('input', '表单字段必须为单个文本值。'); } }
    if (!in_array($operation, ['create','upload','order','remove','submit','withdraw','abandon'], true) || ($input['confirm_public'] ?? '') !== '1') { return wuai_sluyu_topic_media_error('confirm', '请确认：上传后图片直链立即公开，审核、撤回或隐藏正文都不能使图片私密。'); }
    $guard = wuai_sluyu_topic_media_enter($uid); if (is_wp_error($guard)) { return $guard; } $post_lock = null;
    try {
        clean_user_cache($uid); if (!get_userdata($uid) || wuai_sluyu_banned($uid)) { return wuai_sluyu_topic_media_error('auth', '账号状态已变化，未执行图片操作。'); }
        if ($operation === 'create') { return wuai_sluyu_topic_media_create($uid, $input, $guard); }
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $input['id'] ?? '') || !preg_match('/^[1-9][0-9]{0,9}$/D', $input['version'] ?? '')) { return wuai_sluyu_topic_media_error('input', '稿件或版本无效，请刷新。'); }
        $id = (int) $input['id']; $post_lock = wuai_sluyu_lock('poll_' . $id);
        if (!$post_lock) { return wuai_sluyu_topic_media_error('busy', '此话题正在审核或更新，请稍后刷新重试。'); }
        $guard['post_id'] = $id; $guard['post_lock'] = $post_lock;
        global $wpdb;
        $native = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $wpdb->options WHERE option_name=%s", 'wuai_topic_media_native_' . $id));
        if ($wpdb->last_error || $native) { return wuai_sluyu_topic_media_error('busy', '此图片话题存在尚未完成的后台保存，请管理员核验后重试。'); }
        $before = wuai_sluyu_topic_media_permission($id, $uid, !in_array($operation, ['withdraw','abandon'], true));
        if (is_wp_error($before)) { return $before; }
        if ($operation === 'upload' && wuai_sluyu_topic_media_uuid($input['request'] ?? '')) { foreach ($before['value']['items'] as $item) { if ($item['request'] === $input['request']) { return wuai_sluyu_topic_media_snapshot($id, $before); } } }
        if ($before['value']['version'] !== (int) $input['version']) { return wuai_sluyu_topic_media_error('version', '图片列表已更新，请刷新页面，避免覆盖新内容。'); }
        if ($operation === 'withdraw') {
            $changed = wuai_sluyu_topic_media_post_update($id, $before, $guard, ['post_status'=>'draft']);
            if (is_wp_error($changed)) { return $changed; }
            $value = $before['value']; $value['phase'] = 'draft'; $saved = wuai_sluyu_topic_media_save($id, $before, $value, $guard);
            return is_wp_error($saved) ? $saved : wuai_sluyu_topic_media_snapshot($id, $saved);
        }
        if (get_post_status($id) !== 'draft') { return wuai_sluyu_topic_media_error('draft', '请先撤回为草稿，才能删除图片或放弃稿件。'); }
        if ($operation === 'abandon' && !EMPTY_TRASH_DAYS) { return wuai_sluyu_topic_media_error('trash_disabled', '本站未启用回收站，前台不能安全放弃草稿；请联系管理员永久删除。图片尚未移除。'); }
        if ($operation === 'upload') {
            if (!wuai_sluyu_topic_media_uuid($input['request'] ?? '') || count($before['value']['items']) >= 5) { return wuai_sluyu_topic_media_error('capacity', '每份稿件最多 5 张图片，且每次上传需要独立有效标识。'); }
            $quota = wuai_sluyu_topic_media_quota($uid, true); if (is_wp_error($quota)) { return $quota; }
            if (!is_array($file)) { return wuai_sluyu_topic_media_error('file', '请选择图片。'); }
            foreach (['name','tmp_name','error','size'] as $key) { if (!isset($file[$key]) || !is_scalar($file[$key])) { return wuai_sluyu_topic_media_error('file', '图片上传参数无效。'); } }
            if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) { return wuai_sluyu_topic_media_error('origin', '未收到完整浏览器上传，不能从服务器路径或远程 URL 导入。'); }
            $encoded = wuai_sluyu_topic_media_reencode((string) $file['tmp_name'], (string) $file['name']); if (is_wp_error($encoded)) { return $encoded; }
            try { return wuai_sluyu_topic_media_commit($id, $uid, $before, $input['request'], $encoded, $guard); }
            finally { if (is_file($encoded['path'])) { wp_delete_file($encoded['path']); } }
        }
        $value = $before['value']; $cleanup = [];
        if ($operation === 'order') {
            $raw = $input['order'] ?? ''; if (!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*){0,4}$/D', $raw)) { return wuai_sluyu_topic_media_error('order', '排序参数无效。'); }
            $order = array_map('intval', explode(',', $raw)); $expected = array_column($value['items'], 'id'); $sorted = $order; sort($sorted); sort($expected);
            if ($sorted !== $expected || count(array_unique($order)) !== count($order)) { return wuai_sluyu_topic_media_error('order', '只能调整当前稿件已有图片的顺序，不能引用其他附件。'); }
            $map = []; foreach ($value['items'] as $item) { $map[$item['id']] = $item; } $value['items'] = array_map(static function ($id) use ($map) { return $map[$id]; }, $order);
        } elseif ($operation === 'remove') {
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $input['attachment'] ?? '')) { return wuai_sluyu_topic_media_error('remove', '请选择当前稿件中的图片。'); }
            $attachment = (int) $input['attachment']; $found = false; $remaining = [];
            foreach ($value['items'] as $item) { if ($item['id'] === $attachment) { $found = true; } else { $remaining[] = $item; } }
            if (!$found) { return wuai_sluyu_topic_media_error('remove', '此图片不属于当前列表，不会删除其他媒体。'); }
            if (get_post($attachment)) { $owned = wuai_sluyu_topic_media_attachment($attachment, $id, $uid, false); if (is_wp_error($owned)) { return $owned; } $cleanup[] = $attachment; }
            $value['items'] = $remaining;
        } elseif ($operation === 'abandon') {
            $value['items'] = []; $value['phase'] = 'abandoned';
        } elseif ($operation === 'submit') {
            $title = sanitize_text_field($input['title'] ?? ''); $body = sanitize_textarea_field($input['body'] ?? '');
            if (mb_strlen($title) < 2 || mb_strlen($title) > 120 || mb_strlen($body) < 5 || mb_strlen($body) > 20000 || !$value['items']) { return wuai_sluyu_topic_media_error('content', '请填写 2–120 字标题、5–20000 字说明，并上传至少 1 张图片。'); }
            foreach ($value['items'] as $item) { $image = wuai_sluyu_topic_media_attachment($item['id'], $id, $uid); if (is_wp_error($image)) { return $image; } }
            $content = wpautop(str_replace(['[',']'], ['&#091;','&#093;'], esc_html($body))); $updated = wuai_sluyu_topic_media_post_update($id, $before, $guard, ['post_title'=>$title,'post_content'=>$content,'post_status'=>'draft']);
            if (is_wp_error($updated)) { return $updated; }
            $value['phase'] = 'submitted';
        }
        $saved = wuai_sluyu_topic_media_save($id, $before, $value, $guard); if (is_wp_error($saved)) { return $saved; }
        foreach ($cleanup as $attachment) {
            $deleted = wuai_sluyu_topic_media_delete_attachment($attachment, $id, $uid, $guard);
            if (is_wp_error($deleted)) { return wuai_sluyu_topic_media_error('cleanup_partial', '图片已从稿件列表移除，但公开文件删除尚未确认。请刷新列表；剩余专用附件仍计入保留上限，需要管理员清理。'); }
        }
        if ($operation === 'submit') {
            $permission = wuai_sluyu_topic_media_permission($id, $uid, false); if (is_wp_error($permission)) { return $permission; }
            $queued = wuai_sluyu_topic_media_post_update($id, $saved, $guard, ['post_status'=>'pending']);
            if (is_wp_error($queued)) { return wuai_sluyu_topic_media_error('queue', '稿件 #' . $id . ' 未确认进入审核队列，请刷新后先撤回草稿再重试。图片直链已经公开。'); }
            if (!wuai_sluyu_topic_media_publishable($id)) { wuai_sluyu_topic_media_post_update($id, $saved, $guard, ['post_status'=>'draft']); return wuai_sluyu_topic_media_error('queue_changed', '图片或权限在送审时发生变化，未确认完成审核提交，请刷新核对。'); }
        } elseif ($operation === 'abandon') {
            $cleaned = wuai_sluyu_topic_media_cleanup_scope($uid, $id, $guard);
            if (is_wp_error($cleaned)) { return wuai_sluyu_topic_media_error('cleanup_partial', '草稿列表已清空，但公开文件尚未全部确认删除；清理凭据与保留配额仍在，请刷新后重试或联系管理员。'); }
            $trashed = wuai_sluyu_topic_media_post_update($id, $saved, $guard, ['post_status'=>'trash'], true);
            if (is_wp_error($trashed)) { return wuai_sluyu_topic_media_error('trash', '图片已清理，但回收站流程未确认完成；如已开始变更，账号图片锁会保留，请管理员停机核验。'); }
        }
        return wuai_sluyu_topic_media_snapshot($id, $saved);
    } finally { if ($post_lock) { wuai_sluyu_unlock($post_lock); } wuai_sluyu_topic_media_leave($guard); }
}

function wuai_sluyu_topic_media_ajax() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_user_logged_in() || !isset($_POST['nonce']) || !is_string($_POST['nonce']) || !wp_verify_nonce(wp_unslash($_POST['nonce']), 'wuai_topic_media')) { wp_send_json_error(['message'=>'请登录并使用有效图片表单提交。'], 403); }
    $input = [];
    foreach (['id','version','group','request','confirm_public','order','attachment','title','body'] as $key) { if (isset($_POST[$key])) { $input[$key] = is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : null; } }
    $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? wp_unslash($_POST['operation']) : '';
    try { $result = wuai_sluyu_topic_media_operation($operation, $input, $_FILES['image'] ?? null); }
    catch (Throwable $error) { $result = wuai_sluyu_topic_media_error('failed', '图片操作未完成，请刷新核对；不要重复提交同一图片。'); }
    if (is_wp_error($result)) { wp_send_json_error(['message'=>$result->get_error_message()], 400); }
    wp_send_json_success($result);
}
add_action('wp_ajax_wuai_topic_media', 'wuai_sluyu_topic_media_ajax');

add_filter('wuai_sluyu_account_submission_permission', function ($allowed, $post, $operation) { return wuai_sluyu_topic_media_managed($post->ID) && in_array($operation, ['edit_submission','withdraw_submission','delete_submission'], true) ? wuai_sluyu_topic_media_error('managed', '请使用公开图片编辑器撤回、编辑或删除此稿件；图片直链不会因撤回自动变为私密。') : $allowed; }, 40, 3);

function wuai_sluyu_topic_media_attachment_managed($id) {
    $post = get_post($id);
    return $post && $post->post_type === 'attachment' && (metadata_exists('post', $id, wuai_sluyu_topic_media_marker()) || strpos($post->post_name, 'topic-public-') === 0);
}
function wuai_sluyu_topic_media_publishable($id) {
    $row = wuai_sluyu_topic_media_state($id); $post = get_post($id);
    if (is_wp_error($row) || !$row || !$post || $row['value']['phase'] !== 'submitted' || !$row['value']['items'] || (int) $post->post_author !== $row['value']['owner'] || $post->post_password !== '' || get_post_meta($id, '_wuai_topic_kind', true) !== 'topic') { return false; }
    clean_user_cache($row['value']['owner']); wp_cache_delete($row['value']['owner'], 'user_meta');
    if (!get_userdata($row['value']['owner']) || wuai_sluyu_banned($row['value']['owner'])) { return false; }
    foreach (['_wuai_protected_content','_wuai_guess_config','_wuai_download_settings','_wuai_sluyu_download_url'] as $key) { if (metadata_exists('post', $id, $key)) { return false; } }
    if (function_exists('wuai_sluyu_bounty_exists') && wuai_sluyu_bounty_exists($id) !== false) { return false; }
    $groups = wp_get_object_terms($id, 'sluyu_circle', ['fields'=>'ids','wuai_include_pending'=>true]); $group = $row['value']['group'];
    if (is_wp_error($groups) || array_map('intval', $groups) !== ($group ? [$group] : []) || !wuai_sluyu_topic_media_public_group($group, $row['value']['owner'])) { return false; }
    foreach ($row['value']['items'] as $item) { if (is_wp_error(wuai_sluyu_topic_media_attachment($item['id'], $id, $row['value']['owner']))) { return false; } }
    return true;
}
// Dedicated files are immutable. Native editors may delete them, not reparent,
// replace a path or turn somebody else's media into a front-end deletion target.
function wuai_sluyu_topic_media_native_identity($data, $postarr) {
    $id = isset($postarr['ID']) && is_scalar($postarr['ID']) ? absint($postarr['ID']) : 0;
    if ($id && wuai_sluyu_topic_media_attachment_managed($id)) {
        $old = get_post($id);
        foreach (['post_author','post_parent','post_type','post_mime_type','post_name'] as $key) { $data[$key] = wp_slash($old->$key); }
    } elseif ($id && wuai_sluyu_topic_media_managed($id)) {
        $old = $GLOBALS['wuai_topic_media_native'][$id]['post'] ?? get_post($id);
        foreach (['post_author','post_type','post_name','post_password'] as $key) { $data[$key] = wp_slash($old->$key); }
        if ($data['post_status'] === 'future' || (in_array($data['post_status'], ['pending','publish'], true) && !wuai_sluyu_topic_media_publishable($id))) { $data['post_status'] = 'draft'; }
        if (empty($GLOBALS['wuai_topic_media_author_update'][$id]) && empty($GLOBALS['wuai_topic_media_native'][$id])) { wuai_sluyu_topic_media_native_fail(); }
    }
    return $data;
}
add_filter('wp_insert_post_data', 'wuai_sluyu_topic_media_native_identity', 100, 2);
add_filter('wp_insert_attachment_data', 'wuai_sluyu_topic_media_native_identity', 100, 2);

function wuai_sluyu_topic_media_native_end($id) {
    $guard = $GLOBALS['wuai_topic_media_native'][$id] ?? null;
    if (!$guard) { return; }
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND HEX(option_value)=HEX(%s)", $guard['name'], $guard['token']));
    if (!empty($guard['owns_poll'])) { wuai_sluyu_unlock($guard['poll']); }
    unset($GLOBALS['wuai_topic_media_native'][$id]);
}

function wuai_sluyu_topic_media_native_fail() {
    // No shutdown release: a stopped trash/restore/save may have side effects.
    // Its durable fence requires inspection, never expiry-based stealing.
    wp_die('图片稿件版本、锁或原生写入条件已变化，已停止后续保存通知；请管理员核验未完成操作。', '', ['response'=>409]);
    throw new RuntimeException('topic_media_native_stopped');
}
function wuai_sluyu_topic_media_native_begin($id, $old, $scope = 'save') {
    if (!empty($GLOBALS['wuai_topic_media_native'][$id]) || !$old) { wuai_sluyu_topic_media_native_fail(); }
    global $wpdb;
    $owner_lock = 'wuai_topic_media_lock_' . (int) $old->post_author;
    $busy = $wpdb->get_var($wpdb->prepare("SELECT option_name FROM $wpdb->options WHERE option_name=%s", $owner_lock));
    if ($wpdb->last_error || $busy !== null) { wuai_sluyu_topic_media_native_fail(); }
    $name = 'wuai_lock_poll_' . $id; $owned = $GLOBALS['wuai_sluyu_request_locks'][$name] ?? '';
    $poll = $owned !== '' ? [$name, $owned] : wuai_sluyu_lock('poll_' . $id);
    if (!$poll) { wuai_sluyu_topic_media_native_fail(); }
    $token = wp_generate_uuid4(); $fence = 'wuai_topic_media_native_' . $id;
    if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name,option_value,autoload) SELECT %s,%s,'no' WHERE NOT EXISTS (SELECT 1 FROM (SELECT option_id FROM $wpdb->options WHERE option_name=%s LIMIT 1) media_author_lock)", $fence, $token, $owner_lock)) !== 1) {
        if ($owned === '') { wuai_sluyu_unlock($poll); }
        wuai_sluyu_topic_media_native_fail();
    }
    $row = wuai_sluyu_topic_media_state($id);
    $GLOBALS['wuai_topic_media_native'][$id] = ['name'=>$fence,'token'=>$token,'poll'=>$poll,'owns_poll'=>$owned === '', 'owner_lock'=>$owner_lock,'post'=>clone $old,'row'=>$row,'scope'=>$scope,'entered'=>false,'early'=>false,'after'=>true,'sql'=>false,'data'=>[],'committed'=>false,'executing'=>false];
    if (is_wp_error($row) || !$row || (int) $row['value']['owner'] !== (int) $old->post_author) { wuai_sluyu_topic_media_native_fail(); }
}

function wuai_sluyu_topic_media_native_context() {
    $trace = debug_backtrace(0, 32);
    foreach ($trace as $index=>$frame) {
        if (($frame['function'] ?? '') !== 'wp_insert_post' || isset($frame['class'])) { continue; }
        $input = $frame['args'][0] ?? []; $caller = $trace[$index + 1] ?? [];
        return ['input'=>is_array($input) ? $input : [], 'after'=>$frame['args'][2] ?? true, 'sanitizing'=>($trace[$index - 1]['function'] ?? '') === 'sanitize_post', 'update'=>($caller['function'] ?? '') === 'wp_update_post' ? (array) ($caller['args'][0] ?? []) : null];
    }
    return [];
}
function wuai_sluyu_topic_media_native_input($id, $context) {
    if (!$context || !isset($context['input'])) { wuai_sluyu_topic_media_native_fail(); }
    $old = $GLOBALS['wuai_topic_media_native'][$id]['post']; $input = $context['input'];
    foreach (wuai_sluyu_topic_media_post_columns() as $key) {
        $inherited = is_array($context['update']) && !array_key_exists($key, $context['update']);
        if (($inherited || in_array($key, ['post_modified','post_modified_gmt'], true)) && isset($input[$key]) && (string) wp_unslash($input[$key]) !== (string) $old->$key) {
            // wp_update_post intentionally refreshes a draft's date while merging.
            if ($inherited && in_array($key, ['post_date','post_date_gmt'], true)) { continue; }
            wuai_sluyu_topic_media_native_fail();
        }
    }
    $GLOBALS['wuai_topic_media_native'][$id]['after'] = (bool) $context['after'];
}
// Freeze get_post before later sanitizers/data filters can refresh a stale core
// merge. Explicit fields in a trusted direct wp_insert_post call remain caller
// intent; this is not an editor-browser revision-token protocol.
add_filter('pre_post_author', function ($value) {
    $context = wuai_sluyu_topic_media_native_context(); $id = absint($context['input']['ID'] ?? 0);
    if (!$id || empty($context['sanitizing']) || !empty($GLOBALS['wuai_topic_media_author_update'][$id])) { return $value; }
    $old = get_post($id); if (!wuai_sluyu_topic_media_managed($id)) { return $value; }
    $guard = $GLOBALS['wuai_topic_media_native'][$id] ?? null;
    if ($guard && ($guard['early'] || $guard['entered'])) { wuai_sluyu_topic_media_native_fail(); }
    if (!$guard) { wuai_sluyu_topic_media_native_begin($id, $old); }
    wuai_sluyu_topic_media_native_input($id, $context); $GLOBALS['wuai_topic_media_native'][$id]['early'] = true;
    return $value;
}, PHP_INT_MIN);
add_filter('wp_insert_post_empty_content', function ($empty, $postarr) {
    $id = absint($postarr['ID'] ?? 0);
    if (!$id || !empty($GLOBALS['wuai_topic_media_author_update'][$id])) { return $empty; }
    $old = get_post($id); if (!wuai_sluyu_topic_media_managed($id)) { return $empty; }
    if (empty($GLOBALS['wuai_topic_media_native'][$id])) { wuai_sluyu_topic_media_native_begin($id, $old); }
    if ($GLOBALS['wuai_topic_media_native'][$id]['entered']) { wuai_sluyu_topic_media_native_fail(); }
    wuai_sluyu_topic_media_native_input($id, wuai_sluyu_topic_media_native_context());
    $GLOBALS['wuai_topic_media_native'][$id]['entered'] = true;
    return $empty;
}, PHP_INT_MIN, 2);
// These two core early returns precede its first post/metadata mutation. Do not
// strand a durable save fence after an explicitly rejected empty/invalid date.
add_filter('wp_insert_post_empty_content', function ($empty, $postarr) {
    $id = absint($postarr['ID'] ?? 0); $guard = $GLOBALS['wuai_topic_media_native'][$id] ?? null;
    if ($guard && $guard['scope'] === 'save' && !$guard['sql'] && !$guard['committed'] && ($empty || !wp_resolve_post_date($postarr['post_date'] ?? '', $postarr['post_date_gmt'] ?? ''))) { wuai_sluyu_topic_media_native_end($id); }
    return $empty;
}, PHP_INT_MAX, 2);
add_action('pre_post_update', function ($id, $data) {
    if (!empty($GLOBALS['wuai_topic_media_author_update'][$id]) || empty($GLOBALS['wuai_topic_media_native'][$id])) { return; }
    $guard = $GLOBALS['wuai_topic_media_native'][$id];
    if (!$guard['entered'] || $guard['sql'] || $guard['committed']) { wuai_sluyu_topic_media_native_fail(); }
    foreach (['post_author','post_type','post_name','post_password'] as $key) { if (!isset($data[$key]) || (string) $data[$key] !== (string) $guard['post']->$key) { wuai_sluyu_topic_media_native_fail(); } }
    $status = $data['post_status'] ?? '';
    if (!in_array($status, ['draft','pending','publish','trash'], true) || ($guard['scope'] === 'trash' && $status !== 'trash') || ($guard['scope'] === 'untrash' && $status !== 'draft') || (in_array($status, ['pending','publish'], true) && !wuai_sluyu_topic_media_publishable($id))) { wuai_sluyu_topic_media_native_fail(); }
    $sql = wuai_sluyu_topic_media_post_sql($id, $data);
    if (!$sql) { wuai_sluyu_topic_media_native_fail(); }
    $GLOBALS['wuai_topic_media_native'][$id]['sql'] = $sql; $GLOBALS['wuai_topic_media_native'][$id]['data'] = $data;
    remove_filter('query', 'wuai_sluyu_topic_media_native_query', PHP_INT_MAX);
    add_filter('query', 'wuai_sluyu_topic_media_native_query', PHP_INT_MAX);
}, PHP_INT_MAX, 2);
function wuai_sluyu_topic_media_native_query($sql) {
    global $wpdb;
    $origin = wuai_sluyu_topic_media_db_origin(); $call = $origin['caller'] ?? [];
    if (($call['object'] ?? null) !== $wpdb || ($call['function'] ?? '') !== 'update' || ($call['args'][0] ?? '') !== $wpdb->posts) { return $sql; }
    $where = $call['args'][2] ?? []; $parent = $origin['parent']['function'] ?? '';
    $core_input = $origin['parent']['args'][0] ?? null;
    $core_id = is_array($core_input) ? ($core_input['ID'] ?? 0) : 0;
    if ($parent === 'wp_insert_post' && is_scalar($core_id) && !empty($GLOBALS['wuai_topic_media_native'][(int) $core_id]) && (!is_array($where) || array_keys($where) !== ['ID'] || !is_scalar($where['ID']) || (int) $where['ID'] !== (int) $core_id)) { wuai_sluyu_topic_media_native_fail(); }
    $id = is_array($where) && isset($where['ID']) && is_scalar($where['ID']) ? absint($where['ID']) : 0;
    if (!$id || !wuai_sluyu_topic_media_managed($id)) { return $sql; }
    if ($parent === 'wp_add_trashed_suffix_to_post_name_for_post') { return 'SELECT 0 /* Dedicated media slug stays immutable. */'; }
    if ($parent === 'wp_publish_post') { wuai_sluyu_topic_media_native_fail(); }
    if (!empty($GLOBALS['wuai_topic_media_author_update'][$id])) { return $sql; }
    $guard = $GLOBALS['wuai_topic_media_native'][$id] ?? null;
    if ($parent !== 'wp_insert_post') {
        // Core may refresh a comment count during the protected trash lifecycle.
        if ($guard && array_keys($call['args'][1] ?? []) !== ['comment_count']) { wuai_sluyu_topic_media_native_fail(); }
        return $sql;
    }
    if (!$guard && empty($origin['parent']['args'][0]['ID'])) { return $sql; }
    if (!$guard || !$guard['sql'] || $guard['committed'] || !is_string($sql) || !hash_equals($guard['sql'], $sql)) { wuai_sluyu_topic_media_native_fail(); }
    $public = in_array($guard['data']['post_status'], ['pending','publish'], true);
    if ($public && !wuai_sluyu_topic_media_publishable($id)) { wuai_sluyu_topic_media_native_fail(); }
    $predicate = wuai_sluyu_topic_media_identity_sql($id, $guard['row'], $guard['post']);
    $predicate .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM $wpdb->options media_native WHERE media_native.option_name=%s AND HEX(media_native.option_value)=HEX(%s)) AND EXISTS (SELECT 1 FROM $wpdb->options media_poll WHERE media_poll.option_name=%s AND HEX(media_poll.option_value)=HEX(%s)) AND NOT EXISTS (SELECT 1 FROM $wpdb->options media_author WHERE media_author.option_name=%s)", $guard['name'], $guard['token'], $guard['poll'][0], $guard['poll'][1], $guard['owner_lock']);
    if (empty($GLOBALS['wuai_sluyu_circle_review_transition'][$id])) { $predicate .= ' AND ' . wuai_sluyu_topic_media_hold_sql($id); }
    if ($public) { $predicate .= ' AND ' . wuai_sluyu_topic_media_audience_sql($id, $guard['row']); }
    $GLOBALS['wuai_topic_media_native'][$id]['executing'] = true;
    try { $written = wuai_sluyu_topic_media_post_cas($sql, $predicate, $id, $guard['data']); }
    catch (RuntimeException $error) { $written = false; }
    finally { $GLOBALS['wuai_topic_media_native'][$id]['executing'] = false; }
    if (!$written) { wuai_sluyu_topic_media_native_fail(); }
    $GLOBALS['wuai_topic_media_native'][$id]['committed'] = true;
    return 'SELECT 1 /* Native media post CAS already committed. */';
}
add_filter('query', 'wuai_sluyu_topic_media_native_query', PHP_INT_MAX);
foreach (['trash','untrash'] as $scope) {
    // Core has already honored every pre_* veto, but has not changed trash
    // metadata or comments. Explicit cancellation must not strand a fence.
    add_action($scope === 'trash' ? 'wp_trash_post' : 'untrash_post', static function ($id) use ($scope) {
        if (!wuai_sluyu_topic_media_managed($id) || !empty($GLOBALS['wuai_topic_media_author_update'][$id])) { return; }
        wuai_sluyu_topic_media_native_begin((int) $id, get_post($id), $scope);
    }, PHP_INT_MIN);
    add_action($scope === 'trash' ? 'trashed_post' : 'untrashed_post', static function ($id) use ($scope) {
        $guard = $GLOBALS['wuai_topic_media_native'][$id] ?? null;
        if (!$guard) { return; }
        if ($guard['scope'] !== $scope || !$guard['committed']) { wuai_sluyu_topic_media_native_fail(); }
        wuai_sluyu_topic_media_native_end((int) $id);
    }, PHP_INT_MAX);
}
add_action('wp_insert_post', function ($id) {
    $guard = $GLOBALS['wuai_topic_media_native'][$id] ?? null;
    if (!$guard) { return; }
    if (!$guard['committed']) { wuai_sluyu_topic_media_native_fail(); }
    if ($guard['scope'] === 'save' && !$guard['after']) { wuai_sluyu_topic_media_native_end((int) $id); }
}, PHP_INT_MAX);
add_action('wp_after_insert_post', function ($id) {
    $guard = $GLOBALS['wuai_topic_media_native'][$id] ?? null;
    if ($guard && $guard['scope'] === 'save' && $guard['committed']) { wuai_sluyu_topic_media_native_end((int) $id); }
}, PHP_INT_MAX);
add_action('publish_future_post', function ($id) {
    clean_post_cache($id);
    if (!wuai_sluyu_topic_media_managed($id)) { return; }
    // This module does not support scheduled publication. Core's cron publisher
    // bypasses wp_insert_post_data, so cancel an old/restored event before core.
    wp_clear_scheduled_hook('publish_future_post', [$id]);
    if (get_post_status($id) !== 'future') { return; }
    $saved = wp_update_post(['ID'=>$id,'post_status'=>'draft'], true); clean_post_cache($id);
    if (is_wp_error($saved) || get_post_status($id) !== 'draft') { wp_die('公开图片稿件不支持定时发布，撤销定时状态失败，请管理员核验。', '', ['response'=>409]); }
}, 1);
add_action('admin_notices', function () {
    $id = isset($_GET['post']) && is_scalar($_GET['post']) ? absint($_GET['post']) : 0;
    if ($id && wuai_sluyu_topic_media_managed($id)) { echo '<div class="notice notice-info"><p>公开图片稿件须由站点编辑确认图片后手动发布，不支持定时发布。图片直链从上传时即公开；审核或撤回不等于图片私密。</p></div>'; }
});
foreach (['add_post_metadata','update_post_metadata','delete_post_metadata'] as $hook) {
    add_filter($hook, function ($check, $id, $key) {
        if ($check !== null || !in_array($key, [wuai_sluyu_topic_media_marker(),'_wp_attached_file','_wp_attachment_metadata'], true) || !wuai_sluyu_topic_media_attachment_managed($id)) { return $check; }
        return !empty($GLOBALS['wuai_topic_media_creating']) || ($GLOBALS['wuai_topic_media_write_attachment'] ?? 0) === (int) $id || !empty($GLOBALS['wuai_topic_media_deleting_attachment'][$id]) ? $check : false;
    }, 100, 3);
    add_filter($hook, function ($check, $id, $key) {
        if ($check !== null || !wuai_sluyu_topic_media_managed($id) || !in_array($key, ['_wuai_topic_kind','_wuai_protected_content','_wuai_guess_config','_wuai_download_settings','_wuai_sluyu_download_url','_wuai_sluyu_download_access','_wuai_sluyu_download_cost','_wuai_sluyu_download_password','_wuai_sluyu_download_version'], true)) { return $check; }
        return $key === '_wuai_topic_kind' && !metadata_exists('post', $id, $key) ? $check : false;
    }, 100, 3);
}
foreach (['add_term_relationship','delete_term_relationships'] as $hook) {
    add_action($hook, function ($id, $term, $taxonomy) {
        if ($taxonomy === 'sluyu_circle' && wuai_sluyu_topic_media_managed($id) && ($GLOBALS['wuai_topic_media_assigning'] ?? 0) !== (int) $id && empty($GLOBALS['wuai_topic_media_deleting_post'][$id])) { wp_die('公开图片稿件不能直接迁移圈子，请保留公开性说明并通过新稿件重新投稿。', '', ['response'=>409]); }
    }, 8, 3);
}
add_action('before_delete_post', function ($id) { if (wuai_sluyu_topic_media_managed($id)) { $GLOBALS['wuai_topic_media_deleting_post'][$id] = true; } }, 1);
add_action('delete_attachment', function ($id) { if (wuai_sluyu_topic_media_attachment_managed($id)) { $GLOBALS['wuai_topic_media_deleting_attachment'][$id] = true; } }, 1);
add_action('deleted_post', function ($id) { unset($GLOBALS['wuai_topic_media_deleting_attachment'][$id], $GLOBALS['wuai_topic_media_deleting_post'][$id]); }, 100);
add_filter('wp_privacy_personal_data_exporters', function ($exporters) {
    $exporters['wuai-topic-images'] = ['exporter_friendly_name'=>'公开话题图片', 'callback'=>function ($email, $page = 1) {
        $user = get_user_by('email', $email); if (!$user) { return ['data'=>[], 'done'=>true]; }
        $ids = get_posts(['post_type'=>'attachment','post_status'=>'any','author'=>$user->ID,'meta_key'=>wuai_sluyu_topic_media_marker(),'fields'=>'ids','posts_per_page'=>50,'paged'=>max(1, (int) $page)]); $data = [];
        foreach ($ids as $id) { $data[] = ['group_id'=>'wuai-topic-images','group_label'=>'公开话题图片','item_id'=>'wuai-topic-image-' . $id,'data'=>[['name'=>'附件 ID','value'=>$id],['name'=>'话题 ID','value'=>get_post_field('post_parent', $id)],['name'=>'公开链接','value'=>wp_get_attachment_url($id)]]]; }
        return ['data'=>$data, 'done'=>count($ids) < 50];
    }]; return $exporters;
});

function wuai_sluyu_topic_media_gallery($id) {
    if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($id)) { return ''; }
    $row = wuai_sluyu_topic_media_state($id); $post = get_post($id);
    if (is_wp_error($row) || !$row || !$post || $post->post_status !== 'publish' || !wuai_sluyu_topic_media_publishable($id)) { return ''; }
    $html = '<section class="sluyu-topic-gallery"><h3>话题图片</h3><p>公开图片，点击查看大图。未经许可请勿转载。</p><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));gap:12px">';
    foreach ($row['value']['items'] as $item) { $image = wuai_sluyu_topic_media_attachment($item['id'], $id, $row['value']['owner']); if (is_wp_error($image)) { continue; } $html .= '<a href="' . esc_url($image['url']) . '" target="_blank" rel="noopener"><img src="' . esc_url($image['url']) . '" alt="公开话题图片" width="' . $image['width'] . '" height="' . $image['height'] . '" loading="lazy" style="width:100%;height:auto;border-radius:8px"></a>'; }
    return $html . '</div></section>';
}
add_filter('the_content', function ($content) { return is_singular('sluyu_topic') && in_the_loop() && is_main_query() && !is_feed() && !is_embed() && !(defined('REST_REQUEST') && REST_REQUEST) ? $content . wuai_sluyu_topic_media_gallery(get_the_ID()) : $content; }, 115);

function wuai_sluyu_view_topic_media_submit() {
    if (!wuai_sluyu_require_login()) { return; }
    foreach (['draft','group','circle_id'] as $key) { if (isset($_GET[$key]) && !is_scalar($_GET[$key])) { wuai_sluyu_empty('图片稿件或圈子参数格式无效，请重新选择；不会自动改为未分组。'); return; } }
    $uid = get_current_user_id(); $raw = wuai_sluyu_query('draft', '0'); $state = null;
    if ($raw !== '0') { if (!preg_match('/^[1-9][0-9]{0,9}$/D', $raw)) { wuai_sluyu_empty('草稿编号无效。'); return; } $row = wuai_sluyu_topic_media_permission((int) $raw, $uid, false); if (is_wp_error($row)) { wuai_sluyu_empty($row->get_error_message()); return; } $state = wuai_sluyu_topic_media_snapshot((int) $raw, $row); }
    $group = wuai_sluyu_query('group', '0'); if (!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $group) || (!$state && !wuai_sluyu_topic_media_public_group((int) $group, $uid))) { wuai_sluyu_empty('所选圈子不适用公开图片投稿。'); return; }
    $config = ['endpoint'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('wuai_topic_media'),'group'=>$group,'state'=>$state,'request'=>wp_generate_uuid4()];
    echo '<section class="sluyu-panel sluyu-topic-media-composer" data-config="' . esc_attr(wp_json_encode($config)) . '"><h2>公开图片话题</h2><p class="sluyu-notice"><strong>上传后图片直链立即公开。</strong>待审核、撤回、隐藏正文、加入圈子或设置文章密码都不能使图片私密。请勿上传证件、个人隐私或无权分享的图片。图片最多 5 张，JPEG / PNG / WebP，每张 4 MB / 800 万像素；只保留重新编码后的最长 1600 像素静态图，不保留原图和原始元信息。</p><label><input data-media-confirm type="checkbox"> 我拥有分享权限，并确认上传后图片立即公开。</label><p data-media-status role="status" aria-live="polite"></p><div data-media-editor><p><button data-media-create type="button" class="sluyu-action-primary">创建公开图片草稿</button></p><div data-media-existing hidden><label>标题<input data-media-title maxlength="120" style="display:block;width:100%;box-sizing:border-box"></label><label>说明<textarea data-media-body rows="6" maxlength="20000" style="display:block;width:100%;box-sizing:border-box"></textarea></label><p><label>逐张选择图片<input data-media-file type="file" accept="image/jpeg,image/png,image/webp" multiple style="max-width:100%;min-width:0;font-size:12px"></label></p><progress data-media-progress max="100" value="0" hidden style="width:100%"></progress><ol data-media-list style="list-style:none;padding:0;display:grid;gap:16px"></ol><div class="sluyu-button-row"><button data-media-submit type="button" class="sluyu-action-primary">提交管理员审核</button><button data-media-withdraw type="button" class="sluyu-action-plain">撤回草稿并重新编辑</button><button data-media-abandon type="button" class="sluyu-action-plain">删除专用图片并放弃草稿</button></div></div></div><p>上传进度只表示网络传输，随后还需服务器解码与核验。失败时请保留说明并刷新核对；已公开文件删除后第三方缓存仍可能保留。</p><p><a href="' . esc_url(wuai_sluyu_portal_url('account', ['tab'=>'submissions'])) . '">返回我的投稿</a></p></section>';
    wuai_sluyu_topic_media_script();
}

function wuai_sluyu_topic_media_script() {
    ?>
<script>
(function(){'use strict';var root=document.querySelector('.sluyu-topic-media-composer');if(!root)return;var config=JSON.parse(root.dataset.config),state=config.state,busy=false,status=root.querySelector('[data-media-status]'),progress=root.querySelector('[data-media-progress]'),title=root.querySelector('[data-media-title]'),body=root.querySelector('[data-media-body]'),input=root.querySelector('[data-media-file]');
function message(text){status.textContent=text;}
function uuid(){var b=new Uint8Array(16);crypto.getRandomValues(b);b[6]=(b[6]&15)|64;b[8]=(b[8]&63)|128;var h=Array.from(b,function(v){return v.toString(16).padStart(2,'0');}).join('');return h.slice(0,8)+'-'+h.slice(8,12)+'-'+h.slice(12,16)+'-'+h.slice(16,20)+'-'+h.slice(20);}
function render(){root.querySelector('[data-media-create]').hidden=!!state;root.querySelector('[data-media-existing]').hidden=!state;if(!state)return;var editable=state.status==='draft'&&state.phase==='draft';input.disabled=!editable||busy;title.disabled=body.disabled=!editable||busy;root.querySelector('[data-media-submit]').hidden=!editable;root.querySelector('[data-media-withdraw]').hidden=editable||state.status==='trash';root.querySelector('[data-media-abandon]').hidden=state.status!=='draft';var list=root.querySelector('[data-media-list]');list.textContent='';state.items.forEach(function(item,index){var li=document.createElement('li');li.style.cssText='border:1px solid #e6e0e8;padding:12px;border-radius:8px;min-width:0';if(item.valid){var img=document.createElement('img');img.src=item.url;img.alt='已公开的图片 '+(index+1);img.style.cssText='max-width:100%;height:auto;max-height:240px;display:block';li.appendChild(img);}else{li.textContent='图片记录缺失或变更，请移除后重传。';}var row=document.createElement('div');row.className='sluyu-button-row';[['上移',-1],['下移',1],['删除',0]].forEach(function(spec){var button=document.createElement('button');button.type='button';button.className='sluyu-action-plain';button.textContent=spec[0];button.disabled=!editable||busy||(spec[1]===-1&&index===0)||(spec[1]===1&&index===state.items.length-1);button.addEventListener('click',function(){if(spec[1]===0){if(confirm('删除这张公开图片？第三方缓存可能仍保留。'))run('remove',{attachment:String(item.id)});}else{var order=state.items.map(function(i){return i.id;}),next=index+spec[1],temp=order[index];order[index]=order[next];order[next]=temp;run('order',{order:order.join(',')});}});row.appendChild(button);});li.appendChild(row);list.appendChild(li);});root.querySelectorAll('button').forEach(function(button){if(busy)button.disabled=true;});}
function send(operation,values,file){return new Promise(function(resolve,reject){var data=new FormData();data.set('action','wuai_topic_media');data.set('nonce',config.nonce);data.set('operation',operation);data.set('confirm_public','1');if(state){data.set('id',String(state.id));data.set('version',String(state.version));}Object.keys(values||{}).forEach(function(key){data.set(key,values[key]);});if(file)data.set('image',file,file.name);var xhr=new XMLHttpRequest();xhr.open('POST',config.endpoint);xhr.responseType='json';xhr.timeout=120000;xhr.upload.onprogress=function(event){if(file&&event.lengthComputable){progress.hidden=false;progress.value=Math.floor(event.loaded/event.total*100);message('网络上传 '+progress.value+'%（不代表服务器已保存）');}};xhr.upload.onload=function(){if(file)message('网络上传完成，服务器正在重新编码并核验…');};xhr.onload=function(){var result=xhr.response;if(!result||!result.success){reject(new Error(result&&result.data&&result.data.message||'服务器返回异常，请刷新核对。'));return;}resolve(result.data);};xhr.onerror=xhr.ontimeout=function(){reject(new Error('连接中断，请先刷新核对，不要重复上传同一图片。'));};xhr.send(data);});}
async function run(operation,values,file){if(busy)return;if(!root.querySelector('[data-media-confirm]').checked){message('请先确认图片上传后立即公开。');return;}busy=true;render();try{state=await send(operation,values,file);if(operation==='create'){history.replaceState(null,'',state.url);}if(operation==='submit'){message('稿件已进入管理员审核；图片直链早已公开。');}else if(operation==='abandon'){message('专用图片已清理，稿件已移入回收站。');}else{message('操作已保存，图片直链是公开的。');}return true;}catch(error){message(error.message);return false;}finally{busy=false;progress.hidden=true;root.querySelectorAll('button').forEach(function(b){b.disabled=false;});render();}}
root.querySelector('[data-media-create]').addEventListener('click',function(){run('create',{request:config.request,group:config.group});});root.querySelector('[data-media-submit]').addEventListener('click',function(){run('submit',{title:title.value,body:body.value});});root.querySelector('[data-media-withdraw]').addEventListener('click',function(){if(confirm('撤回后文字话题不再公开，但图片直链仍公开。确认继续？'))run('withdraw',{});});root.querySelector('[data-media-abandon]').addEventListener('click',function(){if(confirm('删除这份草稿的全部专用图片并放弃稿件？不可恢复。'))run('abandon',{});});input.addEventListener('change',async function(){var files=Array.from(input.files||[]);for(var i=0;i<files.length;i++){if(!state||state.items.length>=5){message('最多 5 张，剩余文件没有上传。');break;}if(!await run('upload',{request:uuid()},files[i]))break;}input.value='';});if(state){title.value=state.title;body.value=state.body;}render();})();
</script>
    <?php
}
