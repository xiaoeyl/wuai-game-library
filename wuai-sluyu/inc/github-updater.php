<?php
if (!defined('ABSPATH')) {
    exit;
}

function wuai_sluyu_github_release() {
    $o = wuai_sluyu_get_options();
    $owner = sanitize_key($o['github_owner'] ?? 'xiaoeyl');
    $repo = sanitize_key($o['github_repo'] ?? 'wuai-game-library');
    if (!$owner || !$repo) {
        return false;
    }
    $response = wp_remote_get("https://api.github.com/repos/{$owner}/{$repo}/releases/latest", [
        'timeout' => 8,
        'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Wuai-Sluyu-Theme'],
    ]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return is_array($data) && !empty($data['tag_name']) ? $data : false;
}

add_filter('pre_set_site_transient_update_themes', function ($transient) {
    if (!is_object($transient) || empty($transient->checked)) {
        return $transient;
    }
    $release = wuai_sluyu_github_release();
    if (!$release) {
        return $transient;
    }
    $version = ltrim((string) $release['tag_name'], 'vV');
    if (version_compare($version, WUAI_SLUYU_VERSION, '<=')) {
        return $transient;
    }
    $o = wuai_sluyu_get_options();
    $theme_slug = basename(WUAI_SLUYU_DIR);
    $package = $release['zipball_url'] ?? '';
    foreach ((array) ($release['assets'] ?? []) as $asset) {
        if (!empty($asset['browser_download_url']) && preg_match('/wuai-sluyu.*\.zip$/i', (string) ($asset['name'] ?? ''))) {
            $package = $asset['browser_download_url'];
            break;
        }
    }
    $transient->response[$theme_slug] = [
        'theme' => $theme_slug,
        'new_version' => $version,
        'url' => $release['html_url'] ?? "https://github.com/{$o['github_owner']}/{$o['github_repo']}/releases",
        'package' => $package,
    ];
    return $transient;
});

add_filter('themes_api', function ($result, $action, $args) {
    if ($action !== 'theme_information' || empty($args->slug) || $args->slug !== basename(WUAI_SLUYU_DIR)) {
        return $result;
    }
    $release = wuai_sluyu_github_release();
    if (!$release) {
        return $result;
    }
    $package = $release['zipball_url'] ?? '';
    foreach ((array) ($release['assets'] ?? []) as $asset) {
        if (!empty($asset['browser_download_url']) && preg_match('/wuai-sluyu.*\.zip$/i', (string) ($asset['name'] ?? ''))) {
            $package = $asset['browser_download_url'];
            break;
        }
    }
    return (object) [
        'name' => '吾爱森鹿语',
        'slug' => basename(WUAI_SLUYU_DIR),
        'version' => ltrim((string) $release['tag_name'], 'vV'),
        'author' => '<a href="https://github.com/xiaoeyl">吾爱游戏库</a>',
        'homepage' => $release['html_url'] ?? '',
        'sections' => ['description' => wp_kses_post(wpautop($release['body'] ?? 'GitHub 最新版本。'))],
        'download_link' => $package,
    ];
}, 10, 3);
