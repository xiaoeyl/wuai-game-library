<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_portal_pages() {
    return [
        'account' => ['个人中心', '你的创作、收藏与账户资料', 'user-heart-line'],
        'mission' => ['每日签到', '每天相遇，记录热爱', 'gift-2-line'],
        'task' => ['任务中心', '完成真实任务，领取积分奖励', 'checkbox-circle-line'],
        'gold' => ['财富管理', '积分明细与账户记录', 'coin-line'],
        'circle' => ['茶话会', '分享日常，交流问题，一起投票', 'chat-smile-2-line'],
        'circles' => ['圈子广场', '寻找同好，加入或创建你的茶话会', 'chat-heart-line'],
        'newsflashes' => ['解忧资讯', '发现新鲜事，分享值得关注的消息', 'notification-3-line'],
        'links' => ['网址导航', '收藏好网站，也欢迎推荐你的站点', 'links-line'],
        'document' => ['帮助中心', '下载、解压、账户与订单使用指南', 'book-open-line'],
        'directmessage' => ['往来私信', '只属于你与收件人的对话', 'mail-send-line'],
        'message' => ['消息通知', '查看申请、互动和审核进度', 'notification-3-line'],
        'verify' => ['认证服务', '展示你的专长，申请专属认证称号', 'shield-user-line'],
        'dark-room' => ['小黑屋', '共同维护友善、有序的社区', 'shield-user-line'],
        'shop' => ['商城店铺', '商品、库存与价格以商家发布为准', 'shopping-bag-line'],
        'carts' => ['购物车', '确认商品，安全结算', 'shopping-cart-2-line'],
        'orders' => ['我的订单', '订单进度、付款与下载记录', 'file-list-line'],
        'coupons' => ['优惠券', '查看活动优惠，结算时使用', 'coupon-2-line'],
        'vips' => ['会员中心', '选择适合你的会员计划', 'vip-crown-line'],
        'vipjiesangsa' => ['会员说明', '权益、有效期与购买须知', 'vip-crown-line'],
        'gyuwo' => ['关于本站', '记录每一份热爱，分享每一次发现', 'heart-line'],
        'yhusm' => ['用户说明', '使用本站前请阅读本说明', 'file-text-line'],
        'mianzesm' => ['免责声明', '尊重原创，规范使用', 'shield-user-line'],
        'submit' => ['发布中心', '投稿、发布话题、推荐网站与提交工单', 'edit-line'],
        'tools' => ['实用工具', '二维码、文本处理与密码生成，全部在本地运行', 'tools-line'],
        'more' => ['发现更多', '站内服务与频道一览', 'apps-line'],
    ];
}

function wuai_sluyu_channels() {
    return [
        'webxw' => ['宅趣资讯', '资讯', ['hhuaywj'=>'绘画源文件','dmzt'=>'动漫杂谈','dmzb'=>'动漫周边','cosjxuan'=>'cos鉴赏']],
        'pcgame' => ['游戏资源', '游戏资源', ['dzmxss'=>'动作冒险','jsbyu'=>'角色扮演','qzsji'=>'枪战射击','tiyujs'=>'体育竞速','kbjs'=>'恐怖惊悚','mnijys'=>'模拟经营','rxgdo'=>'热血格斗','xxyizhi'=>'休闲益智','jsusak'=>'即时战略','cyilv'=>'策略战棋','mfgf'=>'免费国风','ljzhhe'=>'联机整合']],
        'tuimg' => ['次元图包', '图包', ['pzmtus'=>'P站插画','dmanmeitu'=>'动漫美图','yxtubao'=>'动漫图包','pzmjiy'=>'画师图集']],
        'xiezhenji' => ['写真美图', '美图', ['cosplay'=>'Cosplay','shyin'=>'摄影图册']],
        'cosheji' => ['写真合集', '合集', ['coser'=>'Coser合集','wmq'=>'微密圈合集','xiurw'=>'秀人网合集','smjg'=>'私模机构']],
    ];
}

