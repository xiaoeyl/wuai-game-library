<?php
// CLI-only anonymous-browser fixture; no HTTP mutation endpoints or passwords.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); $resolved = $root ? realpath($root) : false;
$expected = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
if (!$resolved || !$expected || strcasecmp($resolved,$expected)!==0 || !is_file($resolved . '/wp-load.php')) { throw new RuntimeException('Dedicated SQLite WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST']='127.0.0.1:8771'; $_SERVER['REMOTE_ADDR']='127.0.0.1'; $_SERVER['REQUEST_URI']='/';
require $resolved . '/wp-load.php';
if (home_url()!=='http://127.0.0.1:8771' || strcasecmp(rtrim((string)realpath(ABSPATH),'/\\'),rtrim($resolved,'/\\'))!==0 || !defined('DB_ENGINE') || DB_ENGINE!=='sqlite' || !is_a($wpdb,'WP_SQLite_DB')) { throw new RuntimeException('Exact disposable SQLite site only.'); }
if (!function_exists('wuai_sluyu_discovery_tags') || !isset(wuai_sluyu_portal_pages()['tags']) || wuai_sluyu_channel_info('webxw/yxzx')[1]!=='游戏资讯') { throw new RuntimeException('Integrate discovery routes, module and yxzx before this fixture.'); }

function discovery_browser_check($condition,$message) { if (!$condition) { throw new RuntimeException($message); } }
function discovery_browser_within($path,$parent) {
    $path=str_replace('\\','/',rtrim($path,'/\\')); $parent=str_replace('\\','/',rtrim($parent,'/\\'));
    return strcasecmp($path,$parent)===0 || strncasecmp($path,$parent.'/',strlen($parent)+1)===0;
}
function discovery_browser_cleanup($fixture) {
    discovery_browser_check(($fixture['marker']??'')==='WUAI_DISCOVERY_BROWSER_V1' && preg_match('/^[a-f0-9]{16}$/D',$fixture['run']??''), 'Refuse unrelated fixture manifest.');
    $run=$fixture['run'];
    // Verify every owned record before deleting any of them.
    foreach ($fixture['owned_posts'] as $id) { if (get_post($id)) { discovery_browser_check(get_post_meta($id,'_wuai_discovery_browser_run',true)===$run,'Refuse unrelated post cleanup.'); } }
    foreach ($fixture['owned_terms'] as $entry) { if (get_term($entry['id'],$entry['taxonomy']) instanceof WP_Term) { discovery_browser_check(get_term_meta($entry['id'],'_wuai_discovery_browser_run',true)===$run,'Refuse unrelated term cleanup.'); } }
    global $wpdb;
    foreach ($fixture['owned_terms'] as $entry) {
        $term=get_term($entry['id'],$entry['taxonomy']); if (!($term instanceof WP_Term)) { continue; }
        $objects=$wpdb->get_col($wpdb->prepare("SELECT object_id FROM $wpdb->term_relationships WHERE term_taxonomy_id=%d",$term->term_taxonomy_id));
        discovery_browser_check(!$wpdb->last_error && !array_diff(array_map('intval',$objects),array_map('intval',$fixture['owned_posts'])),'Refuse to remove a fixture term now used by unrelated content.');
    }
    foreach ($fixture['owned_users'] as $id) {
        if (!get_userdata($id)) { continue; }
        discovery_browser_check(get_user_meta($id,'_wuai_discovery_browser_run',true)===$run,'Refuse unrelated user cleanup.');
        $objects=$wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_author=%d",$id));
        discovery_browser_check(!$wpdb->last_error && !array_diff(array_map('intval',$objects),array_map('intval',$fixture['owned_posts'])),'Refuse to delete a fixture user now owning unrelated content.');
    }
    foreach ($fixture['owned_posts'] as $id) { if (get_post($id)) { discovery_browser_check((bool)wp_delete_post($id,true),'Fixture post deletion failed.'); } }
    foreach ($fixture['owned_terms'] as $entry) {
        if (!(get_term($entry['id'],$entry['taxonomy']) instanceof WP_Term)) { continue; }
        $result=wp_delete_term($entry['id'],$entry['taxonomy']); discovery_browser_check(!is_wp_error($result) && $result!==false,'Fixture term deletion failed.');
    }
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach ($fixture['owned_users'] as $id) { if (get_userdata($id)) { discovery_browser_check(wp_delete_user($id),'Fixture user deletion failed.'); } }
}

$dist=realpath(dirname(__DIR__).'/dist'); $output=realpath(getenv('WP_QA_OUTPUT')?:dirname($resolved));
if (!$dist || !$output || !is_dir($output) || !discovery_browser_within($output,$dist) || discovery_browser_within($output,$resolved)) { throw new RuntimeException('WP_QA_OUTPUT must be an existing ignored dist directory outside the served site.'); }
$path=$output.'/reference-discovery-browser-fixture.json';
$old_user=get_current_user_id(); $old_post=$_POST; $old_get=$_GET;
$mail_block=function () { return true; }; add_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
$fixture=null; $tag_types=get_taxonomy('post_tag')->object_type;
try {
    $_POST=[]; $_GET=[];
    if (in_array('--cleanup',$argv,true)) {
        discovery_browser_check(is_file($path),'No fixture manifest to clean up.'); $fixture=json_decode(file_get_contents($path),true);
        discovery_browser_check(is_array($fixture) && ($fixture['base']??'')===home_url() && ($fixture['root']??'')===$resolved,'Wrong fixture cleanup site.');
        discovery_browser_cleanup($fixture); $fixture['state']='cleaned';
        discovery_browser_check(file_put_contents($path,wp_json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))!==false,'Persist cleanup state.');
        echo "PASS: removed only marker-owned discovery browser records; no site options changed.\n"; return;
    }
    if (is_file($path)) {
        $previous=json_decode(file_get_contents($path),true);
        discovery_browser_check(is_array($previous) && ($previous['marker']??'')==='WUAI_DISCOVERY_BROWSER_V1' && ($previous['state']??'')==='cleaned','Clean up the previous discovery fixture before reseeding.');
    }
    $run=bin2hex(random_bytes(8));
    $fixture=['marker'=>'WUAI_DISCOVERY_BROWSER_V1','run'=>$run,'base'=>home_url(),'root'=>$resolved,'state'=>'preparing','owned_posts'=>[],'owned_terms'=>[],'owned_users'=>[]];
    $persist=function () use (&$fixture,$path) { discovery_browser_check(file_put_contents($path,wp_json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))!==false,'Persist discovery fixture manifest.'); };
    $persist();
    $login='qa_discovery_browser_'.$run;
    $uid=wp_create_user($login,wp_generate_password(48,true,true),$login.'@example.test');
    discovery_browser_check(!is_wp_error($uid) && $uid,'Create isolated fixture owner.');
    update_user_meta($uid,'_wuai_discovery_browser_run',$run); $fixture['owned_users'][]=(int)$uid; $persist();
    (new WP_User($uid))->set_role('administrator'); wp_set_current_user($uid);
    register_taxonomy_for_object_type('post_tag','sluyu_topic');
    $term=function ($name,$taxonomy,$slug='') use (&$fixture,$run,$persist) {
        $created=wp_insert_term($name,$taxonomy,$slug?['slug'=>$slug]:[]);
        discovery_browser_check(!is_wp_error($created),'Create isolated fixture term.'); $id=(int)$created['term_id'];
        update_term_meta($id,'_wuai_discovery_browser_run',$run); $fixture['owned_terms'][]=['id'=>$id,'taxonomy'=>$taxonomy]; $persist(); return $id;
    };
    $category=get_term_by('name','游戏资讯','category');
    if (!$category) { $category=get_term($term('游戏资讯','category','yxzx'),'category'); }
    discovery_browser_check($category instanceof WP_Term,'Reference channel category exists.');
    $other_category=$term('QA Discovery Other '.$run,'category');
    $tag_prefix='QADiscTag-'.$run.'-'; $query='QADiscQuery'.$run;
    $hidden_names=[$tag_prefix.'private-only',$tag_prefix.'password-only',$tag_prefix.'topic-only'];
    $mixed=$term($tag_prefix.'mixed','post_tag');
    $private_tag=$term($hidden_names[0],'post_tag'); $password_tag=$term($hidden_names[1],'post_tag'); $topic_tag=$term($hidden_names[2],'post_tag');
    $expected_tags=[]; $public_posts=[];
    $make=function ($key,$status,$type,$category_id,$term_ids,$password='') use (&$fixture,$uid,$run,$query,$persist) {
        $title=$query.' '.$key;
        $id=wp_insert_post(['post_type'=>$type,'post_status'=>$status,'post_author'=>$uid,'post_title'=>$title,'post_content'=>'Read-only discovery browser fixture. Public searchable content.','post_password'=>$password,'comment_status'=>'closed','ping_status'=>'closed','meta_input'=>['_wuai_discovery_browser_run'=>$run]],true);
        discovery_browser_check(!is_wp_error($id) && $id,'Create fixture post.'); $fixture['owned_posts'][]=(int)$id; $persist();
        if ($type==='post') { discovery_browser_check(!is_wp_error(wp_set_post_categories($id,[$category_id],false)),'Assign fixture category.'); }
        discovery_browser_check(!is_wp_error(wp_set_object_terms($id,$term_ids,'post_tag')),'Assign fixture tags.');
        return ['id'=>(int)$id,'title'=>$title,'url'=>get_permalink($id)];
    };
    for ($index=1;$index<=27;$index++) {
        $name=$tag_prefix.str_pad((string)$index,2,'0',STR_PAD_LEFT);
        $id=$term($name,'post_tag'); $ids=[$id]; if ($index===1) { $ids[]=$mixed; }
        $public_posts[]=$make('Public '.str_pad((string)$index,2,'0',STR_PAD_LEFT),'publish','post',$category->term_id,$ids);
        $expected_tags[]=['id'=>$id,'name'=>$name,'url'=>get_tag_link($id),'count'=>1];
    }
    $expected_tags[]=['id'=>$mixed,'name'=>$tag_prefix.'mixed','url'=>get_tag_link($mixed),'count'=>1];
    $hidden_posts=[];
    $hidden_posts[]=$make('PrivateHidden','private','post',$category->term_id,[$private_tag,$mixed]);
    $hidden_posts[]=$make('PasswordHidden','publish','post',$category->term_id,[$password_tag,$mixed],wp_generate_password(48,true,true));
    $hidden_posts[]=$make('TopicHidden','publish','sluyu_topic',0,[$topic_tag,$mixed]);
    $hidden_posts[]=$make('PendingHidden','pending','post',$category->term_id,[$private_tag]);
    $outside=$make('OutsideCategory','publish','post',$other_category,[]);
    $tag_taxonomy_ids=[];
    foreach ($fixture['owned_terms'] as $entry) { if ($entry['taxonomy']==='post_tag') { $tag_taxonomy_ids[]=(int)get_term($entry['id'],'post_tag')->term_taxonomy_id; } }
    wp_update_term_count_now($tag_taxonomy_ids,'post_tag');
    wp_set_current_user(0);
    $data=wuai_sluyu_discovery_tags(['tag_search'=>$tag_prefix]);
    discovery_browser_check(!is_wp_error($data) && $data['total']===28 && $data['pages']===2,'Fixture has 28 public-only searchable tags.');
    $config=wuai_sluyu_discovery_channel($category,['archiveSearch'=>$query,'post_order'=>'new']);
    discovery_browser_check(!is_wp_error($config),'Fixture channel query supported.');
    $channel_query=new WP_Query($config['args']);
    discovery_browser_check((int)$channel_query->found_posts===27,'Fixture has 27 public in-category posts.');
    $fixture=array_merge($fixture,[
        'state'=>'ready','created_at'=>gmdate('c'),'tags_url'=>wuai_sluyu_portal_url('tags'),
        'channel_url'=>wuai_sluyu_portal_url('webxw/yxzx'),'tag_prefix'=>$tag_prefix,'search'=>$query,
        'expected_tags'=>$expected_tags,'hidden_tag_names'=>$hidden_names,
        'public_posts'=>$public_posts,'hidden_post_titles'=>array_column($hidden_posts,'title'),
        'outside_title'=>$outside['title'],'random_seed'=>'593271','tag_total'=>28,'channel_total'=>27,
        'mail_intercepted'=>true,'credentials_in_manifest'=>false,
    ]);
    $persist();
    echo "PASS: discovery browser fixture ready; 28 public tags and 27 channel posts, private/password/topic-only exclusions, no credentials stored. Run --cleanup after the browser suite.\n";
} catch (Throwable $error) {
    if (is_array($fixture) && isset($fixture['run']) && !in_array('--cleanup',$argv,true)) {
        $fixture['state']='incomplete'; file_put_contents($path,wp_json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    }
    throw $error;
} finally {
    get_taxonomy('post_tag')->object_type=$tag_types;
    remove_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
    $_POST=$old_post; $_GET=$old_get; wp_set_current_user($old_user);
}
