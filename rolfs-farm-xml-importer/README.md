# Rolf's Farm XML Importer

**Version:** 1.1
**Author:** Anton Antonov
**WordPress Plugin for WooCommerce**

## Overview

A WordPress/WooCommerce plugin that automatically imports and updates Anipro-branded products from an XML feed provided by Miazoo.bg. The plugin synchronises product data and manages inventory daily. Prices are imported as-is from the feed and managed manually in WooCommerce.

## Features

### Automated Daily Import
- Scheduled daily import runs automatically at **23:00 (11 PM)** Europe/Sofia timezone
- Uses WordPress cron system for reliable execution
- Processes products in batches to avoid memory issues

### Product Management
- **Creates new products** if they don't exist in WooCommerce
- **Updates existing products** with fresh data from the XML feed
- Sets products as **out of stock** if they're missing from the feed
- Automatically assigns the "Anipro" brand taxonomy

### Pricing
- Prices are imported **directly from the XML feed** with no markup applied
- Set and adjust prices manually in WooCommerce after import

### Stock Management
- Sets stock quantity to **20** for items marked "in stock"
- Sets stock quantity to **0** for items out of stock
- Automatically manages stock status (`instock` / `outofstock`)

### Performance Optimisation
- **Batch processing**: Processes 5 products at a time
- **15-second delay** between batches to prevent server overload
- **Retry logic**: Up to 5 attempts per product if errors occur
- **Memory management**: Cache flushing and garbage collection

### Admin Interface
- Manual import option via WordPress admin panel
- Located at: **Tools → Anipro Import**
- Real-time progress messages during import
- Detailed logging of created/updated products

## How It Works

### Data Source
- **XML Feed URL:** `https://miazoo.bg/index.php?route=extension/feed/google_merchant_center`
- Format: Google Merchant Center XML feed
- Filters products by brand: "Anipro"

### Import Process

1. **Fetch XML Feed** — Downloads and validates the feed from Miazoo.bg
2. **Filter Products** — Extracts only products with brand = "Anipro" and collects their SKUs
3. **Process in Batches** — Creates or updates products, syncing:
   - Title, Description, SKU, GTIN
   - Price (raw from feed, no markup)
   - Stock quantity and status
   - Brand taxonomy (Anipro)
   - Featured image (downloaded once; skipped if already set)
4. **Handle Missing Products** — Any Anipro product absent from the feed is set to out of stock

### Product Matching
The plugin identifies existing Anipro products by:
- Brand taxonomy: `pa_brand = "anipro"`
- SKU starting with: `ANI`

## Installation

1. Upload `anipro-xml-importer.php` to `/wp-content/plugins/`
2. Activate via the WordPress Plugins menu
3. The daily import schedules itself automatically at 23:00

## Usage

### Automatic Import
Runs daily at 23:00 Europe/Sofia time — no manual intervention required.

### Manual Import
1. Go to **WordPress Admin → Tools → Anipro Import**
2. Click **"Run Import Now"**
3. Wait for the completion message

## Technical Details

### Stock Logic
- **In Stock:** Quantity set to 20
- **Out of Stock:** Quantity set to 0

### Error Handling
- XML fetch failures abort the import and log the error
- Per-product errors retry up to 5 times (5-second delay between attempts)

### Deactivation
When deactivated, the scheduled import is cancelled. Existing products and data are untouched.

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.0+
- SimpleXML PHP extension

## Changelog

### Version 1.1
- Renamed to Rolf's Farm XML Importer
- Removed pricing markup — prices now imported directly from feed

### Version 1.0
- Initial release (Anipro XML Importer)
- Daily automated import at 23:00
- Manual import via admin interface
- Batch processing with retry logic
- Stock management for missing products
- Brand taxonomy assignment

## License

Proprietary — All rights reserved
