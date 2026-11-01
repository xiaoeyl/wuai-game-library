<?php
if (!defined('ABSPATH')) { exit; }

// Security boundary: only this private fragment is gated. Trusted plugins, editors,
// database operators and cache configuration remain outside the theme's isolation.
// Never migrate already-public text here and assume old revisions/caches are revoked.
function wuai_sluyu_protected_key() { return '_wuai_protected_content'; }

function wuai_sluyu_protected_modes() {
    return ['login'=>'登录可见','member'=>'有效会员可见','comment'=>'本人评论通过后可见','circle'=>'所属圈子成员可见','points'=>'积分解锁'];
}

function wuai_sluyu_protected_supported($post) {
    $post = get_post($post);
    if ($post && function_exists('wuai_sluyu_topic_media_managed') && wuai_sluyu_topic_media_managed($post->ID)) { return false; }
    return $post && ($post->post_type === 'post' || ($post->post_type === 'sluyu_topic' && in_array(get_post_meta($post->ID, '_wuai_topic_kind', true), ['', 'topic'], true)));
}

function wuai_sluyu_protected_html($value) {
    if (!is_string($value)) { return ''; }
    $allowed = [
        'p'=>[], 'br'=>[], 'strong'=>[], 'b'=>[], 'em'=>[], 'i'=>[], 'u'=>[], 's'=>[],
        'ul'=>[], 'ol'=>[], 'li'=>[], 'blockquote'=>[], 'pre'=>[], 'code'=>[],
        'h2'=>[], 'h3'=>[], 'h4'=>[], 'hr'=>[], 'a'=>['href'=>true,'title'=>true],
    ];
    $value = preg_replace('/<!--[\s\S]*?-->/', '', $value);
    $value = wp_kses($value, $allowed, ['http','https']);
    // Appending after the normal content filters must never make shortcodes executable.
    return str_replace(['[', ']'], ['&#091;', '&#093;'], $value);
}

function wuai_sluyu_protected_config($post_id) {
    $data = get_post_meta(absint($post_id), wuai_sluyu_protected_key(), true);
    if (!is_array($data) || !isset($data['body'], $data['access'], $data['cost'], $data['version'])) { return false; }
    if (!is_string($data['body']) || !is_string($data['access']) || !is_string($data['version']) || !is_scalar($data['cost'])) { return false; }
    if (!isset(wuai_sluyu_protected_modes()[$data['access']]) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $data['version'])) { return false; }
    $body = wuai_sluyu_protected_html($data['body']);
    if (!trim(wp_strip_all_tags($body)) || strlen($body) > 200000) { return false; }
    $cost = (int) $data['cost'];
    if ($data['access'] === 'points' && ($cost < 1 || $cost > 1000000)) { return false; }
    return ['body'=>$body,'access'=>$data['access'],'cost'=>$data['access'] === 'points' ? $cost : 0,'version'=>$data['version']];
}

function wuai_sluyu_protected_meta_authorized($allowed, $key, $post_id, $uid) {
    return $key === wuai_sluyu_protected_key() && $uid && !wuai_sluyu_banned($uid) && user_can($uid, 'edit_others_posts') && user_can($uid, 'edit_post', $post_id);
}
add_action('init', function () {
    foreach (['post','sluyu_topic'] as $type) {
        register_post_meta($type, wuai_sluyu_protected_key(), ['single'=>true,'type'=>'array','show_in_rest'=>false,'auth_callback'=>'wuai_sluyu_protected_meta_authorized']);
    }
}, 30);

function wuai_sluyu_protected_event_key($post_id, $config) {
    return 'unlock:' . absint($post_id) . ':' . $config['version'];
}

function wuai_sluyu_protected_purchased($post_id, $uid, $config = null) {
    if (!$uid) { return false; }
    $config = $config ?: wuai_sluyu_protected_config($post_id);
    if (!$config) { return false; }
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . wuai_sluyu_table('events') . " WHERE user_id=%d AND event_key=%s AND target_id=%d AND event_type='unlock' AND points<0 LIMIT 1", $uid, wuai_sluyu_protected_event_key($post_id, $config), $post_id));
}

