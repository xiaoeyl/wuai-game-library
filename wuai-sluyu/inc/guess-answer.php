<?php
if (!defined('ABSPATH')) { exit; }

// This is a low-stakes community puzzle, not access control for valuable secrets.
// Choices are public and can be tried; trusted plugins/database operators are not isolated.
function wuai_sluyu_guess_key() { return '_wuai_guess_config'; }
function wuai_sluyu_guess_supported($id) { return is_scalar($id) && absint($id)>0 && get_post_type(absint($id))==='sluyu_topic' && get_post_meta(absint($id),'_wuai_topic_kind',true)==='guess'; }
function wuai_sluyu_guess_prompt($id) {
    $post=get_post($id); return $post?hash('sha256',wp_json_encode([$post->post_title,$post->post_content,$post->post_excerpt])):'';
}
function wuai_sluyu_guess_hash($version,$index) { return hash_hmac('sha256','guess|'.$version.'|'.$index,wp_salt('auth')); }
function wuai_sluyu_guess_html($value) { return function_exists('wuai_sluyu_protected_html')?wuai_sluyu_protected_html($value):''; }
function wuai_sluyu_guess_stamp($id) { return hash_hmac('sha256',absint($id).'|'.wp_json_encode(get_post_meta($id,wuai_sluyu_guess_key(),true)),wp_salt('nonce')); }

function wuai_sluyu_guess_config($id,$require_prompt=true) {
    if (!wuai_sluyu_guess_supported($id)) { return false; }
    $data=get_post_meta($id,wuai_sluyu_guess_key(),true);
    if (!is_array($data) || !isset($data['choices'],$data['answer_hash'],$data['body'],$data['version'],$data['prompt_hash']) || !is_array($data['choices']) || !is_string($data['answer_hash']) || !is_string($data['body']) || !is_string($data['version']) || !is_string($data['prompt_hash'])) { return false; }
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$data['version']) || !preg_match('/^[a-f0-9]{64}$/D',$data['answer_hash']) || !preg_match('/^[a-f0-9]{64}$/D',$data['prompt_hash'])) { return false; }
    $choices=$data['choices']; if(count($choices)<2 || count($choices)>8 || array_keys($choices)!==range(0,count($choices)-1))return false;
    foreach($choices as $choice) { if(!is_string($choice) || trim($choice)==='' || mb_strlen($choice)>100 || sanitize_text_field($choice)!==$choice)return false; }
    if(count(array_unique($choices))!==count($choices))return false;
    $body=wuai_sluyu_guess_html($data['body']);
    if(mb_strlen(trim(wp_strip_all_tags($body)))<5 || mb_strlen($body)>30000 || strlen($body)>200000)return false;
    if($require_prompt && !hash_equals($data['prompt_hash'],wuai_sluyu_guess_prompt($id)))return false;
    $matches=0;foreach(array_keys($choices) as $index){if(hash_equals($data['answer_hash'],wuai_sluyu_guess_hash($data['version'],$index)))$matches++;}
    if($matches!==1)return false;
    return ['choices'=>$choices,'answer_hash'=>$data['answer_hash'],'body'=>$body,'version'=>$data['version'],'prompt_hash'=>$data['prompt_hash']];
}

function wuai_sluyu_guess_validate($input) {
    if(!is_array($input) || !isset($input['guess_choices'],$input['guess_correct'],$input['guess_body']) || !is_string($input['guess_choices']) || !is_string($input['guess_correct']) || !is_string($input['guess_body']))return new WP_Error('guess_fields','猜答案字段格式不正确。');
    if(mb_strlen($input['guess_choices'])>808 || strlen($input['guess_choices'])>4000)return new WP_Error('guess_choices','选项内容过长，每项最多 100 字，最多 8 项。');
    $lines=explode("\n",trim($input['guess_choices']));$choices=[];
    foreach($lines as $line){$value=sanitize_text_field(trim($line));if($value==='')return new WP_Error('guess_choices','选项中间不能有空行，以免正确答案序号发生错位。');$choices[]=$value;}
    if(count($choices)<2 || count($choices)>8 || count(array_unique($choices))!==count($choices) || max(array_map('mb_strlen',$choices))>100)return new WP_Error('guess_choices','请填写 2–8 个不同选项，每项最多 100 字，不要留空项。');
    $correct=$input['guess_correct'];
    if(!preg_match('/^[1-8]$/D',$correct) || (int)$correct>count($choices))return new WP_Error('guess_answer','正确答案序号必须对应一个有效选项（从 1 开始）。');
    $body=wuai_sluyu_guess_html($input['guess_body']);
    if(mb_strlen($input['guess_body'])>30000 || mb_strlen(trim(wp_strip_all_tags($body)))<5 || mb_strlen($body)>30000 || strlen($body)>200000)return new WP_Error('guess_body','猜对后片段至少 5 字、最多 30000 字，仅支持安全基础 HTML。');
    return ['choices'=>$choices,'answer_index'=>(int)$correct-1,'body'=>$body];
}

