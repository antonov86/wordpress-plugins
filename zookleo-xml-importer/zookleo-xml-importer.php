<?php
/*
Plugin Name: Miazoo Importer
Description: Imports and updates products from Miazoo.bg Google Merchant Center XML feed daily.
Version: 1.1.0
Author: Anton Antonov
*/

if (!defined('ABSPATH')) exit;

define('MIAZOO_FEED_URL',      'https://miazoo.bg/index.php?route=extension/feed/google_merchant_center');
define('MIAZOO_MARKUP_DEFAULT', 1.30);

// ========================
// SCHEDULED IMPORT SETUP
// ========================

register_activation_hook(__FILE__, 'miazoo_schedule_daily_event');
function miazoo_schedule_daily_event() {
    if (!wp_next_scheduled('miazoo_daily_import')) {
        $timezone = new DateTimeZone(get_option('timezone_string') ?: 'Europe/Sofia');
        $now      = new DateTime('now', $timezone);
        $run_time = new DateTime('23:00:00', $timezone);
        if ($run_time <= $now) $run_time->modify('+1 day');
        wp_schedule_event($run_time->getTimestamp(), 'daily', 'miazoo_daily_import');
    }
}

register_deactivation_hook(__FILE__, 'miazoo_clear_daily_event');
function miazoo_clear_daily_event() {
    $ts = wp_next_scheduled('miazoo_daily_import');
    if ($ts) wp_unschedule_event($ts, 'miazoo_daily_import');
}

add_action('miazoo_daily_import', function () {
    miazoo_run_import(true);
});

// ========================
// ADMIN PAGE
// ========================

add_action('admin_menu', function () {
    add_management_page(
        'Miazoo Product Import',
        'Miazoo Import',
        'manage_options',
        'miazoo-product-import',
        'miazoo_admin_page'
    );
});

