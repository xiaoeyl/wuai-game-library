<?php
if (!defined('ABSPATH')) { exit; }

function wuai_sluyu_profile_image_key($kind) {
    return in_array($kind, ['avatar', 'cover'], true) ? '_wuai_profile_' . $kind : '';
}

function wuai_sluyu_profile_image_owned($id, $uid, $kind) {
    $post = get_post(absint($id));
    return $post && $post->post_type === 'attachment' && (int) $post->post_author === (int) $uid && !$post->post_parent
        && (int) get_post_meta($id, '_wuai_profile_image_owner', true) === (int) $uid
        && get_post_meta($id, '_wuai_profile_image_kind', true) === $kind;
}

function wuai_sluyu_profile_image_id($uid, $kind) {
    $key = wuai_sluyu_profile_image_key($kind);
    $id = $key ? absint(get_user_meta($uid, $key, true)) : 0;
    return $id && wuai_sluyu_profile_image_owned($id, $uid, $kind) && in_array(get_post_mime_type($id), ['image/jpeg', 'image/png'], true) ? $id : 0;
}

function wuai_sluyu_profile_cover_url($uid) {
    $id = wuai_sluyu_profile_image_id(absint($uid), 'cover');
    return $id ? (string) wp_get_attachment_image_url($id, 'full') : '';
}

function wuai_sluyu_profile_image_delete($id, $uid, $kind) {
    if (!wuai_sluyu_profile_image_owned($id, $uid, $kind)) { return false; }
    return (bool) wp_delete_attachment($id, true);
}

function wuai_sluyu_profile_image_cleanup($uid, $all = false) {
    $ids = get_posts(['post_type'=>'attachment', 'post_status'=>['inherit', 'private', 'publish', 'draft', 'pending', 'trash'], 'author'=>$uid, 'posts_per_page'=>20, 'fields'=>'ids', 'no_found_rows'=>true, 'meta_key'=>'_wuai_profile_image_owner', 'meta_value'=>$uid]);
    $removed = false;
    foreach ($ids as $id) {
        $kind = get_post_meta($id, '_wuai_profile_image_kind', true);
        $key = wuai_sluyu_profile_image_key($kind);
        if (!$key || (!$all && absint(get_user_meta($uid, $key, true)) === (int) $id)) { continue; }
        $removed = wuai_sluyu_profile_image_delete($id, $uid, $kind) || $removed;
    }
    return $removed;
}

function wuai_sluyu_profile_image_remaining($uid) {
    return get_posts(['post_type'=>'attachment', 'post_status'=>['inherit', 'private', 'publish', 'draft', 'pending', 'trash'], 'author'=>$uid, 'posts_per_page'=>20, 'fields'=>'ids', 'no_found_rows'=>true, 'meta_key'=>'_wuai_profile_image_owner', 'meta_value'=>$uid]);
}

function wuai_sluyu_profile_image_authorized($uid) {
    return $uid && (int) $uid === get_current_user_id() && !wuai_sluyu_banned($uid);
}

function wuai_sluyu_profile_image_rate($uid) {
    if (get_transient('wuai_profile_image_rate_' . $uid)) { return new WP_Error('image_rate', '图片操作过于频繁，请等待 15 秒再试。'); }
    $budget = get_user_meta($uid, '_wuai_profile_image_budget', true);
    if (!is_array($budget) || empty($budget['until']) || (int) $budget['until'] <= time()) { $budget = ['until'=>time() + HOUR_IN_SECONDS, 'count'=>0]; }
    if ((int) $budget['count'] >= 12) { return new WP_Error('image_budget', '每小时最多尝试上传 12 次图片，请稍后再试。'); }
    $budget['count'] = (int) $budget['count'] + 1;
    if (!update_user_meta($uid, '_wuai_profile_image_budget', $budget)) { return new WP_Error('image_storage', '无法记录上传额度，请稍后再试。'); }
    set_transient('wuai_profile_image_rate_' . $uid, 1, 15);
    return true;
}

