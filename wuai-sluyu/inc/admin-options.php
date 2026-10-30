<?php
if (!defined('ABSPATH')) {
    exit;
}

function wuai_sluyu_defaults() {
    return [
        'hero_title' => '二次元美图资源分享',
        'hero_notice' => '本站不包含任何色情等不符合国家相关法律的信息！',
        'hero_video' => 'https://img-baofun.zhhainiao.com/pcwallpaper_ugc/preview/9b3f6e857ed4ce7c957d9a1d7a2eaa53_preview.mp4',
        'hero_image' => wuai_sluyu_asset('images/hero.jpg'),
        'hot_words' => '生化危机9,使命召唤系列,剑星,看门狗2',
        'promo_image' => wuai_sluyu_asset('images/promo.jpg'),
        'ad_image' => wuai_sluyu_asset('images/ad.jpg'),
        'sections' => "图包\n美图\n游戏资源\n资讯",
        'primary_color' => '#ef0053',
        'footer_text' => '记录每一份热爱，分享每一次发现。',
        'github_owner' => 'xiaoeyl',
        'github_repo' => 'wuai-game-library',
        'legacy_html_links' => '1',
    ];
}

function wuai_sluyu_get_options() {
    return wp_parse_args((array) get_option('wuai_sluyu_options', []), wuai_sluyu_defaults());
}

add_action('admin_menu', function () {
    add_theme_page('吾爱森鹿语设置', '吾爱森鹿语', 'manage_options', 'wuai-sluyu', 'wuai_sluyu_options_page');
});

add_action('admin_init', function () {
    register_setting('wuai_sluyu_options', 'wuai_sluyu_options', [
        'sanitize_callback' => function ($input) {
            $defaults = wuai_sluyu_defaults();
            $input = is_array($input) ? $input : [];
            foreach (['hero_title', 'hero_notice', 'hero_video', 'hero_image', 'hot_words', 'promo_image', 'ad_image', 'sections', 'footer_text', 'github_owner', 'github_repo'] as $key) {
                $defaults[$key] = isset($input[$key]) ? sanitize_textarea_field($input[$key]) : $defaults[$key];
            }
            $defaults['primary_color'] = isset($input['primary_color']) && preg_match('/^#[0-9a-f]{6}$/i', $input['primary_color']) ? $input['primary_color'] : '#ef0053';
            return $defaults;
        },
    ]);
});

function wuai_sluyu_options_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $o = wuai_sluyu_get_options();
    ?>
    <div class="wrap wuai-sluyu-settings">
        <h1>吾爱森鹿语主题设置</h1>
        <p class="description">基于 Zibll 风格的首页配置。设置保存后刷新前台即可看到效果。</p>
        <form method="post" action="options.php">
            <?php settings_fields('wuai_sluyu_options'); ?>
            <div class="wuai-settings-grid">
                <section class="wuai-settings-card"><h2>首页 Hero</h2>
                    <?php wuai_sluyu_field('hero_title', '主标题', $o); ?>
                    <?php wuai_sluyu_field('hero_notice', '免责声明', $o, 'textarea'); ?>
                    <?php wuai_sluyu_field('hero_video', '背景视频 URL', $o); ?>
                    <?php wuai_sluyu_field('hero_image', '背景图片 URL', $o); ?>
                    <?php wuai_sluyu_field('hot_words', '热搜词（逗号分隔）', $o); ?>
                </section>
                <section class="wuai-settings-card"><h2>首页模块</h2>
                    <?php wuai_sluyu_field('promo_image', '横幅图片 URL', $o); ?>
                    <?php wuai_sluyu_field('ad_image', '侧栏广告 URL', $o); ?>
                    <?php wuai_sluyu_field('sections', '首页栏目（每行一个分类）', $o, 'textarea'); ?>
                    <?php wuai_sluyu_field('primary_color', '主题色', $o, 'color'); ?>
                    <?php wuai_sluyu_field('footer_text', '页脚说明', $o); ?>
                </section>
                <section class="wuai-settings-card"><h2>GitHub 在线更新</h2>
                    <?php wuai_sluyu_field('github_owner', '仓库账号', $o); ?>
                    <?php wuai_sluyu_field('github_repo', '仓库名称', $o); ?>
                    <p class="description">主题通过公开 GitHub Releases 检查更新，不在主题中保存 PAT。</p>
                </section>
            </div>
            <?php submit_button('保存主题设置'); ?>
        </form>
    </div>
    <?php
}

function wuai_sluyu_field($key, $label, $options, $type = 'text') {
    $value = $options[$key] ?? '';
    printf('<label class="wuai-settings-field"><span>%s</span>', esc_html($label));
    if ($type === 'textarea') {
        printf('<textarea name="wuai_sluyu_options[%1$s]" rows="4">%2$s</textarea>', esc_attr($key), esc_textarea($value));
    } elseif ($type === 'color') {
        printf('<input type="color" name="wuai_sluyu_options[%1$s]" value="%2$s">', esc_attr($key), esc_attr($value));
    } else {
        printf('<input type="text" name="wuai_sluyu_options[%1$s]" value="%2$s">', esc_attr($key), esc_attr($value));
    }
    echo '</label>';
}

add_action('admin_head-appearance_page_wuai-sluyu', function () {
    echo '<style>.wuai-sluyu-settings{max-width:1200px}.wuai-settings-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin:22px 0}.wuai-settings-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:22px;box-shadow:0 4px 18px rgba(30,41,59,.05)}.wuai-settings-card h2{margin-top:0;font-size:17px}.wuai-settings-field{display:block;margin:17px 0}.wuai-settings-field>span{display:block;font-weight:600;margin-bottom:7px}.wuai-settings-field input[type=text],.wuai-settings-field textarea{width:100%;border:1px solid #ccd6e3;border-radius:7px;padding:9px}.wuai-settings-field input[type=color]{width:70px;height:38px;padding:2px}@media(max-width:900px){.wuai-settings-grid{grid-template-columns:1fr}}</style>';
});
