<?php
// Standalone regression: status polling must never cancel or complete a worker.
define('ABSPATH', __DIR__ . '/');
define('WUAI_SLUYU_DIR', __DIR__ . '/../wuai-sluyu');
define('MINUTE_IN_SECONDS', 60);
$actions = [];
$options = [];
$writes = 0;
$version = '1.6.0';
function add_action($name, $callback) { global $actions; $actions[$name] = $callback; }
function add_filter() {}
function current_user_can() { return true; }
function check_ajax_referer() { return true; }
function get_option($name, $default = []) { global $options; return $options[$name] ?? $default; }
function update_option($name, $value, $autoload = false) { global $options, $writes; $options[$name] = $value; $writes++; }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wuai_sluyu_installed_version() { global $version; return $version; }
function wp_cache_delete() {}
function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error extends Exception { public function __construct($code, $message) { parent::__construct($message); } public function get_error_message() { return $this->getMessage(); } }
class StatusResult extends Exception { public $data; public function __construct($data) { $this->data = $data; } }
function wp_send_json_success($data) { throw new StatusResult($data); }
require __DIR__ . '/../wuai-sluyu/inc/theme-update-actions.php';
function verify($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function job($phase = 'downloading', $age = 200, $target = '1.6.1') {
    return ['id' => 'qa-status-job-123', 'phase' => $phase, 'target' => $target, 'percent' => $phase === 'complete' ? 100 : 20, 'updated_at' => time() - $age];
}
function snapshot($value, $lock = 0) {
    global $options, $writes;
    $options = ['wuai_sluyu_update_job' => $value, 'wuai_sluyu_update.lock' => $lock];
    $before = $options; $count = $writes;
    $status = wuai_sluyu_update_snapshot();
    verify($options === $before && $writes === $count, 'Status polling mutated persisted worker state');
    return $status;
}
// OS lock, not timestamp or browser storage, proves that a worker is still alive.
$mutex = wuai_sluyu_update_mutex();
verify(is_resource($mutex), 'Acquire local test lock');
foreach ([130, 301, 1800] as $age) {
    $status = snapshot(job('downloading', $age));
    verify($status['active'] && $status['job']['phase'] === 'downloading', 'Slow live download was cancelled');
}
$status = snapshot(job('installing', 3, '1.6.0'));
verify($status['active'] && $status['job']['phase'] === 'installing', 'Matching disk version completed a live worker');
wuai_sluyu_update_mutex_release($mutex);
verify($mutex === null, 'Release local test lock');
foreach ([1, 130, 1000] as $age) {
    $status = snapshot(job('downloading', $age));
    verify(!$status['active'] && $status['job']['phase'] === 'error', 'Dead worker still shows 20% progress');
}
$status = snapshot(job('error', 10, '1.6.0'));
verify($status['job']['phase'] === 'error', 'Matching header must not convert failed install to success');
$status = snapshot(job('complete', 10, '1.6.0'));
verify($status['job']['phase'] === 'complete', 'Recent terminal result retained');
foreach (['error', 'complete'] as $phase) {
    $status = snapshot(job($phase, 999));
    verify(!$status['job'], 'Old terminal result is hidden without deleting a racing new job');
}
$status = snapshot(job('downloading', 200), time());
verify($status['active'] && $status['job']['phase'] === 'downloading', 'Legacy worker lock respected');
$status = snapshot(job('downloading', 200), time() - 901);
verify(!$status['active'] && $status['job']['phase'] === 'error', 'Expired legacy lock does not resurrect progress');
$modern = job(); $modern['worker_lock'] = 'file-v1';
$status = snapshot($modern, time());
verify(!$status['active'] && $status['job']['phase'] === 'error', 'Dead modern process is not kept alive by a legacy timestamp');
foreach ([false, 'damaged', ['id' => []], ['id' => 'qa-status-job-123', 'phase' => ['bad']], ['id' => 'bad', 'phase' => 'installing']] as $damaged) {
    verify(!snapshot($damaged)['job'], 'Damaged job rendered');
}
// A stale browser may ask to abandon; compatibility endpoint stays read-only.
$original = job(); $options['wuai_sluyu_update_job'] = $original;
try { $actions['wp_ajax_wuai_sluyu_abandon_update'](); }
catch (StatusResult $result) { verify($options['wuai_sluyu_update_job'] === $original, 'Abandon endpoint changed a worker'); }
wuai_sluyu_update_progress('checking', 5, 'new', ['id' => 'qa-next-job-456', 'target' => '1.6.2']);
verify($options['wuai_sluyu_update_job']['id'] === 'qa-next-job-456', 'New job has its own identity');
echo "PASS: live OS lock, slow downloads, crash recovery, no false completion, read-only status/abandon, terminal expiry, legacy locks and damaged state.\n";
