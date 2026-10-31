<?php
$theme_options = wuai_sluyu_get_options();
$locations = get_nav_menu_locations();
$footer_links = [
    ['支持服务', [
        ['用户说明', '用户服务协议', '2026022415154781.png', 'terms', wuai_sluyu_page_link('footer_terms_url', ['yhusm', 'terms'])],
        ['免责声明', '仅供学习与交流', '2026020403375274.png', 'disclaimer', wuai_sluyu_page_link('footer_disclaimer_url', ['mianzesm', 'disclaimer'])],
    ]],
    ['热门频道', [
        ['次元圈子', '爱能穿越次元', '2026022415154734.png', 'circle', wuai_sluyu_page_link('footer_circle_url', ['circle', 'forums'])],
        ['认证服务', '专属达人称号', '2026020403375350.png', 'verify', wuai_sluyu_page_link('footer_verify_url', ['verify'])],
    ]],
    ['帮助中心', [
        ['帮助文档', '解决疑难烦恼', '2026020403380474.svg', 'help', wuai_sluyu_page_link('footer_help_url', ['document', 'help'])],
        ['关于本站', '本站介绍使用指南', '2026022415154722.png', 'about', wuai_sluyu_page_link('footer_about_url', ['gyuwo', 'about'])],
    ]],
];
$logo = $theme_options['site_logo'] ?: wp_get_attachment_image_url(get_theme_mod('custom_logo'), 'full');
$logo = $logo ?: wuai_sluyu_asset('images/logo.png');
$account_url = wuai_sluyu_page_link('rail_account_url', ['account', 'user']);
if (!$account_url && function_exists('wc_get_page_permalink')) {
    $account_url = wc_get_page_permalink('myaccount');
}
$cart_url = wuai_sluyu_page_link('rail_cart_url', ['carts', 'cart']);
if (!$cart_url && function_exists('wc_get_cart_url')) {
    $cart_url = wc_get_cart_url();
}
$vip_url = wuai_sluyu_page_link('rail_vip_url', ['vips', 'vip']);
?>
<?php if ($theme_options['footer_banner']) : ?><div class="sluyu-container sluyu-footer-banner-wrap"><?php wuai_sluyu_banner($theme_options['footer_banner'], $theme_options['footer_banner_url']); ?></div><?php endif; ?>
<footer class="sluyu-footer sluyu-footer-reference">
    <div class="sluyu-container sluyu-footer-inner">
        <div class="sluyu-footer-brand">
            <a class="sluyu-footer-logo" href="<?php echo esc_url(home_url('/')); ?>"><img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($theme_options['site_title']); ?>"></a>
            <p><?php echo esc_html($theme_options['footer_description']); ?></p>
            <?php if (!empty($locations['footer'])) : ?><nav aria-label="页脚菜单"><?php wp_nav_menu(['theme_location' => 'footer', 'container' => false, 'fallback_cb' => false]); ?></nav><?php endif; ?>
        </div>
        <?php foreach ($footer_links as $column) : ?>
            <section class="sluyu-footer-column"><h3><?php echo esc_html($column[0]); ?></h3>
                <?php foreach ($column[1] as $item) : ?><a <?php echo wuai_sluyu_service_attributes($item[4], $item[3]); ?>><img src="<?php echo esc_url(wuai_sluyu_asset('images/sluyu/' . $item[2])); ?>" alt="" width="40" height="40"><span><strong><?php echo esc_html($item[0]); ?></strong><small><?php echo esc_html($item[1]); ?></small></span></a><?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </div>
    <div class="sluyu-footer-bottom"><div class="sluyu-container"><span>Copyright © <?php echo esc_html(wp_date('Y')); ?> <a href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html($theme_options['footer_copyright'] ?: $theme_options['site_title']); ?></a></span>
        <?php if ($theme_options['footer_show_stats'] === '1') : ?><span>查询 <?php echo esc_html(get_num_queries()); ?> 次，耗时 <?php echo esc_html(timer_stop(0, 4)); ?> 秒</span><?php endif; ?>
    </div></div>
