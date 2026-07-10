<?php
/*
Plugin Name: Miazoo Importer
Description: Imports and updates products from Miazoo.bg Google Merchant Center XML feed daily.
Version: 2.5.1
Author: Anton Antonov
*/

if (!defined('ABSPATH')) exit;

// Strip supplier inline styles/classes and MS-Word paste artifacts from feed HTML,
// keeping only structural tags.
function miazoo_clean_desc($html) {
    // Remove MS-Word conditional comments (<!--[if !supportLists]-->…) and any HTML comments.
    $html = preg_replace('/<!--.*?-->/su', '', $html);

    // Replace <br> (incl. attributed variants like <br data-start="…"> that ChatGPT/
    // Docs paste) with a space. These are line-wrap artifacts frozen at the source
    // document's width — kept, they make the description render at ~half page width.
    // Paragraph structure lives in <p> tags, so no <br> is needed for layout.
    $html = preg_replace('#<br\b[^>]*>#i', ' ', $html);

    $allowed = [
        'p'      => [],
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'i'      => [],
        'ul'     => [],
        'ol'     => [],
        'li'     => [],
        'span'   => [],
    ];
    $html = wp_kses($html, $allowed);

    // Decode non-breaking spaces (entity + literal U+00A0) and drop leftover
    // Word list-bullet glyphs, so runs collapse to a single normal space below.
    $html = str_replace(['&nbsp;', '&#160;', "\xC2\xA0"], ' ', $html);
    $html = str_replace(['·', '•'], ' ', $html);

    // Drop a leading run of supplier component SKU-codes (MIX/assortment artifact,
    // e.g. "090438 090439 090440GranCarno …"), keeping any leading tags.
    $html = preg_replace('/^((?:\s|<[^>]+>)*)(?:\d{5,}[\s,]*){2,}/u', '$1', $html);

    // Collapse raw newlines/tabs to a single space so wpautop (via
    // wc_format_content) can't turn mid-sentence newlines into stray <br> tags.
    // Paragraph structure lives in <p> tags, not whitespace.
    $html = preg_replace('/[\r\n\t]+/', ' ', $html);
    $html = preg_replace('/ {2,}/', ' ', $html);
    return trim($html);
}

define('MIAZOO_FEED_URL',      'https://miazoo.bg/miazoo_b2b_feed.php');
define('MIAZOO_MARKUP_DEFAULT', 1.30);

// SKUs to always skip (wholesale assortment bundles, not retail products)
define('MIAZOO_SKIP_SKUS', ['700173', '700174', '700175']);

