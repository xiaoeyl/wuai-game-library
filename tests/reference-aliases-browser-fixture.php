<?php
// Local CLI fixture. The MU guard only blocks mail; it exposes no HTTP API.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); $resolved = $root ? realpath($root) : false;
$expected = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
if (!$resolved || !$expected || strcasecmp($resolved, $expected) !== 0 || !is_file($resolved . '/wp-load.php')) { throw new RuntimeException('Exact disposable WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
require $resolved . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771' || strcasecmp((string) realpath(ABSPATH), $resolved) !== 0 || !defined('DB_ENGINE') || DB_ENGINE !== 'sqlite' || !is_a($wpdb, 'WP_SQLite_DB') || !function_exists('wuai_sluyu_reference_documents_view')) { throw new RuntimeException('Integrated aliases on exact disposable SQLite only.'); }
function rab_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function rab_within($path, $parent) { $path = str_replace('\\','/',rtrim($path,'/\\')); $parent = str_replace('\\','/',rtrim($parent,'/\\')); return strcasecmp($path,$parent) === 0 || strncasecmp($path,$parent.'/',strlen($parent)+1) === 0; }
$output = realpath(getenv('WP_QA_OUTPUT') ?: dirname($resolved)); $dist = realpath(dirname(__DIR__) . '/dist');
rab_check($output && $dist && is_dir($output) && rab_within($output,$dist) && !rab_within($output,$resolved), 'Output must be ignored dist outside HTTP root.');
$manifest = $output . '/reference-aliases-browser-fixture.json'; $mu_dir = WP_CONTENT_DIR . '/mu-plugins'; $mu = $mu_dir . '/wuai-reference-aliases-browser-qa.php';
rab_check(!is_link($manifest) && !is_link($mu) && rab_within((string) realpath(WP_CONTENT_DIR),$resolved) && (!is_dir($mu_dir) || rab_within((string) realpath($mu_dir),$resolved)), 'No symlink or external fixture target.');
$marker = 'WUAI_REFERENCE_ALIASES_BROWSER_V1'; $meta = '_wuai_reference_alias_browser_run'; $fixture = null;
$mail = static function () { return true; }; add_filter('pre_wp_mail',$mail,PHP_INT_MAX); $old_user = get_current_user_id(); $_POST = []; $_GET = [];
$persist = static function () use (&$fixture,$manifest) { rab_check(file_put_contents($manifest,wp_json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) !== false, 'Persist fixture manifest.'); };
$validate = static function () use (&$fixture,$resolved,$marker,$meta,$wpdb,$mu) {
    rab_check(is_array($fixture) && ($fixture['marker'] ?? '') === $marker && ($fixture['root'] ?? '') === $resolved && ($fixture['base'] ?? '') === home_url() && preg_match('/^[a-f0-9]{16}$/D',$fixture['run'] ?? ''), 'Refuse unrelated manifest.');
    foreach ($fixture['posts'] as $id) { if (get_post($id)) { rab_check(get_post_meta($id,$meta,true) === $fixture['run'], 'Refuse unrelated post.'); } }
    foreach ($fixture['terms'] as $entry) {
        $term = get_term($entry['id'],$entry['taxonomy']); if (!$term instanceof WP_Term) { continue; }
        rab_check(get_term_meta($entry['id'],$meta,true) === $fixture['run'], 'Refuse unrelated term.');
        $ids = $wpdb->get_col($wpdb->prepare("SELECT object_id FROM $wpdb->term_relationships WHERE term_taxonomy_id=%d",$term->term_taxonomy_id));
        rab_check(!$wpdb->last_error && !array_diff(array_map('intval',$ids),$fixture['posts']), 'Fixture term has unrelated relationships.');
    }
    foreach ($fixture['users'] as $id) {
        if (!get_userdata($id)) { continue; }
        rab_check(get_user_meta($id,$meta,true) === $fixture['run'], 'Refuse unrelated user.');
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_author=%d",$id));
        rab_check(!$wpdb->last_error && !array_diff(array_map('intval',$ids),$fixture['posts']), 'Fixture user owns unrelated content.');
    }
    if (is_file($mu)) { rab_check(hash_file('sha256',$mu) === ($fixture['mu_sha256'] ?? ''), 'Refuse changed mail guard.'); }
};
try {
    $mode = $argv[1] ?? '--seed'; rab_check(in_array($mode,['--seed','--cleanup','--plain','--pretty','--unavailable','--available'],true), 'Unknown CLI fixture mode.');
    if ($mode !== '--seed') {
        rab_check(is_file($manifest), 'Missing fixture manifest.'); $fixture = json_decode(file_get_contents($manifest),true); $validate();
        if (($fixture['state'] ?? '') === 'cleaned') { rab_check($mode === '--cleanup', 'Fixture is already cleaned.'); echo "PASS aliases browser fixture already cleaned.\n"; return; }
        if ($mode === '--cleanup') {
            rab_check(get_option('permalink_structure') === ($fixture['expected_permalink'] ?? null) || get_option('permalink_structure') === $fixture['original_permalink'], 'Permalinks changed externally; inspect before cleanup.');
            rab_check(get_option('rewrite_rules',null) === ($fixture['expected_rewrite'] ?? null) || get_option('rewrite_rules',null) === $fixture['original_rewrite'], 'Rewrite rules changed externally; inspect before cleanup.');
            wp_set_current_user($fixture['users'][0] ?? 0);
            foreach ($fixture['posts'] as $id) { if (get_post($id)) { rab_check((bool) wp_delete_post($id,true), 'Delete owned post.'); } }
            // Core term_exists() queries hide pending circles on a frontend CLI
            // request too. Ownership was checked above; include them for cleanup.
            $old_all_terms = $GLOBALS['wuai_sluyu_all_circle_terms'] ?? false;
            $GLOBALS['wuai_sluyu_all_circle_terms'] = true;
            try {
                foreach (array_reverse($fixture['terms']) as $entry) { if (get_term($entry['id'],$entry['taxonomy']) instanceof WP_Term) { $result = wp_delete_term($entry['id'],$entry['taxonomy']); rab_check(!is_wp_error($result) && $result !== false, 'Delete owned term.'); } }
            } finally { $GLOBALS['wuai_sluyu_all_circle_terms'] = $old_all_terms; }
            wp_set_current_user(0); require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ($fixture['users'] as $id) { if (get_userdata($id)) { rab_check(wp_delete_user($id), 'Delete owned user.'); } }
            update_option('permalink_structure',$fixture['original_permalink']); $wp_rewrite->set_permalink_structure($fixture['original_permalink']);
            if ($fixture['original_rewrite'] === null) { delete_option('rewrite_rules'); } else { update_option('rewrite_rules',$fixture['original_rewrite']); }
            if (is_file($mu)) { rab_check(unlink($mu), 'Remove exact mail guard.'); }
            $fixture['state'] = 'cleaned'; $persist(); echo "PASS aliases browser fixture cleaned; exact permalink/rewrite options restored.\n"; return;
        }
        rab_check(($fixture['state'] ?? '') === 'ready', 'Only ready fixture supports mode changes.');
        if ($mode === '--unavailable' || $mode === '--available') {
            $link = $fixture['routes']['links/tuijzd']['term_id']; $circle = $fixture['routes']['circle/sscosplay']['term_id'];
            $slug = $mode === '--unavailable' ? 'qa-alias-unavailable-' . $fixture['run'] : 'tuijzd';
            $result = wp_update_term($link,'sluyu_link_cat',['slug'=>$slug]); rab_check(!is_wp_error($result), 'Toggle only fixture alias availability.');
            update_term_meta($circle,'_wuai_circle_status',$mode === '--unavailable' ? 'pending' : 'publish');
            $fixture['available'] = $mode === '--available'; $persist(); echo "PASS aliases browser fixture availability changed.\n"; return;
        }
        $permalink = $mode === '--plain' ? '' : '/%postname%/';
        $wp_rewrite->set_permalink_structure($permalink); flush_rewrite_rules(false);
        $fixture['expected_permalink'] = get_option('permalink_structure'); $fixture['expected_rewrite'] = get_option('rewrite_rules',null); $fixture['mode'] = $mode === '--plain' ? 'plain' : 'pretty'; $persist();
        echo "PASS aliases browser fixture permalink mode changed.\n"; return;
    }
    if (is_file($manifest)) { $previous = json_decode(file_get_contents($manifest),true); rab_check(($previous['marker'] ?? '') === $marker && ($previous['state'] ?? '') === 'cleaned', 'Clean previous fixture before seeding.'); }
    rab_check(!file_exists($mu), 'Refuse existing mail guard.');
    foreach (wuai_sluyu_reference_aliases() as $route=>$spec) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT t.term_id FROM $wpdb->terms t INNER JOIN $wpdb->term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.taxonomy=%s AND t.slug=%s LIMIT 1",$spec['taxonomy'],$spec['slug']));
        rab_check(!$wpdb->last_error && !$exists, 'Reserved alias slug already exists, including hidden terms: ' . $route);
    }
    $run = bin2hex(random_bytes(8)); $prefix = 'QAAlias' . $run;
    $fixture = ['marker'=>$marker,'run'=>$run,'base'=>home_url(),'root'=>$resolved,'php'=>PHP_BINARY,'php_ini'=>php_ini_loaded_file() ?: null,'state'=>'preparing','mode'=>'pretty','available'=>true,'posts'=>[],'terms'=>[],'users'=>[],'routes'=>[],'hidden_titles'=>[],'search'=>$prefix,'original_permalink'=>get_option('permalink_structure'),'original_rewrite'=>get_option('rewrite_rules',null),'credentials_in_manifest'=>false,'mail_intercepted'=>true]; $persist();
    rab_check(is_dir($mu_dir) || wp_mkdir_p($mu_dir), 'Create mail guard directory.'); rab_check(rab_within((string) realpath($mu_dir),$resolved), 'Resolved MU directory stays in fixture root.');
    $plugin = "<?php\n// $marker: mail block only, no HTTP fixture API.\nif (!defined('ABSPATH') || strcasecmp((string)realpath(ABSPATH)," . var_export($resolved,true) . ")!==0 || !defined('DB_ENGINE') || DB_ENGINE!=='sqlite') return;\nif (PHP_SAPI!=='cli' && ((!in_array(\$_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)) || (\$_SERVER['HTTP_HOST']??'')!=='127.0.0.1:8771')) return;\nadd_filter('pre_wp_mail',function(){return true;},PHP_INT_MAX);\nadd_action('init',function(){if(!headers_sent())header('X-Wuai-Aliases-QA-Mail-Blocked: 1');});\n";
    $fixture['mu_sha256'] = hash('sha256',$plugin); $persist(); $handle = fopen($mu,'xb'); rab_check($handle !== false, 'Create exclusive mail guard.'); try { rab_check(fwrite($handle,$plugin) === strlen($plugin), 'Write complete guard.'); } finally { fclose($handle); }
    $login = 'qa_alias_' . $run; $uid = wp_create_user($login,wp_generate_password(48,true,true),$login . '@example.test'); rab_check(!is_wp_error($uid), 'Create fixture owner.'); $uid = (int) $uid;
    update_user_meta($uid,$meta,$run); $fixture['users'][] = $uid; $persist(); (new WP_User($uid))->set_role('administrator'); wp_set_current_user($uid);
    $make = static function ($name,$type,$term,$taxonomy,$status='publish',$password='') use (&$fixture,$persist,$prefix,$uid,$meta,$run) {
        $title = $prefix . ' ' . $name;
        $id = wp_insert_post(['post_type'=>$type,'post_status'=>$status,'post_author'=>$uid,'post_title'=>$title,'post_content'=>'Read-only reference alias browser fixture.','post_password'=>$password,'comment_status'=>'closed','ping_status'=>'closed','meta_input'=>[$meta=>$run,'_wuai_topic_kind'=>'topic']],true);
        rab_check(!is_wp_error($id) && $id, 'Create marked fixture post.'); $id = (int) $id; $fixture['posts'][] = $id; $persist();
        rab_check(!is_wp_error(wp_set_object_terms($id,[(int)$term],$taxonomy)), 'Assign fixture term.');
        rab_check(get_post_status($id) === $status, 'Fixture persisted requested status.'); return ['id'=>$id,'title'=>$title];
    };
    foreach (wuai_sluyu_reference_aliases() as $route=>$spec) {
        $term = wp_insert_term('QA Alias ' . $spec['slug'] . ' ' . $run,$spec['taxonomy'],['slug'=>$spec['slug']]); rab_check(!is_wp_error($term), 'Create exact fixture term.'); $term = (int) $term['term_id'];
        update_term_meta($term,$meta,$run); $fixture['terms'][] = ['id'=>$term,'taxonomy'=>$spec['taxonomy']]; $persist();
        if ($spec['portal'] === 'circle') { update_term_meta($term,'_wuai_circle_status','publish'); }
        $items = []; $number = $route === 'document/xzsnbs' ? 13 : 1;
        for ($i=1;$i<=$number;$i++) { $items[] = $make($spec['slug'] . ' Public ' . $i,$spec['type'],$term,$spec['taxonomy']); }
        $fixture['routes'][$route] = ['portal'=>$spec['portal'],'term_id'=>$term,'items'=>$items]; $persist();
    }
    $doc = $fixture['routes']['document/xzsnbs']['term_id'];
    foreach (['draft','private','password'] as $kind) { $item = $make('Doc Hidden ' . $kind,'sluyu_doc',$doc,'sluyu_doc_cat',$kind === 'password' ? 'publish' : $kind,$kind === 'password' ? wp_generate_password(40,true,true) : ''); $fixture['hidden_titles'][] = $item['title']; if ($kind === 'private') { $fixture['private_id'] = $item['id']; } }
    $circle = $fixture['routes']['circle/sscosplay']['term_id']; $held = $make('Circle Held','sluyu_topic',$circle,'sluyu_circle'); update_post_meta($held['id'],'_wuai_circle_review_hold','qa browser hold'); $fixture['hidden_titles'][] = $held['title'];
    $mixed = $make('Circle Hidden Second','sluyu_topic',$circle,'sluyu_circle'); $hidden = wp_insert_term('QA Hidden Circle ' . $run,'sluyu_circle'); rab_check(!is_wp_error($hidden),'Create hidden-circle fixture.'); $hidden = (int) $hidden['term_id'];
    update_term_meta($hidden,$meta,$run); $fixture['terms'][] = ['id'=>$hidden,'taxonomy'=>'sluyu_circle']; $persist();
    rab_check(!is_wp_error(wp_set_object_terms($mixed['id'],[$circle,$hidden],'sluyu_circle')), 'Assign second circle before hiding it.');
    rab_check(count(wp_get_object_terms($mixed['id'],'sluyu_circle',['fields'=>'ids','wuai_include_pending'=>true])) === 2, 'Mixed circle fixture has both actual relationships.');
    update_term_meta($hidden,'_wuai_circle_status','pending'); $fixture['hidden_titles'][] = $mixed['title'];
    $wp_rewrite->set_permalink_structure('/%postname%/'); flush_rewrite_rules(false);
    $fixture['expected_permalink'] = get_option('permalink_structure'); $fixture['expected_rewrite'] = get_option('rewrite_rules',null); $fixture['state'] = 'ready'; $persist();
    echo "PASS aliases browser fixture ready: 13 exact routes, public/hidden records, anonymous browser, no stored passwords.\n";
} catch (Throwable $error) {
    if (is_array($fixture) && ($fixture['state'] ?? '') === 'preparing') { $fixture['state'] = 'incomplete'; $persist(); }
    throw $error;
} finally { remove_filter('pre_wp_mail',$mail,PHP_INT_MAX); wp_set_current_user($old_user); }
