<?php
/*
Plugin Name: Anipro XML Importer
Description: Imports and updates Anipro products from XML feed daily.
Version: 1.6
Author: Anton Antonov
*/


if (!defined('ABSPATH')) exit;

// Recipients are intentionally empty here — this repo is public. Set the real
// addresses per site in wp-config.php; these guards mean a define there wins.
// Empty falls back to admin_email, which is often NOT who should get these.
//
// Where FAILURE alerts go (network errors, bad XML, batch retries, staleness).
// Comma-separate for multiple recipients, or leave empty to use admin_email.
if (!defined('ANIPRO_IMPORT_ALERT_EMAIL')) {
    define('ANIPRO_IMPORT_ALERT_EMAIL', '');
}
// Where NEW-PRODUCT digest emails go (one email per import run if new drafts
// were created and need admin review). Different recipient — content team.
if (!defined('ANIPRO_NEW_PRODUCT_NOTIFY_EMAIL')) {
    define('ANIPRO_NEW_PRODUCT_NOTIFY_EMAIL', '');
}
// How long without a successful run before we send a "cron is stale" alert.
if (!defined('ANIPRO_IMPORT_STALE_AFTER_DAYS')) {
    define('ANIPRO_IMPORT_STALE_AFTER_DAYS', 3);
}

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

// Self-heal: if the scheduled event is somehow missing (activation hook never
// fired, or cron queue got cleared), re-register on every request. Cheap check.
add_action('init', function() {
    if (!wp_next_scheduled('anipro_daily_stock_update')) {
        anipro_schedule_daily_event();
    }
});

// Staleness monitoring is handled by the Miazoo Cron Watchdog mu-plugin
// (wp-content/mu-plugins/miazoo-cron-watchdog.php). This file still writes
// anipro_last_successful_import on every successful run — the watchdog
// reads that marker to decide whether to alert.

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

    $next = wp_next_scheduled('anipro_daily_stock_update');
    if ($next) {
        echo '<p><strong>Next scheduled run:</strong> ' . esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'Y-m-d H:i:s')) . ' (' . esc_html(wp_timezone_string()) . ')</p>';
    } else {
        echo '<p style="color:#c00;"><strong>No cron scheduled!</strong> Run import manually or reactivate the plugin.</p>';
    }

    $last_success = get_option('anipro_last_successful_import');
    if ($last_success) {
        echo '<p><strong>Last successful run:</strong> ' . esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $last_success), 'Y-m-d H:i:s')) . ' (' . esc_html(wp_timezone_string()) . ')</p>';
    }

    $last_error = get_option('anipro_import_last_error');
    if ($last_error && is_array($last_error)) {
        $error_time = get_date_from_gmt(gmdate('Y-m-d H:i:s', $last_error['time']), 'Y-m-d H:i:s');
        $is_after_success = $last_success && $last_error['time'] > $last_success;
        $color = $is_after_success ? '#c00' : '#666';
        echo '<p style="color:' . $color . '"><strong>Last error (' . esc_html($last_error['type']) . '):</strong> '
            . esc_html($error_time) . ' — ' . esc_html($last_error['message']) . '</p>';
    }

    // Non-alarming auto-repair marker. If the create-path fix regresses, the
    // final verify block self-heals dropped sku/price rows and records it here
    // (no email fires). A growing count means FIX A has a hole to investigate.
    $autorepair = get_option('anipro_import_last_autorepair');
    if ($autorepair && is_array($autorepair) && !empty($autorepair['time'])) {
        $ar_time = get_date_from_gmt(gmdate('Y-m-d H:i:s', $autorepair['time']), 'Y-m-d H:i:s');
        echo '<p style="color:#666;"><strong>Last self-heal (auto-repair):</strong> '
            . esc_html($ar_time) . ' (' . esc_html(wp_timezone_string()) . ') — repaired field-drops on '
            . intval($autorepair['count']) . ' product(s). If this keeps growing, the create-path fix has regressed.</p>';
    }

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

    // Set the featured image via set_post_thumbnail() (a direct _thumbnail_id
    // postmeta write) rather than wc_get_product()->save(). The WC object save
    // was itself the last vulnerable sink for the 2026-07-08 data-loss bug:
    // for a just-created product, wc_get_product() hydrates from this host's
    // stale, empty SG Memcached meta snapshot, and the subsequent save() makes
    // WC DELETE the real _sku/_regular_price/_price rows (see the CREATE block
    // in process_anipro_batch()). set_post_thumbnail() writes ONLY _thumbnail_id
    // — exactly the key WC_Product::get_image_id() reads — and never triggers a
    // WC save, so it cannot drop any other field, for new OR existing products.
    // clean_post_cache() + wc_delete_product_transients() keep any later
    // same-request read of this product coherent.
    set_post_thumbnail($product_id, $attachment_id);
    clean_post_cache($product_id);
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($product_id);
    }

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
    // Force a fresh read, bypassing any stale object-cache snapshot from a
    // meta write moments earlier in the same request (observed to cause
    // wc_get_product() to construct an object with empty core props right
    // after creation, which WC_Product::save() then persists as empty —
    // see the 2026-07-08 create-path fix).
    clean_post_cache($product_id);
    $product = wc_get_product($product_id);

    if (!$product) {
        return false;
    }

    // Refuse to persist an object whose SKU reads empty. On this host a
    // just-created product can hydrate from a stale, empty SG Memcached meta
    // snapshot even after clean_post_cache(); saving that object makes WC
    // delete the real _sku/_regular_price/_price rows (the 2026-07-08
    // data-loss bug). A genuine Anipro product always has a SKU, so an empty
    // read here means the in-memory object is untrustworthy — bail rather than
    // corrupt the DB. This is the shared sink hardened once, so any caller
    // (the per-pid loop, anipro_disable_missing_products(), or future callers)
    // handed a freshly-created id in the stale window is protected.
    if ($product->get_sku() === '') {
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

/**
 * Read a single postmeta value directly from the database, bypassing
 * WordPress's object cache entirely.
 *
 * Needed because this host's object cache (SG Cachepress / Memcache) was
 * empirically observed (2026-07-08, via SAVEQUERIES + raw SQL tracing) to
 * serve a stale, empty meta snapshot to get_post_meta()/wc_get_product() for
 * a post written to moments earlier in the same request — the DB row
 * genuinely has the correct value, confirmed via direct uncached SQL, but
 * the cached read shows it as missing. Neither clean_post_cache() nor an
 * explicit wp_cache_delete($id, 'post_meta') reliably busted this before
 * the next read in testing. Use this for any verification that must be
 * trustworthy regardless of that caching quirk.
 *
 * @param int $post_id
 * @param string $meta_key
 * @return string Empty string if not found (matches get_post_meta() single=true behavior).
 */
function anipro_raw_get_meta($post_id, $meta_key) {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
        $post_id, $meta_key
    ));
    return $value === null ? '' : $value;
}

