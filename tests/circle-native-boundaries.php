<?php
// Native/core integration only; run serially on the disposable local installation.
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('WP_QA_ROOT'); $resolved=$root?realpath($root):false; $expected=realpath(dirname(__DIR__).'/dist/checks-v1.1.0/site');
if(!$resolved || !$expected || strcasecmp($resolved,$expected)!==0 || !is_file($resolved.'/wp-load.php'))throw new RuntimeException('Dedicated WP_QA_ROOT required.');
$_SERVER['HTTP_HOST']='127.0.0.1:8771'; $_SERVER['REQUEST_URI']='/'; $_SERVER['REMOTE_ADDR']='127.0.0.1'; require $resolved.'/wp-load.php';
if(home_url()!=='http://127.0.0.1:8771' || !function_exists('wuai_sluyu_circle_review_rest_editor'))throw new RuntimeException('Integrated local native review boundaries required.');
function cnb_check($condition,$message) { if(!$condition)throw new RuntimeException($message); }
function cnb_rest($uid,$method,$route,$input=[]) {
    wp_set_current_user(0); wp_set_current_user($uid); $_POST=[]; $GLOBALS['current_screen']=null;
    $request=new WP_REST_Request($method,$route); foreach($input as $key=>$value)$request->set_param($key,$value);
    return rest_do_request($request);
}
function cnb_notice_count($uid) {
    global $wpdb; return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.wuai_sluyu_table('messages')." WHERE recipient_id=%d AND kind='notice'",$uid));
}
function cnb_native_data($id,$status='publish') {
    $post=get_post($id);
    return ['post_ID'=>$id,'post_type'=>'sluyu_topic','post_author'=>$post->post_author,'post_status'=>$status,'post_title'=>$post->post_title,'content'=>$post->post_content,'excerpt'=>$post->post_excerpt,'comment_status'=>'open','ping_status'=>'closed'];
}
$old_user=get_current_user_id(); $old_post=$_POST; $old_get=$_GET; $old_screen=$GLOBALS['current_screen']??null; $old_global_post=$GLOBALS['post']??null;
$old_option=get_option(wuai_sluyu_circle_review_option(),null); $users=[]; $posts=[]; $terms=[]; $comments=[];
$prefix='qa_circle_native_'.strtolower(wp_generate_password(8,false,false)); $secret='CNB-PRIVATE-'.wp_generate_uuid4();
$mail_block=function(){return true;}; add_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
try {
    foreach(['owner'=>'subscriber','author'=>'author','editor'=>'editor','limited'=>'editor','admin'=>'administrator'] as $name=>$role) {
        $id=wp_create_user($prefix.'_'.$name,'qa-local-only',$prefix.'_'.$name.'@example.test'); cnb_check(!is_wp_error($id),'Create isolated native boundary user.');
        (new WP_User($id))->set_role($role); $users[$name]=(int)$id;
    }
    $owner=$users['owner']; $author=$users['author']; $editor=$users['editor']; $limited=$users['limited']; $admin=$users['admin'];
    wp_set_current_user($admin); $_POST=[];
    $circle=wp_insert_term($prefix.'_circle','sluyu_circle'); cnb_check(!is_wp_error($circle),'Create isolated native boundary circle.'); $circle=(int)$circle['term_id']; $terms[]=$circle;
    foreach(['_wuai_circle_status'=>'publish','_wuai_circle_owner'=>(string)$owner,'_wuai_circle_join'=>'open','_wuai_circle_posting'=>'everyone'] as $key=>$value)update_term_meta($circle,$key,$value);
    $make=function($label,$status='pending',$type='sluyu_topic')use(&$posts,$admin,$author,$circle,$prefix){
        wp_set_current_user($admin); $_POST=[];
        $id=wp_insert_post(['post_type'=>$type,'post_author'=>$author,'post_status'=>$status,'post_title'=>$prefix.' '.$label,'post_content'=>'Public native review text: '.$label.'.','post_excerpt'=>'Public native summary.','comment_status'=>'open','ping_status'=>'closed','meta_input'=>$type==='sluyu_topic'?['_wuai_topic_kind'=>'topic']:[]],true);
        cnb_check(!is_wp_error($id) && $id,'Create native fixture.'); $id=(int)$id; $posts[]=$id;
        if($type==='sluyu_topic')cnb_check(!is_wp_error(wp_set_object_terms($id,[$circle],'sluyu_circle')),'Assign native circle.'); return $id;
    };
    require_once ABSPATH.'wp-admin/includes/post.php';
    $topic_type=get_post_type_object('sluyu_topic'); cnb_check($topic_type->cap->publish_posts==='wuai_publish_circle_topics','Topic native publishing has its own three-capability primitive.');
    cnb_check(get_post_type_object('sluyu_news')->cap->publish_posts==='wuai_manage_service_content','Unrelated service publishing behavior is preserved.');
    foreach(['edit_others_posts','publish_posts','manage_categories'] as $missing) {
        (new WP_User($limited))->add_cap($missing,false); clean_user_cache($limited); wp_set_current_user(0); wp_set_current_user($limited);
        cnb_check(!wuai_sluyu_circle_review_editor($limited) && !user_can($limited,$topic_type->cap->publish_posts),'A limited editor without '.$missing.' cannot natively publish topics.');
        $id=$make('limited '.$missing); update_post_meta($id,wuai_sluyu_protected_key(),['body'=>'<p>'.$secret.'</p>','access'=>'login','cost'=>0,'version'=>wp_generate_uuid4()]);
        foreach(['publish','future','private'] as $status) {
            $input=['status'=>$status]; if($status==='future')$input['date_gmt']=gmdate('Y-m-d\TH:i:s',time()+DAY_IN_SECONDS);
            $before=cnb_notice_count($author); $response=cnb_rest($limited,'POST','/wp/v2/sluyu_topic/'.$id,$input);
            cnb_check($response->get_status()===403 && get_post_status($id)==='pending','REST update rejects '.$status.' without '.$missing.'.');
            cnb_check(cnb_notice_count($author)===$before,'Denied native status update never sends approval.');
            $response=cnb_rest($limited,'POST','/wp/v2/sluyu_topic',array_merge($input,['title'=>$prefix.' forbidden create','content'=>'This must not publish.']));
            $data=$response->get_data(); if(!empty($data['id']))$posts[]=(int)$data['id'];
            cnb_check($response->get_status()===403,'REST create rejects '.$status.' without '.$missing.'.');
        }
        wp_set_current_user($limited); $_POST=[];
        foreach(['publish','future','private'] as $status) {
            $translated=_wp_translate_postdata(true,cnb_native_data($id,$status));
            cnb_check(is_wp_error($translated) || !in_array($translated['post_status'],['publish','future','private'],true),'Classic editor translation cannot publish/private/schedule without '.$missing.'.');
        }
        if($missing!=='edit_others_posts') {
            $saved=edit_post(wp_slash(cnb_native_data($id)));
            cnb_check((int)$saved===$id && get_post_status($id)==='pending','Real classic edit_post silently queues rather than publishing without '.$missing.'.');
            $draft=cnb_rest($limited,'POST','/wp/v2/sluyu_topic',['title'=>$prefix.' allowed draft '.$missing,'content'=>'Limited editors may retain draft editing.','status'=>'draft']);
            $data=$draft->get_data(); if(!empty($data['id']))$posts[]=(int)$data['id'];
            cnb_check($draft->get_status()===201 && $data['status']==='draft','Limited native editor can still save drafts without publication.');
        }
        (new WP_User($limited))->remove_cap($missing); clean_user_cache($limited);
    }
    $id=$make('trusted REST approval'); $before=cnb_notice_count($author);
    $response=cnb_rest($editor,'POST','/wp/v2/sluyu_topic/'.$id,['status'=>'publish']);
    cnb_check($response->get_status()===200 && get_post_status($id)==='publish' && cnb_notice_count($author)===$before+1,'Normal editor retains native REST final publication with exactly one author notice.');
    $response=cnb_rest($editor,'POST','/wp/v2/sluyu_topic',['title'=>$prefix.' trusted create','content'=>'Trusted native topic publishing.','status'=>'publish']);
    $data=$response->get_data(); if(!empty($data['id']))$posts[]=(int)$data['id']; cnb_check($response->get_status()===201 && $data['status']==='publish','Normal editor can create a published topic.');
    $response=cnb_rest($author,'POST','/wp/v2/posts',['title'=>$prefix.' ordinary author post','content'=>'Ordinary posts are not circle topics.','status'=>'publish']);
    $data=$response->get_data(); if(!empty($data['id']))$posts[]=(int)$data['id']; cnb_check($response->get_status()===201,'Ordinary WordPress authors retain their own post publication capability.');

    // Frontend ownership is explicit business authorization, not a native role grant.
    wp_set_current_user($admin); $setting=wuai_sluyu_circle_review_settings_save(['owner_publish'=>'1','stamp'=>wuai_sluyu_circle_review_settings_stamp()]); cnb_check($setting===true,'Explicitly enable ordinary circle-owner approval for this fixture.');
    $id=$make('business owner publish'); wp_set_current_user($owner);
    cnb_check(!current_user_can($topic_type->cap->publish_posts),'Owner remains unable to invoke native publication.');
    $approved=wuai_sluyu_circle_review($id,$circle,'approve','',wuai_sluyu_circle_review_stamp($id,$circle));
    cnb_check(!is_wp_error($approved) && get_post_status($id)==='publish','Narrow explicit owner business operation still works without native publish capability.');

    $held=$make('held native update'); wp_set_current_user($admin);
    update_post_meta($held,wuai_sluyu_protected_key(),['body'=>'<p>'.$secret.'</p>','access'=>'login','cost'=>0,'version'=>wp_generate_uuid4()]);
    wp_set_current_user($owner); $private_reason='Private audit reason only for author and editors.';
    $recommended=wuai_sluyu_circle_review($held,$circle,'approve',$private_reason,wuai_sluyu_circle_review_stamp($held,$circle));
    cnb_check(!is_wp_error($recommended) && get_post_status($held)==='pending' && count(wuai_sluyu_circle_review_audit($held))===1,'Create an actual private recommendation audit before native boundary checks.');
    wp_set_current_user($admin); update_post_meta($held,wuai_sluyu_circle_review_hold_key(),wp_generate_uuid4());
    $before=cnb_notice_count($author); wp_set_current_user($editor); $_POST=[];
    $saved=edit_post(wp_slash(cnb_native_data($held))); cnb_check((int)$saved===$held && get_post_status($held)==='publish','Native classic editor reaches a real held publish status.');
    cnb_check(wuai_sluyu_circle_review_held($held) && cnb_notice_count($author)===$before,'Native publication does not clear hold or claim that isolated content passed review.');
    $audit_before=get_post_meta($held,wuai_sluyu_circle_review_key(),true); $changed='Updated public content while the native transaction remains isolated.';
    $saved=edit_post(wp_slash(array_merge(cnb_native_data($held),['content'=>$changed])));
    cnb_check((int)$saved===$held && get_post_field('post_content',$held)===$changed && wuai_sluyu_circle_review_held($held),'Trusted native content edits preserve isolation instead of deleting the hold.');
    cnb_check(get_post_meta($held,wuai_sluyu_circle_review_key(),true)===$audit_before && cnb_notice_count($author)===$before,'Native edits do not invent audit completion or approval notices.');
    $revision=wp_save_post_revision($held); cnb_check(!is_wp_error($revision),'Native revision save is available.');
    $revisions=wp_get_post_revisions($held); cnb_check((bool)$revisions,'The held topic has at least one real WordPress revision.'); $revision_id=(int)key($revisions);
    foreach([0,$owner,$author,$editor,$admin] as $uid) {
        foreach(['GET','HEAD'] as $method) {
            cnb_check(cnb_rest($uid,$method,'/wp/v2/sluyu_topic/'.$held)->get_status()===404,'View-context held REST is inaccessible even to editors.');
            cnb_check(cnb_rest($uid,$method,'/wp/v2/sluyu_topic/'.$held.'/revisions')->get_status()===404,'View-context revisions cannot bypass the held parent.');
        }
    }
    foreach([$editor,$admin] as $uid) {
        $response=cnb_rest($uid,'GET','/wp/v2/sluyu_topic/'.$held,['context'=>'edit']); $data=$response->get_data();
        cnb_check($response->get_status()===200 && $data['content']['raw']===$changed,'Complete site editor can load the held raw public body in native edit context.');
        $json=wp_json_encode($data); cnb_check(strpos($json,$secret)===false && strpos($json,$private_reason)===false && strpos($json,wuai_sluyu_protected_key())===false && strpos($json,wuai_sluyu_circle_review_key())===false,'Even authenticated native edit REST omits independent private and actual audit metadata.');
        cnb_check(cnb_rest($uid,'HEAD','/wp/v2/sluyu_topic/'.$held,['context'=>'edit'])->get_status()===200,'Native editor HEAD preflight remains usable.');
        $response=cnb_rest($uid,'GET','/wp/v2/sluyu_topic/'.$held.'/revisions',['context'=>'edit']); cnb_check($response->get_status()===200 && $response->get_data(),'Native edit context can inspect actual revisions.');
        cnb_check(strpos(wp_json_encode($response->get_data()),$secret)===false,'Private fragment never appears in native revisions.');
        cnb_check(cnb_rest($uid,'GET','/wp/v2/sluyu_topic/'.$held.'/revisions/'.$revision_id,['context'=>'edit'])->get_status()===200,'Native editor can inspect an individual revision.');
        cnb_check(cnb_rest($uid,'GET','/wp/v2/sluyu_topic/'.$held.'/autosaves',['context'=>'edit'])->get_status()===200,'Gutenberg autosave preload is not blocked by the public hold.');
    }
    foreach([0,$owner,$author] as $uid)cnb_check(in_array(cnb_rest($uid,'GET','/wp/v2/sluyu_topic/'.$held,['context'=>'edit'])->get_status(),[401,403,404],true),'Non-editor cannot ask for edit-context held content.');
    foreach(['publish_posts','manage_categories'] as $missing) {
        (new WP_User($limited))->add_cap($missing,false); clean_user_cache($limited);
        cnb_check(cnb_rest($limited,'GET','/wp/v2/sluyu_topic/'.$held,['context'=>'edit'])->get_status()===404,'Partial editor cannot bypass held isolation with context=edit.');
        (new WP_User($limited))->remove_cap($missing); clean_user_cache($limited);
    }
    update_user_meta($editor,'_wuai_banned_until',-1);
    try { cnb_check(in_array(cnb_rest($editor,'GET','/wp/v2/sluyu_topic/'.$held,['context'=>'edit'])->get_status(),[403,404],true),'Banned editor cannot use the native edit-context exception.'); }
    finally { delete_user_meta($editor,'_wuai_banned_until'); }

    // A real comments admin screen is deliberately allowed to inspect text;
    // frontend helpers and public REST must remain empty/404 for the same row.
    $comment_topic=$make('held historical comment','publish'); $comment_token='CNB-COMMENT-'.wp_generate_uuid4(); wp_set_current_user($admin);
    $comment_id=wp_insert_comment(['comment_post_ID'=>$comment_topic,'user_id'=>$author,'comment_author'=>'Native review reader','comment_author_email'=>'native-reader@example.test','comment_content'=>$comment_token,'comment_approved'=>1]);
    cnb_check((bool)$comment_id,'Create approved historical comment before quarantine.'); $comments[]=(int)$comment_id; update_post_meta($comment_topic,wuai_sluyu_circle_review_hold_key(),wp_generate_uuid4());
    $GLOBALS['current_screen']=null; wp_set_current_user($editor);
    cnb_check(get_comment_text($comment_id)==='' && !get_comments(['post_id'=>$comment_topic,'status'=>'approve']),'Even an editor frontend request cannot render a held public comment.');
    cnb_check(get_comment_excerpt($comment_id)==='','Direct comment excerpt cannot bypass text isolation.');
    cnb_check(cnb_rest(0,'GET','/wp/v2/comments/'.$comment_id)->get_status()===404,'Guest direct comment REST is hidden.');
    cnb_check(cnb_rest($editor,'GET','/wp/v2/comments/'.$comment_id)->get_status()===404,'Default editor REST is view context, not implicit bypass.');
    $response=cnb_rest($editor,'GET','/wp/v2/comments/'.$comment_id,['context'=>'edit']);
    cnb_check($response->get_status()===200 && $response->get_data()['content']['raw']===$comment_token,'Trusted edit context can load the exact comment for moderation.');
    require_once ABSPATH.'wp-admin/includes/class-wp-screen.php'; require_once ABSPATH.'wp-admin/includes/screen.php';
    wp_set_current_user($editor); set_current_screen('edit-comments');
    cnb_check(is_admin() && !wp_doing_ajax(),'Use actual WordPress admin screen context without globally defining WP_ADMIN.');
    cnb_check(get_comment_text($comment_id)===$comment_token && count(get_comments(['post_id'=>$comment_topic,'status'=>'approve']))===1,'Native comments table can retrieve held rows and their visible review text.');
    cnb_check(strpos(get_comment_excerpt($comment_id),'CNB-COMMENT-')===0,'Trusted native comment excerpt remains available with the locale-specific excerpt length.');
    cnb_check((int)get_comments(['post_id'=>$comment_topic,'status'=>'approve','count'=>true])===1,'Native review count uses its isolated cache domain.');
    ob_start(); comment_text($comment_id); $comment_html=ob_get_clean(); cnb_check(strpos($comment_html,$comment_token)!==false,'The exact comment_text renderer used by core admin lists is nonempty.');
    wp_set_current_user($owner); cnb_check(get_comment_text($comment_id)==='','Admin screen context alone never grants a circle owner the trusted-editor exception.');
    wp_set_current_user($editor); update_user_meta($editor,'_wuai_banned_until',-1);
    try { cnb_check(get_comment_text($comment_id)==='','Banned native editor cannot use comments screen exception.'); }
    finally { delete_user_meta($editor,'_wuai_banned_until'); }
    $GLOBALS['current_screen']=null; wp_set_current_user(0); cnb_check(get_comment_text($comment_id)==='','Native review must not contaminate later frontend rendering.');
    cnb_check(get_comment_excerpt($comment_id)==='','Native excerpt rendering cannot grant later guests access.');
    cnb_check(!get_comments(['post_id'=>$comment_topic,'status'=>'approve']) && (int)get_comments(['post_id'=>$comment_topic,'status'=>'approve','count'=>true])===0,'Warmed trusted comment list and count caches never leak into frontend queries.');

    wp_set_current_user($admin); $_POST=[]; $preserved_audit=get_post_meta($held,wuai_sluyu_circle_review_key(),true);
    ob_start(); wuai_sluyu_circle_review_audit_box(get_post($held)); $box=ob_get_clean();
    cnb_check(strpos($box,'circle-topics')!==false && strpos($box,$private_reason)!==false,'Native audit metabox offers the real recovery route and preserves prior decisions.');
    wp_update_post(['ID'=>$held,'post_password'=>'temporary-native-password']); wp_set_object_terms($held,[],'sluyu_circle');
    ob_start(); wuai_sluyu_circle_review_audit_box(get_post($held)); $box=ob_get_clean();
    cnb_check(strpos($box,'circle-topics')===false && strpos($box,$private_reason)!==false && wuai_sluyu_circle_review_held($held),'Unscoped/password native edits preserve hold and audit without offering an unusable recovery link.');
    cnb_check(strpos($box,"\xE5\xAF\x86\xE7\xA0\x81")!==false && strpos($box,"\xE5\x9C\x88\xE5\xAD\x90")!==false,'Blocked native recovery explicitly explains password and circle prerequisites.');
    wp_update_post(['ID'=>$held,'post_password'=>'']); wp_set_object_terms($held,[$circle],'sluyu_circle');
    ob_start(); wuai_sluyu_circle_review_audit_box(get_post($held)); $box=ob_get_clean();
    cnb_check(strpos($box,'circle-topics')!==false && get_post_meta($held,wuai_sluyu_circle_review_key(),true)===$preserved_audit,'Restoring safe prerequisites makes recovery reachable without deleting historical audit.');
    echo "PASS: separate native topic publish primitive, limited editor REST/classic denial, retained ordinary author and explicit owner business rights, held native notices, trusted edit-context post/revisions/autosaves, and native comment review isolation.\n";
} finally {
    $_POST=[]; $_GET=[]; $GLOBALS['current_screen']=null; wp_set_current_user($users['admin']??0);
    if($old_option===null)delete_option(wuai_sluyu_circle_review_option()); else update_option(wuai_sluyu_circle_review_option(),$old_option,false); wuai_sluyu_circle_review_refresh_settings();
    foreach($comments as $id)wp_delete_comment($id,true); foreach(array_unique($posts) as $id)wp_delete_post($id,true);
    global $wuai_sluyu_all_circle_terms; $previous=$wuai_sluyu_all_circle_terms??false; $wuai_sluyu_all_circle_terms=true;
    try { foreach($terms as $id)wp_delete_term($id,'sluyu_circle'); } finally { $wuai_sluyu_all_circle_terms=$previous; }
    require_once ABSPATH.'wp-admin/includes/user.php'; foreach($users as $id) { delete_user_meta($id,'_wuai_banned_until'); wp_delete_user($id); }
    remove_filter('pre_wp_mail',$mail_block,PHP_INT_MAX); wp_set_current_user($old_user); $_POST=$old_post; $_GET=$old_get; $GLOBALS['current_screen']=$old_screen; $GLOBALS['post']=$old_global_post;
}
