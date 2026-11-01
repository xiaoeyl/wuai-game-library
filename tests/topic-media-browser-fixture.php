<?php
// Disposable local browser fixture with mail interception; never ship the helper.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT'); $expected = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
if (!$root || !$expected || strcasecmp((string) realpath($root), $expected) !== 0) { throw new RuntimeException('Explicit disposable WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/'; require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771' || !function_exists('wuai_sluyu_topic_media_operation') || !extension_loaded('gd')) { throw new RuntimeException('Integrated local topic-media/GD fixture required.'); }
function tmb_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$output = getenv('WP_QA_OUTPUT') ?: dirname($root); if (!is_dir($output)) { wp_mkdir_p($output); }
$mu = WP_CONTENT_DIR . '/mu-plugins/wuai-topic-media-browser-qa.php';
if (is_file($mu) && strpos(file_get_contents($mu), 'WUAI_TOPIC_MEDIA_BROWSER_QA_V1') === false) { throw new RuntimeException('Unrelated MU helper exists.'); }
$plugin = <<<'PHP'
<?php
// WUAI_TOPIC_MEDIA_BROWSER_QA_V1. Local mail guard; no privileged mutation endpoint.
if(!defined('ABSPATH') || strcasecmp((string)realpath(ABSPATH),__QA_ROOT__)!==0 || home_url()!=='http://127.0.0.1:8771')return;
if(PHP_SAPI!=='cli' && (!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true) || ($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:8771'))return;
add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);
add_action('init',function(){if(!headers_sent())header('X-Wuai-QA-Topic-Media: 1');});
PHP;
tmb_check(file_put_contents($mu, str_replace('__QA_ROOT__', var_export(realpath($root), true), $plugin)) !== false, 'Write local guard.');
$block = static function () { return true; }; add_filter('pre_wp_mail', $block, PHP_INT_MAX); $users = []; $old = get_current_user_id();
try {
    wp_set_current_user(0);
    foreach (['owner','other','admin'] as $name) {
        $login = 'qa_topic_media_browser_' . $name; $user = get_user_by('login', $login);
        if ($user && get_user_meta($user->ID, '_wuai_topic_media_browser_fixture', true) !== 'v1') { throw new RuntimeException('Refusing to reset unmarked account.'); }
        $uid = $user ? $user->ID : wp_create_user($login, 'qa-local-only', $login . '@example.test'); tmb_check(!is_wp_error($uid), 'Create dedicated browser user.'); $uid = (int) $uid; $users[$name] = $uid;
        update_user_meta($uid, '_wuai_topic_media_browser_fixture', 'v1'); (new WP_User($uid))->set_role($name === 'admin' ? 'administrator' : 'subscriber');
        wp_set_password('qa-local-only', $uid); WP_Session_Tokens::get_instance($uid)->destroy_all(); delete_user_meta($uid, '_wuai_banned_until'); delete_user_meta($uid, '_wuai_topic_media_daily');
        $posts = get_posts(['post_type'=>'sluyu_topic','post_status'=>['draft','pending','publish','trash'],'author'=>$uid,'posts_per_page'=>100,'fields'=>'ids','meta_key'=>wuai_sluyu_topic_media_key()]);
        foreach ($posts as $id) { wp_delete_post($id, true); }
    }
    $source = rtrim($output, '/\\') . '/topic-media-source.png'; $image = imagecreatetruecolor(1800, 900); imagefill($image, 0, 0, imagecolorallocate($image, 28, 134, 151));
    imagefilledrectangle($image, 100, 150, 1300, 600, imagecolorallocate($image, 242, 187, 94)); imagepng($image, $source); imagedestroy($image);
    file_put_contents($source, 'QA-TOPIC-ORIGINAL-BYTES-MUST-NOT-SURVIVE<?php echo "not-public";?>', FILE_APPEND);
    $manifest = ['base'=>home_url(),'users'=>$users,'login_prefix'=>'qa_topic_media_browser_','composer_url'=>wuai_sluyu_portal_url('submit', ['kind'=>'topic-images']),'source'=>$source];
    tmb_check(file_put_contents(rtrim($output, '/\\') . '/topic-media-browser-fixture.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false, 'Write media fixture manifest.');
    echo "PASS: public topic image browser accounts and source fixture prepared. Run topic-media.cjs; no upload-origin bypass exists.\n";
} finally { remove_filter('pre_wp_mail', $block, PHP_INT_MAX); wp_set_current_user($old); }
