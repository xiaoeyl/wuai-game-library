<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_listing_types() { return ['supply'=>'提供 / 出让','demand'=>'寻找 / 需求']; }
function wuai_sluyu_listing_categories() { return ['game'=>'游戏','material'=>'素材','service'=>'服务','other'=>'其他']; }
function wuai_sluyu_listing_states() { return ['open'=>'进行中','resolved'=>'已解决','expired'=>'已过期']; }

add_action('init',function(){
    $caps=array_fill_keys(['edit_post','delete_post','edit_posts','edit_others_posts','publish_posts','read_private_posts','delete_posts','delete_private_posts','delete_published_posts','delete_others_posts','edit_private_posts','edit_published_posts','create_posts'],'manage_options');
    $caps['read_post']='manage_options';
    register_post_type('sluyu_listing',['labels'=>['name'=>'供求信息','singular_name'=>'供求信息','add_new_item'=>'添加供求信息','edit_item'=>'编辑供求信息'],'public'=>true,'show_ui'=>true,'show_in_rest'=>false,'has_archive'=>false,'menu_icon'=>'dashicons-megaphone','supports'=>['title','editor','author','revisions'],'rewrite'=>['slug'=>'listing'],'capabilities'=>$caps,'map_meta_cap'=>false]);
},21);

function wuai_sluyu_listing_data($id) {
    $post=get_post($id);
    if (!$post || $post->post_type!=='sluyu_listing') { return false; }
    $type=get_post_meta($post->ID,'_wuai_listing_type',true);
    $category=get_post_meta($post->ID,'_wuai_listing_category',true);
    $state=get_post_meta($post->ID,'_wuai_listing_state',true);
    $deadline=get_post_meta($post->ID,'_wuai_listing_deadline',true);
    if (!is_string($type) || !isset(wuai_sluyu_listing_types()[$type]) || !is_string($category) || !isset(wuai_sluyu_listing_categories()[$category]) || !is_string($state) || !isset(wuai_sluyu_listing_states()[$state]) || !is_scalar($deadline) || !ctype_digit((string)$deadline) || (int)$deadline<1) { return false; }
    $status=$state==='resolved'?'resolved':(($state==='expired' || (int)$deadline<=time())?'expired':'open');
    return ['post'=>$post,'type'=>$type,'category'=>$category,'state'=>$status,'stored_state'=>$state,'deadline'=>(int)$deadline];
}

function wuai_sluyu_listing_version($id) {
    $post=get_post($id); if (!$post || $post->post_type!=='sluyu_listing') { return ''; }
    $state=[$post->ID,$post->post_author,$post->post_status,$post->post_title,$post->post_content,$post->post_modified_gmt];
    foreach (['_wuai_listing_type','_wuai_listing_category','_wuai_listing_state','_wuai_listing_deadline'] as $key) { $state[]=get_post_meta($post->ID,$key,true); }
    return hash('sha256',wp_json_encode($state));
}

function wuai_sluyu_listing_fields($admin=false) {
    $prefix=$admin?'wuai_listing_':'listing_';
    $type=wuai_sluyu_input($prefix.'type'); $category=wuai_sluyu_input($prefix.'category');
    $state=$admin?wuai_sluyu_input($prefix.'state'):'open';
    if (!isset(wuai_sluyu_listing_types()[$type]) || !isset(wuai_sluyu_listing_categories()[$category]) || !isset(wuai_sluyu_listing_states()[$state])) { return new WP_Error('listing_fields','请选择有效的供求类型、分类和状态。'); }
    $date=wuai_sluyu_parse_date(wuai_sluyu_input($prefix.'deadline'));
    if (!$date) { return new WP_Error('listing_date','请填写有效的截止日期（年-月-日）。'); }
    $deadline=$date->setTime(23,59,59)->getTimestamp();
    if (($state==='open' && $deadline<=time()) || (!$admin && $deadline>current_datetime()->modify('+90 days')->setTime(23,59,59)->getTimestamp())) { return new WP_Error('listing_date','进行中的信息截止日期不能早于今天；前台发布最多有效 90 天，按站点时区计算。'); }
    return ['_wuai_listing_type'=>$type,'_wuai_listing_category'=>$category,'_wuai_listing_state'=>$state,'_wuai_listing_deadline'=>$deadline];
}

