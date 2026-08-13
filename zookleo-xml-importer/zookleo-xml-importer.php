<?php
/*
Plugin Name: Miazoo Importer
Description: Imports and updates products from Miazoo.bg Google Merchant Center XML feed daily.
Version: 2.17.0
Author: Anton Antonov
*/

if (!defined('ABSPATH')) exit;

// Strip supplier inline styles/classes and MS-Word paste artifacts from feed HTML,
// keeping only structural tags.
function miazoo_clean_desc($html) {
    // Remove MS-Word conditional comments (<!--[if !supportLists]-->…) and any HTML comments.
    $html = preg_replace('/<!--.*?-->/su', '', $html);

    $allowed = [
        'p'      => [],
        'br'     => [],
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

// ── BUNDLE POLICY (client rule, 2026-08-06) ────────────────────────────────────────
// Wholesale assortment packs and promo multi-packs are NEVER sold on the site and must
// NEVER be published — drafts only, permanently. The supplier groups them in the 7001xx
// SKU range, but treat the *title* as the real signal: "АСОРТИМЕНТ", "ПРОМОПАКЕТ",
// "N пакета на промоцена", or a contents list like "/6 нашийника, 8 нагръдника/".
// Check any new 7001xx SKU by hand before an import run and add it here if it qualifies.
// Not every 7001xx SKU is a bundle — e.g. 700183 (anipro wipes 40 бр, 1+1) is genuine retail.
define('MIAZOO_SKIP_SKUS', [
    '700173', '700174', '700175',                        // Piper 500 г assortment packs
    '700176', '700177', '700178', '700179', '700180',    // anipro Soft Touch SUMMER TIME bundles
    '700181', '700182',                                  // Rolf's Farm promo multi-packs (4 / 12 пакета)
    '700184', '700185',                                  // Antos ПРОМОПАКЕТ ЛАКОМСТВА
]);

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

    // ── sera (aquarium / terrarium) — added 2026-07-15 ──
    'Акваристика > Филтри и пълнежи'                                       => 513,
    'Акваристика > резервни части > аквариумно оборудване'                 => 528,
    'Акваристика > резервни части'                                         => 528,
    'Акваристика > резервни части > езерно оборудване'                     => 527,
    'Акваристика > Храни > на гранули'                                     => 505,
    'Акваристика > Храни > на люспи'                                       => 504,
    'Акваристика > Храни > на таблетки и други'                            => 507,
    'Акваристика > Храни > лиофилизирани'                                  => 506,
    'Акваристика > Езерни риби > храни'                                    => 523,
    'Акваристика > Езерни риби > препарати'                                => 525,
    'Акваристика > Езерни риби > oборудване и филтрация'                   => 524,
    'Акваристика > Препарати за аквариум > сладководни аквариуми'          => 509,
    'Акваристика > Препарати за аквариум > поддръжка на соленоводни'       => 510,
    'Акваристика > Препарати за дезинфекция'                               => 508,
    'Акваристика > За растенията'                                          => 512,
    'Акваристика > Тестове'                                                => 511,
    'Акваристика > Оборудване и декорация'                                 => 516,
    'Акваристика > Оборудване и декорация > нагреватели, осветление'       => 518,
    'Акваристика > Оборудване и декорация > аксесоари'                     => 517,
    'Акваристика > Оборудване и декорация > помпи за вода, въздух и вълни'  => 519,
    'Терариумни животни > Храни'                                           => 556,
    'Терариумни животни > Оборудване > осветление'                         => 561,
    'Терариумни животни > Постелки за терариум'                            => 564,

    // ── Its My Dog — added 2026-07-15 ──
    'Котки > Храни > лакомства за котки'                                   => 470,

    // ── Beaphar (care / vitamins; anti-parasite is skipped globally) — added 2026-07-15 ──
    'Котки > Витамини и добавки за котки > хранителни добавки за котки'     => 674,
    'Котки > Витамини и добавки за котки > витамини'                       => 674,
    'Кучета > Витамини и добавки за кучета > витамини'                     => 673,
    'Кучета > Витамини и добавки за кучета > хранителни добавки > сухо мляко'          => 673,
    'Кучета > Витамини и добавки за кучета > хранителни добавки > други добавки'       => 673,
    'Кучета > Витамини и добавки за кучета > хранителни добавки > добавки за храносмилане' => 673,
    'Кучета > Козметика и грижа > шампоани и козметика'                    => 406,
    'Кучета > Козметика и грижа > дентална хигиена'                        => 406,
    'Кучета > Козметика и грижа > грижа за очи и уши'                      => 406,
    'Кучета > Козметика и грижа > успокояващи продукти'                    => 453,
    'Кучета > Козметика и грижа'                                           => 406,
    'Котки > Козметика и грижа > успокояващи продукти'                     => 486,
    'Котки > Козметика и грижа > продукти за навици'                       => 486,
    'Котки > Козметика и грижа > шампоани за котки'                        => 485,
    'Малки животни > Храни'                                               => 531,
    'Малки животни > Козметика и грижа'                                    => 543,
    'Малки животни > Лакомства, сено и добавки'                            => 536,
    'Птици > Храни за птици'                                               => 546,
    'Птици > Лакомства и добавки'                                          => 547,
    'Кучета > Аксесоари за разходка > поводи, карабинки и удавници > от изкуствена лента' => 430,
    'Котки > Котешка тоалетна > ароматизатори'                             => 480,
    'Кучета > Хигиена > препарати за почистване'                          => 405,
    'Терариумни животни > Витамини и добавки'                              => 563,

    // ── anipro (dog/cat walk accessories + toys; house brand) — added 2026-07-15 ──
    'Кучета > Аксесоари за разходка > нашийници > от изкуствена лента'                => 436,
    'Кучета > Аксесоари за разходка > нашийници > от Еко текстил'                     => 433,
    'Кучета > Аксесоари за разходка > нашийници > от кожа'                            => 434,
    'Кучета > Аксесоари за разходка > нашийници > от метал'                           => 435,
    'Кучета > Аксесоари за разходка > нагръдници'                                     => 438,
    'Кучета > Аксесоари за разходка > нагръдници > от Еко текстил'                    => 439,
    'Кучета > Аксесоари за разходка > нагръдници > от изкуствена лента'               => 441,
    'Кучета > Аксесоари за разходка > нагръдници > от кожа'                           => 440,
    'Кучета > Аксесоари за разходка > поводи, карабинки и удавници'                   => 426,
    'Кучета > Аксесоари за разходка > поводи, карабинки и удавници > от Еко текстил'  => 427,
    'Кучета > Аксесоари за разходка > поводи, карабинки и удавници > от метал'        => 431,
    'Кучета > Аксесоари за разходка > поводи, карабинки и удавници > от кожа'         => 429,
    'Кучета > Аксесоари за разходка > поводи, карабинки и удавници > от въже'         => 681,
    'Кучета > Аксесоари за разходка > намордници'                                     => 442,
    'Кучета > Играчки'                                                                => 444,
    'Котки > Играчки, драскалки и аксесоари'                                          => 501,
    'Кучета > Транспорт и клетки > аксесоари за кола'                                 => 454,
    'Кучета > легла за кучета'                                                        => 412,
    'Кучета > Хигиена > хигиенни торбички'                                            => 403,
    'Кучета > Хигиена'                                                                => 399,  // bare parent type (anipro wipes, 2026-08-06)

    // ── Tier B: Simple Solution, Vet's Best, Clean Box / Relish / Lindo Cat, SmartPack — added 2026-08-10 ──
    // All targets are categories that already existed and were empty; no new categories created.
    'Котки > Хигиена и препарати'                                                     => 484,  // Simple Solution: Препарати против миризми от котки (category built for this brand)
    'Други'                                                                           => 480,  // Ароматизатори за котешка тоалетна (Simple Solution litter deodorant spray)
    'Котки > Котешка тоалетна > постелки за тоалетна'                                 => 475,  // Постелка, пясък и гранули — hand-sort into 476/477/478/479 after import
    'Котки > Козметика и грижа > грижа за очи, уши, уста'                             => 485,  // Шампоани и грижа за козината за котки (Vet's Best dental powder)
    'Котки > Козметика и грижа'                                                       => 485,  // bare parent type (Vet's Best ear pads)
    'Други > за дома и магазина'                                                      => 567,  // За стопаните (SmartPack bulk bag box — review after import)
    'Кучета > Аксесоари за разходка'                                                  => 425,  // bare parent type (flexi Multi Box). Generic on purpose: do NOT point at flexi's 424, other brands use this type too.
    'Птици > Къщички за птици'                                                        => 554,
]);

