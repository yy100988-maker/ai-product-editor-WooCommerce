<?php
/**
 * 文本 LLM 翻译：标题 / 属性名 / 属性值。术语库优先（原文命中直接替换），其余走 chat，aipe_cache 表缓存。
 * SKU 编码本身永不动（调用方保证只传属性词）。
 *
 * @package AIPE
 */

defined('ABSPATH') || exit;

class AIPE_Translate {

    const RULES = 'Translate faithfully; do not invent sizes, materials or numbers. '
        . 'Keep brand names, model numbers, digits and units as-is (cm stays cm). '
        . 'Title: e-commerce style, <=140 chars, no emoji. '
        . 'Flag prohibited words (fake/replica/counterfeit) with prefix "[REVIEW] ".';

    /**
     * 标题翻译（同步，metabox 预览用）。
     */
    public static function title($text, $settings = null) {
        $text = trim((string) $text);
        if ($text === '') {
            return ['text' => '', 'cached' => true, 'provider' => '', 'model' => ''];
        }
        return self::segment($settings ?: AIPE_Settings::get(), 'title', $text);
    }

    /**
     * 属性名/属性值批量翻译：输入 ['红色','蓝色']，返回 [原文 => 译文]。
     * 纯 ASCII（已是英文/编码）直接原样返回，不调模型不花钱。
     */
    public static function terms(array $terms, $settings = null) {
        $s = $settings ?: AIPE_Settings::get();
        $out = [];
        $todo = [];
        foreach ($terms as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            if (!preg_match('/[\x{4e00}-\x{9fff}]/u', $t)) {
                $out[$t] = $t; // 无中文：英文/数字/编码，原样
                continue;
            }
            $todo[] = $t;
        }
        if ($todo) {
            // 打包一次请求："CN=EN" 一行一条，省调用次数。
            $joined = implode("\n", array_unique($todo));
            $r = self::segment($s, 'attrs', $joined);
            $map = self::parse_pairs($joined, $r['text']);
            foreach ($todo as $t) {
                $out[$t] = $map[$t] ?? $t;
            }
            // 透出最后一次调用的来源（缓存/供应商）。
            $out['_meta'] = ['cached' => $r['cached'], 'provider' => $r['provider'], 'model' => $r['model']];
        } else {
            $out['_meta'] = ['cached' => true, 'provider' => '', 'model' => ''];
        }
        return $out;
    }

    /**
     * 通用单段翻译（图内文字也走这里，保证术语库/缓存一致）。
     */
    public static function text($text, $settings = null) {
        return self::segment($settings ?: AIPE_Settings::get(), 'text', (string) $text);
    }

    // ---------- 内部 ----------

    protected static function segment($s, $kind, $text) {
        $glossary = AIPE_Settings::glossary_map($s);
        // 术语库整段命中：直接替换，不调模型。
        $pre = self::apply_glossary($text, $glossary);
        $key = self::cache_key($s, $kind, $text);
        $hit = self::cache_get($key);
        if ($hit) {
            return ['text' => $hit['dst_text'], 'cached' => true, 'provider' => $hit['provider'], 'model' => $hit['model']];
        }
        $task = $kind === 'title'
            ? 'Translate this Chinese product title to ' . $s['target_lang'] . '.'
            : ($kind === 'attrs'
                ? 'Translate these Chinese attribute values to ' . $s['target_lang'] . ', one per line as "CN=EN".'
                : 'Translate this Chinese text to ' . $s['target_lang'] . '.');
        $hint = $glossary ? 'Use these fixed terms: ' . wp_json_encode($glossary) . '.' : '';
        $messages = [
            ['role' => 'system', 'content' => "You are an e-commerce CN->{$s['target_lang']} translator.\n" . self::RULES . "\n" . $hint],
            ['role' => 'user', 'content' => $task . "\n\n" . $pre],
        ];
        $r = AIPE_Client::chat($s, $messages, 'chat');
        $clean = self::post_clean($r['text']);
        self::cache_put($key, $kind, $text, $clean, $r['provider'], $r['model']);
        return ['text' => $clean, 'cached' => false, 'provider' => $r['provider'], 'model' => $r['model']];
    }

    /**
     * 术语库预替换：原文中的中文词直接换成英文。长词优先，避免短词先替换破坏长词。
     */
    public static function apply_glossary($text, $glossary) {
        if (!$glossary) {
            return $text;
        }
        uksort($glossary, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });
        return str_replace(array_keys($glossary), array_values($glossary), $text);
    }

    /**
     * 解析模型返回的 "CN=EN" 行，键必须出自原文行（防模型幻觉添油加醋）。
     */
    public static function parse_pairs($src_joined, $dst_text) {
        $allowed = [];
        foreach (preg_split('/\r?\n/', $src_joined) as $ln) {
            $ln = trim($ln);
            if ($ln !== '') {
                $allowed[$ln] = true;
            }
        }
        $map = [];
        foreach (preg_split('/\r?\n/', (string) $dst_text) as $ln) {
            $ln = trim($ln);
            if ($ln === '' || strpos($ln, '=') === false) {
                continue;
            }
            list($cn, $en) = array_map('trim', explode('=', $ln, 2));
            if ($cn !== '' && $en !== '' && isset($allowed[$cn])) {
                $map[$cn] = $en;
            }
        }
        return $map;
    }

    protected static function post_clean($text) {
        $text = trim($text);
        // 去掉模型可能加的代码围栏。
        $text = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text);
        return trim($text);
    }

    public static function cache_key($s, $kind, $text) {
        return 'aipe_' . sha1(AIPE_Client::PROMPT_VERSION . '|' . $s['target_lang'] . '|' . md5((string) $s['glossary']) . '|' . $kind . '|' . $text);
    }

    protected static function cache_get($key) {
        global $wpdb;
        $t = $wpdb->prefix . 'aipe_cache';
        $row = $wpdb->get_row($wpdb->prepare("SELECT dst_text, provider, model FROM {$t} WHERE cache_key = %s", $key), ARRAY_A);
        return $row ?: null;
    }

    protected static function cache_put($key, $kind, $src, $dst, $provider, $model) {
        global $wpdb;
        $t = $wpdb->prefix . 'aipe_cache';
        $wpdb->replace($t, [
            'cache_key' => $key,
            'kind' => $kind,
            'src_text' => $src,
            'dst_text' => $dst,
            'provider' => $provider,
            'model' => $model,
            'created_at' => current_time('mysql'),
        ]);
    }
}
