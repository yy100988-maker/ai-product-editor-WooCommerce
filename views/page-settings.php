<?php
// 设置页模板。变量：$s（设置）、$notice、$gd、$font。
defined('ABSPATH') || exit;
?>
<div class="wrap aipe-wrap">
    <h1>AI 产品编辑器 <small style="font-weight:normal;color:#666">Key 直连供应商 · 不经过 SaaS · 不走 credits</small></h1>
    <?php if (!empty($notice)) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="aipe_save">
        <?php wp_nonce_field(AIPE_Admin::NONCE); ?>

        <h2 class="title">语言与术语库</h2>
        <table class="form-table">
            <tr>
                <th>目标语言</th>
                <td>
                    <select name="target_lang">
                        <?php foreach (['en' => 'English', 'ms' => 'Malay', 'th' => 'Thai', 'vi' => 'Vietnamese', 'es' => 'Spanish'] as $code => $label) : ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($s['target_lang'], $code); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">源语言固定中文。标题 / 属性词 / 图内文字统一翻成此语言。</p>
                </td>
            </tr>
            <tr>
                <th>术语库</th>
                <td>
                    <textarea name="glossary" rows="6" cols="60" class="large-text code"><?php echo esc_textarea($s['glossary']); ?></textarea>
                    <p class="description">一行一条 <code>中文=English</code>。命中时直接替换、不调模型；也会作为指令约束发给模型。</p>
                </td>
            </tr>
        </table>

        <h2 class="title">供应商（OpenAI 协议）</h2>
        <p>按勾选顺序 fallback：主供应商失败自动试下一个。Key 留 <code>***</code> 表示不改；不填则该供应商被跳过。</p>
        <?php foreach ($s['providers'] as $id => $p) : ?>
            <h3>
                <label>
                    <input type="checkbox" name="enabled_providers[<?php echo esc_attr($id); ?>]" value="1"
                        <?php checked(in_array($id, (array) $s['fallback_order'], true)); ?>>
                    <?php echo esc_html($p['label']); ?>
                </label>
            </h3>
            <table class="form-table">
                <tr>
                    <th>Base URL</th>
                    <td><input type="url" name="providers[<?php echo esc_attr($id); ?>][base_url]" value="<?php echo esc_attr($p['base_url']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>API Key</th>
                    <td>
                        <input type="password" name="providers[<?php echo esc_attr($id); ?>][api_key]" value="<?php echo $p['api_key'] !== '' ? '***' : ''; ?>" class="regular-text" autocomplete="new-password">
                        <?php if ($p['api_key'] !== '') : ?><span class="description">已填写（●●●●<?php echo esc_html(substr($p['api_key'], -4)); ?>）</span><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>超时（秒）</th>
                    <td><input type="number" name="providers[<?php echo esc_attr($id); ?>][timeout_s]" value="<?php echo (int) $p['timeout_s']; ?>" min="10" max="600" style="width:6em"></td>
                </tr>
                <?php foreach (['chat' => '文本模型（标题/属性词）', 'vision' => '视觉模型（图片 OCR）', 'image' => '生图模型（文生图/重绘，留空=此供应商不支持生图）'] as $m => $mlabel) : ?>
                    <tr>
                        <th><?php echo esc_html($mlabel); ?></th>
                        <td><input type="text" name="providers[<?php echo esc_attr($id); ?>][models][<?php echo esc_attr($m); ?>]" value="<?php echo esc_attr($p['models'][$m]); ?>" class="regular-text"></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endforeach; ?>

        <h2 class="title">图片翻译来源</h2>
        <table class="form-table">
            <tr><th>来源</th><td>
                <label><input type="radio" name="img_source" value="vision" <?php checked($s['img_source'] ?? 'vision', 'vision'); ?>> Vision 模型 OCR（走上面的供应商，需配 vision 模型）</label><br>
                <label><input type="radio" name="img_source" value="baidu" <?php checked($s['img_source'] ?? 'vision', 'baidu'); ?>> 百度翻译开放平台图片翻译（APP ID + 密钥）</label>
                <p class="description">百度通道：OCR+翻译+实景回填一次完成，比 Vision 便宜、版式还原更好；目标语言仅支持百度列表（en/ms 等，无 th/vi/es）。文档：fanyi-api.baidu.com/doc/26</p>
            </td></tr>
            <tr><th>百度 APP ID</th><td><input type="text" name="imgapi[baidu][appid]" value="<?php echo esc_attr($s['imgapi']['baidu']['appid'] ?? ''); ?>" class="regular-text"></td></tr>
            <tr><th>百度密钥</th><td>
                <input type="password" name="imgapi[baidu][key]" value="<?php echo ($s['imgapi']['baidu']['key'] ?? '') !== '' ? '***' : ''; ?>" class="regular-text" autocomplete="new-password">
                <?php if (!empty($s['imgapi']['baidu']['key'])) : ?><span class="description">已填写（●●●●<?php echo esc_html(substr($s['imgapi']['baidu']['key'], -4)); ?>）</span><?php endif; ?>
            </td></tr>
            <tr><th>回填图优先</th><td><label><input type="checkbox" name="imgapi[prefer_paste]" value="1" <?php checked(!empty($s['imgapi']['prefer_paste'])); ?>> 有百度回填图时直接入库，跳过 GD 重绘</label></td></tr>
            <tr><th>连接测试</th><td>
                <button type="button" class="button" id="aipe-baidu-test">测试百度连接</button>
                <span id="aipe-baidu-test-status" class="aipe-status"></span>
                <p class="description">先点页面底部“保存设置”再测。测试发一张空白小图验证签名，鉴权通过即有效。</p>
            </td></tr>
        </table>

        <h2 class="title">图片输出</h2>
        <table class="form-table">
            <tr>
                <th>GD 状态</th>
                <td><code><?php echo esc_html($gd); ?></code></td>
            </tr>
            <tr>
                <th>当前字体</th>
                <td><code><?php echo $font ? esc_html($font) : '未找到（将用内置点阵字体兜底）'; ?></code></td>
            </tr>
            <tr>
                <th>最长边上限</th>
                <td><input type="number" name="image[max_width]" value="<?php echo (int) $s['image']['max_width']; ?>" min="0" style="width:6em"> px（0=不缩放）</td>
            </tr>
            <tr>
                <th>自定义字体</th>
                <td>
                    <input type="text" name="image[font_path]" value="<?php echo esc_attr($s['image']['font_path']); ?>" class="regular-text" placeholder="留空=自动探测">
                    <p class="description">服务器上 TTF/TTC 绝对路径。图内英文推荐 Arial/DejaVuSans；要渲染中文请填中文字体。</p>
                </td>
            </tr>
            <tr>
                <th>重绘字色</th>
                <td>
                    <input type="text" name="image[text_color]" value="<?php echo esc_attr($s['image']['text_color']); ?>" style="width:8em" placeholder="auto">
                    <p class="description"><code>auto</code>=按底色自动黑/白，或填 <code>#RRGGBB</code>。</p>
                </td>
            </tr>
            <tr>
                <th>JPEG 质量</th>
                <td><input type="number" name="image[jpeg_quality]" value="<?php echo (int) $s['image']['jpeg_quality']; ?>" min="60" max="100" style="width:6em"></td>
            </tr>
        </table>

        <?php submit_button('保存设置'); ?>
    </form>

    <hr>
    <h2 class="title">字段说明</h2>
    <ul class="ul-disc">
        <li><b>SKU 编码永不改写</b>：只翻译变体属性名/属性值（如“颜色→Color”“红色→Red”），term 改名不改 slug，变体不断链。</li>
        <li><b>全局属性标签</b>（如 pa_color 的“颜色”）改名会影响全站使用该属性的商品，应用时会二次确认。</li>
        <li><b>图片 AI 翻译</b>先覆盖预览（译文气泡画在原图上），确认后才重绘入库；原图保留，可选挂载位置。</li>
        <li>图片管线对标 <b>MoeTranslate v5.2.0</b>：OCR 与翻译解耦、框合并排序、OCR 结果缓存，详见 README。</li>
    </ul>
</div>

