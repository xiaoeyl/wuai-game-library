<?php
// Isolated CLI fixture. The optional MU file only blocks mail; no HTTP helpers.
if (PHP_SAPI!=='cli') { exit(1); }
$root=getenv('WP_QA_ROOT'); $resolved=$root?realpath($root):false; $expected=realpath(dirname(__DIR__).'/dist/checks-v1.1.0/site');
if (!$resolved || !$expected || strcasecmp($resolved,$expected)!==0 || !is_file($resolved.'/wp-load.php')) { throw new RuntimeException('Dedicated local WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST']='127.0.0.1:8771'; $_SERVER['REMOTE_ADDR']='127.0.0.1'; $_SERVER['REQUEST_URI']='/'; $_SERVER['REQUEST_METHOD']='GET';
require $resolved.'/wp-load.php';
if (home_url()!=='http://127.0.0.1:8771' || strcasecmp((string)realpath(ABSPATH),$resolved)!==0 || !defined('DB_ENGINE') || DB_ENGINE!=='sqlite' || !is_a($wpdb,'WP_SQLite_DB') || !class_exists('Wuai_Sluyu_Announcements_Widget') || !isset(wuai_sluyu_portal_pages()['announcement'])) { throw new RuntimeException('Integrated announcements in exact disposable SQLite site required.'); }
function announcement_browser_check($condition,$message) { if (!$condition) { throw new RuntimeException($message); } }
function announcement_browser_inside($path,$parent) {
    $path=str_replace('\\','/',rtrim($path,'/\\')); $parent=str_replace('\\','/',rtrim($parent,'/\\'));
    return strcasecmp($path,$parent)===0 || strncasecmp($path,$parent.'/',strlen($parent)+1)===0;
}
$output=realpath(getenv('WP_QA_OUTPUT')?:dirname($resolved)); $dist=realpath(dirname(__DIR__).'/dist');
if (!$output || !is_dir($output) || !$dist || !announcement_browser_inside($output,$dist) || announcement_browser_inside($output,$resolved)) { throw new RuntimeException('Existing ignored output directory outside the HTTP root required.'); }
$path=$output.'/announcements-browser-fixture.json'; $marker='WUAI_ANNOUNCEMENTS_BROWSER_V1';
$mu_dir=WP_CONTENT_DIR.'/mu-plugins'; $mu_file=$mu_dir.'/wuai-announcements-browser-qa.php';
announcement_browser_check(realpath(WP_CONTENT_DIR) && announcement_browser_inside(realpath(WP_CONTENT_DIR),$resolved),'Local content directory required.');
announcement_browser_check(!is_link($path) && !is_link($mu_file),'Refuse symbolic fixture manifest or mail guard.');
announcement_browser_check(!is_dir($mu_dir) || announcement_browser_inside(realpath($mu_dir),$resolved),'Existing mail guard directory must remain inside disposable site.');
$old_user=get_current_user_id(); $old_get=$_GET; $old_post=$_POST;
$mail_block=function () { return true; }; add_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
$fixture=null;
try {
    $_POST=[]; $_GET=[];
    if (in_array('--cleanup',$argv,true)) {
        announcement_browser_check(is_file($path),'Missing announcement fixture manifest.'); $fixture=json_decode(file_get_contents($path),true);
        announcement_browser_check(is_array($fixture) && ($fixture['marker']??'')===$marker && ($fixture['root']??'')===$resolved && ($fixture['base']??'')===home_url() && preg_match('/^[a-f0-9]{16}$/D',$fixture['run']??''),'Refuse unrelated announcement fixture.');
        if (($fixture['state']??'')==='cleaned') { echo "PASS: announcement browser fixture already cleaned; no changes made.\n"; return; }
        announcement_browser_check(isset($fixture['posts'],$fixture['users']) && is_array($fixture['posts']) && is_array($fixture['users']) && array_key_exists('original_announcements',$fixture) && array_key_exists('original_widget_option',$fixture) && array_key_exists('original_sidebars_option',$fixture),'Incomplete fixture ownership data; manual cleanup required.');
        $run=$fixture['run'];
        foreach ($fixture['posts'] as $post) { if (get_post($post['id'])) { announcement_browser_check(get_post_meta($post['id'],'_wuai_announcement_browser_run',true)===$run,'Refuse unrelated post cleanup.'); } }
        foreach ($fixture['users'] as $user) {
            if (!get_userdata($user['id'])) { continue; }
            announcement_browser_check(get_user_meta($user['id'],'_wuai_announcement_browser_run',true)===$run,'Refuse unrelated user cleanup.');
            $owned=$wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_author=%d",$user['id']));
            announcement_browser_check(!$wpdb->last_error && !array_diff(array_map('intval',$owned),array_map('intval',array_column($fixture['posts'],'id'))),'Fixture user owns unrecorded content; manual cleanup required.');
        }
        $current_option=get_option(wuai_sluyu_announcements_option(),null); $settings=wuai_sluyu_announcements_settings();
        if ($current_option!==$fixture['original_announcements']) {
            announcement_browser_check(!empty($fixture['announcements_touched']) && !is_wp_error($settings) && !array_diff($settings['ids'],array_map('intval',array_column($fixture['posts'],'id'))),'Unrelated or malformed announcement configuration changed during fixture; do not overwrite it.');
        }
        if (isset($fixture['widget'])) {
            $widgets=get_option('widget_wuai_sluyu_announcements',[]); $slot=$fixture['widget']['slot'];
            announcement_browser_check(is_array($widgets) && is_array(get_option('sidebars_widgets',[])),'Widget/sidebar option format changed; manual cleanup required.');
            if (isset($widgets[$slot])) { announcement_browser_check($widgets[$slot]===$fixture['widget']['instance'],'Fixture widget was changed externally; manual cleanup required.'); }
        }
        if (is_file($mu_file)) { announcement_browser_check(isset($fixture['mu_sha256']) && hash_file('sha256',$mu_file)===$fixture['mu_sha256'],'Refuse to remove unrelated mail guard.'); }
        if (!empty($fixture['announcements_touched'])) {
            if ($fixture['original_announcements']===null) { delete_option(wuai_sluyu_announcements_option()); }
            else { update_option(wuai_sluyu_announcements_option(),$fixture['original_announcements'],false); }
        }
        if (isset($fixture['widget'])) {
            $bars=get_option('sidebars_widgets',[]);
            foreach ($bars as $sidebar=>$items) { if (is_array($items) && in_array($fixture['widget']['id'],$items,true)) { $bars[$sidebar]=array_values(array_filter($items,function ($id) use ($fixture) { return $id!==$fixture['widget']['id']; })); } }
            $original_bars=$fixture['original_sidebars_option']??[]; $expected_bars=$original_bars;
            if (!isset($expected_bars['sluyu-footer'])) { $expected_bars['sluyu-footer']=[]; }
            if ($bars===$expected_bars || $bars===$original_bars) {
                if ($fixture['original_sidebars_option']===null) { delete_option('sidebars_widgets'); }
                else { update_option('sidebars_widgets',$fixture['original_sidebars_option']); }
            } else { update_option('sidebars_widgets',$bars); }
            $widgets=get_option('widget_wuai_sluyu_announcements',[]); unset($widgets[$fixture['widget']['slot']]);
            $original_widgets=$fixture['original_widget_option']??[]; $expected_widgets=$original_widgets; $expected_widgets['_multiwidget']=1;
            if ($widgets===$expected_widgets || $widgets===$original_widgets) {
                if ($fixture['original_widget_option']===null) { delete_option('widget_wuai_sluyu_announcements'); }
                else { update_option('widget_wuai_sluyu_announcements',$fixture['original_widget_option']); }
            } else { update_option('widget_wuai_sluyu_announcements',$widgets); }
        }
        wp_set_current_user($fixture['users']['admin']['id']??0);
        foreach ($fixture['posts'] as $post) { if (get_post($post['id'])) { announcement_browser_check((bool)wp_delete_post($post['id'],true),'Delete owned fixture post.'); } }
        wp_set_current_user(0);
        require_once ABSPATH.'wp-admin/includes/user.php';
        foreach ($fixture['users'] as $user) { if (get_userdata($user['id'])) { announcement_browser_check(wp_delete_user($user['id']),'Delete owned fixture user.'); } }
        if (is_file($mu_file)) { announcement_browser_check(unlink($mu_file),'Remove exact fixture mail guard.'); }
        $fixture['state']='cleaned'; announcement_browser_check(file_put_contents($path,wp_json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))!==false,'Persist cleaned fixture state.');
        echo "PASS: announcement settings restored, only own widget removed, unrelated widgets preserved, marked content/users and mail guard cleaned.\n"; return;
    }
    if (is_file($path)) { $previous=json_decode(file_get_contents($path),true); announcement_browser_check(($previous['marker']??'')===$marker && ($previous['state']??'')==='cleaned','Clean previous announcement fixture before reseeding.'); }
    announcement_browser_check(!file_exists($mu_file),'Refuse an existing announcement mail guard; use its matching fixture cleanup first.');
    $run=bin2hex(random_bytes(8));
    $fixture=['marker'=>$marker,'run'=>$run,'base'=>home_url(),'root'=>$resolved,'state'=>'preparing','posts'=>[],'users'=>[],'announcements_touched'=>false,
        'original_announcements'=>get_option(wuai_sluyu_announcements_option(),null),'original_widget_option'=>get_option('widget_wuai_sluyu_announcements',null),'original_sidebars_option'=>get_option('sidebars_widgets',null)];
    announcement_browser_check(($fixture['original_widget_option']===null || is_array($fixture['original_widget_option'])) && ($fixture['original_sidebars_option']===null || is_array($fixture['original_sidebars_option'])),'Existing widget/sidebar options must be arrays.');
    $persist=function () use (&$fixture,$path) {
        $json=wp_json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
        announcement_browser_check(is_string($json) && strpos($json,'qa-local-only')===false,'Fixture manifest must not contain the local testing password.');
        announcement_browser_check(file_put_contents($path,$json)!==false,'Persist fixture manifest.');
    }; $persist();
    if (!is_dir($mu_dir)) { announcement_browser_check(wp_mkdir_p($mu_dir),'Create local MU directory.'); }
    announcement_browser_check(announcement_browser_inside(realpath($mu_dir),$resolved),'Mail guard directory must remain inside disposable site.');
    $plugin="<?php\n// WUAI_ANNOUNCEMENTS_BROWSER_V1: local mail block only; never deploy.\n";
    $plugin.="if (!defined('ABSPATH') || strcasecmp((string)realpath(ABSPATH),".var_export($resolved,true).")!==0 || !defined('DB_ENGINE') || DB_ENGINE!=='sqlite') return;\n";
    $plugin.="if (PHP_SAPI!=='cli' && (!in_array(\$_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true) || (\$_SERVER['HTTP_HOST']??'')!=='127.0.0.1:8771')) return;\n";
    $plugin.="add_filter('pre_wp_mail',function () { return true; },PHP_INT_MAX);\nadd_action('init',function () { if (home_url()==='http://127.0.0.1:8771' && !headers_sent()) header('X-Wuai-Announcements-QA-Mail-Blocked: 1'); });\n";
    $fixture['mu_sha256']=hash('sha256',$plugin); $persist();
    $handle=fopen($mu_file,'xb'); announcement_browser_check($handle!==false,'Exclusively create isolated mail-only guard.');
    try { announcement_browser_check(fwrite($handle,$plugin)===strlen($plugin),'Write complete mail-only guard.'); } finally { fclose($handle); }
    foreach (['admin'=>'administrator','reader'=>'subscriber'] as $name=>$role) {
        $login='qa_announce_'.$run.'_'.$name; $id=wp_create_user($login,'qa-local-only',$login.'@example.test');
        announcement_browser_check(!is_wp_error($id) && $id,'Create isolated announcement browser identity.');
        update_user_meta($id,'_wuai_announcement_browser_run',$run); $fixture['users'][$name]=['id'=>(int)$id,'login'=>$login]; $persist(); (new WP_User($id))->set_role($role);
    }
    wp_set_current_user($fixture['users']['admin']['id']);
    $prefix='QAAnnounce'.$run;
    $make=function ($key,$type='post',$status='publish',$password='') use (&$fixture,$run,$prefix,$persist) {
        $id=wp_insert_post(['post_type'=>$type,'post_status'=>$status,'post_author'=>$fixture['users']['admin']['id'],'post_title'=>$prefix.' '.$key,
            'post_content'=>'<p>Public notice for the isolated announcements browser regression.</p>','post_password'=>$password,'comment_status'=>'closed','ping_status'=>'closed','meta_input'=>['_wuai_announcement_browser_run'=>$run]],true);
        announcement_browser_check(!is_wp_error($id) && $id,'Create announcement browser post.');
        $entry=['id'=>(int)$id,'title'=>$prefix.' '.$key,'url'=>get_permalink($id)]; $fixture['posts'][$key]=$entry; $persist(); return $entry;
    };
    $public=[];
    for ($i=1;$i<=15;$i++) { $public[]=$make('Public '.str_pad((string)$i,2,'0',STR_PAD_LEFT),$i%3===0?'sluyu_news':($i%3===1?'post':'page')); }
    $private=$make('PrivateHidden','post','private'); $draft=$make('DraftHidden','post','draft');
    $password=$make('PasswordHidden','post','publish',wp_generate_password(48,true,true)); $topic=$make('TopicHidden','sluyu_topic');
    $unlisted=$make('UnlistedPublic'); $new_private=$make('NewPrivateRejected','post','private');
    $ids=array_merge(array_column($public,'id'),[$private['id'],$draft['id'],$password['id'],$topic['id']]);
    $initial=['enabled'=>0,'ids'=>$ids]; $fixture['announcements_touched']=true; $persist(); update_option(wuai_sluyu_announcements_option(),$initial,false);
    announcement_browser_check(get_option(wuai_sluyu_announcements_option())===$initial,'Persist initial disabled announcements.');
    $widgets=get_option('widget_wuai_sluyu_announcements',[]); announcement_browser_check(is_array($widgets),'Widget option format is valid.');
    $bars=get_option('sidebars_widgets',[]); announcement_browser_check(is_array($bars) && (!isset($bars['sluyu-footer']) || is_array($bars['sluyu-footer'])),'Footer sidebar format is valid.');
    $used=[]; foreach ($bars as $items) { if (is_array($items)) { $used=array_merge($used,$items); } }
    $slot=901; while (isset($widgets[$slot]) || in_array('wuai_sluyu_announcements-'.$slot,$used,true)) { $slot++; }
    $instance=['title'=>'QA Announcement Widget '.$run,'count'=>3]; $widget_id='wuai_sluyu_announcements-'.$slot;
    $fixture['widget']=['slot'=>$slot,'id'=>$widget_id,'instance'=>$instance]; $persist();
    $widgets[$slot]=$instance; $widgets['_multiwidget']=1; update_option('widget_wuai_sluyu_announcements',$widgets);
    if (!isset($bars['sluyu-footer'])) { $bars['sluyu-footer']=[]; }
    $bars['sluyu-footer'][]=$widget_id; update_option('sidebars_widgets',$bars);
    announcement_browser_check(get_option('widget_wuai_sluyu_announcements')===$widgets && get_option('sidebars_widgets')===$bars,'Append real fixture widget without replacing existing sidebars.');
    $fixture=array_merge($fixture,['state'=>'ready','created_at'=>gmdate('c'),'page_url'=>wuai_sluyu_announcements_url(),
        'admin_url'=>admin_url('admin.php?page=wuai-sluyu-announcements'),'post_url'=>admin_url('admin-post.php'),
        'initial_ids'=>$ids,'public_posts'=>$public,'expected_order'=>array_reverse(array_column($public,'id')),
        'selection_order'=>array_reverse($ids),'search'=>$prefix,'private_titles'=>[$private['title'],$draft['title'],$password['title'],$topic['title'],$new_private['title']],
        'unlisted_title'=>$unlisted['title'],'rejected_id'=>$new_private['id'],'credentials_in_manifest'=>false]); $persist();
    echo "PASS: announcement browser fixture ready, disabled selected notices, isolated admin/reader, one appended footer widget and mail-only guard. Use --cleanup after suite.\n";
} catch (Throwable $error) {
    if (is_array($fixture) && !in_array('--cleanup',$argv,true)) { $fixture['state']='incomplete'; file_put_contents($path,wp_json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }
    throw $error;
} finally { remove_filter('pre_wp_mail',$mail_block,PHP_INT_MAX); $_GET=$old_get; $_POST=$old_post; wp_set_current_user($old_user); }
