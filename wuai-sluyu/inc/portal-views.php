<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_empty($message = '这里还没有内容，期待你的第一份分享。') { echo '<div class="sluyu-portal-empty">' . wuai_sluyu_icon('inbox-line') . '<p>' . esc_html($message) . '</p></div>'; }
function wuai_sluyu_require_login() {
    if (is_user_logged_in()) { return true; }
    echo '<section class="sluyu-panel sluyu-login-gate"><h2>登录后继续</h2><p>你的积分、私信与个人资料仅对你自己开放。</p><a class="sluyu-action-primary" href="' . esc_url(wp_login_url(wuai_sluyu_portal_url(wuai_sluyu_portal_key() ?: 'account'))) . '">登录账号</a> ';
    if (get_option('users_can_register')) { echo '<a class="sluyu-action-plain" href="' . esc_url(wp_registration_url()) . '">注册账号</a>'; }
    echo '<p><a href="' . esc_url(wp_lostpassword_url()) . '">忘记密码？</a></p></section>'; return false;
}
function wuai_sluyu_action_button($operation, $label, $data = [], $disabled = false) {
    echo '<button type="button" class="sluyu-action-primary" data-community-action="' . esc_attr($operation) . '"';
    foreach ($data as $key => $value) { echo ' data-' . esc_attr($key) . '="' . esc_attr($value) . '"'; }
    echo $disabled ? ' disabled' : ''; echo '>' . esc_html($label) . '</button>';
}
function wuai_sluyu_form_start($operation) { echo '<form class="sluyu-community-form" data-operation="' . esc_attr($operation) . '"><div class="sluyu-form-status" role="status" aria-live="polite"></div>'; }
function wuai_sluyu_form_end($label = '提交') { echo '<button type="submit" class="sluyu-action-primary">' . esc_html($label) . '</button></form>'; }
function wuai_sluyu_portal_pagination($total, $key) {
    if ($total < 2) { return; }
    $args=[];
    foreach(['peer','group','kind','sort'] as $param) { if(isset($_GET[$param]) && is_scalar($_GET[$param]))$args[$param]=sanitize_text_field(wp_unslash($_GET[$param])); }
    echo '<nav class="sluyu-portal-pagination" aria-label="分页">' . wp_kses_post(paginate_links(['base'=>add_query_arg('pg','%#%',wuai_sluyu_portal_url($key,$args)), 'format'=>'','current'=>max(1,absint($_GET['pg'] ?? 1)), 'total'=>$total, 'prev_text'=>'上一页','next_text'=>'下一页'])) . '</nav>';
}

function wuai_sluyu_render_portal($key) {
    $handlers = ['account'=>'account','mission'=>'mission','task'=>'task','gold'=>'gold','directmessage'=>'messages','message'=>'messages','verify'=>'verify','dark-room'=>'bans','submit'=>'submit','tools'=>'tools','document'=>'documents','more'=>'directory','circle'=>'feed','newsflashes'=>'feed','links'=>'feed'];
    if (isset($handlers[$key])) { call_user_func('wuai_sluyu_view_' . $handlers[$key], $key); }
    elseif (in_array($key,['shop','carts','orders','coupons','vips'],true)) { wuai_sluyu_view_commerce($key); }
    elseif (isset(wuai_sluyu_portal_pages()[$key])) { wuai_sluyu_view_information($key); }
    else { wuai_sluyu_view_channel($key); }
}

function wuai_sluyu_view_directory() {
    echo '<div class="sluyu-service-grid">';
    foreach (wuai_sluyu_portal_pages() as $slug => $info) {
        if ($slug === 'more') { continue; }
        echo '<a class="sluyu-service-tile" href="' . esc_url(wuai_sluyu_portal_url($slug)) . '">' . wuai_sluyu_icon($info[2]) . '<h2>' . esc_html($info[0]) . '</h2><p>' . esc_html($info[1]) . '</p></a>';
    }
    echo '</div>';
}

