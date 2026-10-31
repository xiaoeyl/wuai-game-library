<?php
// Integration tests only for the disposable local WordPress installation.
if (PHP_SAPI !== 'cli') { exit(1); }
$root=getenv('WP_QA_ROOT'); if (!$root) { throw new RuntimeException('WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST']='127.0.0.1:8771'; $_SERVER['REQUEST_URI']='/'; require $root.'/wp-load.php';
if (home_url()!=='http://127.0.0.1:8771') { throw new RuntimeException('Local QA only.'); }
function cp_check($condition,$message) { if (!$condition) { throw new RuntimeException($message); } }
function cp_action($uid,$operation,$input=[]) { wp_set_current_user($uid); $_POST=$input; return wuai_sluyu_community_operation($operation,$uid); }
$users=[];
foreach (['owner','participant','outsider'] as $role) {
    $login='qa_circle_'.$role; $user=get_user_by('login',$login);
    $id=$user?$user->ID:wp_create_user($login,'qa-local-only',$login.'@example.test');
    (new WP_User($id))->set_role('subscriber'); $users[$role]=$id;
    foreach (['_wuai_banned_until','_wuai_member_until'] as $key) { delete_user_meta($id,$key); }
    $wpdb->delete(wuai_sluyu_table('memberships'),['user_id'=>$id]);
    $wpdb->delete(wuai_sluyu_table('events'),['user_id'=>$id]);
    foreach (['submit','circle_create'] as $op) { delete_transient('wuai_rate_'.$id.'_'.$op); }
}
$admin=get_user_by('login','qa_admin')->ID;
$owner=$users['owner']; $participant=$users['participant']; $outsider=$users['outsider'];
wp_set_current_user($admin);
foreach (get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false,'wuai_include_pending'=>true]) as $term) { if (strpos($term->name,'QA-CIRCLE-')===0) { wp_delete_term($term->term_id,'sluyu_circle'); } }
foreach (get_posts(['post_type'=>'sluyu_topic','post_status'=>'any','author'=>$owner,'numberposts'=>-1]) as $post) { wp_delete_post($post->ID,true); }
$created=cp_action($owner,'circle_create',['title'=>'QA-CIRCLE-Approval','body'=>'A local approval-only community used for integration tests.','join_mode'=>'approval']);
cp_check(!is_wp_error($created),'Circle creation succeeds.');
$terms=get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false,'wuai_include_pending'=>true,'meta_query'=>[['key'=>'_wuai_circle_owner','value'=>$owner]]]);
cp_check(count($terms)===1,'Owner application is stored.'); $circle=$terms[0]->term_id;
cp_check(is_wp_error(cp_action($outsider,'circle_create',['title'=>'QA-CIRCLE-Approval','body'=>'Attempt to take ownership of a pending application.'])),'Duplicate pending name cannot be created by another user.');
cp_check((int)get_term_meta($circle,'_wuai_circle_owner',true)===$owner,'Original pending owner is never replaced.');
cp_check(!wuai_sluyu_circle($circle),'Pending circle is not public.');
cp_check(!in_array($circle,get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false,'fields'=>'ids'])),'Pending circle omitted from lists.');
wp_set_current_user(0); $request=new WP_REST_Request('GET','/wp/v2/sluyu_circle/'.$circle); $response=rest_do_request($request);
cp_check($response->get_status()===404,'Direct REST cannot expose pending circle.');
$request=new WP_REST_Request('GET','/wp/v2/sluyu_circle'); $request->set_param('search','QA-CIRCLE-Approval'); $response=rest_do_request($request);
cp_check($response->get_status()===200 && !$response->get_data(),'REST circle collection omits pending names.');
cp_check(is_wp_error(cp_action($participant,'circle_join',['id'=>$circle])),'Pending join denied.');
$_GET=['scope'=>'owned']; wp_set_current_user($owner); ob_start(); wuai_sluyu_view_circles(); $owned=ob_get_clean(); cp_check(strpos($owned,'QA-CIRCLE-Approval')!==false,'Owner can see own application.');
$_GET=['scope'=>'joined']; wp_set_current_user($outsider); ob_start(); wuai_sluyu_view_circles(); $empty=ob_get_clean(); cp_check(strpos($empty,'QA-CIRCLE-Approval')===false,'Empty joined list never includes unrelated term.');
wp_set_current_user($admin); $_POST=['wuai_circle_nonce'=>wp_create_nonce('wuai_circle_admin'),'_wuai_circle_status'=>'publish','_wuai_circle_join'=>'approval','_wuai_circle_posting'=>'members','_wuai_circle_owner'=>$owner]; wuai_sluyu_circle_admin_save($circle);
cp_check((bool)wuai_sluyu_circle($circle),'Administrator approval opens circle.');
cp_check(!is_wp_error(cp_action($participant,'circle_join',['id'=>$circle])),'Application accepted.');
cp_check(wuai_sluyu_circle_membership($circle,$participant)==='pending','Join approval required.');
cp_check(!wuai_sluyu_circle_can_post($circle,$participant),'Pending applicant cannot post.');
cp_check(is_wp_error(cp_action($outsider,'circle_member',['id'=>$circle,'user_id'=>$participant,'decision'=>'active'])),'Non-owner cannot approve.');
cp_check(!is_wp_error(cp_action($owner,'circle_member',['id'=>$circle,'user_id'=>$participant,'decision'=>'active'])),'Owner approval succeeds.');
cp_check(wuai_sluyu_circle_count($circle)===1 && wuai_sluyu_circle_can_post($circle,$participant),'Approved member can post.');
cp_check(!is_wp_error(cp_action($owner,'circle_member',['id'=>$circle,'user_id'=>$participant,'decision'=>'blocked'])),'Block succeeds.');
cp_check(is_wp_error(cp_action($participant,'circle_leave',['id'=>$circle])),'Blocked status cannot be self-erased.');
cp_check(is_wp_error(cp_action($participant,'circle_join',['id'=>$circle])),'Blocked participant cannot rejoin.');
cp_action($owner,'circle_member',['id'=>$circle,'user_id'=>$participant,'decision'=>'active']);
update_term_meta($circle,'_wuai_circle_join','member');
cp_check(!wuai_sluyu_circle_can_post($circle,$participant),'Expired membership loses participation.');
update_user_meta($participant,'_wuai_member_until',time()+DAY_IN_SECONDS);
cp_check(wuai_sluyu_circle_can_post($circle,$participant),'Valid membership restores participation.');
update_term_meta($circle,'_wuai_circle_join','approval');
cp_check(is_wp_error(cp_action($outsider,'submit',['kind'=>'topic','title'=>'Should fail','body'=>'Not a circle member','group'=>$circle])),'Submission cannot bypass circle membership.');
$_POST=['kind'=>'poll','title'=>'QA-CIRCLE-Multiple','body'=>'Select up to two test options.','choices'=>"Zero\nOne\nTwo",'poll_mode'=>'multiple','poll_max'=>'2','poll_deadline'=>wp_date('Y-m-d\TH:i',time()+DAY_IN_SECONDS),'group'=>$circle];
$submission=cp_action($owner,'submit',$_POST); cp_check(!is_wp_error($submission),'Multiple-choice submission accepted.');
$posts=get_posts(['post_type'=>'sluyu_topic','post_status'=>'pending','author'=>$owner,'numberposts'=>1]); $poll=$posts[0]->ID;
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>['0','2']])),'Pending poll cannot be voted on.');
wp_update_post(['ID'=>$poll,'post_status'=>'publish']);
cp_check(is_wp_error(cp_action($outsider,'vote',['id'=>$poll,'choice'=>['0']])),'Non-member vote denied.');
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>['0','1','2']])),'Selection limit enforced.');
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>[['0']]])),'Nested input rejected.');
cp_check(!is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>['0','2']])),'Multi-choice ballot saved.');
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>['1']])),'Repeated ballot denied.');
cp_check(!is_wp_error(cp_action($owner,'vote',['id'=>$poll,'choice'=>'0'])),'Legacy single-value payload accepted.');
$tally=wuai_sluyu_poll_tally($poll); cp_check($tally['counts']===[2,0,1] && $tally['voters']===2,'Actual ballot counts are accurate.');
cp_check(is_wp_error(cp_action($outsider,'poll_close',['id'=>$poll])),'Outsider cannot close poll.');
wp_set_current_user($admin); $_POST=['wuai_service_meta_nonce'=>wp_create_nonce('wuai_service_meta'),'wuai_topic_kind'=>'topic','wuai_poll_mode'=>'single','wuai_poll_choices'=>"Changed\nWrong",'wuai_poll_max'=>'1']; wp_update_post(['ID'=>$poll,'post_title'=>'QA-CIRCLE-Multiple']);
cp_check(wuai_sluyu_poll($poll)['mode']==='multiple' && wuai_sluyu_poll($poll)['choices']===['Zero','One','Two'],'Poll configuration locks after votes.');
cp_check(!is_wp_error(cp_action($owner,'poll_close',['id'=>$poll])),'Owner can close poll.');
cp_check(wuai_sluyu_poll($poll)['closed'],'Closed status persisted.');
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>'1'])),'Closed poll rejects ballots.');
$pk=wp_insert_post(['post_type'=>'sluyu_topic','post_status'=>'publish','post_author'=>$owner,'post_title'=>'QA-CIRCLE-PK','post_content'=>'Choose your side.','meta_input'=>['_wuai_topic_kind'=>'poll','_wuai_poll_choices'=>['Left','Right'],'_wuai_poll_mode'=>'pk']]);
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$pk,'choice'=>['0','1']])),'PK does not allow multiple selections.');
cp_check(!is_wp_error(cp_action($participant,'vote',['id'=>$pk,'choice'=>'1'])),'PK vote saved.');
update_post_meta($pk,'_wuai_poll_deadline',time()-60); cp_check(is_wp_error(cp_action($outsider,'vote',['id'=>$pk,'choice'=>'0'])),'Expired poll rejects ballots.');
$browser_poll=wp_insert_post(['post_type'=>'sluyu_topic','post_status'=>'publish','post_author'=>$owner,'post_title'=>'QA-CIRCLE-Browser Multi','post_content'=>'A browser-tested multiple-choice ballot.','meta_input'=>['_wuai_topic_kind'=>'poll','_wuai_poll_choices'=>['Morning','Evening','Weekend'],'_wuai_poll_mode'=>'multiple','_wuai_poll_max'=>2]]);
$open=wp_insert_term('QA-CIRCLE-Open','sluyu_circle',['description'=>'A public circle for browser join and leave tests.']); $open=$open['term_id']; update_term_meta($open,'_wuai_circle_status','publish');update_term_meta($open,'_wuai_circle_owner',$owner);update_term_meta($open,'_wuai_circle_posting','members');
cp_check(!wuai_sluyu_parse_date('2026-02-30'),'Overflow date denied.'); cp_check(!wuai_sluyu_parse_date('tomorrow'),'Relative date denied.'); cp_check(!wuai_sluyu_parse_date(['bad']),'Non-scalar date denied.');
$_GET=['pg'=>['bad'],'kind'=>['bad']]; cp_check(wuai_sluyu_page_number()===1 && wuai_sluyu_query('kind')==='','Nested query parameters safe.');
$manifest=['users'=>$users,'circle'=>$circle,'open'=>$open,'poll'=>$browser_poll,'closed'=>$poll,'pk'=>$pk];
file_put_contents(dirname($root).'/circles-fixture.json',wp_json_encode($manifest));
echo "PASS: circle moderation/membership/owner checks, pending visibility, blocked and expired users, multiple/PK ballots, legacy payloads, deadlines, repeat voting, locked poll metadata and malformed input.\n";
