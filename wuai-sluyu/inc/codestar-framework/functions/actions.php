<?php if (!defined('ABSPATH')) {exit;} // Cannot access directly.

/** Restrict backup endpoints to registered settings and their menu capability. */
function csf_check_options_access($unique) {
    if (!isset(CSF::$args['admin_options'][$unique])) {
        return false;
    }
    $args = CSF::$args['admin_options'][$unique];
    return current_user_can(isset($args['menu_capability']) ? $args['menu_capability'] : 'manage_options');
}
/**
 *
 * Get icons from admin ajax
 *
 * @since 1.0.0
 * @version 1.0.0
 *
 */
if (!function_exists('csf_get_icons')) {
    function csf_get_icons()
    {

        $nonce = (!empty($_POST['nonce'])) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        if (!wp_verify_nonce($nonce, 'csf_icon_nonce')) {
            wp_send_json_error(array('error' => esc_html__('Error: Invalid nonce verification.', 'csf')));
        }

        ob_start();

        $icon_library = (apply_filters('csf_fa4', false)) ? 'fa4' : 'fa5';

        CSF::include_plugin_file('fields/icon/' . $icon_library . '-icons.php');

        $icon_lists = apply_filters('csf_field_icon_add_icons', csf_get_default_icons());

        if (!empty($icon_lists)) {

            foreach ($icon_lists as $list) {

                echo(count($icon_lists) >= 2) ? '<div class="csf-icon-title">' . esc_attr($list['title']) . '</div>' : '';

                foreach ($list['icons'] as $icon) {
                    echo '<i title="' . esc_attr($icon) . '" class="' . esc_attr($icon) . '"></i>';
                }

            }

        } else {

            echo '<div class="csf-error-text">' . esc_html__('No data available.', 'csf') . '</div>';

        }

        $content = ob_get_clean();

        wp_send_json_success(array('content' => $content));

    }
    add_action('wp_ajax_csf-get-icons', 'csf_get_icons');
}

/**
 *
 * Export
 *
 * @since 1.0.0
 * @version 1.0.0
 *
 */
if (!function_exists('csf_export')) {
    function csf_export()
    {

        $nonce  = (!empty($_GET['nonce'])) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        $unique = (!empty($_GET['unique'])) ? sanitize_text_field(wp_unslash($_GET['unique'])) : '';

        if (!csf_check_options_access($unique)) {
            wp_die(esc_html__('Error: You do not have permission to do that.', 'csf'), '', array('response' => 403));
        }

        if (!wp_verify_nonce($nonce, 'csf_backup_nonce')) {
            exit(esc_html__('Error: Invalid nonce verification.', 'csf'));
        }

        if (empty($unique)) {
            exit(esc_html__('Error: Invalid key.', 'csf'));
        }

        // Export
        header('Content-Type: application/json');
        header('Content-disposition: attachment; filename=backup-' . gmdate('d-m-Y') . '.json');
        header('Content-Transfer-Encoding: binary');
        header('Pragma: no-cache');
        header('Expires: 0');

        // A fresh installation can render defaults before its first save.
        echo wp_json_encode($unique === 'wuai_sluyu_options' ? wuai_sluyu_get_options() : get_option($unique));

        exit();

    }
    add_action('wp_ajax_csf-export', 'csf_export');
}

/**
 *
 * Import Ajax
 *
 * @since 1.0.0
 * @version 1.0.0
 *
 */
if (!function_exists('csf_import_ajax')) {
    function csf_import_ajax()
    {

        $nonce  = (!empty($_POST['nonce'])) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        $unique = (!empty($_POST['unique'])) ? sanitize_text_field(wp_unslash($_POST['unique'])) : '';
        $data   = (!empty($_POST['data']) && is_string($_POST['data'])) ? json_decode(wp_unslash(trim($_POST['data'])), true) : array();

        if (!csf_check_options_access($unique)) {
            wp_send_json_error(array('error' => esc_html__('Error: You do not have permission to do that.', 'csf')), 403);
        }

        if (!wp_verify_nonce($nonce, 'csf_backup_nonce')) {
            wp_send_json_error(array('error' => esc_html__('Error: Invalid nonce verification.', 'csf')));
        }

        if (empty($unique)) {
            wp_send_json_error(array('error' => esc_html__('Error: Invalid key.', 'csf')));
        }

        if (empty($data) || !is_array($data)) {
            wp_send_json_error(array('error' => esc_html__('Error: The response is not a valid JSON response.', 'csf')));
        }

        // Success
        $data = $unique === 'wuai_sluyu_options' ? wuai_sluyu_sanitize_options($data) : wp_kses_post_deep($data);
        update_option($unique, $data);

        wp_send_json_success();

    }
    add_action('wp_ajax_csf-import', 'csf_import_ajax');
}

/**
 *
 * Reset Ajax
 *
 * @since 1.0.0
 * @version 1.0.0
 *
 */
if (!function_exists('csf_reset_ajax')) {
    function csf_reset_ajax()
    {

        $nonce  = (!empty($_POST['nonce'])) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        $unique = (!empty($_POST['unique'])) ? sanitize_text_field(wp_unslash($_POST['unique'])) : '';

        if (!csf_check_options_access($unique)) {
            wp_send_json_error(array('error' => esc_html__('Error: You do not have permission to do that.', 'csf')), 403);
        }

        if (!wp_verify_nonce($nonce, 'csf_backup_nonce')) {
            wp_send_json_error(array('error' => esc_html__('Error: Invalid nonce verification.', 'csf')));
        }

        // Success
        if ($unique === 'wuai_sluyu_options') {
            update_option($unique, wuai_sluyu_defaults());
        } else {
            delete_option($unique);
        }

        wp_send_json_success();

    }
    add_action('wp_ajax_csf-reset', 'csf_reset_ajax');
}

/**
 *
 * Chosen Ajax
 *
 * @since 1.0.0
 * @version 1.0.0
 *
 */
if (!function_exists('csf_chosen_ajax')) {
    function csf_chosen_ajax()
    {

        $nonce = (!empty($_POST['nonce'])) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        $type  = (!empty($_POST['type'])) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
        $term  = (!empty($_POST['term'])) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
        $query = (!empty($_POST['query_args'])) ? wp_kses_post_deep($_POST['query_args']) : array();

        if (!wp_verify_nonce($nonce, 'csf_chosen_ajax_nonce')) {
            wp_send_json_error(array('error' => esc_html__('Error: Invalid nonce verification.', 'csf')));
        }

        if (empty($type) || empty($term)) {
            wp_send_json_error(array('error' => esc_html__('Error: Invalid term ID.', 'csf')));
        }

        $capability = apply_filters('csf_chosen_ajax_capability', 'manage_options');

        if (!current_user_can($capability)) {
            wp_send_json_error(array('error' => esc_html__('Error: You do not have permission to do that.', 'csf')));
        }

        //处理一个bug
        //如果query_args['hide_empty'] == 'false'，则不显示空分类
        if (isset($query['hide_empty']) && $query['hide_empty'] === 'false') {
            $query['hide_empty'] = false;
        }
        // Success
        $options = CSF_Fields::field_data($type, $term, $query);

        wp_send_json_success($options);

    }
    add_action('wp_ajax_csf-chosen', 'csf_chosen_ajax');
}
