<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_circle_request_prefix() { return 'wuai-circle-request-'; }
function wuai_sluyu_circle_old_request_pattern() { return '^circle-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$'; }

function wuai_sluyu_circle_is_request($slug) {
    return strpos($slug, wuai_sluyu_circle_request_prefix()) === 0 || preg_match('~' . wuai_sluyu_circle_old_request_pattern() . '~D', $slug);
}

add_filter('wp_insert_term_data',function($data,$taxonomy){
    if($taxonomy==='sluyu_circle' && current_user_can('manage_categories') && !wuai_sluyu_banned(get_current_user_id()) && wp_verify_nonce(wuai_sluyu_input('wuai_circle_nonce'),'wuai_circle_admin')) {
        // Native configured creation also needs an atomic fail-closed marker before
        // its first metadata write. Public circle links use IDs, not these slugs.
        $data['slug']=wuai_sluyu_circle_request_prefix().wp_generate_uuid4();
    }
    return $data;
},20,2);

function wuai_sluyu_circle_status($term) {
    $term = $term instanceof WP_Term ? $term : get_term(absint($term), 'sluyu_circle');
    if (!$term || is_wp_error($term)) { return 'pending'; }
    $status = get_term_meta($term->term_id, '_wuai_circle_status', true);
    if (metadata_exists('term', $term->term_id, '_wuai_circle_status')) { return $status === 'publish' ? 'publish' : 'pending'; }
    // The slug is stored with the term itself: a failed first metadata write must
    // never turn a new application into a public legacy circle.
    return wuai_sluyu_circle_is_request($term->slug) ? 'pending' : 'publish';
}

function wuai_sluyu_circle($id, $include_pending = false) {
    $term = get_term(absint($id), 'sluyu_circle');
    if (!$term || is_wp_error($term)) { return false; }
    return $include_pending || wuai_sluyu_circle_status($term) === 'publish' ? $term : false;
}

function wuai_sluyu_circle_manage($id, $uid = 0) {
    $uid = $uid ?: get_current_user_id();
    return $uid && wuai_sluyu_circle($id,true) && !wuai_sluyu_banned($uid) && (user_can($uid, 'manage_categories') || (int) get_term_meta($id, '_wuai_circle_owner', true) === $uid);
}

function wuai_sluyu_circle_membership($circle_id, $user_id = 0) {
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) { return ''; }
    if (wuai_sluyu_circle_manage($circle_id, $user_id)) { return 'owner'; }
    global $wpdb;
    return (string) $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . wuai_sluyu_table('memberships') . ' WHERE circle_id=%d AND user_id=%d', $circle_id, $user_id));
}

function wuai_sluyu_circle_participation($circle_ids, $uid = null) {
    $uid = $uid === null ? get_current_user_id() : absint($uid);
    if (!$uid || !get_userdata($uid)) { return new WP_Error('circle_login', '请先登录后参与圈子。'); }
    if (wuai_sluyu_banned($uid)) { return new WP_Error('circle_banned', '账号当前不能参与圈子，请在限制解除后重试。'); }
    foreach ((array) $circle_ids as $id) {
        if (!is_scalar($id) || !absint($id) || !wuai_sluyu_circle($id)) { return new WP_Error('circle_unavailable', '所属圈子不存在或尚未开放。'); }
        $id = absint($id); $state = wuai_sluyu_circle_membership($id, $uid);
        if ($state === 'blocked') { return new WP_Error('circle_blocked', '你已被限制参与此圈子。'); }
        if ($state === 'owner') { continue; }
        $mode = get_term_meta($id, '_wuai_circle_join', true) ?: 'open';
        if ($mode === 'member' && !wuai_sluyu_member($uid)) { return new WP_Error('circle_member', '此圈子需要有效会员资格。'); }
        if (get_term_meta($id, '_wuai_circle_posting', true) === 'members' && $state !== 'active') { return new WP_Error('circle_join', '请先加入圈子并等待申请通过，再参与发帖、评论或投票。'); }
    }
    return true;
}

function wuai_sluyu_circle_can_post($id, $uid) {
    return wuai_sluyu_circle_participation([$id], $uid) === true;
}