function wuai_sluyu_view_account() {
    if (!wuai_sluyu_require_login()) { return; }
    $user = wp_get_current_user(); $uid = $user->ID;
    global $wpdb;
    $following = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . wuai_sluyu_table('events') . " WHERE user_id=%d AND event_type='follow'", $uid));
    $followers = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . wuai_sluyu_table('events') . " WHERE target_id=%d AND event_type='follow'", $uid));
    echo '<section class="sluyu-panel"><div class="sluyu-profile-heading">' . get_avatar($uid,80) . '<div><h2>' . esc_html($user->display_name) . '</h2><p>' . esc_html(get_user_meta($uid,'_wuai_verified_title',true) ?: '普通用户') . ' · ' . (wuai_sluyu_member($uid) ? '会员有效' : '未开通会员') . '</p></div><a href="' . esc_url(wp_logout_url(home_url('/'))) . '">退出登录</a></div><div class="sluyu-stat-grid">';
    foreach (['积分'=>wuai_sluyu_points($uid),'文章'=>count_user_posts($uid,'post',true),'关注'=>$following,'粉丝'=>$followers] as $label=>$value) { echo '<div><strong>' . esc_html($value) . '</strong><span>' . esc_html($label) . '</span></div>'; }
    echo '</div></section><section class="sluyu-panel"><h2>我的资料</h2>';
    wuai_sluyu_form_start('profile');
    echo '<label>昵称<input name="display_name" maxlength="40" value="' . esc_attr($user->display_name) . '" required></label><label>个人简介<textarea name="description" maxlength="500">' . esc_textarea($user->description) . '</textarea></label><label>个人网站<input type="url" name="user_url" value="' . esc_attr($user->user_url) . '"></label>';
    wuai_sluyu_form_end('保存资料');
    echo '<p class="sluyu-muted">头像使用 WordPress 头像服务。安全操作：<a href="' . esc_url(wp_lostpassword_url()) . '">重置密码</a></p></section>';
    echo '<section class="sluyu-panel"><h2>我的收藏</h2><div class="sluyu-compact-list">';
    $bookmarks = wuai_sluyu_events($uid,'bookmark',100); $visible=0;
    foreach ($bookmarks as $entry) {
        $post=wuai_sluyu_public_post($entry->target_id); if (!$post) { continue; } $visible++;
        echo '<div><a href="' . esc_url(get_permalink($post)) . '">' . esc_html($post->post_title) . '</a>';
        wuai_sluyu_action_button('bookmark','移除',['id'=>$post->ID]); echo '</div>';
    }
    if (!$visible) { wuai_sluyu_empty('还没有账号收藏。登录后收藏文章，即可跨设备查看。'); }
    echo '</div></section><section class="sluyu-panel"><h2>我的投稿与申请</h2><div class="sluyu-compact-list">';
    $posts=get_posts(['post_type'=>['post','sluyu_topic','sluyu_news','sluyu_link','sluyu_verify','sluyu_ticket'],'author'=>$uid,'post_status'=>['publish','pending','draft'],'numberposts'=>30]);
    foreach ($posts as $post) { echo '<div><span>' . esc_html($post->post_title) . '</span><small>' . esc_html(['publish'=>'已发布 / 已通过','pending'=>'审核中','draft'=>'草稿 / 需修改'][$post->post_status]) . '</small></div>'; }
    if (!$posts) { wuai_sluyu_empty('还没有投稿或申请。'); }
    echo '</div><a href="' . esc_url(wuai_sluyu_portal_url('submit')) . '">去发布中心 →</a></section><section class="sluyu-panel"><h2>我的关注</h2>';
    $follows=wuai_sluyu_events($uid,'follow',100);
    foreach ($follows as $entry) { $person=get_userdata($entry->target_id); if (!$person) { continue; } echo '<p><a href="' . esc_url(get_author_posts_url($person->ID)) . '">' . esc_html($person->display_name) . '</a> '; wuai_sluyu_action_button('follow','取消关注',['id'=>$person->ID]); echo '</p>'; }
    if (!$follows) { wuai_sluyu_empty('还没有关注的作者。'); } echo '</section>';
}

