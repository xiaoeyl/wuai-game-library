<?php
if (!defined('ABSPATH')) { exit; }

// Non-cash site points only. This mutex has deliberately NO lease or takeover.
// A crashed holder requires maintenance with all PHP workers stopped. Never
// delete/recreate its row while a worker can resume; do not add an unlock UI.
function wuai_sluyu_economy_schema() {
    global $wpdb;
    return 'CREATE TABLE ' . wuai_sluyu_table('economy_mutex') . " (
        name varchar(32) NOT NULL,
        token char(64) NOT NULL,
        actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
        purpose varchar(80) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (name)
    ) " . $wpdb->get_charset_collate() . ';';
}

function wuai_sluyu_economy_storage_ready($schema_only = false) {
    global $wpdb;
    if (!$schema_only && (is_multisite() || get_option('wuai_sluyu_storage_version') !== '5')) { return false; }
    static $verified = [];
    $cache_key = spl_object_hash($wpdb) . ':' . $wpdb->prefix;
    if (!empty($verified[$cache_key])) { return true; }
    $mutex_table = wuai_sluyu_table('economy_mutex'); $bounty_table = wuai_sluyu_table('bounties'); $event_table = wuai_sluyu_table('events');
    foreach (['events'=>['id','user_id','event_key','event_type','target_id','points','payload','created_at'], 'messages'=>['id','sender_id','recipient_id','kind','body','is_read','sender_deleted','recipient_deleted','created_at'], 'memberships'=>['id','circle_id','user_id','status','created_at','updated_at'], 'invites'=>['id','code_hash','code_hint','owner_id','claimed_by','status','expires_at','created_at','claimed_at','reserve_token','reserve_fingerprint','reserved_at'], 'economy_mutex'=>['name','token','actor_id','purpose','created_at'], 'bounties'=>['id','question_id','requester_id','request_key','request_hash','amount','audience','targets','deadline','prompt_hash','state','version','winner_id','accepted_comment','reserved_at','opened_at','settled_at','settled_by','reason']] as $name=>$required) {
        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . wuai_sluyu_table($name));
        if ($wpdb->last_error || !is_array($columns) || array_diff($required, $columns)) { return false; }
    }
    $indexes = $wpdb->get_results('SHOW INDEX FROM ' . wuai_sluyu_table('economy_mutex'), ARRAY_A);
    if ($wpdb->last_error || !is_array($indexes)) { return false; }
    $primary = array_values(array_filter($indexes, function ($row) { return isset($row['Key_name']) && $row['Key_name'] === 'PRIMARY'; }));
    if (count($primary) !== 1 || ($primary[0]['Column_name'] ?? '') !== 'name' || (int) ($primary[0]['Non_unique'] ?? 1) !== 0 || !empty($primary[0]['Sub_part']) || PHP_INT_SIZE < 8) { return false; }
    $indexes = $wpdb->get_results('SHOW INDEX FROM ' . wuai_sluyu_table('bounties'), ARRAY_A);
    if ($wpdb->last_error || !is_array($indexes)) { return false; }
    foreach (['PRIMARY'=>['id'], 'question'=>['question_id'], 'request_once'=>['requester_id','request_key']] as $name=>$columns) {
        $actual = [];
        foreach ($indexes as $row) { if (($row['Key_name'] ?? '') === $name && (int) ($row['Non_unique'] ?? 1) === 0 && empty($row['Sub_part'])) { $actual[(int) $row['Seq_in_index']] = $row['Column_name']; } }
        ksort($actual, SORT_NUMERIC); if (array_values($actual) !== $columns) { return false; }
    }
    $indexes = $wpdb->get_results('SHOW INDEX FROM ' . $event_table, ARRAY_A);
    if ($wpdb->last_error || !is_array($indexes)) { return false; }
    $unique = [];
    foreach ($indexes as $row) { if (($row['Key_name'] ?? '') === 'user_event' && (int) ($row['Non_unique'] ?? 1) === 0 && empty($row['Sub_part'])) { $unique[(int) $row['Seq_in_index']] = $row['Column_name']; } }
    ksort($unique, SORT_NUMERIC); if (array_values($unique) !== ['user_id','event_key']) { return false; }
    foreach (['memberships'=>['circle_user'=>['circle_id','user_id']], 'invites'=>['code_hash'=>['code_hash']]] as $name=>$required) {
        $indexes = $wpdb->get_results('SHOW INDEX FROM ' . wuai_sluyu_table($name), ARRAY_A);
        if ($wpdb->last_error || !is_array($indexes)) { return false; }
        foreach ($required as $index=>$columns) {
            $actual = [];
            foreach ($indexes as $row) { if (($row['Key_name'] ?? '') === $index && (int) ($row['Non_unique'] ?? 1) === 0 && empty($row['Sub_part'])) { $actual[(int) $row['Seq_in_index']] = $row['Column_name']; } }
            ksort($actual, SORT_NUMERIC); if (array_values($actual) !== $columns) { return false; }
        }
    }
    $sqlite = defined('DB_ENGINE') && DB_ENGINE === 'sqlite' && is_a($wpdb, 'WP_SQLite_DB');
    if (!$sqlite) {
        // Read replicas or custom wpdb routing cannot certify read-your-writes.
        if (get_class($wpdb) !== 'wpdb') { return false; }
        $tables = array_values(array_unique([$mutex_table, $bounty_table, $event_table, wuai_sluyu_table('memberships'), $wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta, $wpdb->comments, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta, $wpdb->options]));
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        $engines = $wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $placeholders . ')', $tables), ARRAY_A);
        if ($wpdb->last_error || !is_array($engines) || count($engines) !== count($tables)) { return false; }
        foreach ($engines as $engine) { if (strtolower((string) ($engine['ENGINE'] ?? '')) !== 'innodb') { return false; } }
    }
    $db_now = $wpdb->get_var('SELECT UNIX_TIMESTAMP()');
    if ($wpdb->last_error || !is_scalar($db_now) || !preg_match('/^[0-9]+$/D', (string) $db_now) || abs((int) $db_now-time()) > 5) { return false; }
    $verified[$cache_key] = true;
    return true;
}

