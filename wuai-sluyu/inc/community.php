<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_table($name) { global $wpdb; return $wpdb->prefix . 'wuai_' . $name; }

function wuai_sluyu_lock($key) {
    global $wpdb;
    $name = 'wuai_lock_' . sanitize_key($key);
    $existing = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", $name));
    if ($existing && (int)$existing < time() - 120) {
        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", $name, $existing));
    }
    $token = time() . ':' . wp_generate_uuid4();
    // add_option() uses an upsert, so it is not a safe compare-and-insert lock.
    $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name,option_value,autoload) VALUES (%s,%s,'no')", $name, $token));
    return $inserted === 1 ? [$name, $token] : false;
}
function wuai_sluyu_unlock($lock) {
    global $wpdb;
    if ($lock) { $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", $lock[0], $lock[1])); }
}

function wuai_sluyu_install_storage() {
    if (get_option('wuai_sluyu_storage_version') === '4') { return; }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $events = wuai_sluyu_table('events');
    $messages = wuai_sluyu_table('messages');
    $memberships = wuai_sluyu_table('memberships');
    $invites = wuai_sluyu_table('invites');
    dbDelta("CREATE TABLE $events (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        event_key varchar(160) NOT NULL,
        event_type varchar(32) NOT NULL,
        target_id bigint(20) unsigned NOT NULL DEFAULT 0,
        points int NOT NULL DEFAULT 0,
        payload text NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_event (user_id,event_key),
        KEY target_type (target_id,event_type),
        KEY user_type (user_id,event_type)
    ) $charset;");
    dbDelta("CREATE TABLE $messages (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        sender_id bigint(20) unsigned NOT NULL DEFAULT 0,
        recipient_id bigint(20) unsigned NOT NULL,
        kind varchar(20) NOT NULL DEFAULT 'message',
        body text NOT NULL,
        is_read tinyint(1) NOT NULL DEFAULT 0,
        sender_deleted tinyint(1) NOT NULL DEFAULT 0,
        recipient_deleted tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY inbox (recipient_id,kind,is_read),
        KEY sender (sender_id,recipient_id)
    ) $charset;");
    dbDelta("CREATE TABLE $memberships (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        circle_id bigint(20) unsigned NOT NULL,
        user_id bigint(20) unsigned NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY circle_user (circle_id,user_id),
        KEY user_status (user_id,status),
        KEY circle_status (circle_id,status)
    ) $charset;");
    dbDelta("CREATE TABLE $invites (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        code_hash char(64) NOT NULL,
        code_hint varchar(8) NOT NULL,
        owner_id bigint(20) unsigned NOT NULL,
        claimed_by bigint(20) unsigned NOT NULL DEFAULT 0,
        status varchar(20) NOT NULL DEFAULT 'active',
        expires_at bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL,
        claimed_at datetime DEFAULT NULL,
        reserve_token char(64) NOT NULL DEFAULT '',
        reserve_fingerprint char(64) NOT NULL DEFAULT '',
        reserved_at bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY code_hash (code_hash),
        KEY owner_created (owner_id,created_at),
        KEY status_expiry (status,expires_at)
    ) $charset;");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $events)) === $events && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $messages)) === $messages && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $memberships)) === $memberships) {
        $columns=$wpdb->get_col("SHOW COLUMNS FROM $messages");
        if (in_array('sender_deleted',$columns,true) && in_array('recipient_deleted',$columns,true) && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$invites))===$invites && !array_diff(['id','code_hash','code_hint','owner_id','claimed_by','status','expires_at','created_at','claimed_at','reserve_token','reserve_fingerprint','reserved_at'],$wpdb->get_col("SHOW COLUMNS FROM $invites"))) { update_option('wuai_sluyu_storage_version', '4', false); }
    }
}
add_action('after_setup_theme', 'wuai_sluyu_install_storage', 30);

