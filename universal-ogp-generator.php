<?php
/**
 * Plugin Name: Universal OGP Generator - Hybrid Font Edition -
 * Description: あらゆるテーマでアイキャッチを自動生成。WebP圧縮・名前の漢字欠け防止フォントエンジン搭載。
 * Version: 1.7.0
 * Author: Yuuga Tamekuni
 * License: GPL2
 */

if ( !defined( 'ABSPATH' ) ) exit;

// --- 定数定義 ---
define('TTFI_FONT_DIR', plugin_dir_path(__FILE__) . 'fonts/');
define('TTFI_TEMP_FONT_DIR', TTFI_FONT_DIR . 'temp/');

/**
 * 標準フォント（名前用）を自動検索
 */
function ttfi_find_standard_font() {
    $names = ['NotoSansJP-Regular.ttf', 'notosansjp-regular.ttf', 'NotoSansJP-Medium.ttf'];
    foreach ($names as $name) {
        if (file_exists(TTFI_FONT_DIR . $name)) return TTFI_FONT_DIR . $name;
    }
    $files = glob(TTFI_FONT_DIR . "*.ttf");
    return !empty($files) ? $files[0] : false;
}

/**
 * 高精度な円形切り抜き（アンチエイリアス処理）
 */
function ttfi_get_circular_avatar($url, $size) {
    $raw = @file_get_contents($url);
    if (!$raw) return false;
    $src = @imagecreatefromstring($raw);
    if (!$src) return false;

    $w = imagesx($src); $h = imagesy($src);
    $square = min($w, $h);
    $dest = imagecreatetruecolor($size, $size);
    
    // 透明度の保持
    imagealphablending($dest, false);
    imagesavealpha($dest, true);
    $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
    imagefill($dest, 0, 0, $transparent);

    // 円形サンプリング
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $dx = $x - $size / 2;
            $dy = $y - $size / 2;
            if (($dx * $dx + $dy * $dy) <= ($size / 2) * ($size / 2)) {
                $sx = ($x * $square / $size) + ($w - $square) / 2;
                $sy = ($y * $square / $size) + ($h - $square) / 2;
                $color = imagecolorat($src, (int)$sx, (int)$sy);
                imagesetpixel($dest, $x, $y, $color);
            }
        }
    }
    imagedestroy($src);
    return $dest;
}

/**
 * 画像生成、WebP変換、および圧縮メインロジック
 */
function ttfi_generate_dynamic_image($post_id, $path, $width, $height) {
    // Cocoonテーマ使用時は動作停止（リスペクト設計）
    $theme = wp_get_theme();
    if ($theme->get_template() === 'cocoon-master') return false;

    // フォント選定
    $title_font = ttfi_find_standard_font(); 
    $custom_url = get_option('ttfi_font_url');
    if (get_option('ttfi_font_mode') === 'custom' && !empty($custom_url)) {
        if (!file_exists(TTFI_TEMP_FONT_DIR)) wp_mkdir_p(TTFI_TEMP_FONT_DIR);
        $temp_path = TTFI_TEMP_FONT_DIR . 'custom-' . md5($custom_url) . '.ttf';
        if (file_exists($temp_path)) $title_font = $temp_path;
    }
    $name_font = ttfi_find_standard_font(); // 著者名は常に標準

    // 投稿情報取得
    $title = html_entity_decode(get_the_title($post_id));
    $author_id = get_post_field('post_author', $post_id);
    $author_name = get_the_author_meta('display_name', $author_id);
    $avatar_url = get_avatar_url($author_id, ['size' => 128]);

    $img = imagecreatetruecolor($width, $height);
    
    // カラー設定
    list($br,$bg,$bb) = sscanf(get_option('ttfi_bg_color','#ffffff'), "#%02x%02x%02x");
    list($bor,$bog,$bob) = sscanf(get_option('ttfi_border_color','#a2d7dd'), "#%02x%02x%02x");
    list($tr,$tg,$tb) = sscanf(get_option('ttfi_text_color','#333333'), "#%02x%02x%02x");
    $bg_c = imagecolorallocate($img, $br, $bg, $bb);
    $bor_c = imagecolorallocate($img, $bor, $bog, $bob);
    $txt_c = imagecolorallocate($img, $tr, $tg, $tb);

    // 描画開始
    imagefilledrectangle($img, 0, 0, $width, $height, $bor_c);
    imagefilledrectangle($img, 30, 30, $width-30, $height-30, $bg_c);

    // タイトル描画（マルチライン対応）
    $font_size = 46; $margin = $width * 0.1;
    $words = preg_split('/(?<=\p{Hiragana}|\p{Katakana}|\p{Han}|\s)|(?=\p{Hiragana}|\p{Katakana}|\p{Han}|\s)/u', $title, -1, PREG_SPLIT_NO_EMPTY);
    $lines = []; $cur = '';
    foreach($words as $w){
        $box = imagettfbbox($font_size, 0, $title_font, $cur.$w);
        if(($box[2]-$box[0]) > ($width-$margin*2)){ $lines[]=trim($cur); $cur=$w; } else { $cur.=$w; }
    }
    $lines[]=trim($cur);
    $y = 220;
    foreach(array_slice($lines,0,2) as $l){
        imagettftext($img, $font_size, 0, $margin, $y, $txt_c, $title_font, $l);
        $y += 85;
    }

    // アバター描画
    $avatar = ttfi_get_circular_avatar($avatar_url, 82);
    if ($avatar) {
        imagecopy($img, $avatar, $margin, $height-150, 0, 0, 82, 82);
        imagedestroy($avatar);
        // 著者名の描画（ハイブリッド：標準フォント使用）
        imagettftext($img, 34, 0, $margin + 110, $height-100, $txt_c, $name_font, $author_name);
    }

    // --- WebP/PNG 圧縮保存 ---
    if (function_exists('imagewebp')) {
        $path = str_replace(['.png', '.jpg', '.jpeg'], '.webp', $path);
        $res = imagewebp($img, $path, 80); // クオリティ80で圧縮
    } else {
        $res = imagepng($img, $path, 8); // PNG圧縮レベル8
    }

    imagedestroy($img);
    return $res ? $path : false;
}

