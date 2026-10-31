<?php
// Local WooCommerce fixtures only; no external payment gateway is called.
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('WP_QA_ROOT');if(!$root || !is_file($root.'/wp-load.php'))throw new RuntimeException('WP_QA_ROOT required.');
$_SERVER['HTTP_HOST']='127.0.0.1:8771';$_SERVER['REQUEST_URI']='/';require $root.'/wp-load.php';
if(home_url()!=='http://127.0.0.1:8771' || !class_exists('WooCommerce') || !function_exists('wuai_sluyu_membership_sync'))throw new RuntimeException('Disposable local WooCommerce and membership module required.');
function ms_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function ms_until($uid){return wuai_sluyu_membership_until($uid);}
function ms_order($uid,$product,$days,$qty=1){$order=wc_create_order(['customer_id'=>$uid]);$item=new WC_Order_Item_Product();$item->set_product($product);$item->set_quantity($qty);$item->set_total('10');$item->add_meta_data('_wuai_membership_days',$days,true);$order->add_item($item);$order->calculate_totals();$order->save();return $order;}
function ms_fault($uid,$key,$callback){
    $hits=0;$block=function($check,$object_id,$meta_key)use($uid,$key,&$hits){if((int)$object_id===(int)$uid && $meta_key===$key){$hits++;return false;}return $check;};
    add_filter('update_user_metadata',$block,999,3);add_filter('add_user_metadata',$block,999,3);
    try{$result=$callback();}finally{remove_filter('update_user_metadata',$block,999);remove_filter('add_user_metadata',$block,999);}
    ms_check($hits>0,'The injected storage failure was exercised: '.$key);return $result;
}
function ms_checkpoint_fault($order_id,$callback){
    global $wpdb;$hits=0;
    $block=function($query)use($order_id,&$hits,$wpdb){
        if(strpos($query,'_wuai_membership_user')===false || !preg_match('/^\s*(?:UPDATE|INSERT)\b/i',$query))return $query;
        $post_table=preg_match('/\b'.preg_quote($wpdb->postmeta,'/').'\b/',$query);
        $hpos_table=preg_match('/\b'.preg_quote($wpdb->prefix.'wc_orders_meta','/').'\b/',$query);
        if(!$post_table && !$hpos_table)return $query;
        // Only this isolated order is touched while the filter is installed.
        if(strpos($query,(string)$order_id)===false && !$post_table)return $query;
        $hits++;return 'UPDATE '.$wpdb->options.' SET option_value=option_value WHERE option_id=0';
    };
    add_filter('query',$block,999);
    try{$result=$callback();}finally{remove_filter('query',$block,999);}
    ms_check($hits>0,'Order-recipient checkpoint failure was exercised.');return $result;
}
$old_user=get_current_user_id();$old_post=$_POST;$users=[];$orders=[];$product=null;$prefix='qa_membership_'.strtolower(wp_generate_password(8,false,false));
try{
    foreach(['reader','other','failed','invalid','legacy','third','repaired','admin']as$role){$uid=wp_create_user($prefix.'_'.$role,'qa-local-only',$prefix.'_'.$role.'@example.test');ms_check(!is_wp_error($uid),'Create membership fixture user.');(new WP_User($uid))->set_role($role==='admin'?'administrator':'subscriber');$users[$role]=(int)$uid;}
    $reader=$users['reader'];$other=$users['other'];$failed=$users['failed'];$invalid=$users['invalid'];$legacy=$users['legacy'];
    $product=new WC_Product_Simple();$product->set_name($prefix.' plan');$product->set_status('publish');$product->set_virtual(true);$product->set_regular_price('10');$product->save();
    $first=ms_order($reader,$product,30);$orders[]=$first->get_id();$first->payment_complete('local-only-first');
    ms_check(wuai_sluyu_membership_sync($first->get_id())===true,'Paid order reconciles.');
    $first=wuai_sluyu_membership_fresh_order($first->get_id());$paid=$first->get_date_paid()->getTimestamp();$until=ms_until($reader);
    ms_check($until===$paid+30*DAY_IN_SECONDS,'Paid timestamp and duration determine membership.');
    ms_check((int)$first->get_meta('_wuai_membership_user')===$reader,'Order checkpoint records its granted user.');
    ms_check((int)get_user_meta($reader,'_wuai_member_until',true)===$until,'Legacy getter maps to the authoritative bundle.');
    $first_grants=[$first->get_id()=>['at'=>$paid,'days'=>30]];
    ms_check(get_user_meta($reader,'_wuai_membership_grants',true)===$first_grants && get_user_meta($reader,'_wuai_membership_grants',false)===[$first_grants],'Legacy grant getter preserves array values for both single and multi metadata reads.');
    wuai_sluyu_membership_sync($first->get_id());ms_check(ms_until($reader)===$until,'Repeated reconciliation cannot extend an existing payment.');
    $second=ms_order($reader,$product,10);$orders[]=$second->get_id();$second->payment_complete('local-only-second');
    ms_check(ms_until($reader)===$until+10*DAY_IN_SECONDS,'Second order renews only by its own duration.');
    $current=wuai_sluyu_membership_raw($reader,'_wuai_membership_state')['value'];
    $stale=[$first->get_id()=>['at'=>$paid,'days'=>30]];
    wp_cache_set($reader,['_wuai_membership_state'=>[maybe_serialize(['grants'=>$stale,'until'=>$until])],'_wuai_membership_grants'=>[maybe_serialize($stale)],'_wuai_member_until'=>[$until]],'user_meta');
    wuai_sluyu_membership_sync($first->get_id());
    ms_check(wuai_sluyu_membership_raw($reader,'_wuai_membership_state')['value']===$current,'Stale per-worker user metadata cache cannot overwrite another order grant.');

    $second=wuai_sluyu_membership_fresh_order($second->get_id());$second->set_customer_id($other);$second->save();
    ms_check(ms_until($reader)===$until && ms_until($other)>time()+9*DAY_IN_SECONDS,'Customer transfer revokes old account before granting new account.');
    $second=wuai_sluyu_membership_fresh_order($second->get_id());ms_check((int)$second->get_meta('_wuai_membership_user')===$other,'Transfer checkpoint follows the new customer.');
    $second->update_status('refunded');ms_check(ms_until($other)===0 && ms_until($reader)===$until,'Refund after transfer revokes only the current recipient order.');

    $broken=ms_order($failed,$product,7);$orders[]=$broken->get_id();
    ms_fault($failed,'_wuai_membership_state',function()use($broken){$broken->payment_complete('local-only-storage-failure');return wuai_sluyu_membership_sync($broken->get_id());});
    ms_check(ms_until($failed)===0,'Failed payment authorization stays fail-closed.');
    ms_check((bool)wuai_sluyu_membership_raw($failed,'_wuai_membership_sync')['value'],'Failed authorization retains a durable dirty marker.');
    ms_check((bool)wp_next_scheduled('wuai_sluyu_retry_membership',[$broken->get_id()]),'Storage failure schedules an ordinary retry.');
    $notes=wc_get_order_notes(['order_id'=>$broken->get_id(),'limit'=>100]);$issue_count=0;foreach($notes as$note){if(strpos($note->content,'吾爱会员同步未完成')!==false)$issue_count++;}ms_check($issue_count===1,'Repeated failure creates only one administrative issue note.');
    ms_check(wuai_sluyu_membership_sync($broken->get_id())===true && ms_until($failed)>time(),'Retry repairs authorization after storage recovers.');
    ms_check(!wuai_sluyu_membership_raw($failed,'_wuai_membership_sync')['value'] && !wp_next_scheduled('wuai_sluyu_retry_membership',[$broken->get_id()]),'Successful repair clears dirty marker and queued retry.');
    ms_fault($failed,'_wuai_membership_state',function()use($broken){$order=wuai_sluyu_membership_fresh_order($broken->get_id());$order->update_status('refunded');return wuai_sluyu_membership_sync($order->get_id());});
    ms_check(ms_until($failed)===0,'Refund storage failure revokes access immediately through dirty marker despite old until.');
    ms_check(wuai_sluyu_membership_raw($failed,'_wuai_membership_state')['value']['until']>0,'Fixture genuinely retains previous state under the failed write.');
    wuai_sluyu_membership_sync($broken->get_id());ms_check(ms_until($failed)===0 && !wuai_sluyu_membership_raw($failed,'_wuai_membership_sync')['value'],'Refund retry removes stale entitlement and clears only its marker.');

    $transfer=ms_order($reader,$product,2);$orders[]=$transfer->get_id();$transfer->payment_complete('local-only-transfer');$other_before=ms_until($other);
    ms_fault($reader,'_wuai_membership_state',function()use($transfer,$other){$order=wuai_sluyu_membership_fresh_order($transfer->get_id());$order->set_customer_id($other);$order->save();return wuai_sluyu_membership_sync($order->get_id());});
    ms_check(ms_until($reader)===0 && ms_until($other)===$other_before,'Failed old-recipient revoke never grants the target recipient.');
    $transfer=wuai_sluyu_membership_fresh_order($transfer->get_id());ms_check((int)$transfer->get_meta('_wuai_membership_user')===$reader,'Failed revoke keeps checkpoint on old recipient for retry.');
    wuai_sluyu_membership_sync($transfer->get_id());ms_check(ms_until($reader)===$until && ms_until($other)>time(),'Transfer retry removes source entitlement then grants target.');

    $marker=ms_order($failed,$product,3);$orders[]=$marker->get_id();$marker->payment_complete('local-only-marker');$marker_until=ms_until($failed);
    ms_check($marker_until>time(),'Marker-failure fixture starts with valid access.');
    ms_fault($failed,'_wuai_membership_sync',function()use($marker){$order=wuai_sluyu_membership_fresh_order($marker->get_id());$order->update_status('refunded');return wuai_sluyu_membership_sync($order->get_id());});
    ms_check(!wuai_sluyu_membership_raw($failed,'_wuai_membership_sync')['value'] && wuai_sluyu_membership_raw($failed,'_wuai_membership_state')['value']['until']===$marker_until,'Fixture blocks dirty user-meta while retaining the old live bundle.');
    ms_check(wuai_sluyu_membership_pending($failed) && ms_until($failed)===0,'Independent durable order pending-users checkpoint denies access when the dirty user-meta write fails.');
    wuai_sluyu_membership_sync($marker->get_id());ms_check(!wuai_sluyu_membership_pending($failed) && ms_until($failed)===0,'Retry clears the independent pending checkpoint after refund reconciliation.');

    $cascade=ms_order($reader,$product,4);$orders[]=$cascade->get_id();$cascade->payment_complete('local-only-cascade');
    ms_checkpoint_fault($cascade->get_id(),function()use($cascade,$other){$order=wuai_sluyu_membership_fresh_order($cascade->get_id());$order->set_customer_id($other);$order->save();return wuai_sluyu_membership_sync($order->get_id());});
    $cascade=wuai_sluyu_membership_fresh_order($cascade->get_id());
    ms_check((int)$cascade->get_meta('_wuai_membership_user')===$reader && wuai_sluyu_membership_pending($other),'Failed A-to-B checkpoint keeps the original recipient and durably remembers B.');
    $third=$users['third'];$cascade->set_customer_id($third);$cascade->save();
    ms_check(wuai_sluyu_membership_sync($cascade->get_id())===true,'A-to-B failure followed by A-to-C reconciles.');
    ms_check(!wuai_sluyu_membership_pending($other) && !wuai_sluyu_membership_raw($other,'_wuai_membership_sync')['value'],'Intermediate recipient B has no orphan dirty marker.');
    ms_check(!isset(wuai_sluyu_membership_state($other)['grants'][$cascade->get_id()]) && isset(wuai_sluyu_membership_state($third)['grants'][$cascade->get_id()]),'Only final recipient C receives the transferred order.');

    $zero=ms_order($invalid,$product,30,0);$orders[]=$zero->get_id();$zero->payment_complete('local-only-zero');
    ms_check(ms_until($invalid)===0 && is_wp_error(wuai_sluyu_membership_sync($zero->get_id())),'Zero quantity cannot grant one unit by coercion.');
    $zero=wuai_sluyu_membership_fresh_order($zero->get_id());$items=$zero->get_items();$item=reset($items);$item->set_quantity(2);$item->save();
    ms_check(wuai_sluyu_membership_sync($zero->get_id())===true && ms_until($invalid)>time()+59*DAY_IN_SECONDS,'Valid corrected positive quantity recovers via item-save reconciliation.');
    $item->set_quantity(-1);$item->save();ms_check(ms_until($invalid)===0,'Negative quantity cannot retain a live grant.');$item->set_quantity(1);$item->save();
    $zero=wuai_sluyu_membership_fresh_order($zero->get_id());$zero->set_date_paid(time()+DAY_IN_SECONDS);$zero->save();ms_check(ms_until($invalid)===0,'Future payment date does not grant early access.');$zero->set_date_paid($paid);$zero->save();
    $invalid_before=ms_until($invalid);$zero=wuai_sluyu_membership_fresh_order($zero->get_id());$zero->set_date_paid(null);$zero->save();
    wuai_sluyu_membership_sync($zero->get_id());$stable=ms_until($invalid);wuai_sluyu_membership_sync($zero->get_id());ms_check($stable===$invalid_before && ms_until($invalid)===$stable,'Missing paid date reuses established grant time, never the current retry time.');

    $partial=ms_order($other,$product,9);$orders[]=$partial->get_id();$partial->payment_complete('local-only-partial');
    $refund=wc_create_refund(['order_id'=>$partial->get_id(),'amount'=>1,'reason'=>'Local test only','refund_payment'=>false]);ms_check(!is_wp_error($refund),'Local refund without external gateway.');
    wuai_sluyu_membership_sync($partial->get_id());$state=wuai_sluyu_membership_state($other);ms_check(!isset($state['grants'][$partial->get_id()]),'Partial refund conservatively revokes this order only.');
    $refund->delete(true);ms_check(isset(wuai_sluyu_membership_state($other)['grants'][$partial->get_id()]),'Native refund object deletion restores the paid parent without a manual order resave.');
    ms_check(!wp_next_scheduled('wuai_sluyu_retry_membership',[$partial->get_id()]),'Successful native refund deletion clears its crash-recovery retry.');
    $refund=new WC_Order_Refund();$refund->set_parent_id($partial->get_id());$refund->set_amount(1);$refund->set_reason('Local direct refund object only');$refund->save();
    ms_check((float)$refund->get_amount()===1.0 && (float)$refund->get_total()===0.0,'Direct-save fixture intentionally sets refund amount before its negative total.');
    ms_check(!isset(wuai_sluyu_membership_state($other)['grants'][$partial->get_id()]),'Direct refund amount revokes in CPT and HPOS even before total is populated.');
    $refund->set_amount(0);$refund->save();ms_check(isset(wuai_sluyu_membership_state($other)['grants'][$partial->get_id()]),'Direct refund amount-only correction to zero refreshes cached refund data and restores its original parent grant.');
    $refund->set_amount(2);$refund->save();ms_check(!isset(wuai_sluyu_membership_state($other)['grants'][$partial->get_id()]),'Direct refund amount-only edit revokes again in the same request.');
    $refund->delete(true);ms_check(isset(wuai_sluyu_membership_state($other)['grants'][$partial->get_id()]),'Direct refund object deletion maps its parent reliably.');
    foreach([[],NAN,INF,'not-a-number',-1]as$bad_amount){$invalid_refund_order=new class($bad_amount){private $amount;public function __construct($amount){$this->amount=$amount;}public function get_total_refunded(){return $this->amount;}};ms_check(is_wp_error(wuai_sluyu_membership_has_refund($invalid_refund_order)),'Malformed/nonfinite/negative aggregate refund data fails closed without scalar coercion.');}
    $original_grant=wuai_sluyu_membership_state($other)['grants'][$partial->get_id()];
    $partial=wuai_sluyu_membership_fresh_order($partial->get_id());$partial->update_status('refunded');$partial=wuai_sluyu_membership_fresh_order($partial->get_id());
    ms_check((float)$partial->get_total_refunded()===(float)$partial->get_total() && !isset(wuai_sluyu_membership_state($other)['grants'][$partial->get_id()]),'Woo refunded status generates a full manual refund and revokes the grant.');
    $partial->update_status('completed');$partial=wuai_sluyu_membership_fresh_order($partial->get_id());
    ms_check((float)$partial->get_total_refunded()>0 && !isset(wuai_sluyu_membership_state($other)['grants'][$partial->get_id()]),'Changing status back to paid cannot override a retained real refund record.');
    foreach($partial->get_refunds()as$full_refund)$full_refund->delete(true);
    ms_check(wuai_sluyu_membership_state($other)['grants'][$partial->get_id()]===$original_grant,'Explicitly deleting the full local refund restores only the original paid grant.');
    $partial_id=$partial->get_id();$partial->delete(false);ms_check(!isset(wuai_sluyu_membership_state($other)['grants'][$partial_id]),'Trash path revokes the actual order ID, even after Woo clears the deleted object ID.');
    $lifetime=ms_order($other,$product,-1);$orders[]=$lifetime->get_id();$lifetime->payment_complete('local-only-lifetime');ms_check(ms_until($other)===-1,'Lifetime grant works.');$life_id=$lifetime->get_id();
    $zero_refund=new WC_Order_Refund();$zero_refund->set_parent_id($life_id);$zero_refund->set_amount(0);$zero_refund->set_total(0);$zero_refund->save();ms_check(ms_until($other)===-1,'Zero-amount child refund alone does not revoke an otherwise valid lifetime grant.');
    $items=$lifetime->get_items();$life_item=reset($items);$nested=0;$regranted=false;
    $reentry=function($id)use($life_id,$life_item,$lifetime,&$nested){if((int)$id!==$life_id)return;$nested++;do_action('woocommerce_update_order_item',$life_item->get_id(),$life_item,$life_id);do_action('woocommerce_order_status_changed',$life_id,'completed','processing',$lifetime);};
    $no_regrant=function($check,$uid,$key,$value)use($other,$life_id,&$regranted){if((int)$uid===$other && $key==='_wuai_membership_state' && isset($value['grants'][$life_id]))$regranted=true;return $check;};
    add_action('woocommerce_before_delete_order',$reentry,20);add_filter('update_user_metadata',$no_regrant,1000,4);add_filter('add_user_metadata',$no_regrant,1000,4);
    try{$lifetime->delete(true);}finally{remove_action('woocommerce_before_delete_order',$reentry,20);remove_filter('update_user_metadata',$no_regrant,1000);remove_filter('add_user_metadata',$no_regrant,1000);}
    ms_check($nested>0 && !$regranted && ms_until($other)!==-1 && !isset(wuai_sluyu_membership_state($other)['grants'][$life_id]),'Permanent order deletion with nested item/status/refund hooks never regrants the deleting lifetime order.');
    $native_item=ms_order($other,$product,6);$orders[]=$native_item->get_id();$native_item->payment_complete('local-only-native-item');$items=$native_item->get_items();$item=reset($items);
    ms_check(isset(wuai_sluyu_membership_state($other)['grants'][$native_item->get_id()]),'Native item deletion fixture is initially granted.');
    wc_delete_order_item($item->get_id());ms_check(!isset(wuai_sluyu_membership_state($other)['grants'][$native_item->get_id()]),'Native wc_delete_order_item revokes the deleted membership line.');
    if(wuai_sluyu_membership_uses_posts()){
        $native=ms_order($other,$product,8);$orders[]=$native->get_id();$native->payment_complete('local-only-native-post');$native_id=$native->get_id();
        wp_trash_post($native_id);ms_check(!isset(wuai_sluyu_membership_state($other)['grants'][$native_id]),'Native wp_trash_post immediately revokes CPT order access.');
        wp_untrash_post($native_id);ms_check(isset(wuai_sluyu_membership_state($other)['grants'][$native_id]),'Native wp_untrash_post restores the saved paid CPT order.');
        $native_refund=wc_create_refund(['order_id'=>$native_id,'amount'=>1,'reason'=>'Local WP deletion only','refund_payment'=>false]);ms_check(!is_wp_error($native_refund),'Create refund for native WP delete.');
        ms_check(!isset(wuai_sluyu_membership_state($other)['grants'][$native_id]),'Native refund fixture starts revoked.');
        wp_delete_post($native_refund->get_id(),true);ms_check(isset(wuai_sluyu_membership_state($other)['grants'][$native_id]),'Native wp_delete_post refund restores the paid parent.');
        $native_refund=wc_create_refund(['order_id'=>$native_id,'amount'=>1,'reason'=>'Local parent deletion only','refund_payment'=>false]);ms_check(!is_wp_error($native_refund),'Create child refund before parent deletion.');
        $regranted=false;$watch=function($check,$uid,$key,$value)use($other,$native_id,&$regranted){if((int)$uid===$other && $key==='_wuai_membership_state' && isset($value['grants'][$native_id]))$regranted=true;return $check;};
        add_filter('update_user_metadata',$watch,1000,4);add_filter('add_user_metadata',$watch,1000,4);
        try{wp_delete_post($native_id,true);}finally{remove_filter('update_user_metadata',$watch,1000);remove_filter('add_user_metadata',$watch,1000);}
        ms_check(!$regranted && !isset(wuai_sluyu_membership_state($other)['grants'][$native_id]),'Deleting a parent and its refunds never temporarily regrants that deleting order.');
        $native_paid=ms_order($other,$product,8);$orders[]=$native_paid->get_id();$native_paid->payment_complete('local-only-native-paid-delete');$native_paid_id=$native_paid->get_id();
        wp_delete_post($native_paid_id,true);ms_check(!isset(wuai_sluyu_membership_state($other)['grants'][$native_paid_id]),'Native paid CPT permanent deletion revokes its grant.');
    }

    $legacy_order=ms_order($legacy,$product,5);$orders[]=$legacy_order->get_id();$legacy_order->payment_complete('local-only-legacy');$legacy_order=wuai_sluyu_membership_fresh_order($legacy_order->get_id());$legacy_at=$legacy_order->get_date_paid()->getTimestamp();
    delete_user_meta($legacy,'_wuai_membership_state');delete_user_meta($legacy,'_wuai_membership_sync');update_user_meta($legacy,'_wuai_membership_grants',[$legacy_order->get_id()=>['at'=>$legacy_at,'days'=>5]]);update_user_meta($legacy,'_wuai_member_until',$legacy_at+5*DAY_IN_SECONDS);
    $legacy_order->delete_meta_data('_wuai_membership_user');$legacy_order->save_meta_data();$legacy_order->set_customer_id($other);$legacy_order->save();
    ms_check(ms_until($legacy)===0 && isset(wuai_sluyu_membership_state($other)['grants'][$legacy_order->get_id()]),'Historical uncheckpointed grant is discovered and revoked during first owner migration.');
    $saved_state=wuai_sluyu_membership_raw($reader,'_wuai_membership_state')['value'];update_user_meta($reader,'_wuai_membership_state','corrupt');update_user_meta($reader,'_wuai_member_until',-1);ms_check(ms_until($reader)===0,'Present malformed bundle never falls back to legacy lifetime access.');update_user_meta($reader,'_wuai_membership_state',$saved_state);

    $repaired=$users['repaired'];update_user_meta($repaired,'_wuai_membership_state','corrupt');
    $ordinary=ms_order($repaired,$product,0);$orders[]=$ordinary->get_id();$ordinary->payment_complete('local-only-ordinary');
    ms_check(wuai_sluyu_membership_pending_users($ordinary->get_id())===[] && !wp_next_scheduled('wuai_sluyu_retry_membership',[$ordinary->get_id()]) && !wuai_sluyu_membership_raw($repaired,'_wuai_membership_sync')['exists'],'A corrupt account bundle does not attach recovery markers to unrelated ordinary paid orders.');
    $damaged=ms_order($repaired,$product,5);$orders[]=$damaged->get_id();$damaged->payment_complete('local-only-damaged');
    ms_check(is_wp_error(wuai_sluyu_membership_sync($damaged->get_id())) && wuai_sluyu_membership_pending_users($damaged->get_id())===[$repaired],'Corrupt-state failures persist their involved users before marking them dirty.');
    ms_check(!wuai_sluyu_membership_fresh_order($damaged->get_id())->get_meta('_wuai_membership_user') && wuai_sluyu_membership_raw($repaired,'_wuai_membership_sync')['value'][$damaged->get_id()]===1,'Failed first grant has no owner checkpoint but remains durably discoverable.');
    update_user_meta($repaired,'_wuai_membership_state',['grants'=>[],'until'=>0]);
    $items=$damaged->get_items();$item=reset($items);wc_delete_order_item($item->get_id());
    ms_check(wuai_sluyu_membership_sync($damaged->get_id())===true && !wuai_sluyu_membership_pending($repaired) && !wuai_sluyu_membership_raw($repaired,'_wuai_membership_sync')['value'] && ms_until($repaired)===0,'After manual bundle repair, removing the membership line clears its old recovery marker without granting.');
    wuai_sluyu_membership_mark($repaired,$ordinary->get_id());
    ms_check(wuai_sluyu_membership_pending_users($ordinary->get_id())===[] && wuai_sluyu_membership_sync($ordinary->get_id())===true && !wuai_sluyu_membership_raw($repaired,'_wuai_membership_sync')['value'],'A legacy standalone dirty marker is not skipped by the no-grant early return.');
    update_user_meta($repaired,'_wuai_membership_state','corrupt');$deleted_damaged=ms_order($repaired,$product,3);$deleted_damaged_id=$deleted_damaged->get_id();$orders[]=$deleted_damaged_id;$deleted_damaged->payment_complete('local-only-deleted-damaged');
    $deleted_damaged->delete(true);ms_check(wuai_sluyu_membership_pending($repaired),'Deleting an order with corrupt authorization retains its durable recovery account.');
    update_user_meta($repaired,'_wuai_membership_state',['grants'=>[],'until'=>0]);
    ms_check(wuai_sluyu_membership_sync($deleted_damaged_id)===true && !wuai_sluyu_membership_pending($repaired) && !wuai_sluyu_membership_raw($repaired,'_wuai_membership_sync')['value'] && !wp_next_scheduled('wuai_sluyu_retry_membership',[$deleted_damaged_id]),'Manual bundle repair after permanent order deletion clears markers through the surviving checkpoint.');
    wp_set_current_user($reader);ms_check(!current_user_can('edit_user_meta',$reader,'_wuai_membership_state'),'Subscriber cannot edit authoritative membership metadata.');
    ms_check(!wuai_sluyu_membership_can_manage($first),'Subscriber cannot access the order retry controls.');
    wp_set_current_user($users['admin']);ms_check(wuai_sluyu_membership_can_manage($first),'Order administrator can access membership retry controls.');
    update_user_meta($users['admin'],'_wuai_banned_until',-1);ms_check(!wuai_sluyu_membership_can_manage($first),'Banned administrator cannot retry authorization.');delete_user_meta($users['admin'],'_wuai_banned_until');
    echo "PASS: fresh atomic membership bundle, stable renewals/paid timestamps, stale-cache isolation, dirty-marker failure/retry/refund behavior, single issue note, transfer checkpointing, quantity/date validation, legacy migration, corrupt-state repair after item/order deletion, native Woo/refund/item and WP trash/delete lifecycle, private metadata and retry capabilities.\n";
}finally{
    $_POST=[];wp_set_current_user(0);
    foreach(array_unique($orders)as$id){$order=wc_get_order($id);if($order)$order->delete(true);wp_clear_scheduled_hook('wuai_sluyu_retry_membership',[$id]);delete_option('wuai_membership_issue_'.$id);delete_option('wuai_membership_retry_owner_'.$id);delete_option('wuai_membership_pending_users_'.$id);}
    if($product)$product->delete(true);require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$uid)wp_delete_user($uid);wp_set_current_user($old_user);$_POST=$old_post;
}
