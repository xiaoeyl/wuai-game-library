<?php
// Dedicated local integration suite. Parent executes serially, not this agent.
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('WP_QA_ROOT');if(!$root || !is_file($root.'/wp-load.php'))throw new RuntimeException('WP_QA_ROOT required.');
$_SERVER['HTTP_HOST']='127.0.0.1:8771';$_SERVER['REQUEST_URI']='/';require $root.'/wp-load.php';
if(home_url()!=='http://127.0.0.1:8771')throw new RuntimeException('Local QA only.');
if(!function_exists('wuai_sluyu_guess_answer'))throw new RuntimeException('Integrate guess-answer module before testing.');
function guess_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function guess_act($uid,$op,$data=[]){wp_set_current_user($uid);$_POST=wp_slash($data);return wuai_sluyu_community_operation($op,$uid);}
function guess_save($uid,$id,$secret,$correct='2',$extra=[]){wp_set_current_user($uid);return wuai_sluyu_guess_save($id,array_merge(['guess_choices'=>"Red\nGreen\nBlue",'guess_correct'=>$correct,'guess_body'=>$secret,'stamp'=>wuai_sluyu_guess_stamp($id)],$extra));}
function guess_context($id,$mode='single'){$query=new WP_Query();$query->is_singular=$mode==='single';$query->is_single=$mode==='single';$query->is_feed=$mode==='feed';$query->is_embed=$mode==='embed';$query->in_the_loop=true;$query->queried_object=get_post($id);$query->queried_object_id=$id;$GLOBALS['wp_query']=$query;$GLOBALS['wp_the_query']=$query;$GLOBALS['post']=get_post($id);}
$old_user=get_current_user_id();$old_post=$_POST;$old_get=$_GET;$old_query=$GLOBALS['wp_query'];$old_main=$GLOBALS['wp_the_query'];$old_global_post=$GLOBALS['post']??null;
$users=[];$posts=[];$terms=[];$callbacks=[];$prefix='qa_guess_'.strtolower(wp_generate_password(8,false,false));$secret='GUESS-PRIVATE-'.wp_generate_password(12,false,false);$mail_block=function(){return true;};add_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
try{
    foreach(['owner'=>'subscriber','reader'=>'subscriber','other'=>'subscriber','editor'=>'editor','author'=>'author'] as $name=>$role){$uid=wp_create_user($prefix.'_'.$name,'qa-local-only',$prefix.'_'.$name.'@example.test');guess_check(!is_wp_error($uid),'Create dedicated user.');(new WP_User($uid))->set_role($role);$users[$name]=(int)$uid;}
    $owner=$users['owner'];$reader=$users['reader'];$other=$users['other'];$editor=$users['editor'];$author=$users['author'];
    $make=function($status='publish',$extra=[])use(&$posts,$owner,$prefix){$_POST=[];$id=wp_insert_post(array_merge(['post_type'=>'sluyu_topic','post_status'=>$status,'post_author'=>$owner,'post_title'=>$prefix.' public puzzle','post_content'=>'Which colour belongs to a leaf? Public prompt only.','meta_input'=>['_wuai_topic_kind'=>'guess']],$extra),true);guess_check(!is_wp_error($id),'Create puzzle.');$posts[]=(int)$id;return (int)$id;};
    $id=$make();$draft=$make('draft');$password=$make('publish',['post_password'=>'locked']);$circle_post=$make();$author_post=$make('publish',['post_author'=>$author]);
    $body='<p>'.$secret.'</p><script>neverRun()</script><img src="https://example.test/no.png">[qa_guess_probe]';
    foreach([$id,$draft,$password,$circle_post,$author_post] as $postid){guess_check(guess_save($editor,$postid,$body)===true,'Trusted editor saves puzzle config.');guess_check(strpos(get_post_field('post_content',$postid),$secret)===false && strpos(get_post_field('post_excerpt',$postid),$secret)===false,'Secret never copied into public database columns.');}
    $config=wuai_sluyu_guess_config($id);guess_check($config && !isset($config['answer_index']) && !isset($config['answer']),'Correct index never stored in plaintext config.');guess_check(strpos($config['body'],'<script')===false && strpos($config['body'],'<img')===false && strpos($config['body'],'[qa_guess_probe]')===false,'Private snippet strips dangerous tags and shortcodes.');
    guess_check(is_wp_error(guess_save($author,$author_post,$secret)),'Native author cannot change answer config.');
    wp_set_current_user($author);guess_check(!current_user_can('edit_post_meta',$author_post,wuai_sluyu_guess_key()),'Private meta auth excludes authors.');
    wp_set_current_user($editor);guess_check(current_user_can('edit_post_meta',$author_post,wuai_sluyu_guess_key()),'Trusted editor can manage config.');
    guess_check(is_wp_error(wuai_sluyu_guess_validate(['guess_choices'=>"One\nOne",'guess_correct'=>'1','guess_body'=>$secret])),'Duplicate choices denied.');
    guess_check(is_wp_error(wuai_sluyu_guess_validate(['guess_choices'=>['One','Two'],'guess_correct'=>'1','guess_body'=>$secret])),'Nested choice field denied.');
    guess_check(is_wp_error(wuai_sluyu_guess_validate(['guess_choices'=>"One\nTwo",'guess_correct'=>'3','guess_body'=>$secret])),'Out-of-range answer denied.');
    guess_check(is_wp_error(wuai_sluyu_guess_validate(['guess_choices'=>"One\n\nTwo",'guess_correct'=>'2','guess_body'=>$secret])),'Empty middle choice cannot silently shift the correct index.');
    guess_check(!is_wp_error(wuai_sluyu_guess_validate(['guess_choices'=>"0\n1",'guess_correct'=>'1','guess_body'=>$secret])),'Numeric zero is a valid choice.');
    guess_check(!wuai_sluyu_guess_state($id,0)['allowed'],'Guest cannot read hidden snippet.');
    guess_check(wuai_sluyu_guess_state($id,$owner)['preview'],'Author has labeled preview, not earned entitlement.');
    guess_check(!wuai_sluyu_guess_state($draft,$owner)['allowed'] && wuai_sluyu_guess_state($draft,$owner,true)['allowed'],'Draft owner requires explicit preview.');
    guess_check(!wuai_sluyu_guess_state($draft,$reader,true)['allowed'] && !wuai_sluyu_guess_state($password,$editor,true)['allowed'],'Foreign draft and password posts fail closed.');
    wp_set_current_user($reader);guess_check(strpos(wuai_sluyu_guess_view($id),$secret)===false,'Initial reader view has no secret.');
    $balance=wuai_sluyu_points($reader);$lock=wuai_sluyu_lock('user_'.$reader);guess_check(is_wp_error(wuai_sluyu_guess_answer($id,'1',$config['version'])),'Direct helper respects user lock.');wuai_sluyu_unlock($lock);
    $lock=wuai_sluyu_lock('poll_'.$id);guess_check(is_wp_error(wuai_sluyu_guess_answer($id,'1',$config['version'])),'Post lock prevents guessing during editor changes.');wuai_sluyu_unlock($lock);
    guess_check(is_wp_error(wuai_sluyu_guess_answer($id,['1'],$config['version'])),'Nested submitted answer denied.');
    guess_check(is_wp_error(wuai_sluyu_guess_answer($id,'1','stale')),'Stale version denied without attempt.');
    $wrong=wuai_sluyu_guess_answer($id,'0',$config['version']);guess_check(is_wp_error($wrong) && $wrong->get_error_code()==='guess_wrong','Wrong guess is recorded.');
    guess_check(strpos($wrong->get_error_message(),$secret)===false && strpos($wrong->get_error_message(),'Green')===false,'Wrong response reveals no correct answer or hidden snippet.');
    guess_check(wuai_sluyu_guess_attempts($id,$reader,$config['version'])['failures']===1,'One wrong attempt persisted.');
    guess_check(wuai_sluyu_guess_answer($id,'1',$config['version'])->get_error_code()==='guess_cooldown','Immediate next guess respects cooldown even when correct.');
    $attempt=wuai_sluyu_guess_attempts($id,$reader,$config['version']);$attempt['last_error']=time()-31;update_user_meta($reader,'_wuai_guess_attempts_'.$id,$attempt);
    $solved=wuai_sluyu_guess_answer($id,'1',$config['version']);guess_check(!is_wp_error($solved) && $solved['solved'],'Correct choice grants entitlement.');
    guess_check(wuai_sluyu_points($reader)===$balance,'Guessing never charges or rewards points.');
    guess_check(wuai_sluyu_guess_state($id,$reader)['solved'] && strpos(wuai_sluyu_guess_view($id),$secret)!==false,'Only saved correct result reveals snippet.');
    $repeat=wuai_sluyu_guess_answer($id,'1',$config['version']);guess_check(!is_wp_error($repeat),'Repeated correct answer is idempotent.');
    $events=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.wuai_sluyu_table('events')." WHERE user_id=%d AND event_key=%s",$reader,wuai_sluyu_guess_event_key($id,$config)));guess_check(count($events)===1 && (int)$events[0]->points===0 && $events[0]->event_type==='guess','Exactly one zero-point version-bound entitlement.');
    guess_check(!wuai_sluyu_guess_state($id,$other)['allowed'],'Other account gets no shared unlock.');
    update_user_meta($reader,'_wuai_banned_until',-1);guess_check(!wuai_sluyu_guess_state($id,$reader)['allowed'],'Ban overrides correct entitlement.');delete_user_meta($reader,'_wuai_banned_until');
    wp_set_current_user($other);for($i=0;$i<5;$i++){$result=wuai_sluyu_guess_answer($id,'0',$config['version']);guess_check(is_wp_error($result) && $result->get_error_code()==='guess_wrong','Wrong guess within max recorded.');$attempt=wuai_sluyu_guess_attempts($id,$other,$config['version']);$attempt['last_error']=time()-31;update_user_meta($other,'_wuai_guess_attempts_'.$id,$attempt);}
    guess_check(wuai_sluyu_guess_answer($id,'1',$config['version'])->get_error_code()==='guess_limit','Five failures deny further attempts including known-correct answer.');
    $old_stamp=wuai_sluyu_guess_stamp($id);guess_check(guess_save($editor,$id,$body)===true && wuai_sluyu_guess_config($id)['version']===$config['version'],'No-op editor save preserves version.');
    guess_check(guess_save($editor,$id,$secret.' revised private snippet','3')===true,'Answer/body edit saved.');$new=wuai_sluyu_guess_config($id);guess_check($new['version']!==$config['version'] && !wuai_sluyu_guess_state($id,$reader)['allowed'],'Config edit rotates edition and invalidates prior entitlement.');
    guess_check(is_wp_error(guess_save($editor,$id,$body,'2',['stamp'=>$old_stamp])),'Stale editor stamp cannot overwrite revision.');
    wp_set_current_user($other);guess_check(wuai_sluyu_guess_attempts($id,$other,$new['version'])['failures']===0,'New version has a fresh limit.');guess_check(!is_wp_error(wuai_sluyu_guess_answer($id,'2',$new['version'])),'New version correct choice is independently earned.');
    $_POST=[];wp_set_current_user($editor);wp_update_post(['ID'=>$id,'post_content'=>'Changed public riddle requiring review.']);guess_check(!wuai_sluyu_guess_config($id) && !wuai_sluyu_guess_state($id,$other)['allowed'],'Public prompt changes without companion config save fail closed.');
    guess_check(guess_save($editor,$id,$secret.' revised private snippet','3')===true,'Saving companion config binds changed prompt and rotates version.');

    $circle=wp_insert_term($prefix.'_circle','sluyu_circle');guess_check(!is_wp_error($circle),'Create circle.');$circle=(int)$circle['term_id'];$terms[]=$circle;update_term_meta($circle,'_wuai_circle_owner',$owner);update_term_meta($circle,'_wuai_circle_status','publish');update_term_meta($circle,'_wuai_circle_posting','members');wp_set_object_terms($circle_post,[$circle],'sluyu_circle');
    wp_set_current_user($reader);$circle_config=wuai_sluyu_guess_config($circle_post);guess_check(is_wp_error(wuai_sluyu_guess_answer($circle_post,'1',$circle_config['version'])),'Circle nonmember cannot guess.');
    $wpdb->replace(wuai_sluyu_table('memberships'),['circle_id'=>$circle,'user_id'=>$reader,'status'=>'active','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);guess_check(!is_wp_error(wuai_sluyu_guess_answer($circle_post,'1',$circle_config['version'])),'Circle member can correctly unlock.');
    update_term_meta($circle,'_wuai_circle_status','pending');guess_check(!wuai_sluyu_guess_state($circle_post,$reader)['allowed'],'Hidden pending taxonomy cannot disappear from permission checks.');update_term_meta($circle,'_wuai_circle_status','publish');
    $wpdb->update(wuai_sluyu_table('memberships'),['status'=>'blocked'],['circle_id'=>$circle,'user_id'=>$reader]);guess_check(!wuai_sluyu_guess_state($circle_post,$reader)['allowed'],'Blocked circle member loses access even after success.');
    $wpdb->update(wuai_sluyu_table('memberships'),['status'=>'active'],['circle_id'=>$circle,'user_id'=>$reader]);

    $storage=$make();guess_check(guess_save($editor,$storage,$body)===true,'Storage failure fixture configured.');wp_set_current_user($reader);$storage_config=wuai_sluyu_guess_config($storage);
    $block_attempt=function($check,$uid,$key){return strpos($key,'_wuai_guess_attempts_')===0?false:$check;};add_filter('update_user_metadata',$block_attempt,10,3);$callbacks[]=['update_user_metadata',$block_attempt,10];
    $failed=wuai_sluyu_guess_answer($storage,'0',$storage_config['version']);guess_check(is_wp_error($failed) && $failed->get_error_code()==='guess_storage' && !wuai_sluyu_guess_solved($storage,$reader),'Attempt write failure never grants access or reports a saved wrong attempt.');remove_filter('update_user_metadata',$block_attempt,10);$callbacks=[];
    add_filter('update_user_metadata',$block_attempt,10,3);$callbacks[]=['update_user_metadata',$block_attempt,10];$failed=wuai_sluyu_guess_answer($storage,'1',$storage_config['version']);guess_check(is_wp_error($failed) && $failed->get_error_code()==='guess_storage' && !wuai_sluyu_guess_solved($storage,$reader),'Correct selection also cannot bypass failed attempt persistence.');remove_filter('update_user_metadata',$block_attempt,10);$callbacks=[];
    $block_event=function($query)use($wpdb){if(strpos($query,'INSERT INTO `'.wuai_sluyu_table('events').'`')===0 && strpos($query,"'guess:")!==false)return 'SELECT 0';return $query;};add_filter('query',$block_event);$callbacks[]=['query',$block_event,10];
    $failed=wuai_sluyu_guess_answer($storage,'1',$storage_config['version']);guess_check(is_wp_error($failed) && !wuai_sluyu_guess_solved($storage,$reader),'Correct-answer entitlement write failure remains locked.');remove_filter('query',$block_event);$callbacks=[];
    update_user_meta($reader,'_wuai_guess_attempts_'.$storage,['bad'=>'state']);guess_check(is_wp_error(wuai_sluyu_guess_answer($storage,'1',$storage_config['version'])),'Malformed persistent attempt record fails closed.');delete_user_meta($reader,'_wuai_guess_attempts_'.$storage);

    $data=['title'=>'QA guess submitted','body'=>'This public mystery contains no secret.','guess_choices'=>"North\nSouth\nEast",'guess_correct'=>'2','guess_body'=>$secret.' submitted','group'=>(string)$circle];
    wp_set_current_user($owner);
    foreach([['group'=>[$circle]],['circle_id'=>[$circle]],['group'=>'0','circle_id'=>[$circle]]] as $query){$_GET=$query;ob_start();wuai_sluyu_view_guess_submit();$form=ob_get_clean();guess_check(strpos($form,'data-operation="guess_submit"')===false,'Nested group query or legacy alias cannot silently show an ungrouped submission form.');}
    $_GET=[];guess_check(is_wp_error(guess_act($owner,'guess_submit',array_merge($data,['group'=>[$circle]]))),'Nested submitted group cannot silently create an ungrouped topic.');
    $submitted=guess_act($owner,'guess_submit',$data);guess_check(!is_wp_error($submitted),'Frontend private puzzle submission accepted.');
    $submitted_posts=get_posts(['post_type'=>'sluyu_topic','post_status'=>'pending','author'=>$owner,'numberposts'=>-1]);$found=0;
    foreach($submitted_posts as $post){if($post->post_title==='QA guess submitted'){$posts[]=$post->ID;$found=$post->ID;}}
    guess_check($found && wuai_sluyu_guess_config($found) && strpos(get_post_field('post_content',$found),$secret)===false,'Pending submission keeps body private and verifies group.');
    guess_check(array_map('intval',wp_get_object_terms($found,'sluyu_circle',['fields'=>'ids','wuai_include_pending'=>true]))===[$circle],'Circle assignment retained before review.');
    guess_check(is_wp_error(wuai_sluyu_account_submission_permission(get_post($found),$owner,'edit_submission')),'Generic account edit cannot change puzzle meaning outside versioning.');
    guess_check(is_wp_error(guess_act($other,'guess_submit',$data)),'Nonmember cannot submit into private participation circle.');
    delete_transient('wuai_guess_submit_'.$owner);$reject_meta=function($check,$pid,$key){return $key===wuai_sluyu_guess_key()?false:$check;};add_filter('update_post_metadata',$reject_meta,10,3);$callbacks[]=['update_post_metadata',$reject_meta,10];
    $partial=guess_act($owner,'guess_submit',array_merge($data,['title'=>'QA guess partial save']));guess_check(is_wp_error($partial),'Partial private metadata save rejected.');remove_filter('update_post_metadata',$reject_meta,10);$callbacks=[];
    $partial_posts=get_posts(['post_type'=>'sluyu_topic','post_status'=>'draft','author'=>$owner,'numberposts'=>-1]);$partial_found=false;foreach($partial_posts as $post){if($post->post_title==='QA guess partial save'){$posts[]=$post->ID;$partial_found=true;guess_check(!wuai_sluyu_guess_config($post->ID),'Partial submission never has valid public gate.');}}guess_check($partial_found,'Partial save is kept as draft, not pending/public.');
    delete_transient('wuai_guess_submit_'.$owner);
    $reject_group=function($postid,$terms_arg,$ttids,$taxonomy)use($wpdb){if($taxonomy==='sluyu_circle' && get_the_title($postid)==='QA guess group save failure'){$wpdb->delete($wpdb->term_relationships,['object_id'=>$postid]);clean_object_term_cache($postid,'sluyu_topic');}};
    add_action('set_object_terms',$reject_group,20,4);$callbacks[]=['set_object_terms',$reject_group,20];
    $group_failed=guess_act($owner,'guess_submit',array_merge($data,['title'=>'QA guess group save failure']));guess_check(is_wp_error($group_failed) && $group_failed->get_error_code()==='guess_group','Failed taxonomy readback cannot become ungrouped public submission.');remove_action('set_object_terms',$reject_group,20);$callbacks=[];
    $group_drafts=get_posts(['post_type'=>'sluyu_topic','post_status'=>'draft','author'=>$owner,'numberposts'=>-1]);$group_found=false;foreach($group_drafts as $post){if($post->post_title==='QA guess group save failure'){$posts[]=$post->ID;$group_found=true;}}guess_check($group_found,'Failed group assignment stays draft.');

    wp_set_current_user($reader);guess_check(!is_wp_error(wuai_sluyu_guess_answer($storage,'1',$storage_config['version'])),'Restore normal storage and solve rendering fixture.');
    $ran=0;add_shortcode('qa_guess_probe',function()use(&$ran){$ran++;return 'GUESS-SHORTCODE-RAN';});guess_context($storage);
    $html=apply_filters('the_content',get_post_field('post_content',$storage));guess_check(strpos($html,$secret)!==false && $ran===0,'Authorized main-loop HTML appends sanitized private fragment only.');
    guess_check(strpos(get_the_excerpt($storage),$secret)===false,'Excerpt remains public even in authorized single context.');
    wp_set_current_user(0);guess_check(strpos(apply_filters('the_content',get_post_field('post_content',$storage)),$secret)===false,'Guest HTML cannot see fragment.');
    wp_set_current_user($reader);foreach(['archive','feed','embed'] as $mode){guess_context($storage,$mode);guess_check(strpos(apply_filters('the_content',get_post_field('post_content',$storage)),$secret)===false,'Non-single context excludes fragment: '.$mode);}
    guess_context($storage);foreach([0,$reader,$editor] as $uid){wp_set_current_user($uid);$response=rest_do_request(new WP_REST_Request('GET','/wp/v2/sluyu_topic/'.$storage));guess_check($response->get_status()===200,'Public REST puzzle remains readable.');$json=wp_json_encode($response->get_data());guess_check(strpos($json,$secret)===false && strpos($json,'answer_hash')===false && strpos($json,wuai_sluyu_guess_key())===false,'REST excludes config/answer/snippet for all roles.');}
    $query=new WP_Query(['s'=>$secret,'post_type'=>'sluyu_topic','post_status'=>'publish','fields'=>'ids']);guess_check(!$query->posts,'Search never indexes private fragment.');guess_check((int)($GLOBALS['wuai_sluyu_guess_rest_depth']??0)===0,'REST guard counter restored.');
    $export=wuai_sluyu_guess_export(get_userdata($reader)->user_email);guess_check(!empty($export['data']) && strpos(wp_json_encode($export),$secret)===false,'Privacy export covers attempts without revealing answer/snippet.');guess_check(wuai_sluyu_guess_erase(get_userdata($reader)->user_email)['items_retained'],'Attempt-limit retention is disclosed.');
    wp_delete_post($storage,true);guess_check(!metadata_exists('user',$reader,'_wuai_guess_attempts_'.$storage),'Permanent puzzle deletion cleans obsolete attempts.');
    echo "PASS: private guess config, versioned zero-point entitlements, locks, strict eligibility, persistent failure/cooldown limits, version rotation/stale saves, moderated complete submissions and REST/feed/excerpt/search isolation.\n";
}finally{
    foreach($callbacks as $callback)remove_filter($callback[0],$callback[1],$callback[2]);$_POST=[];remove_shortcode('qa_guess_probe');
    $GLOBALS['wp_query']=$old_query;$GLOBALS['wp_the_query']=$old_main;$GLOBALS['post']=$old_global_post;
    foreach($posts as $postid)wp_delete_post($postid,true);foreach($terms as $termid)wp_delete_term($termid,'sluyu_circle');
    require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $uid){delete_transient('wuai_guess_submit_'.$uid);delete_transient('wuai_guess_notice_'.$uid);wp_delete_user($uid);}
    remove_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);$_POST=$old_post;$_GET=$old_get;wp_set_current_user($old_user);
}
