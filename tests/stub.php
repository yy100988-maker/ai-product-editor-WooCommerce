<?php
/**
 * AIPE 离线测试桩：WP/WC/HTTP/DB 全内存模拟。
 * 用法：require 本文件后再 require 被测类。
 */
defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');

$GLOBALS['aipe_options'] = [];
$GLOBALS['aipe_meta'] = [];
$GLOBALS['aipe_terms'] = [];       // term_id => ['name'=>..,'slug'=>..,'taxonomy'=>..]
$GLOBALS['aipe_products'] = [];    // id => fake product
$GLOBALS['aipe_attachments'] = []; // id => ['file'=>..,'title'=>..]
$GLOBALS['aipe_http_log'] = [];
$GLOBALS['aipe_http_plan'] = [];   // url 片段 => 响应（见 aipe_plan_http）
$GLOBALS['aipe_uploads'] = [];
$GLOBALS['aipe_next_att'] = 5000;
$GLOBALS['aipe_cron'] = [];
$GLOBALS['aipe_actions'] = [];

// ---------- 基础 ----------
class WP_Error {
    private $m;
    public function __construct($c = '', $m = '') { $this->m = $m ?: $c; }
    public function get_error_message() { return $this->m; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function apply_filters($t, $v) { return $v; }
function add_action(...$a) { $GLOBALS['aipe_actions'][] = $a; }
function add_meta_box(...$a) {}
function wp_nonce_field(...$a) {}
function check_ajax_referer(...$a) {}
function check_admin_referer(...$a) {}
function wp_send_json_success($d = null) { throw new AipeJsonExit(['success' => true, 'data' => $d]); }
function wp_send_json_error($d = null, $c = null) { throw new AipeJsonExit(['success' => false, 'data' => $d, 'code' => $c]); }
class AipeJsonExit extends Exception {
    public $payload;
    public function __construct($p) { $this->payload = $p; parent::__construct('json-exit'); }
}
function current_time($f) { return date('Y-m-d H:i:s'); }
function wp_json_encode($d) { return json_encode($d, JSON_UNESCAPED_UNICODE); }
function wp_generate_password($l, $s = true) { return substr(str_repeat('k7x9q2', 8), 0, $l); }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string) $v); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $s)); }
function sanitize_title($s) { return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $s)); }
function esc_url_raw($u) { return $u; }
function esc_url($u) { return $u; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function wp_kses_post($s) { return $s; }
function selected($a, $b) { echo $a == $b ? 'selected' : ''; }
function checked($a) { echo $a ? 'checked' : ''; }
function current_user_can(...$a) { return true; }
function get_current_user_id() { return 1; }
function set_transient($k, $v, $e = 0) { $GLOBALS['aipe_options']['_tr_' . $k] = $v; }
function get_transient($k) { return $GLOBALS['aipe_options']['_tr_' . $k] ?? false; }
function delete_transient($k) { unset($GLOBALS['aipe_options']['_tr_' . $k]); }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['aipe_options']) ? $GLOBALS['aipe_options'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['aipe_options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['aipe_options'][$k]); }
function get_post_meta($id, $k, $single = false) {
    $v = $GLOBALS['aipe_meta'][$id][$k] ?? null;
    return $single ? ($v ?? '') : ($v === null ? [] : [$v]);
}
function update_post_meta($id, $k, $v) { $GLOBALS['aipe_meta'][$id][$k] = $v; }
function delete_post_meta($id, $k) { unset($GLOBALS['aipe_meta'][$id][$k]); }
function get_the_title($id) { return $GLOBALS['aipe_attachments'][$id]['title'] ?? 'img'; }
function get_attached_file($id) { return $GLOBALS['aipe_attachments'][$id]['file'] ?? false; }
function wp_get_attachment_url($id) { return 'http://local.test/img-' . $id . '.jpg'; }
function get_term($id) {
    $t = $GLOBALS['aipe_terms'][$id] ?? null;
    if (!$t) return false;
    return (object) ['term_id' => $id, 'name' => $t['name'], 'slug' => $t['slug'], 'taxonomy' => $t['taxonomy']];
}
function wp_update_term($id, $tax, $args) {
    if (isset($args['name'])) $GLOBALS['aipe_terms'][$id]['name'] = $args['name'];
    return ['term_id' => $id];
}

// ---------- HTTP（剧本式） ----------
function aipe_plan_http($match, $response) { $GLOBALS['aipe_http_plan'][] = [$match, $response]; }
function aipe_http_count() { return count($GLOBALS['aipe_http_log']); }
function aipe_http_last_body() {
    $log = $GLOBALS['aipe_http_log'];
    $e = end($log);
    return $e ? json_decode($e['body'], true) : null;
}
function wp_remote_post($url, $args = []) {
    $GLOBALS['aipe_http_log'][] = ['url' => $url, 'body' => $args['body'] ?? ''];
    foreach ($GLOBALS['aipe_http_plan'] as $p) {
        if (strpos($url, $p[0]) !== false) {
            $r = $p[1];
            return is_callable($r) ? $r($url, $args) : $r;
        }
    }
    return new WP_Error('no_plan', 'no http plan for ' . $url);
}
function wp_remote_get($url, $args = []) {
    $GLOBALS['aipe_http_log'][] = ['url' => $url, 'body' => ''];
    return ['response' => ['code' => 200], 'body' => 'BINARY'];
}
function wp_remote_retrieve_response_code($r) { return is_wp_error($r) ? 0 : ($r['response']['code'] ?? 200); }
function wp_remote_retrieve_body($r) { return is_wp_error($r) ? '' : ($r['body'] ?? ''); }
function aipe_json_resp($arr, $code = 200) {
    return ['response' => ['code' => $code], 'body' => json_encode($arr, JSON_UNESCAPED_UNICODE)];
}

// ---------- 媒体入库 ----------
function wp_upload_bits($name, $a, $bits) {
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, $bits);
    $GLOBALS['aipe_uploads'][] = $path;
    return ['file' => $path, 'error' => false];
}
function wp_check_filetype($f) { return ['type' => 'image/png']; }
function wp_insert_attachment($att, $file) {
    $id = $GLOBALS['aipe_next_att']++;
    $GLOBALS['aipe_attachments'][$id] = ['file' => $file, 'title' => $att['post_title'] ?? ''];
    return $id;
}
function wp_generate_attachment_metadata($id, $file) { return []; }
function wp_update_attachment_metadata($id, $m) {}

// ---------- 调度 ----------
function as_enqueue_async_action($hook, $args = []) { $GLOBALS['aipe_cron'][] = ['as', $hook, $args]; }
function wp_schedule_single_event($ts, $hook, $args = []) { $GLOBALS['aipe_cron'][] = ['cron', $hook, $args]; }

// ---------- WC ----------
function wc_get_product($id) { return $GLOBALS['aipe_products'][$id] ?? null; }
function wc_attribute_label($key) {
    foreach ($GLOBALS['aipe_products'] as $p) {
        foreach ($p->attrs as $k => $a) {
            if ($k === $key) return $a->label;
        }
    }
    return $key;
}
class WC_Product_Attribute {
    public $label = '';
    public $options = [];
    public $taxonomy = false;
    public function set_name($v) { $this->label = $v; }
    public function get_name() { return $this->label; }
    public function set_options($v) { $this->options = $v; }
    public function get_options() { return $this->options; }
    public function set_visible($v) {}
    public function set_variation($v) {}
    public function is_taxonomy() { return $this->taxonomy; }
}
class AIPE_Fake_Product {
    public $id = 0;
    public $type = 'simple';
    public $name = '';
    public $sku = '';
    public $attrs = [];      // key => WC_Product_Attribute
    public $children = [];
    public $image_id = 0;
    public $gallery = [];
    public $desc = '';
    public function get_id() { return $this->id; }
    public function get_type() { return $this->type; }
    public function is_type($t) { return $this->type === $t; }
    public function get_name() { return $this->name; }
    public function set_name($v) { $this->name = $v; }
    public function get_sku() { return $this->sku; }
    public function get_attributes() { return $this->attrs; }
    public function set_attributes($v) { $this->attrs = $v; }
    public function get_children() { return $this->children; }
    public function get_image_id() { return $this->image_id; }
    public function set_image_id($v) { $this->image_id = $v; }
    public function get_gallery_image_ids() { return $this->gallery; }
    public function set_gallery_image_ids($v) { $this->gallery = array_values($v); }
    public function get_description() { return $this->desc; }
    public function set_description($v) { $this->desc = $v; }
    public function save() { $GLOBALS['aipe_products'][$this->id] = $this; return $this->id; }
}
class AIPE_Fake_Variation extends AIPE_Fake_Product {
    public $type = 'variation';
    public $child_attrs = []; // key => value
    public function get_attributes() { return $this->child_attrs; }
}
class WC_Product_Variable {
    public static function sync($id) { $GLOBALS['aipe_synced'][] = $id; }
}

// ---------- wpdb（aipe 两表内存实现） ----------
class AIPE_Fake_WPDB {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $jobs = [];
    public $cache = [];
    public $attr_labels = []; // attribute_name => label
    public function prepare($q, ...$a) {
        foreach ($a as $v) $q = preg_replace('/%[sd]/', is_int($v) ? (string) $v : "'" . addslashes($v) . "'", $q, 1);
        return $q;
    }
    public function insert($t, $d) {
        if (strpos($t, 'aipe_jobs') !== false) {
            $this->insert_id = count($this->jobs) + 1;
            $d['id'] = $this->insert_id;
            $this->jobs[$this->insert_id] = $d;
            return 1;
        }
        return 0;
    }
    public function replace($t, $d) {
        if (strpos($t, 'aipe_cache') !== false) { $this->cache[$d['cache_key']] = $d; return 1; }
        return 0;
    }
    public function update($t, $d, $w) {
        if (strpos($t, 'aipe_jobs') !== false && isset($w['id'])) {
            foreach ($d as $k => $v) $this->jobs[$w['id']][$k] = $v;
            return 1;
        }
        if (strpos($t, 'woocommerce_attribute_taxonomies') !== false) {
            $this->attr_labels[$w['attribute_name']] = $d['attribute_label'];
            return 1;
        }
        return 0;
    }
    public function get_row($q, $fmt = null) {
        if (strpos($q, 'aipe_jobs') !== false && preg_match('/id = (\d+)/', $q, $m)) {
            $r = $this->jobs[(int) $m[1]] ?? null;
            return $r ? (object) $r : null;
        }
        if (strpos($q, 'aipe_cache') !== false && preg_match("/cache_key = '([^']+)'/", $q, $m)) {
            $r = $this->cache[$m[1]] ?? null;
            if (!$r) return null;
            return ['dst_text' => $r['dst_text'], 'provider' => $r['provider'], 'model' => $r['model']];
        }
        return null;
    }
    public function get_results($q) {
        if (strpos($q, 'aipe_jobs') !== false && preg_match('/product_id = (\d+)/', $q, $m)) {
            $out = [];
            foreach ($this->jobs as $j) {
                if ((int) $j['product_id'] === (int) $m[1]) $out[] = (object) $j;
            }
            return $out;
        }
        return [];
    }
    public function get_var($q) {
        if (strpos($q, 'SHOW TABLES LIKE') !== false) {
            return strpos($q, 'woocommerce_attribute_taxonomies') !== false ? 'wp_woocommerce_attribute_taxonomies' : null;
        }
        return null;
    }
    public function query($q) { return 1; }
}
$GLOBALS['wpdb'] = new AIPE_Fake_WPDB();
