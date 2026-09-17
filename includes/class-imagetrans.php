<?php
/**
 * 图片 AI 翻译（管线对标 MoeTranslate v5.2.0，适配 PHP/GD 与 Vision 模型）。
 *
 * MoeTranslate: 检测(ML Kit OCR 给框) → 文本翻译(多 API) → 悬浮窗覆盖显示。
 * 本插件:      检测(Vision 模型给 0-1000 框) → 文本管线翻译(术语库+缓存) → GD 擦除重绘入库。
 *
 * 借鉴点：
 *  1) OCR 与翻译解耦（MoeTranslate 本地 OCR + 自选翻译 API；这里 vision 只做框+原文，译文走统一文本管线）；
 *  2) 框后处理：同行合并 + 阅读顺序排序（对应 ML Kit block/line 结构）；
 *  3) OCR 结果按文件 md5 缓存（对应离线复用思想，省 vision 调用）；
 *  4) 先覆盖预览、确认再重绘入库（对应悬浮窗先看译文再决定）；
 *  5) 擦除框外扩 + 译文描边（弥补 PHP 侧没有 LaMa inpainting，保可读性）。
 *
 * 原图永不动：输出 `-ai-{lang}.jpg` 新附件，记 `_aipe_source_attachment`。
 *
 * @package AIPE
 */

defined('ABSPATH') || exit;

class AIPE_ImageTrans {

    /**
     * 入口（异步任务里调）：$attachment_id → 新附件 ID。无文字时抛 AIPE_NO_TEXT。
     * $payload['items'] 可携带预览结果（[{box(0-1000), dst}]），命中则跳过 OCR+翻译。
     */
    public static function run($attachment_id, $settings = null, $payload = []) {
        $s = $settings ?: AIPE_Settings::get();
        if (!extension_loaded('gd')) {
            throw new Exception('AIPE_NO_GD');
        }
        $file = get_attached_file((int) $attachment_id);
        if (!$file || !file_exists($file)) {
            throw new Exception('AIPE_FILE_MISSING');
        }
        $info = @getimagesize($file);
        if (!$info) {
            throw new Exception('AIPE_BAD_IMAGE');
        }
        list($w, $h) = [$info[0], $info[1]];

        // 百度回填优先：有官方贴合图时直接入库，跳过 GD 重绘。
        if (($s['img_source'] ?? 'vision') === 'baidu' && !empty($s['imgapi']['prefer_paste'])) {
            $paste = AIPE_ImgApi::paste_for_file($file);
            if ($paste !== null) {
                $pid2 = AIPE_ImageGen::sideload_bytes($paste, get_the_title((int) $attachment_id) . ' (百度回填)', 'image_translate', ['provider' => 'baidu', 'model' => 'sdk-picture']);
                update_post_meta($pid2, '_aipe_source_attachment', (int) $attachment_id);
                return $pid2;
            }
        }

        $items = self::items_for_job((int) $attachment_id, (array) $payload, $s, $w, $h);
        $out = self::temp_path($file, $s['target_lang']);
        self::redraw($file, $out, $items, $s['image']);
        $new_id = self::sideload($out, (int) $attachment_id, $s['target_lang']);
        @unlink($out);
        return $new_id;
    }

    // ---------- OCR ----------

    /**
     * 带缓存的 OCR：同一文件 md5 命中 aipe_cache（kind=ocr）即不调 vision 模型。
     * 返回 normalize 后的 boxes。
     */
    public static function ocr_cached($file, $s) {
        $key = 'aipe_ocr_' . md5_file($file);
        $hit = self::cache_get($key);
        if ($hit) {
            $boxes = json_decode($hit['dst_text'], true);
            if (is_array($boxes)) {
                return $boxes;
            }
        }
        $boxes = self::normalize_boxes(self::ocr($file, $s));
        self::cache_put($key, 'ocr', $file, wp_json_encode($boxes), '', '');
        return $boxes;
    }

