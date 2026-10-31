<?php if (post_password_required() || (!comments_open() && !get_comments_number())) return; ?>
<section class="sluyu-comments sluyu-comments-reference" id="comments">
    <h2>评论 <span><?php echo esc_html(get_comments_number()); ?> 条</span></h2>
    <?php if (comments_open()) : comment_form([
        'title_reply' => '说说你的看法',
        'title_reply_to' => '回复 %s',
        'label_submit' => '发表评论',
        'class_submit' => 'sluyu-comment-submit',
        'comment_notes_before' => '<p class="comment-notes">友善交流，分享你的使用体验。</p>',
        'comment_field' => '<p class="comment-form-comment"><label class="screen-reader-text" for="comment">评论内容</label><textarea id="comment" name="comment" rows="4" placeholder="写下你的评论…" required></textarea></p>',
    ]); endif; ?>
    <?php if (have_comments()) : ?><ol class="sluyu-comment-list"><?php wp_list_comments(['style' => 'ol', 'short_ping' => true, 'callback' => 'wuai_sluyu_comment', 'avatar_size' => 40]); ?></ol><nav class="sluyu-comment-pagination"><?php paginate_comments_links(); ?></nav><?php else : ?><div class="sluyu-comments-empty"><?php echo wuai_sluyu_icon('chat-smile-2-line'); ?><p>暂无评论，来聊聊吧。</p></div><?php endif; ?>
</section>
