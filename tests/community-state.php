<?php
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('WP_QA_ROOT'); if(!$root)throw new RuntimeException('WP_QA_ROOT required.');
$_SERVER['HTTP_HOST']='127.0.0.1:8771';$_SERVER['REQUEST_URI']='/';require $root.'/wp-load.php';
if(home_url()!=='http://127.0.0.1:8771')throw new RuntimeException('Local QA only.');
function state_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$uid=get_user_by('login','qa_member_c')->ID;wp_set_current_user($uid);
$wpdb->delete(wuai_sluyu_table('events'),['user_id'=>$uid]);
update_user_meta($uid,'_wuai_sluyu_checkin_date',current_datetime()->modify('-3 days')->format('Y-m-d'));
update_user_meta($uid,'_wuai_sluyu_checkin_days',4);update_user_meta($uid,'_wuai_checkin_streak',4);
state_check(is_wp_error(wuai_sluyu_community_operation('makeup',$uid)),'Insufficient funds rejected.');
wuai_sluyu_event($uid,'qa-seed','qa',100,0,'test');
$result=wuai_sluyu_community_operation('makeup',$uid);state_check(!is_wp_error($result),'Makeup succeeds.');state_check(wuai_sluyu_points($uid)===40,'Exact debit.');
state_check(is_wp_error(wuai_sluyu_community_operation('makeup',$uid)),'No repeated debit.');
state_check((int)get_user_meta($uid,'_wuai_checkin_streak',true)===6,'Makeup bridges streak.');
$result=wuai_sluyu_checkin($uid);state_check($result['streak']===7,'Today continues repaired streak.');
$verify=wp_insert_post(['post_type'=>'sluyu_verify','post_author'=>$uid,'post_title'=>'QA approved title','post_status'=>'pending','post_content'=>'Test evidence']);
wp_update_post(['ID'=>$verify,'post_status'=>'publish']);state_check(get_user_meta($uid,'_wuai_verified_title',true)==='QA approved title','Approval grants badge.');
wp_update_post(['ID'=>$verify,'post_status'=>'draft']);state_check(!get_user_meta($uid,'_wuai_verified_title',true),'Withdrawal revokes badge.');
update_user_meta($uid,'_wuai_banned_until',-1);state_check(wuai_sluyu_banned($uid),'Permanent ban.');update_user_meta($uid,'_wuai_banned_until',time()-60);state_check(!wuai_sluyu_banned($uid),'Expired ban.');
$export=wuai_sluyu_export_personal_data(get_userdata($uid)->user_email);state_check(count($export['data'])>=3,'Privacy export includes records.');
$erase=wuai_sluyu_erase_personal_data(get_userdata($uid)->user_email);state_check($erase['items_retained'],'Accounting retention is disclosed.');
$widget=new Wuai_Sluyu_Widget();
foreach(array_keys(Wuai_Sluyu_Widget::modes()) as $mode){ob_start();$widget->widget(['before_widget'=>'<section>','after_widget'=>'</section>','before_title'=>'<h2>','after_title'=>'</h2>'],['mode'=>$mode]);$html=ob_get_clean();state_check(strpos($html,'<h2>')!==false,'Widget renders: '.$mode);}
$original_structure=get_option('permalink_structure'); $wp_rewrite->set_permalink_structure('/%postname%/');
$rules=$wp_rewrite->rewrite_rules();
foreach(['mission/today','pcgame/dzmxss','document.html','today.html'] as $path){$matched='';foreach($rules as $regex=>$query){if(preg_match('#^'.$regex.'#',$path)){$matched=$query;break;}}state_check(strpos($matched,'sluyu_page=')!==false,'Reference route precedence: '.$path);}
$wp_rewrite->set_permalink_structure($original_structure); flush_rewrite_rules(false);
echo "PASS: makeup accounting, streak continuity, verification approval/revocation, bans, privacy export/erasure, 11 widgets and reference rewrite precedence.\n";