function wuai_sluyu_listing_query_args($filters=[],$uid=0,$page=1) {
    $type=isset($filters['listing_type']) && is_string($filters['listing_type'])?$filters['listing_type']:'';
    $category=isset($filters['listing_category']) && is_string($filters['listing_category'])?$filters['listing_category']:'';
    $state=isset($filters['listing_state']) && is_string($filters['listing_state'])?$filters['listing_state']:'open';
    if (!isset(wuai_sluyu_listing_states()[$state]) && $state!=='all') { $state='open'; }
    $meta=['relation'=>'AND',
        ['key'=>'_wuai_listing_type','value'=>isset(wuai_sluyu_listing_types()[$type])?[$type]:array_keys(wuai_sluyu_listing_types()),'compare'=>'IN'],
        ['key'=>'_wuai_listing_category','value'=>isset(wuai_sluyu_listing_categories()[$category])?[$category]:array_keys(wuai_sluyu_listing_categories()),'compare'=>'IN'],
        ['key'=>'_wuai_listing_deadline','value'=>0,'compare'=>'>','type'=>'NUMERIC'],
        ['key'=>'_wuai_listing_state','value'=>array_keys(wuai_sluyu_listing_states()),'compare'=>'IN'],
    ];
    if ($state==='open') {
        $meta[]=['key'=>'_wuai_listing_state','value'=>'open'];
        $meta[]=['key'=>'_wuai_listing_deadline','value'=>time(),'compare'=>'>','type'=>'NUMERIC'];
    } elseif ($state==='resolved') { $meta[]=['key'=>'_wuai_listing_state','value'=>'resolved']; }
    elseif ($state==='expired') {
        $meta[]=['relation'=>'OR',['key'=>'_wuai_listing_state','value'=>'expired'],['relation'=>'AND',['key'=>'_wuai_listing_state','value'=>'open'],['key'=>'_wuai_listing_deadline','value'=>time(),'compare'=>'<=','type'=>'NUMERIC']]];
    }
    $args=['post_type'=>'sluyu_listing','post_status'=>'publish','has_password'=>false,'posts_per_page'=>12,'paged'=>max(1,min(100000,absint($page))),'orderby'=>['date'=>'DESC','ID'=>'DESC'],'ignore_sticky_posts'=>true,'meta_query'=>$meta];
    if (($filters['scope']??'')==='mine') {
        if (!$uid || (int)$uid!==get_current_user_id()) { $args['post__in']=[0]; }
        else { $args['author']=absint($uid); $args['post_status']=['publish','pending','draft']; }
    }
    return $args;
}

function wuai_sluyu_listing_quota($uid,$exclude=0) {
    $base=['post_type'=>'sluyu_listing','author'=>$uid,'posts_per_page'=>20,'fields'=>'ids','no_found_rows'=>true,'post__not_in'=>$exclude?[$exclude]:[]];
    $pending=new WP_Query($base+['post_status'=>['pending','draft']]);
    if ($pending->post_count>=20) { return false; }
    $active=new WP_Query($base+['post_status'=>'publish','meta_query'=>[['key'=>'_wuai_listing_state','value'=>'open'],['key'=>'_wuai_listing_deadline','value'=>time(),'compare'=>'>','type'=>'NUMERIC']]]);
    return $pending->post_count+$active->post_count<20;
}