function wuai_sluyu_event($user_id, $key, $type, $points = 0, $target = 0, $payload = '') {
    global $wpdb;
    return $wpdb->insert(wuai_sluyu_table('events'), ['user_id'=>$user_id,'event_key'=>$key,'event_type'=>$type,'target_id'=>$target,'points'=>$points,'payload'=>$payload,'created_at'=>current_time('mysql')], ['%d','%s','%s','%d','%d','%s','%s']);
}
function wuai_sluyu_has_event($user_id, $key) {
    global $wpdb; return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . wuai_sluyu_table('events') . ' WHERE user_id=%d AND event_key=%s', $user_id, $key));
}
function wuai_sluyu_points($user_id) {
    global $wpdb; return (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(points),0) FROM ' . wuai_sluyu_table('events') . ' WHERE user_id=%d', $user_id));
}
function wuai_sluyu_events($user_id, $type = '', $limit = 30, $offset = 0) {
    global $wpdb;
    $where = $wpdb->prepare('user_id=%d', $user_id);
    if ($type) { $where .= $wpdb->prepare(' AND event_type=%s', $type); }
    return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . wuai_sluyu_table('events') . " WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset));
}
function wuai_sluyu_notify($user_id, $body) {
    global $wpdb;
    return $wpdb->insert(wuai_sluyu_table('messages'), ['sender_id'=>0,'recipient_id'=>$user_id,'kind'=>'notice','body'=>sanitize_textarea_field($body),'created_at'=>current_time('mysql')]);
}
function wuai_sluyu_banned($user_id) {
    $until = (int) get_user_meta($user_id, '_wuai_banned_until', true);
    return $until === -1 || $until > time();
}
function wuai_sluyu_member($user_id = 0) {
    $user_id = $user_id ?: get_current_user_id();
    $until = wuai_sluyu_membership_until($user_id);
    return $until === -1 || $until > time();
}
function wuai_sluyu_public_post($id, $types = ['post','sluyu_topic','sluyu_news','sluyu_link','sluyu_listing']) {
    $post = get_post($id);
    return $post && in_array($post->post_type, $types, true) && $post->post_status === 'publish' && !post_password_required($post) ? $post : false;
}
function wuai_sluyu_input($key, $textarea = false) {
    $value = isset($_POST[$key]) && is_scalar($_POST[$key]) ? wp_unslash((string) $_POST[$key]) : '';
    return $textarea ? sanitize_textarea_field($value) : sanitize_text_field($value);
}
function wuai_sluyu_saved_meta_matches($post_id,$expected) {
    foreach($expected as $key=>$value) {
        $stored=get_post_meta($post_id,$key,true);
        if(is_array($value) ? $stored!==$value : (string)$stored!==(string)$value) { return false; }
    }
    return true;
}
function wuai_sluyu_checkin($user_id) {
    $today = wp_date('Y-m-d');
    $previous = get_user_meta($user_id, '_wuai_sluyu_checkin_date', true);
    if ($previous === $today || wuai_sluyu_has_event($user_id, 'checkin:' . $today)) {
        return ['message'=>'今天已经签到啦。','days'=>(int) get_user_meta($user_id,'_wuai_sluyu_checkin_days',true),'points'=>wuai_sluyu_points($user_id)];
    }
    $reward = max(0, (int) wuai_sluyu_option('checkin_points', 10));
    if (!wuai_sluyu_event($user_id, 'checkin:' . $today, 'checkin', $reward, 0, '每日签到')) { return new WP_Error('storage', '签到保存失败，请稍后重试。'); }
    $streak = $previous === current_datetime()->modify('-1 day')->format('Y-m-d') ? (int) get_user_meta($user_id, '_wuai_checkin_streak', true) + 1 : 1;
    $days = (int) get_user_meta($user_id, '_wuai_sluyu_checkin_days', true) + 1;
    update_user_meta($user_id, '_wuai_sluyu_checkin_date', $today);
    update_user_meta($user_id, '_wuai_sluyu_checkin_days', $days);
    update_user_meta($user_id, '_wuai_checkin_streak', $streak);
    return ['message'=>'签到成功，获得 ' . $reward . ' 积分。','days'=>$days,'streak'=>$streak,'points'=>wuai_sluyu_points($user_id)];
}
function wuai_sluyu_tasks($user_id) {
    $today = wp_date('Y-m-d');
    $user = get_userdata($user_id);
    return [
        'daily' => ['每日签到', 5, 'task:daily:' . $today, get_user_meta($user_id,'_wuai_sluyu_checkin_date',true) === $today, 'mission'],
        'profile' => ['完善昵称与个人简介', 20, 'task:profile', $user && $user->display_name && (bool) get_user_meta($user_id, 'description', true), 'account'],
        'comment' => ['第一条通过审核的评论', 15, 'task:comment', (bool) get_comments(['user_id'=>$user_id,'status'=>'approve','count'=>true]), 'circle'],
    ];
}

