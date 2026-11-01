<?php
if (!defined('ABSPATH')) { exit; }

// These are observed category paths, not guesses for arbitrary legacy URLs.
function wuai_sluyu_reference_aliases() {
    return [
        'links/tuijzd'=>['portal'=>'links','taxonomy'=>'sluyu_link_cat','type'=>'sluyu_link','slug'=>'tuijzd'],
        'links/youlian'=>['portal'=>'links','taxonomy'=>'sluyu_link_cat','type'=>'sluyu_link','slug'=>'youlian'],
        'links/bokz'=>['portal'=>'links','taxonomy'=>'sluyu_link_cat','type'=>'sluyu_link','slug'=>'bokz'],
        'links/qtsjaz'=>['portal'=>'links','taxonomy'=>'sluyu_link_cat','type'=>'sluyu_link','slug'=>'qtsjaz'],
        'newsflashes/60s'=>['portal'=>'newsflashes','taxonomy'=>'sluyu_news_cat','type'=>'sluyu_news','slug'=>'60s'],
        'newsflashes/sumchh'=>['portal'=>'newsflashes','taxonomy'=>'sluyu_news_cat','type'=>'sluyu_news','slug'=>'sumchh'],
        'newsflashes/yxizixu'=>['portal'=>'newsflashes','taxonomy'=>'sluyu_news_cat','type'=>'sluyu_news','slug'=>'yxizixu'],
        'document/xzsnbs'=>['portal'=>'document','taxonomy'=>'sluyu_doc_cat','type'=>'sluyu_doc','slug'=>'xzsnbs'],
        'document/syjds'=>['portal'=>'document','taxonomy'=>'sluyu_doc_cat','type'=>'sluyu_doc','slug'=>'syjds'],
        'document/cjyswt'=>['portal'=>'document','taxonomy'=>'sluyu_doc_cat','type'=>'sluyu_doc','slug'=>'cjyswt'],
        'document/zfsyw'=>['portal'=>'document','taxonomy'=>'sluyu_doc_cat','type'=>'sluyu_doc','slug'=>'zfsyw'],
        'circle/square'=>['portal'=>'circle','taxonomy'=>'sluyu_circle','type'=>'sluyu_topic','slug'=>'square'],
        'circle/sscosplay'=>['portal'=>'circle','taxonomy'=>'sluyu_circle','type'=>'sluyu_topic','slug'=>'sscosplay'],
    ];
}
function wuai_sluyu_reference_alias_error($status = 404) {
    return new WP_Error('reference_alias_' . (int) $status, $status === 400 ? '分类筛选参数无效，请重新选择。' : '此分类不存在或暂未公开。', ['status'=>$status]);
}
function wuai_sluyu_reference_alias_path($path) {
    if (!is_string($path) || strlen($path) > 100) { return ''; }
    foreach (wuai_sluyu_reference_aliases() as $route=>$spec) {
        if (in_array($path, [$route,$route.'/',$route.'.html',$route.'.html/'], true)) { return $route; }
    }
    return '';
}
function wuai_sluyu_reference_alias_scalar($input, $key, $default = '', $limit = 80) {
    if (!array_key_exists($key, $input)) { return $default; }
    if (!is_string($input[$key]) && !is_int($input[$key])) { return wuai_sluyu_reference_alias_error(400); }
    $value = wp_unslash((string) $input[$key]);
    if (strlen($value) > $limit * 4 || wp_check_invalid_utf8($value, true) !== $value || preg_match('/[\x00-\x1f\x7f]/', $value) || mb_strlen($value) > $limit) { return wuai_sluyu_reference_alias_error(400); }
    return trim(sanitize_text_field($value));
}
function wuai_sluyu_reference_alias_resolve($route, $input = []) {
    $map = wuai_sluyu_reference_aliases();
    if (!is_string($route) || !isset($map[$route])) { return false; }
    if (!is_array($input)) { return wuai_sluyu_reference_alias_error(400); }
    $spec = $map[$route]; $taxonomy = get_taxonomy($spec['taxonomy']);
    if (!$taxonomy || !$taxonomy->public || !in_array($spec['type'], $taxonomy->object_type, true)) { return wuai_sluyu_reference_alias_error(); }
    $term = get_term_by('slug', $spec['slug'], $spec['taxonomy']);
    if (!$term instanceof WP_Term || $term->taxonomy !== $spec['taxonomy'] || $term->slug !== $spec['slug']) { return wuai_sluyu_reference_alias_error(); }
    if ($spec['portal'] === 'circle') {
        $statuses = get_term_meta($term->term_id, '_wuai_circle_status', false);
        if (!function_exists('wuai_sluyu_circle_status') || count($statuses) > 1 || wuai_sluyu_circle_status($term) !== 'publish') { return wuai_sluyu_reference_alias_error(); }
    }
    $page = wuai_sluyu_reference_alias_scalar($input, 'pg', '1', 6);
    if (is_wp_error($page) || !preg_match('/^[1-9][0-9]{0,5}$/D', $page) || (int) $page > 100000) { return wuai_sluyu_reference_alias_error(400); }
    $query = ['group'=>(string) $term->term_id,'pg'=>(string) $page];
    foreach (['group','circle_id'] as $key) {
        if (!array_key_exists($key, $input)) { continue; }
        $group = wuai_sluyu_reference_alias_scalar($input, $key, '', 10);
        if (is_wp_error($group) || $group !== (string) $term->term_id) { return wuai_sluyu_reference_alias_error(400); }
    }
    if ($spec['portal'] === 'circle') {
        foreach (['kind'=>['','topic','question','poll','guess','bounty'],'sort'=>['latest','featured','likes','comments']] as $key=>$allowed) {
            $value = wuai_sluyu_reference_alias_scalar($input, $key, $key === 'sort' ? 'latest' : '', 16);
            if (is_wp_error($value) || !in_array($value, $allowed, true)) { return wuai_sluyu_reference_alias_error(400); }
            $query[$key] = $value;
        }
    } elseif ($spec['portal'] === 'document') {
        $search = wuai_sluyu_reference_alias_scalar($input, 'doc_search');
        if (is_wp_error($search)) { return $search; } $query['doc_search'] = $search;
    }
    return array_merge($spec, ['route'=>$route,'term_id'=>(int) $term->term_id,'name'=>$term->name,'query'=>$query]);
}
function wuai_sluyu_reference_alias_current() {
    $state = $GLOBALS['wuai_sluyu_reference_alias_state'] ?? null;
    return is_array($state) ? $state : false;
}
function wuai_sluyu_reference_alias_query_entry() {
    // With plain permalinks WP can leave $wp->request empty even for an unknown
    // physical path. A query alias is only an alternate homepage/index entry.
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (!is_string($uri) || $uri === '' || $uri[0] !== '/' || strpos($uri, '//') === 0) { return false; }
    $path = wp_parse_url($uri, PHP_URL_PATH); $home = wp_parse_url(home_url('/'), PHP_URL_PATH);
    if (!is_string($path) || !is_string($home)) { return false; }
    $base = rtrim($home, '/');
    return in_array($path, [$base,$base.'/',$base.'/index.php',$base.'/index.php/'], true);
}
function wuai_sluyu_reference_alias_request($vars) {
    global $wp;
    unset($GLOBALS['wuai_sluyu_reference_alias_state']);
    $path = isset($wp->request) && is_string($wp->request) ? $wp->request : '';
    $route = wuai_sluyu_reference_alias_path($path);
    $marker = $vars['wuai_reference_alias'] ?? '';
    if (!$route && $marker === '') { return $vars; }
    if (!$route && in_array($path, ['','index.php'], true) && wuai_sluyu_reference_alias_query_entry() && is_string($marker) && isset(wuai_sluyu_reference_aliases()[$marker])) { $route = $marker; }
    $state = $route ? wuai_sluyu_reference_alias_resolve($route, $_GET) : wuai_sluyu_reference_alias_error();
    $GLOBALS['wuai_sluyu_reference_alias_state'] = $state;
    // Do not carry p, preview, status, taxonomy, or another sluyu_page into the
    // portal query. Only an exact physical path or a homepage query alias wins.
    if (is_wp_error($state)) { return ['error'=>'404','wuai_reference_alias'=>$route]; }
    return ['sluyu_page'=>$state['portal'],'wuai_reference_alias'=>$route];
}
add_filter('query_vars', function ($vars) { $vars[] = 'wuai_reference_alias'; return $vars; });
add_action('init', function () {
    foreach (wuai_sluyu_reference_aliases() as $route=>$spec) { add_rewrite_rule('^' . preg_quote($route, '#') . '(?:\.html)?/?$', 'index.php?wuai_reference_alias=' . $route, 'top'); }
}, 25);
add_filter('request', 'wuai_sluyu_reference_alias_request', 5);
add_action('pre_get_posts', function ($query) {
    if (!$query->is_main_query() || !wuai_sluyu_reference_alias_current()) { return; }
    $query->set('post_type', 'page'); $query->set('post_status', 'publish'); $query->set('has_password', false);
    if (!$query->get('page_id')) { $query->set('post__in', [0]); }
}, 5);
add_action('template_redirect', function () {
    if (!array_key_exists('wuai_sluyu_reference_alias_state', $GLOBALS)) { return; }
    $state = $GLOBALS['wuai_sluyu_reference_alias_state'];
    if (is_array($state)) { $state = wuai_sluyu_reference_alias_resolve($state['route'], $_GET); $GLOBALS['wuai_sluyu_reference_alias_state'] = $state; }
    if (!is_wp_error($state)) { return; }
    global $wp_query;
    $wp_query->set('sluyu_page', ''); $wp_query->set('page_id', 0); $wp_query->set_404();
    nocache_headers(); status_header((int) $state->get_error_data()['status']);
}, 0);
add_filter('redirect_canonical', function ($url) { return array_key_exists('wuai_sluyu_reference_alias_state', $GLOBALS) ? false : $url; }, 100);