add_filter('wuai_sluyu_community_dispatch',function($result,$operation,$uid){
    $listing_submit=$operation==='listing_submit' || ($operation==='submit' && wuai_sluyu_input('kind')==='listing');
    if ($result!==null || (!$listing_submit && !in_array($operation,['listing_status','listing_resubmit'],true))) { return $result; }
    $uid=absint($uid);
    if (!$uid || $uid!==get_current_user_id() || wuai_sluyu_banned($uid)) { return new WP_Error('listing_auth','请使用当前有效账号操作。'); }
    $id=$listing_submit?0:absint(wuai_sluyu_input('id'));
    if (!$listing_submit && !$id) { return new WP_Error('listing_id','请选择有效的供求信息。'); }
    $lock=$id?wuai_sluyu_lock('submission_'.$id):false;
    if ($id && !$lock) { return new WP_Error('listing_busy','信息正在更新，请稍后再试。'); }
    try {
        $post=null;
        if (!$listing_submit) {
            clean_post_cache($id); $post=get_post($id);
            if (!$post || $post->post_type!=='sluyu_listing' || (int)$post->post_author!==$uid || !in_array($post->post_status,['draft','pending','publish'],true)) { return new WP_Error('listing_owner','信息不存在或不能由当前账号管理。'); }
        }
        if ($operation==='listing_status') {
            $listing=wuai_sluyu_listing_data($id); $decision=wuai_sluyu_input('decision');
            if ($post->post_status!=='publish' || !$listing || $decision!=='resolved') { return new WP_Error('listing_state','只能将已发布的信息标记为已解决；重新开放必须再次提交审核。'); }
            if ($listing['state']==='resolved') { return ['message'=>'此信息已经标记为已解决。','reload'=>true]; }
            if ($listing['state']!=='open') { return new WP_Error('listing_expired','信息已过期，不能直接改变为进行中；请重新提交审核。'); }
            if (!update_post_meta($id,'_wuai_listing_state','resolved')) { return new WP_Error('listing_storage','状态未保存，请刷新核对后重试。'); }
            return ['message'=>'已标记为已解决，联系入口已关闭。','reload'=>true];
        }
        $rate='wuai_listing_rate_'.$uid;
        if (get_transient($rate)) { return new WP_Error('listing_rate','提交过于频繁，请稍后再试。'); }
        if ($post) {
            $version=wuai_sluyu_input('version');
            if (!preg_match('/^[a-f0-9]{64}$/',$version) || !hash_equals(wuai_sluyu_listing_version($id),$version)) { return new WP_Error('listing_changed','信息已更新，请刷新后重新编辑，避免覆盖新状态。'); }
        }
        $title=wuai_sluyu_input('title'); $body=wuai_sluyu_input('body',true);
        if (mb_strlen($title)<2 || mb_strlen($title)>120 || mb_strlen($body)<10 || mb_strlen($body)>10000) { return new WP_Error('listing_content','标题为 2–120 字，说明为 10–10000 字。'); }
        $meta=wuai_sluyu_listing_fields(); if (is_wp_error($meta)) { return $meta; }
        if (!wuai_sluyu_listing_quota($uid,$id)) { return new WP_Error('listing_quota','最多同时保留 20 条草稿、待审核或进行中的供求信息，请先处理已有信息。'); }
        $submission=['post_type'=>'sluyu_listing','post_author'=>$uid,'post_status'=>'pending','post_title'=>$title,'post_content'=>$body,'comment_status'=>'closed','ping_status'=>'closed','meta_input'=>$meta];
        if ($post) { $submission['ID']=$id; $saved=wp_update_post(wp_slash($submission),true); }
        else { $saved=wp_insert_post(wp_slash($submission),true); }
        if (is_wp_error($saved) || !$saved) { return is_wp_error($saved)?$saved:new WP_Error('listing_storage','信息未保存，请刷新核对后重试。'); }
        if(!wuai_sluyu_saved_meta_matches($saved,$meta)) {
            wp_update_post(['ID'=>$saved,'post_status'=>'draft']);
            return new WP_Error('listing_partial','供求信息 #'.$saved.' 的设置未完整保存，暂留草稿。请保留表单内容并联系管理员核对。');
        }
        set_transient($rate,1,20);
        wuai_sluyu_notify($uid,'供求信息「'.$title.'」已提交审核。这里只提供信息展示和站内联系，不代收款或担保交易。');
        if ($post) { do_action('wuai_sluyu_after_submission_edit',$saved,$uid); }
        else { do_action('wuai_sluyu_after_submission',$saved,'listing',$uid); }
        return ['message'=>'信息已保存为待审核，通过后才会公开。本站不代收款、不担保交易。','reset'=>!$post,'reload'=>(bool)$post];
    } finally { if ($lock) { wuai_sluyu_unlock($lock); } }
},15,3);

add_filter('wuai_sluyu_account_types',function($types){$types['sluyu_listing']='供求信息';return $types;});
add_filter('wuai_sluyu_account_submission_permission',function($allowed,$post,$operation,$uid){
    if (is_wp_error($allowed) || !$allowed || $post->post_type!=='sluyu_listing' || $operation!=='edit_submission') { return $allowed; }
    return new WP_Error('listing_special_edit','供求信息请在供求广场的“我的信息”编辑并重新审核，以便同时校验分类和截止日期。');
},10,4);
add_action('transition_post_status',function($new,$old,$post){
    if ($post->post_type==='sluyu_listing' && $new==='publish' && $new!==$old && empty($GLOBALS['wuai_sluyu_listing_admin_silent'][$post->ID])) { wuai_sluyu_notify($post->post_author,'供求信息「'.$post->post_title.'」已通过审核，是否过期以当前截止日期为准。'); }
},10,3);

