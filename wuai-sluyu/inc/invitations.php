<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_invitation_settings() {
    $raw=get_option('wuai_sluyu_invitation_settings',[]); $raw=is_array($raw)?$raw:[];
    $enabled=!empty($raw['enabled']) && !is_multisite();
    return ['enabled'=>$enabled,'required'=>$enabled && !empty($raw['required']),'days'=>max(1,min(30,absint($raw['days']??7)))];
}

function wuai_sluyu_invitation_storage_ready() {
    global $wpdb; $table=wuai_sluyu_table('invites');
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table) { return false; }
    $columns=$wpdb->get_col("SHOW COLUMNS FROM $table");
    return !array_diff(['id','code_hash','code_hint','owner_id','claimed_by','status','expires_at','created_at','claimed_at','reserve_token','reserve_fingerprint','reserved_at'],$columns);
}

function wuai_sluyu_invitation_hash($code) { return hash_hmac('sha256','invitation-code|'.$code,wp_salt('auth')); }
function wuai_sluyu_invitation_fingerprint($login,$email) { return hash_hmac('sha256',strtolower($login)."\0".strtolower($email),wp_salt('secure_auth')); }
function wuai_sluyu_invitation_status($row) {
    return $row->status==='active' && (int)$row->expires_at<=time()?'expired':$row->status;
}
function wuai_sluyu_invitation_labels() { return ['active'=>'可使用','reserved'=>'注册核验中','claimed'=>'已使用','revoked'=>'已撤销','expired'=>'已过期']; }

function wuai_sluyu_invitation_issue($owner,$actor=0) {
    $actor=$actor?:get_current_user_id(); $owner=absint($owner); $settings=wuai_sluyu_invitation_settings();
    if (!$actor || (int)$actor!==get_current_user_id() || wuai_sluyu_banned($actor) || !get_userdata($owner) || wuai_sluyu_banned($owner) || ($owner!==(int)$actor && !user_can($actor,'manage_options'))) { return new WP_Error('invite_owner','不能为此账号生成邀请码。'); }
    if (!$settings['enabled']) { return new WP_Error('invite_disabled','站长尚未启用邀请码。'); }
    if (!get_option('users_can_register')) { return new WP_Error('invite_registration_closed','WordPress 注册未开放，生成邀请码不会开启注册。'); }
    if (!wuai_sluyu_invitation_storage_ready()) { return new WP_Error('invite_storage','邀请码存储尚未就绪，请联系管理员。'); }
    $lock=wuai_sluyu_lock('invite_owner_'.$owner);
    if (!$lock) { return new WP_Error('invite_busy','邀请码正在生成，请稍后再试。'); }
    try {
        global $wpdb; $table=wuai_sluyu_table('invites');
        $today=current_datetime()->setTime(0,0,0)->format('Y-m-d H:i:s');
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE owner_id=%d AND created_at>=%s",$owner,$today));
        $limit=user_can($actor,'manage_options')?50:3;
        if ($count>=$limit) { return new WP_Error('invite_limit','该账号今天已达到生成上限（'.$limit.' 个）。撤销旧码不会重置今日数量。'); }
        try { $code='SL-'.strtoupper(bin2hex(random_bytes(16))); }
        catch (Throwable $error) { return new WP_Error('invite_random','服务器安全随机数暂不可用，未生成邀请码。'); }
        $expires=time()+$settings['days']*DAY_IN_SECONDS;
        $saved=$wpdb->insert($table,['code_hash'=>wuai_sluyu_invitation_hash($code),'code_hint'=>substr($code,-8),'owner_id'=>$owner,'claimed_by'=>0,'status'=>'active','expires_at'=>$expires,'created_at'=>current_time('mysql'),'reserve_token'=>'','reserve_fingerprint'=>'','reserved_at'=>0],['%s','%s','%d','%d','%s','%d','%s','%s','%s','%d']);
        if (!$saved) { return new WP_Error('invite_storage','邀请码未保存，请稍后重试。'); }
        return ['id'=>(int)$wpdb->insert_id,'code'=>$code,'expires_at'=>$expires,'owner_id'=>$owner];
    } finally { wuai_sluyu_unlock($lock); }
}