function wuai_sluyu_economy_contract_ready() {
    // Set only after every negative event writer, user deletion, points display,
    // and merged ledger/privacy exporter use this protocol. Loading is not enough.
    // Per-blog allocation tables are not yet covered by a network-wide user
    // deletion fence. Do not imply that setting the single-site flag fixes this.
    if (is_multisite()) { return false; }
    return defined('WUAI_SLUYU_ECONOMY_CONTRACT') && WUAI_SLUYU_ECONOMY_CONTRACT === 1 && wuai_sluyu_economy_storage_ready();
}

function wuai_sluyu_economy_current_token() {
    return isset($GLOBALS['wuai_sluyu_economy_guard']['token']) ? $GLOBALS['wuai_sluyu_economy_guard']['token'] : '';
}

function wuai_sluyu_economy_fence($token) {
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token) || !hash_equals(wuai_sluyu_economy_current_token(), $token)) { return '0=1'; }
    global $wpdb;
    return $wpdb->prepare('EXISTS (SELECT 1 FROM ' . wuai_sluyu_table('economy_mutex') . " WHERE name='global' AND token=%s)", $token);
}

function wuai_sluyu_economy_guard_valid($token) {
    global $wpdb;
    $valid = $wpdb->get_var('SELECT 1 WHERE ' . wuai_sluyu_economy_fence($token));
    return !$wpdb->last_error && (string) $valid === '1';
}

