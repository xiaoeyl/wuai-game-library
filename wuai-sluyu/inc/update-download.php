<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Only the selected release and GitHub's own asset hosts may serve an update. */
function wuai_sluyu_update_download_allowed($url, $package) {
    if (!is_string($url)) {
        return false;
    }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
        return false;
    }
    list($owner, $repo) = wuai_sluyu_update_source();
    $base = 'https://github.com/' . $owner . '/' . $repo . '/releases/download/';
    $api = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/releases/assets/';
    if ($url === ($package['url'] ?? '') && strpos($url, $base) === 0 && empty($parts['query'])) {
        return true;
    }
    if (!empty($package['api_url']) && $url === $package['api_url'] && strpos($url, $api) === 0 && ctype_digit(substr($url, strlen($api))) && empty($parts['query'])) {
        return true;
    }
    return in_array($parts['host'] ?? '', ['release-assets.githubusercontent.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com'], true);
}

function wuai_sluyu_update_download_retryable($error) {
    if ($error->get_error_code() === 'theme_download_http') {
        $data = $error->get_error_data();
        return in_array($data['status'] ?? 0, [403, 404, 408, 429, 500, 502, 503, 504], true);
    }
    return $error->get_error_code() === 'http_request_failed'
        && (bool) preg_match('/(?:cURL error (?:6|7|18|28|52|56)\b|timed?\s*out|timeout|could not resolve|failed to connect|connection (?:reset|refused|closed))/i', $error->get_error_message());
}

function wuai_sluyu_update_download_error($error) {
    $message = $error->get_error_message();
    if (preg_match('/(?:cURL error 28\b|timed?\s*out|timeout)/i', $message)) {
        $reason = '网站服务器连接 GitHub 安装包地址超时';
    } elseif (preg_match('/(?:cURL error 6\b|could not resolve)/i', $message)) {
        $reason = '网站服务器无法解析 GitHub 下载域名';
    } elseif (preg_match('/(?:cURL error (?:51|60|77)\b|certificate|SSL)/i', $message)) {
        $reason = '网站服务器未通过 GitHub HTTPS 证书校验，请检查系统时间与 CA 证书，不要关闭证书验证';
    } else {
        $reason = '网站服务器未能下载 GitHub 主题安装包';
    }
    return new WP_Error('theme_download_failed', $reason . '。本次未进入主题文件替换阶段。请稍后重新检查更新，或在本机下载 ZIP 后通过“外观 → 主题 → 安装主题 → 上传主题”替换；请勿反复点击。技术信息：' . wp_strip_all_tags($message));
}