function wuai_sluyu_guess_build($id,$fields,$existing=false) {
    $prompt=wuai_sluyu_guess_prompt($id);
    if($existing && $existing['choices']===$fields['choices'] && $existing['body']===$fields['body'] && $existing['prompt_hash']===$prompt && hash_equals($existing['answer_hash'],wuai_sluyu_guess_hash($existing['version'],$fields['answer_index'])))return $existing;
    $version=wp_generate_uuid4();
    return ['choices'=>$fields['choices'],'answer_hash'=>wuai_sluyu_guess_hash($version,$fields['answer_index']),'body'=>$fields['body'],'version'=>$version,'prompt_hash'=>$prompt];
}
function wuai_sluyu_guess_event_key($id,$config) { return 'guess:'.absint($id).':'.$config['version']; }
function wuai_sluyu_guess_solved($id,$uid,$config=null) {
    if(!$uid)return false;$config=$config?:wuai_sluyu_guess_config($id);if(!$config)return false;
    global $wpdb;return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.wuai_sluyu_table('events')." WHERE user_id=%d AND event_key=%s AND event_type='guess' AND target_id=%d AND points=0 LIMIT 1",$uid,wuai_sluyu_guess_event_key($id,$config),$id));
}
function wuai_sluyu_guess_attempts($id,$uid,$version) {
    $key='_wuai_guess_attempts_'.absint($id);$data=get_user_meta($uid,$key,true);
    if(!metadata_exists('user',$uid,$key))return ['version'=>$version,'failures'=>0,'last_error'=>0];
    if(!is_array($data) || !isset($data['version'],$data['failures'],$data['last_error']) || !is_string($data['version']) || !is_int($data['failures']) || !is_int($data['last_error']) || $data['failures']<0 || $data['failures']>5 || $data['last_error']<0)return false;
    return $data['version']===$version?$data:['version'=>$version,'failures'=>0,'last_error'=>0];
}
function wuai_sluyu_guess_eligible($id,$uid) {
    if(!$uid || !get_userdata($uid) || wuai_sluyu_banned($uid))return false;
    $groups=wp_get_object_terms($id,'sluyu_circle',['fields'=>'ids','wuai_include_pending'=>true]);
    if(is_wp_error($groups))return false;
    foreach($groups as $group){if(!wuai_sluyu_circle_can_post($group,$uid))return false;}
    return true;
}
function wuai_sluyu_guess_state($id,$uid=null,$preview=false) {
    $uid=$uid===null?get_current_user_id():absint($uid);$post=get_post($id);$config=wuai_sluyu_guess_config($id);
    $state=['enabled'=>wuai_sluyu_guess_supported($id),'allowed'=>false,'solved'=>false,'preview'=>false,'reason'=>'unavailable','remaining'=>0,'wait'=>0];
    if(function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($id))return $state;
    if(!$post || !$config || $post->post_password!=='' || ($uid && (!get_userdata($uid) || wuai_sluyu_banned($uid))))return $state;
    $trusted=$uid && ((int)$post->post_author===$uid || user_can($uid,'edit_others_posts'));
    if($post->post_status!=='publish'){
        if($preview && $trusted && in_array($post->post_status,['pending','draft','private','future'],true)){ $state['allowed']=true;$state['preview']=true;$state['reason']='owner'; }
        return $state;
    }
    if(!$uid){$state['reason']='login';return $state;}
    if($trusted){$state['allowed']=true;$state['preview']=true;$state['reason']='owner';return $state;}
    if(!wuai_sluyu_guess_eligible($id,$uid)){$state['reason']='circle';return $state;}
    if(wuai_sluyu_guess_solved($id,$uid,$config)){$state['allowed']=true;$state['solved']=true;$state['reason']='solved';return $state;}
    $attempts=wuai_sluyu_guess_attempts($id,$uid,$config['version']);if($attempts===false)return $state;
    $state['remaining']=max(0,5-$attempts['failures']);$state['wait']=max(0,30-(time()-$attempts['last_error']));
    $state['reason']=$state['remaining']<1?'limit':($state['wait']?'cooldown':'guess');return $state;
}

