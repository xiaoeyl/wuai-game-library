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
function wuai_sluyu_portal_pagination($total, $key, $page_key = 'pg', $extra = []) {
    if (function_exists('wuai_sluyu_reference_alias_pagination') && wuai_sluyu_reference_alias_pagination($total, $key)) { return; }
    if ($total < 2) { return; }
    $args=[];
    foreach(['peer','group','kind','sort','scope','tab'] as $param) { if(isset($_GET[$param]) && is_scalar($_GET[$param]))$args[$param]=wuai_sluyu_query($param); }
    if(!isset($args['group']) && wuai_sluyu_query('group')!=='')$args['group']=wuai_sluyu_query('group');
    // paginate_links otherwise merges arbitrary query arguments from the URL.
    $clean = static function ($url) { return home_url('/'); };
    add_filter('get_pagenum_link', $clean, PHP_INT_MAX);
    try { $links = paginate_links(['base'=>add_query_arg($page_key,'%#%',wuai_sluyu_portal_url($key,array_merge($args,$extra))), 'format'=>'','current'=>wuai_sluyu_page_number($page_key), 'total'=>$total, 'add_args'=>[], 'prev_text'=>'上一页','next_text'=>'下一页']); }
    finally { remove_filter('get_pagenum_link', $clean, PHP_INT_MAX); }
    if ($links) { echo '<nav class="sluyu-portal-pagination" aria-label="分页">' . wp_kses_post($links) . '</nav>'; }
}