/** The scoped Requests hook also controls connect_timeout and redirected requests. */
function wuai_sluyu_update_download_attempt($url, $package, $deadline) {
    $temporary = wp_tempnam('wuai-sluyu-update.zip');
    if (!$temporary) {
        return new WP_Error('theme_download_temp', '无法创建更新临时文件，请检查磁盘空间和临时目录权限。');
    }
    $keep = false;
    $guard = function (&$request_url, &$headers, &$data, &$method, &$options) use ($package, $deadline) {
        if (!wuai_sluyu_update_download_allowed($request_url, $package)) {
            throw new RuntimeException('更新下载被重定向到非 GitHub 官方地址，已停止。');
        }
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 1) {
            throw new RuntimeException('更新下载已达到本次请求的等待时限。');
        }
        // WP's timeout argument alone leaves Requests' connection timeout at 10s.
        $options['connect_timeout'] = min(30, $remaining);
        $options['timeout'] = min(60, $remaining);
    };
    add_action('requests-requests.before_request', $guard, 20, 5);
    try {
        $response = wp_safe_remote_get($url, [
            'timeout' => min(60, max(1, (int) floor($deadline - microtime(true)))),
            'redirection' => 3,
            'stream' => true,
            'filename' => $temporary,
            'sslverify' => true,
            'limit_response_size' => !empty($package['size']) ? min(50 * MB_IN_BYTES, $package['size']) + 1 : 50 * MB_IN_BYTES + 1,
            'headers' => ['Accept' => 'application/octet-stream', 'User-Agent' => 'Wuai-Sluyu/' . WUAI_SLUYU_VERSION],
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return new WP_Error('theme_download_http', 'GitHub 下载地址返回 HTTP ' . $status . '。', ['status' => $status]);
        }
        clearstatcache(true, $temporary);
        $size = filesize($temporary);
        if (!$size || $size > 50 * MB_IN_BYTES || (!empty($package['size']) && $size !== $package['size'])) {
            return new WP_Error('theme_package_size', '安装包大小不完整或超出限制，旧主题未被替换，请重新下载。');
        }
        if (preg_match('/^sha256:([a-f0-9]{64})$/i', $package['digest'] ?? '', $digest)) {
            $actual = hash_file('sha256', $temporary);
            if (!is_string($actual) || !hash_equals(strtolower($digest[1]), $actual)) {
                return new WP_Error('theme_package_digest', '下载文件的 SHA256 校验失败，旧主题未被替换，请重新下载。');
            }
        }
        $keep = true;
        return $temporary;
    } catch (Throwable $error) {
        return new WP_Error('theme_download_guard', $error->getMessage());
    } finally {
        remove_action('requests-requests.before_request', $guard, 20);
        if (!$keep && is_file($temporary)) {
            wp_delete_file($temporary);
        }
    }
}

/** Retry read-only downloads, never the installation or checksum failures. */
function wuai_sluyu_update_download_package($package, $progress = null) {
    if (!is_array($package) || empty($package['url']) || !wuai_sluyu_update_download_allowed($package['url'], $package)) {
        return new WP_Error('theme_download_source', '更新安装包地址无效，请重新检查更新。');
    }
    if (!function_exists('wp_tempnam')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $deadline = microtime(true) + 150;
    $routes = [$package['url']];
    if (!empty($package['api_url'])) {
        $routes[] = $package['api_url'];
    }
    $routes[] = $package['url'];
    $last = null;
    foreach ($routes as $index => $url) {
        if (microtime(true) >= $deadline - 1) {
            break;
        }
        if (is_callable($progress)) {
            $progress(($index ? '上次下载未成功，正在重试' : '正在下载主题安装包') . '（' . ($index + 1) . '/' . count($routes) . '，' . ($url === $package['url'] ? 'GitHub 官方下载' : 'GitHub 官方 API 备用通道') . '）…');
        }
        $last = wuai_sluyu_update_download_attempt($url, $package, min($deadline, microtime(true) + 60));
        if (!is_wp_error($last)) {
            return $last;
        }
        if (!wuai_sluyu_update_download_retryable($last)) {
            break;
        }
    }
    if ($last && in_array($last->get_error_code(), ['theme_package_digest', 'theme_package_size', 'theme_download_temp', 'theme_download_guard'], true)) {
        return $last;
    }
    return wuai_sluyu_update_download_error($last ?: new WP_Error('http_request_failed', '下载等待超时。'));
}

/** Native WP theme updates use the same guarded downloader as the settings panel. */
add_filter('upgrader_pre_download', function ($reply, $url, $upgrader, $extra) {
    if ($reply !== false || ($extra['theme'] ?? '') !== basename(WUAI_SLUYU_DIR)) {
        return $reply;
    }
    list($owner, $repo) = wuai_sluyu_update_source();
    if (!is_string($url) || strpos($url, 'https://github.com/' . $owner . '/' . $repo . '/releases/download/') !== 0) {
        return $reply;
    }
    $release = wuai_sluyu_github_release();
    $package = is_wp_error($release) ? $release : wuai_sluyu_release_package($release);
    if (is_wp_error($package)) {
        return $package;
    }
    return $url === $package['url'] ? wuai_sluyu_update_download_package($package) : $reply;
}, 30, 4);