function wuai_sluyu_guess_answer($id,$choice,$version,$user_locked=false) {
    $uid=get_current_user_id();$id=absint($id);
    if(!$uid || !$id || !get_userdata($uid) || wuai_sluyu_banned($uid))return new WP_Error('guess_auth','请使用有效的登录账号作答。');
    $user_lock=$user_locked?false:wuai_sluyu_lock('user_'.$uid);if(!$user_locked && !$user_lock)return new WP_Error('guess_busy','账号正在操作，请稍后重试。');
    $post_lock=false;
    try{
        $post_lock=wuai_sluyu_lock('poll_'.$id);if(!$post_lock)return new WP_Error('guess_busy','题目正在更新，请刷新后重试。');
        clean_user_cache($uid);clean_post_cache($id);$post=get_post($id);$config=wuai_sluyu_guess_config($id);
        if(function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($id))return new WP_Error('guess_held','题目正在核验，暂不能作答。');
        if(!$post || !$config || $post->post_status!=='publish' || $post->post_password!=='' || !wuai_sluyu_guess_eligible($id,$uid))return new WP_Error('guess_unavailable','题目暂不能作答，请核对账号、圈子权限及发布状态。');
        if(!is_string($version) || !hash_equals($config['version'],$version))return new WP_Error('guess_version','题目版本已变化，请刷新页面后再作答。');
        if(!is_string($choice) || !preg_match('/^[0-7]$/D',$choice) || !isset($config['choices'][(int)$choice]))return new WP_Error('guess_choice','请选择一个有效选项。');
        if(wuai_sluyu_guess_solved($id,$uid,$config))return ['message'=>'你已经答对当前版本，不会重复记录或扣分。','reload'=>true,'solved'=>true];
        if((int)$post->post_author===$uid || user_can($uid,'edit_others_posts'))return ['message'=>'作者与站点编辑可预览，无需作答；未创建答题记录。','reload'=>true,'solved'=>false];
        $attempts=wuai_sluyu_guess_attempts($id,$uid,$config['version']);
        if($attempts===false)return new WP_Error('guess_storage','尝试记录暂不可验证，请联系管理员，不会授予访问权。');
        if($attempts['failures']>=5)return new WP_Error('guess_limit','本账号当前版本已答错 5 次，不能继续尝试。');
        $wait=30-(time()-$attempts['last_error']);if($wait>0)return new WP_Error('guess_cooldown','请在 '.$wait.' 秒后再尝试。');
        // Persist a provisional attempt before comparing the answer. A storage
        // failure must not become a free correctness oracle or bypass the limit.
        $next=['version'=>$config['version'],'failures'=>$attempts['failures']+1,'last_error'=>time()];
        update_user_meta($uid,'_wuai_guess_attempts_'.$id,$next);wp_cache_delete($uid,'user_meta');
        if(get_user_meta($uid,'_wuai_guess_attempts_'.$id,true)!==$next)return new WP_Error('guess_storage','尝试记录未完整保存，请稍后重试；内容仍不可见。');
        if(!hash_equals($config['answer_hash'],wuai_sluyu_guess_hash($config['version'],(int)$choice))){
            return new WP_Error('guess_wrong','这次没有猜对。请等待 30 秒；当前版本还可答错 '.max(0,5-$next['failures']).' 次。不会显示正确答案或隐藏片段。');
        }
        wuai_sluyu_event($uid,wuai_sluyu_guess_event_key($id,$config),'guess',0,$id,'猜对当前题目版本；不涉及积分或现金。');
        if(!wuai_sluyu_guess_solved($id,$uid,$config)){
            update_user_meta($uid,'_wuai_guess_attempts_'.$id,$attempts);wp_cache_delete($uid,'user_meta');
            $restored=get_user_meta($uid,'_wuai_guess_attempts_'.$id,true)===$attempts;
            return new WP_Error('guess_storage',$restored?'答题结果未保存，尝试额度已恢复；请刷新核对后重试。':'答题结果未保存，尝试记录仍待核验；请联系管理员，尚未授予访问权。');
        }
        update_user_meta($uid,'_wuai_guess_attempts_'.$id,$attempts);
        return ['message'=>'恭喜猜对！刷新后查看当前版本片段，不扣除积分。','reload'=>true,'solved'=>true];
    }finally{wuai_sluyu_unlock($post_lock);wuai_sluyu_unlock($user_lock);}
}

