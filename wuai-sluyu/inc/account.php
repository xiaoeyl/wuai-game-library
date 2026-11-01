<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_account_types() {
    return apply_filters('wuai_sluyu_account_types', [
        'post'=>'文章', 'sluyu_topic'=>'话题', 'sluyu_news'=>'快讯',
        'sluyu_link'=>'网址', 'sluyu_verify'=>'认证申请', 'sluyu_ticket'=>'工单 / 举报',
    ]);
}

function wuai_sluyu_account_tabs() {
    $tabs=['overview'=>'资料概览','bookmarks'=>'我的收藏','submissions'=>'投稿与申请','following'=>'我的关注','followers'=>'我的粉丝','comments'=>'我的评论'];
    if(function_exists('wuai_sluyu_view_account_security'))$tabs['security']='账户安全';
    return $tabs;
}

function wuai_sluyu_account_page_data($tab, $uid, $page = 1, $per_page = 12) {
    $page = max(1, min(100000, (int) $page));
    $per_page = max(1, min(50, (int) $per_page));
    $data = ['items'=>[], 'total'=>0, 'pages'=>0, 'page'=>$page];
    if (!$uid || (int) $uid !== get_current_user_id()) { return $data; }
    global $wpdb;
    $events = wuai_sluyu_table('events');
    $offset = ($page - 1) * $per_page;
    if ($tab === 'bookmarks') {
        $where = $wpdb->prepare("user_id=%d AND event_type='bookmark'", $uid);
        $from = "$events WHERE $where";
        $select = '*'; $order = 'id DESC';
    } elseif ($tab === 'following' || $tab === 'followers') {
        $field = $tab === 'following' ? 'e.user_id' : 'e.target_id';
        $join = $tab === 'following' ? 'e.target_id' : 'e.user_id';
        $from = "$events e INNER JOIN $wpdb->users u ON u.ID=$join WHERE " . $wpdb->prepare("$field=%d AND e.event_type='follow'", $uid);
        $select = 'u.ID AS user_id, e.created_at'; $order = 'e.id DESC';
    } elseif ($tab === 'submissions') {
        $types = array_keys(wuai_sluyu_account_types());
        if (!$types) { return $data; }
        $type_sql = implode(',', array_fill(0, count($types), '%s'));
        $from = "$wpdb->posts WHERE " . $wpdb->prepare("post_author=%d AND post_type IN ($type_sql) AND post_status IN ('publish','pending','draft','private','future')", array_merge([$uid], $types));
        $select = 'ID'; $order = 'post_modified DESC, ID DESC';
    } elseif ($tab === 'comments') {
        $from = "$wpdb->comments c INNER JOIN $wpdb->posts p ON p.ID=c.comment_post_ID WHERE " . $wpdb->prepare("c.user_id=%d AND c.comment_approved IN ('0','1') AND c.comment_type IN ('','comment') AND p.post_type IN ('post','sluyu_topic','sluyu_news','sluyu_link')", $uid);
        $select = 'c.*'; $order = 'c.comment_ID DESC';
    } else { return $data; }
    $data['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $from");
    $data['pages'] = (int) ceil($data['total'] / $per_page);
    $data['items'] = $wpdb->get_results($wpdb->prepare("SELECT $select FROM $from ORDER BY $order LIMIT %d OFFSET %d", $per_page, $offset));
    if ($tab === 'submissions') {
        $data['items'] = array_values(array_filter(array_map(function ($row) { return get_post($row->ID); }, $data['items'])));
    }
    return $data;
}

function wuai_sluyu_account_submission_version($post) {
    $post = get_post($post);
    if (!$post) { return ''; }
    $state = [$post->ID, $post->post_modified_gmt, $post->post_status, $post->post_title, $post->post_content, $post->post_excerpt];
    foreach (['_wuai_source_url','_wuai_topic_kind','_wuai_poll_choices','_wuai_poll_mode','_wuai_poll_max','_wuai_poll_deadline','_wuai_poll_closed','_wuai_accepted_comment','_wuai_ticket_reply'] as $key) {
        $state[] = get_post_meta($post->ID, $key, true);
    }
    return hash('sha256', wp_json_encode($state));
}

function wuai_sluyu_account_submission_permission($post, $uid, $operation) {
    if (!$post instanceof WP_Post || !$uid || (int) $uid !== get_current_user_id() || (int) $post->post_author !== (int) $uid || !isset(wuai_sluyu_account_types()[$post->post_type])) {
        return new WP_Error('submission_missing', '稿件不存在或不属于当前账号。');
    }
    if (!in_array($post->post_status, ['publish','pending','draft'], true)) {
        return new WP_Error('submission_state', '此稿件当前由管理员管理，请联系管理员处理。');
    }
    if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($post->ID)) {
        return new WP_Error('submission_review_held', '此话题的审核尚未完成，请联系站点编辑核验后再修改、撤回或删除。');
    }
    if (function_exists('wuai_sluyu_bounty_managed') && wuai_sluyu_bounty_managed($post->ID)) {
        return new WP_Error('bounty_managed', '悬赏条款与分配凭证不可直接修改、撤回或删除；请到问题页使用专用退回操作，待审稿件请联系站点管理员。');
    }
    if (in_array($post->post_type, ['sluyu_verify','sluyu_ticket'], true) && ($post->post_status === 'publish' || get_post_meta($post->ID, '_wuai_ticket_reply', true))) {
        return new WP_Error('submission_processed', '已处理的申请和工单为只读记录，请另行提交补充说明。');
    }
    if ($operation==='edit_submission' && $post->post_type==='sluyu_verify') {
        $other=get_posts(['post_type'=>'sluyu_verify','post_status'=>['pending','publish'],'author'=>$uid,'post__not_in'=>[$post->ID],'numberposts'=>1]);
        if($other) { return new WP_Error('submission_verify_exists','你已有另一份待审或已通过的认证申请，不能重复提交。'); }
    }
    if ($operation === 'delete_submission' && $post->post_status === 'publish') {
        return new WP_Error('submission_public', '公开稿件不能直接删除，请先撤回为草稿。');
    }
    if ($post->post_type === 'sluyu_topic' && in_array($operation, ['edit_submission','delete_submission'], true)) {
        global $wpdb;
        $votes = (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . wuai_sluyu_table('events') . " WHERE target_id=%d AND event_type='vote' LIMIT 1", $post->ID));
        if ($votes) { return new WP_Error('submission_votes', '已有参与记录的投票不能修改或删除；需要纠错请联系管理员。'); }
    }
    if ($operation === 'edit_submission' && $post->post_type === 'sluyu_topic' && function_exists('wuai_sluyu_circle_can_post')) {
        $groups = wp_get_object_terms($post->ID, 'sluyu_circle', ['fields'=>'ids','wuai_include_pending'=>true]);
        if (is_wp_error($groups)) { return $groups; }
        foreach ($groups as $group) {
            if (!wuai_sluyu_circle_can_post($group, $uid)) { return new WP_Error('submission_circle', '你当前不满足原圈子的发帖条件，暂时不能重新提交。'); }
        }
    }
    $allowed = apply_filters('wuai_sluyu_account_submission_permission', true, $post, $operation, $uid);
    return is_wp_error($allowed) ? $allowed : ($allowed ? true : new WP_Error('submission_locked', '此稿件暂不能由作者修改，请联系管理员。'));
}

