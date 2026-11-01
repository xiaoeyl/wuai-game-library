<?php
if (!defined('ABSPATH')) { exit; }

// New creation stays disabled until the economy contract and admin opt-in agree.
// Bounty amounts are NOT events. One immutable allocation row accounts for both
// sides; deleting content or an account must never delete this allocation row.
function wuai_sluyu_bounty_schema() {
    global $wpdb;
    return 'CREATE TABLE ' . wuai_sluyu_table('bounties') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        question_id bigint(20) unsigned NOT NULL,
        requester_id bigint(20) unsigned NOT NULL,
        request_key char(36) NOT NULL,
        request_hash char(64) NOT NULL,
        amount int(10) unsigned NOT NULL,
        audience varchar(12) NOT NULL,
        targets varchar(100) NOT NULL,
        deadline bigint(20) unsigned NOT NULL,
        prompt_hash char(64) NOT NULL,
        state varchar(12) NOT NULL,
        version bigint(20) unsigned NOT NULL DEFAULT 1,
        winner_id bigint(20) unsigned NOT NULL DEFAULT 0,
        accepted_comment bigint(20) unsigned NOT NULL DEFAULT 0,
        reserved_at datetime NOT NULL,
        opened_at datetime DEFAULT NULL,
        settled_at datetime DEFAULT NULL,
        settled_by bigint(20) unsigned NOT NULL DEFAULT 0,
        reason varchar(40) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        UNIQUE KEY question (question_id),
        UNIQUE KEY request_once (requester_id,request_key),
        KEY requester_state (requester_id,state),
        KEY winner_state (winner_id,state),
        KEY state_deadline (state,deadline)
    ) " . $wpdb->get_charset_collate() . ';';
}

function wuai_sluyu_bounty_enabled() {
    return get_option('wuai_sluyu_bounty_enabled', '0') === '1' && wuai_sluyu_economy_contract_ready();
}

function wuai_sluyu_bounty_row($question_id) {
    if (!is_scalar($question_id) || !absint($question_id)) { return new WP_Error('bounty_id', '问题编号无效。'); }
    if (!wuai_sluyu_economy_storage_ready()) { return new WP_Error('bounty_storage', '悬赏记账暂不可用。'); }
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . wuai_sluyu_table('bounties') . ' WHERE question_id=%d', absint($question_id)), ARRAY_A);
    if ($wpdb->last_error) { return new WP_Error('bounty_storage', '无法读取悬赏记账记录。'); }
    if (!$row) { return false; }
    foreach (['id','question_id','requester_id','amount','deadline','version','winner_id','accepted_comment','settled_by'] as $key) {
        if (!isset($row[$key]) || !preg_match('/^[0-9]+$/D', (string) $row[$key])) { return new WP_Error('bounty_corrupt', '悬赏分配记录需要管理员核验。'); }
        $row[$key] = (int) $row[$key];
    }
    $targets = json_decode($row['targets'], true);
    if (!$row['id'] || !$row['question_id'] || !$row['requester_id'] || $row['amount'] < 1 || $row['amount'] > 1000000 || !$row['version'] || !in_array($row['state'], ['held','open','awarded','refunded'], true) || !in_array($row['audience'], ['everyone','someone'], true) || !is_array($targets) || count($targets) > 4 || array_values(array_unique($targets)) !== $targets || ($row['audience'] === 'someone' ? !$targets : (bool) $targets) || !preg_match('/^[a-f0-9]{64}$/D', $row['prompt_hash']) || !preg_match('/^[a-f0-9]{64}$/D', $row['request_hash'])) { return new WP_Error('bounty_corrupt', '悬赏分配记录需要管理员核验。'); }
    foreach ($targets as $target) { if (!is_int($target) || $target < 1 || $target === $row['requester_id']) { return new WP_Error('bounty_corrupt', '指定回答用户记录无效。'); } }
    if (($row['state'] === 'awarded' && (!$row['winner_id'] || $row['winner_id'] === $row['requester_id'] || !$row['accepted_comment'] || !$row['settled_at'])) || ($row['state'] !== 'awarded' && ($row['winner_id'] || $row['accepted_comment']))) { return new WP_Error('bounty_corrupt', '悬赏结算结果需要管理员核验。'); }
    $row['target_ids'] = $targets;
    return $row;
}

function wuai_sluyu_bounty_exists($question_id) {
    $row = wuai_sluyu_bounty_row($question_id);
    return is_wp_error($row) ? $row : (bool) $row;
}

function wuai_sluyu_bounty_managed($id) {
    if (get_post_meta($id, '_wuai_topic_kind', true) === 'bounty') { return true; }
    if (!wuai_sluyu_economy_storage_ready()) { return get_post_type($id) === 'sluyu_topic'; }
    $row = wuai_sluyu_bounty_row($id);
    return is_wp_error($row) || (bool) $row;
}

function wuai_sluyu_bounty_prompt_hash($id) {
    $post = get_post($id); if (!$post) { return new WP_Error('bounty_missing', '问题已不存在。'); }
    $groups = wp_get_object_terms($id, 'sluyu_circle', ['fields'=>'ids', 'wuai_include_pending'=>true]);
    if (is_wp_error($groups)) { return $groups; }
    $groups = array_map('intval', $groups); sort($groups, SORT_NUMERIC);
    return hash('sha256', wp_json_encode([$post->post_title, $post->post_content, $post->post_excerpt, (int) $post->post_author, $post->post_password, get_post_meta($id, '_wuai_topic_kind', true), $groups]));
}

function wuai_sluyu_bounty_post_matches($row) {
    $post = get_post($row['question_id']); $hash = wuai_sluyu_bounty_prompt_hash($row['question_id']);
    return $post && !(function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($post->ID)) && !is_wp_error($hash) && $post->post_type === 'sluyu_topic' && $post->post_password === '' && (int) $post->post_author === $row['requester_id'] && get_post_meta($post->ID, '_wuai_topic_kind', true) === 'bounty' && hash_equals($row['prompt_hash'], $hash);
}

function wuai_sluyu_bounty_validate($input, $uid) {
    if (!is_array($input)) { return new WP_Error('bounty_input', '悬赏表单无效。'); }
    foreach (['amount','days','audience','targets','request_key','title','body','group','confirm'] as $key) {
        if (!isset($input[$key]) || !is_string($input[$key])) { return new WP_Error('bounty_input', '悬赏字段必须是普通文本值，不能提交数组。'); }
    }
    if (!preg_match('/^[1-9][0-9]{0,6}$/D', $input['amount']) || (int) $input['amount'] > 1000000 || !preg_match('/^(?:[2-9]|1[0-9]|2[0-9])$/D', $input['days']) || !in_array($input['audience'], ['everyone','someone'], true) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $input['request_key']) || $input['confirm'] !== '1') { return new WP_Error('bounty_terms', '请确认 1–1000000 的整数悬赏积分及 2–29 天期限。提交后立即预留积分，包含审核期间。'); }
    if (!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $input['group']) || ((int) $input['group'] && !wuai_sluyu_circle_can_post((int) $input['group'], $uid))) { return new WP_Error('bounty_group', '所选圈子不可发布，不会自动改为未分组悬赏。'); }
    $targets = [];
    if ($input['audience'] === 'someone') {
        if (strlen($input['targets']) > 100 || !preg_match('/^[1-9][0-9]{0,9}(?:,[1-9][0-9]{0,9}){0,3}$/D', $input['targets'])) { return new WP_Error('bounty_targets', '请选择 1–4 个不同的注册用户 ID，用英文逗号分隔。'); }
        $targets = array_map('intval', explode(',', $input['targets']));
        if (count(array_unique($targets)) !== count($targets)) { return new WP_Error('bounty_targets', '不能重复指定同一回答用户。'); }
        foreach ($targets as $target) {
            if ($target === (int) $uid || !get_userdata($target) || wuai_sluyu_banned($target) || ((int) $input['group'] && !wuai_sluyu_circle_can_post((int) $input['group'], $target))) { return new WP_Error('bounty_targets', '指定用户当前不可用或不能参与所选圈子。'); }
        }
        sort($targets, SORT_NUMERIC);
    } elseif ($input['targets'] !== '') { return new WP_Error('bounty_targets', '所有人模式不能同时填写指定用户 ID。'); }
    $title = sanitize_text_field($input['title']); $body = wp_kses_post($input['body']);
    if (mb_strlen($title) < 2 || mb_strlen($title) > 120 || mb_strlen(trim(wp_strip_all_tags($body))) < 5 || mb_strlen($body) > 20000) { return new WP_Error('bounty_content', '请填写 2–120 字标题及 5–20000 字公开问题。'); }
    $data = ['amount'=>(int) $input['amount'], 'days'=>(int) $input['days'], 'audience'=>$input['audience'], 'targets'=>wp_json_encode($targets), 'request_key'=>$input['request_key'], 'title'=>$title, 'body'=>$body, 'group'=>(int) $input['group']];
    $data['request_hash'] = hash('sha256', wp_json_encode($data));
    return $data;
}

