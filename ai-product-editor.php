<?php
/**
 * Plugin Name: AI 产品编辑器 (AI Product Editor for WooCommerce)
 * Description: 导入后在商品编辑页直接做 AI 编辑：标题/属性词 LLM 翻译（SKU 编码不动）、图片 AI 翻译（图内文字擦除重绘）、图片 AI 生成。Key 直连供应商，不经过 SaaS。
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: vtetech
 * License: GPL-2.0-or-later
 * Text Domain: aipe
 * Domain Path: /languages
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 *
 * @package AIPE
 */

defined('ABSPATH') || exit;

define('AIPE_VER', '0.1.0');
define('AIPE_DIR', plugin_dir_path(__FILE__));
define('AIPE_URL', plugin_dir_url(__FILE__));
define('AIPE_FILE', __FILE__);

require_once AIPE_DIR . 'includes/class-settings.php';
require_once AIPE_DIR . 'includes/class-client.php';
require_once AIPE_DIR . 'includes/class-translate.php';
require_once AIPE_DIR . 'includes/class-imagetrans.php';
require_once AIPE_DIR . 'includes/class-imagegen.php';
require_once AIPE_DIR . 'includes/class-jobs.php';
require_once AIPE_DIR . 'includes/class-product.php';
require_once AIPE_DIR . 'includes/class-admin.php';

/**
 * 激活：建任务表 + 翻译缓存表（幂等）。
 */
register_activation_hook(__FILE__, function () {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    $jobs = $wpdb->prefix . 'aipe_jobs';
    dbDelta("CREATE TABLE {$jobs} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        kind VARCHAR(32) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT 'queued',
        payload LONGTEXT,
        result LONGTEXT,
        error_code VARCHAR(64) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY product_id (product_id),
        KEY status (status)
    ) {$charset};");

    $cache = $wpdb->prefix . 'aipe_cache';
    dbDelta("CREATE TABLE {$cache} (
        cache_key CHAR(64) NOT NULL,
        kind VARCHAR(16) NOT NULL DEFAULT 'text',
        src_text MEDIUMTEXT,
        dst_text MEDIUMTEXT,
        provider VARCHAR(64) DEFAULT NULL,
        model VARCHAR(128) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (cache_key),
        KEY created_at (created_at)
    ) {$charset};");
});

/**
 * 声明与 WooCommerce HPOS 及区块结账兼容。
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', AIPE_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', AIPE_FILE, true);
    }
});

add_action('plugins_loaded', function () {
    load_plugin_textdomain('aipe', false, dirname(plugin_basename(__FILE__)) . '/languages');
    AIPE_Jobs::init();
    if (is_admin()) {
        AIPE_Admin::init();
    }
});

// 异步任务执行：Action Scheduler 动作名 aipe_run_job；无 AS 时用 WP-Cron 单次事件兜底（见 AIPE_Jobs::dispatch）。
add_action('aipe_run_job', function ($job_id) {
    AIPE_Jobs::run((int) $job_id);
}, 10, 1);
