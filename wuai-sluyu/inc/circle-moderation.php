<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_circle_review_key() { return '_wuai_circle_review'; }
function wuai_sluyu_circle_review_hold_key() { return '_wuai_circle_review_hold'; }
function wuai_sluyu_circle_review_held($id) { return get_post_type($id)==='sluyu_topic' && metadata_exists('post',$id,wuai_sluyu_circle_review_hold_key()); }
function wuai_sluyu_circle_review_option() { return 'wuai_sluyu_circle_review_settings'; }
function wuai_sluyu_circle_review_refresh_settings() {
    foreach([wuai_sluyu_circle_review_option(),'alloptions','notoptions'] as $key)wp_cache_delete($key,'options');
}
function wuai_sluyu_circle_review_settings() {
    $saved=get_option(wuai_sluyu_circle_review_option(),[]);
    return ['owner_publish'=>is_array($saved) && ($saved['owner_publish']??'')==='1'?'1':'0','revision'=>is_array($saved) && is_string($saved['revision']??null)?$saved['revision']:''];
}
function wuai_sluyu_circle_review_settings_stamp() { return hash_hmac('sha256',wp_json_encode(wuai_sluyu_circle_review_settings()),wp_salt('nonce')); }
function wuai_sluyu_circle_review_editor($uid=null) {
    $uid=$uid===null?get_current_user_id():absint($uid);
    return $uid && get_userdata($uid) && !wuai_sluyu_banned($uid) && user_can($uid,'edit_others_posts') && user_can($uid,'publish_posts') && user_can($uid,'manage_categories');
}
function wuai_sluyu_circle_review_can_manage($group,$uid=null) {
    $uid=$uid===null?get_current_user_id():absint($uid); $group=absint($group);
    return $uid && get_userdata($uid) && !wuai_sluyu_banned($uid) && wuai_sluyu_circle($group) && (wuai_sluyu_circle_review_editor($uid) || (int)get_term_meta($group,'_wuai_circle_owner',true)===$uid);
}
function wuai_sluyu_circle_review_groups($id) {
    $groups=wp_get_object_terms($id,'sluyu_circle',['fields'=>'ids','wuai_include_pending'=>true]);
    if(is_wp_error($groups))return $groups;
    $groups=array_values(array_unique(array_map('intval',$groups))); sort($groups,SORT_NUMERIC); return $groups;
}
function wuai_sluyu_circle_review_permission($id,$group,$uid=null) {
    $uid=$uid===null?get_current_user_id():absint($uid); $post=get_post($id); $group=absint($group);
    if(!$post || $post->post_type!=='sluyu_topic' || $post->post_password!=='' || !wuai_sluyu_circle_review_can_manage($group,$uid))return new WP_Error('circle_review_scope','话题不存在，或当前账号无权管理此圈子。');
    $groups=wuai_sluyu_circle_review_groups($id);
    if(is_wp_error($groups) || !$groups || !in_array($group,$groups,true))return new WP_Error('circle_review_scope','话题不存在，或当前账号无权管理此圈子。');
    foreach($groups as $circle)if(!wuai_sluyu_circle_review_can_manage($circle,$uid))return new WP_Error('circle_review_scope','话题不存在，或当前账号无权管理此圈子。');
    return true;
}
function wuai_sluyu_circle_review_private($id) {
    foreach(['_wuai_protected_content','_wuai_guess_config','_wuai_download_settings','_wuai_sluyu_download_url'] as $key)if(metadata_exists('post',$id,$key))return true;
    return get_post_meta($id,'_wuai_topic_kind',true)==='guess';
}
function wuai_sluyu_circle_review_poll_valid($id) {
    if(!wuai_sluyu_poll($id))return false;
    $choices=get_post_meta($id,'_wuai_poll_choices',true);
    if(array_keys($choices)!==range(0,count($choices)-1))return false;
    foreach($choices as $choice)if(!is_string($choice) || trim($choice)==='' || mb_strlen($choice)>100 || sanitize_text_field($choice)!==$choice)return false;
    if(count(array_unique($choices))!==count($choices))return false;
    $mode=get_post_meta($id,'_wuai_poll_mode',true); $mode=$mode===''?'single':$mode;
    if(!in_array($mode,['single','multiple','pk'],true) || ($mode==='pk' && count($choices)!==2))return false;
    $maximum=get_post_meta($id,'_wuai_poll_max',true);
    if($maximum!=='' && ((!is_int($maximum) && !is_string($maximum)) || !preg_match('/^[1-8]$/D',(string)$maximum) || (int)$maximum>count($choices) || ($mode!=='multiple' && (int)$maximum!==1)))return false;
    $deadline=get_post_meta($id,'_wuai_poll_deadline',true);
    if($deadline!=='' && ((!is_int($deadline) && !is_string($deadline)) || !preg_match('/^(?:0|[1-9][0-9]{0,10})$/D',(string)$deadline)))return false;
    $closed=get_post_meta($id,'_wuai_poll_closed',true);
    return $closed==='' || in_array($closed,['0','1',0,1],true);
}
function wuai_sluyu_circle_review_config_valid($id) {
    $kind=get_post_meta($id,'_wuai_topic_kind',true);
    if(!in_array($kind,['','topic','question','poll','guess'],true))return false;
    if($kind==='poll' && !wuai_sluyu_circle_review_poll_valid($id))return false;
    if(($kind==='guess' || metadata_exists('post',$id,'_wuai_guess_config')) && (!function_exists('wuai_sluyu_guess_config') || !wuai_sluyu_guess_config($id)))return false;
    if(metadata_exists('post',$id,'_wuai_protected_content') && (!wuai_sluyu_protected_supported($id) || !wuai_sluyu_protected_config($id)))return false;
    if((metadata_exists('post',$id,'_wuai_download_settings') || metadata_exists('post',$id,'_wuai_sluyu_download_url')) && !wuai_sluyu_download_config($id)) {
        $empty=get_post_meta($id,'_wuai_download_settings',true);
        // The native resource box persists an explicit disabled URL. Only its
        // exact safe shape is inactive; malformed/partially saved data is not.
        if(!is_array($empty) || count($empty)!==5 || ($empty['url']??null)!=='' || ($empty['password']??null)!=='' || !isset($empty['access'],$empty['cost'],$empty['version']) || !is_string($empty['access']) || !isset(wuai_sluyu_download_modes()[$empty['access']]) || $empty['access']==='points' || $empty['cost']!==0 || !is_string($empty['version']) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$empty['version']))return false;
    }
    return true;
}
function wuai_sluyu_circle_review_audit($id) {
    if(!metadata_exists('post',$id,wuai_sluyu_circle_review_key()))return [];
    $entries=get_post_meta($id,wuai_sluyu_circle_review_key(),true);
    if(!is_array($entries) || count($entries)>50 || ($entries && array_keys($entries)!==range(0,count($entries)-1)))return false;
    $seen=[]; $targets=['recommend'=>'pending','publish'=>'publish','reject'=>'draft','recover'=>'pending'];
    foreach($entries as $entry) {
        if(!is_array($entry) || !isset($entry['id'],$entry['actor'],$entry['group'],$entry['groups'],$entry['action'],$entry['reason'],$entry['phase'],$entry['from'],$entry['to'],$entry['request'],$entry['subject'],$entry['created']))return false;
        foreach(['id','action','reason','phase','from','to','request','subject','created'] as $key)if(!is_string($entry[$key]))return false;
        if(!is_int($entry['actor']) || $entry['actor']<1 || !is_int($entry['group']) || $entry['group']<1 || !is_array($entry['groups']) || !$entry['groups'] || !in_array($entry['phase'],['prepared','applied'],true))return false;
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$entry['id']) || isset($seen[$entry['id']]) || !isset($targets[$entry['action']]) || $targets[$entry['action']]!==$entry['to'])return false;
        if(!preg_match('/^[a-f0-9]{64}$/D',$entry['request']) || !preg_match('/^[a-f0-9]{64}$/D',$entry['subject']) || !wuai_sluyu_parse_date($entry['created'],'Y-m-d H:i:s') || mb_strlen($entry['reason'])>500 || sanitize_textarea_field($entry['reason'])!==$entry['reason'])return false;
        if(($entry['action']!=='recover' && $entry['from']!=='pending') || !get_post_status_object($entry['from']) || (in_array($entry['action'],['reject','recover'],true) && mb_strlen(trim($entry['reason']))<5))return false;
        $groups=$entry['groups']; foreach($groups as $circle)if(!is_int($circle) || $circle<1)return false;
        $sorted=array_values(array_unique($groups)); sort($sorted,SORT_NUMERIC);
        if($groups!==$sorted || !in_array($entry['group'],$groups,true))return false;
        $seen[$entry['id']]=true;
    }
    return $entries;
}
function wuai_sluyu_circle_review_subject($id) {
    $post=get_post($id); if(!$post)return '';
    $state=[$post->ID,$post->post_author,$post->post_title,$post->post_content,$post->post_excerpt,$post->post_password,wuai_sluyu_circle_review_settings()];
    foreach(['_wuai_topic_kind','_wuai_poll_choices','_wuai_poll_mode','_wuai_poll_max','_wuai_poll_deadline','_wuai_poll_closed','_wuai_protected_content','_wuai_guess_config','_wuai_download_settings','_wuai_sluyu_download_url','_wuai_sluyu_download_access','_wuai_sluyu_download_password','_wuai_sluyu_download_version','_wuai_sluyu_download_cost'] as $key)$state[]=[metadata_exists('post',$id,$key),get_post_meta($id,$key,true)];
    $groups=wuai_sluyu_circle_review_groups($id); if(is_wp_error($groups))return '';
    foreach($groups as $group)$state[]=[$group,wuai_sluyu_circle_status($group),get_term_meta($group,'_wuai_circle_owner',true),get_term_meta($group,'_wuai_circle_join',true),get_term_meta($group,'_wuai_circle_posting',true)];
    return hash_hmac('sha256',wp_json_encode($state),wp_salt('nonce'));
}
function wuai_sluyu_circle_review_stamp($id,$group,$uid=null) {
    $uid=$uid===null?get_current_user_id():absint($uid);
    if(wuai_sluyu_circle_review_permission($id,$group,$uid)!==true)return '';
    return hash_hmac('sha256',wp_json_encode([$uid,absint($group),get_post_status($id),wuai_sluyu_circle_review_subject($id),get_post_meta($id,wuai_sluyu_circle_review_key(),true),get_post_meta($id,wuai_sluyu_circle_review_hold_key(),true)]),wp_salt('nonce'));
}
function wuai_sluyu_circle_review_save_meta($id,$key,$value) {
    update_post_meta($id,$key,wp_slash($value)); wp_cache_delete($id,'post_meta');
    return get_post_meta($id,$key,true)===$value;
}
function wuai_sluyu_circle_review_set_status($id,$status) {
    $GLOBALS['wuai_sluyu_circle_review_transition'][$id]=true;
    try { $saved=wp_update_post(['ID'=>$id,'post_status'=>$status],true); clean_post_cache($id); return !is_wp_error($saved) && $saved && get_post_status($id)===$status; }
    finally { unset($GLOBALS['wuai_sluyu_circle_review_transition'][$id]); }
}
function wuai_sluyu_circle_review_final_matches($id,$group,$uid,$entry) {
    clean_post_cache($id); clean_user_cache($uid);
    if(get_post_status($id)!==$entry['to'] || wuai_sluyu_circle_review_permission($id,$group,$uid)!==true || !hash_equals($entry['subject'],wuai_sluyu_circle_review_subject($id)))return false;
    $author=(int)get_post_field('post_author',$id); clean_user_cache($author);
    return $entry['to']!=='publish' || (get_userdata($author) && !wuai_sluyu_banned($author) && wuai_sluyu_circle_review_config_valid($id));
}
function wuai_sluyu_circle_review_notify($id,$entry) {
    $uid=(int)get_post_field('post_author',$id); if(!$uid || !get_userdata($uid))return true;
    $receipt='_wuai_circle_review_notice_'.$entry['id'];
    if(get_post_meta($id,$receipt,true)==='1')return true;
    $labels=['recommend'=>'圈主已建议通过，仍等待站点最终审核','publish'=>'话题已审核发布','reject'=>'话题已退回草稿','recover'=>'异常审核已恢复为待审，尚未公开发布'];
    $text='话题 #'.absint($id).'：'.$labels[$entry['action']].($entry['reason']!==''?'。说明：'.$entry['reason']:'').' [审核记录 '.$entry['id'].']';
    global $wpdb; $table=wuai_sluyu_table('messages');
    // Retain a private delivery receipt even after the recipient deletes a notice.
    // The existing row also deduplicates retries when writing that receipt failed.
    $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE recipient_id=%d AND sender_id=0 AND kind='notice' AND body=%s LIMIT 1",$uid,$text));
    if(!$exists && !wuai_sluyu_notify($uid,$text))return false;
    return wuai_sluyu_circle_review_save_meta($id,$receipt,'1');
}
function wuai_sluyu_circle_review($id,$group,$decision,$reason,$stamp,$user_locked=false) {
    $uid=get_current_user_id(); $id=absint($id); $group=absint($group);
    if(!$uid || !get_userdata($uid) || wuai_sluyu_banned($uid))return new WP_Error('circle_review_auth','请使用正常状态的账号登录。');
    if(!in_array($decision,['approve','reject','recover'],true) || !is_string($reason) || !is_string($stamp) || !preg_match('/^[a-f0-9]{64}$/D',$stamp))return new WP_Error('circle_review_input','审核字段无效，请刷新页面。');
    $reason=sanitize_textarea_field($reason);
    if(mb_strlen($reason)>500 || (in_array($decision,['reject','recover'],true) && mb_strlen(trim($reason))<5))return new WP_Error('circle_review_reason','退回或恢复说明至少 5 字，审核说明最多 500 字。');
    $locks=[];
    if(!$user_locked) { $lock=wuai_sluyu_lock('user_'.$uid); if(!$lock)return new WP_Error('circle_review_busy','账号正在处理其他操作。'); $locks[]=$lock; }
    try {
        $lock=wuai_sluyu_lock('poll_'.$id); if(!$lock)return new WP_Error('circle_review_busy','话题正在更新，请刷新重试。'); $locks[]=$lock;
        clean_post_cache($id); $permission=wuai_sluyu_circle_review_permission($id,$group,$uid); if(is_wp_error($permission))return $permission;
        $groups=wuai_sluyu_circle_review_groups($id);
        foreach($groups as $circle) { $lock=wuai_sluyu_lock('circle_'.$circle); if(!$lock)return new WP_Error('circle_review_busy','所属圈子权限正在更新，请稍后重试。'); $locks[]=$lock; wp_cache_delete($circle,'term_meta'); }
        $lock=wuai_sluyu_lock('circle_review_settings'); if(!$lock)return new WP_Error('circle_review_busy','站点审核设置正在更新。'); $locks[]=$lock;
        wuai_sluyu_circle_review_refresh_settings();
        clean_post_cache($id); clean_user_cache($uid);
        if(wuai_sluyu_circle_review_groups($id)!==$groups || wuai_sluyu_circle_review_permission($id,$group,$uid)!==true)return new WP_Error('circle_review_changed','所属圈子或管理权限已变化，请刷新页面。');
        $post=get_post($id); $entries=wuai_sluyu_circle_review_audit($id); if($entries===false)return new WP_Error('circle_review_audit','审核记录异常，请站点管理员核对。');
        $last=$entries?end($entries):false; $resume=$last && $last['actor']===$uid && $last['group']===$group && hash_equals($last['request'],$stamp);
        if($decision==='recover' && !$resume) {
            if(!wuai_sluyu_circle_review_editor($uid) || !hash_equals(wuai_sluyu_circle_review_stamp($id,$group,$uid),$stamp) || (!metadata_exists('post',$id,wuai_sluyu_circle_review_hold_key()) && (!$last || $last['phase']==='applied')))return new WP_Error('circle_review_recovery','仅站点编辑可以恢复当前版本的未完成审核。');
            if(count($entries)>=50)return new WP_Error('circle_review_limit','审核记录已达安全上限，请站点管理员处理。');
            $entry=['id'=>wp_generate_uuid4(),'actor'=>$uid,'group'=>$group,'groups'=>$groups,'action'=>'recover','reason'=>$reason,'phase'=>'prepared','from'=>$post->post_status,'to'=>'pending','request'=>$stamp,'subject'=>wuai_sluyu_circle_review_subject($id),'created'=>current_time('mysql')];
            $entries[]=$entry;
            if(!wuai_sluyu_circle_review_save_meta($id,wuai_sluyu_circle_review_key(),$entries))return new WP_Error('circle_review_storage','恢复记录未保存，隔离保持不变。');
            $resume=true;
        }
        if($resume) {
            $entry=isset($entry)?$entry:$last;
            if(!hash_equals($entry['subject'],wuai_sluyu_circle_review_subject($id)) || ($decision==='reject')!==($entry['action']==='reject') || ($decision==='recover')!==($entry['action']==='recover') || $reason!==$entry['reason'] || !in_array($post->post_status,[$entry['from'],$entry['to']],true))return new WP_Error('circle_review_changed','内容、审核意见或状态已变化，不能重放旧决定。');
        } else {
            if($post->post_status!=='pending' || !hash_equals(wuai_sluyu_circle_review_stamp($id,$group,$uid),$stamp))return new WP_Error('circle_review_changed','仅能审核当前版本的待审话题，请刷新页面。');
            if(metadata_exists('post',$id,wuai_sluyu_circle_review_hold_key()) || ($last && $last['phase']!=='applied'))return new WP_Error('circle_review_recovery','上一项审核尚未完成，请原审核者重试或联系站点管理员核对。');
            if(count($entries)>=50)return new WP_Error('circle_review_limit','审核记录已达安全上限，请站点管理员处理。');
            $action=$decision==='reject'?'reject':'recommend';
            if($decision==='approve' && (wuai_sluyu_circle_review_editor($uid) || (wuai_sluyu_circle_review_settings()['owner_publish']==='1' && !wuai_sluyu_circle_review_private($id))))$action='publish';
            if($action==='publish' && (!get_userdata($post->post_author) || wuai_sluyu_banned($post->post_author) || !wuai_sluyu_circle_review_config_valid($id)))return new WP_Error('circle_review_content','作者状态或话题配置不能发布，请站点编辑核对。');
            if($action==='recommend' && $last && $last['action']==='recommend' && $last['actor']===$uid && $last['group']===$group && $last['reason']===$reason && hash_equals($last['subject'],wuai_sluyu_circle_review_subject($id))) {
                $notified=wuai_sluyu_circle_review_notify($id,$last);
                return ['message'=>$notified?'当前内容已有相同推荐意见，仍等待站点终审。':'推荐已经记录，作者通知尚未确认；可稍后重试。','reload'=>true];
            }
            $entry=['id'=>wp_generate_uuid4(),'actor'=>$uid,'group'=>$group,'groups'=>$groups,'action'=>$action,'reason'=>$reason,'phase'=>'prepared','from'=>'pending','to'=>['recommend'=>'pending','reject'=>'draft','publish'=>'publish'][$action],'request'=>$stamp,'subject'=>wuai_sluyu_circle_review_subject($id),'created'=>current_time('mysql')];
            // Quarantine first. A failed audit leaves a discoverable held item,
            // never an unheld prepared action that native editing can publish.
            if(!wuai_sluyu_circle_review_save_meta($id,wuai_sluyu_circle_review_hold_key(),$entry['id']))return new WP_Error('circle_review_storage','未能建立审核隔离标记，话题状态未更改。');
            $entries[]=$entry;
            if(!wuai_sluyu_circle_review_save_meta($id,wuai_sluyu_circle_review_key(),$entries))return new WP_Error('circle_review_storage','审核记录未保存，话题继续隔离，请站点编辑恢复为待审。');
        }
        if($entry['action']==='recover' && !wuai_sluyu_circle_review_editor($uid))return new WP_Error('circle_review_recovery','恢复审核需要站点编辑权限。');
        if($entry['action']==='publish' && (!(wuai_sluyu_circle_review_editor($uid) || (wuai_sluyu_circle_review_settings()['owner_publish']==='1' && !wuai_sluyu_circle_review_private($id))) || !get_userdata($post->post_author) || wuai_sluyu_banned($post->post_author) || !wuai_sluyu_circle_review_config_valid($id)))return new WP_Error('circle_review_content','审核者、作者状态或私有配置不能发布，请站点编辑核对。');
        $hold=get_post_meta($id,wuai_sluyu_circle_review_hold_key(),true);
        if($entry['phase']==='applied' && !metadata_exists('post',$id,wuai_sluyu_circle_review_hold_key()) && $post->post_status!==$entry['to'])return new WP_Error('circle_review_changed','已完成决定之后稿件状态发生变化，旧请求不能再次执行。');
        if($entry['phase']==='applied' && $post->post_status===$entry['to'] && !metadata_exists('post',$id,wuai_sluyu_circle_review_hold_key())) {
            $notified=wuai_sluyu_circle_review_notify($id,$entry);
            return ['message'=>$notified?'该审核决定已完成，不会重复执行或通知。':'审核已完成，作者通知尚未确认；可重试核验，不会重复操作。','reload'=>true];
        }
        // A verified recovery owns the existing hold, including when its previous
        // attempt saved the recovery record but could not replace the old token.
        if($hold!=='' && $hold!==$entry['id'] && $entry['action']!=='recover')return new WP_Error('circle_review_changed','有另一项未完成的审核，请联系站点管理员。');
        if(!wuai_sluyu_circle_review_save_meta($id,wuai_sluyu_circle_review_hold_key(),$entry['id']))return new WP_Error('circle_review_storage','未能建立审核隔离标记，话题状态未更改。');
        if(get_post_status($id)!==$entry['to'] && !wuai_sluyu_circle_review_set_status($id,$entry['to']))return new WP_Error('circle_review_storage','审核尚未完成，话题保持隔离，请保留当前表单重试。');
        if(!wuai_sluyu_circle_review_final_matches($id,$group,$uid,$entry)) {
            if($entry['to']==='publish')wuai_sluyu_circle_review_set_status($id,'pending');
            return new WP_Error('circle_review_changed','保存期间内容或权限发生变化，隔离保留，请站点编辑核对。');
        }
        $entry['phase']='applied'; $entries[count($entries)-1]=$entry;
        if(!wuai_sluyu_circle_review_save_meta($id,wuai_sluyu_circle_review_key(),$entries)) {
            if($entry['to']==='publish')wuai_sluyu_circle_review_set_status($id,'pending');
            return new WP_Error('circle_review_storage','审核完成记录未保存，话题继续隔离且不会通知通过。请重试或联系站点管理员。');
        }
        if(!wuai_sluyu_circle_review_final_matches($id,$group,$uid,$entry) || get_post_meta($id,wuai_sluyu_circle_review_hold_key(),true)!==$entry['id']) {
            wuai_sluyu_circle_review_save_meta($id,wuai_sluyu_circle_review_hold_key(),$entry['id']);
            if($entry['to']==='publish')wuai_sluyu_circle_review_set_status($id,'pending');
            return new WP_Error('circle_review_changed','最终核验未通过，话题继续隔离，请站点编辑核对。');
        }
        delete_post_meta($id,wuai_sluyu_circle_review_hold_key(),$entry['id']); wp_cache_delete($id,'post_meta');
        if(metadata_exists('post',$id,wuai_sluyu_circle_review_hold_key()))return new WP_Error('circle_review_storage','审核记录已保存，但公开隔离尚未解除；请使用原表单重试。');
        if(!wuai_sluyu_circle_review_final_matches($id,$group,$uid,$entry)) {
            wuai_sluyu_circle_review_save_meta($id,wuai_sluyu_circle_review_hold_key(),$entry['id']);
            if($entry['to']==='publish')wuai_sluyu_circle_review_set_status($id,'pending');
            return new WP_Error('circle_review_changed','解除隔离时内容发生变化，请站点编辑核对；未发送通过通知。');
        }
        $notified=wuai_sluyu_circle_review_notify($id,$entry);
        $messages=['recommend'=>'已建议通过，话题仍等待站点最终审核。','reject'=>'已退回草稿，作者可修改后重新提交。','publish'=>'已审核发布话题。','recover'=>'已安全恢复为待审状态，尚未公开发布。'];
        return ['message'=>$messages[$entry['action']].($notified?'':' 作者通知尚未确认，可安全重试核验。'),'reload'=>true];
    } finally { foreach(array_reverse($locks) as $lock)wuai_sluyu_unlock($lock); }
}