add_filter('wuai_sluyu_community_dispatch', function ($result, $operation, $uid) {
    $operations = ['edit_submission','withdraw_submission','delete_submission','remove_bookmark','remove_follow'];
    if ($result !== null || !in_array($operation, $operations, true)) { return $result; }
    if (!$uid || (int) $uid !== get_current_user_id() || wuai_sluyu_banned($uid)) { return new WP_Error('account_auth', '请使用当前有效账号操作。'); }
    $id = absint(wuai_sluyu_input('id'));
    if (!$id) { return new WP_Error('account_id', '无效的记录。'); }
    global $wpdb;
    if ($operation === 'remove_bookmark' || $operation === 'remove_follow') {
        $type = $operation === 'remove_bookmark' ? 'bookmark' : 'follow';
        $removed = $wpdb->delete(wuai_sluyu_table('events'), ['user_id'=>$uid,'event_key'=>$type . ':' . $id,'event_type'=>$type,'points'=>0]);
        return $removed === false ? new WP_Error('account_storage', '移除失败，请稍后重试。') : ['message'=>'已从你的列表移除。','reload'=>true];
    }
    $post = get_post($id);
    $permission = wuai_sluyu_account_submission_permission($post, $uid, $operation);
    if (is_wp_error($permission)) { return $permission; }
    // Votes and author edits share a lock so a first vote cannot race a question change.
    $lock = wuai_sluyu_lock(($post->post_type === 'sluyu_topic' ? 'poll_' : 'submission_') . $id);
    if (!$lock) { return new WP_Error('submission_busy', '稿件正在更新，请稍后重试。'); }
    try {
        clean_post_cache($id);
        $post = get_post($id);
        $permission = wuai_sluyu_account_submission_permission($post, $uid, $operation);
        if (is_wp_error($permission)) { return $permission; }
        if ($operation === 'delete_submission') {
            $deleted = wp_trash_post($id);
            return $deleted && !is_wp_error($deleted) && get_post_status($id)==='trash' ? ['message'=>'非公开稿件已删除。','reload'=>true] : new WP_Error('submission_delete', '删除失败，请稍后重试。');
        }
        if ($operation === 'withdraw_submission') {
            if ($post->post_status === 'draft') { return ['message'=>'稿件已经是草稿。','reload'=>true]; }
            $saved = wp_update_post(['ID'=>$id,'post_status'=>'draft'], true);
            return is_wp_error($saved) ? $saved : ($saved && get_post_status($id)==='draft' ? ['message'=>'稿件已撤回为草稿，不再公开展示。','reload'=>true] : new WP_Error('submission_storage', '撤回失败，请刷新核对状态。'));
        }
        $version = wuai_sluyu_input('version');
        if (!preg_match('/^[a-f0-9]{64}$/', $version) || !hash_equals(wuai_sluyu_account_submission_version($post), $version)) {
            return new WP_Error('submission_changed', '稿件已发生变化，请刷新页面后再编辑，避免覆盖新内容。');
        }
        $title = wuai_sluyu_input('title');
        $body = isset($_POST['body']) && is_string($_POST['body']) ? wp_kses_post(wp_unslash($_POST['body'])) : '';
        if (!$title || mb_strlen($title) > ($post->post_type === 'sluyu_verify' ? 30 : 120) || mb_strlen(trim(wp_strip_all_tags($body))) < 5 || mb_strlen($body) > 20000) {
            return new WP_Error('submission_content', '请填写有效标题和 5–20000 字正文；认证称号最多 30 字，其余标题最多 120 字。');
        }
        $update = ['ID'=>$id,'post_title'=>$title,'post_content'=>$body,'post_status'=>'draft'];
        if (in_array($post->post_type, ['sluyu_news','sluyu_link'], true)) {
            $url = esc_url_raw(wuai_sluyu_input('url'), ['http','https']);
            if ($post->post_type === 'sluyu_link' && (!$url || !wp_http_validate_url($url))) { return new WP_Error('submission_url', '请输入有效的公网 HTTP/HTTPS 网址。'); }
            $update['meta_input'] = ['_wuai_source_url'=>$url];
        }
        $saved = wp_update_post(wp_slash($update), true);
        if (is_wp_error($saved)) { return $saved; }
        if (!$saved) { return new WP_Error('submission_storage', '保存失败，请刷新核对状态。'); }
        if (!wuai_sluyu_saved_meta_matches($id,$update['meta_input']??[])) {
            return new WP_Error('submission_partial','稿件附加信息未完整保存，暂留草稿，请保留修改内容并联系管理员核对。');
        }
        $queued=wp_update_post(['ID'=>$id,'post_status'=>'pending'],true);
        if (is_wp_error($queued) || get_post_status($id)!=='pending') { return new WP_Error('submission_queue','稿件未进入审核队列，请刷新核对并联系管理员。'); }
        wuai_sluyu_notify($uid, '你修改的「' . $title . '」已重新提交审核，审核前不公开展示。');
        do_action('wuai_sluyu_after_submission_edit', $id, $uid);
        return ['message'=>'已保存并重新提交审核；类型、分组和投票设置保持不变。','reload'=>true];
    } finally { wuai_sluyu_unlock($lock); }
}, 20, 3);

