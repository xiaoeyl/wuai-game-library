<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Theme settings stored under the existing option key. */
function wuai_sluyu_defaults() {
    return [
        'site_logo' => '',
        'site_icon' => '',
        'site_title' => '吾爱游戏资源',
        'site_description' => '发现好玩的游戏，分享值得收藏的资源。',
        'hero_title' => '二次元美图资源分享',
        'hero_notice' => '本站不包含任何色情等不符合国家相关法律的信息！',
        'hero_video' => 'https://img-baofun.zhhainiao.com/pcwallpaper_ugc/preview/9b3f6e857ed4ce7c957d9a1d7a2eaa53_preview.mp4',
        'hero_image' => wuai_sluyu_asset('images/hero.jpg'),
        'hot_words' => '生化危机9,使命召唤系列,剑星,看门狗2',
        'promo_image' => wuai_sluyu_asset('images/promo.jpg'),
        'ad_image' => wuai_sluyu_asset('images/ad.jpg'),
        'sections' => "图包\n美图\n游戏资源\n资讯",
        'resource_badge' => '资源分享',
        'download_title' => '网盘下载',
        'show_post_meta' => '1',
        'show_resource_box' => '1',
        'enable_comments' => '1',
        'primary_color' => '#ef0053',
        'accent_color' => '#9b55d8',
        'background_color' => '#f4f6f7',
        'border_radius' => '12',
        'content_width' => '1250',
        'footer_text' => '记录每一份热爱，分享每一次发现。',
        'show_floating_tools' => '1',
        'show_mobile_nav' => '1',
        'enable_lightbox' => '1',
        'enable_loader' => '1',
        'enable_particles' => '0',
        'glass_cards' => '1',
        'color_scrollbar' => '1',
        'seo_description' => '',
        'seo_keywords' => '',
        'enable_og' => '1',
        'github_owner' => 'xiaoeyl',
        'github_repo' => 'wuai-game-library',
        'github_channel' => 'stable',
        'legacy_html_links' => '1',
    ];
}

function wuai_sluyu_get_options() {
    return wp_parse_args((array) get_option('wuai_sluyu_options', []), wuai_sluyu_defaults());
}

/** Use the same value format as the front end, including disabled switches. */
function wuai_sluyu_sanitize_options($input) {
    $defaults = wuai_sluyu_defaults();
    $input = is_array($input) ? $input : [];
    $output = $defaults;
    $switches = ['show_post_meta', 'show_resource_box', 'enable_comments', 'show_floating_tools', 'show_mobile_nav', 'enable_lightbox', 'enable_loader', 'enable_particles', 'glass_cards', 'color_scrollbar', 'enable_og', 'legacy_html_links'];
    $urls = ['site_logo', 'site_icon', 'hero_video', 'hero_image', 'promo_image', 'ad_image'];
    $multiline = ['site_description', 'hero_notice', 'sections', 'seo_description', 'footer_text'];
    foreach ($defaults as $key => $default) {
        if (!array_key_exists($key, $input) || !is_scalar($input[$key])) {
            continue;
        }
        $value = (string) $input[$key];
        if (in_array($key, $switches, true)) {
            $output[$key] = in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
        } elseif (in_array($key, $urls, true)) {
            $output[$key] = esc_url_raw($value, ['http', 'https']);
        } elseif (in_array($key, ['primary_color', 'accent_color', 'background_color'], true)) {
            $output[$key] = sanitize_hex_color($value) ?: $default;
        } elseif ($key === 'border_radius' || $key === 'content_width') {
            $output[$key] = (string) max($key === 'content_width' ? 980 : 0, min($key === 'content_width' ? 1600 : 32, absint($value)));
        } elseif ($key === 'github_channel') {
            $output[$key] = in_array($value, ['stable', 'beta'], true) ? $value : 'stable';
        } elseif ($key === 'github_owner' || $key === 'github_repo') {
            $output[$key] = preg_replace('/[^a-zA-Z0-9_.-]/', '', $value);
        } elseif (in_array($key, $multiline, true)) {
            $output[$key] = sanitize_textarea_field($value);
        } else {
            $output[$key] = sanitize_text_field($value);
        }
    }
    return $output;
}

