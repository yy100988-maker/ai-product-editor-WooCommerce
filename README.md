# AI 产品编辑器 (AI Product Editor for WooCommerce)

导入后在**商品编辑页**直接做 AI 编辑。独立插件，对所有来源的商品生效（1688 / 店小秘 / 手工建品）。

| 能力 | 说明 |
|---|---|
| **标题翻译** | LLM 中→英（目标语言可配），术语库优先，缓存命中不花钱 |
| **属性词翻译** | 只翻属性名/属性值（颜色→Color，红色→Red）；**SKU 编码永不动**；term 改名不改 slug，变体不断链 |
| **图片 AI 翻译** | Vision OCR 定位图内中文 → 翻译 → GD 擦除原字、按原版式渲染译文；先覆盖预览、确认再入库，原图保留 |
| **图片 AI 生成** | 白底主图 / 场景图（文生图）、原图重绘去字（图生图）；一键挂载为特色图/图集 |

## 安装

1. 把本目录（`ai-product-editor`）上传到 `/wp-content/plugins/`，后台启用
2. 环境：WordPress 6.4+，WooCommerce 8.0+，PHP 7.4+；图片翻译需要 `gd` 扩展
3. 进 **WooCommerce → AI 产品编辑** 填供应商 Key（OpenAI 协议即可，默认 SiliconFlow 主 + OpenRouter 备）

## 使用

1. 打开任意商品编辑页，下拉到 **AI 产品编辑** 框
2. **标题与属性词**：点「AI 预览翻译」→ 检查/修改译文 →「应用到商品」
3. **图片翻译**：点「预览译文」→ 译文气泡覆盖在原图上（悬停看原文，可直接改气泡文字）→「确认重绘入库」
4. **重绘去字**：图生图整图重绘，适合底纹复杂、擦除效果差的图
5. **生成**：选模板（白底主图/场景图）→ 生成 → 自动挂载
6. 图片任务是后台异步的，可以关页面，回来轮询结果；近 10 条任务记录直接显示在编辑框底部

## 图片管线对照 MoeTranslate v5.2.0

参考：https://github.com/murangogo/MoeTranslate/tree/v5.2.0（Android 图片翻译 App）。

| MoeTranslate | 本插件 | 说明 |
|---|---|---|
| ML Kit 本地 OCR（给框+原文） | Vision 模型 OCR（0-1000 框+原文） | PHP 跑不了端侧模型，用视觉模型等价实现“检测” |
| OCR 与翻译解耦（自选翻译 API） | OCR 只出框+原文，译文走统一文本管线 | 术语库/缓存/目标语言三处一致 |
| block/line 层级 | `normalize_boxes()` 同行合并+阅读排序 | 模型分行偏碎时拼回整句再翻 |
| 悬浮窗覆盖显示译文 | metabox 覆盖层气泡预览 | 先看后写，气泡可改字 |
| ——（端侧 inpaint） | 外扩擦除 + 译文描边 | PHP 无 LaMa，用底色填充+描边保可读 |

OCR 结果按文件 md5 进 `aipe_cache`（kind=ocr），同一张图重复操作不重复调 vision 模型；
预览结果可随任务下发（payload.items），任务执行时跳过 OCR+翻译。

## 第三方图片翻译通道

商品编辑页默认用 Vision 模型做 OCR；切到百度通道后 OCR+翻译+回填一次完成。

| | Vision 模型 | 百度翻译开放平台 |
|---|---|---|
| 接口 | 自配 OpenAI 协议 vision 模型 | `fanyi-api.baidu.com/api/trans/sdk/picture`（[官方文档](https://fanyi-api.baidu.com/doc/26)） |
| 鉴权 | Bearer Key | APP ID + 密钥，`sign=md5(appid+md5(原图字节)+salt+APICUID+mac+密钥)` |
| 返回 | 只有框+原文，译文再走 LLM | 框+原文+译文+整图回填（`pasteImg`，优先直接入库） |
| 目标语言 | 任意（en/ms/th/vi/es） | 仅百度列表（en/ms 等，无 th/vi/es） |
| 开通 | 各模型供应商 | 百度翻译开放平台控制台 → 开通图片翻译（每月 1000 次免费） |

Key 填在 **WooCommerce → AI 产品编辑** 设置页（脱敏存储，不进仓库），填完点「测试百度连接」一键验证签名。
超限图（>4M/边>4096）本地先缩小再提交，签名用提交字节、坐标按比例换回原图。

## 设计取舍

- **Key 直连供应商**：密钥只存本站 `wp_options`（autoload=false，页面脱敏），不经过 SaaS、不走 credits
- **翻译缓存表** `aipe_cache`：同原文+术语库+目标语言命中即返回，图片里的重复文案也不重复花钱
- **任务表** `aipe_jobs` + Action Scheduler（Woo 自带；缺失时 WP-Cron 单次事件兜底）
- **全局属性标签改名**（如“颜色”→“Color”）影响全站，应用前二次确认；term 只改名不改 slug
- **原图永不动**：AI 图都是新附件（记 `_aipe_source_attachment`），挂载位置可选

## 文件结构

```
ai-product-editor/
├── ai-product-editor.php       # 主入口、激活建表、HPOS 声明
├── uninstall.php               # 只删自己的表和设置
├── includes/
│   ├── class-settings.php      # 供应商/语言/术语库/图片输出设置
│   ├── class-client.php        # OpenAI 协议客户端（chat/vision/文生图/图生图，fallback 链）
│   ├── class-translate.php     # 文本翻译（术语库预替换 + 缓存）
│   ├── class-imagetrans.php    # 图片翻译（OCR → 合并排序 → 翻译 → GD 擦除重绘）
│   ├── class-imagegen.php      # 图片生成（模板 + 入库）
│   ├── class-jobs.php          # 异步任务
│   ├── class-product.php       # 商品读写（标题/属性/挂载），SKU 只读
│   └── class-admin.php         # 设置页 + metabox + AJAX
├── views/                      # page-settings.php / metabox.php
├── assets/                     # admin.js / admin.css
└── tests/                      # 离线验证（桩 + 断言，无需 WP）
```

## 离线测试（无需 WordPress，需要 PHP CLI + GD）

```bash
php tests/test-offline.php        # 文本管线 + 任务 + 商品落库
php tests/test-image.php          # OCR 解析/合并 + GD 擦除重绘（真实出图到系统临时目录）
```