function wuai_sluyu_bounty_request($uid, $key) {
    global $wpdb;
    $id = $wpdb->get_var($wpdb->prepare('SELECT question_id FROM ' . wuai_sluyu_table('bounties') . ' WHERE requester_id=%d AND request_key=%s', $uid, $key));
    return $wpdb->last_error ? new WP_Error('bounty_storage', '此前提交记录暂不可核验。') : ($id ? wuai_sluyu_bounty_row($id) : false);
}

function wuai_sluyu_bounty_submit($input) {
    $uid = get_current_user_id();
    if (!$uid || !get_userdata($uid) || wuai_sluyu_banned($uid) || !wuai_sluyu_bounty_enabled()) { return new WP_Error('bounty_disabled', '当前账号不可发起悬赏，或管理员尚未启用此功能。'); }
    $data = wuai_sluyu_bounty_validate($input, $uid); if (is_wp_error($data)) { return $data; }
    $guard = wuai_sluyu_economy_enter('bounty_submit'); if (is_wp_error($guard)) { return $guard; }
    try {
        $settled = wuai_sluyu_bounty_due_for_user($uid); if (is_wp_error($settled)) { return $settled; }
        $existing = wuai_sluyu_bounty_request($uid, $data['request_key']);
        if (is_wp_error($existing)) { return $existing; }
        if ($existing) {
            if (!hash_equals($existing['request_hash'], $data['request_hash'])) { return new WP_Error('bounty_conflict', '此提交标识已经用于另一组条款，请刷新并核对已有问题。'); }
            return ['message'=>'此问题已经提交，不会重复预留积分。', 'id'=>$existing['question_id'], 'reload'=>true];
        }
        $balance = wuai_sluyu_economy_balance($uid);
        if (is_wp_error($balance) || $balance < $data['amount']) { return new WP_Error('bounty_points', '完整可用积分暂不可验证或余额不足，本次未预留悬赏。'); }
        if (get_transient('wuai_bounty_submit_' . $uid)) { return new WP_Error('bounty_rate', '提交过于频繁，请稍后再发起新问题。'); }
        $id = wp_insert_post(wp_slash(['post_type'=>'sluyu_topic', 'post_status'=>'draft', 'post_author'=>$uid, 'post_title'=>$data['title'], 'post_content'=>$data['body'], 'comment_status'=>'open', 'ping_status'=>'closed', 'meta_input'=>['_wuai_topic_kind'=>'bounty']]), true);
        if (is_wp_error($id) || !$id) { return new WP_Error('bounty_post', '无法创建草稿，本次未预留积分。'); }
        if (get_post_meta($id, '_wuai_topic_kind', true) !== 'bounty') { return new WP_Error('bounty_partial', '草稿类型未核验，本次未预留积分。'); }
        if ($data['group']) {
            $assigned = wp_set_object_terms($id, [$data['group']], 'sluyu_circle');
            $groups = wp_get_object_terms($id, 'sluyu_circle', ['fields'=>'ids','wuai_include_pending'=>true]);
            if (is_wp_error($assigned) || is_wp_error($groups) || array_map('intval', $groups) !== [$data['group']] || !wuai_sluyu_circle_can_post($data['group'], $uid)) { return new WP_Error('bounty_partial', '草稿圈子归属未核验，本次未预留积分。'); }
        }
        $hash = wuai_sluyu_bounty_prompt_hash($id); if (is_wp_error($hash)) { return $hash; }
        $deadline = time() + $data['days'] * DAY_IN_SECONDS;
        wuai_sluyu_economy_allocation_insert(['question_id'=>(int) $id, 'requester_id'=>$uid, 'request_key'=>$data['request_key'], 'request_hash'=>$data['request_hash'], 'amount'=>$data['amount'], 'audience'=>$data['audience'], 'targets'=>$data['targets'], 'deadline'=>$deadline, 'prompt_hash'=>$hash, 'state'=>'held', 'version'=>1, 'reserved_at'=>current_time('mysql')], $uid, $data['amount'], $guard['token']);
        $row = wuai_sluyu_bounty_request($uid, $data['request_key']);
        if (is_wp_error($row) || !$row || $row['question_id'] !== (int) $id || !hash_equals($row['request_hash'], $data['request_hash'])) { return new WP_Error('bounty_reserve', '预留记录尚未核验，草稿保持不公开；请先检查积分及流水再重试。'); }
        set_transient('wuai_bounty_submit_' . $uid, 1, 20);
        $queued = wp_update_post(['ID'=>$id, 'post_status'=>'pending'], true);
        if (is_wp_error($queued) || get_post_status($id) !== 'pending' || !wuai_sluyu_bounty_post_matches($row) || wuai_sluyu_circle_topic_participation($id, $uid) !== true) {
            $refund = wuai_sluyu_bounty_refund_locked($row, 'queue_failed', $guard['token']);
            return new WP_Error('bounty_partial', is_wp_error($refund) ? '进入审核队列失败，预留积分的恢复需要核验，核验前仍保持占用。' : '进入审核队列失败，已核验退回预留积分，该问题不可发放奖励。');
        }
        wuai_sluyu_notify($uid, '积分悬赏已预留，正在等待站点审核，有效期包含审核时间。');
        return ['message'=>'悬赏已提交审核并立即预留积分；截止前未采纳回答将退回预留积分。', 'id'=>(int) $id, 'reload'=>true];
    } finally { wuai_sluyu_economy_leave($guard); }
}

function wuai_sluyu_bounty_refund_locked($row, $reason, $token) {
    if (is_wp_error($row) || !is_array($row)) { return new WP_Error('bounty_row', '无法读取预留记录。'); }
    if ($row['state'] === 'refunded') { return true; }
    if ($row['state'] === 'awarded') { return new WP_Error('bounty_final', '悬赏奖励已发放，内容审核不能追回已分配积分。'); }
    $reasons = ['expired','withdrawn','review_rejected','queue_failed','content_changed','content_unavailable','requester_deleted','targets_unavailable'];
    if (!in_array($reason, $reasons, true)) { return new WP_Error('bounty_reason', '退回原因无效。'); }
    global $wpdb;
    $wpdb->query($wpdb->prepare('UPDATE ' . wuai_sluyu_table('bounties') . " SET state='refunded',version=version+1,settled_at=%s,settled_by=%d,reason=%s WHERE id=%d AND version=%d AND state IN ('held','open') AND ", current_time('mysql'), get_current_user_id(), $reason, $row['id'], $row['version']) . wuai_sluyu_economy_fence($token));
    $saved = wuai_sluyu_bounty_row($row['question_id']);
    return !is_wp_error($saved) && $saved && $saved['state'] === 'refunded' ? true : new WP_Error('bounty_refund', '退回尚未核验，请重试状态结算；不得另加退款流水补账。');
}

