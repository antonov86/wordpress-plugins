<?php
/*
Plugin Name: Rolf's Farm XML Importer
Description: Imports and updates Rolf's Farm products from XML feed daily.
Version: 1.1
Author: Anton Antonov
*/


if (!defined('ABSPATH')) exit;

// ========================
// SCHEDULED IMPORT SETUP
// ========================

register_activation_hook(__FILE__, 'anipro_schedule_daily_event');
function anipro_schedule_daily_event() {
    if (!wp_next_scheduled('anipro_daily_stock_update')) {
        $timezone = new DateTimeZone(get_option('timezone_string') ?: 'Europe/Sofia');
        $now = new DateTime('now', $timezone);
        $run_time = new DateTime('23:00:00', $timezone);
        if ($run_time <= $now) $run_time->modify('+1 day');
        if ($run_time) wp_schedule_event($run_time->getTimestamp(), 'daily', 'anipro_daily_stock_update');
    }
}

register_deactivation_hook(__FILE__, 'anipro_clear_daily_event');
function anipro_clear_daily_event() {
    $timestamp = wp_next_scheduled('anipro_daily_stock_update');
    if ($timestamp) wp_unschedule_event($timestamp, 'anipro_daily_stock_update');
}

add_action('anipro_daily_stock_update', function() {
    import_anipro_products_from_xml(true);
});

// ========================
// ADMIN INTERFACE
// ========================

add_action('admin_menu', function() {
    add_management_page(
        'Anipro Product Import',
        'Anipro Import',
        'manage_options',
        'anipro-product-import',
        'anipro_import_page_callback'
    );
});

function anipro_import_page_callback() {
    if (isset($_POST['run_anipro_import']) && check_admin_referer('run_anipro_import_action')) {
        import_anipro_products_from_xml(false);
        echo '<div class="notice notice-success"><p>Anipro import completed.</p></div>';
    }

    echo '<div class="wrap"><h1>Anipro Product Import</h1>';
    echo '<form method="post">';
    wp_nonce_field('run_anipro_import_action');
    submit_button('Run Import Now', 'primary', 'run_anipro_import');
    echo '</form></div>';
}

// ========================
// IMAGE HANDLING
// ========================

/**
 * Download an image from URL and attach it to a product as featured image
 *
 * @param int $product_id The product ID to attach the image to
 * @param string $image_url The URL of the image to download
 * @return int|false Attachment ID on success, false on failure
 */
function anipro_download_and_attach_image($product_id, $image_url) {
    if (empty($image_url) || empty($product_id)) {
        return false;
    }

    // Required for media_sideload_image
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // Download and attach the image
    $attachment_id = media_sideload_image($image_url, $product_id, null, 'id');

    if (is_wp_error($attachment_id)) {
        return false;
    }

    // Set as featured image
    set_post_thumbnail($product_id, $attachment_id);

    return $attachment_id;
}

/**
 * Check if a product has a featured image
 *
 * @param int $product_id The product ID to check
 * @return bool True if product has a featured image
 */
function anipro_product_has_image($product_id) {
    return has_post_thumbnail($product_id);
}

// ========================
// CORE IMPORT FUNCTIONS
// ========================

function format_description($description) {
    $description = preg_replace('/([.!?])\s+/', "$1\n\n", $description);
    $description = trim($description);
    $description = wpautop($description, false);
    return $description;
}

function update_product_stock($product_id, $stock) {
    $product = wc_get_product($product_id);

    if (!$product) {
        return false;
    }

    if (!$product->get_manage_stock()) {
        $product->set_manage_stock(true);
    }

    $product->set_stock_quantity($stock);
    $product->set_stock_status(($stock > 0) ? 'instock' : 'outofstock');
    $product->save();

    return true;
}