// Maps g:product_type feed values → WooCommerce product_cat term IDs.
// Add rows here whenever a new brand introduces a new feed category.
define('MIAZOO_CATEGORY_MAP', [
    // ── Храни (food brands: Wanpy, Piper, Animonda) ──
    'Кучета > Храни > сухи храни'    => 372,  // Суха гранулирана храна за кучета
    'Кучета > Храни > консервирани'  => 385,  // Консервирана храна за кучета
    'Кучета > Храни > лакомства'     => 391,  // Лакомства и деликатеси за кучета
    'Котки > Храни > сухи храни'     => 457,  // Суха храна за котки
    'Котки > Храни > консервирани'   => 465,  // Консервирана или мокра храна за котки

    // ── flexi (retractable dog leashes → dedicated category) ──
    'Кучета > Flexi автоматични поводи > лента'         => 424,
    'Кучета > Flexi автоматични поводи > въже'          => 424,
    'Кучета > Flexi автоматични поводи > flexi Xtreme'  => 424,
    'Кучета > Flexi автоматични поводи > принадлежности' => 424,

    // ── Savic (accessories & equipment) ──
    'Котки > Котешка тоалетна > съдове за тоалетна'                => 472,  // Съдове за котешка тоалетна
    'Кучета > Аксесоари за кучета > съдове за храна и вода'        => 443,  // Съдове за храна и вода за кучета
    'Малки животни > Оборудване > аксесоари'                      => 541,  // Аксесоари за малки животни
    'Котки > Котешка тоалетна > лопатки, филтри и други аксесоари' => 481,  // Лопатки и аксесоари
    'Кучета > Транспорт и клетки > клетки'                        => 451,  // Транспортни чанти и клетки за кучета
    'Кучета > Хигиена > памперси'                                => 401,  // Памперси за кучета
    'Кучета > Хигиена > подложки за постилане'                    => 402,  // Пелени за кучета
    'Котки > Легла и транспортни чанти'                          => 494,  // Легла за котки (approx: also carriers)
    'Малки животни > Оборудване > клетки'                        => 540,  // Клетки за малки животни
    'Кучета > Транспорт и клетки > транспортни чанти'             => 451,  // Транспортни чанти и клетки за кучета
    'Птици > Оборудване > клетки'                                 => 551,  // Клетки за птици
    'Птици > Оборудване > аксесоари за клетки'                    => 553,  // Аксесоари за птици
    'Кучета > Транспорт и клетки > бариери и удължители'          => 451,  // Транспортни чанти и клетки (approx: barriers)
    'Котки > Съдове за храна и вода'                              => 495,  // Купи за храна и вода за котки
    'Кучета > Обучение и навици'                                  => 402,  // Пелени за кучета (approx: trainer spray)
    'Котки > Котешка тоалетна'                                    => 472,  // Съдове за котешка тоалетна
    'Други > за дома'                                            => 567,  // За стопаните (approx: home gadget)
]);

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
            <tr><th>Brand filter</th>      <td><?php echo $brand ? esc_html($brand) : '<em>not set (all brands)</em>'; ?></td></tr>
            <tr><th>Price markup</th>      <td><?php echo esc_html($markup_pct); ?>% (×<?php echo esc_html($markup); ?>)</td></tr>
            <tr><th>Price rounding</th>    <td>nearest <?php echo esc_html($price_round); ?> EUR</td></tr>
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
                               style="width:220px" placeholder="e.g. piper" />
                        <p class="description">Case-insensitive substring match against <code>g:brand</code>. "piper" matches "piper", "piper dry", "piper dry cat". Leave empty to import all brands.</p>
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
                    <th>Price rounding (EUR)</th>
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

// Parse <price_tier_N qty="..." unit_price="...">total</price_tier_N> elements.
// Returns array of ['qty', 'unit_price', 'total_price'] or empty array if none found.
// Tiers with qty > MIAZOO_MAX_TIER_QTY are skipped (pallet/wholesale quantities).
define('MIAZOO_MAX_TIER_QTY', 100);

function miazoo_parse_tiers($item) {
    $tiers   = [];
    $max_qty = MIAZOO_MAX_TIER_QTY;
    for ($i = 1; $i <= 10; $i++) {
        $key = 'price_tier_' . $i;
        if (!isset($item->$key)) break;
        $el    = $item->$key;
        $qty   = (int)$el['qty'];
        $unit  = (float)$el['unit_price'];
        $total = (float)(string)$el;
        if ($qty < 1 || $total <= 0 || $qty > $max_qty) continue;
        $tiers[] = ['qty' => $qty, 'unit_price' => $unit, 'total_price' => $total];
    }
    return $tiers;
}

// Create pa_opakovka WooCommerce attribute if it doesn't exist yet.
function miazoo_ensure_opakovka_attr() {
    if (wc_attribute_taxonomy_id_by_name('pa_opakovka')) return;
    wc_create_attribute([
        'name'         => 'Опаковка',
        'slug'         => 'opakovka',
        'type'         => 'select',
        'order_by'     => 'menu_order',
        'has_archives' => false,
    ]);
    // Register the taxonomy immediately so it's usable within this request.
    register_taxonomy('pa_opakovka', 'product', ['public' => true, 'hierarchical' => false]);
}

// Get or create a pa_opakovka term for a given pack quantity.
// qty=1 → "1 бр." / slug "1-br"
// qty=N → "N бр. СТЕК" / slug "N-br-stek"
function miazoo_opakovka_term($qty) {
    $qty  = (int)$qty;
    $name = $qty === 1 ? '1 бр.' : $qty . ' бр. СТЕК';
    $slug = $qty === 1 ? '1-br'  : $qty . '-br-stek';

    $existing = get_term_by('slug', $slug, 'pa_opakovka');
    if ($existing) return ['id' => $existing->term_id, 'slug' => $slug, 'name' => $name];

    $r = wp_insert_term($name, 'pa_opakovka', ['slug' => $slug]);
    if (is_wp_error($r)) return false;
    return ['id' => $r['term_id'], 'slug' => $slug, 'name' => $name];
}