</footer>
<?php if ($theme_options['show_floating_tools'] === '1') : ?>
<aside class="sluyu-reference-rail" aria-label="快捷导航">
    <a class="sluyu-rail-vip" <?php echo wuai_sluyu_service_attributes($vip_url, 'vip'); ?>><img src="<?php echo esc_url(wuai_sluyu_asset('images/sluyu/vip-youce.svg')); ?>" alt=""><span>解锁<br>会员<br>权限</span><span class="sluyu-rail-label">会员中心</span></a>
    <div class="sluyu-rail-group">
        <?php $rail = [
            ['个人中心', 'user-heart-line', $account_url, 'account'],
            ['购物车', 'shopping-cart-2-line', $cart_url, 'cart'],
            ['优惠券', 'coupon-2-line', wuai_sluyu_page_link('rail_coupon_url', ['coupons', 'coupon']), 'coupon'],
            ['今日签到', 'gift-2-line', '', 'checkin'],
            ['私信列表', 'mail-send-line', wuai_sluyu_page_link('rail_message_url', ['directmessage', 'messages']), 'message'],
            ['搜索', 'search-line', '', 'search'],
        ]; foreach ($rail as $item) : ?><a class="sluyu-rail-item" aria-label="<?php echo esc_attr($item[0]); ?>" <?php echo wuai_sluyu_service_attributes($item[2], $item[3]); ?>><?php echo wuai_sluyu_icon($item[1]); ?><span class="sluyu-rail-label"><?php echo esc_html($item[0]); ?></span></a><?php endforeach; ?>
    </div>
    <div class="sluyu-rail-group sluyu-rail-secondary">
        <a class="sluyu-rail-item" aria-label="客服" <?php echo wuai_sluyu_service_attributes($theme_options['rail_support_url'], 'support'); ?>><?php echo wuai_sluyu_icon('customer-service-2-line1'); ?><span class="sluyu-rail-label">客服</span></a>
        <button type="button" class="sluyu-rail-item" data-sluyu-panel="qrcode" aria-label="二维码"><?php echo wuai_sluyu_icon('qr-code-fill'); ?><span class="sluyu-rail-label">扫码打开当前页</span></button>
        <button type="button" class="sluyu-rail-item" data-sluyu-top aria-label="返回顶部"><?php echo wuai_sluyu_icon('rocket-2-line'); ?><span class="sluyu-rail-label">返回顶部</span></button>
    </div>
</aside>
<?php endif; ?>
<div class="sluyu-dialog" id="sluyu-dialog" role="dialog" aria-modal="true" aria-labelledby="sluyu-dialog-title" hidden>
    <div class="sluyu-dialog-window"><header><h2 id="sluyu-dialog-title"></h2><button type="button" data-sluyu-close aria-label="关闭">×</button></header><div class="sluyu-dialog-body"></div></div>
