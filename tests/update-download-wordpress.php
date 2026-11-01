<?php
// WordPress/Requests hook bridge with a deterministic transport; no external HTTP.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath((string) getenv('WP_QA_ROOT'));
$boundary = realpath(dirname(__DIR__) . '/dist');
if (!$root || !$boundary || strpos(str_replace('\\', '/', $root) . '/', str_replace('\\', '/', $boundary) . '/') !== 0) {
    throw new RuntimeException('Use an independent marked updater site inside workspace dist.');
}
$marker = json_decode((string) @file_get_contents($root . '/updater-isolated.json'), true);
if (($marker['kind'] ?? '') !== 'WUAI_UPDATER_ISOLATED_V2' || realpath($marker['target'] ?? '') !== $root) {
    throw new RuntimeException('Independent updater marker mismatch.');
}
require $root . '/wp-load.php';
if (!WP_HTTP_BLOCK_EXTERNAL || !preg_match('~^http://127\.0\.0\.1:\d+$~', home_url())) {
    throw new RuntimeException('Disposable local site with outbound HTTP blocked required.');
}

function download_wp_check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
class Wuai_Download_Transport implements \WpOrg\Requests\Transport {
    public static $calls = [];
    public static $bytes = "PK\x03\x04wordpress-request-options";
    public static $redirect = false;
    public function request($url, $headers = [], $data = [], $options = []) {
        self::$calls[] = ['url' => $url, 'connect_timeout' => $options['connect_timeout'], 'timeout' => $options['timeout'], 'verify' => $options['verify']];
        if (self::$redirect && strpos($url, 'https://github.com/') === 0) {
            file_put_contents($options['filename'], '');
            return "HTTP/1.1 302 Found\r\nLocation: " . self::$redirect . "\r\nContent-Length: 0";
        }
        if (!empty($options['filename'])) file_put_contents($options['filename'], self::$bytes);
        return "HTTP/1.1 200 OK\r\nContent-Length: " . strlen(self::$bytes) . "\r\nContent-Type: application/zip";
    }
    public function request_multiple($requests, $options) { throw new RuntimeException('Unexpected multi-request'); }
    public static function test($capabilities = []) { return true; }
}
$package = [
    'url' => 'https://github.com/xiaoeyl/wuai-game-library/releases/download/v99.0.0/wuai-sluyu-v99.0.0.zip',
    'digest' => 'sha256:' . hash('sha256', Wuai_Download_Transport::$bytes), 'size' => strlen(Wuai_Download_Transport::$bytes),
];
// Enter the actual Requests parser/hook bridge before WP_HTTP_BLOCK_EXTERNAL;
// the deterministic transport exercises redirects without opening any socket.
$http_transport = function ($pre, $args, $url) use ($package) {
    if ($url !== $package['url']) return $pre;
    $options = ['filename' => $args['filename'], 'timeout' => $args['timeout'],
        'verify' => $args['sslverify'], 'hooks' => new WP_HTTP_Requests_Hooks($url, $args),
        'transport' => new Wuai_Download_Transport(), 'redirects' => $args['redirection']];
    $response = \WpOrg\Requests\Requests::request($url, $args['headers'], [], 'GET', $options);
    return ['response' => ['code' => $response->status_code, 'message' => 'OK'], 'headers' => [], 'body' => '', 'cookies' => []];
};
add_filter('pre_http_request', $http_transport, 5, 3);
$temporary = [];
try {
    foreach ([false, 'https://release-assets.githubusercontent.com/local-transport-test', 'https://mirror.invalid/update.zip'] as $redirect) {
        Wuai_Download_Transport::$calls = [];
        Wuai_Download_Transport::$redirect = $redirect;
        $result = wuai_sluyu_update_download_package($package);
        $calls = Wuai_Download_Transport::$calls;
        if ($redirect && strpos($redirect, 'mirror.invalid') !== false) {
            download_wp_check(is_wp_error($result) && $result->get_error_code() === 'theme_download_guard' && count($calls) === 1, 'Untrusted redirect reached transport');
        } else {
            download_wp_check(is_string($result) && file_get_contents($result) === Wuai_Download_Transport::$bytes, 'Requests download failed: ' . (is_wp_error($result) ? $result->get_error_message() : 'invalid bytes'));
            $temporary[] = $result;
            download_wp_check(count($calls) === ($redirect ? 2 : 1), 'Redirect count differs');
        }
        foreach ($calls as $call) {
            download_wp_check($call['connect_timeout'] === 30 && $call['timeout'] <= 60 && $call['verify'] !== false, 'Real Requests retained default 10s connect timeout or disabled TLS');
        }
    }
    download_wp_check(has_action('requests-requests.before_request') === false, 'Download Requests hook leaked to later HTTP');
    remove_filter('pre_http_request', $http_transport, 5);
    $release = wuai_qa_release('99.0.0');
    $selected = wuai_sluyu_release_package($release);
    download_wp_check($selected['api_url'] === $release['assets'][0]['url'], 'Valid same-asset API route not selected');
    foreach (['https://api.github.com/repos/other/repo/releases/assets/99000', 'https://mirror.invalid/asset', 'https://api.github.com/repos/xiaoeyl/wuai-game-library/releases/assets/99000?x=1'] as $bad) {
        $changed = $release; $changed['assets'][0]['url'] = $bad;
        download_wp_check(wuai_sluyu_release_package($changed)['api_url'] === '', 'Unreviewed API route accepted');
    }
    $before = get_option('wuai_qa_update_mode', 'current');
    update_option('wuai_qa_update_mode', 'update');
    wuai_sluyu_github_release(true);
    $native = apply_filters('upgrader_pre_download', false, $package['url'], new stdClass(), ['theme' => 'wuai-sluyu', 'action' => 'update']);
    update_option('wuai_qa_update_mode', $before);
    download_wp_check(is_string($native) && hash_file('sha256', $native) === substr($selected['digest'], 7), 'Native WordPress theme update bypassed guarded downloader');
    $temporary[] = $native;
    download_wp_check(apply_filters('upgrader_pre_download', false, $package['url'], new stdClass(), ['theme' => 'other-theme']) === false, 'Another theme download was intercepted');
    echo "PASS: actual WordPress hook bridge + Requests pipeline applies 30s connect timeout on original/redirected requests, retains TLS, blocks unofficial redirects and removes hooks; same-asset API metadata validated; native theme updates use guarded downloader and unrelated themes stay untouched. Transport is deterministic and opens no socket.\n";
} finally {
    foreach ($temporary as $path) wp_delete_file($path);
}
