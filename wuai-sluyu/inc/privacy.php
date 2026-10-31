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
    foreach($events as $event) $data[]=['group_id'=>'wuai-events','group_label'=>'社区积分与互动','item_id'=>'wuai-event-'.$event->id,'data'=>[['name'=>'类型','value'=>$event->event_type],['name'=>'时间','value'=>$event->created_at],['name'=>'积分','value'=>$event->points],['name'=>'说明','value'=>$event->payload],['name'=>'内容 ID','value'=>$event->target_id]]];
    $messages=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.wuai_sluyu_table('messages').' WHERE sender_id=%d OR recipient_id=%d ORDER BY id LIMIT 100 OFFSET %d',$user->ID,$user->ID,$offset));
    foreach($messages as $message) $data[]=['group_id'=>'wuai-messages','group_label'=>'站内私信与通知','item_id'=>'wuai-message-'.$message->id,'data'=>[['name'=>'时间','value'=>$message->created_at],['name'=>'发件人 ID','value'=>$message->sender_id],['name'=>'收件人 ID','value'=>$message->recipient_id],['name'=>'内容','value'=>$message->body]]];
    return ['data'=>$data,'done'=>count($events)<100 && count($messages)<100];
}
add_filter('wp_privacy_personal_data_erasers', function ($erasers) {
    $erasers['wuai-community']=['eraser_friendly_name'=>'森鹿语社区记录','callback'=>'wuai_sluyu_erase_personal_data'];
    return $erasers;
});
function wuai_sluyu_erase_personal_data($email,$page=1) {
    $user=get_user_by('email',$email); if(!$user)return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
    global $wpdb;
    $removed=$wpdb->query($wpdb->prepare('DELETE FROM '.wuai_sluyu_table('messages').' WHERE sender_id=%d OR recipient_id=%d',$user->ID,$user->ID));
    $removed+=$wpdb->query($wpdb->prepare('DELETE FROM '.wuai_sluyu_table('events')." WHERE (user_id=%d AND event_type IN ('bookmark','follow','vote','news_vote')) OR (target_id=%d AND event_type='follow')",$user->ID,$user->ID));
    $retained=(bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.wuai_sluyu_table('events').' WHERE user_id=%d LIMIT 1',$user->ID));
    return ['items_removed'=>(bool)$removed,'items_retained'=>$retained,'messages'=>$retained?['积分与任务领取凭证暂保留以防重复领取；删除账号时一并删除。支付订单请使用 WooCommerce 的数据处理流程。']:[],'done'=>true];
}