function wuai_sluyu_community_operation($operation, $user_id) {
    global $wpdb;
    $events = wuai_sluyu_table('events');
    $id = absint(wuai_sluyu_input('id'));
    $extension = apply_filters('wuai_sluyu_community_dispatch', null, $operation, $user_id);
    if ($extension !== null) { return $extension; }
    if ($operation === 'checkin') { return wuai_sluyu_checkin($user_id); }
    if ($operation === 'makeup') {
        $previous = get_user_meta($user_id, '_wuai_sluyu_checkin_date', true);
        $start = wuai_sluyu_parse_date($previous);
        $gap = $start ? (int) $start->diff(current_datetime()->setTime(0, 0))->format('%r%a') - 1 : 0;
        $cost = max(1, (int) wuai_sluyu_option('makeup_points', 30)) * $gap;
        if ($gap < 1 || $gap > 7) { return new WP_Error('gap','仅支持补签最近 1 至 7 天的连续缺签。'); }
        if (wuai_sluyu_points($user_id) < $cost) { return new WP_Error('points','积分不足，本次补签需要 ' . $cost . ' 积分。'); }
        if (!wuai_sluyu_event($user_id, 'makeup:' . $previous, 'makeup', -$cost, 0, '补签 ' . $gap . ' 天')) { return new WP_Error('duplicate','该次补签已经处理。'); }
        update_user_meta($user_id, '_wuai_sluyu_checkin_date', current_datetime()->modify('-1 day')->format('Y-m-d'));
        update_user_meta($user_id, '_wuai_sluyu_checkin_days', (int) get_user_meta($user_id, '_wuai_sluyu_checkin_days', true) + $gap);
        update_user_meta($user_id, '_wuai_checkin_streak', (int) get_user_meta($user_id, '_wuai_checkin_streak', true) + $gap);
        return ['message'=>'补签成功，扣除 ' . $cost . ' 积分。','reload'=>true];
    }
    if ($operation === 'task') {
        $tasks = wuai_sluyu_tasks($user_id); $key = wuai_sluyu_input('task');
        if (!isset($tasks[$key]) || !$tasks[$key][3]) { return new WP_Error('task','请先完成对应任务。'); }
        $task = $tasks[$key];
        if (wuai_sluyu_has_event($user_id, $task[2])) { return new WP_Error('claimed','奖励已经领取。'); }
        if (!wuai_sluyu_event($user_id, $task[2], 'task', $task[1], 0, $task[0])) { return new WP_Error('storage','领取失败，请重试。'); }
        return ['message'=>'领取成功，获得 ' . $task[1] . ' 积分。','reload'=>true];
    }
    if ($operation === 'profile') {
        $name = wuai_sluyu_input('display_name'); $description = wuai_sluyu_input('description', true);
        if (!$name || mb_strlen($name) > 40 || mb_strlen($description) > 500) { return new WP_Error('profile','昵称为 1–40 字，简介不超过 500 字。'); }
        $result = wp_update_user(['ID'=>$user_id,'display_name'=>$name,'description'=>$description,'user_url'=>esc_url_raw(wuai_sluyu_input('user_url'), ['http','https'])]);
        return is_wp_error($result) ? $result : ['message'=>'资料已保存。'];
    }
    if (in_array($operation, ['bookmark','follow','news_vote'], true)) {
        if ($operation === 'follow') {
            if ($id === $user_id || !get_userdata($id) || wuai_sluyu_banned($id)) { return new WP_Error('user','无法关注该用户。'); }
        } elseif (!wuai_sluyu_public_post($id)) { return new WP_Error('post','内容不存在或尚未公开。'); }
        $key = $operation . ':' . $id; $exists = wuai_sluyu_has_event($user_id, $key);
        if ($operation === 'news_vote' && get_post_type($id) !== 'sluyu_news') { return new WP_Error('post','无效的快讯。'); }
        if ($exists) { $wpdb->delete($events, ['user_id'=>$user_id,'event_key'=>$key]); }
        elseif (!wuai_sluyu_event($user_id, $key, $operation, 0, $id, $operation === 'news_vote' ? (wuai_sluyu_input('direction') === 'down' ? 'down' : 'up') : '')) { return new WP_Error('storage','操作未保存，请重试。'); }
        return ['message'=>$exists ? '已取消。' : '操作成功。','selected'=>!$exists];
    }
    if ($operation === 'submit' || $operation === 'verify' || $operation === 'report') {
        $kind = $operation === 'submit' ? wuai_sluyu_input('kind') : $operation;
        $types = ['post'=>'post','topic'=>'sluyu_topic','question'=>'sluyu_topic','poll'=>'sluyu_topic','news'=>'sluyu_news','link'=>'sluyu_link','verify'=>'sluyu_verify','ticket'=>'sluyu_ticket','report'=>'sluyu_ticket'];
        if (!isset($types[$kind])) { return new WP_Error('type','无效的发布类型。'); }
        $group = 0;
        if ($types[$kind] === 'sluyu_topic' && isset($_POST['group'])) {
            $raw_group = is_scalar($_POST['group']) ? wp_unslash((string) $_POST['group']) : '';
            if (!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $raw_group)) { return new WP_Error('circle_input','圈子参数无效，请重新选择发布位置。'); }
            $group = (int) $raw_group;
            if ($group && !wuai_sluyu_circle_can_post($group,$user_id)) { return new WP_Error('circle_post','所选圈子不可发布，请先加入并满足该圈子的发帖条件。'); }
        }
        if ($kind === 'verify') {
            $existing = get_posts(['post_type'=>'sluyu_verify','author'=>$user_id,'post_status'=>['pending','publish'],'numberposts'=>1]);
            if ($existing) { return new WP_Error('exists','你已提交认证申请，请等待审核或查看结果。'); }
        }
        $title = wuai_sluyu_input('title'); $body = wuai_sluyu_input('body', true);
        if (!$title || mb_strlen($title) > ($kind === 'verify' ? 30 : 120) || mb_strlen($body) < 5 || mb_strlen($body) > 20000) { return new WP_Error('content','请填写有效标题和 5–20000 字的内容。认证称号最多 30 字。'); }
        $url = esc_url_raw(wuai_sluyu_input('url'), ['http','https']);
        if ($kind === 'link' && (!wp_http_validate_url($url) || !$url)) { return new WP_Error('url','请输入有效的公网 HTTP/HTTPS 网址。'); }
        $meta = ['_wuai_source_url'=>$url];
        if (in_array($kind, ['topic','question','poll'], true)) { $meta['_wuai_topic_kind'] = $kind; }
        if ($kind === 'poll') {
            $choices = array_values(array_unique(array_filter(array_map('trim', explode("\n", wuai_sluyu_input('choices', true))), 'strlen')));
            if (count($choices) < 2 || count($choices) > 8 || max(array_map('mb_strlen', $choices)) > 100) { return new WP_Error('choices','投票需要 2–8 个不同选项，每项不超过 100 字。'); }
            $meta['_wuai_poll_choices'] = $choices;
        }
        if ($kind === 'report') { $meta['_wuai_report_target'] = $id; }
        $submission = ['post_type'=>$types[$kind],'post_status'=>'pending','post_author'=>$user_id,'post_title'=>$title,'post_content'=>$body,'comment_status'=>in_array($kind,['topic','question','poll','post','news'],true)?'open':'closed','meta_input'=>$meta];
        $submission = apply_filters('wuai_sluyu_submission', $submission, $kind, $user_id);
        if (is_wp_error($submission)) { return $submission; }
        // Keep incomplete metadata or failed taxonomy assignments out of review queues.
        $submission['post_status'] = 'draft';
        $post_id = wp_insert_post(wp_slash($submission), true);
        if (is_wp_error($post_id)) { return $post_id; }
        if (!wuai_sluyu_saved_meta_matches($post_id,$submission['meta_input'])) {
            wp_update_post(['ID'=>$post_id,'post_status'=>'draft']);
            return new WP_Error('submission_partial','稿件 #'.$post_id.' 的附加信息未完整保存，暂留草稿。请保留表单内容并联系管理员核对，不会公开发布。');
        }
        if ($group) {
            $assigned = wp_set_object_terms($post_id, [$group], 'sluyu_circle');
            $saved_groups = wp_get_object_terms($post_id, 'sluyu_circle', ['fields'=>'ids','wuai_include_pending'=>true]);
            if (is_wp_error($assigned) || is_wp_error($saved_groups) || array_map('intval',$saved_groups) !== [$group] || !wuai_sluyu_circle_can_post($group,$user_id)) {
                return new WP_Error('submission_group','稿件 #'.$post_id.' 未能确认保存到所选圈子，暂留草稿。请保留表单内容并联系管理员，不会改成未分组投稿。');
            }
        }
        $queued = wp_update_post(['ID'=>$post_id,'post_status'=>'pending'], true);
        if (is_wp_error($queued) || get_post_status($post_id) !== 'pending') {
            return new WP_Error('submission_queue','稿件 #'.$post_id.' 未进入审核队列，请保留表单内容并联系管理员核对。');
        }
        do_action('wuai_sluyu_after_submission', $post_id, $kind, $user_id);
        wuai_sluyu_notify($user_id, '你的「' . $title . '」已提交，等待管理员审核。');
        return ['message'=>'已提交审核，通过后才会公开。','reset'=>true];
    }
    if ($operation === 'accept') {
        $comment = get_comment($id); $post = $comment ? wuai_sluyu_public_post($comment->comment_post_ID, ['sluyu_topic']) : false;
        if (!$post || ((int)$post->post_author !== $user_id && !current_user_can('moderate_comments')) || $comment->comment_approved !== '1' || get_post_meta($post->ID,'_wuai_topic_kind',true) !== 'question') { return new WP_Error('permission','只能采纳自己提问下已审核的回答。'); }
        update_post_meta($post->ID, '_wuai_accepted_comment', $id);
        return ['message'=>'已采纳该回答。','reload'=>true];
    }
    return new WP_Error('operation','不支持的操作。');
}

