<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_service_post_types() {
    return ['sluyu_topic','sluyu_news','sluyu_link','sluyu_doc'];
}

function wuai_sluyu_service_capabilities() {
    return array_fill_keys([
        'edit_posts','edit_others_posts','delete_posts','publish_posts','read_private_posts',
        'create_posts','delete_private_posts','delete_published_posts','delete_others_posts',
        'edit_private_posts','edit_published_posts',
    ], 'wuai_manage_service_content');
}

// Native editors use a separate capability; front-end moderated writes keep their own checks.
add_filter('register_post_type_args', function ($args, $post_type) {
    if (!in_array($post_type, wuai_sluyu_service_post_types(), true)) { return $args; }
    $args['map_meta_cap'] = true;
    $args['capabilities'] = array_merge((array) ($args['capabilities'] ?? []), wuai_sluyu_service_capabilities());
    return $args;
}, 20, 2);

add_filter('map_meta_cap', function ($caps, $cap, $uid, $args) {
    $write_caps = [
        'edit_post','edit_page','delete_post','delete_page','edit_comment','moderate_comments',
        'edit_posts','edit_others_posts','edit_published_posts','edit_private_posts','publish_posts','create_posts',
        'delete_posts','delete_others_posts','delete_published_posts','delete_private_posts',
        'edit_pages','edit_others_pages','edit_published_pages','edit_private_pages','publish_pages',
        'delete_pages','delete_others_pages','delete_published_pages','delete_private_pages','upload_files',
        'wuai_manage_service_content',
        'manage_categories','edit_terms','delete_terms','assign_terms','edit_term','delete_term','assign_term',
    ];
    if ($uid && in_array($cap, $write_caps, true) && wuai_sluyu_banned($uid)) { return ['do_not_allow']; }
    if ($cap === 'wuai_manage_service_content') { return ['edit_others_posts']; }
    if (in_array($cap, ['edit_post','edit_page','delete_post','delete_page'], true) && !empty($args[0]) && is_scalar($args[0])) {
        $post = get_post(absint($args[0]));
        if ($post && $post->post_type === 'revision') { $post = get_post($post->post_parent); }
        if ($post && in_array($post->post_type, wuai_sluyu_service_post_types(), true)) { $caps[] = 'edit_others_posts'; }
    }
    // WP does not recursively map primitive capabilities returned for edit_post/read_post.
    $caps = array_map(function ($required) { return $required === 'wuai_manage_service_content' ? 'edit_others_posts' : $required; }, $caps);
    return array_values(array_unique($caps));
}, 20, 4);

function wuai_sluyu_comment_permission($comment, $uid = null) {
    if (is_wp_error($comment)) { return $comment; }
    if (is_object($comment)) { $comment = get_object_vars($comment); }
    if (!is_array($comment)) { return new WP_Error('wuai_comment_data', '评论数据无效。', ['status'=>400]); }
    $uid = $uid === null ? get_current_user_id() : absint($uid);
    if ($uid && wuai_sluyu_banned($uid)) { return new WP_Error('wuai_comment_banned', '禁言期间不能创建或编辑评论。', ['status'=>403]); }
    $post_id = isset($comment['comment_post_ID']) && is_scalar($comment['comment_post_ID']) ? absint($comment['comment_post_ID']) : 0;
    if (!$post_id && !empty($comment['comment_ID']) && is_scalar($comment['comment_ID'])) {
        $existing = get_comment(absint($comment['comment_ID']));
        $post_id = $existing ? (int) $existing->comment_post_ID : 0;
    }
    if (!$post_id || get_post_type($post_id) !== 'sluyu_topic') { return true; }
    $groups = wp_get_object_terms($post_id, 'sluyu_circle', ['fields'=>'ids','wuai_include_pending'=>true]);
    if (is_wp_error($groups)) { return new WP_Error('wuai_comment_circle', '无法核验圈子权限，请稍后再试。', ['status'=>403]); }
    foreach ($groups as $group) {
        $restricted = get_term_meta($group, '_wuai_circle_posting', true) === 'members' || get_term_meta($group, '_wuai_circle_join', true) === 'member';
        $blocked = $uid && wuai_sluyu_circle_membership($group, $uid) === 'blocked';
        if (!wuai_sluyu_circle($group) || $blocked || ($restricted && !wuai_sluyu_circle_can_post($group, $uid))) {
            return new WP_Error('wuai_comment_circle', '请先满足此圈子的参与条件。', ['status'=>403]);
        }
    }
    return true;
}

