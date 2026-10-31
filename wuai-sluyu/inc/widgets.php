<?php
if (!defined('ABSPATH')) { exit; }

class Wuai_Sluyu_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct('wuai_sluyu_service', '森鹿语 · 功能小工具', ['description'=>'签到、任务、文章排行、作者、快讯、导航、会员、统计、广告和社群二维码。']);
    }
    public static function modes() {
        return ['checkin'=>'每日签到','tasks'=>'任务进度','posts'=>'最新文章','popular'=>'热门排行','author'=>'作者信息','news'=>'最新快讯','links'=>'网址导航','vip'=>'会员中心','stats'=>'站点统计','ad'=>'图片广告','qr'=>'社群二维码'];
    }
    public function form($instance) {
        $mode=$instance['mode']??'checkin';
        foreach(['title'=>'标题','count'=>'显示数量（1–10）','image'=>'图片 / 二维码地址','url'=>'点击跳转地址'] as $key=>$label) {
            echo '<p><label for="' . esc_attr($this->get_field_id($key)) . '">' . esc_html($label) . '</label><input class="widefat" id="' . esc_attr($this->get_field_id($key)) . '" name="' . esc_attr($this->get_field_name($key)) . '" value="' . esc_attr($instance[$key]??($key==='count'?5:'')) . '"></p>';
        }
        echo '<p><label>小工具类型<select class="widefat" name="' . esc_attr($this->get_field_name('mode')) . '">';
        foreach(self::modes() as $key=>$label)echo '<option value="' . esc_attr($key) . '" ' . selected($mode,$key,false) . '>' . esc_html($label) . '</option>'; echo '</select></label></p>';
    }
    public function update($new,$old) {
        return ['mode'=>isset(self::modes()[$new['mode']??''])?$new['mode']:'checkin','title'=>sanitize_text_field($new['title']??''),'count'=>max(1,min(10,absint($new['count']??5))),'image'=>esc_url_raw($new['image']??'',['http','https']),'url'=>esc_url_raw($new['url']??'',['http','https'])];
    }
    public function widget($args,$instance) {
        $mode=$instance['mode']??'checkin'; $title=$instance['title']??''; $count=max(1,min(10,absint($instance['count']??5)));
        if(!isset(self::modes()[$mode]))return;
        echo $args['before_widget']; echo $args['before_title'] . esc_html($title?:self::modes()[$mode]) . $args['after_title'];
        if(in_array($mode,['posts','popular','news','links'],true)) {
            $query=new WP_Query(['post_type'=>['news'=>'sluyu_news','links'=>'sluyu_link'][$mode]??'post','post_status'=>'publish','has_password'=>false,'posts_per_page'=>$count,'no_found_rows'=>true,'ignore_sticky_posts'=>true,'orderby'=>$mode==='popular'?'comment_count':'date']);
            echo '<div class="sluyu-widget-list">'; $rank=0;
            while($query->have_posts()) { $query->the_post(); $rank++; echo '<a href="' . esc_url(get_permalink()) . '"><b>' . $rank . '</b><span>' . esc_html(get_the_title()) . '<small>' . esc_html(get_the_date('m-d')) . '</small></span></a>'; }
            echo '</div>'; if(!$query->post_count)echo '<p class="sluyu-muted">暂无已发布内容</p>'; wp_reset_postdata();
        } elseif($mode==='checkin') {
            echo '<p>每日签到 +' . absint(wuai_sluyu_option('checkin_points',10)) . ' 积分</p>';
            if(is_user_logged_in()) { $checked=get_user_meta(get_current_user_id(),'_wuai_sluyu_checkin_date',true)===wp_date('Y-m-d'); wuai_sluyu_action_button('checkin',$checked?'今日已签到':'立即签到',[],$checked); }
            else echo '<a class="sluyu-action-primary" href="' . esc_url(wp_login_url(wuai_sluyu_portal_url('mission'))) . '">登录后签到</a>';
            echo '<p><a href="' . esc_url(wuai_sluyu_portal_url('mission')) . '">签到日历与排行 →</a></p>';
        } elseif($mode==='tasks') {
            $tasks=is_user_logged_in()?wuai_sluyu_tasks(get_current_user_id()):[]; $done=0; foreach($tasks as $task){if($task[3])$done++;}
            echo '<p>' . ($tasks?'已完成 '.$done.' / '.count($tasks).' 项任务':'登录后查看任务进度') . '</p><a class="sluyu-action-plain" href="' . esc_url(wuai_sluyu_portal_url('task')) . '">前往任务中心</a>';
        } elseif($mode==='vip') {
            echo '<p>' . (wuai_sluyu_member()?'你的会员当前有效。':'查看会员计划和实际权益。') . '</p><a class="sluyu-action-primary" href="' . esc_url(wuai_sluyu_portal_url('vips')) . '">会员中心</a>';
        } elseif($mode==='stats') {
            echo '<div class="sluyu-stat-grid"><div><strong>' . absint(wp_count_posts('post')->publish) . '</strong><span>文章</span></div><div><strong>' . absint(wp_count_comments()->approved) . '</strong><span>评论</span></div></div>';
        } elseif($mode==='author') {
            $uid=is_singular()?(int)get_post_field('post_author',get_queried_object_id()):get_current_user_id(); $user=get_userdata($uid);
            if($user) { echo '<div class="sluyu-profile-heading">' . get_avatar($uid,48) . '<a href="' . esc_url(get_author_posts_url($uid)) . '">' . esc_html($user->display_name) . '</a></div><p>' . esc_html(get_user_meta($uid,'description',true)) . '</p>'; if($uid!==get_current_user_id())wuai_sluyu_action_button('follow','关注 / 取消关注',['id'=>$uid]); }
            else echo '<p>打开文章查看作者信息。</p>';
        } else {
            $image=$instance['image']??''; $url=$instance['url']??'';
            if($image) { if($url)echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">'; echo '<img class="sluyu-widget-image" src="' . esc_url($image) . '" alt="' . esc_attr($title?:self::modes()[$mode]) . '" loading="lazy">'; if($url)echo '</a>'; }
            else echo '<p class="sluyu-muted">请在小工具设置中选择图片。</p>';
        }
        echo $args['after_widget'];
    }
}
add_action('widgets_init',function(){
    register_widget('Wuai_Sluyu_Widget');
    foreach(['sluyu-home'=>'首页侧栏','sluyu-sidebar'=>'文章与页面侧栏','sluyu-footer'=>'页脚小工具'] as $id=>$name)register_sidebar(['id'=>$id,'name'=>$name,'description'=>'添加后显示已配置的小工具；首页和文章侧栏替换默认内容。','before_widget'=>'<section id="%1$s" class="sluyu-panel sluyu-widget %2$s">','after_widget'=>'</section>','before_title'=>'<h2>','after_title'=>'</h2>']);
});