function wuai_sluyu_circle_topic_participation($post_id, $uid = null) {
    if (get_post_type($post_id) !== 'sluyu_topic') { return new WP_Error('circle_topic', '话题不存在。'); }
    if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($post_id)) { return new WP_Error('circle_topic_held', '话题正在核验，暂不能参与。'); }
    $groups = wp_get_object_terms($post_id, 'sluyu_circle', ['fields'=>'ids','wuai_include_pending'=>true]);
    if (is_wp_error($groups)) { return new WP_Error('circle_groups', '无法核验所属圈子，请稍后重试。'); }
    return wuai_sluyu_circle_participation($groups, $uid);
}

function wuai_sluyu_circle_count($id) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . wuai_sluyu_table('memberships') . " WHERE circle_id=%d AND status='active'", $id));
}

add_filter('wuai_sluyu_community_dispatch', function ($result, $operation, $uid) {
    if ($result !== null || !in_array($operation, ['circle_create','circle_join','circle_leave','circle_member'], true)) { return $result; }
    if (!$uid || (int)$uid!==get_current_user_id() || !get_userdata($uid) || wuai_sluyu_banned($uid)) { return new WP_Error('circle_auth','请使用正常状态的账号登录后操作圈子。'); }
    global $wpdb;
    $table = wuai_sluyu_table('memberships');
    if ($operation === 'circle_create') {
        $creation_lock=wuai_sluyu_lock('circle_create');
        if (!$creation_lock) { return new WP_Error('circle_busy','圈子申请正在处理，请稍后再试。'); }
        global $wuai_sluyu_all_circle_terms;
        $previous_visibility=$wuai_sluyu_all_circle_terms ?? false;
        $wuai_sluyu_all_circle_terms=true;
        try {
        $title = wuai_sluyu_input('title'); $description = wuai_sluyu_input('body', true);
        if (mb_strlen($title) < 2 || mb_strlen($title) > 30 || mb_strlen($description) < 10 || mb_strlen($description) > 1000) { return new WP_Error('circle_fields', '名称为 2–30 字，介绍为 10–1000 字。'); }
        $owned = get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false,'fields'=>'ids','wuai_include_pending'=>true,'meta_query'=>[['key'=>'_wuai_circle_owner','value'=>$uid]]]);
        if (is_wp_error($owned) || count($owned) >= 5) { return new WP_Error('circle_limit', '每个账号最多创建 5 个圈子（含待审申请）。'); }
        if (term_exists($title,'sluyu_circle')) { return new WP_Error('circle_name','此圈子名称暂不可用，请更换名称。'); }
        $term = wp_insert_term($title, 'sluyu_circle', ['description'=>$description,'slug'=>wuai_sluyu_circle_request_prefix().wp_generate_uuid4()]);
        if (is_wp_error($term)) { return new WP_Error('circle_create','圈子未创建，请检查名称后重试。'); }
        $id = $term['term_id'];
        $join = wuai_sluyu_input('join_mode');
        $settings = ['_wuai_circle_status'=>'pending','_wuai_circle_owner'=>(string)$uid,'_wuai_circle_join'=>in_array($join,['open','approval','member'],true) ? $join : 'open','_wuai_circle_posting'=>'members'];
        foreach ($settings as $key=>$value) { update_term_meta($id, $key, $value); }
        wp_cache_delete($id, 'term_meta');
        foreach ($settings as $key=>$value) {
            if (get_term_meta($id, $key, true) !== $value) {
                wp_delete_term($id, 'sluyu_circle');
                return new WP_Error('circle_storage','圈子申请未完整保存，本次不会开放圈子。请保留填写内容并重试；若名称被占用，请联系管理员核对。');
            }
        }
        wuai_sluyu_notify($uid, '圈子「' . $title . '」已提交，管理员审核后开放。');
        return ['message'=>'创建申请已提交，请在圈子目录的“我创建的”查看进度。','reset'=>true];
        } finally { $wuai_sluyu_all_circle_terms=$previous_visibility; wuai_sluyu_unlock($creation_lock); }
    }
    $id = absint(wuai_sluyu_input('id')); $circle = wuai_sluyu_circle($id);
    if (!$circle) { return new WP_Error('circle', '圈子不存在或尚未开放。'); }
    $lock = wuai_sluyu_lock('circle_' . $id);
    if (!$lock) { return new WP_Error('circle_busy', '圈子成员正在更新，请稍后再试。'); }
    try {
        wp_cache_delete($id, 'term_meta');
        $circle=wuai_sluyu_circle($id);
        if (!$circle) { return new WP_Error('circle', '圈子状态已变更，当前不能加入或管理成员。'); }
        $state = wuai_sluyu_circle_membership($id, $uid);
        if ($operation === 'circle_join') {
            if (in_array($state,['owner','active','pending'],true)) { return ['message'=>$state==='pending'?'申请仍在审核中。':'你已经加入该圈子。','reload'=>true]; }
            if ($state === 'blocked') { return new WP_Error('circle_blocked', '你暂时不能加入此圈子。'); }
            $mode = get_term_meta($id,'_wuai_circle_join',true) ?: 'open';
            if ($mode === 'member' && !wuai_sluyu_member($uid)) { return new WP_Error('member', '此圈子仅限有效会员加入。'); }
            $status = $mode === 'approval' ? 'pending' : 'active';
            $saved = $wpdb->replace($table,['circle_id'=>$id,'user_id'=>$uid,'status'=>$status,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
            if (!$saved) { return new WP_Error('storage','加入失败，请稍后重试。'); }
            if ($status === 'pending') { $owner=(int)get_term_meta($id,'_wuai_circle_owner',true); if($owner)wuai_sluyu_notify($owner, '「' . $circle->name . '」有新的加入申请，请在圈子页面审核。'); }
            return ['message'=>$status==='active'?'已加入圈子。':'申请已提交，等待圈主审核。','reload'=>true];
        }
        if ($operation === 'circle_leave') {
            if ($state === 'owner') { return new WP_Error('owner','圈主无法直接退出，请联系站点管理员转交圈子。'); }
            if ($state === 'blocked') { return new WP_Error('blocked','禁入状态不能自行解除。'); }
            if ($wpdb->delete($table,['circle_id'=>$id,'user_id'=>$uid]) === false) { return new WP_Error('storage','退出失败，请稍后再试。'); }
            return ['message'=>'已退出 / 撤回申请。','reload'=>true];
        }
        if (!wuai_sluyu_circle_manage($id,$uid)) { return new WP_Error('permission','只有圈主或站点管理员可以管理成员。'); }
        $target = absint(wuai_sluyu_input('user_id')); $decision = wuai_sluyu_input('decision');
        if (!$target || $target === $uid || user_can($target,'manage_categories') || wuai_sluyu_circle_manage($id,$target) || !in_array($decision,['active','rejected','blocked'],true)) { return new WP_Error('target','无效的成员操作。'); }
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE circle_id=%d AND user_id=%d",$id,$target));
        if (!$existing) { return new WP_Error('missing','申请或成员记录已不存在。'); }
        if ($existing->status===$decision) { return ['message'=>'成员状态未发生变化。','reload'=>true]; }
        if ($decision==='active' && (wuai_sluyu_banned($target) || (get_term_meta($id,'_wuai_circle_join',true)==='member' && !wuai_sluyu_member($target)))) { return new WP_Error('eligibility','该用户当前不符合加入条件。'); }
        if ($wpdb->update($table,['status'=>$decision,'updated_at'=>current_time('mysql')],['id'=>$existing->id]) === false) { return new WP_Error('storage','保存失败，请重试。'); }
        wuai_sluyu_notify($target, '圈子「' . $circle->name . '」成员状态：' . ['active'=>'已通过','rejected'=>'申请未通过','blocked'=>'已被限制加入'][$decision]);
        return ['message'=>'成员状态已更新。','reload'=>true];
    } finally { wuai_sluyu_unlock($lock); }
}, 10, 3);

