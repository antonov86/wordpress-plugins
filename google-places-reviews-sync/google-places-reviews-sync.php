<?php
/**
 * Plugin Name:       Google Places Reviews Sync
 * Plugin URI:        https://github.com/dtp/google-places-reviews-sync
 * Description:       Fetches Google Maps reviews via the Places API (New) for one or more configured Place IDs and syncs them into a WordPress custom post type. Daily WP-Cron, upserts on author URI hash, downloads author photos as attachments, marks inactive when reviews disappear from the API.
 * Version:           1.1.0
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
	const OPTION_LANGUAGE     = 'gprs_language_code';
	const OPTION_MIN_RATING   = 'gprs_min_rating';
	const OPTION_LAST_SYNC    = 'gprs_last_sync';
	const DEFAULT_MIN_RATING  = 5;
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

	/**
	 * Configured Place IDs as an array.
	 *
	 * Accepts one OR several IDs from the GPRS_PLACE_ID constant (preferred) or
	 * the gprs_place_id option, separated by comma, whitespace, or newlines.
	 * Backward-compatible: a single ID still works. Each ID is synced
	 * independently and its reviews are tagged with `_gprs_place_id`, so
	 * multiple business locations populate the same testimonials CPT together.
	 */
	private function get_place_ids() : array {
		$raw = ( defined( 'GPRS_PLACE_ID' ) && GPRS_PLACE_ID )
			? (string) GPRS_PLACE_ID
			: (string) get_option( self::OPTION_PLACE_ID, '' );

		$ids = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$ids = array_values( array_unique( array_map( 'trim', (array) $ids ) ) );

		return apply_filters( 'gprs/place_ids', $ids );
	}

	private function get_target_post_type() {
		$pt = get_option( self::OPTION_POST_TYPE, 'testimonials' );
		return apply_filters( 'gprs/target_post_type', $pt );
	}

	/**
	 * BCP-47 language Google should return review text in.
	 *
	 * Defaults to the site's own locale reduced to its primary subtag
	 * (bg_BG → bg, en_US → en), so a clone speaks its own language with no
	 * setup. Set the option — or filter — for a region-specific code such as
	 * pt-BR, or to pull reviews in a language other than the site's.
	 */
	private function get_language_code() : string {
		$code = trim( (string) get_option( self::OPTION_LANGUAGE, '' ) );

		if ( '' === $code ) {
			$parts = explode( '_', get_locale() );
			$code  = strtolower( $parts[0] );
		}

		return (string) apply_filters( 'gprs/language_code', $code ?: 'en' );
	}

	/**
	 * Lowest star rating worth syncing; anything below it is skipped.
	 *
	 * Defaults to 5 — a testimonials carousel is marketing copy, and Google
	 * only returns 5 reviews per location anyway. Drop it to 1 to take
	 * everything.
	 */
	private function get_min_rating() : int {
		$min = (int) get_option( self::OPTION_MIN_RATING, self::DEFAULT_MIN_RATING );
		$min = (int) apply_filters( 'gprs/min_rating', $min );

		return max( 1, min( 5, $min ) );
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
		register_setting( 'gprs', self::OPTION_LANGUAGE );
		register_setting( 'gprs', self::OPTION_MIN_RATING, [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => self::DEFAULT_MIN_RATING,
		] );
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
						<th><label for="gprs_place_id">Google Place ID(s)</label></th>
						<td>
							<?php if ( $pid_locked ) : ?>
								<em>Locked via <code>GPRS_PLACE_ID</code> constant in wp-config.php.</em>
								<p class="description">Currently syncing <?php echo (int) count( $this->get_place_ids() ); ?> location(s): <code><?php echo esc_html( implode( ', ', $this->get_place_ids() ) ); ?></code></p>
							<?php else : ?>
								<textarea name="<?php echo esc_attr( self::OPTION_PLACE_ID ); ?>" id="gprs_place_id" class="large-text" rows="3"><?php echo esc_textarea( get_option( self::OPTION_PLACE_ID, '' ) ); ?></textarea>
								<p class="description">One or more Place IDs — separate multiple locations with a comma, space, or new line. Each is synced into the same testimonials post type. Find via <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank">Google's Place ID Finder</a>.</p>
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
					<tr>
						<th><label for="gprs_language_code">Review language</label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_LANGUAGE ); ?>" id="gprs_language_code" type="text" class="small-text" value="<?php echo esc_attr( get_option( self::OPTION_LANGUAGE, '' ) ); ?>" placeholder="<?php echo esc_attr( $this->get_language_code() ); ?>">
							<p class="description">Language Google returns review text in, as a BCP-47 code — <code>bg</code>, <code>en</code>, <code>pt-BR</code>. Leave blank to follow the site language (currently <code><?php echo esc_html( $this->get_language_code() ); ?></code>).</p>
						</td>
					</tr>
					<tr>
						<th><label for="gprs_min_rating">Minimum rating</label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_MIN_RATING ); ?>" id="gprs_min_rating">
								<?php
								$rating_labels = [
									5 => '5 stars only',
									4 => '4 stars and up',
									3 => '3 stars and up',
									2 => '2 stars and up',
									1 => 'All reviews',
								];
								foreach ( $rating_labels as $value => $label ) : ?>
									<option value="<?php echo (int) $value; ?>" <?php selected( $this->get_min_rating(), $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Reviews below this are skipped. A synced review that later drops below it is marked inactive on the next sync.</p>
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
					<?php if ( ! empty( $last['places'] ) && is_array( $last['places'] ) ) : ?>
						<tr><th>Per location</th><td>
							<?php foreach ( $last['places'] as $pid => $p ) : ?>
								<div><code><?php echo esc_html( $pid ); ?></code>: fetched <?php echo (int) ( $p['fetched'] ?? 0 ); ?>, created <?php echo (int) ( $p['created'] ?? 0 ); ?>, updated <?php echo (int) ( $p['updated'] ?? 0 ); ?>, inactive <?php echo (int) ( $p['deactivated'] ?? 0 ); ?><?php if ( ! empty( $p['error'] ) ) echo ' — <span style="color:red">' . esc_html( $p['error'] ) . '</span>'; ?></div>
							<?php endforeach; ?>
						</td></tr>
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
		$api_key   = $this->get_api_key();
		$place_ids = $this->get_place_ids();
		$post_type = $this->get_target_post_type();
		$result    = [
			'when'        => wp_date( 'Y-m-d H:i:s' ),
			'status'      => 'ok',
			'fetched'     => 0,
			'created'     => 0,
			'updated'     => 0,
			'deactivated' => 0,
			'error'       => '',
			'places'      => [],  // per-place breakdown
		];

		if ( ! $api_key || ! $place_ids ) {
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

		// Sync each configured location independently. One place erroring (bad
		// ID, transient HTTP failure) must not abort the others — record its
		// error in the per-place breakdown and carry on.
		$errors = [];
		foreach ( $place_ids as $place_id ) {
			$one = $this->sync_one_place( $place_id, $api_key, $post_type );
			$result['places'][ $place_id ] = $one;
			$result['fetched']     += $one['fetched'];
			$result['created']     += $one['created'];
			$result['updated']     += $one['updated'];
			$result['deactivated'] += $one['deactivated'];
			if ( ! empty( $one['error'] ) ) {
				$errors[] = "{$place_id}: {$one['error']}";
			}
		}

		if ( $errors ) {
			// 'ok' only if every place succeeded; otherwise surface the failures.
			$result['status'] = ( count( $errors ) === count( $place_ids ) ) ? 'error' : 'partial';
			$result['error']  = implode( ' | ', $errors );
		}

		update_option( self::OPTION_LAST_SYNC, $result );
	}

	/**
	 * Fetch + upsert reviews for a single Place ID.
	 *
	 * @return array{fetched:int,created:int,updated:int,deactivated:int,error:string}
	 */
	private function sync_one_place( string $place_id, string $api_key, string $post_type ) : array {
		$out = [ 'fetched' => 0, 'created' => 0, 'updated' => 0, 'deactivated' => 0, 'error' => '' ];

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
			"https://places.googleapis.com/v1/places/{$place_id}?languageCode=" . rawurlencode( $this->get_language_code() ),
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
			$out['error'] = 'HTTP error: ' . $response->get_error_message();
			return $out;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$out['error'] = "HTTP {$code}: " . ( $body['error']['message'] ?? wp_remote_retrieve_body( $response ) );
			return $out;
		}

		$reviews        = $body['reviews'] ?? [];
		$out['fetched'] = count( $reviews );
		$seen_uris      = [];
		$min_rating     = $this->get_min_rating();

		foreach ( $reviews as $review ) {
			// Below the threshold: skip, and deliberately stay out of $seen_uris
			// so an already-synced review that drops under it gets deactivated.
			if ( (int) ( $review['rating'] ?? 0 ) < $min_rating ) continue;
			$author_uri = $review['authorAttribution']['uri'] ?? '';
			if ( ! $author_uri ) continue;
			$seen_uris[] = $author_uri;
			$op = $this->upsert_review( $review, $post_type, $place_id );
			if ( $op === 'created' ) $out['created']++;
			if ( $op === 'updated' ) $out['updated']++;
		}

		// Mark this place's posts inactive that didn't appear in this fetch.
		// Scoped per place_id, so other locations are never touched.
		$out['deactivated'] = $this->deactivate_missing( $post_type, $place_id, $seen_uris );

		return $out;
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

		// Find existing post by author URI *within this place*. Scoping by
		// place_id means the same author reviewing two locations yields one
		// card per location, instead of the second place's sync hijacking the
		// first place's post (and flipping its _gprs_place_id).
		$existing = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => self::META_AUTHOR_URI, 'value' => $author_uri ],
				[ 'key' => self::META_PLACE_ID,   'value' => $place_id ],
			],
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
