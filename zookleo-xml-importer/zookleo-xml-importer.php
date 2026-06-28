<?php
/*
Plugin Name: Zookleo XML Importer
Description: Imports and updates Tundra products from ZooCenter.bg XML feed daily.
Version: 1.0.0
Author: Anton Antonov
*/

if (!defined('ABSPATH')) exit;

define('ZOOKLEO_FEED_URL',     'https://zoocenter.bg/app/feed/full');
define('ZOOKLEO_MANUFACTURER', 'tundra');
define('ZOOKLEO_MARKUP_DEFAULT', 1.30); // 30% over supplier price

// ========================
// SCHEDULED IMPORT SETUP
// ========================

register_activation_hook(__FILE__, 'zookleo_schedule_daily_event');
function zookleo_schedule_daily_event() {
    if (!wp_next_scheduled('zookleo_daily_import')) {
        $timezone = new DateTimeZone(get_option('timezone_string') ?: 'Europe/Sofia');
        $now      = new DateTime('now', $timezone);
        $run_time = new DateTime('23:00:00', $timezone);
        if ($run_time <= $now) $run_time->modify('+1 day');
        wp_schedule_event($run_time->getTimestamp(), 'daily', 'zookleo_daily_import');
    }
}

register_deactivation_hook(__FILE__, 'zookleo_clear_daily_event');
function zookleo_clear_daily_event() {
    $ts = wp_next_scheduled('zookleo_daily_import');
    if ($ts) wp_unschedule_event($ts, 'zookleo_daily_import');
}

add_action('zookleo_daily_import', function () {
    import_zookleo_products(true);
});

// ========================
// ADMIN PAGE
// ========================

add_action('admin_menu', function () {
    add_management_page(
        'Zookleo Product Import',
        'Zookleo Import',
        'manage_options',
        'zookleo-product-import',
        'zookleo_admin_page'
    );
});

