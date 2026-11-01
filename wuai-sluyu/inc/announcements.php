<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_announcements_option() { return 'wuai_sluyu_announcements'; }
function wuai_sluyu_announcements_types() { return ['post','page','sluyu_news']; }

function wuai_sluyu_announcements_ids($input) {
    if (!is_array($input) || count($input)>100) { return new WP_Error('announcement_ids','公告清单最多包含 100 个内容 ID。'); }
    $ids=[];
    foreach ($input as $value) {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value) || (float)$value>2147483647) { return new WP_Error('announcement_ids','公告 ID 必须是有效的正整数。'); }
        $id=(int)$value;
        if (in_array($id,$ids,true)) { return new WP_Error('announcement_ids','公告清单中有重复 ID，请删除重复项。'); }
        $ids[]=$id;
    }
    return $ids;
}

function wuai_sluyu_announcements_settings() {
    global $wpdb;
    $stored=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s",wuai_sluyu_announcements_option()));
    if ($wpdb->last_error) { return new WP_Error('announcement_settings','暂时无法核对公告配置。'); }
    $raw=$stored===null?null:(is_serialized($stored)?@unserialize($stored,['allowed_classes'=>false]):$stored);
    if ($raw===null) { return ['enabled'=>false,'ids'=>[]]; }
    if (!is_array($raw) || !isset($raw['enabled'],$raw['ids']) || !in_array($raw['enabled'],[0,1],true)) { return new WP_Error('announcement_settings','公告配置需要管理员核验，暂不展示。'); }
    $ids=wuai_sluyu_announcements_ids($raw['ids']);
    if (is_wp_error($ids)) { return new WP_Error('announcement_settings','公告配置需要管理员核验，暂不展示。'); }
    return ['enabled'=>$raw['enabled']===1,'ids'=>$ids];
}

function wuai_sluyu_announcements_public_sql() {
    return "post_type IN ('post','page','sluyu_news') AND post_status='publish' AND post_password=''";
}

function wuai_sluyu_announcements_input($input) {
    if (!is_array($input)) { return new WP_Error('announcement_input','公告筛选参数格式不正确。'); }
    $search=$input['announcement_search']??''; $page=$input['pg']??'1';
    foreach ([$search,$page] as $value) { if (!is_string($value) && !is_int($value)) { return new WP_Error('announcement_input','公告筛选参数必须为单个文本值。'); } }
    $search=wp_unslash((string)$search); $page=(string)$page;
    if (strlen($search)>320 || mb_strlen($search)>80 || wp_check_invalid_utf8($search,true)!==$search || preg_match('/[\x00-\x1f\x7f]/',$search)) { return new WP_Error('announcement_input','搜索内容过长或包含不支持的字符。'); }
    if (!preg_match('/^[1-9][0-9]{0,5}$/D',$page) || (int)$page>100000) { return new WP_Error('announcement_input','页码不正确。'); }
    return ['search'=>trim(sanitize_text_field($search)),'page'=>(int)$page];
}

