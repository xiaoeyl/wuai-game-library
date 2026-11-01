<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_discovery_error($message) {
    return new WP_Error('discovery_input', $message, ['status'=>400]);
}

function wuai_sluyu_discovery_scalar($input, $key, $default = '', $limit = 80) {
    if (!is_array($input)) { return wuai_sluyu_discovery_error('筛选参数格式不正确。'); }
    if (!array_key_exists($key, $input)) { return $default; }
    if (!is_string($input[$key]) && !is_int($input[$key])) { return wuai_sluyu_discovery_error('筛选参数必须是单个文本值。'); }
    $value = wp_unslash((string)$input[$key]);
    if (strlen($value) > $limit * 4 || wp_check_invalid_utf8($value, true) !== $value || preg_match('/[\x00-\x1f\x7f]/', $value) || mb_strlen($value) > $limit) {
        return wuai_sluyu_discovery_error('筛选内容过长或包含不支持的字符。');
    }
    return trim(sanitize_text_field($value));
}

function wuai_sluyu_discovery_page($input) {
    $page = wuai_sluyu_discovery_scalar($input, 'pg', '1', 6);
    if (is_wp_error($page)) { return $page; }
    if (!preg_match('/^[1-9][0-9]{0,5}$/D', $page) || (int)$page > 100000) { return wuai_sluyu_discovery_error('页码不正确。'); }
    return (int)$page;
}

function wuai_sluyu_discovery_public_sql($alias) {
    return "$alias.post_type='post' AND $alias.post_status='publish' AND $alias.post_password=''";
}

function wuai_sluyu_discovery_pagination($total, $key, $page = 1, $extra = []) {
    $total = max(0, (int)$total); $page = max(1, min(100000, (int)$page));
    if ($total < 2) { return; }
    if (!is_array($extra)) { return; }
    $args = [];
    foreach (['tag_search','archiveSearch','post_order','seed'] as $name) {
        if (isset($extra[$name]) && (is_string($extra[$name]) || is_int($extra[$name]))) { $args[$name] = $extra[$name]; }
    }
    $base = add_query_arg(array_merge($args,['pg'=>'%#%']),wuai_sluyu_portal_url($key));
    // Core otherwise reintroduces old query arguments from the current URL.
    $clean = static function ($url) { return home_url('/'); };
    add_filter('get_pagenum_link',$clean,PHP_INT_MAX);
    try {
        $links = paginate_links(['base'=>$base, 'format'=>'', 'current'=>$page, 'total'=>$total, 'add_args'=>[], 'prev_text'=>'上一页', 'next_text'=>'下一页']);
    } finally {
        remove_filter('get_pagenum_link',$clean,PHP_INT_MAX);
    }
    if ($links) { echo '<nav class="sluyu-portal-pagination" aria-label="分页">' . wp_kses_post($links) . '</nav>'; }
}

