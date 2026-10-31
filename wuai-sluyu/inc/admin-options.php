<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Theme settings. The UI intentionally uses a small, self-contained framework
 * instead of shipping the commercial Zibll source. It follows the same useful
 * ideas: grouped tabs, cards, switches and a predictable settings API.
 */
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

function wuai_sluyu_admin_groups() {
    return [
        'dashboard' => ['label' => '概览', 'icon' => 'dashicons-dashboard', 'description' => '查看主题状态和常用入口'],
        'basic' => ['label' => '基础设置', 'icon' => 'dashicons-admin-generic', 'description' => '站点标识、Logo 和页脚信息'],
        'home' => ['label' => '首页布局', 'icon' => 'dashicons-format-image', 'description' => 'Hero、栏目和首页广告模块'],
        'content' => ['label' => '文章与资源', 'icon' => 'dashicons-media-document', 'description' => '文章页、网盘下载区和元信息'],
        'appearance' => ['label' => '界面美化', 'icon' => 'dashicons-art', 'description' => '颜色、卡片、移动端和交互效果'],
        'seo' => ['label' => 'SEO 设置', 'icon' => 'dashicons-search', 'description' => '搜索摘要和社交分享信息'],
        'update' => ['label' => '在线更新', 'icon' => 'dashicons-update', 'description' => 'GitHub Release 更新源'],
    ];
}

add_action('admin_menu', function () {
    add_menu_page('吾爱森鹿语主题设置', '吾爱森鹿语', 'manage_options', 'wuai-sluyu', 'wuai_sluyu_options_page', 'dashicons-art', 58);
    add_submenu_page('wuai-sluyu', '主题设置', '主题设置', 'manage_options', 'wuai-sluyu', 'wuai_sluyu_options_page');
});

add_action('admin_enqueue_scripts', function ($hook) {
    if (!in_array($hook, ['toplevel_page_wuai-sluyu', 'appearance_page_wuai-sluyu'], true)) {
        return;
    }
    wp_enqueue_media();
    wp_enqueue_style('wuai-sluyu-admin', WUAI_SLUYU_URI . '/assets/css/admin.css', [], WUAI_SLUYU_VERSION);
    wp_enqueue_script('wuai-sluyu-admin', WUAI_SLUYU_URI . '/assets/js/admin.js', [], WUAI_SLUYU_VERSION, true);
});

add_filter('admin_body_class', function ($classes) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && in_array($screen->id, ['toplevel_page_wuai-sluyu', 'appearance_page_wuai-sluyu'], true)) {
        $classes .= ' wuai-admin-screen';
    }
    return $classes;
});

add_action('admin_init', function () {
    register_setting('wuai_sluyu_options', 'wuai_sluyu_options', [
        'sanitize_callback' => function ($input) {
            $defaults = wuai_sluyu_defaults();
            $input = is_array($input) ? $input : [];
            $text_keys = [
                'site_title', 'site_description', 'hero_title', 'hero_notice', 'hot_words',
                'sections', 'resource_badge', 'download_title', 'footer_text', 'seo_description',
                'seo_keywords', 'github_owner', 'github_repo', 'github_channel',
            ];
            $url_keys = ['site_logo', 'site_icon', 'hero_video', 'hero_image', 'promo_image', 'ad_image'];
            foreach ($text_keys as $key) {
                $defaults[$key] = isset($input[$key]) ? sanitize_textarea_field($input[$key]) : $defaults[$key];
            }
            foreach ($url_keys as $key) {
                $defaults[$key] = isset($input[$key]) ? esc_url_raw($input[$key]) : $defaults[$key];
            }
            foreach (['primary_color', 'accent_color', 'background_color'] as $key) {
                $defaults[$key] = isset($input[$key]) ? (sanitize_hex_color($input[$key]) ?: $defaults[$key]) : $defaults[$key];
            }
            foreach (['border_radius', 'content_width'] as $key) {
                $value = isset($input[$key]) ? absint($input[$key]) : absint($defaults[$key]);
                $defaults[$key] = (string) max($key === 'content_width' ? 980 : 0, min($key === 'content_width' ? 1600 : 32, $value));
            }
            foreach (['show_post_meta', 'show_resource_box', 'enable_comments', 'show_floating_tools', 'show_mobile_nav', 'enable_lightbox', 'enable_loader', 'enable_particles', 'glass_cards', 'color_scrollbar', 'enable_og', 'legacy_html_links'] as $key) {
                $defaults[$key] = isset($input[$key]) ? '1' : '0';
            }
            return $defaults;
        },
    ]);
});