function wuai_sluyu_economy_enter($purpose) {
    if (!wuai_sluyu_economy_storage_ready()) { return new WP_Error('economy_schema', '积分记账暂不可用，本次未执行积分操作。'); }
    $current = wuai_sluyu_economy_current_token();
    if ($current !== '') {
        return wuai_sluyu_economy_guard_valid($current) ? ['token'=>$current, 'owner'=>false] : new WP_Error('economy_fenced', '记账锁已变化，已停止操作，请联系管理员核验。');
    }
    try { $token = bin2hex(random_bytes(32)); }
    catch (Throwable $error) { return new WP_Error('economy_random', '无法生成安全操作标识，请稍后重试。'); }
    global $wpdb;
    $table = wuai_sluyu_table('economy_mutex');
    $previous = $wpdb->suppress_errors(true);
    $wpdb->query($wpdb->prepare("INSERT INTO $table (name,token,actor_id,purpose,created_at) VALUES ('global',%s,%d,%s,%s)", $token, get_current_user_id(), substr(sanitize_key($purpose), 0, 80), current_time('mysql')));
    $wpdb->suppress_errors($previous);
    // Readback handles an ambiguous INSERT result without taking somebody else's lock.
    $actual = $wpdb->get_var("SELECT token FROM $table WHERE name='global'");
    if ($wpdb->last_error || !is_string($actual) || !hash_equals($token, $actual)) { return new WP_Error('economy_busy', '其他积分操作正在处理或需要维护，本次未扣分，请稍后重试。'); }
    $guard = ['token'=>$token, 'owner'=>true]; $GLOBALS['wuai_sluyu_economy_guard'] = $guard;
    return $guard;
}

function wuai_sluyu_economy_leave($guard) {
    if (!is_array($guard) || empty($guard['owner']) || !isset($guard['token'])) { return true; }
    $token = $guard['token'];
    if (!is_string($token) || !hash_equals(wuai_sluyu_economy_current_token(), $token)) { return false; }
    global $wpdb;
    $removed = $wpdb->query($wpdb->prepare('DELETE FROM ' . wuai_sluyu_table('economy_mutex') . " WHERE name='global' AND token=%s", $token));
    unset($GLOBALS['wuai_sluyu_economy_guard']);
    return $removed === 1;
}

function wuai_sluyu_economy_balance_sql($uid) {
    global $wpdb;
    $events = wuai_sluyu_table('events'); $bounties = wuai_sluyu_table('bounties'); $uid = absint($uid);
    // One statement/snapshot. Missing users/posts/comments NEVER erase either
    // side of an award; a bounty row remains the authoritative allocation.
    return $wpdb->prepare("SELECT COALESCE(SUM(m.delta),0) FROM (
        SELECT points AS delta FROM $events WHERE user_id=%d
        UNION ALL SELECT -CAST(amount AS SIGNED) FROM $bounties WHERE requester_id=%d
        UNION ALL SELECT CAST(amount AS SIGNED) FROM $bounties WHERE requester_id=%d AND state='refunded'
        UNION ALL SELECT CAST(amount AS SIGNED) FROM $bounties WHERE winner_id=%d AND state='awarded'
    ) m", $uid, $uid, $uid, $uid);
}

function wuai_sluyu_economy_balance($uid) {
    if (!is_scalar($uid) || !absint($uid) || !wuai_sluyu_economy_storage_ready()) { return new WP_Error('economy_balance', '当前无法核验完整可用积分，暂不允许支出。'); }
    global $wpdb;
    $value = $wpdb->get_var(wuai_sluyu_economy_balance_sql($uid));
    if ($wpdb->last_error || !is_scalar($value) || !preg_match('/^-?[0-9]+$/D', (string) $value) || strlen(ltrim((string) $value, '-')) > 15) { return new WP_Error('economy_balance', '当前无法核验完整可用积分，暂不允许支出。'); }
    return (int) $value;
}

