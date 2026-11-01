<?php
if (!defined('ABSPATH')) {
    exit;
}
require_once __DIR__ . '/admin-navigation.php';

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
        'footer_description' => '提供网络资源分享，图集画册，写真美图，动漫周边，动漫美图，动漫资讯，p站插画，cosplay，游戏资源等精品资源。找美图，找游戏，发现更多热爱~',
        'footer_banner' => wuai_sluyu_asset('images/sluyu/2026032501495748.jpg'),
        'footer_banner_url' => '',
        'footer_copyright' => '',
        'footer_show_stats' => '1',
        'footer_terms_url' => '',
        'footer_disclaimer_url' => '',
        'footer_circle_url' => '',
        'footer_verify_url' => '',
        'footer_help_url' => '',
        'footer_about_url' => '',
        'rail_vip_url' => '',
        'rail_account_url' => '',
        'rail_cart_url' => '',
        'rail_coupon_url' => '',
        'rail_message_url' => '',
        'rail_support_url' => '',
        'rail_support_text' => '如有资源问题，请在对应文章下留言，我们会尽快处理。',
        'rail_group_qr' => '',
        'article_banner' => wuai_sluyu_asset('images/sluyu/2026032501495748.jpg'),
        'article_show_cover' => '0',
        'article_show_toc' => '1',
        'article_show_related' => '1',
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
        'checkin_points' => '10',
        'makeup_points' => '30',
        'membership_products' => '',
        'public_coupon_codes' => '',
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
    $switches = ['show_post_meta', 'show_resource_box', 'enable_comments', 'show_floating_tools', 'show_mobile_nav', 'enable_lightbox', 'enable_loader', 'enable_particles', 'glass_cards', 'color_scrollbar', 'enable_og', 'legacy_html_links', 'footer_show_stats', 'article_show_cover', 'article_show_toc', 'article_show_related'];
    $urls = ['site_logo', 'site_icon', 'hero_video', 'hero_image', 'promo_image', 'ad_image', 'footer_banner', 'footer_banner_url', 'footer_terms_url', 'footer_disclaimer_url', 'footer_circle_url', 'footer_verify_url', 'footer_help_url', 'footer_about_url', 'rail_vip_url', 'rail_account_url', 'rail_cart_url', 'rail_coupon_url', 'rail_message_url', 'rail_support_url', 'rail_group_qr', 'article_banner'];
    $multiline = ['site_description', 'hero_notice', 'sections', 'seo_description', 'footer_text', 'footer_description', 'rail_support_text', 'public_coupon_codes'];
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
        } elseif (in_array($key, ['checkin_points','makeup_points'], true)) {
            $output[$key] = (string) max($key === 'makeup_points' ? 1 : 0, min(10000, absint($value)));
        } elseif ($key === 'membership_products') {
            $output[$key] = implode(',', array_filter(array_map('absint', preg_split('/[,\s]+/', $value))));
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
    wp_safe_redirect(add_query_arg('settings-history', $operation, admin_url('admin.php?page=wuai-sluyu')) . '#tab=' . wuai_sluyu_settings_tab('backup'));
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
        // Keep the WordPress menu compact; the CSF panel owns the complete settings tree.
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

    // The top-level groups intentionally follow Zibll's information architecture. Each
    // setting lives in one child section so the sidebar remains scannable as the theme grows.
    CSF::createSection($prefix, ['id' => 'global', 'title' => '全局&功能', 'icon' => 'fa fa-fw fa-bullseye']);
    wuai_sluyu_csf_section('overview', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_overview']]);
    wuai_sluyu_csf_section('site', [
            wuai_sluyu_csf_field('site_title', 'text', '站点显示名称'),
            wuai_sluyu_csf_field('site_description', 'textarea', '站点简介'),
            wuai_sluyu_csf_field('site_logo', 'upload', '网站 Logo', ['desc' => '支持媒体库或图片外链；留空使用 WordPress 自定义 Logo 或默认 Logo。']),
            wuai_sluyu_csf_field('site_icon', 'upload', '站点图标', ['desc' => '建议使用正方形 PNG 图片。']),
    ]);
    wuai_sluyu_csf_section('footer', [
            wuai_sluyu_csf_field('footer_description', 'textarea', '页脚网站介绍'),
            wuai_sluyu_csf_field('footer_text', 'textarea', '页脚说明'),
            wuai_sluyu_csf_field('footer_banner', 'upload', '页脚上方横幅', ['desc' => '留空隐藏；默认使用参考站的装饰横幅。']),
            wuai_sluyu_csf_field('footer_banner_url', 'text', '横幅链接', ['desc' => '填写你自己的社群或活动地址；留空时展示图片。']),
            wuai_sluyu_csf_field('footer_copyright', 'text', '版权名称', ['desc' => '留空时使用站点显示名称。']),
            wuai_sluyu_csf_field('footer_show_stats', 'switcher', '显示本次页面查询耗时'),
            wuai_sluyu_csf_field('footer_terms_url', 'text', '用户说明链接'),
            wuai_sluyu_csf_field('footer_disclaimer_url', 'text', '免责声明链接'),
            wuai_sluyu_csf_field('footer_circle_url', 'text', '次元圈子链接'),
            wuai_sluyu_csf_field('footer_verify_url', 'text', '认证服务链接'),
            wuai_sluyu_csf_field('footer_help_url', 'text', '帮助文档链接'),
            wuai_sluyu_csf_field('footer_about_url', 'text', '关于本站链接'),
            ['type' => 'content', 'content' => '<p>主导航和页脚导航使用 WordPress 菜单管理。</p><a class="button" href="' . esc_url(admin_url('nav-menus.php')) . '">管理导航菜单</a>'],
    ]);
    wuai_sluyu_csf_section('rail', [
            wuai_sluyu_csf_field('rail_vip_url', 'text', '会员中心链接'),
            wuai_sluyu_csf_field('rail_account_url', 'text', '个人中心链接'),
            wuai_sluyu_csf_field('rail_cart_url', 'text', '购物车链接'),
            wuai_sluyu_csf_field('rail_coupon_url', 'text', '优惠券链接'),
            wuai_sluyu_csf_field('rail_message_url', 'text', '私信列表链接'),
            wuai_sluyu_csf_field('rail_support_url', 'text', '客服链接'),
            wuai_sluyu_csf_field('rail_support_text', 'textarea', '客服说明'),
            wuai_sluyu_csf_field('rail_group_qr', 'upload', '社群二维码', ['desc' => '留空时只显示当前页面二维码。']),
    ], '填写你自己的页面地址。未设置时优先识别已有页面；未开放的服务显示说明，不跳到不存在的页面。');
    wuai_sluyu_csf_section('announcements', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_announcements']]);

    CSF::createSection($prefix, ['id' => 'page', 'title' => '页面&显示', 'icon' => 'fa fa-fw fa-calendar-check-o']);
    wuai_sluyu_csf_section('home', [
            wuai_sluyu_csf_field('hero_title', 'text', '首屏主标题'),
            wuai_sluyu_csf_field('hero_notice', 'textarea', '首页公告'),
            wuai_sluyu_csf_field('hero_video', 'upload', '背景视频', ['library' => 'video', 'preview' => false, 'button_title' => '选择视频', 'desc' => '支持 MP4 外链或媒体库视频；视频不能播放时使用背景图片。']),
            wuai_sluyu_csf_field('hero_image', 'upload', '背景图片'),
            wuai_sluyu_csf_field('hot_words', 'text', '热搜关键词', ['desc' => '使用英文或中文逗号分隔。']),
            wuai_sluyu_csf_field('sections', 'textarea', '首页栏目', ['desc' => '每行一个已有分类名称，最多显示四个栏目。只显示已发布文章，空栏目不生成演示文章。']),
            wuai_sluyu_csf_field('promo_image', 'upload', '首页横幅'),
            wuai_sluyu_csf_field('ad_image', 'upload', '侧栏广告图片'),
    ]);
    wuai_sluyu_csf_section('layout', [
            wuai_sluyu_csf_field('primary_color', 'color', '主色调'),
            wuai_sluyu_csf_field('accent_color', 'color', '辅助色'),
            wuai_sluyu_csf_field('background_color', 'color', '页面背景色'),
            wuai_sluyu_csf_field('border_radius', 'number', '卡片圆角', ['unit' => 'px', 'attributes' => ['min' => 0, 'max' => 32]]),
            wuai_sluyu_csf_field('content_width', 'number', '内容区域宽度', ['unit' => 'px', 'attributes' => ['min' => 980, 'max' => 1600]]),
            wuai_sluyu_csf_field('show_floating_tools', 'switcher', '显示悬浮工具'),
            wuai_sluyu_csf_field('show_mobile_nav', 'switcher', '显示移动端底部导航'),
            wuai_sluyu_csf_field('enable_lightbox', 'switcher', '文章图片灯箱'),
            wuai_sluyu_csf_field('enable_loader', 'switcher', '页面加载动画'),
            wuai_sluyu_csf_field('enable_particles', 'switcher', '粒子背景'),
            wuai_sluyu_csf_field('glass_cards', 'switcher', '玻璃卡片效果'),
            wuai_sluyu_csf_field('color_scrollbar', 'switcher', '彩色滚动条'),
    ]);
    wuai_sluyu_csf_section('widgets', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_widgets']]);
    wuai_sluyu_csf_section('pages', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_pages']]);

    CSF::createSection($prefix, ['id' => 'post', 'title' => '文章&列表', 'icon' => 'fa fa-fw fa-map-o']);
    wuai_sluyu_csf_section('article', [
            wuai_sluyu_csf_field('resource_badge', 'text', '默认标题徽章'),
            wuai_sluyu_csf_field('download_title', 'text', '网盘下载区标题'),
            wuai_sluyu_csf_field('show_post_meta', 'switcher', '显示文章信息'),
            wuai_sluyu_csf_field('show_resource_box', 'switcher', '显示资源下载区'),
            wuai_sluyu_csf_field('article_banner', 'upload', '文章顶部横幅'),
            wuai_sluyu_csf_field('article_show_cover', 'switcher', '正文前显示特色图片', ['desc' => '参考站正文直接从文章内容开始，默认关闭独立大封面。']),
            wuai_sluyu_csf_field('article_show_toc', 'switcher', '显示文章目录'),
            wuai_sluyu_csf_field('article_show_related', 'switcher', '显示相关推荐'),
    ]);
    wuai_sluyu_csf_section('content', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_content']]);
    wuai_sluyu_csf_section('downloads', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_downloads']]);

    CSF::createSection($prefix, ['id' => 'cap', 'title' => '功能&权限', 'icon' => 'fa fa-fw fa-shield']);
    wuai_sluyu_csf_section('access', [
            wuai_sluyu_csf_field('legacy_html_links', 'switcher', '使用 .html 文章地址', ['desc' => '更改后请在“设置 → 固定链接”保存一次，刷新重写规则。']),
            ['type' => 'content', 'content' => '<p>文章、下载、社区和管理入口会按当前账号能力显示。主题不会绕过 WordPress 权限，也不会为未安装的商城插件伪造支付状态。</p>'],
    ]);
    wuai_sluyu_csf_section('registration', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_registration']]);
    wuai_sluyu_csf_section('moderation', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_moderation']]);

    CSF::createSection($prefix, ['id' => 'user', 'title' => '用户&互动', 'icon' => 'fa fa-fw fa-user-o']);
    wuai_sluyu_csf_section('comments', [
            wuai_sluyu_csf_field('enable_comments', 'switcher', '显示文章评论'),
            ['type' => 'callback', 'function' => 'wuai_sluyu_csf_comments'],
    ]);
    wuai_sluyu_csf_section('users', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_users']]);
    wuai_sluyu_csf_section('messages', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_messages']]);
    wuai_sluyu_csf_section('points', [
            wuai_sluyu_csf_field('checkin_points', 'number', '每日签到积分', ['min' => 0, 'max' => 10000]),
            wuai_sluyu_csf_field('makeup_points', 'number', '每个缺签日补签费用', ['min' => 1, 'max' => 10000]),
            ['type' => 'content', 'content' => '<p>本站积分是站内非现金记账单位，不提供充值、提现或现金兑换。WooCommerce 付款必须由已配置的支付网关完成确认。</p>'],
    ]);
    CSF::createSection($prefix, ['id' => 'pay', 'title' => '支付&付费', 'icon' => 'fa fa-fw fa-jpy']);
    wuai_sluyu_csf_section('payment', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_payment']]);
    wuai_sluyu_csf_section('bounties', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_bounties']]);

    CSF::createSection($prefix, ['id' => 'shop', 'title' => '商城&商品', 'icon' => 'fa fa-fw fa-cart-plus']);
    wuai_sluyu_csf_section('membership', [
            wuai_sluyu_csf_field('membership_products', 'text', '会员商品 ID', ['desc' => '使用英文逗号分隔 WooCommerce 简单商品 ID，并在商品编辑页设置会员有效天数。真实付款确认后开通会员；退款撤回对应授权。']),
            wuai_sluyu_csf_field('public_coupon_codes', 'textarea', '公开发放的优惠码', ['desc' => '每行一个已在 WooCommerce 创建的优惠码。只展示这里明确配置的券，结算时验证适用性。']),
            ['type' => 'callback', 'function' => 'wuai_sluyu_csf_orders'],
    ]);
    wuai_sluyu_csf_section('orders', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_orders']]);

    CSF::createSection($prefix, ['id' => 'forum', 'title' => '社区&论坛', 'icon' => 'fa fa-fw fa-grav']);
    wuai_sluyu_csf_section('community', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_community']]);
    wuai_sluyu_csf_section('circles', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_circles']]);
    wuai_sluyu_csf_section('listings', [['type' => 'callback', 'function' => 'wuai_sluyu_csf_listings']]);

    CSF::createSection($prefix, ['id' => 'over', 'title' => '扩展&增强', 'icon' => 'fa fa-fw fa-puzzle-piece']);
    wuai_sluyu_csf_section('seo', [
            wuai_sluyu_csf_field('seo_description', 'textarea', '首页 SEO 描述'),
            wuai_sluyu_csf_field('seo_keywords', 'text', '首页关键词'),
            wuai_sluyu_csf_field('enable_og', 'switcher', '社交分享信息'),
    ]);
    wuai_sluyu_csf_section('update', [
            ['type' => 'callback', 'function' => 'wuai_sluyu_csf_update_info'],
            wuai_sluyu_csf_field('github_owner', 'text', 'GitHub 用户名'),
            wuai_sluyu_csf_field('github_repo', 'text', 'GitHub 仓库名'),
            wuai_sluyu_csf_field('github_channel', 'select', '更新通道', ['options' => ['stable' => '正式版', 'beta' => '预发布版'], 'desc' => '正式版读取最新 Release，预发布版同时检查标记为预发布的版本。']),
    ]);
    wuai_sluyu_csf_section('backup', [
            ['type' => 'notice', 'style' => 'info', 'content' => '修改、导入或重置前自动保留原配置，最多保存 20 份。备份仅包含主题设置，不包含文章和媒体文件。'],
            ['type' => 'backup', 'title' => '导入 / 导出设置'],
            ['type' => 'callback', 'function' => 'wuai_sluyu_csf_history'],
    ]);
}

function wuai_sluyu_csf_overview() {
    echo '<div class="wuai-csf-overview"><h2>吾爱森鹿语 V' . esc_html(WUAI_SLUYU_VERSION) . '</h2>';
    echo '<p>设置面板使用用户提供的子比源码中的 Codestar Framework 2.2.0 核心与子比设置层。左侧选择分组，右上角可搜索、保存或重置设置。</p>';
    echo '<p>站点信息、首页配置、文章资源和显示布局沿用原配置，前台内容读取 WordPress 已发布文章。</p>';
    echo '<p><a class="button button-primary" href="' . esc_url(home_url('/')) . '" target="_blank" rel="noopener">查看网站</a> ';
    echo '<a class="button" href="' . esc_url(admin_url('post-new.php')) . '">发布文章</a> ';
    echo '<a class="button" href="' . esc_url(admin_url('edit-tags.php?taxonomy=category')) . '">管理分类</a></p>';
    echo '<p class="description">WordPress ' . esc_html(get_bloginfo('version')) . ' · PHP ' . esc_html(PHP_VERSION) . ' · CSF ' . esc_html(CSF::$version) . '</p></div>';
    echo '<div class="wuai-admin-quicklinks">';
    $sections = wuai_sluyu_settings_sections();
    foreach (['site', 'home', 'widgets', 'registration', 'users', 'points', 'community', 'circles', 'payment', 'orders', 'update', 'backup'] as $key) {
        echo '<a href="' . esc_url(wuai_sluyu_settings_url($key)) . '"><i class="fa fa-fw fa-' . esc_attr($sections[$key][3]) . '" aria-hidden="true"></i><span>' . esc_html($sections[$key][2]) . '</span></a>';
    }
    echo '</div>';
}

function wuai_sluyu_csf_update_info() {
    list($owner, $repo) = wuai_sluyu_update_source();
    ?>
    <div class="wuai-update" data-state="idle" aria-busy="false">
        <div class="wuai-update-card">
            <div class="wuai-update-heading"><span class="wuai-update-icon fa fa-cloud-download" aria-hidden="true"></span><h3 class="wuai-update-title">主题在线更新</h3></div>
            <p class="wuai-update-versions">当前主题版本：<strong class="wuai-update-current">V<?php echo esc_html(WUAI_SLUYU_VERSION); ?></strong><span class="wuai-update-latest-wrap" hidden>可更新到：<code class="wuai-update-latest"></code></span></p>
            <p class="wuai-update-message" role="status" aria-live="polite">正在获取版本信息…</p>
            <div class="wuai-update-progress-wrap" hidden>
                <div class="wuai-update-progress" role="progressbar" aria-label="主题更新进度" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="wuai-update-bar"></div></div>
                <div class="wuai-update-progress-caption"><span>版本核对 → 下载 → 校验 → 安装 → 完成</span><strong class="wuai-update-percent">0%</strong></div>
            </div>
            <div class="wuai-update-buttons">
                <button type="button" class="but jb-blue wuai-update-install" hidden><i class="fa fa-cloud-download" aria-hidden="true"></i> 在线更新</button>
                <button type="button" class="but jb-blue wuai-update-check"><i class="fa fa-refresh" aria-hidden="true"></i> 检查更新</button>
                <button type="button" class="but c-blue wuai-update-reload" hidden>刷新页面</button>
                <a class="but c-blue wuai-update-download" href="<?php echo esc_url('https://github.com/' . $owner . '/' . $repo . '/releases'); ?>" target="_blank" rel="noopener noreferrer" hidden>下载主题安装包</a>
                <a class="but c-blue wuai-update-manual" href="<?php echo esc_url(admin_url('theme-install.php?tab=upload')); ?>" hidden>手动上传安装</a>
            </div>
            <p class="description wuai-update-help" hidden>在线更新由网站服务器下载，与本机能否访问 GitHub 不同。若服务器持续超时，请先备份数据库和主题，再在本机下载 ZIP 并手动上传替换；不要解压或上传 GitHub 的整个仓库源码包。</p>
            <p class="wuai-update-meta"></p>
        </div>
        <div class="wuai-update-log" hidden><h4>更新日志 <a class="wuai-update-release" target="_blank" rel="noopener noreferrer">在 GitHub 查看</a></h4><div class="wuai-update-notes"></div></div>
        <p class="description">更新前自动备份主题设置。修改下方更新源或通道后，请先保存设置，再检查更新。</p>
        <noscript><p>请启用 JavaScript 使用页内更新，也可在 WordPress“仪表盘 → 更新”中检查版本。</p></noscript>
    </div>
    <?php
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
    wp_localize_script('wuai-sluyu-admin', 'wuaiCsf', ['postUrl' => admin_url('admin-post.php'), 'legacyTabs' => wuai_sluyu_settings_legacy_tabs(), 'defaultTab' => wuai_sluyu_settings_tab('overview')]);
    wp_enqueue_script('wuai-sluyu-updater', WUAI_SLUYU_URI . '/assets/js/admin-updater.js', ['jquery', 'wuai-sluyu-admin'], WUAI_SLUYU_VERSION, true);
    wp_localize_script('wuai-sluyu-updater', 'wuaiUpdater', ['ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wuai_sluyu_update')]);
});