function wuai_sluyu_render_portal($key) {
    $handlers = ['account'=>'account_extended','mission'=>'mission','task'=>'task','gold'=>'gold','directmessage'=>'messages_extended','message'=>'messages_extended','verify'=>'verify','dark-room'=>'bans','submit'=>'submit','tools'=>'tools','tags'=>'tags','document'=>'documents','more'=>'directory','circle'=>'feed','circles'=>'circles','circle-topics'=>'circle_topics','listings'=>'listings','invitations'=>'invitations','newsflashes'=>'feed','announcement'=>'announcements','links'=>'feed'];
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

function wuai_sluyu_view_account() { wuai_sluyu_view_account_extended(); }

function wuai_sluyu_view_mission() {
    global $wpdb; $uid=get_current_user_id(); $today=wp_date('Y-m-d');
    echo '<section class="sluyu-panel sluyu-mission-card"><p class="sluyu-eyebrow">A LITTLE EVERY DAY</p><h2>今天，也记得来打个招呼</h2><p>每日签到 +' . absint(wuai_sluyu_option('checkin_points',10)) . ' 积分，任务奖励另行领取。</p>';
    if ($uid) {
        $checked=get_user_meta($uid,'_wuai_sluyu_checkin_date',true)===$today;
        $balance=wuai_sluyu_points_available($uid);
        echo '<div class="sluyu-stat-grid"><div><strong data-checkin-days>' . absint(get_user_meta($uid,'_wuai_sluyu_checkin_days',true)) . '</strong><span>累计签到</span></div><div><strong>' . absint(get_user_meta($uid,'_wuai_checkin_streak',true)) . '</strong><span>上次连续天数</span></div><div><strong>' . esc_html(wuai_sluyu_points_label($balance)) . '</strong><span>当前积分</span></div></div>';
        wuai_sluyu_action_button('checkin', $checked?'今日已签到':'立即签到', [], $checked || is_wp_error($balance));
        echo ' '; wuai_sluyu_action_button('makeup','补签缺失天数', ['confirm'=>'补签将扣除积分，确认继续？'],is_wp_error($balance) || !function_exists('wuai_sluyu_economy_contract_ready') || !wuai_sluyu_economy_contract_ready());
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
    $uid=get_current_user_id(); $page=wuai_sluyu_page_number(); $balance=wuai_sluyu_points_available($uid);
    $ledger=function_exists('wuai_sluyu_economy_ledger')?wuai_sluyu_economy_ledger($uid,$page,30):new WP_Error('economy_ledger','完整积分流水尚未就绪。');
    echo '<section class="sluyu-panel"><p>可用积分</p><h2 class="sluyu-big-number">' . esc_html(wuai_sluyu_points_label($balance)) . '</h2><p>积分由签到、任务及已采纳悬赏回答获得，可用于补签、独立正文、下载解锁及非现金悬赏。预留后即减少可用积分，未发放的预留经核验退回；采纳时不会再次扣分。积分不是现金，不支持充值提现、转账兑换或收益承诺。</p><p>最少量数字悬赏分配凭证在账号或内容删除后仍保留，以保护其他账号余额。普通正文及下载解锁不提供自动退款。</p><a href="' . esc_url(wuai_sluyu_portal_url('orders')) . '">查看真实支付订单 →</a></section><section class="sluyu-panel"><h2>积分流水</h2>';
    if(is_wp_error($balance))echo '<p class="sluyu-notice">'.esc_html($balance->get_error_message()).' 未核验的退回不视为已到账。</p>';
    if(is_wp_error($ledger)) { wuai_sluyu_empty($ledger->get_error_message()); echo '</section>'; return; }
    echo '<div class="sluyu-table-wrap"><table><thead><tr><th>时间</th><th>说明</th><th>积分变动</th></tr></thead><tbody>';
    foreach($ledger['items'] as $entry) { echo '<tr data-ledger-key="'.esc_attr($entry['ledger_key']).'"><td>'.esc_html($entry['created_at']).'</td><td>'.esc_html($entry['payload']).'</td><td>'.((int)$entry['points']>0?'+':'').(int)$entry['points'].'</td></tr>'; }
    echo '</tbody></table></div>'; if(!$ledger['items'])wuai_sluyu_empty('暂无积分记录。');
    wuai_sluyu_portal_pagination(ceil($ledger['total']/30),'gold'); echo '</section>';
}

function wuai_sluyu_view_messages($key) { wuai_sluyu_view_messages_extended($key); }

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
    if(wuai_sluyu_query('kind')==='topic-images') { wuai_sluyu_view_topic_media_submit(); return; }
    if(wuai_sluyu_query('kind')==='listing') { wuai_sluyu_view_listing_submit(); return; }
    if(wuai_sluyu_query('kind')==='guess') { wuai_sluyu_view_guess_submit(); return; }
    if(wuai_sluyu_query('kind')==='bounty') { if(function_exists('wuai_sluyu_view_bounty_submit'))wuai_sluyu_view_bounty_submit(); else wuai_sluyu_empty('非现金积分悬赏尚未就绪。'); return; }
    $requested_group=wuai_sluyu_query('group','0');
    $invalid_group=(isset($_GET['group']) && !is_scalar($_GET['group'])) || (!isset($_GET['group']) && isset($_GET['circle_id']) && !is_scalar($_GET['circle_id']));
    if ($invalid_group || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$requested_group) || ((int)$requested_group && !wuai_sluyu_circle_can_post((int)$requested_group,get_current_user_id()))) {
        echo '<section class="sluyu-panel"><h2>暂不能向所选圈子发布</h2><p>请先加入开放的圈子，并满足它的发帖或会员条件。不会自动改成未分组投稿。</p><p><a class="sluyu-action-plain" href="'.esc_url(wuai_sluyu_portal_url('circles')).'">查看圈子 / 申请加入</a> <a class="sluyu-action-plain" href="'.esc_url(wuai_sluyu_portal_url('submit',['kind'=>'topic'])).'">改为未分组投稿</a></p></section>';
        return;
    }
    echo '<section class="sluyu-panel"><h2>每一份分享，都值得认真对待</h2><p>发布内容需经管理员审核。请确认拥有分享权限，不发布个人隐私、侵权或违法内容。</p>';
    echo '<p><a href="' . esc_url(wuai_sluyu_portal_url('submit',['kind'=>'listing'])) . '">发布供求信息 →</a></p>';
    echo '<p><a href="'.esc_url(wuai_sluyu_portal_url('submit',['kind'=>'guess','group'=>(int)$requested_group])).'">发布猜答案互动题 →</a></p>';
    echo '<p><a href="'.esc_url(wuai_sluyu_portal_url('submit',['kind'=>'topic-images','group'=>(int)$requested_group])).'">发布公开图片话题 →</a> <span class="sluyu-muted">图片上传后立即公开，不能用作私密附件。</span></p>';
    if(function_exists('wuai_sluyu_bounty_enabled') && wuai_sluyu_bounty_enabled())echo '<p><a href="'.esc_url(wuai_sluyu_portal_url('submit',['kind'=>'bounty','group'=>(int)$requested_group])).'">发起非现金积分悬赏 →</a></p>';
    wuai_sluyu_form_start('submit'); $selected=sanitize_key(wuai_sluyu_query('kind','topic'));
    echo '<label>发布类型<select name="kind">';
    foreach(['topic'=>'日常话题','question'=>'提问','poll'=>'投票','post'=>'文章投稿','news'=>'快讯爆料','link'=>'网址入驻','ticket'=>'工单 / 资源反馈'] as $value=>$label) { echo '<option value="' . esc_attr($value) . '" ' . selected($value,$selected,false) . '>' . esc_html($label) . '</option>'; }
    echo '</select></label><label>标题<input name="title" maxlength="120" required></label><label>正文 / 说明<textarea name="body" minlength="5" maxlength="20000" rows="8" required></textarea></label><label>来源或站点网址<input name="url" type="url" placeholder="https://"></label>';
    wuai_sluyu_poll_submission_fields();
    echo '<label>茶话会分组（仅显示可发帖圈子）<select name="group"><option value="0">未分组</option>';
    $terms=get_terms(['taxonomy'=>'sluyu_circle','hide_empty'=>false]); if(!is_wp_error($terms))foreach($terms as $term) { if(!wuai_sluyu_circle_can_post($term->term_id,get_current_user_id()))continue; echo '<option value="' . absint($term->term_id) . '" ' . selected(absint(wuai_sluyu_query('group')),$term->term_id,false) . '>' . esc_html($term->name) . '</option>'; }
    echo '</select></label>'; wuai_sluyu_protected_submission_fields(); wuai_sluyu_form_end('提交审核'); echo '</section>';
}

