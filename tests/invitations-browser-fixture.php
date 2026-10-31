<?php
// CLI-only browser fixture. The generated MU plugin stays outside the theme.
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('WP_QA_ROOT');if(!$root || !is_file($root.'/wp-load.php'))throw new RuntimeException('WP_QA_ROOT required.');
$_SERVER['HTTP_HOST']='127.0.0.1:8771';$_SERVER['REQUEST_URI']='/';require $root.'/wp-load.php';
if(home_url()!=='http://127.0.0.1:8771')throw new RuntimeException('Local QA only.');
if(!function_exists('wuai_sluyu_invitation_issue') || !wuai_sluyu_invitation_storage_ready())throw new RuntimeException('Integrate invitation schema/module first.');
function invite_browser_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$root_path=realpath($root);$wp_path=realpath(ABSPATH);
invite_browser_check($root_path && $wp_path && strcasecmp($root_path,$wp_path)===0,'Resolved QA installation must match loaded WordPress.');
$output=getenv('WP_QA_OUTPUT')?:dirname($root);if(!is_dir($output))wp_mkdir_p($output);
$mu_dir=WP_CONTENT_DIR.'/mu-plugins';if(!is_dir($mu_dir))wp_mkdir_p($mu_dir);
$mu_file=$mu_dir.'/wuai-invitation-browser-qa.php';$marker='WUAI_INVITATION_BROWSER_QA_V1';
if(is_file($mu_file) && strpos(file_get_contents($mu_file),$marker)===false)throw new RuntimeException('Refusing to replace an unrelated MU plugin.');
$plugin=<<<'PHP'
<?php
// WUAI_INVITATION_BROWSER_QA_V1: disposable 127.0.0.1:8771 only; never ship.
if(!defined('ABSPATH') || !defined('WUAI_INVITATION_QA_ROOT'))return;
if(strcasecmp((string)realpath(ABSPATH),WUAI_INVITATION_QA_ROOT)!==0 || home_url()!=='http://127.0.0.1:8771')return;
if(PHP_SAPI!=='cli' && !in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true))return;
add_filter('pre_wp_mail',function($return,$atts){
    $key='wuai_invitation_qa_mail_count';update_option($key,(int)get_option($key,0)+1,false);
    return true;
},PHP_INT_MAX,2);
add_action('login_init',function(){if(!headers_sent())header('X-Wuai-QA-Mail-Blocked: 1');});
add_action('user_register',function($uid){
    $user=get_userdata($uid);if($user && strpos($user->user_login,'qa_inv_browser_reg_')===0 && preg_match('/@example\.test$/i',$user->user_email))wp_set_password('qa-local-only',$uid);
},PHP_INT_MAX-1);
add_filter('pre_user_email',function($email){
    $input=isset($_POST['user_email']) && is_string($_POST['user_email'])?wp_unslash($_POST['user_email']):'';
    if($email===$input && strpos($email,'qa_inv_browser_reg_mutated_')===0 && preg_match('/@example\.test$/i',$email))return str_replace('@example.test','+filtered@example.test',$email);
    return $email;
});
add_action('wp_ajax_wuai_invitation_qa_state',function(){
    if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Admin only'],403);
    check_ajax_referer('wuai_invitation_qa');
    global $wpdb;$table=wuai_sluyu_table('invites');
    $prefix='qa_inv_browser_reg_';$users=$wpdb->get_results($wpdb->prepare("SELECT ID,user_login,user_email FROM $wpdb->users WHERE user_login LIKE %s",$wpdb->esc_like($prefix).'%'));
    $rows=$wpdb->get_results("SELECT i.id,i.code_hint,i.owner_id,i.claimed_by,i.status,i.reserved_at FROM $table i INNER JOIN $wpdb->users u ON u.ID=i.owner_id WHERE u.user_login IN ('qa_inv_browser_owner','qa_inv_browser_seed','qa_inv_browser_other','qa_inv_browser_admin') ORDER BY i.id DESC LIMIT 200");
    wp_send_json_success(['mail_blocked'=>(int)get_option('wuai_invitation_qa_mail_count',0),'users'=>$users,'invites'=>$rows,'registered'=>(int)get_option('users_can_register')]);
});
add_action('admin_notices',function(){if(current_user_can('manage_options'))echo '<input type="hidden" id="wuai-invitation-qa-nonce" value="'.esc_attr(wp_create_nonce('wuai_invitation_qa')).'">';});
PHP;
$plugin=str_replace("if(!defined('ABSPATH') || !defined('WUAI_INVITATION_QA_ROOT'))return;","if(!defined('ABSPATH'))return;\ndefine('WUAI_INVITATION_QA_ROOT',".var_export($root_path,true).");",$plugin);
invite_browser_check(file_put_contents($mu_file,$plugin)!==false,'QA mail-blocker MU plugin written outside theme.');
$mail_block=function(){return true;};add_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);
$old_user=get_current_user_id();$old_post=$_POST;$users=[];$codes=[];
try{
    $_POST=[];require_once ABSPATH.'wp-admin/includes/user.php';
    $test_users=$wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->users WHERE user_login LIKE %s",$wpdb->esc_like('qa_inv_browser_reg_').'%'));
    foreach($test_users as $id)wp_delete_user($id);
    foreach(['owner','seed','other','admin','banned'] as $name){
        $login='qa_inv_browser_'.$name;$user=get_user_by('login',$login);$id=$user?$user->ID:wp_create_user($login,'qa-local-only',$login.'@example.test');
        invite_browser_check(!is_wp_error($id),'Fixture user created.');(new WP_User($id))->set_role(in_array($name,['admin','banned'],true)?'administrator':'subscriber');
        wp_update_user(['ID'=>$id,'display_name'=>'Invitation Browser '.ucfirst($name),'user_pass'=>'qa-local-only']);
        delete_user_meta($id,'_wuai_banned_until');$users[$name]=(int)$id;
        if($name==='banned')update_user_meta($id,'_wuai_banned_until',time()+DAY_IN_SECONDS);
        $wpdb->delete(wuai_sluyu_table('invites'),['owner_id'=>$id]);
    }
    update_option('wuai_sluyu_invitation_settings',['enabled'=>true,'required'=>false,'days'=>7]);update_option('users_can_register',1);update_option('wuai_invitation_qa_mail_count',0,false);
    wp_set_current_user($users['admin']);
    foreach(['valid','expired','revoked','mutated'] as $key){
        $record=wuai_sluyu_invitation_issue($users['seed']);invite_browser_check(!is_wp_error($record),'Seed invite generated.');$codes[$key]=$record;
        if($key==='expired')$wpdb->update(wuai_sluyu_table('invites'),['expires_at'=>time()-60],['id'=>$record['id']]);
        if($key==='revoked')wuai_sluyu_invitation_revoke($record['id']);
    }
    $manifest=['users'=>$users,'codes'=>$codes,'login_prefix'=>'qa_inv_browser_','mu_plugin'=>$mu_file];
    file_put_contents(rtrim($output,'/\\').'/invitations-fixture.json',wp_json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo "PASS: invitation browser fixture prepared; localhost-only MU plugin blocks all mail and test-only account passwords. No real email sent.\n";
}finally{remove_filter('pre_wp_mail',$mail_block,PHP_INT_MAX);$_POST=$old_post;wp_set_current_user($old_user);}