function wuai_sluyu_portal_aliases() {
    return ['today'=>'mission','mission/today'=>'mission','vip'=>'vips','user'=>'account','forums'=>'circle','all-circles'=>'circles','create-circle'=>'circles','circle-users'=>'circle','write'=>'submit','po-ask'=>'submit','link-register'=>'submit','about'=>'gyuwo','help'=>'document','terms'=>'yhusm','disclaimer'=>'mianzesm','cart'=>'carts','coupon'=>'coupons','messages'=>'directmessage','psuaujs'=>'tuimg','cat-group/psuaujs'=>'tuimg','biaotibg4'=>'xiezhenji','cat-group/biaotibg4'=>'xiezhenji','xiezhenji1'=>'xiezhenji'];
}

function wuai_sluyu_portal_routes() {
    $routes = array_fill_keys(array_keys(wuai_sluyu_portal_pages()), true);
    foreach (wuai_sluyu_channels() as $slug => $channel) {
        $routes[$slug] = true;
        foreach ($channel[2] as $child => $name) { $routes[$child] = true; $routes[$slug . '/' . $child] = true; }
    }
    foreach (wuai_sluyu_portal_aliases() as $alias => $target) { $routes[$alias] = true; }
    return $routes;
}

function wuai_sluyu_portal_key($key = '') {
    $query = get_query_var('sluyu_page');
    $key = is_scalar($key) && $key ? (string)$key : (is_scalar($query) ? (string)$query : '');
    if (!$key && function_exists('is_shop') && is_shop()) { $key = 'shop'; }
    if (!$key && is_page()) {
        $page = get_queried_object();
        if (!$page instanceof WP_Post) { return ''; }
        $key = get_post_meta($page->ID, '_wuai_portal_page', true) ?: $page->post_name;
    }
    $aliases = wuai_sluyu_portal_aliases();
    $key = isset($aliases[$key]) ? $aliases[$key] : $key;
    return isset(wuai_sluyu_portal_routes()[$key]) ? $key : '';
}

function wuai_sluyu_portal_url($key, $args = []) {
    $key = wuai_sluyu_portal_key($key) ?: 'more';
    $page = get_page_by_path($key);
    if (!get_option('permalink_structure')) { $url = add_query_arg('sluyu_page', $key, home_url('/')); }
    elseif ($page && $page->post_status === 'publish') { $url = get_permalink($page); }
    else { $url = get_option('permalink_structure') ? home_url('/' . $key . '/') : add_query_arg('sluyu_page', $key, home_url('/')); }
    return $args ? add_query_arg($args, $url) : $url;
}

add_filter('query_vars', function ($vars) { $vars[] = 'sluyu_page'; return $vars; });
add_action('init', function () {
    foreach (array_keys(wuai_sluyu_portal_routes()) as $route) {
        add_rewrite_rule('^' . preg_quote($route, '#') . '(?:\.html|/)?$', 'index.php?sluyu_page=' . $route, 'top');
    }
    $public = ['sluyu_topic'=>'茶话会话题','sluyu_news'=>'解忧资讯','sluyu_link'=>'网址导航','sluyu_doc'=>'帮助文档'];
    foreach ($public as $type => $label) {
        register_post_type($type, ['labels'=>['name'=>$label,'singular_name'=>$label,'add_new_item'=>'添加' . $label,'edit_item'=>'编辑' . $label], 'public'=>true,'show_in_rest'=>true,'has_archive'=>false,'menu_icon'=>'dashicons-format-chat','supports'=>['title','editor','author','thumbnail','comments','revisions'],'rewrite'=>['slug'=>str_replace('sluyu_', '', $type)]]);
    }
    foreach (['sluyu_verify'=>'认证申请','sluyu_ticket'=>'工单与举报'] as $type => $label) {
        register_post_type($type, ['labels'=>['name'=>$label,'singular_name'=>$label], 'public'=>false,'show_ui'=>true,'show_in_rest'=>false,'supports'=>['title','editor','author'],'capabilities'=>array_fill_keys(['edit_post','read_post','delete_post','edit_posts','edit_others_posts','publish_posts','read_private_posts','delete_posts','delete_private_posts','delete_published_posts','delete_others_posts','edit_private_posts','edit_published_posts','create_posts'], 'manage_options'),'map_meta_cap'=>false]);
    }
    foreach (['sluyu_circle'=>['茶话会分组','sluyu_topic'],'sluyu_link_cat'=>['导航分类','sluyu_link'],'sluyu_doc_cat'=>['帮助分类','sluyu_doc'],'sluyu_news_cat'=>['快讯分类','sluyu_news']] as $tax => $config) {
        register_taxonomy($tax, $config[1], ['label'=>$config[0],'public'=>true,'hierarchical'=>true,'show_in_rest'=>true]);
    }
}, 20);