/** Fresh rows, never a current-user permission shortcut or cached private post. */
function wuai_sluyu_announcements_list($input = null, $per_page = 12) {
    global $wpdb;
    $input=wuai_sluyu_announcements_input($input===null?$_GET:$input);
    if (is_wp_error($input)) { return $input; }
    $settings=wuai_sluyu_announcements_settings();
    if (is_wp_error($settings)) { return $settings; }
    $per_page=max(1,min(20,(int)$per_page));
    $result=['items'=>[],'total'=>0,'pages'=>0,'page'=>$input['page'],'search'=>$input['search'],'enabled'=>$settings['enabled']];
    if (!$settings['enabled'] || !$settings['ids']) { return $result; }
    $ids=implode(',',$settings['ids']);
    $where="ID IN ($ids) AND ".wuai_sluyu_announcements_public_sql();
    // Only title search is used; hidden bodies and metadata never participate.
    if ($input['search']!=='') { $where.=$wpdb->prepare(' AND post_title LIKE %s','%'.$wpdb->esc_like($input['search']).'%'); }
    $rows=$wpdb->get_results("SELECT ID,post_type,post_status,post_password,post_title,post_excerpt,post_content,post_name,post_date,post_date_gmt,post_modified,post_author FROM $wpdb->posts WHERE $where",ARRAY_A);
    if ($wpdb->last_error || !is_array($rows)) { return new WP_Error('announcement_storage','暂时无法读取公开公告，请稍后重试。'); }
    $by_id=[];
    foreach ($rows as $row) { $by_id[(int)$row['ID']]=$row; }
    $ordered=[];
    foreach ($settings['ids'] as $id) {
        if (!isset($by_id[$id])) { continue; }
        if (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held($id)) { continue; }
        $row=$by_id[$id];
        if ($row['post_status']!=='publish' || $row['post_password']!=='' || !in_array($row['post_type'],wuai_sluyu_announcements_types(),true)) { continue; }
        $ordered[]=$row;
    }
    $result['total']=count($ordered); $result['pages']=(int)ceil($result['total']/$per_page);
    foreach (array_slice($ordered,($input['page']-1)*$per_page,$per_page) as $row) {
        $post=new WP_Post((object)$row); $url=get_permalink($post);
        if (!$url) { continue; }
        $text=$row['post_excerpt']!==''?$row['post_excerpt']:$row['post_content'];
        $text=wp_strip_all_tags(strip_shortcodes($text),true);
        $result['items'][]=['id'=>(int)$row['ID'],'title'=>$row['post_title'],'url'=>$url,'date'=>$row['post_date'],'excerpt'=>wp_trim_words($text,48,'…')];
    }
    return $result;
}

function wuai_sluyu_announcements_url() {
    return function_exists('wuai_sluyu_portal_key') && wuai_sluyu_portal_key('announcement') ? wuai_sluyu_portal_url('announcement') : add_query_arg('sluyu_page','announcement',home_url('/'));
}

function wuai_sluyu_view_announcements() {
    $data=wuai_sluyu_announcements_list();
    echo '<section class="sluyu-panel"><h2>全部公告</h2><p>由站点管理员选定的公开通知，以各公告最新内容为准。</p>';
    if (is_wp_error($data)) { echo '<p class="sluyu-notice" role="alert">'.esc_html($data->get_error_message()).'</p></section>'; return; }
    echo '<form class="sluyu-filter-form" method="get" action="'.esc_url(wuai_sluyu_announcements_url()).'">';
    if (!get_option('permalink_structure')) { echo '<input type="hidden" name="sluyu_page" value="announcement">'; }
    echo '<label>搜索公告标题<input type="search" name="announcement_search" maxlength="80" value="'.esc_attr($data['search']).'"></label><button class="sluyu-action-plain">查找</button></form></section>';
    if (!$data['items']) { echo '<section class="sluyu-panel"><p class="sluyu-empty">暂无可公开展示的公告。</p></section>'; }
    foreach ($data['items'] as $item) {
        echo '<article class="sluyu-panel sluyu-announcement"><time>'.esc_html(mysql2date('Y-m-d',$item['date'])).'</time><h2><a href="'.esc_url($item['url']).'">'.esc_html($item['title']).'</a></h2>';
        if ($item['excerpt']!=='') { echo '<p>'.esc_html($item['excerpt']).'</p>'; }
        echo '<a class="sluyu-action-plain" href="'.esc_url($item['url']).'">阅读公告 →</a></article>';
    }
    if ($data['pages']>1) {
        $clean=static function ($url) { return home_url('/'); }; add_filter('get_pagenum_link',$clean,PHP_INT_MAX);
        try { $links=paginate_links(['base'=>add_query_arg(['announcement_search'=>$data['search'],'pg'=>'%#%'],wuai_sluyu_announcements_url()),'format'=>'','current'=>$data['page'],'total'=>$data['pages'],'add_args'=>[],'prev_text'=>'上一页','next_text'=>'下一页']); }
        finally { remove_filter('get_pagenum_link',$clean,PHP_INT_MAX); }
        echo '<nav class="sluyu-portal-pagination" aria-label="公告分页">'.wp_kses_post($links).'</nav>';
    }
}

