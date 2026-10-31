<?php
if (!defined('ABSPATH')) {
    exit;
}

function wuai_sluyu_update_source() {
    $o = wuai_sluyu_get_options();
    return [sanitize_key($o['github_owner'] ?? 'xiaoeyl'), sanitize_key($o['github_repo'] ?? 'wuai-game-library'), ($o['github_channel'] ?? 'stable') === 'beta' ? 'beta' : 'stable'];
}

/** The cache includes the repository and channel; manual checks bypass it. */
function wuai_sluyu_github_release($force = false) {
    list($owner, $repo, $channel) = wuai_sluyu_update_source();
    if (!$owner || !$repo) {
        return new WP_Error('update_source', '请填写 GitHub 用户名和仓库名，并保存设置。');
    }
    $key = 'wuai_release_' . md5($owner . '/' . $repo . '/' . $channel);
    $cached = get_transient($key);
    if (!$force && $cached !== false) {
        return $cached;
    }
    $endpoint = $channel === 'beta' ? 'releases?per_page=20' : 'releases/latest';
    $response = wp_remote_get("https://api.github.com/repos/{$owner}/{$repo}/{$endpoint}", [
        'timeout' => 20,
        'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Wuai-Sluyu/' . WUAI_SLUYU_VERSION],
        'limit_response_size' => 2 * MB_IN_BYTES,
    ]);
    if (is_wp_error($response)) {
        $release = new WP_Error('github_connection', '无法连接 GitHub：' . $response->get_error_message() . ' 请稍后重试。');
    } else {
        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status !== 200) {
            $messages = [404 => '未找到可用的 Release，请检查仓库是否公开、是否已发布正式版本。', 403 => 'GitHub 拒绝了请求，可能已达到 API 频率限制，请稍后重试。', 429 => 'GitHub 请求过于频繁，请稍后重试。'];
            $release = new WP_Error('github_http', $messages[$status] ?? ('GitHub 返回 HTTP ' . $status . '，暂时无法检查更新。'));
        } else {
            $releases = $channel === 'beta' ? $data : [$data];
            $releases = is_array($releases) ? array_filter($releases, function ($item) use ($channel) {
                return is_array($item) && empty($item['draft']) && ($channel === 'beta' || empty($item['prerelease'])) && preg_match('/^v?\d+\.\d+\.\d+(?:[-.][0-9a-z.-]+)?$/i', $item['tag_name'] ?? '');
            }) : [];
            usort($releases, function ($a, $b) {
                return version_compare(ltrim($b['tag_name'], 'vV'), ltrim($a['tag_name'], 'vV'));
            });
            $release = $releases ? reset($releases) : new WP_Error('github_release', '仓库中没有可用的版本，请发布带版本号（如 v1.0.8）的 Release。');
            if (!is_wp_error($release)) {
                $release['_checked_at'] = time();
            }
        }
    }
    set_transient($key, $release, is_wp_error($release) ? MINUTE_IN_SECONDS : HOUR_IN_SECONDS);
    return $release;
}

/** Only install a theme asset from the selected repository, never a whole-repo ZIP. */
function wuai_sluyu_release_package($release) {
    list($owner, $repo) = wuai_sluyu_update_source();
    $version = ltrim($release['tag_name'], 'vV');
    $base = 'https://github.com/' . $owner . '/' . $repo . '/releases/download/' . rawurlencode($release['tag_name']) . '/';
    foreach ((array) ($release['assets'] ?? []) as $asset) {
        $name = (string) ($asset['name'] ?? '');
        if (!in_array($name, ['wuai-sluyu-v' . $version . '.zip', 'wuai-sluyu-' . $version . '.zip', 'wuai-sluyu.zip'], true)) {
            continue;
        }
        $url = (string) ($asset['browser_download_url'] ?? '');
        if ($url === $base . rawurlencode($name)) {
            return ['url' => $url, 'digest' => (string) ($asset['digest'] ?? '')];
        }
    }
    return new WP_Error('update_package', '此版本尚未上传可安装的主题 ZIP，请在 GitHub Release 中上传 wuai-sluyu-v' . $version . '.zip。');
}

function wuai_sluyu_installed_version() {
    // Read the file, not the constant or cached WP_Theme from the pre-upgrade request.
    $headers = get_file_data(WUAI_SLUYU_DIR . '/style.css', ['Version' => 'Version']);
    return $headers['Version'];
}

function wuai_sluyu_release_info($release) {
    list($owner, $repo, $channel) = wuai_sluyu_update_source();
    $version = ltrim($release['tag_name'], 'vV');
    $package = wuai_sluyu_release_package($release);
    $current = wuai_sluyu_installed_version();
    return [
        'current' => $current, 'latest' => $version,
        'available' => version_compare($version, $current, '>'),
        'installable' => !is_wp_error($package) && current_user_can('update_themes') && wp_is_file_mod_allowed('wuai_sluyu_update'),
        'package_error' => is_wp_error($package) ? $package->get_error_message() : '',
        'notes' => (string) ($release['body'] ?? ''),
        'url' => 'https://github.com/' . $owner . '/' . $repo . '/releases/tag/' . rawurlencode($release['tag_name']),
        'checked_at' => wp_date('Y-m-d H:i:s', $release['_checked_at'] ?? time()),
        'channel' => $channel === 'beta' ? '预发布版' : '正式版',
    ];
}

function wuai_sluyu_update_transient($transient, $release) {
    if (!is_object($transient)) {
        $transient = new stdClass();
    }
    $slug = basename(WUAI_SLUYU_DIR);
    $version = ltrim($release['tag_name'], 'vV');
    $package = wuai_sluyu_release_package($release);
    unset($transient->response[$slug]);
    if (!is_wp_error($package) && version_compare($version, wuai_sluyu_installed_version(), '>')) {
        list($owner, $repo) = wuai_sluyu_update_source();
        $transient->response[$slug] = ['theme' => $slug, 'new_version' => $version, 'url' => 'https://github.com/' . $owner . '/' . $repo . '/releases', 'package' => $package['url']];
        unset($transient->no_update[$slug]);
    }
    return $transient;
}

add_filter('pre_set_site_transient_update_themes', function ($transient) {
    if (!is_object($transient) || empty($transient->checked)) {
        return $transient;
    }
    $release = wuai_sluyu_github_release();
    return is_wp_error($release) ? $transient : wuai_sluyu_update_transient($transient, $release);
});

add_filter('themes_api', function ($result, $action, $args) {
    if ($action !== 'theme_information' || empty($args->slug) || $args->slug !== basename(WUAI_SLUYU_DIR)) {
        return $result;
    }
    $release = wuai_sluyu_github_release();
    if (is_wp_error($release)) {
        return $result;
    }
    $package = wuai_sluyu_release_package($release);
    return (object) [
        'name' => '吾爱森鹿语', 'slug' => basename(WUAI_SLUYU_DIR), 'version' => ltrim($release['tag_name'], 'vV'),
        'author' => '吾爱游戏库', 'homepage' => wuai_sluyu_release_info($release)['url'],
        'sections' => ['description' => wpautop(esc_html($release['body'] ?? 'GitHub 最新版本。'))],
        'download_link' => is_wp_error($package) ? '' : $package['url'],
    ];
}, 10, 3);

require_once __DIR__ . '/theme-update-actions.php';
