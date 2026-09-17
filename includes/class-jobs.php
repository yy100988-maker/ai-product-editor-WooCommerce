<?php
/**
 * 异步任务：aipe_jobs 表 + Action Scheduler（有则用）/ WP-Cron 单次事件兜底。
 * kind: image_translate | image_generate | image_edit
 *
 * @package AIPE
 */

defined('ABSPATH') || exit;

class AIPE_Jobs {

    public static function init() {
        // 兼容老式主机：Action Scheduler 不可用时，用 WP-Cron 单次事件触发 aipe_run_job。
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'aipe_jobs';
    }

    public static function create($product_id, $kind, $payload) {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert(self::table(), [
            'product_id' => (int) $product_id,
            'kind' => $kind,
            'status' => 'queued',
            'payload' => wp_json_encode($payload),
            'result' => null,
            'error_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id));
    }

    public static function for_product($product_id, $limit = 20) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE product_id = %d ORDER BY id DESC LIMIT %d',
            (int) $product_id, (int) $limit
        ));
    }

    public static function set_status($id, $status, $error_code = null, $result = null) {
        global $wpdb;
        $data = ['status' => $status, 'updated_at' => current_time('mysql')];
        if ($error_code !== null) {
            $data['error_code'] = $error_code;
        }
        if ($result !== null) {
            $data['result'] = is_string($result) ? $result : wp_json_encode($result);
        }
        $wpdb->update(self::table(), $data, ['id' => (int) $id]);
    }

    /**
     * 分发：优先 Action Scheduler（Woo 自带），否则 WP-Cron 单次事件（10 秒后，给当前请求先返回）。
     */
    public static function dispatch($job_id) {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('aipe_run_job', ['job_id' => (int) $job_id]);
        } else {
            wp_schedule_single_event(time() + 10, 'aipe_run_job', [(int) $job_id]);
            // 兜底再加一道：有些主机 WP-Cron 残废，spawn 一下提高触发率。
            if (function_exists('spawn_cron')) {
                add_action('shutdown', 'spawn_cron');
            }
        }
    }

    /**
     * 执行（幂等：只有 queued 才跑；跑完置 done/failed）。
     */
    public static function run($job_id) {
        $job = self::get($job_id);
        if (!$job || $job->status !== 'queued') {
            return;
        }
        self::set_status($job->id, 'running');
        try {
            $payload = json_decode((string) $job->payload, true) ?: [];
            $settings = AIPE_Settings::get();
            $out = self::execute($job->kind, (int) $job->product_id, $payload, $settings);
            self::set_status($job->id, 'done', null, $out);
        } catch (Exception $e) {
            $code = explode(':', $e->getMessage())[0];
            self::set_status($job->id, 'failed', $code ?: 'AIPE_FAILED');
        }
    }

    /**
     * 纯执行逻辑（payload['items'] 可携带预览结果，image_translate 会优先使用）。
     */
    public static function execute($kind, $product_id, $payload, $settings) {
        switch ($kind) {
            case 'image_translate': {
                $new_id = AIPE_ImageTrans::run((int) $payload['attachment_id'], $settings, $payload);
                $applied = !empty($payload['auto_apply'])
                    ? AIPE_Product::attach_image($product_id, $new_id, $payload['slot'] ?? 'gallery', (int) $payload['attachment_id'])
                    : false;
                return ['attachment_id' => $new_id, 'url' => wp_get_attachment_url($new_id), 'applied' => $applied];
            }
            case 'image_generate': {
                $tpls = AIPE_ImageGen::templates();
                $tpl = $tpls[$payload['template']] ?? null;
                if (!$tpl) {
                    throw new Exception('AIPE_BAD_TEMPLATE');
                }
                $prompt = str_replace('{title}', $payload['title_en'] ?? '', $payload['custom_prompt'] ?? $tpl['prompt']);
                $new_id = AIPE_ImageGen::generate($prompt, $tpl['size'], $payload['title_en'] ?? 'product', $settings);
                $applied = !empty($payload['auto_apply'])
                    ? AIPE_Product::attach_image($product_id, $new_id, $payload['slot'] ?? 'gallery')
                    : false;
                return ['attachment_id' => $new_id, 'url' => wp_get_attachment_url($new_id), 'applied' => $applied];
            }
            case 'image_edit': {
                $tpls = AIPE_ImageGen::templates();
                $tpl = $tpls['redraw'];
                $new_id = AIPE_ImageGen::edit(
                    (int) $payload['attachment_id'],
                    $payload['custom_prompt'] ?? $tpl['prompt'],
                    $tpl['size'],
                    $payload['title_en'] ?? 'product',
                    $settings
                );
                $applied = !empty($payload['auto_apply'])
                    ? AIPE_Product::attach_image($product_id, $new_id, $payload['slot'] ?? 'gallery', (int) $payload['attachment_id'])
                    : false;
                return ['attachment_id' => $new_id, 'url' => wp_get_attachment_url($new_id), 'applied' => $applied];
            }
            default:
                throw new Exception('AIPE_BAD_KIND');
        }
    }
}
