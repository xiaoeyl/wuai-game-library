<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_poll($id) {
    if (get_post_type($id) !== 'sluyu_topic' || get_post_meta($id,'_wuai_topic_kind',true) !== 'poll') { return false; }
    $choices=get_post_meta($id,'_wuai_poll_choices',true);
    if (!is_array($choices) || count($choices)<2 || count($choices)>8) { return false; }
    $mode=get_post_meta($id,'_wuai_poll_mode',true);
    $mode=in_array($mode,['single','multiple','pk'],true)?$mode:'single';
    $maximum=$mode==='multiple'?max(1,min(count($choices),(int)get_post_meta($id,'_wuai_poll_max',true) ?: count($choices))):1;
    $deadline=(int)get_post_meta($id,'_wuai_poll_deadline',true);
    return ['choices'=>array_values($choices),'mode'=>$mode,'max'=>$maximum,'deadline'=>$deadline,'closed'=>(bool)get_post_meta($id,'_wuai_poll_closed',true) || ($deadline && $deadline<=time())];
}

function wuai_sluyu_poll_ballot($payload, $count) {
    if (is_string($payload) && ctype_digit($payload)) { $payload=[(int)$payload]; }
    elseif (is_string($payload)) { $payload=json_decode($payload,true); }
    if (!is_array($payload) || count($payload)>8) { return []; }
    $result=[];
    foreach ($payload as $index) {
        if ((!is_int($index) && !(is_string($index) && ctype_digit($index))) || (int)$index<0 || (int)$index>=$count) { return []; }
        $result[]=(int)$index;
    }
    return array_values(array_unique($result));
}

function wuai_sluyu_poll_tally($id, $poll = null) {
    $poll=$poll ?: wuai_sluyu_poll($id);
    if (!$poll) { return ['counts'=>[],'voters'=>0]; }
    global $wpdb;
    $rows=$wpdb->get_results($wpdb->prepare('SELECT payload,COUNT(*) AS total FROM '.wuai_sluyu_table('events')." WHERE target_id=%d AND event_type='vote' GROUP BY payload",$id));
    $counts=array_fill(0,count($poll['choices']),0); $voters=0;
    foreach ($rows as $row) {
        $ballot=wuai_sluyu_poll_ballot($row->payload,count($counts));
        if (!$ballot) { continue; }
        $voters+=(int)$row->total;
        foreach ($ballot as $index) { $counts[$index]+=(int)$row->total; }
    }
    return ['counts'=>$counts,'voters'=>$voters];
}

add_filter('wuai_sluyu_community_dispatch', function ($result,$operation,$uid) {
    if (!in_array($operation,['vote','poll_close'],true)) { return $result; }
    $id=absint(wuai_sluyu_input('id'));
    $post=wuai_sluyu_public_post($id,['sluyu_topic']); $poll=wuai_sluyu_poll($id);
    if (!$post || !$poll) { return new WP_Error('poll','投票不存在或尚未公开。'); }
    $lock=wuai_sluyu_lock('poll_'.$id);
    if (!$lock) { return new WP_Error('poll_busy','投票正在更新，请稍后再试。'); }
    try {
        clean_post_cache($id);
        $post=wuai_sluyu_public_post($id,['sluyu_topic']);
        $poll=wuai_sluyu_poll($id);
        if (!$post || !$poll) { return new WP_Error('poll','投票配置已更新或内容已撤回，请刷新页面。'); }
        if ($operation==='poll_close') {
            if ((int)$post->post_author!==$uid && !user_can($uid,'moderate_comments')) { return new WP_Error('permission','只有发起人或管理员可以结束投票。'); }
            update_post_meta($id,'_wuai_poll_closed',1);
            return ['message'=>'投票已结束，已有结果保留。','reload'=>true];
        }
        if ($poll['closed']) { return new WP_Error('poll_closed','本次投票已结束。'); }
        $groups=wp_get_object_terms($id,'sluyu_circle',['fields'=>'ids','wuai_include_pending'=>true]);
        if (is_wp_error($groups)) { return new WP_Error('group','无法验证圈子权限，请稍后再试。'); }
        foreach ($groups as $group) { if (!wuai_sluyu_circle_can_post($group,$uid)) { return new WP_Error('circle_vote','请先满足此圈子的参与条件。'); } }
        if (wuai_sluyu_has_event($uid,'vote:'.$id)) { return new WP_Error('voted','你已经参与过这次投票。'); }
        $raw=isset($_POST['choice'])?wp_unslash($_POST['choice']):'';
        if (is_array($raw)) {
            foreach ($raw as $value) { if (!is_scalar($value)) { return new WP_Error('choice','请选择有效选项。'); } }
        } elseif (!is_scalar($raw)) { return new WP_Error('choice','请选择有效选项。'); }
        $ballot=wuai_sluyu_poll_ballot(is_array($raw)?$raw:(string)$raw,count($poll['choices']));
        if (!$ballot || count($ballot)>$poll['max'] || ($poll['mode']!=='multiple' && count($ballot)!==1)) { return new WP_Error('choice','请选择 1–'.$poll['max'].' 个有效选项。'); }
        sort($ballot,SORT_NUMERIC);
        $payload=count($ballot)===1?(string)$ballot[0]:wp_json_encode($ballot);
        if (!wuai_sluyu_event($uid,'vote:'.$id,'vote',0,$id,$payload)) { return new WP_Error('storage','投票未保存，请刷新确认后重试。'); }
        return ['message'=>'投票成功。','reload'=>true];
    } finally { wuai_sluyu_unlock($lock); }
},20,3);

