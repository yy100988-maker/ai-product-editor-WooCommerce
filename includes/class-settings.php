<?php
/**
 * 设置：供应商（OpenAI 协议）+ 语言 + 术语库 + 图片输出。Key 存 wp_options（autoload=false），页面上脱敏显示。
 *
 * @package AIPE
 */

defined('ABSPATH') || exit;

class AIPE_Settings {

    const OPT = 'aipe_settings';

    /**
     * 出厂默认：SiliconFlow 主 + OpenRouter 备（与 SaaS providers.yml 同源，只是 Key 改由本站填写）。
     */
    public static function defaults() {
        return [
            'source_lang' => 'zh',
            'target_lang' => 'en',
            'fallback_order' => ['siliconflow', 'openrouter'],
            'providers' => [
                'siliconflow' => [
                    'label' => 'SiliconFlow（主）',
                    'base_url' => 'https://api.siliconflow.cn/v1',
                    'api_key' => '',
                    'timeout_s' => 60,
                    'models' => [
                        'chat' => 'Qwen/Qwen3-8B',
                        'vision' => 'Qwen/Qwen2.5-VL-72B-Instruct',
                        'image' => 'Kwai-Kolors/Kolors',
                    ],
                ],
                'openrouter' => [
                    'label' => 'OpenRouter（备）',
                    'base_url' => 'https://openrouter.ai/api/v1',
                    'api_key' => '',
                    'timeout_s' => 90,
                    'models' => [
                        'chat' => 'qwen/qwen3-32b:free',
                        'vision' => 'qwen/qwen2.5-vl-72b-instruct:free',
                        'image' => '',
                    ],
                ],
            ],
            // 术语库：一行一条 "中文=English"，翻译时强制使用（标题/属性词/图内文字通用）。
            'glossary' => "连衣裙=Dress\n真丝=Mulberry Silk\n包邮=Free Shipping",
            'image' => [
                'max_width' => 1600,   // 输出图最长边上限，0=不缩放
                'font_path' => '',     // 留空则自动探测系统字体
                'text_color' => 'auto',// auto=按底色自动黑/白；或填 #RRGGBB
                'jpeg_quality' => 88,
            ],
        ];
    }

    public static function get() {
        $d = self::defaults();
        $s = (array) get_option(self::OPT, []);
        // 深合并一层（providers 按 id 合并，避免升级丢 key）。
        foreach ($d['providers'] as $id => $p) {
            if (isset($s['providers'][$id]) && is_array($s['providers'][$id])) {
                $s['providers'][$id] = array_merge($p, $s['providers'][$id]);
                $s['providers'][$id]['models'] = array_merge($p['models'], (array) $s['providers'][$id]['models']);
            }
        }
        return array_merge($d, $s);
    }

    /**
     * 保存设置页表单（含 Key 脱敏回填：输入 "***" 表示不改）。
     */
    public static function save_from_post($post) {
        $s = self::get();
        $s['target_lang'] = sanitize_text_field($post['target_lang'] ?? 'en') ?: 'en';
        $s['glossary'] = self::sanitize_glossary($post['glossary'] ?? '');

        $order = [];
        foreach (array_keys($s['providers']) as $id) {
            $p = $s['providers'][$id];
            if (isset($post['providers'][$id])) {
                $in = $post['providers'][$id];
                $p['base_url'] = esc_url_raw(trim($in['base_url'] ?? $p['base_url']));
                $key = trim($in['api_key'] ?? '');
                if ($key !== '' && $key !== '***') {
                    $p['api_key'] = $key;
                }
                $p['timeout_s'] = max(10, (int) ($in['timeout_s'] ?? $p['timeout_s']));
                foreach (['chat', 'vision', 'image'] as $m) {
                    if (isset($in['models'][$m])) {
                        $p['models'][$m] = sanitize_text_field($in['models'][$m]);
                    }
                }
            }
            $s['providers'][$id] = $p;
            if (!empty($post['enabled_providers'][$id])) {
                $order[] = $id;
            }
        }
        $s['fallback_order'] = $order ?: array_keys($s['providers']);

        $img = $post['image'] ?? [];
        $s['image']['max_width'] = max(0, (int) ($img['max_width'] ?? $s['image']['max_width']));
        $s['image']['font_path'] = sanitize_text_field($img['font_path'] ?? '');
        $c = sanitize_text_field($img['text_color'] ?? 'auto');
        $s['image']['text_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : 'auto';
        $s['image']['jpeg_quality'] = min(100, max(60, (int) ($img['jpeg_quality'] ?? 88)));

        update_option(self::OPT, $s, false);
        return $s;
    }

    public static function sanitize_glossary($raw) {
        $lines = preg_split('/\r?\n/', (string) $raw);
        $out = [];
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '' || strpos($ln, '=') === false) {
                continue;
            }
            list($cn, $en) = array_map('trim', explode('=', $ln, 2));
            if ($cn !== '' && $en !== '') {
                $out[] = $cn . '=' . $en;
            }
        }
        return implode("\n", $out);
    }

    /**
     * 解析术语库为 ['中文' => 'English']。
     */
    public static function glossary_map($settings = null) {
        $s = $settings ?: self::get();
        $map = [];
        foreach (preg_split('/\r?\n/', (string) $s['glossary']) as $ln) {
            $ln = trim($ln);
            if ($ln === '' || strpos($ln, '=') === false) {
                continue;
            }
            list($cn, $en) = array_map('trim', explode('=', $ln, 2));
            if ($cn !== '' && $en !== '') {
                $map[$cn] = $en;
            }
        }
        return $map;
    }

    /**
     * 按 fallback 顺序返回可用的 provider 路由（有 key 的优先；无 key 也返回，调用时报 NO_KEY）。
     */
    public static function routes($settings = null, $need = 'chat') {
        $s = $settings ?: self::get();
        $routes = [];
        foreach ((array) $s['fallback_order'] as $id) {
            if (!isset($s['providers'][$id])) {
                continue;
            }
            $p = $s['providers'][$id];
            if (empty($p['models'][$need])) {
                continue; // 该供应商没配这类模型则跳过（如 openrouter 没配 image）
            }
            $routes[] = [
                'id' => $id,
                'label' => $p['label'],
                'base_url' => rtrim($p['base_url'], '/'),
                'api_key' => $p['api_key'],
                'timeout' => (int) $p['timeout_s'],
                'model' => $p['models'][$need],
            ];
        }
        return $routes;
    }
}
