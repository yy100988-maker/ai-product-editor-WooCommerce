<?php
/**
 * 离线测试：设置/文本翻译/任务/商品落库（无需 WP，HTTP 全剧本模拟）。
 * 用法：php tests/test-offline.php
 */
require __DIR__ . '/stub.php';
require dirname(__DIR__) . '/includes/class-settings.php';
require dirname(__DIR__) . '/includes/class-client.php';
require dirname(__DIR__) . '/includes/class-translate.php';
require dirname(__DIR__) . '/includes/class-jobs.php';
require dirname(__DIR__) . '/includes/class-imagegen.php';
require dirname(__DIR__) . '/includes/class-imagetrans.php';
require dirname(__DIR__) . '/includes/class-product.php';

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
    $s['providers']['openrouter']['models']['image'] = 'some/image-model';
    return $s;
}

// ---------- 1. 设置 ----------
$g = AIPE_Settings::glossary_map();
ok($g['真丝'] === 'Mulberry Silk', 'glossary parse');
ok(AIPE_Settings::sanitize_glossary("a\n坏行\nb = C ") === 'b=C', 'glossary sanitize drops bad lines');
$s = AIPE_Settings::get();
$routes = AIPE_Settings::routes($s, 'image');
ok(count($routes) === 1 && $routes[0]['id'] === 'siliconflow', 'image routes skip provider without image model');
$routes = AIPE_Settings::routes($s, 'chat');
ok(count($routes) === 2 && $routes[0]['id'] === 'siliconflow', 'chat fallback order');
$post = [
    'target_lang' => 'ms',
    'glossary' => "红色=Red\n",
    'providers' => ['siliconflow' => ['api_key' => '***', 'base_url' => 'https://x.test/v1', 'timeout_s' => '5', 'models' => ['chat' => 'M']]],
    'enabled_providers' => ['siliconflow' => 1],
    'image' => ['max_width' => '800', 'font_path' => '', 'text_color' => '#112233', 'jpeg_quality' => '90'],
];
update_option('aipe_settings', ['providers' => ['siliconflow' => ['api_key' => 'OLDKEY']]]);
$saved = AIPE_Settings::save_from_post($post);
ok($saved['providers']['siliconflow']['api_key'] === 'OLDKEY', 'key *** keeps old');
ok($saved['target_lang'] === 'ms' && $saved['image']['max_width'] === 800, 'settings save');
update_option('aipe_settings', []); // 复位

// ---------- 2. 术语库 ----------
$pre = AIPE_Translate::apply_glossary('真丝连衣裙真丝', ['真丝' => 'Silk', '真丝连衣裙' => 'Silk Dress']);
ok($pre === 'Silk DressSilk', 'glossary longest-first');
$map = AIPE_Translate::parse_pairs("红色\n蓝色", "红色=Red\n绿色=Green\n蓝色=Blue");
ok($map === ['红色' => 'Red', '蓝色' => 'Blue'], 'parse_pairs drops hallucinated keys');

// ---------- 3. chat fallback + 缓存 ----------
$s = settings_with_keys();
// 主路由 500，备路由成功
aipe_plan_http('siliconflow.cn', aipe_json_resp(['error' => 'x'], 500));
aipe_plan_http('openrouter', function () {
    return aipe_json_resp(['choices' => [['message' => ['content' => 'Silk Dress']]]]);
});
$n0 = aipe_http_count();
$r = AIPE_Translate::title('真丝连衣裙', $s);
ok($r['text'] === 'Silk Dress' && $r['provider'] === 'openrouter' && !$r['cached'], 'fallback to openrouter');
ok(aipe_http_count() === $n0 + 2, 'two http attempts');
$body = aipe_http_last_body();
ok(strpos($body['messages'][0]['content'], 'Mulberry Silk') !== false, 'glossary hint sent to model');
ok(strpos($body['model'], 'qwen') !== false, 'model from route');
// 第二次命中缓存，不再出网
$n1 = aipe_http_count();
$r2 = AIPE_Translate::title('真丝连衣裙', $s);
ok($r2['cached'] && aipe_http_count() === $n1, 'cache hit skips http');
// 无 key 时报 NO_KEY
$s_nokey = AIPE_Settings::get();
try {
    AIPE_Translate::title('测试标题 Gothic', $s_nokey);
    ok(false, 'no-key throws');
} catch (Exception $e) {
    ok(strpos($e->getMessage(), 'AIPE_NO_KEY') === 0, 'no-key error code');
}

// ---------- 4. terms 批量 ----------
$GLOBALS['aipe_http_plan'] = [];
aipe_plan_http('chat/completions', function () {
    return aipe_json_resp(['choices' => [['message' => ['content' => "颜色=Color\n红色=Red"]]]]);
});
$t = AIPE_Translate::terms(['颜色', '红色', 'RED-123', '42'], $s);
ok($t['颜色'] === 'Color' && $t['红色'] === 'Red', 'terms batch map');
ok($t['RED-123'] === 'RED-123' && $t['42'] === '42', 'ascii passthrough without http');
ok(aipe_http_count() === $n1 + 1, 'terms single http call');

