<?php
if (!defined('ABSPATH')) {
    exit;
}

function wuai_sluyu_update_auth($install = false) {
    if (!current_user_can('manage_options') || !current_user_can('update_themes')) {
        wp_send_json_error(['message' => '当前账号没有更新主题的权限。'], 403);
    }
    if (!check_ajax_referer('wuai_sluyu_update', 'nonce', false)) {
        wp_send_json_error(['message' => '页面验证已过期，请刷新后重试。'], 403);
    }
    if ($install && !wp_is_file_mod_allowed('wuai_sluyu_update')) {
        wp_send_json_error(['message' => 'WordPress 已禁止修改文件，请联系管理员或手动上传主题。'], 403);
    }
}

add_action('wp_ajax_wuai_sluyu_check_update', function () {
    wuai_sluyu_update_auth();
    $status = wuai_sluyu_update_snapshot();
    if ($status['active']) {
        wp_send_json_success(array_merge($status, ['busy' => true]));
    }
    $release = wuai_sluyu_github_release(!empty($_POST['force']));
    if (is_wp_error($release)) {
        wp_send_json_error(['message' => $release->get_error_message()]);
    }
    set_site_transient('update_themes', wuai_sluyu_update_transient(get_site_transient('update_themes'), $release));
    wp_send_json_success(wuai_sluyu_release_info($release));
});

function wuai_sluyu_update_progress($phase, $percent, $message, $extra = []) {
    $job = get_option('wuai_sluyu_update_job', []);
    $job = is_array($job) && !isset($extra['id']) ? $job : [];
    $job = array_merge($job, $extra, ['phase' => $phase, 'percent' => $percent, 'message' => wp_strip_all_tags($message), 'updated_at' => time()]);
    update_option('wuai_sluyu_update_job', $job, false);
    return $job;
}

/** A held OS lock survives slow downloads; never infer worker death from progress age. */
function wuai_sluyu_update_mutex() {
    $path = get_temp_dir() . 'wuai-sluyu-update-' . hash('sha256', wp_normalize_path(WUAI_SLUYU_DIR)) . '.lock';
    $handle = @fopen($path, 'c');
    if (!$handle) {
        return new WP_Error('update_lock', '无法创建更新进程锁，请检查临时目录权限。');
    }
    $would_block = 0;
    if (!@flock($handle, LOCK_EX | LOCK_NB, $would_block)) {
        fclose($handle);
        return $would_block ? false : new WP_Error('update_lock', '服务器不支持可靠的更新进程锁，请手动上传主题。');
    }
    // Never unlink this file: an existing worker may still hold its inode.
    return $handle;
}

function wuai_sluyu_update_mutex_release(&$handle) {
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $handle = null;
}

/** Polling only derives a display snapshot; it never overwrites a worker's job. */
function wuai_sluyu_update_snapshot() {
    $mutex = wuai_sluyu_update_mutex();
    foreach (['wuai_sluyu_update_job', 'wuai_sluyu_update.lock'] as $option) {
        wp_cache_delete($option, 'options');
    }
    $job = get_option('wuai_sluyu_update_job', []);
    $phases = ['checking', 'downloading', 'unpacking', 'installing', 'complete', 'error'];
    $valid = is_array($job) && is_string($job['id'] ?? null) && preg_match('/^[a-z0-9-]{12,80}$/D', $job['id']) && in_array($job['phase'] ?? null, $phases, true);
    $job = $valid ? $job : [];
    $installed = wuai_sluyu_installed_version();
    $legacy_lock = get_option('wuai_sluyu_update.lock', 0);
    // Respect older in-flight installers that did not yet acquire the OS lock.
    $active = !is_resource($mutex) || (($job['worker_lock'] ?? '') !== 'file-v1' && is_numeric($legacy_lock) && (int) $legacy_lock > time() - 15 * MINUTE_IN_SECONDS);
    if ($job) {
        $job['percent'] = max(0, min(100, (int) ($job['percent'] ?? 0)));
        $job['message'] = is_string($job['message'] ?? null) ? wp_strip_all_tags($job['message']) : '';
        $job['updated_at'] = is_numeric($job['updated_at'] ?? null) ? (int) $job['updated_at'] : 0;
        if (!$active && !in_array($job['phase'], ['complete', 'error'], true)) {
            // A matching style.css alone cannot prove that every file was installed.
            $job['phase'] = 'error';
            $job['message'] = '上次更新进程已结束，但未记录完整安装结果。当前磁盘版本 V' . $installed . '，请核对主题文件或重新安装完整更新包。';
        } elseif (!$active && time() - $job['updated_at'] > 15 * MINUTE_IN_SECONDS) {
            $job = [];
        }
    }
    $message = is_wp_error($mutex) ? $mutex->get_error_message() : '';
    wuai_sluyu_update_mutex_release($mutex);
    return ['job' => $job, 'current' => $installed, 'active' => $active, 'message' => $message];
}

