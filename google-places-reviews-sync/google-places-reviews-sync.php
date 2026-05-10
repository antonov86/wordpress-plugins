<?php
/**
 * Plugin Name:       Google Places Reviews Sync
 * Plugin URI:        https://github.com/dtp/google-places-reviews-sync
 * Description:       Fetches Google Maps reviews via the Places API (New) for a configured Place ID and syncs them into a WordPress custom post type. Daily WP-Cron, upserts on author URI hash, downloads author photos as attachments, marks inactive when reviews disappear from the API.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Domnatapeta.bg / Antonov
 * License:           GPL-2.0-or-later
 * Text Domain:       gprs
 *
 * @package GooglePlacesReviewsSync
 */

defined( 'ABSPATH' ) || exit;

class GPRS_Plugin {

	const OPTION_API_KEY      = 'gprs_api_key';
	const OPTION_PLACE_ID     = 'gprs_place_id';
	const OPTION_POST_TYPE    = 'gprs_target_post_type';
	const OPTION_LAST_SYNC    = 'gprs_last_sync';
	const CRON_HOOK           = 'gprs_daily_sync';
	const META_AUTHOR_URI     = '_gprs_author_uri';      // dedupe key
	const META_PUBLISH_TIME   = '_gprs_publish_time';
	const META_RATING         = '_gprs_rating';
	const META_PLACE_ID       = '_gprs_place_id';
	const META_ACTIVE         = '_gprs_active';
	const META_SYNCED_AT      = '_gprs_synced_at';

	public function __construct() {
		add_action( 'admin_menu',                 [ $this, 'register_settings_page' ] );
		add_action( 'admin_init',                 [ $this, 'register_settings' ] );
		add_action( 'admin_post_gprs_sync_now',   [ $this, 'handle_sync_now' ] );
		add_action( self::CRON_HOOK,              [ $this, 'sync_reviews' ] );
		add_filter( 'cron_schedules',             [ $this, 'add_daily_schedule' ] );
		register_activation_hook( __FILE__,       [ __CLASS__, 'activate' ] );
		register_deactivation_hook( __FILE__,     [ __CLASS__, 'deactivate' ] );
	}

	/* ---------------------------------------------------------------------
	 * Activation / deactivation
	 * ------------------------------------------------------------------- */

	public static function activate() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function add_daily_schedule( $schedules ) {
		// 'daily' is built-in but we ensure it exists.
		return $schedules;
	}

	/* ---------------------------------------------------------------------
	 * Config — constants override DB options (preferred for API key)
	 * ------------------------------------------------------------------- */

	private function get_api_key() {
		if ( defined( 'GPRS_API_KEY' ) && GPRS_API_KEY ) return GPRS_API_KEY;
		return trim( (string) get_option( self::OPTION_API_KEY, '' ) );
	}

	private function get_place_id() {
		if ( defined( 'GPRS_PLACE_ID' ) && GPRS_PLACE_ID ) return GPRS_PLACE_ID;
		return trim( (string) get_option( self::OPTION_PLACE_ID, '' ) );
	}

	private function get_target_post_type() {
		$pt = get_option( self::OPTION_POST_TYPE, 'testimonials' );
		return apply_filters( 'gprs/target_post_type', $pt );
	}

	/* ---------------------------------------------------------------------
	 * Settings page
	 * ------------------------------------------------------------------- */