function wuai_sluyu_community_ajax() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_send_json_error(['message'=>'请使用表单提交此操作。'], 405); }
    if (!wp_verify_nonce(wuai_sluyu_input('nonce'),'wuai_sluyu')) { wp_send_json_error(['message'=>'安全校验已过期，请刷新页面重试。'],403); }
    if (!is_user_logged_in()) { wp_send_json_error(['message'=>'请先登录后再操作。','login'=>wp_login_url()], 401); }
    $user_id = get_current_user_id();
    if (wuai_sluyu_banned($user_id)) { wp_send_json_error(['message'=>'账户处于禁言期，暂时无法操作。'], 403); }
    $operation = wuai_sluyu_input('operation');
    $lock = wuai_sluyu_lock('user_' . $user_id);
    if (!$lock) { wp_send_json_error(['message'=>'上一项操作正在处理，请稍后重试。'], 429); }
    try {
        $limited = in_array($operation, ['submit','verify','report','message','circle_create','circle_join','circle_member','edit_submission','withdraw_submission'], true);
        $rate_key = 'wuai_rate_' . $user_id . '_' . $operation;
        if ($limited && get_transient($rate_key)) { $result = new WP_Error('rate','提交过于频繁，请稍后再试。'); }
        else {
            $result = wuai_sluyu_community_operation($operation, $user_id);
            if ($limited && !is_wp_error($result)) { set_transient($rate_key, 1, 20); }
        }
    } catch (Throwable $error) {
        error_log('Wuai community operation failed: ' . sanitize_key($operation) . ' / ' . get_class($error));
        $result = new WP_Error('operation_failed', '操作暂未完成，请刷新页面核对状态后重试。');
    } finally { wuai_sluyu_unlock($lock); }
    if (is_wp_error($result)) { wp_send_json_error(['message'=>$result->get_error_message()], 400); }
    wp_send_json_success($result);
}
add_action('wp_ajax_wuai_sluyu_community', 'wuai_sluyu_community_ajax');
add_action('wp_ajax_nopriv_wuai_sluyu_community', 'wuai_sluyu_community_ajax');

