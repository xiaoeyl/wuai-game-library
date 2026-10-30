<?php
$locations = get_nav_menu_locations();
$theme_options = wuai_sluyu_get_options();
$post_archive = get_permalink(get_option('page_for_posts')) ?: home_url('/');
?>
<footer class="sluyu-footer">
    <div class="sluyu-container sluyu-footer-inner">
        <div class="sluyu-footer-brand">
            <a class="sluyu-footer-logo" href="<?php echo esc_url(home_url('/')); ?>"><img src="<?php echo esc_url(!empty($theme_options['site_logo']) ? $theme_options['site_logo'] : wuai_sluyu_asset('images/logo.png')); ?>" alt="<?php echo esc_attr($theme_options['site_title']); ?>"></a>
            <p><?php echo esc_html($theme_options['footer_text']); ?></p>
            <nav><?php if (!empty($locations['footer'])) : wp_nav_menu(['theme_location' => 'footer', 'container' => false, 'fallback_cb' => false]); ?><?php else : ?><a href="<?php echo esc_url(home_url('/')); ?>">首页</a><a href="<?php echo esc_url(get_privacy_policy_url()); ?>">隐私政策</a><?php endif; ?></nav>
        </div>
        <div class="sluyu-footer-column"><h3>支持服务</h3><a href="<?php echo esc_url(wp_login_url()); ?>"><b>◉</b><span>用户注册<small>加入森鹿语社区</small></span></a><a href="<?php echo esc_url(home_url('/about/')); ?>"><b>▤</b><span>免责声明<small>本站内容使用说明</small></span></a></div>
        <div class="sluyu-footer-column"><h3>热门频道</h3><a href="<?php echo esc_url(home_url('/')); ?>"><b>✦</b><span>次元美图<small>精选图包资源</small></span></a><a href="<?php echo esc_url(home_url('/')); ?>"><b>✿</b><span>认证资源<small>优质内容推荐</small></span></a></div>
        <div class="sluyu-footer-column"><h3>帮助中心</h3><a href="<?php echo esc_url(home_url('/about/')); ?>"><b>♛</b><span>解忧答疑<small>常见问题解答</small></span></a><a href="<?php echo esc_url(home_url('/')); ?>"><b>▣</b><span>关于本站<small>森鹿语资源分享</small></span></a></div>
    </div>
    <div class="sluyu-footer-bottom"><div class="sluyu-container"><span>Copyright © <?php echo esc_html(wp_date('Y')); ?> <?php echo esc_html($theme_options['site_title']); ?> 版权所有</span><span>资源分享 · 游戏交流</span></div></div>
</footer>
<?php if (wuai_sluyu_option('show_floating_tools', '1')) : ?>
    <aside class="sluyu-floating-tools wuai-sluyu-float-tools" aria-label="快捷工具">
        <button type="button" class="sluyu-tool-button sluyu-tool-top" aria-label="返回顶部" title="返回顶部">↑</button>
        <a class="sluyu-tool-button" href="<?php echo esc_url(home_url('/')); ?>" aria-label="返回首页" title="返回首页">⌂</a>
        <a class="sluyu-tool-button" href="<?php echo esc_url(wp_login_url()); ?>" aria-label="用户中心" title="用户中心">♙</a>
        <a class="sluyu-tool-button" href="<?php echo esc_url($post_archive); ?>" aria-label="文章列表" title="文章列表">▤</a>
        <a class="sluyu-tool-button" href="<?php echo esc_url(home_url('/about/')); ?>" aria-label="关于本站" title="关于本站">ⓘ</a>
        <a class="sluyu-tool-button" href="<?php echo esc_url(home_url('/today/')); ?>" aria-label="每日签到" title="每日签到">✿</a>
        <a class="sluyu-tool-button" href="<?php echo esc_url(home_url('/task/')); ?>" aria-label="任务中心" title="任务中心">♛</a>
        <a class="sluyu-tool-button" href="<?php echo esc_url(home_url('/')); ?>" aria-label="搜索" title="搜索">⌕</a>
        <button type="button" class="sluyu-tool-button sluyu-tool-theme" aria-label="切换显示模式" title="切换显示模式">◐</button>
    </aside>
<?php endif; ?>
<?php if (wuai_sluyu_option('show_mobile_nav', '1')) : ?>
    <nav class="sluyu-mobile-nav wuai-sluyu-mobile-nav" aria-label="移动端导航"><a href="<?php echo esc_url(home_url('/')); ?>"><span>⌂</span><small>首页</small></a><a href="<?php echo esc_url(get_permalink(get_option('page_for_posts')) ?: home_url('/')); ?>"><span>▦</span><small>资源</small></a><a href="<?php echo esc_url(wp_login_url()); ?>"><span>◉</span><small><?php echo is_user_logged_in() ? '我的' : '登录'; ?></small></a></nav>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