function wuai_sluyu_listing_form_fields($listing=false) {
    $type=$listing?$listing['type']:'supply'; $category=$listing?$listing['category']:'other';
    $deadline=$listing && $listing['deadline']>time()?wp_date('Y-m-d',$listing['deadline']):current_datetime()->modify('+30 days')->format('Y-m-d');
    echo '<label>信息类型<select name="listing_type">';
    foreach (wuai_sluyu_listing_types() as $value=>$label) { echo '<option value="'.$value.'" '.selected($type,$value,false).'>'.esc_html($label).'</option>'; }
    echo '</select></label><label>分类<select name="listing_category">';
    foreach (wuai_sluyu_listing_categories() as $value=>$label) { echo '<option value="'.$value.'" '.selected($category,$value,false).'>'.esc_html($label).'</option>'; }
    echo '</select></label><label>截止日期（'.esc_html(wp_timezone_string()).'，最多 90 天）<input name="listing_deadline" type="date" min="'.esc_attr(wp_date('Y-m-d')).'" max="'.esc_attr(current_datetime()->modify('+90 days')->format('Y-m-d')).'" value="'.esc_attr($deadline).'" required></label>';
}

function wuai_sluyu_view_listing_submit() {
    if (!wuai_sluyu_require_login()) { return; }
    $uid=get_current_user_id(); $id=absint(wuai_sluyu_query('listing_id')); $post=$id?get_post($id):null; $listing=false;
    if ($id) {
        if (!$post || $post->post_type!=='sluyu_listing' || (int)$post->post_author!==$uid || !in_array($post->post_status,['publish','pending','draft'],true)) { wuai_sluyu_empty('此供求信息不能由当前账号编辑。'); return; }
        $listing=wuai_sluyu_listing_data($id);
    }
    echo '<section class="sluyu-panel"><div class="sluyu-panel-heading"><h2>'.($id?'编辑并重新审核':'发布供求信息').'</h2><a href="'.esc_url(wuai_sluyu_portal_url('listings',['scope'=>'mine','listing_state'=>'all'])).'">我的供求信息</a></div><p>仅提供供求展示和站内私信联系，不代收款、不担保交易、不自动执行交易。请勿发布他人隐私、侵权或违法信息，也不要公开手机号、邮箱、账号密码。</p><p class="sluyu-muted">所有发布和修改均需审核；修改公开信息会暂时下架。已解决或已过期的信息只能重新提交审核后开放，不能直接恢复。</p>';
    wuai_sluyu_form_start($id?'listing_resubmit':'listing_submit');
    if ($id) { echo '<input type="hidden" name="id" value="'.absint($id).'"><input type="hidden" name="version" value="'.esc_attr(wuai_sluyu_listing_version($id)).'">'; }
    echo '<label>标题<input name="title" minlength="2" maxlength="120" value="'.esc_attr($post?$post->post_title:'').'" required></label><label>说明（公开展示，请勿填写敏感联系方式）<textarea name="body" minlength="10" maxlength="10000" rows="8" required>'.esc_textarea($post?$post->post_content:'').'</textarea></label>';
    wuai_sluyu_listing_form_fields($listing); wuai_sluyu_form_end($id?'保存并重新提交审核':'提交审核'); echo '</section>';
}

function wuai_sluyu_listing_actions($listing,$uid) {
    $post=$listing['post'];
    if ($uid && (int)$post->post_author===$uid) {
        echo '<div class="sluyu-button-row"><a class="sluyu-action-plain" href="'.esc_url(wuai_sluyu_portal_url('submit',['kind'=>'listing','listing_id'=>$post->ID])).'">编辑 / 重新提交审核</a>';
        if ($post->post_status==='publish' && $listing['state']==='open') { wuai_sluyu_action_button('listing_status','标记已解决',['id'=>$post->ID,'decision'=>'resolved','confirm'=>'确认标记为已解决？联系入口将关闭，重新开放需要重新审核。']); }
        echo '</div>';
    }
}