function wuai_sluyu_announcements_save($input) {
    global $wpdb;
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { return new WP_Error('announcement_method','请使用表单提交。',['status'=>405]); }
    if (!current_user_can('manage_options') || (function_exists('wuai_sluyu_banned') && wuai_sluyu_banned(get_current_user_id()))) { return new WP_Error('announcement_permission','无权配置公告。',['status'=>403]); }
    if (!is_array($input) || !isset($input['_wpnonce']) || !is_string($input['_wpnonce']) || !wp_verify_nonce(wp_unslash($input['_wpnonce']),'wuai_sluyu_announcements')) { return new WP_Error('announcement_nonce','安全校验已过期，请重新打开页面。',['status'=>403]); }
    $enabled=$input['enabled']??'0'; $raw=$input['ids']??'';
    if (!is_string($enabled) || !in_array($enabled,['0','1'],true) || !is_string($raw) || strlen($raw)>2000) { return new WP_Error('announcement_input','公告配置格式不正确。',['status'=>400]); }
    $raw=trim(wp_unslash($raw));
    if ($raw!=='' && !preg_match('/^[0-9,\s]+$/D',$raw)) { return new WP_Error('announcement_input','只填写内容 ID，以空白或逗号分隔。',['status'=>400]); }
    $ids=wuai_sluyu_announcements_ids($raw===''?[]:preg_split('/[\s,]+/',$raw,-1,PREG_SPLIT_NO_EMPTY));
    if (is_wp_error($ids)) { return $ids; }
    $previous=wuai_sluyu_announcements_settings();
    $previous_ids=is_wp_error($previous)?[]:$previous['ids'];
    $new_ids=array_values(array_diff($ids,$previous_ids));
    if ($new_ids) {
        $rows=$wpdb->get_results('SELECT ID,post_type,post_status,post_password FROM '.$wpdb->posts.' WHERE ID IN ('.implode(',',$new_ids).')',ARRAY_A);
        if ($wpdb->last_error || !is_array($rows) || count($rows)!==count($new_ids)) { return new WP_Error('announcement_unavailable','部分新增内容不存在或暂时无法核验，未修改配置。'); }
        foreach ($rows as $row) {
            if (!in_array($row['post_type'],wuai_sluyu_announcements_types(),true) || $row['post_status']!=='publish' || $row['post_password']!=='' || !current_user_can('edit_post',(int)$row['ID']) || (function_exists('wuai_sluyu_circle_review_held') && wuai_sluyu_circle_review_held((int)$row['ID']))) {
                return new WP_Error('announcement_unavailable','仅能选择你有权编辑的公开、无密码文章、页面或快讯，未修改配置。');
            }
        }
    }
    $desired=['enabled'=>$enabled==='1'?1:0,'ids'=>$ids];
    update_option(wuai_sluyu_announcements_option(),$desired,false);
    wp_cache_delete(wuai_sluyu_announcements_option(),'options'); wp_cache_delete('alloptions','options'); wp_cache_delete('notoptions','options');
    $stored=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s",wuai_sluyu_announcements_option()));
    if ($wpdb->last_error || $stored!==maybe_serialize($desired)) { return new WP_Error('announcement_storage','配置未完整保存，请重新核验。'); }
    return ['message'=>'公告配置已保存。'];
}