function wuai_sluyu_circle_feature_key($group) { return '_wuai_circle_feature_'.absint($group); }
function wuai_sluyu_circle_featured($id,$group) { return get_post_meta($id,wuai_sluyu_circle_feature_key($group),true)==='1'; }
function wuai_sluyu_circle_feature_permission($id,$group,$uid=null) {
    $uid=$uid===null?get_current_user_id():absint($uid);
    if(!wuai_sluyu_public_post($id,['sluyu_topic']) || metadata_exists('post',$id,wuai_sluyu_circle_review_hold_key()))return false;
    return wuai_sluyu_circle_review_permission($id,$group,$uid)===true && wuai_sluyu_circle_topic_participation($id,$uid)===true;
}
function wuai_sluyu_circle_feature_stamp($id,$group) { return hash_hmac('sha256',wp_json_encode([get_current_user_id(),absint($group),get_post_status($id),wuai_sluyu_circle_review_subject($id),get_post_meta($id,wuai_sluyu_circle_feature_key($group),true)]),wp_salt('nonce')); }
function wuai_sluyu_circle_feature($id,$group,$enabled,$stamp,$user_locked=false) {
    $uid=get_current_user_id(); $id=absint($id); $group=absint($group); $locks=[];
    if(!$uid || !get_userdata($uid) || wuai_sluyu_banned($uid))return new WP_Error('circle_feature_auth','请使用正常状态的账号登录。');
    if(!in_array($enabled,['1','0'],true) || !is_string($stamp) || !preg_match('/^[a-f0-9]{64}$/D',$stamp))return new WP_Error('circle_feature_input','精选字段无效。');
    try {
        if(!$user_locked) { $lock=wuai_sluyu_lock('user_'.$uid); if(!$lock)return new WP_Error('circle_feature_busy','账号正在处理其他操作。'); $locks[]=$lock; }
        $lock=wuai_sluyu_lock('poll_'.$id); if(!$lock)return new WP_Error('circle_feature_busy','话题正在更新。'); $locks[]=$lock;
        clean_post_cache($id); if(!wuai_sluyu_circle_feature_permission($id,$group,$uid))return new WP_Error('circle_feature_scope','只能管理已公开且所有所属圈子均有权限的话题。');
        $groups=wuai_sluyu_circle_review_groups($id);
        foreach($groups as $circle) { $lock=wuai_sluyu_lock('circle_'.$circle); if(!$lock)return new WP_Error('circle_feature_busy','圈子权限正在更新。'); $locks[]=$lock; wp_cache_delete($circle,'term_meta'); }
        clean_post_cache($id); clean_user_cache($uid);
        if(wuai_sluyu_circle_review_groups($id)!==$groups || !wuai_sluyu_circle_feature_permission($id,$group,$uid))return new WP_Error('circle_feature_scope','圈子或参与权限已变化。');
        if(get_post_meta($id,wuai_sluyu_circle_feature_key($group),true)===$enabled)return ['message'=>'精选状态未变化。','reload'=>true];
        if(!preg_match('/^[a-f0-9]{64}$/D',$stamp) || !hash_equals(wuai_sluyu_circle_feature_stamp($id,$group),$stamp))return new WP_Error('circle_feature_changed','话题已变化，请刷新后重试。');
        if(!wuai_sluyu_circle_review_save_meta($id,wuai_sluyu_circle_feature_key($group),$enabled))return new WP_Error('circle_feature_storage','精选状态未保存，请重试。');
        return ['message'=>$enabled==='1'?'已设为本圈精选。':'已取消本圈精选。','reload'=>true];
    } finally { foreach(array_reverse($locks) as $lock)wuai_sluyu_unlock($lock); }
}
add_filter('wuai_sluyu_community_dispatch',function($result,$operation,$uid){
    if($result!==null || !in_array($operation,['circle_review','circle_feature'],true))return $result;
    if(!$uid || (int)$uid!==get_current_user_id() || wuai_sluyu_banned($uid))return new WP_Error('circle_review_auth','请使用当前正常账号操作。');
    $id=wuai_sluyu_input('id'); $group=wuai_sluyu_input('group');
    if(!preg_match('/^[1-9][0-9]*$/D',$id) || !preg_match('/^[1-9][0-9]*$/D',$group))return new WP_Error('circle_review_input','请选择有效话题与圈子。');
    if($operation==='circle_feature')return wuai_sluyu_circle_feature($id,$group,wuai_sluyu_input('enabled'),wuai_sluyu_input('version'),true);
    return wuai_sluyu_circle_review($id,$group,wuai_sluyu_input('decision'),wuai_sluyu_input('reason',true),wuai_sluyu_input('version'),true);
},35,3);