function wuai_sluyu_rest_comment_permission($prepared, $request) {
    if (is_wp_error($prepared)) { return $prepared; }
    $data = is_object($prepared) ? get_object_vars($prepared) : (array) $prepared;
    $id = $request instanceof WP_REST_Request && is_scalar($request['id']) ? absint($request['id']) : 0;
    if ($id) {
        $existing = get_comment($id);
        if ($existing) {
            $permission = wuai_sluyu_comment_permission($existing);
            if (is_wp_error($permission)) { return $permission; }
            if (empty($data['comment_post_ID'])) { $data['comment_post_ID'] = $existing->comment_post_ID; }
        }
    }
    $permission = wuai_sluyu_comment_permission($data);
    return is_wp_error($permission) ? $permission : $prepared;
}
add_filter('rest_preprocess_comment', 'wuai_sluyu_rest_comment_permission', 20, 2);
add_filter('rest_pre_insert_comment', 'wuai_sluyu_rest_comment_permission', 20, 2);

add_filter('pre_comment_approved', function ($approved, $comment) {
    if (is_wp_error($approved)) { return $approved; }
    $permission = wuai_sluyu_comment_permission($comment);
    return is_wp_error($permission) ? $permission : $approved;
}, 20, 2);

add_filter('wp_update_comment_data', function ($data, $previous, $input) {
    if (is_wp_error($data)) { return $data; }
    foreach ([$previous, $data] as $comment) {
        $permission = wuai_sluyu_comment_permission($comment);
        if (is_wp_error($permission)) { return $permission; }
    }
    return $data;
}, 20, 3);

function wuai_sluyu_rest_write_permission($response, $handler, $request) {
    if ($response !== null || !in_array($request->get_method(), ['POST','PUT','PATCH','DELETE'], true)) { return $response; }
    $route = $request->get_route();
    if (preg_match('~^/wp/v2/comments(?:/([0-9]+))?/?$~', $route, $match)) {
        $data = ['comment_post_ID'=>is_scalar($request['post']) ? absint($request['post']) : 0];
        if (!empty($match[1])) {
            $comment = get_comment((int) $match[1]);
            if ($comment) {
                $permission = wuai_sluyu_comment_permission($comment);
                if (is_wp_error($permission)) { return $permission; }
                if (!$data['comment_post_ID']) { $data['comment_post_ID'] = $comment->comment_post_ID; }
            }
        }
        $permission = wuai_sluyu_comment_permission($data);
        return is_wp_error($permission) ? $permission : $response;
    }
    foreach (array_merge(['post','page'], wuai_sluyu_service_post_types()) as $type) {
        $object = get_post_type_object($type);
        if (!$object || !$object->show_in_rest) { continue; }
        $namespace = !empty($object->rest_namespace) ? $object->rest_namespace : 'wp/v2';
        $base = !empty($object->rest_base) ? $object->rest_base : $type;
        if (!preg_match('~^/' . preg_quote(trim($namespace, '/') . '/' . $base, '~') . '(?:/|$)~', $route)) { continue; }
        $uid = get_current_user_id();
        if ($uid && wuai_sluyu_banned($uid)) { return new WP_Error('wuai_content_banned', '禁言期间不能创建、编辑或删除内容。', ['status'=>403]); }
        if (in_array($type, wuai_sluyu_service_post_types(), true) && !current_user_can('edit_others_posts')) {
            return new WP_Error('wuai_service_editor', '请通过前台发布中心提交审核，不能直接修改站内服务内容。', ['status'=>403]);
        }
        break;
    }
    return $response;
}
add_filter('rest_request_before_callbacks', 'wuai_sluyu_rest_write_permission', 20, 3);

foreach (array_merge(['post','page'], wuai_sluyu_service_post_types()) as $wuai_permission_type) {
    add_filter('rest_pre_insert_' . $wuai_permission_type, function ($prepared, $request) use ($wuai_permission_type) {
        if (is_wp_error($prepared)) { return $prepared; }
        $uid = get_current_user_id();
        if ($uid && wuai_sluyu_banned($uid)) { return new WP_Error('wuai_content_banned', '禁言期间不能创建或编辑内容。', ['status'=>403]); }
        if (in_array($wuai_permission_type, wuai_sluyu_service_post_types(), true) && !current_user_can('edit_others_posts')) {
            return new WP_Error('wuai_service_editor', '请通过前台发布中心提交审核。', ['status'=>403]);
        }
        return $prepared;
    }, 20, 2);
}
unset($wuai_permission_type);

// This early core filter can abort inserts/updates without overwriting published data.
add_filter('wp_insert_post_empty_content', function ($empty, $postarr) {
    $uid = get_current_user_id();
    if ($empty || !$uid || !wuai_sluyu_banned($uid)) { return $empty; }
    $type = isset($postarr['post_type']) && is_scalar($postarr['post_type']) ? (string) $postarr['post_type'] : 'post';
    return in_array($type, array_merge(['post','page','revision'], wuai_sluyu_service_post_types()), true) ? true : $empty;
}, 20, 2);