add_filter('wuai_sluyu_submission', function ($data, $kind, $uid) {
    if (is_wp_error($data) || !in_array($kind,['topic','question','poll'],true)) { return $data; }
    $group = absint(wuai_sluyu_input('group'));
    if ($group && !wuai_sluyu_circle_can_post($group,$uid)) { return new WP_Error('circle_post','请先加入开放的圈子，并满足该圈子的发帖条件。'); }
    return $data;
}, 10, 3);

add_filter('term_link', function ($url,$term,$taxonomy) {
    return $taxonomy === 'sluyu_circle' ? wuai_sluyu_portal_url('circle',['group'=>$term->term_id]) : $url;
},10,3);

// Public term queries must not expose unreviewed applications, including REST lists.
add_action('pre_get_terms', function ($query) {
    global $wuai_sluyu_all_circle_terms;
    if (!in_array('sluyu_circle',(array)($query->query_vars['taxonomy'] ?? []),true) || !empty($query->query_vars['wuai_include_pending']) || $wuai_sluyu_all_circle_terms || (is_admin() && current_user_can('manage_categories'))) { return; }
    $visible=['relation'=>'OR',['key'=>'_wuai_circle_status','compare'=>'NOT EXISTS'],['key'=>'_wuai_circle_status','value'=>'publish']];
    $existing=$query->query_vars['meta_query'] ?? [];
    $query->query_vars['meta_query']=$existing ? ['relation'=>'AND',$existing,$visible] : $visible;
});
add_filter('terms_clauses', function ($clauses, $taxonomies, $args) {
    global $wpdb, $wuai_sluyu_all_circle_terms;
    if (!in_array('sluyu_circle', (array)$taxonomies, true) || !empty($args['wuai_include_pending']) || $wuai_sluyu_all_circle_terms || (is_admin() && current_user_can('manage_categories'))) { return $clauses; }
    // Apply before LIMIT/COUNT so orphaned applications cannot leak through lists,
    // pagination totals or the REST term collection when metadata storage failed.
    $clauses['where'] .= $wpdb->prepare(" AND (tt.taxonomy <> 'sluyu_circle' OR (t.slug NOT LIKE %s AND t.slug NOT REGEXP %s) OR EXISTS (SELECT 1 FROM $wpdb->termmeta AS wuai_circle_visibility WHERE wuai_circle_visibility.term_id=t.term_id AND wuai_circle_visibility.meta_key='_wuai_circle_status' AND wuai_circle_visibility.meta_value='publish'))", $wpdb->esc_like(wuai_sluyu_circle_request_prefix()) . '%', wuai_sluyu_circle_old_request_pattern());
    return $clauses;
}, 20, 3);
add_filter('rest_prepare_sluyu_circle', function ($response,$term) {
    return !wuai_sluyu_circle($term->term_id) && !current_user_can('manage_categories') ? new WP_Error('rest_not_found','圈子尚未开放。',['status'=>404]) : $response;
},10,2);
add_action('template_redirect', function () {
    if (is_tax('sluyu_circle') && !wuai_sluyu_circle(get_queried_object_id())) { global $wp_query; $wp_query->set_404(); status_header(404); nocache_headers(); }
},2);