	public function register_settings_page() {
		add_options_page(
			'Google Reviews Sync',
			'Google Reviews',
			'manage_options',
			'gprs-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'gprs', self::OPTION_API_KEY );
		register_setting( 'gprs', self::OPTION_PLACE_ID );
		register_setting( 'gprs', self::OPTION_POST_TYPE );
	}

	public function render_settings_page() {
		$last       = get_option( self::OPTION_LAST_SYNC, [] );
		$api_locked = defined( 'GPRS_API_KEY' );
		$pid_locked = defined( 'GPRS_PLACE_ID' );
		?>
		<div class="wrap">
			<h1>Google Places Reviews Sync</h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'gprs' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="gprs_api_key">Google API key</label></th>
						<td>
							<?php if ( $api_locked ) : ?>
								<em>Locked via <code>GPRS_API_KEY</code> constant in wp-config.php.</em>
							<?php else : ?>
								<input name="<?php echo esc_attr( self::OPTION_API_KEY ); ?>" id="gprs_api_key" type="text" class="regular-text" value="<?php echo esc_attr( get_option( self::OPTION_API_KEY, '' ) ); ?>">
								<p class="description">Or set <code>define('GPRS_API_KEY','AIza…')</code> in wp-config.php (recommended).</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="gprs_place_id">Google Place ID</label></th>
						<td>
							<?php if ( $pid_locked ) : ?>
								<em>Locked via <code>GPRS_PLACE_ID</code> constant in wp-config.php.</em>
							<?php else : ?>
								<input name="<?php echo esc_attr( self::OPTION_PLACE_ID ); ?>" id="gprs_place_id" type="text" class="regular-text" value="<?php echo esc_attr( get_option( self::OPTION_PLACE_ID, '' ) ); ?>">
								<p class="description">Find via <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank">Google's Place ID Finder</a>.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="gprs_post_type">Target post type</label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_POST_TYPE ); ?>" id="gprs_post_type" type="text" class="regular-text" value="<?php echo esc_attr( get_option( self::OPTION_POST_TYPE, 'testimonials' ) ); ?>">
							<p class="description">Slug of the WP custom post type to write reviews into. Must already be registered.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>

			<h2>Manual sync</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gprs_sync_now">
				<?php wp_nonce_field( 'gprs_sync_now' ); ?>
				<?php submit_button( 'Sync reviews now', 'secondary', 'submit', false ); ?>
			</form>

			<h2>Last sync</h2>
			<?php if ( ! empty( $last ) ) : ?>
				<table class="widefat" style="max-width:600px">
					<tr><th>When</th><td><?php echo esc_html( $last['when'] ?? '—' ); ?></td></tr>
					<tr><th>Status</th><td><?php echo esc_html( $last['status'] ?? '—' ); ?></td></tr>
					<tr><th>Fetched</th><td><?php echo (int) ( $last['fetched'] ?? 0 ); ?> reviews</td></tr>
					<tr><th>Created</th><td><?php echo (int) ( $last['created'] ?? 0 ); ?></td></tr>
					<tr><th>Updated</th><td><?php echo (int) ( $last['updated'] ?? 0 ); ?></td></tr>
					<tr><th>Marked inactive</th><td><?php echo (int) ( $last['deactivated'] ?? 0 ); ?></td></tr>
					<?php if ( ! empty( $last['error'] ) ) : ?>
						<tr><th>Error</th><td style="color:red"><?php echo esc_html( $last['error'] ); ?></td></tr>
					<?php endif; ?>
				</table>
			<?php else : ?>
				<p>No syncs yet.</p>
			<?php endif; ?>

			<p>Next scheduled: <code><?php
				$next = wp_next_scheduled( self::CRON_HOOK );
				echo $next ? esc_html( wp_date( 'Y-m-d H:i:s', $next ) ) : 'not scheduled';
			?></code></p>
		</div>
		<?php
	}

	public function handle_sync_now() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
		check_admin_referer( 'gprs_sync_now' );
		$this->sync_reviews();
		wp_safe_redirect( admin_url( 'options-general.php?page=gprs-settings&synced=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * The actual sync
	 * ------------------------------------------------------------------- */

	public function sync_reviews() {
		$api_key  = $this->get_api_key();
		$place_id = $this->get_place_id();
		$post_type = $this->get_target_post_type();
		$result   = [
			'when'        => wp_date( 'Y-m-d H:i:s' ),
			'status'      => 'ok',
			'fetched'     => 0,
			'created'     => 0,
			'updated'     => 0,
			'deactivated' => 0,
			'error'       => '',
		];

		if ( ! $api_key || ! $place_id ) {
			$result['status'] = 'error';
			$result['error']  = 'API key or Place ID not configured.';
			update_option( self::OPTION_LAST_SYNC, $result );
			return;
		}

		if ( ! post_type_exists( $post_type ) ) {
			$result['status'] = 'error';
			$result['error']  = "Target post type '{$post_type}' is not registered.";
			update_option( self::OPTION_LAST_SYNC, $result );
			return;
		}

		// Field mask MUST list each subfield explicitly. `reviews` alone (or *)
		// does NOT trigger the fetch — Google silently returns 0. Discovered
		// the hard way 2026-05-10. Also: any invalid subfield name (e.g. a
		// typo) silently zeros the array — must use exact valid names.
		$field_mask = implode( ',', [
			'id',
			'displayName',
			'rating',
			'userRatingCount',
			'reviews.text',
			'reviews.originalText',
			'reviews.rating',
			'reviews.authorAttribution',
			'reviews.publishTime',
			'reviews.relativePublishTimeDescription',
		] );

		$response = wp_remote_get(
			"https://places.googleapis.com/v1/places/{$place_id}",
			[
				'timeout' => 15,
				'headers' => [
					'X-Goog-Api-Key'    => $api_key,
					'X-Goog-FieldMask'  => $field_mask,
					'Referer'           => home_url( '/' ),  // matches HTTP-referrer key restriction
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$result['status'] = 'error';
			$result['error']  = 'HTTP error: ' . $response->get_error_message();
			update_option( self::OPTION_LAST_SYNC, $result );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$result['status'] = 'error';
			$result['error']  = "HTTP {$code}: " . ( $body['error']['message'] ?? wp_remote_retrieve_body( $response ) );
			update_option( self::OPTION_LAST_SYNC, $result );
			return;
		}

		$reviews          = $body['reviews'] ?? [];
		$result['fetched'] = count( $reviews );
		$seen_uris        = [];

		foreach ( $reviews as $review ) {
			$author_uri = $review['authorAttribution']['uri'] ?? '';
			if ( ! $author_uri ) continue;
			$seen_uris[] = $author_uri;
			$op = $this->upsert_review( $review, $post_type, $place_id );
			if ( $op === 'created' ) $result['created']++;
			if ( $op === 'updated' ) $result['updated']++;
		}

		// Mark posts inactive that didn't appear in this fetch.
		$result['deactivated'] = $this->deactivate_missing( $post_type, $place_id, $seen_uris );

		update_option( self::OPTION_LAST_SYNC, $result );
	}

	/**
	 * Insert a new post or update an existing one matched by author URI.
	 *
	 * @return string 'created'|'updated'|'noop'
	 */
	private function upsert_review( array $review, string $post_type, string $place_id ) : string {
		$author      = $review['authorAttribution']['displayName'] ?? '';
		$author_uri  = $review['authorAttribution']['uri']         ?? '';
		$photo_uri   = $review['authorAttribution']['photoUri']    ?? '';
		$rating      = (int) ( $review['rating'] ?? 0 );
		$publish_iso = $review['publishTime'] ?? '';
		$body_orig   = $review['originalText']['text'] ?? ( $review['text']['text'] ?? '' );

		// Find existing post by author URI.
		$existing = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => self::META_AUTHOR_URI,
			'meta_value'     => $author_uri,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		$existing_id = $existing[0] ?? 0;

		$post_data = [
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'post_title'   => $author,
			'post_content' => $body_orig,
			'post_date'    => $publish_iso ? wp_date( 'Y-m-d H:i:s', strtotime( $publish_iso ) ) : current_time( 'mysql' ),
		];

		if ( $existing_id ) {
			$post_data['ID'] = $existing_id;
			wp_update_post( $post_data );
			$post_id = $existing_id;
			$op      = 'updated';
		} else {
			$post_id = wp_insert_post( $post_data, true );
			if ( is_wp_error( $post_id ) ) return 'noop';
			$op = 'created';
		}

		// Bookkeeping postmeta.
		update_post_meta( $post_id, self::META_AUTHOR_URI,   $author_uri );
		update_post_meta( $post_id, self::META_PUBLISH_TIME, $publish_iso );
		update_post_meta( $post_id, self::META_RATING,       $rating );
		update_post_meta( $post_id, self::META_PLACE_ID,     $place_id );
		update_post_meta( $post_id, self::META_ACTIVE,       '1' );
		update_post_meta( $post_id, self::META_SYNCED_AT,    current_time( 'mysql' ) );

		// Project-friendly meta — keys used by SmartCartHub-style testimonial designs.
		update_post_meta( $post_id, 'testimonial_name',         $author );
		update_post_meta( $post_id, 'testimonial_body_text',    $body_orig );
		update_post_meta( $post_id, 'testimonials_star_rating', $rating );

		// Author photo as featured image (only if not already set or photo URI changed).
		if ( $photo_uri ) {
			$current_photo = get_post_meta( $post_id, '_gprs_photo_uri', true );
			if ( $current_photo !== $photo_uri ) {
				$attach_id = $this->sideload_image( $photo_uri, $post_id, $author );
				if ( $attach_id ) {
					set_post_thumbnail( $post_id, $attach_id );
					update_post_meta( $post_id, '_gprs_photo_uri', $photo_uri );
				}
			}
		}

		return $op;
	}

	private function sideload_image( string $url, int $post_id, string $author_name ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 15 );
		if ( is_wp_error( $tmp ) ) return 0;

		$file_array = [
			'name'     => sanitize_title( $author_name ) . '.jpg',
			'tmp_name' => $tmp,
		];
		$id = media_handle_sideload( $file_array, $post_id, $author_name . ' — Google review photo' );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return 0;
		}
		// Set ALT for accessibility.
		update_post_meta( $id, '_wp_attachment_image_alt', $author_name . ' — рецензия в Google' );
		return $id;
	}

	private function deactivate_missing( string $post_type, string $place_id, array $seen_uris ) : int {
		$all = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_query'     => [
				[ 'key' => self::META_PLACE_ID, 'value' => $place_id ],
				[ 'key' => self::META_ACTIVE,   'value' => '1' ],
			],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		$count = 0;
		foreach ( $all as $pid ) {
			$uri = get_post_meta( $pid, self::META_AUTHOR_URI, true );
			if ( $uri && ! in_array( $uri, $seen_uris, true ) ) {
				update_post_meta( $pid, self::META_ACTIVE, '0' );
				$count++;
			}
		}
		return $count;
	}
}

new GPRS_Plugin();
