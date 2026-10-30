<?php
get_header();
$options = wuai_sluyu_get_options();
$hot_words = array_values(array_filter(array_map('trim', preg_split('/,|，/', (string) $options['hot_words']))));
$post_archive = get_permalink(get_option('page_for_posts')) ?: home_url('/');

$demo_sections = [
    [
        'title' => '二次元美图',
        'category' => '图包',
        'banner' => '2024050713122681.webp',
        'style' => 'overlay',
        'images' => [
            'fill_w475_h948_g0_mark_2026041706063860-1.jpg',
            'fill_w475_h948_g0_mark_2026041409122785.jpg',
            'fill_w475_h948_g0_mark_2026041408571195.jpg',
            'fill_w475_h948_g0_mark_2026041408544548.jpg',
            'fill_w475_h948_g0_mark_2026041301540522.jpg',
            'fill_w475_h948_g0_mark_2026041301413612.jpg',
            'fill_w475_h948_g0_mark_2026040708534268-1.jpg',
            'fill_w475_h948_g0_mark_2026040206112776.jpg',
            'fill_w475_h948_g0_mark_2026040206093812.jpg',
            'fill_w475_h948_g0_mark_2026040206073443.jpg',
        ],
        'titles' => ['画师 DearArisu 的 Pixiv 图片包', '画师 nekoboshi_yuki 的图片包', 'Ruzi 画师精选图集', 'futabakurumi 的 pixiv 图片', 'miko 酢精选资源', 'PiOKO 插画图片包', '猫耳少女 CG 图包', 'orenziiro1 画师作品', '精选二次元插画合集', '夏稀とむ图片资源'],
    ],
    [
        'title' => '写真美图',
        'category' => '美图',
        'banner' => '2024050713181919.webp',
        'style' => 'overlay',
        'images' => [
            'fill_w475_h1068_g0_mark_Bu5KfwrKMj8kx_Ev.webp',
            'fill_w475_h1068_g0_mark_2025052003250649.jpg',
            'fill_w475_h1068_g0_mark_68cf9982c5157e1a882138b3.jpg',
            'fill_w475_h1068_g0_mark_2026031706312391.jpg',
            'fill_w475_h1068_g0_mark_2026031706085550-1.jpg',
            'fill_w475_h1068_g0_mark_2026031607112397.jpg',
            'fill_w475_h1068_g0_mark_2026031309094948.jpg',
            'fill_w475_h1068_g0_mark_2026031309022525.jpg',
            'fill_w475_h1068_g0_mark_7f8bb0fe8b33780.webp',
            'fill_w475_h1068_g0_mark_253a8513d159183-10.webp',
        ],
        'titles' => ['落落 raku 从小就是二次元脑袋', '邂逅樱花雨的写真作品', 'Cosplay 她的名字是美好本身', '摄影图册 我们就这样纠缠一辈子', '摄影图册 拍了好久的化蝶模特图', '暖暖的春风遇见了甜甜的暖暖', '一眼回眸温柔了整个冬天', '风一吹樱花和我都心动了', '屿鱼的黎明派对写真', '九柒喵 碧蓝航线写真'],
    ],
    [
        'title' => 'Cosplay 合集',
        'category' => '合集',
        'banner' => '2025082808220873.jpg',
        'style' => 'standard',
        'images' => [
            'fill_w475_h296_g0_mark_2026102816263182.jpg',
            'fill_w475_h296_g0_mark_20220529044704-6292fac89ebed.jpg',
            'fill_w475_h296_g0_mark_20220529044641-6292fab1bbc47.jpg',
            'fill_w475_h296_g0_mark_1-13-2048x1366-1.jpg',
            'fill_w475_h296_g0_mark_2024091911325791.jpg',
            'fill_w475_h296_g0_mark_2025031707253445.jpg',
            'fill_w475_h296_g0_mark_qingshuizhi-4-768x1024-1.webp',
            'fill_w475_h296_g0_mark_2023112015330384.jpg',
            'fill_w475_h296_g0_mark_202301191674103784338863.jpg',
            'fill_w475_h296_g0_mark_0_6-1.jpg-1.webp',
            'fill_w475_h296_g0_mark_20220530042301-629446a57c1a2.jpg',
            'fill_w475_h296_g0_mark_2024011212411168.jpg',
            'fill_w475_h296_g0_mark_2026032007443620.jpg',
            'fill_w475_h296_g0_mark_2023041702553338.png',
            'fill_w475_h296_g0_mark_1.jpg',
        ],
        'titles' => ['渡久山写真 coser 美图作品合集', '清水凪写真作品合集', '神楽坂真冬精选写真', '模特写真与日常记录', 'Azami 精美美图合集', 'Cosplay 作品持续更新', '清水凪写真资源下载', '摄影图册精选内容', '甜美少女写真合集', 'Coser 作品资源分享', '写真作品第 17 期', 'coser 美图全套资源', '最新作品合集下载', '热门 Coser 图集', '精选写真内容'],
    ],
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
            <section class="sluyu-rank-card"><h2>签到排行</h2><?php $rank = new WP_Query(['post_type' => 'post', 'posts_per_page' => 5]); if ($rank->have_posts()) : $i = 1; while ($rank->have_posts()) : $rank->the_post(); ?><a class="sluyu-rank-item" href="<?php the_permalink(); ?>"><b><?php echo esc_html($i++); ?></b><img src="<?php echo esc_url(wuai_sluyu_image(get_the_ID(), 'thumbnail')); ?>" alt=""><span><?php the_title(); ?><small><?php echo esc_html(get_the_date('m月d日')); ?></small></span></a><?php endwhile; wp_reset_postdata(); else : ?><p class="sluyu-empty">暂无签到数据</p><?php endif; ?></section>
        </aside>
    </div>

    <?php foreach ($demo_sections as $section_index => $section) :
        $term = get_term_by('name', $section['category'], 'category');
        $query = $term ? new WP_Query(['post_type' => 'post', 'posts_per_page' => count($section['images']), 'cat' => $term->term_id]) : null;
        $section_url = $term ? get_category_link($term->term_id) : $post_archive;
    ?>
        <section class="sluyu-source-section sluyu-source-section-<?php echo esc_attr($section['style']); ?>">
            <a class="sluyu-source-banner" href="<?php echo esc_url($section_url); ?>"><img src="<?php echo esc_url(wuai_sluyu_asset('images/sluyu/' . $section['banner'])); ?>" alt="<?php echo esc_attr($section['title']); ?>"><span><?php echo esc_html($section['title']); ?> <b>浏览全部 →</b></span></a>
            <div class="sluyu-grid sluyu-source-grid">
                <?php if ($query && $query->have_posts()) : while ($query->have_posts()) : $query->the_post(); wuai_sluyu_render_card(get_the_ID(), $section['style']); endwhile; wp_reset_postdata(); else : foreach ($section['images'] as $i => $image) : wuai_sluyu_render_demo_card($image, $section['titles'][$i] ?? $section['title'], $section['style'], $section['category']); endforeach; endif; ?>
            </div>
        </section>
        <?php if ($section_index === 0) : ?><div class="sluyu-promo sluyu-promo-source"><img src="<?php echo esc_url(wuai_sluyu_asset('images/sluyu/2024050713181919.webp')); ?>" alt="多元魅力"></div><?php endif; ?>
    <?php endforeach; ?>

    <div class="sluyu-promo sluyu-promo-source sluyu-promo-footer"><img src="<?php echo esc_url(wuai_sluyu_asset('images/sluyu/2026032501495748.jpg')); ?>" alt="加入森鹿语"></div>
</main>
<?php get_footer(); ?>
