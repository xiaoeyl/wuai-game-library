<?php
// Native save_post lifecycle regression; execute serially on the disposable site.
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('WP_QA_ROOT');if(!$root || !is_file($root.'/wp-load.php'))throw new RuntimeException('WP_QA_ROOT required.');
$_SERVER['HTTP_HOST']='127.0.0.1:8771';$_SERVER['REQUEST_URI']='/';require $root.'/wp-load.php';
if(home_url()!=='http://127.0.0.1:8771' || !function_exists('wuai_sluyu_guess_save'))throw new RuntimeException('Integrated localhost guess module required.');
function gl_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function gl_form($uid,$id,$body,$overrides=[]){
    wp_set_current_user($uid);
    return array_merge(['wuai_service_meta_nonce'=>wp_create_nonce('wuai_service_meta'),'wuai_topic_kind'=>get_post_meta($id,'_wuai_topic_kind',true),'wuai_guess_nonce'=>wp_create_nonce('wuai_guess_admin'),'wuai_guess_stamp'=>wuai_sluyu_guess_stamp($id),'wuai_guess_choices'=>"Alpha\nBeta\nGamma",'wuai_guess_correct'=>'2','wuai_guess_body'=>$body],$overrides);
}
function gl_native($uid,$id,$changes,$form){
    wp_set_current_user($uid);$_POST=wp_slash($form);
    $saved=wp_update_post(wp_slash(array_merge(['ID'=>$id],$changes)),true);
    gl_check(!is_wp_error($saved) && (int)$saved===(int)$id,'WordPress saved native public post fields.');
    clean_post_cache($id);return wuai_sluyu_guess_config($id);
}
function gl_solve($uid,$id){wp_set_current_user($uid);$config=wuai_sluyu_guess_config($id);gl_check((bool)$config,'Valid lifecycle config before solving.');$result=wuai_sluyu_guess_answer($id,'1',$config['version']);gl_check(!is_wp_error($result) && !empty($result['solved']),'Dedicated reader solves the current lifecycle version.');}
$old_user=get_current_user_id();$old_post=$_POST;$users=[];$posts=[];$prefix='qa_guess_lifecycle_'.strtolower(wp_generate_password(8,false,false));
$secret='QA-GUESS-LIFECYCLE-'.wp_generate_uuid4();$private='<p>'.$secret.' Original private note.</p>';
$mail_block=function(){return true;};add_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
try{
    foreach(['reader'=>'subscriber','owner'=>'author','editor'=>'editor']as$name=>$role){$uid=wp_create_user($prefix.'_'.$name,'qa-local-only',$prefix.'_'.$name.'@example.test');gl_check(!is_wp_error($uid),'Create dedicated lifecycle account.');$users[$name]=(int)$uid;(new WP_User($uid))->set_role($role);}
    $reader=$users['reader'];$owner=$users['owner'];$editor=$users['editor'];
    $make=function($kind='guess')use(&$posts,$prefix,$owner){$_POST=[];$id=wp_insert_post(['post_type'=>'sluyu_topic','post_status'=>'publish','post_author'=>$owner,'post_title'=>$prefix.' prompt','post_content'=>'Public question: which Greek letter is second?','post_excerpt'=>'Public excerpt only.','meta_input'=>['_wuai_topic_kind'=>$kind]],true);gl_check(!is_wp_error($id) && $id,'Create isolated native save target.');$posts[]=(int)$id;return (int)$id;};
    $id=$make();$initial=gl_native($editor,$id,[],gl_form($editor,$id,$private));gl_check((bool)$initial,'Native service + guess metabox save creates the complete config.');
    gl_solve($reader,$id);$original_version=$initial['version'];
    $noop=gl_native($editor,$id,[],gl_form($editor,$id,$private));gl_check($noop['version']===$original_version && wuai_sluyu_guess_state($id,$reader)['allowed'],'Native no-op save preserves the current edition and solved receipt.');

    // The generic type selector must not provide a backdoor around versioning.
    foreach(['topic','question','poll']as$kind){$before=get_post_meta($id,wuai_sluyu_guess_key(),true);gl_native($editor,$id,[],gl_form($editor,$id,$private,['wuai_topic_kind'=>$kind]));gl_check(get_post_meta($id,'_wuai_topic_kind',true)==='guess' && get_post_meta($id,wuai_sluyu_guess_key(),true)===$before,'Existing guess cannot become '.$kind.' through the native generic metabox.');}
    $before=get_post_meta($id,wuai_sluyu_guess_key(),true);
    gl_native($owner,$id,[],gl_form($owner,$id,$private.' Author must not replace this.',['wuai_topic_kind'=>'topic']));
    gl_check(get_post_meta($id,'_wuai_topic_kind',true)==='guess' && get_post_meta($id,wuai_sluyu_guess_key(),true)===$before,'Native author cannot change private config or downgrade the puzzle type.');
    update_user_meta($editor,'_wuai_banned_until',-1);
    $public_before=get_post($id,ARRAY_A);$_POST=wp_slash(gl_form($editor,$id,$private.' Banned editor must not replace this.',['wuai_topic_kind'=>'question']));
    $denied=wp_update_post(['ID'=>$id,'post_content'=>'Banned editor must not replace the public prompt.'],true);clean_post_cache($id);
    gl_check(is_wp_error($denied) || $denied===0,'Native post safety guard rejects a banned editor before saving.');
    gl_check(get_post($id,ARRAY_A)===$public_before && get_post_meta($id,'_wuai_topic_kind',true)==='guess' && get_post_meta($id,wuai_sluyu_guess_key(),true)===$before,'Banned editor rejection preserves public fields, private config and puzzle type.');delete_user_meta($editor,'_wuai_banned_until');
    $ordinary=$make('topic');
    gl_native($editor,$ordinary,[],gl_form($editor,$ordinary,$private,['wuai_topic_kind'=>'guess']));
    gl_check(get_post_meta($ordinary,'_wuai_topic_kind',true)==='topic' && !metadata_exists('post',$ordinary,wuai_sluyu_guess_key()),'Generic topic cannot become a guess even with crafted valid config fields/nonces.');
    $historical=$make('topic');update_post_meta($historical,wuai_sluyu_guess_key(),'historical-corrupt-config');
    foreach(['guess','question','poll']as$kind){gl_native($editor,$historical,[],gl_form($editor,$historical,$private,['wuai_topic_kind'=>$kind]));gl_check(get_post_meta($historical,'_wuai_topic_kind',true)==='topic' && get_post_meta($historical,wuai_sluyu_guess_key(),true)==='historical-corrupt-config','A historical config marker blocks generic retyping even when its stored config is malformed.');}

    foreach(['post_title'=>'Updated public title','post_content'=>'Changed public question: select the second symbol.','post_excerpt'=>'Changed public summary.']as$field=>$value){
        $old=wuai_sluyu_guess_config($id);$config=gl_native($editor,$id,[$field=>$value],gl_form($editor,$id,$private));
        gl_check($config && $config['version']!==$old['version'] && !wuai_sluyu_guess_state($id,$reader)['allowed'],'Native '.$field.' change binds a new version and invalidates prior solved access.');gl_solve($reader,$id);
    }
    $old=wuai_sluyu_guess_config($id);$stale_form=gl_form($editor,$id,$private);
    $new_private='<p>'.$secret.' New authoritative private note.</p>';
    $new=gl_native($editor,$id,[],gl_form($editor,$id,$new_private));gl_check($new && $new['version']!==$old['version'],'Native private edit produces a newer config.');
    gl_native($editor,$id,['post_content'=>'A stale editor changed public prompt without the current private configuration.'],$stale_form);
    gl_check(get_post_meta($id,wuai_sluyu_guess_key(),true)===$new && !wuai_sluyu_guess_config($id) && !wuai_sluyu_guess_state($id,$reader)['allowed'],'Stale private stamp cannot overwrite current config; independently changed public prompt fails closed.');
    $fixed=gl_native($editor,$id,[],gl_form($editor,$id,$new_private));gl_check($fixed && $fixed['version']!==$new['version'],'A fresh native save repairs the mismatched prompt with another new version.');gl_solve($reader,$id);

    // A failed config write must not bless the public post's changed meaning.
    $before=get_post_meta($id,wuai_sluyu_guess_key(),true);$hits=0;
    $reject=function($check,$post_id,$key)use($id,&$hits){if((int)$post_id===$id && $key===wuai_sluyu_guess_key()){$hits++;return false;}return $check;};
    add_filter('update_post_metadata',$reject,999,3);add_filter('add_post_metadata',$reject,999,3);
    try{gl_native($editor,$id,['post_content'=>'Storage-failure public prompt requiring fresh companion settings.'],gl_form($editor,$id,$new_private));}finally{remove_filter('update_post_metadata',$reject,999);remove_filter('add_post_metadata',$reject,999);}
    gl_check($hits>0 && get_post_meta($id,wuai_sluyu_guess_key(),true)===$before && !wuai_sluyu_guess_config($id) && !wuai_sluyu_guess_state($id,$reader)['allowed'],'Exact injected private storage failure leaves old config inaccessible after public prompt save.');
    gl_check(gl_native($editor,$id,[],gl_form($editor,$id,$new_private))!==false,'Recovered storage permits an explicitly reviewed native repair.');gl_solve($reader,$id);
    $before=get_post_meta($id,wuai_sluyu_guess_key(),true);
    gl_native($editor,$id,['post_title'=>'Public edit with missing puzzle nonce.'],['wuai_service_meta_nonce'=>wp_create_nonce('wuai_service_meta'),'wuai_topic_kind'=>'guess']);
    gl_check(get_post_meta($id,wuai_sluyu_guess_key(),true)===$before && !wuai_sluyu_guess_config($id),'Missing companion nonce never rebinds a changed public prompt.');
    gl_native($editor,$id,[],gl_form($editor,$id,$new_private));gl_solve($reader,$id);

    // Malformed source config stays closed, then editor reconstruction must not
    // silently inherit an old version-bound solved event from the broken bundle.
    $bad_values=['corrupt',[],['choices'=>['Alpha','Beta']],null];
    foreach($bad_values as$bad){
        $valid=wuai_sluyu_guess_config($id);update_post_meta($id,wuai_sluyu_guess_key(),$bad);
        gl_check(!wuai_sluyu_guess_config($id) && !wuai_sluyu_guess_state($id,$reader)['allowed'] && !wuai_sluyu_guess_state($id,$editor,true)['allowed'],'Malformed source config fails closed even for preview.');
        $repaired=gl_native($editor,$id,[],gl_form($editor,$id,$new_private));
        gl_check($repaired && $repaired['version']!==$valid['version'] && !wuai_sluyu_guess_state($id,$reader)['allowed'],'Native reconstruction creates a fresh version, never resurrecting an old solved receipt.');gl_solve($reader,$id);
    }
    $valid=wuai_sluyu_guess_config($id);$tampered=$valid;$tampered['answer_hash']=str_repeat('0',64);update_post_meta($id,wuai_sluyu_guess_key(),$tampered);
    gl_check(!wuai_sluyu_guess_config($id),'A well-shaped hash that matches no answer is not a valid config.');
    $repaired=gl_native($editor,$id,[],gl_form($editor,$id,$new_private));gl_check($repaired && $repaired['version']!==$valid['version'] && !wuai_sluyu_guess_state($id,$reader)['allowed'],'Invalid answer hash reconstruction rotates its edition.');
    foreach($posts as$post_id){gl_check(strpos(get_post_field('post_content',$post_id),$secret)===false && strpos(get_post_field('post_excerpt',$post_id),$secret)===false,'Native lifecycle never copies the independent fragment into public fields.');foreach(wp_get_post_revisions($post_id)as$revision)gl_check(strpos($revision->post_content.$revision->post_excerpt,$secret)===false,'Native revisions contain only public prompt data.');}
    echo "PASS: native metabox type-conversion guard, author/banned-editor boundaries, title/body/excerpt version lifecycle, stale stamps, failed companion config storage, nonce omission, malformed-source reconstruction and public/revision isolation.\n";
}finally{
    $_POST=[];wp_set_current_user(0);foreach($posts as$id)wp_delete_post($id,true);require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$uid){delete_transient('wuai_guess_notice_'.$uid);wp_delete_user($uid);}remove_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);$_POST=$old_post;wp_set_current_user($old_user);
}