/** Every write path (CSF save/import/reset/restore) shares validation and history. */
add_filter('pre_update_option_wuai_sluyu_options', function ($value, $previous) {
    $value = wuai_sluyu_sanitize_options($value);
    if (is_array($previous) && $previous && $previous !== $value) {
        wuai_sluyu_backup_options('更改前自动备份', $previous);
    }
    return $value;
}, 10, 2);

function wuai_sluyu_backup_options($reason = '手动备份', $options = null) {
    $options = $options === null ? wuai_sluyu_get_options() : $options;
    $backups = get_option('wuai_sluyu_options_backups', []);
    $backups = is_array($backups) ? $backups : [];
    $id = wp_generate_uuid4();
    $backups[$id] = [
        'time' => current_time('Y-m-d H:i:s'),
        'reason' => $reason,
        'data' => is_array($options) ? $options : [],
    ];
    update_option('wuai_sluyu_options_backups', array_slice($backups, -20, null, true), false);
    return $id;
}

function wuai_sluyu_settings_history_action() {
    if (!current_user_can('manage_options')) {
        wp_die('你没有管理主题设置的权限。', '', ['response' => 403]);
    }
    check_admin_referer('wuai_sluyu_settings_history');
    $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
    if ($operation === 'backup') {
        wuai_sluyu_backup_options();
    } elseif ($operation === 'restore') {
        $id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';
        $backups = get_option('wuai_sluyu_options_backups', []);
        if (!isset($backups[$id]['data']) || !is_array($backups[$id]['data'])) {
            wp_die('此备份已不存在。', '', ['response' => 400]);
        }
        update_option('wuai_sluyu_options', $backups[$id]['data']);
    } else {
        wp_die('无效的备份操作。', '', ['response' => 400]);
    }
    wp_safe_redirect(admin_url('admin.php?page=wuai-sluyu&settings-history=' . $operation) . '#tab=' . rawurlencode('备份与恢复'));
    exit;
}
add_action('admin_post_wuai_sluyu_settings_history', 'wuai_sluyu_settings_history_action');

/** Bootstrap the actual CSF core and the supplied Zibll overrides before init. */
function wuai_sluyu_load_settings_framework() {
    if (!is_admin()) {
        return;
    }
    if (!class_exists('CSF', false)) {
        add_filter('csf_override', function () { return 'inc/csf-framework'; });
        add_filter('csf_fa4', '__return_true');
        require_once WUAI_SLUYU_DIR . '/inc/codestar-framework/codestar-framework.php';
    }
    wuai_sluyu_register_csf_options();
}
add_action('after_setup_theme', 'wuai_sluyu_load_settings_framework', 20);

function wuai_sluyu_csf_field($id, $type, $title, $extra = []) {
    $field = ['id' => $id, 'type' => $type, 'title' => $title];
    if ($type === 'switcher') {
        $field += ['text_on' => '开启', 'text_off' => '关闭'];
    }
    if ($type === 'upload') {
        $field += ['library' => 'image', 'preview' => true, 'button_title' => '选择图片', 'remove_title' => '移除'];
    }
    return array_merge($field, $extra);
}

