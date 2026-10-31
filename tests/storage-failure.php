<?php
// Fault injection is limited to this suite's users, posts and exact target meta key.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771') { throw new RuntimeException('Local QA only.'); }
if (!function_exists('wuai_sluyu_saved_meta_matches') || !function_exists('wuai_sluyu_protected_config') || !function_exists('wuai_sluyu_listing_fields')) { throw new RuntimeException('Integrate the storage verification modules first.'); }
function storage_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function storage_latest($uid, $type) {
    $posts = get_posts(['post_type'=>$type,'post_status'=>['draft','pending','publish'],'author'=>$uid,'numberposts'=>1,'orderby'=>'ID','order'=>'DESC']);
    return $posts ? (int) $posts[0]->ID : 0;
}
function storage_fault_action($uid, $operation, $input, $key, $hook, $target = 0) {
    wp_set_current_user($uid); $_POST = wp_slash($input); $hits = 0;
    $block = function ($check, $object_id, $meta_key) use ($uid, $key, $target, &$hits) {
        if ($meta_key !== $key || ($target && (int) $object_id !== (int) $target) || (int) get_post_field('post_author', $object_id) !== (int) $uid || !in_array(get_post_type($object_id), ['post','sluyu_topic','sluyu_listing','sluyu_link'], true)) { return $check; }
        $hits++; return false;
    };
    add_filter($hook, $block, 999, 3);
    try { $result = wuai_sluyu_community_operation($operation, $uid); }
    finally { remove_filter($hook, $block, 999); }
    storage_check($hits > 0, 'The exact injected failure must actually be exercised: ' . $hook . '/' . $key);
    return $result;
}
function storage_assert_failed($result, $code, $uid, $type, $title, &$posts, $expected_id = 0) {
    storage_check(is_wp_error($result) && $result->get_error_code() === $code, 'Partial storage must return WP_Error, never a success/reset response: ' . $title);
    $id = $expected_id ?: storage_latest($uid, $type); storage_check($id > 0, 'Partial submission leaves a recoverable post.'); $posts[] = $id;
    storage_check(get_post_status($id) === 'draft', 'Incomplete configuration is quarantined as a draft: ' . $title);
    storage_check(get_post_field('post_title', $id) === $title, 'Recoverable post preserves the submitted title.');
    global $wpdb;
    $notice = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . wuai_sluyu_table('messages') . " WHERE recipient_id=%d AND kind='notice' AND body LIKE %s", $uid, '%' . $wpdb->esc_like($title) . '%'));
    storage_check($notice === 0, 'A failed submission must not send a successful-review notice.');
    return $id;
}
function storage_admin_meta_failure($editor,$post_id,$input,$key,$hook,$post_update) {
    wp_set_current_user($editor); $_POST=wp_slash($input); $hits=0;
    $block=function($check,$object_id,$meta_key)use($post_id,$key,&$hits){if((int)$object_id===(int)$post_id && $meta_key===$key){$hits++;return false;}return $check;};
    add_filter($hook,$block,999,3);
    try { $saved=wp_update_post(array_merge(['ID'=>$post_id],$post_update),true); }
    finally { remove_filter($hook,$block,999); }
    storage_check(!is_wp_error($saved) && (int)$saved===(int)$post_id,'Native post write survives the injected metadata failure.');
    storage_check($hits>0,'Admin fault targets the intended metadata write: '.$key);
}
$old_user = get_current_user_id(); $old_get = $_GET; $old_post = $_POST;
$prefix = 'qa_storage_' . strtolower(wp_generate_password(8, false, false)); $users = []; $posts = []; $after = [];
$after_hook = function ($id) use (&$after) { $after[] = (int) $id; };
try {
    foreach (['owner'=>'subscriber','editor'=>'administrator'] as $name=>$role) {
        $uid = wp_create_user($prefix . '_' . $name, 'qa-local-only', $prefix . '_' . $name . '@example.test');
        storage_check(!is_wp_error($uid), 'Create dedicated fixture user.'); (new WP_User($uid))->set_role($role); $users[$name] = (int) $uid;
    }
    $owner = $users['owner']; $editor = $users['editor'];
    add_action('wuai_sluyu_after_submission', $after_hook, 100, 1);
    add_action('wuai_sluyu_after_submission_edit', $after_hook, 100, 1);
    $secret = 'PRIVATE-FAULT-' . wp_generate_password(12, false, false);
    foreach (['add_post_metadata','update_post_metadata'] as $hook) {
        $title = $prefix . ' protected ' . $hook;
        $input = ['kind'=>'post','title'=>$title,'body'=>'A harmless public summary kept separate from the secret.','access'=>'points','cost'=>'25','protected_body'=>$secret . ' protected paragraph.'];
        $result = storage_fault_action($owner, 'submit', $input, wuai_sluyu_protected_key(), $hook);
        $id = storage_assert_failed($result, 'submission_partial', $owner, 'post', $title, $posts);
        storage_check(!wuai_sluyu_protected_config($id), 'The target private metadata really failed to persist.');
        storage_check(strpos(get_post_field('post_content', $id), $secret) === false, 'Failed private metadata is never moved into public content.');
        storage_check(!in_array($id, $after, true), 'Failure must not run successful submission side effects.');
        storage_check(wuai_sluyu_points($owner) === 0, 'Submitting failed protected content never debits points.');
    }
    foreach (['_wuai_topic_kind','_wuai_poll_choices','_wuai_poll_mode','_wuai_poll_max','_wuai_poll_deadline'] as $key) {
        $title = $prefix . ' poll ' . $key;
        $input = ['kind'=>'poll','title'=>$title,'body'=>'A harmless multiple-choice poll fixture.','choices'=>"Option zero\nOption one\nOption two",'poll_mode'=>'multiple','poll_max'=>'2','poll_deadline'=>wp_date('Y-m-d\TH:i', time() + DAY_IN_SECONDS)];
        $result = storage_fault_action($owner, 'submit', $input, $key, 'add_post_metadata');
        $id = storage_assert_failed($result, 'submission_partial', $owner, 'sluyu_topic', $title, $posts);
        storage_check(!metadata_exists('post', $id, $key), 'Failed poll setting is genuinely missing: ' . $key);
        storage_check(!in_array($id, $after, true), 'Incomplete poll must not trigger successful-submission hooks.');
        wp_set_current_user($owner); $_POST = ['id'=>$id, 'choice'=>'0'];
        storage_check(is_wp_error(wuai_sluyu_community_operation('vote', $owner)), 'Incomplete draft poll cannot accept votes.');
    }
    $listing_base = ['body'=>'A harmless information-only listing used for fault tests.','listing_type'=>'supply','listing_category'=>'game','listing_deadline'=>current_datetime()->modify('+30 days')->format('Y-m-d')];
    foreach (['_wuai_listing_type','_wuai_listing_category','_wuai_listing_state','_wuai_listing_deadline'] as $key) {
        $title = $prefix . ' listing ' . $key;
        $result = storage_fault_action($owner, 'listing_submit', array_merge($listing_base, ['title'=>$title]), $key, 'add_post_metadata');
        $id = storage_assert_failed($result, 'listing_partial', $owner, 'sluyu_listing', $title, $posts);
        storage_check(!metadata_exists('post', $id, $key), 'Failed listing setting is genuinely missing: ' . $key);
        storage_check(!in_array($id, $after, true) && !get_transient('wuai_listing_rate_' . $owner), 'Partial listing neither triggers success hooks nor consumes successful-submit throttling.');
    }
    wp_set_current_user($owner); $_POST = wp_slash(array_merge($listing_base, ['title'=>$prefix . ' successful baseline']));
    $valid = wuai_sluyu_community_operation('listing_submit', $owner);
    storage_check(!is_wp_error($valid) && !empty($valid['reset']), 'Baseline valid listing can save after fault filters are removed.');
    $listing = storage_latest($owner, 'sluyu_listing'); $posts[] = $listing;
    wp_set_current_user($editor); $_POST = []; wp_update_post(['ID'=>$listing,'post_status'=>'publish']);
    $before_deadline = get_post_meta($listing, '_wuai_listing_deadline', true);
    delete_transient('wuai_listing_rate_' . $owner);
    $title = $prefix . ' partial update';
    $input = array_merge($listing_base, ['id'=>$listing,'version'=>wuai_sluyu_listing_version($listing),'title'=>$title,'listing_type'=>'demand','listing_category'=>'service','listing_deadline'=>current_datetime()->modify('+60 days')->format('Y-m-d')]);
    $result = storage_fault_action($owner, 'listing_resubmit', $input, '_wuai_listing_deadline', 'update_post_metadata', $listing);
    $id = storage_assert_failed($result, 'listing_partial', $owner, 'sluyu_listing', $title, $posts, $listing);
    storage_check(get_post_meta($id, '_wuai_listing_deadline', true) === $before_deadline, 'Failed update keeps the old stored deadline rather than claiming the requested one.');
    storage_check(get_post_meta($id, '_wuai_listing_type', true) === 'demand', 'Failure injection blocks only its exact target key, exercising a real partial write.');
    wp_set_current_user(0);
    $public = new WP_Query(wuai_sluyu_listing_query_args(['listing_state'=>'all']));
    storage_check(!in_array($id, wp_list_pluck($public->posts, 'ID'), true), 'Partially updated formerly-public listing is no longer in public results.');
    storage_check(wuai_sluyu_listing_detail($id) === '', 'Other users cannot render partial draft listing metadata.');
    wp_set_current_user($owner); $_POST = wp_slash(array_merge($input, ['version'=>wuai_sluyu_listing_version($id),'title'=>$prefix . ' recovered listing']));
    $recovered = wuai_sluyu_community_operation('listing_resubmit', $owner);
    storage_check(!is_wp_error($recovered) && !empty($recovered['reload']) && empty($recovered['reset']), 'After storage recovers, the same draft can be resubmitted without duplicate creation.');
    storage_check(get_post_status($id) === 'pending' && get_post_meta($id, '_wuai_listing_deadline', true) !== $before_deadline, 'Recovered record awaits review with the intended new settings.');

    wp_set_current_user($editor); $_POST=[]; wp_update_post(['ID'=>$listing,'post_status'=>'publish']);
    $admin_input=['wuai_listing_nonce'=>wp_create_nonce('wuai_listing_meta'),'wuai_listing_type'=>'supply','wuai_listing_category'=>'material','wuai_listing_state'=>'open','wuai_listing_deadline'=>current_datetime()->modify('+75 days')->format('Y-m-d')];
    $admin_title=$prefix.' admin partial update'; $admin_body='The administrator edited this harmless public description.';
    $before_admin_deadline=get_post_meta($listing,'_wuai_listing_deadline',true);
    $before_notices=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.wuai_sluyu_table('messages')." WHERE recipient_id=%d AND kind='notice'",$owner));
    $write_statuses=[];
    $observe=function($check,$object_id,$key)use($listing,&$write_statuses){if((int)$object_id===$listing && strpos($key,'_wuai_listing_')===0)$write_statuses[]=get_post_status($listing);return $check;};
    add_filter('update_post_metadata',$observe,900,3);
    try { storage_admin_meta_failure($editor,$listing,$admin_input,'_wuai_listing_deadline','update_post_metadata',['post_status'=>'publish','post_title'=>$admin_title,'post_content'=>$admin_body]); }
    finally { remove_filter('update_post_metadata',$observe,900); }
    storage_check(get_post_status($listing)==='pending','Backend partial metadata write cannot leave a published mixed configuration.');
    storage_check($write_statuses && !array_diff($write_statuses,['pending']),'Backend writes occur while the information is non-public, not after publication.');
    storage_check(get_post_field('post_title',$listing)===$admin_title && get_post_field('post_content',$listing)===$admin_body,'Quarantine changes only status, never reverts the administrator post body/title.');
    storage_check(get_post_meta($listing,'_wuai_listing_deadline',true)===$before_admin_deadline && get_post_meta($listing,'_wuai_listing_category',true)==='material','Single-key fault exercises a real mixed setting state held for review.');
    storage_check((bool)get_transient('wuai_listing_admin_error_'.$editor),'Backend partial write emits a useful admin notice.');
    storage_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.wuai_sluyu_table('messages')." WHERE recipient_id=%d AND kind='notice'",$owner))===$before_notices,'Failed backend publication does not emit a false approval notification.');
    $free_lock=wuai_sluyu_lock('submission_'.$listing); storage_check((bool)$free_lock,'Backend storage failure releases its submission lock.'); wuai_sluyu_unlock($free_lock);

    $_POST=wp_slash($admin_input); wp_update_post(['ID'=>$listing,'post_status'=>'publish'],true);
    storage_check(get_post_status($listing)==='publish' && wuai_sluyu_saved_meta_matches($listing,wuai_sluyu_listing_fields(true)),'Normal backend retry publishes only the fully stored settings.');
    storage_check(!get_transient('wuai_listing_admin_error_'.$editor),'Successful backend retry clears its old error notice.');
    $baseline=[]; foreach(['_wuai_listing_type','_wuai_listing_category','_wuai_listing_state','_wuai_listing_deadline']as$key)$baseline[$key]=get_post_meta($listing,$key,true);
    $_POST=wp_slash(array_merge($admin_input,['wuai_listing_nonce'=>'invalid','wuai_listing_category'=>'other'])); wp_update_post(['ID'=>$listing,'post_title'=>$prefix.' admin bad nonce'],true);
    storage_check(wuai_sluyu_saved_meta_matches($listing,$baseline),'Invalid backend nonce cannot write any supply-demand settings.');
    $_POST=wp_slash(array_merge($admin_input,['wuai_listing_category'=>'game']));
    $held=wuai_sluyu_lock('submission_'.$listing); storage_check((bool)$held,'Fixture acquires pre-existing submission lock.');
    try { wp_update_post(['ID'=>$listing,'post_status'=>'publish','post_content'=>'Latest harmless editor text while another operation owns the lock.'],true); }
    finally { wuai_sluyu_unlock($held); }
    storage_check(get_post_status($listing)==='pending' && wuai_sluyu_saved_meta_matches($listing,$baseline),'Busy backend save does not alter metadata or leave it published.');
    storage_check(get_post_field('post_content',$listing)==='Latest harmless editor text while another operation owns the lock.','Lock-conflict quarantine preserves the already-saved editor body.');
    storage_check((bool)get_transient('wuai_listing_admin_error_'.$editor),'Lock conflict emits a retry notice.');
    $_POST=wp_slash($admin_input); wp_update_post(['ID'=>$listing,'post_status'=>'publish'],true);
    storage_check(get_post_status($listing)==='publish','Backend publication recovers after the lock is released.');

    if(function_exists('wuai_sluyu_download_save_fields')) {
        $_POST=[]; $resource=wp_insert_post(['post_type'=>'post','post_author'=>$owner,'post_status'=>'publish','post_title'=>$prefix.' download atomic bundle','post_content'=>'Harmless public resource summary.']); $posts[]=(int)$resource;
        $download_input=['wuai_sluyu_resource_nonce'=>wp_create_nonce('wuai_sluyu_resource'),'wuai_sluyu_download_url'=>'https://example.test/new-restricted-file.zip','wuai_sluyu_download_password'=>'restricted-code','wuai_sluyu_download_access'=>'member','wuai_sluyu_download_cost'=>'25'];
        storage_admin_meta_failure($editor,$resource,$download_input,'_wuai_download_settings','add_post_metadata',['post_status'=>'publish']);
        storage_check(!metadata_exists('post',$resource,'_wuai_download_settings') && !wuai_sluyu_download_config($resource),'Failed first bundle insert never makes a partially configured resource public.');
        storage_check(!wuai_sluyu_download_access($resource,0) && !wuai_sluyu_download_handoff($resource),'Failed first save does not create a downloadable endpoint.');
        storage_check((bool)get_transient('wuai_download_notice_'.$editor),'Failed first bundle emits an admin notice.');
        $_POST=wp_slash($download_input); wp_update_post(['ID'=>$resource,'post_status'=>'publish'],true);
        $old_bundle=get_post_meta($resource,'_wuai_download_settings',true); $old_config=wuai_sluyu_download_config($resource);
        storage_check(is_array($old_bundle) && $old_config['access']==='member' && !wuai_sluyu_download_access($resource,0),'Healthy bundle save retains its intended member restriction.');
        $changed_input=array_merge($download_input,['wuai_sluyu_download_url'=>'https://example.test/should-not-become-public.zip','wuai_sluyu_download_password'=>'changed-code','wuai_sluyu_download_access'=>'public','wuai_sluyu_download_new_version'=>'1']);
        storage_admin_meta_failure($editor,$resource,$changed_input,'_wuai_download_settings','update_post_metadata',['post_status'=>'publish']);
        storage_check(get_post_meta($resource,'_wuai_download_settings',true)===$old_bundle && wuai_sluyu_download_config($resource)===$old_config,'Bundle update failure retains URL/password/access/cost/version as one previous configuration.');
        storage_check(!wuai_sluyu_download_access($resource,0),'Failed public-mode update does not expose the previous member-only resource.');
        storage_check(get_post_meta($resource,'_wuai_sluyu_download_url',true)===$old_config['url'] && get_post_meta($resource,'_wuai_sluyu_download_access',true)==='member','Legacy metadata readers still observe the unchanged atomic bundle.');
        $_POST=wp_slash(array_merge($changed_input,['wuai_sluyu_resource_nonce'=>'invalid'])); wp_update_post(['ID'=>$resource,'post_status'=>'publish'],true);
        storage_check(get_post_meta($resource,'_wuai_download_settings',true)===$old_bundle,'Invalid resource nonce cannot change bundle settings.');
        wp_set_current_user($owner); $_POST=wp_slash(array_merge($changed_input,['wuai_sluyu_resource_nonce'=>wp_create_nonce('wuai_sluyu_resource')]));
        wp_update_post(['ID'=>$resource,'post_status'=>'publish'],true);
        storage_check(get_post_meta($resource,'_wuai_download_settings',true)===$old_bundle && !current_user_can('edit_post_meta',$resource,'_wuai_download_settings'),'Subscriber cannot change an owned resource bundle even with their own valid nonce.');
        wp_set_current_user($editor); $_POST=wp_slash($download_input); $held=wuai_sluyu_protected_post_lock($resource); storage_check((bool)$held,'Fixture holds resource lock.');
        try { wp_update_post(['ID'=>$resource,'post_status'=>'publish'],true); } finally { wuai_sluyu_unlock($held); }
        storage_check(get_post_meta($resource,'_wuai_download_settings',true)===$old_bundle,'Lock conflict preserves the whole existing bundle.');
    }

    wp_set_current_user($owner);
    $_POST = wp_slash(['kind'=>'post','title'=>$prefix . ' recovered protected','body'=>'Public summary is still independent.','protected_body'=>$secret . ' retained safely after recovery.','access'=>'login']);
    $protected_recovery = wuai_sluyu_community_operation('submit', $owner); $protected_id = storage_latest($owner, 'post'); $posts[] = $protected_id;
    storage_check(!is_wp_error($protected_recovery) && !empty($protected_recovery['reset']) && wuai_sluyu_protected_config($protected_id), 'Private-body storage works normally after filter removal.');
    storage_check(strpos(get_post_field('post_content', $protected_id), $secret) === false, 'Successful recovery still keeps the private fragment out of public content.');
    wp_set_current_user($editor); $_POST=[];
    $link=wp_insert_post(['post_type'=>'sluyu_link','post_status'=>'publish','post_author'=>$owner,'post_title'=>$prefix.' link before edit','post_content'=>'A safe public navigation description.','meta_input'=>['_wuai_source_url'=>'https://example.com/old']]);$posts[]=(int)$link;
    $edit_title=$prefix.' partial source edit';
    $result=storage_fault_action($owner,'edit_submission',['id'=>$link,'version'=>wuai_sluyu_account_submission_version($link),'title'=>$edit_title,'body'=>'Updated harmless navigation description.','url'=>'https://example.com/repaired'],'_wuai_source_url','update_post_metadata',$link);
    $saved_id=storage_assert_failed($result,'submission_partial',$owner,'sluyu_link',$edit_title,$posts,$link);
    storage_check(get_post_meta($link,'_wuai_source_url',true)==='https://example.com/old' && !in_array((int)$link,$after,true),'Failed source URL edit preserves the old field in a draft and does not run success hooks.');
    wp_set_current_user($owner);$_POST=['id'=>$link,'version'=>wuai_sluyu_account_submission_version($link),'title'=>$prefix.' source recovered','body'=>'Updated harmless navigation description.','url'=>'https://example.com/repaired'];
    $fixed=wuai_sluyu_community_operation('edit_submission',$owner);storage_check(!is_wp_error($fixed) && get_post_status($link)==='pending' && get_post_meta($link,'_wuai_source_url',true)==='https://example.com/repaired','Source metadata recovery enters review only when fully stored.');
    echo "PASS: exact-key metadata faults quarantine protected bodies, polls and listing creates/edits; backend listing writes are locked, non-public until verified and recoverable; download bundle faults preserve atomic restrictions; nonce/owner checks and ordinary saves survive filter cleanup.\n";
} finally {
    remove_action('wuai_sluyu_after_submission', $after_hook, 100); remove_action('wuai_sluyu_after_submission_edit', $after_hook, 100);
    $_POST = []; wp_set_current_user($users['editor'] ?? 0);
    if (isset($users['owner'])) {
        $all = get_posts(['post_type'=>['post','sluyu_topic','sluyu_listing','sluyu_link'],'post_status'=>['draft','pending','publish','trash'],'author'=>$users['owner'],'numberposts'=>-1,'fields'=>'ids']);
        foreach (array_unique(array_merge($posts, $all)) as $id) { if ((int) get_post_field('post_author', $id) === $users['owner']) { wp_delete_post($id, true); } }
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $uid) { delete_transient('wuai_listing_rate_' . $uid); delete_transient('wuai_listing_admin_error_'.$uid); delete_transient('wuai_download_notice_'.$uid); wp_delete_user($uid); }
    $_GET = $old_get; $_POST = $old_post; wp_set_current_user($old_user);
}
