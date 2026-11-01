<?php
if (!defined('ABSPATH')) { exit; }

add_filter('wp_privacy_personal_data_exporters', function ($exporters) {
    $exporters['wuai-community']=['exporter_friendly_name'=>'森鹿语社区记录','callback'=>'wuai_sluyu_export_personal_data'];
    return $exporters;
});
function wuai_sluyu_export_personal_data($email,$page=1) {
    $user=get_user_by('email',$email); if(!$user)return ['data'=>[],'done'=>true];
    global $wpdb; $offset=(max(1,(int)$page)-1)*100; $data=[];
    $events=wuai_sluyu_events($user->ID,'',100,$offset);
    if($wpdb->last_error || !is_array($events))return new WP_Error('community_export','积分与互动凭证读取失败，请修复存储后重新导出。');
    foreach($events as $event) $data[]=['group_id'=>'wuai-events','group_label'=>'社区积分与互动','item_id'=>'wuai-event-'.$event->id,'data'=>[['name'=>'类型','value'=>$event->event_type],['name'=>'时间','value'=>$event->created_at],['name'=>'积分','value'=>$event->points],['name'=>'说明','value'=>$event->payload],['name'=>'内容 ID','value'=>$event->target_id]]];
    $messages=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.wuai_sluyu_table('messages').' WHERE (sender_id=%d AND sender_deleted=0) OR (recipient_id=%d AND recipient_deleted=0) ORDER BY id LIMIT 100 OFFSET %d',$user->ID,$user->ID,$offset));
    if($wpdb->last_error || !is_array($messages))return new WP_Error('community_export','私信记录读取失败，请修复存储后重新导出。');
    foreach($messages as $message) $data[]=['group_id'=>'wuai-messages','group_label'=>'站内私信与通知','item_id'=>'wuai-message-'.$message->id,'data'=>[['name'=>'时间','value'=>$message->created_at],['name'=>'发件人 ID','value'=>$message->sender_id],['name'=>'收件人 ID','value'=>$message->recipient_id],['name'=>'内容','value'=>$message->body]]];
    $memberships=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.wuai_sluyu_table('memberships').' WHERE user_id=%d ORDER BY id LIMIT 100 OFFSET %d',$user->ID,$offset));
    if($wpdb->last_error || !is_array($memberships))return new WP_Error('community_export','圈子记录读取失败，请修复存储后重新导出。');
    foreach($memberships as $membership) $data[]=['group_id'=>'wuai-circles','group_label'=>'圈子加入记录','item_id'=>'wuai-circle-'.$membership->id,'data'=>[['name'=>'圈子 ID','value'=>$membership->circle_id],['name'=>'状态','value'=>$membership->status],['name'=>'加入时间','value'=>$membership->created_at],['name'=>'更新时间','value'=>$membership->updated_at]]];
    if((int)$page===1) { $data[]=['group_id'=>'wuai-preferences','group_label'=>'社区偏好','item_id'=>'wuai-preferences-'.$user->ID,'data'=>[['name'=>'私信接收范围','value'=>wuai_sluyu_message_preference($user->ID)],['name'=>'屏蔽用户 ID','value'=>implode(', ',wuai_sluyu_message_blocks($user->ID))]]]; }
    return ['data'=>$data,'done'=>count($events)<100 && count($messages)<100 && count($memberships)<100];
}
add_filter('wp_privacy_personal_data_erasers', function ($erasers) {
    $erasers['wuai-community']=['eraser_friendly_name'=>'森鹿语社区记录','callback'=>'wuai_sluyu_erase_personal_data'];
    return $erasers;
});
function wuai_sluyu_erase_personal_data($email,$page=1) {
    $user=get_user_by('email',$email); if(!$user)return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
    global $wpdb;
    $lock=wuai_sluyu_lock('user_'.$user->ID);
    if(!$lock)return ['items_removed'=>false,'items_retained'=>true,'messages'=>['账号正在执行其他操作，请稍后重新执行社区数据清除。'],'done'=>true];
    try {
    $results=[];
    $results[]=$wpdb->query($wpdb->prepare('DELETE FROM '.wuai_sluyu_table('messages').' WHERE sender_id=%d OR recipient_id=%d',$user->ID,$user->ID));
    $results[]=$wpdb->query($wpdb->prepare('DELETE FROM '.wuai_sluyu_table('events')." WHERE points=0 AND ((user_id=%d AND event_type IN ('bookmark','follow','vote','news_vote')) OR (target_id=%d AND event_type='follow'))",$user->ID,$user->ID));
    $results[]=$wpdb->delete(wuai_sluyu_table('memberships'),['user_id'=>$user->ID]);
    $removed=array_sum($results); $failed=in_array(false,$results,true);
    foreach(['_wuai_message_receive','_wuai_message_blocks'] as $meta_key) { if(delete_user_meta($user->ID,$meta_key))$removed++; }
    $retained=(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.wuai_sluyu_table('events').' WHERE user_id=%d LIMIT 1',$user->ID));
    $failed=$failed || (bool)$wpdb->last_error;
    $allocation=function_exists('wuai_sluyu_bounty_erase')?wuai_sluyu_bounty_erase($email,$page):['items_retained'=>true];
    $retained=$retained || $allocation['items_retained'] || $failed;
    $messages=$retained?['积分、任务领取与解锁凭证暂保留，以防重复领取或扣款；账号删除经核验后清理本人事件。最少量数字悬赏分配凭证在账号或内容删除后仍保留，以保护其他账号余额，不删除提问者或获奖者数字身份。支付订单请使用 WooCommerce 数据处理流程。']:[];
    if($failed)$messages[]='部分社区数据未能核验清除，请修复存储后重试；不会把读取失败当成没有保留数据。';
    return ['items_removed'=>(bool)$removed,'items_retained'=>$retained,'messages'=>$messages,'done'=>true];
    } finally { wuai_sluyu_unlock($lock); }
}