function wuai_sluyu_register_csf_options() {
    $prefix = 'wuai_sluyu_options';
    CSF::createOptions($prefix, [
        'menu_title' => '吾爱森鹿语',
        'menu_slug' => 'wuai-sluyu',
        'menu_icon' => 'dashicons-art',
        'menu_position' => 58,
        'menu_capability' => 'manage_options',
        'framework_title' => '吾爱森鹿语 <small>V' . WUAI_SLUYU_VERSION . '</small>',
        'framework_class' => 'wuai-csf-settings',
        'theme' => 'light',
        'show_bar_menu' => false,
        'show_sub_menu' => false,
        'show_in_network' => false,
        'show_search' => true,
        'show_reset_all' => true,
        'show_reset_section' => true,
        'show_all_options' => true,
        'show_form_warning' => true,
        'ajax_save' => true,
        'save_defaults' => false,
        'output_css' => false,
        'enqueue_webfont' => false,
        'defaults' => wuai_sluyu_defaults(),
        'footer_text' => '吾爱森鹿语 · CSF 2.2.0 / 子比设置面板',
        'footer_credit' => '吾爱森鹿语主题设置',
    ]);
    CSF::createSection($prefix, [
        'title' => '主题概览', 'icon' => 'fa fa-dashboard',
        'fields' => [['type' => 'callback', 'function' => 'wuai_sluyu_csf_overview']],
    ]);
    CSF::createSection($prefix, ['id' => 'basic', 'title' => '基础设置', 'icon' => 'fa fa-cog']);
    CSF::createSection($prefix, [
        'parent' => 'basic', 'title' => '站点信息', 'icon' => 'fa fa-globe',
        'fields' => [
            wuai_sluyu_csf_field('site_title', 'text', '站点显示名称'),
            wuai_sluyu_csf_field('site_description', 'textarea', '站点简介'),
            wuai_sluyu_csf_field('site_logo', 'upload', '网站 Logo', ['desc' => '支持媒体库或图片外链；留空使用 WordPress 自定义 Logo 或默认 Logo。']),
            wuai_sluyu_csf_field('site_icon', 'upload', '站点图标', ['desc' => '建议使用正方形 PNG 图片。']),
        ],
    ]);
    CSF::createSection($prefix, [
        'parent' => 'basic', 'title' => '页脚与导航', 'icon' => 'fa fa-bars',
        'fields' => [
            wuai_sluyu_csf_field('footer_text', 'textarea', '页脚说明'),
            ['type' => 'content', 'content' => '<p>主导航和页脚导航使用 WordPress 菜单管理。</p><a class="button" href="' . esc_url(admin_url('nav-menus.php')) . '">管理导航菜单</a>'],
        ],
    ]);
    CSF::createSection($prefix, ['id' => 'home', 'title' => '首页布局', 'icon' => 'fa fa-home']);
    CSF::createSection($prefix, [
        'parent' => 'home', 'title' => '首屏与搜索', 'icon' => 'fa fa-picture-o',
        'fields' => [
            wuai_sluyu_csf_field('hero_title', 'text', '首屏主标题'),
            wuai_sluyu_csf_field('hero_notice', 'textarea', '首页公告'),
            wuai_sluyu_csf_field('hero_video', 'upload', '背景视频', ['library' => 'video', 'preview' => false, 'button_title' => '选择视频', 'desc' => '支持 MP4 外链或媒体库视频；视频不能播放时使用背景图片。']),
            wuai_sluyu_csf_field('hero_image', 'upload', '背景图片'),
            wuai_sluyu_csf_field('hot_words', 'text', '热搜关键词', ['desc' => '使用英文或中文逗号分隔。']),
        ],
    ]);
    CSF::createSection($prefix, [
        'parent' => 'home', 'title' => '栏目与广告', 'icon' => 'fa fa-th-large',
        'fields' => [
            wuai_sluyu_csf_field('sections', 'textarea', '首页栏目', ['desc' => '每行一个已有分类名称，最多显示四个栏目。只显示已发布文章，空栏目不生成演示文章。']),
            wuai_sluyu_csf_field('promo_image', 'upload', '首页横幅'),
            wuai_sluyu_csf_field('ad_image', 'upload', '侧栏广告图片'),
        ],
    ]);
    CSF::createSection($prefix, [
        'title' => '文章与资源', 'icon' => 'fa fa-file-text-o',
        'fields' => [
            wuai_sluyu_csf_field('resource_badge', 'text', '默认标题徽章'),
            wuai_sluyu_csf_field('download_title', 'text', '网盘下载区标题'),
            wuai_sluyu_csf_field('show_post_meta', 'switcher', '显示文章信息'),
            wuai_sluyu_csf_field('show_resource_box', 'switcher', '显示资源下载区'),
            wuai_sluyu_csf_field('enable_comments', 'switcher', '显示文章评论'),
            wuai_sluyu_csf_field('legacy_html_links', 'switcher', '使用 .html 文章地址', ['desc' => '更改后请在“设置 → 固定链接”保存一次，刷新重写规则。']),
        ],
    ]);
    CSF::createSection($prefix, ['id' => 'appearance', 'title' => '界面美化', 'icon' => 'fa fa-paint-brush']);
    CSF::createSection($prefix, [
        'parent' => 'appearance', 'title' => '颜色与布局', 'icon' => 'fa fa-adjust',
        'fields' => [
            wuai_sluyu_csf_field('primary_color', 'color', '主色调'),
            wuai_sluyu_csf_field('accent_color', 'color', '辅助色'),
            wuai_sluyu_csf_field('background_color', 'color', '页面背景色'),
            wuai_sluyu_csf_field('border_radius', 'number', '卡片圆角', ['unit' => 'px', 'attributes' => ['min' => 0, 'max' => 32]]),
            wuai_sluyu_csf_field('content_width', 'number', '内容区域宽度', ['unit' => 'px', 'attributes' => ['min' => 980, 'max' => 1600]]),
        ],
    ]);
    CSF::createSection($prefix, [
        'parent' => 'appearance', 'title' => '交互与特效', 'icon' => 'fa fa-magic',
        'fields' => [
            wuai_sluyu_csf_field('show_floating_tools', 'switcher', '显示悬浮工具'),
            wuai_sluyu_csf_field('show_mobile_nav', 'switcher', '显示移动端底部导航'),
            wuai_sluyu_csf_field('enable_lightbox', 'switcher', '文章图片灯箱'),
            wuai_sluyu_csf_field('enable_loader', 'switcher', '页面加载动画'),
            wuai_sluyu_csf_field('enable_particles', 'switcher', '粒子背景'),
            wuai_sluyu_csf_field('glass_cards', 'switcher', '玻璃卡片效果'),
            wuai_sluyu_csf_field('color_scrollbar', 'switcher', '彩色滚动条'),
        ],
    ]);
    CSF::createSection($prefix, [
        'title' => 'SEO 设置', 'icon' => 'fa fa-search',
        'fields' => [
            wuai_sluyu_csf_field('seo_description', 'textarea', '首页 SEO 描述'),
            wuai_sluyu_csf_field('seo_keywords', 'text', '首页关键词'),
            wuai_sluyu_csf_field('enable_og', 'switcher', '社交分享信息'),
        ],
    ]);
    CSF::createSection($prefix, [
        'title' => '在线更新', 'icon' => 'fa fa-refresh',
        'fields' => [
            ['type' => 'callback', 'function' => 'wuai_sluyu_csf_update_info'],
            wuai_sluyu_csf_field('github_owner', 'text', 'GitHub 用户名'),
            wuai_sluyu_csf_field('github_repo', 'text', 'GitHub 仓库名'),
            wuai_sluyu_csf_field('github_channel', 'select', '更新通道', ['options' => ['stable' => '正式版', 'beta' => '预发布版'], 'desc' => '正式版读取最新 Release，预发布版同时检查标记为预发布的版本。']),
        ],
    ]);
    CSF::createSection($prefix, [
        'title' => '备份与恢复', 'icon' => 'fa fa-database',
        'fields' => [
            ['type' => 'notice', 'style' => 'info', 'content' => '修改、导入或重置前自动保留原配置，最多保存 20 份。备份仅包含主题设置，不包含文章和媒体文件。'],
            ['type' => 'backup', 'title' => '导入 / 导出设置'],
            ['type' => 'callback', 'function' => 'wuai_sluyu_csf_history'],
        ],
    ]);
}

