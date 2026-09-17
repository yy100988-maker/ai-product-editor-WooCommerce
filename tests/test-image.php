<?php
/**
 * 离线测试：OCR 解析/合并 + GD 擦除重绘（真实出图到系统临时目录）。
 * 用法：php tests/test-image.php（需要 GD；无 GD 时只跑纯函数部分）
 */
require __DIR__ . '/stub.php';
require dirname(__DIR__) . '/includes/class-settings.php';
require dirname(__DIR__) . '/includes/class-client.php';
require dirname(__DIR__) . '/includes/class-translate.php';
require dirname(__DIR__) . '/includes/class-imagetrans.php';

$pass = 0;
$fail = 0;
function ok($cond, $name) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}
function settings_with_keys() {
    $s = AIPE_Settings::get();
    $s['providers']['siliconflow']['api_key'] = 'sk-sf-test';
    $s['providers']['openrouter']['api_key'] = 'sk-or-test';
    return $s;
}

// ---------- 1. parse_ocr ----------
$r = AIPE_ImageTrans::parse_ocr('```json {"texts":[{"box":[10,20,300,60],"src":"真丝连衣裙"}]} ```');
ok(count($r) === 1 && $r[0]['src'] === '真丝连衣裙', 'parse fenced json');
$r = AIPE_ImageTrans::parse_ocr('Here you go: {"texts":[]} done');
ok($r === [], 'parse empty texts');
$r = AIPE_ImageTrans::parse_ocr('not json at all');
ok($r === [], 'parse garbage');
$r = AIPE_ImageTrans::parse_ocr('{"texts":[{"box":[0,0,0,50],"src":"x"},{"box":[1,2,3],"src":"y"},{"src":"z"}]}');
ok($r === [], 'parse drops invalid boxes');
// 幻觉字段不影响（只取 box+src）
$r = AIPE_ImageTrans::parse_ocr('{"texts":[{"box":[10,20,30,40],"src":"包邮","dst":"Free","confidence":0.9}]}');
ok(count($r) === 1 && $r[0] === ['box' => [10.0, 20.0, 30.0, 40.0], 'src' => '包邮'], 'parse ignores extra fields');

// ---------- 2. normalize_boxes ----------
// 极小框丢弃 + 同行合并 + 阅读排序
$in = [
    ['box' => [500, 500, 5, 5], 'src' => '水印'],          // 丢
    ['box' => [400, 100, 120, 40], 'src' => '连衣裙'],     // 行1右
    ['box' => [100, 100, 150, 40], 'src' => '真丝'],       // 行1左（gap=150 → 不合并，gap<40才合）
    ['box' => [100, 200, 100, 40], 'src' => '包'],         // 行2左
    ['box' => [210, 202, 100, 40], 'src' => '邮'],         // 行2右（gap=10 → 合并）
];
$n = AIPE_ImageTrans::normalize_boxes($in);
ok(count($n) === 4, 'tiny dropped, close pair merged');
ok($n[0]['src'] === '真丝' && $n[1]['src'] === '连衣裙', 'reading order row1');
$found = null;
foreach ($n as $b) { if ($b['src'] === '包邮') $found = $b; }
ok($found && $found['box'][0] == 100 && $found['box'][2] == 210, 'row2 merged box spans both');

// ---------- 3. scale_box / ink_color ----------
$px = AIPE_ImageTrans::scale_box([100, 200, 300, 50], 800, 600);
ok($px === ['x' => 80, 'y' => 120, 'w' => 240, 'h' => 30], 'scale 0-1000 to px');
ok(AIPE_ImageTrans::ink_color(['text_color' => 'auto'], [250, 250, 250]) === [34, 34, 34], 'ink auto on light');
ok(AIPE_ImageTrans::ink_color(['text_color' => 'auto'], [10, 10, 10]) === [255, 255, 255], 'ink auto on dark');
ok(AIPE_ImageTrans::ink_color(['text_color' => '#112233'], [250, 250, 250]) === [17, 34, 51], 'ink explicit');

// ---------- 4. items_for_job：自带 items 跳过 OCR（零 HTTP） ----------
$s = settings_with_keys();
$n0 = aipe_http_count();
$items = AIPE_ImageTrans::items_for_job(999, ['items' => [
    ['box' => [100, 100, 200, 60], 'dst' => 'Silk Dress'],
    ['box' => [0, 0, 10, 10], 'dst' => ''],   // 空译文丢弃
    ['dst' => 'NoBox'],                        // 无框丢弃
]], $s, 800, 600);
ok(count($items) === 1 && $items[0]['rect'] === ['x' => 80, 'y' => 60, 'w' => 160, 'h' => 36], 'items from payload scaled');
ok(aipe_http_count() === $n0, 'no http when items provided');