function wuai_sluyu_protected_circle_access($post_id, $uid) {
    if (!$uid || wuai_sluyu_banned($uid)) { return false; }
    $groups = wp_get_object_terms($post_id, 'sluyu_circle', ['fields'=>'ids','wuai_include_pending'=>true]);
    if (is_wp_error($groups) || !$groups) { return false; }
    foreach ($groups as $group) {
        if (!wuai_sluyu_circle($group) || !in_array(wuai_sluyu_circle_membership($group, $uid), ['active','owner'], true)) { return false; }
        if (get_term_meta($group, '_wuai_circle_join', true) === 'member' && !wuai_sluyu_member($uid)) { return false; }
    }
    return true;
}

function wuai_sluyu_protected_state($post_id, $uid = null, $allow_preview = false) {
    $uid = $uid === null ? get_current_user_id() : absint($uid);
    $post = get_post($post_id); $config = wuai_sluyu_protected_config($post_id);
    $state = ['enabled'=>(bool) $config,'allowed'=>false,'reason'=>'unavailable','access'=>'','cost'=>0,'purchased'=>false,'preview'=>false];
    if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($post_id)) { return $state; }
    if (!$post || !$config || !wuai_sluyu_protected_supported($post)) { return $state; }
    $state['access'] = $config['access']; $state['cost'] = $config['cost'];
    if ($post->post_password !== '' || ($uid && (!get_userdata($uid) || wuai_sluyu_banned($uid)))) { return $state; }
    $privileged = $uid && ((int) $post->post_author === $uid || user_can($uid, 'edit_others_posts'));
    if ($post->post_status !== 'publish') {
        if (!$allow_preview || !$privileged || !in_array($post->post_status, ['draft','pending','private','future'], true)) { return $state; }
        $state['allowed'] = true; $state['preview'] = true; $state['reason'] = 'owner'; return $state;
    }
    if (!$uid) { $state['reason'] = 'login'; return $state; }
    if ($privileged) { $state['allowed'] = true; $state['preview'] = true; $state['reason'] = 'owner'; return $state; }
    $allowed = false;
    if ($config['access'] === 'login') { $allowed = true; }
    elseif ($config['access'] === 'member') { $allowed = wuai_sluyu_member($uid); }
    elseif ($config['access'] === 'circle') { $allowed = wuai_sluyu_protected_circle_access($post_id, $uid); }
    elseif ($config['access'] === 'comment') {
        global $wpdb;
        $allowed = (bool) $wpdb->get_var($wpdb->prepare("SELECT comment_ID FROM $wpdb->comments WHERE comment_post_ID=%d AND user_id=%d AND comment_approved='1' AND comment_type IN ('','comment') LIMIT 1", $post_id, $uid));
    } elseif ($config['access'] === 'points') {
        $allowed = wuai_sluyu_protected_purchased($post_id, $uid, $config); $state['purchased'] = $allowed;
    }
    $state['allowed'] = $allowed; $state['reason'] = $allowed ? 'granted' : $config['access'];
    return $state;
}

function wuai_sluyu_protected_quote($post_id, $uid = null) {
    if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($post_id)) { return ''; }
    $uid = $uid === null ? get_current_user_id() : absint($uid);
    $post = get_post($post_id); $config = wuai_sluyu_protected_config($post_id);
    if (!$uid || $uid !== get_current_user_id() || !$post || !$config || $config['access'] !== 'points' || $post->post_status !== 'publish' || $post->post_password !== '' || !wuai_sluyu_protected_supported($post) || wuai_sluyu_banned($uid)) { return ''; }
    $subject = [$uid, $post->ID, $post->post_title, $post->post_status, $post->post_modified_gmt, $config];
    return hash_hmac('sha256', wp_json_encode($subject), wp_salt('nonce'));
}

function wuai_sluyu_protected_post_lock($post_id) {
    return wuai_sluyu_lock((get_post_type($post_id) === 'sluyu_topic' ? 'poll_' : 'submission_') . absint($post_id));
}

