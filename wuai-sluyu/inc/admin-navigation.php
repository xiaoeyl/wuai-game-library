<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Stable keys keep links independent of the translated CSF sidebar titles. */
function wuai_sluyu_settings_sections() {
    return [
        'overview' => ['global', '全局&功能', '主题概览', 'dashboard'],
        'site' => ['global', '全局&功能', '站点信息', 'globe'],
        'footer' => ['global', '全局&功能', '页脚与导航', 'navicon'],
        'rail' => ['global', '全局&功能', '右侧悬浮按钮', 'thumb-tack'],
        'announcements' => ['global', '全局&功能', '站点公告', 'bullhorn'],
        'home' => ['page', '页面&显示', '首页配置', 'home'],
        'layout' => ['page', '页面&显示', '显示&布局', 'delicious'],
        'widgets' => ['page', '页面&显示', '小工具&菜单', 'th-large'],
        'pages' => ['page', '页面&显示', '服务页面', 'clone'],
        'article' => ['post', '文章&列表', '文章与资源', 'file-text-o'],
        'content' => ['post', '文章&列表', '分类&内容管理', 'folder-open-o'],
        'downloads' => ['post', '文章&列表', '下载&隐藏内容', 'download'],
        'access' => ['cap', '功能&权限', '访问与兼容', 'unlock-alt'],
        'registration' => ['cap', '功能&权限', '注册&邀请码', 'key'],
        'moderation' => ['cap', '功能&权限', '投稿&审核', 'check-square-o'],
        'comments' => ['user', '用户&互动', '评论与互动', 'comments'],
        'users' => ['user', '用户&互动', '用户&安全', 'user-circle-o'],
        'messages' => ['user', '用户&互动', '消息&通知', 'envelope-o'],
        'points' => ['user', '用户&互动', '积分与签到', 'calendar-check-o'],
        'payment' => ['pay', '支付&付费', '支付设置', 'credit-card'],
        'bounties' => ['pay', '支付&付费', '积分悬赏', 'gift'],
        'membership' => ['shop', '商城&商品', '会员与优惠', 'shopping-bag'],
        'orders' => ['shop', '商城&商品', '商品&订单', 'shopping-cart'],
        'community' => ['forum', '社区&论坛', '社区服务', 'users'],
        'circles' => ['forum', '社区&论坛', '圈子&审核', 'shield'],
        'listings' => ['forum', '社区&论坛', '供求信息', 'exchange'],
        'seo' => ['over', '扩展&增强', 'SEO优化', 'superpowers'],
        'update' => ['over', '扩展&增强', '文档&更新', 'cloud-upload'],
        'backup' => ['over', '扩展&增强', '备份&导入', 'copy'],
    ];
}

function wuai_sluyu_settings_tab($key) {
    $sections = wuai_sluyu_settings_sections();
    $section = $sections[$key] ?? $sections['overview'];
    return sanitize_title($section[1]) . '/' . sanitize_title($section[2]);
}

function wuai_sluyu_settings_url($key = 'overview') {
    return admin_url('admin.php?page=wuai-sluyu') . '#tab=' . wuai_sluyu_settings_tab($key);
}

function wuai_sluyu_csf_section($key, $fields, $description = '') {
    $section = wuai_sluyu_settings_sections()[$key];
    CSF::createSection('wuai_sluyu_options', [
        'parent' => $section[0], 'title' => $section[2], 'icon' => 'fa fa-fw fa-' . $section[3],
        'description' => $description, 'fields' => $fields,
    ]);
}

