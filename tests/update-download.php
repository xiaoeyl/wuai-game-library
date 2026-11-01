<?php
// Standalone deterministic regression; no network, WordPress install or credentials.
define('ABSPATH', __DIR__ . '/');
define('WUAI_SLUYU_DIR', __DIR__ . '/../wuai-sluyu');
define('WUAI_SLUYU_VERSION', '1.6.1');
define('MB_IN_BYTES', 1024 * 1024);
$hooks = [];
$responses = [];
$requests = [];
$temporary = [];
$deleted = [];
class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct($code, $message, $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function add_action($name, $callback, $priority = 10, $accepted = 1) { global $hooks; $hooks[$name][$priority][] = $callback; }
function add_filter($name, $callback, $priority = 10, $accepted = 1) { add_action($name, $callback, $priority, $accepted); }
function remove_action($name, $callback, $priority = 10) {
    global $hooks;
    foreach ($hooks[$name][$priority] ?? [] as $key => $current) if ($current === $callback) unset($hooks[$name][$priority][$key]);
}
function wp_parse_url($url) { return parse_url($url); }
function wuai_sluyu_update_source() { return ['xiaoeyl', 'wuai-game-library', 'stable']; }
function wp_strip_all_tags($message) { return strip_tags($message); }
function wp_tempnam($label) { global $temporary; $name = tempnam(sys_get_temp_dir(), 'wuai-dl-test-'); $temporary[] = $name; return $name; }
function wp_delete_file($path) { global $deleted; $deleted[] = $path; if (is_file($path)) unlink($path); }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_safe_remote_get($url, $args) {
    global $hooks, $responses, $requests;
    $headers = $args['headers']; $data = null; $method = 'GET';
    $options = ['connect_timeout' => 10, 'timeout' => $args['timeout']];
    foreach ($hooks['requests-requests.before_request'][20] ?? [] as $guard) $guard($url, $headers, $data, $method, $options);
    $requests[] = ['url' => $url, 'args' => $args, 'options' => $options];
    if (!$responses) throw new RuntimeException('Unexpected extra download request');
    $response = array_shift($responses);
    if (isset($response['redirect'])) {
        $target = $response['redirect'];
        foreach ($hooks['requests-requests.before_request'][20] ?? [] as $guard) $guard($target, $headers, $data, $method, $options);
    }
    file_put_contents($args['filename'], $response['bytes'] ?? 'partial');
    return $response['error'] ?? ['response' => ['code' => $response['status'] ?? 200]];
}
require __DIR__ . '/../wuai-sluyu/inc/update-download.php';
function verify_download($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function run_download($package, $plan) {
    global $responses, $requests, $hooks;
    $responses = $plan; $requests = []; $messages = [];
    $result = wuai_sluyu_update_download_package($package, function ($message) use (&$messages) { $messages[] = $message; });
    verify_download(empty($hooks['requests-requests.before_request'][20]), 'Temporary Requests hook leaked after download');
    foreach ($requests as $request) {
        verify_download($request['options']['connect_timeout'] > 10 && $request['options']['connect_timeout'] <= 30, '10s connection timeout was not replaced');
        verify_download($request['options']['timeout'] <= 60 && $request['args']['sslverify'] === true, 'Timeout budget or TLS verification changed');
        verify_download($request['args']['stream'] === true && !isset($request['args']['headers']['Authorization']), 'Download must stream without credentials');
    }
    return [$result, $requests, $messages];
}
$bytes = "PK\x03\x04unit-download-fixture";
$package = [
    'url' => 'https://github.com/xiaoeyl/wuai-game-library/releases/download/v1.6.1/wuai-sluyu-v1.6.1.zip',
    'api_url' => 'https://api.github.com/repos/xiaoeyl/wuai-game-library/releases/assets/12345',
    'size' => strlen($bytes), 'digest' => 'sha256:' . hash('sha256', $bytes),
];
$timeout = new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 10000 milliseconds with 0 out of 0 bytes received');
$passed = [];
try {
    list($result, $calls) = run_download($package, [['bytes' => $bytes]]);
    verify_download(is_string($result) && file_get_contents($result) === $bytes && count($calls) === 1, 'Successful direct download changed');
    wp_delete_file($result); $passed[] = 'Direct download, 30s connect timeout, TLS and SHA256';

    list($result, $calls, $messages) = run_download($package, [['error' => $timeout], ['bytes' => $bytes]]);
    verify_download(is_string($result) && count($calls) === 2 && $calls[1]['url'] === $package['api_url'], 'Timeout did not switch to official API asset');
    verify_download(strpos($messages[1], 'API') !== false, 'Fallback phase not reported');
    wp_delete_file($result); $passed[] = 'Exact screenshot timeout falls back to official API';

    list($result, $calls) = run_download($package, [['error' => $timeout], ['error' => $timeout], ['bytes' => $bytes]]);
    verify_download(is_string($result) && count($calls) === 3 && $calls[2]['url'] === $package['url'], 'Last bounded read retry did not recover');
    wp_delete_file($result); $passed[] = 'Final direct read retry recovers';

    list($result, $calls) = run_download($package, [['error' => $timeout], ['error' => $timeout], ['error' => $timeout]]);
    verify_download(is_wp_error($result) && count($calls) === 3 && strpos($result->get_error_message(), '网站服务器') !== false && strpos($result->get_error_message(), '上传主题') !== false, 'Persistent timeout lacks actionable Chinese guidance');
    $passed[] = 'Persistent timeouts bounded to three reads with manual-install guidance';

    $legacy = $package; $legacy['api_url'] = '';
    list($result, $calls) = run_download($legacy, [['error' => $timeout], ['bytes' => $bytes]]);
    verify_download(is_string($result) && count($calls) === 2, 'Assets without API metadata lost compatibility');
    wp_delete_file($result); $passed[] = 'Legacy asset metadata retains direct retry';

    list($result, $calls) = run_download($package, [['bytes' => str_repeat('X', strlen($bytes))]]);
    verify_download(is_wp_error($result) && $result->get_error_code() === 'theme_package_digest' && count($calls) === 1, 'Checksum failure must not fall back or install');
    $passed[] = 'Checksum mismatch fails closed without retry';

    list($result, $calls) = run_download($package, [['bytes' => 'PK']]);
    verify_download(is_wp_error($result) && $result->get_error_code() === 'theme_package_size' && count($calls) === 1, 'Truncated download was accepted');
    $passed[] = 'Short or oversized response fails closed';

    foreach ([404, 429, 503] as $status) {
        list($result, $calls) = run_download($package, [['status' => $status], ['bytes' => $bytes]]);
        verify_download(is_string($result) && count($calls) === 2, 'Transient HTTP response did not fall back');
        wp_delete_file($result);
    }
    $passed[] = 'HTTP 404, 429 and 503 fall back within fixed bounds';

    list($result, $calls) = run_download($package, [['error' => new WP_Error('http_request_failed', 'cURL error 60: SSL certificate problem')]]);
    verify_download(is_wp_error($result) && count($calls) === 1 && strpos($result->get_error_message(), 'CA') !== false, 'Certificate failure must not retry or disable TLS');
    $passed[] = 'Certificate failure stops with CA guidance';

    foreach (['http://release-assets.githubusercontent.com/test', 'https://mirror.example/update.zip', 'https://127.0.0.1/update.zip', 'https://github.com/other/repo/asset', 'https://user@release-assets.githubusercontent.com/test'] as $redirect) {
        list($result, $calls) = run_download($package, [['redirect' => $redirect, 'bytes' => $bytes]]);
        verify_download(is_wp_error($result) && $result->get_error_code() === 'theme_download_guard' && count($calls) === 1, 'Unsafe redirect was not blocked');
    }
    $passed[] = 'HTTP, third-party, loopback, credentials and unrelated repository redirects blocked';
    list($result, $calls) = run_download($package, [['redirect' => 'https://release-assets.githubusercontent.com/asset?signature=public-test', 'bytes' => $bytes]]);
    verify_download(is_string($result), 'Official signed redirect blocked'); wp_delete_file($result);
    $passed[] = 'Official HTTPS asset redirect accepted';

    foreach ($temporary as $path) verify_download(!is_file($path), 'Temporary partial or completed file leaked: ' . $path);
    $passed[] = 'All failed partial files removed and per-request hooks released';
    echo json_encode(['status' => 'PASS', 'checks' => $passed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} finally {
    foreach ($temporary as $path) if (is_file($path)) unlink($path);
}