function wuai_sluyu_view_mission() {
    global $wpdb; $uid=get_current_user_id(); $today=wp_date('Y-m-d');
    echo '<section class="sluyu-panel sluyu-mission-card"><p class="sluyu-eyebrow">A LITTLE EVERY DAY</p><h2>今天，也记得来打个招呼</h2><p>每日签到 +' . absint(wuai_sluyu_option('checkin_points',10)) . ' 积分，任务奖励另行领取。</p>';
    if ($uid) {
        $checked=get_user_meta($uid,'_wuai_sluyu_checkin_date',true)===$today;
        echo '<div class="sluyu-stat-grid"><div><strong data-checkin-days>' . absint(get_user_meta($uid,'_wuai_sluyu_checkin_days',true)) . '</strong><span>累计签到</span></div><div><strong>' . absint(get_user_meta($uid,'_wuai_checkin_streak',true)) . '</strong><span>上次连续天数</span></div><div><strong>' . wuai_sluyu_points($uid) . '</strong><span>当前积分</span></div></div>';
        wuai_sluyu_action_button('checkin', $checked?'今日已签到':'立即签到', [], $checked);
        echo ' '; wuai_sluyu_action_button('makeup','补签缺失天数', ['confirm'=>'补签将扣除积分，确认继续？']);
        echo '<p class="sluyu-muted">最多补签最近 7 天，每缺失一天消耗 ' . absint(wuai_sluyu_option('makeup_points',30)) . ' 积分。请先补签，再进行今日签到。</p>';
        $month=wp_date('Y-m');
        $dates=$wpdb->get_col($wpdb->prepare('SELECT event_key FROM ' . wuai_sluyu_table('events') . " WHERE user_id=%d AND event_type='checkin' AND event_key LIKE %s",$uid,'checkin:'.$month.'%'));
        echo '<div class="sluyu-calendar" aria-label="本月签到记录">';
        for($day=1;$day<=(int)wp_date('t');$day++) { $date=$month.'-'.str_pad($day,2,'0',STR_PAD_LEFT); echo '<span class="' . (in_array('checkin:'.$date,$dates,true)?'is-checked':'') . '" title="' . esc_attr($date) . '">' . $day . '</span>'; } echo '</div>';
    } else { wuai_sluyu_require_login(); } echo '</section><section class="sluyu-panel"><h2>今日签到排行</h2><div class="sluyu-compact-list">';
    $ranking=$wpdb->get_results($wpdb->prepare('SELECT user_id,created_at FROM ' . wuai_sluyu_table('events') . ' WHERE event_key=%s ORDER BY id ASC LIMIT 30', 'checkin:'.$today));
    foreach ($ranking as $index=>$item) { $user=get_userdata($item->user_id); if(!$user)continue; echo '<div><span><b>' . ($index+1) . '</b> ' . esc_html($user->display_name) . '</span><time>' . esc_html(substr($item->created_at,11,5)) . '</time></div>'; }
    if (!$ranking) { wuai_sluyu_empty('今天还没有人签到，来做第一个吧。'); } echo '</div></section><section class="sluyu-panel"><h2>连续签到排行</h2><div class="sluyu-compact-list">';
    $users=get_users(['meta_key'=>'_wuai_checkin_streak','orderby'=>'meta_value_num','order'=>'DESC','number'=>20]);
    $active=0; foreach($users as $user) { $last=get_user_meta($user->ID,'_wuai_sluyu_checkin_date',true); if($last!==$today && $last!==current_datetime()->modify('-1 day')->format('Y-m-d'))continue; $active++; echo '<div><strong>' . esc_html($user->display_name) . '</strong><span>' . absint(get_user_meta($user->ID,'_wuai_checkin_streak',true)) . ' 天</span></div>'; }
    if(!$active)wuai_sluyu_empty('还没有连续签到记录。'); echo '</div></section>';
}

function wuai_sluyu_view_task() {
    if (!wuai_sluyu_require_login()) { return; }
    echo '<section class="sluyu-panel"><h2>成长，从小小的行动开始</h2><div class="sluyu-task-list">';
    foreach(wuai_sluyu_tasks(get_current_user_id()) as $key=>$task) {
        $claimed=wuai_sluyu_has_event(get_current_user_id(),$task[2]);
        echo '<article><div><h3>' . esc_html($task[0]) . '</h3><p>奖励 <b>+' . $task[1] . '</b> 积分 · ' . ($claimed?'已领取':($task[3]?'已完成':'未完成')) . '</p></div>';
        if($task[3]) { wuai_sluyu_action_button('task',$claimed?'已领取':'领取奖励',['task'=>$key],$claimed); }
        else { echo '<a class="sluyu-action-plain" href="' . esc_url(wuai_sluyu_portal_url($task[4])) . '">去完成 →</a>'; } echo '</article>';
    } echo '</div></section>';
}

