<?php
if (!defined('ABSPATH')) { exit; }

add_action('admin_menu',function(){add_submenu_page('wuai-sluyu','站内服务管理','站内服务管理','manage_options','wuai-sluyu-services','wuai_sluyu_services_admin');},30);
function wuai_sluyu_services_admin() {
    if(!current_user_can('manage_options'))return;
    echo '<div class="wrap"><h1>站内服务管理</h1><p>服务页面开箱可访问；需要在“页面”和“菜单”中编辑时可初始化。不会覆盖已有页面、文章、分类或主题设置。</p>';
    if(isset($_GET['created']))echo '<div class="notice notice-success"><p>缺失的服务页面与分类已创建，原有内容保持不变。</p></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wuai_sluyu_install_pages">'; wp_nonce_field('wuai_sluyu_install_pages'); submit_button('创建缺失的页面与分类','primary','submit',false); echo '</form><h2>功能配置</h2><p>签到积分、公开优惠码和会员商品 ID 在“主题设置 → 社区与商城”配置。</p><table class="widefat striped"><thead><tr><th>模块</th><th>管理入口 / 状态</th></tr></thead><tbody>';
    foreach(['sluyu_topic'=>'茶话会与投票','sluyu_news'=>'解忧资讯','sluyu_link'=>'网址导航','sluyu_doc'=>'帮助文档','sluyu_listing'=>'供求信息','sluyu_verify'=>'认证申请','sluyu_ticket'=>'工单与举报'] as $type=>$label)echo '<tr><td>' . esc_html($label) . '</td><td><a href="' . esc_url(admin_url('edit.php?post_type='.$type)) . '">管理内容与审核</a></td></tr>';
    echo '<tr><td>独立隐藏正文 / 下载权限</td><td>文章、页面或话题编辑中的对应信息框；正文与下载独立授权，请明确说明价格和用途。</td></tr><tr><td>一次性邀请码</td><td><a href="'.esc_url(admin_url('admin.php?page=wuai-sluyu-invitations')).'">启用方式、生成与挂起记录核验</a>；默认关闭，仅覆盖原生 WordPress 注册。</td></tr>';
    echo '<tr><td>圈子与创建申请</td><td><a href="' . esc_url(admin_url('edit-tags.php?taxonomy=sluyu_circle&post_type=sluyu_topic')) . '">管理分组、圈主和审核状态</a></td></tr><tr><td>拖放小工具</td><td><a href="' . esc_url(admin_url('widgets.php')) . '">外观 → 小工具</a></td></tr><tr><td>商城与支付</td><td>' . (class_exists('WooCommerce')?'WooCommerce 已启用，请配置支付网关并完成沙盒测试。':'需要安装 WooCommerce；未安装时不显示虚假交易。') . '</td></tr></tbody></table><h2>页面目录</h2><ul>';
    foreach(wuai_sluyu_portal_pages() as $key=>$info)echo '<li><a href="' . esc_url(wuai_sluyu_portal_url($key)) . '">' . esc_html($info[0]) . '</a> <code>[sluyu_portal page="' . esc_html($key) . '"]</code></li>';
    echo '</ul><h2>审核与管理规则</h2><p>前台投稿、认证、工单均保存为待审核。认证申请发布后授予称号，改为草稿或移入回收站后撤销。工单请通过下方“处理结果”回复。用户禁言在“用户 → 编辑用户”设置。</p></div>';
}
add_action('add_meta_boxes',function(){
    add_meta_box('wuai_service_fields','服务信息','wuai_sluyu_service_meta',['sluyu_topic','sluyu_news','sluyu_link','sluyu_ticket'],'normal');
});
function wuai_sluyu_service_meta($post) {
    wp_nonce_field('wuai_service_meta','wuai_service_meta_nonce');
    if(in_array($post->post_type,['sluyu_news','sluyu_link'],true))echo '<p><label>来源 / 站点网址 <input class="widefat" type="url" name="wuai_source_url" value="' . esc_attr(get_post_meta($post->ID,'_wuai_source_url',true)) . '"></label></p>';
    if($post->post_type==='sluyu_topic') { echo '<p><label>话题类型 <select name="wuai_topic_kind">'; foreach(['topic'=>'日常','question'=>'提问','poll'=>'投票'] as $value=>$label)echo '<option value="' . $value . '" ' . selected(get_post_meta($post->ID,'_wuai_topic_kind',true),$value,false) . '>' . $label . '</option>'; echo '</select></label></p><p>投票创建后不可更改选项，避免已投票的数据发生错配。</p><label>投票选项（仅新投票填写，每行一项）<textarea class="widefat" name="wuai_poll_choices" rows="5">' . esc_textarea(implode("\n",(array)get_post_meta($post->ID,'_wuai_poll_choices',true))) . '</textarea></label>'; }
    if($post->post_type==='sluyu_topic') { wuai_sluyu_poll_admin_fields($post->ID); }
    if($post->post_type==='sluyu_ticket')echo '<label>处理结果（保存后发送给提交者）<textarea class="widefat" name="wuai_ticket_reply" rows="5">' . esc_textarea(get_post_meta($post->ID,'_wuai_ticket_reply',true)) . '</textarea></label>';
}
add_action('save_post',function($id){
    if(!wp_verify_nonce(wuai_sluyu_input('wuai_service_meta_nonce'),'wuai_service_meta') || !current_user_can('edit_post',$id) || wp_is_post_revision($id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE))return;
    if(isset($_POST['wuai_source_url']))update_post_meta($id,'_wuai_source_url',esc_url_raw(wuai_sluyu_input('wuai_source_url'),['http','https']));
    $lock=wuai_sluyu_lock('poll_'.$id);
    if($lock) {
        try {
            $tally=wuai_sluyu_poll_tally($id); $has_votes=$tally['voters']>0;
            if(isset($_POST['wuai_topic_kind']) && !$has_votes) { $kind=wuai_sluyu_input('wuai_topic_kind'); if(in_array($kind,['topic','question','poll'],true))update_post_meta($id,'_wuai_topic_kind',$kind); }
            if(isset($_POST['wuai_poll_choices']) && !get_post_meta($id,'_wuai_poll_choices',true)) { $choices=array_values(array_unique(array_filter(array_map('sanitize_text_field',explode("\n",wuai_sluyu_input('wuai_poll_choices',true))),'strlen'))); if(count($choices)>=2 && count($choices)<=8 && max(array_map('mb_strlen',$choices))<=100)update_post_meta($id,'_wuai_poll_choices',$choices); }
            $choices=(array)get_post_meta($id,'_wuai_poll_choices',true); $mode=wuai_sluyu_input('wuai_poll_mode');
            if(!$has_votes && in_array($mode,['single','multiple','pk'],true) && ($mode!=='pk' || count($choices)===2)) { update_post_meta($id,'_wuai_poll_mode',$mode); update_post_meta($id,'_wuai_poll_max',$mode==='multiple'?max(1,min(count($choices),absint(wuai_sluyu_input('wuai_poll_max')))):1); }
            if(isset($_POST['wuai_poll_deadline'])) { $value=wuai_sluyu_input('wuai_poll_deadline'); $date=wuai_sluyu_parse_date($value,'Y-m-d\TH:i'); if(!$value || $date)update_post_meta($id,'_wuai_poll_deadline',$date?$date->getTimestamp():0); }
            if(isset($_POST['wuai_poll_mode']))update_post_meta($id,'_wuai_poll_closed',wuai_sluyu_input('wuai_poll_closed')==='1'?1:0);
        } finally { wuai_sluyu_unlock($lock); }
    }
    if(isset($_POST['wuai_ticket_reply']) && current_user_can('manage_options')) { $reply=wuai_sluyu_input('wuai_ticket_reply',true); if($reply && $reply!==get_post_meta($id,'_wuai_ticket_reply',true))wuai_sluyu_notify((int)get_post_field('post_author',$id),'工单「'.get_the_title($id).'」回复：'.$reply); update_post_meta($id,'_wuai_ticket_reply',$reply); }
});

