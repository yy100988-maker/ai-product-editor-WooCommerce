<?php
/**
 * 后台：Woo 子菜单设置页 + 商品编辑页 metabox + AJAX。
 *
 * @package AIPE
 */

defined('ABSPATH') || exit;

class AIPE_Admin {

    const PAGE = 'aipe-settings';
    const NONCE = 'aipe';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_aipe_save', [__CLASS__, 'handle_save']);
        add_action('add_meta_boxes', [__CLASS__, 'meta_boxes']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_notices', [__CLASS__, 'notices']);

        add_action('wp_ajax_aipe_preview_text', [__CLASS__, 'ajax_preview_text']);
        add_action('wp_ajax_aipe_apply_text', [__CLASS__, 'ajax_apply_text']);
        add_action('wp_ajax_aipe_preview_image', [__CLASS__, 'ajax_preview_image']);
        add_action('wp_ajax_aipe_create_job', [__CLASS__, 'ajax_create_job']);
        add_action('wp_ajax_aipe_test_baidu', [__CLASS__, 'ajax_test_baidu']);
        add_action('wp_ajax_aipe_job_status', [__CLASS__, 'ajax_job_status']);
    }

    public static function menu() {
        add_submenu_page(
            'woocommerce',
            'AI 产品编辑器',
            'AI 产品编辑',
            'manage_woocommerce',
            self::PAGE,
            [__CLASS__, 'render_settings']
        );
    }