function wuai_sluyu_circle_header($id) {
    $term = wuai_sluyu_circle($id); if (!$term) { return; }
    $uid = get_current_user_id(); $participation = wuai_sluyu_circle_participation([$id], $uid);
    $state = wuai_sluyu_circle_membership($id);
    $join = get_term_meta($id,'_wuai_circle_join',true) ?: 'open';
    echo '<section class="sluyu-panel sluyu-circle-heading"><p class="sluyu-eyebrow">TEA TIME / COMMUNITY</p><h2>' . esc_html($term->name) . '</h2><p>' . esc_html($term->description) . '</p><p class="sluyu-muted">' . wuai_sluyu_circle_count($id) . ' 位会友 · ' . esc_html(['open'=>'自由加入','approval'=>'审核加入','member'=>'会员专属'][$join]??'自由加入') . ' · 话题公开阅读</p><div class="sluyu-button-row">';
    if (!$uid) { echo '<a class="sluyu-action-primary" href="' . esc_url(wp_login_url(wuai_sluyu_portal_url('circle',['group'=>$id]))) . '">登录后参与</a>'; }
    elseif (wuai_sluyu_banned($uid)) { echo '<span class="sluyu-state-pill">账号暂不能参与</span>'; }
    elseif (!$state || $state==='rejected') { wuai_sluyu_action_button('circle_join',$join==='approval'?'申请加入':'加入圈子',['id'=>$id]); }
    elseif ($state==='pending') { echo '<span class="sluyu-state-pill">申请审核中</span>'; wuai_sluyu_action_button('circle_leave','撤回申请',['id'=>$id]); }
    elseif ($state==='active') { echo '<span class="sluyu-state-pill">已加入</span>'; wuai_sluyu_action_button('circle_leave','退出圈子',['id'=>$id,'confirm'=>'确认退出此圈子？']); }
    elseif ($state==='blocked') { echo '<span class="sluyu-state-pill">加入受限</span>'; }
    else { echo '<span class="sluyu-state-pill">圈子管理者</span>'; }
    if ($participation === true) { echo '<a class="sluyu-action-plain" href="' . esc_url(wuai_sluyu_portal_url('submit',['kind'=>'topic','group'=>$id])) . '">发布话题</a>'; }
    if (wuai_sluyu_circle_review_can_manage($id,$uid)) { echo '<a class="sluyu-action-plain" href="'.esc_url(wuai_sluyu_portal_url('circle-topics',['group'=>$id])).'">话题审核</a>'; }
    echo '<a href="' . esc_url(wuai_sluyu_portal_url('circles')) . '">全部圈子 →</a></div>';
    if ($uid && is_wp_error($participation)) { echo '<p class="sluyu-muted">' . esc_html($participation->get_error_message()) . '</p>'; }
    echo '</section>';
    if (!wuai_sluyu_circle_manage($id)) { return; }
    global $wpdb;
    $where=$wpdb->prepare("circle_id=%d AND status IN ('pending','active','blocked')",$id);
    $total=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.wuai_sluyu_table('memberships')." WHERE $where");
    $members = $wpdb->get_results($wpdb->prepare('SELECT * FROM '.wuai_sluyu_table('memberships')." WHERE $where ORDER BY status DESC,id DESC LIMIT 20 OFFSET %d",(wuai_sluyu_page_number('members_pg')-1)*20));
    echo '<details class="sluyu-panel sluyu-circle-members"' . (wuai_sluyu_page_number('members_pg')>1?' open':'') . '><summary>成员与加入申请管理（' . $total . '）</summary><p class="sluyu-muted">仅圈主及管理员可见。每页显示 20 条成员记录。</p><div class="sluyu-compact-list">';
    foreach ($members as $member) {
        $user = get_userdata($member->user_id); if(!$user)continue;
        echo '<div><span>' . esc_html($user->display_name) . ' · ' . esc_html(['pending'=>'待审核','active'=>'已加入','blocked'=>'已限制'][$member->status]) . '</span><div class="sluyu-button-row">';
        if ($member->status!=='active') { wuai_sluyu_action_button('circle_member','批准',['id'=>$id,'user-id'=>$user->ID,'decision'=>'active']); }
        wuai_sluyu_action_button('circle_member',$member->status==='pending'?'拒绝':'移出',['id'=>$id,'user-id'=>$user->ID,'decision'=>'rejected']);
        if ($member->status!=='blocked') { wuai_sluyu_action_button('circle_member','限制加入',['id'=>$id,'user-id'=>$user->ID,'decision'=>'blocked','confirm'=>'确认限制此成员加入？']); }
        echo '</div></div>';
    }
    if (!$members)wuai_sluyu_empty('暂无成员或待审申请。'); echo '</div>'; wuai_sluyu_portal_pagination(ceil($total/20),'circle','members_pg',['group'=>$id]); echo '</details>';
}