function wuai_sluyu_bounty_reconcile_locked($row, $token) {
    if (is_wp_error($row) || !$row || in_array($row['state'], ['awarded','refunded'], true)) { return $row; }
    $reason = '';
    if ($row['deadline'] <= time()) { $reason = 'expired'; }
    elseif (!get_userdata($row['requester_id'])) { $reason = 'requester_deleted'; }
    elseif (!wuai_sluyu_bounty_post_matches($row)) { $reason = 'content_changed'; }
    elseif (!in_array(get_post_status($row['question_id']), $row['state'] === 'held' ? ['pending','publish'] : ['publish'], true)) { $reason = 'content_unavailable'; }
    elseif ($row['audience'] === 'someone') {
        $available = false;
        foreach ($row['target_ids'] as $uid) { if (get_userdata($uid) && !wuai_sluyu_banned($uid)) { $available = true; break; } }
        if (!$available) { $reason = 'targets_unavailable'; }
    }
    if ($reason) {
        $result = wuai_sluyu_bounty_refund_locked($row, $reason, $token);
        return is_wp_error($result) ? $result : wuai_sluyu_bounty_row($row['question_id']);
    }
    return $row;
}

function wuai_sluyu_bounty_review($id, $decision, $version) {
    if (!current_user_can('manage_options') || wuai_sluyu_banned(get_current_user_id()) || !in_array($decision, ['approve','reject'], true) || !is_string($version) || !preg_match('/^[1-9][0-9]*$/D', $version)) { return new WP_Error('bounty_review', '仅站点管理员可以独立审核此预留记录。'); }
    $guard = wuai_sluyu_economy_enter('bounty_review'); if (is_wp_error($guard)) { return $guard; }
    try {
        $row = wuai_sluyu_bounty_row($id); $row = wuai_sluyu_bounty_reconcile_locked($row, $guard['token']);
        if (is_wp_error($row) || !$row) { return is_wp_error($row) ? $row : new WP_Error('bounty_missing', '此问题没有积分预留记录。'); }
        if ($row['version'] !== (int) $version) { return new WP_Error('bounty_changed', '预留记录已变化，请刷新后再审核。'); }
        if ($decision === 'reject') { return wuai_sluyu_bounty_refund_locked($row, 'review_rejected', $guard['token']); }
        if ($row['state'] === 'open') { return true; }
        if (!wuai_sluyu_economy_contract_ready() || $row['state'] !== 'held' || get_post_status($id) !== 'publish' || !wuai_sluyu_bounty_post_matches($row) || wuai_sluyu_banned($row['requester_id']) || wuai_sluyu_circle_topic_participation($id, $row['requester_id']) !== true) { return new WP_Error('bounty_publish', '请先发布审核后的问题并核对提问者和圈子资格，再单独开启预留悬赏；仅点击原生发布按钮不会激活奖励。'); }
        global $wpdb;
        $post = get_post($id);
        $snapshot = wuai_sluyu_bounty_snapshot_sql($wpdb->postmeta, $wpdb->prepare("post_id=%d AND meta_key IN ('_wuai_topic_kind','_wuai_circle_review_hold')", $id), 'meta_id', ['meta_key','meta_value']);
        if(is_wp_error($snapshot))return $snapshot;
        $wpdb->query($wpdb->prepare('UPDATE ' . wuai_sluyu_table('bounties') . " SET state='open',version=version+1,opened_at=%s WHERE id=%d AND version=%d AND state='held' AND deadline>UNIX_TIMESTAMP() AND EXISTS (SELECT 1 FROM $wpdb->posts WHERE ID=%d AND post_type='sluyu_topic' AND post_status='publish' AND post_author=%d AND post_password='' AND BINARY post_title=BINARY %s AND BINARY post_content=BINARY %s AND BINARY post_excerpt=BINARY %s) AND NOT EXISTS (SELECT 1 FROM $wpdb->postmeta WHERE post_id=%d AND meta_key='_wuai_circle_review_hold') AND ", current_time('mysql', true), $row['id'], $row['version'], $id, $row['requester_id'], $post->post_title, $post->post_content, $post->post_excerpt, $id) . wuai_sluyu_economy_fence($guard['token']) . ' AND ' . $snapshot);
        $saved = wuai_sluyu_bounty_row($id);
        return !is_wp_error($saved) && $saved && $saved['state'] === 'open' ? true : new WP_Error('bounty_review', '开启悬赏的结果尚未核验。');
    } finally { wuai_sluyu_economy_leave($guard); }
}

function wuai_sluyu_bounty_answer_allowed($row, $uid) {
    $uid = absint($uid);
    if (is_wp_error($row) || !$row || $row['state'] !== 'open' || $row['deadline'] <= time() || !$uid || !get_userdata($uid) || $uid === $row['requester_id'] || wuai_sluyu_banned($uid) || !wuai_sluyu_bounty_post_matches($row) || get_post_status($row['question_id']) !== 'publish' || ($row['audience'] === 'someone' && !in_array($uid, $row['target_ids'], true))) { return false; }
    return wuai_sluyu_circle_topic_participation($row['question_id'], $uid) === true;
}

function wuai_sluyu_bounty_snapshot_sql($table, $where, $key, $columns, $limit = 80) {
    global $wpdb;
    $select = array_merge([$key], $columns);
    $rows = $wpdb->get_results('SELECT ' . implode(',', $select) . ' FROM ' . $table . ' WHERE ' . $where . ' ORDER BY ' . $key . ' LIMIT ' . (absint($limit)+1), ARRAY_A);
    if ($wpdb->last_error || !is_array($rows) || count($rows) > $limit) { return new WP_Error('bounty_snapshot', '参与资格暂不可读或过于复杂，无法完成原子核验。'); }
    $parts = ['(SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where . ')=' . count($rows)];
    foreach ($rows as $row) {
        $equal = [];
        foreach ($select as $field) { $equal[] = 'BINARY ' . $field . '=BINARY ' . $wpdb->prepare('%s', (string) $row[$field]); }
        $parts[] = 'EXISTS (SELECT 1 FROM ' . $table . ' WHERE ' . $where . ' AND ' . implode(' AND ', $equal) . ')';
    }
    return implode(' AND ', $parts);
}