function wuai_sluyu_guess_submit($uid,$user_locked=false) {
    if(!$uid || (int)$uid!==get_current_user_id() || wuai_sluyu_banned($uid))return new WP_Error('guess_auth','请使用当前有效账号投稿。');
    $lock=$user_locked?false:wuai_sluyu_lock('user_'.$uid);if(!$user_locked && !$lock)return new WP_Error('guess_busy','账号正在操作，请稍后重试。');
    try{
        if(get_transient('wuai_guess_submit_'.$uid))return new WP_Error('guess_rate','提交过于频繁，请稍后再试。');
        $raw=[];foreach(['guess_choices','guess_correct','guess_body'] as $key)$raw[$key]=isset($_POST[$key]) && is_string($_POST[$key])?wp_unslash($_POST[$key]):null;
        $fields=wuai_sluyu_guess_validate($raw);if(is_wp_error($fields))return $fields;
        $title=wuai_sluyu_input('title');$body=wuai_sluyu_input('body',true);
        if(mb_strlen($title)<2 || mb_strlen($title)>120 || mb_strlen($body)<5 || mb_strlen($body)>20000)return new WP_Error('guess_prompt','请填写 2–120 字标题和 5–20000 字公开谜面。');
        $group=isset($_POST['group']) && is_string($_POST['group'])?wp_unslash($_POST['group']):(!isset($_POST['group'])?'0':'');
        if(!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$group) || ((int)$group && !wuai_sluyu_circle_can_post((int)$group,$uid)))return new WP_Error('guess_circle','所选圈子不可发布，不会自动转为未分组投稿。');
        $id=wp_insert_post(wp_slash(['post_type'=>'sluyu_topic','post_status'=>'draft','post_author'=>$uid,'post_title'=>$title,'post_content'=>$body,'comment_status'=>'open','ping_status'=>'closed','meta_input'=>['_wuai_topic_kind'=>'guess']]),true);
        if(is_wp_error($id) || !$id)return is_wp_error($id)?$id:new WP_Error('guess_storage','稿件未创建，请重试。');
        set_transient('wuai_guess_submit_'.$uid,1,20);
        if(get_post_meta($id,'_wuai_topic_kind',true)!=='guess')return new WP_Error('guess_partial','稿件 #'.$id.' 的题型未完整保存，暂留草稿，请保留表单并联系管理员。');
        $config=wuai_sluyu_guess_build($id,$fields);
        update_post_meta($id,wuai_sluyu_guess_key(),wp_slash($config));wp_cache_delete($id,'post_meta');
        if(get_post_meta($id,wuai_sluyu_guess_key(),true)!==$config || !wuai_sluyu_guess_config($id))return new WP_Error('guess_partial','稿件 #'.$id.' 的私有题目信息未完整保存，暂留草稿，不会公开。');
        if((int)$group){
            $assigned=wp_set_object_terms($id,[(int)$group],'sluyu_circle');$saved=wp_get_object_terms($id,'sluyu_circle',['fields'=>'ids','wuai_include_pending'=>true]);
            if(is_wp_error($assigned) || is_wp_error($saved) || array_map('intval',$saved)!==[(int)$group] || !wuai_sluyu_circle_can_post((int)$group,$uid))return new WP_Error('guess_group','稿件 #'.$id.' 未确认保存到所选圈子，暂留草稿，不会自动改为公开广场。');
        }
        $queued=wp_update_post(['ID'=>$id,'post_status'=>'pending'],true);
        if(is_wp_error($queued) || get_post_status($id)!=='pending' || !wuai_sluyu_guess_config($id)){wp_update_post(['ID'=>$id,'post_status'=>'draft']);return new WP_Error('guess_queue','稿件 #'.$id.' 未完整进入审核队列，请保留表单并联系管理员。');}
        if((int)$group){$confirmed=wp_get_object_terms($id,'sluyu_circle',['fields'=>'ids','wuai_include_pending'=>true]);if(is_wp_error($confirmed) || array_map('intval',$confirmed)!==[(int)$group] || !wuai_sluyu_circle_can_post((int)$group,$uid)){wp_update_post(['ID'=>$id,'post_status'=>'draft']);return new WP_Error('guess_group','稿件 #'.$id.' 的圈子状态发生变化，暂留草稿，请联系管理员核对。');}}
        wuai_sluyu_notify($uid,'猜答案话题「'.$title.'」已提交审核，隐藏片段不进入公开谜面。');do_action('wuai_sluyu_after_submission',$id,'guess',$uid);
        return ['message'=>'猜答案话题已提交审核。谜面和选项公开；猜对后片段不等同高安全保密或付费资源保护。','reset'=>true];
    }finally{wuai_sluyu_unlock($lock);}
}
add_filter('wuai_sluyu_community_dispatch',function($result,$operation,$uid){
    if($result!==null)return $result;
    if($operation==='guess_submit' || ($operation==='submit' && wuai_sluyu_input('kind')==='guess'))return wuai_sluyu_guess_submit($uid,true);
    if($operation!=='guess_answer')return $result;
    if((int)$uid!==get_current_user_id())return new WP_Error('guess_auth','当前账号不一致。');
    return wuai_sluyu_guess_answer(wuai_sluyu_input('id'),isset($_POST['choice']) && is_string($_POST['choice'])?wp_unslash($_POST['choice']):null,wuai_sluyu_input('version'),true);
},35,3);