function wuai_sluyu_view_gold() {
    if (!wuai_sluyu_require_login()) { return; }
    $uid=get_current_user_id(); $page=max(1,absint($_GET['pg']??1));
    echo '<section class="sluyu-panel"><p>可用积分</p><h2 class="sluyu-big-number">' . wuai_sluyu_points($uid) . '</h2><p>积分由签到和任务获得，可用于补签。积分不是现金，不支持提现或兑换承诺。</p><a href="' . esc_url(wuai_sluyu_portal_url('orders')) . '">查看真实支付订单 →</a></section><section class="sluyu-panel"><h2>积分流水</h2><div class="sluyu-table-wrap"><table><thead><tr><th>时间</th><th>说明</th><th>积分变动</th></tr></thead><tbody>';
    $events=wuai_sluyu_events($uid,'',30,($page-1)*30);
    foreach($events as $entry) { if(!$entry->points)continue; echo '<tr><td>' . esc_html($entry->created_at) . '</td><td>' . esc_html($entry->payload) . '</td><td>' . ($entry->points>0?'+':'') . (int)$entry->points . '</td></tr>'; }
    echo '</tbody></table></div>'; if(!$events)wuai_sluyu_empty('暂无积分记录。');
    global $wpdb; $total=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . wuai_sluyu_table('events') . ' WHERE user_id=%d',$uid));
    wuai_sluyu_portal_pagination(ceil($total/30),'gold'); echo '</section>';
}

function wuai_sluyu_view_messages($key) {
    if(!wuai_sluyu_require_login())return;
    global $wpdb; $uid=get_current_user_id(); $table=wuai_sluyu_table('messages'); $page=max(1,absint($_GET['pg']??1)); $offset=($page-1)*30;
    if($key==='message') {
        echo '<section class="sluyu-panel"><h2>我的通知</h2>'; wuai_sluyu_action_button('read_messages','全部标记已读');
        $where=$wpdb->prepare("recipient_id=%d AND kind='notice'",$uid);
    } else {
        $peer=absint($_GET['peer']??0);
        echo '<section class="sluyu-panel"><h2>写一封新私信</h2>'; wuai_sluyu_form_start('message');
        echo '<label>收件人用户名<input name="recipient" autocomplete="off" value="' . esc_attr($peer && get_userdata($peer) ? get_userdata($peer)->user_login : '') . '" required></label><label>消息内容<textarea name="body" maxlength="2000" rows="4" required></textarea></label>'; wuai_sluyu_form_end('发送私信');
        echo '</section><section class="sluyu-panel"><h2>往来私信</h2>';
        if($peer) { $where=$wpdb->prepare("kind='message' AND ((sender_id=%d AND recipient_id=%d) OR (sender_id=%d AND recipient_id=%d))",$uid,$peer,$peer,$uid); wuai_sluyu_action_button('read_messages','此对话标记已读',['id'=>$peer]); }
        else { $where=$wpdb->prepare("kind='message' AND (sender_id=%d OR recipient_id=%d)",$uid,$uid); }
    }
    $messages=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT 30 OFFSET %d",$offset));
    foreach($messages as $message) {
        $sender=get_userdata($message->sender_id); $recipient=get_userdata($message->recipient_id);
        echo '<article class="sluyu-message"><header><strong>' . esc_html($sender?$sender->display_name:'系统通知') . '</strong><span>' . ($message->is_read || (int)$message->sender_id===$uid?'':'未读 · ') . esc_html($message->created_at) . '</span></header><p>' . nl2br(esc_html($message->body)) . '</p>';
        if($key!=='message') { $other=(int)$message->sender_id===$uid?$recipient:$sender; if($other)echo '<a href="' . esc_url(wuai_sluyu_portal_url('directmessage',['peer'=>$other->ID])) . '">与 ' . esc_html($other->display_name) . ' 的对话 →</a>'; } echo '</article>';
    }
    if(!$messages)wuai_sluyu_empty('暂无消息。');
    $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where"); wuai_sluyu_portal_pagination(ceil($count/30),$key); echo '</section>';
}