function wuai_sluyu_view_circles() {
    $scope=wuai_sluyu_query('scope','all'); $uid=get_current_user_id();
    if(!in_array($scope,['all','joined','owned'],true))$scope='all';
    if($scope!=='all' && !wuai_sluyu_require_login())return;
    echo '<section class="sluyu-panel"><div class="sluyu-panel-heading"><h2>找到同好的小天地</h2><a class="sluyu-action-primary" href="#create-circle">创建圈子</a></div><nav class="sluyu-filter-tabs">';
    foreach(['all'=>'全部圈子','joined'=>'我加入的','owned'=>'我创建的'] as $key=>$label)echo '<a href="' . esc_url(wuai_sluyu_portal_url('circles',['scope'=>$key])) . '" ' . ($scope===$key?'aria-current="page"':'') . '>' . esc_html($label) . '</a>';
    echo '</nav></section><div class="sluyu-service-grid">';
    $args=['taxonomy'=>'sluyu_circle','hide_empty'=>false,'number'=>12,'offset'=>(wuai_sluyu_page_number()-1)*12];
    if($scope==='owned') { $args['wuai_include_pending']=true; $args['meta_query']=[['key'=>'_wuai_circle_owner','value'=>$uid]]; }
    $empty=false;
    if($scope==='joined') { global $wpdb; $ids=$wpdb->get_col($wpdb->prepare('SELECT circle_id FROM '.wuai_sluyu_table('memberships')." WHERE user_id=%d AND status IN ('active','pending')",$uid)); $empty=!$ids; $args['include']=$ids; }
    $terms=$empty?[]:get_terms($args); $shown=0;
    $count_args=$args; unset($count_args['number'],$count_args['offset']); $count_args['fields']='count';
    $total=$empty?0:get_terms($count_args); if(is_wp_error($total))$total=0;
    if(!is_wp_error($terms))foreach($terms as $term) {
        $status=wuai_sluyu_circle_status($term);
        if($status!=='publish' && !wuai_sluyu_circle_manage($term->term_id))continue;
        $shown++;
        echo '<article class="sluyu-service-tile">' . wuai_sluyu_icon('chat-heart-line') . '<h2>' . esc_html($term->name) . '</h2><p>' . esc_html(wp_trim_words($term->description,40)) . '</p><p>' . wuai_sluyu_circle_count($term->term_id) . ' 位会友 · ' . ($status==='publish'?'已开放':'审核中') . '</p>';
        if($status==='publish')echo '<a href="' . esc_url(wuai_sluyu_portal_url('circle',['group'=>$term->term_id])) . '">进入圈子 →</a>';
        echo '</article>';
    }
    echo '</div>'; if(!$shown)wuai_sluyu_empty('此列表暂无圈子。'); wuai_sluyu_portal_pagination(ceil((int)$total/12),'circles');
    echo '<section class="sluyu-panel" id="create-circle"><h2>创建新的茶话会</h2><p>圈子审核通过后开放；当前话题为公开阅读。每个账号最多 5 个圈子。</p>';
    if(wuai_sluyu_require_login()) { wuai_sluyu_form_start('circle_create'); echo '<label>圈子名称<input name="title" minlength="2" maxlength="30" required></label><label>圈子介绍<textarea name="body" minlength="10" maxlength="1000" required></textarea></label><label>加入方式<select name="join_mode"><option value="open">自由加入</option><option value="approval">圈主审核</option><option value="member">有效会员加入</option></select></label>'; wuai_sluyu_form_end('提交创建申请'); }
    echo '</section>';
}