function wuai_sluyu_guess_save($id,$input) {
    $id=absint($id);$uid=get_current_user_id();
    if(!$uid || wuai_sluyu_banned($uid) || !current_user_can('edit_others_posts') || !current_user_can('edit_post',$id) || !wuai_sluyu_guess_supported($id))return new WP_Error('guess_editor','仅受信任的站点编辑可管理猜答案题目。');
    if(!is_array($input))return new WP_Error('guess_fields','题目信息格式不正确。');
    $lock=wuai_sluyu_lock('poll_'.$id);if(!$lock)return new WP_Error('guess_busy','题目正在更新，请稍后再试。');
    try{
        clean_post_cache($id);$stamp=$input['stamp']??'';
        if(!is_string($stamp) || !hash_equals(wuai_sluyu_guess_stamp($id),$stamp))return new WP_Error('guess_changed','题目设置已变化，请刷新后重新编辑。');
        $fields=wuai_sluyu_guess_validate($input);if(is_wp_error($fields))return $fields;
        $config=wuai_sluyu_guess_build($id,$fields,wuai_sluyu_guess_config($id,false));
        update_post_meta($id,wuai_sluyu_guess_key(),wp_slash($config));wp_cache_delete($id,'post_meta');
        if(get_post_meta($id,wuai_sluyu_guess_key(),true)!==$config || !wuai_sluyu_guess_config($id))return new WP_Error('guess_storage','题目配置未完整保存，请核对后重试。');
        return true;
    }finally{wuai_sluyu_unlock($lock);}
}
add_action('init',function(){register_post_meta('sluyu_topic',wuai_sluyu_guess_key(),['single'=>true,'type'=>'array','show_in_rest'=>false,'auth_callback'=>function($allowed,$key,$id,$uid){return $key===wuai_sluyu_guess_key() && $uid && !wuai_sluyu_banned($uid) && user_can($uid,'edit_others_posts') && user_can($uid,'edit_post',$id);}]);},31);
add_filter('wuai_sluyu_account_submission_permission',function($allowed,$post,$operation,$uid){
    if(is_wp_error($allowed) || !$allowed || $operation!=='edit_submission' || !wuai_sluyu_guess_supported($post->ID))return $allowed;
    return new WP_Error('guess_edit','猜答案话题由站点编辑管理题面、答案和版本；作者可撤回后联系管理员修改，不能绕过重新作答规则。');
},20,4);

