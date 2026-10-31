<?php
if (!defined('ABSPATH')) { exit; }
get_header();
$author = get_queried_object();
$author_id = (int)$author->ID;
$cover_url = wuai_sluyu_profile_cover_url($author_id);
?>
<main class="sluyu-container sluyu-main sluyu-portal"><section class="sluyu-panel"><?php if($cover_url) : ?><img class="sluyu-profile-cover" src="<?php echo esc_url($cover_url); ?>" alt="<?php echo esc_attr($author->display_name . ' 的公开主页封面'); ?>"><?php endif; ?><div class="sluyu-profile-heading"><?php echo get_avatar($author_id,80); ?><div><h1><?php echo esc_html($author->display_name); ?></h1><p><?php echo esc_html(get_user_meta($author_id,'_wuai_verified_title',true) ?: '社区创作者'); ?></p></div></div><p><?php echo esc_html(get_user_meta($author_id,'description',true)); ?></p>
    <div class="sluyu-filter-tabs"><a href="<?php echo esc_url(get_author_feed_link($author_id)); ?>">订阅文章</a><a href="<?php echo esc_url(wuai_sluyu_portal_url('directmessage',['peer'=>$author_id])); ?>">发私信</a></div>
    <?php if($author_id!==get_current_user_id()) { wuai_sluyu_action_button('follow',is_user_logged_in() && wuai_sluyu_has_event(get_current_user_id(),'follow:'.$author_id)?'取消关注':'关注作者',['id'=>$author_id]); } ?>
</section><section class="sluyu-panel"><h2>发布的文章</h2><div class="sluyu-grid"><?php while(have_posts()) { the_post(); wuai_sluyu_render_card(); } ?></div><?php the_posts_pagination(['prev_text'=>'上一页','next_text'=>'下一页']); ?></section></main>
<?php get_footer(); ?>
