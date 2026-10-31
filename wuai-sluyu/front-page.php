<?php
get_header();
$options = wuai_sluyu_get_options();
$hot_words = array_values(array_filter(array_map('trim', preg_split('/,|，/', (string) $options['hot_words']))));
$post_archive = get_permalink(get_option('page_for_posts')) ?: home_url('/');

$home_sections = wuai_sluyu_section_categories();
$section_banners = [
    '图包' => '2024050713122681.webp',
    '美图' => '2024050713181919.webp',
    '合集' => '2025082808220873.jpg',
];
?>
<main class="sluyu-container sluyu-main sluyu-home-main">
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
                    <?php $shortcut = [['宅趣资讯', '资讯', '2026022415154781.png'], ['游戏资源', '游戏资源', '2026022415154830.png'], ['次元赏析', '图包', '2026022415154734.png'], ['写真美图', '美图', '2026022415154722.png']]; foreach ($shortcut as $item) : $cat = get_term_by('name', $item[1], 'category'); $url = $cat ? get_category_link($cat->term_id) : $post_archive; ?>
                        <a href="<?php echo esc_url($url); ?>"><img src="<?php echo esc_url(wuai_sluyu_asset('images/sluyu/' . $item[2])); ?>" alt=""><span><strong><?php echo esc_html($item[0]); ?></strong><small><?php echo esc_html($item[1]); ?> · 精选内容</small></span></a>
                    <?php endforeach; ?>
                </div>
                <div class="sluyu-shortcut-links"><a class="highlight" href="<?php echo esc_url(home_url('/about/')); ?>">关于本站</a><a href="<?php echo esc_url(wp_login_url()); ?>">会员中心</a><a href="<?php echo esc_url(home_url('/task/')); ?>">任务中心</a><a href="<?php echo esc_url(home_url('/today/')); ?>">每日签到</a></div>
            </div>
        </section>
        <aside class="sluyu-home-side">
            <a class="sluyu-side-ad" href="<?php echo esc_url(home_url('/about/')); ?>"><img src="<?php echo esc_url(wuai_sluyu_asset('images/sluyu/2026022502224161.jpg')); ?>" alt="关于本站"></a>
            <a class="sluyu-side-mini-ad" href="<?php echo esc_url(home_url('/today/')); ?>"><img src="<?php echo esc_url(wuai_sluyu_asset('images/sluyu/2026022803495878.png')); ?>" alt="每日签到"></a>
            <section class="sluyu-sign-card"><div class="sluyu-sign-title"><span>🎁</span><div><strong>每日签到</strong><small>连续签到领取积分奖励</small></div></div><a class="sluyu-sign-button" href="<?php echo esc_url(is_user_logged_in() ? get_edit_profile_url() : wp_login_url()); ?>"><?php echo is_user_logged_in() ? '今日签到' : '登录后签到'; ?></a><div class="sluyu-progress"><i></i></div><p>签到可兑换下载加速和会员权益</p></section>
            <section class="sluyu-rank-card"><h2>最新文章</h2><?php $rank = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 5, 'no_found_rows' => true, 'ignore_sticky_posts' => true]); if ($rank->have_posts()) : $i = 1; while ($rank->have_posts()) : $rank->the_post(); ?><a class="sluyu-rank-item" href="<?php the_permalink(); ?>"><b><?php echo esc_html($i++); ?></b><img src="<?php echo esc_url(wuai_sluyu_image(get_the_ID(), 'thumbnail')); ?>" alt=""><span><?php the_title(); ?><small><?php echo esc_html(get_the_date('m月d日')); ?></small></span></a><?php endwhile; wp_reset_postdata(); else : ?><p class="sluyu-empty">暂无已发布文章</p><?php endif; ?></section>
        </aside>
    </div>

    <?php foreach ($home_sections as $term) :
        $style = in_array($term->name, ['图包', '美图'], true) ? 'overlay' : 'standard';
        $banner = $section_banners[$term->name] ?? '';
        $query = new WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $style === 'overlay' ? 10 : 15,
            'cat' => $term->term_id,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ]);
        $section_url = get_category_link($term->term_id);
    ?>
        <section class="sluyu-source-section sluyu-source-section-<?php echo esc_attr($style); ?>">
            <?php if ($banner) : ?>
                <a class="sluyu-source-banner" href="<?php echo esc_url($section_url); ?>"><img src="<?php echo esc_url(wuai_sluyu_asset('images/sluyu/' . $banner)); ?>" alt="<?php echo esc_attr($term->name); ?>"><span><?php echo esc_html($term->name); ?> <b>浏览全部 →</b></span></a>
            <?php else : ?>
                <header class="sluyu-section-title"><div><i aria-hidden="true"></i><h2><?php echo esc_html($term->name); ?></h2></div><a href="<?php echo esc_url($section_url); ?>">浏览全部 →</a></header>
            <?php endif; ?>
            <?php if ($query->have_posts()) : ?>
                <div class="sluyu-grid sluyu-source-grid">
                    <?php while ($query->have_posts()) : $query->the_post(); wuai_sluyu_render_card(get_the_ID(), $style); endwhile; ?>
                </div>
            <?php else : ?>
                <p class="sluyu-empty">暂无已发布文章</p>
            <?php endif; wp_reset_postdata(); ?>
        </section>
    <?php endforeach; ?>
    <?php if (!$home_sections) : ?><p class="sluyu-empty">暂无已发布文章</p><?php endif; ?>
</main>
<?php get_footer(); ?>
