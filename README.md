# WordPress Plugins Collection

A collection of custom WordPress plugins developed by Anton Antonov.

## Plugins

### 1. Anipro XML Importer
**Location:** `/anipro-xml-importer/`

WordPress/WooCommerce plugin that automatically imports and updates Anipro-branded products from XML feed.

**Features:**
- Automated daily imports at 23:00
- Stock management
- Custom pricing calculations (1.38x markup)
- Batch processing with retry logic
- Admin interface for manual imports

[View Plugin Documentation →](./anipro-xml-importer/README.md)

### 2. Beaphar XML Importer
**Location:** `/beaphar-xml-importer/`

WordPress/WooCommerce plugin that automatically imports and updates Beaphar-branded products from XML feed.

**Features:**
- Automated daily imports at 23:00
- Stock management
- Custom pricing calculations (1.38x markup)
- Batch processing with retry logic
- Admin interface for manual imports

[View Plugin Documentation →](./beaphar-xml-importer/README.md)

### 3. Google Places Reviews Sync
**Location:** `/google-places-reviews-sync/`

WordPress plugin that fetches Google Maps reviews via the Places API (New) and writes them into a configurable custom post type. Designed to drop into any e-commerce site that already has a styled testimonials/reviews section — keeps the design, replaces the data with real Google reviews.

**Features:**
- Daily WP-Cron sync from a configured Place ID
- Upserts into a configurable post type (default `testimonials`), deduped by author profile URI
- Downloads author photos as featured-image attachments with descriptive ALT
- Marks reviews inactive (instead of deleting) when they drop from Google's API response
- Settings page with manual "Sync now" trigger and last-sync diagnostics
- Reads original-language review text (not Google's auto-translation)
- Configurable via wp-config constants (`GPRS_API_KEY`, `GPRS_PLACE_ID`) or DB options

[View Plugin Documentation →](./google-places-reviews-sync/README.md)

---

## Author

Anton Antonov

## License

All plugins are proprietary unless otherwise specified.