    /**
     * 预览用：OCR + 文本管线翻译，返回 [{box, src, dst}]（0-1000 坐标），不写图。
     * metabox 把它画成覆盖层气泡，确认后才进任务重绘入库。
     */
    public static function preview($attachment_id, $settings = null) {
        $s = $settings ?: AIPE_Settings::get();
        $file = get_attached_file((int) $attachment_id);
        if (!$file || !file_exists($file)) {
            throw new Exception('AIPE_FILE_MISSING');
        }
        if (($s['img_source'] ?? 'vision') === 'baidu') {
            $d = AIPE_ImgApi::detect_cached($file, $s);
            if (!$d['boxes']) {
                throw new Exception('AIPE_NO_TEXT');
            }
            $out = [];
            foreach ($d['boxes'] as $b) {
                $out[] = ['box' => $b['box'], 'src' => $b['src'], 'dst' => $b['dst'], 'cached' => $d['hit']];
            }
            return $out;
        }
        $boxes = self::ocr_cached($file, $s);
        if (!$boxes) {
            throw new Exception('AIPE_NO_TEXT');
        }
        $out = [];
        foreach ($boxes as $b) {
            $r = AIPE_Translate::text($b['src'], $s);
            $out[] = ['box' => $b['box'], 'src' => $b['src'], 'dst' => $r['text'], 'cached' => $r['cached']];
        }
        return $out;
    }

    /**
     * 任务执行用：payload 自带 items（预览结果）则直接用，省一次 vision 调用；
     * 否则走完整 OCR+翻译。返回 [{rect(px), dst}]。
     */
    public static function items_for_job($attachment_id, $payload, $s, $img_w, $img_h) {
        if (!empty($payload['items']) && is_array($payload['items'])) {
            $items = [];
            foreach ($payload['items'] as $it) {
                $box = $it['box'] ?? null;
                $dst = trim((string) ($it['dst'] ?? ''));
                if (!is_array($box) || count($box) !== 4 || $dst === '') {
                    continue;
                }
                $items[] = ['rect' => self::scale_box(array_map('floatval', $box), $img_w, $img_h), 'dst' => $dst];
            }
            if ($items) {
                return $items;
            }
        }
        $file = get_attached_file((int) $attachment_id);
        if (!$file || !file_exists($file)) {
            throw new Exception('AIPE_FILE_MISSING');
        }
        if (($s['img_source'] ?? 'vision') === 'baidu') {
            $d = AIPE_ImgApi::detect_cached($file, $s);
            if (!$d['boxes']) {
                throw new Exception('AIPE_NO_TEXT');
            }
            $items = [];
            foreach ($d['boxes'] as $b) {
                $items[] = ['rect' => self::scale_box($b['box'], $img_w, $img_h), 'dst' => $b['dst']];
            }
            return $items;
        }
        $boxes = self::ocr_cached($file, $s);
        if (!$boxes) {
            throw new Exception('AIPE_NO_TEXT');
        }
        $items = [];
        foreach ($boxes as $b) {
            $r = AIPE_Translate::text($b['src'], $s);
            $items[] = ['rect' => self::scale_box($b['box'], $img_w, $img_h), 'dst' => $r['text']];
        }
        return $items;
    }