function wuai_sluyu_user_moderation_fields($user) {
    if(!current_user_can('manage_options'))return;
    $until=(int)get_user_meta($user->ID,'_wuai_banned_until',true);
    echo '<h2>社区管理</h2>'; wp_nonce_field('wuai_user_moderation','wuai_user_moderation_nonce');
    echo '<table class="form-table"><tr><th>禁言截止时间</th><td><input name="wuai_ban_until" type="datetime-local" value="' . esc_attr($until>0?wp_date('Y-m-d\TH:i',$until):'') . '"><p><label><input type="checkbox" name="wuai_ban_forever" value="1" ' . checked($until,-1,false) . '> 永久禁言</label></p><p>留空且不选永久禁言即解除禁言。不能禁言管理员。</p></td></tr><tr><th>公开原因</th><td><input name="wuai_ban_reason" value="' . esc_attr(get_user_meta($user->ID,'_wuai_ban_reason',true)) . '" class="regular-text"></td></tr></table>';
}
add_action('show_user_profile','wuai_sluyu_user_moderation_fields'); add_action('edit_user_profile','wuai_sluyu_user_moderation_fields');
function wuai_sluyu_save_moderation($uid) {
    if(!current_user_can('manage_options') || !wp_verify_nonce(wuai_sluyu_input('wuai_user_moderation_nonce'),'wuai_user_moderation') || user_can($uid,'manage_options'))return;
    $date=wuai_sluyu_input('wuai_ban_until'); $parsed=wuai_sluyu_parse_date($date,'Y-m-d\TH:i');
    if ($date && !$parsed) { return; }
    $until=isset($_POST['wuai_ban_forever'])?-1:($parsed?$parsed->getTimestamp():0);
    update_user_meta($uid,'_wuai_banned_until',$until); update_user_meta($uid,'_wuai_ban_reason',wuai_sluyu_input('wuai_ban_reason'));
}
add_action('personal_options_update','wuai_sluyu_save_moderation'); add_action('edit_user_profile_update','wuai_sluyu_save_moderation');
