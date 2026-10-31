<?php
if (!defined('ABSPATH') || wuai_sluyu_option('enable_loader', '1') !== '1') {
    return;
}
?>
<div class="sluyu-page-loader" aria-hidden="true">
    <span class="sluyu-loader-orbit"></span>
    <strong><?php echo esc_html(wuai_sluyu_option('site_title') ?: get_bloginfo('name')); ?></strong>
</div>
<noscript><style>.sluyu-page-loader{display:none!important}</style></noscript>
<?php
// Inline only this small controller so a slow footer bundle cannot delay dismissal.
wp_print_inline_script_tag((string) file_get_contents(WUAI_SLUYU_DIR . '/assets/js/page-loader.js'), ['id' => 'wuai-sluyu-loader-js']);