add_filter('preprocess_comment', function ($comment) {
    $permission=wuai_sluyu_comment_permission($comment);
    if (is_wp_error($permission)) { wp_die(esc_html($permission->get_error_message()), '', ['response'=>403]); }
    return $comment;
});
add_action('transition_post_status', function ($new, $old, $post) {
    if ($new === $old || !in_array($post->post_type, ['sluyu_topic','sluyu_news','sluyu_link','sluyu_verify','sluyu_ticket'],true)) { return; }
    if ($new === 'publish') {
        if ($post->post_type === 'sluyu_verify') { update_user_meta($post->post_author, '_wuai_verified_title', sanitize_text_field($post->post_title)); }
        wuai_sluyu_notify($post->post_author, '「' . $post->post_title . '」已通过审核。');
    } elseif ($old === 'publish' && $post->post_type === 'sluyu_verify') {
        $remaining=get_posts(['post_type'=>'sluyu_verify','post_status'=>'publish','author'=>$post->post_author,'post__not_in'=>[$post->ID],'numberposts'=>1,'orderby'=>'date','order'=>'DESC']);
        if ($remaining) { update_user_meta($post->post_author,'_wuai_verified_title',sanitize_text_field($remaining[0]->post_title)); }
        else { delete_user_meta($post->post_author, '_wuai_verified_title'); }
    }
}, 10, 3);

