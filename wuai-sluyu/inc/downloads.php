<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_download_modes() {
    return ['public'=>'公开下载','login'=>'登录后下载','member'=>'有效会员下载','comment'=>'本人评论通过后下载','circle'=>'所属圈子成员下载','points'=>'积分解锁下载'];
}

function wuai_sluyu_download_config($post_id) {
    $saved=get_post_meta($post_id,'_wuai_download_settings',true);
    if(metadata_exists('post',$post_id,'_wuai_download_settings') && (!is_array($saved) || count(array_intersect(['url','access','cost','version','password'],array_keys($saved)))!==5)) { return false; }
    $bundle=is_array($saved);
    $url=$bundle?($saved['url']??''):wuai_sluyu_post_meta($post_id,'download_url');
    $access=$bundle?($saved['access']??''):wuai_sluyu_post_meta($post_id,'download_access','public');
    if (!is_string($url) || !preg_match('~^https?://~i',$url) || !is_string($access) || !isset(wuai_sluyu_download_modes()[$access])) { return false; }
    $url=esc_url_raw($url,['http','https']); $parts=wp_parse_url($url);
    if (!$url || !$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) { return false; }
    $raw_cost=$bundle?$saved['cost']:wuai_sluyu_post_meta($post_id,'download_cost',0);
    if ((!is_int($raw_cost) && !is_string($raw_cost)) || !preg_match('/^(?:0|[1-9][0-9]{0,6})$/D',(string)$raw_cost) || (int)$raw_cost>1000000) { return false; }
    $cost=(int)$raw_cost;
    $version=$bundle?($saved['version']??''):wuai_sluyu_post_meta($post_id,'download_version','');
    if ($access==='points' && ($cost<1 || $cost>1000000 || !is_string($version) || !preg_match('/^[a-f0-9-]{36}$/',$version))) { return false; }
    $password=$bundle?($saved['password']??''):wuai_sluyu_post_meta($post_id,'download_password','');
    if(!is_string($version) || !is_scalar($password))return false;
    return ['url'=>$url,'access'=>$access,'cost'=>$cost,'version'=>$version,'password'=>(string)$password];
}
add_filter('get_post_metadata',function($value,$post_id,$key,$single){
    $fields=['_wuai_sluyu_download_url'=>'url','_wuai_sluyu_download_access'=>'access','_wuai_sluyu_download_cost'=>'cost','_wuai_sluyu_download_version'=>'version','_wuai_sluyu_download_password'=>'password'];
    if($value!==null || !isset($fields[$key]))return $value;
    $bundle=get_post_meta($post_id,'_wuai_download_settings',true);
    if(!is_array($bundle) || !array_key_exists($fields[$key],$bundle))return $value;
    return $single?$bundle[$fields[$key]]:[$bundle[$fields[$key]]];
},10,4);

function wuai_sluyu_download_entitlement($post_id,$uid,$config) {
    if (!$uid || !$config) { return false; }
    global $wpdb;
    return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.wuai_sluyu_table('events')." WHERE user_id=%d AND event_key=%s AND target_id=%d AND event_type='download_unlock' AND points<0 LIMIT 1",$uid,'resource_unlock:'.absint($post_id).':'.$config['version'],$post_id));
}

function wuai_sluyu_download_access($post_id,$uid=null) {
    if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($post_id)) { return false; }
    $uid=$uid===null?get_current_user_id():absint($uid);
    $post=get_post($post_id); $config=wuai_sluyu_download_config($post_id);
    if (!$post || !$config || $post->post_status!=='publish' || post_password_required($post) || ($uid && (!get_userdata($uid) || wuai_sluyu_banned($uid)))) { return false; }
    if ($uid && ((int)$post->post_author===$uid || user_can($uid,'edit_others_posts'))) { return true; }
    if ($config['access']==='public') { return true; }
    if (!$uid) { return false; }
    if ($config['access']==='login') { return true; }
    if ($config['access']==='member') { return wuai_sluyu_member($uid); }
    if ($config['access']==='circle') { return wuai_sluyu_protected_circle_access($post_id,$uid); }
    if ($config['access']==='points') { return wuai_sluyu_download_entitlement($post_id,$uid,$config); }
    if ($config['access']==='comment') {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT comment_ID FROM $wpdb->comments WHERE comment_post_ID=%d AND user_id=%d AND comment_approved='1' AND comment_type IN ('','comment') LIMIT 1",$post_id,$uid));
    }
    return false;
}

