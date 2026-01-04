# Anipro XML Importer

**Version:** 1.0
**Author:** Anton Antonov
**WordPress Plugin for WooCommerce**

## Overview

A WordPress/WooCommerce plugin that automatically imports and updates Anipro-branded products from an XML feed provided by Miazoo.bg. The plugin synchronizes product data, manages inventory, and handles pricing calculations daily.

## Features

### 🔄 Automated Daily Import
- Scheduled daily import runs automatically at **23:00 (11 PM)** Europe/Sofia timezone
- Uses WordPress cron system for reliable execution
- Processes products in batches to avoid memory issues

### 📦 Product Management
- **Creates new products** if they don't exist in WooCommerce
- **Updates existing products** with fresh data from the XML feed
- Sets products as **out of stock** if they're missing from the feed
- Automatically assigns the "Anipro" brand taxonomy

### 💰 Pricing System
- Applies custom pricing formula: `Base Price × 1.38`
- Rounds up to nearest 0.1 (e.g., 12.34 → 12.4)
- Updates both regular price and sale price

### 📊 Stock Management
- Sets stock quantity to **20** for items marked "in stock"
- Sets stock quantity to **0** for items out of stock
- Automatically manages stock status (`instock` / `outofstock`)

### ⚙️ Performance Optimization
- **Batch processing**: Processes 5 products at a time
- **15-second delay** between batches to prevent server overload
- **Retry logic**: Up to 5 attempts per product if errors occur
- **Memory management**: Cache flushing and garbage collection

### 🎛️ Admin Interface
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

1. **Fetch XML Feed**
   - Downloads product feed from Miazoo.bg
   - Validates XML structure

2. **Filter Products**
   - Extracts only products with brand = "Anipro"
   - Collects SKUs for tracking

3. **Process Products in Batches**
   - Creates or updates products in WooCommerce
   - Syncs the following data:
     - **Title** (product name)
     - **Description** (formatted with paragraphs)
     - **SKU** (product identifier)
     - **GTIN** (barcode)
     - **Price** (calculated with markup)
     - **Stock quantity**
     - **Brand taxonomy** (Anipro)

4. **Handle Missing Products**
   - Identifies Anipro products not in the current feed
   - Sets them to out of stock (quantity: 0)

### Product Matching
The plugin identifies Anipro products by:
- Brand taxonomy: `pa_brand = "anipro"`
- SKU starting with: `ANI`

## Installation

1. Upload `anipro-xml-importer.php` to `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The daily import will be scheduled automatically at 23:00

## Usage

### Automatic Import
- Runs daily at 23:00 Europe/Sofia time
- No manual intervention required

### Manual Import
1. Go to **WordPress Admin → Tools → Anipro Import**
2. Click **"Run Import Now"** button
3. Wait for completion message

## Technical Details

### WordPress Hooks Used
- `register_activation_hook` - Schedules daily event
- `register_deactivation_hook` - Clears scheduled event
- `add_action('anipro_daily_stock_update')` - Runs import
- `add_action('admin_menu')` - Adds admin interface

### WooCommerce Integration
- Uses WooCommerce product API
- Manages product meta fields
- Sets product taxonomies
- Controls stock management

### Data Fields Imported
- Product Title
- Product Description
- SKU (Stock Keeping Unit)
- GTIN (Global Trade Item Number)
- Price
- Availability/Stock Status
- Brand (Anipro)

### Pricing Formula
```php
$base_price = floatval($xml_price);
$final_price = ceil($base_price * 1.38 * 10) / 10;
```

**Example:**
- XML Price: €10.50
- Calculation: 10.50 × 1.38 = 14.49
- Final Price: €14.50 (rounded up to nearest 0.1)

### Stock Logic
- **In Stock:** Quantity set to 20
- **Out of Stock:** Quantity set to 0
- Missing products automatically marked as out of stock

## Error Handling

- **XML Fetch Failures:** Logged with error message, import aborted
- **Invalid XML:** Validation check, aborts if invalid
- **Product Processing Errors:** Retry up to 5 times with 5-second delays
- **Stock Update Failures:** Logged for debugging

## Deactivation

When the plugin is deactivated:
- Daily scheduled import is automatically cancelled
- Existing products remain unchanged
- No data is deleted

## Requirements

- **WordPress:** 5.0+
- **WooCommerce:** 3.0+
- **PHP:** 7.0+
- **SimpleXML PHP Extension:** Required for XML parsing

## Security

- Nonce verification for admin actions
- `ABSPATH` check to prevent direct file access
- Sanitized input data
- Uses WordPress security best practices

## Changelog

### Version 1.0
- Initial release
- Daily automated import at 23:00
- Manual import via admin interface
- Batch processing with retry logic
- Stock management for missing products
- Brand taxonomy assignment
- Custom pricing formula (1.38x markup)

## Support

For issues or questions, contact: Anton Antonov

## License

Proprietary - All rights reserved
