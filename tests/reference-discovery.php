<?php
// Run serially only after the root release task schedules this disposable fixture.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); $resolved = $root ? realpath($root) : false;
$expected = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
if (!$resolved || !$expected || strcasecmp($resolved,$expected) !== 0 || !is_file($resolved . '/wp-load.php')) { throw new RuntimeException('Dedicated disposable WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/';
require $resolved . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_discovery_tags')) { throw new RuntimeException('Integrated local discovery candidate required.'); }

function discovery_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function discovery_ids($config) {
    discovery_check(!is_wp_error($config), 'Valid channel configuration.');
    $query = new WP_Query($config['args']);
    return array_map('intval', wp_list_pluck($query->posts,'ID'));
}

$old_user = get_current_user_id(); $old_get = $_GET; $old_post = $_POST; $old_uri=$_SERVER['REQUEST_URI'];
$users = []; $posts = []; $tags = []; $categories = []; $comments = [];
$prefix = 'qa_discovery_' . strtolower(wp_generate_password(8,false,false));
$old_tag_types = get_taxonomy('post_tag')->object_type;
$mail_block = function () { return true; }; add_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
try {
    foreach (['admin'=>'administrator','reader'=>'subscriber','other'=>'subscriber'] as $name=>$role) {
        $id = wp_create_user($prefix . '_' . $name, wp_generate_password(40,true,true), $prefix . '_' . $name . '@example.test');
        discovery_check(!is_wp_error($id), 'Create isolated discovery user.');
        $users[$name] = (int)$id; (new WP_User($id))->set_role($role);
    }
    wp_set_current_user($users['admin']); $_POST = []; $_GET = [];
    register_taxonomy_for_object_type('post_tag','sluyu_topic');
    foreach (['public','mixed','private','password','topic','pending','empty'] as $name) {
        $term = wp_insert_term($prefix . '_' . $name,'post_tag');
        discovery_check(!is_wp_error($term), 'Create discovery tag.'); $tags[$name] = (int)$term['term_id'];
    }
    foreach (['one','two'] as $name) {
        $term = wp_insert_term($prefix . '_' . $name,'category');
        discovery_check(!is_wp_error($term), 'Create discovery category.'); $categories[$name] = (int)$term['term_id'];
    }
    $make = function ($name,$status='publish',$type='post',$password='',$category='one') use (&$posts,$prefix,$users,$categories) {
        $id = wp_insert_post(['post_type'=>$type,'post_status'=>$status,'post_author'=>$users['admin'],'post_title'=>$prefix . ' ' . $name,'post_content'=>'Public searchable needle content.','post_password'=>$password,'comment_status'=>'open'],true);
        discovery_check(!is_wp_error($id) && $id, 'Create isolated discovery post.'); $posts[$name] = (int)$id;
        if ($type === 'post') { wp_set_post_categories($id,[$categories[$category]],false); }
        return (int)$id;
    };
    $make('a'); $make('b'); $make('private','private'); $make('password','publish','post','locked'); $make('pending','pending'); $make('topic','publish','sluyu_topic'); $make('elsewhere','publish','post','','two');
    wp_set_object_terms($posts['a'],[$tags['public'],$tags['mixed']],'post_tag');
    wp_set_object_terms($posts['b'],[$tags['public']],'post_tag');
    wp_set_object_terms($posts['private'],[$tags['private'],$tags['mixed']],'post_tag');
    wp_set_object_terms($posts['password'],[$tags['password'],$tags['mixed']],'post_tag');
    wp_set_object_terms($posts['pending'],[$tags['pending']],'post_tag');
    wp_set_object_terms($posts['topic'],[$tags['topic'],$tags['mixed']],'post_tag');
    $tag_taxonomy_ids = [];
    foreach ($tags as $id) { $tag_taxonomy_ids[] = (int)get_term($id,'post_tag')->term_taxonomy_id; }
    wp_update_term_count_now($tag_taxonomy_ids,'post_tag');
    wp_set_current_user(0);
    $data = wuai_sluyu_discovery_tags(['tag_search'=>$prefix]);
    discovery_check(!is_wp_error($data) && $data['total']===2, 'Only tags on ordinary public password-free posts are counted.');
    $counts = array_column($data['items'],'count','id');
    discovery_check($counts[$tags['public']]===2 && $counts[$tags['mixed']]===1, 'Counts exclude private, password-protected and topic relationships.');
    foreach ($data['items'] as $item) { discovery_check($item['url']===get_tag_link($item['id']), 'Directory uses actual WordPress tag archives.'); }
    $one = wuai_sluyu_discovery_tags(['tag_search'=>$prefix,'pg'=>'1'],1);
    $two = wuai_sluyu_discovery_tags(['tag_search'=>$prefix,'pg'=>'2'],1);
    discovery_check($one['total']===2 && $one['pages']===2 && count($one['items'])===1 && count($two['items'])===1 && $one['items'][0]['id']!==$two['items'][0]['id'], 'Tag pagination is complete without duplicates.');
    $missing = wuai_sluyu_discovery_tags(['tag_search'=>$prefix . '_private']);
    discovery_check($missing['total']===0 && !$missing['items'], 'Private-only tag name cannot be discovered through search.');
    $wildcard = wuai_sluyu_discovery_tags(['tag_search'=>$prefix . '%']);
    discovery_check($wildcard['total']===0, 'Search wildcards are literal, not SQL wildcards.');
    foreach (['tag_search'=>['bad'],'pg'=>['1']] as $key=>$value) { discovery_check(is_wp_error(wuai_sluyu_discovery_tags([$key=>$value])), 'Array tag input is rejected.'); }
    discovery_check(is_wp_error(wuai_sluyu_discovery_tags(['tag_search'=>str_repeat('a',81)])), 'Oversized tag search rejected.');
    $_GET = ['tag_search'=>$prefix]; ob_start(); wuai_sluyu_view_tags(); $html = ob_get_clean();
    discovery_check(strpos($html,$prefix . '_private')===false && strpos($html,$prefix . '_topic')===false && strpos($html,'tag_search')!==false, 'Tag form does not render private/topic-only names.');

    $category = get_term($categories['one'],'category');
    $base = wuai_sluyu_discovery_channel($category,[]);
    discovery_check(!isset($base['options']['views']) && !isset($base['options']['likes']) && !isset($base['options']['comments']), 'Metric sorts are absent when no public eligible data exists.');
    $modified = wuai_sluyu_discovery_channel($category,['sort'=>'modified']);
    discovery_check(isset($modified['args']['orderby']['modified']), 'Existing recently-updated sort remains available.');
    $fallback = wuai_sluyu_discovery_channel($category,['post_order'=>'views']);
    discovery_check($fallback['order']==='new' && count($fallback['notices'])===1, 'No fake view ranking: unsupported legacy selection explains latest fallback.');
    foreach (['archiveSearch','post_order','sort','seed','pg'] as $key) { discovery_check(is_wp_error(wuai_sluyu_discovery_channel($category,[$key=>['bad']])), 'Array channel parameter rejected: ' . $key); }
    foreach ([['pg'=>'0'],['pg'=>'-1'],['pg'=>'100001'],['seed'=>'1 OR 1=1'],['post_order'=>'invalid'],['sort'=>'new','post_order'=>'random']] as $input) { discovery_check(is_wp_error(wuai_sluyu_discovery_channel($category,$input)), 'Invalid/conflicting query is rejected.'); }
    $search = wuai_sluyu_discovery_channel($category,['archiveSearch'=>'needle','post_order'=>'new']);
    $ids = discovery_ids($search); sort($ids);
    $expected_ids = [$posts['a'],$posts['b']]; sort($expected_ids);
    discovery_check($ids===$expected_ids, 'Archive search stays in category and excludes private/password/pending/topic posts.');
    discovery_check($search['args']['s']==='needle' && $search['pagination']['archiveSearch']==='needle' && $search['pagination']['sort']===false, 'Search and canonical sort survive pagination without old conflicting sort.');

    update_user_meta($users['reader'],'_wuai_sluyu_like_' . $posts['a'],'1');
    add_user_meta($users['reader'],'_wuai_sluyu_like_' . $posts['a'],'1');
    update_user_meta($users['reader'],'_wuai_sluyu_like_' . $posts['b'],'1');
    update_user_meta($users['other'],'_wuai_sluyu_like_' . $posts['b'],'1');
    global $wpdb;
    $orphan = (int)$wpdb->get_var("SELECT MAX(ID) FROM $wpdb->users") + 100000;
    $wpdb->insert($wpdb->usermeta,['user_id'=>$orphan,'meta_key'=>'_wuai_sluyu_like_' . $posts['a'],'meta_value'=>'1']);
    $likes = wuai_sluyu_discovery_channel($category,['post_order'=>'like']);
    discovery_check($likes['order']==='likes' && discovery_ids($likes)===[$posts['b'],$posts['a']], 'Likes count distinct existing users, not duplicate metadata or deleted accounts.');
    update_post_meta($posts['a'],'_wuai_sluyu_views','3'); update_post_meta($posts['b'],'_wuai_sluyu_views','12');
    update_post_meta($posts['private'],'_wuai_sluyu_views','999');
    $views = wuai_sluyu_discovery_channel($category,['post_order'=>'views']);
    discovery_check(isset($views['options']['views']) && discovery_ids($views)===[$posts['b'],$posts['a']], 'Existing real view counters order only eligible posts numerically.');
    add_post_meta($posts['b'],'_wuai_sluyu_views','12');
    discovery_check(discovery_ids(wuai_sluyu_discovery_channel($category,['post_order'=>'views']))===[$posts['a'],$posts['b']], 'Duplicate view metadata is not inflated into a count.');
    delete_post_meta($posts['a'],'_wuai_sluyu_views'); delete_post_meta($posts['b'],'_wuai_sluyu_views');
    update_post_meta($posts['a'],'_wuai_sluyu_views','9999999999999999999999999'); update_post_meta($posts['b'],'_wuai_sluyu_views','-2');
    discovery_check(!isset(wuai_sluyu_discovery_channel($category,[])['options']['views']), 'Malformed/overflow/negative view values do not enable a fake metric.');
    foreach ([[$posts['a'],'1'],[$posts['b'],'1'],[$posts['b'],'1'],[$posts['a'],'0'],[$posts['a'],'spam']] as $value) {
        $id = wp_insert_comment(['comment_post_ID'=>$value[0],'comment_content'=>'Discovery fixture comment','comment_approved'=>$value[1],'comment_type'=>'comment','user_id'=>$users['reader']]);
        discovery_check((bool)$id,'Create isolated comment.'); $comments[]=(int)$id;
    }
    discovery_check(discovery_ids(wuai_sluyu_discovery_channel($category,['sort'=>'popular']))===[$posts['b'],$posts['a']], 'Legacy modern popular maps to approved real comments only.');
    $all_random = discovery_ids(wuai_sluyu_discovery_channel($category,['post_order'=>'random','seed'=>'12345'],20));
    $first_random = discovery_ids(wuai_sluyu_discovery_channel($category,['post_order'=>'random','seed'=>'12345','pg'=>'1'],1));
    $second_random = discovery_ids(wuai_sluyu_discovery_channel($category,['post_order'=>'random','seed'=>'12345','pg'=>'2'],1));
    discovery_check(array_merge($first_random,$second_random)===$all_random && count(array_unique($all_random))===2, 'Seeded random order is stable and complete across pages.');
    $_GET=[]; ob_start(); wuai_sluyu_discovery_channel_form('pcgame',$search); $form=ob_get_clean();
    discovery_check(strpos($form,'name="archiveSearch"')!==false && strpos($form,'name="post_order"')!==false && strpos($form,'name="seed"')!==false, 'Channel form uses reference-compatible names and preserves random seed.');
    $request_uri=$_SERVER['REQUEST_URI'];
    $_SERVER['REQUEST_URI']='/?sluyu_page=pcgame&sort=new&archiveSearch=stale&unrelated=must-not-carry';
    $before_link=get_pagenum_link(1,false);
    ob_start(); wuai_sluyu_discovery_pagination(3,'pcgame',1,['archiveSearch'=>'needle','post_order'=>'random','seed'=>12345,'sort'=>'new','unrelated'=>'must-not-carry']); $pagination=ob_get_clean();
    discovery_check(get_pagenum_link(1,false)===$before_link,'Discovery pagination removes its scoped core URL filter.');
    preg_match_all('/href="([^"]+)"/',$pagination,$urls);
    discovery_check(count($urls[1])>0,'Discovery pagination renders usable links.');
    foreach ($urls[1] as $url) {
        $query=[]; parse_str((string)wp_parse_url(html_entity_decode($url,ENT_QUOTES,'UTF-8'),PHP_URL_QUERY),$query);
        discovery_check(($query['archiveSearch']??'')==='needle' && ($query['post_order']??'')==='random' && ($query['seed']??'')==='12345' && !isset($query['sort']) && !isset($query['unrelated']),'Pagination preserves only approved canonical state.');
    }
    $_SERVER['REQUEST_URI']=$request_uri;
    echo "PASS: public-only tag names/counts/search/pagination; strict input; scoped archive search; real comments/likes/views without duplicate or orphan inflation; stable random pagination. Mail blocked.\n";
} finally {
    $_GET=[]; $_POST=[]; wp_set_current_user($users['admin']??0);
    if (isset($orphan)) { $wpdb->delete($wpdb->usermeta,['user_id'=>$orphan]); }
    foreach ($comments as $id) { wp_delete_comment($id,true); }
    foreach ($posts as $id) { wp_delete_post($id,true); }
    foreach ($tags as $id) { wp_delete_term($id,'post_tag'); }
    foreach ($categories as $id) { wp_delete_term($id,'category'); }
    get_taxonomy('post_tag')->object_type=$old_tag_types;
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $id) { wp_delete_user($id); }
    remove_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
    $_GET=$old_get; $_POST=$old_post; $_SERVER['REQUEST_URI']=$old_uri; wp_set_current_user($old_user);
}