    public static function ocr($file, $s) {
        $mime = self::mime_of($file);
        $data_url = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($file));
        $messages = [
            ['role' => 'system', 'content' => 'You are an OCR engine. Output STRICT JSON only, no markdown, no explanation.'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' =>
                    'Detect ALL Chinese text regions in this e-commerce product image. '
                    . 'Return {"texts":[{"box":[x,y,w,h],"src":"原文"}]} where box uses 0-1000 relative coordinates '
                    . '(x,y = top-left). Merge characters of the same line into ONE box, reading order. '
                    . 'Ignore watermark-like tiny text under 12px equivalent. If no Chinese text: {"texts":[]}.'],
                ['type' => 'image_url', 'image_url' => ['url' => $data_url, 'detail' => 'high']],
            ]],
        ];
        $r = AIPE_Client::chat($s, $messages, 'vision');
        return self::parse_ocr($r['text']);
    }

    /**
     * 解析 OCR JSON：只收 box 为 4 个 0-1000 数字、src 非空的条目。
     */
    public static function parse_ocr($text) {
        $text = trim((string) $text);
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $text, $m)) {
            $text = $m[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        $j = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!is_array($j) || empty($j['texts']) || !is_array($j['texts'])) {
            return [];
        }
        $out = [];
        foreach ($j['texts'] as $t) {
            $box = $t['box'] ?? null;
            $src = trim((string) ($t['src'] ?? ''));
            if (!is_array($box) || count($box) !== 4 || $src === '') {
                continue;
            }
            $box = array_map('floatval', $box);
            if ($box[2] <= 0 || $box[3] <= 0) {
                continue;
            }
            $out[] = ['box' => $box, 'src' => $src];
        }
        return $out;
    }

    /**
     * 框后处理（对标 ML Kit 的 block/line 结构）：
     *  1) 丢极小框（0-1000 坐标下 w<20 且 h<14）；
     *  2) 同行（垂直重叠>50%）且横向间距<40 的框合并，原文直接拼接；
     *  3) 按先上后下、先左后右排序。
     */
    public static function normalize_boxes($boxes) {
        $kept = [];
        foreach ((array) $boxes as $b) {
            if (!isset($b['box']) || !is_array($b['box']) || count($b['box']) !== 4) {
                continue;
            }
            $box = array_map('floatval', $b['box']);
            $src = trim((string) ($b['src'] ?? ''));
            if ($src === '' || $box[2] <= 0 || $box[3] <= 0) {
                continue;
            }
            if ($box[2] < 20 && $box[3] < 14) {
                continue;
            }
            $kept[] = ['box' => $box, 'src' => $src, 'dst' => isset($b['dst']) ? trim((string) $b['dst']) : null];
        }
        usort($kept, function ($a, $b) {
            if (abs($a['box'][1] - $b['box'][1]) > 10) {
                return $a['box'][1] < $b['box'][1] ? -1 : 1;
            }
            return $a['box'][0] < $b['box'][0] ? -1 : 1;
        });
        // 同行合并
        $merged = [];
        foreach ($kept as $b) {
            $n = count($merged);
            if ($n > 0) {
                $last = &$merged[$n - 1];
                $y_overlap = min($last['box'][1] + $last['box'][3], $b['box'][1] + $b['box'][3])
                    - max($last['box'][1], $b['box'][1]);
                $min_h = min($last['box'][3], $b['box'][3]);
                $gap = $b['box'][0] - ($last['box'][0] + $last['box'][2]);
                if ($min_h > 0 && $y_overlap / $min_h > 0.5 && $gap < 40 && $gap > -20) {
                    $x1 = min($last['box'][0], $b['box'][0]);
                    $y1 = min($last['box'][1], $b['box'][1]);
                    $x2 = max($last['box'][0] + $last['box'][2], $b['box'][0] + $b['box'][2]);
                    $y2 = max($last['box'][1] + $last['box'][3], $b['box'][1] + $b['box'][3]);
                    $last['box'] = [$x1, $y1, $x2 - $x1, $y2 - $y1];
                    $last['src'] .= $b['src'];
                    if ($last['dst'] !== null && $b['dst'] !== null && $b['dst'] !== '') {
                        $ascii = !preg_match('/[\x{4e00}-\x{9fff}]/u', $last['dst']) && !preg_match('/[\x{4e00}-\x{9fff}]/u', $b['dst']);
                        $last['dst'] .= ($ascii ? ' ' : '') . $b['dst'];
                    } elseif ($last['dst'] === null) {
                        $last['dst'] = $b['dst'];
                    }
                    unset($last);
                    continue;
                }
                unset($last);
            }
            $merged[] = $b;
        }
        return array_values($merged);
    }

    public static function scale_box($box1000, $img_w, $img_h) {
        return [
            'x' => (int) round($box1000[0] / 1000 * $img_w),
            'y' => (int) round($box1000[1] / 1000 * $img_h),
            'w' => max(1, (int) round($box1000[2] / 1000 * $img_w)),
            'h' => max(1, (int) round($box1000[3] / 1000 * $img_h)),
        ];
    }

    // ---------- 重绘 ----------

    /**
     * @param array $items 每项 ['rect'=>['x','y','w','h'], 'dst'=>string]
     */
    public static function redraw($src_path, $dst_path, $items, $img_cfg) {
        $im = self::load($src_path);
        if (!$im) {
            throw new Exception('AIPE_BAD_IMAGE');
        }
        $font = self::resolve_font($img_cfg);
        foreach ($items as $it) {
            $r = $it['rect'];
            // clamp 到画布内
            $W = imagesx($im);
            $H = imagesy($im);
            $r['x'] = max(0, min($r['x'], $W - 1));
            $r['y'] = max(0, min($r['y'], $H - 1));
            $r['w'] = max(1, min($r['w'], $W - $r['x']));
            $r['h'] = max(1, min($r['h'], $H - $r['y']));
            // 外扩（mask dilation 思想）：盖住描边/阴影残留
            $pad = max(2, (int) round(min($r['w'], $r['h']) * 0.08));
            $r['x'] = max(0, $r['x'] - $pad);
            $r['y'] = max(0, $r['y'] - $pad);
            $r['w'] = min($W - $r['x'], $r['w'] + $pad * 2);
            $r['h'] = min($H - $r['y'], $r['h'] + $pad * 2);
            $bg = self::edge_average($im, $r);
            $bg_c = imagecolorallocate($im, $bg[0], $bg[1], $bg[2]);
            imagefilledrectangle($im, $r['x'], $r['y'], $r['x'] + $r['w'], $r['y'] + $r['h'], $bg_c);
            $fg = self::ink_color($img_cfg, $bg);
            $fg_c = imagecolorallocate($im, $fg[0], $fg[1], $fg[2]);
            self::render_fit($im, $r, (string) $it['dst'], $font, $fg_c, $bg_c);
        }
        $max = (int) ($img_cfg['max_width'] ?? 0);
        if ($max > 0 && (imagesx($im) > $max || imagesy($im) > $max)) {
            $im = self::shrink($im, $max);
        }
        $q = (int) ($img_cfg['jpeg_quality'] ?? 88);
        imagejpeg($im, $dst_path, $q);
        imagedestroy($im);
    }

    protected static function load($path) {
        $mime = self::mime_of($path);
        if ($mime === 'image/png') {
            return @imagecreatefrompng($path);
        }
        if ($mime === 'image/webp') {
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
        }
        return @imagecreatefromjpeg($path);
    }

    protected static function mime_of($path) {
        $info = @getimagesize($path);
        return $info['mime'] ?? 'image/jpeg';
    }

    /**
     * 矩形外沿采样取底色（避开文字本身）。
     */
    public static function edge_average($im, $r, $n = 12) {
        $W = imagesx($im);
        $H = imagesy($im);
        $pts = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $n === 1 ? 0 : $i / ($n - 1);
            $pts[] = [$r['x'] + (int) round($t * $r['w']), max(0, $r['y'] - 2)];
            $pts[] = [$r['x'] + (int) round($t * $r['w']), min($H - 1, $r['y'] + $r['h'] + 2)];
            $pts[] = [max(0, $r['x'] - 2), $r['y'] + (int) round($t * $r['h'])];
            $pts[] = [min($W - 1, $r['x'] + $r['w'] + 2), $r['y'] + (int) round($t * $r['h'])];
        }
        $sr = $sg = $sb = $c = 0;
        foreach ($pts as $p) {
            $rgb = @imagecolorat($im, $p[0], $p[1]);
            if ($rgb === false) {
                continue;
            }
            $sr += ($rgb >> 16) & 0xFF;
            $sg += ($rgb >> 8) & 0xFF;
            $sb += $rgb & 0xFF;
            $c++;
        }
        if (!$c) {
            return [255, 255, 255];
        }
        return [(int) round($sr / $c), (int) round($sg / $c), (int) round($sb / $c)];
    }

    /**
     * 墨色：auto 按底色亮度选黑/白，否则用配置色。
     */
    public static function ink_color($img_cfg, $bg) {
        $set = trim((string) ($img_cfg['text_color'] ?? 'auto'));
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $set, $m)) {
            return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2))];
        }
        $lum = (0.299 * $bg[0] + 0.587 * $bg[1] + 0.114 * $bg[2]) / 255;
        return $lum > 0.6 ? [34, 34, 34] : [255, 255, 255];
    }

    /**
     * 字体：设置优先 → 系统常见位置 → null（内置点阵字体兜底，只够英文）。
     */
    public static function resolve_font($img_cfg) {
        $set = trim((string) ($img_cfg['font_path'] ?? ''));
        if ($set !== '' && is_readable($set)) {
            return $set;
        }
        $cands = [
            'C:\\Windows\\Fonts\\arial.ttf',
            'C:\\Windows\\Fonts\\msyh.ttc',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
        ];
        foreach ($cands as $c) {
            if (is_readable($c)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * 自适应渲染：从 min(框高, 48) 开始缩小，直到分行后宽高都塞进框。
     * 描边（stroke）：先用底色在 8 个方向各渲一遍，再渲墨色——盖住擦除边缘残留，保可读性。
     */
    protected static function render_fit($im, $r, $text, $font, $color, $bg_color = null) {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        if ($font && function_exists('imagettftext')) {
            $size = max(8, min((int) round($r['h'] * 0.72), 48));
            for (; $size >= 8; $size -= 2) {
                $lines = self::wrap($text, $font, $size, $r['w'] - 4);
                if (!$lines) {
                    continue;
                }
                $lh = (int) round($size * 1.25);
                if (count($lines) * $lh <= $r['h']) {
                    $sw = $bg_color ? max(1, (int) round($size / 14)) : 0;
                    $y = $r['y'] + (int) round(($r['h'] - count($lines) * $lh) / 2) + $size;
                    foreach ($lines as $ln) {
                        $bb = imagettfbbox($size, 0, $font, $ln);
                        $tw = $bb[2] - $bb[0];
                        $x = $r['x'] + (int) round(($r['w'] - $tw) / 2) - $bb[0];
                        if ($sw > 0) {
                            for ($dx = -$sw; $dx <= $sw; $dx++) {
                                for ($dy = -$sw; $dy <= $sw; $dy++) {
                                    if ($dx === 0 && $dy === 0) {
                                        continue;
                                    }
                                    imagettftext($im, $size, 0, $x + $dx, $y + $dy, $bg_color, $font, $ln);
                                }
                            }
                        }
                        imagettftext($im, $size, 0, $x, $y, $color, $font, $ln);
                        $y += $lh;
                    }
                    return;
                }
            }
            // 实在塞不下：用最小字号顶行渲染（宁可溢出也留痕，可人工复核）。
            $lines = self::wrap($text, $font, 8, $r['w'] - 4) ?: [$text];
            imagettftext($im, 8, 0, $r['x'] + 2, $r['y'] + 10, $color, $font, $lines[0]);
            return;
        }
        // 无 TTF 兜底：GD 内置字体（仅 ASCII 可读，译文恰好是英文）。
        $ascii = preg_replace('/[^\x20-\x7E]/', '?', $text);
        imagestring($im, 5, $r['x'] + 2, $r['y'] + 2, mb_substr($ascii, 0, 60), $color);
    }

    /**
     * 按像素宽贪心分行（单词级；超长单词先硬切成块，块与块续接时不加空格）。
     */
    public static function wrap($text, $font, $size, $max_w) {
        $tokens = []; // [文本, glue]
        foreach (preg_split('/\s+/', trim($text)) as $wd) {
            if ($wd === '' || $wd === null) {
                continue;
            }
            $first = true;
            while ($wd !== '' && self::ttf_width($wd, $font, $size) > $max_w) {
                $cut = '';
                foreach (preg_split('//u', $wd, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                    if (self::ttf_width($cut . $ch, $font, $size) > $max_w) {
                        break;
                    }
                    $cut .= $ch;
                }
                if ($cut === '') {
                    $cut = mb_substr($wd, 0, 1);
                }
                $tokens[] = [$cut, !$first];
                $first = false;
                $wd = mb_substr($wd, mb_strlen($cut));
            }
            if ($wd !== '') {
                $tokens[] = [$wd, !$first];
            }
        }
        $lines = [];
        $cur = '';
        foreach ($tokens as $tk) {
            list($t, $glue) = $tk;
            $try = $cur === '' ? $t : ($glue ? $cur . $t : $cur . ' ' . $t);
            if (self::ttf_width($try, $font, $size) <= $max_w) {
                $cur = $try;
            } else {
                if ($cur !== '') {
                    $lines[] = $cur;
                }
                $cur = $t;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return $lines;
    }

    protected static function ttf_width($text, $font, $size) {
        $bb = imagettfbbox($size, 0, $font, $text === '' ? ' ' : $text);
        return $bb[2] - $bb[0];
    }

    protected static function shrink($im, $max_side) {
        $W = imagesx($im);
        $H = imagesy($im);
        $k = $max_side / max($W, $H);
        $nw = max(1, (int) round($W * $k));
        $nh = max(1, (int) round($H * $k));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $W, $H);
        imagedestroy($im);
        return $dst;
    }

    protected static function temp_path($src_file, $lang) {
        $dir = dirname($src_file);
        $base = pathinfo($src_file, PATHINFO_FILENAME);
        return $dir . DIRECTORY_SEPARATOR . $base . '-ai-' . preg_replace('/[^a-z]/', '', (string) $lang) . '-' . wp_generate_password(6, false) . '.jpg';
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

    /**
     * 新图入库：上传目录，标题注明 AI 翻译。
     */
    protected static function sideload($file, $source_id, $lang) {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $bits = file_get_contents($file);
        $up = wp_upload_bits(basename($file), null, $bits);
        if (!empty($up['error'])) {
            throw new Exception('AIPE_UPLOAD:' . $up['error']);
        }
        $att = [
            'post_mime_type' => 'image/jpeg',
            'post_title' => get_the_title($source_id) . ' (AI ' . $lang . ')',
            'post_status' => 'inherit',
        ];
        $id = wp_insert_attachment($att, $up['file']);
        if (is_wp_error($id) || !$id) {
            throw new Exception('AIPE_ATTACH');
        }
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $up['file']));
        update_post_meta($id, '_aipe_source_attachment', $source_id);
        update_post_meta($id, '_aipe_kind', 'image_translate');
        return $id;
    }

    public static function is_translated($attachment_id) {
        return (bool) get_post_meta((int) $attachment_id, '_aipe_source_attachment', true)
            || (bool) get_post_meta((int) $attachment_id, '_aipe_kind', true);
    }
}