add_filter('wuai_sluyu_submission',function($data,$kind,$uid){
    if (is_wp_error($data) || $kind!=='poll') { return $data; }
    $mode=wuai_sluyu_input('poll_mode') ?: 'single';
    $choices=$data['meta_input']['_wuai_poll_choices'];
    if (!in_array($mode,['single','multiple','pk'],true) || ($mode==='pk' && count($choices)!==2)) { return new WP_Error('poll_mode','PK 投票需要恰好 2 个选项。'); }
    $maximum=$mode==='multiple'?absint(wuai_sluyu_input('poll_max')):1;
    if ($maximum<1 || $maximum>count($choices)) { return new WP_Error('poll_max','最多可选数量必须介于 1 与选项数之间。'); }
    $deadline=wuai_sluyu_input('poll_deadline'); $date=$deadline?wuai_sluyu_parse_date($deadline,'Y-m-d\TH:i'):false;
    if ($deadline && (!$date || $date->getTimestamp()<=time() || $date->getTimestamp()>time()+YEAR_IN_SECONDS)) { return new WP_Error('deadline','截止时间需晚于当前时间，且不超过一年（站点时区）。'); }
    $data['meta_input']['_wuai_poll_mode']=$mode;
    $data['meta_input']['_wuai_poll_max']=$maximum;
    $data['meta_input']['_wuai_poll_deadline']=$date?$date->getTimestamp():0;
    return $data;
},20,3);

function wuai_sluyu_poll_submission_fields() {
    echo '<fieldset data-poll-field class="sluyu-poll-fields"><legend>投票设置</legend><label>投票形式<select name="poll_mode"><option value="single">单选投票</option><option value="multiple">多选投票</option><option value="pk">双方 PK</option></select></label><label>投票选项（每行一个，2–8 项；PK 为 2 项）<textarea name="choices" rows="4" maxlength="808"></textarea></label><label data-poll-multiple>最多可选数量<input type="number" name="poll_max" min="1" max="8" value="2"></label><label>截止时间（' . esc_html(wp_timezone_string()) . '，留空不限时）<input type="datetime-local" name="poll_deadline"></label></fieldset>';
}