function wuai_sluyu_update_status_response() {
    wuai_sluyu_update_auth();
    wp_send_json_success(wuai_sluyu_update_snapshot());
}
add_action('wp_ajax_wuai_sluyu_update_status', 'wuai_sluyu_update_status_response');
// Compatibility for an already-open older panel: this endpoint is read-only now.
add_action('wp_ajax_wuai_sluyu_abandon_update', 'wuai_sluyu_update_status_response');

/** Validate before WordPress moves the old theme, also for native WP updates. */
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $extra) {
    if (($extra['theme'] ?? '') !== basename(WUAI_SLUYU_DIR) || ($extra['action'] ?? '') !== 'update' || is_wp_error($source)) {
        return $source;
    }
    global $wp_filesystem, $wuai_sluyu_update_target;
    $release = $wuai_sluyu_update_target ?? wuai_sluyu_github_release();
    if (is_wp_error($release)) {
        return $release;
    }
    if (basename(untrailingslashit($source)) !== 'wuai-sluyu') {
        return new WP_Error('theme_package_directory', '更新包目录不正确，ZIP 顶层必须是 wuai-sluyu 文件夹，旧主题未被替换。');
    }
    foreach (['style.css', 'functions.php', 'index.php', 'inc/github-updater.php', 'inc/theme-update-actions.php', 'inc/admin-options.php'] as $required) {
        if (!$wp_filesystem->is_file($source . $required)) {
            return new WP_Error('theme_package_missing', '更新包缺少必需文件：' . $required . '，旧主题未被替换。');
        }
    }
    $style = (string) $wp_filesystem->get_contents($source . 'style.css');
    $functions = (string) $wp_filesystem->get_contents($source . 'functions.php');
    // Reject incomplete packages before moving the old theme out of the way.
    $bootstrap_files = [$functions, (string) $wp_filesystem->get_contents($source . 'inc/admin-options.php'), (string) $wp_filesystem->get_contents($source . 'inc/github-updater.php')];
    foreach ($bootstrap_files as $bootstrap) {
        preg_match_all('~require(?:_once)?\s+(?:WUAI_SLUYU_DIR|__DIR__)\s*\.\s*[\'"](/[^\'"]+)[\'"]~', $bootstrap, $includes);
        foreach ($includes[1] as $include) {
            $relative = ltrim($include, '/');
            if (strpos($include, '/inc/') !== 0) {
                $relative = 'inc/' . $relative;
            }
            if (!$wp_filesystem->is_file($source . $relative)) {
                return new WP_Error('theme_package_missing', '更新包缺少启动文件：' . $relative . '，旧主题未被替换。');
            }
        }
    }
    preg_match('/^[ \t*]*Version:\s*([^\r\n]+)/mi', $style, $match);
    $version = isset($match[1]) ? trim($match[1]) : '';
    preg_match('/define\s*\(\s*[\'"]WUAI_SLUYU_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $functions, $constant);
    if ($version !== ltrim($release['tag_name'], 'vV') || ($constant[1] ?? '') !== $version || version_compare($version, wuai_sluyu_installed_version(), '<=')) {
        return new WP_Error('theme_package_version', '更新包版本与 Release 不一致或不是新版本，旧主题未被替换。');
    }
    preg_match('/^[ \t*]*Requires PHP:\s*([^\r\n]+)/mi', $style, $php);
    preg_match('/^[ \t*]*Requires at least:\s*([^\r\n]+)/mi', $style, $wp);
    if ((!empty($php[1]) && version_compare(PHP_VERSION, trim($php[1]), '<')) || (!empty($wp[1]) && version_compare(get_bloginfo('version'), trim($wp[1]), '<'))) {
        return new WP_Error('theme_package_requirements', '服务器 PHP 或 WordPress 版本不满足新版主题要求，旧主题未被替换。');
    }
    return $source;
}, 10, 4);

