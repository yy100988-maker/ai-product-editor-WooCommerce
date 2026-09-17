<?php
/**
 * 图片 AI 生成：文生图 / 图生图（重绘）。
 * 走 OpenAI images 形状；prompt 模板内置白底主图/场景图两种，运营可改。
 *
 * @package AIPE
 */

defined('ABSPATH') || exit;

class AIPE_ImageGen {

    /**
     * 生成模板（{title} 会被替换为商品英文标题）。
     */
    public static function templates() {
        return [
            'white_bg' => [
                'label' => '白底主图（文生图）',
                'mode' => 'generate',
                'prompt' => "Professional e-commerce product photo of \"{title}\" on a pure white background, studio lighting, centered, high detail, no text, no watermark.",
                'size' => '1024x1024',
            ],
            'lifestyle' => [
                'label' => '场景图（文生图）',
                'mode' => 'generate',
                'prompt' => "Lifestyle e-commerce photo of \"{title}\" in a bright modern home setting, natural light, shallow depth of field, no text, no watermark.",
                'size' => '1024x1024',
            ],
            'redraw' => [
                'label' => '原图重绘（图生图去字）',
                'mode' => 'edit',
                'prompt' => "Recreate this product photo exactly but remove ALL text, labels and watermarks; keep product, colors, layout and background identical, clean background where text was.",
                'size' => '1024x1024',
            ],
        ];
    }

    /**
     * 文生图：prompt → 新附件 ID。
     */
    public static function generate($prompt, $size, $title_note, $settings = null) {
        $s = $settings ?: AIPE_Settings::get();
        $r = AIPE_Client::images_generate($s, $prompt, $size);
        return self::sideload_bytes($r['bytes'], $title_note . ' (AI 生成)', 'image_gen', $r);
    }

    /**
     * 图生图：$attachment_id + prompt → 新附件 ID。
     */
    public static function edit($attachment_id, $prompt, $size, $title_note, $settings = null) {
        $s = $settings ?: AIPE_Settings::get();
        $file = get_attached_file((int) $attachment_id);
        if (!$file || !file_exists($file)) {
            throw new Exception('AIPE_FILE_MISSING');
        }
        $info = @getimagesize($file);
        $mime = $info['mime'] ?? 'image/jpeg';
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $r = AIPE_Client::images_edit($s, (string) file_get_contents($file), 'source.' . $ext, $mime, $prompt, $size);
        $id = self::sideload_bytes($r['bytes'], $title_note . ' (AI 重绘)', 'image_gen', $r);
        update_post_meta($id, '_aipe_source_attachment', (int) $attachment_id);
        return $id;
    }

    /**
     * 二进制入库（与 dxm-media 思路一致：走 wp_upload_bits，保证 uploads 目录权限复用）。
     */
    public static function sideload_bytes($bytes, $title, $kind, $route) {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        if ($bytes === '' || $bytes === null) {
            throw new Exception('AIPE_EMPTY@' . ($route['provider'] ?? ''));
        }
        $name = 'aipe-' . date('Ymd-His') . '-' . wp_generate_password(6, false) . '.png';
        $up = wp_upload_bits($name, null, $bytes);
        if (!empty($up['error'])) {
            throw new Exception('AIPE_UPLOAD:' . $up['error']);
        }
        $type = wp_check_filetype($up['file']);
        $id = wp_insert_attachment([
            'post_mime_type' => $type['type'] ?: 'image/png',
            'post_title' => $title,
            'post_status' => 'inherit',
        ], $up['file']);
        if (is_wp_error($id) || !$id) {
            throw new Exception('AIPE_ATTACH');
        }
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $up['file']));
        update_post_meta($id, '_aipe_kind', $kind);
        update_post_meta($id, '_aipe_provider', $route['provider'] . '/' . $route['model']);
        return $id;
    }
}