/** Only ordinary, published, password-free posts contribute names or counts. */
function wuai_sluyu_discovery_tags($input = null, $per_page = 24) {
    global $wpdb;
    $input = $input === null ? $_GET : $input;
    $search = wuai_sluyu_discovery_scalar($input, 'tag_search');
    $page = wuai_sluyu_discovery_page($input);
    if (is_wp_error($search)) { return $search; }
    if (is_wp_error($page)) { return $page; }
    $per_page = max(1, min(60, (int)$per_page));
    $where = "dt.taxonomy='post_tag' AND " . wuai_sluyu_discovery_public_sql('dp');
    if ($search !== '') { $where .= $wpdb->prepare(' AND dterm.name LIKE %s', '%' . $wpdb->esc_like($search) . '%'); }
    $from = "$wpdb->terms dterm INNER JOIN $wpdb->term_taxonomy dt ON dt.term_id=dterm.term_id INNER JOIN $wpdb->term_relationships dr ON dr.term_taxonomy_id=dt.term_taxonomy_id INNER JOIN $wpdb->posts dp ON dp.ID=dr.object_id WHERE $where";
    $total = $wpdb->get_var("SELECT COUNT(DISTINCT dterm.term_id) FROM $from");
    if ($wpdb->last_error || $total === null) { return new WP_Error('discovery_storage', '暂时无法核对公开标签，请稍后重试。'); }
    $rows = $wpdb->get_results($wpdb->prepare("SELECT dterm.term_id, COUNT(DISTINCT dp.ID) AS public_count FROM $from GROUP BY dterm.term_id,dterm.name ORDER BY dterm.name ASC,dterm.term_id ASC LIMIT %d OFFSET %d", $per_page, ($page - 1) * $per_page), ARRAY_A);
    if ($wpdb->last_error || !is_array($rows)) { return new WP_Error('discovery_storage', '暂时无法读取公开标签，请稍后重试。'); }
    $items = [];
    if ($rows) {
        $counts = [];
        foreach ($rows as $row) { $counts[(int)$row['term_id']] = (int)$row['public_count']; }
        $terms = get_terms(['taxonomy'=>'post_tag', 'hide_empty'=>true, 'include'=>array_keys($counts), 'orderby'=>'include', 'number'=>$per_page]);
        if (is_wp_error($terms)) { return new WP_Error('discovery_storage', '暂时无法读取公开标签，请稍后重试。'); }
        foreach ($terms as $term) {
            if (!isset($counts[$term->term_id]) || $counts[$term->term_id] < 1) { continue; }
            $url = get_tag_link($term->term_id);
            if (is_wp_error($url)) { continue; }
            $items[] = ['id'=>(int)$term->term_id, 'name'=>$term->name, 'url'=>$url, 'count'=>$counts[$term->term_id]];
        }
    }
    return ['items'=>$items, 'total'=>(int)$total, 'pages'=>(int)ceil((int)$total / $per_page), 'page'=>$page, 'search'=>$search];
}

function wuai_sluyu_view_tags() {
    $data = wuai_sluyu_discovery_tags();
    echo '<section class="sluyu-panel"><h2>全部标签</h2><p>按标签发现已发布的公开文章；数量不包含私密、密码保护或圈子话题。</p>';
    if (is_wp_error($data)) { echo '<p class="sluyu-notice" role="alert">' . esc_html($data->get_error_message()) . '</p></section>'; return; }
    echo '<form class="sluyu-filter-form" method="get" action="' . esc_url(wuai_sluyu_portal_url('tags')) . '">';
    if (!get_option('permalink_structure')) { echo '<input type="hidden" name="sluyu_page" value="tags">'; }
    echo '<label>搜索标签<input type="search" name="tag_search" maxlength="80" value="' . esc_attr($data['search']) . '" placeholder="输入标签名称"></label><button class="sluyu-action-plain">查找</button></form>';
    if (!$data['items']) { echo '<p class="sluyu-empty">没有找到可公开展示的标签。</p>'; }
    else {
        echo '<div class="sluyu-filter-tabs sluyu-tag-directory">';
        foreach ($data['items'] as $item) { echo '<a href="' . esc_url($item['url']) . '">' . esc_html($item['name']) . ' <span>' . (int)$item['count'] . ' 篇</span></a>'; }
        echo '</div>';
    }
    if ($data['pages'] > 1) {
        wuai_sluyu_discovery_pagination($data['pages'],'tags',$data['page'],['tag_search'=>$data['search']]);
    }
    echo '</section>';
}

function wuai_sluyu_discovery_likes_sql() {
    global $wpdb;
    return "(SELECT COUNT(DISTINCT dl.user_id) FROM $wpdb->usermeta dl INNER JOIN $wpdb->users du ON du.ID=dl.user_id WHERE dl.meta_key=CONCAT('_wuai_sluyu_like_',$wpdb->posts.ID) AND dl.meta_value='1')";
}

function wuai_sluyu_discovery_comments_sql() {
    global $wpdb;
    return "(SELECT COUNT(*) FROM $wpdb->comments dc WHERE dc.comment_post_ID=$wpdb->posts.ID AND dc.comment_approved='1' AND dc.comment_type IN ('','comment'))";
}