function wuai_sluyu_protected_unlock($post_id, $quote, $confirmed, $user_locked = false) {
    $uid = get_current_user_id(); $post_id = absint($post_id);
    if (!$uid || !$post_id || !get_userdata($uid) || wuai_sluyu_banned($uid)) { return new WP_Error('protected_auth', '请使用有效的登录账号解锁。'); }
    // A direct helper call must not silently join a caller-held account lock.
    // The community dispatcher explicitly passes $user_locked=true after it
    // has acquired the account lock itself; all other callers must observe a
    // busy lock rather than re-entering the protected spend path. This keeps
    // lock ownership explicit and prevents a failed assertion/exception from
    // leaving a durable user lock behind during cleanup.
    if (!$user_locked) {
        $lock_name = 'wuai_lock_user_' . absint($uid);
        if (!empty($GLOBALS['wuai_sluyu_request_locks'][$lock_name])) {
            return new WP_Error('protected_busy', '账号正在执行其他积分操作，请稍后重试。');
        }
    }
    // Expiry reconciliation precedes the content lock: user -> economy -> post.
    $guard = wuai_sluyu_points_operation_enter($uid, 'protected_unlock');
    if (is_wp_error($guard)) { return $guard; }
    $post_lock = false;
    try {
        $settled = wuai_sluyu_bounty_due_for_user($uid); if (is_wp_error($settled)) { return $settled; }
        $post_lock = wuai_sluyu_protected_post_lock($post_id);
        if (!$post_lock) { return new WP_Error('protected_busy', '内容正在更新，请刷新后重试。'); }
        clean_user_cache($uid);
        if (!get_userdata($uid) || wuai_sluyu_banned($uid)) { return new WP_Error('protected_auth', '账户状态已变化，本次不会扣分。'); }
        clean_post_cache($post_id);
        if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($post_id)) { return new WP_Error('protected_held','内容正在核验，不会扣除积分。'); }
        $post = get_post($post_id); $config = wuai_sluyu_protected_config($post_id);
        if (!$post || !$config || !wuai_sluyu_protected_supported($post) || $post->post_status !== 'publish' || $post->post_password !== '' || $config['access'] !== 'points') { return new WP_Error('protected_unavailable', '此内容当前不能用积分解锁，不会扣除积分。'); }
        $state = wuai_sluyu_protected_state($post_id, $uid);
        if ($state['allowed']) { return ['message'=>$state['purchased'] ? '你已经解锁当前版本，不会重复扣分。' : '作者与站点编辑可直接查看，无需扣分。','reload'=>true,'charged'=>0]; }
        if ($confirmed !== '1' || !is_string($quote) || !preg_match('/^[a-f0-9]{64}$/', $quote) || !hash_equals(wuai_sluyu_protected_quote($post_id, $uid), $quote)) {
            return new WP_Error('protected_confirm', '请重新核对标题、积分价格并确认；内容或价格可能已更新。');
        }
        $balance = wuai_sluyu_points($uid);
        if (is_wp_error($balance)) { return $balance; }
        if ($balance < $config['cost']) { return new WP_Error('protected_points', '积分不足，当前内容需要 ' . $config['cost'] . ' 积分。'); }
        // One unique ledger row is both the debit and entitlement; no second grant can fail.
        $saved = wuai_sluyu_economy_event($uid, wuai_sluyu_protected_event_key($post_id, $config), 'unlock', -$config['cost'], $post_id, '解锁受保护正文：' . sanitize_text_field($post->post_title));
        if (is_wp_error($saved)) { return $saved; }
        $after = wuai_sluyu_points($uid);
        return ['message'=>$saved['created'] ? '已扣除 ' . $config['cost'] . ' 积分并解锁当前版本。' : '已核验当前版本解锁凭证，本次未确认新增扣分。','reload'=>true,'charged'=>$saved['created'] ? $config['cost'] : 0,'points'=>is_wp_error($after) ? null : $after];
    } finally { wuai_sluyu_unlock($post_lock); wuai_sluyu_points_operation_leave($guard); }
}

add_filter('wuai_sluyu_community_dispatch', function ($result, $operation, $uid) {
    if ($result !== null || $operation !== 'protected_unlock') { return $result; }
    if ((int) $uid !== get_current_user_id()) { return new WP_Error('protected_auth', '当前账号不一致。'); }
    // Only this internal dispatch path inherits the community endpoint's user lock.
    return wuai_sluyu_protected_unlock(wuai_sluyu_input('id'), wuai_sluyu_input('quote'), wuai_sluyu_input('confirm_unlock'), true);
}, 40, 3);