function wuai_sluyu_guess_submission_fields() {
    echo '<label>公开选项（每行一项，2–8 项）<textarea name="guess_choices" rows="6" maxlength="808" required></textarea></label><label>正确选项序号（从 1 开始，私有保存）<input type="number" name="guess_correct" min="1" max="8" value="1" required></label><label>猜对后片段（仅基础 HTML，不执行短代码或嵌入）<textarea name="guess_body" rows="7" minlength="5" maxlength="30000" required></textarea></label>';
}
function wuai_sluyu_view_guess_submit() {
    if(!wuai_sluyu_require_login())return;$uid=get_current_user_id();$group=wuai_sluyu_query('group','0');
    $invalid_group=(isset($_GET['group']) && !is_scalar($_GET['group'])) || (isset($_GET['circle_id']) && !is_scalar($_GET['circle_id']));
    if($invalid_group || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$group) || ((int)$group && !wuai_sluyu_circle_can_post((int)$group,$uid))){wuai_sluyu_empty('所选圈子当前不可发布，不会自动转为未分组投稿。');return;}
    echo '<section class="sluyu-panel sluyu-guess-submit"><h2>出一道互动猜答案题</h2><p>标题、谜面、选项和评论公开。猜对后只向该账号展示独立片段；最多答错 5 次，每次错误后等待 30 秒。</p><p class="sluyu-notice">选项少时仍可通过尝试猜中，这不是高安全保密或付费资源权限系统。不要放密码、隐私或高价值秘密。没有积分下注、扣分、奖励或现金支付。</p>';
    wuai_sluyu_form_start('guess_submit');echo '<label>标题<input name="title" minlength="2" maxlength="120" required></label><label>公开谜面（不要写隐藏内容）<textarea name="body" rows="5" minlength="5" maxlength="20000" required></textarea></label>';
    wuai_sluyu_guess_submission_fields();echo '<label>所属圈子<select name="group"><option value="0">未分组</option>';
    $terms=get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false]);if(!is_wp_error($terms))foreach($terms as $term){if(wuai_sluyu_circle_can_post($term->term_id,$uid))echo '<option value="'.absint($term->term_id).'" '.selected((int)$group,$term->term_id,false).'>'.esc_html($term->name).'</option>';}
    echo '</select></label><p class="sluyu-muted">发布需审核。站点编辑修改谜面、选项、正确答案或隐藏片段会轮换版本；旧版本的答对记录不再解锁新版本，需重新作答。已看过的内容无法追回。</p>';wuai_sluyu_form_end('提交猜答案话题审核');echo '</section>';
}

