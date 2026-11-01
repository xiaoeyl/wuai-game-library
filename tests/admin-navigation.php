<?php
// Real WordPress integration; rendering settings must never change business options.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT');
$expected = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
if (!$expected || strcasecmp((string) realpath((string) $root), $expected) !== 0) {
    throw new RuntimeException('Use the explicit disposable checks-v1.1.0/site installation.');
}
define('WP_ADMIN', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=wuai-sluyu';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['page'] = 'wuai-sluyu';
require $root . '/wp-load.php';
function nav_check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
$admin = get_user_by('login', 'qa_admin');
nav_check($admin && home_url() === 'http://127.0.0.1:8771', 'Local admin fixture required');
wp_set_current_user($admin->ID);
$watched = ['wuai_sluyu_options', 'wuai_sluyu_invitation_settings', wuai_sluyu_circle_review_option(), 'wuai_sluyu_bounty_enabled'];
$before = [];
foreach ($watched as $option) { $before[$option] = get_option($option); }
$sections = CSF::$args['sections']['wuai_sluyu_options'];
$roots = array_values(array_filter($sections, function ($item) { return !isset($item['parent']); }));
nav_check(array_column($roots, 'title') === ['全局&功能', '页面&显示', '文章&列表', '功能&权限', '用户&互动', '支付&付费', '商城&商品', '社区&论坛', '扩展&增强'], 'Nine real parent groups in reference order');
$children = array_values(array_filter($sections, function ($item) { return isset($item['parent']); }));
nav_check(count($children) === count(wuai_sluyu_settings_sections()), 'Every declared section is registered');
$keys = [];
$field_ids = [];
$html = '';
foreach ($children as $child) {
    nav_check(in_array($child['parent'], array_column($roots, 'id'), true), 'No orphan child');
    $keys[] = $child['parent'] . '/' . $child['title'];
    foreach ($child['fields'] as $field) {
        if (isset($field['id'])) { $field_ids[] = $field['id']; }
        if ($field['type'] === 'callback') {
            nav_check(is_callable($field['function']), 'Real callback: ' . $field['function']);
            ob_start(); call_user_func($field['function']); $html .= ob_get_clean();
        }
    }
}
nav_check(count($keys) === count(array_unique($keys)), 'No duplicate sections');
nav_check(count($field_ids) === count(array_unique($field_ids)), 'Each setting has exactly one save/reset location');
$defaults = array_keys(wuai_sluyu_defaults()); sort($defaults); sort($field_ids);
nav_check($defaults === $field_ids, 'All existing fields retained');
foreach (['announcements', 'widgets', 'menus', 'pages', 'registration', 'moderation', 'users', 'account-security', 'messages', 'bounties', 'sluyu_topic', 'circles', 'sluyu_listing'] as $module) {
    nav_check(strpos($html, 'data-module="' . $module . '"') !== false, 'Module rendered: ' . $module);
}
$tabs = wuai_sluyu_settings_legacy_tabs();
foreach ($tabs as $old => $tab) {
    nav_check(in_array($tab, array_map('wuai_sluyu_settings_tab', array_keys(wuai_sluyu_settings_sections())), true), 'Legacy target exists');
}
nav_check($tabs[sanitize_title('备份与恢复')] === wuai_sluyu_settings_tab('backup'), 'Old backup bookmark supported');
nav_check($tabs[sanitize_title('支付&付费') . '/' . sanitize_title('积分与签到')] === wuai_sluyu_settings_tab('points'), 'Moved points bookmark supported');
nav_check(strpos(wuai_sluyu_settings_url('backup'), '#tab=' . wuai_sluyu_settings_tab('backup')) !== false, 'CSF slash remains unencoded');
foreach ($before as $option => $value) { nav_check(get_option($option) === $value, 'Rendering did not mutate ' . $option); }
wp_set_current_user(get_user_by('login', 'qa_subscriber')->ID);
ob_start(); wuai_sluyu_csf_registration(); $limited = ob_get_clean();
nav_check(strpos($limited, 'admin.php?page=wuai-sluyu-invitations') === false, 'No privileged link for subscriber');
echo 'PASS: 9 parent groups / ' . count($children) . " child sections; every setting retained once, actual callbacks/status, legacy links, no writes and capability-aware links.\n";