// Shared post-save setup: category, brand attribute, GTIN, featured image.
function miazoo_attach_meta($product_id, $item, &$product) {
    $g            = $item->children('g', true);
    $brand        = sanitize_text_field((string)$g->brand);
    $gtin         = sanitize_text_field((string)$g->gtin);
    $image_url    = trim((string)$g->image_link);
    $product_type = trim((string)$g->product_type);

    $cat_map = MIAZOO_CATEGORY_MAP;
    if ($product_type && isset($cat_map[$product_type])) {
        wp_set_object_terms($product_id, (int)$cat_map[$product_type], 'product_cat');
    }

    if ($brand) {
        wp_set_object_terms($product_id, $brand, 'pa_brand');
        $attrs = get_post_meta($product_id, '_product_attributes', true) ?: [];
        if (empty($attrs['pa_brand'])) {
            $attrs['pa_brand'] = ['name' => 'pa_brand', 'value' => '', 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 1];
            update_post_meta($product_id, '_product_attributes', $attrs);
        }
    }

    if ($gtin) update_post_meta($product_id, '_gtin', $gtin);

    if (!empty($image_url)) {
        $att = miazoo_sideload_image($product_id, $image_url);
        if ($att) { $product->set_image_id($att); $product->save(); }
    }
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
    $skip_skus    = MIAZOO_SKIP_SKUS;
    $items        = [];

    foreach ($xml->channel->item as $item) {
        $g     = $item->children('g', true);
        $brand = strtolower(trim((string)$g->brand));
        if ($brand_filter && strpos($brand, $brand_filter) === false) continue;
        $sku = trim((string)$item->sku);
        if (!$sku || in_array($sku, $skip_skus, true)) continue;
        $items[] = $item;
    }

    $log[] = 'Found ' . count($items) . ' matching products in feed'
           . ($brand_filter ? " (brand contains: $brand_filter)" : ' (all brands)') . '.';

    foreach ($items as $item) {
        $log[] = miazoo_process_item($item);
    }

    update_option('miazoo_last_run', current_time('Y-m-d H:i') . ' (' . count($items) . ' products)');
    return $log;
}

function miazoo_process_item($item) {
    $g            = $item->children('g', true);
    $sku          = trim((string)$item->sku);
    $availability = strtolower(trim((string)$g->availability));
    $stock        = ($availability === 'in stock') ? 20 : 0;
    $tiers        = miazoo_parse_tiers($item);

    $product_id = wc_get_product_id_by_sku($sku);

    if ($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) return "Error loading product: $sku";

        if ($product->is_type('variation') && !empty($tiers)) {
            // SKU belongs to a variation inside a manually-merged variable product.
            // Upgrade that variation (and its parent) to include the pa_opakovka dimension.
            return miazoo_upgrade_variation_tiers($product, $tiers, $stock, $item);
        }

        if ($product->is_type('variable') && !empty($tiers)) {
            return miazoo_update_variable($product, $tiers, $stock, $item);
        }

        // Simple product (or variable without tiers): update stock only.
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stock);
        $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
        $product->save();
        return sprintf('Stock updated: %s  qty:%d', $sku, $stock);
    }

    // New product.
    return !empty($tiers)
        ? miazoo_create_variable($item, $tiers, $stock)
        : miazoo_create_simple($item, $stock);
}

// ── Upgrade existing variation to include pa_opakovka tier dimension ──────────
//
// Called when a feed SKU is already stored as a WC_Product_Variation inside a
// manually-merged variable product (e.g. Piper Adult Dog консервирана храна).
// On the first run for a given variation it:
//   1. Adds pa_opakovka as a third attribute on the parent variable product.
//   2. Tags this variation with pa_opakovka = tier-1 slug ("1 бр." or "10 бр. СТЕК").
//   3. Creates a new sibling variation for every other tier (the bulk case price).
// On subsequent runs it just refreshes prices + stock.