/**
 * Return ALL product + variation IDs that share a given SKU.
 *
 * Polylang translation pairs share SKUs (via our Polylang SKU-sharing filter),
 * so a single feed SKU can map to two or more products (EN + BG, sometimes
 * more). Every matching record must be updated on each import — otherwise
 * translated products drift out of sync with the feed.
 *
 * @param string $sku
 * @return int[]
 */
function anipro_get_all_ids_for_sku($sku) {
    if (empty($sku)) return array();
    $ids = get_posts(array(
        'post_type'   => array('product', 'product_variation'),
        'meta_key'    => '_sku',
        'meta_value'  => $sku,
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields'      => 'ids',
    ));
    return $ids ? array_map('intval', $ids) : array();
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
        if (!$product) {
            continue;
        }
        $sku = $product->get_sku();

        // Empty SKU => either a stale-cache read (a product created in the
        // final batch whose object cache hasn't settled) or a genuinely
        // SKU-less product. Either way it must NOT be treated as "missing from
        // the feed" and forced out of stock: that mis-flags a live product and
        // routes it through update_product_stock()'s save. Skip it.
        if ($sku === '') {
            continue;
        }

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
    $critical_issues = []; // escalated to email alerts
    $new_products = [];    // collected across batches; emailed at end of run
    $feed_skus = [];
    $xml_url = 'https://miazoo.bg/miazoo_b2b_feed.php';

    // Do NOT set Accept-Encoding manually — WordPress's cURL transport already
    // sets CURLOPT_ENCODING='' which negotiates and auto-decompresses gzip,
    // deflate, and br. Setting it manually here breaks auto-decompression and
    // causes the raw compressed bytes to reach simplexml_load_string.
    //
    // Retry-with-backoff (added 2026-07-08): a single fetch failure used to
    // abort the whole run immediately, with no retry until the next day's
    // cron. Root cause of the 2026-07-08 incident (cURL error 28, 240s
    // timeout, ~5.6MB of ~16.3MB received) was a transient slowdown on the
    // remote feed source — a re-test moments later completed in ~1s at full
    // speed, so the feed/timeout sizing itself is fine (240s gives >200x
    // headroom under normal conditions). One retry after a short backoff
    // gives a transient blip a chance to clear before we escalate to email.
    $max_fetch_attempts = 2; // 1 initial attempt + 1 retry
    $fetch_backoff_seconds = 20;
    $fetch_errors = [];
    $response = null;

    for ($fetch_attempt = 1; $fetch_attempt <= $max_fetch_attempts; $fetch_attempt++) {
        $response = wp_remote_get($xml_url, [
            'timeout' => 240,
            'sslverify' => false,
        ]);

        if (!is_wp_error($response)) {
            break;
        }

        $fetch_errors[] = $response->get_error_message();
        if ($fetch_attempt < $max_fetch_attempts) {
            sleep($fetch_backoff_seconds);
        }
    }

    if (is_wp_error($response)) {
        $msg = sprintf(
            'Failed to fetch XML feed after %d attempt%s: %s',
            count($fetch_errors),
            count($fetch_errors) === 1 ? '' : 's',
            implode(' | ', $fetch_errors)
        );
        $debug_messages[] = 'Anipro Import: ' . $msg;
        $critical_issues[] = $msg;
        update_option('anipro_import_last_error', ['time' => time(), 'type' => 'fetch-failure', 'message' => $msg]);
        anipro_import_send_alert_email($critical_issues, 'fetch-failure');
        if (!$is_cron) echo '<div class="notice notice-error"><p>'.implode('<br>', $debug_messages).'</p></div>';
        return;
    } elseif (!empty($fetch_errors)) {
        $debug_messages[] = sprintf(
            'Anipro Import: Feed fetch succeeded on attempt %d/%d (earlier attempt failed: %s)',
            $fetch_attempt, $max_fetch_attempts, implode(' | ', $fetch_errors)
        );
    }

    $xml_body = wp_remote_retrieve_body($response);
    // Safety fallback: if cURL somehow returns a raw gzip stream, decode it.
    if (strlen($xml_body) > 2 && substr($xml_body, 0, 2) === "\x1f\x8b") {
        $xml_body = gzdecode($xml_body);
    }
    $xml = simplexml_load_string($xml_body);

    if (!$xml || !isset($xml->channel) || !isset($xml->channel->item)) {
        $msg = 'Invalid XML format or empty feed response (' . strlen($xml_body) . ' bytes received)';
        $debug_messages[] = "Anipro Import: {$msg}";
        $critical_issues[] = $msg;
        update_option('anipro_import_last_error', ['time' => time(), 'type' => 'invalid-xml', 'message' => $msg]);
        anipro_import_send_alert_email($critical_issues, 'invalid-xml');
        if (!$is_cron) echo '<div class="notice notice-error"><p>'.implode('<br>', $debug_messages).'</p></div>';
        return;
    }

    $batch_size = 5;
    $items = [];
    foreach ($xml->channel->item as $item) {
        $g = $item->children('g', true);
        if (strtolower(trim((string)$g->brand)) === 'anipro') {
            $sku = trim((string)$item->sku);
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

        $batch_result = process_anipro_batch($batches[$batch_offset], $batch_offset, $is_cron);
        if (!empty($batch_result['issues'])) {
            $critical_issues = array_merge($critical_issues, $batch_result['issues']);
        }
        if (!empty($batch_result['new_products'])) {
            $new_products = isset($new_products) ? array_merge($new_products, $batch_result['new_products']) : $batch_result['new_products'];
        }

        if ($batch_offset < $total_batches - 1) {
            // Short breather between batches. Was 15s historically (safety valve
            // for image downloads on NEW products) — 1s is enough in steady state
            // because images are rare once the initial catalog is imported.
            sleep(1);
            wp_cache_flush();
        }
    }

    $missing_products_log = anipro_disable_missing_products($feed_skus);
    $debug_messages = array_merge($debug_messages, $missing_products_log);

    // Post-run: record success or escalate to email alert.
    if (empty($critical_issues)) {
        update_option('anipro_last_successful_import', time());
        delete_option('anipro_import_alert_fingerprint');
    } else {
        update_option('anipro_import_last_error', ['time' => time(), 'type' => 'batch-errors', 'message' => implode(' | ', $critical_issues)]);
        anipro_import_send_alert_email($critical_issues, 'batch-errors');
    }

    // If this run created any new products, email a digest to the content team
    // so they can review/edit the new BG drafts and write EN counterpart copy.
    if (!empty($new_products)) {
        anipro_import_send_new_products_email($new_products);
    }

    if (!$is_cron) {
        echo '<div class="notice notice-success"><p>'.implode('<br>', $debug_messages).'</p></div>';
    }
}

function process_anipro_batch($batch_items, $batch_offset, $is_cron) {
    $debug_messages = [];
    $batch_issues = array();   // returned to caller; escalated to email alerts
    $batch_new_products = array(); // returned to caller; emailed as digest
    $parents_to_sync = array();

    foreach ($batch_items as $item) {
        $retry_count = 0;
        while ($retry_count < 5) {
            try {
                $g = $item->children('g', true);
                $sku = trim((string)$item->sku);
                if (!$sku) continue;

                $title = sanitize_text_field((string)$item->title);
                $description = wp_kses_post((string)$item->description);
                $gtin = sanitize_text_field((string)$g->gtin);
                $availability = strtolower(trim((string)$g->availability));
                $price_raw = (string)$g->price;
                $image_url = trim((string)$g->image_link);

                preg_match('/([\d.]+)/', $price_raw, $matches);
                $base_price = isset($matches[1]) ? floatval($matches[1]) : 0;
                $price = ceil($base_price * 1.38 * 10) / 10;
                $stock = ($availability === 'in stock') ? 20 : 0;

                // Determine WooCommerce category from the feed's product_type field.
                // product_type starts with the main category in Bulgarian, e.g.:
                //   "Кучета > Аксесоари за разходка > поводи..."
                //   "Котки > Котешка тоалетна > ..."
                // EN term IDs: Dog=119, Cat=120 | BG term IDs: Кучета=361, Котки=363
                $feed_product_type = (string)$g->product_type;
                $top_cat = null;
                if (mb_strpos($feed_product_type, 'Кучета') === 0) {
                    $top_cat = 'dogs';
                } elseif (mb_strpos($feed_product_type, 'Котки') === 0) {
                    $top_cat = 'cats';
                }

                if (!class_exists('WooCommerce')) { break; }

                // Find ALL products + variations matching this SKU.
                // Polylang translation pairs share SKUs, so one feed row can
                // map to multiple WP posts (EN product + BG product, etc.).
                $product_ids = anipro_get_all_ids_for_sku($sku);
                $is_new = false;

                // CREATE a new translation pair if no products match this SKU.
                // Feed content is in Bulgarian — BG draft gets the full feed
                // data; EN draft is a placeholder linked as the translation,
                // with empty description for the content team to write.
                //
                // 2026-07-08 rewrite: each product is now built through
                // WooCommerce's own OOP API (WC_Product_Simple + setters +
                // ->save()) instead of raw update_post_meta() interleaved
                // with a later, separate wc_get_product()->save() call.
                //
                // Root cause (confirmed empirically via SAVEQUERIES + raw
                // uncached SQL tracing, 2026-07-08): this host's object cache
                // (SG Cachepress / Memcache) serves a STALE, empty meta
                // snapshot to wc_get_product() for a post that was written to
                // moments earlier in the same request — the DB row genuinely
                // has the correct value (verified via direct, uncached SQL),
                // but the cached read via get_post_meta()/wc_get_product()
                // shows it as missing. Neither clean_post_cache() nor a
                // direct wp_cache_delete($id,'post_meta') reliably busts this
                // before the next read. When WC_Product_Data_Store_CPT::update()
                // then treats regular_price/price as unset (based on that
                // stale read) it *deletes* the real, correct DB row via
                // update_or_delete_post_meta(). That silently produced 487
                // empty draft/published products since Dec 2025, 100% of new
                // creates from 2026-06-27 onward.
                //
                // Fix: never re-fetch these objects via wc_get_product() (the
                // cache-dependent path) within this block. Keep the SAME
                // in-memory WC_Product_Simple instances from the first save
                // and reuse them for the second (SKU) save — their in-memory
                // props were set directly by our own code and were never
                // re-read from the cache, so they can't be corrupted by it.
                // WC_Product::save() operates on the object's current
                // in-memory state, not a fresh DB/cache read, so this is safe.
                //
                // SKU is deliberately set in a SECOND save, AFTER language +
                // translation linking: the sibling-SKU-sharing filter
                // (wc_product_has_unique_sku, section 2 of
                // polylang-bricks-fix.php) resolves conflicts by looking up
                // pll_get_post_translations($product_id) — that only works
                // once the product has a real ID and is already linked into
                // a translation group. Setting sku earlier (product_id=0,
                // no translations yet) would make the sibling lookup fail
                // and throw a duplicate-SKU exception on the second (EN)
                // product every time.
                if (empty($product_ids)) {
                    $lang_content = array('bg' => $description, 'en' => '');
                    $new_pids = array();
                    $product_objs = array();

                    try {
                        foreach ($lang_content as $lang => $post_content) {
                            $product_obj = new WC_Product_Simple();
                            $product_obj->set_name(wp_strip_all_tags($title));
                            $product_obj->set_description($post_content);
                            $product_obj->set_status('draft');
                            $product_obj->set_regular_price((string) $price);
                            $product_obj->set_price((string) $price);
                            $product_obj->set_manage_stock(true);
                            // Set stock on the SAME in-memory object BEFORE the
                            // first save so create() (force=true, no stale-cache
                            // read) writes _stock/_stock_status directly. This is
                            // what lets a new product skip update_product_stock()
                            // entirely — its wc_get_product()->save() is the sink
                            // that drops sku/price. Safety here does NOT depend on
                            // "only sku changes on the second save"; it depends on
                            // reusing this same instance whose props we set
                            // ourselves and never re-read from cache.
                            $product_obj->set_stock_quantity($stock);
                            $product_obj->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
                            $new_id = $product_obj->save();

                            if (!$new_id || is_wp_error($new_id)) {
                                throw new Exception('wc_product_save_failed for lang ' . $lang);
                            }
                            $new_pids[$lang] = $new_id;
                            $product_objs[$lang] = $product_obj;
                        }

                        $bg_id = $new_pids['bg'];
                        $en_id = $new_pids['en'];

                        // Tag languages and link as a translation pair so they
                        // share state via Polylang (and our SKU-sharing filter).
                        if (function_exists('pll_set_post_language')) {
                            pll_set_post_language($bg_id, 'bg');
                            pll_set_post_language($en_id, 'en');
                        }
                        if (function_exists('pll_save_post_translations')) {
                            pll_save_post_translations(array(
                                'bg' => $bg_id,
                                'en' => $en_id,
                            ));
                        }

                        // Second save per product: SKU, now that the
                        // translation group exists and the sibling-sharing
                        // filter can resolve correctly. Reuses the SAME
                        // object instances from the first save (see comment
                        // above) instead of wc_get_product() — deliberately
                        // avoids the cache-dependent read path.
                        foreach ($product_objs as $lang => $p) {
                            $p->set_sku($sku);
                            $p->save();
                        }
                    } catch (Exception $e) {
                        // Don't leave a half-created orphan behind — delete
                        // whatever was made so the next run retries this SKU
                        // cleanly from scratch (anipro_get_all_ids_for_sku()
                        // will correctly find nothing and re-attempt CREATE).
                        foreach ($new_pids as $orphan_pid) {
                            wp_delete_post($orphan_pid, true);
                        }
                        $fail_msg = sprintf('Failed to create product pair for SKU %s: %s', $sku, $e->getMessage());
                        error_log('Anipro Import: ' . $fail_msg);
                        $batch_issues[] = $fail_msg;
                        $new_pids = array();
                    }

                    if (!empty($new_pids['bg']) && !empty($new_pids['en'])) {
                        $bg_id = $new_pids['bg'];
                        $en_id = $new_pids['en'];

                        // Brand attribute + gtin: not core WC props, kept as
                        // plain postmeta same as before (never part of the
                        // race — these always imported correctly).
                        foreach (array($bg_id, $en_id) as $pid) {
                            update_post_meta($pid, '_gtin', $gtin);
                            wp_set_object_terms($pid, 'Anipro', 'pa_brand');
                            $attributes = get_post_meta($pid, '_product_attributes', true);
                            if (!is_array($attributes)) $attributes = array();
                            $attributes['pa_brand'] = array(
                                'name'        => 'pa_brand',
                                'value'       => '',
                                'is_visible'  => 1,
                                'is_variation'=> 0,
                                'is_taxonomy' => 1,
                            );
                            update_post_meta($pid, '_product_attributes', $attributes);
                        }

                        // Featured image for the new pair, attached to the BG id
                        // (the one carrying full feed content) — matches the
                        // pre-fix behaviour where the image was handled once, on
                        // the first/BG pid. Uses the now-safe set_post_thumbnail()
                        // path (see anipro_download_and_attach_image): no WC
                        // re-save, so it cannot drop sku/price. Placed OUTSIDE the
                        // create try/catch above so an image failure can never
                        // delete the freshly created pair (media_sideload_image
                        // returns WP_Error -> false return, not an exception).
                        if (!empty($image_url)) {
                            anipro_download_and_attach_image($bg_id, $image_url);
                        }

                        $product_ids = array($bg_id, $en_id);
                        $is_new = true;

                        // ---- Self-heal verification ----
                        // Re-read immediately after save() to confirm sku/
                        // regular_price actually persisted. Uses
                        // anipro_raw_get_meta() (direct SQL, bypasses object
                        // cache) rather than get_post_meta() — a cached read
                        // here would be unreliable given the caching quirk
                        // documented on that function, and could produce
                        // false-positive "missing" reports. This path used to
                        // fail 100% silently (no exception, no log, run still
                        // marked "successful"). Now it's a loud, run-blocking
                        // critical issue instead of a false success — see
                        // anipro_import_last_error / the failure alert email.
                        // A second, final check (with auto-repair) runs after
                        // the UPDATE loop below, since update_product_stock()
                        // and the image-download step each construct their
                        // own wc_get_product() object and are independently
                        // exposed to the same caching quirk.
                        foreach ($product_ids as $pid) {
                            $verify_sku = anipro_raw_get_meta($pid, '_sku');
                            $verify_price = anipro_raw_get_meta($pid, '_regular_price');
                            if ($verify_sku === '' || $verify_price === '') {
                                $verify_msg = sprintf(
                                    'CRITICAL: product %d (SKU %s) missing required fields immediately after create (sku=%s, regular_price=%s)',
                                    $pid, $sku,
                                    $verify_sku === '' ? 'MISSING' : $verify_sku,
                                    $verify_price === '' ? 'MISSING' : $verify_price
                                );
                                error_log('Anipro Import: ' . $verify_msg);
                                $batch_issues[] = $verify_msg;
                            }
                        }

                        // Track for the post-run digest email. We send the
                        // BG ID since that's the one with full feed content
                        // and is what admins will edit first.
                        $batch_new_products[] = array(
                            'sku'    => $sku,
                            'title'  => $title,
                            'bg_id'  => $bg_id,
                            'en_id'  => $en_id,
                        );
                    }
                }

                // UPDATE every matching product/variation.
                $image_handled  = false;
                $skipped_count  = 0;
                foreach ($product_ids as $pid) {
                    $post_type = get_post_type($pid);

                    // Assign top-level category (Dogs/Cats) if not already set.
                    // Runs before the fast-path skip so existing products get
                    // categorised even when stock/price haven't changed.
                    // Language-aware: EN products get EN term, BG products get BG term.
                    if ($top_cat && $post_type !== 'product_variation') {
                        $lang = function_exists('pll_get_post_language') ? pll_get_post_language($pid) : 'en';
                        $cat_id = ($top_cat === 'dogs')
                            ? ($lang === 'bg' ? 361 : 119)
                            : ($lang === 'bg' ? 363 : 120);
                        $existing = wp_get_post_terms($pid, 'product_cat', ['fields' => 'ids']);
                        if (!in_array($cat_id, $existing)) {
                            wp_set_object_terms($pid, $cat_id, 'product_cat', true);
                        }
                    }

                    // A just-created product already has everything it needs set
                    // safely in the CREATE block above (name/desc/status/price/
                    // stock/sku/brand/gtin/image/category). Skip the rest of the
                    // existing-product update path — most importantly
                    // update_product_stock() and the price re-write, each of which
                    // (directly or via wc_get_product()->save()) re-hydrates a
                    // fresh object from the stale SG Memcached snapshot and would
                    // DELETE the real _sku/_regular_price/_price rows. Category
                    // assignment above intentionally runs first, for BOTH pids.
                    if ($is_new) {
                        continue;
                    }

                    // Fast path: if nothing about this product changed, skip entirely.
                    // After the first successful import, every subsequent run finds
                    // stock=20, status=instock, same price → high skip rate.
                    // Title is intentionally NOT compared: EN products have
                    // English titles (different from the BG feed) and we don't
                    // want them to be flagged as "changed" and overwritten.
                    if (!$is_new) {
                        $cur_stock     = (int) get_post_meta($pid, '_stock', true);
                        $cur_status    = (string) get_post_meta($pid, '_stock_status', true);
                        $cur_price     = (float) get_post_meta($pid, '_regular_price', true);
                        $desired_status = ($stock > 0) ? 'instock' : 'outofstock';

                        $unchanged = ($cur_stock === $stock)
                            && ($cur_status === $desired_status)
                            && (abs($cur_price - $price) < 0.001);

                        if ($unchanged) {
                            $skipped_count++;
                            continue; // nothing to do for this pid
                        }
                    }

                    // pa_brand attribute: only on product posts (not variations).
                    //
                    // NOTE: title is NOT updated here on existing products. The feed
                    // title is in Bulgarian, but EN translation pairs need English
                    // titles managed manually. v1.5 used to overwrite the title on
                    // every run, which silently reverted human-edited EN titles. As
                    // of v1.6, title is set ONLY when a product is first created
                    // (in the CREATE branch above) — never on update.
                    if ($post_type !== 'product_variation' && !$is_new) {
                        wp_set_object_terms($pid, 'Anipro', 'pa_brand');
                        $attributes = get_post_meta($pid, '_product_attributes', true);
                        if (!is_array($attributes)) $attributes = array();
                        if (empty($attributes['pa_brand'])) {
                            $attributes['pa_brand'] = array(
                                'name'        => 'pa_brand',
                                'value'       => '',
                                'is_visible'  => 1,
                                'is_variation'=> 0,
                                'is_taxonomy' => 1,
                            );
                            update_post_meta($pid, '_product_attributes', $attributes);
                        }
                    }

                    // Price: applies to both products and variations.
                    update_post_meta($pid, '_price', $price);
                    update_post_meta($pid, '_regular_price', $price);

                    // Featured image: download once per batch iteration, only for
                    // non-variations missing an image.
                    if (!$image_handled && $post_type !== 'product_variation'
                        && !empty($image_url) && !anipro_product_has_image($pid)) {
                        anipro_download_and_attach_image($pid, $image_url);
                        $image_handled = true;
                    }

                    // Stock: per-product. Variations manage their own stock.
                    update_product_stock($pid, $stock);

                    // Queue parent variable products for post-batch sync so the
                    // aggregate stock status on the parent reflects the variation change.
                    if ($post_type === 'product_variation') {
                        $parent_id = wp_get_post_parent_id($pid);
                        if ($parent_id) $parents_to_sync[$parent_id] = true;
                    }
                }

                // ---- Final verify + auto-repair for newly created products ----
                // update_product_stock() and anipro_download_and_attach_image()
                // above each independently construct their own wc_get_product()
                // object, so — even though the CREATE block's own SKU save was
                // fixed to avoid the cache-dependent read path (2026-07-08) — a
                // freshly created product can still pass through one more
                // wc_get_product()->save() cycle here that's exposed to the
                // same object-cache quirk (see anipro_raw_get_meta() docblock).
                // As a safety net, verify via raw SQL and repair directly via
                // update_post_meta() (bypassing WC entirely) if anything came
                // back missing — this is a known-correct value we already have
                // in hand ($sku, $price), not a guess.
                if ($is_new) {
                    foreach ($product_ids as $pid) {
                        $post_type = get_post_type($pid);
                        $final_sku = anipro_raw_get_meta($pid, '_sku');
                        $final_price = anipro_raw_get_meta($pid, '_regular_price');
                        $final_thumb = anipro_raw_get_meta($pid, '_thumbnail_id');
                        $repaired = array();

                        if ($final_sku === '') {
                            update_post_meta($pid, '_sku', $sku);
                            $repaired[] = '_sku';
                        }
                        if ($final_price === '') {
                            update_post_meta($pid, '_regular_price', $price);
                            update_post_meta($pid, '_price', $price);
                            $repaired[] = '_regular_price/_price';
                        }
                        // _thumbnail_id: the attachment itself (created by
                        // media_sideload_image) is independent of this bug —
                        // only the postmeta pointer can go missing. Recover it
                        // by finding the attachment already parented to this
                        // product rather than re-downloading.
                        if ($final_thumb === '' && $post_type !== 'product_variation') {
                            $existing_attachment = get_posts(array(
                                'post_type'      => 'attachment',
                                'post_parent'    => $pid,
                                'post_mime_type' => 'image',
                                'posts_per_page' => 1,
                                'fields'         => 'ids',
                            ));
                            if (!empty($existing_attachment)) {
                                update_post_meta($pid, '_thumbnail_id', $existing_attachment[0]);
                                $repaired[] = '_thumbnail_id';
                            }
                        }

                        if (!empty($repaired)) {
                            clean_post_cache($pid);

                            // Re-verify via direct SQL that the repair actually
                            // landed. A SUCCESSFUL self-heal is NOT a run failure:
                            // pushing it into $batch_issues would fire the
                            // "Product import errors" email AND block
                            // anipro_last_successful_import (which the staleness
                            // watchdog reads) — the exact false-alarm cascade this
                            // fix removes. Only a field STILL empty after the
                            // repair is a genuine failure worth alerting on.
                            // _thumbnail_id is intentionally not gating: a missing
                            // image is non-critical (matches the immediate
                            // post-create verify, which checks sku/regular_price).
                            $still_empty = array();
                            if (in_array('_sku', $repaired, true)
                                && anipro_raw_get_meta($pid, '_sku') === '') {
                                $still_empty[] = '_sku';
                            }
                            if (in_array('_regular_price/_price', $repaired, true)
                                && anipro_raw_get_meta($pid, '_regular_price') === '') {
                                $still_empty[] = '_regular_price';
                            }

                            $repair_msg = sprintf(
                                'Auto-repaired product %d (SKU %s) after stale post-create write dropped: %s',
                                $pid, $sku, implode(', ', $repaired)
                            );
                            error_log('Anipro Import: ' . $repair_msg);
                            $debug_messages[] = $repair_msg;

                            // Record a non-alarming marker so recurrence stays
                            // observable on the admin page even though it no longer
                            // emails. Auto-repair firing at all means FIX A has a
                            // hole to investigate.
                            $autorepair_log = get_option('anipro_import_last_autorepair');
                            if (!is_array($autorepair_log)) {
                                $autorepair_log = array('time' => 0, 'count' => 0, 'skus' => array());
                            }
                            $autorepair_log['time']   = time();
                            $autorepair_log['count']  = (int) $autorepair_log['count'] + 1;
                            $autorepair_log['skus'][] = $sku;
                            $autorepair_log['skus']   = array_slice(array_unique($autorepair_log['skus']), -50);
                            update_option('anipro_import_last_autorepair', $autorepair_log);

                            if (!empty($still_empty)) {
                                $fail_msg = sprintf(
                                    'CRITICAL: product %d (SKU %s) STILL missing %s after auto-repair attempt',
                                    $pid, $sku, implode(', ', $still_empty)
                                );
                                error_log('Anipro Import: ' . $fail_msg);
                                $batch_issues[] = $fail_msg;
                            }
                        }
                    }
                }

                $total_matches = count($product_ids);
                $changed_count = $total_matches - $skipped_count;
                if ($is_new) {
                    $debug_messages[] = sprintf('Created SKU %s (stock=%d)', $sku, $stock);
                } elseif ($changed_count === 0) {
                    $debug_messages[] = sprintf('Skip SKU %s (%d match%s, nothing changed)',
                        $sku, $total_matches, $total_matches === 1 ? '' : 'es');
                } else {
                    $debug_messages[] = sprintf('Updated SKU %s (%d of %d match%s, stock=%d, status=%s)',
                        $sku, $changed_count, $total_matches,
                        $total_matches === 1 ? '' : 'es',
                        $stock, ($stock > 0) ? 'instock' : 'outofstock');
                }
                break; // success — exit retry loop
            } catch (Exception $e) {
                $retry_count++;
                sleep(5);
                if ($retry_count >= 5) {
                    $fail_msg = sprintf('SKU %s failed after 5 retries: %s', $sku, $e->getMessage());
                    $debug_messages[] = $fail_msg;
                    $batch_issues[] = $fail_msg;
                }
            }
        }
    }

    // Sync all affected variable-product parents so parent stock status + variation
    // ranges reflect the updated variation stock.
    if (!empty($parents_to_sync) && class_exists('WC_Product_Variable')) {
        foreach (array_keys($parents_to_sync) as $parent_id) {
            WC_Product_Variable::sync($parent_id);
            wc_delete_product_transients($parent_id);
        }
    }

    if (function_exists('gc_collect_cycles')) gc_collect_cycles();
    if (!$is_cron && !empty($debug_messages)) {
        echo '<div class="notice notice-info"><p>'.implode('<br>', $debug_messages).'</p></div>';
    }
    return array(
        'issues'       => $batch_issues,
        'new_products' => $batch_new_products,
    );
}

/**
 * Send failure-alert email, deduped by issue fingerprint so we don't spam
 * on repeated cron runs that all hit the same error.
 *
 * @param array  $issues    List of issue messages (plain strings)
 * @param string $type      Kind of failure: fetch-failure, invalid-xml,
 *                          batch-errors, staleness
 */
function anipro_import_send_alert_email($issues, $type = 'failure') {
    if (empty($issues) || !is_array($issues)) return;

    $fingerprint = md5($type . '|' . implode('|', $issues));
    $stored      = (string) get_option('anipro_import_alert_fingerprint', '');
    // Staleness emails are time-gated separately — always send when called.
    if ($type !== 'staleness' && $stored === $fingerprint) return;

    $to = ANIPRO_IMPORT_ALERT_EMAIL !== '' ? ANIPRO_IMPORT_ALERT_EMAIL : get_option('admin_email');
    if (empty($to)) return;

    $site_url  = home_url();
    $site_host = parse_url($site_url, PHP_URL_HOST);
    $admin_url = admin_url('tools.php?page=anipro-product-import');

    $type_labels = array(
        'fetch-failure' => 'Feed fetch failed',
        'invalid-xml'   => 'Invalid XML feed',
        'batch-errors'  => 'Product import errors',
        'staleness'     => 'Cron not running',
        'failure'       => 'Import failure',
    );
    $label = isset($type_labels[$type]) ? $type_labels[$type] : $type;

    $subject = sprintf('[%s] Anipro Import: %s', $site_host, $label);

    $body  = "Anipro product import issue on: {$site_url}\n";
    $body .= "Type: {$label}\n";
    $body .= "Time: " . current_time('mysql') . " " . wp_timezone_string() . "\n";
    $body .= "Issue count: " . count($issues) . "\n\n";
    $body .= "Details:\n";
    foreach (array_slice($issues, 0, 50) as $issue) {
        $body .= "  - " . $issue . "\n";
    }
    if (count($issues) > 50) {
        $body .= "  (+" . (count($issues) - 50) . " more suppressed)\n";
    }
    $body .= "\n---\n";
    $body .= "Admin page: {$admin_url}\n";
    $body .= "Next scheduled cron: ";
    $next = wp_next_scheduled('anipro_daily_stock_update');
    $body .= $next ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'Y-m-d H:i:s') . " " . wp_timezone_string() : "NOT SCHEDULED (problem!)";
    $body .= "\n\nTo run immediately: open the admin page and click 'Run Import Now'.\n";
    $body .= "\n-- Anipro XML Importer\n";

    $sent = wp_mail($to, $subject, $body);
    if ($sent) {
        update_option('anipro_import_alert_fingerprint', $fingerprint);
    }
}

