<?php
// CLI-only fixture for a disposable local WordPress database.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('Set WP_QA_ROOT to a disposable installation.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
if (!preg_match('~^http://(127\.0\.0\.1|localhost):8771$~', home_url())) { throw new RuntimeException('Refusing to seed a non-QA site.'); }
$options = wuai_sluyu_get_options();
$options['hero_video'] = ''; $options['enable_loader'] = '0'; $options['legacy_html_links'] = '0'; $options['show_post_meta']='1';
foreach(['hero_image','promo_image','ad_image','footer_banner','article_banner'] as $key)$options[$key]=wuai_sluyu_defaults()[$key];
update_option('wuai_sluyu_options', $options);
update_option('permalink_structure', '');
update_option('timezone_string', 'Asia/Shanghai');
$users = [];
foreach (['qa_member_a','qa_member_b','qa_member_c'] as $login) {
    $existing=get_user_by('login',$login);
    $id=$existing?$existing->ID:wp_create_user($login,'qa-local-only',$login.'@example.test');
    (new WP_User($id))->set_role('subscriber');
    wp_update_user(['ID'=>$id,'description'=>'','display_name'=>$login]);
    foreach(['submit','verify','report','message'] as $operation)delete_transient('wuai_rate_'.$id.'_'.$operation);
    foreach(['_wuai_banned_until','_wuai_sluyu_checkin_date','_wuai_sluyu_checkin_days','_wuai_checkin_streak','_wuai_verified_title','_wuai_membership_state','_wuai_membership_sync','_wuai_membership_grants','_wuai_member_until'] as $key)delete_user_meta($id,$key);
    $wpdb->delete(wuai_sluyu_table('events'),['user_id'=>$id]);
    $wpdb->query($wpdb->prepare('DELETE FROM '.wuai_sluyu_table('messages').' WHERE sender_id=%d OR recipient_id=%d',$id,$id));
    $existing_posts=get_posts(['post_type'=>['post','sluyu_topic','sluyu_news','sluyu_link','sluyu_verify','sluyu_ticket'],'author'=>$id,'post_status'=>'any','numberposts'=>-1]);
    foreach($existing_posts as $post)wp_delete_post($post->ID,true);
    $users[$login]=$id;
}
$posts=[];
foreach(['article'=>['post','publish',''],'draft'=>['post','draft',''],'protected'=>['post','publish','locked'],'poll'=>['sluyu_topic','publish',''],'news'=>['sluyu_news','publish',''],'member'=>['post','publish',''],'login'=>['post','publish',''],'question'=>['sluyu_topic','publish','']] as $key=>$config) {
    $id=wp_insert_post(['post_type'=>$config[0],'post_status'=>$config[1],'post_password'=>$config[2],'post_title'=>'QA '.$key,'post_author'=>$users['qa_member_a'],'post_content'=>"<h2>测试内容</h2><p>这是隔离测试站的内容。不会导入正式站点。</p>",'comment_status'=>'open']);
    $posts[$key]=$id;
}
update_post_meta($posts['poll'],'_wuai_topic_kind','poll'); update_post_meta($posts['poll'],'_wuai_poll_choices',['选项 A','选项 B']);
update_post_meta($posts['question'],'_wuai_topic_kind','question');
foreach(['article'=>'public','member'=>'member','login'=>'login','protected'=>'public'] as $key=>$mode) {
    update_post_meta($posts[$key],'_wuai_sluyu_download_access',$mode);
    update_post_meta($posts[$key],'_wuai_sluyu_download_url','https://example.test/'.$key.'-secret.zip');
    update_post_meta($posts[$key],'_wuai_sluyu_download_password',$key.'-secret-code');
}
$comment=wp_insert_comment(['comment_post_ID'=>$posts['question'],'user_id'=>$users['qa_member_b'],'comment_author'=>'QA answer','comment_content'=>'测试回答内容','comment_approved'=>1]);
$manifest=['users'=>$users,'posts'=>$posts,'comment'=>$comment,'routes'=>array_keys(wuai_sluyu_portal_routes())];
file_put_contents(dirname($root).'/fixture.json',wp_json_encode($manifest));
flush_rewrite_rules(false);
echo "PASS: isolated fixture prepared.\n";