function miazoo_upgrade_variation_tiers($variation, $tiers, $stock, $item = null) {
    miazoo_ensure_opakovka_attr();

    $parent_id = $variation->get_parent_id();
    $parent    = wc_get_product($parent_id);
    $sku       = $variation->get_sku();
    $var_attrs = $variation->get_attributes();  // e.g. ['pa_retsepta'=>'agneshko-...', 'pa_teglo-opakovka'=>'800g']

    // Build term objects for every tier in this feed item.
    $tier_terms = [];
    foreach ($tiers as $tier) {
        $t = miazoo_opakovka_term($tier['qty']);
        if ($t) $tier_terms[$tier['qty']] = $t;
    }
    if (empty($tier_terms)) return "No valid tier terms for: $sku";

    $first_tier     = reset($tiers);
    $first_term     = $tier_terms[$first_tier['qty']] ?? null;
    $already_tagged = isset($var_attrs['pa_opakovka']);

    // ── 1. Merge tier terms into parent's pa_opakovka attribute ────────────────
    $parent_attrs = $parent->get_attributes();
    $attr_id      = wc_attribute_taxonomy_id_by_name('pa_opakovka');

    if (!isset($parent_attrs['pa_opakovka'])) {
        $attr = new WC_Product_Attribute();
        $attr->set_id($attr_id);
        $attr->set_name('pa_opakovka');
        $attr->set_options(array_column($tier_terms, 'id'));
        $attr->set_visible(true);
        $attr->set_variation(true);
        $parent_attrs['pa_opakovka'] = $attr;
    } else {
        $existing_ids = $parent_attrs['pa_opakovka']->get_options();
        $new_ids      = array_values(array_unique(array_merge($existing_ids, array_column($tier_terms, 'id'))));
        $parent_attrs['pa_opakovka']->set_options($new_ids);
    }
    $parent->set_attributes(array_values($parent_attrs));
    $parent->save();
    $parent = wc_get_product($parent_id); // reload to flush internal variation cache

    // ── 2. Tag this variation with tier-1 pack term (first-time only) ──────────
    if (!$already_tagged && $first_term) {
        $updated_attrs = $var_attrs;
        $updated_attrs['pa_opakovka'] = $first_term['slug'];
        $variation->set_attributes($updated_attrs);
    }
    $variation->set_regular_price(miazoo_retail_price($first_tier['total_price']));
    $variation->set_manage_stock(true);
    $variation->set_stock_quantity($stock);
    $variation->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
    $variation->save();

    // ── 3. Find-or-create sibling variations for the remaining tiers ───────────
    $created = 0;
    foreach ($tiers as $tier) {
        if ($tier['qty'] === $first_tier['qty']) continue;  // already handled above
        $t = $tier_terms[$tier['qty']] ?? null;
        if (!$t) continue;

        // Build the full attribute set for this sibling: same recipe+weight, new pack.
        $sibling_attrs = $var_attrs;
        $sibling_attrs['pa_opakovka'] = $t['slug'];

        // WooCommerce helper: returns existing variation ID if all attrs match, else false.
        $match_id = $parent->get_matching_variation($sibling_attrs);
        if ($match_id) {
            $sibling = wc_get_product($match_id);
            $sibling->set_regular_price(miazoo_retail_price($tier['total_price']));
            $sibling->save();
        } else {
            $sibling = new WC_Product_Variation();
            $sibling->set_parent_id($parent_id);
            $sibling->set_status('publish');
            $sibling->set_attributes($sibling_attrs);
            $sibling->set_regular_price(miazoo_retail_price($tier['total_price']));
            if ($item) $sibling->set_description(miazoo_clean_desc((string)$item->description));
            $sibling->save();
            $created++;
        }
    }

    WC_Product_Variable::sync($parent_id);

    return sprintf('Tier-upgraded variation: %s  new_vars:%d  stock:%d', $sku, $created, $stock);
}

// ── New simple product ────────────────────────────────────────────────────────