function wuai_sluyu_account_status($post) {
    $labels = ['publish'=>'已发布 / 已处理','pending'=>'审核中','draft'=>'草稿 / 已撤回','private'=>'私密记录','future'=>'计划发布'];
    return $labels[$post->post_status] ?? '由管理员处理';
}

function wuai_sluyu_account_edit_form($id, $uid) {
    $post = get_post($id);
    $permission = wuai_sluyu_account_submission_permission($post, $uid, 'edit_submission');
    if (is_wp_error($permission)) { wuai_sluyu_empty($permission->get_error_message()); return; }
    echo '<section class="sluyu-panel"><h2>编辑我的稿件</h2><p class="sluyu-muted">' . esc_html(wuai_sluyu_account_status($post)) . ' · 保存后将重新审核，审核期间不公开。类型、分组、投票选项和资源设置不可在这里更改。</p>';
    wuai_sluyu_form_start('edit_submission');
    echo '<input type="hidden" name="id" value="' . absint($id) . '"><input type="hidden" name="version" value="' . esc_attr(wuai_sluyu_account_submission_version($post)) . '">';
    echo '<label>标题<input name="title" value="' . esc_attr($post->post_title) . '" maxlength="' . ($post->post_type === 'sluyu_verify' ? 30 : 120) . '" required></label><label>正文（支持安全的基础 HTML 排版）<textarea name="body" rows="12" minlength="5" maxlength="20000" required>' . esc_textarea($post->post_content) . '</textarea></label>';
    if (in_array($post->post_type, ['sluyu_news','sluyu_link'], true)) { echo '<label>来源 / 站点网址<input name="url" type="url" value="' . esc_attr(get_post_meta($id, '_wuai_source_url', true)) . '"' . ($post->post_type === 'sluyu_link' ? ' required' : '') . '></label>'; }
    wuai_sluyu_form_end('保存并重新提交审核');
    if(function_exists('wuai_sluyu_protected_view')) { echo wuai_sluyu_protected_view($id,true); }
    echo '<p><a href="' . esc_url(wuai_sluyu_portal_url('account', ['tab'=>'submissions'])) . '">返回投稿列表</a></p></section>';
}

