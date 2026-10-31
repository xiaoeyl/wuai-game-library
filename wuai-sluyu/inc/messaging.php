<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_message_preference($uid) {
    $value=get_user_meta(absint($uid),'_wuai_message_receive',true);
    return in_array($value,['all','following','none'],true)?$value:'all';
}

function wuai_sluyu_message_blocks($uid) {
    $value=get_user_meta(absint($uid),'_wuai_message_blocks',true);
    if (!is_array($value)) { return []; }
    return array_values(array_unique(array_filter(array_map('absint',array_filter($value,'is_scalar')))));
}

function wuai_sluyu_message_can_contact($sender,$recipient) {
    $sender=absint($sender); $recipient=absint($recipient);
    if (!$sender || !$recipient || $sender===$recipient || !get_userdata($sender) || !get_userdata($recipient) || wuai_sluyu_banned($sender) || wuai_sluyu_banned($recipient)) { return false; }
    if (in_array($recipient,wuai_sluyu_message_blocks($sender),true) || in_array($sender,wuai_sluyu_message_blocks($recipient),true)) { return false; }
    $preference=wuai_sluyu_message_preference($recipient);
    return $preference==='all' || ($preference==='following' && wuai_sluyu_has_event($recipient,'follow:'.$sender));
}

function wuai_sluyu_message_where($uid,$kind='message',$peer=0) {
    global $wpdb;
    $uid=absint($uid); $peer=absint($peer);
    if (!$uid) { return '1=0'; }
    if ($kind==='notice') { return $wpdb->prepare("kind='notice' AND recipient_id=%d AND recipient_deleted=0",$uid); }
    if ($peer) {
        return $wpdb->prepare("kind='message' AND ((sender_id=%d AND recipient_id=%d AND sender_deleted=0) OR (recipient_id=%d AND sender_id=%d AND recipient_deleted=0))",$uid,$peer,$uid,$peer);
    }
    return $wpdb->prepare("kind='message' AND ((sender_id=%d AND sender_deleted=0) OR (recipient_id=%d AND recipient_deleted=0))",$uid,$uid);
}

function wuai_sluyu_message_unread($uid,$kind='message',$peer=0) {
    global $wpdb;
    $where=wuai_sluyu_message_where($uid,$kind,$peer);
    return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.wuai_sluyu_table('messages')." WHERE $where AND recipient_id=%d AND is_read=0",$uid));
}

function wuai_sluyu_message_history($uid,$kind='message',$peer=0,$page=1,$per_page=20) {
    global $wpdb;
    $where=wuai_sluyu_message_where($uid,$kind,$peer); $table=wuai_sluyu_table('messages');
    $per_page=max(1,min(50,absint($per_page))); $page=max(1,min(100000,absint($page)));
    return ['total'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where"),'items'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",$per_page,($page-1)*$per_page))];
}

function wuai_sluyu_message_threads($uid,$page=1,$per_page=20) {
    global $wpdb;
    $uid=absint($uid); $where=wuai_sluyu_message_where($uid); $table=wuai_sluyu_table('messages');
    $per_page=max(1,min(50,absint($per_page))); $page=max(1,min(100000,absint($page)));
    $peer=$wpdb->prepare('CASE WHEN sender_id=%d THEN recipient_id ELSE sender_id END',$uid);
    $unread=$wpdb->prepare('SUM(CASE WHEN recipient_id=%d AND is_read=0 THEN 1 ELSE 0 END)',$uid);
    $total=(int)$wpdb->get_var("SELECT COUNT(DISTINCT $peer) FROM $table WHERE $where");
    // Only visible copies participate in both aggregation and latest-message selection.
    $threads=$wpdb->get_results($wpdb->prepare("SELECT m.*, t.peer_id, t.unread FROM $table m INNER JOIN (SELECT $peer AS peer_id, MAX(id) AS last_id, $unread AS unread FROM $table WHERE $where GROUP BY $peer) t ON m.id=t.last_id ORDER BY m.id DESC LIMIT %d OFFSET %d",$per_page,($page-1)*$per_page));
    return ['total'=>$total,'items'=>$threads];
}

function wuai_sluyu_message_purge($uid,$peer=0) {
    global $wpdb;
    $table=wuai_sluyu_table('messages'); $uid=absint($uid); $peer=absint($peer);
    if (!$uid) { return; }
    $participants=$peer?$wpdb->prepare('((sender_id=%d AND recipient_id=%d) OR (sender_id=%d AND recipient_id=%d))',$uid,$peer,$peer,$uid):$wpdb->prepare('(sender_id=%d OR recipient_id=%d)',$uid,$uid);
    $wpdb->query("DELETE FROM $table WHERE kind='message' AND sender_deleted=1 AND recipient_deleted=1 AND $participants");
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE kind='notice' AND recipient_id=%d AND recipient_deleted=1",$uid));
}

