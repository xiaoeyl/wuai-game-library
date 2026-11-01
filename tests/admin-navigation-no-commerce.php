<?php
// Keep unavailable WooCommerce links out of all three commerce-related sections.
define('ABSPATH', __DIR__ . '/');
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES); }
function admin_url($path) { return 'http://127.0.0.1/wp-admin/' . $path; }
function current_user_can($capability) { return true; }
require __DIR__ . '/../wuai-sluyu/inc/admin-navigation.php';
ob_start();
wuai_sluyu_csf_payment();
wuai_sluyu_csf_orders();
$html = ob_get_clean();
foreach (['post_type=product', 'post_type=shop_order', 'page=wc-orders', 'page=wc-settings', 'post_type=shop_coupon'] as $unavailable) {
    if (strpos($html, $unavailable) !== false) { throw new RuntimeException('Unavailable commerce link: ' . $unavailable); }
}
if (substr_count($html, 'payment-unavailable') !== 2 || strpos($html, 'plugins.php') === false) {
    throw new RuntimeException('Missing explicit unavailable state');
}
echo "PASS: missing WooCommerce shows honest status and no dead product/order/payment/coupon links.\n";