function wuai_sluyu_invitation_revoke($id,$actor=0) {
    $actor=$actor?:get_current_user_id();
    if (!$actor || (int)$actor!==get_current_user_id() || wuai_sluyu_banned($actor) || !wuai_sluyu_invitation_storage_ready()) { return new WP_Error('invite_permission','当前不能执行邀请码操作。'); }
    global $wpdb; $table=wuai_sluyu_table('invites'); $id=absint($id);
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id));
    if (!$row || ((int)$row->owner_id!==(int)$actor && !user_can($actor,'manage_options'))) { return new WP_Error('invite_missing','邀请码不存在或不属于当前账号。'); }
    $lock=wuai_sluyu_lock('invite_code_'.$row->code_hash);
    if (!$lock) { return new WP_Error('invite_busy','邀请码正在注册核验，请稍后再试。'); }
    try {
        $saved=$wpdb->query($wpdb->prepare("UPDATE $table SET status='revoked' WHERE id=%d AND status='active'",$id));
        if ($saved===false) { return new WP_Error('invite_storage','撤销失败，请稍后重试。'); }
        if (!$saved) { return new WP_Error('invite_state','仅未使用的邀请码可以直接撤销，核验中或已使用的记录不能重置。'); }
        return ['message'=>'邀请码已撤销，不再可用。','reload'=>true];
    } finally { wuai_sluyu_unlock($lock); }
}

function wuai_sluyu_invitation_release_lock() {
    if (!empty($GLOBALS['wuai_invitation_reservation']['lock'])) {
        wuai_sluyu_unlock($GLOBALS['wuai_invitation_reservation']['lock']);
        $GLOBALS['wuai_invitation_reservation']['lock']=false;
    }
}

function wuai_sluyu_invitation_matches($reservation,$user) {
    return $user instanceof WP_User && strcasecmp($user->user_login,$reservation['login'])===0 && strcasecmp($user->user_email,$reservation['email'])===0;
}

function wuai_sluyu_invitation_claim($reservation,$uid) {
    $user=get_userdata($uid);
    if (!wuai_sluyu_invitation_matches($reservation,$user)) { return false; }
    global $wpdb; $table=wuai_sluyu_table('invites');
    $saved=$wpdb->query($wpdb->prepare("UPDATE $table SET status='claimed',claimed_by=%d,claimed_at=%s,reserve_token='',reserve_fingerprint='',reserved_at=0 WHERE id=%d AND status='reserved' AND code_hash=%s AND reserve_token=%s AND reserve_fingerprint=%s",$uid,current_time('mysql'),$reservation['id'],$reservation['hash'],$reservation['token'],$reservation['fingerprint']));
    if ($saved!==1) {
        $row=$wpdb->get_row($wpdb->prepare("SELECT status,claimed_by FROM $table WHERE id=%d",$reservation['id']));
        if (!$row || $row->status!=='claimed' || (int)$row->claimed_by!==(int)$uid) { return false; }
    }
    if ((int)get_user_meta($uid,'_wuai_invitation_claim_pending',true)===(int)$reservation['id']) { delete_user_meta($uid,'_wuai_invitation_claim_pending'); }
    return true;
}