function wuai_sluyu_protected_validate($input, $existing = false) {
    if (!is_array($input) || !isset($input['protected_body'], $input['access']) || !is_string($input['protected_body']) || !is_string($input['access'])) { return new WP_Error('protected_fields', '请填写有效的受保护正文和访问方式。'); }
    $access = sanitize_key($input['access']);
    if ($access === 'none') { return false; }
    if (!isset(wuai_sluyu_protected_modes()[$access]) || mb_strlen($input['protected_body']) > 30000) { return new WP_Error('protected_fields', '请选择有效访问方式；受保护正文最多 30000 字。'); }
    $body = wuai_sluyu_protected_html($input['protected_body']);
    if (mb_strlen(trim(wp_strip_all_tags($body))) < 5 || strlen($body) > 200000) { return new WP_Error('protected_body', '受保护正文至少 5 字且体积不超过 200 KB，只能使用基础安全 HTML。'); }
    $cost = 0;
    if ($access === 'points') {
        $raw = isset($input['cost']) && is_scalar($input['cost']) ? (string) $input['cost'] : '';
        if (!preg_match('/^[1-9][0-9]{0,6}$/', $raw) || (int) $raw > 1000000) { return new WP_Error('protected_cost', '积分价格须为 1–1000000 的整数，不使用现金。'); }
        $cost = (int) $raw;
    }
    $reset = isset($input['reset_version']) && $input['reset_version'] === '1';
    return ['body'=>$body,'access'=>$access,'cost'=>$cost,'version'=>$existing && !$reset ? $existing['version'] : wp_generate_uuid4()];
}

add_filter('wuai_sluyu_submission', function ($submission, $kind, $uid) {
    if (is_wp_error($submission)) { return $submission; }
    $raw_body = isset($_POST['protected_body']) ? wp_unslash($_POST['protected_body']) : '';
    $access = isset($_POST['access']) ? wp_unslash($_POST['access']) : 'none';
    if (!is_string($raw_body) || !is_string($access)) { return new WP_Error('protected_fields', '受保护正文字段格式不正确。'); }
    if (trim($raw_body) === '' && in_array($access, ['', 'none'], true)) { return $submission; }
    if (!in_array($kind, ['post','topic'], true)) { return new WP_Error('protected_kind', '仅文章和普通话题支持独立受保护正文；投票、问答与其他内容不支持。'); }
    if (!$uid || (int) $uid !== get_current_user_id() || wuai_sluyu_banned($uid)) { return new WP_Error('protected_auth', '请使用有效账号投稿。'); }
    $config = wuai_sluyu_protected_validate(['protected_body'=>$raw_body,'access'=>$access,'cost'=>wuai_sluyu_input('cost')]);
    if (is_wp_error($config)) { return $config; }
    if (!$config) { return new WP_Error('protected_mode', '填写了隐藏正文时必须明确选择访问方式。'); }
    if ($config['access'] === 'circle') {
        $group = absint(wuai_sluyu_input('group'));
        if ($kind !== 'topic' || !$group || !wuai_sluyu_circle_can_post($group, $uid)) { return new WP_Error('protected_circle', '圈内正文必须关联你可以发帖的已开放圈子。'); }
    }
    $submission['post_status'] = 'pending';
    $submission['meta_input'][wuai_sluyu_protected_key()] = $config;
    return $submission;
}, 40, 3);

function wuai_sluyu_protected_stamp($post_id) {
    return hash_hmac('sha256', absint($post_id) . '|' . wp_json_encode(get_post_meta($post_id, wuai_sluyu_protected_key(), true)), wp_salt('nonce'));
}