// ---------- 5. 商品落库 ----------
// 可变商品：全局属性 pa_color（terms 1 红 2 蓝）+ 自定义属性 尺码
$GLOBALS['aipe_terms'] = [
    1 => ['name' => '红色', 'slug' => 'red', 'taxonomy' => 'pa_color'],
    2 => ['name' => '蓝色', 'slug' => 'blue', 'taxonomy' => 'pa_color'],
];
$p = new AIPE_Fake_Product();
$p->id = 101; $p->type = 'variable'; $p->name = '真丝连衣裙'; $p->sku = 'DXM-001'; $p->image_id = 900; $p->gallery = [901];
$tax = new WC_Product_Attribute(); $tax->taxonomy = true; $tax->label = '颜色'; $tax->options = [1, 2];
$cus = new WC_Product_Attribute(); $cus->label = '尺码'; $cus->options = ['均码', '加大'];
$p->attrs = ['pa_color' => $tax, '尺码' => $cus];
$p->children = [201, 202];
$p->save();
$v1 = new AIPE_Fake_Variation(); $v1->id = 201; $v1->sku = 'DXM-001-S'; $v1->save();
$v2 = new AIPE_Fake_Variation(); $v2->id = 202; $v2->sku = 'DXM-001-X'; $v2->save();
update_post_meta(201, 'attribute_' . sanitize_title('尺码'), '均码');
update_post_meta(202, 'attribute_' . sanitize_title('尺码'), '加大');

$c = AIPE_Product::collect(101);
ok($c['sku'] === 'DXM-001' && $c['attributes']['pa_color']['options'] === ['红色', '蓝色'], 'collect taxonomy names');
ok($c['attributes']['尺码']['options'] === ['均码', '加大'], 'collect custom options');
ok(count($c['images']) === 2, 'collect images');

AIPE_Product::apply_title(101, 'Silk Dress');
ok(wc_get_product(101)->get_name() === 'Silk Dress', 'apply title');
ok(wc_get_product(101)->get_sku() === 'DXM-001', 'sku untouched');

$res = AIPE_Product::apply_attributes(101, ['颜色' => 'Color', '尺码' => 'Size'], ['红色' => 'Red', '蓝色' => 'Blue', '均码' => 'One Size']);
ok($res['renamed_terms'] === 2, 'terms renamed');
ok($GLOBALS['aipe_terms'][1]['name'] === 'Red' && $GLOBALS['aipe_terms'][1]['slug'] === 'red', 'term rename keeps slug');
ok($GLOBALS['wpdb']->attr_labels['color'] === 'Color', 'taxonomy label renamed globally');
$np = wc_get_product(101);
ok($np->attrs['尺码']->get_options() === ['One Size', '加大'], 'custom options rewritten');
ok(get_post_meta(201, 'attribute_size', true) === 'One Size', 'variation meta follows');
ok(get_post_meta(202, 'attribute_size', true) === '加大', 'renamed meta key migrated');

// attach_image：gallery 原位替换 / 特色图转正 / 描述内图替换
AIPE_Product::attach_image(101, 902, 'gallery', 901);
ok(wc_get_product(101)->gallery === [902], 'gallery in-place replace');
AIPE_Product::attach_image(101, 903, 'gallery', 900);
ok(wc_get_product(101)->image_id === 903, 'featured replaced');
$np->desc = '<img class="wp-image-901" src="http://local.test/img-901.jpg">';
$np->save();
AIPE_Product::attach_image(101, 904, 'desc', 901);
ok(strpos(wc_get_product(101)->desc, 'img-904') !== false, 'desc inline replaced');

// ---------- 6. 任务（文生图路径端到端） ----------
$GLOBALS['aipe_http_plan'] = [];
aipe_plan_http('images/generations', function () {
    // 1x1 png
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    return aipe_json_resp(['data' => [['b64_json' => base64_encode($png)]]]);
});
$jid = AIPE_Jobs::create(101, 'image_generate', ['template' => 'white_bg', 'title_en' => 'Silk Dress', 'slot' => 'gallery', 'auto_apply' => true]);
AIPE_Jobs::dispatch($jid);
$job = AIPE_Jobs::get($jid);
ok($job->status === 'queued', 'job created');
AIPE_Jobs::run($jid);
$job = AIPE_Jobs::get($jid);
$res = json_decode($job->result, true);
ok($job->status === 'done' && $res['attachment_id'] >= 5000 && $res['applied'] === true, 'image_generate done+applied');
ok(in_array($res['attachment_id'], wc_get_product(101)->gallery, true), 'generated image in gallery');
ok(($GLOBALS['aipe_meta'][$res['attachment_id']]['_aipe_kind'] ?? '') === 'image_gen', 'ai meta recorded');
// 幂等：重复 run 不执行
$n_before = aipe_http_count();
AIPE_Jobs::run($jid);
ok(aipe_http_count() === $n_before, 'rerun skipped');
// 坏 kind → failed + 错误码
$jid2 = AIPE_Jobs::create(101, 'nope', []);
AIPE_Jobs::run($jid2);
ok(AIPE_Jobs::get($jid2)->status === 'failed' && AIPE_Jobs::get($jid2)->error_code === 'AIPE_BAD_KIND', 'bad kind failed');
// dispatch 记账（桩里 as_enqueue_async_action 存在，走 AS 通道）
ok(count($GLOBALS['aipe_cron']) === 1, 'dispatch recorded');

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