function wuai_sluyu_settings_legacy_tabs() {
    $old = [
        '主题概览' => 'overview', '基础设置' => 'site', '基础设置/站点信息' => 'site',
        '基础设置/页脚与导航' => 'footer', '基础设置/右侧快捷导航' => 'rail',
        '社区与商城' => 'community', '首页布局' => 'home', '首页布局/首屏与搜索' => 'home',
        '首页布局/栏目与广告' => 'home', '文章与资源' => 'article', '界面美化' => 'layout',
        '界面美化/颜色与布局' => 'layout', '界面美化/交互与特效' => 'layout',
        'SEO 设置' => 'seo', '在线更新' => 'update', '备份与恢复' => 'backup',
        '支付&付费/积分与签到' => 'points',
    ];
    $tabs = [];
    foreach ($old as $title => $key) {
        $tabs[implode('/', array_map('sanitize_title', explode('/', $title)))] = wuai_sluyu_settings_tab($key);
    }
    return $tabs;
}

function wuai_sluyu_admin_link($title, $path, $capability = 'manage_options') {
    return ['title' => $title, 'url' => admin_url($path), 'capability' => $capability];
}

function wuai_sluyu_portal_link($key, $title = '') {
    $pages = wuai_sluyu_portal_pages();
    return ['title' => $title ?: $pages[$key][0], 'url' => wuai_sluyu_portal_url($key), 'external' => true];
}

