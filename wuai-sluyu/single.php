<?php get_header(); $theme_options = wuai_sluyu_get_options(); ?>
<main class="sluyu-container sluyu-main">
    <div class="sluyu-content-layout">
        <article class="sluyu-article">
            <?php while (have_posts()) : the_post();
                $post_id = get_the_ID();
                $version = wuai_sluyu_post_meta($post_id, 'version');
                $platform = wuai_sluyu_post_meta($post_id, 'platform');
                $download = wuai_sluyu_post_meta($post_id, 'download_url');
                $password = wuai_sluyu_post_meta($post_id, 'download_password');
                $title_badge = wuai_sluyu_post_meta($post_id, 'title_badge', $theme_options['resource_badge']);
                $title_badge_color = sanitize_hex_color(wuai_sluyu_post_meta($post_id, 'title_badge_color', '#ef0053')) ?: '#ef0053';
            ?>
                <header class="sluyu-article-header">
                    <div class="sluyu-article-cat"><?php the_category(' · '); ?></div>
                    <h1><?php if ($title_badge) : ?><span class="sluyu-title-badge sluyu-title-badge-large" style="--badge-color:<?php echo esc_attr($title_badge_color); ?>"><?php echo esc_html($title_badge); ?></span><?php endif; ?><?php the_title(); ?></h1>
                    <?php if ($theme_options['show_post_meta'] === '1') : ?><div class="sluyu-article-meta">
                        <span><?php echo esc_html(get_the_date('Y年m月d日')); ?></span>
                        <span><?php echo esc_html(get_the_author()); ?></span>
                        <span><?php echo esc_html(number_format_i18n(get_comments_number())); ?> 条评论</span>
                        <button type="button" class="sluyu-copy-button sluyu-copy-article" data-sluyu-copy="<?php echo esc_attr(get_permalink($post_id)); ?>" data-copy-success="文章链接已复制">复制链接</button>
                    </div><?php endif; ?>
                    <?php the_tags('<div class="sluyu-article-tags"><span>标签</span>', '', '</div>'); ?>
                </header>

                <?php if (has_post_thumbnail()) : ?>
                    <figure class="sluyu-featured"><img data-sluyu-lightbox="1" src="<?php echo esc_url(get_the_post_thumbnail_url($post_id, 'large')); ?>" alt="<?php the_title_attribute(); ?>"></figure>
                <?php endif; ?>

                <div class="sluyu-entry"><?php the_content(); ?></div>

                <?php if ($version || $platform) : ?>
                    <div class="sluyu-facts">
                        <div><small>版本</small><strong><?php echo esc_html($version ?: '最新版'); ?></strong></div>
                        <div><small>平台</small><strong><?php echo esc_html($platform ?: 'Windows'); ?></strong></div>
                    </div>
                <?php endif; ?>

                <?php if (function_exists('zibpay_posts_pay_content')) : ?><div class="sluyu-zibpay"><?php zibpay_posts_pay_content(); ?></div><?php endif; ?>

                <?php if ($download && $theme_options['show_resource_box'] === '1') : ?>
                    <section class="sluyu-download">
                        <h2><?php echo esc_html($theme_options['download_title']); ?></h2>
                        <p>资源链接已整理，请按需下载。下载前请先查看文章说明和运行平台。</p>
                        <div>
                            <a href="<?php echo esc_url($download); ?>" target="_blank" rel="nofollow noopener">打开下载链接</a>
                            <button type="button" class="sluyu-copy-button" data-sluyu-copy="<?php echo esc_attr($download); ?>" data-copy-success="下载链接已复制">复制链接</button>
                            <?php if ($password) : ?>
                                <code>提取码：<?php echo esc_html($password); ?></code>
                                <button type="button" class="sluyu-copy-button" data-sluyu-copy="<?php echo esc_attr($password); ?>" data-copy-success="提取码已复制">复制提取码</button>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($theme_options['enable_comments'] === '1') : ?><?php comments_template(); ?><?php endif; ?>
            <?php endwhile; ?>
        </article>
        <?php get_sidebar(); ?>
    </div>
</main>
<?php get_footer(); ?>