function wuai_sluyu_discovery_views_sql() {
    global $wpdb;
    // Existing frontend view counters are page visits, not unique readers.
    // Duplicated, negative, malformed, or overflowing metadata is not a count.
    return "(SELECT CASE WHEN COUNT(*)=1 THEN MAX(CASE WHEN dv.meta_value REGEXP '^(0|[1-9][0-9]{0,9})$' AND LENGTH(dv.meta_value)<=10 AND CAST(dv.meta_value AS UNSIGNED)<=2147483647 THEN CAST(dv.meta_value AS UNSIGNED) ELSE 0 END) ELSE 0 END FROM $wpdb->postmeta dv WHERE dv.post_id=$wpdb->posts.ID AND dv.meta_key='_wuai_sluyu_views')";
}

function wuai_sluyu_discovery_channel_scope($term) {
    global $wpdb;
    if (!$term instanceof WP_Term || $term->taxonomy !== 'category') { return wuai_sluyu_discovery_error('分类不存在。'); }
    $children = get_term_children($term->term_id, 'category');
    if (is_wp_error($children)) { return wuai_sluyu_discovery_error('无法读取分类。'); }
    $ids = array_unique(array_merge([(int)$term->term_id], array_map('absint', $children)));
    return wuai_sluyu_discovery_public_sql($wpdb->posts) . " AND EXISTS (SELECT 1 FROM $wpdb->term_relationships dcr INNER JOIN $wpdb->term_taxonomy dct ON dct.term_taxonomy_id=dcr.term_taxonomy_id WHERE dcr.object_id=$wpdb->posts.ID AND dct.taxonomy='category' AND dct.term_id IN (" . implode(',', $ids) . '))';
}

/** Returns WP_Query arguments plus canonical form/pagination state. */
function wuai_sluyu_discovery_channel($term, $input = null, $per_page = 12) {
    global $wpdb;
    $input = $input === null ? $_GET : $input;
    $search = wuai_sluyu_discovery_scalar($input, 'archiveSearch');
    $legacy = wuai_sluyu_discovery_scalar($input, 'post_order', '', 16);
    $modern = wuai_sluyu_discovery_scalar($input, 'sort', '', 16);
    $seed = wuai_sluyu_discovery_scalar($input, 'seed', '', 10);
    $page = wuai_sluyu_discovery_page($input);
    foreach ([$search, $legacy, $modern, $seed, $page] as $value) { if (is_wp_error($value)) { return $value; } }
    if ($seed !== '' && (!preg_match('/^[1-9][0-9]{0,9}$/D', $seed) || (float)$seed > 2147483647)) { return wuai_sluyu_discovery_error('随机排序标记不正确。'); }
    $map = ['new'=>'new', 'latest'=>'new', 'modified'=>'modified', 'comments'=>'comments', 'popular'=>'comments', 'random'=>'random', 'like'=>'likes', 'likes'=>'likes', 'views'=>'views'];
    foreach ([$legacy, $modern] as $value) { if ($value !== '' && !isset($map[$value])) { return wuai_sluyu_discovery_error('不支持此排序方式。'); } }
    if ($legacy !== '' && $modern !== '' && $map[$legacy] !== $map[$modern]) { return wuai_sluyu_discovery_error('排序参数相互冲突，请重新选择。'); }
    $order = $map[$legacy !== '' ? $legacy : ($modern !== '' ? $modern : 'new')];
    $scope = wuai_sluyu_discovery_channel_scope($term);
    if (is_wp_error($scope)) { return $scope; }
    $options = ['new'=>'最新发布', 'modified'=>'最近更新', 'random'=>'随机发现'];
    $metrics = ['comments'=>['最多评论',wuai_sluyu_discovery_comments_sql()], 'likes'=>['最多喜欢',wuai_sluyu_discovery_likes_sql()]];
    if (function_exists('wuai_sluyu_frontend_action')) { $metrics['views'] = ['最多浏览（已记录）',wuai_sluyu_discovery_views_sql()]; }
    foreach ($metrics as $name=>$metric) {
        $found = $wpdb->get_var("SELECT $wpdb->posts.ID FROM $wpdb->posts WHERE $scope AND {$metric[1]}>0 LIMIT 1");
        if ($wpdb->last_error) { return new WP_Error('discovery_storage', '暂时无法核对分类排序，请稍后重试。'); }
        if ($found !== null) { $options[$name] = $metric[0]; }
    }
    $notices = [];
    if (!isset($options[$order])) { $notices[] = '此分类暂无可核验的该项排序数据，已显示最新发布；不会生成虚假热度。'; $order = 'new'; }
    $seed = $seed === '' ? wp_rand(1, 2147483647) : (int)$seed;
    $date_order = $order === 'modified' ? 'modified' : 'date';
    $args = ['post_type'=>'post', 'post_status'=>'publish', 'has_password'=>false, 'cat'=>(int)$term->term_id, 'posts_per_page'=>max(1,min(48,(int)$per_page)), 'paged'=>$page, 'ignore_sticky_posts'=>true, 'suppress_filters'=>false, 'orderby'=>[$date_order=>'DESC', 'ID'=>'DESC'], 'wuai_discovery_sort'=>$order, 'wuai_discovery_seed'=>$seed];
    if ($search !== '') { $args['s'] = $search; }
    if (in_array($order, ['comments','likes','views','random'], true)) { $args['cache_results'] = false; }
    $pagination = ['archiveSearch'=>$search, 'post_order'=>$order, 'sort'=>false];
    if ($order === 'random') { $pagination['seed'] = $seed; }
    return ['args'=>$args, 'search'=>$search, 'order'=>$order, 'options'=>$options, 'notices'=>$notices, 'page'=>$page, 'seed'=>$seed, 'pagination'=>$pagination];
}