</div>
<div class="sluyu-toast" role="status" aria-live="polite" hidden></div>
<template id="sluyu-panel-search"><form class="sluyu-modal-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>"><input type="search" name="s" placeholder="搜索你感兴趣的内容" aria-label="搜索内容" required><button class="sluyu-action-primary" type="submit">搜索</button></form><p>搜索站内文章、游戏与资源。</p></template>
<template id="sluyu-panel-account"><?php if (is_user_logged_in()) : $current_user = wp_get_current_user(); ?><div class="sluyu-account-summary"><?php echo get_avatar($current_user->ID, 56); ?><div><strong><?php echo esc_html($current_user->display_name); ?></strong><p>欢迎回来</p></div></div><a class="sluyu-action-primary" href="<?php echo esc_url(get_author_posts_url($current_user->ID)); ?>">我的文章</a> <a class="sluyu-action-plain" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">退出登录</a><?php else : ?><p>登录后可以签到、评论和收藏文章。</p><a class="sluyu-action-primary" href="<?php echo esc_url(wp_login_url(home_url('/'))); ?>">登录</a><?php if (get_option('users_can_register')) : ?> <a class="sluyu-action-plain" href="<?php echo esc_url(wp_registration_url()); ?>">注册账号</a><?php endif; ?><?php endif; ?></template>
<template id="sluyu-panel-vip"><p>本站暂未开放会员服务，公开资源可在文章详情中下载。</p><a class="sluyu-action-primary" href="<?php echo esc_url(home_url('/')); ?>">浏览资源</a></template>
<template id="sluyu-panel-cart"><div class="sluyu-service-empty"><?php echo wuai_sluyu_icon('shopping-cart-2-line'); ?><p>购物车空空如也</p><small>本站当前资源通过文章中的网盘链接下载。</small></div></template>
<template id="sluyu-panel-coupon"><div class="sluyu-service-empty"><?php echo wuai_sluyu_icon('coupon-2-line'); ?><p>暂无可用优惠券</p></div></template>
<template id="sluyu-panel-message"><div class="sluyu-service-empty"><?php echo wuai_sluyu_icon('mail-send-line'); ?><p>暂无私信服务</p><small>需要联系作者时，可在文章下方留言。</small></div></template>
<template id="sluyu-panel-checkin"><div class="sluyu-checkin-panel"><?php echo wuai_sluyu_icon('gift-2-line'); ?><h3>每日签到</h3><?php if (is_user_logged_in()) : ?><p>累计签到 <strong data-checkin-days><?php echo esc_html((int) get_user_meta(get_current_user_id(), '_wuai_sluyu_checkin_days', true)); ?></strong> 天</p><button class="sluyu-action-primary" type="button" data-sluyu-checkin><?php echo get_user_meta(get_current_user_id(), '_wuai_sluyu_checkin_date', true) === wp_date('Y-m-d') ? '今日已签到' : '立即签到'; ?></button><?php else : ?><p>登录后记录每日签到。</p><a class="sluyu-action-primary" href="<?php echo esc_url(wp_login_url(home_url('/'))); ?>">登录后签到</a><?php endif; ?></div></template>
<template id="sluyu-panel-qrcode"><div class="sluyu-qr-list"><div><canvas data-sluyu-qr></canvas><p>扫码打开当前页</p></div><?php if ($theme_options['rail_group_qr']) : ?><div><img src="<?php echo esc_url($theme_options['rail_group_qr']); ?>" alt="社群二维码"><p>加入社群</p></div><?php endif; ?></div></template>
<template id="sluyu-panel-support"><p><?php echo nl2br(esc_html($theme_options['rail_support_text'])); ?></p></template>
<template id="sluyu-panel-about"><p><?php echo esc_html($theme_options['footer_description']); ?></p><p><?php echo esc_html($theme_options['footer_text']); ?></p></template>
<template id="sluyu-panel-terms"><p>用户说明尚未发布。浏览和使用资源前，请查看文章中的使用说明及原作者的授权要求。</p></template>
<template id="sluyu-panel-disclaimer"><p>请遵守资源原作者的授权范围。链接失效或内容存在问题时，请在文章下留言反馈。</p></template>
<template id="sluyu-panel-circle"><p>圈子暂未开放，欢迎通过文章评论交流。</p></template>
<template id="sluyu-panel-verify"><p>本站暂未开放用户认证服务。</p></template>
<template id="sluyu-panel-help"><p>打开资源文章，阅读说明后点击“立即下载”。如有提取码，可点击复制；遇到问题请在文章下留言。</p></template>
<template id="sluyu-panel-share"><div class="sluyu-qr-list"><div><canvas data-sluyu-qr></canvas><p>扫码分享这篇文章</p></div></div><button class="sluyu-action-primary" type="button" data-sluyu-copy-page>复制文章链接</button></template>
<?php if ($theme_options['show_mobile_nav'] === '1') : ?>
<nav class="sluyu-mobile-nav" aria-label="移动端导航"><a href="<?php echo esc_url(home_url('/')); ?>"><?php echo wuai_sluyu_icon('home-heart-line'); ?><small>首页</small></a><a href="#sluyu-dialog" data-sluyu-panel="search"><?php echo wuai_sluyu_icon('search-line'); ?><small>搜索</small></a><a href="#sluyu-dialog" data-sluyu-panel="checkin"><?php echo wuai_sluyu_icon('gift-2-line'); ?><small>签到</small></a><a <?php echo wuai_sluyu_service_attributes($account_url, 'account'); ?>><?php echo wuai_sluyu_icon('user-heart-line'); ?><small>我的</small></a><button type="button" data-sluyu-top><?php echo wuai_sluyu_icon('rocket-2-line'); ?><small>顶部</small></button></nav>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
