<?php get_header(); ?>
<main class="sluyu-container sluyu-main"><div class="sluyu-content-layout"><article class="sluyu-article"><header class="sluyu-article-header"><h1><?php the_title(); ?></h1></header><?php while (have_posts()) : the_post(); ?><div class="sluyu-entry"><?php the_content(); ?></div><?php endwhile; ?></article><?php get_sidebar(); ?></div></main>
<?php get_footer(); ?>