function zookleo_admin_page() {
    // Save settings
    if (isset($_POST['save_zookleo_settings']) && check_admin_referer('zookleo_settings')) {
        update_option('zookleo_markup',      1 + floatval($_POST['markup_pct']) / 100);
        update_option('zookleo_price_round', sanitize_text_field($_POST['price_round']));
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    // Run import
    if (isset($_POST['run_zookleo_import']) && check_admin_referer('zookleo_run')) {
        $log = import_zookleo_products(false);
        echo '<div class="notice notice-success"><p>' . implode('<br>', array_map('esc_html', $log)) . '</p></div>';
    }

    $markup      = get_option('zookleo_markup', ZOOKLEO_MARKUP_DEFAULT);
    $markup_pct  = round(($markup - 1) * 100, 1);
    $price_round = get_option('zookleo_price_round', '0.10');
    $last_run    = get_option('zookleo_last_run', 'Never');
    $tz          = new DateTimeZone(get_option('timezone_string') ?: 'Europe/Sofia');
    $next_ts     = wp_next_scheduled('zookleo_daily_import');
    $next_str    = $next_ts ? (new DateTime('@' . $next_ts))->setTimezone($tz)->format('Y-m-d H:i T') : 'Not scheduled';

    ?>
    <div class="wrap">
        <h1>Zookleo XML Importer — Tundra</h1>

        <table class="widefat" style="margin-bottom:20px;max-width:600px">
            <tr><th>Feed URL</th>        <td><code><?php echo esc_html(ZOOKLEO_FEED_URL); ?></code></td></tr>
            <tr><th>Manufacturer filter</th><td><?php echo esc_html(ZOOKLEO_MANUFACTURER); ?></td></tr>
            <tr><th>Price markup</th>    <td><?php echo esc_html($markup_pct); ?>% (×<?php echo esc_html($markup); ?>)</td></tr>
            <tr><th>Price rounding</th>  <td>nearest <?php echo esc_html($price_round); ?> BGN</td></tr>
            <tr><th>Last import</th>     <td><?php echo esc_html($last_run); ?></td></tr>
            <tr><th>Next scheduled</th>  <td><?php echo esc_html($next_str); ?></td></tr>
        </table>

        <h2>Settings</h2>
        <form method="post">
            <?php wp_nonce_field('zookleo_settings'); ?>
            <table class="form-table">
                <tr>
                    <th>Price markup (%)</th>
                    <td>
                        <input type="number" name="markup_pct" value="<?php echo esc_attr($markup_pct); ?>"
                               min="0" max="500" step="0.5" style="width:80px" />
                        <p class="description">Retail = supplier price × (1 + markup/100). E.g. 30 → supplier ×1.30.</p>
                    </td>
                </tr>
                <tr>
                    <th>Price rounding (BGN)</th>
                    <td>
                        <select name="price_round">
                            <?php foreach (['0.01','0.10','0.50','1.00'] as $r): ?>
                                <option value="<?php echo $r; ?>" <?php selected($price_round, $r); ?>><?php echo $r; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Round up to nearest value after markup.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings', 'secondary', 'save_zookleo_settings'); ?>
        </form>

        <h2>Manual Import</h2>
        <form method="post">
            <?php wp_nonce_field('zookleo_run'); ?>
            <?php submit_button('Run Import Now', 'primary', 'run_zookleo_import'); ?>
        </form>
    </div>
    <?php
}

// ========================
// HELPERS
// ========================

function zookleo_retail_price($supplier_price) {
    $markup = get_option('zookleo_markup', ZOOKLEO_MARKUP_DEFAULT);
    $round  = floatval(get_option('zookleo_price_round', '0.10'));
    $raw    = floatval($supplier_price) * $markup;
    if ($round <= 0) return round($raw, 2);
    return ceil($raw / $round) * $round;
}

function zookleo_sideload_image($post_id, $url) {
    if (empty($url) || empty($post_id)) return false;
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $att_id = media_sideload_image($url, $post_id, null, 'id');
    return is_wp_error($att_id) ? false : $att_id;
}

function zookleo_get_or_create_term($name, $taxonomy, $parent_id = 0) {
    if (empty($name)) return 0;
    $term = get_term_by('name', $name, $taxonomy);
    if ($term) return $term->term_id;
    $res = wp_insert_term($name, $taxonomy, ['parent' => $parent_id]);
    return is_wp_error($res) ? 0 : $res['term_id'];
}

function zookleo_resolve_categories($cat, $sub, $sub_sub) {
    $ids = [];
    $parent_id = 0;
    foreach (array_filter([$cat, $sub, $sub_sub]) as $name) {
        $id = zookleo_get_or_create_term($name, 'product_cat', $parent_id);
        if ($id) { $ids[] = $id; $parent_id = $id; }
    }
    return $ids;
}

function zookleo_ensure_attribute($attr_label) {
    $slug     = wc_sanitize_taxonomy_name($attr_label);
    $taxonomy = 'pa_' . $slug;
    if (!taxonomy_exists($taxonomy)) {
        wc_create_attribute([
            'name'        => $attr_label,
            'slug'        => $slug,
            'type'        => 'select',
            'order_by'    => 'menu_order',
            'has_archives' => false,
        ]);
        register_taxonomy($taxonomy, 'product');
    }
    return $taxonomy;
}

// ========================
// CORE IMPORT
// ========================

function import_zookleo_products($is_cron = false) {
    $log = [];

    $response = wp_remote_get(ZOOKLEO_FEED_URL, ['timeout' => 120, 'sslverify' => false]);
    if (is_wp_error($response)) {
        $log[] = 'Failed to fetch feed: ' . $response->get_error_message();
        return $log;
    }

    $xml = simplexml_load_string(wp_remote_retrieve_body($response));
    if (!$xml) {
        $log[] = 'Invalid XML from feed.';
        return $log;
    }

    $items = [];
    foreach ($xml->xpath('.//product') as $p) {
        if (stripos((string)$p->manufacturer, ZOOKLEO_MANUFACTURER) !== false) {
            $items[] = $p;
        }
    }

    $log[] = 'Found ' . count($items) . ' Tundra products in feed.';

    foreach ($items as $item) {
        $log[] = zookleo_process_product($item);
        wp_cache_flush();
    }

    update_option('zookleo_last_run', current_time('Y-m-d H:i') . ' (' . count($items) . ' products)');
    return $log;
}

function zookleo_process_product($item) {
    $title      = sanitize_text_field((string)$item->title);
    $desc       = wp_kses_post((string)$item->description);
    $short_desc = wp_kses_post((string)$item->short_description);
    $parent_sku = sanitize_text_field((string)($item->sku ?: $item->product_code ?: $item->id));
    $cat_ids    = zookleo_resolve_categories(
        (string)$item->category,
        (string)$item->sub_category,
        (string)$item->sub_sub_category
    );

    // Parse cc_variants
    $variants = [];
    foreach ($item->xpath('.//cc_variants/variant') as $v) {
        $d = json_decode((string)$v, true);
        if (!empty($d['sku'])) {
            $d['retail_price'] = zookleo_retail_price($d['price'] ?? 0);
            $variants[] = $d;
        }
    }

    if (empty($variants)) return "Skip (no variants): $parent_sku";

    $attr_name   = !empty($variants[0]['p1']) ? $variants[0]['p1'] : 'Разфасовка';
    $attr_values = array_values(array_unique(array_filter(array_map(fn($v) => trim($v['v1'] ?? ''), $variants))));

    if (count($variants) === 1 || count($attr_values) <= 1) {
        return zookleo_upsert_simple($parent_sku, $title, $desc, $short_desc, $variants[0], $cat_ids);
    }

    return zookleo_upsert_variable($parent_sku, $title, $desc, $short_desc, $variants, $attr_name, $attr_values, $cat_ids);
}

function zookleo_upsert_simple($sku, $title, $desc, $short_desc, $v, $cat_ids) {
    $existing_id = wc_get_product_id_by_sku($sku);
    $product     = $existing_id ? wc_get_product($existing_id) : new WC_Product_Simple();
    $is_new      = !$existing_id;

    $product->set_name($title);
    $product->set_description($desc);
    $product->set_short_description($short_desc);
    $product->set_sku($sku);
    $product->set_regular_price($v['retail_price']);
    $product->set_price($v['retail_price']);
    $product->set_manage_stock(true);
    $qty = intval($v['quantity'] ?? 0);
    $product->set_stock_quantity($qty);
    $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
    if (!empty($cat_ids)) $product->set_category_ids($cat_ids);
    $id = $product->save();

    if ($is_new && !empty($v['image'])) {
        $att = zookleo_sideload_image($id, $v['image']);
        if ($att) { $product->set_image_id($att); $product->save(); }
    }

    return sprintf('%s simple: %s  price:%.2f  stock:%d', $is_new ? 'Created' : 'Updated', $sku, $v['retail_price'], $qty);
}

function zookleo_upsert_variable($parent_sku, $title, $desc, $short_desc, $variants, $attr_name, $attr_values, $cat_ids) {
    // Find parent by custom meta (avoids confusion with variation SKUs)
    $parent_id = null;
    $existing  = get_posts([
        'post_type'      => 'product',
        'meta_key'       => '_zookleo_sku',
        'meta_value'     => $parent_sku,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ]);
    if (!empty($existing)) $parent_id = $existing[0];

    $is_new = !$parent_id;
    $product = $is_new ? new WC_Product_Variable() : wc_get_product($parent_id);
    if (!$product || !$product->is_type('variable')) {
        $product  = new WC_Product_Variable();
        $is_new   = true;
        $parent_id = null;
    }

    $product->set_name($title);
    $product->set_description($desc);
    $product->set_short_description($short_desc);
    if (!empty($cat_ids)) $product->set_category_ids($cat_ids);

    // Ensure WC attribute taxonomy exists
    $taxonomy = zookleo_ensure_attribute($attr_name);
    foreach ($attr_values as $val) {
        if ($val && !term_exists($val, $taxonomy)) wp_insert_term($val, $taxonomy);
    }

    $attr = new WC_Product_Attribute();
    $attr->set_name($taxonomy);
    $attr->set_options($attr_values);
    $attr->set_visible(true);
    $attr->set_variation(true);
    $product->set_attributes([$attr]);

    $parent_id = $product->save();
    update_post_meta($parent_id, '_zookleo_sku', $parent_sku);

    $gallery_ids     = [];
    $var_log_parts   = [];

    foreach ($variants as $v) {
        $var_sku   = sanitize_text_field($v['sku'] ?? '');
        $var_val   = sanitize_text_field($v['v1'] ?? '');
        $var_qty   = intval($v['quantity'] ?? 0);
        $var_price = $v['retail_price'];
        $var_img   = $v['image'] ?? '';
        if (empty($var_sku)) continue;

        // Find existing variation
        $var_id = wc_get_product_id_by_sku($var_sku);
        if ($var_id) {
            $variation = wc_get_product($var_id);
            // Ensure it's a child of this parent
            if (!$variation || $variation->get_parent_id() !== $parent_id) $variation = null;
        }
        if (empty($variation)) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parent_id);
        }

        $variation->set_sku($var_sku);
        $variation->set_regular_price($var_price);
        $variation->set_price($var_price);
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity($var_qty);
        $variation->set_stock_status($var_qty > 0 ? 'instock' : 'outofstock');
        $variation->set_attributes([$taxonomy => $var_val]);
        $var_id = $variation->save();

        if (!empty($var_img) && !$variation->get_image_id()) {
            $att = zookleo_sideload_image($var_id, $var_img);
            if ($att) {
                $variation->set_image_id($att);
                $variation->save();
                $gallery_ids[] = $att;
            }
        } elseif ($variation->get_image_id()) {
            $gallery_ids[] = $variation->get_image_id();
        }

        $var_log_parts[] = "$var_sku/$var_val/×{$var_price}";
    }

    // Set parent images only on new products
    if ($is_new && !empty($gallery_ids)) {
        $product->set_image_id($gallery_ids[0]);
        $product->set_gallery_image_ids(array_slice($gallery_ids, 1));
        $product->save();
    }

    WC_Product_Variable::sync($parent_id);

    return sprintf('%s variable: %s  [%s]', $is_new ? 'Created' : 'Updated', $parent_sku, implode(', ', $var_log_parts));
}
