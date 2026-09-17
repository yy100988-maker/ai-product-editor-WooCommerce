<?php
// 卸载：只删插件自己的表和设置，不删商品和媒体（与 dxm-importer 同策略）。
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aipe_jobs");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aipe_cache");
delete_option('aipe_settings');