function wuai_sluyu_invitation_registration_errors($errors,$login,$email) {
    $settings=wuai_sluyu_invitation_settings();
    if (!$settings['enabled'] || !($errors instanceof WP_Error)) { return $errors; }
    if ($errors->has_errors()) { return $errors; }
    $provided=isset($_POST['wuai_invitation_code']);
    if ($provided && !is_string($_POST['wuai_invitation_code'])) { $errors->add('wuai_invitation','请填写有效的邀请码。'); return $errors; }
    $code=strtoupper(trim($provided?wp_unslash($_POST['wuai_invitation_code']):''));
    if (!$code && !$settings['required']) { return $errors; }
    if (!get_option('users_can_register')) { $errors->add('wuai_invitation','本站暂未开放 WordPress 注册。'); return $errors; }
    if (!preg_match('/^SL-[A-F0-9]{32}$/D',$code)) { $errors->add('wuai_invitation','请填写有效的邀请码。'); return $errors; }
    if (!wuai_sluyu_invitation_storage_ready()) { $errors->add('wuai_invitation','邀请码服务暂不可用，请稍后重试或联系管理员。'); return $errors; }
    $hash=wuai_sluyu_invitation_hash($code); $fingerprint=wuai_sluyu_invitation_fingerprint($login,$email);
    $existing=$GLOBALS['wuai_invitation_reservation']??null;
    if ($existing) {
        if (hash_equals($existing['hash'],$hash) && hash_equals($existing['fingerprint'],$fingerprint)) { return $errors; }
        $errors->add('wuai_invitation','上一项注册核验尚未完成，请重新提交。'); return $errors;
    }
    $lock=wuai_sluyu_lock('invite_code_'.$hash);
    if (!$lock) { $errors->add('wuai_invitation','邀请码正在核验或暂不可用，请稍后重试。'); return $errors; }
    $reserved=false;
    try {
        global $wpdb; $table=wuai_sluyu_table('invites');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE code_hash=%s",$hash));
        if (!$row || $row->status!=='active' || (int)$row->expires_at<=time() || !get_userdata($row->owner_id) || wuai_sluyu_banned($row->owner_id)) { $errors->add('wuai_invitation','邀请码无效、已使用、已过期或已撤销。'); return $errors; }
        try { $token=bin2hex(random_bytes(24)); }
        catch (Throwable $error) { $errors->add('wuai_invitation','安全核验暂不可用，请稍后重试。'); return $errors; }
        // The durable reservation is not auto-expired: an interrupted process may
        // already have created its account. Only a proven failed request releases it.
        $saved=$wpdb->query($wpdb->prepare("UPDATE $table SET status='reserved',reserve_token=%s,reserve_fingerprint=%s,reserved_at=%d WHERE id=%d AND status='active' AND expires_at>%d",$token,$fingerprint,time(),$row->id,time()));
        if ($saved!==1) { $errors->add('wuai_invitation','邀请码正在核验或暂不可用，请稍后重试。'); return $errors; }
        $GLOBALS['wuai_invitation_reservation']=['id'=>(int)$row->id,'hash'=>$hash,'token'=>$token,'fingerprint'=>$fingerprint,'login'=>$login,'email'=>$email,'lock'=>$lock,'created_user'=>0];
        $reserved=true; return $errors;
    } finally { if (!$reserved) { wuai_sluyu_unlock($lock); } }
}
add_filter('registration_errors','wuai_sluyu_invitation_registration_errors',PHP_INT_MAX,3);

function wuai_sluyu_invitation_observe_user($uid,$userdata=[]) {
    $reservation=$GLOBALS['wuai_invitation_reservation']??null;
    if (!$reservation) { return; }
    $raw_match=is_array($userdata) && isset($userdata['user_login'],$userdata['user_email']) && is_string($userdata['user_login']) && is_string($userdata['user_email']) && strcasecmp($userdata['user_login'],$reservation['login'])===0 && strcasecmp($userdata['user_email'],$reservation['email'])===0;
    if ($raw_match || wuai_sluyu_invitation_matches($reservation,get_userdata($uid))) {
        // Preserve creation evidence before later plugins can change/delete it.
        $GLOBALS['wuai_invitation_reservation']['created_user']=(int)$uid;
    }
}
add_action('user_register','wuai_sluyu_invitation_observe_user',-PHP_INT_MAX,2);

function wuai_sluyu_invitation_user_register($uid,$userdata=[]) {
    $reservation=$GLOBALS['wuai_invitation_reservation']??null;
    if (!$reservation) { return; }
    if (!wuai_sluyu_invitation_matches($reservation,get_userdata($uid))) {
        if ((int)$reservation['created_user']===(int)$uid && get_userdata($uid)) { update_user_meta($uid,'_wuai_invitation_claim_pending',(int)$reservation['id']); }
        return;
    }
    $GLOBALS['wuai_invitation_reservation']['created_user']=(int)$uid;
    if (wuai_sluyu_invitation_claim($reservation,$uid)) {
        wuai_sluyu_invitation_release_lock(); unset($GLOBALS['wuai_invitation_reservation']); return;
    }
    update_user_meta($uid,'_wuai_invitation_claim_pending',(int)$reservation['id']);
    // Never log the code, reservation proof or supplied registration identity.
    error_log('Wuai invitation claim requires review: record '.(int)$reservation['id'].', user '.(int)$uid);
}
add_action('user_register','wuai_sluyu_invitation_user_register',PHP_INT_MAX,2);