function wuai_sluyu_view_verify() {
    echo '<section class="sluyu-panel"><h2>让你的热爱，被更多人认识</h2><p>填写真实专长与可公开核验的资料，管理员审核通过后展示认证称号。不要提交身份证、密码等敏感信息。</p>';
    if(wuai_sluyu_require_login()) {
        $uid=get_current_user_id(); $title=get_user_meta($uid,'_wuai_verified_title',true);
        $pending=get_posts(['post_type'=>'sluyu_verify','author'=>$uid,'post_status'=>'pending','numberposts'=>1]);
        if($title)echo '<p class="sluyu-notice">已认证：' . esc_html($title) . '</p>';
        elseif($pending)echo '<p class="sluyu-notice">申请审核中：' . esc_html($pending[0]->post_title) . '</p>';
        else { wuai_sluyu_form_start('verify'); echo '<label>认证称号<input name="title" maxlength="30" required></label><label>申请说明与公开证明<textarea name="body" minlength="5" maxlength="5000" required></textarea></label>'; wuai_sluyu_form_end('提交认证申请'); }
    } echo '</section>';
}
function wuai_sluyu_view_bans() {
    echo '<section class="sluyu-panel"><h2>社区公约</h2><p>请勿发布违法内容、骚扰他人、恶意刷屏或滥发广告。禁言期间无法投稿、评论、签到或发私信。</p><div class="sluyu-compact-list">';
    $users=get_users(['meta_key'=>'_wuai_banned_until','number'=>100]); $count=0;
    foreach($users as $user) { if(!wuai_sluyu_banned($user->ID))continue; $count++; $until=(int)get_user_meta($user->ID,'_wuai_banned_until',true); echo '<div><strong>' . esc_html($user->display_name) . '</strong><span>' . esc_html(get_user_meta($user->ID,'_wuai_ban_reason',true) ?: '违反社区规范') . '</span><small>' . esc_html($until===-1?'永久禁言':wp_date('Y-m-d H:i',$until).' 解除') . '</small></div>'; }
    if(!$count)wuai_sluyu_empty('目前没有处于禁言期的用户。'); echo '</div></section>';
}

function wuai_sluyu_view_submit() {
    if(!wuai_sluyu_require_login())return;
    echo '<section class="sluyu-panel"><h2>每一份分享，都值得认真对待</h2><p>发布内容需经管理员审核。请确认拥有分享权限，不发布个人隐私、侵权或违法内容。</p>';
    wuai_sluyu_form_start('submit'); $selected=sanitize_key($_GET['kind']??'topic');
    echo '<label>发布类型<select name="kind">';
    foreach(['topic'=>'日常话题','question'=>'提问','poll'=>'投票','post'=>'文章投稿','news'=>'快讯爆料','link'=>'网址入驻','ticket'=>'工单 / 资源反馈'] as $value=>$label) { echo '<option value="' . esc_attr($value) . '" ' . selected($value,$selected,false) . '>' . esc_html($label) . '</option>'; }
    echo '</select></label><label>标题<input name="title" maxlength="120" required></label><label>正文 / 说明<textarea name="body" minlength="5" maxlength="20000" rows="8" required></textarea></label><label>来源或站点网址<input name="url" type="url" placeholder="https://"></label><label data-poll-field>投票选项（每行一个，2–8 项）<textarea name="choices" rows="4" maxlength="808"></textarea></label><label>茶话会分组<select name="group"><option value="0">未分组</option>';
    $terms=get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false]); if(!is_wp_error($terms))foreach($terms as $term)echo '<option value="' . absint($term->term_id) . '">' . esc_html($term->name) . '</option>';
    echo '</select></label>'; wuai_sluyu_form_end('提交审核'); echo '</section>';
}

