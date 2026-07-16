# Google Places Reviews Sync

WordPress plugin that fetches Google Maps reviews via the **Places API (New)** and writes them into a WordPress custom post type. Designed to be reused across e-commerce sites that already have a testimonials/reviews CPT and want their existing design rendered with real Google data.

## What it does

- Daily WP-Cron fetches reviews from Google for one or more configured Place IDs
- Upserts each review into a configurable post type (`testimonials` by default), deduped by author's Google profile URI **scoped per Place ID** (the same author reviewing two locations yields one card per location)
- Downloads the author's profile photo and attaches it as the post's featured image
- Marks reviews inactive (rather than deleting them) when they disappear from Google's response — preserves history. Deactivation is **per Place ID**, so one location's sync never touches another's reviews
- Settings page in `Settings → Google Reviews` with a manual "Sync now" button + per-location last-sync diagnostics
- Syncs in the **site's own language** and keeps **5-star reviews only** — both defaults, both configurable (see [Language and rating](#language-and-rating))

### Multiple locations

Set several Place IDs (separated by comma, whitespace, or newline) to populate the same testimonials post type from more than one business location — they render together in whatever template queries the CPT. Each post is tagged with `_gprs_place_id`. Example: `define( 'GPRS_PLACE_ID', 'ChIJaaa…,ChIJbbb…' );`. A single ID still works exactly as before.

### Language and rating

Two settings that used to be baked into the code, so a clone can be configured instead of edited:

| Setting | Option | Default | Filter |
|---|---|---|---|
| Review language | `gprs_language_code` | the site's locale, primary subtag (`bg_BG` → `bg`) | `gprs/language_code` |
| Minimum star rating | `gprs_min_rating` | `5` (5-star only) | `gprs/min_rating` |

**Language** is a BCP-47 code — `bg`, `en`, `pt-BR`. Leave the option blank and a clone speaks its own language with zero setup; set it explicitly only for a region-specific code, or to pull reviews in a language other than the site's.

**Minimum rating** clamps to 1–5. Set it to `1` to take every review. A review that later drops below the threshold is marked inactive on the next sync, same as one that disappears from Google entirely.

## What it does NOT do

- Does **not** register a custom post type — assumes the target CPT already exists. If yours doesn't, register it before activating (or change the target slug to `post`).
- Does **not** render any frontend output — your theme/page builder reads the synced post type via its existing template. The plugin is data-sync only.
- Does **not** fix Google's hard cap of **5 reviews per fetch, per Place ID**. This is a Places API limitation, not the plugin's. (Configuring N locations therefore yields up to 5×N reviews.) To display more than 5 from a single location you need the Google Business Profile API (separate setup, requires business-ownership verification).

## Requirements

- WordPress 6.0+, PHP 7.4+
- A custom post type to write into (default slug: `testimonials`)
- Google Cloud project with **Places API (New)** enabled (the legacy Places API is not supported by this plugin)
- An API key with optional HTTP-referrer restrictions (the plugin sets the `Referer` header to the site's `home_url()` so referrer restrictions pass)

## Installation

1. Upload the `google-places-reviews-sync/` folder to `wp-content/plugins/`.
2. Activate via the Plugins screen or `wp plugin activate google-places-reviews-sync`.
3. Configure either via the settings page (`Settings → Google Reviews`) or in `wp-config.php` (preferred for the API key):
   ```php
   define( 'GPRS_API_KEY',  'AIza…' );        // optional; constants override DB options
   define( 'GPRS_PLACE_ID', 'ChIJ…' );        // optional; comma-separate for multiple locations
   ```
4. Click "Sync reviews now" on the settings page to populate immediately. After that the daily cron handles refreshes.

## Field mapping (default — for SmartCartHub-style testimonials)

The plugin writes to both standard WP fields and project-specific postmeta keys:

| WP field / postmeta | Source |
|---|---|
| `post_title` | `authorAttribution.displayName` |
| `post_content` | `originalText.text` (Bulgarian for BG sites) — falls back to `text.text` |
| `post_date` | `publishTime` |
| `_thumbnail_id` | downloaded `authorAttribution.photoUri` |
| `testimonial_name` (meta) | author name |
| `testimonial_body_text` (meta) | review text |
| `testimonials_star_rating` (meta) | `rating` |
| `_gprs_author_uri` (meta) | dedupe key |
| `_gprs_publish_time` (meta) | ISO 8601 publish time |
| `_gprs_rating` (meta) | rating int |
| `_gprs_place_id` (meta) | which Place this came from |
| `_gprs_active` (meta) | `'1'` while present in API; `'0'` when no longer returned |
| `_gprs_synced_at` (meta) | last sync MySQL timestamp |
| `_gprs_photo_uri` (meta) | last downloaded photo URL (for re-download detection) |

## Filters

- `gprs/target_post_type` — override the configured target post type at runtime.
- `gprs/place_ids` — array of Place IDs to sync.
- `gprs/language_code` — BCP-47 language for review text.
- `gprs/min_rating` — lowest star rating to sync (clamped to 1–5 afterwards).

## Gotchas (worth knowing if you fork or extend)

### Places API field mask requires explicit subfields

The naive call `X-Goog-FieldMask: reviews` returns **0 reviews silently** even on places with reviews. So does `X-Goog-FieldMask: *`. You MUST list each subfield: `reviews.text,reviews.originalText,reviews.rating,reviews.authorAttribution,reviews.publishTime,reviews.relativePublishTimeDescription`. Worse: any *invalid* subfield name (typo, deprecated alias) also silently zeros the array — no error, just empty. Test the field mask carefully.

### Translation defaults to user's session locale

Google translates reviews to your account's language by default. The translated text is in `reviews[].text.text` with `languageCode` set accordingly. The original is in `reviews[].originalText.text`. For a Bulgarian site, **always read `originalText`** — that's what your customers wrote, not Google Translate's English rendering. The plugin reads `originalText` first and falls back to `text`, so the `languageCode` it sends mainly affects that fallback.

### HTTP referrer restriction needs an explicit `Referer` header server-side

`wp_remote_get()` does not auto-set `Referer`. If your API key has HTTP referrer restrictions, you must pass `Referer: home_url('/')` explicitly. The plugin already does this; if you copy-paste the fetch logic elsewhere, don't strip the header.

### Reviews in the response can change between fetches

Google's `reviews` array contains "the most relevant" reviews — the algorithm decides. A 5★ review might disappear from one fetch to the next without being deleted. Don't hard-delete posts; the plugin marks them inactive instead.

### Author photo URLs may rotate

Google photo URIs (`lh3.googleusercontent.com/a/...`) sometimes change server-side. The plugin tracks the last downloaded URI in `_gprs_photo_uri` and re-downloads only when the URI changes — preventing constant re-downloads each cron tick.

## Telemetry / debugging

- `Settings → Google Reviews` shows the last sync's status, count, errors, and next scheduled run
- Each post stores `_gprs_synced_at` so you can spot stale records
- Errors during fetch are stored in the `gprs_last_sync` option's `error` field — visible on the settings page

## Removing

Deactivating the plugin clears the cron. Posts and attachments stay (so your testimonial CPT keeps its data). Delete `gprs_*` options if you want a clean uninstall.