function wuai_sluyu_csf_overview() {
    echo '<div class="wuai-csf-overview"><h2>吾爱森鹿语 V' . esc_html(WUAI_SLUYU_VERSION) . '</h2>';
    echo '<p>设置面板使用用户提供的子比源码中的 Codestar Framework 2.2.0 核心与子比设置层。左侧选择分组，右上角可搜索、保存或重置设置。</p>';
    echo '<p>站点信息、首页布局、资源文章和美化设置沿用原配置，前台内容读取 WordPress 已发布文章。</p>';
    echo '<p><a class="button button-primary" href="' . esc_url(home_url('/')) . '" target="_blank" rel="noopener">查看网站</a> ';
    echo '<a class="button" href="' . esc_url(admin_url('post-new.php')) . '">发布文章</a> ';
    echo '<a class="button" href="' . esc_url(admin_url('edit-tags.php?taxonomy=category')) . '">管理分类</a></p>';
    echo '<p class="description">WordPress ' . esc_html(get_bloginfo('version')) . ' · PHP ' . esc_html(PHP_VERSION) . ' · CSF ' . esc_html(CSF::$version) . '</p></div>';
}

function wuai_sluyu_csf_update_info() {
    echo '<p>当前主题版本：<strong>' . esc_html(WUAI_SLUYU_VERSION) . '</strong>。更新继续通过你自己的 GitHub Releases 发布，由 WordPress 检测并安装。</p>';
    echo '<p>修改更新源或通道后请先保存，再点击检查更新。</p><a class="button button-primary" href="' . esc_url(admin_url('update-core.php?force-check=1')) . '">手动检查更新</a>';
}