function wuai_sluyu_options_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $options = wuai_sluyu_get_options();
    $groups = wuai_sluyu_admin_groups();
    $active = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'dashboard';
    if (!isset($groups[$active])) {
        $active = 'dashboard';
    }
    ?>
    <div class="wrap wuai-admin-wrap" data-wuai-admin="1">
        <header class="wuai-admin-header">
            <div class="wuai-admin-brand">
                <div class="wuai-admin-logo"><span class="dashicons dashicons-art"></span></div>
                <div>
                    <h1>吾爱森鹿语主题设置</h1>
                    <p>用一套清晰的设置面板管理首页、文章资源和视觉效果。</p>
                </div>
            </div>
            <div class="wuai-admin-header-actions">
                <span class="wuai-version">版本 <?php echo esc_html(WUAI_SLUYU_VERSION); ?></span>
                <a class="button" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener">查看前台</a>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('update-core.php?force-check=1')); ?>">检查更新</a>
            </div>
        </header>

        <form method="post" action="options.php" class="wuai-admin-form">
            <?php settings_fields('wuai_sluyu_options'); ?>
            <div class="wuai-admin-layout">
                <aside class="wuai-admin-sidebar">
                    <div class="wuai-sidebar-title">主题控制台</div>
                    <nav aria-label="主题设置分组">
                        <?php foreach ($groups as $slug => $group) : ?>
                            <a class="wuai-admin-nav<?php echo $active === $slug ? ' is-active' : ''; ?>" href="#<?php echo esc_attr($slug); ?>" data-wuai-tab="<?php echo esc_attr($slug); ?>">
                                <span class="dashicons <?php echo esc_attr($group['icon']); ?>"></span>
                                <span><strong><?php echo esc_html($group['label']); ?></strong><small><?php echo esc_html($group['description']); ?></small></span>
                                <?php if ($active === $slug) : ?><i class="dashicons dashicons-arrow-right-alt2"></i><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                    <div class="wuai-sidebar-help"><span class="dashicons dashicons-lightbulb"></span><strong>配置提示</strong><p>保存设置后打开前台查看效果。图片和视频字段支持媒体库或外链地址。</p></div>
                </aside>

                <main class="wuai-admin-content">
                    <div class="wuai-admin-savebar">
                        <div><strong><?php echo esc_html($groups[$active]['label']); ?></strong><span><?php echo esc_html($groups[$active]['description']); ?></span></div>
                        <button type="submit" class="button button-primary wuai-save-button"><span class="dashicons dashicons-saved"></span>保存全部设置</button>
                    </div>

                    <section class="wuai-tab-pane<?php echo $active === 'dashboard' ? ' is-active' : ''; ?>" data-wuai-pane="dashboard">
                        <div class="wuai-stat-grid">
                            <div class="wuai-stat-card"><span class="dashicons dashicons-admin-appearance"></span><div><small>当前主题</small><strong>吾爱森鹿语</strong><em>已启用</em></div></div>
                            <div class="wuai-stat-card"><span class="dashicons dashicons-wordpress"></span><div><small>运行环境</small><strong>WordPress</strong><em>PHP <?php echo esc_html(PHP_VERSION); ?></em></div></div>
                            <div class="wuai-stat-card"><span class="dashicons dashicons-cloud"></span><div><small>更新通道</small><strong><?php echo esc_html($options['github_owner'] . '/' . $options['github_repo']); ?></strong><em>GitHub Release</em></div></div>
                        </div>
                        <div class="wuai-card wuai-welcome-card">
                            <div class="wuai-card-icon"><span class="dashicons dashicons-admin-customizer"></span></div>
                            <div><h2>欢迎使用吾爱森鹿语</h2><p>这是主题自己的设置框架，布局参考了子比后台的分组和卡片交互，但代码与命名空间均为本主题独立实现。你可以从左侧依次配置站点、首页、资源文章、视觉效果和在线更新。</p><div class="wuai-quick-links"><a href="#home" data-wuai-tab="home">配置首页</a><a href="#content" data-wuai-tab="content">配置资源文章</a><a href="#appearance" data-wuai-tab="appearance">调整界面美化</a></div></div>
                        </div>
                    </section>

                    <section class="wuai-tab-pane<?php echo $active === 'basic' ? ' is-active' : ''; ?>" data-wuai-pane="basic">
                        <div class="wuai-card-grid">
                            <div class="wuai-card"><div class="wuai-card-heading"><span class="dashicons dashicons-admin-site-alt3"></span><div><h2>站点信息</h2><p>设置网站标题、描述和品牌标识。</p></div></div>
                                <?php wuai_sluyu_field('site_title', '站点显示名称', $options, 'text', '用于加载动画和后台品牌区域'); ?>
                                <?php wuai_sluyu_field('site_description', '站点简介', $options, 'textarea', '用于首页介绍和 SEO 描述的默认内容'); ?>
                                <?php wuai_sluyu_field('site_logo', 'Logo 地址', $options, 'url', '留空时使用自定义 Logo 或主题默认 Logo'); ?>
                                <?php wuai_sluyu_field('site_icon', '站点图标地址', $options, 'url', '可填写 32×32 或 64×64 PNG 地址'); ?>
                            </div>
                            <div class="wuai-card"><div class="wuai-card-heading"><span class="dashicons dashicons-admin-footer"></span><div><h2>页脚与站点链接</h2><p>控制页面底部展示的版权和说明文字。</p></div></div>
                                <?php wuai_sluyu_field('footer_text', '页脚说明', $options, 'text'); ?>
                                <div class="wuai-info-box"><span class="dashicons dashicons-info-outline"></span><p>菜单仍然使用 WordPress 原生“外观 → 菜单”管理，主题会自动读取主导航和页脚导航。</p></div>
                            </div>
                        </div>
                    </section>

                    <section class="wuai-tab-pane<?php echo $active === 'home' ? ' is-active' : ''; ?>" data-wuai-pane="home">
                        <div class="wuai-card-grid wuai-card-grid-wide">
                            <div class="wuai-card"><div class="wuai-card-heading"><span class="dashicons dashicons-format-image"></span><div><h2>首页 Hero 区域</h2><p>配置首页顶部的大图、视频和搜索提示。</p></div></div>
                                <?php wuai_sluyu_field('hero_title', '主标题', $options, 'text'); ?>
                                <?php wuai_sluyu_field('hero_notice', '免责声明', $options, 'textarea'); ?>
                                <?php wuai_sluyu_field('hero_video', '背景视频 URL', $options, 'url', '建议使用 mp4 外链；无法播放时会自动显示背景图片'); ?>
                                <?php wuai_sluyu_field('hero_image', '背景图片 URL', $options, 'url'); ?>
                                <?php wuai_sluyu_field('hot_words', '热搜词', $options, 'text', '使用逗号分隔，例如：黑神话,生化危机,游戏资源'); ?>
                            </div>
                            <div class="wuai-card"><div class="wuai-card-heading"><span class="dashicons dashicons-layout"></span><div><h2>首页模块</h2><p>控制栏目、横幅和侧栏广告资源。</p></div></div>
                                <?php wuai_sluyu_field('sections', '首页栏目', $options, 'textarea', '每行一个已有分类名称，最多显示四个栏目；栏目只展示已发布文章，空栏目显示暂无内容，不生成演示文章'); ?>
                                <?php wuai_sluyu_field('promo_image', '横幅图片 URL', $options, 'url'); ?>
                                <?php wuai_sluyu_field('ad_image', '侧栏广告 URL', $options, 'url'); ?>
                            </div>
                        </div>
                    </section>

                    <section class="wuai-tab-pane<?php echo $active === 'content' ? ' is-active' : ''; ?>" data-wuai-pane="content">
                        <div class="wuai-card-grid">
                            <div class="wuai-card"><div class="wuai-card-heading"><span class="dashicons dashicons-media-document"></span><div><h2>文章页显示</h2><p>适用于游戏资源文章和普通资讯文章。</p></div></div>
                                <?php wuai_sluyu_field('resource_badge', '默认标题徽章', $options, 'text', '文章没有单独设置徽章时使用'); ?>
                                <?php wuai_sluyu_field('download_title', '网盘下载区标题', $options, 'text'); ?>
                                <?php wuai_sluyu_field('show_post_meta', '显示文章作者和时间', $options, 'checkbox'); ?>
                                <?php wuai_sluyu_field('show_resource_box', '显示网盘资源卡片', $options, 'checkbox'); ?>
                                <?php wuai_sluyu_field('enable_comments', '开启文章评论区', $options, 'checkbox'); ?>
                            </div>
                            <div class="wuai-card"><div class="wuai-card-heading"><span class="dashicons dashicons-editor-help"></span><div><h2>发布提示</h2><p>资源文章建议使用文章编辑页的“游戏资源信息”面板。</p></div></div>
                                <div class="wuai-guide-list"><div><b>01</b><span>设置特色图片，首页卡片会自动使用。</span></div><div><b>02</b><span>在资源信息中填写网盘地址和提取码。</span></div><div><b>03</b><span>给文章分配“游戏资源”或“资讯”分类。</span></div></div>
                            </div>
                        </div>
                    </section>

                    <section class="wuai-tab-pane<?php echo $active === 'appearance' ? ' is-active' : ''; ?>" data-wuai-pane="appearance">
                        <div class="wuai-card-grid">
                            <div class="wuai-card"><div class="wuai-card-heading"><span class="dashicons dashicons-art"></span><div><h2>颜色与布局</h2><p>调整主题的粉色强调和卡片尺寸。</p></div></div>
                                <?php wuai_sluyu_field('primary_color', '主题色', $options, 'color'); ?>
                                <?php wuai_sluyu_field('accent_color', '辅助色', $options, 'color'); ?>
                                <?php wuai_sluyu_field('background_color', '页面背景色', $options, 'color'); ?>
                                <?php wuai_sluyu_field('border_radius', '卡片圆角', $options, 'number', '范围 0–32 像素'); ?>
                                <?php wuai_sluyu_field('content_width', '内容最大宽度', $options, 'number', '范围 980–1600 像素'); ?>
                            </div>
                            <div class="wuai-card"><div class="wuai-card-heading"><span class="dashicons dashicons-admin-customizer"></span><div><h2>交互与美化</h2><p>按服务器性能选择动画和移动端组件。</p></div></div>
                                <?php wuai_sluyu_field('show_floating_tools', '显示右侧悬浮工具栏', $options, 'checkbox'); ?>
                                <?php wuai_sluyu_field('show_mobile_nav', '显示移动端底部导航', $options, 'checkbox'); ?>
                                <?php wuai_sluyu_field('enable_lightbox', '文章图片灯箱预览', $options, 'checkbox'); ?>
                                <?php wuai_sluyu_field('enable_loader', '页面加载动画', $options, 'checkbox'); ?>
                                <?php wuai_sluyu_field('enable_particles', '背景粒子效果', $options, 'checkbox', '移动端会自动降低强度'); ?>
                                <?php wuai_sluyu_field('glass_cards', '卡片玻璃拟态效果', $options, 'checkbox'); ?>
                                <?php wuai_sluyu_field('color_scrollbar', '彩色滚动条与选中文字', $options, 'checkbox'); ?>
                                <?php wuai_sluyu_field('legacy_html_links', '兼容旧版 .html 链接', $options, 'checkbox'); ?>
                            </div>
                        </div>
                    </section>

                    <section class="wuai-tab-pane<?php echo $active === 'seo' ? ' is-active' : ''; ?>" data-wuai-pane="seo">
                        <div class="wuai-card-grid">
                            <div class="wuai-card"><div class="wuai-card-heading"><span class="dashicons dashicons-search"></span><div><h2>搜索引擎摘要</h2><p>为首页和社交分享补充统一描述。</p></div></div>
                                <?php wuai_sluyu_field('seo_description', 'SEO 描述', $options, 'textarea', '建议 80–160 个中文字符'); ?>
                                <?php wuai_sluyu_field('seo_keywords', 'SEO 关键词', $options, 'text', '使用逗号分隔'); ?>
                                <?php wuai_sluyu_field('enable_og', '输出 Open Graph 分享信息', $options, 'checkbox'); ?>
                            </div>
                            <div class="wuai-card"><div class="wuai-card-heading"><span class="dashicons dashicons-yes-alt"></span><div><h2>主题状态</h2><p>当前设置使用 WordPress 原生保存机制。</p></div></div><div class="wuai-info-box success"><span class="dashicons dashicons-yes"></span><p>保存后会立即写入主题设置，不需要重新安装或修改数据库表。</p></div></div>
                        </div>
                    </section>

                    <section class="wuai-tab-pane<?php echo $active === 'update' ? ' is-active' : ''; ?>" data-wuai-pane="update">
                        <div class="wuai-card-grid">
                            <div class="wuai-card"><div class="wuai-card-heading"><span class="dashicons dashicons-update"></span><div><h2>GitHub 在线更新</h2><p>后台会通过公开 Release 检查新版本，不保存 PAT。</p></div></div>
                                <?php wuai_sluyu_field('github_owner', '仓库账号', $options, 'text', '例如：xiaoeyl'); ?>
                                <?php wuai_sluyu_field('github_repo', '仓库名称', $options, 'text', '例如：wuai-game-library'); ?>
                                <?php wuai_sluyu_field('github_channel', '更新通道', $options, 'select', '', ['stable' => '稳定版 Release', 'beta' => '测试版 Release']); ?>
                                <div class="wuai-update-actions"><a class="button button-primary" href="<?php echo esc_url(admin_url('update-core.php?force-check=1')); ?>">立即检查主题更新</a><a class="button" href="<?php echo esc_url('https://github.com/' . rawurlencode($options['github_owner']) . '/' . rawurlencode($options['github_repo']) . '/releases'); ?>" target="_blank" rel="noopener">打开 GitHub Releases</a></div>
                            </div>
                            <div class="wuai-card"><div class="wuai-card-heading"><span class="dashicons dashicons-shield"></span><div><h2>更新说明</h2><p>发布新版本时，请使用主题 ZIP 作为 Release 附件。</p></div></div><div class="wuai-guide-list"><div><b>01</b><span>上传命名为 <code>wuai-sluyu-v<?php echo esc_html(WUAI_SLUYU_VERSION); ?>.zip</code> 的压缩包。</span></div><div><b>02</b><span>创建 GitHub Release，并填写版本标签。</span></div><div><b>03</b><span>回到后台“仪表盘 → 更新”执行检查。</span></div></div></div>
                        </div>
                    </section>
                </main>
            </div>
        </form>
    </div>
    <?php
}

