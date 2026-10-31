<?php
if (!defined('ABSPATH')) { exit; }
get_header();
$search_type = isset($_GET['type']) && $_GET['type']==='user' ? 'user' : 'post';
?>
<main class="sluyu-container sluyu-main sluyu-portal"><section class="sluyu-panel"><h1>搜索：<?php echo esc_html(get_search_query()); ?></h1><form class="sluyu-modal-search" method="get" action="<?php echo esc_url(home_url('/')); ?>"><label class="screen-reader-text" for="sluyu-search-content">关键词</label><input id="sluyu-search-content" type="search" name="s" value="<?php echo esc_attr(get_search_query()); ?>" required><select name="type" aria-label="搜索类型"><option value="post" <?php selected($search_type,'post'); ?>>文章与资源</option><option value="user" <?php selected($search_type,'user'); ?>>用户</option></select><button class="sluyu-action-primary">搜索</button></form></section>
<section class="sluyu-panel">
<?php if($search_type==='user') :
    $page=wuai_sluyu_page_number();
    $users=new WP_User_Query(['search'=>'*'.get_search_query(false).'*','search_columns'=>['display_name','user_nicename'],'number'=>20,'offset'=>($page-1)*20]);
    echo '<div class="sluyu-service-grid">';
    foreach($users->get_results() as $user) { echo '<a class="sluyu-service-tile" href="' . esc_url(get_author_posts_url($user->ID)) . '">' . get_avatar($user->ID,48) . '<h2>' . esc_html($user->display_name) . '</h2><p>' . esc_html(get_user_meta($user->ID,'_wuai_verified_title',true) ?: '社区成员') . '</p></a>'; }
    echo '</div>';
    if(!$users->get_total())wuai_sluyu_empty('没有找到匹配的用户。');
    echo '<nav class="sluyu-portal-pagination">' . wp_kses_post((string)paginate_links(['base'=>add_query_arg(['s'=>get_search_query(false),'type'=>'user','pg'=>'%#%'],home_url('/')),'current'=>$page,'total'=>ceil($users->get_total()/20)])) . '</nav>';
else : ?>
    <?php if(have_posts()) : ?><div class="sluyu-grid"><?php while(have_posts()) { the_post(); wuai_sluyu_render_card(); } ?></div><?php the_posts_pagination(['prev_text'=>'上一页','next_text'=>'下一页']); else : wuai_sluyu_empty('没有找到匹配的内容，换个关键词试试。'); endif; ?>
<?php endif; ?>
</section></main>
<?php get_footer(); ?>
