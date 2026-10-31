<?php
// Dedicated disposable fixture. The generated MU plugin is never part of the theme.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT');
$expected = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
$resolved = $root ? realpath($root) : false;
if (!$expected || !$resolved || strcasecmp($expected, $resolved) !== 0 || !is_file($resolved . '/wp-load.php')) {
    throw new RuntimeException('WP_QA_ROOT must resolve to the dedicated dist/checks-v1.1.0/site installation.');
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REQUEST_URI'] = '/';
require $resolved . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771' || strcasecmp((string) realpath(ABSPATH), $resolved) !== 0) {
    throw new RuntimeException('Refusing a different WordPress installation or a non-local site.');
}
if (!function_exists('wuai_sluyu_guess_answer')) { throw new RuntimeException('Integrate guess-answer.php before preparing this fixture.'); }
function guess_browser_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }

$mu_dir = WP_CONTENT_DIR . '/mu-plugins';
if (!is_dir($mu_dir)) { guess_browser_check(wp_mkdir_p($mu_dir), 'Create disposable MU plugin directory.'); }
$mu_file = $mu_dir . '/wuai-guess-browser-qa.php';
$marker = 'WUAI_GUESS_BROWSER_QA_V1';
if (is_file($mu_file) && strpos(file_get_contents($mu_file), $marker) === false) {
    throw new RuntimeException('Refusing to overwrite an unrelated MU plugin.');
}
$plugin = <<<'PHP'
<?php
// WUAI_GUESS_BROWSER_QA_V1: local disposable installation only; never distribute.
if (!defined('ABSPATH') || !defined('WUAI_GUESS_QA_ROOT')) { return; }
if (strcasecmp((string) realpath(ABSPATH), WUAI_GUESS_QA_ROOT) !== 0 || home_url() !== 'http://127.0.0.1:8771') { return; }
if (PHP_SAPI !== 'cli' && (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8771')) { return; }
add_filter('pre_wp_mail', function () { return true; }, PHP_INT_MAX);
add_action('login_init', function () { if (!headers_sent()) { header('X-Wuai-Guess-QA-Mail-Blocked: 1'); } });
add_action('admin_notices', function () {
    if (current_user_can('manage_options')) { echo '<input type="hidden" id="wuai-guess-qa-nonce" value="' . esc_attr(wp_create_nonce('wuai_guess_qa')) . '">'; }
});
// Read-only accounting assertions; no mutation, password shortcut or public endpoint.
add_action('wp_ajax_wuai_guess_qa_state', function () {
    if (!current_user_can('manage_options')) { wp_send_json_error(['message'=>'Admin only.'], 403); }
    check_ajax_referer('wuai_guess_qa');
    $raw = isset($_POST['id']) && is_string($_POST['id']) ? wp_unslash($_POST['id']) : '';
    if (!preg_match('/^[1-9][0-9]*$/D', $raw)) { wp_send_json_error(['message'=>'Invalid fixture ID.'], 400); }
    $post = get_post((int) $raw); $author = $post ? get_userdata($post->post_author) : false;
    if (!$post || $post->post_type !== 'sluyu_topic' || !$author || strpos($author->user_login, 'qa_guess_browser_') !== 0 || strpos($post->post_title, 'QA Guess Browser ') !== 0) {
        wp_send_json_error(['message'=>'Dedicated fixture posts only.'], 404);
    }
    $config = wuai_sluyu_guess_config($post->ID); $users = []; $attempts = [];
    foreach (['owner', 'reader', 'wrong', 'other', 'admin'] as $name) {
        $user = get_user_by('login', 'qa_guess_browser_' . $name);
        if (!$user) { continue; }
        $users[$name] = ['id'=>(int) $user->ID, 'points'=>wuai_sluyu_points($user->ID)];
        $attempts[$name] = $config ? wuai_sluyu_guess_attempts($post->ID, $user->ID, $config['version']) : false;
    }
    global $wpdb;
    $events = $wpdb->get_results($wpdb->prepare('SELECT e.id,e.user_id,e.event_key,e.event_type,e.target_id,e.points,e.payload FROM ' . wuai_sluyu_table('events') . " e INNER JOIN $wpdb->users u ON u.ID=e.user_id WHERE e.target_id=%d AND e.event_type='guess' AND u.user_login LIKE %s ORDER BY e.id", $post->ID, $wpdb->esc_like('qa_guess_browser_') . '%'), ARRAY_A);
    wp_send_json_success([
        'post'=>['id'=>(int) $post->ID, 'status'=>$post->post_status, 'kind'=>get_post_meta($post->ID, '_wuai_topic_kind', true), 'title'=>$post->post_title, 'content'=>$post->post_content, 'excerpt'=>$post->post_excerpt, 'config_valid'=>(bool) $config, 'version'=>$config ? $config['version'] : ''],
        'users'=>$users, 'attempts'=>$attempts, 'events'=>$events,
    ]);
});
PHP;
$plugin = str_replace("if (!defined('ABSPATH') || !defined('WUAI_GUESS_QA_ROOT')) { return; }", "if (!defined('ABSPATH')) { return; }\ndefine('WUAI_GUESS_QA_ROOT', " . var_export($resolved, true) . ');', $plugin);
guess_browser_check(file_put_contents($mu_file, $plugin) !== false, 'Write guarded local mail blocker and read-only QA endpoint.');

$mail_block = function () { return true; }; add_filter('pre_wp_mail', $mail_block, PHP_INT_MAX);
$old_user = get_current_user_id(); $old_post = $_POST; $old_get = $_GET;
$users = []; $posts = []; $versions = []; $secrets = [];
$prefix = 'QA Guess Browser'; $secret_prefix = 'QA-GUESS-HIDDEN-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 12);
$initial_points = 100; $choices = "North\nSouth\nEast";
try {
    $_POST = []; $_GET = []; wp_set_current_user(0);
    foreach (['owner', 'reader', 'wrong', 'other', 'admin'] as $name) {
        $login = 'qa_guess_browser_' . $name; $existing = get_user_by('login', $login);
        $id = $existing ? $existing->ID : wp_create_user($login, 'qa-local-only', $login . '@example.test');
        guess_browser_check(!is_wp_error($id) && $id, 'Create dedicated guess browser account.');
        $id = (int) $id; $users[$name] = $id;
        (new WP_User($id))->set_role($name === 'admin' ? 'administrator' : 'subscriber');
        $updated = wp_update_user(['ID'=>$id, 'display_name'=>'Guess Browser ' . ucfirst($name), 'description'=>'', 'user_pass'=>'qa-local-only']);
        guess_browser_check(!is_wp_error($updated), 'Reset dedicated account credentials.');
        delete_user_meta($id, '_wuai_banned_until');
        foreach (['wuai_guess_submit_', 'wuai_guess_notice_'] as $transient) { delete_transient($transient . $id); }
        foreach (['guess_submit', 'guess_answer', 'submit'] as $operation) { delete_transient('wuai_rate_' . $id . '_' . $operation); }
        $owned = get_posts(['post_type'=>'sluyu_topic', 'post_status'=>['publish','pending','draft','private','future','trash'], 'author'=>$id, 'numberposts'=>-1]);
        foreach ($owned as $post) {
            if (strpos($post->post_title, $prefix . ' ') === 0) { wp_delete_post($post->ID, true); }
        }
        foreach (array_keys(get_user_meta($id)) as $key) {
            if (strpos($key, '_wuai_guess_attempts_') === 0) { delete_user_meta($id, $key); }
        }
        $wpdb->delete(wuai_sluyu_table('events'), ['user_id'=>$id]);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . wuai_sluyu_table('messages') . ' WHERE sender_id=%d OR recipient_id=%d', $id, $id));
    }
    wp_set_current_user($users['admin']);
    foreach (['seed', 'draft', 'password'] as $key) {
        $id = wp_insert_post([
            'post_type'=>'sluyu_topic', 'post_author'=>$users['owner'], 'post_status'=>$key === 'draft' ? 'draft' : 'publish',
            'post_title'=>$prefix . ' ' . ucfirst($key),
            'post_content'=>'A public local browser puzzle. Choose the direction opposite to north. The private note is not part of this prompt.',
            'post_excerpt'=>'', 'post_password'=>$key === 'password' ? 'qa-guess-password' : '',
            'comment_status'=>'open', 'ping_status'=>'closed', 'meta_input'=>['_wuai_topic_kind'=>'guess'],
        ], true);
        guess_browser_check(!is_wp_error($id) && $id, 'Create ' . $key . ' puzzle.');
        $id = (int) $id; $posts[$key] = $id; $secrets[$key] = $secret_prefix . '-' . strtoupper($key);
        $saved = wuai_sluyu_guess_save($id, ['stamp'=>wuai_sluyu_guess_stamp($id), 'guess_choices'=>$choices, 'guess_correct'=>'2', 'guess_body'=>'<h3>Private puzzle note</h3><p>' . $secrets[$key] . '</p><p>Disposable browser fixture only.</p>']);
        guess_browser_check($saved === true, 'Save validated private config for ' . $key . '.');
        $config = wuai_sluyu_guess_config($id); guess_browser_check((bool) $config, 'Read back valid private config.');
        $versions[$key] = $config['version'];
        guess_browser_check(strpos(get_post_field('post_content', $id), $secret_prefix) === false && strpos(get_post_field('post_excerpt', $id), $secret_prefix) === false, 'Private fragment is absent from public columns.');
    }
    update_user_meta($users['wrong'], '_wuai_guess_attempts_' . $posts['seed'], ['version'=>$versions['seed'], 'failures'=>1, 'last_error'=>time()-60]);
    update_user_meta($users['other'], '_wuai_guess_attempts_' . $posts['seed'], ['version'=>$versions['seed'], 'failures'=>5, 'last_error'=>time()-60]);
    guess_browser_check(wuai_sluyu_guess_state($posts['seed'], $users['wrong'])['reason'] === 'guess', 'Expired cooldown permits a fresh attempt.');
    guess_browser_check(wuai_sluyu_guess_state($posts['seed'], $users['other'])['reason'] === 'limit', 'Five-failure fixture remains locked after cooldown.');
    foreach ($users as $id) {
        guess_browser_check((bool) wuai_sluyu_event($id, 'qa-guess-browser-seed', 'qa', $initial_points, 0, 'Guess browser fixture points.'), 'Seed exact fixture balance.');
        guess_browser_check(wuai_sluyu_points($id) === $initial_points, 'Exact starting balance.');
    }
    $secrets['submitted'] = $secret_prefix . '-SUBMITTED'; $secrets['revised'] = $secret_prefix . '-REVISED';
    $manifest = [
        'base'=>home_url(), 'users'=>$users, 'posts'=>$posts, 'versions'=>$versions, 'secrets'=>$secrets,
        'loginPrefix'=>'qa_guess_browser_', 'publicPrefix'=>$prefix, 'initialPoints'=>$initial_points,
        'choices'=>$choices, 'correct'=>'1', 'wrong'=>'0', 'revisedCorrect'=>'2',
        'submission'=>['title'=>$prefix . ' Submitted ' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8), 'prompt'=>'Which direction is opposite to north? This is a public prompt, not the private note.'],
        'muPlugin'=>$mu_file,
    ];
    $output = getenv('WP_QA_OUTPUT') ?: dirname($resolved);
    if (!is_dir($output)) { guess_browser_check(wp_mkdir_p($output), 'Create fixture manifest directory.'); }
    guess_browser_check(file_put_contents(rtrim($output, '/\\') . '/guess-answer-fixture.json', wp_json_encode($manifest, JSON_PRETTY_PRINT)) !== false, 'Write isolated browser fixture manifest.');
    echo "PASS: guess browser fixture prepared; dedicated users, draft/password/attempt fixtures, exact balances and guarded read-only local accounting. No real mail sent.\n";
} finally {
    remove_filter('pre_wp_mail', $mail_block, PHP_INT_MAX); $_POST = $old_post; $_GET = $old_get; wp_set_current_user($old_user);
}