function wuai_sluyu_poll_view($id) {
    $poll=wuai_sluyu_poll($id); if (!$poll) { return; }
    $tally=wuai_sluyu_poll_tally($id,$poll); $uid=get_current_user_id(); global $wpdb;
    $payload=$uid?$wpdb->get_var($wpdb->prepare('SELECT payload FROM '.wuai_sluyu_table('events').' WHERE user_id=%d AND event_key=%s',$uid,'vote:'.$id)):null;
    $ballot=$payload!==null?wuai_sluyu_poll_ballot($payload,count($poll['choices'])):[];
    $can_vote=$uid && !$poll['closed'] && $payload===null && !wuai_sluyu_banned($uid);
    echo '<section class="sluyu-panel sluyu-poll sluyu-poll-' . esc_attr($poll['mode']) . '"><div class="sluyu-panel-heading"><h2>' . esc_html(['single'=>'单选投票','multiple'=>'多选投票','pk'=>'观点 PK'][$poll['mode']]) . '</h2><span class="sluyu-state-pill">' . ($poll['closed']?'已结束':($payload!==null?'已参与':'进行中')) . '</span></div><p class="sluyu-muted">' . $tally['voters'] . ' 人参与 · 最多选 ' . $poll['max'] . ' 项';
    if ($poll['deadline']) { echo ' · 截止 ' . esc_html(wp_date('Y-m-d H:i',$poll['deadline'])) . '（' . esc_html(wp_timezone_string()) . '）'; }
    echo '</p>';
    if ($can_vote) { wuai_sluyu_form_start('vote'); echo '<input type="hidden" name="id" value="' . absint($id) . '">'; }
    else { echo '<div class="sluyu-poll-results">'; }
    foreach ($poll['choices'] as $index=>$choice) {
        $count=$tally['counts'][$index]; $percent=$tally['voters']?round($count/$tally['voters']*100):0;
        echo '<label class="sluyu-poll-choice" style="--poll-percent:' . (int)$percent . '%">';
        if ($can_vote) { echo '<input type="' . ($poll['mode']==='multiple'?'checkbox':'radio') . '" name="' . ($poll['mode']==='multiple'?'choice[]':'choice') . '" value="' . $index . '"' . ($poll['mode']!=='multiple'?' required':'') . '>'; }
        echo '<b>' . esc_html($choice) . (in_array($index,$ballot,true)?' ✓':'') . '</b><span>' . $count . ' 票 · ' . (int)$percent . '%</span></label>';
    }
    if ($can_vote) { wuai_sluyu_form_end('提交投票'); } else { echo '</div>'; }
    if (!$uid && !$poll['closed']) { echo '<p><a href="' . esc_url(wp_login_url(get_permalink($id))) . '">登录后投票 →</a></p>'; }
    if ($poll['mode']==='multiple') { echo '<p class="sluyu-muted">百分比以参与人数计算，多选结果之和可超过 100%。投票后不可重复或改投。</p>'; }
    if (!$poll['closed'] && $uid && ((int)get_post_field('post_author',$id)===$uid || user_can($uid,'moderate_comments'))) { wuai_sluyu_action_button('poll_close','提前结束投票',['id'=>$id,'confirm'=>'确认结束投票？结果将保留，但不能继续投票。']); }
    echo '</section>';
}

function wuai_sluyu_poll_admin_fields($id) {
    $poll=wuai_sluyu_poll($id);
    echo '<p><label>投票形式 <select name="wuai_poll_mode">';
    foreach (['single'=>'单选','multiple'=>'多选','pk'=>'双方 PK'] as $value=>$label) { echo '<option value="' . $value . '" ' . selected($poll?$poll['mode']:'single',$value,false) . '>' . $label . '</option>'; }
    echo '</select></label> <label>最多可选 <input name="wuai_poll_max" type="number" min="1" max="8" value="' . ($poll?$poll['max']:2) . '"></label></p><p><label>截止时间（' . esc_html(wp_timezone_string()) . '）<input name="wuai_poll_deadline" type="datetime-local" value="' . esc_attr($poll && $poll['deadline']?wp_date('Y-m-d\TH:i',$poll['deadline']):'') . '"></label></p><p><label><input name="wuai_poll_closed" type="checkbox" value="1" ' . checked((bool)get_post_meta($id,'_wuai_poll_closed',true),true,false) . '> 已结束投票</label></p><p>已有票数后，选项、形式与最多可选数量锁定。截止时间和结束状态仍可管理。</p>';
}