// Brand-specific category overrides (brand substring → product_cat term ID). Applied OVER the
// product_type map — for brands the feed mis-classifies under a generic type.
define('MIAZOO_BRAND_CATEGORY', [
    'kudo' => 384,  // Kudo = cold-pressed dog food → Студено пресована храна за кучета (feed says generic "сухи храни")
]);

// Product types containing ANY of these substrings are SKIPPED entirely (never imported).
// Anti-parasite / veterinary-medicinal products require a special registration this client
// does not hold (client instruction 2026-07-15).
define('MIAZOO_SKIP_PRODUCT_TYPE_CONTAINS', ['Противопаразитни']);

// Categories that take the ×1.50 (accessory/equipment) markup instead of the run default.
// sera aquarium/terrarium EQUIPMENT: filters, spare parts, heaters/lighting, pumps, pond gear,
// aquarium equipment/decoration & accessories. Foods + conditioners/treatments/tests stay default.
// Plus dog/cat walk accessories + toys + car/beds/bird-houses/hygiene-bags (anipro, 2026-07-15).
define('MIAZOO_MARKUP_150_CATS', [
    513, 528, 527, 524, 518, 519, 561, 516, 517,                 // sera equipment
    436, 433, 434, 435,                                          // collars
    438, 439, 441, 440,                                          // harnesses
    426, 427, 431, 429, 681, 430,                                // leashes (+rope 681, +PP tape 430)
    432,                                                         // collars: bare parent (children already listed)
    442,                                                         // muzzles
    444, 501,                                                    // dog toys / cat toys
    454, 412, 554, 403,                                          // car acc / beds / bird houses / hygiene bags
    540,                                                         // small-animal cages (Esve, 2026-08-06)
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
                               style="width:100%;max-width:640px" placeholder="e.g. piper, antos, west paw" />
                        <p class="description">Comma-separated allow-list of case-insensitive substrings matched against <code>g:brand</code>. "piper" matches "piper", "piper dry", "piper dry cat"; "antos, esve" imports both brands in one run. Leave empty to import <strong>all</strong> brands.</p>
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
                            <?php foreach (['0.01','0.10','0.50','1.00','charm'] as $r): ?>
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

// The brand filter is an allow-list: a comma-separated list of case-insensitive substrings.
// "piper" alone behaves exactly as before; "antos, esve, west paw" matches any of the three.
// Returns [] when the option is empty (= import all brands).
function miazoo_brand_tokens() {
    $raw    = (string) get_option('miazoo_brand_filter', '');
    $tokens = array_map('trim', explode(',', mb_strtolower($raw)));
    return array_values(array_filter($tokens, function ($t) { return $t !== ''; }));
}

// True if the (already lower-cased) feed brand contains ANY of the allow-list tokens.
function miazoo_brand_matches($brand, array $tokens) {
    foreach ($tokens as $token) {
        if (mb_strpos($brand, $token) !== false) return true;
    }
    return false;
}

// Select the feed items a run should process: brand allow-list, blocked/blank SKUs, and
// skipped product types. SINGLE SOURCE OF TRUTH — miazoo_run_import() and any dry-run
// tooling must both call this, so a reporting script can never drift from real behaviour.
// $stats is filled by reference with the skip counts.
function miazoo_select_feed_items($xml, &$stats = null) {
    $brand_tokens = miazoo_brand_tokens();
    $skip_skus    = MIAZOO_SKIP_SKUS;
    $items        = [];
    $stats        = ['scanned' => 0, 'skipped_ptype' => 0, 'skipped_sku' => 0];

    foreach ($xml->channel->item as $item) {
        $stats['scanned']++;
        $g = $item->children('g', true);

        // mb_strtolower, not strtolower: the latter is byte-based and leaves Cyrillic
        // brands ("Вълшебната Гора", "Бурканите") uppercase, so they never match a token.
        $brand = mb_strtolower(trim((string) $g->brand));
        if ($brand_tokens && !miazoo_brand_matches($brand, $brand_tokens)) continue;

        $sku = trim((string) $item->sku);
        if (!$sku || in_array($sku, $skip_skus, true)) { $stats['skipped_sku']++; continue; }

        $ptype = trim((string) $g->product_type);
        foreach (MIAZOO_SKIP_PRODUCT_TYPE_CONTAINS as $needle) {
            if ($needle !== '' && mb_strpos($ptype, $needle) !== false) {
                $stats['skipped_ptype']++;
                continue 2; // anti-parasite / ВЛП — never imported
            }
        }

        $items[] = $item;
    }

    return $items;
}

// Markup for a feed item, chosen by its mapped category: equipment/accessory categories
// (MIAZOO_MARKUP_150_CATS) → ×1.50, everything else → the run default (miazoo_markup option).
function miazoo_markup_for_item($item) {
    $default = (float) get_option('miazoo_markup', MIAZOO_MARKUP_DEFAULT);
    $g       = $item->children('g', true);
    $ptype   = trim((string) $g->product_type);
    $map     = MIAZOO_CATEGORY_MAP;
    $cat_id  = ($ptype !== '' && isset($map[$ptype])) ? (int) $map[$ptype] : 0;
    return ($cat_id && in_array($cat_id, MIAZOO_MARKUP_150_CATS, true)) ? 1.50 : $default;
}

function miazoo_retail_price($supplier_price, $markup = null) {
    if ($markup === null) {
        $markup = isset($GLOBALS['miazoo_item_markup'])
            ? (float) $GLOBALS['miazoo_item_markup']
            : (float) get_option('miazoo_markup', MIAZOO_MARKUP_DEFAULT);
    }
    $round  = get_option('miazoo_price_round', '0.10');
    $raw    = floatval($supplier_price) * $markup;

    // Charm rounding: round UP to the next price ending in .X9 (…09, .19, .29 … .99).
    // e.g. 12.10 → 12.19, 12.00 → 12.09, 12.95 → 12.99.
    if ($round === 'charm') {
        $cents  = (int) ceil($raw * 100 - 0.001);   // whole cents, rounded up
        $cents += (9 - $cents % 10);                 // bump to the next cents digit 9
        return $cents / 100;
    }

    $round = floatval($round);
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
// Normalise supplier brand variants (e.g. "Wanpy Dry", "Piper Dry Cat", "flexi FUN")
// to a single canonical brand name for the product_brand taxonomy.
function miazoo_canonical_brand($raw) {
    $b   = mb_strtolower($raw);
    $map = [
        'wanpy' => 'Wanpy', 'piper' => 'Piper', 'animonda' => 'Animonda', 'flexi' => 'Flexi', 'savic' => 'Savic',
        'luger' => "Luger's", 'chicopee' => 'Chicopee', 'wolfsblut' => 'Wolfsblut', 'integra' => 'Integra',
        'wildfull' => 'Wildfull', 'pet comfort' => 'Pet Comfort', '4vets' => '4Vets', 'kudo' => 'Kudo',
    ];
    foreach ($map as $needle => $canonical) {
        if (strpos($b, $needle) !== false) return $canonical;
    }
    return trim($raw); // unknown brand → store as-is; add to the map to normalise
}

/**
 * Hand the item to the SmartCartHub feed-map so filter facets are assigned at import time.
 *
 * Before v2.14.0 the importer never called this engine, so every import needed a manual
 * `wp sch feed-map-apply` + visibility pass afterwards (2026-08-06: 1,309 facet assignments
 * and 441 attribute entries had to be backfilled by hand).
 *
 * Two halves, and BOTH are needed:
 *   1. SCH_Feed_Map::apply() assigns the taxonomy terms (pa_food-form / pa_diet / pa_life-stage).
 *   2. The feed-map deliberately never writes `_product_attributes` — it only reads it, to avoid
 *      colliding with variation selectors. Without an is_visible entry WooCommerce assigns the
 *      terms but renders nothing in "Допълнителна информация", so we add the entries here.
 *
 * `pa_flavour` (Месо) is intentionally NOT handled: the rules CSV omits flavour because Zookleo
 * owns it separately, populated from the product title. See scripts/protein-parse.php.
 *
 * Degrades silently if the mu-plugin is absent — the importer must not hard-depend on it.
 */
function miazoo_apply_feed_map($product_id, $item) {
    if (!class_exists('SCH_Feed_Map')) return;

    $g   = $item->children('g', true);
    $sku = trim((string) $item->sku);

    $payload = [
        'brand'           => (string) $g->brand,
        'title'           => (string) $item->title,
        'description'     => (string) $item->description,
        'product_type'    => (string) $g->product_type,
        'shipping_weight' => (string) $g->shipping_weight,
        'sku'             => $sku,
    ];

    $report = SCH_Feed_Map::apply((int) $product_id, $payload, ['dry' => false]);

    // --- make whatever it committed actually visible on the product page ---
    // Only taxonomies that actually received term slugs. SCH_Feed_Map::apply() returns a
    // 'committed' key for a taxonomy even when its slug list is EMPTY, and blindly taking
    // array_keys() adds is_visible entries with no terms behind them — which WooCommerce
    // renders as blank rows in "Допълнителна информация" (e.g. a leash showing an empty
    // "Вид храна"). Regression shipped in v2.14.0, fixed in v2.16.0.
    $committed = [];
    foreach ((array) ($report['committed'] ?? []) as $tax => $slugs) {
        if (!empty($slugs)) $committed[] = $tax;
    }
    if (!$committed) return;

    $attrs = get_post_meta($product_id, '_product_attributes', true);
    if (!is_array($attrs)) $attrs = [];

    $changed = false;
    foreach ($committed as $tax) {
        if (isset($attrs[$tax])) continue;          // never clobber an existing entry / variation axis
        $attrs[$tax] = [
            'name'         => $tax,
            'value'        => '',
            'position'     => count($attrs),
            'is_visible'   => 1,
            'is_variation' => 0,
            'is_taxonomy'  => 1,
        ];
        $changed = true;
    }
    if ($changed) update_post_meta($product_id, '_product_attributes', $attrs);
}

function miazoo_attach_meta($product_id, $item, &$product) {
    $g            = $item->children('g', true);
    $brand        = sanitize_text_field((string)$g->brand);
    $gtin         = sanitize_text_field((string)$g->gtin);
    $image_url    = trim((string)$g->image_link);
    $product_type = trim((string)$g->product_type);

    $cat_map = MIAZOO_CATEGORY_MAP;
    $cat_id  = ($product_type && isset($cat_map[$product_type])) ? (int)$cat_map[$product_type] : 0;
    // Brand override takes precedence over the product_type map.
    $brand_lc = mb_strtolower($brand);
    foreach (MIAZOO_BRAND_CATEGORY as $needle => $tid) {
        if ($needle !== '' && strpos($brand_lc, $needle) !== false) { $cat_id = (int)$tid; break; }
    }
    if ($cat_id) {
        wp_set_object_terms($product_id, $cat_id, 'product_cat');
    }

    if ($brand) {
        wp_set_object_terms($product_id, miazoo_canonical_brand($brand), 'product_brand');
    }

    miazoo_apply_feed_map($product_id, $item);

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

    $brand_tokens = miazoo_brand_tokens();
    $items        = miazoo_select_feed_items($xml, $stats);

    $log[] = 'Found ' . count($items) . ' matching products in feed'
           . ($brand_tokens
                ? ' (brand matches any of: ' . implode(', ', $brand_tokens) . ')'
                : ' (all brands)') . '.'
           . sprintf(' Skipped: %d anti-parasite, %d blocked/blank SKU.',
                     $stats['skipped_ptype'], $stats['skipped_sku']);

    foreach ($items as $item) {
        $log[] = miazoo_process_item($item);
    }

    update_option('miazoo_last_run', current_time('Y-m-d H:i') . ' (' . count($items) . ' products)');
    return $log;
}

function miazoo_process_item($item) {
    $g            = $item->children('g', true);
    $GLOBALS['miazoo_item_markup'] = miazoo_markup_for_item($item); // per-item markup (category-aware)
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