// ---------- 5. GD 真实重绘 ----------
if (!extension_loaded('gd')) {
    echo "SKIP gd redraw (no gd)\n";
} else {
    // 造一张白底 + 黑条（假装原文）测试图
    $src = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aipe-test-src.jpg';
    $im = imagecreatetruecolor(800, 600);
    $white = imagecolorallocate($im, 255, 255, 255);
    $black = imagecolorallocate($im, 0, 0, 0);
    imagefilledrectangle($im, 0, 0, 799, 599, $white);
    imagefilledrectangle($im, 80, 60, 240, 96, $black); // 假原文块
    imagejpeg($im, $src, 90);
    imagedestroy($im);

    $font = AIPE_ImageTrans::resolve_font(['font_path' => '']);
    echo 'INFO font=' . ($font ?: 'none(builtin fallback)') . "\n";
    $out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aipe-test-out.jpg';
    AIPE_ImageTrans::redraw($src, $out, [
        ['rect' => ['x' => 80, 'y' => 60, 'w' => 160, 'h' => 36], 'dst' => 'Silk Dress Free Shipping'],
    ], ['max_width' => 400, 'font_path' => '', 'text_color' => 'auto', 'jpeg_quality' => 85]);
    ok(file_exists($out), 'redraw output exists');
    $info = getimagesize($out);
    ok($info && $info[0] <= 400 && $info['mime'] === 'image/jpeg', 'redraw resized + jpeg');
    // 黑条应被盖掉：原黑条中心采样不再是黑色
    $chk = imagecreatefromjpeg($out);
    $k = 400 / 800; // 输出缩放比
    $rgb = imagecolorat($chk, (int) round(160 * $k), (int) round(78 * $k));
    $r8 = ($rgb >> 16) & 0xFF;
    imagedestroy($chk);
    ok($r8 > 100, 'original text erased (center bright)');
    @unlink($src);
    @unlink($out);

    if ($font && function_exists('imagettfbbox')) {
        $lines = AIPE_ImageTrans::wrap('Silk Dress Free Shipping Extra Long Words Here', $font, 20, 150);
        ok(count($lines) > 1, 'wrap splits long text');
        $joined = implode('', $lines);
        ok(str_replace(' ', '', $joined) === str_replace(' ', '', 'Silk Dress Free Shipping Extra Long Words Here'), 'wrap lossless');
    } else {
        echo "SKIP wrap (no ttf font)\n";
    }
}

// ---------- 6. preview 全链（剧本 HTTP） + OCR 缓存 ----------
if (extension_loaded('gd')) {
    $src = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aipe-test-cache.jpg';
    $im = imagecreatetruecolor(100, 100);
    imagefilledrectangle($im, 0, 0, 99, 99, imagecolorallocate($im, 255, 255, 255));
    imagejpeg($im, $src, 80);
    imagedestroy($im);
    $GLOBALS['aipe_attachments'][777] = ['file' => $src, 'title' => 't'];
    $GLOBALS['aipe_http_plan'] = [];
    aipe_plan_http('chat/completions', function ($url, $args) {
        $b = json_decode($args['body'], true);
        $txt = $b['messages'][1]['content'] ?? '';
        if (is_array($txt)) $txt = json_encode($txt);
        if (strpos($txt, 'Detect ALL Chinese') !== false) {
            return aipe_json_resp(['choices' => [['message' => ['content' => '{"texts":[{"box":[100,100,200,60],"src":"真丝"}]}']]]]);
        }
        return aipe_json_resp(['choices' => [['message' => ['content' => 'Mulberry Silk']]]]);
    });
    $n0 = aipe_http_count();
    $p1 = AIPE_ImageTrans::preview(777, $s);
    ok(count($p1) === 1 && $p1[0]['dst'] === 'Mulberry Silk' && !$p1[0]['cached'], 'preview first run');
    $n1 = aipe_http_count();
    ok($n1 === $n0 + 2, 'preview costs vision+text');
    $p2 = AIPE_ImageTrans::preview(777, $s);
    ok(aipe_http_count() === $n1, 'preview second run fully cached');
    @unlink($src);
} else {
    echo "SKIP preview chain (no gd)\n";
}

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