function wuai_sluyu_circle_review_query_args($group,$uid=null,$page=1) {
    $uid=$uid===null?get_current_user_id():absint($uid); $group=absint($group);
    $args=['post_type'=>'sluyu_topic','post_status'=>['pending','draft','publish','private','future'],'has_password'=>false,'posts_per_page'=>12,'paged'=>max(1,min(100000,absint($page))),'orderby'=>['date'=>'DESC','ID'=>'DESC'],'ignore_sticky_posts'=>true,'suppress_filters'=>false];
    if($uid!==get_current_user_id() || !wuai_sluyu_circle_review_can_manage($group,$uid)) { $args['post__in']=[0]; return $args; }
    $terms=get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false,'fields'=>'ids','wuai_include_pending'=>true]); $allowed=[];
    if(!is_wp_error($terms))foreach($terms as $term)if(wuai_sluyu_circle_review_can_manage($term,$uid))$allowed[]=absint($term);
    if(!$allowed) { $args['post__in']=[0]; return $args; }
    $args['tax_query']=[['taxonomy'=>'sluyu_circle','terms'=>[$group],'include_children'=>false]];
    $args['wuai_circle_review_queue']=$allowed; $args['wuai_circle_review_actor']=$uid; $args['wuai_circle_review_group']=$group; $args['cache_results']=false;
    return $args;
}
function wuai_sluyu_circle_feed_query_args($group=0,$kind='',$sort='latest',$page=1) {
    $group=absint($group); $sort=in_array($sort,['latest','featured','likes','comments'],true)?$sort:'latest';
    $args=['post_type'=>'sluyu_topic','post_status'=>'publish','has_password'=>false,'posts_per_page'=>12,'paged'=>max(1,min(100000,absint($page))),'orderby'=>['date'=>'DESC','ID'=>'DESC'],'ignore_sticky_posts'=>true,'suppress_filters'=>false,'wuai_circle_feed_sort'=>$sort,'wuai_circle_feed_group'=>$group];
    if($group) { if(!wuai_sluyu_circle($group))$args['post__in']=[0]; else $args['tax_query']=[['taxonomy'=>'sluyu_circle','terms'=>[$group],'include_children'=>false]]; }
    if(in_array($kind,['topic','question','poll','guess'],true))$args['meta_query']=[['key'=>'_wuai_topic_kind','value'=>$kind]];
    if($sort==='comments')$args['orderby']=['comment_count'=>'DESC','date'=>'DESC','ID'=>'DESC'];
    if(in_array($sort,['likes','featured'],true))$args['cache_results']=false;
    if($sort==='featured' && !$group) {
        $public_groups=get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false,'fields'=>'ids']);
        $args['wuai_circle_feature_groups']=is_wp_error($public_groups)?[]:array_map('absint',$public_groups);
    }
    return $args;
}
add_filter('posts_clauses',function($clauses,$query){
    global $wpdb;
    $allowed=$query->get('wuai_circle_review_queue');
    if(is_array($allowed) && $allowed) {
        $actor=absint($query->get('wuai_circle_review_actor')); $group=absint($query->get('wuai_circle_review_group'));
        if(!$actor || $actor!==get_current_user_id() || !wuai_sluyu_circle_review_can_manage($group,$actor)) { $clauses['where'].=' AND 1=0'; return $clauses; }
        $allowed=array_filter(array_map('absint',$allowed),function($circle)use($actor){return wuai_sluyu_circle_review_can_manage($circle,$actor);});
        if(!$allowed) { $clauses['where'].=' AND 1=0'; return $clauses; }
        $ids=implode(',',$allowed);
        $clauses['where'].=" AND NOT EXISTS (SELECT 1 FROM $wpdb->term_relationships cr_rel INNER JOIN $wpdb->term_taxonomy cr_tax ON cr_tax.term_taxonomy_id=cr_rel.term_taxonomy_id WHERE cr_rel.object_id=$wpdb->posts.ID AND cr_tax.taxonomy='sluyu_circle' AND cr_tax.term_id NOT IN ($ids))";
        $clauses['where'].=$wpdb->prepare(" AND ($wpdb->posts.post_status='pending' OR EXISTS (SELECT 1 FROM $wpdb->postmeta cr_recovery WHERE cr_recovery.post_id=$wpdb->posts.ID AND cr_recovery.meta_key=%s))",wuai_sluyu_circle_review_hold_key());
    }
    $sort=$query->get('wuai_circle_feed_sort');
    if($sort==='likes')$clauses['orderby']="(SELECT COUNT(DISTINCT cr_likes.user_id) FROM $wpdb->usermeta cr_likes WHERE cr_likes.meta_key=CONCAT('_wuai_sluyu_like_', $wpdb->posts.ID) AND cr_likes.meta_value='1') DESC, $wpdb->posts.post_date DESC, $wpdb->posts.ID DESC";
    if($sort==='featured') {
        $group=absint($query->get('wuai_circle_feed_group'));
        if($group)$clauses['where'].=$wpdb->prepare(" AND EXISTS (SELECT 1 FROM $wpdb->postmeta cr_feature WHERE cr_feature.post_id=$wpdb->posts.ID AND cr_feature.meta_key=%s AND cr_feature.meta_value='1')",wuai_sluyu_circle_feature_key($group));
        else {
            $ids=array_map('absint',(array)$query->get('wuai_circle_feature_groups'));
            $clauses['where'].=$ids?" AND EXISTS (SELECT 1 FROM $wpdb->postmeta cr_feature INNER JOIN $wpdb->term_relationships cr_feature_rel ON cr_feature_rel.object_id=cr_feature.post_id INNER JOIN $wpdb->term_taxonomy cr_feature_tax ON cr_feature_tax.term_taxonomy_id=cr_feature_rel.term_taxonomy_id WHERE cr_feature.post_id=$wpdb->posts.ID AND cr_feature_tax.taxonomy='sluyu_circle' AND cr_feature_tax.term_id IN (".implode(',',$ids).") AND cr_feature.meta_key=CONCAT('_wuai_circle_feature_',cr_feature_tax.term_id) AND cr_feature.meta_value='1')":" AND 1=0";
        }
    }
    return $clauses;
},30,2);
// Query filtering also covers get_posts(), whose default suppress_filters would
// skip posts_clauses. Trusted plugins/DB access are outside this theme boundary.
add_action('pre_get_posts',function($query){
    if($query->get('wuai_circle_review_queue') || (is_admin() && !wp_doing_ajax() && wuai_sluyu_circle_review_editor()))return;
    $types=$query->get('post_type')?(array)$query->get('post_type'):[];
    if($types && !array_intersect($types,['sluyu_topic','any']))return;
    if(!$types && !$query->is_search() && !$query->is_tax() && !$query->get('tax_query') && !$query->get('taxonomy') && !$query->get('sluyu_topic') && !$query->get('p'))return;
    $private=['key'=>wuai_sluyu_circle_review_hold_key(),'compare'=>'NOT EXISTS'];
    $existing=$query->get('meta_query'); $query->set('meta_query',$existing?['relation'=>'AND',$existing,$private]:[$private]);
    $query->set('cache_results',false);
},50);
add_filter('get_the_excerpt',function($excerpt,$post){return $post instanceof WP_Post && $post->post_type==='sluyu_topic' && metadata_exists('post',$post->ID,wuai_sluyu_circle_review_hold_key())?'':$excerpt;},PHP_INT_MAX,2);
add_filter('the_content',function($content){$id=get_the_ID();return get_post_type($id)==='sluyu_topic' && metadata_exists('post',$id,wuai_sluyu_circle_review_hold_key())?'':$content;},PHP_INT_MAX);
function wuai_sluyu_circle_review_rest_hidden() { return new WP_REST_Response(['code'=>'rest_not_found','message'=>'话题暂不可查看。','data'=>['status'=>404]],404); }
function wuai_sluyu_circle_review_rest_editor($request) {
    return $request instanceof WP_REST_Request && $request->get_param('context')==='edit' && wuai_sluyu_circle_review_editor();
}
add_filter('rest_request_before_callbacks',function($response,$handler,$request){
    if($response!==null || !in_array($request->get_method(),['GET','HEAD'],true))return $response;
    if(wuai_sluyu_circle_review_rest_editor($request))return $response;
    $id=0;
    if(preg_match('~^/wp/v2/sluyu_topic/([0-9]+)(?:/|$)~',$request->get_route(),$match))$id=(int)$match[1];
    elseif(preg_match('~^/wp/v2/comments/([0-9]+)/?$~',$request->get_route(),$match)){$comment=get_comment((int)$match[1]);$id=$comment?(int)$comment->comment_post_ID:0;}
    return $id && wuai_sluyu_circle_review_held($id)?new WP_Error('rest_not_found','话题暂不可查看。',['status'=>404]):$response;
},60,3);
add_filter('rest_prepare_sluyu_topic',function($response,$post,$request){
    return wuai_sluyu_circle_review_held($post->ID) && !wuai_sluyu_circle_review_rest_editor($request)?wuai_sluyu_circle_review_rest_hidden():$response;
},100,3);
// oEmbed resolves ?p= IDs directly and does not pass through the public post query.
add_filter('oembed_request_post_id',function($id){return wuai_sluyu_circle_review_held($id)?0:$id;},100);
add_filter('is_post_embeddable',function($allowed,$post){return $post instanceof WP_Post && wuai_sluyu_circle_review_held($post->ID)?false:$allowed;},100,2);
add_filter('oembed_response_data',function($data,$post){return $post instanceof WP_Post && wuai_sluyu_circle_review_held($post->ID)?false:$data;},PHP_INT_MAX,2);
add_filter('embed_html',function($html,$post){return $post instanceof WP_Post && wuai_sluyu_circle_review_held($post->ID)?'':$html;},PHP_INT_MAX,2);
// Comments and adjacent links have their own SQL/cache paths, outside WP_Query.
function wuai_sluyu_circle_review_comment_where($where) {
    global $wpdb;
    return $where.$wpdb->prepare(" AND NOT EXISTS (SELECT 1 FROM $wpdb->postmeta cr_comment_hold INNER JOIN $wpdb->posts cr_comment_post ON cr_comment_post.ID=cr_comment_hold.post_id WHERE cr_comment_hold.post_id=$wpdb->comments.comment_post_ID AND cr_comment_post.post_type='sluyu_topic' AND cr_comment_hold.meta_key=%s)",wuai_sluyu_circle_review_hold_key());
}
add_action('pre_get_comments',function($query){
    // Core caches IDs before comments_clauses; public and native review must not share that cache.
    $domain=preg_replace('~\|wuai_circle_review:(?:editor|public)$~','',(string)$query->query_vars['cache_domain']);
    $trusted=is_admin() && !wp_doing_ajax() && wuai_sluyu_circle_review_editor();
    $query->query_vars['cache_domain']=$domain.'|wuai_circle_review:'.($trusted?'editor':'public');
},50);
add_filter('comments_clauses',function($clauses){
    if(is_admin() && !wp_doing_ajax() && wuai_sluyu_circle_review_editor())return $clauses;
    $clauses['where']=wuai_sluyu_circle_review_comment_where($clauses['where']); return $clauses;
},50);
add_filter('comment_feed_where','wuai_sluyu_circle_review_comment_where',50);
add_filter('rest_prepare_comment',function($response,$comment,$request){return wuai_sluyu_circle_review_held($comment->comment_post_ID) && !wuai_sluyu_circle_review_rest_editor($request)?wuai_sluyu_circle_review_rest_hidden():$response;},100,3);
function wuai_sluyu_circle_review_comment_text($text,$comment) {
    if(is_admin() && !wp_doing_ajax() && wuai_sluyu_circle_review_editor())return $text;
    return $comment instanceof WP_Comment && wuai_sluyu_circle_review_held($comment->comment_post_ID)?'':$text;
}
add_filter('get_comment_text','wuai_sluyu_circle_review_comment_text',PHP_INT_MAX,2);
add_filter('get_comment_excerpt',function($text,$id,$comment){return wuai_sluyu_circle_review_comment_text($text,$comment);},PHP_INT_MAX,3);
add_filter('get_comments_number',function($number,$id){return wuai_sluyu_circle_review_held($id)?0:$number;},100,2);
foreach(['previous','next'] as $adjacency) {
    add_filter('get_'.$adjacency.'_post_where',function($where){
        global $wpdb;
        return $where.$wpdb->prepare(" AND (p.post_type<>'sluyu_topic' OR NOT EXISTS (SELECT 1 FROM $wpdb->postmeta cr_neighbor_hold WHERE cr_neighbor_hold.post_id=p.ID AND cr_neighbor_hold.meta_key=%s))",wuai_sluyu_circle_review_hold_key());
    },50);
}
unset($adjacency);
foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $meta_hook) {
    add_action($meta_hook,function($meta_id,$id,$key){
        if($key!==wuai_sluyu_circle_review_hold_key() || get_post_type($id)!=='sluyu_topic')return;
        clean_post_cache($id); wp_cache_set_comments_last_changed();
    },50,3);
}
unset($meta_hook);
add_action('template_redirect',function(){
    if(wuai_sluyu_portal_key()==='circle-topics')nocache_headers();
    if(is_singular('sluyu_topic') && metadata_exists('post',get_queried_object_id(),wuai_sluyu_circle_review_hold_key())) { global $wp_query; $wp_query->set_404(); status_header(404); nocache_headers(); }
},1);
add_action('init',function(){
    foreach([wuai_sluyu_circle_review_key()=>'array',wuai_sluyu_circle_review_hold_key()=>'string'] as $key=>$type)register_post_meta('sluyu_topic',$key,['single'=>true,'type'=>$type,'show_in_rest'=>false,'auth_callback'=>function($allowed,$key,$id,$uid){return wuai_sluyu_circle_review_editor($uid) && user_can($uid,'edit_post',$id);}]);
},35);