// Called first by wuai_sluyu_query(). No mutation of request superglobals is
// needed, and a query string cannot switch the category encoded by the path.
function wuai_sluyu_reference_alias_query($key, $default, &$handled) {
    $handled = array_key_exists('wuai_sluyu_reference_alias_state', $GLOBALS);
    $state = wuai_sluyu_reference_alias_current();
    return $state && array_key_exists($key, $state['query']) ? $state['query'][$key] : $default;
}
function wuai_sluyu_reference_alias_args($state) {
    if ($state['portal'] === 'circle') { $args = wuai_sluyu_circle_feed_query_args($state['term_id'], $state['query']['kind'], $state['query']['sort'], (int) $state['query']['pg']); }
    else { $args = ['post_type'=>$state['type'],'post_status'=>'publish','has_password'=>false,'posts_per_page'=>12,'paged'=>(int) $state['query']['pg'],'orderby'=>['date'=>'DESC','ID'=>'DESC'],'ignore_sticky_posts'=>true]; }
    $args['tax_query'] = [['taxonomy'=>$state['taxonomy'],'terms'=>[$state['term_id']],'include_children'=>false]];
    $args['suppress_filters'] = false; $args['cache_results'] = false; $args['wuai_reference_alias_scope'] = $state['route'];
    if (!empty($state['query']['doc_search'])) { $args['s'] = $state['query']['doc_search']; }
    return $args;
}
function wuai_sluyu_reference_alias_feed_args($args, $key) {
    $state = wuai_sluyu_reference_alias_current();
    return $state && $state['portal'] === $key ? wuai_sluyu_reference_alias_args($state) : $args;
}
function wuai_sluyu_reference_alias_clauses($clauses, $query) {
    global $wpdb;
    $route = $query->get('wuai_reference_alias_scope'); $map = wuai_sluyu_reference_aliases();
    if (!is_string($route) || !isset($map[$route])) { return $clauses; }
    $spec = $map[$route];
    $clauses['where'] .= $wpdb->prepare(" AND $wpdb->posts.post_type=%s AND $wpdb->posts.post_status='publish' AND $wpdb->posts.post_password='' AND EXISTS (SELECT 1 FROM $wpdb->term_relationships ra_rel INNER JOIN $wpdb->term_taxonomy ra_tax ON ra_tax.term_taxonomy_id=ra_rel.term_taxonomy_id INNER JOIN $wpdb->terms ra_term ON ra_term.term_id=ra_tax.term_id WHERE ra_rel.object_id=$wpdb->posts.ID AND ra_tax.taxonomy=%s AND ra_term.slug=%s)", $spec['type'], $spec['taxonomy'], $spec['slug']);
    if ($spec['portal'] !== 'circle') { return $clauses; }
    // Recheck every related circle at SQL execution, including a second hidden
    // circle and a status changed after request resolution. No editor bypass.
    $clauses['where'] .= " AND NOT EXISTS (SELECT 1 FROM $wpdb->postmeta ra_hold WHERE ra_hold.post_id=$wpdb->posts.ID AND ra_hold.meta_key='_wuai_circle_review_hold')";
    $clauses['where'] .= $wpdb->prepare(" AND NOT EXISTS (SELECT 1 FROM $wpdb->term_relationships ra_cr INNER JOIN $wpdb->term_taxonomy ra_ct ON ra_ct.term_taxonomy_id=ra_cr.term_taxonomy_id INNER JOIN $wpdb->terms ra_c ON ra_c.term_id=ra_ct.term_id WHERE ra_cr.object_id=$wpdb->posts.ID AND ra_ct.taxonomy='sluyu_circle' AND (EXISTS (SELECT 1 FROM $wpdb->termmeta ra_bad WHERE ra_bad.term_id=ra_c.term_id AND ra_bad.meta_key='_wuai_circle_status' AND HEX(ra_bad.meta_value)<>HEX('publish')) OR (SELECT COUNT(*) FROM $wpdb->termmeta ra_count WHERE ra_count.term_id=ra_c.term_id AND ra_count.meta_key='_wuai_circle_status')>1 OR ((ra_c.slug LIKE %s OR ra_c.slug REGEXP %s) AND NOT EXISTS (SELECT 1 FROM $wpdb->termmeta ra_ok WHERE ra_ok.term_id=ra_c.term_id AND ra_ok.meta_key='_wuai_circle_status' AND HEX(ra_ok.meta_value)=HEX('publish')))))", $wpdb->esc_like(wuai_sluyu_circle_request_prefix()) . '%', wuai_sluyu_circle_old_request_pattern());
    return $clauses;
}
add_filter('posts_clauses', 'wuai_sluyu_reference_alias_clauses', 65, 2);
function wuai_sluyu_reference_alias_url($state, $extra = []) {
    $url = get_option('permalink_structure') ? home_url('/' . $state['route'] . '/') : add_query_arg('wuai_reference_alias', $state['route'], home_url('/'));
    $args = $state['query']; unset($args['group']);
    return add_query_arg(array_merge($args, $extra), $url);
}
add_filter('get_pagenum_link', function ($url) { $state = wuai_sluyu_reference_alias_current(); return $state ? wuai_sluyu_reference_alias_url($state) : $url; }, 100);
function wuai_sluyu_reference_alias_pagination($total, $key) {
    $state = wuai_sluyu_reference_alias_current();
    if (!$state || $state['portal'] !== $key) { return false; }
    if ((int) $total > 1) {
        echo '<nav class="sluyu-portal-pagination" aria-label="分页">' . wp_kses_post(paginate_links(['base'=>wuai_sluyu_reference_alias_url($state, ['pg'=>'%#%']),'format'=>'','current'=>(int) $state['query']['pg'],'total'=>(int) $total,'add_args'=>[],'prev_text'=>'上一页','next_text'=>'下一页'])) . '</nav>';
    }
    return true;
}
function wuai_sluyu_reference_documents($input = null) {
    $input = $input === null ? $_GET : $input;
    if (!is_array($input)) { return wuai_sluyu_reference_alias_error(400); }
    $alias = wuai_sluyu_reference_alias_current();
    if ($alias && $alias['portal'] === 'document') {
        $alias = wuai_sluyu_reference_alias_resolve($alias['route'], $input);
        if (is_wp_error($alias)) { return $alias; }
        return ['args'=>wuai_sluyu_reference_alias_args($alias),'search'=>$alias['query']['doc_search'],'group'=>$alias['term_id'],'name'=>$alias['name'],'page'=>(int) $alias['query']['pg'],'alias'=>$alias];
    }
    if (is_wp_error($GLOBALS['wuai_sluyu_reference_alias_state'] ?? null)) { return $GLOBALS['wuai_sluyu_reference_alias_state']; }
    $search = wuai_sluyu_reference_alias_scalar($input, 'doc_search');
    $group = wuai_sluyu_reference_alias_scalar($input, 'group', '0', 10);
    $page = wuai_sluyu_reference_alias_scalar($input, 'pg', '1', 6);
    foreach ([$search,$group,$page] as $value) { if (is_wp_error($value)) { return $value; } }
    if (!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $group) || !preg_match('/^[1-9][0-9]{0,5}$/D', $page) || (int) $page > 100000) { return wuai_sluyu_reference_alias_error(400); }
    $taxonomy = get_taxonomy('sluyu_doc_cat');
    if (!$taxonomy || !$taxonomy->public || !in_array('sluyu_doc', $taxonomy->object_type, true) || !post_type_exists('sluyu_doc')) { return wuai_sluyu_reference_alias_error(); }
    $term = (int) $group ? get_term((int) $group, 'sluyu_doc_cat') : false;
    if ((int) $group && !$term instanceof WP_Term) { return wuai_sluyu_reference_alias_error(); }
    $args = ['post_type'=>'sluyu_doc','post_status'=>'publish','has_password'=>false,'posts_per_page'=>12,'paged'=>(int) $page,'orderby'=>['date'=>'DESC','ID'=>'DESC'],'ignore_sticky_posts'=>true,'suppress_filters'=>false,'cache_results'=>false,'wuai_reference_document_scope'=>true];
    if ($term) { $args['tax_query'] = [['taxonomy'=>'sluyu_doc_cat','terms'=>[(int) $term->term_id],'include_children'=>false]]; }
    if ($search !== '') { $args['s'] = $search; }
    return ['args'=>$args,'search'=>$search,'group'=>(int) $group,'name'=>$term ? $term->name : '全部帮助文档','page'=>(int) $page,'alias'=>false];
}
add_filter('posts_clauses', function ($clauses, $query) {
    if ($query->get('wuai_reference_document_scope') !== true) { return $clauses; }
    global $wpdb;
    $clauses['where'] .= " AND $wpdb->posts.post_type='sluyu_doc' AND $wpdb->posts.post_status='publish' AND $wpdb->posts.post_password=''";
    return $clauses;
}, 65, 2);
function wuai_sluyu_reference_documents_url($config, $extra = []) {
    if ($config['alias']) { return wuai_sluyu_reference_alias_url($config['alias'], $extra); }
    return wuai_sluyu_portal_url('document', array_merge(['doc_search'=>$config['search'],'group'=>$config['group'],'pg'=>$config['page']], $extra));
}
function wuai_sluyu_reference_documents_pagination($total, $config) {
    if ((int) $total < 2) { return; }
    $clean = static function () use ($config) { return wuai_sluyu_reference_documents_url($config); };
    add_filter('get_pagenum_link', $clean, PHP_INT_MAX);
    try { $links = paginate_links(['base'=>wuai_sluyu_reference_documents_url($config, ['pg'=>'%#%']),'format'=>'','current'=>$config['page'],'total'=>(int) $total,'add_args'=>[],'prev_text'=>'上一页','next_text'=>'下一页']); }
    finally { remove_filter('get_pagenum_link', $clean, PHP_INT_MAX); }
    if ($links) { echo '<nav class="sluyu-portal-pagination" aria-label="文档分页">' . wp_kses_post($links) . '</nav>'; }
}
function wuai_sluyu_reference_documents_faq() {
    $sections = [
        '下载帮助'=>['如何下载资源？'=>'打开资源文章，阅读说明后点击“立即下载”。有提取码时点击复制。会员或登录资源需先满足页面标示的权限。','压缩包如何解压？'=>'先将分卷全部下载到同一目录，在电脑上使用支持该格式的解压工具，从首个分卷开始解压。手机使用系统文件应用或可信的解压工具。','链接失效怎么办？'=>'在发布中心选择工单 / 资源反馈，附上文章地址、错误提示与设备信息。不要提交网盘账号密码。'],
        '账户与积分'=>['忘记密码怎么办？'=>'在登录页面点击“忘记密码”，通过注册邮箱重置。邮件无法收到时检查垃圾邮件并联系管理员。','积分如何获取？'=>'每日签到和完成任务可获取积分，奖励以任务中心显示为准。重复签到和重复领取不会重复发奖。','如何申请认证？'=>'在认证服务页面提交称号与公开证明材料，审核结果可在消息中心查看。'],
        '订单与会员'=>['付款后在哪里查看？'=>'前往我的订单查看支付状态。只有支付平台确认付款、订单进入已付款状态后才会开通会员，浏览器跳转不是支付凭证。','遇到支付失败怎么办？'=>'不要反复付款。保留订单号与付款记录，通过工单联系管理员核查。','如何申请退款？'=>'以商品页列明的退款政策及适用法律为准，通过订单渠道联系商家处理。'],
    ];
    echo '<section class="sluyu-panel"><h2>基础使用提示</h2><p>以下是内置常见问题，不计入已发布文档数量或服务器搜索结果。</p><label class="sluyu-help-search">筛选内置提示<input type="search" data-help-search placeholder="例如：下载、密码、订单"></label></section>';
    foreach ($sections as $title=>$items) {
        echo '<section class="sluyu-panel sluyu-help-group"><h3>' . esc_html($title) . '</h3>';
        foreach ($items as $question=>$answer) { echo '<details class="sluyu-faq"><summary>' . esc_html($question) . '</summary><p>' . esc_html($answer) . '</p></details>'; }
        echo '</section>';
    }
}
function wuai_sluyu_reference_documents_view() {
    $config = wuai_sluyu_reference_documents();
    if (is_wp_error($config)) {
        echo '<section class="sluyu-panel"><h2>帮助文档</h2><p role="alert">' . esc_html($config->get_error_message()) . '</p><a href="' . esc_url(wuai_sluyu_portal_url('document')) . '">返回全部帮助</a></section>';
        wuai_sluyu_reference_documents_faq(); return;
    }
    echo '<section class="sluyu-panel"><h2>' . esc_html($config['name']) . '</h2><p>搜索已发布且无密码的帮助文档；不搜索草稿、私密文档或其他内容类型。</p><nav class="sluyu-filter-tabs"><a href="' . esc_url(wuai_sluyu_portal_url('document')) . '">全部文档</a>';
    $terms = get_terms(['taxonomy'=>'sluyu_doc_cat','hide_empty'=>false]);
    if (!is_wp_error($terms)) { foreach ($terms as $term) { echo '<a href="' . esc_url(wuai_sluyu_portal_url('document', ['group'=>$term->term_id])) . '">' . esc_html($term->name) . '</a>'; } }
    echo '</nav><form class="sluyu-filter-form" method="get" action="' . esc_url(wuai_sluyu_reference_documents_url($config, ['pg'=>false,'doc_search'=>false])) . '">';
    if (!get_option('permalink_structure')) { echo $config['alias'] ? '<input type="hidden" name="wuai_reference_alias" value="' . esc_attr($config['alias']['route']) . '">' : '<input type="hidden" name="sluyu_page" value="document">'; }
    if ($config['group'] && !$config['alias']) { echo '<input type="hidden" name="group" value="' . (int) $config['group'] . '">'; }
    echo '<label>搜索公开文档<input type="search" name="doc_search" maxlength="80" value="' . esc_attr($config['search']) . '"></label><button class="sluyu-action-plain">搜索</button></form></section>';
    $query = new WP_Query($config['args']);
    echo '<section class="sluyu-panel"><h3>已发布文档</h3><p>共 ' . (int) $query->found_posts . ' 篇符合条件的公开文档。</p>';
    if (!$query->posts) { echo '<p class="sluyu-empty">没有符合筛选条件的公开文档。</p>'; }
    else { foreach ($query->posts as $post) { echo '<p><a href="' . esc_url(get_permalink($post)) . '">' . esc_html($post->post_title) . '</a></p>'; } }
    echo '</section>'; wuai_sluyu_reference_documents_pagination($query->max_num_pages, $config);
    wuai_sluyu_reference_documents_faq();
}
add_filter('pre_get_document_title', function ($title) { $state = wuai_sluyu_reference_alias_current(); return $state ? $state['name'] . ' - ' . get_bloginfo('name') : $title; }, 30);