function wuai_sluyu_download_quote($post_id) {
    if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($post_id)) { return ''; }
    $config=wuai_sluyu_download_config($post_id); $post=get_post($post_id); $uid=get_current_user_id();
    if (!$uid || !$config || !$post || $config['access']!=='points' || $post->post_status!=='publish' || post_password_required($post) || wuai_sluyu_banned($uid)) { return ''; }
    return hash_hmac('sha256',wp_json_encode([$uid,$post_id,$post->post_title,$config]),wp_salt('nonce'));
}

function wuai_sluyu_download_unlock($post_id,$quote,$confirmed,$user_locked=false) {
    $uid=get_current_user_id(); $post_id=absint($post_id);
    if (!$uid || wuai_sluyu_banned($uid)) { return new WP_Error('download_auth','请使用正常状态的账号登录。'); }
    $user_lock=$user_locked?false:wuai_sluyu_lock('user_'.$uid); $post_lock=false;
    if (!$user_locked && !$user_lock) { return new WP_Error('download_busy','账户正在处理其他操作。'); }
    try {
        $post_lock=wuai_sluyu_protected_post_lock($post_id);
        if (!$post_lock) { return new WP_Error('download_busy','资源正在更新，请刷新后重试。'); }
        clean_post_cache($post_id); wp_cache_delete($uid,'user_meta');
        if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($post_id)) { return new WP_Error('download_held','内容正在核验，不会扣除积分。'); }
        $post=get_post($post_id); $config=wuai_sluyu_download_config($post_id);
        if (!$post || !$config || $config['access']!=='points' || $post->post_status!=='publish' || post_password_required($post) || wuai_sluyu_banned($uid)) { return new WP_Error('download_unavailable','资源当前无法解锁，不会扣除积分。'); }
        if (wuai_sluyu_download_access($post_id)) { return ['message'=>'已具有下载权限，不会重复扣分。','charged'=>0,'reload'=>true]; }
        if ($confirmed!=='1' || !is_string($quote) || !preg_match('/^[a-f0-9]{64}$/',$quote) || !hash_equals(wuai_sluyu_download_quote($post_id),$quote)) { return new WP_Error('download_confirm','请刷新页面重新核对资源名称与积分价格，并明确确认。'); }
        $balance=wuai_sluyu_points($uid);
        if ($balance<$config['cost']) { return new WP_Error('download_points','积分不足，本资源需要 '.$config['cost'].' 积分。'); }
        if (!wuai_sluyu_event($uid,'resource_unlock:'.$post_id.':'.$config['version'],'download_unlock',-$config['cost'],$post_id,'解锁下载：'.sanitize_text_field($post->post_title))) { return new WP_Error('download_storage','解锁未完成，请刷新核对积分后重试。'); }
        return ['message'=>'已扣除 '.$config['cost'].' 积分并解锁当前下载版本。','charged'=>$config['cost'],'reload'=>true];
    } finally { wuai_sluyu_unlock($post_lock); wuai_sluyu_unlock($user_lock); }
}
add_filter('wuai_sluyu_community_dispatch',function($result,$operation,$uid){
    if ($result!==null || $operation!=='download_unlock') { return $result; }
    return wuai_sluyu_download_unlock(wuai_sluyu_input('id'),wuai_sluyu_input('quote'),wuai_sluyu_input('confirm_unlock'),true);
},45,3);