function miazoo_create_simple($item, $stock) {
    $g     = $item->children('g', true);
    $sku   = trim((string)$item->sku);

    preg_match('/([\d.]+)/', (string)$g->price, $m);
    $retail = miazoo_retail_price(isset($m[1]) ? floatval($m[1]) : 0);

    $product = new WC_Product_Simple();
    $product->set_status('draft');
    $product->set_name(sanitize_text_field((string)$item->title));
    $product->set_description(miazoo_clean_desc((string)$item->description));
    $product->set_sku($sku);
    $product->set_regular_price($retail);
    $product->set_price($retail);
    $product->set_manage_stock(true);
    $product->set_stock_quantity($stock);
    $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
    $product_id = $product->save();

    miazoo_attach_meta($product_id, $item, $product);

    return sprintf('Created simple: %s  price:%.2f  stock:%d', $sku, $retail, $stock);
}

// ── New variable product (price tiers → pa_opakovka variations) ───────────────

function miazoo_create_variable($item, $tiers, $stock) {
    $g   = $item->children('g', true);
    $sku = trim((string)$item->sku);

    miazoo_ensure_opakovka_attr();

    // Build pa_opakovka terms for all tiers.
    $attr_id  = wc_attribute_taxonomy_id_by_name('pa_opakovka');
    $term_ids = [];
    $terms    = [];
    foreach ($tiers as $tier) {
        $t = miazoo_opakovka_term($tier['qty']);
        if (!$t) continue;
        $term_ids[]              = $t['id'];
        $terms[$tier['qty']] = $t;
    }

    $attr = new WC_Product_Attribute();
    $attr->set_id($attr_id);
    $attr->set_name('pa_opakovka');
    $attr->set_options($term_ids);
    $attr->set_visible(true);
    $attr->set_variation(true);

    $variable = new WC_Product_Variable();
    $variable->set_status('draft');
    $variable->set_name(sanitize_text_field((string)$item->title));
    $variable->set_description(miazoo_clean_desc((string)$item->description));
    $variable->set_sku($sku);
    $variable->set_manage_stock(true);
    $variable->set_stock_quantity($stock);
    $variable->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
    $variable->set_attributes([$attr]);
    $variable_id = $variable->save();

    if (!$variable_id) return "Failed to create variable product: $sku";

    miazoo_attach_meta($variable_id, $item, $variable);

    // One variation per tier. Price = total pack price × markup.
    foreach ($tiers as $tier) {
        $t = $terms[$tier['qty']] ?? null;
        if (!$t) continue;
        $retail = miazoo_retail_price($tier['total_price']);

        $variation = new WC_Product_Variation();
        $variation->set_parent_id($variable_id);
        $variation->set_status('publish');
        $variation->set_attributes(['pa_opakovka' => $t['slug']]);
        $variation->set_regular_price($retail);
        $variation->set_description(miazoo_clean_desc((string)$item->description));
        $variation->save();
    }

    WC_Product_Variable::sync($variable_id);

    return sprintf('Created variable: %s  tiers:%d  stock:%d', $sku, count($tiers), $stock);
}

// ── Update existing variable product ─────────────────────────────────────────

function miazoo_update_variable($product, $tiers, $stock, $item = null) {
    $sku = $product->get_sku();

    // Stock is managed on the parent (shared across all pack options).
    $product->set_manage_stock(true);
    $product->set_stock_quantity($stock);
    $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
    $product->save();

    $variation_ids = $product->get_children();

    foreach ($tiers as $tier) {
        $t = miazoo_opakovka_term($tier['qty']);
        if (!$t) continue;
        $retail = miazoo_retail_price($tier['total_price']);

        // Find the variation that matches this pack size.
        $matched = false;
        foreach ($variation_ids as $var_id) {
            $var = wc_get_product($var_id);
            if (!$var) continue;
            if (($var->get_attributes()['pa_opakovka'] ?? '') === $t['slug']) {
                $var->set_regular_price($retail);
                $var->save();
                $matched = true;
                break;
            }
        }

        // New tier added to feed since last import: create the variation.
        if (!$matched) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product->get_id());
            $variation->set_status('publish');
            $variation->set_attributes(['pa_opakovka' => $t['slug']]);
            $variation->set_regular_price($retail);
            if ($item) $variation->set_description(miazoo_clean_desc((string)$item->description));
            $variation->save();
        }
    }

    WC_Product_Variable::sync($product->get_id());

    return sprintf('Updated variable: %s  qty:%d', $sku, $stock);
}
