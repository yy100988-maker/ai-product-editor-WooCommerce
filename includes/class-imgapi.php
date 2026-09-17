<?php
/**
 * 第三方图片翻译通道：百度翻译开放平台图片翻译（fanyi-api, APP ID + 密钥）。
 * 规格来源：官方《图片翻译API接入文档》(fanyi-api.baidu.com/doc/26)，浏览器实页核对。
 * - 地址 POST https://fanyi-api.baidu.com/api/trans/sdk/picture（图片字段必须 FORM POST，见官方注4）
 * - 字段：image(原图二进制) from to appid salt cuid=APICUID mac=mac version=3 paste(0/1/2) sign needIntervene(0/1)
 * - 签名：sign = md5(appid + md5(image原字节) + salt + cuid + mac + 密钥)，32位小写
 * - 限制：jpg/jpeg/png小写；≤4M；最短边≥30px；最长边≤4096px；长宽比≤3:1（超限本地先缩小，签名用提交字节、坐标按比例换回）
 * - 返回：error_code/error_msg/data{content[{src,dst,rect:"left top wide high"(提交图px),lineCount,points,pasteImg}],sumSrc,sumDst,pasteImg(paste=1)}
 * - 目标语种：zh/en/jp/kor/fra/spa/ru/pt/de/it/dan/nl/may/swe/id/pl/rom/tr/el/hu（无 th/vi/es，对应目标请用 Vision 通道）
 *
 * @package AIPE
 */

defined('ABSPATH') || exit;

class AIPE_ImgApi {

    const ENDPOINT = 'https://fanyi-api.baidu.com/api/trans/sdk/picture';
    const MAX_BYTES = 4 * 1024 * 1024;

    // 本插件目标语言 → 百度语种码（百度无 th/vi/es，对应目标请用 Vision 通道）。
    public static function lang_map() {
        return ['en' => 'en', 'zh' => 'zh', 'ms' => 'may', 'ja' => 'jp', 'ko' => 'kor', 'fr' => 'fra', 'de' => 'de'];
    }

    public static function creds($s) {
        $b = $s['imgapi']['baidu'] ?? [];
        return ['appid' => trim((string) ($b['appid'] ?? '')), 'key' => trim((string) ($b['key'] ?? ''))];
    }

    /**
     * 带缓存的整图检测：['boxes'=>[{box(0-1000),src,dst}], 'hit'=>bool]。
     * boxes 进 aipe_cache（kind=ocr），整图回填另起一行（key.'_paste'）。
     */
    public static function detect_cached($file, $s) {
        $key = 'aipe_ocr_f_' . md5_file($file);
        $hit = self::cache_get($key);
        if ($hit) {
            $boxes = json_decode($hit['dst_text'], true);
            if (is_array($boxes)) {
                return ['boxes' => $boxes, 'hit' => true];
            }
        }
        $r = self::detect($file, $s);
        $boxes = AIPE_ImageTrans::normalize_boxes($r['boxes']);
        self::cache_put($key, 'ocr', $file, wp_json_encode($boxes), 'baidu', 'sdk-picture');
        if (!empty($r['paste'])) {
            self::cache_put($key . '_paste', 'ocr-paste', $file, $r['paste'], 'baidu', 'sdk-picture');
        }
        return ['boxes' => $boxes, 'hit' => false];
    }

