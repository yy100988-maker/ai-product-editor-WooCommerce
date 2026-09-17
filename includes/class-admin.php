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

        // 我们的 AJAX 请求：尽早接管输出缓冲，把其他插件在请求期间写出的
        // BOM / PHP 警告文本全部吞掉，保证响应体从第一个字节起就是我们的 JSON。
        if (self::is_our_ajax()) {
            add_action('plugins_loaded', [__CLASS__, 'start_clean_buffer'], 0);
        }

        add_action('wp_ajax_aipe_preview_text', [__CLASS__, 'ajax_preview_text']);
        add_action('wp_ajax_aipe_apply_text', [__CLASS__, 'ajax_apply_text']);
        add_action('wp_ajax_aipe_preview_image', [__CLASS__, 'ajax_preview_image']);
        add_action('wp_ajax_aipe_create_job', [__CLASS__, 'ajax_create_job']);
        add_action('wp_ajax_aipe_test_baidu', [__CLASS__, 'ajax_test_baidu']);
        add_action('wp_ajax_aipe_job_status', [__CLASS__, 'ajax_job_status']);
    }

    /**
     * 当前请求是不是本插件的 AJAX 调用。
     */
    protected static function is_our_ajax() {
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        return strpos($action, 'aipe_') === 0;
    }

    /**
     * 开一个过滤型缓冲：任何被 echo 出来的内容都不进响应体。
     *
     * 实测本站有其他插件（疑似 woo-multi-currency）在 wp-load 阶段就 echo 了 7 个 UTF-8 BOM，
     * 污染所有 AJAX 响应。json_out() 写 JSON 前会清掉这层缓冲。
     */
    public static function start_clean_buffer() {
        if (!self::is_our_ajax()) {
            return;
        }
        // 关掉别人先开的缓冲（内容丢弃），再由我们独占一个干净的
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        // 万一有插件在我们之后又 echo，shutdown 时再清一次
        add_action('shutdown', [__CLASS__, 'flush_clean'], 0);
    }

    /**
     * 请求收尾：确保响应体只剩我们要的 JSON（清掉任何后追加的字节）。
     */
    public static function flush_clean() {
        // json_out() 已经 exit，走到这里说明 handler 没正常返回，兜底清一次
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
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
        $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE)) {
            // 不用 check_ajax_referer：它 wp_die('-1') 返回非 JSON，前端只会看到 "not valid JSON"
            self::json_out(['success' => false, 'data' => ['code' => 'AIPE_BAD_NONCE', 'detail' => '页面 nonce 已过期，请刷新页面后重试']], 403);
        }
        if ($post_id && !current_user_can('edit_post', $post_id)) {
            self::json_out(['success' => false, 'data' => ['code' => 'AIPE_FORBIDDEN']], 403);
        }
        if (!$post_id && !current_user_can('manage_woocommerce')) {
            self::json_out(['success' => false, 'data' => ['code' => 'AIPE_FORBIDDEN']], 403);
        }
    }

    /**
     * 输出 JSON 并结束请求。
     *
     * 实测：其他插件（woo-multi-currency）在请求期间会 setcookie/echo，导致响应体前面
     * 混入 BOM 或 PHP 警告文本，前端 JSON.parse 直接失败。
     * 这里逐层清干净所有输出缓冲后，再把 JSON 写出去，并强制指定 Content-Length，
     * 让任何后续追加的字节都落在声明的长度之外（浏览器会忽略）。
     */
    protected static function json_out($payload, $status = 200) {
        $json = wp_json_encode($payload);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // 关掉之后可能又有插件重新开缓冲/追加内容，这里再清一次
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8', true, (int) $status);
            header('Content-Length: ' . strlen($json));
            header('X-Content-Type-Options: nosniff');
        }
        echo $json;
        exit;
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
            self::json_out(['success' => true, 'data' => [
                'title_en' => $title_r['text'],
                'name_map' => $label_map,
                'value_map' => $value_map,
                'cached' => $title_r['cached'],
                'provider' => $title_r['provider'],
            ]]);
        } catch (Exception $e) {
            self::json_out(['success' => false, 'data' => ['code' => explode(':', $e->getMessage())[0]]]);
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
            self::json_out(['success' => true, 'data' => $r]);
        } catch (Exception $e) {
            self::json_out(['success' => false, 'data' => ['code' => explode(':', $e->getMessage())[0]]]);
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
            self::json_out(['success' => false, 'data' => ['code' => 'AIPE_NO_IMAGE']]);
        }
        try {
            $items = AIPE_ImageTrans::preview($att);
            self::json_out(['success' => true, 'data' => ['items' => $items]]);
        } catch (Exception $e) {
            self::json_out(['success' => false, 'data' => ['code' => explode(':', $e->getMessage())[0]]]);
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
            self::json_out(['success' => false, 'data' => ['code' => 'AIPE_BAD_KIND']]);
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
            self::json_out(['success' => false, 'data' => ['code' => 'AIPE_NO_IMAGE']]);
        }
        $job_id = AIPE_Jobs::create($pid, $kind, $payload);
        AIPE_Jobs::dispatch($job_id);
        self::json_out(['success' => true, 'data' => ['job_id' => $job_id]]);
    }

    /**
     * 百度连接测试：用 keys 发一张白底小图；鉴权通过即有效（不花钱的是失败也分得清）。
     */
    public static function ajax_test_baidu() {
        self::check();
        try {
            $r = AIPE_ImgApi::test_auth(AIPE_Settings::get());
            self::json_out(['success' => true, 'data' => $r]);
        } catch (Exception $e) {
            self::json_out(['success' => false, 'data' => ['code' => explode(':', $e->getMessage())[0], 'detail' => $e->getMessage()]]);
        }
    }

    public static function ajax_job_status() {
        $job_id = (int) ($_POST['job_id'] ?? 0);
        self::check();
        $job = AIPE_Jobs::get($job_id);
        if (!$job) {
            self::json_out(['success' => false, 'data' => ['code' => 'AIPE_NO_JOB']]);
        }
        if (!current_user_can('edit_post', (int) $job->product_id)) {
            self::json_out(['success' => false, 'data' => ['code' => 'AIPE_FORBIDDEN']], 403);
        }
        self::json_out(['success' => true, 'data' => [
            'status' => $job->status,
            'error_code' => $job->error_code,
            'result' => $job->result ? json_decode($job->result, true) : null,
        ]]);
    }
}