function wuai_sluyu_csf_history() {
    $backups = get_option('wuai_sluyu_options_backups', []);
    $nonce = wp_create_nonce('wuai_sluyu_settings_history');
    echo '<h3>历史配置备份</h3><p><button type="button" class="button wuai-csf-history-action" data-operation="backup" data-nonce="' . esc_attr($nonce) . '">立即备份当前配置</button></p>';
    echo '<p class="description">恢复操作会先备份当前配置。恢复前请保存或放弃尚未保存的修改。</p>';
    if (!is_array($backups) || !$backups) {
        echo '<p>暂无历史备份，首次保存更改时会自动生成。</p>';
        return;
    }
    echo '<table class="widefat striped"><thead><tr><th>备份时间</th><th>备份原因</th><th>操作</th></tr></thead><tbody>';
    foreach (array_reverse($backups, true) as $id => $backup) {
        echo '<tr><td>' . esc_html($backup['time']) . '</td><td>' . esc_html($backup['reason']) . '</td><td><button type="button" class="button wuai-csf-history-action" data-operation="restore" data-backup-id="' . esc_attr($id) . '" data-nonce="' . esc_attr($nonce) . '">恢复此备份</button></td></tr>';
    }
    echo '</tbody></table>';
}

add_action('csf_enqueue', function () {
    $screen = get_current_screen();
    if (!$screen || substr($screen->id, -10) !== 'wuai-sluyu') {
        return;
    }
    wp_enqueue_style('wuai-csf-zibll', WUAI_SLUYU_URI . '/inc/csf-framework/assets/css/style.css', ['csf'], WUAI_SLUYU_VERSION);
    wp_enqueue_style('wuai-sluyu-admin', WUAI_SLUYU_URI . '/assets/css/admin.css', ['wuai-csf-zibll'], WUAI_SLUYU_VERSION);
    wp_enqueue_script('wuai-sluyu-admin', WUAI_SLUYU_URI . '/assets/js/admin.js', ['jquery', 'csf'], WUAI_SLUYU_VERSION, true);
    wp_localize_script('wuai-sluyu-admin', 'wuaiCsf', ['postUrl' => admin_url('admin-post.php')]);
});