function anipro_disable_missing_products($feed_skus) {
    $debug_messages = [];

    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'meta_query' => array(
            'relation' => 'OR',
            array(
                'key' => 'attribute_pa_brand',
                'value' => 'anipro'
            ),
            array(
                'key' => '_sku',
                'compare' => 'LIKE',
                'value' => 'ANI'
            ),
            array(
                'key' => '_sku',
                'compare' => 'REGEXP',
                'value' => '^ANI'
            )
        ),
        'fields' => 'ids',
    );

    $products = get_posts($args);

    foreach ($products as $product_id) {
        $product = wc_get_product($product_id);
        $sku = $product->get_sku();

        if (in_array($sku, $feed_skus)) {
            continue;
        }

        wp_set_object_terms($product_id, 'Anipro', 'pa_brand');

        if (update_product_stock($product_id, 0)) {
            $debug_messages[] = "Set missing product to out of stock: $sku";
        } else {
            $debug_messages[] = "Failed to update stock for: $sku";
        }
    }

    return $debug_messages;
}

function import_anipro_products_from_xml($is_cron = false) {
    $debug_messages = [];
    $feed_skus = [];
    $xml_url = 'https://miazoo.bg/index.php?route=extension/feed/google_merchant_center';

    $response = wp_remote_get($xml_url, [
        'timeout' => 240,
        'sslverify' => false,
        'headers' => ['Accept-Encoding' => 'gzip']
    ]);

    if (is_wp_error($response)) {
        $debug_messages[] = 'Anipro Import: Failed to fetch XML - ' . $response->get_error_message();
        if (!$is_cron) echo '<div class="notice notice-error"><p>'.implode('<br>', $debug_messages).'</p></div>';
        return;
    }

    $xml_body = wp_remote_retrieve_body($response);
    $xml = simplexml_load_string($xml_body);

    if (!$xml || !isset($xml->channel) || !isset($xml->channel->item)) {
        $debug_messages[] = "Anipro Import: Invalid XML format";
        if (!$is_cron) echo '<div class="notice notice-error"><p>'.implode('<br>', $debug_messages).'</p></div>';
        return;
    }

    $batch_size = 5;
    $items = [];
    foreach ($xml->channel->item as $item) {
        $g = $item->children('g', true);
        if (strtolower(trim((string)$g->brand)) === 'anipro') {
            $sku = trim((string)$item->id);
            if ($sku) {
                $feed_skus[] = $sku;
                $items[] = $item;
            }
        }
    }

    $total = count($items);
    $batches = array_chunk($items, $batch_size);
    $total_batches = count($batches);

    $debug_messages[] = "Found $total Anipro products in feed, processing in $total_batches batches";
    if (!$is_cron) echo '<div class="notice notice-info"><p>'.implode('<br>', $debug_messages).'</p></div>';

    for ($batch_offset = 0; $batch_offset < $total_batches; $batch_offset++) {
        $batch_debug = [];
        $batch_debug[] = "Processing batch ".($batch_offset+1)."/$total_batches";
        if (!$is_cron) echo '<div class="notice notice-info"><p>'.implode('<br>', $batch_debug).'</p></div>';

        process_anipro_batch($batches[$batch_offset], $batch_offset, $is_cron);

        if ($batch_offset < $total_batches - 1) {
            sleep(15);
            wp_cache_flush();
        }
    }

    $missing_products_log = anipro_disable_missing_products($feed_skus);
    $debug_messages = array_merge($debug_messages, $missing_products_log);

    if (!$is_cron) {
        echo '<div class="notice notice-success"><p>'.implode('<br>', $debug_messages).'</p></div>';
    }
}

