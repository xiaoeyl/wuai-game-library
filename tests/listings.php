<?php
// Disposable local integration fixture; never run on a production WordPress site.
if (PHP_SAPI!=='cli') { exit(1); }
$root=getenv('WP_QA_ROOT'); if (!$root || !is_file($root.'/wp-load.php')) { throw new RuntimeException('WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST']='127.0.0.1:8771'; $_SERVER['REQUEST_URI']='/'; require $root.'/wp-load.php';
if (home_url()!=='http://127.0.0.1:8771') { throw new RuntimeException('Local QA only.'); }
if (!function_exists('wuai_sluyu_view_listings')) { throw new RuntimeException('Integrate inc/listings.php before testing.'); }
function listing_check($condition,$message) { if (!$condition) { throw new RuntimeException($message); } }
function listing_action($uid,$operation,$data=[]) { wp_set_current_user($uid); $_POST=wp_slash($data); return wuai_sluyu_community_operation($operation,$uid); }
function listing_form($uid,$extra=[]) {
    return array_merge(['title'=>'QA listing title','body'=>'A harmless supply and demand fixture for local tests.','listing_type'=>'supply','listing_category'=>'game','listing_deadline'=>current_datetime()->modify('+30 days')->format('Y-m-d')],$extra);
}
function listing_query($filters=[],$uid=0,$page=1) { return new WP_Query(wuai_sluyu_listing_query_args($filters,$uid,$page)); }
function listing_latest($uid) { $posts=get_posts(['post_type'=>'sluyu_listing','post_status'=>['pending','publish','draft'],'author'=>$uid,'posts_per_page'=>1,'orderby'=>'ID','order'=>'DESC']);return $posts?(int)$posts[0]->ID:0; }
$old_user=get_current_user_id(); $old_get=$_GET; $old_post=$_POST;
$prefix='qa_listing_'.strtolower(wp_generate_password(8,false,false)); $users=[]; $posts=[];
try {
    foreach (['owner','other','admin'] as $name) {
        $id=wp_create_user($prefix.'_'.$name,'qa-local-only',$prefix.'_'.$name.'@example.test'); listing_check(!is_wp_error($id),'Dedicated user created.');
        (new WP_User($id))->set_role($name==='admin'?'administrator':'subscriber'); wp_update_user(['ID'=>$id,'display_name'=>'Listing '.ucfirst($name)]); $users[$name]=(int)$id;
    }
    $owner=$users['owner']; $other=$users['other']; $admin=$users['admin'];
    listing_check(post_type_exists('sluyu_listing'),'CPT registered.'); listing_check(isset(wuai_sluyu_account_types()['sluyu_listing']),'Account includes listings.');
    wp_set_current_user($owner); listing_check(!current_user_can('publish_posts') && !current_user_can(get_post_type_object('sluyu_listing')->cap->edit_posts),'Subscriber cannot bypass frontend moderation through native editor.');
    listing_check(is_wp_error(listing_action(0,'listing_submit',listing_form($owner))),'Anonymous submission denied.');
    listing_check(is_wp_error(listing_action($owner,'listing_submit',listing_form($owner,['listing_type'=>['supply']]))),'Nested type denied.');
    listing_check(is_wp_error(listing_action($owner,'listing_submit',listing_form($owner,['listing_deadline'=>'2026-02-30']))),'Overflow date denied.');
    listing_check(is_wp_error(listing_action($owner,'listing_submit',listing_form($owner,['listing_deadline'=>wp_date('Y-m-d',time()-DAY_IN_SECONDS)]))),'Past deadline denied.');
    listing_check(is_wp_error(listing_action($owner,'listing_submit',listing_form($owner,['listing_deadline'=>current_datetime()->modify('+91 days')->format('Y-m-d')]))),'More than 90 days denied.');
    $created=listing_action($owner,'listing_submit',listing_form($owner,['title'=>'QA Pending Supply','body'=>'<script>alert(1)</script> Plain text source must not expose executable markup.','post_status'=>'publish','post_author'=>$other,'listing_state'=>'resolved']));
    listing_check(!is_wp_error($created),'Frontend listing submitted.'); $id=listing_latest($owner); $posts[]=$id;
    $post=get_post($id); listing_check($post->post_status==='pending' && (int)$post->post_author===$owner,'Cannot forge publication or author.');
    listing_check(wuai_sluyu_listing_data($id)['state']==='open','Cannot forge initial resolved state.');
    listing_check(strpos($post->post_content,'<script')===false,'Submission strips script markup.');
    listing_check(is_wp_error(listing_action($owner,'listing_submit',listing_form($owner))),'Creation rate limit enforced.');
    $query=listing_query(['listing_state'=>'all']); listing_check(!in_array($id,wp_list_pluck($query->posts,'ID'),true),'Pending listing absent from public list.');
    wp_set_current_user($owner); $mine=listing_query(['scope'=>'mine','listing_state'=>'all'],$owner); listing_check(in_array($id,wp_list_pluck($mine->posts,'ID'),true),'Owner sees own pending item.');
    wp_set_current_user($other); $foreign=listing_query(['scope'=>'mine','listing_state'=>'all'],$owner); listing_check(!$foreign->post_count,'Cannot query another user private scope.');
    listing_check(wuai_sluyu_listing_detail($id)==='','Non-owner detail helper cannot expose pending listing metadata.');
    listing_check(is_wp_error(listing_action($owner,'listing_status',['id'=>0,'decision'=>'resolved'])),'Zero ID cannot fall back to global post.');
    listing_check(is_wp_error(listing_action($other,'listing_resubmit',listing_form($owner,['id'=>$id,'version'=>wuai_sluyu_listing_version($id)]))),'Other user cannot edit item.');
    $_POST=[]; wp_set_current_user($admin); wp_update_post(['ID'=>$id,'post_status'=>'publish']);
    $listing=wuai_sluyu_listing_data($id); listing_check($listing['post']->post_status==='publish','Admin can approve listing.');
    wp_set_current_user($other); $detail=wuai_sluyu_listing_detail($id); listing_check(strpos($detail,'peer='.$owner)!==false,'Open detail offers private-message CTA.');
    listing_check(strpos($detail,get_userdata($owner)->user_email)===false && strpos($detail,get_userdata($owner)->user_login)===false,'CTA does not disclose email or permanent login.');
    listing_check(is_wp_error(listing_action($other,'listing_status',['id'=>$id,'decision'=>'resolved'])),'Other user cannot change status.');
    listing_check(!is_wp_error(listing_action($owner,'listing_status',['id'=>$id,'decision'=>'resolved'])),'Owner marks solved.');
    listing_check(wuai_sluyu_listing_data($id)['state']==='resolved','Resolved state saved.');
    wp_set_current_user($other); listing_check(strpos(wuai_sluyu_listing_detail($id),'peer='.$owner)===false,'Resolved detail closes contact CTA.');
    listing_check(is_wp_error(listing_action($owner,'listing_status',['id'=>$id,'decision'=>'open'])),'Direct reopen denied.');
    listing_check(is_wp_error(wuai_sluyu_account_submission_permission(get_post($id),$owner,'edit_submission')),'Generic editor cannot bypass listing validation.');
    delete_transient('wuai_listing_rate_'.$owner);
    $version=wuai_sluyu_listing_version($id);
    listing_check(is_wp_error(listing_action($owner,'listing_resubmit',listing_form($owner,['id'=>$id,'version'=>str_repeat('0',64)]))),'Stale editing version denied.');
    listing_check(!is_wp_error(listing_action($owner,'listing_resubmit',listing_form($owner,['id'=>$id,'version'=>$version,'title'=>'QA Reopened Demand','listing_type'=>'demand','listing_category'=>'service']))),'Resolved listing can be resubmitted.');
    listing_check(get_post_status($id)==='pending' && wuai_sluyu_listing_data($id)['state']==='open','Reopening requires fresh moderation, not immediate publication.');
    $_POST=[]; wp_set_current_user($admin); wp_update_post(['ID'=>$id,'post_status'=>'publish']);
    update_post_meta($id,'_wuai_listing_deadline',time()-1); listing_check(wuai_sluyu_listing_data($id)['state']==='expired','Expired state computed immediately without cron.');
    wp_set_current_user($other); listing_check(strpos(wuai_sluyu_listing_detail($id),'peer='.$owner)===false,'Expired detail closes contact CTA.');
    $open=listing_query(['listing_state'=>'open']); listing_check(!in_array($id,wp_list_pluck($open->posts,'ID'),true),'Expired item is absent from open list.');
    $expired=listing_query(['listing_state'=>'expired']); listing_check(in_array($id,wp_list_pluck($expired->posts,'ID'),true),'Expired item appears in expired filter.');
    listing_check(is_wp_error(listing_action($owner,'listing_status',['id'=>$id,'decision'=>'open'])),'Expired listing cannot be directly restored.');
    delete_transient('wuai_listing_rate_'.$owner);
    listing_check(!is_wp_error(listing_action($owner,'submit',listing_form($owner,['kind'=>'listing','title'=>'QA Alias Submit']))),'Generic submit kind listing dispatch accepted without altering other submit types.'); $alias=listing_latest($owner); $posts[]=$alias;
    listing_check(get_post_status($alias)==='pending','Alias submission remains moderated.');
    $fields=listing_form($owner); $stamp=wuai_sluyu_listing_data($alias)['deadline']; listing_check(wp_date('H:i:s',$stamp)==='23:59:59','Deadline ends at site-local day boundary.');
    wp_set_current_user($admin); $_POST=['wuai_listing_nonce'=>wp_create_nonce('wuai_listing_meta'),'wuai_listing_type'=>'supply','wuai_listing_category'=>'material','wuai_listing_state'=>'open','wuai_listing_deadline'=>current_datetime()->modify('+10 days')->format('Y-m-d')];
    wp_update_post(['ID'=>$alias,'post_status'=>'publish']); listing_check(wuai_sluyu_listing_data($alias)['category']==='material','Admin nonce-protected metadata saves.');
    $before=wuai_sluyu_listing_data($alias)['deadline']; $_POST['wuai_listing_deadline']='tomorrow'; wp_update_post(['ID'=>$alias,'post_status'=>'publish']);
    listing_check(get_post_status($alias)==='pending' && wuai_sluyu_listing_data($alias)['deadline']===$before,'Invalid admin deadline cannot publish or partially change metadata.');
    $_POST=['wuai_listing_nonce'=>'bad','wuai_listing_type'=>'demand','wuai_listing_category'=>'other','wuai_listing_state'=>'resolved','wuai_listing_deadline'=>wp_date('Y-m-d')]; wp_update_post(['ID'=>$alias,'post_title'=>'QA Bad Nonce Metadata']);
    listing_check(wuai_sluyu_listing_data($alias)['category']==='material','Invalid nonce cannot change protected listing metadata.');
    $_POST=[];
    for ($i=0;$i<14;$i++) {
        $seed=wp_insert_post(['post_type'=>'sluyu_listing','post_status'=>'publish','post_author'=>$owner,'post_title'=>'QA PAGED SUPPLY '.$i,'post_content'=>'Harmless local pagination fixture.','meta_input'=>['_wuai_listing_type'=>'supply','_wuai_listing_category'=>'game','_wuai_listing_state'=>'open','_wuai_listing_deadline'=>time()+DAY_IN_SECONDS]]); $posts[]=(int)$seed;
    }
    wp_set_current_user($owner); $filters=['scope'=>'mine','listing_state'=>'open','listing_type'=>'supply','listing_category'=>'game'];
    $first=listing_query($filters,$owner,1); $second=listing_query($filters,$owner,2);
    listing_check((int)$first->found_posts===14 && count($first->posts)===12 && count($second->posts)===2,'Public/mine filtered pagination has exact counts.');
    listing_check(!array_intersect(wp_list_pluck($first->posts,'ID'),wp_list_pluck($second->posts,'ID')),'Listing pages do not overlap.');
    $_GET=$filters; ob_start(); wuai_sluyu_view_listings(); $html=ob_get_clean(); listing_check(strpos($html,'listing_category=game')!==false && strpos($html,'listing_type=supply')!==false,'Pagination preserves filters.');
    wp_set_current_user($other); $_GET=['listing_id'=>$alias]; ob_start(); wuai_sluyu_view_listing_submit(); $form=ob_get_clean(); listing_check(strpos($form,'QA Bad Nonce Metadata')===false,'Foreign editing form does not leak pending title.');
    wp_set_current_user($owner); $_GET=['listing_id'=>$id]; ob_start(); wuai_sluyu_view_listing_submit(); $form=ob_get_clean(); listing_check(strpos($form,'listing_resubmit')!==false && strpos($form,'name="version"')!==false,'Owner receives versioned resubmission form.');
    listing_check(!is_wp_error(wuai_sluyu_account_submission_permission(get_post($alias),$owner,'withdraw_submission')),'Owner can still withdraw through account.');
    listing_check(!is_wp_error(listing_action($owner,'withdraw_submission',['id'=>$alias])) && get_post_status($alias)==='draft','Account withdrawal hides pending listing.');
    listing_check(!is_wp_error(listing_action($owner,'delete_submission',['id'=>$alias])) && get_post_status($alias)==='trash','Only nonpublic owner records can be deleted.');
    echo "PASS: listing moderation, ownership, immutable public reopen, realtime expiry, strict dates, private-message CTA, admin metadata, account integration and filtered pagination.\n";
} finally {
    $_POST=[]; wp_set_current_user(isset($users['admin'])?$users['admin']:0);
    foreach ($posts as $id) { if (get_post_type($id)==='sluyu_listing') { wp_delete_post($id,true); } }
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach ($users as $id) { delete_transient('wuai_listing_rate_'.$id); delete_transient('wuai_listing_admin_error_'.$id); wp_delete_user($id); }
    $_GET=$old_get; $_POST=$old_post; wp_set_current_user($old_user);
}