add_filter('wuai_sluyu_community_dispatch',function($result,$operation,$uid){
    if (!in_array($operation,['message','read_messages','message_delete','conversation_delete','message_block','message_preferences'],true)) { return $result; }
    $uid=absint($uid);
    if (!$uid || !get_userdata($uid) || wuai_sluyu_banned($uid)) { return new WP_Error('message_auth','当前无法处理私信操作，请重新登录或联系管理员。'); }
    global $wpdb; $table=wuai_sluyu_table('messages'); $id=absint(wuai_sluyu_input('id'));
    if ($operation==='message') {
        $body=wuai_sluyu_input('body',true);
        if (mb_strlen($body)<1 || mb_strlen($body)>2000) { return new WP_Error('body','私信应为 1–2000 字。'); }
        $recipient=$id?get_userdata($id):get_user_by('login',wuai_sluyu_input('recipient'));
        $unavailable=new WP_Error('recipient','当前无法向此收件人发送私信，请核对收件人或稍后再试。');
        if (!$recipient || (int)$recipient->ID===$uid) { return $unavailable; }
        $receiver=(int)$recipient->ID; $lock=wuai_sluyu_lock('message_receive_'.$receiver);
        if (!$lock) { return $unavailable; }
        try {
            wp_cache_delete($receiver,'user_meta');
            if (!wuai_sluyu_message_can_contact($uid,$receiver)) { return $unavailable; }
            $rate=get_user_meta($uid,'_wuai_message_daily',true); $today=wp_date('Y-m-d');
            $sent_today=is_array($rate) && ($rate['date']??'')===$today?max(0,(int)($rate['count']??0)):0;
            if ($sent_today>=200) { return new WP_Error('message_limit','今天已发送 200 封私信，请明天再试。'); }
            $sent=$wpdb->insert($table,['sender_id'=>$uid,'recipient_id'=>$receiver,'kind'=>'message','body'=>$body,'created_at'=>current_time('mysql')],['%d','%d','%s','%s','%s']);
            if ($sent) { update_user_meta($uid,'_wuai_message_daily',['date'=>$today,'count'=>$sent_today+1]); }
            return $sent?['message'=>'私信已发送。','reload'=>true]:new WP_Error('storage','发送失败，请刷新页面核对后重试。');
        } finally { wuai_sluyu_unlock($lock); }
    }
    if ($operation==='read_messages') {
        $where=['recipient_id'=>$uid,'recipient_deleted'=>0];
        if ($id) { $where['sender_id']=$id; $where['kind']='message'; }
        else { $where['kind']=wuai_sluyu_input('task')==='private'?'message':'notice'; }
        $saved=$wpdb->update($table,['is_read'=>1],$where);
        return $saved!==false?['message'=>'已标记为已读。','reload'=>true]:new WP_Error('storage','标记失败，请稍后再试。');
    }
    if ($operation==='message_delete') {
        $visible='('.wuai_sluyu_message_where($uid).') OR ('.wuai_sluyu_message_where($uid,'notice').')';
        $message=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND ($visible)",$id));
        if (!$message) { return new WP_Error('message_missing','消息不可用或已经从你的列表删除。'); }
        $field=(int)$message->sender_id===$uid?'sender_deleted':'recipient_deleted';
        $saved=$wpdb->update($table,[$field=>1],['id'=>$id,$field=>0]);
        if ($saved===false) { return new WP_Error('storage','删除失败，请刷新页面核对。'); }
        wuai_sluyu_message_purge($uid);
        return ['message'=>'已从你的列表删除，不影响对方的消息副本。','reload'=>true];
    }
    if ($operation==='conversation_delete') {
        if (!$id || $id===$uid) { return new WP_Error('conversation','请选择有效的对话。'); }
        $where=wuai_sluyu_message_where($uid,'message',$id);
        $saved=$wpdb->query($wpdb->prepare("UPDATE $table SET sender_deleted=CASE WHEN sender_id=%d THEN 1 ELSE sender_deleted END, recipient_deleted=CASE WHEN recipient_id=%d THEN 1 ELSE recipient_deleted END WHERE $where",$uid,$uid));
        if ($saved===false) { return new WP_Error('storage','删除失败，请刷新页面核对。'); }
        wuai_sluyu_message_purge($uid,$id);
        return ['message'=>'现有对话已从你的列表删除；对方副本和此后新消息不受影响。','reload'=>true];
    }
    $lock=wuai_sluyu_lock('message_receive_'.$uid);
    if (!$lock) { return new WP_Error('message_busy','私信设置正在更新，请稍后再试。'); }
    try {
        wp_cache_delete($uid,'user_meta');
        if ($operation==='message_preferences') {
            $preference=wuai_sluyu_input('receive');
            if (!in_array($preference,['all','following','none'],true)) { return new WP_Error('preference','请选择有效的接收范围。'); }
            $saved=update_user_meta($uid,'_wuai_message_receive',$preference);
            if (!$saved && get_user_meta($uid,'_wuai_message_receive',true)!==$preference) { return new WP_Error('storage','设置未保存，请稍后再试。'); }
            return ['message'=>'接收设置已保存，系统审核与订单通知不受影响。','reload'=>true];
        }
        $decision=wuai_sluyu_input('decision');
        if (!$id || $id===$uid || !in_array($decision,['block','unblock'],true) || ($decision==='block' && !get_userdata($id))) { return new WP_Error('block','无法执行此操作，请核对用户。'); }
        $blocks=wuai_sluyu_message_blocks($uid);
        if ($decision==='block') {
            if (!in_array($id,$blocks,true)) {
                if (count($blocks)>=200) { return new WP_Error('block_limit','最多可屏蔽 200 位用户，请先清理列表。'); }
                $blocks[]=$id;
            }
        } else { $blocks=array_values(array_diff($blocks,[$id])); }
        $saved=update_user_meta($uid,'_wuai_message_blocks',$blocks);
        if (!$saved && wuai_sluyu_message_blocks($uid)!==$blocks) { return new WP_Error('storage','设置未保存，请稍后再试。'); }
        return ['message'=>$decision==='block'?'已屏蔽双方的新私信，历史消息不会删除。':'已解除私信屏蔽。','reload'=>true];
    } finally { wuai_sluyu_unlock($lock); }
},30,3);

