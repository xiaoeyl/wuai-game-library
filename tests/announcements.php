<?php
// Candidate fixture; root must schedule this serially. Never run on a real site.
if (PHP_SAPI!=='cli') { exit(1); }
$root=getenv('WP_QA_ROOT'); $resolved=$root?realpath($root):false; $expected=realpath(dirname(__DIR__).'/dist/checks-v1.1.0/site');
if (!$resolved || !$expected || strcasecmp($resolved,$expected)!==0 || !is_file($resolved.'/wp-load.php')) { throw new RuntimeException('Dedicated disposable WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST']='127.0.0.1:8771'; $_SERVER['REMOTE_ADDR']='127.0.0.1'; $_SERVER['REQUEST_URI']='/';
require $resolved.'/wp-load.php';
if (home_url()!=='http://127.0.0.1:8771' || !defined('DB_ENGINE') || DB_ENGINE!=='sqlite' || !is_a($wpdb,'WP_SQLite_DB') || !function_exists('wuai_sluyu_announcements_list')) { throw new RuntimeException('Integrated candidate on isolated SQLite required.'); }
function announcements_check($condition,$message) { if (!$condition) { throw new RuntimeException($message); } }
function announcements_render_widget($instance=[]) {
    $widget=new Wuai_Sluyu_Announcements_Widget(); ob_start();
    $widget->widget(['before_widget'=>'<section>','after_widget'=>'</section>','before_title'=>'<h2>','after_title'=>'</h2>'],$instance);
    return ob_get_clean();
}
$old_user=get_current_user_id(); $old_get=$_GET; $old_post=$_POST; $old_method=$_SERVER['REQUEST_METHOD']??null;
$old_uri=$_SERVER['REQUEST_URI']; $old_settings=get_option(wuai_sluyu_announcements_option(),null);
$users=[]; $posts=[]; $prefix='qa_announcement_'.strtolower(wp_generate_password(8,false,false));
$mail_block=function () { return true; }; add_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
$shortcode_calls=0; $shortcode=function () use (&$shortcode_calls) { $shortcode_calls++; return 'DO-NOT-EXECUTE'; }; add_shortcode('qa_announcement_secret',$shortcode);
try {
    foreach (['admin'=>'administrator','editor'=>'editor','reader'=>'subscriber'] as $name=>$role) {
        $id=wp_create_user($prefix.'_'.$name,wp_generate_password(40,true,true),$prefix.'_'.$name.'@example.test');
        announcements_check(!is_wp_error($id),'Create announcement fixture user.'); $users[$name]=(int)$id; (new WP_User($id))->set_role($role);
    }
    wp_set_current_user($users['admin']); $_POST=[]; $_GET=[];
    $make=function ($name,$type='post',$status='publish',$password='') use (&$posts,$prefix,$users) {
        $id=wp_insert_post(['post_type'=>$type,'post_status'=>$status,'post_author'=>$users['admin'],'post_title'=>$prefix.' '.$name,'post_content'=>'<p>Public announcement text.</p>[qa_announcement_secret]','post_password'=>$password,'comment_status'=>'closed','ping_status'=>'closed'],true);
        announcements_check(!is_wp_error($id) && $id,'Create announcement fixture content.'); $posts[$name]=(int)$id; return (int)$id;
    };
    $make('article'); $make('page','page'); $make('news','sluyu_news'); $make('unlisted');
    $make('private','post','private'); $make('draft','post','draft'); $make('password','post','publish','secret-not-for-output');
    $make('topic','sluyu_topic'); $make('ticket','sluyu_ticket','private');
    $_SERVER['REQUEST_METHOD']='POST';
    $nonce=wp_create_nonce('wuai_sluyu_announcements');
    $save=['_wpnonce'=>$nonce,'enabled'=>'1','ids'=>$posts['news']."\n".$posts['article'].','.$posts['page']];
    announcements_check(!is_wp_error(wuai_sluyu_announcements_save($save)),'Administrator explicitly configures three public announcements.');
    $settings=wuai_sluyu_announcements_settings();
    announcements_check($settings['enabled'] && $settings['ids']===[$posts['news'],$posts['article'],$posts['page']],'Administrative selection order is persisted.');
    $_SERVER['REQUEST_METHOD']='GET';
    announcements_check(is_wp_error(wuai_sluyu_announcements_save($save)) && wuai_sluyu_announcements_settings()===$settings,'GET cannot mutate announcement configuration.');
    $_SERVER['REQUEST_METHOD']='POST';
    foreach (['editor','reader'] as $name) {
        wp_set_current_user($users[$name]); $input=$save; $input['_wpnonce']=wp_create_nonce('wuai_sluyu_announcements');
        announcements_check(is_wp_error(wuai_sluyu_announcements_save($input)),'Only manage_options can choose official announcements.');
    }
    wp_set_current_user($users['admin']); $save['_wpnonce']=wp_create_nonce('wuai_sluyu_announcements');
    foreach ([['_wpnonce'=>'bad'],['_wpnonce'=>['bad']],['enabled'=>['1']],['ids'=>[$posts['article']]],['ids'=>'-1'],['ids'=>$posts['article'].','.$posts['article']],['ids'=>'1 OR 1=1'],['ids'=>'2147483648']] as $bad) {
        announcements_check(is_wp_error(wuai_sluyu_announcements_save(array_merge($save,$bad))),'Malformed/unauthenticated configuration rejected.');
        announcements_check(wuai_sluyu_announcements_settings()===$settings,'Failed configuration leaves prior settings intact.');
    }
    foreach (['private','draft','password','topic','ticket'] as $name) {
        announcements_check(is_wp_error(wuai_sluyu_announcements_save(array_merge($save,['ids'=>(string)$posts[$name]]))),'Nonpublic or disallowed type cannot be selected: '.$name);
    }
    wp_set_current_user(0); $_SERVER['REQUEST_METHOD']='GET';
    $public=wuai_sluyu_announcements_list([],2);
    announcements_check(!is_wp_error($public) && $public['total']===3 && $public['pages']===2 && array_column($public['items'],'id')===[$posts['news'],$posts['article']],'Anonymous page is selected, public, ordered and paged.');
    $second=wuai_sluyu_announcements_list(['pg'=>'2'],2);
    announcements_check(array_column($second['items'],'id')===[$posts['page']],'Second page has only remaining public announcement.');
    $search=wuai_sluyu_announcements_list(['announcement_search'=>$prefix.' news']);
    announcements_check($search['total']===1 && $search['items'][0]['id']===$posts['news'],'Search matches only selected public title.');
    announcements_check(wuai_sluyu_announcements_list(['announcement_search'=>'Public announcement text'])['total']===0,'Body/hidden text is not used in announcement search.');
    announcements_check(wuai_sluyu_announcements_list(['announcement_search'=>$prefix.'%'])['total']===0,'SQL wildcards are literal.');
    foreach ([['announcement_search'=>['bad']],['pg'=>['1']],['pg'=>'0'],['pg'=>'100001'],['announcement_search'=>str_repeat('x',81)]] as $bad) { announcements_check(is_wp_error(wuai_sluyu_announcements_list($bad)),'Invalid anonymous query rejected.'); }
    $before=get_option(wuai_sluyu_announcements_option()); $_GET=[]; ob_start(); wuai_sluyu_view_announcements(); $html=ob_get_clean();
    $widget=announcements_render_widget(['count'=>20]);
    foreach (['private','draft','password','topic','ticket','unlisted'] as $name) { announcements_check(strpos($html.$widget,$prefix.' '.$name)===false,'Nonpublic/unselected title is absent: '.$name); }
    announcements_check($shortcode_calls===0 && strpos($html,'DO-NOT-EXECUTE')===false,'List rendering never executes embedded shortcodes.');
    announcements_check(get_option(wuai_sluyu_announcements_option())===$before,'GET render has no settings writes.');
    $widget_object=new Wuai_Sluyu_Announcements_Widget(); $safe=$widget_object->update(['title'=>['bad'],'count'=>['bad']],[]);
    announcements_check($safe===['title'=>'','count'=>5],'Malformed widget input uses safe defaults.');
    wp_set_current_user($users['admin']);
    get_post($posts['article']);
    $wpdb->update($wpdb->posts,['post_status'=>'private'],['ID'=>$posts['article']]);
    $fresh=wuai_sluyu_announcements_list([]);
    announcements_check(!in_array($posts['article'],array_column($fresh['items'],'id'),true),'Fresh SQL rejects stale cached post after direct private transition.');
    clean_post_cache($posts['article']);
    $wpdb->update($wpdb->posts,['post_password'=>'now-locked'],['ID'=>$posts['page']]);
    $fresh=wuai_sluyu_announcements_list([]);
    announcements_check(array_column($fresh['items'],'id')===[$posts['news']],'Password transition is hidden for administrator and anonymous views alike.');
    clean_post_cache($posts['page']);
    $_SERVER['REQUEST_METHOD']='POST'; $disabled=$save; $disabled['_wpnonce']=wp_create_nonce('wuai_sluyu_announcements'); $disabled['enabled']='0';
    announcements_check(!is_wp_error(wuai_sluyu_announcements_save($disabled)) && !wuai_sluyu_announcements_settings()['enabled'],'Administrator can disable a stale list after original public posts become private or protected.');
    $_SERVER['REQUEST_METHOD']='GET';
    update_option(wuai_sluyu_announcements_option(),['enabled'=>1,'ids'=>[$posts['private'],$posts['password'],$posts['topic'],$posts['news']]],false);
    announcements_check(array_column(wuai_sluyu_announcements_list([])['items'],'id')===[$posts['news']],'Corrupt stale allowlist cannot bypass per-read publication checks.');
    update_option(wuai_sluyu_announcements_option(),['enabled'=>1,'ids'=>['not-an-id']],false);
    announcements_check(is_wp_error(wuai_sluyu_announcements_list([])),'Malformed stored configuration fails closed.');
    update_option(wuai_sluyu_announcements_option(),['enabled'=>0,'ids'=>[$posts['news']]],false);
    announcements_check(wuai_sluyu_announcements_list([])['total']===0,'Disabled configuration never emits a fake notice.');
    echo "PASS: admin-only POST/nonce configuration, read-only public listing/widget, allowlisted types, fresh private/password suppression, search/pagination, no shortcode execution, malformed input/config fail-closed. Mail blocked.\n";
} finally {
    $_POST=[]; $_GET=[]; wp_set_current_user($users['admin']??0);
    if ($old_settings===null) { delete_option(wuai_sluyu_announcements_option()); } else { update_option(wuai_sluyu_announcements_option(),$old_settings,false); }
    foreach ($posts as $id) { wp_delete_post($id,true); }
    require_once ABSPATH.'wp-admin/includes/user.php'; foreach ($users as $id) { wp_delete_user($id); }
    remove_shortcode('qa_announcement_secret'); remove_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
    $_POST=$old_post; $_GET=$old_get; $_SERVER['REQUEST_URI']=$old_uri;
    if ($old_method===null) { unset($_SERVER['REQUEST_METHOD']); } else { $_SERVER['REQUEST_METHOD']=$old_method; }
    wp_set_current_user($old_user);
}
