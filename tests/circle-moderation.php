<?php
// Run only after integrating the module and its shared held-post guards.
if (PHP_SAPI !== 'cli') { exit(1); }
$root=getenv('WP_QA_ROOT');
if(!$root || !is_file($root.'/wp-load.php')) { throw new RuntimeException('WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST']='127.0.0.1:8771'; $_SERVER['REQUEST_URI']='/'; require $root.'/wp-load.php';
if(home_url()!=='http://127.0.0.1:8771') { throw new RuntimeException('Local QA only.'); }
if(!function_exists('wuai_sluyu_circle_review_held')) { throw new RuntimeException('Integrate circle-moderation.php first.'); }
function cm_check($condition,$message) { if(!$condition)throw new RuntimeException($message); }
function cm_ids($query) { return array_map('intval',wp_list_pluck($query->posts,'ID')); }
function cm_as($uid) { wp_set_current_user($uid); $_POST=[]; }
function cm_circle($owner,$suffix) {
    global $cm_terms,$cm_prefix,$cm_admin;
    $before=get_current_user_id(); cm_as($cm_admin);
    $term=wp_insert_term($cm_prefix.' '.$suffix,'sluyu_circle',['slug'=>$cm_prefix.'-'.$suffix]);
    cm_check(!is_wp_error($term),'Create isolated circle.'); $id=(int)$term['term_id']; $cm_terms[]=$id;
    foreach(['_wuai_circle_status'=>'publish','_wuai_circle_owner'=>(string)$owner,'_wuai_circle_join'=>'open','_wuai_circle_posting'=>'everyone'] as $key=>$value)update_term_meta($id,$key,$value);
    cm_as($before); return $id;
}
function cm_topic($author,$groups,$suffix,$status='pending',$extra=[]) {
    global $cm_posts,$cm_prefix,$cm_admin;
    $before=get_current_user_id(); cm_as($cm_admin);
    $id=wp_insert_post(wp_slash(array_merge(['post_type'=>'sluyu_topic','post_author'=>$author,'post_status'=>$status,'post_title'=>$cm_prefix.' '.$suffix,'post_content'=>'Harmless public review text for '.$suffix.'.','comment_status'=>'open','meta_input'=>['_wuai_topic_kind'=>'topic']],$extra)),true);
    cm_check(!is_wp_error($id) && $id,'Create isolated review topic.'); $id=(int)$id; $cm_posts[]=$id;
    $assigned=wp_set_object_terms($id,array_map('intval',(array)$groups),'sluyu_circle'); cm_check(!is_wp_error($assigned),'Assign exact fixture circles.');
    cm_as($before); return $id;
}
function cm_review($uid,$id,$group,$decision='approve',$reason='',$stamp=null) {
    cm_as($uid); return wuai_sluyu_circle_review($id,$group,$decision,$reason,$stamp===null?wuai_sluyu_circle_review_stamp($id,$group):$stamp);
}
function cm_notices($uid) {
    global $wpdb; return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.wuai_sluyu_table('messages')." WHERE recipient_id=%d AND kind='notice'",$uid));
}
function cm_setting($enabled) {
    global $cm_admin; cm_as($cm_admin);
    $result=wuai_sluyu_circle_review_settings_save(['owner_publish'=>$enabled,'stamp'=>wuai_sluyu_circle_review_settings_stamp()]); cm_check($result===true,'Save explicit owner publication setting.');
}
function cm_unlocked($uid,$id,$groups) {
    foreach(array_merge(['user_'.$uid,'poll_'.$id],array_map(function($id){return 'circle_'.$id;},(array)$groups),['circle_review_settings']) as $key) {
        $lock=wuai_sluyu_lock($key); cm_check((bool)$lock,'Operation releases lock '.$key); wuai_sluyu_unlock($lock);
    }
}
function cm_fault($id,$key,$hook,$predicate,$operation) {
    $hits=0;
    $block=function($check,$object_id,$meta_key,$value)use($id,$key,$predicate,&$hits){
        if((int)$object_id===$id && $meta_key===$key && (!$predicate || $predicate($value))) { $hits++; return false; }
        return $check;
    };
    add_filter($hook,$block,999,4);
    try { $result=$operation(); } finally { remove_filter($hook,$block,999); }
    cm_check($hits>0,'Exercise exact metadata fault '.$hook.'/'.$key); return $result;
}
function cm_applied($value) { return is_array($value) && $value && end($value)['phase']==='applied'; }
function cm_rest($path,$parameters=[]) {
    $request=new WP_REST_Request('GET',$path); foreach($parameters as $key=>$value)$request->set_param($key,$value); return rest_do_request($request);
}
function cm_held_isolation($id,$group,$viewer) {
    cm_as(0); $post=get_post($id); cm_check($post->post_status==='publish' && wuai_sluyu_circle_review_held($id),'Isolation fixture really is published with a durable hold.');
    foreach([
        ['post_type'=>'sluyu_topic','post_status'=>'publish','post__in'=>[$id]],
        ['post_type'=>'any','s'=>$post->post_title],
        ['s'=>$post->post_title],
        ['tax_query'=>[['taxonomy'=>'sluyu_circle','terms'=>[$group],'include_children'=>false]],'post__in'=>[$id]],
        ['post_type'=>'sluyu_topic','feed'=>'rss2','post__in'=>[$id]],
        ['post_type'=>'sluyu_topic','p'=>$id],
        array_merge(wuai_sluyu_circle_feed_query_args($group),['post__in'=>[$id]]),
    ] as $args) { $query=new WP_Query($args); cm_check(!$query->post_count && (int)$query->found_posts===0,'Held content is excluded before public rows and counts: '.wp_json_encode($args).' SQL='.$query->request); }
    cm_check(!get_posts(['post_type'=>'sluyu_topic','post_status'=>'publish','include'=>[$id]]),'get_posts suppress_filters default cannot bypass the hold.');
    cm_check(cm_rest('/wp/v2/sluyu_topic/'.$id)->get_status()===404,'Held REST detail is inaccessible.');
    $response=cm_rest('/wp/v2/sluyu_topic',['include'=>[$id]]);
    cm_check($response->get_status()===200 && !$response->get_data(),'Held REST collection has no item.');
    cm_check(get_the_excerpt($id)==='' && wuai_sluyu_excerpt($id)==='','Empty excerpt cannot fall back to held raw content.');
    $previous=$GLOBALS['post']??null; $GLOBALS['post']=$post;
    try { cm_check(apply_filters('the_content',$post->post_content)==='','Content filters do not render held text.'); }
    finally { $GLOBALS['post']=$previous; }
    cm_check(!wuai_sluyu_public_post($id,['sluyu_topic']),'Shared public-post lookup rejects held IDs.');
    cm_as($viewer); $like=cm_ajax($viewer,['operation'=>'like','post_id'=>(string)$id,'nonce'=>wp_create_nonce('wuai_sluyu')],'POST','wuai_sluyu_frontend_action');
    cm_check($like['success']===false && get_user_meta($viewer,'_wuai_sluyu_like_'.$id,true)!=='1','Real frontend like endpoint cannot act on a held published ID.');
    cm_check(!wuai_sluyu_circle_feature_permission($id,$group),'Held content cannot be featured.');
    cm_as((int)$post->post_author);
    foreach(['edit_submission','withdraw_submission','delete_submission'] as $operation)cm_check(is_wp_error(wuai_sluyu_account_submission_permission($post,(int)$post->post_author,$operation)),'Author '.$operation.' cannot interfere with an unfinished held review.');
}
class CM_QA_Ajax_Exit extends RuntimeException {}
function cm_ajax($uid,$input,$method='POST',$handler_name='wuai_sluyu_community_ajax') {
    if(!defined('DOING_AJAX'))define('DOING_AJAX',true);
    cm_as($uid); $_POST=wp_slash($input); $previous=$_SERVER['REQUEST_METHOD']??null; $_SERVER['REQUEST_METHOD']=$method;
    $handler=function(){return function(){throw new CM_QA_Ajax_Exit();};}; add_filter('wp_die_ajax_handler',$handler,999); ob_start();
    try { call_user_func($handler_name); }
    catch(CM_QA_Ajax_Exit $exit) {}
    finally { $output=ob_get_clean(); remove_filter('wp_die_ajax_handler',$handler,999); if($previous===null)unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD']=$previous; }
    $result=json_decode($output,true); cm_check(is_array($result) && isset($result['success']),'AJAX produced a valid envelope.'); return $result;
}
$cm_old_user=get_current_user_id(); $cm_old_post=$_POST; $cm_old_get=$_GET; $cm_old_global_post=$GLOBALS['post']??null;
$cm_option=get_option(wuai_sluyu_circle_review_option(),null); $cm_prefix='qa_review_'.strtolower(wp_generate_password(9,false,false));
$cm_users=[]; $cm_posts=[]; $cm_terms=[]; $cm_admin=0;
try {
    foreach(['owner'=>'subscriber','other'=>'subscriber','writer'=>'subscriber','viewer'=>'subscriber','editor'=>'editor','limited'=>'editor','admin'=>'administrator'] as $name=>$role) {
        $id=wp_create_user($cm_prefix.'_'.$name,'qa-local-only',$cm_prefix.'_'.$name.'@example.test'); cm_check(!is_wp_error($id),'Create isolated review user.');
        (new WP_User($id))->set_role($role); $cm_users[$name]=(int)$id;
    }
    $owner=$cm_users['owner']; $other=$cm_users['other']; $writer=$cm_users['writer']; $viewer=$cm_users['viewer']; $editor=$cm_users['editor']; $limited=$cm_users['limited']; $cm_admin=$cm_users['admin'];
    $a=cm_circle($owner,'owner-a'); $a2=cm_circle($owner,'owner-a-two'); $b=cm_circle($other,'owner-b');
    cm_setting('0');
    cm_check(!user_can($owner,'edit_others_posts') && !user_can($owner,'publish_posts'),'Circle ownership does not grant WordPress publishing capabilities.');
    cm_check(wuai_sluyu_circle_review_editor($editor) && !wuai_sluyu_circle_review_editor($owner),'Only the complete trusted editorial role qualifies.');
    foreach(['edit_others_posts','publish_posts','manage_categories'] as $cap) {
        (new WP_User($limited))->add_cap($cap,false); clean_user_cache($limited);
        cm_check(!wuai_sluyu_circle_review_editor($limited),'Removing '.$cap.' disables trusted final review.');
        (new WP_User($limited))->remove_cap($cap); clean_user_cache($limited);
    }
    $id=cm_topic($writer,[$a],'ordinary recommendation'); cm_as($owner); $stamp=wuai_sluyu_circle_review_stamp($id,$a); $before=cm_notices($writer);
    cm_check(!is_wp_error(cm_review($owner,$id,$a,'approve','Ready for site review.',$stamp)),'Circle owner can recommend ordinary pending content.');
    $audit=wuai_sluyu_circle_review_audit($id);
    cm_check(get_post_status($id)==='pending' && count($audit)===1 && $audit[0]['action']==='recommend' && $audit[0]['phase']==='applied' && !wuai_sluyu_circle_review_held($id),'Default approval only records a fully applied recommendation.');
    cm_check(cm_notices($writer)===$before+1,'Completed recommendation sends one author notice.');
    cm_check(!is_wp_error(cm_review($owner,$id,$a,'approve','Ready for site review.',$stamp)) && cm_notices($writer)===$before+1 && count(wuai_sluyu_circle_review_audit($id))===1,'Original request retry is idempotent in both audit and notifications.');
    cm_check(!is_wp_error(cm_review($owner,$id,$a,'approve','Ready for site review.')) && cm_notices($writer)===$before+1 && count(wuai_sluyu_circle_review_audit($id))===1,'Refreshing and repeating unchanged recommendation does not spam notices or consume audit slots.');
    cm_check(is_wp_error(cm_review($editor,$id,$a,'approve','Ready for site review.',$stamp)),'Another actor cannot reuse the owner version stamp.');
    cm_check(!is_wp_error(cm_review($editor,$id,$a)) && get_post_status($id)==='publish','Trusted site editor can finalize a recommendation.');
    cm_check(cm_notices($writer)===$before+2,'Final publication does not also emit a premature generic transition notice.');
    cm_as(0); $json=wp_json_encode(cm_rest('/wp/v2/sluyu_topic/'.$id)->get_data());
    cm_check(strpos($json,wuai_sluyu_circle_review_key())===false && strpos($json,'Ready for site review.')===false && strpos($json,$audit[0]['id'])===false,'REST never exposes private review reasons or audit UUIDs.');

    $notice_topic=cm_topic($writer,[$a],'deleted notice receipt'); cm_as($owner); $stamp=wuai_sluyu_circle_review_stamp($notice_topic,$a);
    cm_check(!is_wp_error(cm_review($owner,$notice_topic,$a,'approve','',$stamp)),'Create a durable notice receipt.');
    $entry=wuai_sluyu_circle_review_audit($notice_topic)[0];
    $wpdb->query($wpdb->prepare('DELETE FROM '.wuai_sluyu_table('messages').' WHERE recipient_id=%d AND body LIKE %s',$writer,'%'.$wpdb->esc_like($entry['id']).'%')); $after_delete=cm_notices($writer);
    cm_check(!is_wp_error(cm_review($owner,$notice_topic,$a,'approve','',$stamp)) && cm_notices($writer)===$after_delete,'A replay does not recreate an author-deleted notification.');

    $notice_fault=cm_topic($writer,[$a],'notice write fault'); cm_as($owner); $stamp=wuai_sluyu_circle_review_stamp($notice_fault,$a); $before=cm_notices($writer); $notice_hits=0;
    $notice_block=function($sql)use($notice_fault,&$notice_hits){
        if(preg_match('/^INSERT\s+INTO\s+`?'.preg_quote(wuai_sluyu_table('messages'),'/').'`?/i',$sql) && strpos($sql,'#'.$notice_fault)!==false) { $notice_hits++; return 'UPDATE '.wuai_sluyu_table('messages').' SET id=id WHERE 1=0'; }
        return $sql;
    };
    add_filter('query',$notice_block,999);
    try { $result=cm_review($owner,$notice_fault,$a,'approve','',$stamp); } finally { remove_filter('query',$notice_block,999); }
    cm_check($notice_hits===1 && !is_wp_error($result) && get_post_status($notice_fault)==='pending' && cm_notices($writer)===$before && !wuai_sluyu_circle_review_held($notice_fault),'Notification write failure does not roll back an already durable recommendation or fake delivery.');
    cm_check(!is_wp_error(cm_review($owner,$notice_fault,$a,'approve','',$stamp)) && cm_notices($writer)===$before+1 && count(wuai_sluyu_circle_review_audit($notice_fault))===1,'Retry delivers a failed notice without reapplying the decision.');

    $reject=cm_topic($writer,[$a],'rejection'); cm_as($owner); $stamp=wuai_sluyu_circle_review_stamp($reject,$a); $before=cm_notices($writer);
    cm_check(is_wp_error(cm_review($owner,$reject,$a,'reject','no',$stamp)) && !metadata_exists('post',$reject,wuai_sluyu_circle_review_key()),'Short rejection reason cannot start an audit.');
    cm_check(!is_wp_error(cm_review($owner,$reject,$a,'reject','Please add the missing evidence.',$stamp)) && get_post_status($reject)==='draft','Rejected topic is returned to draft with a reason.');
    cm_check(cm_notices($writer)===$before+1 && !is_wp_error(cm_review($owner,$reject,$a,'reject','Please add the missing evidence.',$stamp)) && cm_notices($writer)===$before+1,'Rejection retry sends no duplicate notice.');
    cm_as($writer); cm_check(wuai_sluyu_account_submission_permission(get_post($reject),$writer,'edit_submission')===true,'A completed rejection returns the ordinary draft to its author for editing.');
    cm_as($cm_admin); wp_update_post(['ID'=>$reject,'post_status'=>'pending']);
    cm_check(is_wp_error(cm_review($owner,$reject,$a,'reject','Please add the missing evidence.',$stamp)) && get_post_status($reject)==='pending','A completed old rejection cannot reject a resubmitted topic again.');

    $scope=cm_topic($writer,[$a,$b],'CROSS-CIRCLE-PRIVATE-TITLE');
    cm_as($owner); cm_check(wuai_sluyu_circle_review_stamp($scope,$a)==='' && is_wp_error(wuai_sluyu_circle_review_permission($scope,$a)),'Managing one of two circles gives no topic review permission.');
    cm_check(is_wp_error(cm_review($owner,$scope,$a,'approve','',str_repeat('a',64))),'Direct action also enforces all assigned circles.');
    $qa=cm_circle($owner,'queue'); $queue=[];
    for($i=0;$i<13;$i++)$queue[]=cm_topic($writer,[$qa],'queue '.$i);
    $cross=cm_topic($writer,[$qa,$b],'CROSS-QUEUE-HIDDEN');
    cm_as($owner); $query=new WP_Query(wuai_sluyu_circle_review_query_args($qa));
    cm_check($query->post_count===12 && (int)$query->found_posts===13 && (int)$query->max_num_pages===2 && !in_array($cross,cm_ids($query),true),'Multi-circle denial applies before queue pagination and totals.');
    $second=new WP_Query(wuai_sluyu_circle_review_query_args($qa,null,2)); cm_check($second->post_count===1 && !array_intersect(cm_ids($query),cm_ids($second)),'Queue page two is stable without duplication.');
    cm_as($editor); $query=new WP_Query(wuai_sluyu_circle_review_query_args($qa)); cm_check((int)$query->found_posts===14,'Trusted editor can review all circles without leaking this total to owners.');
    cm_as($owner); $query=new WP_Query(wuai_sluyu_circle_review_query_args($qa,$editor)); cm_check(!$query->post_count,'Query helper cannot impersonate another UID.');
    $old_args=wuai_sluyu_circle_review_query_args($qa); cm_as($viewer); cm_check(!(new WP_Query($old_args))->post_count,'A saved owner query cannot be reused after switching accounts.');
    cm_as($owner); $old_args=wuai_sluyu_circle_review_query_args($qa); update_term_meta($qa,'_wuai_circle_owner',(string)$other);
    cm_check(!(new WP_Query($old_args))->post_count,'Query execution revalidates ownership rather than trusting an earlier authorization snapshot.'); update_term_meta($qa,'_wuai_circle_owner',(string)$owner);
    $private_token='CM-SECRET-'.wp_generate_password(16,false,false); update_post_meta($queue[12],'_wuai_protected_content',['body'=>'<p>'.$private_token.'</p>','access'=>'login','cost'=>0,'version'=>wp_generate_uuid4()]);
    add_shortcode('cm_secret_probe',function()use($private_token){return $private_token;}); cm_as($cm_admin); wp_update_post(['ID'=>$queue[12],'post_content'=>'Public [cm_secret_probe] preview <script>unsafe()</script>']);
    cm_as($owner); $_GET=['circle_id'=>(string)$qa]; ob_start(); wuai_sluyu_view_circle_topics(); $html=ob_get_clean();
    cm_check(strpos($html,'[cm_secret_probe]')!==false && strpos($html,'CROSS-QUEUE-HIDDEN')===false && strpos($html,$private_token)===false && strpos($html,'<script>')===false,'Review page renders public source without leaking multi-circle titles, executing shortcodes, or exposing private fragments.');
    remove_shortcode('cm_secret_probe'); $_GET=[];

    $auth=cm_topic($writer,[$a],'authorization');
    foreach([0,$viewer,$other] as $uid)cm_check(is_wp_error(cm_review($uid,$auth,$a,'approve','',str_repeat('b',64))),'Guest and unrelated accounts cannot review.');
    update_user_meta($owner,'_wuai_banned_until',-1);
    try { cm_check(is_wp_error(cm_review($owner,$auth,$a,'approve','',str_repeat('b',64))) && !wuai_sluyu_circle_review_can_manage($a,$owner),'Banned owner cannot list or mutate review content.'); }
    finally { delete_user_meta($owner,'_wuai_banned_until'); }
    cm_as($owner); cm_check(is_wp_error(wuai_sluyu_circle_review_settings_save(['owner_publish'=>'1','stamp'=>wuai_sluyu_circle_review_settings_stamp()])),'Circle owners cannot opt themselves into publication.');
    cm_as($cm_admin); update_user_meta($cm_admin,'_wuai_banned_until',-1);
    try { cm_check(is_wp_error(wuai_sluyu_circle_review_settings_save(['owner_publish'=>'1','stamp'=>wuai_sluyu_circle_review_settings_stamp()])),'Banned administrator cannot change review authorization.'); }
    finally { delete_user_meta($cm_admin,'_wuai_banned_until'); }
    $option_fault_hits=0;
    $option_fault=function($new,$old)use(&$option_fault_hits){$option_fault_hits++;return $old;};
    add_filter('pre_update_option_'.wuai_sluyu_circle_review_option(),$option_fault,999,2);
    try { $result=wuai_sluyu_circle_review_settings_save(['owner_publish'=>'1','stamp'=>wuai_sluyu_circle_review_settings_stamp()]); }
    finally { remove_filter('pre_update_option_'.wuai_sluyu_circle_review_option(),$option_fault,999); }
    cm_check($option_fault_hits===1 && is_wp_error($result) && wuai_sluyu_circle_review_settings()['owner_publish']==='0','Failed option persistence does not grant owner publication.');
    cm_as($cm_admin); $old_setting_stamp=wuai_sluyu_circle_review_settings_stamp(); cm_setting('1');
    cm_check(is_wp_error(wuai_sluyu_circle_review_settings_save(['owner_publish'=>'0','stamp'=>$old_setting_stamp])) && wuai_sluyu_circle_review_settings()['owner_publish']==='1','Settings reject an old optimistic version.');
    $settings_lock=wuai_sluyu_lock('circle_review_settings'); cm_check((bool)$settings_lock,'Hold shared settings lock.');
    try { cm_check(is_wp_error(wuai_sluyu_circle_review_settings_save(['owner_publish'=>'0','stamp'=>wuai_sluyu_circle_review_settings_stamp()])),'Settings cannot change during another review transaction.'); }
    finally { wuai_sluyu_unlock($settings_lock); }
    $ordinary=cm_topic($writer,[$a,$a2],'explicit owner publication'); $before=cm_notices($writer);
    cm_check(!is_wp_error(cm_review($owner,$ordinary,$a)) && get_post_status($ordinary)==='publish' && cm_notices($writer)===$before+1,'Explicit site option permits ordinary same-owner multi-circle publication with one notice.');
    cm_check(!user_can($owner,'edit_others_posts') && !user_can($owner,'publish_posts'),'Owner publication option never escalates native WordPress capabilities.');
    foreach(['protected','guess','download','legacy-download','own-protected'] as $type) {
        $author=$type==='own-protected'?$owner:$writer; $id=cm_topic($author,[$a],$type.' private review');
        if(in_array($type,['protected','own-protected'],true))update_post_meta($id,'_wuai_protected_content',['body'=>'<p>'.$private_token.'</p>','access'=>'login','cost'=>0,'version'=>wp_generate_uuid4()]);
        elseif($type==='guess') {
            update_post_meta($id,'_wuai_topic_kind','guess');
            update_post_meta($id,'_wuai_guess_config',wuai_sluyu_guess_build($id,['choices'=>['First option','Second option'],'answer_index'=>1,'body'=>'<p>'.$private_token.'</p>']));
        } elseif($type==='download')update_post_meta($id,'_wuai_download_settings',['url'=>'https://example.test/'.$private_token.'.zip','access'=>'login','cost'=>0,'version'=>wp_generate_uuid4(),'password'=>'not-for-circle-owners']);
        else update_post_meta($id,'_wuai_sluyu_download_url','https://example.test/'.$private_token.'.zip');
        cm_check(!is_wp_error(cm_review($owner,$id,$a)) && get_post_status($id)==='pending','Owner approval cannot publish '.$type.' content, including their own private content.');
        cm_as($owner); $_GET=['group'=>(string)$a]; ob_start(); wuai_sluyu_view_circle_topics(); $html=ob_get_clean(); $_GET=[];
        cm_check(strpos($html,$private_token)===false && strpos($html,'not-for-circle-owners')===false,'Review page does not reveal '.$type.' configuration.');
        cm_check(!is_wp_error(cm_review($editor,$id,$a)) && get_post_status($id)==='publish','Trusted editor can finalize valid '.$type.' configuration.');
    }
    foreach(['_wuai_protected_content','_wuai_guess_config','_wuai_download_settings','_wuai_sluyu_download_url'] as $key) {
        $id=cm_topic($writer,[$a],'invalid '.$key); update_post_meta($id,$key,'malformed');
        cm_check(is_wp_error(cm_review($editor,$id,$a)) && get_post_status($id)==='pending','Editor cannot publish malformed '.$key.' configuration.');
    }
    foreach(['poll','guess','unknown-kind'] as $kind) {
        $id=cm_topic($writer,[$a],'invalid '.$kind); update_post_meta($id,'_wuai_topic_kind',$kind);
        cm_check(is_wp_error(cm_review($editor,$id,$a)) && get_post_status($id)==='pending','Invalid '.$kind.' configuration cannot be published.');
    }
    $valid_poll=cm_topic($writer,[$a],'valid ordinary owner poll'); update_post_meta($valid_poll,'_wuai_topic_kind','poll'); update_post_meta($valid_poll,'_wuai_poll_choices',['First','Second']);
    cm_check(!is_wp_error(cm_review($owner,$valid_poll,$a)) && get_post_status($valid_poll)==='publish','Enabled owner can publish a valid ordinary poll without private config.');
    foreach([['_wuai_poll_choices'=>['First','First']],['_wuai_poll_choices'=>['First',['unsafe']]],['_wuai_poll_mode'=>'unsupported'],['_wuai_poll_mode'=>'pk','_wuai_poll_choices'=>['One','Two','Three']],['_wuai_poll_max'=>'9'],['_wuai_poll_deadline'=>'not-a-time']] as $bad) {
        $bad_poll=cm_topic($writer,[$a],'invalid structured poll'); update_post_meta($bad_poll,'_wuai_topic_kind','poll'); update_post_meta($bad_poll,'_wuai_poll_choices',['First','Second']);
        foreach($bad as $key=>$value)update_post_meta($bad_poll,$key,$value);
        cm_check(is_wp_error(cm_review($owner,$bad_poll,$a)) && get_post_status($bad_poll)==='pending','Owner cannot publish a poll whose normalized reader would mask invalid storage.');
    }
    $banned_author=cm_topic($writer,[$a],'banned author'); update_user_meta($writer,'_wuai_banned_until',-1);
    try { cm_check(is_wp_error(cm_review($owner,$banned_author,$a)) && get_post_status($banned_author)==='pending','Owner cannot publish a banned author.'); }
    finally { delete_user_meta($writer,'_wuai_banned_until'); }

    foreach(['content','status','groups','settings','private-presence'] as $mutation) {
        $id=cm_topic($writer,[$a],'stale '.$mutation); cm_as($owner); $stamp=wuai_sluyu_circle_review_stamp($id,$a);
        cm_as($cm_admin);
        if($mutation==='content')wp_update_post(['ID'=>$id,'post_content'=>'Changed public text after the review was opened.']);
        elseif($mutation==='status')wp_update_post(['ID'=>$id,'post_status'=>'draft']);
        elseif($mutation==='groups')wp_set_object_terms($id,[$a,$a2],'sluyu_circle');
        elseif($mutation==='settings')cm_setting('1');
        else update_post_meta($id,'_wuai_sluyu_download_url','');
        cm_check(is_wp_error(cm_review($owner,$id,$a,'approve','',$stamp)) && !metadata_exists('post',$id,wuai_sluyu_circle_review_key()),'Old stamp rejects changed '.$mutation.' without starting an audit.');
    }
    $id=cm_topic($writer,[$a],'lock ordering'); cm_as($owner); $stamp=wuai_sluyu_circle_review_stamp($id,$a);
    foreach(['user_'.$owner,'poll_'.$id,'circle_'.$a,'circle_review_settings'] as $key) {
        $lock=wuai_sluyu_lock($key); cm_check((bool)$lock,'Acquire deliberate lock '.$key);
        try { cm_check(is_wp_error(cm_review($owner,$id,$a,'approve','',$stamp)) && !metadata_exists('post',$id,wuai_sluyu_circle_review_key()),'Busy '.$key.' causes no partial audit.'); }
        finally { wuai_sluyu_unlock($lock); }
        cm_unlocked($owner,$id,[$a]);
    }
    $throw=function($check,$object_id,$key)use($id){if((int)$object_id===$id && $key===wuai_sluyu_circle_review_key())throw new RuntimeException('cm-injected-exception');return $check;};
    add_filter('add_post_metadata',$throw,999,3); $thrown=false;
    try { cm_review($owner,$id,$a,'approve','',$stamp); }
    catch(RuntimeException $error) { if($error->getMessage()!=='cm-injected-exception')throw $error; $thrown=true; }
    finally { remove_filter('add_post_metadata',$throw,999); }
    cm_check($thrown,'Exception injection exercises the locked mutation path.'); cm_unlocked($owner,$id,[$a]);

    foreach(['add_post_metadata','update_post_metadata'] as $hook) {
        $id=cm_topic($writer,[$a],'prepared write failure '.$hook); cm_as($owner); $stamp=wuai_sluyu_circle_review_stamp($id,$a); $before=cm_notices($writer);
        $result=cm_fault($id,wuai_sluyu_circle_review_key(),$hook,null,function()use($owner,$id,$a,$stamp){return cm_review($owner,$id,$a,'approve','',$stamp);});
        cm_check(is_wp_error($result) && get_post_status($id)==='pending' && !metadata_exists('post',$id,wuai_sluyu_circle_review_key()) && wuai_sluyu_circle_review_held($id),'First audit failure preserves a held pending item, never an unheld prepared transaction.');
        cm_check(cm_notices($writer)===$before,'Failed prepared audit sends no notice.'); cm_unlocked($owner,$id,[$a]);
        cm_check(is_wp_error(cm_review($owner,$id,$a,'approve','',$stamp)),'Missing durable decision cannot be invented from an old owner request.');
        cm_as($writer); cm_check(is_wp_error(wuai_sluyu_account_submission_permission(get_post($id),$writer,'withdraw_submission')),'Orphan hold prevents withdrawal from hiding the recovery item.');
        cm_as($editor); $queue=wuai_sluyu_circle_review_query_args($a); $queue['post__in']=[$id]; cm_check(cm_ids(new WP_Query($queue))===[$id],'Orphan hold remains discoverable for trusted recovery.');
        cm_check(!is_wp_error(cm_review($editor,$id,$a,'recover','Recover after initial audit storage failure.')) && get_post_status($id)==='pending' && !wuai_sluyu_circle_review_held($id),'Trusted editor can recover the orphan into pending without publishing.');
        cm_check(!is_wp_error(cm_review($owner,$id,$a)) && get_post_status($id)==='publish','A fresh owner decision works after explicit recovery.');
    }
    foreach(['add_post_metadata','update_post_metadata'] as $hook) {
        $id=cm_topic($writer,[$a],'hold write failure '.$hook); cm_as($owner); $stamp=wuai_sluyu_circle_review_stamp($id,$a); $before=cm_notices($writer);
        $result=cm_fault($id,wuai_sluyu_circle_review_hold_key(),$hook,null,function()use($owner,$id,$a,$stamp){return cm_review($owner,$id,$a,'approve','',$stamp);});
        $entries=wuai_sluyu_circle_review_audit($id);
        cm_check(is_wp_error($result) && get_post_status($id)==='pending' && $entries===[] && !wuai_sluyu_circle_review_held($id),'Failed initial hold leaves no prepared audit or publication.');
        cm_check(cm_notices($writer)===$before,'Failed hold cannot report approval.');
        cm_check(!is_wp_error(cm_review($owner,$id,$a,'approve','',$stamp)) && get_post_status($id)==='publish' && count(wuai_sluyu_circle_review_audit($id))===1,'Unchanged owner request safely retries when the first hold never persisted.');
        cm_unlocked($owner,$id,[$a]);
    }
    $id=cm_topic($writer,[$a],'failed status transition'); cm_as($owner); $stamp=wuai_sluyu_circle_review_stamp($id,$a); $before=cm_notices($writer); $status_hits=0;
    $status_block=function($data,$input)use($id,&$status_hits){if((int)($input['ID']??0)===$id && $data['post_status']==='publish'){$status_hits++;$data['post_status']='pending';}return $data;};
    add_filter('wp_insert_post_data',$status_block,999,2);
    try { $result=cm_review($owner,$id,$a,'approve','',$stamp); } finally { remove_filter('wp_insert_post_data',$status_block,999); }
    cm_check($status_hits===1 && is_wp_error($result) && get_post_status($id)==='pending' && wuai_sluyu_circle_review_held($id) && cm_notices($writer)===$before,'Status write mismatch preserves hold and never notifies success.');
    cm_check(!is_wp_error(cm_review($owner,$id,$a,'approve','',$stamp)) && !wuai_sluyu_circle_review_held($id),'Exact status failure resumes safely.');

    $id=cm_topic($writer,[$a],'applied audit failure'); cm_as($owner); $stamp=wuai_sluyu_circle_review_stamp($id,$a); $before=cm_notices($writer);
    $result=cm_fault($id,wuai_sluyu_circle_review_key(),'update_post_metadata','cm_applied',function()use($owner,$id,$a,$stamp){return cm_review($owner,$id,$a,'approve','',$stamp);});
    cm_check(is_wp_error($result) && get_post_status($id)==='pending' && wuai_sluyu_circle_review_held($id) && wuai_sluyu_circle_review_audit($id)[0]['phase']==='prepared','Applied audit failure rolls back to pending and keeps quarantine.');
    cm_check(cm_notices($writer)===$before,'Intermediate publication never emits the generic success notification.');
    cm_check(!is_wp_error(cm_review($owner,$id,$a,'approve','',$stamp)) && get_post_status($id)==='publish' && cm_notices($writer)===$before+1,'Applied audit retry completes exactly once.');

    $id=cm_topic($writer,[$a],'hold deletion failure'); cm_as($owner); $stamp=wuai_sluyu_circle_review_stamp($id,$a); $before=cm_notices($writer);
    $result=cm_fault($id,wuai_sluyu_circle_review_hold_key(),'delete_post_metadata',null,function()use($owner,$id,$a,$stamp){return cm_review($owner,$id,$a,'approve','',$stamp);});
    cm_check(is_wp_error($result) && get_post_status($id)==='publish' && wuai_sluyu_circle_review_held($id) && wuai_sluyu_circle_review_audit($id)[0]['phase']==='applied' && cm_notices($writer)===$before,'Hold release failure remains visibly incomplete despite durable applied audit.');
    cm_held_isolation($id,$a,$viewer);
    cm_check(!is_wp_error(cm_review($owner,$id,$a,'approve','',$stamp)) && !wuai_sluyu_circle_review_held($id) && cm_notices($writer)===$before+1,'Retry finishes a hold release without creating a second audit entry.');

    $double=cm_topic($writer,[$a],'double storage fault hidden'); cm_as($owner); $double_stamp=wuai_sluyu_circle_review_stamp($double,$a); $before=cm_notices($writer); $rollback_hits=0;
    $rollback_block=function($data,$input)use($double,&$rollback_hits){if((int)($input['ID']??0)===$double && $data['post_status']==='pending' && get_post_status($double)==='publish'){$rollback_hits++;$data['post_status']='publish';}return $data;};
    add_filter('wp_insert_post_data',$rollback_block,999,2);
    try { $result=cm_fault($double,wuai_sluyu_circle_review_key(),'update_post_metadata','cm_applied',function()use($owner,$double,$a,$double_stamp){return cm_review($owner,$double,$a,'approve','',$double_stamp);}); }
    finally { remove_filter('wp_insert_post_data',$rollback_block,999); }
    cm_check(is_wp_error($result) && $rollback_hits===1 && get_post_status($double)==='publish' && wuai_sluyu_circle_review_held($double) && cm_notices($writer)===$before,'Double fault leaves a real publish+hold record without false approval.');
    cm_held_isolation($double,$a,$viewer);
    cm_as($owner); $queue_ids=cm_ids(new WP_Query(wuai_sluyu_circle_review_query_args($a))); cm_check(in_array($double,$queue_ids,true),'Owner review queue can find its held published recovery item.');
    update_term_meta($a,'_wuai_circle_owner',(string)$other);
    cm_check(is_wp_error(cm_review($owner,$double,$a,'approve','',$double_stamp)),'Revoked owner cannot finish an old decision.');
    cm_as($editor); $recovery_stamp=wuai_sluyu_circle_review_stamp($double,$a); $original_hold=get_post_meta($double,wuai_sluyu_circle_review_hold_key(),true);
    $result=cm_fault($double,wuai_sluyu_circle_review_hold_key(),'update_post_metadata',null,function()use($editor,$double,$a,$recovery_stamp){return cm_review($editor,$double,$a,'recover','Restore after failed owner transaction.',$recovery_stamp);});
    cm_check(is_wp_error($result) && get_post_meta($double,wuai_sluyu_circle_review_hold_key(),true)===$original_hold && count(wuai_sluyu_circle_review_audit($double))===2,'Failed recovery-token replacement retains the old isolation and prepared recovery audit.');
    cm_check(!is_wp_error(cm_review($editor,$double,$a,'recover','Restore after failed owner transaction.',$recovery_stamp)) && get_post_status($double)==='pending' && !wuai_sluyu_circle_review_held($double),'Trusted recovery resumes even when its previous token replacement failed.');
    $entries=wuai_sluyu_circle_review_audit($double); cm_check(count($entries)===2 && $entries[1]['action']==='recover' && $entries[1]['phase']==='applied' && cm_notices($writer)===$before+1,'Recovery restores pending rather than publishing, and emits only its own final notice.');
    update_term_meta($a,'_wuai_circle_owner',(string)$owner);
    cm_unlocked($editor,$double,[$a]);

    $mutating=cm_topic($writer,[$a],'late hook mutation'); cm_as($owner); $stamp=wuai_sluyu_circle_review_stamp($mutating,$a); $before=cm_notices($writer); $mutated=false;
    $late=function($meta_id,$object_id,$key,$value)use($mutating,&$mutated){
        if(!$mutated && (int)$object_id===$mutating && $key===wuai_sluyu_circle_review_key() && cm_applied($value)) { $mutated=true; wp_update_post(['ID'=>$mutating,'post_title'=>'Changed while finishing the audit']); }
    };
    add_action('updated_post_meta',$late,999,4);
    try { $result=cm_review($owner,$mutating,$a,'approve','',$stamp); } finally { remove_action('updated_post_meta',$late,999); }
    cm_check($mutated && is_wp_error($result) && get_post_status($mutating)==='pending' && wuai_sluyu_circle_review_held($mutating) && cm_notices($writer)===$before,'Post-save hook changes are rechecked before hold release or author notice.');
    cm_check(!is_wp_error(cm_review($editor,$mutating,$a,'recover','Review content changed during a save.')) && get_post_status($mutating)==='pending','Trusted editor can recover content changed by a native hook.');

    $corrupt=cm_topic($writer,[$a],'corrupt audit');
    foreach([[['id'=>'broken']],['not-a-list'=>[]]] as $value) {
        update_post_meta($corrupt,wuai_sluyu_circle_review_key(),$value);
        cm_check(wuai_sluyu_circle_review_audit($corrupt)===false && is_wp_error(cm_review($editor,$corrupt,$a)) && get_post_status($corrupt)==='pending','Malformed audit fails closed rather than raising array-index notices or publishing.');
    }

    $feature_group=cm_circle($owner,'sort'); $feature_other=cm_circle($owner,'sort-second');
    $date='2026-01-01 12:00:00';
    $first=cm_topic($writer,[$feature_group,$feature_other],'sort first','publish',['post_date'=>$date,'post_date_gmt'=>$date]);
    $second=cm_topic($writer,[$feature_group],'sort second','publish',['post_date'=>$date,'post_date_gmt'=>$date]);
    $third=cm_topic($writer,[$feature_group],'sort third','publish',['post_date'=>$date,'post_date_gmt'=>$date]);
    cm_as($owner); $stamp=wuai_sluyu_circle_feature_stamp($first,$feature_group);
    cm_check(!is_wp_error(wuai_sluyu_circle_feature($first,$feature_group,'1',$stamp)) && wuai_sluyu_circle_featured($first,$feature_group) && !wuai_sluyu_circle_featured($first,$feature_other),'Featured state is scoped to one circle only.');
    cm_check(!is_wp_error(wuai_sluyu_circle_feature($first,$feature_group,'1',$stamp)),'Repeated desired feature state is idempotent.');
    cm_as($viewer); cm_check(is_wp_error(wuai_sluyu_circle_feature($first,$feature_group,'0',$stamp)),'Unrelated user cannot toggle a feature, including no-op replay.');
    cm_as($owner); $stamp=wuai_sluyu_circle_feature_stamp($second,$feature_group); cm_as($cm_admin); wp_update_post(['ID'=>$second,'post_content'=>'Updated public content after opening a feature form.']); cm_as($owner);
    cm_check(is_wp_error(wuai_sluyu_circle_feature($second,$feature_group,'1',$stamp)),'Feature version rejects changes in public content.');
    $result=cm_fault($second,wuai_sluyu_circle_feature_key($feature_group),'add_post_metadata',null,function()use($second,$feature_group){return wuai_sluyu_circle_feature($second,$feature_group,'1',wuai_sluyu_circle_feature_stamp($second,$feature_group));});
    cm_check(is_wp_error($result) && !wuai_sluyu_circle_featured($second,$feature_group),'Failed feature storage cannot report success.');
    foreach(['draft','pending'] as $status) { $id=cm_topic($writer,[$feature_group],'feature '.$status,$status); cm_as($owner); cm_check(!wuai_sluyu_circle_feature_permission($id,$feature_group),'Feature rejects '.$status.' topic.'); }
    $password=cm_topic($writer,[$feature_group],'feature password','publish',['post_password'=>'private-password']); cm_as($owner); cm_check(!wuai_sluyu_circle_feature_permission($password,$feature_group),'Feature rejects password-protected topic.');
    $cross_feature=cm_topic($writer,[$feature_group,$b],'feature foreign circle','publish'); cm_as($owner); cm_check(!wuai_sluyu_circle_feature_permission($cross_feature,$feature_group),'Feature also requires every assigned circle.');
    cm_as(0); $query=new WP_Query(wuai_sluyu_circle_feed_query_args($feature_group,'','featured'));
    cm_check(cm_ids($query)===[$first] && (int)$query->found_posts===1,'Featured filter returns exact per-circle selections.');
    cm_check(!(new WP_Query(wuai_sluyu_circle_feed_query_args($feature_other,'','featured')))->post_count,'Feature does not bleed into another assigned circle.');
    $args=wuai_sluyu_circle_feed_query_args($feature_group,'','latest'); $args['post__in']=[$first,$second,$third];
    cm_check(cm_ids(new WP_Query($args))===[$third,$second,$first],'Latest ties use deterministic descending post ID.');
    update_user_meta($owner,'_wuai_sluyu_like_'.$first,'1'); update_user_meta($viewer,'_wuai_sluyu_like_'.$first,'1'); update_user_meta($other,'_wuai_sluyu_like_'.$second,'1');
    add_user_meta($owner,'_wuai_sluyu_like_'.$first,'1');
    $args=wuai_sluyu_circle_feed_query_args($feature_group,'','likes'); $args['post__in']=[$first,$second,$third];
    cm_check(cm_ids(new WP_Query($args))===[$first,$second,$third],'Likes order uses distinct live user likes, not duplicate metadata or fake counters.');
    delete_user_meta($owner,'_wuai_sluyu_like_'.$first); delete_user_meta($viewer,'_wuai_sluyu_like_'.$first);
    cm_check(cm_ids(new WP_Query($args))===[$second,$third,$first],'A like/unlike immediately changes sorting without stale query cache.');
    foreach([$first,$first,$second] as $target)wp_insert_comment(['comment_post_ID'=>$target,'user_id'=>$viewer,'comment_author'=>'QA reader','comment_author_email'=>'reader@example.test','comment_content'=>'A local approved sorting comment.','comment_approved'=>1]);
    foreach([$first,$second,$third] as $target)wp_update_comment_count_now($target);
    $args=wuai_sluyu_circle_feed_query_args($feature_group,'','comments'); $args['post__in']=[$first,$second,$third];
    cm_check(cm_ids(new WP_Query($args))===[$first,$second,$third],'Comments sort uses actual approved comment count.');
    $args=['post_type'=>'sluyu_topic','post_status'=>'publish','post__in'=>[$first,$second,$third],'orderby'=>'ID','order'=>'ASC'];
    cm_check(cm_ids(new WP_Query($args))===[$first,$second,$third],'Unflagged WordPress queries retain their own ordering.');
    cm_as($owner); $feature_lock=wuai_sluyu_lock('circle_'.$feature_group);
    try { cm_check(is_wp_error(wuai_sluyu_circle_feature($third,$feature_group,'1',wuai_sluyu_circle_feature_stamp($third,$feature_group))) && !wuai_sluyu_circle_featured($third,$feature_group),'Busy circle lock prevents feature mutation.'); }
    finally { wuai_sluyu_unlock($feature_lock); }
    cm_unlocked($owner,$third,[$feature_group]);
    cm_as($owner); wuai_sluyu_circle_feature($first,$feature_group,'0',wuai_sluyu_circle_feature_stamp($first,$feature_group));
    $result=wuai_sluyu_circle_feature($first,$feature_other,'1',wuai_sluyu_circle_feature_stamp($first,$feature_other)); cm_check(!is_wp_error($result),'Mark exact second circle featured.');
    cm_as(0); $args=wuai_sluyu_circle_feed_query_args(0,'','featured'); $args['post__in']=[$first]; cm_check(cm_ids(new WP_Query($args))===[$first],'All-circle featured feed accepts a selection in a real public assigned circle.');
    cm_as($cm_admin); wp_set_object_terms($first,[$feature_group],'sluyu_circle'); cm_as(0);
    $args=wuai_sluyu_circle_feed_query_args(0,'','featured'); $args['post__in']=[$first]; cm_check(!(new WP_Query($args))->post_count,'A stale feature key from a removed group cannot qualify global featured feed.');
    cm_as($cm_admin); wp_set_object_terms($first,[$feature_group,$feature_other],'sluyu_circle'); update_term_meta($feature_other,'_wuai_circle_status','pending'); cm_as(0);
    $args=wuai_sluyu_circle_feed_query_args(0,'','featured'); $args['post__in']=[$first]; cm_check(!(new WP_Query($args))->post_count,'A feature belonging only to a nonpublic circle cannot enter global featured results.');
    update_term_meta($feature_other,'_wuai_circle_status','publish');

    // Exercise shared direct-ID guards using realistic configured held topics.
    foreach(['protected','guess','download','poll'] as $kind) {
        $id=cm_topic($writer,[$a],'held direct '.$kind,'publish');
        if($kind==='protected')update_post_meta($id,'_wuai_protected_content',['body'=>'<p>'.$private_token.'</p>','access'=>'login','cost'=>0,'version'=>wp_generate_uuid4()]);
        elseif($kind==='guess') { update_post_meta($id,'_wuai_topic_kind','guess'); update_post_meta($id,'_wuai_guess_config',wuai_sluyu_guess_build($id,['choices'=>['First option','Second option'],'answer_index'=>0,'body'=>'<p>'.$private_token.'</p>'])); }
        elseif($kind==='download')update_post_meta($id,'_wuai_download_settings',['url'=>'https://example.test/'.$private_token.'.zip','access'=>'public','cost'=>0,'version'=>wp_generate_uuid4(),'password'=>'secret']);
        else { update_post_meta($id,'_wuai_topic_kind','poll'); update_post_meta($id,'_wuai_poll_choices',['First option','Second option']); }
        update_post_meta($id,wuai_sluyu_circle_review_hold_key(),wp_generate_uuid4()); cm_as($viewer);
        if($kind==='protected')cm_check(!wuai_sluyu_protected_state($id,$viewer)['allowed'],'Held protected state denies a normally allowed logged-in reader.');
        elseif($kind==='guess') {
            $config=wuai_sluyu_guess_config($id); cm_check(!wuai_sluyu_guess_state($id,$viewer)['allowed'] && is_wp_error(wuai_sluyu_guess_answer($id,'0',$config['version'])),'Held guess state and direct answer reject access and writes.');
        } elseif($kind==='download')cm_check(!wuai_sluyu_download_access($id,$viewer) && wuai_sluyu_download_handoff($id)==='','Held public-download configuration cannot generate a handoff.');
        else cm_check(is_wp_error(wuai_sluyu_poll_participation($id,$viewer)),'Held polls cannot accept votes.');
    }

    $held_download=cm_topic($writer,[$a],'already issued download token','publish');
    update_post_meta($held_download,'_wuai_download_settings',['url'=>'https://example.test/'.$private_token.'.zip','access'=>'points','cost'=>10,'version'=>wp_generate_uuid4(),'password'=>'secret']);
    cm_as($writer); $config=wuai_sluyu_download_config($held_download); $expires=time()+60; $signature=wuai_sluyu_download_signature($held_download,$writer,$expires,$config);
    cm_check(wuai_sluyu_download_target($held_download,(string)$expires,$signature)===$config['url'],'Baseline author token resolves before quarantine.');
    cm_as($viewer); wuai_sluyu_event($viewer,'cm-credit:'.$held_download,'qa',100); $quote=wuai_sluyu_download_quote($held_download); $points=wuai_sluyu_points($viewer);
    update_post_meta($held_download,wuai_sluyu_circle_review_hold_key(),wp_generate_uuid4());
    cm_check(is_wp_error(wuai_sluyu_download_unlock($held_download,$quote,'1')) && wuai_sluyu_points($viewer)===$points,'Old download purchase quote cannot spend points while review is held.');
    cm_as($writer); cm_check(is_wp_error(wuai_sluyu_download_target($held_download,(string)$expires,$signature)),'Previously issued signed author download also stops after hold is set.');
    $held_protected=cm_topic($writer,[$a],'already issued private quote','publish');
    update_post_meta($held_protected,'_wuai_protected_content',['body'=>'<p>'.$private_token.'</p>','access'=>'points','cost'=>10,'version'=>wp_generate_uuid4()]);
    cm_as($viewer); $quote=wuai_sluyu_protected_quote($held_protected); cm_check($quote!=='','Baseline private purchase quote exists.');
    update_post_meta($held_protected,wuai_sluyu_circle_review_hold_key(),wp_generate_uuid4());
    cm_check(is_wp_error(wuai_sluyu_protected_unlock($held_protected,$quote,'1')) && wuai_sluyu_points($viewer)===$points,'Held protected fragment rejects old purchase quote without a debit.');
    cm_check(is_wp_error(wuai_sluyu_comment_permission(['comment_post_ID'=>$held_protected,'comment_content'=>'No held comment'], $viewer)),'Direct comment validation rejects held topic IDs.');

    $comment_topic=cm_topic($writer,[$a],'held historical comment','publish');
    cm_as($cm_admin); $comment_id=wp_insert_comment(['comment_post_ID'=>$comment_topic,'user_id'=>$viewer,'comment_author'=>'QA historic reader','comment_author_email'=>'historic@example.test','comment_content'=>'Historical held reply with a unique token.','comment_approved'=>1]);
    cm_check((bool)$comment_id,'Create pre-existing public comment.'); cm_as(0);
    $comment_args=['post_id'=>$comment_topic,'status'=>'approve'];
    cm_check(count(get_comments($comment_args))===1 && (int)get_comments(array_merge($comment_args,['count'=>true]))===1,'Warm public comment list and count caches before quarantine.');
    cm_check(cm_rest('/wp/v2/comments/'.$comment_id)->get_status()===200,'Unheld public comment is readable through REST.');
    update_post_meta($comment_topic,wuai_sluyu_circle_review_hold_key(),wp_generate_uuid4());
    cm_check(!get_comments($comment_args) && (int)get_comments(array_merge($comment_args,['count'=>true]))===0,'Held comments disappear from cached list and count queries.');
    cm_check(cm_rest('/wp/v2/comments/'.$comment_id)->get_status()===404 && !cm_rest('/wp/v2/comments',['post'=>$comment_topic])->get_data(),'REST comment detail and collection do not expose old replies.');
    cm_check(get_comment_text($comment_id)==='' && (int)get_comments_number($comment_topic)===0,'Direct comment text/count helpers fail closed.');
    ob_start(); $GLOBALS['post']=get_post($comment_topic); wuai_sluyu_render_card($comment_topic); cm_check(ob_get_clean()==='','Direct card cannot leak held title or excerpt.');
    delete_post_meta($comment_topic,wuai_sluyu_circle_review_hold_key());
    cm_check(count(get_comments($comment_args))===1 && cm_rest('/wp/v2/comments/'.$comment_id)->get_status()===200,'Clearing hold invalidates comment caches and restores ordinary access.');
    $neighbor_date='2026-02-02 12:00:00';
    $neighbor_before=cm_topic($writer,[$a],'neighbor before','publish',['post_date'=>$neighbor_date,'post_date_gmt'=>$neighbor_date]);
    $neighbor_held=cm_topic($writer,[$a],'neighbor hidden','publish',['post_date'=>$neighbor_date,'post_date_gmt'=>$neighbor_date]);
    $neighbor_after=cm_topic($writer,[$a],'neighbor after','publish',['post_date'=>$neighbor_date,'post_date_gmt'=>$neighbor_date]);
    cm_as(0); $GLOBALS['post']=get_post($neighbor_after);
    cm_check((int)get_previous_post()->ID===$neighbor_held,'Warm adjacent post cache before hold.');
    update_post_meta($neighbor_held,wuai_sluyu_circle_review_hold_key(),wp_generate_uuid4());
    cm_check((int)get_previous_post()->ID===$neighbor_before,'Previous link skips quarantined title after cache invalidation.');
    $GLOBALS['post']=get_post($neighbor_before); cm_check((int)get_next_post()->ID===$neighbor_after,'Next link skips quarantined title.');
    delete_post_meta($neighbor_held,wuai_sluyu_circle_review_hold_key());
    cm_check((int)get_next_post()->ID===$neighbor_held,'Releasing hold restores adjacent result without stale negative cache.');

    $ajax=cm_topic($writer,[$a],'ajax nonce review'); cm_as($owner); $stamp=wuai_sluyu_circle_review_stamp($ajax,$a);
    $input=['operation'=>'circle_review','id'=>(string)$ajax,'group'=>(string)$a,'decision'=>'approve','reason'=>'','version'=>$stamp,'nonce'=>'invalid'];
    cm_check(cm_ajax($owner,$input)['success']===false && get_post_status($ajax)==='pending' && !metadata_exists('post',$ajax,wuai_sluyu_circle_review_key()),'AJAX rejects forged nonce before starting a moderation transaction.');
    cm_as($owner); $input['nonce']=wp_create_nonce('wuai_sluyu');
    cm_check(cm_ajax($owner,$input,'GET')['success']===false && !metadata_exists('post',$ajax,wuai_sluyu_circle_review_key()),'AJAX review is POST-only.');
    cm_check(cm_ajax($owner,$input)['success']===true && get_post_status($ajax)==='publish','Nonce-validated dispatch inherits the existing user lock without deadlocking.');
    cm_as($viewer); cm_check(cm_ajax($viewer,['operation'=>'like','post_id'=>(string)$ajax,'nonce'=>wp_create_nonce('wuai_sluyu')],'POST','wuai_sluyu_frontend_action')['success']===true,'Unheld published baseline proves the real like endpoint remains operational.');
    cm_unlocked($owner,$ajax,[$a]);
    echo "PASS: scoped circle moderation, private audit, recovery faults, hold isolation, featured sorting, and nonce dispatch.\n";
} finally {
    $_POST=[]; $_GET=[]; cm_as($cm_admin);
    if($cm_option===null)delete_option(wuai_sluyu_circle_review_option()); else update_option(wuai_sluyu_circle_review_option(),$cm_option,false);
    wuai_sluyu_circle_review_refresh_settings();
    foreach(array_unique($cm_posts) as $id)wp_delete_post($id,true);
    global $wuai_sluyu_all_circle_terms; $previous=$wuai_sluyu_all_circle_terms??false; $wuai_sluyu_all_circle_terms=true;
    try { foreach(array_unique($cm_terms) as $id)wp_delete_term($id,'sluyu_circle'); } finally { $wuai_sluyu_all_circle_terms=$previous; }
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach($cm_users as $id) { foreach(['messages','events','memberships'] as $table) { if($table==='messages')$wpdb->query($wpdb->prepare('DELETE FROM '.wuai_sluyu_table($table).' WHERE sender_id=%d OR recipient_id=%d',$id,$id)); else $wpdb->delete(wuai_sluyu_table($table),['user_id'=>$id]); } wp_delete_user($id); }
    wp_set_current_user($cm_old_user); $_POST=$cm_old_post; $_GET=$cm_old_get; $GLOBALS['post']=$cm_old_global_post;
}
