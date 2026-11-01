<?php
// LOCAL TEST PLUGIN ONLY. Copy into an isolated WP mu-plugins directory.
if (!defined('ABSPATH') || !defined('WP_HTTP_BLOCK_EXTERNAL') || !WP_HTTP_BLOCK_EXTERNAL || !preg_match('~^http://(127\.0\.0\.1|localhost)(:\d+)?$~', WP_HOME)) {
    return;
}
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);

function wuai_qa_release($version) {
    $mode = get_option('wuai_qa_update_mode', 'current');
    $root = dirname(__DIR__) . '/qa-update';
    $package = $root . '/' . (in_array($mode, ['bad-version', 'bad-directory', 'missing-file', 'missing-bootstrap', 'requirements'], true) ? $mode : 'valid') . '.zip';
    return [
        'tag_name' => 'v' . $version, 'draft' => false, 'prerelease' => strpos($version, '-') !== false,
        'body' => "## 测试版本\n\n- 页内检测、更新日志\n- 彩色进度与真实主题安装\n<script>window.unsafeRelease=true</script>",
        'assets' => $mode === 'no-asset' ? [] : [[
            'name' => 'wuai-sluyu-v' . $version . '.zip',
            'browser_download_url' => 'https://github.com/xiaoeyl/wuai-game-library/releases/download/v' . $version . '/wuai-sluyu-v' . $version . '.zip',
            'digest' => 'sha256:' . ($mode === 'digest' ? str_repeat('0', 64) : (is_file($package) ? hash_file('sha256', $package) : '')),
        ]],
    ];
}

add_filter('pre_http_request', function ($pre, $args, $url) {
    $mode = get_option('wuai_qa_update_mode', 'current');
    if (strpos($url, 'https://api.github.com/repos/') === 0) {
        update_option('wuai_qa_api_calls', (int) get_option('wuai_qa_api_calls', 0) + 1);
        if ($mode === 'network') return new WP_Error('http_request_failed', 'QA network disconnected');
        $status = $mode === 'missing' ? 404 : ($mode === 'limited' ? 403 : 200);
        $version = $mode === 'current' ? wuai_sluyu_installed_version() : '99.0.0';
        $data = wuai_qa_release($version);
        if (strpos($url, 'per_page') !== false) $data = [wuai_qa_release('98.0.0'), wuai_qa_release('99.0.0-beta.2'), wuai_qa_release('99.0.0-beta.1')];
        return ['response' => ['code' => $status, 'message' => 'QA'], 'headers' => [], 'body' => wp_json_encode($data), 'cookies' => []];
    }
    if (strpos($url, 'https://github.com/xiaoeyl/wuai-game-library/releases/download/') === 0) {
        if ($mode === 'download-fail') return new WP_Error('http_request_failed', 'QA download failed');
        if ($mode === 'slow') sleep(6);
        $kind = in_array($mode, ['bad-version', 'bad-directory', 'missing-file', 'missing-bootstrap', 'requirements'], true) ? $mode : 'valid';
        $file = dirname(__DIR__) . '/qa-update/' . $kind . '.zip';
        if (!empty($args['stream'])) copy($file, $args['filename']);
        return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => '', 'cookies' => []];
    }
    return $pre;
}, 10, 3);

add_filter('upgrader_install_package_result', function ($result, $extra) {
    if (get_option('wuai_qa_update_mode') === 'install-fail' && ($extra['theme'] ?? '') === 'wuai-sluyu') {
        return new WP_Error('qa_install_failure', 'QA installation failed after replacing files');
    }
    return $result;
}, 10, 2);

add_action('added_option', function ($name, $value) {
    if ($name === 'wuai_sluyu_update_job') update_option('wuai_qa_progress', [$value['phase']]);
}, 10, 2);
add_action('updated_option', function ($name, $old, $value) {
    if ($name === 'wuai_sluyu_update_job') {
        $phases = get_option('wuai_qa_progress', []);
        $phases[] = $value['phase'];
        update_option('wuai_qa_progress', $phases);
    }
}, 10, 3);

add_action('wp_ajax_wuai_qa_mode', function () {
    if (!current_user_can('manage_options')) wp_send_json_error([], 403);
    check_ajax_referer('wuai_sluyu_update', 'nonce');
    $mode = sanitize_key($_POST['mode'] ?? 'current');
    update_option('wuai_qa_update_mode', $mode);
    if (isset($_POST['channel'])) {
        $o = wuai_sluyu_get_options();
        $o['github_channel'] = $_POST['channel'] === 'beta' ? 'beta' : 'stable';
        update_option('wuai_sluyu_options', $o);
    }
    if ($mode === 'busy') update_option('wuai_sluyu_update.lock', time(), false);
    else delete_option('wuai_sluyu_update.lock');
    if ($mode === 'stale') update_option('wuai_sluyu_update_job', ['id' => 'qa-stale-job-123', 'phase' => 'downloading', 'percent' => 20, 'updated_at' => time() - 1000], false);
    wp_send_json_success(['calls' => get_option('wuai_qa_api_calls'), 'phases' => get_option('wuai_qa_progress'), 'options' => wuai_sluyu_get_options(), 'backups' => get_option('wuai_sluyu_options_backups')]);
});