function wuai_sluyu_view_channel($key) {
    $info=wuai_sluyu_channel_info($key); $term=get_term_by('name',$info[1],'category');
    echo '<section class="sluyu-panel"><div class="sluyu-filter-tabs"><a href="' . esc_url(wuai_sluyu_portal_url($info[2])) . '">全部</a>';
    foreach($info[3] as $slug=>$name)echo '<a href="' . esc_url(wuai_sluyu_portal_url($slug)) . '">' . esc_html($name) . '</a>';
    echo '</div><form class="sluyu-filter-form" method="get" action="' . esc_url(wuai_sluyu_portal_url($key)) . '">';
    if(!get_option('permalink_structure'))echo '<input type="hidden" name="sluyu_page" value="' . esc_attr($key) . '">';
    $sort=sanitize_key($_GET['sort']??'new'); echo '<label>排序<select name="sort">';
    foreach(['new'=>'最新发布','modified'=>'最近更新','popular'=>'最多讨论'] as $value=>$label)echo '<option value="' . $value . '" ' . selected($sort,$value,false) . '>' . $label . '</option>';
    echo '</select></label><button class="sluyu-action-plain">筛选</button></form></section>';
    if(!$term) { wuai_sluyu_empty('此分类尚未创建或没有内容。管理员可在服务管理中初始化分类。'); return; }
    $query=new WP_Query(['post_type'=>'post','post_status'=>'publish','has_password'=>false,'cat'=>$term->term_id,'posts_per_page'=>12,'paged'=>max(1,absint($_GET['pg']??1)),'orderby'=>['new'=>'date','modified'=>'modified','popular'=>'comment_count'][$sort]??'date','ignore_sticky_posts'=>true]);
    echo '<div class="sluyu-grid sluyu-channel-grid">'; while($query->have_posts()) { $query->the_post(); wuai_sluyu_render_card(); } echo '</div>'; if(!$query->post_count)wuai_sluyu_empty(); wp_reset_postdata(); wuai_sluyu_portal_pagination($query->max_num_pages,$key);
}

function wuai_sluyu_view_feed($key) {
    $config=['circle'=>['sluyu_topic','sluyu_circle','topic'],'newsflashes'=>['sluyu_news','sluyu_news_cat','news'],'links'=>['sluyu_link','sluyu_link_cat','link']][$key];
    $group=absint($_GET['group']??0); $kind=sanitize_key($_GET['kind']??''); $page=max(1,absint($_GET['pg']??1));
    echo '<section class="sluyu-panel"><div class="sluyu-panel-heading"><h2>' . esc_html(wuai_sluyu_portal_pages()[$key][0]) . '</h2><a class="sluyu-action-primary" href="' . esc_url(wuai_sluyu_portal_url('submit',['kind'=>$config[2]])) . '">' . ($key==='links'?'申请入驻':'发布内容') . '</a></div><div class="sluyu-filter-tabs"><a href="' . esc_url(wuai_sluyu_portal_url($key)) . '">全部</a>';
    $terms=get_terms(['taxonomy'=>$config[1],'hide_empty'=>false]); if(!is_wp_error($terms))foreach($terms as $term)echo '<a href="' . esc_url(wuai_sluyu_portal_url($key,['group'=>$term->term_id])) . '">' . esc_html($term->name) . '</a>';
    if($key==='circle')foreach(['topic'=>'日常','question'=>'提问','poll'=>'投票'] as $value=>$label)echo '<a href="' . esc_url(wuai_sluyu_portal_url($key,['kind'=>$value])) . '">' . $label . '</a>';
    echo '</div></section>';
    $args=['post_type'=>$config[0],'post_status'=>'publish','has_password'=>false,'posts_per_page'=>12,'paged'=>$page];
    if($group)$args['tax_query']=[['taxonomy'=>$config[1],'terms'=>$group]];
    if($key==='circle' && in_array($kind,['topic','question','poll'],true)) { $args['meta_key']='_wuai_topic_kind'; $args['meta_value']=$kind; }
    $query=new WP_Query($args);
    echo '<div class="' . ($key==='links'?'sluyu-service-grid':'sluyu-feed') . '">';
    while($query->have_posts()) { $query->the_post(); $id=get_the_ID(); $source=get_post_meta($id,'_wuai_source_url',true);
        echo '<article class="sluyu-panel"><div class="sluyu-feed-meta"><time>' . esc_html(get_the_date('Y-m-d H:i')) . '</time><span>' . esc_html(get_the_author()) . '</span></div><h2><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h2><p>' . esc_html(wp_trim_words(wp_strip_all_tags(get_the_content()),80)) . '</p>';
        if($key==='links' && $source)echo '<a class="sluyu-action-plain" href="' . esc_url($source) . '" rel="nofollow noopener noreferrer" target="_blank">访问网站 ↗</a>';
        if($key==='circle')echo '<a class="sluyu-action-plain" href="' . esc_url(get_permalink()) . '">参与讨论 · ' . get_comments_number() . '</a>';
        if($key==='newsflashes') { wuai_sluyu_action_button('news_vote','赞同',['id'=>$id,'direction'=>'up']); echo ' '; wuai_sluyu_action_button('news_vote','不赞同',['id'=>$id,'direction'=>'down']); }
        echo '</article>';
    } echo '</div>'; if(!$query->post_count)wuai_sluyu_empty(); wp_reset_postdata(); wuai_sluyu_portal_pagination($query->max_num_pages,$key);
}

