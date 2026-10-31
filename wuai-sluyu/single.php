<?php get_header(); $theme_options = wuai_sluyu_get_options(); ?>
<main class="sluyu-container sluyu-main sluyu-single-main">
    <div class="sluyu-content-layout sluyu-single-layout">
        <div class="sluyu-article-column">
        <?php while (have_posts()) : the_post();
            $post_id = get_the_ID();
            $protected = post_password_required();
            $download = !$protected && $theme_options['show_resource_box'] === '1' && wuai_sluyu_post_meta($post_id, 'download_url');
            $cats = get_the_category();
            $badge = wuai_sluyu_post_meta($post_id, 'title_badge');
            $badge_color = sanitize_hex_color(wuai_sluyu_post_meta($post_id, 'title_badge_color', '#ef0053')) ?: '#ef0053';
            $likes = wuai_sluyu_like_count($post_id);
            $liked = is_user_logged_in() && get_user_meta(get_current_user_id(), '_wuai_sluyu_like_' . $post_id, true) === '1';
        ?>
        <article class="sluyu-article sluyu-article-reference" data-post-id="<?php echo absint($post_id); ?>">
            <nav class="sluyu-breadcrumb" aria-label="当前位置">当前位置：<a href="<?php echo esc_url(home_url('/')); ?>">首页</a><span>›</span><?php if ($cats) : ?><a href="<?php echo esc_url(get_category_link($cats[0])); ?>"><?php echo esc_html($cats[0]->name); ?></a><span>›</span><?php endif; ?><span><?php the_title(); ?></span></nav>
            <?php wuai_sluyu_banner($theme_options['article_banner'], $theme_options['footer_banner_url'], 'sluyu-article-banner'); ?>
            <header class="sluyu-article-header">
                <h1><?php if ($badge) : ?><span class="sluyu-title-badge" style="--badge-color:<?php echo esc_attr($badge_color); ?>"><?php echo esc_html($badge); ?></span><?php endif; ?><?php the_title(); ?></h1>
                <div class="sluyu-post-meta-row">
                    <?php if ($theme_options['show_post_meta'] === '1') : ?><div class="sluyu-article-meta"><span class="sluyu-article-cat"><?php the_category(' '); ?></span><time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('n月j日')); ?></time><span title="喜欢"><?php echo wuai_sluyu_icon('heart-fill'); ?><b data-like-count><?php echo esc_html($likes); ?></b></span><span title="阅读"><?php echo wuai_sluyu_icon('eye-fill'); ?><?php echo esc_html((int) get_post_meta($post_id, '_wuai_sluyu_views', true)); ?></span></div><?php endif; ?>
                    <?php if ($download) : ?><a class="sluyu-action-primary sluyu-jump-download" href="#download-box"><?php echo wuai_sluyu_icon('download-cloud-line'); ?> 前往下载</a><?php endif; ?>
                </div>
                <div class="sluyu-mobile-author"><?php echo get_avatar(get_the_author_meta('ID'), 32); ?><a href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>"><?php the_author(); ?></a><span>文章作者</span></div>
            </header>
            <?php if (!$protected && $theme_options['article_show_cover'] === '1' && has_post_thumbnail()) : ?><figure class="sluyu-featured"><img data-sluyu-lightbox="1" src="<?php echo esc_url(get_the_post_thumbnail_url($post_id, 'large')); ?>" alt="<?php the_title_attribute(); ?>"></figure><?php endif; ?>
            <div class="sluyu-entry"><?php the_content(); wp_link_pages(['before' => '<nav class="sluyu-content-pages">文章分页：', 'after' => '</nav>']); ?></div>
            <?php if (!$protected) : ?>
                <?php wuai_sluyu_download_card($post_id); ?>
                <div class="sluyu-article-actions"><div><button type="button" data-sluyu-panel="share" aria-label="分享文章"><?php echo wuai_sluyu_icon('share-line'); ?><span>分享</span></button><?php if ($theme_options['enable_comments'] === '1' && (comments_open() || get_comments_number())) : ?><a href="#comments" aria-label="去评论"><?php echo wuai_sluyu_icon('chat-smile-2-line'); ?><span>评论</span></a><?php endif; ?><button type="button" data-sluyu-bookmark="<?php echo absint($post_id); ?>" aria-pressed="false" aria-label="收藏文章"><?php echo wuai_sluyu_icon('star-line'); ?><span>收藏</span></button><button type="button" data-sluyu-panel="support" aria-label="反馈问题"><?php echo wuai_sluyu_icon('shield-user-line'); ?><span>反馈</span></button></div><button type="button" data-sluyu-like="<?php echo absint($post_id); ?>" aria-pressed="<?php echo $liked ? 'true' : 'false'; ?>"><?php echo wuai_sluyu_icon('thumb-up-line'); ?><b data-like-count><?php echo esc_html($likes); ?></b><span>喜欢</span></button></div>
                <?php $tags = get_the_tags(); if ($tags) : ?><div class="sluyu-article-tags"><?php foreach ($tags as $tag) : ?><a href="<?php echo esc_url(get_tag_link($tag)); ?>"><?php echo wuai_sluyu_icon('price-tag-3-line'); ?><?php echo esc_html($tag->name); ?></a><?php endforeach; ?></div><?php endif; ?>
            <?php endif; ?>
        </article>
        <?php if (!$protected) : ?><aside class="sluyu-article-side-tools" aria-label="文章快捷操作"><button type="button" data-sluyu-panel="share" aria-label="分享文章" title="分享文章"><?php echo wuai_sluyu_icon('share-line'); ?></button><?php if ($theme_options['enable_comments'] === '1' && (comments_open() || get_comments_number())) : ?><a href="#comments" aria-label="去评论" title="去评论"><?php echo wuai_sluyu_icon('chat-smile-2-line'); ?></a><?php endif; ?><button type="button" data-sluyu-bookmark="<?php echo absint($post_id); ?>" aria-pressed="false" aria-label="收藏文章" title="收藏文章"><?php echo wuai_sluyu_icon('star-line'); ?></button><button type="button" data-sluyu-panel="support" aria-label="反馈问题" title="反馈问题"><?php echo wuai_sluyu_icon('shield-user-line'); ?></button><button type="button" data-sluyu-like="<?php echo absint($post_id); ?>" aria-pressed="<?php echo $liked ? 'true' : 'false'; ?>" aria-label="喜欢文章" title="喜欢文章"><?php echo wuai_sluyu_icon('thumb-up-line'); ?><b data-like-count><?php echo esc_html($likes); ?></b></button></aside><?php endif; ?>
        <?php $adjacent = [get_previous_post(), get_next_post()]; if ($adjacent[0] || $adjacent[1]) : ?><nav class="sluyu-post-neighbors" aria-label="相邻文章"><?php foreach ($adjacent as $index => $neighbor) : if (!$neighbor || $neighbor->post_status !== 'publish') continue; ?><a href="<?php echo esc_url(get_permalink($neighbor)); ?>" class="<?php echo $index ? 'is-next' : ''; ?>"><small><?php echo $index ? '下一篇' : '上一篇'; ?></small><strong><?php echo esc_html($neighbor->post_title); ?></strong><time><?php echo esc_html(get_the_date('Y-m-d', $neighbor)); ?></time><?php echo wuai_sluyu_icon($index ? 'arrow-right-s-line' : 'arrow-left-s-line'); ?></a><?php endforeach; ?></nav><?php endif; ?>
        <?php if (!$protected && $theme_options['article_show_related'] === '1') :
            $related = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'has_password' => false, 'post__not_in' => [$post_id], 'category__in' => wp_list_pluck($cats, 'term_id'), 'posts_per_page' => 4, 'ignore_sticky_posts' => true, 'no_found_rows' => true]);
            if ($related->have_posts()) : ?><section class="sluyu-related"><h2>相关推荐</h2><div class="sluyu-grid"><?php while ($related->have_posts()) : $related->the_post(); wuai_sluyu_render_card(get_the_ID()); endwhile; ?></div></section><?php endif; wp_reset_postdata(); endif; ?>
        <?php if ($theme_options['enable_comments'] === '1') comments_template(); ?>
        <?php endwhile; ?>
        </div>
        <?php get_sidebar(); ?>
    </div>
</main>
<?php get_footer(); ?>