function wuai_sluyu_protected_save($post_id, $input) {
    $post_id = absint($post_id); $uid = get_current_user_id();
    if (!is_array($input)) { return new WP_Error('protected_fields', '无效的正文设置。'); }
    if (!$uid || wuai_sluyu_banned($uid) || !current_user_can('edit_others_posts') || !current_user_can('edit_post', $post_id)) { return new WP_Error('protected_editor', '只有受信任的站点编辑可以管理受保护正文。'); }
    if (!wuai_sluyu_protected_supported($post_id)) { return new WP_Error('protected_kind', '仅文章和普通话题支持独立受保护正文。'); }
    $lock = wuai_sluyu_protected_post_lock($post_id);
    if (!$lock) { return new WP_Error('protected_busy', '内容正在更新，请刷新后重试。'); }
    try {
        wp_cache_delete($post_id, 'post_meta');
        $stamp = isset($input['stamp']) && is_string($input['stamp']) ? $input['stamp'] : '';
        if (!$stamp || !hash_equals(wuai_sluyu_protected_stamp($post_id), $stamp)) { return new WP_Error('protected_changed', '受保护正文已变更，请刷新后重新编辑。'); }
        $config = wuai_sluyu_protected_validate($input, wuai_sluyu_protected_config($post_id));
        if (is_wp_error($config)) { return $config; }
        if (!$config) {
            if (!metadata_exists('post', $post_id, wuai_sluyu_protected_key())) { return true; }
            return delete_post_meta($post_id, wuai_sluyu_protected_key()) ? true : new WP_Error('protected_storage', '受保护正文未移除，请重试。');
        }
        if ($config['access'] === 'circle') {
            $groups = wp_get_object_terms($post_id, 'sluyu_circle', ['fields'=>'ids','wuai_include_pending'=>true]);
            if (is_wp_error($groups) || !$groups) { return new WP_Error('protected_circle', '圈内正文须先设置有效的所属圈子。'); }
            foreach ($groups as $group) { if (!wuai_sluyu_circle($group)) { return new WP_Error('protected_circle', '所属圈子尚未开放，请先处理圈子状态。'); } }
        }
        $saved = update_post_meta($post_id, wuai_sluyu_protected_key(), wp_slash($config));
        return $saved || wuai_sluyu_protected_config($post_id) === $config ? true : new WP_Error('protected_storage', '受保护正文未保存，请重试。');
    } finally { wuai_sluyu_unlock($lock); }
}

function wuai_sluyu_protected_admin_box($post) {
    if (!current_user_can('edit_others_posts')) { return; }
    $config = wuai_sluyu_protected_config($post->ID);
    wp_nonce_field('wuai_protected_content', 'wuai_protected_nonce');
    echo '<input type="hidden" name="wuai_protected_stamp" value="' . esc_attr(wuai_sluyu_protected_stamp($post->ID)) . '"><p><strong>编辑器的主正文只写公开摘要。</strong>这里的独立片段存入私有元数据，不进入公开正文、摘要、搜索或订阅源。评论、标题、投票和附件直链不属于此保护范围。</p><p><label>访问方式 <select name="wuai_protected_access"><option value="none">移除独立片段（不转为公开正文）</option>';
    foreach (wuai_sluyu_protected_modes() as $value=>$label) { echo '<option value="' . esc_attr($value) . '" ' . selected($config ? $config['access'] : 'none', $value, false) . '>' . esc_html($label) . '</option>'; }
    echo '</select></label> <label>积分价格 <input name="wuai_protected_cost" type="number" min="1" max="1000000" value="' . ($config && $config['cost'] ? $config['cost'] : 10) . '"></label></p><label>受保护正文（基础 HTML；不运行短代码，不接受图片、脚本或嵌入）<textarea class="widefat" name="wuai_protected_body" rows="12" maxlength="30000">' . esc_textarea($config ? $config['body'] : '') . '</textarea></label><p><label><input type="checkbox" name="wuai_protected_reset" value="1">明确发布一个新的收费版本：已有积分解锁不再覆盖新版本，用户须重新确认购买</label></p><p>默认编辑保留已解锁权益；价格变化不会自动再次扣分。删除片段不删除积分凭证。投票和问答不支持此功能。旧站内容不会自动迁移：以前已公开的正文或历史修订无法由本功能追回。</p><p>安全边界：主题不限制可信插件、内容过滤器、数据库管理员直接读取元数据。上线须审查插件和 CDN，登录页面与受保护文章不得共用公开缓存。管理员可撤稿，解锁不保证内容永久在线；当前不自动退款。</p>';
}
add_action('add_meta_boxes', function () {
    if (current_user_can('edit_others_posts')) { add_meta_box('wuai-protected-content', '独立受保护正文', 'wuai_sluyu_protected_admin_box', ['post','sluyu_topic'], 'normal', 'default'); }
});
add_action('save_post', function ($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !wp_verify_nonce(wuai_sluyu_input('wuai_protected_nonce'), 'wuai_protected_content')) { return; }
    $input = ['protected_body'=>isset($_POST['wuai_protected_body']) && is_string($_POST['wuai_protected_body']) ? wp_unslash($_POST['wuai_protected_body']) : null,'access'=>wuai_sluyu_input('wuai_protected_access'),'cost'=>wuai_sluyu_input('wuai_protected_cost'),'reset_version'=>wuai_sluyu_input('wuai_protected_reset'),'stamp'=>wuai_sluyu_input('wuai_protected_stamp')];
    $result = wuai_sluyu_protected_save($post_id, $input);
    if (is_wp_error($result)) { set_transient('wuai_protected_notice_' . get_current_user_id(), $result->get_error_message(), 60); }
}, 40);
add_action('admin_notices', function () {
    if (!current_user_can('edit_others_posts')) { return; }
    $key = 'wuai_protected_notice_' . get_current_user_id(); $message = get_transient($key);
    if ($message) { delete_transient($key); echo '<div class="notice notice-error"><p>独立正文设置：' . esc_html($message) . '</p></div>'; }
});