function wuai_sluyu_bounty_award_eligibility_sql($row, $winner) {
    // Capture raw authoritative eligibility first, then evaluate the normal
    // rules with clean caches. The award SQL must still match the captured rows.
    // Thus an intervening ban/role/member/circle change causes a retry, not an
    // award based on stale PHP caches. No external service calls occur here.
    global $wpdb; $id = $row['question_id']; $requester = $row['requester_id'];
    $members = wuai_sluyu_table('memberships'); $member_deadline_required = false;
    foreach ([$requester, $winner] as $uid) { clean_user_cache($uid); wp_cache_delete($uid, 'user_meta'); }
    $relations = $wpdb->get_results($wpdb->prepare("SELECT r.term_taxonomy_id,t.term_id,t.taxonomy,s.slug FROM $wpdb->term_relationships r INNER JOIN $wpdb->term_taxonomy t ON t.term_taxonomy_id=r.term_taxonomy_id INNER JOIN $wpdb->terms s ON s.term_id=t.term_id WHERE r.object_id=%d AND t.taxonomy='sluyu_circle' ORDER BY r.term_taxonomy_id LIMIT 17", $id), ARRAY_A);
    if ($wpdb->last_error || !is_array($relations) || count($relations) > 16) { return new WP_Error('bounty_groups', '无法原子核验圈子参与资格。'); }
    $parts = [$wpdb->prepare("(SELECT COUNT(*) FROM $wpdb->term_relationships r INNER JOIN $wpdb->term_taxonomy t ON t.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=%d AND t.taxonomy='sluyu_circle')=%d", $id, count($relations))];
    foreach ($relations as $relation) {
        $term = (int) $relation['term_id'];
        $parts[] = $wpdb->prepare("EXISTS (SELECT 1 FROM $wpdb->term_relationships r INNER JOIN $wpdb->term_taxonomy t ON t.term_taxonomy_id=r.term_taxonomy_id INNER JOIN $wpdb->terms s ON s.term_id=t.term_id WHERE r.object_id=%d AND r.term_taxonomy_id=%d AND t.term_id=%d AND t.taxonomy='sluyu_circle' AND s.slug=%s)", $id, $relation['term_taxonomy_id'], $term, $relation['slug']);
        $snapshot = wuai_sluyu_bounty_snapshot_sql($wpdb->termmeta, $wpdb->prepare("term_id=%d AND meta_key IN ('_wuai_circle_owner','_wuai_circle_status','_wuai_circle_join','_wuai_circle_posting')", $term), 'meta_id', ['meta_key','meta_value']);
        if (is_wp_error($snapshot)) { return $snapshot; } $parts[] = $snapshot;
        $snapshot = wuai_sluyu_bounty_snapshot_sql($members, $wpdb->prepare('circle_id=%d AND user_id=%d', $term, $winner), 'id', ['circle_id','user_id','status']);
        if (is_wp_error($snapshot)) { return $snapshot; } $parts[] = $snapshot;
        clean_term_cache($term, 'sluyu_circle'); wp_cache_delete($term, 'term_meta');
    }
    $snapshot = wuai_sluyu_bounty_snapshot_sql($wpdb->postmeta, $wpdb->prepare("post_id=%d AND meta_key IN ('_wuai_topic_kind','_wuai_circle_review_hold')", $id), 'meta_id', ['meta_key','meta_value']);
    if (is_wp_error($snapshot)) { return $snapshot; } $parts[] = $snapshot;
    foreach ([$requester, $winner] as $uid) {
        $role_key = $wpdb->get_blog_prefix() . 'capabilities';
        $where = $wpdb->prepare("user_id=%d AND meta_key IN ('_wuai_banned_until','_wuai_membership_state','_wuai_membership_sync','_wuai_member_until',%s)", $uid, $role_key);
        $snapshot = wuai_sluyu_bounty_snapshot_sql($wpdb->usermeta, $where, 'umeta_id', ['meta_key','meta_value']);
        if (is_wp_error($snapshot)) { return $snapshot; } $parts[] = $snapshot;
        $parts[] = $wpdb->prepare("NOT EXISTS (SELECT 1 FROM $wpdb->usermeta WHERE user_id=%d AND meta_key='_wuai_banned_until' AND (meta_value='-1' OR CAST(meta_value AS SIGNED)>UNIX_TIMESTAMP()))", $uid);
        clean_user_cache($uid); wp_cache_delete($uid, 'user_meta');
    }
    $pending_where = $wpdb->prepare('option_name LIKE %s AND option_value LIKE %s', $wpdb->esc_like('wuai_membership_pending_users_') . '%', '%' . $wpdb->esc_like(';i:' . $winner . ';') . '%');
    $snapshot = wuai_sluyu_bounty_snapshot_sql($wpdb->options, $pending_where, 'option_id', ['option_name','option_value']);
    if (is_wp_error($snapshot)) { return $snapshot; } $parts[] = $snapshot;
    $role_option = $wpdb->get_blog_prefix() . 'user_roles';
    $snapshot = wuai_sluyu_bounty_snapshot_sql($wpdb->options, $wpdb->prepare('option_name=%s', $role_option), 'option_id', ['option_name','option_value']);
    if (is_wp_error($snapshot)) { return $snapshot; } $parts[] = $snapshot;
    foreach ([$role_option, 'alloptions', 'notoptions'] as $key) { wp_cache_delete($key, 'options'); }
    if (function_exists('wp_roles') && method_exists(wp_roles(), 'for_site')) { wp_roles()->for_site(get_current_blog_id()); }
    foreach ([$requester, $winner] as $uid) { clean_user_cache($uid); wp_cache_delete($uid, 'user_meta'); }
    clean_post_cache($id); clean_object_term_cache($id, 'sluyu_topic');
    if (wuai_sluyu_banned($requester) || !wuai_sluyu_bounty_answer_allowed($row, $winner)) { return new WP_Error('bounty_eligibility', '提问者、回答者或圈子参与资格已发生变化。'); }
    foreach ($relations as $relation) { $term = (int) $relation['term_id']; if (get_term_meta($term, '_wuai_circle_join', true) === 'member' && !wuai_sluyu_circle_manage($term, $winner)) { $member_deadline_required = true; } }
    if ($member_deadline_required) {
        $until = wuai_sluyu_membership_until($winner);
        if ($until !== -1) { $parts[] = $wpdb->prepare('%d>UNIX_TIMESTAMP()', $until); }
    }
    return implode(' AND ', $parts);
}

function wuai_sluyu_bounty_accept($comment_id, $version) {
    $uid = get_current_user_id();
    if (!$uid || !get_userdata($uid) || wuai_sluyu_banned($uid) || !is_scalar($comment_id) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $comment_id) || !is_string($version) || !preg_match('/^[1-9][0-9]*$/D', $version) || !wuai_sluyu_economy_contract_ready()) { return new WP_Error('bounty_auth', '请使用有效的原提问者账号及当前预留版本。'); }
    $guard = wuai_sluyu_economy_enter('bounty_accept'); if (is_wp_error($guard)) { return $guard; }
    try {
        clean_user_cache($uid); wp_cache_delete($uid, 'user_meta'); clean_comment_cache(absint($comment_id));
        $comment = get_comment(absint($comment_id)); $id = $comment ? (int) $comment->comment_post_ID : 0;
        if (!$comment) {
            global $wpdb;
            $prior = $wpdb->get_var($wpdb->prepare('SELECT question_id FROM ' . wuai_sluyu_table('bounties') . " WHERE requester_id=%d AND state='awarded' AND accepted_comment=%d LIMIT 1", $uid, absint($comment_id)));
            if (!$wpdb->last_error && $prior) { return ['message'=>'此回答删除前已经发奖，本次没有再次移动积分。', 'reload'=>true]; }
        }
        $row = $id ? wuai_sluyu_bounty_row($id) : false;
        if (is_wp_error($row) || !$row || $row['requester_id'] !== $uid) { return new WP_Error('bounty_owner', '仅原提问者可以选择获奖回答；管理员审核内容，不代替用户选择获奖者。'); }
        if ($row['state'] === 'awarded') { return $row['accepted_comment'] === (int) $comment_id ? ['message'=>'此回答已经采纳，不会重复发放积分。', 'reload'=>true] : new WP_Error('bounty_final', '已有其他回答获奖，不能更换采纳结果。'); }
        $row = wuai_sluyu_bounty_reconcile_locked($row, $guard['token']);
        if (is_wp_error($row) || !$row || $row['state'] !== 'open' || $row['version'] !== (int) $version) { return new WP_Error('bounty_changed', '悬赏已关闭、到期或发生变化，请刷新状态。'); }
        $winner = (int) $comment->user_id;
        if ($comment->comment_approved !== '1' || !in_array($comment->comment_type, ['', 'comment'], true) || (int) $comment->comment_parent !== 0 || !wuai_sluyu_bounty_answer_allowed($row, $winner)) { return new WP_Error('bounty_answer', '请选择合格注册用户的已审核顶层回答，不能采纳自己的回答。'); }
        $date = strtotime($comment->comment_date_gmt . ' UTC'); $opened = strtotime($row['opened_at'] . ' UTC');
        if (!$date || !$opened || $date < $opened || $date >= $row['deadline']) { return new WP_Error('bounty_answer_time', '回答并非在本悬赏有效期间内提交。'); }
        $eligibility = wuai_sluyu_bounty_award_eligibility_sql($row, $winner); if (is_wp_error($eligibility)) { return $eligibility; }
        global $wpdb;
        // This CAS is the debit allocation AND the winner credit. No second write
        // may grant points. The SQL predicate rechecks native comment moderation.
        $post = get_post($id);
        $sql = $wpdb->prepare('UPDATE ' . wuai_sluyu_table('bounties') . " SET state='awarded',winner_id=%d,accepted_comment=%d,settled_at=%s,settled_by=%d,reason='accepted',version=version+1 WHERE id=%d AND version=%d AND state='open' AND deadline>UNIX_TIMESTAMP() AND EXISTS (SELECT 1 FROM $wpdb->comments WHERE comment_ID=%d AND comment_post_ID=%d AND user_id=%d AND comment_approved='1' AND comment_type IN ('','comment') AND comment_parent=0 AND comment_date_gmt>=%s AND comment_date_gmt<%s) AND EXISTS (SELECT 1 FROM $wpdb->users WHERE ID=%d) AND EXISTS (SELECT 1 FROM $wpdb->users WHERE ID=%d) AND EXISTS (SELECT 1 FROM $wpdb->posts WHERE ID=%d AND post_type='sluyu_topic' AND post_status='publish' AND post_author=%d AND post_password='' AND BINARY post_title=BINARY %s AND BINARY post_content=BINARY %s AND BINARY post_excerpt=BINARY %s) AND ", $winner, $comment_id, current_time('mysql'), $uid, $row['id'], $row['version'], $comment_id, $id, $winner, $row['opened_at'], gmdate('Y-m-d H:i:s', $row['deadline']), $winner, $uid, $id, $uid, $post->post_title, $post->post_content, $post->post_excerpt) . wuai_sluyu_economy_fence($guard['token']);
        $wpdb->query($sql . ' AND ' . $eligibility); $saved = wuai_sluyu_bounty_row($id);
        if (is_wp_error($saved) || !$saved || $saved['state'] !== 'awarded' || $saved['winner_id'] !== $winner || $saved['accepted_comment'] !== (int) $comment_id) { return new WP_Error('bounty_award', '发奖结果尚未核验，请刷新权威悬赏记录；不得额外添加扣分或加分流水补账。'); }
        wuai_sluyu_notify($winner, '你的回答已被采纳，预留的站内积分已计入可用积分和流水。');
        return ['message'=>'回答已采纳，原预留积分已原子分配给回答者，没有再次扣分。', 'reload'=>true];
    } finally { wuai_sluyu_economy_leave($guard); }
}

