<?php
// Run only against a disposable local QA database, after the message-column migration.
if (PHP_SAPI!=='cli') { exit(1); }
$root=getenv('WP_QA_ROOT'); if (!$root) { throw new RuntimeException('WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST']='127.0.0.1:8771'; $_SERVER['REQUEST_URI']='/'; require $root.'/wp-load.php';
if (home_url()!=='http://127.0.0.1:8771') { throw new RuntimeException('Local QA only.'); }
function msg_check($condition,$message) { if (!$condition) { throw new RuntimeException($message); } }
function msg_action($uid,$operation,$data=[]) { wp_set_current_user($uid); $_POST=$data; return wuai_sluyu_community_operation($operation,$uid); }
function msg_latest($sender,$recipient) { global $wpdb; return (int)$wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM '.wuai_sluyu_table('messages')." WHERE sender_id=%d AND recipient_id=%d AND kind='message'",$sender,$recipient)); }
$users=[]; $table=wuai_sluyu_table('messages');
foreach (['a','b','c'] as $suffix) {
    $login='qa_messages_'.$suffix; $existing=get_user_by('login',$login); $id=$existing?$existing->ID:wp_create_user($login,'qa-local-only',$login.'@example.test');
    (new WP_User($id))->set_role('subscriber'); wp_update_user(['ID'=>$id,'display_name'=>'Message User '.strtoupper($suffix)]); $users[$suffix]=(int)$id;
    foreach (['_wuai_message_receive','_wuai_message_blocks','_wuai_message_daily','_wuai_banned_until'] as $key) { delete_user_meta($id,$key); }
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE sender_id=%d OR recipient_id=%d",$id,$id));
    $wpdb->delete(wuai_sluyu_table('events'),['user_id'=>$id]);
}
$a=$users['a']; $b=$users['b']; $c=$users['c'];
msg_check(wuai_sluyu_message_preference($b)==='all','Existing accounts default to all senders.');
msg_check(!is_wp_error(msg_action($a,'message',['recipient'=>'qa_messages_b','body'=>'PRIVATE-A-B-FIRST'])),'Username addressing sends a message.'); $first=msg_latest($a,$b);
msg_check(!is_wp_error(msg_action($b,'message',['id'=>$a,'body'=>'PRIVATE-B-A-REPLY'])),'Public user ID addressing sends a reply.'); $reply=msg_latest($b,$a);
msg_check(!is_wp_error(msg_action($c,'message',['id'=>$b,'body'=>'PRIVATE-C-B-ONLY'])),'Second sender starts separate conversation.'); $third=msg_latest($c,$b);
$threads=wuai_sluyu_message_threads($b); msg_check($threads['total']===2 && count($threads['items'])===2,'Inbox aggregates one item per peer.');
msg_check(wuai_sluyu_message_unread($b)===2,'Unread count includes incoming messages only.');
msg_check(wuai_sluyu_message_history($a,'message',$b)['total']===2,'Both directions visible to participants.');
msg_check(wuai_sluyu_message_history($c,'message',$a)['total']===0,'Unrelated pair cannot read other conversation.');
msg_check(is_wp_error(msg_action($c,'message_delete',['id'=>$first])),'Third party cannot delete message by ID.');
msg_action($c,'read_messages',['id'=>$a]); msg_check(wuai_sluyu_message_unread($b)===2,'Third party cannot change another inbox read state.');
msg_action($b,'read_messages',['id'=>$a]); msg_check(wuai_sluyu_message_unread($b)===1,'Peer read affects only selected thread.');
msg_check(!is_wp_error(msg_action($a,'message_delete',['id'=>$first])),'Sender can hide own copy.');
msg_check(wuai_sluyu_message_history($a,'message',$b)['total']===1,'Sender no longer sees deleted copy.');
msg_check(wuai_sluyu_message_history($b,'message',$a)['total']===2,'Recipient still sees sender-deleted message.');
msg_check(!is_wp_error(msg_action($b,'message_delete',['id'=>$first])),'Recipient can independently delete same message.');
msg_check(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id=%d",$first)),'Both-deleted content is physically purged.');
msg_check(!is_wp_error(msg_action($b,'conversation_delete',['id'=>$a])),'Conversation removal succeeds.');
msg_check(wuai_sluyu_message_history($b,'message',$a)['total']===0 && wuai_sluyu_message_history($a,'message',$b)['total']===1,'Conversation deletion is not a recall.');
msg_check(wuai_sluyu_message_threads($b)['total']===1,'Hidden conversation removed from aggregation.');
msg_check(!is_wp_error(msg_action($a,'message',['id'=>$b,'body'=>'PRIVATE-A-B-NEW'])),'New message after conversation deletion is delivered.');
msg_check(wuai_sluyu_message_threads($b)['total']===2 && wuai_sluyu_message_history($b,'message',$a)['total']===1,'New message does not restore deleted history.');
msg_check(!is_wp_error(msg_action($b,'message_block',['id'=>$a,'decision'=>'block'])),'Receiver blocks sender.');
$blocked=msg_action($a,'message',['id'=>$b,'body'=>'Should be blocked']);
$missing=msg_action($a,'message',['recipient'=>'qa_does_not_exist_abc','body'=>'Missing recipient']);
msg_check(is_wp_error($blocked) && is_wp_error($missing) && $blocked->get_error_message()===$missing->get_error_message(),'Unavailable recipients return the same generic failure.');
msg_check(is_wp_error(msg_action($b,'message',['id'=>$a,'body'=>'Cannot send while blocked'])),'Block is bidirectional.');
msg_check(!is_wp_error(msg_action($b,'message_block',['id'=>$a,'decision'=>'unblock'])),'Receiver can unblock.');
msg_check(!is_wp_error(msg_action($b,'message_preferences',['receive'=>'following'])),'Following-only preference accepted.');
msg_check(is_wp_error(msg_action($a,'message',['id'=>$b,'body'=>'Not followed'])),'Following-only rejects non-followed senders.');
wuai_sluyu_event($b,'follow:'.$a,'follow',0,$a);
msg_check(!is_wp_error(msg_action($a,'message',['id'=>$b,'body'=>'FOLLOWED-A-B'])),'Recipient follows sender: delivery allowed.');
msg_check(is_wp_error(msg_action($c,'message',['id'=>$b,'body'=>'Not followed C'])),'Following direction cannot be reversed.');
msg_action($b,'message_preferences',['receive'=>'none']);
msg_check(is_wp_error(msg_action($a,'message',['id'=>$b,'body'=>'Receiver paused'])),'None preference rejects even followed users.');
wuai_sluyu_notify($b,'SYSTEM-NOTICE-ALWAYS');
msg_check(wuai_sluyu_message_history($b,'notice')['total']===1,'System notices bypass user-message preferences.');
$notice=wuai_sluyu_message_history($b,'notice')['items'][0]->id;
msg_check(is_wp_error(msg_action($a,'message_delete',['id'=>$notice])),'Foreign system notice cannot be removed.');
msg_action($b,'read_messages'); msg_check(wuai_sluyu_message_unread($b,'notice')===0,'Default read operation still handles system notices.');
msg_action($b,'message_delete',['id'=>$notice]); msg_check(wuai_sluyu_message_history($b,'notice')['total']===0,'Recipient can remove system notice.');
msg_check(is_wp_error(msg_action($b,'message_preferences',['receive'=>['all']])),'Malformed preference rejected.');
msg_check(wuai_sluyu_message_preference($b)==='none','Rejected input does not change preference.');
msg_action($b,'message_preferences',['receive'=>'all']);
for ($i=0;$i<26;$i++) { $wpdb->insert($table,['sender_id'=>$a,'recipient_id'=>$b,'kind'=>'message','body'=>'PAGINATED-MESSAGE-'.$i,'created_at'=>current_time('mysql')]); }
$page1=wuai_sluyu_message_history($b,'message',$a,1); $page2=wuai_sluyu_message_history($b,'message',$a,2);
msg_check(count($page1['items'])===20 && count($page2['items'])===8 && $page1['total']===28,'Conversation page counts include only visible copies.');
msg_check(!array_intersect(wp_list_pluck($page1['items'],'id'),wp_list_pluck($page2['items'],'id')),'Message pages never overlap.');
$thread1=wuai_sluyu_message_threads($b,1,1); $thread2=wuai_sluyu_message_threads($b,2,1);
msg_check($thread1['total']===2 && $thread1['items'][0]->peer_id!==$thread2['items'][0]->peer_id,'Thread pagination uses conversations rather than raw messages.');
msg_action($b,'read_messages',['task'=>'private']); msg_check(wuai_sluyu_message_unread($b)===0,'All private conversations can be marked read.');
$_GET=['peer'=>$a]; wp_set_current_user($c); ob_start(); wuai_sluyu_view_messages_extended('directmessage'); $html=ob_get_clean();
msg_check(strpos($html,'PRIVATE-A-B-')===false && strpos($html,'PAGINATED-MESSAGE-')===false,'Rendered peer page cannot disclose third-party bodies.');
$_GET=[]; wp_set_current_user($b); ob_start(); wuai_sluyu_view_messages_extended('directmessage'); $html=ob_get_clean();
msg_check(strpos($html,'PAGINATED-MESSAGE-25')!==false && substr_count($html,'sluyu-message-thread')===2,'Inbox renders latest summary per conversation.');
$_GET=['peer'=>$a]; ob_start(); wuai_sluyu_view_messages_extended('directmessage'); $html=ob_get_clean();
msg_check(strpos($html,'qa_messages_a')===false,'Peer composer does not expose permanent login name.');
msg_check(wuai_sluyu_message_history(0)['total']===0 && wuai_sluyu_message_threads(0)['total']===0,'Anonymous helper calls disclose no message data.');
update_user_meta($a,'_wuai_message_daily',['date'=>wp_date('Y-m-d'),'count'=>200]);
msg_check(is_wp_error(msg_action($a,'message',['id'=>$b,'body'=>'Should hit daily cap'])),'Daily cap survives message deletion.');
update_user_meta($a,'_wuai_message_daily',['date'=>wp_date('Y-m-d',time()-DAY_IN_SECONDS),'count'=>200]);
msg_check(!is_wp_error(msg_action($a,'message',['id'=>$b,'body'=>'New day resets daily cap'])),'Daily counter rolls over in site timezone.');
echo "PASS: message threads/pagination, one-sided deletion, recipient preferences, symmetric blocks, system notices, unread scope, generic delivery failures and cross-account privacy.\n";
