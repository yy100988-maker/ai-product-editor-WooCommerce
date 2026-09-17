<?php
// 商品编辑页 metabox 模板。变量：$post、$data（AIPE_Product::collect）、$jobs、$templates。
defined('ABSPATH') || exit;
$pid = (int) $post->ID;
?>
<div class="aipe-box" data-product="<?php echo $pid; ?>">
    <p class="description">SKU 编码 <code><?php echo esc_html($data['sku'] ?: '（无）'); ?></code> 只读展示，AI 不会改写。文本类即时执行，图片类先覆盖预览、确认后走后台任务（可关页面，回来轮询结果）。</p>

    <h4>1）标题与属性词翻译</h4>
    <div class="aipe-row">
        <button type="button" class="button" id="aipe-preview-btn">AI 预览翻译</button>
        <button type="button" class="button button-primary" id="aipe-apply-btn" disabled>应用到商品</button>
        <span id="aipe-text-status" class="aipe-status"></span>
    </div>
    <table class="widefat aipe-table" id="aipe-text-table" hidden>
        <thead><tr><th>字段</th><th>原文</th><th>译文（可改）</th></tr></thead>
        <tbody></tbody>
    </table>
    <?php if ($data['attributes']) : ?>
        <p class="description">待翻属性：<?php echo esc_html(implode(' / ', array_map(function ($a) { return $a['label']; }, $data['attributes']))); ?>
        （<?php echo count($data['variations']); ?> 个变体）</p>
    <?php else : ?>
        <p class="description">该商品没有可翻译的属性词。</p>
    <?php endif; ?>

    <h4>2）图片 AI 翻译（先预览覆盖层，确认再重绘）</h4>
    <?php if (!$data['images']) : ?>
        <p class="description">暂无图片。</p>
    <?php else : ?>
        <div class="aipe-imgs">
            <?php foreach ($data['images'] as $img) : ?>
                <div class="aipe-img" data-att="<?php echo (int) $img['attachment_id']; ?>" data-slot="<?php echo esc_attr($img['slot']); ?>">
                    <div class="aipe-thumb">
                        <img src="<?php echo esc_url($img['url']); ?>" alt="">
                        <div class="aipe-overlay" hidden></div>
                    </div>
                    <div class="aipe-img-meta">
                        <span><?php echo esc_html($img['label']); ?></span>
                        <?php if ($img['is_ai']) : ?><span class="aipe-badge">AI 图</span><?php endif; ?>
                    </div>
                    <div class="aipe-img-actions">
                        <button type="button" class="button aipe-act-preview">预览译文</button>
                        <button type="button" class="button button-primary aipe-act-confirm" disabled>确认重绘入库</button>
                        <button type="button" class="button aipe-act-trans" data-kind="image_edit">重绘去字</button>
                        <label class="aipe-auto"><input type="checkbox" class="aipe-auto-apply" checked> 自动挂载</label>
                    </div>
                    <div class="aipe-job-status"></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h4>3）图片 AI 生成</h4>
    <div class="aipe-row">
        <select id="aipe-gen-template">
            <?php foreach ($templates as $key => $t) : ?>
                <?php if ($t['mode'] !== 'generate') continue; ?>
                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($t['label']); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="aipe-gen-slot">
            <option value="gallery">追加到图集</option>
            <option value="featured">设为特色图</option>
        </select>
        <label class="aipe-auto"><input type="checkbox" id="aipe-gen-apply" checked> 自动挂载</label>
        <button type="button" class="button" id="aipe-gen-btn">生成</button>
        <span id="aipe-gen-status" class="aipe-status"></span>
    </div>
    <p>
        <input type="text" id="aipe-gen-prompt" class="large-text" placeholder="自定义 prompt（留空用模板；可用 {title} 占位）">
    </p>

    <?php if ($jobs) : ?>
        <h4>任务记录（近 10 条）</h4>
        <table class="widefat aipe-table">
            <thead><tr><th>ID</th><th>类型</th><th>状态</th><th>结果</th><th>时间</th></tr></thead>
            <tbody>
                <?php foreach ($jobs as $j) : ?>
                    <tr>
                        <td>#<?php echo (int) $j->id; ?></td>
                        <td><?php echo esc_html($j->kind); ?></td>
                        <td><?php echo esc_html($j->status . ($j->error_code ? ' / ' . $j->error_code : '')); ?></td>
                        <td><?php
                            $r = $j->result ? json_decode($j->result, true) : null;
                            echo $r && !empty($r['url']) ? '<a href="' . esc_url($r['url']) . '" target="_blank">查看图片</a>' : '—';
                        ?></td>
                        <td><?php echo esc_html($j->updated_at); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