function wuai_sluyu_bounty_withdraw($id, $version) {
    $uid = get_current_user_id(); $guard = wuai_sluyu_economy_enter('bounty_withdraw'); if (is_wp_error($guard)) { return $guard; }
    try {
        $row = wuai_sluyu_bounty_row($id);
        if (is_wp_error($row) || !$row || !$uid || wuai_sluyu_banned($uid) || $row['requester_id'] !== $uid || !is_string($version) || !preg_match('/^[1-9][0-9]*$/D', $version)) { return new WP_Error('bounty_owner', '请使用原提问者账号并刷新预留记录。'); }
        if ($row['state'] !== 'refunded' && $row['version'] !== (int) $version) { return new WP_Error('bounty_changed', '预留记录已变化，请刷新后再撤回。'); }
        $refund = wuai_sluyu_bounty_refund_locked($row, 'withdrawn', $guard['token']); if (is_wp_error($refund)) { return $refund; }
        // Returning the allocation first is safe: a later post failure cannot
        // award after the refund. Show the verified partial outcome, never undo it.
        $saved = wp_update_post(['ID'=>$id, 'post_status'=>'draft'], true);
        return ['message'=>is_wp_error($saved) || get_post_status($id) !== 'draft' ? '预留积分已退回，但页面撤回失败；请联系管理员隐藏页面，该问题已不可发奖。' : '问题已撤回，尚未发放的预留积分已退回。', 'reload'=>true];
    } finally { wuai_sluyu_economy_leave($guard); }
}

function wuai_sluyu_bounty_reconcile($id) {
    $guard = wuai_sluyu_economy_enter('bounty_reconcile'); if (is_wp_error($guard)) { return $guard; }
    try { return wuai_sluyu_bounty_reconcile_locked(wuai_sluyu_bounty_row($id), $guard['token']); }
    finally { wuai_sluyu_economy_leave($guard); }
}

function wuai_sluyu_bounty_sweep() {
    if (!wuai_sluyu_economy_storage_ready()) { return; }
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare('SELECT question_id FROM ' . wuai_sluyu_table('bounties') . " WHERE state IN ('held','open') AND deadline<=%d ORDER BY deadline,id LIMIT 50", time()));
    foreach ($ids as $id) { if (is_wp_error(wuai_sluyu_bounty_reconcile($id))) { break; } }
}
add_action('wuai_sluyu_bounty_sweep', 'wuai_sluyu_bounty_sweep');
add_action('init', function () {
    if (wuai_sluyu_economy_storage_ready() && !wp_next_scheduled('wuai_sluyu_bounty_sweep')) { wp_schedule_event(time() + 300, 'hourly', 'wuai_sluyu_bounty_sweep'); }
}, 40);
add_action('switch_theme', function () { wp_clear_scheduled_hook('wuai_sluyu_bounty_sweep'); });

function wuai_sluyu_bounty_due_for_user($uid, $limit = 25) {
    // Call before displaying a spendable balance or starting a spend. Do not
    // recursively call this from economy_balance() or its SQL expression.
    if (!wuai_sluyu_economy_storage_ready()) { return new WP_Error('bounty_storage', '预留积分结算暂不可用。'); }
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare('SELECT question_id FROM ' . wuai_sluyu_table('bounties') . " WHERE requester_id=%d AND state IN ('held','open') AND deadline<=%d ORDER BY deadline,id LIMIT %d", absint($uid), time(), max(1, min(100, absint($limit)))));
    if ($wpdb->last_error) { return new WP_Error('bounty_storage', '无法读取到期预留记录。'); }
    foreach ($ids as $id) { $result = wuai_sluyu_bounty_reconcile($id); if (is_wp_error($result)) { return $result; } }
    return true;
}

function wuai_sluyu_bounty_user_deleting($uid, $token) {
    if (!wuai_sluyu_economy_guard_valid($token)) { return new WP_Error('bounty_delete_lock', '删除账号必须持有积分记账锁。'); }
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare('SELECT question_id FROM ' . wuai_sluyu_table('bounties') . " WHERE requester_id=%d AND state IN ('held','open') ORDER BY id", absint($uid)));
    if ($wpdb->last_error) { return new WP_Error('bounty_delete', '账号预留积分无法核验，请停止删除。'); }
    foreach ($ids as $id) {
        $result = wuai_sluyu_bounty_refund_locked(wuai_sluyu_bounty_row($id), 'requester_deleted', $token);
        if (is_wp_error($result)) { return $result; }
    }
    $targeted = $wpdb->get_col("SELECT question_id FROM " . wuai_sluyu_table('bounties') . " WHERE audience='someone' AND state IN ('held','open') ORDER BY id");
    if ($wpdb->last_error) { return new WP_Error('bounty_delete', '指定回答者相关预留积分无法核验，请停止删除。'); }
    foreach ($targeted as $id) {
        $row = wuai_sluyu_bounty_row($id); if (is_wp_error($row)) { return $row; }
        if (!$row || !in_array(absint($uid), $row['target_ids'], true)) { continue; }
        $remaining = array_filter($row['target_ids'], function ($target) use ($uid) { return $target !== absint($uid) && get_userdata($target) && !wuai_sluyu_banned($target); });
        if (!$remaining) { $result = wuai_sluyu_bounty_refund_locked($row, 'targets_unavailable', $token); if (is_wp_error($result)) { return $result; } }
    }
    // Awarded allocations retain numeric requester/winner IDs and never cascade.
    // Reassigning WordPress post_author does not reassign this financial identity.
    return true;
}

function wuai_sluyu_bounty_content_guard($id, $reason) {
    if (!wuai_sluyu_bounty_managed($id)) { return true; }
    $guard = wuai_sluyu_economy_enter('bounty_content_change'); if (is_wp_error($guard)) { return $guard; }
    try {
        $row = wuai_sluyu_bounty_row($id);
        if (is_wp_error($row)) { return $row; }
        if (!$row || in_array($row['state'], ['awarded','refunded'], true)) { return true; }
        return wuai_sluyu_bounty_refund_locked($row, $reason, $guard['token']);
    } finally { wuai_sluyu_economy_leave($guard); }
}