function process_anipro_batch($batch_items, $batch_offset, $is_cron) {
    $debug_messages = [];

    foreach ($batch_items as $item) {
        $retry_count = 0;
        while ($retry_count < 5) {
            try {
                $g = $item->children('g', true);
                $sku = trim((string)$item->id);
                if (!$sku) continue;

                $title = sanitize_text_field((string)$item->title);
                $description = format_description((string)$item->description);
                $gtin = sanitize_text_field((string)$g->gtin);
                $availability = strtolower(trim((string)$g->availability));
                $price_raw = (string)$g->price;
                $image_url = trim((string)$g->image_link);

                preg_match('/([\d.]+)/', $price_raw, $matches);
                $price = isset($matches[1]) ? floatval($matches[1]) : 0;
                $stock = ($availability === 'in stock') ? 20 : 0;
                $product_id = wc_get_product_id_by_sku($sku);

                if (class_exists('WooCommerce')) {
                    $is_new = false;
                    $needs_image = false;

                    if (!$product_id) {
                        $product = get_posts([
                            'post_type' => 'product',
                            'meta_key' => '_sku',
                            'meta_value' => $sku,
                            'post_status' => 'any',
                            'numberposts' => 1,
                        ]);
                        if (!empty($product)) $product_id = $product[0]->ID;
                    }

                    if ($product_id) {
                        wp_update_post([
                            'ID' => $product_id,
                            'post_title' => $title
                        ]);

                        wp_set_object_terms($product_id, 'Anipro', 'pa_brand');

                        $attributes = get_post_meta($product_id, '_product_attributes', true);
                        if (empty($attributes)) {
                            $attributes = array();
                        }

                        if (empty($attributes['pa_brand'])) {
                            $attributes['pa_brand'] = array(
                                'name' => 'pa_brand',
                                'value' => '',
                                'is_visible' => 1,
                                'is_variation' => 0,
                                'is_taxonomy' => 1,
                            );
                            update_post_meta($product_id, '_product_attributes', $attributes);
                        }

                        update_post_meta($product_id, '_price', $price);
                        update_post_meta($product_id, '_regular_price', $price);

                        // Check if existing product needs an image
                        if (!anipro_product_has_image($product_id)) {
                            $needs_image = true;
                        }
                    } else {
                        $post_data = [
                            'post_title' => wp_strip_all_tags($title),
                            'post_content' => $description,
                            'post_status' => 'publish',
                            'post_type' => 'product',
                        ];
                        $product_id = wp_insert_post($post_data);
                        $is_new = true;
                        $needs_image = true; // New products always need an image

                        if ($product_id) {
                            wp_set_object_terms($product_id, 'simple', 'product_type');
                            update_post_meta($product_id, '_sku', $sku);
                            update_post_meta($product_id, '_manage_stock', 'yes');
                            update_post_meta($product_id, '_gtin', $gtin);
                            update_post_meta($product_id, '_price', $price);
                            update_post_meta($product_id, '_regular_price', $price);

                            wp_set_object_terms($product_id, 'Anipro', 'pa_brand');
                            update_post_meta($product_id, '_product_attributes', [
                                'pa_brand' => [
                                    'name' => 'pa_brand',
                                    'value' => '',
                                    'is_visible' => 1,
                                    'is_variation' => 0,
                                    'is_taxonomy' => 1,
                                ],
                            ]);
                        }
                    }

                    if ($product_id) {
                        // Handle image download if needed
                        $image_status = '';
                        if ($needs_image && !empty($image_url)) {
                            $attachment_id = anipro_download_and_attach_image($product_id, $image_url);
                            if ($attachment_id) {
                                $image_status = ', Image: downloaded';
                            } else {
                                $image_status = ', Image: failed';
                            }
                        } elseif ($needs_image && empty($image_url)) {
                            $image_status = ', Image: no URL';
                        }

                        if (update_product_stock($product_id, $stock)) {
                            $debug_messages[] = sprintf(
                                "%s product: %s (Stock: %d, Status: %s%s)",
                                $is_new ? "Created" : "Updated",
                                $sku,
                                $stock,
                                ($stock > 0) ? 'instock' : 'outofstock',
                                $image_status
                            );
                        } else {
                            $debug_messages[] = "Failed to update stock for: $sku";
                        }
                    }
                }
                break;
            } catch (Exception $e) {
                $retry_count++;
                sleep(5);
                if ($retry_count >= 5) {
                    $debug_messages[] = "Failed to process product after 5 attempts: " . $sku;
                }
            }
        }
    }

    if (function_exists('gc_collect_cycles')) gc_collect_cycles();
    if (!$is_cron && !empty($debug_messages)) {
        echo '<div class="notice notice-info"><p>'.implode('<br>', $debug_messages).'</p></div>';
    }
}
