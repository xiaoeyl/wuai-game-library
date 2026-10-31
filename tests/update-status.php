<?php
// Regression check for disk-version reconciliation; no WordPress installation needed.
define('ABSPATH', __DIR__ . '/');
define('WUAI_SLUYU_DIR', __DIR__ . '/../wuai-sluyu');
define('MINUTE_IN_SECONDS', 60);
$actions = [];
$job = [];
$version = '1.0.10';
function add_action($name, $callback) { global $actions; $actions[$name] = $callback; }
function add_filter() {}
function current_user_can() { return true; }
function check_ajax_referer() { return true; }
function get_option($name, $default = []) { global $job; return $job; }
function update_option($name, $value, $autoload = false) { global $job; $job = $value; }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wuai_sluyu_installed_version() { global $version; return $version; }
class StatusResult extends Exception { public $data; public function __construct($data) { $this->data = $data; } }
function wp_send_json_success($data) { throw new StatusResult($data); }
require __DIR__ . '/../wuai-sluyu/inc/theme-update-actions.php';
function verify($phase, $target, $age, $expected) {
    global $job, $actions;
    $job = ['id' => 'qa-status-123', 'phase' => $phase, 'target' => $target, 'percent' => $phase === 'complete' ? 100 : 20, 'updated_at' => time() - $age];
    try { $actions['wp_ajax_wuai_sluyu_update_status'](); }
    catch (StatusResult $result) {
        if ($result->data['job']['phase'] !== $expected) throw new RuntimeException(json_encode($result->data));
        if ($expected === 'complete' && $result->data['job']['percent'] !== 100) throw new RuntimeException('Incorrect completion percentage');
        return;
    }
    throw new RuntimeException('No status response');
}
verify('downloading', '1.0.9', 5, 'complete');
verify('error', '1.0.10', 10, 'complete');
verify('downloading', '1.0.11', 301, 'error');
verify('downloading', '1.0.11', 20, 'downloading');
verify('complete', '1.0.9', 999, 'complete');
echo "PASS: manual-upload reconciliation, actual version, stale job error and active job preserved.\n";