function wuai_sluyu_view_listings() {
    $scope=wuai_sluyu_query('scope')==='mine'?'mine':'all'; $uid=get_current_user_id();
    if ($scope==='mine' && !wuai_sluyu_require_login()) { return; }
    $filters=['scope'=>$scope,'listing_type'=>wuai_sluyu_query('listing_type'),'listing_category'=>wuai_sluyu_query('listing_category'),'listing_state'=>wuai_sluyu_query('listing_state',$scope==='mine'?'all':'open')];
    if (!isset(wuai_sluyu_listing_types()[$filters['listing_type']])) { $filters['listing_type']=''; }
    if (!isset(wuai_sluyu_listing_categories()[$filters['listing_category']])) { $filters['listing_category']=''; }
    if (!isset(wuai_sluyu_listing_states()[$filters['listing_state']]) && $filters['listing_state']!=='all') { $filters['listing_state']='open'; }
    echo '<section class="sluyu-panel"><div class="sluyu-panel-heading"><h2>让需要与擅长相遇</h2><a class="sluyu-action-primary" href="'.esc_url(wuai_sluyu_portal_url('submit',['kind'=>'listing'])).'">发布供求</a></div><p class="sluyu-muted">审核制信息展示；不代收款、不担保交易。联系请使用站内私信，谨防冒名及要求预付款的消息。</p><nav class="sluyu-filter-tabs"><a href="'.esc_url(wuai_sluyu_portal_url('listings')).'" '.($scope==='all'?'aria-current="page"':'').'>全部信息</a><a href="'.esc_url(wuai_sluyu_portal_url('listings',['scope'=>'mine','listing_state'=>'all'])).'" '.($scope==='mine'?'aria-current="page"':'').'>我的信息</a></nav><form class="sluyu-filter-form sluyu-listing-filters" method="get" action="'.esc_url(wuai_sluyu_portal_url('listings')).'">';
    if (!get_option('permalink_structure')) { echo '<input type="hidden" name="sluyu_page" value="listings">'; }
    echo '<input type="hidden" name="scope" value="'.esc_attr($scope).'">';
    foreach (['listing_type'=>['类型',[''=>'全部类型']+wuai_sluyu_listing_types()],'listing_category'=>['分类',[''=>'全部分类']+wuai_sluyu_listing_categories()],'listing_state'=>['状态',['all'=>'全部状态']+wuai_sluyu_listing_states()]] as $key=>$field) {
        echo '<label>'.esc_html($field[0]).'<select name="'.esc_attr($key).'">';
        foreach ($field[1] as $value=>$label) { echo '<option value="'.esc_attr($value).'" '.selected($filters[$key],$value,false).'>'.esc_html($label).'</option>'; }
        echo '</select></label>';
    }
    echo '<button class="sluyu-action-plain" type="submit">筛选</button></form></section>';
    $query=new WP_Query(wuai_sluyu_listing_query_args($filters,$uid,wuai_sluyu_page_number()));
    echo '<div class="sluyu-feed sluyu-listings">';
    foreach ($query->posts as $post) {
        $listing=wuai_sluyu_listing_data($post); if (!$listing) { continue; }
        $review=['publish'=>'已发布','pending'=>'审核中','draft'=>'草稿'][$post->post_status]??'待处理';
        echo '<article class="sluyu-panel"><div class="sluyu-feed-meta"><span>'.esc_html(wuai_sluyu_listing_types()[$listing['type']].' · '.wuai_sluyu_listing_categories()[$listing['category']]).'</span><span>'.esc_html(wuai_sluyu_listing_states()[$listing['state']]).($scope==='mine'?' · '.esc_html($review):'').'</span></div><h2>';
        if ($post->post_status==='publish') { echo '<a href="'.esc_url(get_permalink($post)).'">'.esc_html($post->post_title).'</a>'; } else { echo esc_html($post->post_title); }
        echo '</h2><p>'.esc_html(wp_trim_words(wp_strip_all_tags($post->post_content),65)).'</p><p class="sluyu-muted">截止 '.esc_html(wp_date('Y-m-d',$listing['deadline'])).' · 发布 '.esc_html(get_the_date('Y-m-d',$post)).'</p>';
        wuai_sluyu_listing_actions($listing,$uid); echo '</article>';
    }
    echo '</div>'; if (!$query->post_count) { wuai_sluyu_empty('暂无符合筛选条件的信息。'); }
    wuai_sluyu_portal_pagination($query->max_num_pages,'listings','pg',$filters);
}

