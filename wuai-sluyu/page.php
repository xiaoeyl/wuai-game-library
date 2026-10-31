<?php get_header(); ?>
<main class="sluyu-container sluyu-main"><div class="sluyu-content-layout"><article class="sluyu-article"><header class="sluyu-article-header"><h1><?php the_title(); ?></h1></header><?php while (have_posts()) : the_post(); ?><div class="sluyu-entry"><?php the_content(); wp_link_pages(['before'=>'<nav class="sluyu-content-pages">页面分页：','after'=>'</nav>']); ?></div><?php wuai_sluyu_download_card(get_the_ID()); ?><?php endwhile; ?></article><?php get_sidebar(); ?></div></main>
<?php get_footer(); ?>