function wuai_sluyu_circle_review_feature_form($id,$group) {
    if(!wuai_sluyu_circle_feature_permission($id,$group))return;
    wuai_sluyu_form_start('circle_feature');
    echo '<input type="hidden" name="id" value="'.absint($id).'"><input type="hidden" name="group" value="'.absint($group).'"><input type="hidden" name="version" value="'.esc_attr(wuai_sluyu_circle_feature_stamp($id,$group)).'"><input type="hidden" name="enabled" value="'.(wuai_sluyu_circle_featured($id,$group)?'0':'1').'">';
    wuai_sluyu_form_end(wuai_sluyu_circle_featured($id,$group)?'取消本圈精选':'设为本圈精选');
}
function wuai_sluyu_view_circle_topics() {
    if(!wuai_sluyu_require_login())return;
    $uid=get_current_user_id(); $group=absint(wuai_sluyu_query('group'));
    if(!wuai_sluyu_circle_review_can_manage($group,$uid)) { wuai_sluyu_empty('此圈子不存在或当前账号没有审核权限。'); return; }
    nocache_headers(); $circle=wuai_sluyu_circle($group);
    echo '<section class="sluyu-panel"><h2>'.esc_html($circle->name).' · 待审话题</h2><p>只展示你能管理全部所属圈子的待审稿件。公开正文在此供审核；隐藏正文、猜题答案和下载配置只由原作者与真正站点编辑在各自入口管理。</p><p>'.(wuai_sluyu_circle_review_settings()['owner_publish']==='1'?'站长已允许圈主发布普通稿件；含私有配置的内容仍须站点编辑终审。':'当前圈主仅提供推荐意见，所有通过决定仍需站点最终审核。').'</p><a href="'.esc_url(wuai_sluyu_portal_url('circle',['group'=>$group])).'">返回圈子</a></section>';
    $query=new WP_Query(wuai_sluyu_circle_review_query_args($group,$uid,wuai_sluyu_page_number()));
    foreach($query->posts as $post) {
        if(wuai_sluyu_circle_review_permission($post->ID,$group,$uid)!==true)continue;
        $entries=wuai_sluyu_circle_review_audit($post->ID); $last=$entries?end($entries):false;
        $held=wuai_sluyu_circle_review_held($post->ID);
        echo '<article class="sluyu-panel sluyu-circle-review-item"><h3>'.esc_html($post->post_title).'</h3><p class="sluyu-muted">稿件 #'.absint($post->ID).' · '.esc_html(get_the_author_meta('display_name',$post->post_author)).'</p><div class="sluyu-entry">'.wpautop(esc_html(wp_strip_all_tags($post->post_content))).'</div>';
        if(wuai_sluyu_circle_review_editor($uid) && user_can($uid,'edit_post',$post->ID))echo '<p><a href="'.esc_url(get_edit_post_link($post->ID,'raw')).'">进入后台核对完整配置与私有片段</a></p>';
        if(get_post_meta($post->ID,'_wuai_topic_kind',true)==='poll' && wuai_sluyu_circle_review_poll_valid($post->ID)) {
            $poll=wuai_sluyu_poll($post->ID); echo '<div class="sluyu-notice"><strong>公开投票选项</strong><ol>';
            foreach($poll['choices'] as $choice)echo '<li>'.esc_html($choice).'</li>';
            echo '</ol><p>最多选择 '.absint($poll['max']).' 项'.($poll['closed']?' · 已结束':'').'</p></div>';
        }
        if($last)echo '<p class="sluyu-notice">'.($held || $last['phase']!=='applied'?'上次操作尚待完成；请原审核者重试，其他用户不要重复处理。':'最近意见：'.esc_html(['recommend'=>'推荐站点审核','publish'=>'通过发布','reject'=>'退回修改','recover'=>'已恢复待审'][$last['action']]??'待核验')).($last['reason']!==''?' · '.esc_html($last['reason']):'').'</p>';
        $incomplete=$held || ($last && $last['phase']!=='applied');
        $can_retry=$incomplete && $last && $last['actor']===$uid && hash_equals($last['subject'],wuai_sluyu_circle_review_subject($post->ID));
        if($incomplete && $entries!==false && wuai_sluyu_circle_review_editor($uid)) {
            wuai_sluyu_form_start('circle_review'); echo '<input type="hidden" name="id" value="'.absint($post->ID).'"><input type="hidden" name="group" value="'.$group.'"><input type="hidden" name="version" value="'.esc_attr(wuai_sluyu_circle_review_stamp($post->ID,$group,$uid)).'"><input type="hidden" name="decision" value="recover"><label>异常恢复说明<textarea name="reason" minlength="5" maxlength="500" required></textarea></label>'; wuai_sluyu_form_end('恢复为待审（不发布）');
        }
        if($entries===false || ($incomplete && !$can_retry)) { echo '<p>请原审核者重试，或由站点编辑安全恢复待审。</p></article>'; continue; }
        $version=$can_retry?$last['request']:wuai_sluyu_circle_review_stamp($post->ID,$group,$uid);
        wuai_sluyu_form_start('circle_review');
        echo '<input type="hidden" name="id" value="'.absint($post->ID).'"><input type="hidden" name="group" value="'.$group.'"><input type="hidden" name="version" value="'.esc_attr($version).'">';
        if($can_retry)echo '<input type="hidden" name="decision" value="'.esc_attr($last['action']==='reject'?'reject':($last['action']==='recover'?'recover':'approve')).'"><p>重试原决定，不改变上次意见。</p>';
        else echo '<label>审核意见<select name="decision"><option value="approve">'.(wuai_sluyu_circle_review_editor($uid) || (wuai_sluyu_circle_review_settings()['owner_publish']==='1' && !wuai_sluyu_circle_review_private($post->ID))?'审核通过并发布':'推荐站点终审').'</option><option value="reject">退回修改</option></select></label>';
        echo '<label>说明（退回必填 5–500 字）<textarea name="reason" maxlength="500" rows="3"'.($can_retry?' readonly':'').'>'.esc_textarea($can_retry?$last['reason']:'').'</textarea></label>';
        wuai_sluyu_form_end($can_retry?'重试未完成审核':'提交审核意见'); echo '</article>';
    }
    if(!$query->post_count)wuai_sluyu_empty('暂无可由你审核的待审话题。');
    wuai_sluyu_portal_pagination($query->max_num_pages,'circle-topics','pg',['group'=>$group]);
}
function wuai_sluyu_circle_review_settings_save($input) {
    $uid=get_current_user_id();
    if(!$uid || !current_user_can('manage_options') || wuai_sluyu_banned($uid))return new WP_Error('circle_review_settings_auth','只有站长可以改变审核授权。');
    if(!is_array($input) || !isset($input['owner_publish'],$input['stamp']) || !in_array($input['owner_publish'],['0','1'],true) || !is_string($input['stamp']))return new WP_Error('circle_review_settings_input','审核设置无效。');
    $lock=wuai_sluyu_lock('circle_review_settings'); if(!$lock)return new WP_Error('circle_review_settings_busy','审核授权正在使用，请稍后再试。');
    try {
        wuai_sluyu_circle_review_refresh_settings(); clean_user_cache($uid);
        if(!user_can($uid,'manage_options') || wuai_sluyu_banned($uid))return new WP_Error('circle_review_settings_auth','审核设置权限已变化。');
        if(!hash_equals(wuai_sluyu_circle_review_settings_stamp(),$input['stamp']))return new WP_Error('circle_review_settings_changed','设置已变化，请刷新后重试。');
        $saved=['owner_publish'=>$input['owner_publish'],'revision'=>wp_generate_uuid4()]; update_option(wuai_sluyu_circle_review_option(),$saved,false);
        wuai_sluyu_circle_review_refresh_settings();
        return get_option(wuai_sluyu_circle_review_option())===$saved?true:new WP_Error('circle_review_settings_storage','授权未保存，原设置继续生效。');
    } finally { wuai_sluyu_unlock($lock); }
}
add_action('admin_menu',function(){add_submenu_page('wuai-sluyu','圈子审核授权','圈子审核授权','manage_options','wuai-circle-review-settings','wuai_sluyu_circle_review_settings_page');},35);
function wuai_sluyu_circle_review_settings_page() {
    if(!current_user_can('manage_options'))return;
    echo '<div class="wrap"><h1>圈子审核授权</h1><p>默认所有稿件由站点最终审核。开启后，圈主只能审核自己管理的圈子；多圈话题必须同时管理全部所属圈子。不会改变任何 WordPress 用户角色或授予后台编辑能力。隐藏正文、猜题答案和下载配置始终要求真正站点编辑终审。</p>';
    $notice=get_transient('wuai_circle_review_settings_notice_'.get_current_user_id());
    if($notice) { delete_transient('wuai_circle_review_settings_notice_'.get_current_user_id()); echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>'; }
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wuai_circle_review_settings">'; wp_nonce_field('wuai_circle_review_settings');
    echo '<input type="hidden" name="stamp" value="'.esc_attr(wuai_sluyu_circle_review_settings_stamp()).'"><input type="hidden" name="owner_publish" value="0"><p><label><input type="checkbox" name="owner_publish" value="1" '.checked(wuai_sluyu_circle_review_settings()['owner_publish'],'1',false).'> 我明确授权圈主发布其权限范围内的普通待审话题，理解站点仍承担内容审核责任。</label></p>'; submit_button('保存审核授权'); echo '</form></div>';
}
add_action('admin_post_wuai_circle_review_settings',function(){
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST' || !current_user_can('manage_options'))wp_die('无权操作。','',['response'=>403]);
    check_admin_referer('wuai_circle_review_settings');
    $result=wuai_sluyu_circle_review_settings_save(['owner_publish'=>wuai_sluyu_input('owner_publish'),'stamp'=>wuai_sluyu_input('stamp')]);
    set_transient('wuai_circle_review_settings_notice_'.get_current_user_id(),is_wp_error($result)?$result->get_error_message():'审核授权已保存。',60);
    wp_safe_redirect(admin_url('admin.php?page=wuai-circle-review-settings')); exit;
});
add_action('add_meta_boxes',function(){if(wuai_sluyu_circle_review_editor())add_meta_box('wuai_circle_review_audit','圈主审核记录','wuai_sluyu_circle_review_audit_box','sluyu_topic','normal');});
function wuai_sluyu_circle_review_audit_box($post) {
    if(!wuai_sluyu_circle_review_editor())return;
    $entries=wuai_sluyu_circle_review_audit($post->ID);
    if($entries===false) { echo '<p>审核记录格式异常，请核对数据库。</p>'; return; }
    if(metadata_exists('post',$post->ID,wuai_sluyu_circle_review_hold_key())) {
        echo '<p><strong>审核操作尚未完成：此话题被隔离，不对公众开放。原生“发布”按钮不会解除隔离。</strong></p><p>恢复前请先清除文章密码，并至少关联一个已开放圈子；确认当前账号可管理全部所属圈子，再从圈主审核页选择“恢复为待审”。请勿直接删除隔离元数据。</p>';
        $groups=wuai_sluyu_circle_review_groups($post->ID);
        if(!is_wp_error($groups))foreach($groups as $group)if(wuai_sluyu_circle_review_permission($post->ID,$group)===true) { echo '<p><a href="'.esc_url(wuai_sluyu_portal_url('circle-topics',['group'=>$group])).'">前往话题审核与安全恢复</a></p>'; break; }
    }
    if(!$entries)echo '<p>尚无圈主审核记录。</p>';
    foreach(array_reverse($entries) as $entry)echo '<p><code>'.esc_html($entry['id']).'</code> · '.esc_html($entry['created']).' · 用户 #'.absint($entry['actor']).' · 圈子 #'.absint($entry['group']).' · '.esc_html($entry['action']).' / '.esc_html($entry['phase']).'<br>'.nl2br(esc_html($entry['reason'])).'</p>';
}