function wuai_sluyu_view_documents() {
    $sections=[
        '下载帮助'=>['如何下载资源？'=>'打开资源文章，阅读说明后点击“立即下载”。有提取码时点击复制。会员或登录资源需先满足页面标示的权限。','压缩包如何解压？'=>'先将分卷全部下载到同一目录，在电脑上使用支持该格式的解压工具，从首个分卷开始解压。手机使用系统文件应用或可信的解压工具。','链接失效怎么办？'=>'在发布中心选择工单 / 资源反馈，附上文章地址、错误提示与设备信息。不要提交网盘账号密码。'],
        '账户与积分'=>['忘记密码怎么办？'=>'在登录页面点击“忘记密码”，通过注册邮箱重置。邮件无法收到时检查垃圾邮件并联系管理员。','积分如何获取？'=>'每日签到和完成任务可获取积分，奖励以任务中心显示为准。重复签到和重复领取不会重复发奖。','如何申请认证？'=>'在认证服务页面提交称号与公开证明材料，审核结果可在消息中心查看。'],
        '订单与会员'=>['付款后在哪里查看？'=>'前往我的订单查看支付状态。只有支付平台确认付款、订单进入已付款状态后才会开通会员，浏览器跳转不是支付凭证。','遇到支付失败怎么办？'=>'不要反复付款。保留订单号与付款记录，通过工单联系管理员核查。','如何申请退款？'=>'以商品页列明的退款政策及适用法律为准，通过订单渠道联系商家处理。'],
    ];
    echo '<label class="sluyu-help-search">搜索帮助<input type="search" data-help-search placeholder="例如：下载、密码、订单"></label>';
    foreach($sections as $title=>$items) { echo '<section class="sluyu-panel sluyu-help-group"><h2>' . esc_html($title) . '</h2>'; foreach($items as $question=>$answer) { echo '<details class="sluyu-faq"><summary>' . esc_html($question) . '</summary><p>' . esc_html($answer) . '</p></details>'; } echo '</section>'; }
    $docs=get_posts(['post_type'=>'sluyu_doc','post_status'=>'publish','has_password'=>false,'numberposts'=>50]); if($docs) { echo '<section class="sluyu-panel"><h2>更多文档</h2>'; foreach($docs as $doc)echo '<p><a href="' . esc_url(get_permalink($doc)) . '">' . esc_html($doc->post_title) . '</a></p>'; echo '</section>'; }
}

function wuai_sluyu_view_information($key) {
    $texts=[
        'gyuwo'=>['关于本站'=>wuai_sluyu_option('footer_description',''),'联系与反馈'=>'欢迎分享原创内容、推荐优质站点。资源问题、合作申请与版权反馈请通过发布中心提交工单。'],
        'yhusm'=>['社区使用说明'=>'请使用真实可联系的邮箱注册，妥善保护账号。尊重其他用户，不发布违法、侵权、骚扰、广告刷屏或泄露隐私的内容。','内容与账户'=>'投稿经审核后发布。收藏、签到、任务、私信均与账号关联。请勿向任何人提供密码或支付凭证中的敏感信息。','隐私与联系'=>'站点使用 WordPress 账户及评论数据，额外记录签到、积分、收藏、关注和站内消息。需要导出或删除个人数据时请联系管理员。'],
        'mianzesm'=>['尊重知识产权'=>'资源版权归相应权利人所有，具体使用范围以资源自身授权为准。没有明确授权时，不应假设可以商用或转售。','问题处理'=>'发现侵权、恶意链接或不准确内容时，请通过工单提交原文地址与证明材料，管理员核验后处理。外部网站的服务与安全由其运营方负责。'],
        'vipjiesangsa'=>['会员权益'=>'会员计划及价格以会员中心中实际配置的商品为准。未配置商品时不会提供付款入口。会员仅获得明确列出的站内权限，并不替代资源版权授权。','有效期与退款'=>'会员开通以服务端订单确认付款为准，到期时间在会员中心查看。退款按照商品说明与适用法律处理；退款或取消订单对应的会员授权会撤回。'],
    ];
    echo '<article class="sluyu-panel sluyu-entry">'; foreach($texts[$key]??[] as $title=>$text)echo '<h2>' . esc_html($title) . '</h2><p>' . esc_html($text) . '</p>'; echo '<p><a href="' . esc_url(wuai_sluyu_portal_url('submit',['kind'=>'ticket'])) . '">联系管理员 / 提交工单 →</a></p></article>';
}

