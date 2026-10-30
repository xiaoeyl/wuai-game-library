<?php
get_header();
$options = wuai_sluyu_get_options();
$hot_words = array_values(array_filter(array_map('trim', explode(',', (string) $options['hot_words']))));
$sections = wuai_sluyu_section_categories();
$featured = new WP_Query(['post_type' => 'post', 'posts_per_page' => 5, 'meta_key' => '_wuai_sluyu_featured', 'meta_value' => '1']);
?>
<main class="sluyu-container sluyu-main">
    <div class="sluyu-home-top">
        <section class="sluyu-hero">
            <?php if (!empty($options['hero_video'])) : ?><video class="sluyu-hero-video" autoplay loop muted playsinline poster="<?php echo esc_url($options['hero_image']); ?>"><source src="<?php echo esc_url($options['hero_video']); ?>" type="video/mp4"></video><?php endif; ?>
            <img class="sluyu-hero-image" src="<?php echo esc_url($options['hero_image']); ?>" alt="">
            <div class="sluyu-hero-overlay"></div>
            <div class="sluyu-hero-content">
                <p class="sluyu-hero-kicker">森鹿语 · 二次元资源分享</p>
                <h1><?php echo esc_html($options['hero_title']); ?></h1>
                <p class="sluyu-hero-notice"><?php echo esc_html($options['hero_notice']); ?></p>
                <form class="sluyu-hero-search" method="get" action="<?php echo esc_url(home_url('/')); ?>"><input type="search" name="s" placeholder="搜索你感兴趣的内容"><button type="submit">站内搜索</button></form>
                <p class="sluyu-hot-words"><span>热搜词：</span><?php foreach ($hot_words as $word) : ?><a href="<?php echo esc_url(home_url('/?s=' . rawurlencode($word))); ?>"><?php echo esc_html($word); ?></a><?php endforeach; ?></p>
            </div>
            <div class="sluyu-shortcuts">
                <div class="sluyu-shortcut-categories">
                    <?php $shortcut = [['宅趣资讯', '资讯', 'icon-news.png'], ['游戏资源', '游戏资源', 'icon-game.png'], ['次元赏析', '图包', 'icon-art.png'], ['写真美图', '美图', 'icon-photo.png']]; foreach ($shortcut as $item) : $cat = get_category_by_slug(sanitize_title($item[1])); if (!$cat) { $cat = get_term_by('name', $item[1], 'category'); } $url = $cat ? get_category_link($cat->term_id) : (get_permalink(get_option('page_for_posts')) ?: home_url('/')); ?>
                        <a href="<?php echo esc_url($url); ?>"><img src="<?php echo esc_url(wuai_sluyu_asset('images/' . $item[2])); ?>" alt=""><span><strong><?php echo esc_html($item[0]); ?></strong><small><?php echo esc_html($item[1]); ?> · 精选内容</small></span></a>
                    <?php endforeach; ?>
                </div>
                <div class="sluyu-shortcut-links"><a class="highlight" href="<?php echo esc_url(home_url('/about/')); ?>">关于本站</a><a href="<?php echo esc_url(wp_login_url()); ?>">会员中心</a><a href="<?php echo esc_url(home_url('/task/')); ?>">任务中心</a><a href="<?php echo esc_url(home_url('/today/')); ?>">每日签到</a></div>
            </div>
        </section>
        <aside class="sluyu-home-side">
            <a class="sluyu-side-ad" href="<?php echo esc_url(home_url('/about/')); ?>"><img src="<?php echo esc_url($options['ad_image']); ?>" alt="关于本站"></a>
            <section class="sluyu-sign-card"><div class="sluyu-sign-title"><span>🎁</span><div><strong>每日签到</strong><small>连续签到领取积分奖励</small></div></div><a class="sluyu-sign-button" href="<?php echo esc_url(is_user_logged_in() ? get_edit_profile_url() : wp_login_url()); ?>"><?php echo is_user_logged_in() ? '今日签到' : '登录后签到'; ?></a><div class="sluyu-progress"><i></i></div><p>签到可兑换下载加速和会员权益</p></section>
            <section class="sluyu-rank-card"><h2>签到排行</h2><?php $rank = new WP_Query(['post_type' => 'post', 'posts_per_page' => 5]); if ($rank->have_posts()) : $i = 1; while ($rank->have_posts()) : $rank->the_post(); ?><a class="sluyu-rank-item" href="<?php the_permalink(); ?>"><b><?php echo esc_html($i++); ?></b><img src="<?php echo esc_url(wuai_sluyu_image(get_the_ID(), 'thumbnail')); ?>" alt=""><span><?php the_title(); ?><small><?php echo esc_html(get_the_date('m月d日')); ?></small></span></a><?php endwhile; wp_reset_postdata(); else : ?><p class="sluyu-empty">暂无排行数据</p><?php endif; ?></section>
        </aside>
    </div>
    <a class="sluyu-promo" href="<?php echo esc_url(home_url('/')); ?>"><img src="<?php echo esc_url($options['promo_image']); ?>" alt=""><span><strong>探索更多精彩内容</strong><small>游戏资源、图包与实用教程持续更新</small></span><b>→</b></a>
    <?php $post_archive = get_permalink(get_option('page_for_posts')) ?: home_url('/'); if ($featured->have_posts()) : ?><section class="sluyu-section"><header class="sluyu-section-title"><div><i></i><h2>推荐资源</h2><small>站长精选内容</small></div><a href="<?php echo esc_url($post_archive); ?>">更多 →</a></header><div class="sluyu-grid"><?php while ($featured->have_posts()) : $featured->the_post(); wuai_sluyu_render_card(); endwhile; wp_reset_postdata(); ?></div></section><?php endif; ?>
    <?php foreach ($sections as $index => $section) : $query = new WP_Query(['post_type' => 'post', 'posts_per_page' => 5, 'cat' => $section->term_id]); if (!$query->have_posts()) { continue; } ?>
        <section class="sluyu-section"><div class="sluyu-section-banner sluyu-tone-<?php echo esc_attr($index % 3); ?>"><div><strong><?php echo esc_html($section->name); ?></strong><span>精选资源与实用内容</span></div><a href="<?php echo esc_url(get_category_link($section->term_id)); ?>">浏览全部 →</a></div><div class="sluyu-grid"><?php while ($query->have_posts()) : $query->the_post(); wuai_sluyu_render_card(); endwhile; wp_reset_postdata(); ?></div></section>
    <?php endforeach; ?>
</main>
<?php get_footer(); ?>