function wuai_sluyu_admin_module($key, $title, $description, $links = [], $status = '') {
    echo '<section class="wuai-admin-module" data-module="' . esc_attr($key) . '"><h3 class="csf-search-tags">' . esc_html($title) . '</h3>';
    if ($status !== '') {
        echo '<p class="wuai-admin-module-status">' . esc_html($status) . '</p>';
    }
    echo '<p>' . esc_html($description) . '</p><div class="wuai-admin-module-actions">';
    foreach ($links as $link) {
        if (!empty($link['capability']) && !current_user_can($link['capability'])) {
            continue;
        }
        echo '<a class="button" href="' . esc_url($link['url']) . '"' . (!empty($link['external']) ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . esc_html($link['title']) . '</a>';
    }
    echo '</div></section>';
}

function wuai_sluyu_admin_content_module($type, $title, $description, $portal = '', $taxonomy = '') {
    if (!post_type_exists($type)) {
        wuai_sluyu_admin_module($type, $title, '模块尚未注册，请检查主题文件是否完整。');
        return;
    }
    $counts = wp_count_posts($type, 'readable');
    $links = [wuai_sluyu_admin_link('管理' . $title, 'edit.php?post_type=' . $type)];
    if ($taxonomy && taxonomy_exists($taxonomy)) {
        $links[] = wuai_sluyu_admin_link('管理分类', 'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=' . $type, 'manage_categories');
    }
    if ($portal) {
        $links[] = wuai_sluyu_portal_link($portal, '查看前台');
    }
    wuai_sluyu_admin_module($type, $title, $description, $links, '已发布 ' . absint($counts->publish ?? 0) . ' · 待审核 ' . absint($counts->pending ?? 0) . ' · 草稿 ' . absint($counts->draft ?? 0));
}

function wuai_sluyu_csf_announcements() {
    wuai_sluyu_admin_module('announcements', '站点公告', '在独立公告管理页选择公开文章、页面或快讯，按 ID 清单顺序展示。只有已发布、无密码的公开内容才会展示；公告小工具可单独添加到侧栏。', [
        wuai_sluyu_admin_link('管理站点公告', 'admin.php?page=wuai-sluyu-announcements'),
        wuai_sluyu_portal_link('announcement', '查看公告列表'),
    ]);
}

function wuai_sluyu_csf_widgets() {
    $modes = class_exists('Wuai_Sluyu_Widget') ? Wuai_Sluyu_Widget::modes() : [];
    wuai_sluyu_admin_module('widgets', '拖放小工具', '首页侧栏、文章与页面侧栏、页脚小工具三个区域独立配置；添加侧栏小工具后替换该区域的默认内容。', [wuai_sluyu_admin_link('配置小工具', 'widgets.php', 'edit_theme_options')], count($modes) . ' 种功能小工具 + 站点公告小工具');
    echo '<p class="description csf-search-tags">' . esc_html(implode(' / ', $modes)) . '</p>';
    wuai_sluyu_admin_module('menus', '导航菜单', '主导航和页脚导航使用 WordPress 原生菜单；移动端底部导航和右侧悬浮按钮在显示布局与快捷按钮栏目配置。', [
        wuai_sluyu_admin_link('管理导航菜单', 'nav-menus.php', 'edit_theme_options'),
        ['title' => '配置悬浮按钮', 'url' => wuai_sluyu_settings_url('rail')],
    ]);
}

function wuai_sluyu_csf_pages() {
    wuai_sluyu_admin_module('pages', '站内服务页面', '服务路由无需预建页面即可访问。需要编辑说明或加入菜单时，在站内服务管理中创建缺失页面；不会覆盖已有内容。', [
        wuai_sluyu_admin_link('站内服务管理', 'admin.php?page=wuai-sluyu-services'),
        wuai_sluyu_admin_link('管理独立页面', 'edit.php?post_type=page', 'edit_pages'),
    ]);
    echo '<div class="wuai-admin-page-directory">';
    foreach (wuai_sluyu_portal_pages() as $key => $page) {
        echo '<a href="' . esc_url(wuai_sluyu_portal_url($key)) . '" target="_blank" rel="noopener noreferrer">' . esc_html($page[0]) . '</a>';
    }
    echo '</div>';
}

function wuai_sluyu_csf_content() {
    wuai_sluyu_admin_module('posts', '文章、分类与标签', '文章列表、草稿和分类标签沿用 WordPress 原生管理。首页栏目填写已有分类名称，不会自动生成演示文章。', [
        wuai_sluyu_admin_link('管理文章', 'edit.php', 'edit_posts'),
        wuai_sluyu_admin_link('发布文章', 'post-new.php', 'edit_posts'),
        wuai_sluyu_admin_link('文章分类', 'edit-tags.php?taxonomy=category', 'manage_categories'),
        wuai_sluyu_admin_link('文章标签', 'edit-tags.php?taxonomy=post_tag', 'manage_categories'),
    ]);
    wuai_sluyu_admin_content_module('sluyu_news', '解忧资讯', '资讯支持前台投稿与后台审核。', 'newsflashes', 'sluyu_news_cat');
    wuai_sluyu_admin_content_module('sluyu_doc', '帮助文档', '文档支持独立分类与前台搜索。', 'document', 'sluyu_doc_cat');
    wuai_sluyu_admin_content_module('sluyu_link', '网址导航', '推荐网站先审核，再进入前台导航目录。', 'links', 'sluyu_link_cat');
}

function wuai_sluyu_csf_downloads() {
    wuai_sluyu_admin_module('downloads', '资源下载权限', '在文章编辑页的资源下载信息框中设置下载地址、提取码和访问条件。下载入口与正文隐藏内容独立授权；登录、评论、会员、积分和圈子条件由服务器校验。', [wuai_sluyu_admin_link('选择文章配置下载', 'edit.php', 'edit_posts')]);
    wuai_sluyu_admin_module('protected-content', '隐藏正文', '在文章或普通话题编辑页的“独立受保护正文”信息框配置。独立页面、投票、猜答案和图片话题不支持此信息框。公开摘要不得包含隐藏正文；请明确说明授权用途与积分消耗。', [
        wuai_sluyu_admin_link('管理文章', 'edit.php', 'edit_posts'),
        wuai_sluyu_admin_link('管理话题', 'edit.php?post_type=sluyu_topic', 'edit_posts'),
    ]);
}

function wuai_sluyu_csf_registration() {
    $settings = wuai_sluyu_invitation_settings();
    wuai_sluyu_admin_module('registration', '注册与一次性邀请码', '注册总开关由 WordPress 常规设置控制。邀请码独立管理，默认关闭，仅作用于原生 WordPress 注册；生成邀请码不会自动开放注册。', [
        wuai_sluyu_admin_link('WordPress 注册设置', 'options-general.php'),
        wuai_sluyu_admin_link('邀请码设置与记录', 'admin.php?page=wuai-sluyu-invitations'),
    ], (get_option('users_can_register') ? '原生注册已开放' : '原生注册已关闭') . ' · 邀请码' . ($settings['enabled'] ? ($settings['required'] ? '已启用，注册必填' : '已启用，注册选填') : '未启用'));
}

function wuai_sluyu_csf_moderation() {
    wuai_sluyu_admin_module('moderation', '投稿与管理权限', '前台投稿进入待审核，认证与工单仅管理员可管理。用户禁言在编辑用户页设置；圈子授权和积分悬赏保留独立审核流程，不通过主题配置表单修改权限。', [
        wuai_sluyu_admin_link('待审话题', 'edit.php?post_type=sluyu_topic&post_status=pending'),
        wuai_sluyu_admin_link('用户与禁言管理', 'users.php', 'list_users'),
        wuai_sluyu_admin_link('圈子审核授权', 'admin.php?page=wuai-circle-review-settings'),
    ]);
    wuai_sluyu_admin_content_module('sluyu_verify', '认证申请', '发布认证申请后授予称号，撤回或删除申请时撤销认证。', 'verify');
    wuai_sluyu_admin_content_module('sluyu_ticket', '工单与举报', '工单处理结果由原提交者查看，不公开工单内容。');
}

function wuai_sluyu_csf_comments() {
    wuai_sluyu_admin_module('comments', '评论与互动管理', '评论审核、匿名评论和讨论规则使用 WordPress 原生设置。主题评论显示开关不会替代单篇文章的评论开关，也不会删除既有评论。', [
        wuai_sluyu_admin_link('管理评论', 'edit-comments.php', 'moderate_comments'),
        wuai_sluyu_admin_link('讨论设置', 'options-discussion.php'),
    ]);
}

function wuai_sluyu_csf_users() {
    wuai_sluyu_admin_module('users', '用户资料与社区禁言', '管理员可在编辑用户页设置禁言期限和公开原因；不能禁言管理员。个人资料、头像、封面与账号安全在个人中心维护。', [wuai_sluyu_admin_link('管理用户', 'users.php', 'list_users'), wuai_sluyu_admin_link('编辑我的资料', 'profile.php', 'read'), wuai_sluyu_portal_link('dark-room', '查看小黑屋')]);
    wuai_sluyu_admin_module('account-security', '账号与登录安全', '仅本人可以查看或撤销自己的登录会话、修改密码并使用原生邮箱验证。管理员不会在此面板获得用户密码或他人的会话令牌。正式环境请使用 HTTPS。', [wuai_sluyu_portal_link('account', '打开我的个人中心')]);
}

function wuai_sluyu_csf_messages() {
    wuai_sluyu_admin_module('messages', '私信与站内通知', '私信仅对参与者可见；通知展示审核、申请和互动结果。收件偏好、会话与未读状态由用户本人管理，不提供管理员浏览所有私信的入口。', [wuai_sluyu_portal_link('directmessage', '我的私信'), wuai_sluyu_portal_link('message', '我的通知'), wuai_sluyu_admin_link('管理公开公告', 'admin.php?page=wuai-sluyu-announcements')]);
}

function wuai_sluyu_csf_payment() {
    if (!class_exists('WooCommerce')) {
        wuai_sluyu_admin_module('payment-unavailable', '尚未启用 WooCommerce', '主题不内置支付网关。启用 WooCommerce 并配置网关后，才可使用商品、订单和会员购买；未启用时不生成虚假订单或付款状态。', [wuai_sluyu_admin_link('管理已安装插件', 'plugins.php', 'activate_plugins')], '商城与在线付款不可用');
        return;
    }
    wuai_sluyu_admin_module('payment', 'WooCommerce 支付与结算', '支付方式、币种、税费与结算规则由 WooCommerce 管理。只有支付网关确认付款后才会授予对应权益；上线前请完成沙盒支付与退款测试。', [
        wuai_sluyu_admin_link('支付网关设置', 'admin.php?page=wc-settings&tab=checkout', 'manage_woocommerce'),
        wuai_sluyu_admin_link('WooCommerce 常规设置', 'admin.php?page=wc-settings', 'manage_woocommerce'),
    ], 'WooCommerce 已启用；网关是否可用请在支付设置中核验');
}

function wuai_sluyu_csf_bounties() {
    $ready = wuai_sluyu_economy_contract_ready();
    wuai_sluyu_admin_module('bounties', '非现金积分悬赏', '积分仅用于站内记账，不支持充值、提现或现金兑换。悬赏创建默认关闭；通过独立审核页开放、审核并核验预留和结算记录。普通发布操作不会自动激活悬赏。', [wuai_sluyu_admin_link('积分悬赏设置与审核', 'admin.php?page=wuai-sluyu-bounty-review')], '积分记账' . ($ready ? '已就绪' : '未就绪') . ' · 悬赏创建' . (wuai_sluyu_bounty_enabled() ? '已开放' : '未开放'));
}

function wuai_sluyu_csf_orders() {
    if (!class_exists('WooCommerce')) {
        wuai_sluyu_csf_payment();
        return;
    }
    $hpos = class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    $links = [
        wuai_sluyu_admin_link('管理商品', 'edit.php?post_type=product', 'edit_products'),
        wuai_sluyu_admin_link('管理订单', $hpos ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order', 'edit_shop_orders'),
    ];
    if (function_exists('wc_coupons_enabled') && wc_coupons_enabled()) {
        $links[] = wuai_sluyu_admin_link('管理优惠券', 'edit.php?post_type=shop_coupon', 'edit_shop_coupons');
    }
    wuai_sluyu_admin_module('orders', '商品、订单与优惠券', '商品价格、库存、订单、退款与优惠券在 WooCommerce 原生页面管理。会员商品须配置有效天数；公开优惠码只展示主题中明确配置的优惠券。', $links, $hpos ? '订单存储：HPOS 高性能订单表' : '订单存储：WordPress 订单文章');
}

function wuai_sluyu_csf_community() {
    wuai_sluyu_admin_content_module('sluyu_topic', '茶话会话题', '支持日常、问答、投票、猜答案和公开图片话题，专用题型保留各自的审核和类型锁定规则。', 'circle', 'sluyu_circle');
    wuai_sluyu_admin_module('community-services', '社区服务目录', '认证、举报、供求、资讯、网址导航与帮助文档均接入真实内容与审核入口，不依赖演示数据。', [wuai_sluyu_admin_link('站内服务管理', 'admin.php?page=wuai-sluyu-services'), wuai_sluyu_portal_link('submit', '查看发布中心')]);
}

function wuai_sluyu_csf_circles() {
    $settings = wuai_sluyu_circle_review_settings();
    wuai_sluyu_admin_module('circles', '圈子与圈主审核', '圈子分组、圈主和创建申请在分类管理中维护。圈主直接发布权限默认关闭，必须通过独立授权页明确开启；受保护内容仍由管理员审核。', [
        wuai_sluyu_admin_link('管理圈子与申请', 'edit-tags.php?taxonomy=sluyu_circle&post_type=sluyu_topic', 'manage_categories'),
        wuai_sluyu_admin_link('配置圈子审核授权', 'admin.php?page=wuai-circle-review-settings'),
        wuai_sluyu_portal_link('circles', '查看圈子广场'),
    ], '圈主直接发布：' . ($settings['owner_publish'] === '1' ? '已授权（仍受内容限制）' : '未授权'));
}

function wuai_sluyu_csf_listings() {
    wuai_sluyu_admin_content_module('sluyu_listing', '供求信息', '供求信息先审核后展示，支持有效期与已解决状态。站内联系使用私信；主题不托管资金，不提供交易担保。', 'listings');
}