function miazoo_admin_page() {
    if (isset($_POST['save_miazoo_settings']) && check_admin_referer('miazoo_settings')) {
        update_option('miazoo_brand_filter', sanitize_text_field($_POST['brand_filter']));
        update_option('miazoo_markup',       1 + floatval($_POST['markup_pct']) / 100);
        update_option('miazoo_price_round',  sanitize_text_field($_POST['price_round']));
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    if (isset($_POST['run_miazoo_import']) && check_admin_referer('miazoo_run')) {
        $log = miazoo_run_import(false);
        echo '<div class="notice notice-info"><p>' . implode('<br>', array_map('esc_html', $log)) . '</p></div>';
    }

    $brand       = get_option('miazoo_brand_filter', '');
    $markup      = get_option('miazoo_markup', MIAZOO_MARKUP_DEFAULT);
    $markup_pct  = round(($markup - 1) * 100, 1);
    $price_round = get_option('miazoo_price_round', '0.10');
    $last_run    = get_option('miazoo_last_run', 'Never');
    $tz          = new DateTimeZone(get_option('timezone_string') ?: 'Europe/Sofia');
    $next_ts     = wp_next_scheduled('miazoo_daily_import');
    $next_str    = $next_ts ? (new DateTime('@' . $next_ts))->setTimezone($tz)->format('Y-m-d H:i T') : 'Not scheduled';
    ?>
    <div class="wrap">
        <h1>Miazoo Importer</h1>

        <table class="widefat" style="margin-bottom:20px;max-width:640px">
            <tr><th>Feed URL</th>          <td><code><?php echo esc_html(MIAZOO_FEED_URL); ?></code></td></tr>
            <tr><th>Brand filter</th>      <td><?php echo $brand ? esc_html($brand) : '<em>not set</em>'; ?></td></tr>
            <tr><th>Price markup</th>      <td><?php echo esc_html($markup_pct); ?>% (×<?php echo esc_html($markup); ?>)</td></tr>
            <tr><th>Price rounding</th>    <td>nearest <?php echo esc_html($price_round); ?> BGN</td></tr>
            <tr><th>Last import</th>       <td><?php echo esc_html($last_run); ?></td></tr>
            <tr><th>Next scheduled</th>    <td><?php echo esc_html($next_str); ?></td></tr>
        </table>

        <h2>Settings</h2>
        <form method="post">
            <?php wp_nonce_field('miazoo_settings'); ?>
            <table class="form-table">
                <tr>
                    <th>Brand filter</th>
                    <td>
                        <input type="text" name="brand_filter" value="<?php echo esc_attr($brand); ?>"
                               style="width:220px" placeholder="e.g. Tundra" />
                        <p class="description">Case-insensitive match against <code>g:brand</code> in the feed. Leave empty to import all brands.</p>
                    </td>
                </tr>
                <tr>
                    <th>Price markup (%)</th>
                    <td>
                        <input type="number" name="markup_pct" value="<?php echo esc_attr($markup_pct); ?>"
                               min="0" max="500" step="0.5" style="width:80px" />
                        <p class="description">Retail = supplier price × (1 + markup/100).</p>
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
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings', 'secondary', 'save_miazoo_settings'); ?>
        </form>

        <h2>Manual Import</h2>
        <form method="post">
            <?php wp_nonce_field('miazoo_run'); ?>
            <?php submit_button('Run Import Now', 'primary', 'run_miazoo_import'); ?>
        </form>
    </div>
    <?php
}

// ========================
// HELPERS
// ========================

function miazoo_retail_price($supplier_price) {
    $markup = get_option('miazoo_markup', MIAZOO_MARKUP_DEFAULT);
    $round  = floatval(get_option('miazoo_price_round', '0.10'));
    $raw    = floatval($supplier_price) * $markup;
    if ($round <= 0) return round($raw, 2);
    return ceil($raw / $round) * $round;
}

function miazoo_sideload_image($post_id, $url) {
    if (empty($url) || empty($post_id)) return false;
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $att_id = media_sideload_image($url, $post_id, null, 'id');
    return is_wp_error($att_id) ? false : $att_id;
}

// ========================
// CORE IMPORT
// ========================

function miazoo_run_import($is_cron = false) {
    $log = [];

    $response = wp_remote_get(MIAZOO_FEED_URL, ['timeout' => 240, 'sslverify' => false,
        'headers' => ['Accept-Encoding' => 'gzip']]);

    if (is_wp_error($response)) {
        $log[] = 'Failed to fetch feed: ' . $response->get_error_message();
        return $log;
    }

    $xml = simplexml_load_string(wp_remote_retrieve_body($response));
    if (!$xml || !isset($xml->channel->item)) {
        $log[] = 'Invalid XML or empty feed.';
        return $log;
    }

    $brand_filter = strtolower(trim(get_option('miazoo_brand_filter', '')));
    $feed_skus    = [];
    $items        = [];

    foreach ($xml->channel->item as $item) {
        $g     = $item->children('g', true);
        $brand = strtolower(trim((string)$g->brand));
        if ($brand_filter && $brand !== $brand_filter) continue;
        $sku = trim((string)$item->id);
        if (!$sku) continue;
        $feed_skus[] = $sku;
        $items[]     = $item;
    }

    $log[] = 'Found ' . count($items) . ' matching products in feed'
           . ($brand_filter ? " (brand: $brand_filter)" : ' (all brands)') . '.';

    foreach ($items as $item) {
        $log[] = miazoo_process_item($item);
    }

    update_option('miazoo_last_run', current_time('Y-m-d H:i') . ' (' . count($items) . ' products)');
    return $log;
}

function miazoo_process_item($item) {
    $g            = $item->children('g', true);
    $sku          = trim((string)$item->id);
    $title        = sanitize_text_field((string)$item->title);
    $description  = wp_kses_post((string)$item->description);
    $gtin         = sanitize_text_field((string)$g->gtin);
    $brand        = sanitize_text_field((string)$g->brand);
    $image_url    = trim((string)$g->image_link);
    $availability = strtolower(trim((string)$g->availability));

    preg_match('/([\d.]+)/', (string)$g->price, $m);
    $supplier_price = isset($m[1]) ? floatval($m[1]) : 0;
    $retail_price   = miazoo_retail_price($supplier_price);
    $stock          = ($availability === 'in stock') ? 20 : 0;

    $product_id = wc_get_product_id_by_sku($sku);
    $is_new     = !$product_id;

    $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();
    if (!$product) return "Error loading product: $sku";

    $product->set_name($title);
    $product->set_description($description);
    $product->set_sku($sku);
    $product->set_regular_price($retail_price);
    $product->set_price($retail_price);
    $product->set_manage_stock(true);
    $product->set_stock_quantity($stock);
    $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');

    if ($gtin) update_post_meta($product->get_id() ?: 0, '_gtin', $gtin);

    // Brand attribute
    if ($brand) {
        $product_id_temp = $product->get_id() ?: wp_insert_post(['post_type' => 'product', 'post_status' => 'draft', 'post_title' => $title]);
        wp_set_object_terms($product_id_temp, $brand, 'pa_brand');
        $attrs = get_post_meta($product_id_temp, '_product_attributes', true) ?: [];
        if (empty($attrs['pa_brand'])) {
            $attrs['pa_brand'] = ['name' => 'pa_brand', 'value' => '', 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 1];
            update_post_meta($product_id_temp, '_product_attributes', $attrs);
        }
    }

    $product_id = $product->save();

    if ($is_new && !empty($image_url)) {
        $att = miazoo_sideload_image($product_id, $image_url);
        if ($att) { $product->set_image_id($att); $product->save(); }
    }

    return sprintf('%s: %s  price:%.2f  stock:%d', $is_new ? 'Created' : 'Updated', $sku, $retail_price, $stock);
}