// The original is decoded in private temporary storage, never copied into uploads.
function wuai_sluyu_profile_image_reencode($path, $kind, $name) {
    if (!wuai_sluyu_profile_image_key($kind) || !is_string($path) || strpos($path, '://') !== false || !is_file($path) || !is_readable($path) || !is_string($name)) { return new WP_Error('image_file', '请选择有效的图片文件。'); }
    $size = filesize($path);
    if (!$size || $size > 4 * MB_IN_BYTES || $size > wp_max_upload_size()) { return new WP_Error('image_size', '图片不能超过 4 MB 或服务器允许的上传上限。'); }
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $types = ['jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'png'=>'image/png', 'webp'=>'image/webp'];
    $mime = wp_get_image_mime($path);
    if (!isset($types[$extension]) || $types[$extension] !== $mime) { return new WP_Error('image_type', '仅支持扩展名与真实格式一致的 JPEG、PNG、WebP 图片，不支持 SVG 或 GIF。'); }
    $dimensions = @getimagesize($path);
    if (!$dimensions || empty($dimensions[0]) || empty($dimensions[1]) || ($dimensions['mime'] ?? '') !== $mime) { return new WP_Error('image_invalid', '无法读取此图片，请重新导出后上传。'); }
    $width = (int) $dimensions[0]; $height = (int) $dimensions[1];
    if ($width > 4096 || $height > 4096 || $width * $height > 8000000 || $width < ($kind === 'avatar' ? 32 : 120) || $height < ($kind === 'avatar' ? 32 : 40)) { return new WP_Error('image_dimensions', '图片最长边不超过 4096 像素、总像素不超过 800 万；头像至少 32×32，封面至少 120×40。'); }
    $decoder = ['image/jpeg'=>'imagecreatefromjpeg', 'image/png'=>'imagecreatefrompng', 'image/webp'=>'imagecreatefromwebp'][$mime];
    if (!extension_loaded('gd') || !function_exists($decoder) || !function_exists('imagecreatetruecolor') || !function_exists('imagejpeg') || !function_exists('imagepng')) { return new WP_Error('image_editor', '服务器需要启用支持此格式的 PHP GD 图像扩展；为保护隐私，暂不保存未经重新编码的原图。'); }
    $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
    if ($limit > 0 && memory_get_usage(true) + $width * $height * 9 + 24 * MB_IN_BYTES > $limit) { return new WP_Error('image_memory', '图片解码所需内存过大，请缩小尺寸后再试。'); }
    $source = @$decoder($path);
    if (!$source) { return new WP_Error('image_decode', '图片解码失败；请上传静态 JPEG、PNG 或 WebP 图片。'); }
    $canvas = null; $temp = '';
    try {
        if (imagesx($source) !== $width || imagesy($source) !== $height) { return new WP_Error('image_dimensions', '图片尺寸与解码结果不一致，请重新导出。'); }
        if ($kind === 'avatar') {
            $crop_width = $crop_height = min($width, $height);
            $out_width = $out_height = min(320, $crop_width);
        } else {
            $crop_width = min($width, $height * 3);
            $crop_height = max(1, (int) floor($crop_width / 3));
            $out_width = min(1500, $crop_width);
            $out_height = max(1, (int) floor($out_width / 3));
        }
        $canvas = imagecreatetruecolor($out_width, $out_height);
        if (!$canvas) { return new WP_Error('image_canvas', '图片处理失败，请稍后重试。'); }
        imagealphablending($canvas, false); imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        if (!imagecopyresampled($canvas, $source, 0, 0, (int) floor(($width - $crop_width) / 2), (int) floor(($height - $crop_height) / 2), $out_width, $out_height, $crop_width, $crop_height)) { return new WP_Error('image_resize', '图片裁切失败，请重试。'); }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $temp = wp_tempnam('wuai-profile-' . wp_generate_uuid4());
        if (!$temp || !is_file($temp) || !is_writable($temp)) { return new WP_Error('image_temp', '服务器无法创建安全临时文件。'); }
        $output_mime = $mime === 'image/jpeg' ? 'image/jpeg' : 'image/png';
        $saved = $output_mime === 'image/jpeg' ? imagejpeg($canvas, $temp, 88) : imagepng($canvas, $temp, 7);
        if (!$saved || !is_file($temp) || !filesize($temp) || filesize($temp) > 4 * MB_IN_BYTES || wp_get_image_mime($temp) !== $output_mime) { return new WP_Error('image_encode', '图片保存失败或重新编码后过大，请缩小图片后重试。'); }
        $result = ['path'=>$temp, 'name'=>'profile-' . $kind . '-' . wp_generate_uuid4() . ($output_mime === 'image/jpeg' ? '.jpg' : '.png'), 'mime'=>$output_mime, 'width'=>$out_width, 'height'=>$out_height, 'size'=>filesize($temp)];
        $temp = '';
        return $result;
    } finally {
        if ($canvas) { imagedestroy($canvas); }
        imagedestroy($source);
        if ($temp && is_file($temp)) { wp_delete_file($temp); }
    }
}

function wuai_sluyu_profile_image_commit($uid, $kind, $encoded) {
    if (!wuai_sluyu_profile_image_authorized($uid) || !wuai_sluyu_profile_image_key($kind)) { return new WP_Error('image_permission', '只能修改自己的头像或封面。'); }
    if (!is_array($encoded) || empty($encoded['path']) || empty($encoded['name']) || !is_file($encoded['path'])) { return new WP_Error('image_encoded', '图片处理结果已失效，请重新上传。'); }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wuai_sluyu_profile_image_cleanup($uid);
    $existing = wuai_sluyu_profile_image_remaining($uid);
    if (count($existing) >= 5) { return new WP_Error('image_quota', '旧图片清理尚未完成，请联系管理员后再上传。'); }
    $file = ['name'=>$encoded['name'], 'tmp_name'=>$encoded['path'], 'size'=>filesize($encoded['path']), 'error'=>UPLOAD_ERR_OK, 'type'=>$encoded['mime']];
    $upload = wp_handle_sideload($file, ['test_form'=>false, 'mimes'=>['jpg|jpeg'=>'image/jpeg', 'png'=>'image/png']]);
    if (!empty($upload['error'])) { return new WP_Error('image_upload', '服务器无法保存图片，请稍后重试。'); }
    $id = wp_insert_attachment(['post_author'=>$uid, 'post_title'=>$kind === 'avatar' ? '用户头像' : '个人主页封面', 'post_status'=>'inherit', 'post_mime_type'=>$upload['type'], 'post_content'=>'', 'post_excerpt'=>'', 'meta_input'=>['_wuai_profile_image_owner'=>$uid, '_wuai_profile_image_kind'=>$kind]], $upload['file'], 0, true);
    if (is_wp_error($id) || !$id) { wp_delete_file($upload['file']); return new WP_Error('image_attachment', '图片记录保存失败，请重试。'); }
    $bound = false;
    $sizes_filter = function ($sizes, $metadata, $attachment_id) use ($id) { return (int) $attachment_id === (int) $id ? [] : $sizes; };
    add_filter('intermediate_image_sizes_advanced', $sizes_filter, 100, 3);
    try {
        $metadata = wp_generate_attachment_metadata($id, $upload['file']);
        if (!is_array($metadata) || empty($metadata['width']) || empty($metadata['height'])) { return new WP_Error('image_metadata', '图片信息保存失败，请重试。'); }
        wp_update_attachment_metadata($id, $metadata);
        $stored_metadata = wp_get_attachment_metadata($id);
        if (!is_array($stored_metadata) || empty($stored_metadata['file']) || (int) ($stored_metadata['width'] ?? 0) !== (int) $metadata['width']) { return new WP_Error('image_metadata', '图片信息未能完整保存，请重试。'); }
        if (!wuai_sluyu_profile_image_owned($id, $uid, $kind)) { return new WP_Error('image_owner', '无法确认图片归属，请重试。'); }
        $key = wuai_sluyu_profile_image_key($kind); $old = absint(get_user_meta($uid, $key, true));
        if (!update_user_meta($uid, $key, $id) || absint(get_user_meta($uid, $key, true)) !== (int) $id) { return new WP_Error('image_binding', '图片未能关联到账号，请重试。'); }
        $bound = true;
        if ($old && $old !== (int) $id) { wuai_sluyu_profile_image_delete($old, $uid, $kind); }
        wuai_sluyu_profile_image_cleanup($uid);
        return ['id'=>(int) $id, 'url'=>wp_get_attachment_image_url($id, 'full'), 'message'=>$kind === 'avatar' ? '公开头像已更新。' : '公开主页封面已更新。', 'reload'=>true];
    } finally {
        remove_filter('intermediate_image_sizes_advanced', $sizes_filter, 100);
        if (!$bound) { wp_delete_attachment($id, true); }
    }
}

function wuai_sluyu_profile_image_upload($uid, $kind, $file) {
    if (!wuai_sluyu_profile_image_authorized($uid) || !wuai_sluyu_profile_image_key($kind)) { return new WP_Error('image_permission', '只能修改自己的头像或封面。'); }
    if (!is_array($file)) { return new WP_Error('image_missing', '请选择要上传的图片。'); }
    foreach (['name', 'tmp_name', 'error', 'size'] as $field) { if (!isset($file[$field]) || !is_scalar($file[$field])) { return new WP_Error('image_shape', '上传参数不正确。'); } }
    if ((int) $file['error'] !== UPLOAD_ERR_OK) { return new WP_Error('image_transfer', '上传未完成，请检查文件大小和网络后重试。'); }
    if (!is_uploaded_file((string) $file['tmp_name'])) { return new WP_Error('image_origin', '未检测到有效的浏览器上传文件。'); }
    $encoded = wuai_sluyu_profile_image_reencode((string) $file['tmp_name'], $kind, (string) $file['name']);
    if (is_wp_error($encoded)) { return $encoded; }
    try { return wuai_sluyu_profile_image_commit($uid, $kind, $encoded); }
    finally { if (is_file($encoded['path'])) { wp_delete_file($encoded['path']); } }
}

add_filter('wuai_sluyu_community_dispatch', function ($result, $operation, $uid) {
    if (!in_array($operation, ['profile_image', 'remove_profile_image'], true)) { return $result; }
    $kind = wuai_sluyu_input('image_kind'); $key = wuai_sluyu_profile_image_key($kind);
    if (!$key || !wuai_sluyu_profile_image_authorized($uid)) { return new WP_Error('image_permission', '请选择自己的头像或封面。'); }
    if ($operation === 'remove_profile_image') {
        $id = absint(get_user_meta($uid, $key, true));
        if ($id && wuai_sluyu_profile_image_owned($id, $uid, $kind) && !wuai_sluyu_profile_image_delete($id, $uid, $kind)) { return new WP_Error('image_remove', '图片删除未完成，请稍后再试或联系管理员。'); }
        if ($id && absint(get_user_meta($uid, $key, true)) === $id && !delete_user_meta($uid, $key)) { return new WP_Error('image_remove', '图片关联未能删除，请稍后重试。'); }
        wuai_sluyu_profile_image_cleanup($uid);
        return ['message'=>$kind === 'avatar' ? '已恢复默认头像。' : '已移除主页封面。', 'reload'=>true];
    }
    $rate = wuai_sluyu_profile_image_rate($uid);
    if (is_wp_error($rate)) { return $rate; }
    return wuai_sluyu_profile_image_upload($uid, $kind, $_FILES['profile_image'] ?? null);
}, 20, 3);

add_filter('get_avatar_data', function ($args, $identity) {
    if (!empty($args['force_default'])) { return $args; }
    $uid = 0;
    if ($identity instanceof WP_User) { $uid = $identity->ID; }
    elseif ($identity instanceof WP_Post) { $uid = $identity->post_author; }
    elseif ($identity instanceof WP_Comment) {
        if (!is_avatar_comment_type(get_comment_type($identity))) { return $args; }
        $uid = $identity->user_id;
    } elseif (is_numeric($identity)) { $uid = absint($identity); }
    elseif (is_string($identity) && is_email($identity)) { $user = get_user_by('email', $identity); $uid = $user ? $user->ID : 0; }
    $id = $uid ? wuai_sluyu_profile_image_id((int) $uid, 'avatar') : 0;
    $url = $id ? wp_get_attachment_image_url($id, 'full') : false;
    if ($url) { $args['url'] = $url; $args['found_avatar'] = true; }
    return $args;
}, 20, 2);

function wuai_sluyu_profile_media_form($uid = 0) {
    $uid = $uid ?: get_current_user_id();
    if (!$uid || $uid !== get_current_user_id()) { return; }
    echo '<div class="sluyu-profile-media"><h3>头像与主页封面</h3><p class="sluyu-muted">上传后会公开显示，任何访客均可查看；请勿上传证件、私密照片或不愿公开的内容。支持 JPEG、PNG、WebP，最多 4 MB / 800 万像素。图片会居中裁切，清除原始元信息，动画不保留。删除后第三方缓存可能仍需时间失效。</p>';
    if (!extension_loaded('gd')) { echo '<p class="sluyu-notice">服务器尚未启用 PHP GD 图像扩展，暂时不能上传图片，请联系管理员。</p>'; }
    foreach (['avatar'=>'头像', 'cover'=>'主页封面'] as $kind=>$label) {
        $id = wuai_sluyu_profile_image_id($uid, $kind);
        echo '<details class="sluyu-faq"><summary>修改' . esc_html($label) . '</summary>';
        if ($id) { echo wp_get_attachment_image($id, 'full', false, ['class'=>'sluyu-profile-media-preview', 'style'=>$kind === 'avatar' ? 'max-width:120px;height:auto;border-radius:50%' : 'max-width:100%;height:auto;border-radius:8px', 'alt'=>$label]); }
        echo '<form class="sluyu-community-form" data-operation="profile_image" enctype="multipart/form-data"><div class="sluyu-form-status" role="status" aria-live="polite"></div><input type="hidden" name="image_kind" value="' . esc_attr($kind) . '"><label>选择' . esc_html($label) . '图片<input type="file" name="profile_image" accept="image/jpeg,image/png,image/webp" required></label><p class="sluyu-muted">' . ($kind === 'avatar' ? '头像将裁切为正方形，最大 320×320。' : '封面将裁切为 3:1 横图，最大 1500×500。') . '</p><button type="submit" class="sluyu-action-primary"' . (!extension_loaded('gd') ? ' disabled' : '') . '>上传并公开' . esc_html($label) . '</button></form>';
        if ($id) { echo '<form class="sluyu-community-form" data-operation="remove_profile_image"><div class="sluyu-form-status" role="status" aria-live="polite"></div><input type="hidden" name="image_kind" value="' . esc_attr($kind) . '"><button type="submit" class="sluyu-action-plain">' . ($kind === 'avatar' ? '恢复默认头像' : '移除封面') . '</button></form>'; }
        echo '</details>';
    }
    echo '</div>';
}
add_action('wuai_sluyu_account_profile_extra', 'wuai_sluyu_profile_media_form');

add_action('delete_attachment', function ($id) {
    $uid = absint(get_post_meta($id, '_wuai_profile_image_owner', true));
    $key = wuai_sluyu_profile_image_key(get_post_meta($id, '_wuai_profile_image_kind', true));
    if ($uid && $key && absint(get_user_meta($uid, $key, true)) === (int) $id) { delete_user_meta($uid, $key); }
});
function wuai_sluyu_profile_media_erase($uid) {
    $removed = wuai_sluyu_profile_image_cleanup($uid, true);
    foreach (['avatar', 'cover'] as $kind) { $removed = delete_user_meta($uid, wuai_sluyu_profile_image_key($kind)) || $removed; }
    delete_user_meta($uid, '_wuai_profile_image_budget'); delete_transient('wuai_profile_image_rate_' . $uid);
    return $removed;
}
add_action('delete_user', 'wuai_sluyu_profile_media_erase');
add_action('wpmu_delete_user', 'wuai_sluyu_profile_media_erase');
add_filter('wp_privacy_personal_data_exporters', function ($exporters) {
    $exporters['wuai-profile-images'] = ['exporter_friendly_name'=>'公开头像与主页封面', 'callback'=>function ($email, $page = 1) {
        $user = get_user_by('email', $email); $data = [];
        if ($user && (int) $page === 1) { foreach (['avatar'=>'头像', 'cover'=>'主页封面'] as $kind=>$label) {
            $id = wuai_sluyu_profile_image_id($user->ID, $kind);
            if ($id) { $data[] = ['group_id'=>'wuai-profile-images', 'group_label'=>'公开资料图片', 'item_id'=>'wuai-profile-image-' . $id, 'data'=>[['name'=>'类型', 'value'=>$label], ['name'=>'公开图片地址', 'value'=>wp_get_attachment_image_url($id, 'full')]]]; }
        } }
        return ['data'=>$data, 'done'=>true];
    }];
    return $exporters;
});
add_filter('wp_privacy_personal_data_erasers', function ($erasers) {
    $erasers['wuai-profile-images'] = ['eraser_friendly_name'=>'公开头像与主页封面', 'callback'=>function ($email, $page = 1) {
        $user = get_user_by('email', $email); $removed = false; $retained = false;
        if ($user) {
            $lock = wuai_sluyu_lock('user_' . $user->ID);
            if (!$lock) { return ['items_removed'=>false, 'items_retained'=>true, 'messages'=>['账号正在执行其他操作，请稍后重试资料图片清除。'], 'done'=>true]; }
            try { $removed = wuai_sluyu_profile_media_erase($user->ID); $retained = (bool) wuai_sluyu_profile_image_remaining($user->ID); }
            finally { wuai_sluyu_unlock($lock); }
        }
        $messages = $removed ? ['头像和封面关联已移除；外部缓存副本可能仍需时间失效。'] : [];
        if ($retained) { $messages[] = '部分图片文件未能删除，需管理员检查媒体文件权限后重新执行清除。'; }
        return ['items_removed'=>$removed, 'items_retained'=>$retained, 'messages'=>$messages, 'done'=>true];
    }];
    return $erasers;
});