add_action('deleted_user', function ($user_id) {
    global $wpdb;
    $wpdb->delete(wuai_sluyu_table('events'), ['user_id'=>$user_id]);
    $wpdb->delete(wuai_sluyu_table('events'), ['event_type'=>'follow','target_id'=>$user_id]);
    $wpdb->delete(wuai_sluyu_table('memberships'), ['user_id'=>$user_id]);
    $wpdb->query($wpdb->prepare('DELETE FROM ' . wuai_sluyu_table('messages') . ' WHERE sender_id=%d OR recipient_id=%d', $user_id, $user_id));
});
add_action('delete_user',function($uid){
    $lock=wuai_sluyu_lock('user_'.$uid);
    if(!$lock)wp_die('此账号仍有操作正在处理，请稍后重试删除。','',['response'=>409]);
    $GLOBALS['wuai_sluyu_user_deletion_locks'][$uid]=$lock;
},1);
add_action('deleted_user',function($uid){
    if(isset($GLOBALS['wuai_sluyu_user_deletion_locks'][$uid])) { wuai_sluyu_unlock($GLOBALS['wuai_sluyu_user_deletion_locks'][$uid]); unset($GLOBALS['wuai_sluyu_user_deletion_locks'][$uid]); }
},100);
add_action('shutdown',function(){
    foreach($GLOBALS['wuai_sluyu_user_deletion_locks']??[] as $lock)wuai_sluyu_unlock($lock);
});