function wuai_sluyu_account_render_list($tab, $uid, $page = 1, $per_page = 12, $preview = false) {
    $data = wuai_sluyu_account_page_data($tab, $uid, $page, $per_page);
    $tabs = wuai_sluyu_account_tabs();
    if (!isset($tabs[$tab])) { return; }
    echo '<section class="sluyu-panel" data-account-list="' . esc_attr($tab) . '"><div class="sluyu-panel-heading"><h2>' . esc_html($tabs[$tab]) . '</h2><span class="sluyu-muted">' . absint($data['total']) . ' 条</span></div><div class="sluyu-compact-list">';
    foreach ($data['items'] as $entry) {
        if ($tab === 'bookmarks') {
            $post = wuai_sluyu_public_post($entry->target_id);
            echo '<div>';
            if ($post) { echo '<a href="' . esc_url(get_permalink($post)) . '">' . esc_html($post->post_title) . '</a>'; }
            else { echo '<span class="sluyu-muted">该收藏已不可用或当前无权查看</span>'; }
            wuai_sluyu_action_button('remove_bookmark', '移除', ['id'=>$entry->target_id]); echo '</div>';
        } elseif ($tab === 'following' || $tab === 'followers') {
            $person = get_userdata($entry->user_id); if (!$person) { continue; }
            $available = !wuai_sluyu_banned($person->ID);
            echo '<div>';
            if ($available) { echo '<a href="' . esc_url(get_author_posts_url($person->ID)) . '">' . get_avatar($person->ID, 32) . ' ' . esc_html($person->display_name) . '</a>'; }
            else { echo '<span class="sluyu-muted">账号暂不可用</span>'; }
            if ($tab === 'following') { wuai_sluyu_action_button('remove_follow', '取消关注', ['id'=>$person->ID]); }
            elseif ($available && $person->ID !== $uid) { wuai_sluyu_action_button('follow', wuai_sluyu_has_event($uid, 'follow:' . $person->ID) ? '已关注 / 取消' : '关注', ['id'=>$person->ID]); }
            echo '</div>';
        } elseif ($tab === 'comments') {
            $post = wuai_sluyu_public_post($entry->comment_post_ID);
            echo '<div><span>';
            if ($post) { echo '<a href="' . esc_url($entry->comment_approved === '1' ? get_comment_link($entry) : get_permalink($post)) . '">' . esc_html($post->post_title) . '</a>'; }
            else { echo '<b>原内容暂不可查看</b>'; }
            echo '<p>' . esc_html(wp_trim_words(wp_strip_all_tags($entry->comment_content), 65)) . '</p><small>' . esc_html($entry->comment_date) . ' · ' . ($entry->comment_approved === '1' ? '已通过' : '审核中') . '</small></span>';
            do_action('wuai_sluyu_account_comment_actions', $entry); echo '</div>';
        } elseif ($tab === 'submissions') {
            $types = wuai_sluyu_account_types();
            echo '<div><span><b>' . esc_html($entry->post_title ?: '无标题') . '</b><p class="sluyu-muted">' . esc_html(($types[$entry->post_type] ?? '') . ' · ' . wuai_sluyu_account_status($entry)) . '</p></span><div class="sluyu-button-row">';
            if (wuai_sluyu_public_post($entry->ID)) { echo '<a class="sluyu-action-plain" href="' . esc_url(get_permalink($entry)) . '">查看</a>'; }
            if (function_exists('wuai_sluyu_topic_media_managed') && wuai_sluyu_topic_media_managed($entry->ID)) { echo '<a class="sluyu-action-plain" href="' . esc_url(wuai_sluyu_portal_url('submit', ['kind'=>'topic-images','draft'=>$entry->ID])) . '">图片编辑器</a>'; }
            if (!is_wp_error(wuai_sluyu_account_submission_permission($entry, $uid, 'edit_submission'))) { echo '<a class="sluyu-action-plain" href="' . esc_url(wuai_sluyu_portal_url('account', ['tab'=>'submissions','edit'=>$entry->ID])) . '">编辑</a>'; }
            if ($entry->post_status !== 'draft' && !is_wp_error(wuai_sluyu_account_submission_permission($entry, $uid, 'withdraw_submission'))) { wuai_sluyu_action_button('withdraw_submission', '撤回', ['id'=>$entry->ID,'confirm'=>'撤回后内容不再公开，重新发布需要审核。确认撤回？']); }
            if (!is_wp_error(wuai_sluyu_account_submission_permission($entry, $uid, 'delete_submission'))) { wuai_sluyu_action_button('delete_submission', '删除', ['id'=>$entry->ID,'confirm'=>'确认删除这份非公开稿件？此操作不会删除账号、订单或积分记录。']); }
            if (function_exists('wuai_sluyu_bounty_managed') && wuai_sluyu_bounty_managed($entry->ID)) {
                $allocation=wuai_sluyu_bounty_row($entry->ID);
                if (!is_wp_error($allocation) && $allocation && $allocation['requester_id']===$uid && in_array($allocation['state'],['held','open'],true)) {
                    wuai_sluyu_form_start('bounty_withdraw');
                    echo '<input type="hidden" name="id" value="'.absint($entry->ID).'"><input type="hidden" name="version" value="'.$allocation['version'].'"><label><span><input type="checkbox" name="confirm" value="1" required style="width:auto"> 我确认关闭问题并退回尚未发放的预留积分。</span></label>';
                    wuai_sluyu_form_end('退回未发放悬赏并撤回');
                }
            }
            echo '</div></div>';
        }
    }
    echo '</div>';
    if (!$data['items']) { wuai_sluyu_empty($page > 1 ? '这一页暂无记录，请返回上一页。' : '这里还没有记录。'); }
    if ($preview) { echo '<p><a href="' . esc_url(wuai_sluyu_portal_url('account', ['tab'=>$tab])) . '">查看全部 →</a></p>'; }
    else { wuai_sluyu_portal_pagination($data['pages'], 'account', 'pg', ['tab'=>$tab]); }
    echo '</section>';
}