add_filter('request', function ($vars) {
    if (!empty($vars['sluyu_page'])) {
        if (!is_scalar($vars['sluyu_page'])) { $vars['sluyu_page'] = ''; return $vars; }
        $key = wuai_sluyu_portal_key($vars['sluyu_page']);
        $page = get_page_by_path($key);
        if ($page && $page->post_status === 'publish') { $vars['page_id'] = $page->ID; }
    }
    return $vars;
});
add_action('template_redirect', function () {
    if (is_user_logged_in()) { nocache_headers(); }
    if (is_singular('post') && wuai_sluyu_post_meta(get_queried_object_id(),'download_access','public') !== 'public') { nocache_headers(); }
    if (!wuai_sluyu_portal_key()) { return; }
    if (get_query_var('sluyu_page')) { global $wp_query; $wp_query->is_404 = false; $wp_query->is_home = false; status_header(200); }
    if (is_user_logged_in() || in_array(wuai_sluyu_portal_key(), ['account','gold','directmessage','message','orders','carts','task','verify'], true)) { nocache_headers(); }
}, 1);
add_filter('redirect_canonical', function ($url) { return get_query_var('sluyu_page') ? false : $url; });
add_filter('template_include', function ($template) { return wuai_sluyu_portal_key() ? WUAI_SLUYU_DIR . '/portal.php' : $template; }, 100);
add_filter('pre_get_document_title', function ($title) {
    $key = wuai_sluyu_portal_key();
    if (!$key) { return $title; }
    $pages = wuai_sluyu_portal_pages();
    return (isset($pages[$key]) ? $pages[$key][0] : wuai_sluyu_channel_info($key)[0]) . ' - ' . get_bloginfo('name');
});
add_shortcode('sluyu_portal', function ($atts) {
    $atts = shortcode_atts(['page'=>'more'], $atts);
    $key = wuai_sluyu_portal_key($atts['page']);
    if (!$key) { return ''; }
    ob_start(); wuai_sluyu_render_portal($key); return ob_get_clean();
});

function wuai_sluyu_channel_info($key) {
    foreach (wuai_sluyu_channels() as $slug => $channel) {
        if ($key === $slug) { return [$channel[0], $channel[1], $slug, $channel[2]]; }
        foreach ($channel[2] as $child => $name) {
            if ($key === $child || $key === $slug . '/' . $child) { return [$name, $name, $slug, $channel[2]]; }
        }
    }
    return ['发现更多', '', '', []];
}

add_action('admin_init', function () {
    if (get_option('wuai_sluyu_routes_version') !== WUAI_SLUYU_VERSION && current_user_can('manage_options')) {
        flush_rewrite_rules(false);
        update_option('wuai_sluyu_routes_version', WUAI_SLUYU_VERSION, false);
    }
});
add_action('admin_post_wuai_sluyu_install_pages', function () {
    if (!current_user_can('manage_options')) { wp_die('无权操作', '', ['response'=>403]); }
    check_admin_referer('wuai_sluyu_install_pages');
    foreach (wuai_sluyu_portal_pages() as $slug => $page) {
        if (get_page_by_path($slug)) { continue; }
        wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_name'=>$slug,'post_title'=>$page[0],'post_content'=>'','meta_input'=>['_wuai_portal_page'=>$slug]]);
    }
    foreach (wuai_sluyu_channels() as $slug => $channel) {
        $parent = get_term_by('name', $channel[1], 'category');
        if (!$parent) { $result = wp_insert_term($channel[1], 'category', ['slug'=>$slug]); $parent = !is_wp_error($result) ? get_term($result['term_id']) : null; }
        if (!$parent) { continue; }
        foreach ($channel[2] as $child => $name) {
            if (!get_term_by('name', $name, 'category')) { wp_insert_term($name, 'category', ['slug'=>$child, 'parent'=>$parent->term_id]); }
        }
    }
    flush_rewrite_rules(false);
    wp_safe_redirect(admin_url('admin.php?page=wuai-sluyu-services&created=1')); exit;
});