function wuai_sluyu_protected_submission_fields() {
    echo '<details class="sluyu-panel sluyu-protected-fields"><summary>可选：添加独立受保护正文</summary><p class="sluyu-muted">仅文章和普通话题支持。主正文仍是公开摘要，标题和评论也公开；这里只保护单独填写的正文片段，不保护附件直链。提交后由站点编辑审核，作者不能自行改动权限或价格。</p><label>访问方式<select name="access"><option value="none">不添加独立片段</option>';
    foreach (wuai_sluyu_protected_modes() as $value=>$label) { echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>'; }
    echo '</select></label><label>受保护正文<textarea name="protected_body" rows="6" maxlength="30000"></textarea></label><label>积分价格（仅积分模式）<input name="cost" type="number" min="1" max="1000000" value="10"></label></details>';
}

function wuai_sluyu_protected_view($post_id, $allow_preview = false) {
    $post = get_post($post_id); $state = wuai_sluyu_protected_state($post_id, null, $allow_preview);
    if (!$post || !$state['enabled']) { return ''; }
    if (!headers_sent()) { nocache_headers(); }
    $html = '<section class="sluyu-panel sluyu-protected-content"><h2>独立受保护正文</h2>';
    if ($state['allowed']) {
        $config = wuai_sluyu_protected_config($post_id);
        $label = $state['preview'] ? '作者 / 编辑预览，不代表其他用户具有访问权限。' : ($state['purchased'] ? '当前版本已解锁，不会重复扣分。' : '当前账号已满足访问条件。');
        return $html . '<p class="sluyu-muted">' . esc_html($label) . '</p><div class="sluyu-protected-body">' . wuai_sluyu_protected_html(wpautop($config['body'])) . '</div><p class="sluyu-muted">仅此独立片段受保护，公开摘要与评论不受影响。</p></section>';
    }
    $messages = ['unavailable'=>'此片段暂不可查看；禁言账号、未公开稿件和密码文章不能解锁。','login'=>'请登录后查看此片段的访问条件。','member'=>'此片段仅向有效会员开放。','comment'=>'请使用当前登录账号发表评论，评论通过审核后可见。匿名评论或他人评论不授予权限。','circle'=>'需要加入本文所有所属的已开放圈子，满足有效会员条件且未被限制加入。','points'=>'积分解锁只扣除站内积分，不涉及现金；确认后获得当前版本访问权。'];
    $html .= '<p>' . esc_html($messages[$state['reason']] ?? $messages['unavailable']) . '</p>';
    if ($state['reason'] === 'login') { $html .= '<a class="sluyu-action-primary" href="' . esc_url(wp_login_url(get_permalink($post_id))) . '">登录后继续</a>'; }
    elseif ($state['reason'] === 'member') { $html .= '<a class="sluyu-action-primary" href="' . esc_url(wuai_sluyu_portal_url('vips')) . '">会员中心</a>'; }
    elseif ($state['reason'] === 'comment') { $html .= '<a class="sluyu-action-plain" href="#comments">前往评论</a>'; }
    elseif ($state['reason'] === 'circle') { $html .= '<a class="sluyu-action-plain" href="' . esc_url(wuai_sluyu_portal_url('circles')) . '">查看圈子目录</a>'; }
    elseif ($state['reason'] === 'points') {
        $quote = wuai_sluyu_protected_quote($post_id);
        $balance = wuai_sluyu_points_available(get_current_user_id());
        if (is_wp_error($balance) || !wuai_sluyu_economy_contract_ready()) { return $html . '<p class="sluyu-notice">完整积分暂不可核验或支出尚未开放，不会扣分。已有解锁凭证不受影响，请稍后重试。</p></section>'; }
        $html .= '<div class="sluyu-notice"><p>内容：<strong>' . esc_html($post->post_title) . '</strong></p><p>本次需要 <strong>' . absint($state['cost']) . ' 积分</strong>，当前余额 ' . esc_html($balance) . ' 积分。请核对内容与价格；没有确认不会扣分。</p><p>管理员可因审核、版权或维护撤稿，不保证内容永久在线。积分不是现金，当前没有自动退款流程；有争议请提交工单。</p></div><form class="sluyu-community-form" data-operation="protected_unlock"><div class="sluyu-form-status" role="status" aria-live="polite"></div><input type="hidden" name="id" value="' . absint($post_id) . '"><input type="hidden" name="quote" value="' . esc_attr($quote) . '"><label><span><input type="checkbox" name="confirm_unlock" value="1" required style="width:auto"> 我确认用 ' . absint($state['cost']) . ' 积分解锁此内容当前版本</span></label><button type="submit" class="sluyu-action-primary">确认扣除积分并解锁</button></form>';
    }
    return $html . '<p class="sluyu-muted">只保护此独立正文片段，不隐藏公开摘要、评论，也不保证外部链接或附件直链不可访问。</p></section>';
}

function wuai_sluyu_protected_render_context($post_id) {
    if ((defined('REST_REQUEST') && REST_REQUEST) || !empty($GLOBALS['wuai_sluyu_protected_rest_depth']) || is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed()) { return false; }
    foreach (['get_the_excerpt','the_excerpt','the_excerpt_rss','the_content_feed','get_the_content_feed'] as $filter) { if (doing_filter($filter)) { return false; } }
    return is_singular(['post','sluyu_topic']) && in_the_loop() && is_main_query() && (int) get_queried_object_id() === (int) $post_id;
}

add_filter('the_content', function ($content) {
    $post_id = get_the_ID();
    return $post_id && wuai_sluyu_protected_render_context($post_id) ? $content . wuai_sluyu_protected_view($post_id, is_preview()) : $content;
}, 99);

add_action('template_redirect', function () {
    if (is_singular(['post','sluyu_topic']) && metadata_exists('post', get_queried_object_id(), wuai_sluyu_protected_key())) { nocache_headers(); }
}, 1);

add_filter('rest_request_before_callbacks', function ($response) {
    $GLOBALS['wuai_sluyu_protected_rest_depth'] = (int) ($GLOBALS['wuai_sluyu_protected_rest_depth'] ?? 0) + 1;
    return $response;
}, -1000);
add_filter('rest_request_after_callbacks', function ($response) {
    $GLOBALS['wuai_sluyu_protected_rest_depth'] = max(0, (int) ($GLOBALS['wuai_sluyu_protected_rest_depth'] ?? 0) - 1);
    return $response;
}, 1000);
foreach (['post','sluyu_topic'] as $wuai_protected_type) {
    add_filter('rest_prepare_' . $wuai_protected_type, function ($response) {
        if ($response instanceof WP_REST_Response) {
            $data = $response->get_data();
            if (isset($data['meta']) && is_array($data['meta'])) { unset($data['meta'][wuai_sluyu_protected_key()]); $response->set_data($data); }
        }
        return $response;
    }, 99);
}
unset($wuai_protected_type);