function wuai_sluyu_economy_allocation_insert($columns, $uid, $amount, $token) {
    // MySQL rejects reading an INSERT target inside a nested subquery (1093).
    // Use a top-level INSERT ... SELECT of a LEFT JOIN aggregation instead;
    // the supported SQLite adapter is covered by the dedicated runtime suite.
    global $wpdb; $bounties = wuai_sluyu_table('bounties'); $events = wuai_sluyu_table('events');
    $allowed = ['question_id','requester_id','request_key','request_hash','amount','audience','targets','deadline','prompt_hash','state','version','reserved_at'];
    if (!is_array($columns) || array_keys($columns) !== $allowed || !is_int($amount) || $amount < 1 || $amount > 1000000 || !is_int($uid) || $uid < 1 || $columns['requester_id'] !== $uid || $columns['amount'] !== $amount || $columns['state'] !== 'held' || $columns['version'] !== 1 || !is_int($columns['question_id']) || $columns['question_id'] < 1 || !wuai_sluyu_economy_guard_valid($token)) { return new WP_Error('economy_reserve', '预留参数或记账锁无效。'); }
    if (get_post_type($columns['question_id']) !== 'sluyu_topic' || get_post_status($columns['question_id']) !== 'draft' || (int) get_post_field('post_author', $columns['question_id']) !== $uid || get_post_meta($columns['question_id'], '_wuai_topic_kind', true) !== 'bounty') { return new WP_Error('economy_reserve', '只能为已核验的当前提问者草稿预留积分。'); }
    $values = [];
    foreach ($columns as $value) { $values[] = is_int($value) ? $wpdb->prepare('%d', $value) : $wpdb->prepare('%s', $value); }
    $uid = absint($uid);
    $delta = $wpdb->prepare("CASE WHEN a.requester_id=%d THEN -CAST(a.amount AS SIGNED) ELSE 0 END + CASE WHEN a.requester_id=%d AND a.state='refunded' THEN CAST(a.amount AS SIGNED) ELSE 0 END + CASE WHEN a.winner_id=%d AND a.state='awarded' THEN CAST(a.amount AS SIGNED) ELSE 0 END", $uid, $uid, $uid);
    $sql = 'INSERT INTO ' . $bounties . ' (' . implode(',', $allowed) . ') SELECT ' . implode(',', $values) . $wpdb->prepare(" FROM (SELECT %d AS uid) actor LEFT JOIN $bounties a ON (a.requester_id=actor.uid OR a.winner_id=actor.uid) WHERE ", $uid) . wuai_sluyu_economy_fence($token) . $wpdb->prepare(" AND EXISTS (SELECT 1 FROM $wpdb->users WHERE ID=%d) AND NOT EXISTS (SELECT 1 FROM $wpdb->usermeta WHERE user_id=%d AND meta_key='_wuai_banned_until' AND (meta_value='-1' OR CAST(meta_value AS SIGNED)>UNIX_TIMESTAMP())) AND EXISTS (SELECT 1 FROM $wpdb->posts WHERE ID=%d AND post_author=%d AND post_type='sluyu_topic' AND post_status='draft' AND post_password='') GROUP BY actor.uid HAVING COUNT(*)>0 AND ((SELECT COALESCE(SUM(points),0) FROM $events WHERE user_id=%d)+COALESCE(SUM($delta),0)) >= %d", $uid, $uid, $columns['question_id'], $uid, $uid, $amount);
    $previous = $wpdb->suppress_errors(true); $result = $wpdb->query($sql); $wpdb->suppress_errors($previous);
    return $result === false ? new WP_Error('economy_reserve', '积分预留写入失败，请先核对唯一提交记录再重试。') : (int) $result;
}