function wuai_sluyu_download_signature($post_id,$uid,$expires,$config) {
    return hash_hmac('sha256',wp_json_encode([absint($post_id),absint($uid),(int)$expires,$config]),wp_salt('auth'));
}
function wuai_sluyu_download_handoff($post_id) {
    if (!wuai_sluyu_download_access($post_id)) { return ''; }
    $config=wuai_sluyu_download_config($post_id); $uid=get_current_user_id(); $expires=time()+5*MINUTE_IN_SECONDS;
    return add_query_arg(['action'=>'wuai_sluyu_download','resource'=>absint($post_id),'expires'=>$expires,'signature'=>wuai_sluyu_download_signature($post_id,$uid,$expires,$config)],admin_url('admin-post.php'));
}
function wuai_sluyu_download_target($post_id,$expires,$signature) {
    $post_id=is_scalar($post_id)?absint($post_id):0; $expires=is_scalar($expires)?(string)$expires:'';
    if (!ctype_digit($expires) || (int)$expires<time() || (int)$expires>time()+5*MINUTE_IN_SECONDS || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/',$signature) || !wuai_sluyu_download_access($post_id)) { return new WP_Error('download_token','下载入口已失效或权限已变化，请返回文章刷新后重试。'); }
    $config=wuai_sluyu_download_config($post_id);
    if (!hash_equals(wuai_sluyu_download_signature($post_id,get_current_user_id(),$expires,$config),$signature)) { return new WP_Error('download_token','下载入口已失效或不属于当前账号。'); }
    return $config['url'];
}
function wuai_sluyu_download_redirect() {
    nocache_headers(); header('Referrer-Policy: no-referrer');
    $target=wuai_sluyu_download_target(wuai_sluyu_query('resource'),wuai_sluyu_query('expires'),wuai_sluyu_query('signature'));
    if (is_wp_error($target)) { wp_die(esc_html($target->get_error_message()),'下载入口',['response'=>403,'back_link'=>true]); }
    // Destination is saved by a trusted editor and authenticated by a short-lived HMAC.
    wp_redirect($target,302,'Wuai Download'); exit;
}
add_action('admin_post_wuai_sluyu_download','wuai_sluyu_download_redirect');
add_action('admin_post_nopriv_wuai_sluyu_download','wuai_sluyu_download_redirect');

function wuai_sluyu_download_gate($post_id,$compact=false,$instance_id='') {
    $config=wuai_sluyu_download_config($post_id); $uid=get_current_user_id(); $mode=$config?$config['access']:'';
    $id=$compact?'download-sidebar'.($instance_id?'-'.sanitize_html_class($instance_id):''):'download-box';
    echo '<section class="sluyu-panel sluyu-resource-locked" id="'.esc_attr($id).'"><h3>下载权限</h3>';
    $messages=['login'=>'登录后可下载此资源。','member'=>'需要有效会员权限。','comment'=>'使用当前账号发表评论，审核通过后可下载。','circle'=>'需加入本文全部所属圈子并满足成员资格。','points'=>'确认后使用站内积分解锁当前下载版本，不涉及现金。'];
    echo '<p>'.esc_html($messages[$mode]??'此资源当前不可下载，请联系管理员核对设置。').'</p>';
    if (!$uid && $config) { echo '<a class="sluyu-action-primary" href="'.esc_url(wp_login_url(get_permalink($post_id))).'">登录后继续</a>'; }
    elseif ($uid && wuai_sluyu_banned($uid)) { echo '<p>账号处于禁言期，暂时不能操作。</p>'; }
    elseif ($mode==='member') { echo '<a class="sluyu-action-primary" href="'.esc_url(wuai_sluyu_portal_url('vips')).'">查看会员计划</a>'; }
    elseif ($mode==='comment') { echo '<a class="sluyu-action-primary" href="#comments">前往评论</a>'; }
    elseif ($mode==='circle') { echo '<a class="sluyu-action-primary" href="'.esc_url(wuai_sluyu_portal_url('circles')).'">查看圈子</a>'; }
    elseif ($mode==='points' && $uid && get_post_status($post_id)==='publish') {
        echo '<p>资源：'.esc_html(get_the_title($post_id)).'<br>需要 '.$config['cost'].' 积分，余额 '.wuai_sluyu_points($uid).' 积分。</p>';
        if ($compact) { echo '<a class="sluyu-action-plain" href="#download-box">前往正文核对并解锁</a>'; }
        else { wuai_sluyu_form_start('download_unlock'); echo '<input name="id" type="hidden" value="'.absint($post_id).'"><input name="quote" type="hidden" value="'.esc_attr(wuai_sluyu_download_quote($post_id)).'"><label><span><input type="checkbox" name="confirm_unlock" value="1" required style="width:auto"> 我确认扣除 '.$config['cost'].' 积分解锁当前下载版本</span></label>'; wuai_sluyu_form_end('确认积分解锁'); }
    }
    echo '<p class="sluyu-muted">只控制本站下载卡片和跳转入口。外部网盘、已经复制的链接和第三方缓存不受本站控制；资源可能由发布者撤回。正文片段与下载权限独立计费，请分别核对。</p></section>';
}

function wuai_sluyu_download_admin_fields($post) {
    echo '<p><label>下载积分价格（仅积分模式）<input type="number" min="1" max="1000000" name="wuai_sluyu_download_cost" value="'.max(1,absint(wuai_sluyu_post_meta($post->ID,'download_cost',10))).'" class="widefat"></label></p><p><label><input type="checkbox" name="wuai_sluyu_download_new_version" value="1"> 新收费版本（不沿用旧版积分解锁）</label></p><p>改链接默认保留当前已购权益；独立正文与下载卡片是两个权限。积分不是现金，资源不保证永久在线。圈内下载需先为话题选择圈子。</p>';
}
function wuai_sluyu_download_save_fields($id) {
    if(wuai_sluyu_banned(get_current_user_id()) || !wp_verify_nonce(wuai_sluyu_input('wuai_sluyu_resource_nonce'),'wuai_sluyu_resource') || !current_user_can('edit_others_posts') || !current_user_can('edit_post',$id))return;
    if(!isset($_POST['wuai_sluyu_download_url'],$_POST['wuai_sluyu_download_access']))return;
    $lock=wuai_sluyu_protected_post_lock($id);
    if(!$lock) { set_transient('wuai_download_notice_'.get_current_user_id(),'下载配置正在使用中，本次配置未保存，请刷新重试。',60); return; }
    try {
        clean_post_cache($id);
        $url=esc_url_raw(wuai_sluyu_input('wuai_sluyu_download_url'),['http','https']); $access=wuai_sluyu_input('wuai_sluyu_download_access');
        $parts=$url?wp_parse_url($url):[]; $cost=wuai_sluyu_input('wuai_sluyu_download_cost');
        if(!isset(wuai_sluyu_download_modes()[$access]) || ($url && (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']))) || ($access==='points' && (!preg_match('/^[1-9][0-9]{0,6}$/',$cost) || (int)$cost>1000000))) {
            set_transient('wuai_download_notice_'.get_current_user_id(),'下载地址、访问方式或积分价格无效；原配置保持不变。',60); return;
        }
        $version=wuai_sluyu_post_meta($id,'download_version','');
        if(!$version || wuai_sluyu_input('wuai_sluyu_download_new_version')==='1')$version=wp_generate_uuid4();
        $bundle=['url'=>$url,'password'=>wuai_sluyu_input('wuai_sluyu_download_password'),'access'=>$access,'cost'=>$access==='points'?(int)$cost:0,'version'=>$version];
        // One metadata row switches all permission/URL/price/version fields atomically.
        update_post_meta($id,'_wuai_download_settings',wp_slash($bundle));
        if(get_post_meta($id,'_wuai_download_settings',true)!==$bundle) { set_transient('wuai_download_notice_'.get_current_user_id(),'下载配置未能完整保存，原配置保持不变。请刷新核对。',60); }
    } finally { wuai_sluyu_unlock($lock); }
}
add_action('admin_notices',function(){
    if(!current_user_can('edit_others_posts'))return;
    $key='wuai_download_notice_'.get_current_user_id();$message=get_transient($key);
    if($message){delete_transient($key);echo '<div class="notice notice-error"><p>'.esc_html($message).'</p></div>';}
});
add_action('init',function(){
    foreach(['post','page','sluyu_topic'] as $type) {
        foreach(['_wuai_download_settings','_wuai_sluyu_download_url','_wuai_sluyu_download_password','_wuai_sluyu_download_access','_wuai_sluyu_download_cost','_wuai_sluyu_download_version'] as $key) {
            register_post_meta($type,$key,['single'=>true,'type'=>$key==='_wuai_download_settings'?'array':'string','show_in_rest'=>false,'auth_callback'=>function($allowed,$key,$post_id,$uid){return $uid && !wuai_sluyu_banned($uid) && user_can($uid,'edit_others_posts') && user_can($uid,'edit_post',$post_id);}]);
        }
    }
},30);