function wuai_sluyu_view_tools() {
    ?>
    <section class="sluyu-panel"><h2>二维码生成</h2><p>输入网址或文本，在当前设备生成，不上传服务器。</p><form data-local-tool="qr"><label>内容<textarea name="input" maxlength="1000" required></textarea></label><button class="sluyu-action-primary">生成二维码</button><canvas data-qr-result hidden></canvas><a data-qr-download hidden download="qrcode.png">下载 PNG</a><output aria-live="polite"></output></form></section>
    <section class="sluyu-panel"><h2>文本小助手</h2><form data-local-tool="text"><label>文本<textarea name="input" rows="5" maxlength="100000"></textarea></label><label>处理方式<select name="mode"><option value="count">统计字数</option><option value="dedupe">按行去重</option><option value="trim">清理多余空白</option><option value="encode">URL 编码</option><option value="decode">URL 解码</option><option value="json">JSON 格式化</option></select></label><button class="sluyu-action-primary">处理文本</button><pre><output aria-live="polite"></output></pre></form></section>
    <section class="sluyu-panel"><h2>随机密码</h2><p>使用浏览器安全随机数生成，密码仅在本页显示。</p><form data-local-tool="password"><label>长度<input name="length" type="number" min="12" max="64" value="20" required></label><button class="sluyu-action-primary">生成密码</button><pre><output aria-live="polite"></output></pre></form></section>
    <?php
}

function wuai_sluyu_topic_content($content) {
    if(!is_singular(['sluyu_topic','sluyu_news','sluyu_link']) || !in_the_loop() || !is_main_query() || post_password_required())return $content;
    $id=get_the_ID(); $kind=get_post_meta($id,'_wuai_topic_kind',true); ob_start();
    if($kind==='poll') {
        $choices=get_post_meta($id,'_wuai_poll_choices',true); global $wpdb;
        $counts=$wpdb->get_results($wpdb->prepare('SELECT payload,COUNT(*) AS total FROM ' . wuai_sluyu_table('events') . " WHERE target_id=%d AND event_type='vote' GROUP BY payload",$id),OBJECT_K);
        echo '<section class="sluyu-panel"><h2>参与投票</h2>'; wuai_sluyu_form_start('vote'); echo '<input type="hidden" name="id" value="' . $id . '">';
        foreach((array)$choices as $index=>$choice)echo '<label class="sluyu-poll-choice"><input type="radio" name="choice" value="' . $index . '" required> ' . esc_html($choice) . ' <span>' . (isset($counts[$index])?(int)$counts[$index]->total:0) . ' 票</span></label>';
        wuai_sluyu_form_end('投票'); echo '</section>';
    }
    if($kind==='question') { $accepted=absint(get_post_meta($id,'_wuai_accepted_comment',true)); $answer=$accepted?get_comment($accepted):null; if($answer && $answer->comment_approved==='1' && (int)$answer->comment_post_ID===$id)echo '<section class="sluyu-notice"><h3>已采纳的回答</h3><p>' . nl2br(esc_html($answer->comment_content)) . '</p></section>'; }
    $source=get_post_meta($id,'_wuai_source_url',true); if($source)echo '<p><a href="' . esc_url($source) . '" target="_blank" rel="nofollow noopener noreferrer">查看来源 / 访问网站 ↗</a></p>';
    return $content . ob_get_clean();
}
add_filter('the_content','wuai_sluyu_topic_content',25);