function wuai_sluyu_economy_event($uid, $key, $type, $points = 0, $target = 0, $payload = '') {
    if (!is_scalar($uid) || !absint($uid) || !is_string($key) || $key === '' || strlen($key) > 160 || !is_string($type) || $type === '' || strlen($type) > 32 || !is_int($points) || $points < -1000000 || $points > 1000000 || !is_scalar($target) || !is_string($payload) || strlen($payload) > 20000 || strpos($key, 'bounty:') === 0) {
        return new WP_Error('economy_event', '积分流水参数无效。');
    }
    if ($points < 0 && !wuai_sluyu_economy_contract_ready()) { return new WP_Error('economy_contract', '积分支出集成尚未完成，扣分功能保持关闭。'); }
    $guard = wuai_sluyu_economy_enter('event_' . $type); if (is_wp_error($guard)) { return $guard; }
    try {
        $uid = absint($uid); global $wpdb; clean_user_cache($uid); wp_cache_delete($uid, 'user_meta');
        if ($points < 0 && (!get_userdata($uid) || wuai_sluyu_banned($uid))) { return new WP_Error('economy_account', '账号当前不可支出积分。'); }
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . wuai_sluyu_table('events') . ' WHERE user_id=%d AND event_key=%s', $uid, $key), ARRAY_A);
        if ($wpdb->last_error) { return new WP_Error('economy_read', '已有积分凭证暂不可核验，本次未写入。'); }
        if ($existing) {
            if ($existing['event_type'] !== $type || (int) $existing['target_id'] !== absint($target) || (int) $existing['points'] !== $points || $existing['payload'] !== $payload) { return new WP_Error('economy_conflict', '积分凭证已用于不同操作，不会修改或重复扣分。'); }
            return ['id'=>(int) $existing['id'], 'created'=>false];
        }
        if ($points < 0) {
            $settled = wuai_sluyu_bounty_due_for_user($uid); if (is_wp_error($settled)) { return $settled; }
            $balance = wuai_sluyu_economy_balance($uid);
            if (is_wp_error($balance)) { return $balance; }
        }
        $where = wuai_sluyu_economy_fence($guard['token']) . $wpdb->prepare(" AND EXISTS (SELECT 1 FROM $wpdb->users WHERE ID=%d)", $uid);
        if ($points < 0) { $where .= $wpdb->prepare(" AND NOT EXISTS (SELECT 1 FROM $wpdb->usermeta WHERE user_id=%d AND meta_key='_wuai_banned_until' AND (meta_value='-1' OR CAST(meta_value AS SIGNED)>UNIX_TIMESTAMP()))", $uid); }
        $events = wuai_sluyu_table('events'); $bounties = wuai_sluyu_table('bounties');
        $sql = $wpdb->prepare("INSERT INTO $events (user_id,event_key,event_type,target_id,points,payload,created_at) SELECT %d,%s,%s,%d,%d,%s,%s", $uid, $key, $type, absint($target), $points, $payload, current_time('mysql'));
        if ($points < 0) {
            $delta = $wpdb->prepare("SELECT COALESCE(SUM(CASE WHEN requester_id=%d THEN -CAST(amount AS SIGNED) ELSE 0 END + CASE WHEN requester_id=%d AND state='refunded' THEN CAST(amount AS SIGNED) ELSE 0 END + CASE WHEN winner_id=%d AND state='awarded' THEN CAST(amount AS SIGNED) ELSE 0 END),0) FROM $bounties WHERE requester_id=%d OR winner_id=%d", $uid, $uid, $uid, $uid, $uid);
            $sql .= $wpdb->prepare(" FROM (SELECT %d AS uid) actor LEFT JOIN $events e ON e.user_id=actor.uid WHERE ", $uid) . $where . ' GROUP BY actor.uid HAVING COUNT(*)>0 AND (COALESCE(SUM(e.points),0)+(' . $delta . ')) >= ' . (-$points);
        } else { $sql .= ' WHERE ' . $where; }
        $previous = $wpdb->suppress_errors(true); $written = $wpdb->query($sql); $wpdb->suppress_errors($previous);
        // Exact persisted evidence is required; callers must NOT use WP_Error as truthy.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . wuai_sluyu_table('events') . ' WHERE user_id=%d AND event_key=%s', $uid, $key), ARRAY_A);
        if (!$wpdb->last_error && $row && $row['event_type'] === $type && (int) $row['target_id'] === absint($target) && (int) $row['points'] === $points && $row['payload'] === $payload) { return ['id'=>(int) $row['id'], 'created'=>$written === 1]; }
        return new WP_Error('economy_write', '积分流水尚未核验，不会授予权益；请刷新余额和记录后再重试。');
    } finally { wuai_sluyu_economy_leave($guard); }
}