function wuai_sluyu_message_settings_view($uid) {
    $preference=wuai_sluyu_message_preference($uid); $blocks=wuai_sluyu_message_blocks($uid);
    echo '<details class="sluyu-panel sluyu-message-settings"' . (wuai_sluyu_page_number('blocked_pg')>1?' open':'') . '><summary>私信接收设置与屏蔽名单</summary><p class="sluyu-muted">设置只影响新的用户私信，不关闭系统通知，也不会删除已有消息。</p>';
    wuai_sluyu_form_start('message_preferences'); echo '<label>允许谁给我发私信<select name="receive">';
    foreach (['all'=>'所有未被屏蔽的用户','following'=>'仅我关注的用户','none'=>'暂不接收用户私信'] as $value=>$label) { echo '<option value="' . $value . '" ' . selected($preference,$value,false) . '>' . esc_html($label) . '</option>'; }
    echo '</select></label>'; wuai_sluyu_form_end('保存接收设置');
    echo '<h3>已屏蔽（' . count($blocks) . '）</h3><div class="sluyu-compact-list">';
    foreach (array_slice($blocks,(wuai_sluyu_page_number('blocked_pg')-1)*20,20) as $blocked) {
        $person=get_userdata($blocked);
        echo '<div><span>' . esc_html($person?$person->display_name:'已注销用户') . '</span>';
        wuai_sluyu_action_button('message_block','解除屏蔽',['id'=>$blocked,'decision'=>'unblock']); echo '</div>';
    }
    if (!$blocks) { echo '<p class="sluyu-muted">暂无屏蔽用户。可在具体对话中屏蔽对方。</p>'; }
    echo '</div>'; wuai_sluyu_portal_pagination(ceil(count($blocks)/20),'directmessage','blocked_pg'); echo '</details>';
}

function wuai_sluyu_message_item_view($message,$uid,$kind) {
    $sender=get_userdata($message->sender_id); $own=(int)$message->sender_id===$uid;
    $name=$kind==='notice'?'系统通知':($own?'我':($sender?$sender->display_name:'已注销用户'));
    $state=$own?($message->is_read?'对方已读':'对方未读'):($message->is_read?'已读':'未读');
    echo '<article class="sluyu-message' . ($own?' is-own':'') . '"><header><strong>' . esc_html($name) . '</strong><span>' . esc_html($state . ' · ' . $message->created_at) . '</span></header><p>' . nl2br(esc_html($message->body)) . '</p><div class="sluyu-button-row">';
    wuai_sluyu_action_button('message_delete','从我的列表删除',['id'=>$message->id,'confirm'=>'仅删除你自己的消息副本，不会撤回对方的消息。确定删除？']);
    echo '</div></article>';
}

