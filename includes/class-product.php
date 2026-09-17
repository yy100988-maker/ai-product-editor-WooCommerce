<?php
/**
 * 商品读写：标题 / 属性词落库 / 图片挂载。SKU 编码只读展示，永不改写。
 *
 * @package AIPE
 */

defined('ABSPATH') || exit;

class AIPE_Product {

    /**
     * 收集可编辑项（metabox 渲染 + 预览用）。
     */
    public static function collect($product_id) {
        $p = wc_get_product((int) $product_id);
        if (!$p) {
            return null;
        }
        $data = [
            'id' => $p->get_id(),
            'type' => $p->get_type(),
            'title' => $p->get_name(),
            'sku' => $p->get_sku(),
            'attributes' => [], // ['key'=>['label'=>..,'options'=>[...],'is_taxonomy'=>bool]]
            'variations' => [],
            'images' => [],     // ['attachment_id'=>..,'url'=>..,'slot'=>featured|gallery|variation|desc,'label'=>..]
        ];
        foreach ($p->get_attributes() as $key => $attr) {
            /** @var WC_Product_Attribute $attr */
            $opts = $attr->get_options();
            if ($attr->is_taxonomy()) {
                $names = [];
                foreach ($opts as $term_id) {
                    $t = get_term((int) $term_id);
                    if ($t && !is_wp_error($t)) {
                        $names[] = $t->name;
                    }
                }
                $opts = $names;
            }
            $data['attributes'][$key] = [
                'label' => wc_attribute_label($key),
                'options' => array_values($opts),
                'is_taxonomy' => $attr->is_taxonomy(),
            ];
        }
        if ($p->is_type('variable')) {
            foreach ($p->get_children() as $vid) {
                $v = wc_get_product($vid);
                if (!$v) {
                    continue;
                }
                $data['variations'][] = [
                    'id' => $vid,
                    'sku' => $v->get_sku(),
                    'attributes' => $v->get_attributes(),
                    'image_id' => (int) $v->get_image_id(),
                ];
            }
        }
        $seen = [];
        $push = function ($att_id, $slot, $label) use (&$data, &$seen) {
            $att_id = (int) $att_id;
            if (!$att_id || isset($seen[$att_id . $slot])) {
                return;
            }
            $seen[$att_id . $slot] = true;
            $data['images'][] = [
                'attachment_id' => $att_id,
                'url' => wp_get_attachment_url($att_id),
                'slot' => $slot,
                'label' => $label,
                'is_ai' => AIPE_ImageTrans::is_translated($att_id),
            ];
        };
        $push($p->get_image_id(), 'featured', '特色图');
        foreach ((array) $p->get_gallery_image_ids() as $i => $gid) {
            $push($gid, 'gallery', '图集 #' . ($i + 1));
        }
        foreach ($data['variations'] as $v) {
            if ($v['image_id']) {
                $push($v['image_id'], 'variation', '变体图 #' . $v['id']);
            }
        }
        foreach (self::desc_attachment_ids($p) as $did) {
            $push($did, 'desc', '描述内图');
        }
        return $data;
    }

    /**
     * 描述 HTML 里本地附件图（class="wp-image-123"）。
     */
    public static function desc_attachment_ids($product) {
        $html = method_exists($product, 'get_description') ? (string) $product->get_description() : '';
        if ($html === '') {
            return [];
        }
        preg_match_all('/wp-image-(\d+)/', $html, $m);
        return array_map('intval', array_unique($m[1]));
    }

    public static function apply_title($product_id, $title) {
        $p = wc_get_product((int) $product_id);
        if (!$p) {
            throw new Exception('AIPE_NO_PRODUCT');
        }
        $title = trim((string) $title);
        if ($title === '') {
            throw new Exception('AIPE_EMPTY_TITLE');
        }
        $p->set_name(wp_kses_post($title));
        $p->save();
        update_post_meta($p->get_id(), '_aipe_title_en', $title);
        return $title;
    }