function wuai_sluyu_guess_view($id,$preview=false) {
    if(!wuai_sluyu_guess_supported($id))return '';if(!headers_sent())nocache_headers();
    $state=wuai_sluyu_guess_state($id,null,$preview);$config=wuai_sluyu_guess_config($id);$post=get_post($id);
    $html='<section class="sluyu-panel sluyu-guess"><h2>猜答案 · 看隐藏片段</h2><p class="sluyu-muted">这是互动谜题，不是高安全保密系统或付费资源权限。选项少时仍可能猜中；不扣积分、不下注、不涉及现金。</p>';
    if($state['allowed'])return $html.'<p class="sluyu-state-pill">'.($state['preview']?'作者 / 编辑预览，未创建答题记录':'你已答对当前版本').'</p><div class="sluyu-guess-body">'.wuai_sluyu_guess_html(wpautop($config['body'])).'</div><p class="sluyu-muted">新题目版本需要重新作答；已经展示的内容无法追回。</p></section>';
    $messages=['unavailable'=>'题目未开放或配置正在核验，暂不显示片段。','login'=>'请登录后参与猜答案。','circle'=>'请先满足此题所属圈子的参与条件。','limit'=>'当前版本已答错 5 次，不能继续尝试。','cooldown'=>'请等待 '.$state['wait'].' 秒后再尝试。','guess'=>'请选择一个答案，猜对后才显示当前版本片段。'];
    $html.='<p>'.esc_html($messages[$state['reason']]??$messages['unavailable']).'</p>';
    if($state['reason']==='login')$html.='<a class="sluyu-action-primary" href="'.esc_url(wp_login_url(get_permalink($id))).'">登录后作答</a>';
    if($state['reason']==='circle')$html.='<a href="'.esc_url(wuai_sluyu_portal_url('circles')).'">查看圈子 / 加入条件</a>';
    if($config && in_array($state['reason'],['guess','cooldown'],true)){
        $html.='<form class="sluyu-community-form" data-operation="guess_answer"><div class="sluyu-form-status" role="status" aria-live="polite"></div><input type="hidden" name="id" value="'.absint($id).'"><input type="hidden" name="version" value="'.esc_attr($config['version']).'">';
        foreach($config['choices'] as $index=>$choice)$html.='<label class="sluyu-poll-choice"><input type="radio" name="choice" value="'.$index.'" required> '.esc_html($choice).'</label>';
        $html.='<p class="sluyu-muted">当前版本还可答错 '.$state['remaining'].' 次；每次错误后须等待 30 秒，不会提示正确答案。</p><button class="sluyu-action-primary" type="submit">提交答案</button></form>';
    }
    return $html.'</section>';
}
function wuai_sluyu_guess_render_context($id) {
    if((defined('REST_REQUEST') && REST_REQUEST) || !empty($GLOBALS['wuai_sluyu_guess_rest_depth']) || is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed())return false;
    foreach(['get_the_excerpt','the_excerpt','the_excerpt_rss','the_content_feed','get_the_content_feed'] as $hook){if(doing_filter($hook))return false;}
    return is_singular('sluyu_topic') && in_the_loop() && is_main_query() && (int)get_queried_object_id()===(int)$id;
}
add_filter('the_content',function($content){$id=get_the_ID();return $id && wuai_sluyu_guess_render_context($id)?$content.wuai_sluyu_guess_view($id,is_preview()):$content;},100);
add_action('template_redirect',function(){if(is_singular('sluyu_topic') && (wuai_sluyu_guess_supported(get_queried_object_id()) || metadata_exists('post',get_queried_object_id(),wuai_sluyu_guess_key())))nocache_headers();},1);
add_filter('rest_request_before_callbacks',function($response){$GLOBALS['wuai_sluyu_guess_rest_depth']=(int)($GLOBALS['wuai_sluyu_guess_rest_depth']??0)+1;return $response;},-1001);
add_filter('rest_request_after_callbacks',function($response){$GLOBALS['wuai_sluyu_guess_rest_depth']=max(0,(int)($GLOBALS['wuai_sluyu_guess_rest_depth']??0)-1);return $response;},1001);
add_filter('rest_prepare_sluyu_topic',function($response){if($response instanceof WP_REST_Response){$data=$response->get_data();if(isset($data['meta']) && is_array($data['meta']))unset($data['meta'][wuai_sluyu_guess_key()]);$response->set_data($data);}return $response;},100);