function wuai_sluyu_invitation_shutdown() {
    $reservation=$GLOBALS['wuai_invitation_reservation']??null; if (!$reservation) { return; }
    global $wpdb; $table=wuai_sluyu_table('invites');
    try {
        $user=!empty($reservation['created_user'])?get_userdata($reservation['created_user']):get_user_by('login',$reservation['login']);
        if (wuai_sluyu_invitation_matches($reservation,$user)) {
            if (!wuai_sluyu_invitation_claim($reservation,$user->ID)) { update_user_meta($user->ID,'_wuai_invitation_claim_pending',(int)$reservation['id']); }
        } elseif (!empty($reservation['created_user'])) {
            // Another registration hook may have changed or deleted the account.
            // Once creation was observed, never recycle its invitation implicitly.
            if ($user) { update_user_meta($user->ID,'_wuai_invitation_claim_pending',(int)$reservation['id']); }
        } else {
            $wpdb->query($wpdb->prepare("UPDATE $table SET status='active',reserve_token='',reserve_fingerprint='',reserved_at=0 WHERE id=%d AND status='reserved' AND reserve_token=%s AND reserve_fingerprint=%s AND NOT EXISTS (SELECT ID FROM $wpdb->users WHERE user_login=%s AND user_email=%s)",$reservation['id'],$reservation['token'],$reservation['fingerprint'],$reservation['login'],$reservation['email']));
        }
    } finally { wuai_sluyu_invitation_release_lock(); unset($GLOBALS['wuai_invitation_reservation']); }
}
add_action('shutdown','wuai_sluyu_invitation_shutdown',0);

function wuai_sluyu_invitation_recover($id,$login='',$email='') {
    if (!current_user_can('manage_options') || wuai_sluyu_banned(get_current_user_id()) || !wuai_sluyu_invitation_storage_ready()) { return new WP_Error('invite_permission','仅正常状态的管理员可核验挂起的记录。'); }
    global $wpdb; $table=wuai_sluyu_table('invites');
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",absint($id)));
    if (!$row || $row->status!=='reserved' || (int)$row->reserved_at>time()-10*MINUTE_IN_SECONDS) { return new WP_Error('invite_reserved','仅可核验已挂起至少 10 分钟的记录。'); }
    $lock=wuai_sluyu_lock('invite_code_'.$row->code_hash);
    if (!$lock) { return new WP_Error('invite_busy','此记录仍有正在处理的请求，请稍后再试。'); }
    try {
        if ($login || $email) {
            if (!$login || !$email || !hash_equals($row->reserve_fingerprint,wuai_sluyu_invitation_fingerprint($login,$email))) { return new WP_Error('invite_identity','申请人的注册用户名和邮箱未与原核验记录匹配。'); }
            $user=get_user_by('login',$login);
            if (!$user || strcasecmp($user->user_email,$email)!==0) { return new WP_Error('invite_identity','未找到匹配的新账号；请核查后撤销旧码并另发新码，不要恢复旧码。'); }
            $reservation=['id'=>$row->id,'hash'=>$row->code_hash,'token'=>$row->reserve_token,'fingerprint'=>$row->reserve_fingerprint,'login'=>$login,'email'=>$email];
            return wuai_sluyu_invitation_claim($reservation,$user->ID)?['message'=>'已核验注册账号并完成邀请码认领。']:new WP_Error('invite_storage','核验写入失败，邀请码仍保持挂起。');
        }
        $saved=$wpdb->query($wpdb->prepare("UPDATE $table SET status='revoked',reserve_token='',reserve_fingerprint='',reserved_at=0 WHERE id=%d AND status='reserved' AND reserve_token=%s",$row->id,$row->reserve_token));
        return $saved===1?['message'=>'挂起的邀请码已撤销，不会恢复可用；需要时另行生成新码。']:new WP_Error('invite_storage','记录已变化，请刷新核对。');
    } finally { wuai_sluyu_unlock($lock); }
}