function wuai_sluyu_circle_admin_fields($term = null) {
    if (!current_user_can('manage_categories') || wuai_sluyu_banned(get_current_user_id()))return;
    $id = $term instanceof WP_Term ? $term->term_id : 0;
    $fields=[
        '_wuai_circle_status'=>['开放状态',['publish'=>'已开放','pending'=>'待审核']],
        '_wuai_circle_join'=>['加入方式',['open'=>'自由加入','approval'=>'圈主审核','member'=>'会员专属']],
        '_wuai_circle_posting'=>['发帖权限',['everyone'=>'所有登录用户','members'=>'仅成员']],
    ];
    echo $id?'<tr class="form-field"><th>圈子设置</th><td>':'<div class="form-field"><h3>圈子设置</h3>';
    wp_nonce_field('wuai_circle_admin','wuai_circle_nonce');
    foreach($fields as $key=>$config) { echo '<p><label>' . esc_html($config[0]) . ' <select name="' . esc_attr($key) . '">'; $selected=$id && $key==='_wuai_circle_status'?wuai_sluyu_circle_status($term):get_term_meta($id,$key,true); foreach($config[1] as $value=>$label)echo '<option value="' . $value . '" ' . selected($selected,$value,false) . '>' . esc_html($label) . '</option>'; echo '</select></label></p>'; }
    echo '<p><label>圈主用户 ID <input type="number" name="_wuai_circle_owner" min="0" value="' . absint(get_term_meta($id,'_wuai_circle_owner',true)) . '"></label></p>';
    echo $id?'</td></tr>':'</div>';
}
add_action('sluyu_circle_add_form_fields','wuai_sluyu_circle_admin_fields');
add_action('sluyu_circle_edit_form_fields','wuai_sluyu_circle_admin_fields');
function wuai_sluyu_circle_admin_save($id) {
    if(!current_user_can('manage_categories') || wuai_sluyu_banned(get_current_user_id()) || !wp_verify_nonce(wuai_sluyu_input('wuai_circle_nonce'),'wuai_circle_admin'))return;
    $id=absint($id); $notice='wuai_circle_admin_error_'.get_current_user_id();
    $term=get_term($id,'sluyu_circle'); if(!$term || is_wp_error($term))return;
    $settings=[];
    foreach(['_wuai_circle_status'=>['publish','pending'],'_wuai_circle_join'=>['open','approval','member'],'_wuai_circle_posting'=>['everyone','members']] as $key=>$values) {
        $value=wuai_sluyu_input($key);
        if(!in_array($value,$values,true)) { set_transient($notice,'圈子权限字段无效，原权限配置未更改。请刷新后重试。',60); return; }
        $settings[$key]=$value;
    }
    $raw_owner=wuai_sluyu_input('_wuai_circle_owner');
    if(!preg_match('/^(0|[1-9][0-9]*)$/D',$raw_owner) || ((int)$raw_owner && !get_userdata((int)$raw_owner))) { set_transient($notice,'圈主必须是现有用户 ID，或填写 0 不指定圈主。原权限配置未更改。',60); return; }
    $owner=(int)$raw_owner; $settings['_wuai_circle_owner']=(string)$owner;
    $lock=wuai_sluyu_lock('circle_'.$id);
    if(!$lock) { set_transient($notice,'圈子权限正在更新，本次未改动权限配置。已保存的名称与介绍保留，请刷新后重试。',60); return; }
    try {
        wp_cache_delete($id,'term_meta'); $previous=wuai_sluyu_circle_status($term);
        update_term_meta($id,'_wuai_circle_status','pending'); wp_cache_delete($id,'term_meta');
        if(get_term_meta($id,'_wuai_circle_status',true)!=='pending') { set_transient($notice,'无法将圈子置为待审核，本次未改动其他权限。已保存的名称与介绍保留，请刷新核对原状态。',60); return; }
        $desired_status=$settings['_wuai_circle_status']; unset($settings['_wuai_circle_status']);
        foreach($settings as $key=>$value) { update_term_meta($id,$key,$value); }
        wp_cache_delete($id,'term_meta');
        foreach($settings as $key=>$value) {
            if(get_term_meta($id,$key,true)!==$value) { set_transient($notice,'圈子权限未完整保存，已保持待审核且不会开放。名称与介绍保留，请刷新补全设置。',60); return; }
        }
        if($desired_status==='publish') { update_term_meta($id,'_wuai_circle_status','publish'); wp_cache_delete($id,'term_meta'); }
        if(get_term_meta($id,'_wuai_circle_status',true)!==$desired_status) { set_transient($notice,'最终开放状态未保存，请刷新核对；本次不会发送审核通过通知。',60); return; }
        delete_transient($notice);
        if($owner && $previous==='pending' && $desired_status==='publish') { wuai_sluyu_notify($owner,'圈子「'.$term->name.'」已通过审核。'); }
    } finally { wuai_sluyu_unlock($lock); }
}
add_action('created_sluyu_circle','wuai_sluyu_circle_admin_save');
add_action('edited_sluyu_circle','wuai_sluyu_circle_admin_save');
add_action('admin_notices',function(){
    if(!current_user_can('manage_categories'))return;
    $key='wuai_circle_admin_error_'.get_current_user_id(); $message=get_transient($key);
    if($message) { delete_transient($key); echo '<div class="notice notice-error"><p>圈子设置：'.esc_html($message).'</p></div>'; }
});
add_action('delete_sluyu_circle',function($id){global $wpdb;$wpdb->delete(wuai_sluyu_table('memberships'),['circle_id'=>$id]);});