function wuai_sluyu_listing_detail($id) {
    $record=get_post($id);
    if (!$record || $record->post_type!=='sluyu_listing' || post_password_required($record) || ($record->post_status!=='publish' && (int)$record->post_author!==get_current_user_id() && !current_user_can('manage_options'))) { return ''; }
    $listing=wuai_sluyu_listing_data($id); if (!$listing) { return '<section class="sluyu-notice">此信息配置不完整，暂不开放联系。</section>'; }
    $post=$listing['post']; $uid=get_current_user_id(); $author=get_userdata($post->post_author);
    ob_start();
    echo '<section class="sluyu-panel sluyu-listing-detail"><div class="sluyu-panel-heading"><h2>'.esc_html(wuai_sluyu_listing_types()[$listing['type']].' · '.wuai_sluyu_listing_categories()[$listing['category']]).'</h2><span class="sluyu-state-pill">'.esc_html(wuai_sluyu_listing_states()[$listing['state']]).'</span></div><p>截止 '.esc_html(wp_date('Y-m-d H:i',$listing['deadline'])).'（'.esc_html(wp_timezone_string()).'）</p><p class="sluyu-muted">这里只提供信息展示和站内联系，不提供代收、担保或交易执行。不要向陌生账号提供密码或敏感凭证。</p>';
    if ($post->post_status==='publish' && $listing['state']==='open' && $author && !wuai_sluyu_banned($author->ID) && (int)$author->ID!==$uid) {
        $contact=wuai_sluyu_portal_url('directmessage',['peer'=>$author->ID]);
        echo '<a class="sluyu-action-primary" href="'.esc_url($uid?$contact:wp_login_url($contact)).'">'.($uid?'站内私信联系发布者':'登录后站内联系').'</a>';
    } elseif ($listing['state']!=='open') { echo '<p>此信息已结束，联系入口已关闭。请勿依据过期信息直接交易。</p>'; }
    elseif (!$author || wuai_sluyu_banned($author->ID)) { echo '<p>发布者当前不可联系。</p>'; }
    wuai_sluyu_listing_actions($listing,$uid);
    echo '<p><a href="'.esc_url(wuai_sluyu_portal_url('listings')).'">返回供求广场 →</a></p></section>';
    return ob_get_clean();
}
add_filter('the_content',function($content){
    return is_singular('sluyu_listing') && in_the_loop() && is_main_query() && !post_password_required()?$content.wuai_sluyu_listing_detail(get_the_ID()):$content;
},27);
add_action('template_redirect',function(){if(is_singular('sluyu_listing') || wuai_sluyu_portal_key()==='listings')nocache_headers();},2);