add_action('register_form',function(){
    $settings=wuai_sluyu_invitation_settings(); if (!$settings['enabled']) { return; }
    $code=isset($_POST['wuai_invitation_code']) && is_string($_POST['wuai_invitation_code'])?wp_unslash($_POST['wuai_invitation_code']):'';
    echo '<p><label for="wuai_invitation_code">邀请码'.($settings['required']?'（必填）':'（可选）').'<br><input type="text" name="wuai_invitation_code" id="wuai_invitation_code" class="input" maxlength="35" autocomplete="off" value="'.esc_attr($code).'"'.($settings['required']?' required':'').'></label></p><p>每个邀请码只能成功注册一次，请勿公开发布邀请码。</p>';
});
add_action('login_init',function(){ if (isset($_REQUEST['action']) && is_string($_REQUEST['action']) && $_REQUEST['action']==='register') { nocache_headers(); if(!headers_sent())header('Referrer-Policy: no-referrer'); } });

add_filter('wuai_sluyu_community_dispatch',function($result,$operation,$uid){
    if ($result!==null || !in_array($operation,['invite_generate','invite_revoke'],true)) { return $result; }
    if (!$uid || (int)$uid!==get_current_user_id() || wuai_sluyu_banned($uid)) { return new WP_Error('invite_auth','请使用当前有效账号操作。'); }
    if ($operation==='invite_revoke') { return wuai_sluyu_invitation_revoke(absint(wuai_sluyu_input('id')),$uid); }
    $owner=absint(wuai_sluyu_input('owner_id'))?:$uid; $result=wuai_sluyu_invitation_issue($owner,$uid);
    if (is_wp_error($result)) { return $result; }
    return ['message'=>'请立即复制并私下保存邀请码：'.$result['code'].'。此明文只显示本次，关闭后无法找回；有效至 '.wp_date('Y-m-d H:i',$result['expires_at']).'。','invitation_code'=>$result['code'],'invitation_id'=>$result['id'],'expires_at'=>$result['expires_at']];
},25,3);

