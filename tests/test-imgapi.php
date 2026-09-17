<?php
/**
 * 离线测试：百度 fanyi 图片翻译通道（签名/解析/缓存/设置）。HTTP 全剧本模拟。
 * 用法：php tests/test-imgapi.php
 */
require __DIR__ . '/stub.php';
require dirname(__DIR__) . '/includes/class-settings.php';
require dirname(__DIR__) . '/includes/class-client.php';
require dirname(__DIR__) . '/includes/class-imgapi.php';
require dirname(__DIR__) . '/includes/class-imagetrans.php';

$pass = 0;
$fail = 0;
function ok($cond, $name) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}
function baidu_settings() {
    $s = AIPE_Settings::get();
    $s['img_source'] = 'baidu';
    $s['imgapi']['baidu'] = ['appid' => '20240101001234567', 'key' => 'testsecret'];
    return $s;
}

// ---------- 1. 签名 ----------
$sign = AIPE_ImgApi::sign('APPID', 'BYTES', '123456', 'APICUID', 'mac', 'KEY');
ok(preg_match('/^[0-9a-f]{32}$/', $sign) === 1, 'sign 32-lower-hex');
ok($sign === md5('APPID' . md5('BYTES') . '123456' . 'APICUID' . 'mac' . 'KEY'), 'sign formula md5(appid+md5(img)+salt+cuid+mac+key)');
ok(AIPE_ImgApi::sign('APPID', 'BYTES', '654321', 'APICUID', 'mac', 'KEY') !== $sign, 'sign varies with salt');

// ---------- 2. rect / lang ----------
ok(AIPE_ImgApi::parse_rect('79 23 246 43') === [79.0, 23.0, 246.0, 43.0], 'rect parse');
ok(AIPE_ImgApi::parse_rect('bad') === null && AIPE_ImgApi::parse_rect('1 2 0 5') === null, 'rect rejects bad');
$m = AIPE_ImgApi::lang_map();
ok($m['en'] === 'en' && $m['ms'] === 'may', 'lang map en/ms');
ok(!isset($m['th']) && !isset($m['vi']), 'th/vi unsupported');
$s = baidu_settings();
$s['target_lang'] = 'th';
try {
    AIPE_ImgApi::detect(sys_get_temp_dir() . '/nope.jpg', $s);
    ok(false, 'unsupported target throws');
} catch (Exception $e) {
    ok(strpos($e->getMessage(), 'AIPE_LANG@baidu') === 0, 'lang error code');
}
try {
    AIPE_ImgApi::detect(sys_get_temp_dir() . '/nope.jpg', AIPE_Settings::get());
    ok(false, 'missing keys throws');
} catch (Exception $e) {
    ok(strpos($e->getMessage(), 'AIPE_NO_KEY@baidu') === 0, 'no-key error code');
}

// ---------- 3. detect 全链（剧本 HTTP） ----------
$src = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aipe-baidu-src.png';
// 800x600 白底 + 黑块（坐标与 canned rect 对应）
if (extension_loaded('gd')) {
    $im = imagecreatetruecolor(800, 600);
    imagefilledrectangle($im, 0, 0, 799, 599, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 79, 23, 325, 66, imagecolorallocate($im, 0, 0, 0));
    imagepng($im, $src);
    imagedestroy($im);
} else {
    $src = null;
}
if ($src) {
$GLOBALS['aipe_attachments'][888] = ['file' => $src, 'title' => 't'];
$GLOBALS['aipe_http_plan'] = [];
aipe_plan_http('api/trans/sdk/picture', function ($url, $args) {
    return aipe_json_resp([
        'error_code' => '0', 'error_msg' => 'success',
        'data' => [
            'from' => 'zh', 'to' => 'en',
            'content' => [
                ['src' => '真丝连衣裙', 'dst' => 'Silk Dress', 'rect' => '79 23 246 43', 'lineCount' => 1],
                ['src' => 'x', 'dst' => '', 'rect' => '1 1 10 10'],   // 空译文丢弃
                ['src' => '', 'dst' => 'Nope', 'rect' => '1 1 10 10'], // 空原文丢弃
            ],
            'pasteImg' => base64_encode('PASTE_BYTES'),
        ],
    ]);
});
$s = baidu_settings();
$n0 = aipe_http_count();
$d = AIPE_ImgApi::detect_cached($src, $s);
ok(count($d['boxes']) === 1 && !$d['hit'], 'detect one valid box');
$b = $d['boxes'][0];
// 800x600 原图 → 0-1000：x=79/800*1000=98.75
ok(abs($b['box'][0] - 98.75) < 0.01 && $b['src'] === '真丝连衣裙' && $b['dst'] === 'Silk Dress', 'box scaled to 0-1000');
ok(aipe_http_count() === $n0 + 1, 'one http call');
// 请求体检查：必填字段齐全
$found = false;
foreach ($GLOBALS['aipe_http_log'] as $e) {
    if (strpos($e['url'], 'sdk/picture') !== false
        && strpos($e['body'], 'APICUID') !== false
        && strpos($e['body'], '20240101001234567') !== false
        && strpos($e['body'], 'name="sign"') !== false
        && strpos($e['body'], 'name="image"') !== false) {
        $found = true;
    }
}
ok($found, 'multipart fields complete');
// 回填图缓存
ok(AIPE_ImgApi::paste_for_file($src) === 'PASTE_BYTES', 'paste cached');
// 第二次全缓存
$n1 = aipe_http_count();
$d2 = AIPE_ImgApi::detect_cached($src, $s);
ok($d2['hit'] && aipe_http_count() === $n1, 'second run fully cached');

// ---------- 4. 错误码 ----------
$GLOBALS['aipe_http_plan'] = [];
aipe_plan_http('api/trans/sdk/picture', function () {
    return aipe_json_resp(['error_code' => '54001', 'error_msg' => 'Invalid Sign']);
});
$GLOBALS['wpdb']->cache = []; // 清缓存，逼直调
try {
    AIPE_ImgApi::detect($src, $s);
    ok(false, 'provider error throws');
} catch (Exception $e) {
    ok(strpos($e->getMessage(), 'AIPE_PROVIDER@baidu:54001') === 0, 'provider error code passthrough');
}

}

// ---------- 5. 设置保存 ----------
update_option('aipe_settings', ['imgapi' => ['baidu' => ['appid' => 'A1', 'key' => 'OLDK']]]);
$saved = AIPE_Settings::save_from_post([
    'target_lang' => 'en',
    'img_source' => 'baidu',
    'imgapi' => ['prefer_paste' => '1', 'baidu' => ['appid' => 'A2', 'key' => '***']],
    'enabled_providers' => [],
]);
ok($saved['img_source'] === 'baidu' && $saved['imgapi']['baidu']['appid'] === 'A2', 'source+appid saved');
ok($saved['imgapi']['baidu']['key'] === 'OLDK' && !empty($saved['imgapi']['prefer_paste']), 'key mask keeps old + paste pref');
$saved2 = AIPE_Settings::save_from_post(['img_source' => 'vision']);
ok($saved2['img_source'] === 'vision', 'source switch back');
$saved3 = AIPE_Settings::save_from_post(['img_source' => 'evil']);
ok($saved3['img_source'] === 'vision', 'bad source rejected');
update_option('aipe_settings', []);

if (!empty($src)) @unlink($src);
echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);