function wuai_sluyu_listing_admin_fields($post) {
    wp_nonce_field('wuai_listing_meta','wuai_listing_nonce');
    $listing=wuai_sluyu_listing_data($post); $type=$listing?$listing['type']:'supply'; $category=$listing?$listing['category']:'other'; $state=$listing?$listing['stored_state']:'open';
    foreach (['type'=>['供求类型',wuai_sluyu_listing_types(),$type],'category'=>['分类',wuai_sluyu_listing_categories(),$category],'state'=>['状态',wuai_sluyu_listing_states(),$state]] as $key=>$field) {
        echo '<p><label>'.esc_html($field[0]).' <select name="wuai_listing_'.$key.'">';
        foreach ($field[1] as $value=>$label) { echo '<option value="'.$value.'" '.selected($field[2],$value,false).'>'.esc_html($label).'</option>'; }
        echo '</select></label></p>';
    }
    echo '<p><label>截止日期（'.esc_html(wp_timezone_string()).'）<input type="date" name="wuai_listing_deadline" value="'.esc_attr($listing?wp_date('Y-m-d',$listing['deadline']):current_datetime()->modify('+30 days')->format('Y-m-d')).'" required></label></p><p>截止日期最后一秒后即时显示“已过期”，无需定时任务。过期信息重新开放必须选择未来截止日期并完成审核。此模块不处理支付或交易。</p>';
}
add_action('add_meta_boxes',function(){add_meta_box('wuai_listing_meta','供求信息设置','wuai_sluyu_listing_admin_fields','sluyu_listing','normal');});
add_filter('wp_insert_post_data',function($data,$postarr){
    if (($data['post_type']??'')!=='sluyu_listing' || !current_user_can('manage_options') || !wp_verify_nonce(wuai_sluyu_input('wuai_listing_nonce'),'wuai_listing_meta') || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) { return $data; }
    $id=absint($postarr['ID']??0);
    if (($id && !current_user_can('edit_post',$id)) || !empty($GLOBALS['wuai_sluyu_listing_admin_saving'][$id])) { return $data; }
    $fields=wuai_sluyu_listing_fields(true);
    // Publish only after all four settings have been written and read back. A
    // save_post callback alone runs after WordPress has already published.
    if (in_array($data['post_status'],['publish','future'],true)) {
        $GLOBALS['wuai_sluyu_listing_admin_publish'][$id]=['requested'=>$data['post_status'],'previous'=>$id?get_post_status($id):''];
        $data['post_status']='pending';
    } else { unset($GLOBALS['wuai_sluyu_listing_admin_publish'][$id]); }
    if (is_wp_error($fields)) {
        set_transient('wuai_listing_admin_error_'.get_current_user_id(),$fields->get_error_message(),MINUTE_IN_SECONDS);
    }
    return $data;
},50,2);
function wuai_sluyu_listing_admin_hold($id,$message) {
    if (get_post_status($id)!=='pending') {
        $saved=wp_update_post(['ID'=>$id,'post_status'=>'pending'],true);
        if (is_wp_error($saved) || !$saved || get_post_status($id)!=='pending') { $message.=' 无法确认下架状态，请管理员立即核对数据库和公开页面。'; }
    }
    set_transient('wuai_listing_admin_error_'.get_current_user_id(),$message,MINUTE_IN_SECONDS);
}
function wuai_sluyu_listing_admin_save($id) {
    if (!empty($GLOBALS['wuai_sluyu_listing_admin_saving'][$id]) || wp_is_post_revision($id) || wp_is_post_autosave($id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('manage_options') || !current_user_can('edit_post',$id) || !wp_verify_nonce(wuai_sluyu_input('wuai_listing_nonce'),'wuai_listing_meta')) { return; }
    $request=$GLOBALS['wuai_sluyu_listing_admin_publish'][$id]??($GLOBALS['wuai_sluyu_listing_admin_publish'][0]??null);
    unset($GLOBALS['wuai_sluyu_listing_admin_publish'][$id],$GLOBALS['wuai_sluyu_listing_admin_publish'][0]);
    $GLOBALS['wuai_sluyu_listing_admin_saving'][$id]=true;
    $lock=false;
    try {
        $fields=wuai_sluyu_listing_fields(true);
        if (is_wp_error($fields)) { wuai_sluyu_listing_admin_hold($id,$fields->get_error_message()); return; }
        $lock=wuai_sluyu_lock('submission_'.$id);
        if (!$lock) { wuai_sluyu_listing_admin_hold($id,'此信息正在被其他操作更新；本次没有写入供求设置，请刷新后重新提交审核。'); return; }
        clean_post_cache($id);
        foreach ($fields as $key=>$value) { update_post_meta($id,$key,$value); }
        wp_cache_delete($id,'post_meta');
        if (!wuai_sluyu_saved_meta_matches($id,$fields)) { wuai_sluyu_listing_admin_hold($id,'供求设置未完整保存；信息保持待审核。请保留本次表单内容并在存储恢复后重试。'); return; }
        if ($request) {
            $GLOBALS['wuai_sluyu_listing_admin_silent'][$id]=$request['previous']==='publish';
            $saved=wp_update_post(['ID'=>$id,'post_status'=>$request['requested']],true);
            if (is_wp_error($saved) || !$saved || get_post_status($id)!==$request['requested']) { wuai_sluyu_listing_admin_hold($id,'设置已保存，但发布状态未能确认，请刷新后核对。'); return; }
        }
        delete_transient('wuai_listing_admin_error_'.get_current_user_id());
    } finally {
        wuai_sluyu_unlock($lock);
        unset($GLOBALS['wuai_sluyu_listing_admin_saving'][$id],$GLOBALS['wuai_sluyu_listing_admin_silent'][$id]);
    }
}
add_action('save_post_sluyu_listing','wuai_sluyu_listing_admin_save');
add_action('admin_notices',function(){
    if (!current_user_can('manage_options')) { return; }
    $key='wuai_listing_admin_error_'.get_current_user_id(); $message=get_transient($key);
    if ($message) { delete_transient($key); echo '<div class="notice notice-error"><p>供求信息保存需要核对：'.esc_html($message).'</p></div>'; }
});