function wuai_sluyu_view_channel($key) {
    $info=wuai_sluyu_channel_info($key); $term=get_term_by('name',$info[1],'category');
    echo '<section class="sluyu-panel"><div class="sluyu-filter-tabs"><a href="' . esc_url(wuai_sluyu_portal_url($info[2])) . '">全部</a>';
    foreach($info[3] as $slug=>$name)echo '<a href="' . esc_url(wuai_sluyu_portal_url($slug)) . '">' . esc_html($name) . '</a>';
    echo '</div>';
    if(!$term) { echo '</section>'; wuai_sluyu_empty('此分类尚未创建或没有内容。管理员可在服务管理中初始化分类。'); return; }
    $config=wuai_sluyu_discovery_channel($term);
    wuai_sluyu_discovery_channel_form($key,$config); echo '</section>';
    if(is_wp_error($config))return;
    $query=new WP_Query($config['args']);
    echo '<div class="sluyu-grid sluyu-channel-grid">'; while($query->have_posts()) { $query->the_post(); wuai_sluyu_render_card(); } echo '</div>'; if(!$query->post_count)wuai_sluyu_empty(); wp_reset_postdata(); wuai_sluyu_discovery_pagination($query->max_num_pages,$key,$config['page'],$config['pagination']);
}

function wuai_sluyu_view_feed($key) {
    $config=['circle'=>['sluyu_topic','sluyu_circle','topic'],'newsflashes'=>['sluyu_news','sluyu_news_cat','news'],'links'=>['sluyu_link','sluyu_link_cat','link']][$key];
    $group=absint(wuai_sluyu_query('group')); $kind=sanitize_key(wuai_sluyu_query('kind')); $page=wuai_sluyu_page_number();
    if($key==='circle' && $group) { if(!wuai_sluyu_circle($group)) { wuai_sluyu_empty('此圈子不存在或尚未开放。'); return; } wuai_sluyu_circle_header($group); }
    echo '<section class="sluyu-panel"><div class="sluyu-panel-heading"><h2>' . esc_html(wuai_sluyu_portal_pages()[$key][0]) . '</h2>';
    if ($key!=='circle' || !$group || wuai_sluyu_circle_can_post($group,get_current_user_id())) { echo '<a class="sluyu-action-primary" href="' . esc_url(wuai_sluyu_portal_url('submit',['kind'=>$config[2],'group'=>$group])) . '">' . ($key==='links'?'申请入驻':'发布内容') . '</a>'; }
    echo '</div><div class="sluyu-filter-tabs"><a href="' . esc_url(wuai_sluyu_portal_url($key)) . '">全部</a>';
    if($key==='circle')echo '<a href="' . esc_url(wuai_sluyu_portal_url('circles')) . '">圈子广场 / 创建圈子</a>';
    $terms=get_terms(['taxonomy'=>$config[1],'hide_empty'=>false]); if(!is_wp_error($terms))foreach($terms as $term)echo '<a href="' . esc_url(wuai_sluyu_portal_url($key,['group'=>$term->term_id])) . '">' . esc_html($term->name) . '</a>';
    if($key==='circle')foreach(['topic'=>'日常','question'=>'提问','poll'=>'投票','guess'=>'猜答案','bounty'=>'积分悬赏'] as $value=>$label)echo '<a href="' . esc_url(wuai_sluyu_portal_url($key,['kind'=>$value,'group'=>$group])) . '">' . $label . '</a>';
    echo '</div>';
    if($key==='circle') {
        $sort=wuai_sluyu_query('sort','latest');
        echo '<form class="sluyu-filter-form" method="get" action="'.esc_url(wuai_sluyu_portal_url('circle')).'">';
        if(!get_option('permalink_structure'))echo '<input type="hidden" name="sluyu_page" value="circle">';
        echo '<input type="hidden" name="group" value="'.$group.'"><input type="hidden" name="kind" value="'.esc_attr($kind).'"><label>话题排序<select name="sort">';
        foreach(['latest'=>'最新发布','featured'=>'圈内精选','likes'=>'最多喜欢','comments'=>'最多讨论'] as $value=>$label)echo '<option value="'.$value.'" '.selected($sort,$value,false).'>'.esc_html($label).'</option>';
        echo '</select></label><button class="sluyu-action-plain">筛选</button></form>';
    }
    echo '</section>';
    $args=['post_type'=>$config[0],'post_status'=>'publish','has_password'=>false,'posts_per_page'=>12,'paged'=>$page];
    if($group)$args['tax_query']=[['taxonomy'=>$config[1],'terms'=>$group]];
    if($key==='circle') { $args=wuai_sluyu_circle_feed_query_args($group,$kind,$sort,$page); }
    if (function_exists('wuai_sluyu_reference_alias_feed_args')) { $args = wuai_sluyu_reference_alias_feed_args($args, $key); }
    $query=new WP_Query($args);
    echo '<div class="' . ($key==='links'?'sluyu-service-grid':'sluyu-feed') . '">';
    while($query->have_posts()) { $query->the_post(); $id=get_the_ID(); $source=get_post_meta($id,'_wuai_source_url',true);
        echo '<article class="sluyu-panel"><div class="sluyu-feed-meta"><time>' . esc_html(get_the_date('Y-m-d H:i')) . '</time><span>' . esc_html(get_the_author()) . '</span></div><h2><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h2><p>' . esc_html(wp_trim_words(wp_strip_all_tags(get_the_content()),80)) . '</p>';
        if($key==='links' && $source)echo '<a class="sluyu-action-plain" href="' . esc_url($source) . '" rel="nofollow noopener noreferrer" target="_blank">访问网站 ↗</a>';
        if($key==='circle')echo '<a class="sluyu-action-plain" href="' . esc_url(get_permalink()) . '">参与讨论 · ' . get_comments_number() . '</a>';
        if($key==='newsflashes') { wuai_sluyu_action_button('news_vote','赞同',['id'=>$id,'direction'=>'up']); echo ' '; wuai_sluyu_action_button('news_vote','不赞同',['id'=>$id,'direction'=>'down']); }
        if($key==='circle' && $group)wuai_sluyu_circle_review_feature_form($id,$group);
        echo '</article>';
    } echo '</div>'; if(!$query->post_count)wuai_sluyu_empty(); wp_reset_postdata(); wuai_sluyu_portal_pagination($query->max_num_pages,$key);
}

function wuai_sluyu_view_documents() {
    wuai_sluyu_reference_documents_view();
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
    if($kind==='poll') { wuai_sluyu_poll_view($id); }
    if($kind==='question' && !(function_exists('wuai_sluyu_bounty_managed') && wuai_sluyu_bounty_managed($id))) { $accepted=absint(get_post_meta($id,'_wuai_accepted_comment',true)); $answer=$accepted?get_comment($accepted):null; if($answer && $answer->comment_approved==='1' && (int)$answer->comment_post_ID===$id)echo '<section class="sluyu-notice"><h3>已采纳的回答</h3><p>' . nl2br(esc_html($answer->comment_content)) . '</p></section>'; }
    $source=get_post_meta($id,'_wuai_source_url',true); if($source)echo '<p><a href="' . esc_url($source) . '" target="_blank" rel="nofollow noopener noreferrer">查看来源 / 访问网站 ↗</a></p>';
    return $content . ob_get_clean();
}
add_filter('the_content','wuai_sluyu_topic_content',25);