add_action('wp_ajax_wuai_sluyu_install_update', function () {
    wuai_sluyu_update_auth(true);
    $id = sanitize_key(wp_unslash($_POST['job'] ?? ''));
    $version = sanitize_text_field(wp_unslash($_POST['version'] ?? ''));
    if (!preg_match('/^[a-z0-9-]{12,80}$/', $id)) {
        wp_send_json_error(['message' => '无效的更新请求，请重新检测版本。'], 400);
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    if (get_filesystem_method([], get_theme_root()) !== 'direct') {
        wp_send_json_error(['message' => '主题目录不能直接写入，请检查文件权限，或到“外观 → 主题”手动上传安装包。']);
    }
    $mutex = wuai_sluyu_update_mutex();
    if (is_wp_error($mutex)) {
        wp_send_json_error(['message' => $mutex->get_error_message()], 500);
    }
    if (!$mutex) {
        wp_send_json_error(['message' => '已有更新正在进行，请等待完成。', 'busy' => true], 409);
    }
    if (!WP_Upgrader::create_lock('wuai_sluyu_update', 15 * MINUTE_IN_SECONDS)) {
        wuai_sluyu_update_mutex_release($mutex);
        wp_send_json_error(['message' => '已有更新正在进行，请等待完成。', 'busy' => true], 409);
    }
    ignore_user_abort(true);
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    $finished = false;
    $upgrader = null;
    register_shutdown_function(function () use (&$finished, &$upgrader, &$mutex) {
        if (!$finished) {
            if ($upgrader) {
                $upgrader->maintenance_mode(false);
            }
            $job = get_option('wuai_sluyu_update_job', []);
            wuai_sluyu_update_progress('error', $job['percent'] ?? 0, '更新意外中断，请检查 PHP 错误日志、磁盘空间和当前主题版本。');
            WP_Upgrader::release_lock('wuai_sluyu_update');
            wuai_sluyu_update_mutex_release($mutex);
        }
    });
    wuai_sluyu_update_progress('checking', 5, '正在核对 GitHub 版本…', ['id' => $id, 'target' => $version, 'started_at' => time(), 'worker_lock' => 'file-v1']);
    $download = null;
    $progress = null;
    $downloaded_file = '';
    try {
        global $wuai_sluyu_update_target;
        $release = wuai_sluyu_github_release(true);
        if (is_wp_error($release)) {
            throw new RuntimeException($release->get_error_message());
        }
        $package = wuai_sluyu_release_package($release);
        if (is_wp_error($package)) {
            throw new RuntimeException($package->get_error_message());
        }
        if (ltrim($release['tag_name'], 'vV') !== $version || version_compare($version, wuai_sluyu_installed_version(), '<=')) {
            throw new RuntimeException('版本信息已变化或当前已经是最新版，请重新检查更新。');
        }
        $wuai_sluyu_update_target = $release;
        set_site_transient('update_themes', wuai_sluyu_update_transient(get_site_transient('update_themes'), $release));
        wuai_sluyu_backup_options('在线更新至 ' . $version);
        $download = function ($reply, $url) use ($package, &$downloaded_file) {
            if ($reply !== false || $url !== $package['url']) {
                return $reply;
            }
            $file = wuai_sluyu_update_download_package($package, function ($message) {
                wuai_sluyu_update_progress('downloading', 20, $message);
            });
            if (is_wp_error($file)) {
                return $file;
            }
            $downloaded_file = $file;
            wuai_sluyu_update_progress('unpacking', 45, '安装包已下载，正在解压和校验…');
            return $file;
        };
        $progress = function ($source, $remote_source, $instance, $extra) {
            if (($extra['theme'] ?? '') === basename(WUAI_SLUYU_DIR) && !is_wp_error($source)) {
                wuai_sluyu_update_progress('installing', 70, '安装包校验通过，正在替换主题文件…');
            }
            return $source;
        };
        add_filter('upgrader_pre_download', $download, 20, 2);
        add_filter('upgrader_source_selection', $progress, 20, 4);
        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);
        $result = $upgrader->upgrade(basename(WUAI_SLUYU_DIR));
        if (is_wp_error($result) || !$result || $skin->get_errors()->has_errors()) {
            throw new RuntimeException(is_wp_error($result) ? $result->get_error_message() : ($skin->get_error_messages() ?: '安装失败，请检查主题目录权限和磁盘空间。'));
        }
        if (wuai_sluyu_installed_version() !== $version) {
            throw new RuntimeException('安装后的主题版本校验失败，请检查主题目录后重试。');
        }
        $job = wuai_sluyu_update_progress('complete', 100, '更新成功！当前主题版本 V' . $version . '，请刷新页面载入新版。');
    } catch (Throwable $error) {
        // WordPress 6.3+ retains a temporary copy for failed theme upgrades.
        if ($upgrader && method_exists($upgrader, 'restore_temp_backup')) {
            $restored = $upgrader->restore_temp_backup();
            if (is_wp_error($restored)) {
                $error = new RuntimeException($error->getMessage() . ' 旧主题恢复失败：' . $restored->get_error_message());
            }
        }
        $current = get_option('wuai_sluyu_update_job', []);
        $job = wuai_sluyu_update_progress('error', $current['percent'] ?? 0, $error->getMessage());
    } finally {
        if ($upgrader) {
            // Core current_after() is skipped when package validation/install fails.
            $upgrader->maintenance_mode(false);
        }
        if ($download) {
            remove_filter('upgrader_pre_download', $download, 20);
        }
        if ($progress) {
            remove_filter('upgrader_source_selection', $progress, 20);
        }
        if ($downloaded_file && file_exists($downloaded_file)) {
            wp_delete_file($downloaded_file);
        }
        $wuai_sluyu_update_target = null;
        $finished = true;
        WP_Upgrader::release_lock('wuai_sluyu_update');
        wuai_sluyu_update_mutex_release($mutex);
    }
    wp_send_json_success(['job' => $job, 'current' => wuai_sluyu_installed_version()]);
});