// Run before native SQL writes. If the refund cannot be verified, native edits
// must stop rather than silently hiding a still-spendable reservation.
add_action('pre_post_update', function ($id, $data) {
    $old = get_post($id); if (!$old || $old->post_type !== 'sluyu_topic') { return; }
    $changing = false;
    foreach (['post_type','post_title','post_content','post_excerpt','post_author','post_password'] as $key) { if (isset($data[$key]) && (string) $data[$key] !== (string) $old->$key) { $changing = true; } }
    if (isset($data['post_status']) && ((in_array($data['post_status'], ['draft','trash','private','future'], true) && $data['post_status'] !== $old->post_status) || ($old->post_status === 'publish' && $data['post_status'] !== 'publish'))) { $changing = true; }
    if (!$changing) { return; }
    $result = wuai_sluyu_bounty_content_guard($id, 'content_changed');
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', ['response'=>409]); }
}, 8, 2);
foreach (['pre_trash_post','pre_delete_post'] as $hook) {
    add_filter($hook, function ($check, $post) {
        if ($check !== null || !$post || $post->post_type !== 'sluyu_topic') { return $check; }
        $result = wuai_sluyu_bounty_content_guard($post->ID, 'content_unavailable');
        return is_wp_error($result) ? false : $check;
    }, 8, 2);
}
foreach (['add_post_metadata','update_post_metadata','delete_post_metadata'] as $hook) {
    add_filter($hook, function ($check, $id, $key, $value) use ($hook) {
        if ($check !== null || $key !== '_wuai_topic_kind' || (get_post_meta($id, $key, true) === $value && $hook === 'update_post_metadata')) { return $check; }
        $result = wuai_sluyu_bounty_content_guard($id, 'content_changed');
        return is_wp_error($result) ? false : $check;
    }, 8, 4);
}

// Circle relationship APIs expose only partial pre-write hooks. Refuse changes
// to funded bounty circles rather than allowing an award during a partial move.
add_action('add_term_relationship', function ($id, $ttid, $taxonomy) {
    if ($taxonomy !== 'sluyu_circle') { return; }
    $result = wuai_sluyu_bounty_content_guard($id, 'content_changed');
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', ['response'=>409]); }
}, 8, 3);
add_action('delete_term_relationships', function ($id, $ttids, $taxonomy) {
    if ($taxonomy !== 'sluyu_circle') { return; }
    $result = wuai_sluyu_bounty_content_guard($id, 'content_changed');
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', ['response'=>409]); }
}, 8, 3);

add_action('wp_after_insert_post', function ($id, $post, $update, $before) {
    if (!$update || !$before || $post->post_type !== 'sluyu_topic' || $post->post_status === $before->post_status) { return; }
    if (get_post_meta($id, '_wuai_topic_kind', true) !== 'bounty') { return; }
    // A normal pending -> publish review leaves held funds untouched. A later
    // transition away from publish closes an already-open reservation.
    if ($before->post_status === 'publish' && $post->post_status !== 'publish') { wuai_sluyu_bounty_content_guard($id, 'content_unavailable'); }
}, 20, 4);

add_filter('wuai_sluyu_community_dispatch', function ($result, $operation, $uid) {
    if ($result !== null) { return $result; }
    if ($operation === 'bounty_submit') {
        $input = [];
        foreach (['amount','days','audience','targets','request_key','title','body','group','confirm'] as $key) { $input[$key] = isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : null; }
        return wuai_sluyu_bounty_submit($input);
    }
    if ($operation === 'bounty_accept') { return wuai_sluyu_input('confirm') === '1' ? wuai_sluyu_bounty_accept(wuai_sluyu_input('id'), wuai_sluyu_input('version')) : new WP_Error('bounty_confirm', '请先明确确认最终获奖回答，再分配预留积分。'); }
    if ($operation === 'bounty_withdraw') { return wuai_sluyu_bounty_withdraw(wuai_sluyu_input('id'), wuai_sluyu_input('version')); }
    if ($operation === 'accept') {
        $comment = get_comment(absint(wuai_sluyu_input('id')));
        if ($comment && wuai_sluyu_bounty_managed($comment->comment_post_ID)) { return new WP_Error('bounty_explicit', '请使用悬赏专用确认采纳操作，不能通过普通提问采纳绕过结算。'); }
    }
    return $result;
}, 12, 3);

add_filter('wuai_sluyu_account_submission_permission', function ($allowed, $post, $operation) {
    if (in_array($operation, ['edit_submission','withdraw_submission','delete_submission'], true) && wuai_sluyu_bounty_managed($post->ID)) { return new WP_Error('bounty_managed', '悬赏条款不可直接修改，请使用专用退回/撤回操作或联系站点管理员。'); }
    return $allowed;
}, 30, 3);

add_filter('preprocess_comment', function ($comment) {
    $id = isset($comment['comment_post_ID']) && is_scalar($comment['comment_post_ID']) ? absint($comment['comment_post_ID']) : 0;
    if (!$id || !wuai_sluyu_bounty_managed($id)) { return $comment; }
    $row = wuai_sluyu_bounty_row($id); $uid = get_current_user_id();
    if (!wuai_sluyu_bounty_answer_allowed($row, $uid) || !empty($comment['comment_parent'])) { wp_die('此悬赏仅接受截止前合格登录用户的顶层回答。', '', ['response'=>403]); }
    $comment['user_id'] = $uid;
    return $comment;
}, 25);

add_filter('rest_pre_insert_comment', function ($prepared, $request) {
    if (is_wp_error($prepared)) { return $prepared; }
    $id = isset($prepared->comment_post_ID) ? (int) $prepared->comment_post_ID : (is_scalar($request['post']) ? absint($request['post']) : 0);
    if ($id && wuai_sluyu_bounty_managed($id)) {
        $row = wuai_sluyu_bounty_row($id); $uid = get_current_user_id();
        if (is_wp_error($row) || !wuai_sluyu_bounty_answer_allowed($row, $uid) || !empty($prepared->comment_parent) || (isset($prepared->user_id) && (int) $prepared->user_id !== $uid)) { return new WP_Error('bounty_answer', '此悬赏当前不接受该账号的回答。', ['status'=>403]); }
    }
    return $prepared;
}, 25, 2);

// Native moderation may remove an accepted answer, but must never silently
// reassign its author/post/date and thereby change who an existing award names.
add_filter('wp_update_comment_data', function ($data, $previous) {
    if (is_wp_error($data) || !is_array($previous) || empty($previous['comment_post_ID'])) { return $data; }
    $id = (int) $previous['comment_post_ID'];
    $destination = isset($data['comment_post_ID']) ? absint($data['comment_post_ID']) : $id;
    if (!wuai_sluyu_bounty_managed($id) && !wuai_sluyu_bounty_managed($destination)) { return $data; }
    foreach (['comment_post_ID','user_id','comment_date','comment_date_gmt','comment_parent','comment_type'] as $key) {
        if (isset($data[$key], $previous[$key]) && (string) $data[$key] !== (string) $previous[$key]) { return new WP_Error('bounty_answer_identity', '悬赏回答的作者、所属问题、层级、类型和提交时间不可更改；请审核或移除内容。'); }
    }
    return $data;
}, 30, 2);

function wuai_sluyu_view_bounty_submit() {
    if (!wuai_sluyu_require_login()) { return; }
    if (!wuai_sluyu_bounty_enabled()) { wuai_sluyu_empty('积分悬赏尚未启用，需站点管理员完成记账集成与验证后开放。'); return; }
    $bad = (isset($_GET['group']) && !is_scalar($_GET['group'])) || (isset($_GET['circle_id']) && !is_scalar($_GET['circle_id']));
    $group = wuai_sluyu_query('group', '0');
    if ($bad || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $group) || ((int) $group && !wuai_sluyu_circle_can_post((int) $group, get_current_user_id()))) { wuai_sluyu_empty('所选圈子当前不可发布，不会自动改为未分组悬赏。'); return; }
    $balance = wuai_sluyu_points_available(get_current_user_id());
    if (is_wp_error($balance)) { wuai_sluyu_empty($balance->get_error_message()); return; }
    echo '<section class="sluyu-panel sluyu-bounty-submit"><h2>积分悬赏提问</h2><p>当前可用积分：' . esc_html($balance) . '。积分不是现金，不支持充值提现、转账兑换或真实支付，不保证答案质量。</p><p>提交后立即预留积分，审核期间同样占用。期限为提交起 2–29 天，包含审核时间；到期前仅提问者可采纳一条已审核的合格回答。到期未采纳退回预留积分，已发放的奖励不能撤回。</p>';
    wuai_sluyu_form_start('bounty_submit');
    echo '<input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '"><input type="hidden" name="group" value="' . esc_attr($group) . '"><label>标题<input name="title" minlength="2" maxlength="120" required></label><label>公开问题<textarea name="body" minlength="5" maxlength="20000" rows="6" required></textarea></label><label>悬赏积分<input type="number" name="amount" min="1" max="1000000" step="1" required></label><label>提问有效天数<input type="number" name="days" min="2" max="29" step="1" value="7" required></label><label>提问对象<select name="audience"><option value="everyone">所有合格登录用户</option><option value="someone">指定用户（最多 4 人）</option></select></label><label>指定用户 ID（英文逗号分隔；所有人模式留空）<input name="targets" maxlength="100" inputmode="numeric"></label><p>问题和通过审核的回答公开。指定用户限制回答与领奖资格，不代表私密阅读；当前不提供付费偷瞄答案。</p><label><span><input type="checkbox" name="confirm" value="1" required style="width:auto"> 我确认预留积分、包含审核的期限、采纳不可撤回及非现金规则。</span></label>';
    wuai_sluyu_form_end('确认预留积分并提交审核'); echo '</section>';
}

