<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_membership_raw($uid,$key) {
    global $wpdb;
    $row=$wpdb->get_row($wpdb->prepare("SELECT meta_value FROM $wpdb->usermeta WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id DESC LIMIT 1",$uid,$key));
    return ['exists'=>(bool)$row,'value'=>$row?maybe_unserialize($row->meta_value):null];
}
function wuai_sluyu_membership_pending_users($order_id,$write=null) {
    global $wpdb;$name='wuai_membership_pending_users_'.absint($order_id);
    if($write!==null){update_option($name,array_values($write),false);wp_cache_delete($name,'options');}
    $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s",$name));
    if($raw===null)return [];$value=maybe_unserialize($raw);
    if(!is_array($value) || count($value)>100)return new WP_Error('membership_pending_users','会员同步账号清单损坏，需管理员核对。');
    $ids=[];foreach($value as$id){if(!is_scalar($id) || !ctype_digit((string)$id) || (int)$id<1)return new WP_Error('membership_pending_users','会员同步账号清单无效。');$ids[]=absint($id);}sort($ids,SORT_NUMERIC);
    return array_values(array_unique($ids));
}
function wuai_sluyu_membership_pending($uid) {
    global $wpdb;
    $key=$wpdb->esc_like('wuai_membership_pending_users_').'%';
    // The option is an independent durable checkpoint, so a failed user-meta
    // dirty-marker write still blocks access until reconciliation completes.
    $rows=$wpdb->get_col($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name LIKE %s AND option_value LIKE %s",$key,'%'.$wpdb->esc_like(';i:'.absint($uid).';').'%'));
    foreach($rows as$raw){$ids=maybe_unserialize($raw);if(is_array($ids) && in_array(absint($uid),$ids,true))return true;}
    return false;
}

function wuai_sluyu_membership_recalculate($grants) {
    if (!is_array($grants) || count($grants)>5000) { return new WP_Error('membership_state','会员授权记录格式不正确，请管理员核对。'); }
    $normalized=[]; $total=0;
    foreach ($grants as $order_id=>$grant) {
        if (!ctype_digit((string)$order_id) || (int)$order_id<1 || !is_array($grant) || !isset($grant['at'],$grant['days']) || !is_scalar($grant['at']) || !is_scalar($grant['days']) || !ctype_digit((string)$grant['at']) || (int)$grant['at']<1 || (int)$grant['at']>time()+300 || !preg_match('/^(?:-1|[1-9][0-9]*)$/',(string)$grant['days'])) { return new WP_Error('membership_state','会员授权含有无效日期或天数，请管理员核对。'); }
        $days=(int)$grant['days']; if ($days>3650000) { return new WP_Error('membership_range','会员累计天数超出系统上限，请管理员核对。'); }
        if ($days>0) { $total+=$days; if ($total>3650000) { return new WP_Error('membership_range','会员累计天数超出系统上限，请管理员核对。'); } }
        $normalized[(int)$order_id]=['at'=>(int)$grant['at'],'days'=>$days];
    }
    uksort($normalized,function($a,$b)use($normalized){$compare=$normalized[$a]['at']<=>$normalized[$b]['at'];return $compare?:($a<=>$b);});
    $until=0;
    foreach ($normalized as $grant) { if($grant['days']===-1){$until=-1;break;}$until=max($until,$grant['at'])+$grant['days']*DAY_IN_SECONDS; }
    return ['grants'=>$normalized,'until'=>$until];
}

function wuai_sluyu_membership_state($uid) {
    $raw=wuai_sluyu_membership_raw($uid,'_wuai_membership_state');
    if ($raw['exists']) {
        $state=$raw['value'];
        if (!is_array($state) || !isset($state['grants'],$state['until'])) { return new WP_Error('membership_state','会员授权数据损坏，已暂停访问并等待核对。'); }
        $checked=wuai_sluyu_membership_recalculate($state['grants']);
        if (is_wp_error($checked) || !is_scalar($state['until']) || (string)$checked['until']!==(string)$state['until']) { return new WP_Error('membership_state','会员授权数据不一致，已暂停访问并等待核对。'); }
        return $checked;
    }
    $legacy=wuai_sluyu_membership_raw($uid,'_wuai_membership_grants');
    return wuai_sluyu_membership_recalculate($legacy['exists']?$legacy['value']:[]);
}

function wuai_sluyu_membership_until($uid=0) {
    $uid=$uid?:get_current_user_id(); if(!$uid)return 0;
    if(wuai_sluyu_membership_pending($uid))return 0;
    $dirty=wuai_sluyu_membership_raw($uid,'_wuai_membership_sync');
    if ($dirty['exists'] && $dirty['value']) { return 0; }
    $raw=wuai_sluyu_membership_raw($uid,'_wuai_membership_state');
    if ($raw['exists']) { $state=wuai_sluyu_membership_state($uid);return is_wp_error($state)?0:$state['until']; }
    $legacy=wuai_sluyu_membership_raw($uid,'_wuai_member_until');
    return $legacy['exists'] && is_scalar($legacy['value']) && preg_match('/^(?:-1|[0-9]+)$/',(string)$legacy['value'])?(int)$legacy['value']:0;
}

add_filter('get_user_metadata',function($value,$uid,$key,$single){
    if($value!==null || !in_array($key,['_wuai_member_until','_wuai_membership_grants'],true))return $value;
    $raw=wuai_sluyu_membership_raw($uid,'_wuai_membership_state');
    $dirty=wuai_sluyu_membership_raw($uid,'_wuai_membership_sync');
    if(!$raw['exists'] && !($dirty['exists'] && $dirty['value']) && !wuai_sluyu_membership_pending($uid))return $value;
    if($key==='_wuai_member_until')$mapped=wuai_sluyu_membership_until($uid);
    else{$state=wuai_sluyu_membership_state($uid);$mapped=is_wp_error($state)?[]:$state['grants'];}
    // WordPress unwraps array filter results for single-value metadata reads.
    return [$mapped];
},10,4);

function wuai_sluyu_membership_write($uid,$key,$value) {
    wp_cache_delete($uid,'user_meta'); update_user_meta($uid,$key,$value);
    $stored=wuai_sluyu_membership_raw($uid,$key);
    return $stored['exists'] && (is_array($value)?$stored['value']===$value:(string)$stored['value']===(string)$value);
}
function wuai_sluyu_membership_mark($uid,$order_id,$remove=false) {
    $raw=wuai_sluyu_membership_raw($uid,'_wuai_membership_sync');
    if ($raw['exists'] && !is_array($raw['value'])) { return false; }
    $pending=$raw['exists']?$raw['value']:[];
    if($remove)unset($pending[$order_id]);else $pending[$order_id]=1;
    return wuai_sluyu_membership_write($uid,'_wuai_membership_sync',$pending);
}
function wuai_sluyu_membership_commit($uid,$grants) {
    $state=wuai_sluyu_membership_recalculate($grants); if(is_wp_error($state))return $state;
    return wuai_sluyu_membership_write($uid,'_wuai_membership_state',$state)?true:new WP_Error('membership_storage','会员授权状态未完整写入，访问已暂停，系统将重试。');
}

function wuai_sluyu_membership_retry($order_id,$error,$order=null,$uid_hint=0) {
    $args=[absint($order_id)];
    if(!wp_next_scheduled('wuai_sluyu_retry_membership',$args))wp_schedule_single_event(time()+30,'wuai_sluyu_retry_membership',$args);
    $code=is_wp_error($error)?$error->get_error_code():'membership_retry';
    error_log('Wuai membership sync deferred: order='.absint($order_id).' code='.sanitize_key($code));
    if($uid_hint && !get_option('wuai_membership_retry_owner_'.absint($order_id)))add_option('wuai_membership_retry_owner_'.absint($order_id),absint($uid_hint),'','no');
    if($order && $order instanceof WC_Order){
        $notice='wuai_membership_issue_'.absint($order_id);
        if(!get_option($notice)){
            $message='吾爱会员同步未完成（'.sanitize_key($code).'）。系统已安排重试；请核对会员状态，勿重复扣款。';
            if(is_wp_error($error))$message.=' '.$error->get_error_message();
            if($order->add_order_note($message))add_option($notice,1,'','no');
        }
    }
    return is_wp_error($error)?$error:new WP_Error($code,'会员授权稍后重试。');
}

function wuai_sluyu_membership_fresh_order($order_id,$refund=false) {
    if(!function_exists('wc_get_order'))return false;
    $order_id=absint($order_id); clean_post_cache($order_id); wp_cache_delete('order-items-'.$order_id,'orders');
    foreach(['total_refunded','refund_ids']as$key)wp_cache_delete(WC_Cache_Helper::get_cache_prefix('orders').$key.$order_id,'orders');
    if(class_exists('Automattic\WooCommerce\Caches\OrderCache')){wc_get_container()->get('Automattic\WooCommerce\Caches\OrderCache')->remove($order_id);}
    $store=WC_Data_Store::load($refund?'order-refund':'order');
    if(method_exists($store->get_current_class_name(),'clear_cached_data'))$store->clear_cached_data([$order_id]);
    $order=wc_get_order($order_id); if(!$order || ($refund?!$order instanceof WC_Order_Refund:!$order instanceof WC_Order))return false;
    $order->read_meta_data(true);
    global $wpdb;
    $items=$wpdb->get_col($wpdb->prepare('SELECT order_item_id FROM '.$wpdb->prefix.'woocommerce_order_items WHERE order_id=%d',$order_id));
    foreach($items as $item_id){wp_cache_delete('item-'.$item_id,'order-items');wp_cache_delete($item_id,'order_item_meta');wp_cache_delete(WC_Data::generate_meta_cache_key($item_id,'order-items'),'order-items');}
    return $order;
}

function wuai_sluyu_membership_legacy_owners($order_id) {
    global $wpdb;
    $needle='%'.$wpdb->esc_like('i:'.absint($order_id).';a:').'%';
    $rows=$wpdb->get_results($wpdb->prepare("SELECT user_id,meta_key,meta_value FROM $wpdb->usermeta WHERE meta_key IN ('_wuai_membership_state','_wuai_membership_grants') AND meta_value LIKE %s LIMIT 101",$needle));
    if(count($rows)>100)return new WP_Error('membership_legacy_owners','历史授权归属过多，需管理员核对后迁移。');
    $owners=[];
    foreach($rows as$row){
        $current=wuai_sluyu_membership_raw($row->user_id,'_wuai_membership_state');
        $value=$current['exists']?$current['value']:maybe_unserialize($row->meta_value);
        $grants=$current['exists'] && is_array($value)?($value['grants']??[]):$value;
        if(is_array($grants) && isset($grants[$order_id]))$owners[]=absint($row->user_id);
    }
    return array_values(array_unique($owners));
}

function wuai_sluyu_membership_has_refund($order) {
    $valid=function($number){return (is_int($number) || is_float($number) || is_string($number)) && is_numeric($number) && is_finite((float)$number);};
    try{
        $aggregate=$order->get_total_refunded();
        if(!$valid($aggregate) || (float)$aggregate<0)return new WP_Error('membership_refund','退款金额数据异常，请核对订单后重试。');
        if((float)$aggregate>0)return true;
    // HPOS totals sum each refund's negative total, while CPT sums its amount.
    // Direct refund saves can set amount before total; either persisted signal
    // must revoke access instead of trusting only the aggregate cached total.
        foreach($order->get_refunds()as$listed){
            if(!$listed instanceof WC_Order_Refund)return new WP_Error('membership_refund','退款记录格式异常，请核对订单后重试。');
            $refund=wuai_sluyu_membership_fresh_order($listed->get_id(),true);
            if(!$refund || (int)$refund->get_parent_id()!==(int)$order->get_id())return new WP_Error('membership_refund','退款记录发生变化，将重新核对。');
            $amount=$refund->get_amount('edit');$total=$refund->get_total('edit');
            if(!$valid($amount) || !$valid($total) || (float)$amount<0 || (float)$total>0)return new WP_Error('membership_refund','退款金额数据异常，请核对订单后重试。');
            if((float)$amount>0 || (float)$total<0)return true;
        }
        return false;
    }catch(Throwable$error){return new WP_Error('membership_refund','无法完整读取退款记录，将重新核对。');}
}
function wuai_sluyu_membership_order_grant($order,$existing=false) {
    if(!$order->is_paid())return false;
    $refunded=wuai_sluyu_membership_has_refund($order);if(is_wp_error($refunded))return $refunded;if($refunded)return false;
    $days=0;$lifetime=false;
    foreach($order->get_items('line_item')as$item){
        $duration=$item->get_meta('_wuai_membership_days',true,'edit');
        if($duration==='' || $duration==='0' || $duration===0)continue;
        $quantity=$item->get_quantity('edit');
        if(!is_scalar($duration) || !preg_match('/^(?:-1|[1-9][0-9]{0,4})$/',(string)$duration) || (int)$duration>36500 || !is_scalar($quantity) || !preg_match('/^[1-9][0-9]{0,3}$/',(string)$quantity) || (int)$quantity>1000){return new WP_Error('membership_item','会员订单行须为正整数数量 1–1000，天数为 -1 或 1–36500；零数量、负数或畸形配置不会授权。');}
        if((int)$duration===-1)$lifetime=true;else{$days+=(int)$duration*(int)$quantity;if($days>3650000)return new WP_Error('membership_range','此订单会员累计天数超过 3650000 天，须人工核对。');}
    }
    if(!$days && !$lifetime)return false;
    $paid=$order->get_date_paid('edit');
    $at=$paid?$paid->getTimestamp():($existing && isset($existing['at'])?(int)$existing['at']:($order->get_date_created('edit')?$order->get_date_created('edit')->getTimestamp():0));
    if($at<1 || $at>time()+300)return new WP_Error('membership_paid_at','付款日期缺失且无稳定创建日期，或日期在未来；请先修正订单，不会按重试时间反复续期。');
    return ['at'=>$at,'days'=>$lifetime?-1:$days];
}

function wuai_sluyu_membership_sync($order_id,$revoked=false) {
    static $running=[];
    $order_id=absint($order_id);if(!$order_id || isset($running[$order_id]) || !function_exists('wc_get_order'))return false;
    // Stock/refund cleanup may save order items inside a delete lifecycle. No
    // nested hook may regrant the still-paid order while its deletion is active.
    if(isset($GLOBALS['wuai_membership_deleting'][$order_id]))$revoked=true;
    $running[$order_id]=true;$locks=[];$order=null;$old_uid=0;
    $order_lock=wuai_sluyu_lock('membership_order_'.$order_id);
    if(!$order_lock){unset($running[$order_id]);return wuai_sluyu_membership_retry($order_id,new WP_Error('membership_busy','订单会员同步正在执行。'));}
    try{
        $order=wuai_sluyu_membership_fresh_order($order_id);
        if($order){$old_uid=absint($order->get_meta('_wuai_membership_user',true,'edit'));$uid=$revoked?0:absint($order->get_user_id());}
        else{$old_uid=absint(get_option('wuai_membership_retry_owner_'.$order_id));$uid=0;}
        $pending_users=wuai_sluyu_membership_pending_users($order_id);
        if(is_wp_error($pending_users))return wuai_sluyu_membership_retry($order_id,$pending_users,$order,$old_uid);
        if(!$old_uid && !$order && !$pending_users)return true;
        if(!$old_uid && $order){$old_uid=absint(get_option('wuai_membership_retry_owner_'.$order_id));}
        if(!$old_uid && $order){
            $legacy=wuai_sluyu_membership_legacy_owners($order_id);
            if(is_wp_error($legacy))return wuai_sluyu_membership_retry($order_id,$legacy,$order);
            if(count($legacy)>1)return wuai_sluyu_membership_retry($order_id,new WP_Error('membership_legacy_conflict','历史订单同时出现在多个账号，已停止新授权，请管理员核对。'),$order);
            if($legacy)$old_uid=$legacy[0];
        }
        $ids=array_values(array_unique(array_filter(array_merge([$old_uid,$uid],$pending_users))));sort($ids,SORT_NUMERIC);
        foreach($ids as$user_id){$lock=wuai_sluyu_lock('user_'.$user_id);if(!$lock)return wuai_sluyu_membership_retry($order_id,new WP_Error('membership_user_busy','账号正在处理其他操作。'),$order,$old_uid);$locks[]=$lock;}
        // Refresh after acquiring user locks; a change of owner invalidates this attempt.
        if($order){$fresh=wuai_sluyu_membership_fresh_order($order_id);if(!$fresh || (!$revoked && (int)$fresh->get_user_id()!==$uid) || absint($fresh->get_meta('_wuai_membership_user',true,'edit'))!==absint($order->get_meta('_wuai_membership_user',true,'edit')))return wuai_sluyu_membership_retry($order_id,new WP_Error('membership_order_changed','订单归属已变化，将重新核对。'),$order,$old_uid);$order=$fresh;}
        $states=[];$state_error=false;$order_dirty=false;$has_grant=false;
        foreach($ids as$user_id){
            clean_user_cache($user_id);if(!get_userdata($user_id))continue;
            $dirty=wuai_sluyu_membership_raw($user_id,'_wuai_membership_sync');
            if(is_array($dirty['value']) && array_key_exists($order_id,$dirty['value']))$order_dirty=true;
            $states[$user_id]=wuai_sluyu_membership_state($user_id);
            if(is_wp_error($states[$user_id])){if(!$state_error)$state_error=$states[$user_id];}
            elseif(isset($states[$user_id]['grants'][$order_id]))$has_grant=true;
        }
        if($state_error){
            $membership_item=false;
            if($order)foreach($order->get_items('line_item')as$item){$days=$item->get_meta('_wuai_membership_days',true,'edit');if($days!=='' && $days!=='0' && $days!==0){$membership_item=true;break;}}
            // Do not attach a damaged account's recovery to unrelated ordinary
            // purchases. Relevant orders persist their users before any marker.
            if(!$old_uid && !$pending_users && !$order_dirty && !$has_grant && !$membership_item)return true;
            $checkpoint=wuai_sluyu_membership_pending_users($order_id,$ids);
            if(is_wp_error($checkpoint) || $checkpoint!==$ids)return wuai_sluyu_membership_retry($order_id,new WP_Error('membership_pending_storage','同步账号清单未保存，尚未改变会员权益。'),$order,$old_uid?:$uid);
            foreach($states as$user_id=>$state){if(!wuai_sluyu_membership_mark($user_id,$order_id))return wuai_sluyu_membership_retry($order_id,new WP_Error('membership_marker','无法写入会员同步保护标记；请立即核对数据库。'),$order,$old_uid?:$uid);}
            return wuai_sluyu_membership_retry($order_id,$state_error,$order,$old_uid?:$uid);
        }
        $prior=$uid && isset($states[$uid]['grants'][$order_id])?$states[$uid]['grants'][$order_id]:false;
        $grant=$order && !$revoked && isset($states[$uid])?wuai_sluyu_membership_order_grant($order,$prior):false;
        if(!$old_uid && !$pending_users && !$order_dirty && !$grant && !is_wp_error($grant) && !$has_grant)return true;
        $checkpoint=wuai_sluyu_membership_pending_users($order_id,$ids);
        if(is_wp_error($checkpoint) || $checkpoint!==$ids)return wuai_sluyu_membership_retry($order_id,new WP_Error('membership_pending_storage','同步账号清单未保存，尚未改变会员权益。'),$order,$old_uid?:$uid);
        foreach($states as$user_id=>$state){if(!wuai_sluyu_membership_mark($user_id,$order_id))return wuai_sluyu_membership_retry($order_id,new WP_Error('membership_marker','无法写入会员同步保护标记；请立即核对数据库。'),$order,$old_uid?:$uid);}
        if(is_wp_error($grant))return wuai_sluyu_membership_retry($order_id,$grant,$order,$old_uid?:$uid);
        foreach($states as$previous_uid=>$state){
            if($previous_uid===$uid)continue;
            unset($states[$previous_uid]['grants'][$order_id]);$saved=wuai_sluyu_membership_commit($previous_uid,$states[$previous_uid]['grants']);
            if(is_wp_error($saved))return wuai_sluyu_membership_retry($order_id,$saved,$order,$previous_uid);
            if(!wuai_sluyu_membership_mark($previous_uid,$order_id,true))return wuai_sluyu_membership_retry($order_id,new WP_Error('membership_marker_clear','旧账号授权已撤回，但同步标记未能解除。'),$order,$previous_uid);
            unset($states[$previous_uid]);
        }
        // Record the intended recipient before granting. Retry can then find and
        // revoke a partially completed transfer without granting two accounts.
        if($order && absint($order->get_meta('_wuai_membership_user',true,'edit'))!==$uid){
            $order->update_meta_data('_wuai_membership_user',$uid);$order->save_meta_data();$order->read_meta_data(true);
            if(absint($order->get_meta('_wuai_membership_user',true,'edit'))!==$uid)return wuai_sluyu_membership_retry($order_id,new WP_Error('membership_owner_storage','订单授权归属未能保存，尚未向新账号发放会员。'),$order,$old_uid?:$uid);
        }
        if($uid && isset($states[$uid])){
            if($grant)$states[$uid]['grants'][$order_id]=$grant;else unset($states[$uid]['grants'][$order_id]);
            $saved=wuai_sluyu_membership_commit($uid,$states[$uid]['grants']);if(is_wp_error($saved))return wuai_sluyu_membership_retry($order_id,$saved,$order,$old_uid?:$uid);
        }
        foreach($states as$user_id=>$state){if(!wuai_sluyu_membership_mark($user_id,$order_id,true))return wuai_sluyu_membership_retry($order_id,new WP_Error('membership_marker_clear','会员状态已保存，但同步标记未能解除。'),$order,$old_uid?:$uid);}
        delete_option('wuai_membership_pending_users_'.$order_id);
        $pending=wuai_sluyu_membership_pending_users($order_id);
        if(is_wp_error($pending) || $pending)return wuai_sluyu_membership_retry($order_id,new WP_Error('membership_pending_clear','会员状态已保存，但同步账号清单未能解除。'),$order,$old_uid?:$uid);
        wp_clear_scheduled_hook('wuai_sluyu_retry_membership',[$order_id]);delete_option('wuai_membership_retry_owner_'.$order_id);delete_option('wuai_membership_issue_'.$order_id);
        return true;
    }catch(Throwable$error){return wuai_sluyu_membership_retry($order_id,new WP_Error('membership_exception','会员同步发生异常，请管理员检查日志和订单后重试。'),$order,$old_uid);}
    finally{foreach(array_reverse($locks)as$lock)wuai_sluyu_unlock($lock);wuai_sluyu_unlock($order_lock);unset($running[$order_id]);}
}

function wuai_sluyu_membership_before_delete($id,$order=null) {
    if(isset($GLOBALS['wuai_membership_deleting'][$id]))return;
    if(!$order)$order=wc_get_order($id);
    if(!$order || !$order instanceof WC_Order || $order instanceof WC_Order_Refund)return;
    $GLOBALS['wuai_membership_deleting'][$id]=true;
    $uid=absint($order->get_meta('_wuai_membership_user',true,'edit'))?:absint($order->get_user_id());
    if($uid)update_option('wuai_membership_retry_owner_'.absint($id),$uid,false);
    wuai_sluyu_membership_sync($id,true);
}
function wuai_sluyu_membership_after_delete($id) {
    wuai_sluyu_membership_sync($id,true);unset($GLOBALS['wuai_membership_deleting'][$id]);
}
function wuai_sluyu_membership_refund_deleting($id,$parent) {
    $id=absint($id);$parent=absint($parent);if(!$id || !$parent)return;
    $GLOBALS['wuai_membership_refund_parents'][$id]=$parent;
    // Queue the parent before its refund disappears; a crashed request still
    // reconciles later without needing to read the now-deleted refund object.
    if(!isset($GLOBALS['wuai_membership_deleting'][$parent]) && !wp_next_scheduled('wuai_sluyu_retry_membership',[$parent]))wp_schedule_single_event(time()+30,'wuai_sluyu_retry_membership',[$parent]);
}
function wuai_sluyu_membership_refund_deleted($id) {
    $parent=$GLOBALS['wuai_membership_refund_parents'][$id]??0;unset($GLOBALS['wuai_membership_refund_parents'][$id]);
    if($parent && !isset($GLOBALS['wuai_membership_deleting'][$parent]))wuai_sluyu_membership_sync($parent);
}
function wuai_sluyu_membership_uses_posts() {
    return function_exists('wc_get_order') && (!class_exists('Automattic\WooCommerce\Utilities\OrderUtil') || !Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled());
}

add_action('woocommerce_after_order_object_save',function($order){if($order instanceof WC_Order && !$order instanceof WC_Order_Refund && !isset($GLOBALS['wuai_membership_deleting'][$order->get_id()]))wuai_sluyu_membership_sync($order->get_id());},30);
add_action('woocommerce_after_order_refund_object_save',function($refund){if($refund instanceof WC_Order_Refund && $refund->get_parent_id() && !isset($GLOBALS['wuai_membership_deleting'][$refund->get_parent_id()]))wuai_sluyu_membership_sync($refund->get_parent_id());},30);
add_action('woocommerce_trash_order',function($id){wuai_sluyu_membership_sync($id,true);});
add_action('woocommerce_untrash_order',function($id){wuai_sluyu_membership_sync($id);});
add_action('woocommerce_before_delete_order','wuai_sluyu_membership_before_delete',5,2);
add_action('woocommerce_delete_order','wuai_sluyu_membership_after_delete',30);
add_filter('woocommerce_pre_delete_order_refund',function($check,$refund){if($check===null && $refund instanceof WC_Order_Refund)wuai_sluyu_membership_refund_deleting($refund->get_id(),$refund->get_parent_id());return $check;},PHP_INT_MAX,2);
add_action('woocommerce_delete_order_refund','wuai_sluyu_membership_refund_deleted',30);
add_action('woocommerce_refund_deleted',function($refund,$order_id){wuai_sluyu_membership_sync($order_id);},10,2);
// CPT administrators can use the core WordPress trash/delete actions directly.
// Ignore HPOS mirror posts: their deletion is not deletion of the real order.
add_action('before_delete_post',function($id,$post){
    if(!wuai_sluyu_membership_uses_posts())return;
    if($post->post_type==='shop_order')wuai_sluyu_membership_before_delete($id);
    elseif($post->post_type==='shop_order_refund')wuai_sluyu_membership_refund_deleting($id,$post->post_parent);
},5,2);
add_action('deleted_post',function($id,$post){
    if(!wuai_sluyu_membership_uses_posts())return;
    if($post->post_type==='shop_order')wuai_sluyu_membership_after_delete($id);
    elseif($post->post_type==='shop_order_refund')wuai_sluyu_membership_refund_deleted($id);
},30,2);
add_action('trashed_post',function($id){if(wuai_sluyu_membership_uses_posts() && get_post_type($id)==='shop_order')wuai_sluyu_membership_sync($id,true);},30);
add_action('untrashed_post',function($id){if(wuai_sluyu_membership_uses_posts() && get_post_type($id)==='shop_order')wuai_sluyu_membership_sync($id);},30);
add_action('woocommerce_update_order_item',function($item_id,$item,$order_id){if($order_id)wuai_sluyu_membership_sync($order_id);},30,3);
add_action('woocommerce_new_order_item',function($item_id,$item,$order_id){if($order_id)wuai_sluyu_membership_sync($order_id);},30,3);
add_action('woocommerce_before_delete_order_item',function($item_id){global $wpdb;$order_id=(int)$wpdb->get_var($wpdb->prepare('SELECT order_id FROM '.$wpdb->prefix.'woocommerce_order_items WHERE order_item_id=%d',$item_id));if($order_id)$GLOBALS['wuai_membership_deleted_item'][$item_id]=$order_id;});
add_action('woocommerce_delete_order_item',function($item_id){$order_id=$GLOBALS['wuai_membership_deleted_item'][$item_id]??0;unset($GLOBALS['wuai_membership_deleted_item'][$item_id]);if($order_id && !isset($GLOBALS['wuai_membership_deleting'][$order_id]))wuai_sluyu_membership_sync($order_id);});

function wuai_sluyu_membership_can_manage($order) {
    if(!$order instanceof WC_Order || $order instanceof WC_Order_Refund || !$order->get_id() || !current_user_can('manage_woocommerce') || wuai_sluyu_banned(get_current_user_id()))return false;
    $type=get_post_type_object('shop_order');return $type && current_user_can($type->cap->edit_post,$order->get_id());
}
function wuai_sluyu_membership_admin_box($object) {
    $order=$object instanceof WC_Order?$object:wc_get_order($object->ID??0);
    if(!wuai_sluyu_membership_can_manage($order))return;
    $id=$order->get_id();$recipient=absint($order->get_meta('_wuai_membership_user',true,'edit'));$uid=absint($order->get_user_id());
    $pending=wuai_sluyu_membership_pending_users($id);$state=$recipient?wuai_sluyu_membership_state($recipient):['grants'=>[]];
    $dirty=$recipient?wuai_sluyu_membership_raw($recipient,'_wuai_membership_sync'):['value'=>[]];
    $waiting=is_wp_error($pending) || $pending || !empty($dirty['value'][$id]);
    $status=$waiting?'等待同步 / 访问保护中':(is_wp_error($state)?'授权数据待核对':(isset($state['grants'][$id])?'本单已授权':'本单未授权 / 已撤回'));
    echo '<div class="wuai-membership-diagnostic" data-order-id="'.esc_attr($id).'" data-recipient="'.esc_attr($recipient).'" data-pending="'.($waiting?'1':'0').'">';
    echo '<p><strong>本单会员状态：</strong><span class="wuai-membership-status">'.esc_html($status).'</span></p><p>订单客户：'.esc_html($uid?'#'.$uid:'访客').'；授权归属：'.esc_html($recipient?'#'.$recipient:'尚无').'</p>';
    $next=wp_next_scheduled('wuai_sluyu_retry_membership',[$id]);echo '<p class="wuai-membership-queue">'.esc_html($next?'已排队重试：'.wp_date('Y-m-d H:i:s',$next):'当前没有排队重试').'</p>';
    $notice_key='wuai_membership_notice_'.get_current_user_id().'_'.$id;$notice=get_transient($notice_key);
    if(is_array($notice)){delete_transient($notice_key);echo '<p role="status" class="wuai-membership-retry-result">'.esc_html(!empty($notice['success'])?'已重新核对会员授权；未重复收款。':'本次仍未完成；保护状态保留并已安排重试，请核对订单和数据库。').'</p>';}
    echo '<p>以已保存的付款状态、订单行、退款记录和客户为准。此按钮仅重新核对权益，不付款、不退款，也不会重复续期；请先保存订单改动。</p><button type="submit" class="button" form="wuai-membership-retry-'.esc_attr($id).'">重新核对会员授权</button></div>';
    $GLOBALS['wuai_membership_retry_forms'][$id]=true;
}
add_action('add_meta_boxes',function($screen,$object){
    if(!function_exists('wc_get_order'))return;$screens=['shop_order'];if(function_exists('wc_get_page_screen_id'))$screens[]=wc_get_page_screen_id('shop-order');
    if(!in_array($screen,$screens,true))return;$order=$object instanceof WC_Order?$object:wc_get_order($object->ID??0);
    if(wuai_sluyu_membership_can_manage($order))add_meta_box('wuai-membership-state','吾爱会员授权','wuai_sluyu_membership_admin_box',$screen,'side','default');
},40,2);
add_action('admin_footer',function(){
    // Keep the retry form outside WooCommerce's order-edit form; nested forms
    // would accidentally submit unsaved payment/customer edits with this action.
    foreach(array_keys($GLOBALS['wuai_membership_retry_forms']??[])as$id){echo '<form id="wuai-membership-retry-'.esc_attr($id).'" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wuai_membership_retry"><input type="hidden" name="order_id" value="'.esc_attr($id).'">';wp_nonce_field('wuai_membership_retry_'.$id,'_wuai_membership_nonce',false);echo '</form>';}
});
function wuai_sluyu_membership_admin_retry() {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')wp_die('请使用订单页的提交按钮。','',405);
    $input=$_POST['order_id']??'';$id=is_scalar($input) && ctype_digit((string)$input)?absint($input):0;
    $order=$id && function_exists('wc_get_order')?wc_get_order($id):false;
    if(!wuai_sluyu_membership_can_manage($order))wp_die('没有重新核对此订单的权限。','',403);
    $nonce=$_POST['_wuai_membership_nonce']??'';
    if(!is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce),'wuai_membership_retry_'.$id))wp_die('请求已过期，请刷新订单页后重试。','',403);
    $result=wuai_sluyu_membership_sync($id);
    set_transient('wuai_membership_notice_'.get_current_user_id().'_'.$id,['success'=>$result===true],60);
    wp_safe_redirect($order->get_edit_order_url());exit;
}
add_action('admin_post_wuai_membership_retry','wuai_sluyu_membership_admin_retry');
add_action('admin_post_nopriv_wuai_membership_retry','wuai_sluyu_membership_admin_retry');
add_action('init',function(){
    foreach(['_wuai_membership_state','_wuai_membership_sync','_wuai_membership_grants','_wuai_member_until']as$key)register_meta('user',$key,['single'=>true,'type'=>$key==='_wuai_member_until'?'integer':'array','show_in_rest'=>false,'auth_callback'=>function(){return current_user_can('manage_options');}]);
},30);
