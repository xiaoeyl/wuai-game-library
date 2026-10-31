<?php
// CLI-only integration suite; the public upload origin check is never bypassed.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('WP_QA_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('WP_QA_ROOT required.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8771'; $_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
if (home_url() !== 'http://127.0.0.1:8771') { throw new RuntimeException('Local QA only.'); }
if (!function_exists('wuai_sluyu_profile_image_reencode')) { throw new RuntimeException('Profile media module must be loaded.'); }
function pm_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function pm_error($result, $code, $message) { pm_check(is_wp_error($result) && $result->get_error_code() === $code, $message); }
function pm_dispose($file) { if (is_array($file) && !empty($file['path']) && is_file($file['path'])) { wp_delete_file($file['path']); } }
require_once ABSPATH . 'wp-admin/includes/file.php';
$users = [];
foreach (['owner', 'other'] as $role) {
    $login = 'qa_profile_media_' . $role; $user = get_user_by('login', $login);
    $uid = $user ? $user->ID : wp_create_user($login, 'qa-local-only', $login . '@example.test');
    (new WP_User($uid))->set_role('subscriber'); delete_user_meta($uid, '_wuai_banned_until');
    wuai_sluyu_profile_media_erase($uid); $users[$role] = $uid;
}
$owner = $users['owner']; $other = $users['other'];
wp_set_current_user($owner); $files = []; $ordinary_id = 0;
try {
    pm_check(!user_can($owner, 'upload_files'), 'Fixture is a subscriber without ordinary media upload capability.');
    $_POST = ['image_kind'=>'avatar']; $_FILES = [];
    pm_error(wuai_sluyu_community_operation('profile_image', $owner), 'image_missing', 'Missing upload rejected.');
    delete_transient('wuai_profile_image_rate_' . $owner);
    pm_error(wuai_sluyu_profile_image_upload($owner, 'avatar', ['name'=>['nested'], 'tmp_name'=>'x', 'error'=>0, 'size'=>10]), 'image_shape', 'Nested file input rejected.');
    pm_error(wuai_sluyu_profile_image_upload($owner, 'avatar', ['name'=>'x.png', 'tmp_name'=>'x', 'error'=>UPLOAD_ERR_PARTIAL, 'size'=>10]), 'image_transfer', 'Partial upload rejected.');
    pm_error(wuai_sluyu_profile_image_upload($owner, 'avatar', ['name'=>'x.png', 'tmp_name'=>__FILE__, 'error'=>0, 'size'=>filesize(__FILE__)]), 'image_origin', 'Non-HTTP local path rejected by the public upload entrypoint.');
    pm_error(wuai_sluyu_profile_image_upload($other, 'avatar', []), 'image_permission', 'Another account cannot be modified.');
    $not_image = wp_tempnam('qa-profile-not-image'); $files[] = $not_image;
    file_put_contents($not_image, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    pm_error(wuai_sluyu_profile_image_reencode($not_image, 'avatar', 'fake.png'), 'image_type', 'SVG disguised as PNG rejected.');
    pm_error(wuai_sluyu_profile_image_reencode('https://example.test/a.png', 'avatar', 'a.png'), 'image_file', 'Remote URLs rejected.');
    pm_error(wuai_sluyu_profile_image_reencode($not_image, 'unknown', 'image.png'), 'image_file', 'Unrecognized image slot rejected.');
    $large = wp_tempnam('qa-profile-large'); $files[] = $large; $handle = fopen($large, 'wb'); ftruncate($handle, 4 * MB_IN_BYTES + 1); fclose($handle);
    pm_error(wuai_sluyu_profile_image_reencode($large, 'avatar', 'huge.jpg'), 'image_size', 'Byte-size limit enforced.');
    if (!extension_loaded('gd')) { throw new RuntimeException('Run with php -d extension=gd tests/profile-media.php to verify real pixel re-encoding.'); }
    $source = wp_tempnam('qa-profile-source'); $files[] = $source;
    $canvas = imagecreatetruecolor(640, 480); imagefill($canvas, 0, 0, imagecolorallocate($canvas, 220, 80, 130)); imagepng($canvas, $source);
    $marker = 'QA-PRIVATE-EXIF-AND-TRAILING-PAYLOAD'; file_put_contents($source, $marker . '<?php echo "never-save-original"; ?>', FILE_APPEND);
    pm_error(wuai_sluyu_profile_image_reencode($source, 'avatar', 'image.svg'), 'image_type', 'Disallowed extension rejected even for real image.');
    pm_error(wuai_sluyu_profile_image_reencode($source, 'avatar', 'image.jpg'), 'image_type', 'Extension/MIME mismatch rejected.');
    $gif = wp_tempnam('qa-profile-gif'); $files[] = $gif; imagegif($canvas, $gif);
    pm_error(wuai_sluyu_profile_image_reencode($gif, 'avatar', 'image.gif'), 'image_type', 'GIF explicitly rejected.');
    $tiny = wp_tempnam('qa-profile-small'); $files[] = $tiny; $small = imagecreatetruecolor(20, 20); imagepng($small, $tiny); imagedestroy($small);
    pm_error(wuai_sluyu_profile_image_reencode($tiny, 'avatar', 'small.png'), 'image_dimensions', 'Minimum dimensions enforced.');
    $tall = wp_tempnam('qa-profile-tall'); $files[] = $tall; $small = imagecreatetruecolor(4097, 40); imagepng($small, $tall); imagedestroy($small);
    pm_error(wuai_sluyu_profile_image_reencode($tall, 'avatar', 'tall.png'), 'image_dimensions', 'Maximum edge enforced.');
    $pixel_limit = wp_tempnam('qa-profile-pixels'); $files[] = $pixel_limit; $large_canvas = imagecreatetruecolor(3000, 3000); imagepng($large_canvas, $pixel_limit); imagedestroy($large_canvas);
    pm_error(wuai_sluyu_profile_image_reencode($pixel_limit, 'avatar', 'pixels.png'), 'image_dimensions', 'Pixel-count limit enforced.');
    $encoded = wuai_sluyu_profile_image_reencode($source, 'avatar', 'avatar.png');
    pm_check(!is_wp_error($encoded), 'Real PNG re-encoded.'); $files[] = $encoded['path'];
    pm_check($encoded['width'] === 320 && $encoded['height'] === 320, 'Avatar centered and bounded.');
    pm_check(strpos(file_get_contents($encoded['path']), $marker) === false && strpos(file_get_contents($encoded['path']), '<?php') === false, 'Original trailing metadata and payload not retained.');
    $first = wuai_sluyu_profile_image_commit($owner, 'avatar', $encoded);
    pm_check(!is_wp_error($first), 'Subscriber can store dedicated profile attachment.'); $first_id = $first['id'];
    pm_check(wuai_sluyu_profile_image_id($owner, 'avatar') === $first_id, 'Stored image bound to its owner.');
    pm_check((int) get_post_field('post_author', $first_id) === $owner && get_post_meta($first_id, '_wuai_profile_image_owner', true) == $owner, 'Attachment ownership and dedicated marker preserved.');
    pm_check(empty(wp_get_attachment_metadata($first_id)['sizes']), 'Profile uploads do not create unrelated thumbnail copies.');
    $first_path = get_attached_file($first_id); pm_check(is_file($first_path), 'Encoded upload exists.');
    pm_check(strpos(file_get_contents($first_path), $marker) === false, 'Uploaded file excludes original metadata.');
    foreach ([$owner, get_userdata($owner), get_userdata($owner)->user_email] as $identity) { pm_check(get_avatar_data($identity)['url'] === $first['url'], 'Custom avatar resolves for WordPress identity types.'); }
    pm_check(get_avatar_data($owner, ['force_default'=>true])['url'] !== $first['url'], 'Force-default avatar honored.');
    $jpeg = wp_tempnam('qa-profile-jpeg'); $files[] = $jpeg; imagejpeg($canvas, $jpeg, 90);
    $encoded_jpeg = wuai_sluyu_profile_image_reencode($jpeg, 'avatar', 'avatar.jpg'); pm_check(!is_wp_error($encoded_jpeg) && $encoded_jpeg['mime'] === 'image/jpeg', 'JPEG decoded and re-encoded.'); $files[] = $encoded_jpeg['path'];
    $replacement = wuai_sluyu_profile_image_commit($owner, 'avatar', $encoded_jpeg);
    pm_check(!is_wp_error($replacement), 'Avatar replacement succeeds.');
    pm_check(!get_post($first_id) && !is_file($first_path), 'Only previous dedicated avatar removed after replacement.');
    $encoded_cover = wuai_sluyu_profile_image_reencode($source, 'cover', 'cover.png'); $files[] = $encoded_cover['path'];
    pm_check(!is_wp_error($encoded_cover) && $encoded_cover['width'] === 640 && $encoded_cover['height'] === 213, 'Cover cropped to a bounded landscape.');
    $cover = wuai_sluyu_profile_image_commit($owner, 'cover', $encoded_cover); pm_check(!is_wp_error($cover), 'Cover stored.');
    pm_check(wuai_sluyu_profile_cover_url($owner) === $cover['url'], 'Public cover URL resolves.');
    pm_check(wuai_sluyu_profile_image_id($owner, 'avatar') === $replacement['id'], 'Cover update leaves avatar unchanged.');
    if (function_exists('imagewebp')) {
        $webp = wp_tempnam('qa-profile-webp'); $files[] = $webp; imagewebp($canvas, $webp, 85);
        $webp_result = wuai_sluyu_profile_image_reencode($webp, 'avatar', 'avatar.webp');
        pm_check(!is_wp_error($webp_result) && $webp_result['mime'] === 'image/png', 'WebP safely flattened and encoded to PNG.'); pm_dispose($webp_result);
    }
    imagedestroy($canvas);
    wp_set_current_user($other); $_POST = ['image_kind'=>'avatar', 'id'=>$replacement['id'], 'user_id'=>$owner];
    $removed = wuai_sluyu_community_operation('remove_profile_image', $other);
    pm_check(!is_wp_error($removed) && wuai_sluyu_profile_image_id($owner, 'avatar') === $replacement['id'], 'Forged target IDs cannot remove another account image.');
    pm_check(!wuai_sluyu_profile_image_delete($replacement['id'], $other, 'avatar'), 'Ownership mismatch prevents deletion.');
    update_user_meta($other, '_wuai_profile_avatar', $replacement['id']);
    pm_check(wuai_sluyu_profile_image_id($other, 'avatar') === 0, 'Foreign attachment cannot be bound as avatar.');
    wuai_sluyu_community_operation('remove_profile_image', $other);
    pm_check((bool) get_post($replacement['id']), 'Removing a stale foreign binding does not delete its attachment.');
    wp_set_current_user($owner);
    $ordinary_id = wp_insert_attachment(['post_author'=>$owner, 'post_title'=>'QA ordinary media must survive', 'post_status'=>'inherit', 'post_mime_type'=>'image/png']);
    pm_check(!wuai_sluyu_profile_image_delete($ordinary_id, $owner, 'avatar'), 'Ordinary media without dedicated markers is never deleted.');
    update_user_meta($owner, '_wuai_profile_cover', $ordinary_id); $_POST = ['image_kind'=>'cover']; wuai_sluyu_community_operation('remove_profile_image', $owner);
    pm_check((bool) get_post($ordinary_id), 'Replacing a stale ordinary-media binding cannot delete it.');
    delete_transient('wuai_profile_image_rate_' . $owner); delete_user_meta($owner, '_wuai_profile_image_budget');
    pm_check(wuai_sluyu_profile_image_rate($owner) === true, 'Upload rate budget starts.');
    pm_error(wuai_sluyu_profile_image_rate($owner), 'image_rate', 'Short-interval limit enforced.');
    delete_transient('wuai_profile_image_rate_' . $owner); update_user_meta($owner, '_wuai_profile_image_budget', ['until'=>time() + 300, 'count'=>12]);
    pm_error(wuai_sluyu_profile_image_rate($owner), 'image_budget', 'Hourly attempt budget enforced.');
    update_user_meta($owner, '_wuai_banned_until', -1);
    pm_error(wuai_sluyu_profile_image_upload($owner, 'avatar', []), 'image_permission', 'Banned account cannot upload.'); delete_user_meta($owner, '_wuai_banned_until');
    $exporters = apply_filters('wp_privacy_personal_data_exporters', []);
    $export = call_user_func($exporters['wuai-profile-images']['callback'], get_userdata($owner)->user_email);
    pm_check($export['done'] && count($export['data']) === 1, 'Current profile image included in privacy export.');
    ob_start(); wuai_sluyu_profile_media_form($owner); $form = ob_get_clean();
    pm_check(strpos($form, 'multipart/form-data') !== false && strpos($form, '公开') !== false && strpos($form, 'name="profile_image"') !== false, 'Form uses multipart and explains public visibility.');
    $erasers = apply_filters('wp_privacy_personal_data_erasers', []);
    $erasure = call_user_func($erasers['wuai-profile-images']['callback'], get_userdata($owner)->user_email);
    pm_check($erasure['items_removed'] && !wuai_sluyu_profile_image_id($owner, 'avatar'), 'Privacy erasure removes dedicated media.');
    pm_check((bool) get_post($ordinary_id), 'Privacy erasure preserves ordinary media.');
    echo "PASS: profile-media file-shape/origin/MIME/bytes/pixels checks, pixel-only re-encoding, dedicated attachment ownership, replace/remove isolation, avatar resolver, cover rendering, upload budgets and privacy export/erasure.\n";
} finally {
    wp_set_current_user($owner); wuai_sluyu_profile_media_erase($owner); wuai_sluyu_profile_media_erase($other);
    if ($ordinary_id) { wp_delete_attachment($ordinary_id, true); }
    foreach ($files as $file) { if (is_string($file) && is_file($file)) { wp_delete_file($file); } }
    $_POST = []; $_FILES = [];
}