function wuai_sluyu_bounty_view($id) {
    $row = wuai_sluyu_bounty_row($id);
    if (is_wp_error($row)) { return '<section class="sluyu-panel sluyu-bounty"><p>积分分配暂不可验证，请勿假定已经退款或发奖。</p></section>'; }
    if (!$row) { return ''; }
    if (!in_array($row['state'], ['awarded','refunded'], true) && ($row['deadline'] <= time() || !wuai_sluyu_bounty_post_matches($row))) { $updated = wuai_sluyu_bounty_reconcile($id); if (!is_wp_error($updated) && $updated) { $row = $updated; } }
    $labels = ['held'=>'积分已预留，等待站点独立审核', 'open'=>'悬赏进行中，回答需审核', 'awarded'=>'已采纳发奖，不可撤回', 'refunded'=>'已关闭，预留积分已退回'];
    $html = '<section class="sluyu-panel sluyu-bounty" data-bounty-state="' . esc_attr($row['state']) . '"><h2>悬赏 ' . $row['amount'] . ' 积分</h2><p class="sluyu-state-pill">' . esc_html($labels[$row['state']]) . '</p><p>截止时间：' . esc_html(wp_date('Y-m-d H:i', $row['deadline'])) . '。仅站内非现金积分，不支持真实支付或提现，不保证答案质量。</p><p>' . ($row['audience'] === 'everyone' ? '所有满足条件的登录用户均可回答。' : '指定回答用户 ID：' . esc_html(implode(', ', $row['target_ids']))) . ' 问题及已审核回答公开。</p>';
    if (in_array($row['state'], ['held','open'], true) && $row['deadline'] <= time()) { $html .= '<p class="sluyu-notice">期限已到，回答和采纳已关闭；预留积分的退回尚未核验完成。请稍后重试，或联系管理员检查积分记账锁。</p>'; }
    if ($row['state'] === 'awarded') {
        $answer = get_comment($row['accepted_comment']);
        $available = $answer && $answer->comment_approved === '1' && (int) $answer->comment_post_ID === (int) $id;
        $html .= '<p data-bounty-answer-available="' . ($available ? '1' : '0') . '">已采纳回答 #' . $row['accepted_comment'] . '，奖励 ' . $row['amount'] . ' 积分，仅发放一次。' . ($available ? '' : '该回答目前不再公开；内容审核或删除不会逆转已完成的积分分配。') . '</p>';
    }
    if (get_current_user_id() === $row['requester_id'] && in_array($row['state'], ['held','open'], true)) {
        $html .= '<form class="sluyu-community-form" data-operation="bounty_withdraw"><div class="sluyu-form-status" role="status"></div><input type="hidden" name="id" value="' . absint($id) . '"><input type="hidden" name="version" value="' . $row['version'] . '"><button type="submit" class="sluyu-action-plain">撤回问题并退回未发放积分</button></form>';
        if ($row['state'] === 'open' && $row['deadline'] > time() && wuai_sluyu_bounty_post_matches($row)) {
            $answers = get_comments(['post_id'=>$id, 'status'=>'approve', 'type'=>'comment', 'parent'=>0, 'number'=>100, 'orderby'=>'comment_ID', 'order'=>'ASC']);
            $html .= '<h3>选择一条获奖回答</h3><p>采纳后将预留积分分配给回答者，不会再次扣除积分；该选择不可撤回。</p>';
            foreach ($answers as $answer) {
                if (!wuai_sluyu_bounty_answer_allowed($row, (int) $answer->user_id)) { continue; }
                $html .= '<form class="sluyu-community-form" data-operation="bounty_accept"><div class="sluyu-form-status" role="status"></div><input type="hidden" name="id" value="' . (int) $answer->comment_ID . '"><input type="hidden" name="version" value="' . $row['version'] . '"><p>#' . (int) $answer->comment_ID . '：' . esc_html(wp_trim_words(wp_strip_all_tags($answer->comment_content), 30)) . '</p><label><span><input type="checkbox" name="confirm" value="1" required style="width:auto"> 我确认采纳这条回答，奖励发放后不可撤回。</span></label><button type="submit" class="sluyu-action-primary">采纳并发放 ' . $row['amount'] . ' 积分</button></form>';
            }
        }
    }
    return $html . '</section>';
}

add_filter('rest_request_before_callbacks', function ($response) { $GLOBALS['wuai_sluyu_bounty_rest_depth'] = (int) ($GLOBALS['wuai_sluyu_bounty_rest_depth'] ?? 0) + 1; return $response; }, -1002);
add_filter('rest_request_after_callbacks', function ($response) { $GLOBALS['wuai_sluyu_bounty_rest_depth'] = max(0, (int) ($GLOBALS['wuai_sluyu_bounty_rest_depth'] ?? 0) - 1); return $response; }, 1002);
add_filter('the_content', function ($content) {
    $id = get_the_ID();
    if (!$id || is_admin() || wp_doing_ajax() || is_feed() || is_embed() || (defined('REST_REQUEST') && REST_REQUEST) || !empty($GLOBALS['wuai_sluyu_bounty_rest_depth']) || !is_singular('sluyu_topic') || !in_the_loop() || !is_main_query() || (int) get_queried_object_id() !== (int) $id || !wuai_sluyu_bounty_managed($id)) { return $content; }
    foreach (['get_the_excerpt','the_excerpt','the_excerpt_rss','the_content_feed'] as $hook) { if (doing_filter($hook)) { return $content; } }
    return $content . wuai_sluyu_bounty_view($id);
}, 110);

add_action('template_redirect', function () { if (is_singular('sluyu_topic') && wuai_sluyu_bounty_managed(get_queried_object_id())) { nocache_headers(); } }, 1);

function wuai_sluyu_bounty_admin_box($post) {
    if (!current_user_can('manage_options') || !wuai_sluyu_bounty_managed($post->ID)) { return; }
    $row = wuai_sluyu_bounty_row($post->ID);
    if (is_wp_error($row) || !$row) { echo '<p>没有已核验的预留记录，请勿承诺奖励或手工添加积分流水。</p>'; return; }
    echo '<p>出资人和悬赏条款不可变。请先在不修改标题、正文、作者、题型、圈子的情况下发布审核后的问题，再执行独立积分审核；原生发布本身不激活奖励。修改条款会关闭并退回未发放积分，已发放的积分不逆转。</p><p>状态：<strong>' . esc_html($row['state']) . '</strong>；积分：' . $row['amount'] . '；截止时间：' . esc_html(wp_date('Y-m-d H:i', $row['deadline'])) . '。</p>';
    if (in_array($row['state'], ['held','open'], true)) {
        // Links avoid nested forms in the native post editor. admin-post accepts
        // POST only; this intermediate page must present an explicit nonce form.
        $url = add_query_arg(['page'=>'wuai-sluyu-bounty-review','question'=>$post->ID], admin_url('admin.php'));
        echo '<p><a class="button" href="' . esc_url($url) . '">审核预留积分</a></p>';
    }
}
add_action('add_meta_boxes', function () { if (current_user_can('manage_options')) { add_meta_box('wuai-bounty', '非现金积分悬赏预留', 'wuai_sluyu_bounty_admin_box', 'sluyu_topic', 'normal'); } });

