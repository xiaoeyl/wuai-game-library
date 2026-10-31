<?php
// CLI-only fixture for the dedicated local listings browser regression.
if (PHP_SAPI!=='cli') { exit(1); }
$root=getenv('WP_QA_ROOT'); if (!$root || !is_file($root.'/wp-load.php')) { throw new RuntimeException('WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST']='127.0.0.1:8771'; $_SERVER['REQUEST_URI']='/'; require $root.'/wp-load.php';
if (home_url()!=='http://127.0.0.1:8771') { throw new RuntimeException('Local QA only.'); }
if (!function_exists('wuai_sluyu_view_listings')) { throw new RuntimeException('Integrate inc/listings.php first.'); }
function listing_browser_check($condition,$message) { if (!$condition) { throw new RuntimeException($message); } }
$old_user=get_current_user_id(); $old_post=$_POST; $old_get=$_GET;
$users=[]; $posts=[];
try {
    $_POST=[];
    foreach (['owner','writer','other','admin'] as $name) {
        $login='qa_listings_'.$name; $existing=get_user_by('login',$login);
        $id=$existing?$existing->ID:wp_create_user($login,'qa-local-only',$login.'@example.test');
        listing_browser_check(!is_wp_error($id),'Create dedicated listing fixture user.');
        (new WP_User($id))->set_role($name==='admin'?'administrator':'subscriber');
        wp_update_user(['ID'=>$id,'display_name'=>'Listing Browser '.ucfirst($name),'user_pass'=>'qa-local-only']);
        $users[$name]=(int)$id;
        delete_user_meta($id,'_wuai_banned_until');
        foreach (['wuai_listing_rate_','wuai_listing_admin_error_','wuai_rate_'] as $prefix) { delete_transient($prefix.$id); }
        foreach (['listing_submit','listing_resubmit','submit'] as $operation) { delete_transient('wuai_rate_'.$id.'_'.$operation); }
        $owned=get_posts(['post_type'=>'sluyu_listing','post_status'=>['publish','pending','draft','private','future','trash'],'author'=>$id,'posts_per_page'=>-1]);
        foreach ($owned as $post) { wp_delete_post($post->ID,true); }
    }
    wp_set_current_user($users['admin']);
    $future=current_datetime()->modify('+30 days')->setTime(23,59,59)->getTimestamp();
    $create=function($key,$author,$status,$type,$category,$state='open',$deadline=null) use (&$posts,$future) {
        $title='QA Listings Browser '.$key;
        $id=wp_insert_post(['post_type'=>'sluyu_listing','post_author'=>$author,'post_status'=>$status,'post_title'=>$title,'post_content'=>'Dedicated local listing fixture. This is not a sale, payment, escrow or real transaction.','comment_status'=>'closed','ping_status'=>'closed','meta_input'=>['_wuai_listing_type'=>$type,'_wuai_listing_category'=>$category,'_wuai_listing_state'=>$state,'_wuai_listing_deadline'=>$deadline===null?$future:$deadline]],true);
        listing_browser_check(!is_wp_error($id) && $id,'Create listing '.$key);
        $posts[$key]=['id'=>(int)$id,'title'=>$title,'url'=>get_permalink($id),'status'=>$status];
        return (int)$id;
    };
    for ($i=0;$i<14;$i++) { $create('Paged '.str_pad((string)$i,2,'0',STR_PAD_LEFT),$users['owner'],'publish','supply','game'); }
    $create('Approval',$users['owner'],'pending','demand','service');
    $create('Draft',$users['owner'],'draft','supply','other');
    $create('Private Secret',$users['owner'],'private','supply','game');
    $create('Expired',$users['owner'],'publish','demand','material','open',time()-60);
    $create('Resolved',$users['owner'],'publish','supply','service','resolved');
    $create('Foreign Pending Secret',$users['other'],'pending','demand','other');
    $create('Foreign Private Secret',$users['other'],'private','supply','other');
    $create('Other Public',$users['other'],'publish','demand','service');
    wp_set_current_user($users['owner']);
    $mine=new WP_Query(wuai_sluyu_listing_query_args(['scope'=>'mine','listing_state'=>'all'],$users['owner']));
    listing_browser_check((int)$mine->found_posts===18,'Owner mine scope includes published/pending/draft, not private.');
    $public=new WP_Query(wuai_sluyu_listing_query_args(['listing_type'=>'supply','listing_category'=>'game','listing_state'=>'open']));
    $all_public_args=wuai_sluyu_listing_query_args(['listing_type'=>'supply','listing_category'=>'game','listing_state'=>'open']);
    $all_public_args['posts_per_page']=-1;
    $all_public=new WP_Query($all_public_args);
    $manifest=['users'=>$users,'posts'=>$posts,'deadline'=>wp_date('Y-m-d',$future),'mine_total'=>(int)$mine->found_posts,'public_total'=>(int)$public->found_posts,'public_titles'=>wp_list_pluck($all_public->posts,'post_title'),'login_prefix'=>'qa_listings_'];
    $output=getenv('WP_QA_OUTPUT')?:dirname($root);
    if (!is_dir($output)) { wp_mkdir_p($output); }
    file_put_contents(rtrim($output,'/\\').'/listings-fixture.json',wp_json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo "PASS: dedicated listings browser fixture prepared; owner scope 18 items, private records excluded, public filter totals recorded.\n";
} finally { $_POST=$old_post; $_GET=$old_get; wp_set_current_user($old_user); }