function wuai_sluyu_invitation_records($uid,$page=1,$admin=false) {
    $uid=absint($uid); $page=max(1,min(100000,absint($page)));
    if ((!$admin && (!$uid || $uid!==get_current_user_id())) || ($admin && !current_user_can('manage_options')) || !wuai_sluyu_invitation_storage_ready()) { return ['total'=>0,'items'=>[]]; }
    global $wpdb; $table=wuai_sluyu_table('invites'); $where=$admin?'1=1':$wpdb->prepare('owner_id=%d',$uid);
    return ['total'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where"),'items'=>$wpdb->get_results($wpdb->prepare("SELECT id,code_hint,owner_id,claimed_by,status,expires_at,created_at,claimed_at,reserved_at FROM $table WHERE $where ORDER BY id DESC LIMIT 10 OFFSET %d",($page-1)*10))];
}

function wuai_sluyu_invitation_panel($uid,$key='invitations') {
    if (!$uid || $uid!==get_current_user_id()) { return; }
    $settings=wuai_sluyu_invitation_settings();
    echo '<section class="sluyu-panel sluyu-invitations"><h2>我的邀请码</h2><p>邀请码只用于本站 WordPress 原生注册，每个码限成功注册一次，不附带积分、会员或现金奖励。关闭系统注册时邀请码不会开放注册。</p>';
    if (!$settings['enabled']) { echo '<p class="sluyu-muted">站长尚未启用邀请码功能。</p></section>'; return; }
    if (!wuai_sluyu_invitation_storage_ready()) { echo '<p>存储尚未就绪，请联系管理员。</p></section>'; return; }
    if (get_option('users_can_register')) {
        echo '<p class="sluyu-notice">每天最多生成 '.(current_user_can('manage_options')?50:3).' 个，有效 '.$settings['days'].' 天。明文只显示一次；丢失时可撤销未使用的码，再生成新码。请勿刷新未保存的生成结果。</p>';
        wuai_sluyu_form_start('invite_generate'); wuai_sluyu_form_end('生成一个邀请码（仅显示一次）');
    } else { echo '<p class="sluyu-notice">WordPress 注册目前关闭，暂不能生成邀请码。</p>'; }
    $page=wuai_sluyu_page_number('invite_pg'); $records=wuai_sluyu_invitation_records($uid,$page); $labels=wuai_sluyu_invitation_labels();
    echo '<div class="sluyu-compact-list">';
    foreach ($records['items'] as $row) {
        $state=wuai_sluyu_invitation_status($row);
        echo '<div><span><b>尾号 '.esc_html($row->code_hint).'</b><p class="sluyu-muted">'.esc_html($labels[$state]??'待核查').' · 有效至 '.esc_html(wp_date('Y-m-d H:i',$row->expires_at)).'</p></span>';
        if ($row->status==='active') { wuai_sluyu_action_button('invite_revoke','撤销',['id'=>$row->id,'confirm'=>'确认撤销此邀请码？原码将永久不可用，撤销不会重置今日生成数量。']); }
        echo '</div>';
    }
    if (!$records['items']) { wuai_sluyu_empty('暂无邀请码记录。'); }
    echo '</div>'; wuai_sluyu_portal_pagination(ceil($records['total']/10),$key,'invite_pg'); echo '</section>';
}
function wuai_sluyu_view_invitations() { if(wuai_sluyu_require_login())wuai_sluyu_invitation_panel(get_current_user_id()); }
add_action('wuai_sluyu_account_profile_extra',function($uid){
    if (!wuai_sluyu_invitation_settings()['enabled']) { return; }
    echo '<details><summary>我的邀请码</summary>'; wuai_sluyu_invitation_panel((int)$uid,'account'); echo '</details>';
},30);

add_action('admin_menu',function(){add_submenu_page('wuai-sluyu','邀请码管理','邀请码管理','manage_options','wuai-sluyu-invitations','wuai_sluyu_invitation_admin');},35);
function wuai_sluyu_invitation_admin() {
    if (!current_user_can('manage_options')) { return; }
    $settings=wuai_sluyu_invitation_settings();
    echo '<div class="wrap"><h1>邀请码管理</h1><p>默认关闭。仅校验 WordPress 原生注册流程；WooCommerce、社交登录、API、多站点等注册渠道不在此模块的强制覆盖范围。启用必填前，请关闭不受支持的公开注册入口或另行完成渠道集成与验收。</p><p>数据库不保存邀请码明文；生成后只展示一次。更换 WordPress 盐值会使未使用的旧邀请码失效。此功能不修改“设置 → 常规 → 任何人都可以注册”。</p>';
    if (is_multisite()) { echo '<div class="notice notice-warning"><p>多站点环境暂不启用本模块，请使用相应网络注册管理方案。</p></div>'; }
    if (!wuai_sluyu_invitation_storage_ready()) { echo '<div class="notice notice-error"><p>邀请码表尚未创建或缺少字段。请先完成主题存储升级；本页面不会自动迁移数据库。</p></div>'; }
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wuai_invitation_settings">'; wp_nonce_field('wuai_invitation_settings');
    echo '<p><label><input type="checkbox" name="enabled" value="1" '.checked($settings['enabled'],true,false).'> 启用邀请码生成及原生注册校验</label></p><p><label><input type="checkbox" name="required" value="1" '.checked($settings['required'],true,false).'> WordPress 原生注册必须填写邀请码（默认不强制）</label></p><p><label>新码有效天数 <input type="number" name="days" min="1" max="30" value="'.$settings['days'].'"></label></p>'; submit_button('保存设置'); echo '</form><hr><h2>为用户生成邀请码</h2><p>生成结果仅在本次回执显示，请立即复制并通过私下渠道交给用户。后台每位受领用户每天最多生成 50 个，不提供无上限批量导出。</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wuai_invitation_admin_operation"><input type="hidden" name="operation" value="generate">'; wp_nonce_field('wuai_invitation_admin');
    echo '<label>受领用户 ID <input name="owner_id" type="number" min="1" value="'.get_current_user_id().'" required></label>'; submit_button('生成并显示一次明文','secondary'); echo '</form><hr><h2>记录与挂起核验</h2><p>挂起记录不会自动恢复可用，避免账号已创建但认领写入失败造成重复使用。至少挂起 10 分钟后，可填原注册用户名和邮箱核验已有账号；不填身份则永久撤销旧码。两种操作都不会删除账号或恢复旧码。</p>';
    $page=wuai_sluyu_page_number('invite_pg'); $records=wuai_sluyu_invitation_records(get_current_user_id(),$page,true); $labels=wuai_sluyu_invitation_labels();
    echo '<table class="widefat striped"><thead><tr><th>ID / 尾号</th><th>受领用户</th><th>状态</th><th>有效期</th><th>操作</th></tr></thead><tbody>';
    foreach ($records['items'] as $row) {
        echo '<tr><td>'.absint($row->id).' / '.esc_html($row->code_hint).'</td><td>'.absint($row->owner_id).'</td><td>'.esc_html($labels[wuai_sluyu_invitation_status($row)]??'待核验').($row->status==='claimed'?' · 注册用户 '.absint($row->claimed_by):'').'</td><td>'.esc_html(wp_date('Y-m-d H:i',$row->expires_at)).'</td><td>';
        if (in_array($row->status,['active','reserved'],true)) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wuai_invitation_admin_operation"><input type="hidden" name="operation" value="'.($row->status==='reserved'?'recover':'revoke').'"><input type="hidden" name="id" value="'.absint($row->id).'">'; wp_nonce_field('wuai_invitation_admin');
            if ($row->status==='reserved') { echo '<label>原注册用户名 <input name="applicant_login" autocomplete="off"></label> <label>原注册邮箱 <input type="email" name="applicant_email" autocomplete="off"></label><p>填写身份：认领核验；留空：撤销挂起码，不恢复可用。</p>'; }
            submit_button($row->status==='reserved'?'执行挂起核验 / 撤销':'撤销未使用码','secondary','submit',false); echo '</form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    if ($records['total']>10) { echo '<div class="tablenav-pages">'.wp_kses_post(paginate_links(['base'=>add_query_arg('invite_pg','%#%',admin_url('admin.php?page=wuai-sluyu-invitations')),'format'=>'','current'=>$page,'total'=>ceil($records['total']/10)])).'</div>'; }
    echo '</div>';
}
add_action('admin_post_wuai_invitation_settings',function(){
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { wp_die('请使用表单提交','',['response'=>405]); }
    if (!current_user_can('manage_options') || wuai_sluyu_banned(get_current_user_id())) { wp_die('无权操作','',['response'=>403]); }
    check_admin_referer('wuai_invitation_settings');
    update_option('wuai_sluyu_invitation_settings',['enabled'=>wuai_sluyu_input('enabled')==='1','required'=>wuai_sluyu_input('required')==='1','days'=>max(1,min(30,absint(wuai_sluyu_input('days'))))],false);
    wp_safe_redirect(admin_url('admin.php?page=wuai-sluyu-invitations')); exit;
});
add_action('admin_post_wuai_invitation_admin_operation',function(){
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { wp_die('请使用表单提交','',['response'=>405]); }
    if (!current_user_can('manage_options') || wuai_sluyu_banned(get_current_user_id())) { wp_die('无权操作','',['response'=>403]); }
    check_admin_referer('wuai_invitation_admin'); nocache_headers(); if(!headers_sent())header('Referrer-Policy: no-referrer');
    $operation=wuai_sluyu_input('operation');
    if ($operation==='generate') { $result=wuai_sluyu_invitation_issue(absint(wuai_sluyu_input('owner_id'))); }
    elseif ($operation==='revoke') { $result=wuai_sluyu_invitation_revoke(absint(wuai_sluyu_input('id'))); }
    elseif ($operation==='recover') { $result=wuai_sluyu_invitation_recover(absint(wuai_sluyu_input('id')),wuai_sluyu_input('applicant_login'),wuai_sluyu_input('applicant_email')); }
    else { $result=new WP_Error('invite_operation','未知操作。'); }
    $html=is_wp_error($result)?'<p>'.esc_html($result->get_error_message()).'</p>':(isset($result['code'])?'<h2>邀请码仅显示一次</h2><p><code style="font-size:20px;overflow-wrap:anywhere">'.esc_html($result['code']).'</code></p><p>请立即复制并私下保存。有效至 '.esc_html(wp_date('Y-m-d H:i',$result['expires_at'])).'。刷新此 POST 回执可能生成另一个新码。</p>':'<p>'.esc_html($result['message']).'</p>');
    $html.='<p><a href="'.esc_url(admin_url('admin.php?page=wuai-sluyu-invitations')).'">返回邀请码管理</a></p>';
    wp_die($html,'邀请码操作回执',['response'=>is_wp_error($result)?400:200]);
});

add_action('deleted_user',function($uid){
    if (!wuai_sluyu_invitation_storage_ready()) { return; }
    global $wpdb; $table=wuai_sluyu_table('invites');
    $wpdb->query($wpdb->prepare("UPDATE $table SET status=CASE WHEN status IN ('active','reserved') THEN 'revoked' ELSE status END,owner_id=0,reserve_token='',reserve_fingerprint='',reserved_at=0 WHERE owner_id=%d",$uid));
    $wpdb->update($table,['claimed_by'=>0],['claimed_by'=>$uid]);
});

add_filter('wp_privacy_personal_data_exporters',function($exporters){$exporters['wuai-invitations']=['exporter_friendly_name'=>'森鹿语邀请码记录','callback'=>'wuai_sluyu_invitation_export'];return $exporters;});
function wuai_sluyu_invitation_export($email,$page=1) {
    $user=get_user_by('email',$email); if(!$user || !wuai_sluyu_invitation_storage_ready())return ['data'=>[],'done'=>true];
    global $wpdb; $table=wuai_sluyu_table('invites'); $offset=(max(1,absint($page))-1)*100;
    $rows=$wpdb->get_results($wpdb->prepare("SELECT id,owner_id,code_hint,status,created_at,expires_at,claimed_at FROM $table WHERE owner_id=%d OR claimed_by=%d ORDER BY id LIMIT 100 OFFSET %d",$user->ID,$user->ID,$offset)); $data=[];
    foreach($rows as $row)$data[]=['group_id'=>'wuai-invitations','group_label'=>'邀请码记录','item_id'=>'wuai-invite-'.$row->id,'data'=>[['name'=>'身份','value'=>(int)$row->owner_id===(int)$user->ID?'邀请码受领人':'注册使用人'],['name'=>'尾号','value'=>$row->code_hint],['name'=>'状态','value'=>wuai_sluyu_invitation_status($row)],['name'=>'创建时间','value'=>$row->created_at],['name'=>'有效至','value'=>wp_date('Y-m-d H:i',$row->expires_at)],['name'=>'使用时间','value'=>$row->claimed_at?:'']]];
    return ['data'=>$data,'done'=>count($rows)<100];
}
add_filter('wp_privacy_personal_data_erasers',function($erasers){$erasers['wuai-invitations']=['eraser_friendly_name'=>'森鹿语邀请码记录','callback'=>'wuai_sluyu_invitation_erase'];return $erasers;});
function wuai_sluyu_invitation_erase($email,$page=1) {
    $user=get_user_by('email',$email); if(!$user || !wuai_sluyu_invitation_storage_ready())return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
    global $wpdb; $table=wuai_sluyu_table('invites');
    $removed=$wpdb->query($wpdb->prepare("UPDATE $table SET status='revoked' WHERE owner_id=%d AND status='active'",$user->ID));
    $retained=(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE owner_id=%d OR claimed_by=%d LIMIT 1",$user->ID,$user->ID));
    return ['items_removed'=>(bool)$removed,'items_retained'=>$retained,'messages'=>$retained?['未使用的邀请码已撤销。尾号、生成数量与一次性使用凭证暂保留，以防重复使用及绕过每日生成限制；邀请码明文从未存储。删除账号时移除关联用户 ID，已使用的哈希仍保留以禁止重用。']:[],'done'=>true];
}