// Register after the CSF parent menu so WordPress derives the canonical
// admin.php?page=... hook under the actual theme menu parent.
add_action('admin_menu', function () {
    add_submenu_page('wuai-sluyu', '积分悬赏审核', '积分悬赏审核', 'manage_options', 'wuai-sluyu-bounty-review', function () {
        if (!current_user_can('manage_options')) { return; }
        $raw = isset($_GET['question']) && is_string($_GET['question']) ? wp_unslash($_GET['question']) : '';
        echo '<div class="wrap"><h1>非现金积分悬赏审核</h1><p>内容或账号删除后仍保留最少量的积分分配凭证，不提供强制解锁、更换获奖者或撤销奖励按钮。</p>';
        if (isset($_GET['settings_saved'])) { echo '<div class="notice notice-success"><p>新悬赏开放设置已保存。已有预留记录仍需按规则完成结算。</p></div>'; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wuai_bounty_settings">'; wp_nonce_field('wuai_bounty_settings');
        echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked(get_option('wuai_sluyu_bounty_enabled', '0'), '1', false) . '> 所有积分集成及测试通过后，允许发起非现金积分悬赏</label></p><button class="button">保存开放设置</button></form><p>记账集成状态：' . (wuai_sluyu_economy_contract_ready() ? '已就绪' : '尚未就绪，保持禁止创建') . '。</p>';
        $diagnostic = wuai_sluyu_economy_storage_ready() ? wuai_sluyu_economy_diagnostic() : null;
        if (is_array($diagnostic)) { echo '<div class="notice notice-warning"><p>积分操作持有持久记账锁：' . esc_html($diagnostic['purpose'] . ' / ' . $diagnostic['created_at']) . '。锁不会自动过期。如长期保留，请计划停机维护：停止所有节点的 PHP、网页、CLI 和定时任务，检查分配记录及流水唯一键后再手工恢复。仍可能恢复执行的进程存在时，严禁删除锁；此处不提供强制解锁。</p></div>'; }
        if (preg_match('/^[1-9][0-9]*$/D', $raw)) {
            $row = wuai_sluyu_bounty_row((int) $raw);
            if (!is_wp_error($row) && $row) {
                echo '<p>问题 #' . $row['question_id'] . '；提问者 #' . $row['requester_id'] . '；积分 ' . $row['amount'] . '；状态 ' . esc_html($row['state']) . '。</p><p><a href="' . esc_url(get_edit_post_link($row['question_id'])) . '">在原生编辑器审核公开问题</a></p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wuai_bounty_review"><input type="hidden" name="id" value="' . $row['question_id'] . '"><input type="hidden" name="version" value="' . $row['version'] . '">';
                wp_nonce_field('wuai_bounty_review');
                echo '<button class="button button-primary" name="decision" value="approve">开启已发布且审核通过的悬赏</button> <button class="button" name="decision" value="reject">拒绝并退回未发放积分</button></form>';
            } else { echo '<p>预留记录暂不可用，本次未修改。</p>'; }
        } elseif (wuai_sluyu_economy_storage_ready()) {
            global $wpdb; $rows = $wpdb->get_results('SELECT question_id,requester_id,amount,state,deadline FROM ' . wuai_sluyu_table('bounties') . " WHERE state IN ('held','open') ORDER BY id DESC LIMIT 100", ARRAY_A);
            echo '<h2>进行中的预留记录（最近 100 条）</h2><ul>';
            foreach ($rows as $row) { echo '<li><a href="' . esc_url(add_query_arg(['page'=>'wuai-sluyu-bounty-review','question'=>$row['question_id']], admin_url('admin.php'))) . '">问题 #' . (int) $row['question_id'] . '</a> / 提问者 #' . (int) $row['requester_id'] . ' / ' . (int) $row['amount'] . ' 积分 / ' . esc_html($row['state']) . '</li>'; }
            echo '</ul>';
        }
        echo '</div>';
    });
}, 40);
add_action('admin_post_wuai_bounty_review', function () {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !current_user_can('manage_options') || !wp_verify_nonce(wuai_sluyu_input('_wpnonce'), 'wuai_bounty_review')) { wp_die('审核请求无效。', '', ['response'=>403]); }
    $result = wuai_sluyu_bounty_review(wuai_sluyu_input('id'), wuai_sluyu_input('decision'), wuai_sluyu_input('version'));
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', ['response'=>409]); }
    wp_safe_redirect(add_query_arg(['page'=>'wuai-sluyu-bounty-review','question'=>absint(wuai_sluyu_input('id'))], admin_url('admin.php'))); exit;
});
add_action('admin_post_wuai_bounty_settings', function () {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !current_user_can('manage_options') || wuai_sluyu_banned(get_current_user_id()) || !wp_verify_nonce(wuai_sluyu_input('_wpnonce'), 'wuai_bounty_settings')) { wp_die('设置请求无效。', '', ['response'=>403]); }
    $enabled = wuai_sluyu_input('enabled') === '1';
    if ($enabled && !wuai_sluyu_economy_contract_ready()) { wp_die('请先完成并验证积分、流水和账号删除集成，再启用悬赏。', '', ['response'=>409]); }
    update_option('wuai_sluyu_bounty_enabled', $enabled ? '1' : '0', false);
    wp_safe_redirect(add_query_arg(['page'=>'wuai-sluyu-bounty-review','settings_saved'=>1], admin_url('admin.php'))); exit;
});

function wuai_sluyu_bounty_export($email, $page = 1) {
    $user = get_user_by('email', $email); if (!$user) { return ['data'=>[], 'done'=>true]; }
    if (!wuai_sluyu_economy_storage_ready()) { return new WP_Error('bounty_export', '完整悬赏记账暂不可核验，请修复存储后重新导出，不能将此结果当成没有分配记录。'); }
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT id,question_id,requester_id,winner_id,amount,state,reserved_at,settled_at,reason FROM ' . wuai_sluyu_table('bounties') . ' WHERE requester_id=%d OR winner_id=%d ORDER BY id LIMIT 100 OFFSET %d', $user->ID, $user->ID, (max(1, absint($page))-1)*100), ARRAY_A);
    if($wpdb->last_error || !is_array($rows)) { return new WP_Error('bounty_export','悬赏凭证读取失败，请修复后重新导出。'); }
    $data = [];
    foreach ($rows as $row) { $fields = []; foreach ($row as $key=>$value) { $fields[] = ['name'=>$key, 'value'=>(string) $value]; } $data[] = ['group_id'=>'wuai-bounties', 'group_label'=>'非现金积分悬赏分配', 'item_id'=>'wuai-bounty-' . $row['id'], 'data'=>$fields]; }
    return ['data'=>$data, 'done'=>count($rows)<100];
}
add_filter('wp_privacy_personal_data_exporters', function ($exporters) { $exporters['wuai-bounties'] = ['exporter_friendly_name'=>'非现金积分悬赏分配', 'callback'=>'wuai_sluyu_bounty_export']; return $exporters; });
function wuai_sluyu_bounty_erase($email, $page = 1) {
    $user = get_user_by('email', $email); $retained = false;
    if ($user && !wuai_sluyu_economy_storage_ready()) { return ['items_removed'=>false, 'items_retained'=>true, 'messages'=>['悬赏凭证暂不可核验，未执行删除；请修复完整记账存储后重试。'], 'done'=>true]; }
    if ($user) { global $wpdb; $retained = (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . wuai_sluyu_table('bounties') . ' WHERE requester_id=%d OR winner_id=%d LIMIT 1', $user->ID, $user->ID)); if($wpdb->last_error)$retained=true; }
    return ['items_removed'=>false, 'items_retained'=>$retained, 'messages'=>$retained ? ['最少量的数字积分分配凭证会保留，包括账号删除后；删除任一侧会改变其他账号余额。分配表不保存回答正文、姓名、邮箱或 IP。'] : [], 'done'=>true];
}
add_filter('wp_privacy_personal_data_erasers', function ($erasers) { $erasers['wuai-bounties'] = ['eraser_friendly_name'=>'非现金积分悬赏分配', 'callback'=>'wuai_sluyu_bounty_erase']; return $erasers; });