    /**
     * 属性词落库：
     * - 全局属性（pa_*）：term 只改名不改 slug（变体不断链）；属性标签全局改名。
     * - 自定义属性：父商品 options 改词 + 各变体 attribute_* 元数据同步改词。
     * $name_map: [旧标签 => 新标签]；$value_map: [旧值 => 新值]。
     */
    public static function apply_attributes($product_id, $name_map, $value_map) {
        $p = wc_get_product((int) $product_id);
        if (!$p) {
            throw new Exception('AIPE_NO_PRODUCT');
        }
        $name_map = array_filter((array) $name_map);
        $value_map = array_filter((array) $value_map);
        if (!$name_map && !$value_map) {
            return ['renamed_terms' => 0, 'updated_variations' => 0];
        }
        $renamed = 0;
        $attrs = $p->get_attributes();
        foreach ($attrs as $key => $attr) {
            /** @var WC_Product_Attribute $attr */
            if ($attr->is_taxonomy()) {
                // term 改名（slug 不动）
                foreach ((array) $attr->get_options() as $term_id) {
                    $t = get_term((int) $term_id);
                    if (!$t || is_wp_error($t)) {
                        continue;
                    }
                    if (isset($value_map[$t->name])) {
                        wp_update_term($t->term_id, $key, ['name' => $value_map[$t->name]]);
                        $renamed++;
                    }
                }
                // 属性标签全局改名（如颜色→Color，影响所有用该属性的商品，后台会提示）
                $label = wc_attribute_label($key);
                if (isset($name_map[$label])) {
                    self::rename_taxonomy_label($key, $name_map[$label]);
                }
            } else {
                $label = $attr->get_name();
                if (isset($name_map[$label])) {
                    $attr->set_name($name_map[$label]);
                }
                $opts = (array) $attr->get_options();
                $new_opts = [];
                foreach ($opts as $o) {
                    $new_opts[] = $value_map[$o] ?? $o;
                }
                $attr->set_options($new_opts);
                $attrs[$key] = $attr;
                // 变体 attribute_* 元数据同步
                $meta_key = 'attribute_' . sanitize_title($label);
                foreach ($p->get_children() as $vid) {
                    $cur = get_post_meta($vid, $meta_key, true);
                    if ($cur !== '' && isset($value_map[$cur])) {
                        update_post_meta($vid, $meta_key, $value_map[$cur]);
                    }
                }
            }
        }
        $p->set_attributes($attrs);
        $p->save();
        // 自定义属性改名后变体 meta key 也要跟随（attribute_旧名 → attribute_新名）
        foreach ($name_map as $old => $new) {
            $old_key = 'attribute_' . sanitize_title($old);
            $new_key = 'attribute_' . sanitize_title($new);
            if ($old_key === $new_key) {
                continue;
            }
            foreach ($p->get_children() as $vid) {
                $cur = get_post_meta($vid, $old_key, true);
                if ($cur !== '') {
                    update_post_meta($vid, $new_key, $value_map[$cur] ?? $cur);
                    delete_post_meta($vid, $old_key);
                }
            }
        }
        if ($p->is_type('variable') && class_exists('WC_Product_Variable')) {
            WC_Product_Variable::sync($p->get_id());
        }
        $updated = $p->is_type('variable') ? count($p->get_children()) : 0;
        update_post_meta($p->get_id(), '_aipe_attrs_en', ['names' => $name_map, 'values' => $value_map, 'at' => current_time('mysql')]);
        return ['renamed_terms' => $renamed, 'updated_variations' => $updated];
    }

    protected static function rename_taxonomy_label($taxonomy, $new_label) {
        global $wpdb;
        // pa_color → attribute_name=color 行的 attribute_label
        $name = preg_replace('/^pa_/', '', $taxonomy);
        $table = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
        // 表不存在（WC 未建）则跳过，不报错。
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }
        $wpdb->update($table, ['attribute_label' => $new_label], ['attribute_name' => $name]);
        delete_transient('wc_attribute_taxonomies');
    }

    /**
     * 新图挂载：featured 设特色图；gallery 追加（replace_id 在图集里则原位替换）；
     * variation 用 replace_id 传变体 ID；desc 替换描述内图地址。返回 true=已挂载。
     */
    public static function attach_image($product_id, $new_id, $slot = 'gallery', $replace_id = 0) {
        $p = wc_get_product((int) $product_id);
        if (!$p || !$new_id) {
            return false;
        }
        $new_id = (int) $new_id;
        $replace_id = (int) $replace_id;
        if ($slot === 'featured') {
            $p->set_image_id($new_id);
            $p->save();
            return true;
        }
        if ($slot === 'variation' && $replace_id) {
            // replace_id 复用为变体 ID
            $v = wc_get_product($replace_id);
            if ($v && method_exists($v, 'set_image_id')) {
                $v->set_image_id($new_id);
                $v->save();
                return true;
            }
            return false;
        }
        if ($slot === 'desc' && $replace_id) {
            $old_url = wp_get_attachment_url($replace_id);
            $new_url = wp_get_attachment_url($new_id);
            $desc = $p->get_description();
            if ($old_url && $new_url && strpos($desc, $old_url) !== false) {
                $p->set_description(str_replace($old_url, $new_url, $desc));
                $p->save();
                return true;
            }
            return false;
        }
        // gallery（含 replace 原位替换）
        $gallery = array_map('intval', (array) $p->get_gallery_image_ids());
        if ($replace_id && in_array($replace_id, $gallery, true)) {
            $gallery = array_map(function ($g) use ($replace_id, $new_id) {
                return $g === $replace_id ? $new_id : $g;
            }, $gallery);
        } elseif ($replace_id && (int) $p->get_image_id() === $replace_id) {
            $p->set_image_id($new_id); // 原图是特色图 → 新图转正
        } elseif (!in_array($new_id, $gallery, true)) {
            $gallery[] = $new_id;
        }
        $p->set_gallery_image_ids($gallery);
        $p->save();
        return true;
    }
}