function wuai_sluyu_view_account_extended() {
    if (!wuai_sluyu_require_login()) { return; }
    $user = wp_get_current_user(); $uid = (int) $user->ID;
    $tabs = wuai_sluyu_account_tabs(); $tab = wuai_sluyu_query('tab', 'overview');
    if (!isset($tabs[$tab])) { $tab = 'overview'; }
    $following = wuai_sluyu_account_page_data('following', $uid, 1, 1);
    $followers = wuai_sluyu_account_page_data('followers', $uid, 1, 1);
    echo '<section class="sluyu-panel"><div class="sluyu-profile-heading">' . get_avatar($uid, 80) . '<div><h2>' . esc_html($user->display_name) . '</h2><p>' . esc_html(get_user_meta($uid, '_wuai_verified_title', true) ?: '普通用户') . ' · ' . (wuai_sluyu_member($uid) ? '会员有效' : '未开通会员') . '</p></div><a href="' . esc_url(wp_logout_url(home_url('/'))) . '">退出登录</a></div><div class="sluyu-stat-grid">';
    foreach (['积分'=>wuai_sluyu_points_label(wuai_sluyu_points_available($uid)),'文章'=>count_user_posts($uid, 'post', true),'关注'=>$following['total'],'粉丝'=>$followers['total']] as $label=>$value) { echo '<div><strong>' . esc_html($value) . '</strong><span>' . esc_html($label) . '</span></div>'; }
    echo '</div><nav class="sluyu-filter-tabs" aria-label="个人中心栏目">';
    foreach ($tabs as $key=>$label) { echo '<a href="' . esc_url(wuai_sluyu_portal_url('account', ['tab'=>$key])) . '"' . ($tab === $key ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>'; }
    echo '</nav></section>';
    if ($tab === 'security' && function_exists('wuai_sluyu_view_account_security')) { wuai_sluyu_view_account_security(); return; }
    if ($tab === 'overview') {
        echo '<section class="sluyu-panel"><h2>我的资料</h2>'; wuai_sluyu_form_start('profile');
        echo '<label>昵称<input name="display_name" maxlength="40" value="' . esc_attr($user->display_name) . '" required></label><label>个人简介<textarea name="description" maxlength="500">' . esc_textarea($user->description) . '</textarea></label><label>个人网站<input type="url" name="user_url" value="' . esc_attr($user->user_url) . '"></label>';
        wuai_sluyu_form_end('保存资料');
        echo '<p class="sluyu-muted">个人资料仅由当前账号编辑。安全操作：<a href="' . esc_url(wp_lostpassword_url()) . '">重置密码</a></p>';
        do_action('wuai_sluyu_account_profile_extra', $uid);
        echo '</section>';
        wuai_sluyu_account_render_list('bookmarks', $uid, 1, 3, true);
        wuai_sluyu_account_render_list('submissions', $uid, 1, 3, true);
        return;
    }
    if ($tab === 'submissions') {
        $edit = absint(wuai_sluyu_query('edit'));
        if ($edit) { wuai_sluyu_account_edit_form($edit, $uid); return; }
        echo '<p class="sluyu-notice">仅列出你自己的投稿和申请。公开稿件修改后重新审核；已处理的认证与工单保留为只读记录。<a href="' . esc_url(wuai_sluyu_portal_url('submit')) . '">发布新内容 →</a></p>';
    }
    wuai_sluyu_account_render_list($tab, $uid, wuai_sluyu_page_number());
}