function wuai_sluyu_economy_delete_user_events($uid, $token) {
    global $wpdb;
    if (!wuai_sluyu_economy_guard_valid($token)) { return new WP_Error('economy_fenced', '删除账号缺少有效积分记账锁，已停止。'); }
    // Core fires deleted_user even when its users DELETE failed. Never erase
    // the surviving account's balance merely because that action was reached.
    $exists = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->users WHERE ID=%d", absint($uid)));
    if ($wpdb->last_error || $exists !== null) { return new WP_Error('economy_delete_identity', '账号删除未获数据库确认，积分凭证保持不变，请停止并核验。'); }
    $result = $wpdb->query($wpdb->prepare('DELETE FROM ' . wuai_sluyu_table('events') . " WHERE user_id=%d AND NOT EXISTS (SELECT 1 FROM $wpdb->users WHERE ID=%d) AND ", absint($uid), absint($uid)) . wuai_sluyu_economy_fence($token));
    $remaining = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . wuai_sluyu_table('events') . ' WHERE user_id=%d', absint($uid)));
    return $result === false || $wpdb->last_error || (string) $remaining !== '0' || !wuai_sluyu_economy_guard_valid($token) ? new WP_Error('economy_delete', '账号积分清理未核验完成，请停止删除并检查。') : true;
}

function wuai_sluyu_economy_ledger_sql($uid) {
    global $wpdb;
    $events = wuai_sluyu_table('events'); $bounties = wuai_sluyu_table('bounties'); $uid = absint($uid);
    return $wpdb->prepare("SELECT CONCAT('event:',id) AS ledger_key,created_at,points,payload,event_type,target_id FROM $events WHERE user_id=%d AND points<>0
        UNION ALL SELECT CONCAT('bounty:',id,':reserve'),reserved_at,-CAST(amount AS SIGNED),'悬赏积分预留','bounty_reserve',question_id FROM $bounties WHERE requester_id=%d
        UNION ALL SELECT CONCAT('bounty:',id,':refund'),settled_at,CAST(amount AS SIGNED),'未发放悬赏积分退回','bounty_refund',question_id FROM $bounties WHERE requester_id=%d AND state='refunded'
        UNION ALL SELECT CONCAT('bounty:',id,':award'),settled_at,CAST(amount AS SIGNED),'采纳回答悬赏奖励','bounty_award',question_id FROM $bounties WHERE winner_id=%d AND state='awarded'", $uid, $uid, $uid, $uid);
}

function wuai_sluyu_economy_ledger($uid, $page = 1, $limit = 30) {
    if (!wuai_sluyu_economy_storage_ready()) { return new WP_Error('economy_schema', '完整积分流水暂不可用。'); }
    global $wpdb; $limit = max(1, min(100, absint($limit))); $offset = (max(1, min(100000, absint($page))) - 1) * $limit;
    $union = wuai_sluyu_economy_ledger_sql($uid);
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM ($union) ledger ORDER BY created_at DESC,ledger_key DESC LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A);
    if ($wpdb->last_error) { return new WP_Error('economy_ledger', '完整积分流水暂不可用。'); }
    $count = $wpdb->get_var("SELECT COUNT(*) FROM ($union) ledger");
    return $wpdb->last_error ? new WP_Error('economy_ledger', '完整积分流水暂不可用。') : ['items'=>$rows, 'total'=>(int) $count];
}

function wuai_sluyu_economy_diagnostic() {
    if (!current_user_can('manage_options')) { return new WP_Error('economy_admin', '仅管理员可查看。'); }
    global $wpdb;
    return $wpdb->get_row('SELECT name,actor_id,purpose,created_at FROM ' . wuai_sluyu_table('economy_mutex') . " WHERE name='global'", ARRAY_A);
}
