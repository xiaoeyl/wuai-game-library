<?php
// Integration tests only for the disposable local WordPress installation.
if (PHP_SAPI !== 'cli') { exit(1); }
$root=getenv('WP_QA_ROOT'); if (!$root) { throw new RuntimeException('WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST']='127.0.0.1:8771'; $_SERVER['REQUEST_URI']='/'; require $root.'/wp-load.php';
if (home_url()!=='http://127.0.0.1:8771') { throw new RuntimeException('Local QA only.'); }
function cp_check($condition,$message) { if (!$condition) { throw new RuntimeException($message); } }
function cp_action($uid,$operation,$input=[]) { wp_set_current_user($uid); $_POST=$input; return wuai_sluyu_community_operation($operation,$uid); }
function cp_poll_html($uid,$id) { wp_set_current_user($uid); ob_start(); wuai_sluyu_poll_view($id); return ob_get_clean(); }
function cp_delete_circle($id) {
    global $wuai_sluyu_all_circle_terms;
    $previous=$wuai_sluyu_all_circle_terms??false; $wuai_sluyu_all_circle_terms=true;
    try { return wp_delete_term($id,'sluyu_circle'); } finally { $wuai_sluyu_all_circle_terms=$previous; }
}
function cp_circle_admin_fault($admin,$id,$input,$key,$value=null,$hook='update_term_metadata') {
    wp_set_current_user($admin); $_POST=array_merge(['wuai_circle_nonce'=>wp_create_nonce('wuai_circle_admin')],$input);
    $hits=0; $write_states=[];
    $block=function($check,$term_id,$meta_key,$meta_value)use($id,$key,$value,&$hits,&$write_states){
        if((int)$term_id!==(int)$id)return $check;
        if(in_array($meta_key,['_wuai_circle_owner','_wuai_circle_join','_wuai_circle_posting'],true)) { $write_states[]=get_term_meta($id,'_wuai_circle_status',true); }
        if($meta_key===$key && ($value===null || (string)$meta_value===$value)) { $hits++; return false; }
        return $check;
    };
    add_filter($hook,$block,999,4);
    try { wuai_sluyu_circle_admin_save($id); }
    finally { remove_filter($hook,$block,999); }
    cp_check($hits>0,'The intended admin circle metadata fault was exercised: '.$key);
    cp_check((bool)get_transient('wuai_circle_admin_error_'.$admin),'Failed administrator save produces an actionable notice.');
    $lock=wuai_sluyu_lock('circle_'.$id); cp_check((bool)$lock,'Failed admin save releases its circle lock.'); wuai_sluyu_unlock($lock);
    return $write_states;
}
function cp_circle_storage_fault($uid,$key,$hook,$keep_orphan=false) {
    global $wpdb;
    $id=0; $tt_id=0; $hits=0; $rollback_hits=0;
    $title='QA-CIRCLE-Fault-'.substr(wp_generate_uuid4(),0,8);
    $block=function($check,$object_id,$meta_key)use($key,$title,&$id,&$tt_id,&$hits){
        if($meta_key!==$key)return $check;
        $term=get_term($object_id,'sluyu_circle');
        if(!$term || is_wp_error($term) || $term->name!==$title)return $check;
        $id=(int)$object_id; $tt_id=(int)$term->term_taxonomy_id; $hits++; return false;
    };
    $block_rollback=function($sql)use($wpdb,&$tt_id,&$rollback_hits){
        $pattern='~^DELETE\\s+FROM\\s+`?'.preg_quote($wpdb->term_taxonomy,'~').'`?\\s+WHERE\\s+`?term_taxonomy_id`?\\s*=\\s*\'?'.(int)$tt_id.'\'?(?:\\s|$)~i';
        if($tt_id && preg_match($pattern,$sql)) { $rollback_hits++; return "DELETE FROM $wpdb->term_taxonomy WHERE 1=0"; }
        return $sql;
    };
    add_filter($hook,$block,999,3);
    if($keep_orphan)add_filter('query',$block_rollback,999);
    try { $result=cp_action($uid,'circle_create',['title'=>$title,'body'=>'A dedicated isolated metadata failure fixture.','join_mode'=>'member']); }
    finally { remove_filter($hook,$block,999); if($keep_orphan)remove_filter('query',$block_rollback,999); }
    cp_check($hits>0 && $id,'Exact circle metadata fault exercised: '.$hook.'/'.$key);
    cp_check(is_wp_error($result) && $result->get_error_code()==='circle_storage','Partial circle settings never return a success/reset response.');
    $notice=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.wuai_sluyu_table('messages').' WHERE recipient_id=%d AND body LIKE %s',$uid,'%'.$wpdb->esc_like($title).'%'));
    cp_check($notice===0,'Failed circle creation never sends a successful-review notice.');
    $lock=wuai_sluyu_lock('circle_create'); cp_check((bool)$lock,'Failure releases global circle creation lock.'); wuai_sluyu_unlock($lock);
    if(!$keep_orphan) { cp_check(!get_term($id,'sluyu_circle'),'Incomplete term is removed when storage permits cleanup.'); return; }
    cp_check($rollback_hits===1 && (bool)get_term($id,'sluyu_circle'),'Exact rollback deletion failure leaves a realistic orphan.');
    try {
        cp_check(!metadata_exists('term',$id,'_wuai_circle_status'),'Rollback fault leaves the status metadata absent.');
        cp_check(!wuai_sluyu_circle($id) && wuai_sluyu_circle_status($id)==='pending','Reserved application slug is fail-closed even without status metadata.');
        cp_check(!wuai_sluyu_circle_can_post($id,$uid),'Orphan cannot accept posts.');
        wp_set_current_user(0);
        $args=['taxonomy'=>'sluyu_circle','hide_empty'=>false,'search'=>$title];
        cp_check(!get_terms($args),'Orphan name/description absent from public lists.');
        cp_check((int)get_terms(array_merge($args,['fields'=>'count']))===0,'Public pagination count cannot reveal an orphan.');
        $request=new WP_REST_Request('GET','/wp/v2/sluyu_circle/'.$id);
        cp_check(rest_do_request($request)->get_status()===404,'Direct REST cannot expose an orphan.');
        $request=new WP_REST_Request('GET','/wp/v2/sluyu_circle'); $request->set_param('search',$title); $response=rest_do_request($request);
        cp_check($response->get_status()===200 && !$response->get_data(),'REST collection excludes an orphan.');
        cp_check(is_wp_error(cp_action($uid,'circle_join',['id'=>$id])),'Orphan cannot accept membership.');
    } finally { cp_delete_circle($id); }
}
$users=[];
foreach (['owner','participant','outsider'] as $role) {
    $login='qa_circle_'.$role; $user=get_user_by('login',$login);
    $id=$user?$user->ID:wp_create_user($login,'qa-local-only',$login.'@example.test');
    (new WP_User($id))->set_role('subscriber'); $users[$role]=$id;
    foreach (['_wuai_banned_until','_wuai_member_until'] as $key) { delete_user_meta($id,$key); }
    $wpdb->delete(wuai_sluyu_table('memberships'),['user_id'=>$id]);
    $wpdb->delete(wuai_sluyu_table('events'),['user_id'=>$id]);
    foreach (['submit','circle_create'] as $op) { delete_transient('wuai_rate_'.$id.'_'.$op); }
}
$admin=get_user_by('login','qa_admin')->ID;
$owner=$users['owner']; $participant=$users['participant']; $outsider=$users['outsider'];
wp_set_current_user($admin);
foreach (get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false,'wuai_include_pending'=>true]) as $term) { if (strpos($term->name,'QA-CIRCLE-')===0) { cp_delete_circle($term->term_id); } }
foreach (get_posts(['post_type'=>'sluyu_topic','post_status'=>'any','author'=>$owner,'numberposts'=>-1]) as $post) { wp_delete_post($post->ID,true); }
foreach(['add_term_metadata','update_term_metadata'] as $hook) {
    foreach(['_wuai_circle_status','_wuai_circle_owner','_wuai_circle_join','_wuai_circle_posting'] as $key) { cp_circle_storage_fault($owner,$key,$hook); }
}
cp_circle_storage_fault($owner,'_wuai_circle_status','add_term_metadata',true);
$legacy=wp_insert_term('QA-CIRCLE-Legacy','sluyu_circle',['slug'=>'qa-circle-legacy']); cp_check(!is_wp_error($legacy),'Create old-format metadata-free circle.'); $legacy=(int)$legacy['term_id'];
try {
    wp_set_current_user(0);
    cp_check((bool)wuai_sluyu_circle($legacy),'Metadata-free legacy circle remains public.');
    cp_check(in_array($legacy,array_map('intval',get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false,'fields'=>'ids'])),true),'Legacy circle remains in public directory.');
    cp_check(!wuai_sluyu_circle_can_post($legacy,0) && wuai_sluyu_circle_can_post($legacy,$participant),'Legacy unrestricted participation still requires a real logged-in account.');
} finally { cp_delete_circle($legacy); }
$old_request=wp_insert_term('QA-CIRCLE-OldRequest','sluyu_circle',['slug'=>'circle-'.wp_generate_uuid4()]); cp_check(!is_wp_error($old_request),'Create v1.2-shaped incomplete request.'); $old_request=(int)$old_request['term_id'];
try {
    wp_set_current_user(0);
    cp_check(!wuai_sluyu_circle($old_request),'A v1.2 circle-UUID application without status is not a public legacy term.');
    $args=['taxonomy'=>'sluyu_circle','hide_empty'=>false,'search'=>'QA-CIRCLE-OldRequest'];
    cp_check(!get_terms($args) && (int)get_terms(array_merge($args,['fields'=>'count']))===0,'Old request is absent from public rows and pagination counts.');
    $request=new WP_REST_Request('GET','/wp/v2/sluyu_circle/'.$old_request);
    cp_check(rest_do_request($request)->get_status()===404,'Old request direct REST is denied.');
    update_term_meta($old_request,'_wuai_circle_status','publish');
    cp_check((bool)wuai_sluyu_circle($old_request) && count(get_terms($args))===1,'Explicitly approved v1.2 UUID circle remains public.');
} finally { cp_delete_circle($old_request); }
wp_set_current_user($admin); $_POST=['wuai_circle_nonce'=>wp_create_nonce('wuai_circle_admin'),'_wuai_circle_status'=>'publish','_wuai_circle_join'=>'member','_wuai_circle_posting'=>'members','_wuai_circle_owner'=>(string)$owner];
$native_hits=0;
$native_fault=function($check,$object_id,$key)use(&$native_hits){
    if($key!=='_wuai_circle_status')return $check;
    $term=get_term($object_id,'sluyu_circle');
    if($term && !is_wp_error($term) && $term->name==='QA-CIRCLE-NativeFault') { $native_hits++; return false; }
    return $check;
};
add_filter('add_term_metadata',$native_fault,999,3);
try { $native=wp_insert_term('QA-CIRCLE-NativeFault','sluyu_circle',['slug'=>'custom-requested-slug','description'=>'Native description survives failed first state write.']); }
finally { remove_filter('add_term_metadata',$native_fault,999); }
cp_check(!is_wp_error($native) && $native_hits>0,'Native term insert exercises the first-status-write failure.'); $native=(int)$native['term_id'];
try {
    cp_check(!metadata_exists('term',$native,'_wuai_circle_status') && !wuai_sluyu_circle($native),'Native configured creation without persisted status is still private.');
    cp_check(get_term($native,'sluyu_circle')->description==='Native description survives failed first state write.','Native form text survives its metadata failure.');
    cp_check(!metadata_exists('term',$native,'_wuai_circle_join') && !metadata_exists('term',$native,'_wuai_circle_owner'),'Native quarantine failure does not start other configuration writes.');
    wp_set_current_user(0);
    cp_check(!get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false,'search'=>'QA-CIRCLE-NativeFault']),'Native first-status failure is hidden from public lists.');
    $request=new WP_REST_Request('GET','/wp/v2/sluyu_circle/'.$native); cp_check(rest_do_request($request)->get_status()===404,'Native first-status failure is hidden from REST.');
} finally { $_POST=[]; delete_transient('wuai_circle_admin_error_'.$admin); cp_delete_circle($native); }
$admin_term=wp_insert_term('QA-CIRCLE-AdminAtomic','sluyu_circle',['slug'=>'qa-circle-admin-atomic']); cp_check(!is_wp_error($admin_term),'Create dedicated admin save fixture.'); $admin_term=(int)$admin_term['term_id'];
try {
    $baseline=['_wuai_circle_status'=>'publish','_wuai_circle_join'=>'open','_wuai_circle_posting'=>'everyone','_wuai_circle_owner'=>(string)$owner];
    $desired=['_wuai_circle_status'=>'publish','_wuai_circle_join'=>'member','_wuai_circle_posting'=>'members','_wuai_circle_owner'=>(string)$outsider];
    $notices_before=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.wuai_sluyu_table('messages')." WHERE recipient_id=%d AND kind='notice'",$outsider));
    foreach(['_wuai_circle_owner','_wuai_circle_join','_wuai_circle_posting'] as $key) {
        $_POST=[]; foreach($baseline as $field=>$value)update_term_meta($admin_term,$field,$value);
        $edit=wp_update_term($admin_term,'sluyu_circle',['name'=>'QA-CIRCLE-AdminAtomic','description'=>'The latest harmless administrator description must be preserved.']); cp_check(!is_wp_error($edit),'Edit native term description before metadata save.');
        $states=cp_circle_admin_fault($admin,$admin_term,$desired,$key);
        cp_check($states && !array_diff($states,['pending']),'Owner/join/posting writes occur only after confirmed pending status.');
        cp_check(get_term_meta($admin_term,'_wuai_circle_status',true)==='pending' && !wuai_sluyu_circle($admin_term),'Partial backend configuration cannot remain public.');
        cp_check(get_term($admin_term,'sluyu_circle')->description==='The latest harmless administrator description must be preserved.','Quarantine does not revert native name/description edits.');
        cp_check(get_term_meta($admin_term,$key,true)===$baseline[$key],'Fault preserves the old target field, exercising a real partial state.');
        cp_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.wuai_sluyu_table('messages')." WHERE recipient_id=%d AND kind='notice'",$outsider))===$notices_before,'Failed approval does not send an approval notice.');
    }
    $_POST=[]; foreach($baseline as $field=>$value)update_term_meta($admin_term,$field,$value);
    $states=cp_circle_admin_fault($admin,$admin_term,$desired,'_wuai_circle_status','pending');
    cp_check(!$states,'If pending quarantine fails, no owner/join/posting field is changed.');
    foreach($baseline as $field=>$value)cp_check(get_term_meta($admin_term,$field,true)===$value,'Failed quarantine retains all old rights.');
    cp_circle_admin_fault($admin,$admin_term,$desired,'_wuai_circle_status','publish');
    cp_check(get_term_meta($admin_term,'_wuai_circle_status',true)==='pending','Failed final publish write remains pending.');
    cp_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.wuai_sluyu_table('messages')." WHERE recipient_id=%d AND kind='notice'",$outsider))===$notices_before,'Failed final publish emits no approval notice.');
    wp_set_current_user($admin); $_POST=array_merge(['wuai_circle_nonce'=>wp_create_nonce('wuai_circle_admin')],$desired); wuai_sluyu_circle_admin_save($admin_term);
    cp_check((bool)wuai_sluyu_circle($admin_term) && !get_transient('wuai_circle_admin_error_'.$admin),'Successful retry publishes verified settings and clears stale error.');
    foreach($desired as $field=>$value)cp_check(get_term_meta($admin_term,$field,true)===$value,'Successful retry stores the complete requested configuration.');
    $notices_after=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.wuai_sluyu_table('messages')." WHERE recipient_id=%d AND kind='notice'",$outsider));
    cp_check($notices_after===$notices_before+1,'Only the real pending-to-publish transition notifies the owner.');
    wuai_sluyu_circle_admin_save($admin_term);
    cp_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.wuai_sluyu_table('messages')." WHERE recipient_id=%d AND kind='notice'",$outsider))===$notices_after,'Unchanged resave does not repeat approval notifications.');
    $held=wuai_sluyu_lock('circle_'.$admin_term); cp_check((bool)$held,'Fixture holds the same membership/admin lock.');
    try { $_POST=array_merge(['wuai_circle_nonce'=>wp_create_nonce('wuai_circle_admin')],$baseline); wuai_sluyu_circle_admin_save($admin_term); }
    finally { wuai_sluyu_unlock($held); }
    foreach($desired as $field=>$value)cp_check(get_term_meta($admin_term,$field,true)===$value,'Busy save never overwrites concurrent circle permissions.');
    cp_check((bool)get_transient('wuai_circle_admin_error_'.$admin),'Busy admin save explains the retry requirement.');
    $_POST=array_merge(['wuai_circle_nonce'=>'invalid'],$baseline); wuai_sluyu_circle_admin_save($admin_term);
    foreach($desired as $field=>$value)cp_check(get_term_meta($admin_term,$field,true)===$value,'Invalid nonce cannot update circle permissions.');
    wp_set_current_user($owner); $_POST=array_merge(['wuai_circle_nonce'=>wp_create_nonce('wuai_circle_admin')],$baseline); wuai_sluyu_circle_admin_save($admin_term);
    foreach($desired as $field=>$value)cp_check(get_term_meta($admin_term,$field,true)===$value,'Ordinary owner cannot invoke native admin permission writes.');
    wp_set_current_user($admin); update_user_meta($admin,'_wuai_banned_until',-1); $_POST=array_merge(['wuai_circle_nonce'=>wp_create_nonce('wuai_circle_admin')],$baseline);
    try { wuai_sluyu_circle_admin_save($admin_term); foreach($desired as $field=>$value)cp_check(get_term_meta($admin_term,$field,true)===$value,'Banned native administrator cannot change circle permissions.'); }
    finally { delete_user_meta($admin,'_wuai_banned_until'); }
} finally { $_POST=[]; delete_transient('wuai_circle_admin_error_'.$admin); cp_delete_circle($admin_term); }
$created=cp_action($owner,'circle_create',['title'=>'QA-CIRCLE-Approval','body'=>'A local approval-only community used for integration tests.','join_mode'=>'approval']);
cp_check(!is_wp_error($created),'Circle creation succeeds.');
$terms=get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false,'wuai_include_pending'=>true,'meta_query'=>[['key'=>'_wuai_circle_owner','value'=>$owner]]]);
cp_check(count($terms)===1,'Owner application is stored.'); $circle=$terms[0]->term_id;
cp_check(is_wp_error(cp_action($outsider,'circle_create',['title'=>'QA-CIRCLE-Approval','body'=>'Attempt to take ownership of a pending application.'])),'Duplicate pending name cannot be created by another user.');
cp_check((int)get_term_meta($circle,'_wuai_circle_owner',true)===$owner,'Original pending owner is never replaced.');
cp_check(!wuai_sluyu_circle($circle),'Pending circle is not public.');
cp_check(!in_array($circle,get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false,'fields'=>'ids'])),'Pending circle omitted from lists.');
wp_set_current_user(0); $request=new WP_REST_Request('GET','/wp/v2/sluyu_circle/'.$circle); $response=rest_do_request($request);
cp_check($response->get_status()===404,'Direct REST cannot expose pending circle.');
$request=new WP_REST_Request('GET','/wp/v2/sluyu_circle'); $request->set_param('search','QA-CIRCLE-Approval'); $response=rest_do_request($request);
cp_check($response->get_status()===200 && !$response->get_data(),'REST circle collection omits pending names.');
cp_check(is_wp_error(cp_action($participant,'circle_join',['id'=>$circle])),'Pending join denied.');
$_GET=['scope'=>'owned']; wp_set_current_user($owner); ob_start(); wuai_sluyu_view_circles(); $owned=ob_get_clean(); cp_check(strpos($owned,'QA-CIRCLE-Approval')!==false,'Owner can see own application.');
$_GET=['scope'=>'joined']; wp_set_current_user($outsider); ob_start(); wuai_sluyu_view_circles(); $empty=ob_get_clean(); cp_check(strpos($empty,'QA-CIRCLE-Approval')===false,'Empty joined list never includes unrelated term.');
wp_set_current_user($admin); $_POST=['wuai_circle_nonce'=>wp_create_nonce('wuai_circle_admin'),'_wuai_circle_status'=>'publish','_wuai_circle_join'=>'approval','_wuai_circle_posting'=>'members','_wuai_circle_owner'=>$owner]; wuai_sluyu_circle_admin_save($circle);
cp_check((bool)wuai_sluyu_circle($circle),'Administrator approval opens circle.');
cp_check(!is_wp_error(cp_action($participant,'circle_join',['id'=>$circle])),'Application accepted.');
cp_check(wuai_sluyu_circle_membership($circle,$participant)==='pending','Join approval required.');
cp_check(!wuai_sluyu_circle_can_post($circle,$participant),'Pending applicant cannot post.');
wp_set_current_user($participant); ob_start(); wuai_sluyu_circle_header($circle); $heading=ob_get_clean(); cp_check(strpos($heading,'>发布话题</a>')===false,'Pending applicant is not offered a misleading publish link.');
cp_check(is_wp_error(cp_action($outsider,'circle_member',['id'=>$circle,'user_id'=>$participant,'decision'=>'active'])),'Non-owner cannot approve.');
cp_check(!is_wp_error(cp_action($owner,'circle_member',['id'=>$circle,'user_id'=>$participant,'decision'=>'active'])),'Owner approval succeeds.');
cp_check(wuai_sluyu_circle_count($circle)===1 && wuai_sluyu_circle_can_post($circle,$participant),'Approved member can post.');
wp_set_current_user($participant); ob_start(); wuai_sluyu_circle_header($circle); $heading=ob_get_clean(); cp_check(strpos($heading,'>发布话题</a>')!==false,'Eligible member receives the publish link.');
cp_check(!is_wp_error(cp_action($owner,'circle_member',['id'=>$circle,'user_id'=>$participant,'decision'=>'blocked'])),'Block succeeds.');
cp_check(is_wp_error(cp_action($participant,'circle_leave',['id'=>$circle])),'Blocked status cannot be self-erased.');
cp_check(is_wp_error(cp_action($participant,'circle_join',['id'=>$circle])),'Blocked participant cannot rejoin.');
cp_action($owner,'circle_member',['id'=>$circle,'user_id'=>$participant,'decision'=>'active']);
update_term_meta($circle,'_wuai_circle_join','member');
cp_check(!wuai_sluyu_circle_can_post($circle,$participant),'Expired membership loses participation.');
update_user_meta($participant,'_wuai_member_until',time()+DAY_IN_SECONDS);
cp_check(wuai_sluyu_circle_can_post($circle,$participant),'Valid membership restores participation.');
update_term_meta($circle,'_wuai_circle_join','approval');
cp_check(is_wp_error(cp_action($outsider,'submit',['kind'=>'topic','title'=>'Should fail','body'=>'Not a circle member','group'=>$circle])),'Submission cannot bypass circle membership.');
$_POST=['kind'=>'poll','title'=>'QA-CIRCLE-Multiple','body'=>'Select up to two test options.','choices'=>"Zero\nOne\nTwo",'poll_mode'=>'multiple','poll_max'=>'2','poll_deadline'=>wp_date('Y-m-d\TH:i',time()+DAY_IN_SECONDS),'group'=>$circle];
$submission=cp_action($owner,'submit',$_POST); cp_check(!is_wp_error($submission),'Multiple-choice submission accepted.');
$posts=get_posts(['post_type'=>'sluyu_topic','post_status'=>'pending','author'=>$owner,'numberposts'=>1]); $poll=$posts[0]->ID;
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>['0','2']])),'Pending poll cannot be voted on.');
wp_update_post(['ID'=>$poll,'post_status'=>'publish']);
cp_check(is_wp_error(cp_action($outsider,'vote',['id'=>$poll,'choice'=>['0']])),'Non-member vote denied.');
cp_check(strpos(cp_poll_html($outsider,$poll),'data-operation="vote"')===false,'Nonmember sees results and eligibility guidance, not a ballot.');
cp_check(strpos(cp_poll_html(0,$poll),'data-operation="vote"')===false,'Guest sees no ballot.');
cp_check(strpos(cp_poll_html($participant,$poll),'data-operation="vote"')!==false,'Eligible active participant sees the same ballot the server accepts.');
$wpdb->update(wuai_sluyu_table('memberships'),['status'=>'blocked'],['circle_id'=>$circle,'user_id'=>$participant]);
cp_check(is_wp_error(wuai_sluyu_poll_participation($poll,$participant)) && strpos(cp_poll_html($participant,$poll),'data-operation="vote"')===false,'Blocked membership consistently denies UI and server participation.');
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>['0']])),'Blocked member cannot bypass the hidden ballot.');
$wpdb->update(wuai_sluyu_table('memberships'),['status'=>'active'],['circle_id'=>$circle,'user_id'=>$participant]);
update_term_meta($circle,'_wuai_circle_join','member'); update_user_meta($participant,'_wuai_member_until',time()-60);
cp_check(!wuai_sluyu_circle_can_post($circle,$participant) && strpos(cp_poll_html($participant,$poll),'data-operation="vote"')===false,'Expired membership removes both post and ballot eligibility.');
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>['0']])),'Expired member vote denied.');
update_user_meta($participant,'_wuai_member_until',time()+DAY_IN_SECONDS);
cp_check(wuai_sluyu_poll_participation($poll,$participant)===true && strpos(cp_poll_html($participant,$poll),'data-operation="vote"')!==false,'Renewed membership restores matching UI/server eligibility.');
update_term_meta($circle,'_wuai_circle_join','approval'); update_term_meta($circle,'_wuai_circle_status','pending');
cp_check(strpos(cp_poll_html($participant,$poll),'data-operation="vote"')===false && is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>['0']])),'Pending attached circle is never filtered away to permit voting.');
update_term_meta($circle,'_wuai_circle_status','publish'); update_user_meta($participant,'_wuai_banned_until',-1);
cp_check(strpos(cp_poll_html($participant,$poll),'data-operation="vote"')===false && is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>['0']])),'Banned account denied even by direct operation helper.');
delete_user_meta($participant,'_wuai_banned_until');
$secondary=wp_insert_term('QA-CIRCLE-Secondary','sluyu_circle',['slug'=>'qa-circle-secondary']); cp_check(!is_wp_error($secondary),'Create second participation boundary.'); $secondary=(int)$secondary['term_id'];
try {
    update_term_meta($secondary,'_wuai_circle_status','publish'); update_term_meta($secondary,'_wuai_circle_posting','members'); update_term_meta($secondary,'_wuai_circle_owner',$owner);
    cp_check(!is_wp_error(wp_set_object_terms($poll,[$circle,$secondary],'sluyu_circle')),'Poll can have multiple required circles.');
    cp_check(is_wp_error(wuai_sluyu_circle_topic_participation($poll,$participant)) && strpos(cp_poll_html($participant,$poll),'data-operation="vote"')===false,'Membership in only one of two circles never grants a ballot.');
    cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>['0']])),'Server checks all attached circles too.');
} finally { wp_set_object_terms($poll,[$circle],'sluyu_circle'); cp_delete_circle($secondary); }
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>['0','1','2']])),'Selection limit enforced.');
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>[['0']]])),'Nested input rejected.');
cp_check(!is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>['0','2']])),'Multi-choice ballot saved.');
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>['1']])),'Repeated ballot denied.');
cp_check(strpos(cp_poll_html($participant,$poll),'data-operation="vote"')===false,'Already-voted account sees results, not another ballot.');
cp_check(!is_wp_error(cp_action($owner,'vote',['id'=>$poll,'choice'=>'0'])),'Legacy single-value payload accepted.');
$tally=wuai_sluyu_poll_tally($poll); cp_check($tally['counts']===[2,0,1] && $tally['voters']===2,'Actual ballot counts are accurate.');
cp_check(is_wp_error(cp_action($outsider,'poll_close',['id'=>$poll])),'Outsider cannot close poll.');
wp_set_current_user($admin); $_POST=['wuai_service_meta_nonce'=>wp_create_nonce('wuai_service_meta'),'wuai_topic_kind'=>'topic','wuai_poll_mode'=>'single','wuai_poll_choices'=>"Changed\nWrong",'wuai_poll_max'=>'1']; wp_update_post(['ID'=>$poll,'post_title'=>'QA-CIRCLE-Multiple']);
cp_check(wuai_sluyu_poll($poll)['mode']==='multiple' && wuai_sluyu_poll($poll)['choices']===['Zero','One','Two'],'Poll configuration locks after votes.');
$close_fault=function($check,$object_id,$key)use($poll){return (int)$object_id===(int)$poll && $key==='_wuai_poll_closed'?false:$check;};
add_filter('update_post_metadata',$close_fault,999,3);
try { $close_result=cp_action($owner,'poll_close',['id'=>$poll]); }
finally { remove_filter('update_post_metadata',$close_fault,999); }
cp_check(is_wp_error($close_result) && $close_result->get_error_code()==='poll_storage' && !wuai_sluyu_poll($poll)['closed'],'Failed close-state write reports failure and never claims the poll ended.');
cp_check(!is_wp_error(cp_action($owner,'poll_close',['id'=>$poll])),'Owner can close poll.');
cp_check(wuai_sluyu_poll($poll)['closed'],'Closed status persisted.');
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$poll,'choice'=>'1'])),'Closed poll rejects ballots.');
$pk=wp_insert_post(['post_type'=>'sluyu_topic','post_status'=>'publish','post_author'=>$owner,'post_title'=>'QA-CIRCLE-PK','post_content'=>'Choose your side.','meta_input'=>['_wuai_topic_kind'=>'poll','_wuai_poll_choices'=>['Left','Right'],'_wuai_poll_mode'=>'pk']]);
cp_check(is_wp_error(cp_action($participant,'vote',['id'=>$pk,'choice'=>['0','1']])),'PK does not allow multiple selections.');
cp_check(!is_wp_error(cp_action($participant,'vote',['id'=>$pk,'choice'=>'1'])),'PK vote saved.');
update_post_meta($pk,'_wuai_poll_deadline',time()-60); cp_check(is_wp_error(cp_action($outsider,'vote',['id'=>$pk,'choice'=>'0'])),'Expired poll rejects ballots.');
$browser_poll=wp_insert_post(['post_type'=>'sluyu_topic','post_status'=>'publish','post_author'=>$owner,'post_title'=>'QA-CIRCLE-Browser Multi','post_content'=>'A browser-tested multiple-choice ballot.','meta_input'=>['_wuai_topic_kind'=>'poll','_wuai_poll_choices'=>['Morning','Evening','Weekend'],'_wuai_poll_mode'=>'multiple','_wuai_poll_max'=>2]]);
$open=wp_insert_term('QA-CIRCLE-Open','sluyu_circle',['description'=>'A public circle for browser join and leave tests.']); $open=$open['term_id']; update_term_meta($open,'_wuai_circle_status','publish');update_term_meta($open,'_wuai_circle_owner',$owner);update_term_meta($open,'_wuai_circle_posting','members');
cp_check(!wuai_sluyu_parse_date('2026-02-30'),'Overflow date denied.'); cp_check(!wuai_sluyu_parse_date('tomorrow'),'Relative date denied.'); cp_check(!wuai_sluyu_parse_date(['bad']),'Non-scalar date denied.');
$_GET=['pg'=>['bad'],'kind'=>['bad']]; cp_check(wuai_sluyu_page_number()===1 && wuai_sluyu_query('kind')==='','Nested query parameters safe.');
$manifest=['users'=>$users,'circle'=>$circle,'open'=>$open,'poll'=>$browser_poll,'closed'=>$poll,'pk'=>$pk];
file_put_contents(dirname($root).'/circles-fixture.json',wp_json_encode($manifest));
echo "PASS: fail-closed circle metadata/rollback faults with legacy compatibility; matching post/ballot eligibility and honest close-write failures; circle moderation, blocked/expired users, multiple/PK ballots, deadlines, repeats, locked settings and malformed input.\n";
