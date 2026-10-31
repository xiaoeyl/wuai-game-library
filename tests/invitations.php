<?php
// Dedicated local tests. Mail is short-circuited before any user is created.
if (PHP_SAPI!=='cli') { exit(1); }
$root=getenv('WP_QA_ROOT'); if(!$root || !is_file($root.'/wp-load.php'))throw new RuntimeException('WP_QA_ROOT required.');
$_SERVER['HTTP_HOST']='127.0.0.1:8771';$_SERVER['REQUEST_URI']='/';require $root.'/wp-load.php';
if(home_url()!=='http://127.0.0.1:8771')throw new RuntimeException('Local QA only.');
if(!function_exists('wuai_sluyu_invitation_issue') || !wuai_sluyu_invitation_storage_ready())throw new RuntimeException('Integrate invitation module and schema before running tests.');
function invite_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function invite_row($id){global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.wuai_sluyu_table('invites').' WHERE id=%d',$id));}
function invite_action($uid,$operation,$data=[]){wp_set_current_user($uid);$_POST=wp_slash($data);return wuai_sluyu_community_operation($operation,$uid);}
function invite_errors($code,$login,$email,$errors=null){$_POST=['wuai_invitation_code'=>$code];return apply_filters('registration_errors',$errors?:new WP_Error(),$login,$email);}
$old_user=get_current_user_id();$old_post=$_POST;$old_get=$_GET;
$saved_settings=get_option('wuai_sluyu_invitation_settings',null);$saved_register=get_option('users_can_register');
$users=[];$records=[];$callbacks=[];$mail_count=0;$prefix='qa_invitation_'.strtolower(wp_generate_password(8,false,false));
$mail_block=function($return,$atts)use(&$mail_count){$mail_count++;return true;};
add_filter('pre_wp_mail',$mail_block,PHP_INT_MAX,2);
try{
    foreach(['owner','other','admin'] as $name){$id=wp_create_user($prefix.'_'.$name,'qa-local-only',$prefix.'_'.$name.'@example.test');invite_check(!is_wp_error($id),'Create fixture user.');(new WP_User($id))->set_role($name==='admin'?'administrator':'subscriber');$users[$name]=(int)$id;}
    $owner=$users['owner'];$other=$users['other'];$admin=$users['admin'];$table=wuai_sluyu_table('invites');
    $issue=function($target=null)use($admin,$owner,&$records){wp_set_current_user($admin);$result=wuai_sluyu_invitation_issue($target?:$owner);invite_check(!is_wp_error($result),'Administrator creates fixture invite.');$records[]=$result['id'];return $result;};
    update_option('wuai_sluyu_invitation_settings',['enabled'=>false,'required'=>false,'days'=>7]);
    invite_check(is_wp_error(invite_action($owner,'invite_generate')),'Default disabled module denies generation.');
    $errors=invite_errors('',$prefix.'_disabled',$prefix.'_disabled@example.test');invite_check(!$errors->has_errors(),'Disabled module does not alter registration.');
    update_option('wuai_sluyu_invitation_settings',['enabled'=>true,'required'=>false,'days'=>7]);update_option('users_can_register',0);
    invite_check(is_wp_error(invite_action($owner,'invite_generate')),'Invites never open closed system registration.');invite_check(!get_option('users_can_register'),'System registration option unchanged.');
    update_option('users_can_register',1);
    $issued=[];
    for($i=0;$i<3;$i++){$result=invite_action($owner,'invite_generate');invite_check(!is_wp_error($result),'Owner issues daily invitation.');$records[]=$result['invitation_id'];$issued[]=$result;invite_check(preg_match('/^SL-[A-F0-9]{32}$/D',$result['invitation_code'])===1,'Invite has 128-bit CSPRNG shape.');}
    invite_check(count(array_unique(array_column($issued,'invitation_code')))===3,'Generated codes differ.');
    $row=invite_row($issued[0]['invitation_id']);invite_check($row->code_hash===wuai_sluyu_invitation_hash($issued[0]['invitation_code']),'Only keyed hash stored.');
    invite_check(strpos(wp_json_encode($row),$issued[0]['invitation_code'])===false && $row->code_hint===substr($issued[0]['invitation_code'],-8),'Persistent record never contains full secret.');
    invite_check(is_wp_error(invite_action($owner,'invite_generate')),'Fourth self-service code rejected.');
    invite_check(is_wp_error(invite_action($other,'invite_revoke',['id'=>$issued[0]['invitation_id']])),'Foreign owner cannot revoke.');
    invite_check(!is_wp_error(invite_action($owner,'invite_revoke',['id'=>$issued[0]['invitation_id']])),'Owner revocation succeeds.');
    invite_check(is_wp_error(invite_action($owner,'invite_generate')),'Revocation does not reset daily count.');
    wp_set_current_user($other);invite_check(wuai_sluyu_invitation_records($owner)['total']===0,'Foreign records helper cannot enumerate secrets.');
    wp_set_current_user($owner);ob_start();wuai_sluyu_invitation_panel($owner,'account');$panel=ob_get_clean();invite_check(strpos($panel,$issued[1]['invitation_code'])===false && strpos($panel,$row->code_hash)===false,'Owner list shows only hints, no plaintext or hashes.');
    $_POST=[];wp_set_current_user(0);$optional=register_new_user($prefix.'_optional',$prefix.'_optional@example.test');invite_check(!is_wp_error($optional),'Optional core registration works without invite.');$users['optional']=(int)$optional;
    update_option('wuai_sluyu_invitation_settings',['enabled'=>true,'required'=>true,'days'=>7]);
    $missing=invite_errors('',$prefix.'_missing',$prefix.'_missing@example.test');invite_check($missing->has_errors(),'Required mode rejects missing invitation.');
    $nested=invite_errors(['bad'],$prefix.'_nested',$prefix.'_nested@example.test');invite_check($nested->has_errors(),'Nested invitation input rejected.');
    $revoked=invite_errors($issued[0]['invitation_code'],$prefix.'_revoked',$prefix.'_revoked@example.test');invite_check($revoked->has_errors(),'Revoked code rejected.');
    $expired=$issue();$wpdb->update($table,['expires_at'=>time()-1],['id'=>$expired['id']]);wp_set_current_user(0);invite_check(invite_errors($expired['code'],$prefix.'_expired',$prefix.'_expired@example.test')->has_errors(),'Expired code rejected.');
    $fresh=$issue();wp_set_current_user(0);$preexisting=new WP_Error('email_exists','Email error from core.');$precheck=invite_errors($fresh['code'],$prefix.'_invalid',$prefix.'_owner@example.test',$preexisting);invite_check($precheck->has_errors() && invite_row($fresh['id'])->status==='active','Earlier validation errors never consume code.');
    $after=function($errors){$errors->add('qa_late_error','Plugin rejects registration.');return $errors;};add_filter('registration_errors',$after,PHP_INT_MAX,3);$callbacks[]=['registration_errors',$after,PHP_INT_MAX];
    $late=invite_errors($fresh['code'],$prefix.'_late',$prefix.'_late@example.test');invite_check($late->has_errors() && invite_row($fresh['id'])->status==='reserved','Late plugin error leaves temporary reservation.');
    wuai_sluyu_invitation_shutdown();invite_check(invite_row($fresh['id'])->status==='active','Shutdown releases only known failed registration.');remove_filter('registration_errors',$after,PHP_INT_MAX);$callbacks=[];
    $expected_login=$prefix.'_claimed';$expected_email=$prefix.'_claimed@example.test';
    $valid=invite_errors($fresh['code'],$expected_login,$expected_email);invite_check(!$valid->has_errors(),'Valid invitation reserves for expected identity.');$reservation=$GLOBALS['wuai_invitation_reservation'];
    invite_check(invite_row($fresh['id'])->status==='reserved','Reservation persisted before account creation.');
    unset($GLOBALS['wuai_invitation_reservation']);$contender=invite_errors($fresh['code'],$prefix.'_race',$prefix.'_race@example.test');invite_check($contender->has_errors(),'Concurrent code-lock contender rejected.');
    wuai_sluyu_unlock($reservation['lock']);$durable=invite_errors($fresh['code'],$prefix.'_race2',$prefix.'_race2@example.test');invite_check($durable->has_errors(),'Durable reservation rejects reuse even without option lock.');
    $GLOBALS['wuai_invitation_reservation']=$reservation;$GLOBALS['wuai_invitation_reservation']['lock']=false;
    $unrelated=wp_create_user($prefix.'_unrelated','qa-local-only',$prefix.'_unrelated@example.test');invite_check(!is_wp_error($unrelated),'Unrelated account can still be created by supported external API boundary.');$users['unrelated']=(int)$unrelated;
    invite_check(invite_row($fresh['id'])->status==='reserved','Unrelated user_register never claims request reservation.');
    $matching=wp_create_user($expected_login,'qa-local-only',$expected_email);invite_check(!is_wp_error($matching),'Expected core account created.');$users['matching']=(int)$matching;
    $claimed=invite_row($fresh['id']);invite_check($claimed->status==='claimed' && (int)$claimed->claimed_by===(int)$matching && $claimed->reserve_token==='' && $claimed->reserve_fingerprint==='','Matching account atomically consumes invitation and clears proof.');
    invite_check(empty($GLOBALS['wuai_invitation_reservation']),'Successful claim clears request context.');
    invite_check(wuai_sluyu_invitation_claim($reservation,$matching),'Repeated claim is idempotent for same user.');
    invite_check(invite_errors($fresh['code'],$prefix.'_reuse',$prefix.'_reuse@example.test')->has_errors(),'Consumed invite cannot register second account.');
    $native=$issue();wp_set_current_user(0);$_POST=['wuai_invitation_code'=>strtolower($native['code'])];$registered=register_new_user($prefix.'_native',$prefix.'_native@example.test');invite_check(!is_wp_error($registered),'Full register_new_user path handles invitation case normalization.');$users['native']=(int)$registered;invite_check(invite_row($native['id'])->status==='claimed','Full core path consumes code.');
    $failure=$issue();wp_set_current_user(0);$failed_login=$prefix.'_writefail';$failed_email=$prefix.'_writefail@example.test';invite_check(!invite_errors($failure['code'],$failed_login,$failed_email)->has_errors(),'Reserve invite for simulated claim write failure.');
    $block_claim=function($query)use($table){if(strpos($query,"UPDATE $table SET status='claimed'")!==false)return str_replace("AND status='reserved'","AND status='__qa_blocked__'",$query);return $query;};
    add_filter('query',$block_claim);$callbacks[]=['query',$block_claim,10];
    $failed=wp_create_user($failed_login,'qa-local-only',$failed_email);invite_check(!is_wp_error($failed),'Account survives independent claim-storage failure.');$users['failed']=(int)$failed;
    invite_check(invite_row($failure['id'])->status==='reserved' && (int)get_user_meta($failed,'_wuai_invitation_claim_pending',true)===$failure['id'],'Failed claim is quarantined, not made reusable or falsely consumed.');
    wuai_sluyu_invitation_shutdown();invite_check(invite_row($failure['id'])->status==='reserved','Failed shutdown claim remains reserved.');
    remove_filter('query',$block_claim);$callbacks=[];
    wp_set_current_user($admin);$wpdb->update($table,['reserved_at'=>time()-11*MINUTE_IN_SECONDS],['id'=>$failure['id']]);
    invite_check(is_wp_error(wuai_sluyu_invitation_recover($failure['id'],$failed_login,'wrong@example.test')),'Recovery rejects wrong identity proof.');
    invite_check(!is_wp_error(wuai_sluyu_invitation_recover($failure['id'],$failed_login,$failed_email)),'Admin proves matching account and repairs claim.');
    invite_check(invite_row($failure['id'])->status==='claimed' && !get_user_meta($failed,'_wuai_invitation_claim_pending',true),'Repair consumes record and clears pending marker.');
    $crash=$issue();wp_set_current_user(0);invite_check(!invite_errors($crash['code'],$prefix.'_crash',$prefix.'_crash@example.test')->has_errors(),'Reserve crash fixture.');$crash_reservation=$GLOBALS['wuai_invitation_reservation'];wuai_sluyu_invitation_release_lock();unset($GLOBALS['wuai_invitation_reservation']);$wpdb->update($table,['reserved_at'=>time()-11*MINUTE_IN_SECONDS],['id'=>$crash['id']]);
    wp_set_current_user($admin);invite_check(!is_wp_error(wuai_sluyu_invitation_recover($crash['id'])),'Uncertain abandoned record can be revoked, never restored to active.');invite_check(invite_row($crash['id'])->status==='revoked','Abandoned code stays permanently unusable.');
    $export=wuai_sluyu_invitation_export(get_userdata($owner)->user_email);invite_check(!empty($export['data']),'Privacy exporter includes invitation hints and status.');invite_check(strpos(wp_json_encode($export),$issued[1]['invitation_code'])===false,'Privacy export never reconstructs secret code.');
    $erase=wuai_sluyu_invitation_erase(get_userdata($owner)->user_email);invite_check($erase['items_retained'] && invite_row($issued[1]['invitation_id'])->status==='revoked','Erasure revokes active codes but retains anti-reuse accounting with disclosure.');
    update_option('wuai_sluyu_invitation_settings',['enabled'=>false,'required'=>true,'days'=>7]);invite_check(!invite_errors('',$prefix.'_off',$prefix.'_off@example.test')->has_errors(),'Disabling module removes required gate without locking site.');
    invite_check($mail_count>0,'Full core registration attempted mail, all intercepted by local test filter.');
    echo "PASS: optional invite generation/hashing/quotas, core registration, late-error release, durable concurrent reservations, identity-bound/idempotent claims, failed-write quarantine/recovery, ownership and privacy. No mail sent.\n";
}finally{
    foreach($callbacks as $callback)remove_filter($callback[0],$callback[1],$callback[2]);
    wuai_sluyu_invitation_shutdown();$_POST=[];wp_set_current_user(isset($users['admin'])?$users['admin']:0);
    global $wpdb;foreach($records as $id)$wpdb->delete(wuai_sluyu_table('invites'),['id'=>$id]);
    require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $id)wp_delete_user($id);
    if($saved_settings===null)delete_option('wuai_sluyu_invitation_settings');else update_option('wuai_sluyu_invitation_settings',$saved_settings);
    update_option('users_can_register',$saved_register);remove_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
    $_POST=$old_post;$_GET=$old_get;wp_set_current_user($old_user);
}