/**
 * Send a digest email listing new draft products created in this run, so the
 * content team can review/edit them. Fires once per run, only when at least
 * one new BG/EN translation pair was created.
 *
 * @param array $new_products Each entry: ['sku' => string, 'title' => string,
 *                                         'bg_id' => int, 'en_id' => int]
 */
function anipro_import_send_new_products_email($new_products) {
    if (empty($new_products) || !is_array($new_products)) return;

    $to = ANIPRO_NEW_PRODUCT_NOTIFY_EMAIL !== ''
        ? ANIPRO_NEW_PRODUCT_NOTIFY_EMAIL
        : get_option('admin_email');
    if (empty($to)) return;

    $count     = count($new_products);
    $site_url  = home_url();
    $site_host = parse_url($site_url, PHP_URL_HOST);

    $subject = sprintf(
        '[%s] %d new draft product%s — needs review',
        $site_host,
        $count,
        $count === 1 ? '' : 's'
    );

    $body  = "Hi,\n\n";
    $body .= sprintf(
        "The Anipro XML import created %d new draft product%s on %s.\n",
        $count,
        $count === 1 ? '' : 's',
        $site_url
    );
    $body .= "Each new product is created as a Bulgarian + English translation pair.\n";
    $body .= "Both are in DRAFT status — they need admin review and English copy before publishing.\n\n";
    $body .= "Time: " . current_time('mysql') . " " . wp_timezone_string() . "\n\n";

    $body .= "New products:\n";
    foreach ($new_products as $i => $p) {
        $body .= "\n" . ($i + 1) . ". SKU " . $p['sku'] . " — " . $p['title'] . "\n";
        $body .= "   BG draft: " . admin_url('post.php?post=' . $p['bg_id'] . '&action=edit') . "\n";
        $body .= "   EN draft: " . admin_url('post.php?post=' . $p['en_id'] . '&action=edit') . "\n";
    }

    $body .= "\n---\n";
    $body .= "All BG drafts: " . admin_url('edit.php?post_type=product&post_status=draft&lang=bg') . "\n";
    $body .= "All EN drafts: " . admin_url('edit.php?post_type=product&post_status=draft&lang=en') . "\n";
    $body .= "\n-- Anipro XML Importer\n";

    wp_mail($to, $subject, $body);
}