function wuai_sluyu_announcements_admin() {
    if (!current_user_can('manage_options')) { return; }
    $settings=wuai_sluyu_announcements_settings();
    echo '<div class="wrap"><h1>站点公告</h1><p>先通过 WordPress 发布文章、页面或快讯，再将其 ID 加入公告清单。按清单顺序展示，最多 100 条；改为草稿、私密或设置密码后会立即停止在公告列表中展示。此功能不会创建演示公告，不执行充值或支付。</p>';
    if (isset($_GET['saved']) && $_GET['saved']==='1') { echo '<div class="notice notice-success"><p>公告配置已保存。</p></div>'; }
    if (is_wp_error($settings)) { echo '<div class="notice notice-error"><p>'.esc_html($settings->get_error_message()).'</p></div>'; $settings=['enabled'=>false,'ids'=>[]]; }
    echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post"><input type="hidden" name="action" value="wuai_sluyu_announcements">';
    wp_nonce_field('wuai_sluyu_announcements');
    echo '<p><label><input type="checkbox" name="enabled" value="1" '.checked($settings['enabled'],true,false).'> 启用公告页及小工具内容</label></p><p><label for="wuai-announcement-ids">公告内容 ID（按顺序，每行一个）</label></p><textarea id="wuai-announcement-ids" class="large-text code" name="ids" rows="10" maxlength="2000">'.esc_textarea(implode("\n",$settings['ids'])).'</textarea>';
    echo '<p>内容编辑地址中的 <code>post=数字</code> 即 ID；允许普通文章、页面和快讯，不允许圈子话题、工单或其他私密类型。清单只保存引用，编辑/删除公告正文仍使用 WordPress 原生编辑器。</p>';
    submit_button('保存公告配置'); echo '</form><p><a href="'.esc_url(wuai_sluyu_announcements_url()).'">查看公告页</a> · <a href="'.esc_url(admin_url('widgets.php')).'">配置“森鹿语 · 站点公告”小工具</a></p></div>';
}
add_action('admin_menu',function () { add_submenu_page('wuai-sluyu','站点公告','站点公告','manage_options','wuai-sluyu-announcements','wuai_sluyu_announcements_admin'); },31);
add_action('admin_post_wuai_sluyu_announcements',function () {
    $result=wuai_sluyu_announcements_save($_POST);
    if (is_wp_error($result)) { $data=$result->get_error_data(); wp_die(esc_html($result->get_error_message()),'公告配置失败',['response'=>is_array($data)?($data['status']??400):400]); }
    wp_safe_redirect(admin_url('admin.php?page=wuai-sluyu-announcements&saved=1')); exit;
});

class Wuai_Sluyu_Announcements_Widget extends WP_Widget {
    public function __construct() { parent::__construct('wuai_sluyu_announcements','森鹿语 · 站点公告',['description'=>'仅展示管理员选定的公开无密码公告，不包含私密或草稿内容。']); }
    public function form($instance) {
        $title=isset($instance['title']) && is_string($instance['title'])?$instance['title']:'';
        $count=isset($instance['count']) && is_scalar($instance['count'])?max(1,min(20,absint($instance['count']))):5;
        echo '<p><label for="'.esc_attr($this->get_field_id('title')).'">标题</label><input class="widefat" id="'.esc_attr($this->get_field_id('title')).'" name="'.esc_attr($this->get_field_name('title')).'" value="'.esc_attr($title).'"></p><p><label for="'.esc_attr($this->get_field_id('count')).'">显示数量（1–20）</label><input class="tiny-text" type="number" min="1" max="20" id="'.esc_attr($this->get_field_id('count')).'" name="'.esc_attr($this->get_field_name('count')).'" value="'.$count.'"></p>';
    }
    public function update($new,$old) {
        $new=is_array($new)?$new:[];
        return ['title'=>isset($new['title']) && is_string($new['title'])?sanitize_text_field($new['title']):'','count'=>isset($new['count']) && is_scalar($new['count'])?max(1,min(20,absint($new['count']))):5];
    }
    public function widget($args,$instance) {
        $safe=$this->update($instance,[]); $data=wuai_sluyu_announcements_list([], $safe['count']);
        echo $args['before_widget'].$args['before_title'].esc_html($safe['title']?:'站点公告').$args['after_title'];
        if (is_wp_error($data)) { echo '<p class="sluyu-muted">公告暂不可用，请稍后重试。</p>'; }
        elseif (!$data['items']) { echo '<p class="sluyu-muted">暂无公开公告。</p>'; }
        else {
            echo '<div class="sluyu-widget-list">';
            foreach ($data['items'] as $item) { echo '<a href="'.esc_url($item['url']).'"><span>'.esc_html($item['title']).'<small>'.esc_html(mysql2date('Y-m-d',$item['date'])).'</small></span></a>'; }
            echo '</div>';
        }
        echo '<p><a href="'.esc_url(wuai_sluyu_announcements_url()).'">全部公告 →</a></p>'.$args['after_widget'];
    }
}
add_action('widgets_init',function () { register_widget('Wuai_Sluyu_Announcements_Widget'); });