    public static function paste_for_file($file) {
        $row = self::cache_get('aipe_ocr_f_' . md5_file($file) . '_paste');
        $b64 = $row ? trim((string) $row['dst_text']) : '';
        if ($b64 === '') {
            return null;
        }
        $bin = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $b64), true);
        return ($bin === false || $bin === '') ? null : $bin;
    }

    /**
     * 直调（无缓存）：['boxes'=>[{box,src,dst}], 'paste'=>base64|null]。
     */
    public static function detect($file, $s) {
        $c = self::creds($s);
        if ($c['appid'] === '' || $c['key'] === '') {
            throw new Exception('AIPE_NO_KEY@baidu');
        }
        $to = self::lang_map()[$s['target_lang']] ?? null;
        if (!$to) {
            throw new Exception('AIPE_LANG@baidu:' . $s['target_lang']);
        }
        $fit = self::fit_size($file); // ['path'=>..,'scale'=>..,'tmp'=>bool]
        try {
            $bytes = (string) file_get_contents($fit['path']);
            $j = self::post($bytes, $c, $to);
        } finally {
            if ($fit['tmp']) {
                @unlink($fit['path']);
            }
        }
        $code = $j['error_code'] ?? null;
        if ($code === null || !($code === 0 || $code === '0')) {
            throw new Exception('AIPE_PROVIDER@baidu:' . ($code === null ? '?' : $code) . ':' . mb_substr((string) ($j['error_msg'] ?? 'unknown'), 0, 160));
        }
        $info = @getimagesize($file);
        $W = $info[0] ?? 0;
        $H = $info[1] ?? 0;
        if (!$W || !$H) {
            throw new Exception('AIPE_BAD_IMAGE');
        }
        $boxes = [];
        foreach ((array) ($j['data']['content'] ?? []) as $c) {
            $src = trim((string) ($c['src'] ?? ''));
            $dst = trim((string) ($c['dst'] ?? ''));
            $rect = self::parse_rect($c['rect'] ?? '');
            if ($src === '' || $dst === '' || !$rect) {
                continue;
            }
            // 提交图坐标 → 原图坐标 → 0-1000
            $k = $fit['scale'] > 0 ? $fit['scale'] : 1;
            $boxes[] = [
                'box' => [
                    $rect[0] / $k / $W * 1000,
                    $rect[1] / $k / $H * 1000,
                    $rect[2] / $k / $W * 1000,
                    $rect[3] / $k / $H * 1000,
                ],
                'src' => $src,
                'dst' => $dst,
            ];
        }
        $paste = null;
        if (!empty($j['data']['pasteImg'])) {
            $paste = trim((string) $j['data']['pasteImg']);
        }
        return ['boxes' => $boxes, 'paste' => $paste ?: null];
    }

    /**
     * 连接测试（设置页用）：发一张 120x60 白底小图；鉴权通过（error 0/69002/69003/69004）即 keys 有效。
     */
    public static function test_auth($s) {
        if (!extension_loaded('gd')) {
            throw new Exception('AIPE_NO_GD');
        }
        $c = self::creds($s);
        if ($c['appid'] === '' || $c['key'] === '') {
            throw new Exception('AIPE_NO_KEY@baidu');
        }
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aipe-baidu-test.jpg';
        $im = imagecreatetruecolor(120, 60);
        imagefilledrectangle($im, 0, 0, 119, 59, imagecolorallocate($im, 255, 255, 255));
        imagejpeg($im, $tmp, 80);
        imagedestroy($im);
        try {
            $bytes = (string) file_get_contents($tmp);
            $j = self::post($bytes, $c, 'en');
        } catch (Exception $e) {
            @unlink($tmp);
            throw $e;
        }
        @unlink($tmp);
        $code = $j['error_code'] ?? null;
        $ok_codes = [0, '0', 69002, 69003, 69004]; // 超时/识别失败/内容为空都说明签名已过
        if (in_array($code, $ok_codes, true)) {
            return ['ok' => true, 'code' => (string) $code];
        }
        throw new Exception('AIPE_PROVIDER@baidu:' . ($code === null ? '?' : $code) . ':' . mb_substr((string) ($j['error_msg'] ?? 'unknown'), 0, 160));
    }

    // ---------- 内部 ----------

    protected static function post($bytes, $c, $to) {
        $salt = (string) mt_rand(100000, 999999);
        $sign = self::sign($c['appid'], $bytes, $salt, 'APICUID', 'mac', $c['key']);
        $boundary = 'AIPE' . wp_generate_password(24, false);
        $body = AIPE_Client::multipart(
            [
                ['name' => 'from', 'value' => 'zh'],
                ['name' => 'to', 'value' => $to],
                ['name' => 'appid', 'value' => $c['appid']],
                ['name' => 'salt', 'value' => $salt],
                ['name' => 'cuid', 'value' => 'APICUID'],
                ['name' => 'mac', 'value' => 'mac'],
                ['name' => 'version', 'value' => '3'],
                ['name' => 'paste', 'value' => '1'],
                ['name' => 'needIntervene', 'value' => '0'],
                ['name' => 'sign', 'value' => $sign],
            ],
            [['name' => 'image', 'filename' => 'source.jpg', 'mime' => 'image/jpeg', 'bytes' => $bytes]],
            $boundary
        );
        $resp = wp_remote_post(self::ENDPOINT, [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            'body' => $body,
        ]);
        if (is_wp_error($resp)) {
            throw new Exception('AIPE_HTTP_TRANSPORT@baidu:' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            throw new Exception('AIPE_HTTP_' . $code . '@baidu:' . mb_substr($raw, 0, 160));
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            throw new Exception('AIPE_BAD_JSON@baidu');
        }
        return $j;
    }

    /**
     * 官方签名：md5(appid + md5(image原字节) + salt + cuid + mac + 密钥)，32位小写。
     */
    public static function sign($appid, $bytes, $salt, $cuid, $mac, $key) {
        return md5($appid . md5($bytes) . $salt . $cuid . $mac . $key);
    }

    /**
     * rect "left top wide high"（px，提交图坐标）→ [l,t,w,h]，非法返回 null。
     */
    public static function parse_rect($rect) {
        $parts = preg_split('/\s+/', trim((string) $rect));
        if (count($parts) !== 4) {
            return null;
        }
        $v = array_map('floatval', $parts);
        if ($v[2] <= 0 || $v[3] <= 0) {
            return null;
        }
        return $v;
    }

    /**
     * 超限图本地缩小（百度：≤4M、最长边≤4096、最短边≥30、长宽比≤3:1）。
     * 返回 ['path'=>提交路径,'scale'=>提交/原图比例,'tmp'=>是否临时文件]。
     * 注意：签名 md5(image) 必须用提交字节（调用方传 $fit['path'] 内容）。
     */
    public static function fit_size($file) {
        $info = @getimagesize($file);
        $size = @filesize($file);
        if (!$info || !$size) {
            throw new Exception('AIPE_BAD_IMAGE');
        }
        list($W, $H) = [$info[0], $info[1]];
        $scale = min(1, 4000 / max($W, $H), sqrt((self::MAX_BYTES - 1048576) / $size));
        if ($scale >= 1) {
            return ['path' => $file, 'scale' => 1, 'tmp' => false];
        }
        if (!extension_loaded('gd')) {
            throw new Exception('AIPE_TOO_LARGE');
        }
        $src = @imagecreatefromstring((string) file_get_contents($file));
        if (!$src) {
            throw new Exception('AIPE_BAD_IMAGE');
        }
        $nw = max(30, (int) round($W * $scale));
        $nh = max(30, (int) round($H * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $W, $H);
        imagedestroy($src);
        $tmp = $file . '.aipe-fit.jpg';
        imagejpeg($dst, $tmp, 85);
        imagedestroy($dst);
        return ['path' => $tmp, 'scale' => $nw / $W, 'tmp' => true];
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
