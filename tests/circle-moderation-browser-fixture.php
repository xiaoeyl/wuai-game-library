<?php
// Disposable fixture only. The generated read-only MU helper is never shipped.
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('WP_QA_ROOT'); $expected=realpath(dirname(__DIR__).'/dist/checks-v1.1.0/site'); $resolved=$root?realpath($root):false;
if(!$expected || !$resolved || strcasecmp($expected,$resolved)!==0 || !is_file($resolved.'/wp-load.php'))throw new RuntimeException('WP_QA_ROOT must be the dedicated dist/checks-v1.1.0/site installation.');
$_SERVER['HTTP_HOST']='127.0.0.1:8771'; $_SERVER['REQUEST_URI']='/'; require $resolved.'/wp-load.php';
if(home_url()!=='http://127.0.0.1:8771' || strcasecmp((string)realpath(ABSPATH),$resolved)!==0)throw new RuntimeException('Local disposable WordPress only.');
if(!function_exists('wuai_sluyu_circle_review_held') || !function_exists('wuai_sluyu_guess_config'))throw new RuntimeException('Integrate circle moderation and its dependencies first.');
function cmb_check($condition,$message) { if(!$condition)throw new RuntimeException($message); }
$mu_dir=WP_CONTENT_DIR.'/mu-plugins'; if(!is_dir($mu_dir))cmb_check(wp_mkdir_p($mu_dir),'Create QA MU directory.');
$mu_file=$mu_dir.'/wuai-circle-moderation-browser-qa.php'; $marker='WUAI_CIRCLE_MODERATION_BROWSER_QA_V1';
if(is_file($mu_file) && strpos(file_get_contents($mu_file),$marker)===false)throw new RuntimeException('Refusing to overwrite an unrelated MU plugin.');
$plugin=<<<'PHP'
<?php
// WUAI_CIRCLE_MODERATION_BROWSER_QA_V1: never install outside the disposable site.
if(!defined('ABSPATH') || !defined('WUAI_CIRCLE_MODERATION_QA_ROOT'))return;
if(strcasecmp((string)realpath(ABSPATH),WUAI_CIRCLE_MODERATION_QA_ROOT)!==0 || home_url()!=='http://127.0.0.1:8771')return;
if(PHP_SAPI!=='cli' && (!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true) || ($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:8771'))return;
add_filter('pre_wp_mail',function(){return true;},PHP_INT_MAX);
add_action('login_init',function(){if(!headers_sent())header('X-Wuai-Circle-Moderation-QA-Mail-Blocked: 1');});
add_action('admin_notices',function(){if(current_user_can('manage_options'))echo '<input type="hidden" id="wuai-circle-moderation-qa-nonce" value="'.esc_attr(wp_create_nonce('wuai_circle_moderation_qa')).'">';});
// The helper only reads exact fixture objects. It cannot approve, ban, feature,
// alter settings, reveal private configurations, issue auth cookies or send mail.
add_action('wp_ajax_wuai_circle_moderation_qa_state',function(){
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST' || !current_user_can('manage_options') || wuai_sluyu_banned(get_current_user_id()))wp_send_json_error(['message'=>'Active admin POST only.'],403);
    check_ajax_referer('wuai_circle_moderation_qa');
    $raw=isset($_POST['id']) && is_string($_POST['id'])?wp_unslash($_POST['id']):'0';
    $raw_group=isset($_POST['group']) && is_string($_POST['group'])?wp_unslash($_POST['group']):'0';
    if(!preg_match('/^(?:0|[1-9][0-9]*)$/D',$raw) || !preg_match('/^(?:0|[1-9][0-9]*)$/D',$raw_group))wp_send_json_error(['message'=>'Invalid fixture ID.'],400);
    $data=['settings'=>wuai_sluyu_circle_review_settings(),'users'=>[],'queues'=>[]]; $prefix='qa_circle_review_browser_';
    foreach(['owner','other','writer','viewer','banned','admin'] as $name) {
        $user=get_user_by('login',$prefix.$name); if(!$user)continue;
        $data['users'][$name]=['id'=>(int)$user->ID,'banned'=>wuai_sluyu_banned($user->ID),'native_publish'=>user_can($user->ID,'publish_posts'),'native_edit_others'=>user_can($user->ID,'edit_others_posts'),'points'=>wuai_sluyu_points($user->ID)];
    }
    if((int)$raw_group) {
        $term=get_term((int)$raw_group,'sluyu_circle');
        if(!$term || is_wp_error($term) || strpos($term->name,'QA Circle Review Browser ')!==0)wp_send_json_error(['message'=>'Fixture circles only.'],404);
        $old=get_current_user_id();
        try {
            foreach($data['users'] as $name=>$user) {
                wp_set_current_user($user['id']); $query=new WP_Query(wuai_sluyu_circle_review_query_args((int)$raw_group));
                $data['queues'][$name]=['total'=>(int)$query->found_posts,'pages'=>(int)$query->max_num_pages,'ids'=>array_map('intval',wp_list_pluck($query->posts,'ID'))];
            }
        } finally { wp_set_current_user($old); }
    }
    if((int)$raw) {
        $post=get_post((int)$raw); $author=$post?get_userdata($post->post_author):false;
        if(!$post || $post->post_type!=='sluyu_topic' || !$author || strpos($author->user_login,$prefix)!==0 || strpos($post->post_title,'QA Circle Review Browser ')!==0)wp_send_json_error(['message'=>'Fixture topics only.'],404);
        $entries=wuai_sluyu_circle_review_audit($post->ID); $audit=[];
        if(is_array($entries))foreach($entries as $entry)$audit[]=array_intersect_key($entry,array_flip(['id','actor','group','groups','action','reason','phase','from','to']));
        $groups=wuai_sluyu_circle_review_groups($post->ID); $featured=[];
        if(!is_wp_error($groups))foreach($groups as $group)$featured[(string)$group]=wuai_sluyu_circle_featured($post->ID,$group);
        global $wpdb;
        $notices=$wpdb->get_results($wpdb->prepare('SELECT id,body FROM '.wuai_sluyu_table('messages')." WHERE recipient_id=%d AND kind='notice' AND body LIKE %s ORDER BY id",$post->post_author,'%'.$wpdb->esc_like($post->post_title).'%'),ARRAY_A);
        // Decision UUIDs match localized notices without exposing unrelated mail.
        foreach($audit as $entry) {
            $rows=$wpdb->get_results($wpdb->prepare('SELECT id,body FROM '.wuai_sluyu_table('messages')." WHERE recipient_id=%d AND kind='notice' AND body LIKE %s ORDER BY id",$post->post_author,'%'.$wpdb->esc_like($entry['id']).'%'),ARRAY_A);
            foreach($rows as $row)$notices[]=$row;
        }
        $unique=[]; foreach($notices as $notice)$unique[$notice['id']]=$notice;
        $data['post']=['id'=>(int)$post->ID,'status'=>$post->post_status,'held'=>wuai_sluyu_circle_review_held($post->ID),'kind'=>get_post_meta($post->ID,'_wuai_topic_kind',true),'title'=>$post->post_title,'groups'=>$groups,'featured'=>$featured,'private'=>wuai_sluyu_circle_review_private($post->ID),'config_valid'=>wuai_sluyu_circle_review_config_valid($post->ID),'audit_valid'=>$entries!==false,'audit'=>$audit,'notices'=>array_values($unique)];
    }
    wp_send_json_success($data);
});
PHP;
$plugin=str_replace("if(!defined('ABSPATH') || !defined('WUAI_CIRCLE_MODERATION_QA_ROOT'))return;","if(!defined('ABSPATH'))return;\ndefine('WUAI_CIRCLE_MODERATION_QA_ROOT',".var_export($resolved,true).');',$plugin);
cmb_check(file_put_contents($mu_file,$plugin)!==false,'Write local guarded mail blocker and read-only state helper.');
$mail_block=function(){return true;}; add_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
$old_user=get_current_user_id(); $old_post=$_POST; $old_get=$_GET;
$prefix='QA Circle Review Browser'; $login_prefix='qa_circle_review_browser_'; $users=[]; $circles=[]; $posts=[]; $titles=[]; $secrets=[];
try {
    $_POST=[]; $_GET=[]; wp_set_current_user(0);
    foreach(['owner','other','writer','viewer','banned','admin'] as $name) {
        $login=$login_prefix.$name; $existing=get_user_by('login',$login); $id=$existing?$existing->ID:wp_create_user($login,'qa-local-only',$login.'@example.test');
        cmb_check(!is_wp_error($id) && $id,'Create dedicated circle review account.'); $id=(int)$id; $users[$name]=$id;
        (new WP_User($id))->set_role($name==='admin'?'administrator':'subscriber');
        cmb_check(!is_wp_error(wp_update_user(['ID'=>$id,'display_name'=>'Review Browser '.ucfirst($name),'description'=>'','user_pass'=>'qa-local-only'])),'Reset dedicated credentials.');
        foreach(['_wuai_banned_until','_wuai_member_until','_wuai_ban_reason'] as $key)delete_user_meta($id,$key);
        foreach(array_keys(get_user_meta($id)) as $key)if(strpos($key,'_wuai_sluyu_like_')===0)delete_user_meta($id,$key);
        foreach(['circle_review','circle_feature','submit'] as $operation)delete_transient('wuai_rate_'.$id.'_'.$operation);
        $wpdb->delete(wuai_sluyu_table('events'),['user_id'=>$id]); $wpdb->delete(wuai_sluyu_table('memberships'),['user_id'=>$id]);
        $wpdb->query($wpdb->prepare('DELETE FROM '.wuai_sluyu_table('messages').' WHERE sender_id=%d OR recipient_id=%d',$id,$id));
        // A held post is deliberately absent from get_posts(), so scope cleanup
        // with exact dedicated author and title prefix rather than public queries.
        $old_ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type='sluyu_topic' AND post_author=%d AND post_title LIKE %s",$id,$wpdb->esc_like($prefix.' ').'%'));
        foreach($old_ids as $post_id)wp_delete_post((int)$post_id,true);
    }
    wp_set_current_user($users['admin']);
    global $wuai_sluyu_all_circle_terms; $previous_visibility=$wuai_sluyu_all_circle_terms??false; $wuai_sluyu_all_circle_terms=true;
    try {
        $old_terms=get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false,'wuai_include_pending'=>true]); cmb_check(!is_wp_error($old_terms),'Read fixture circles.');
        foreach($old_terms as $term)if(strpos($term->name,$prefix.' ')===0) { $wpdb->delete(wuai_sluyu_table('memberships'),['circle_id'=>$term->term_id]); wp_delete_term($term->term_id,'sluyu_circle'); }
    } finally { $wuai_sluyu_all_circle_terms=$previous_visibility; }
    foreach(['main'=>'owner','second'=>'owner','foreign'=>'other','queue'=>'owner','sort'=>'owner','sort_second'=>'owner','banned'=>'banned','held'=>'owner'] as $key=>$owner_name) {
        $term=wp_insert_term($prefix.' '.ucfirst(str_replace('_',' ',$key)),'sluyu_circle',['slug'=>'qa-circle-review-browser-'.$key,'description'=>'Dedicated isolated local circle moderation browser fixture.']);
        cmb_check(!is_wp_error($term),'Create fixture circle.'); $circle=(int)$term['term_id']; $circles[$key]=$circle;
        foreach(['_wuai_circle_status'=>'publish','_wuai_circle_owner'=>(string)$users[$owner_name],'_wuai_circle_join'=>'open','_wuai_circle_posting'=>'everyone'] as $field=>$value)update_term_meta($circle,$field,$value);
    }
    $create=function($key,$groups,$status='pending',$author_name='writer',$extra=[])use(&$posts,&$titles,$users,$prefix){
        $title=$prefix.' Topic '.ucwords(str_replace('_',' ',$key));
        $id=wp_insert_post(wp_slash(array_merge(['post_type'=>'sluyu_topic','post_author'=>$users[$author_name],'post_status'=>$status,'post_title'=>$title,'post_content'=>'Public browser review summary for '.$key.'. Private configuration belongs in separate metadata.','post_excerpt'=>'','comment_status'=>'open','ping_status'=>'closed','meta_input'=>['_wuai_topic_kind'=>'topic']],$extra)),true);
        cmb_check(!is_wp_error($id) && $id,'Create '.$key.' topic.'); $id=(int)$id; $posts[$key]=$id; $titles[$key]=$title;
        cmb_check(!is_wp_error(wp_set_object_terms($id,array_map('intval',$groups),'sluyu_circle')),'Assign exact topic circles.'); return $id;
    };
    foreach(['recommend','reject','publish','protected','guess','download','security','stale','own_private'] as $key)$create($key,[$circles['main']],'pending',$key==='own_private'?'owner':'writer');
    $create('cross',[$circles['main'],$circles['foreign']]); $create('same_owner',[$circles['main'],$circles['second']]);
    $create('banned',[$circles['banned']]);
    for($i=1;$i<=13;$i++)$create('queue_'.str_pad((string)$i,2,'0',STR_PAD_LEFT),[$circles['queue']]);
    $create('queue_cross',[$circles['queue'],$circles['foreign']]);
    foreach(['sort_first','sort_second','sort_third'] as $key)$create($key,$key==='sort_first'?[$circles['sort'],$circles['sort_second']]:[$circles['sort']],'publish','writer',['post_date'=>'2026-01-01 12:00:00','post_date_gmt'=>'2026-01-01 12:00:00']);
    update_user_meta($users['owner'],'_wuai_sluyu_like_'.$posts['sort_first'],'1'); update_user_meta($users['viewer'],'_wuai_sluyu_like_'.$posts['sort_first'],'1'); update_user_meta($users['other'],'_wuai_sluyu_like_'.$posts['sort_second'],'1');
    foreach([$posts['sort_second'],$posts['sort_second'],$posts['sort_third']] as $id)cmb_check((bool)wp_insert_comment(['comment_post_ID'=>$id,'user_id'=>$users['viewer'],'comment_author'=>'Review Browser Reader','comment_author_email'=>'review-reader@example.test','comment_content'=>'Local approved sorting comment.','comment_approved'=>1]),'Seed sorting comment.');
    foreach(['sort_first','sort_second','sort_third'] as $key)wp_update_comment_count_now($posts[$key]);
    $secret_prefix='QA-CIRCLE-REVIEW-PRIVATE-'.substr(str_replace('-','',wp_generate_uuid4()),0,12);
    foreach(['protected','own_private'] as $key) {
        $secrets[$key]=$secret_prefix.'-'.strtoupper($key); $config=wuai_sluyu_protected_validate(['protected_body'=>'<p>'.$secrets[$key].'</p>','access'=>'login','cost'=>'0']);
        cmb_check(is_array($config),'Validate private body.'); update_post_meta($posts[$key],wuai_sluyu_protected_key(),wp_slash($config));
    }
    $secrets['guess']=$secret_prefix.'-GUESS'; update_post_meta($posts['guess'],'_wuai_topic_kind','guess');
    update_post_meta($posts['guess'],wuai_sluyu_guess_key(),wp_slash(wuai_sluyu_guess_build($posts['guess'],['choices'=>['North','South','East'],'answer_index'=>1,'body'=>'<p>'.$secrets['guess'].'</p>'])));
    $secrets['download']=$secret_prefix.'-DOWNLOAD';
    update_post_meta($posts['download'],'_wuai_download_settings',['url'=>'https://example.test/'.$secrets['download'].'.zip','access'=>'login','cost'=>0,'version'=>wp_generate_uuid4(),'password'=>$secrets['download'].'-CODE']);
    foreach(['protected','own_private','guess','download'] as $key)cmb_check(wuai_sluyu_circle_review_config_valid($posts[$key]),'Valid private fixture '.$key);
    $create('held',[$circles['held']],'publish'); update_post_meta($posts['held'],wuai_sluyu_circle_review_hold_key(),wp_generate_uuid4());
    update_option(wuai_sluyu_circle_review_option(),['owner_publish'=>'0','revision'=>wp_generate_uuid4()],false); wuai_sluyu_circle_review_refresh_settings();
    update_user_meta($users['banned'],'_wuai_banned_until',-1);
    foreach($users as $uid) { $wpdb->query($wpdb->prepare('DELETE FROM '.wuai_sluyu_table('messages').' WHERE sender_id=%d OR recipient_id=%d',$uid,$uid)); cmb_check(wuai_sluyu_points($uid)===0,'No points in this nonmonetary fixture.'); }
    wp_set_current_user($users['owner']); $queue=new WP_Query(wuai_sluyu_circle_review_query_args($circles['queue'])); cmb_check((int)$queue->found_posts===13,'Owner queue excludes the cross-circle topic before totals.');
    wp_set_current_user($users['admin']); $queue=new WP_Query(wuai_sluyu_circle_review_query_args($circles['queue'])); cmb_check((int)$queue->found_posts===14,'Administrator sees every fixture queue topic.');
    $manifest=['base'=>home_url(),'loginPrefix'=>$login_prefix,'publicPrefix'=>$prefix,'users'=>$users,'circles'=>$circles,'posts'=>$posts,'titles'=>$titles,'secrets'=>$secrets,'muPlugin'=>$mu_file];
    $output=getenv('WP_QA_OUTPUT')?:dirname($resolved); if(!is_dir($output))cmb_check(wp_mkdir_p($output),'Create fixture output directory.');
    cmb_check(file_put_contents(rtrim($output,'/\\').'/circle-moderation-fixture.json',wp_json_encode($manifest,JSON_PRETTY_PRINT))!==false,'Write browser manifest.');
    echo "PASS: dedicated circle review fixture, 13+1 scoped queue, private configurations, sorted public topics, held topic, and read-only guarded accounting prepared. No mail sent.\n";
} finally { remove_filter('pre_wp_mail',$mail_block,PHP_INT_MAX); $_POST=$old_post; $_GET=$old_get; wp_set_current_user($old_user); }