function wuai_sluyu_view_messages_extended($key) {
    if (!wuai_sluyu_require_login()) { return; }
    $uid=get_current_user_id(); $page=wuai_sluyu_page_number();
    if ($key==='message') {
        echo '<section class="sluyu-panel"><div class="sluyu-panel-heading"><h2>我的通知 <small>未读 ' . wuai_sluyu_message_unread($uid,'notice') . '</small></h2>';
        wuai_sluyu_action_button('read_messages','全部标记已读'); echo '</div>';
        $history=wuai_sluyu_message_history($uid,'notice',0,$page);
        foreach ($history['items'] as $message) { wuai_sluyu_message_item_view($message,$uid,'notice'); }
        if (!$history['items']) { wuai_sluyu_empty('暂无可见的系统通知。'); }
        wuai_sluyu_portal_pagination(ceil($history['total']/20),'message'); echo '</section>'; return;
    }
    $peer=absint(wuai_sluyu_query('peer')); $person=$peer?get_userdata($peer):false;
    if ($peer===$uid) { $person=false; }
    echo '<section class="sluyu-panel"><div class="sluyu-panel-heading"><h2>' . ($peer?'当前对话':'写一封新私信') . '</h2><a href="' . esc_url(wuai_sluyu_portal_url('directmessage')) . '">全部对话</a></div>';
    if ($peer && !$person) { echo '<p>此收件人当前不可用。</p>'; }
    else {
        wuai_sluyu_form_start('message');
        if ($person) { echo '<input type="hidden" name="id" value="' . absint($peer) . '"><p>收件人：' . esc_html($person->display_name) . '</p>'; }
        else { echo '<label>收件人用户名<input name="recipient" maxlength="60" autocomplete="off" required></label>'; }
        echo '<label>消息内容<textarea name="body" minlength="1" maxlength="2000" rows="4" required></textarea></label><p class="sluyu-muted">请勿发送密码、付款凭证或不必要的个人隐私。收件人可设置接收范围。</p>';
        wuai_sluyu_form_end('发送私信');
    }
    echo '</section>';
    if ($peer && $peer!==$uid) {
        echo '<section class="sluyu-panel"><div class="sluyu-panel-heading"><h2>往来私信</h2><span class="sluyu-state-pill">未读 ' . wuai_sluyu_message_unread($uid,'message',$peer) . '</span></div><div class="sluyu-button-row">';
        wuai_sluyu_action_button('read_messages','此对话标记已读',['id'=>$peer]);
        wuai_sluyu_action_button('conversation_delete','删除我的整个对话',['id'=>$peer,'confirm'=>'仅从你的列表删除现有对话。对方副本不受影响，也不会阻止新消息。确定删除？']);
        $blocked=in_array($peer,wuai_sluyu_message_blocks($uid),true);
        if ($person || $blocked) { wuai_sluyu_action_button('message_block',$blocked?'解除私信屏蔽':'屏蔽此用户私信',['id'=>$peer,'decision'=>$blocked?'unblock':'block','confirm'=>$blocked?'确认允许双方再次发送私信？':'屏蔽后双方不能发送新私信，历史消息保留。确定屏蔽？']); }
        echo '</div><p class="sluyu-muted">按最新消息优先排列；删除只影响你自己的列表。</p>';
        $history=wuai_sluyu_message_history($uid,'message',$peer,$page);
        foreach ($history['items'] as $message) { wuai_sluyu_message_item_view($message,$uid,'message'); }
        if (!$history['items']) { wuai_sluyu_empty('你与此收件人暂无可见消息。'); }
        wuai_sluyu_portal_pagination(ceil($history['total']/20),'directmessage'); echo '</section>';
    } else {
        $threads=wuai_sluyu_message_threads($uid,$page);
        echo '<section class="sluyu-panel"><div class="sluyu-panel-heading"><h2>往来私信 <small>' . $threads['total'] . ' 个对话</small></h2><span class="sluyu-state-pill">未读 ' . wuai_sluyu_message_unread($uid) . '</span></div><div class="sluyu-button-row">';
        wuai_sluyu_action_button('read_messages','全部私信标记已读',['task'=>'private']); echo '</div>';
        foreach ($threads['items'] as $message) {
            $other=get_userdata($message->peer_id); $name=$other?$other->display_name:'已注销用户';
            echo '<article class="sluyu-message sluyu-message-thread"><header><strong><a href="' . esc_url(wuai_sluyu_portal_url('directmessage',['peer'=>$message->peer_id])) . '">' . esc_html($name) . '</a></strong><span>' . ((int)$message->unread?'未读 '.(int)$message->unread.' · ':'') . esc_html($message->created_at) . '</span></header><p>' . ((int)$message->sender_id===$uid?'我：':'') . esc_html(mb_strimwidth($message->body,0,240,'…','UTF-8')) . '</p><a href="' . esc_url(wuai_sluyu_portal_url('directmessage',['peer'=>$message->peer_id])) . '">打开完整对话 →</a></article>';
        }
        if (!$threads['items']) { wuai_sluyu_empty('暂无可见的私信对话。'); }
        wuai_sluyu_portal_pagination(ceil($threads['total']/20),'directmessage'); echo '</section>';
    }
    wuai_sluyu_message_settings_view($uid);
}
