<?php
$locations = get_nav_menu_locations();
?>
<footer class="sluyu-footer">
    <div class="sluyu-container sluyu-footer-inner">
        <div>
            <a class="sluyu-footer-logo" href="<?php echo esc_url(home_url('/')); ?>"><?php bloginfo('name'); ?></a>
            <p><?php echo esc_html(wuai_sluyu_option('footer_text', '记录每一份热爱，分享每一次发现。')); ?></p>
            <nav>
                <?php if (!empty($locations['footer'])) : wp_nav_menu(['theme_location' => 'footer', 'container' => false, 'fallback_cb' => false]); ?>
                <?php else : ?><a href="<?php echo esc_url(home_url('/')); ?>">首页</a><a href="<?php echo esc_url(get_privacy_policy_url()); ?>">隐私政策</a><?php endif; ?>
            </nav>
        </div>
        <div class="sluyu-footer-contact">资源分享 · 游戏交流<br><strong><?php echo esc_html(get_bloginfo('name')); ?></strong></div>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