/**
 * 記事保存時の自動フック
 */
add_action('save_post', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['ttfi_enable']) || $_POST['ttfi_enable'] != '1') return;
    if (get_post_thumbnail_id($post_id)) return; // すでに設定済みならスキップ

    $upload_dir = wp_upload_dir();
    $path = $upload_dir['basedir'] . '/ttfi-images/ogp-' . $post_id . '.webp';
    wp_mkdir_p(dirname($path));

    $final_path = ttfi_generate_dynamic_image($post_id, $path, 1200, 630);
    
    if ($final_path) {
        $file_type = wp_check_filetype($final_path, null);
        $attach_id = wp_insert_attachment([
            'post_mime_type' => $file_type['type'],
            'post_title' => basename($final_path),
            'post_status' => 'inherit'
        ], $final_path, $post_id);
        
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $final_path));
        set_post_thumbnail($post_id, $attach_id);
    }
});

/**
 * 設定画面メニュー
 */
add_action('admin_menu', function() {
    $theme = wp_get_theme();
    if ($theme->get_template() === 'cocoon-master') return;
    add_options_page('Universal OGP 設定', 'Universal OGP 設定', 'manage_options', 'ttfi-settings', 'ttfi_render_page');
});

add_action('admin_init', function() {
    register_setting('ttfi_group', 'ttfi_bg_color');
    register_setting('ttfi_group', 'ttfi_border_color');
    register_setting('ttfi_group', 'ttfi_text_color');
    register_setting('ttfi_group', 'ttfi_font_mode');
    register_setting('ttfi_group', 'ttfi_font_url');
});

function ttfi_render_page() {
    ?>
    <div class="wrap">
        <h1>Universal OGP Generator <small>- Hybrid Font Edition -</small></h1>
        <form method="post" action="options.php">
            <?php settings_fields('ttfi_group'); ?>
            <table class="form-table">
                <tr><th>背景色</th><td><input type="color" name="ttfi_bg_color" value="<?php echo esc_attr(get_option('ttfi_bg_color','#ffffff')); ?>"></td></tr>
                <tr><th>枠線の色</th><td><input type="color" name="ttfi_border_color" value="<?php echo esc_attr(get_option('ttfi_border_color','#a2d7dd')); ?>"></td></tr>
                <tr><th>テキスト色</th><td><input type="color" name="ttfi_text_color" value="<?php echo esc_attr(get_option('ttfi_text_color','#333333')); ?>"></td></tr>
                <tr><th>フォントモード</th><td>
                    <label><input type="radio" name="ttfi_font_mode" value="default" <?php checked(get_option('ttfi_font_mode','default'),'default'); ?>> 標準 (Noto Sans JP)</label><br>
                    <label><input type="radio" name="ttfi_font_mode" value="custom" <?php checked(get_option('ttfi_font_mode'),'custom'); ?>> カスタムURL (.ttf)</label>
                </td></tr>
                <tr><th>カスタムフォントURL</th><td><input type="url" name="ttfi_font_url" value="<?php echo esc_attr(get_option('ttfi_font_url')); ?>" class="regular-text" placeholder="https://example.com/font.ttf"></td></tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * 投稿編集画面のメタボックス
 */
add_action('add_meta_boxes', function() {
    $theme = wp_get_theme();
    if ($theme->get_template() === 'cocoon-master') return;
    add_meta_box('ttfi_box', 'Universal OGP 生成', function() {
        echo '<label><input type="checkbox" name="ttfi_enable" value="1" checked> 保存時にWebPアイキャッチを自動生成する</label>';
    }, ['post','page'], 'side');
});