function wuai_sluyu_discovery_channel_clauses($clauses, $query) {
    global $wpdb;
    $sort = $query->get('wuai_discovery_sort');
    if (!in_array($sort, ['comments','likes','views','random'], true)) { return $clauses; }
    if ($query->get('post_type') !== 'post' || $query->get('post_status') !== 'publish' || $query->get('has_password') !== false) { return $clauses; }
    $tie = ", $wpdb->posts.post_date DESC, $wpdb->posts.ID DESC";
    if ($sort === 'likes') { $clauses['orderby'] = wuai_sluyu_discovery_likes_sql() . ' DESC' . $tie; }
    elseif ($sort === 'comments') { $clauses['orderby'] = wuai_sluyu_discovery_comments_sql() . ' DESC' . $tie; }
    elseif ($sort === 'views') { $clauses['orderby'] = wuai_sluyu_discovery_views_sql() . ' DESC' . $tie; }
    else {
        $seed = $query->get('wuai_discovery_seed');
        if (!is_int($seed) || $seed < 1 || $seed > 2147483647) { $clauses['where'] .= ' AND 1=0'; return $clauses; }
        // A row-local hash is stable across LIMIT pages; RAND(seed) is not.
        $clauses['orderby'] = $wpdb->prepare("MD5(CONCAT(%s,':',$wpdb->posts.ID)) ASC, $wpdb->posts.ID ASC", (string)$seed);
    }
    return $clauses;
}
add_filter('posts_clauses', 'wuai_sluyu_discovery_channel_clauses', 35, 2);

function wuai_sluyu_discovery_channel_form($key, $config) {
    if (is_wp_error($config)) { echo '<p class="sluyu-notice" role="alert">' . esc_html($config->get_error_message()) . '</p>'; return; }
    foreach ($config['notices'] as $notice) { echo '<p class="sluyu-notice" role="status">' . esc_html($notice) . '</p>'; }
    echo '<form class="sluyu-filter-form" method="get" action="' . esc_url(wuai_sluyu_portal_url($key)) . '">';
    if (!get_option('permalink_structure')) { echo '<input type="hidden" name="sluyu_page" value="' . esc_attr($key) . '">'; }
    echo '<label>分类内搜索<input type="search" name="archiveSearch" maxlength="80" value="' . esc_attr($config['search']) . '" placeholder="搜索当前分类"></label><label>排序<select name="post_order">';
    foreach ($config['options'] as $value=>$label) { echo '<option value="' . esc_attr($value) . '" ' . selected($config['order'],$value,false) . '>' . esc_html($label) . '</option>'; }
    echo '</select></label><input type="hidden" name="seed" value="' . (int)$config['seed'] . '"><button class="sluyu-action-plain">筛选</button></form>';
    if ($config['order'] === 'views') { echo '<p class="sluyu-muted">按本站已记录的文章访问次数排序，不代表独立访客人数。</p>'; }
}