    public static function assets($hook) {
        $is_settings = strpos((string) $hook, self::PAGE) !== false;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_product = $screen && $screen->id === 'product';
        if (!$is_settings && !$is_product) {
            return;
        }
        wp_enqueue_style('aipe-admin', AIPE_URL . 'assets/admin.css', [], AIPE_VER);
        wp_enqueue_script('aipe-admin', AIPE_URL . 'assets/admin.js', [], AIPE_VER, true);
        wp_localize_script('aipe-admin', 'AIPE', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
        ]);
    }

    public static function notices() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (!extension_loaded('gd')) {
            echo '<div class="notice notice-warning"><p>AI 产品编辑器：未检测到 PHP GD 扩展，图片 AI 翻译（擦除重绘）不可用。请启用 <code>gd</code> 后再试。</p></div>';
        }
        $s = AIPE_Settings::get();
        $has_key = false;
        foreach (AIPE_Settings::routes($s, 'chat') as $r) {
            $raw = $s['providers'][$r['id']]['api_key'] ?? '';
            if ($raw !== '') {
                $has_key = true;
                break;
            }
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (($s['img_source'] ?? 'vision') === 'baidu') {
            $bc = AIPE_ImgApi::creds($s);
            if (($bc['appid'] === '' || $bc['key'] === '') && $screen && (strpos((string) $screen->id, self::PAGE) !== false)) {
                $url = admin_url('admin.php?page=' . self::PAGE);
                echo '<div class="notice notice-warning"><p>AI 产品编辑器：图片翻译来源是百度，但 APP ID / 密钥没填，<a href="' . esc_url($url) . '">去设置</a>。</p></div>';
            }
        }
        if (!$has_key && $screen && ($screen->id === 'product' || strpos((string) $screen->id, self::PAGE) !== false)) {
            $url = admin_url('admin.php?page=' . self::PAGE);
            echo '<div class="notice notice-warning"><p>AI 产品编辑器：还没有填写任何供应商 Key，<a href="' . esc_url($url) . '">去设置</a>。</p></div>';
        }
    }

    // ---------- 设置页 ----------

    public static function render_settings() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('权限不足');
        }
        $s = AIPE_Settings::get();
        $notice = get_transient('aipe_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('aipe_notice_' . get_current_user_id());
        }
        $gd = extension_loaded('gd') ? '可用' : '缺失（图片翻译不可用）';
        $font = AIPE_ImageTrans::resolve_font($s['image']);
        include AIPE_DIR . 'views/page-settings.php';
    }

    public static function handle_save() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('权限不足');
        }
        check_admin_referer(self::NONCE);
        AIPE_Settings::save_from_post($_POST);
        set_transient('aipe_notice_' . get_current_user_id(), '已保存', 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE));
        exit;
    }

    // ---------- 商品编辑页 metabox ----------

    public static function meta_boxes() {
        add_meta_box(
            'aipe-editor',
            'AI 产品编辑',
            [__CLASS__, 'render_metabox'],
            'product',
            'normal',
            'high'
        );
    }

    public static function render_metabox($post) {
        if (!current_user_can('edit_post', $post->ID)) {
            echo '<p>权限不足</p>';
            return;
        }
        $data = AIPE_Product::collect($post->ID);
        if (!$data) {
            echo '<p>商品读取失败（需要 WooCommerce）</p>';
            return;
        }
        $jobs = AIPE_Jobs::for_product($post->ID, 10);
        $templates = AIPE_ImageGen::templates();
        include AIPE_DIR . 'views/metabox.php';
    }

    // ---------- AJAX ----------

    protected static function check($post_id = 0) {
        check_ajax_referer(self::NONCE, 'nonce');
        if ($post_id && !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['code' => 'AIPE_FORBIDDEN'], 403);
        }
        if (!$post_id && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(['code' => 'AIPE_FORBIDDEN'], 403);
        }
    }

    /**
     * 文本预览：标题 + 属性名 + 属性值，一次返回。
     */
    public static function ajax_preview_text() {
        $pid = (int) ($_POST['product_id'] ?? 0);
        self::check($pid);
        try {
            $s = AIPE_Settings::get();
            $data = AIPE_Product::collect($pid);
            if (!$data) {
                throw new Exception('AIPE_NO_PRODUCT');
            }
            $title_r = AIPE_Translate::title($data['title'], $s);
            $labels = [];
            $values = [];
            foreach ($data['attributes'] as $attr) {
                $labels[] = $attr['label'];
                foreach ($attr['options'] as $o) {
                    $values[] = $o;
                }
            }
            $label_map = AIPE_Translate::terms(array_unique($labels), $s);
            $value_map = AIPE_Translate::terms(array_unique($values), $s);
            unset($label_map['_meta'], $value_map['_meta']);
            wp_send_json_success([
                'title_en' => $title_r['text'],
                'name_map' => $label_map,
                'value_map' => $value_map,
                'cached' => $title_r['cached'],
                'provider' => $title_r['provider'],
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['code' => explode(':', $e->getMessage())[0]]);
        }
    }

    public static function ajax_apply_text() {
        $pid = (int) ($_POST['product_id'] ?? 0);
        self::check($pid);
        try {
            $title = isset($_POST['title_en']) ? wp_kses_post(wp_unslash($_POST['title_en'])) : null;
            $names = json_decode(wp_unslash($_POST['name_map'] ?? '{}'), true) ?: [];
            $values = json_decode(wp_unslash($_POST['value_map'] ?? '{}'), true) ?: [];
            // 只接受“原文→译文”且原文真实存在的映射（防伪造 key 乱改）。
            $data = AIPE_Product::collect($pid);
            if (!$data) {
                throw new Exception('AIPE_NO_PRODUCT');
            }
            $names = self::filter_map($names, self::known_labels($data));
            $values = self::filter_map($values, self::known_values($data));
            if ($title !== null && $title !== '') {
                AIPE_Product::apply_title($pid, $title);
            }
            $r = AIPE_Product::apply_attributes($pid, $names, $values);
            wp_send_json_success($r);
        } catch (Exception $e) {
            wp_send_json_error(['code' => explode(':', $e->getMessage())[0]]);
        }
    }

    protected static function known_labels($data) {
        $k = [];
        foreach ($data['attributes'] as $attr) {
            $k[$attr['label']] = true;
        }
        return $k;
    }

    protected static function known_values($data) {
        $k = [];
        foreach ($data['attributes'] as $attr) {
            foreach ($attr['options'] as $o) {
                $k[$o] = true;
            }
        }
        return $k;
    }

    protected static function filter_map($map, $allowed) {
        $out = [];
        foreach ((array) $map as $k => $v) {
            $v = trim(wp_kses_post((string) $v));
            if (isset($allowed[$k]) && $v !== '' && $v !== $k) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * 图片预览：OCR + 翻译，返回 0-1000 框与译文（不写图，前端画覆盖层）。
     */
    public static function ajax_preview_image() {
        $pid = (int) ($_POST['product_id'] ?? 0);
        self::check($pid);
        $att = (int) ($_POST['attachment_id'] ?? 0);
        if (!$att) {
            wp_send_json_error(['code' => 'AIPE_NO_IMAGE']);
        }
        try {
            $items = AIPE_ImageTrans::preview($att);
            wp_send_json_success(['items' => $items]);
        } catch (Exception $e) {
            wp_send_json_error(['code' => explode(':', $e->getMessage())[0]]);
        }
    }

    /**
     * 建图片任务：kind=image_translate|image_generate|image_edit。
     * image_translate 可带 items（预览结果 JSON），命中则跳过 OCR+翻译。
     */
    public static function ajax_create_job() {
        $pid = (int) ($_POST['product_id'] ?? 0);
        self::check($pid);
        $kind = sanitize_key($_POST['kind'] ?? '');
        if (!in_array($kind, ['image_translate', 'image_generate', 'image_edit'], true)) {
            wp_send_json_error(['code' => 'AIPE_BAD_KIND']);
        }
        $items = json_decode(wp_unslash($_POST['items'] ?? ''), true);
        $payload = [
            'attachment_id' => (int) ($_POST['attachment_id'] ?? 0),
            'template' => sanitize_key($_POST['template'] ?? ''),
            'slot' => sanitize_key($_POST['slot'] ?? 'gallery'),
            'auto_apply' => !empty($_POST['auto_apply']),
            'title_en' => sanitize_text_field(wp_unslash($_POST['title_en'] ?? '')),
            'custom_prompt' => trim(sanitize_textarea_field(wp_unslash($_POST['custom_prompt'] ?? ''))),
            'items' => is_array($items) ? array_slice($items, 0, 60) : [],
        ];
        if (in_array($kind, ['image_translate', 'image_edit'], true) && !$payload['attachment_id']) {
            wp_send_json_error(['code' => 'AIPE_NO_IMAGE']);
        }
        $job_id = AIPE_Jobs::create($pid, $kind, $payload);
        AIPE_Jobs::dispatch($job_id);
        wp_send_json_success(['job_id' => $job_id]);
    }

    /**
     * 百度连接测试：用 keys 发一张白底小图；鉴权通过即有效（不花钱的是失败也分得清）。
     */
    public static function ajax_test_baidu() {
        self::check();
        try {
            $r = AIPE_ImgApi::test_auth(AIPE_Settings::get());
            wp_send_json_success($r);
        } catch (Exception $e) {
            wp_send_json_error(['code' => explode(':', $e->getMessage())[0], 'detail' => $e->getMessage()]);
        }
    }

    public static function ajax_job_status() {
        $job_id = (int) ($_POST['job_id'] ?? 0);
        self::check();
        $job = AIPE_Jobs::get($job_id);
        if (!$job) {
            wp_send_json_error(['code' => 'AIPE_NO_JOB']);
        }
        if (!current_user_can('edit_post', (int) $job->product_id)) {
            wp_send_json_error(['code' => 'AIPE_FORBIDDEN'], 403);
        }
        wp_send_json_success([
            'status' => $job->status,
            'error_code' => $job->error_code,
            'result' => $job->result ? json_decode($job->result, true) : null,
        ]);
    }
}