function wuai_sluyu_field($key, $label, $options, $type = 'text', $description = '', $choices = []) {
    $value = isset($options[$key]) ? $options[$key] : '';
    $name = 'wuai_sluyu_options[' . $key . ']';
    $id = 'wuai-sluyu-' . sanitize_html_class($key);
    echo '<div class="wuai-field wuai-field-' . esc_attr($type) . '">';
    echo '<label for="' . esc_attr($id) . '"><span class="wuai-field-label">' . esc_html($label) . '</span>';
    if ($type === 'textarea') {
        printf('<textarea id="%1$s" name="%2$s" rows="4">%3$s</textarea>', esc_attr($id), esc_attr($name), esc_textarea($value));
    } elseif ($type === 'color') {
        printf('<span class="wuai-color-input"><input id="%1$s" type="color" name="%2$s" value="%3$s"><code>%3$s</code></span>', esc_attr($id), esc_attr($name), esc_attr($value));
    } elseif ($type === 'checkbox') {
        printf('<span class="wuai-switch"><input id="%1$s" type="checkbox" name="%2$s" value="1" %3$s><i aria-hidden="true"></i><b>开启</b></span>', esc_attr($id), esc_attr($name), checked($value, '1', false));
    } elseif ($type === 'select') {
        echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
        foreach ((array) $choices as $choice_value => $choice_label) {
            printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($choice_value), selected($value, $choice_value, false), esc_html($choice_label));
        }
        echo '</select>';
    } elseif ($type === 'url') {
        printf('<span class="wuai-url-input"><input id="%1$s" type="url" name="%2$s" value="%3$s"><button type="button" class="button wuai-media-button" data-wuai-target="%1$s">选择媒体</button></span>', esc_attr($id), esc_attr($name), esc_attr($value));
    } else {
        printf('<input id="%1$s" type="%2$s" name="%3$s" value="%4$s">', esc_attr($id), $type === 'number' ? 'number' : 'text', esc_attr($name), esc_attr($value));
    }
    echo '</label>';
    if ($description !== '') {
        echo '<p class="wuai-field-description">' . esc_html($description) . '</p>';
    }
    echo '</div>';
}
