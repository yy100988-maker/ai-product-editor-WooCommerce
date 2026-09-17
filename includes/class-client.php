<?php
/**
 * OpenAI 协议 HTTP 客户端：chat（含 vision image_url）、images/generations、images/edits。
 * 所有供应商差异收敛在 AIPE_Settings::routes()，这里只认 OpenAI 形状。
 *
 * @package AIPE
 */

defined('ABSPATH') || exit;

class AIPE_Client {

    const PROMPT_VERSION = 'aipe-v1';

    /**
     * 纯文本 chat。返回 ['text'=>..., 'provider'=>..., 'model'=>...]，失败抛 Exception（message 为错误码）。
     */
    public static function chat($settings, $messages, $need = 'chat') {
        $routes = AIPE_Settings::routes($settings, $need);
        $last = new Exception('AIPE_NO_ROUTE');
        foreach ($routes as $r) {
            if ($r['api_key'] === '') {
                $last = new Exception('AIPE_NO_KEY@' . $r['id']);
                continue;
            }
            try {
                $text = self::post_json(
                    $r['base_url'] . '/chat/completions',
                    $r,
                    ['model' => $r['model'], 'messages' => $messages, 'temperature' => 0.2]
                );
                $content = $text['choices'][0]['message']['content'] ?? '';
                $content = trim((string) $content);
                if ($content === '') {
                    throw new Exception('AIPE_EMPTY@' . $r['id']);
                }
                return ['text' => $content, 'provider' => $r['id'], 'model' => $r['model']];
            } catch (Exception $e) {
                $last = $e;
            }
        }
        throw $last;
    }

    /**
     * 文生图：POST {base}/images/generations {model,prompt,size,n:1,response_format:b64_json}。
     * 供应商返回 data[0].b64_json 或 data[0].url（url 时自动下载）。返回图片二进制。
     */
    public static function images_generate($settings, $prompt, $size = '1024x1024') {
        $routes = AIPE_Settings::routes($settings, 'image');
        $last = new Exception('AIPE_NO_ROUTE');
        foreach ($routes as $r) {
            if ($r['api_key'] === '') {
                $last = new Exception('AIPE_NO_KEY@' . $r['id']);
                continue;
            }
            try {
                $j = self::post_json(
                    $r['base_url'] . '/images/generations',
                    $r,
                    ['model' => $r['model'], 'prompt' => $prompt, 'size' => $size, 'n' => 1, 'response_format' => 'b64_json']
                );
                $bin = self::extract_image_bytes($j, $r);
                return ['bytes' => $bin, 'provider' => $r['id'], 'model' => $r['model']];
            } catch (Exception $e) {
                $last = $e;
            }
        }
        throw $last;
    }

    /**
     * 图生图/重绘：POST {base}/images/edits（multipart：image + prompt + size + response_format）。
     * wp_remote_post 传原始 multipart body（WP 不原生支持文件上传字段，这里手拼 boundary）。
     */
    public static function images_edit($settings, $image_bytes, $filename, $mime, $prompt, $size = '1024x1024') {
        $routes = AIPE_Settings::routes($settings, 'image');
        $last = new Exception('AIPE_NO_ROUTE');
        foreach ($routes as $r) {
            if ($r['api_key'] === '') {
                $last = new Exception('AIPE_NO_KEY@' . $r['id']);
                continue;
            }
            try {
                $boundary = 'AIPE' . wp_generate_password(24, false);
                $body = self::multipart([
                    ['name' => 'model', 'value' => $r['model']],
                    ['name' => 'prompt', 'value' => $prompt],
                    ['name' => 'size', 'value' => $size],
                    ['name' => 'response_format', 'value' => 'b64_json'],
                ], [
                    ['name' => 'image', 'filename' => $filename, 'mime' => $mime, 'bytes' => $image_bytes],
                ], $boundary);
                $resp = wp_remote_post($r['base_url'] . '/images/edits', [
                    'timeout' => $r['timeout'],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $r['api_key'],
                        'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                    ],
                    'body' => $body,
                ]);
                $j = self::decode_response($resp, $r['id']);
                $bin = self::extract_image_bytes($j, $r);
                return ['bytes' => $bin, 'provider' => $r['id'], 'model' => $r['model']];
            } catch (Exception $e) {
                $last = $e;
            }
        }
        throw $last;
    }

    // ---------- 内部 ----------

    protected static function post_json($url, $route, $payload) {
        $resp = wp_remote_post($url, [
            'timeout' => $route['timeout'],
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $route['api_key'],
            ],
            'body' => wp_json_encode($payload),
        ]);
        return self::decode_response($resp, $route['id']);
    }

    protected static function decode_response($resp, $route_id) {
        if (is_wp_error($resp)) {
            throw new Exception('AIPE_HTTP_TRANSPORT@' . $route_id . ':' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            throw new Exception('AIPE_HTTP_' . $code . '@' . $route_id . ':' . mb_substr($body, 0, 200));
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            throw new Exception('AIPE_BAD_JSON@' . $route_id);
        }
        if (isset($j['error'])) {
            $msg = is_array($j['error']) ? ($j['error']['message'] ?? 'unknown') : $j['error'];
            throw new Exception('AIPE_PROVIDER@' . $route_id . ':' . mb_substr((string) $msg, 0, 200));
        }
        return $j;
    }

    protected static function extract_image_bytes($j, $route) {
        $d0 = $j['data'][0] ?? null;
        if (!is_array($d0)) {
            throw new Exception('AIPE_EMPTY@' . $route['id']);
        }
        if (!empty($d0['b64_json'])) {
            $bin = base64_decode($d0['b64_json'], true);
            if ($bin === false || $bin === '') {
                throw new Exception('AIPE_BAD_IMAGE@' . $route['id']);
            }
            return $bin;
        }
        if (!empty($d0['url'])) {
            $resp = wp_remote_get($d0['url'], ['timeout' => $route['timeout']]);
            if (is_wp_error($resp)) {
                throw new Exception('AIPE_HTTP_TRANSPORT@' . $route['id']);
            }
            $bin = (string) wp_remote_retrieve_body($resp);
            if ($bin === '') {
                throw new Exception('AIPE_EMPTY@' . $route['id']);
            }
            return $bin;
        }
        throw new Exception('AIPE_EMPTY@' . $route['id']);
    }

    public static function multipart($fields, $files, $boundary) {
        $out = '';
        foreach ($fields as $f) {
            $out .= "--{$boundary}\r\n";
            $out .= 'Content-Disposition: form-data; name="' . $f['name'] . "\"\r\n\r\n";
            $out .= $f['value'] . "\r\n";
        }
        foreach ($files as $f) {
            $out .= "--{$boundary}\r\n";
            $out .= 'Content-Disposition: form-data; name="' . $f['name'] . '"; filename="' . $f['filename'] . "\"\r\n";
            $out .= 'Content-Type: ' . $f['mime'] . "\r\n\r\n";
            $out .= $f['bytes'] . "\r\n";
        }
        $out .= "--{$boundary}--\r\n";
        return $out;
    }
}