function wuai_sluyu_guess_admin_box($post) {
    if(!current_user_can('edit_others_posts'))return;
    if(!wuai_sluyu_guess_supported($post->ID)){echo '<p>仅用于“猜答案”题型。请从前台发布中心提交猜答案话题，再在这里审核；普通话题不会自动转换或迁移正文。</p>';return;}
    $config=wuai_sluyu_guess_config($post->ID,false);$answer=1;
    if($config)foreach(array_keys($config['choices']) as $index){if(hash_equals($config['answer_hash'],wuai_sluyu_guess_hash($config['version'],$index)))$answer=$index+1;}
    wp_nonce_field('wuai_guess_admin','wuai_guess_nonce');
    echo '<input type="hidden" name="wuai_guess_stamp" value="'.esc_attr(wuai_sluyu_guess_stamp($post->ID)).'"><p><strong>主编辑器只写公开谜面。</strong>这里的答案校验与片段仅存私有元数据，不加入 REST、搜索、摘要、订阅源或历史正文。不得用来保护密码或替代付费资源权限。</p><p>任何实际题面、选项、答案或片段修改都会生成新版本，旧解锁失效并重置新版本尝试次数；保存无变化不会轮换。旧内容已看过或被缓存的副本无法追回。</p><label>公开选项（每行一个，2–8 个）<textarea class="widefat" name="wuai_guess_choices" rows="6" maxlength="808">'.esc_textarea($config?implode("\n",$config['choices']):'').'</textarea></label><p><label>正确答案序号 <input type="number" name="wuai_guess_correct" min="1" max="8" value="'.$answer.'"></label></p><label>猜对后片段（基础安全 HTML，禁短代码／图片／脚本）<textarea class="widefat" name="wuai_guess_body" rows="10" maxlength="30000">'.esc_textarea($config?$config['body']:'').'</textarea></label><p>最多答错 5 次，每次错误后 30 秒冷却。不扣分、不奖励、不下注。可信插件、编辑、数据库管理员及 CDN 配置在主题隔离边界之外。</p>';
}
add_action('add_meta_boxes',function(){if(current_user_can('edit_others_posts'))add_meta_box('wuai-guess-answer','猜答案设置（私有答案与片段）','wuai_sluyu_guess_admin_box','sluyu_topic','normal');});
add_action('save_post',function($id){
    if(wp_is_post_revision($id) || wp_is_post_autosave($id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !wp_verify_nonce(wuai_sluyu_input('wuai_guess_nonce'),'wuai_guess_admin'))return;
    $input=['stamp'=>wuai_sluyu_input('wuai_guess_stamp')];foreach(['choices','correct','body'] as $key)$input['guess_'.$key]=isset($_POST['wuai_guess_'.$key]) && is_string($_POST['wuai_guess_'.$key])?wp_unslash($_POST['wuai_guess_'.$key]):null;
    $result=wuai_sluyu_guess_save($id,$input);if(is_wp_error($result))set_transient('wuai_guess_notice_'.get_current_user_id(),$result->get_error_message(),60);
},60);
add_action('admin_notices',function(){if(!current_user_can('edit_others_posts'))return;$key='wuai_guess_notice_'.get_current_user_id();$message=get_transient($key);if($message){delete_transient($key);echo '<div class="notice notice-error"><p>猜答案设置：'.esc_html($message).'</p></div>';}});

add_action('deleted_post',function($id){global $wpdb;delete_metadata('user',0,'_wuai_guess_attempts_'.absint($id),'',true);$wpdb->delete(wuai_sluyu_table('events'),['event_type'=>'guess','target_id'=>absint($id)]);});
add_filter('wp_privacy_personal_data_exporters',function($exporters){$exporters['wuai-guesses']=['exporter_friendly_name'=>'猜答案尝试记录','callback'=>'wuai_sluyu_guess_export'];return $exporters;});
function wuai_sluyu_guess_export($email,$page=1) {
    $user=get_user_by('email',$email);if(!$user)return ['data'=>[],'done'=>true];
    global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT umeta_id,meta_key,meta_value FROM $wpdb->usermeta WHERE user_id=%d AND meta_key LIKE %s ORDER BY umeta_id LIMIT 100 OFFSET %d",$user->ID,$wpdb->esc_like('_wuai_guess_attempts_').'%',(max(1,absint($page))-1)*100));$data=[];
    foreach($rows as $row){$record=maybe_unserialize($row->meta_value);if(!is_array($record))continue;$data[]=['group_id'=>'wuai-guesses','group_label'=>'猜答案尝试记录','item_id'=>'wuai-guess-attempt-'.$row->umeta_id,'data'=>[['name'=>'题目 ID','value'=>substr($row->meta_key,strlen('_wuai_guess_attempts_'))],['name'=>'版本','value'=>isset($record['version']) && is_string($record['version'])?$record['version']:''],['name'=>'失败次数','value'=>isset($record['failures']) && is_int($record['failures'])?$record['failures']:0],['name'=>'最后尝试','value'=>isset($record['last_error']) && is_int($record['last_error']) && $record['last_error']?wp_date('Y-m-d H:i:s',$record['last_error']):'']]];}
    return ['data'=>$data,'done'=>count($rows)<100];
}
add_filter('wp_privacy_personal_data_erasers',function($erasers){$erasers['wuai-guesses']=['eraser_friendly_name'=>'猜答案尝试记录','callback'=>'wuai_sluyu_guess_erase'];return $erasers;});
function wuai_sluyu_guess_erase($email,$page=1) {
    $user=get_user_by('email',$email);if(!$user)return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
    global $wpdb;$retained=(bool)$wpdb->get_var($wpdb->prepare("SELECT umeta_id FROM $wpdb->usermeta WHERE user_id=%d AND meta_key LIKE %s LIMIT 1",$user->ID,$wpdb->esc_like('_wuai_guess_attempts_').'%'));
    return ['items_removed'=>false,'items_retained'=>$retained,'messages'=>$retained?['猜答案的版本、失败次数和最后尝试时间暂保留以防绕过答题上限；不存储选择内容或正确答案。删除账号或永久删除对应题目时一并删除。']:[],'done'=>true];
}
