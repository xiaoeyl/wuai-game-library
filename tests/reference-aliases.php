<?php
// Serial disposable integration test. Never run against a production database.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); $resolved = $root ? realpath($root) : false;
$expected = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
if (!$resolved || !$expected || strcasecmp($resolved, $expected) !== 0 || !is_file($resolved . '/wp-load.php')) { throw new RuntimeException('Dedicated disposable WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/';
require $resolved . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_reference_documents_view')) { throw new RuntimeException('Integrated local reference aliases candidate required.'); }

function ra_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function ra_error($value, $status, $message) { ra_check(is_wp_error($value) && ($value->get_error_data()['status'] ?? 0) === $status, $message); }
function ra_ids($args) { $query = new WP_Query($args); return array_map('intval', wp_list_pluck($query->posts, 'ID')); }
function ra_active($route, $input = []) {
    global $wp; $_GET = $input; $wp->request = $route;
    return wuai_sluyu_reference_alias_request(['wuai_reference_alias'=>$route]);
}
function ra_html_urls($html) { preg_match_all('/href="([^"]+)"/', $html, $matches); return array_map(static function ($url) { return html_entity_decode($url, ENT_QUOTES, 'UTF-8'); }, $matches[1]); }

$old_get = $_GET; $old_post = $_POST; $old_user = get_current_user_id(); $old_request = $wp->request; $old_uri = $_SERVER['REQUEST_URI'];
$old_state_exists = array_key_exists('wuai_sluyu_reference_alias_state', $GLOBALS); $old_state = $GLOBALS['wuai_sluyu_reference_alias_state'] ?? null;
$old_permalink = get_option('permalink_structure'); $terms = []; $posts = []; $users = []; $prefix = 'qa_reference_alias_' . substr(bin2hex(random_bytes(6)), 0, 10);
$mail = static function () { return true; }; add_filter('pre_wp_mail', $mail, PHP_INT_MAX);
try {
    $_GET = []; $_POST = []; wp_set_current_user(0);
    foreach (wuai_sluyu_reference_aliases() as $route=>$spec) {
        // Fixed legacy slugs are intentional. Do not alter a pre-existing term.
        ra_check(!get_term_by('slug', $spec['slug'], $spec['taxonomy']), 'Disposable alias slug already exists; inspect fixture instead of overwriting: ' . $route);
        ra_error(wuai_sluyu_reference_alias_resolve($route), 404, 'Missing category does not fall back to all content.');
    }
    $uid = wp_create_user($prefix, wp_generate_password(40, true, true), $prefix . '@example.test'); ra_check(!is_wp_error($uid), 'Create fixture owner.'); $uid = (int) $uid; $users[] = $uid;
    (new WP_User($uid))->set_role('administrator'); wp_set_current_user($uid);
    foreach (wuai_sluyu_reference_aliases() as $route=>$spec) {
        $term = wp_insert_term($prefix . ' ' . $spec['slug'], $spec['taxonomy'], ['slug'=>$spec['slug']]);
        ra_check(!is_wp_error($term), 'Create exact fixture taxonomy term.'); $terms[$route] = [(int) $term['term_id'],$spec['taxonomy']];
        if ($spec['portal'] === 'circle') { update_term_meta($term['term_id'], '_wuai_circle_status', 'publish'); }
        $state = wuai_sluyu_reference_alias_resolve($route, ['pg'=>'2']);
        ra_check(is_array($state) && $state['term_id'] === (int) $term['term_id'] && $state['portal'] === $spec['portal'] && $state['query']['pg'] === '2', 'Each known alias resolves real exact taxonomy data.');
        foreach ([$route,$route.'/',$route.'.html',$route.'.html/'] as $path) { ra_check(wuai_sluyu_reference_alias_path($path) === $route, 'Only documented path suffixes normalize.'); }
        $pattern = '^' . preg_quote($route, '#') . '(?:\.html)?/?$';
        ra_check(isset($wp_rewrite->extra_rules_top[$pattern]), 'Exact rewrite exists without a wildcard catch-all.');
    }
    foreach (['links/missing','document/123.html','circle/SSCOSPLAY','circle//square','circle/%73quare','circle/square/extra','https://www.sluyu.com/circle/square'] as $unknown) {
        ra_check(wuai_sluyu_reference_alias_path($unknown) === '', 'Unknown path remains unknown.');
    }
    $wp->request = 'unknown/route'; $_GET = []; $original = ['pagename'=>'unknown/route','s'=>'retain-me'];
    ra_check(wuai_sluyu_reference_alias_request($original) === $original, 'No catch-all hijacking of unknown requests.');

    $group = $terms['circle/sscosplay'][0];
    foreach ([['pg'=>[]],['pg'=>'0'],['pg'=>'100001'],['group'=>'0'],['group'=>[]],['circle_id'=>'1'],['kind'=>'admin'],['sort'=>'random']] as $input) { ra_error(wuai_sluyu_reference_alias_resolve('circle/sscosplay', $input), 400, 'Ambiguous or malformed alias filter is rejected.'); }
    $vars = ra_active('circle/sscosplay', ['group'=>(string) $group,'kind'=>'poll','sort'=>'comments','pg'=>'2','p'=>'1','preview'=>'true','post_status'=>'private','sluyu_page'=>'account']);
    ra_check($vars === ['sluyu_page'=>'circle','wuai_reference_alias'=>'circle/sscosplay'], 'Alias request discards native query override attempts.');
    $handled = false; ra_check(wuai_sluyu_reference_alias_query('group', '', $handled) === (string) $group && $handled, 'Resolved term scope is available without rewriting GET.');
    ra_check(wuai_sluyu_query('kind') === 'poll' && wuai_sluyu_query('sort') === 'comments', 'Root query helper integration preserves supported filters.');
    $_GET['group'] = '99999999'; ra_check(wuai_sluyu_query('group') === (string) $group, 'Raw GET cannot override a resolved path scope.');
    $_GET = ['kind'=>'poll','sort'=>'comments','pg'=>'2'];
    $_SERVER['REQUEST_URI'] = '/circle/sscosplay/?kind=poll&sort=comments&pg=2&unrelated=must-not-carry';
    ob_start(); wuai_sluyu_reference_alias_pagination(3, 'circle'); $html = ob_get_clean(); $urls = ra_html_urls($html); ra_check((bool) $urls, 'Alias pagination rendered.');
    foreach ($urls as $url) { $query = []; parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query); ra_check(($query['kind'] ?? '') === 'poll' && ($query['sort'] ?? '') === 'comments' && !isset($query['unrelated']), 'Alias pagination retains legal filters only.'); }
    $wp->request = ''; $_GET = ['wuai_reference_alias'=>'links/tuijzd']; $_SERVER['REQUEST_URI'] = '/?wuai_reference_alias=links%2Ftuijzd';
    $vars = wuai_sluyu_reference_alias_request(['wuai_reference_alias'=>'links/tuijzd']); ra_check(($vars['sluyu_page'] ?? '') === 'links', 'Plain permalink query alias works.');
    $wp->request = 'unknown'; ra_check(wuai_sluyu_reference_alias_request(['wuai_reference_alias'=>'links/tuijzd'])['error'] === '404', 'Query marker cannot relabel an unrelated physical path.');
    $wp->request = ''; $_SERVER['REQUEST_URI'] = '/unknown/?wuai_reference_alias=links%2Ftuijzd';
    ra_check(wuai_sluyu_reference_alias_request(['wuai_reference_alias'=>'links/tuijzd'])['error'] === '404', 'Plain-permalink empty WP request cannot disguise an unknown physical path.');

    $saved_taxonomy = $wp_taxonomies['sluyu_news_cat']; unset($wp_taxonomies['sluyu_news_cat']);
    try { ra_error(wuai_sluyu_reference_alias_resolve('newsflashes/60s'), 404, 'Unregistered taxonomy is not invented.'); }
    finally { $wp_taxonomies['sluyu_news_cat'] = $saved_taxonomy; }
    update_term_meta($group, '_wuai_circle_status', 'pending'); ra_error(wuai_sluyu_reference_alias_resolve('circle/sscosplay'), 404, 'Pending circle is indistinguishable from a missing alias.');
    update_term_meta($group, '_wuai_circle_status', 'publish'); add_term_meta($group, '_wuai_circle_status', 'pending');
    ra_error(wuai_sluyu_reference_alias_resolve('circle/sscosplay'), 404, 'Ambiguous circle status fails closed.'); delete_term_meta($group, '_wuai_circle_status'); update_term_meta($group, '_wuai_circle_status', 'publish');

    unset($GLOBALS['wuai_sluyu_reference_alias_state']); $_GET = [];
    $make = static function ($label, $type, $status = 'publish', $password = '', $term = 0, $taxonomy = '') use (&$posts, $uid, $prefix) {
        $id = wp_insert_post(['post_type'=>$type,'post_status'=>$status,'post_author'=>$uid,'post_title'=>$prefix . ' ' . $label,'post_content'=>'document search needle','post_password'=>$password,'comment_status'=>'open'], true);
        ra_check(!is_wp_error($id) && $id, 'Create public-only fixture.'); $posts[] = (int) $id;
        if ($term) { wp_set_object_terms($id, [(int) $term], $taxonomy); } return (int) $id;
    };
    foreach (['links/tuijzd','newsflashes/60s'] as $route) {
        $spec = wuai_sluyu_reference_aliases()[$route]; $term = $terms[$route][0];
        $public = $make('public ' . $route, $spec['type'], 'publish', '', $term, $spec['taxonomy']);
        $make('draft ' . $route, $spec['type'], 'draft', '', $term, $spec['taxonomy']);
        $make('private ' . $route, $spec['type'], 'private', '', $term, $spec['taxonomy']);
        $make('password ' . $route, $spec['type'], 'publish', 'secret', $term, $spec['taxonomy']);
        $child = wp_insert_term($prefix . ' child ' . $route, $spec['taxonomy'], ['parent'=>$term]); ra_check(!is_wp_error($child), 'Create excluded child category.'); $terms['child ' . $route] = [(int) $child['term_id'],$spec['taxonomy']];
        $make('child ' . $route, $spec['type'], 'publish', '', $child['term_id'], $spec['taxonomy']);
        $state = wuai_sluyu_reference_alias_resolve($route); ra_check(ra_ids(wuai_sluyu_reference_alias_args($state)) === [$public], 'Alias feed only returns exact category public password-free posts.');
    }
    $public_topic = $make('public circle', 'sluyu_topic', 'publish', '', $group, 'sluyu_circle');
    $held = $make('held circle', 'sluyu_topic', 'publish', '', $group, 'sluyu_circle'); update_post_meta($held, '_wuai_circle_review_hold', 'qa alias hold');
    $mixed = $make('mixed private circle', 'sluyu_topic', 'publish', '', $group, 'sluyu_circle');
    $private = wp_insert_term($prefix . ' private circle', 'sluyu_circle'); ra_check(!is_wp_error($private), 'Create second hidden circle.'); $terms['private circle'] = [(int) $private['term_id'],'sluyu_circle'];
    wp_set_object_terms($mixed, [$group,(int) $private['term_id']], 'sluyu_circle');
    $relations = wp_get_object_terms($mixed, 'sluyu_circle', ['fields'=>'ids','wuai_include_pending'=>true]);
    ra_check(!is_wp_error($relations) && count($relations) === 2, 'Fixture really associates both circles before closing one.');
    update_term_meta($private['term_id'], '_wuai_circle_status', 'pending');
    $state = wuai_sluyu_reference_alias_resolve('circle/sscosplay'); $args = wuai_sluyu_reference_alias_args($state);
    $circle_query = new WP_Query($args); $actual = array_map('intval', wp_list_pluck($circle_query->posts, 'ID'));
    if ($actual !== [$public_topic]) { fwrite(STDERR, wp_json_encode(['expected'=>$public_topic,'actual'=>$actual,'status'=>get_post_status($public_topic),'args'=>$args,'sql'=>$circle_query->request,'db_error'=>$wpdb->last_error], JSON_UNESCAPED_SLASHES) . "\n"); }
    ra_check($actual === [$public_topic], 'Alias excludes held topics and a second pending circle even for admin.');
    update_term_meta($private['term_id'], '_wuai_circle_status', 'PUBLISH'); ra_check(ra_ids($args) === [$public_topic], 'Mixed circle status comparison remains byte-exact on MySQL collations.');
    update_term_meta($group, '_wuai_circle_status', 'pending'); ra_check(ra_ids($args) === [], 'SQL rechecks circle visibility after resolution.'); update_term_meta($group, '_wuai_circle_status', 'publish');

    $doc_group = $terms['document/xzsnbs'][0]; $doc_ids = [];
    for ($i = 0; $i < 13; $i++) { $doc_ids[] = $make('needle document ' . $i, 'sluyu_doc', 'publish', '', $doc_group, 'sluyu_doc_cat'); }
    $make('needle draft document', 'sluyu_doc', 'draft', '', $doc_group, 'sluyu_doc_cat');
    $make('needle private document', 'sluyu_doc', 'private', '', $doc_group, 'sluyu_doc_cat');
    $make('needle password document', 'sluyu_doc', 'publish', 'secret', $doc_group, 'sluyu_doc_cat');
    $outside = $make('needle outside document', 'sluyu_doc', 'publish', '', $terms['document/cjyswt'][0], 'sluyu_doc_cat');
    $make('needle wrong post type', 'post');
    unset($GLOBALS['wuai_sluyu_reference_alias_state']);
    $one = wuai_sluyu_reference_documents(['doc_search'=>$prefix,'group'=>(string) $doc_group,'pg'=>'1']);
    $two = wuai_sluyu_reference_documents(['doc_search'=>$prefix,'group'=>(string) $doc_group,'pg'=>'2']);
    $q1 = new WP_Query($one['args']); $q2 = new WP_Query($two['args']);
    ra_check((int) $q1->found_posts === 13 && (int) $q1->max_num_pages === 2 && count($q1->posts) === 12 && count($q2->posts) === 1, 'Normal documents server search/pagination uses all public documents, not fixed 50 or FAQ.');
    ra_check(count(array_unique(array_merge(wp_list_pluck($q1->posts,'ID'),wp_list_pluck($q2->posts,'ID')))) === 13, 'Document pagination is stable without duplicate IDs.');
    $all = wuai_sluyu_reference_documents(['doc_search'=>$prefix]); $all_query = new WP_Query($all['args']); ra_check((int) $all_query->found_posts === 14, 'Untyped public posts and draft/private/password docs do not enter counts.');
    foreach ([['doc_search'=>[]],['group'=>[]],['group'=>'-1'],['pg'=>'01'],['doc_search'=>str_repeat('x',81)]] as $input) { ra_error(wuai_sluyu_reference_documents($input), 400, 'Normal document filters are strictly typed.'); }
    ra_error(wuai_sluyu_reference_documents(['group'=>'99999999']), 404, 'Missing document category does not broaden results.');
    ra_active('document/xzsnbs', ['doc_search'=>$prefix,'pg'=>'2']); $alias_docs = wuai_sluyu_reference_documents();
    ra_check(ra_ids($alias_docs['args']) === array_map('intval', wp_list_pluck($q2->posts,'ID')), 'Document alias uses the same public scoped query and paging.');
    unset($GLOBALS['wuai_sluyu_reference_alias_state']); $_GET = ['doc_search'=>$prefix,'group'=>(string) $doc_group]; $_SERVER['REQUEST_URI'] = '/document/?doc_search=stale&unrelated=must-not-carry';
    ob_start(); wuai_sluyu_reference_documents_view(); $html = ob_get_clean();
    ra_check(strpos($html, '基础使用提示') !== false && strpos($html, '不计入已发布文档数量') !== false && strpos($html, 'name="doc_search"') !== false, 'Built-in FAQ remains separately labeled from database search.');
    ra_check(strpos($html, 'needle draft document') === false && strpos($html, 'needle password document') === false, 'Rendered document search never leaks nonpublic titles.');
    ob_start(); wuai_sluyu_reference_documents_pagination(2, $one); $pagination = ob_get_clean();
    foreach (ra_html_urls($pagination) as $url) { $query = []; parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query); ra_check(($query['doc_search'] ?? '') === $prefix && ($query['group'] ?? '') === (string) $doc_group && !isset($query['unrelated']), 'Normal docs pagination retains approved search and scope only.'); }
    update_option('permalink_structure', ''); $url = wuai_sluyu_reference_documents_url($one); parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
    ra_check(($query['sluyu_page'] ?? '') === 'document' && ($query['group'] ?? '') === (string) $doc_group, 'Normal docs support plain permalinks.');
    $url = wuai_sluyu_reference_alias_url(wuai_sluyu_reference_alias_resolve('document/xzsnbs')); parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
    ra_check(($query['wuai_reference_alias'] ?? '') === 'document/xzsnbs', 'Alias supports plain permalinks.');
    echo "PASS reference aliases: 13 exact taxonomy-backed routes, missing/private fail-closed, typed filters, scoped public feeds, docs search/pagination, separate FAQ, plain permalinks. Mail blocked.\n";
} finally {
    unset($GLOBALS['wuai_sluyu_reference_alias_state']); $_GET = []; $_POST = []; wp_set_current_user($users[0] ?? 0);
    foreach ($posts as $id) { wp_delete_post($id, true); }
    foreach (array_reverse($terms) as $term) { wp_delete_term($term[0], $term[1]); }
    require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ($users as $uid) { wp_delete_user($uid); }
    update_option('permalink_structure', $old_permalink); remove_filter('pre_wp_mail', $mail, PHP_INT_MAX);
    $_GET = $old_get; $_POST = $old_post; $wp->request = $old_request; $_SERVER['REQUEST_URI'] = $old_uri; wp_set_current_user($old_user);
    if ($old_state_exists) { $GLOBALS['wuai_sluyu_reference_alias_state'] = $old_state; }
}
