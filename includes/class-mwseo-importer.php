<?php
/**
 * Manny Wenas SEO — Importer
 *
 * Reads post meta from Yoast SEO, RankMath, AIOSEO, SEOPress, and
 * The SEO Framework and maps it into Manny Wenas SEO post meta.
 *
 * Hook in from the main plugin file:
 *
 *     MWSEO_Importer::init();  // called inside MWSEO_Plugin::__construct()
 *
 * @package MannyWenasSEO
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class MWSEO_Importer {

	/**
	 * Our post-meta key prefix.
	 */
	const PREFIX = '_mw_seo_';

	/**
	 * Posts processed per AJAX batch (keeps requests inside 30 s timeout).
	 */
	const BATCH_SIZE = 50;

	// -------------------------------------------------------------------------
	// Source-plugin registry
	// -------------------------------------------------------------------------

	/**
	 * All supported SEO plugins with detection and field maps.
	 *
	 * Each entry:
	 *   label      – display name shown in the admin UI
	 *   detect     – callable(): bool   true when the plugin is active
	 *   fields     – array<src_meta_key, dest_field_slug>
	 *   robots_cb  – callable(int $post_id): string[]   robots directives
	 *                because every plugin stores them differently
	 *
	 * Destination field slugs correspond to _mw_seo_{slug} post meta keys.
	 *
	 * @return array<string, array>
	 */
	private static function source_plugins(): array {
		return [

			// -----------------------------------------------------------------
			// Yoast SEO (Free & Premium)
			// -----------------------------------------------------------------
			'yoast' => [
				'label'  => 'Yoast SEO',
				'detect' => static fn() => defined( 'WPSEO_VERSION' ),
				'fields' => [
					'_yoast_wpseo_title'                 => 'title',
					'_yoast_wpseo_metadesc'              => 'description',
					'_yoast_wpseo_focuskw'               => 'focus_kw',
					'_yoast_wpseo_canonical'             => 'canonical',
					'_yoast_wpseo_opengraph-title'       => 'og_title',
					'_yoast_wpseo_opengraph-description' => 'og_description',
					'_yoast_wpseo_opengraph-image'       => 'og_image',
					'_yoast_wpseo_twitter-title'         => 'twitter_title',
					'_yoast_wpseo_twitter-description'   => 'twitter_description',
					'_yoast_wpseo_twitter-image'         => 'twitter_image',
					'_yoast_wpseo_is_cornerstone'        => 'cornerstone',
					'_yoast_wpseo_schema_page_type'      => 'schema_page_type',
					'_yoast_wpseo_schema_article_type'   => 'schema_article_type',
				],
				'robots_cb' => static function ( int $post_id ): array {
					$directives = [];
					if ( (int) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) === 1 ) {
						$directives[] = 'noindex';
					}
					if ( (int) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true ) === 1 ) {
						$directives[] = 'nofollow';
					}
					// Yoast advanced robots stored as comma-separated in _yoast_wpseo_meta-robots-adv.
					$adv = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', true );
					if ( $adv && $adv !== 'none' ) {
						foreach ( explode( ',', $adv ) as $d ) {
							$d = trim( $d );
							if ( $d ) {
								$directives[] = $d;
							}
						}
					}
					return array_unique( $directives );
				},
			],

			// -----------------------------------------------------------------
			// RankMath SEO (Free & Pro)
			// -----------------------------------------------------------------
			'rankmath' => [
				'label'  => 'RankMath SEO',
				'detect' => static fn() => defined( 'RANK_MATH_VERSION' ),
				'fields' => [
					'rank_math_title'                => 'title',
					'rank_math_description'          => 'description',
					'rank_math_focus_keyword'        => 'focus_kw',   // comma-separated; normalised below
					'rank_math_canonical_url'        => 'canonical',
					'rank_math_facebook_title'       => 'og_title',
					'rank_math_facebook_description' => 'og_description',
					'rank_math_og_content_image'     => 'og_image',
					'rank_math_twitter_title'        => 'twitter_title',
					'rank_math_twitter_description'  => 'twitter_description',
					'rank_math_twitter_image_url'    => 'twitter_image',
					'rank_math_pillar_content'       => 'cornerstone',
				],
				'robots_cb' => static function ( int $post_id ): array {
					// RankMath stores robots as a serialised array.
					$robots = get_post_meta( $post_id, 'rank_math_robots', true );
					if ( ! is_array( $robots ) ) {
						return [];
					}
					return array_values( array_intersect(
						$robots,
						[ 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' ]
					) );
				},
			],

			// -----------------------------------------------------------------
			// All in One SEO (AIOSEO) v4+
			// Note: AIOSEO v4 stores most data in its own tables but mirrors
			// the main fields back into post meta for compatibility.
			// -----------------------------------------------------------------
			'aioseo' => [
				'label'  => 'All in One SEO',
				'detect' => static fn() => defined( 'AIOSEO_VERSION' ),
				'fields' => [
					'_aioseo_title'               => 'title',
					'_aioseo_description'         => 'description',
					'_aioseo_keywords'            => 'focus_kw',   // comma-separated
					'_aioseo_og_title'            => 'og_title',
					'_aioseo_og_description'      => 'og_description',
					'_aioseo_og_image_custom_url' => 'og_image',
					'_aioseo_twitter_title'       => 'twitter_title',
					'_aioseo_twitter_description' => 'twitter_description',
					'_aioseo_twitter_image_url'   => 'twitter_image',
				],
				'robots_cb' => static function ( int $post_id ): array {
					$directives = [];
					if ( (int) get_post_meta( $post_id, '_aioseo_robots_noindex', true ) === 1 ) {
						$directives[] = 'noindex';
					}
					if ( (int) get_post_meta( $post_id, '_aioseo_robots_nofollow', true ) === 1 ) {
						$directives[] = 'nofollow';
					}
					if ( (int) get_post_meta( $post_id, '_aioseo_robots_noarchive', true ) === 1 ) {
						$directives[] = 'noarchive';
					}
					if ( (int) get_post_meta( $post_id, '_aioseo_robots_noimageindex', true ) === 1 ) {
						$directives[] = 'noimageindex';
					}
					if ( (int) get_post_meta( $post_id, '_aioseo_robots_nosnippet', true ) === 1 ) {
						$directives[] = 'nosnippet';
					}
					return $directives;
				},
			],

			// -----------------------------------------------------------------
			// SEOPress (Free & Pro)
			// -----------------------------------------------------------------
			'seopress' => [
				'label'  => 'SEOPress',
				'detect' => static fn() => defined( 'SEOPRESS_VERSION' ),
				'fields' => [
					'_seopress_titles_title'         => 'title',
					'_seopress_titles_desc'          => 'description',
					'_seopress_analysis_target_kw'   => 'focus_kw',
					'_seopress_titles_canonical_urls' => 'canonical',
					'_seopress_social_fb_title'      => 'og_title',
					'_seopress_social_fb_desc'       => 'og_description',
					'_seopress_social_fb_img'        => 'og_image',
					'_seopress_social_twitter_title' => 'twitter_title',
					'_seopress_social_twitter_desc'  => 'twitter_description',
					'_seopress_social_twitter_img'   => 'twitter_image',
				],
				'robots_cb' => static function ( int $post_id ): array {
					$directives = [];
					// SEOPress stores these as 'yes' strings.
					if ( get_post_meta( $post_id, '_seopress_robots_index', true ) === 'yes' ) {
						$directives[] = 'noindex';
					}
					if ( get_post_meta( $post_id, '_seopress_robots_follow', true ) === 'yes' ) {
						$directives[] = 'nofollow';
					}
					if ( get_post_meta( $post_id, '_seopress_robots_imageindex', true ) === 'yes' ) {
						$directives[] = 'noimageindex';
					}
					if ( get_post_meta( $post_id, '_seopress_robots_archive', true ) === 'yes' ) {
						$directives[] = 'noarchive';
					}
					if ( get_post_meta( $post_id, '_seopress_robots_snippet', true ) === 'yes' ) {
						$directives[] = 'nosnippet';
					}
					return $directives;
				},
			],

			// -----------------------------------------------------------------
			// The SEO Framework (TSF)
			// -----------------------------------------------------------------
			'tsf' => [
				'label'  => 'The SEO Framework',
				'detect' => static fn() => defined( 'THE_SEO_FRAMEWORK_VERSION' ),
				'fields' => [
					// TSF uses _genesis_* keys on older versions; newer versions
					// still write compatible meta for migration purposes.
					'_genesis_title'          => 'title',
					'_genesis_description'    => 'description',
					'_genesis_canonical_uri'  => 'canonical',
					// TSF does not store a focus keyword by default — no entry.
					// Social titles / descriptions (TSF stores in its own option
					// table per post but also writes these fallback keys).
					'_open_graph_title'       => 'og_title',
					'_open_graph_description' => 'og_description',
					'_twitter_title'          => 'twitter_title',
					'_twitter_description'    => 'twitter_description',
				],
				'robots_cb' => static function ( int $post_id ): array {
					$directives = [];
					if ( (int) get_post_meta( $post_id, '_genesis_noindex', true ) === 1 ) {
						$directives[] = 'noindex';
					}
					if ( (int) get_post_meta( $post_id, '_genesis_nofollow', true ) === 1 ) {
						$directives[] = 'nofollow';
					}
					if ( (int) get_post_meta( $post_id, '_genesis_noarchive', true ) === 1 ) {
						$directives[] = 'noarchive';
					}
					return $directives;
				},
			],
		];
	}

	// -------------------------------------------------------------------------
	// Detection
	// -------------------------------------------------------------------------

	/**
	 * Return which of the supported SEO plugins are currently active.
	 *
	 * @return array<string, string>  slug => label
	 */
	public static function detect_active_plugins(): array {
		$active = [];
		foreach ( static::source_plugins() as $slug => $config ) {
			if ( ( $config['detect'] )() ) {
				$active[ $slug ] = $config['label'];
			}
		}
		return $active;
	}

	// -------------------------------------------------------------------------
	// Admin page
	// -------------------------------------------------------------------------

	/**
	 * Register the Import submenu page under the MW SEO top-level menu.
	 * Hooked to 'admin_menu'.
	 */
	public static function register_admin_page(): void {
		add_submenu_page(
			'mwseo',
			__( 'Import SEO Data', 'mw-seo' ),
			__( 'Import', 'mw-seo' ),
			'manage_options',
			'mw-seo-import',
			[ static::class, 'render_admin_page' ]
		);
	}

	/**
	 * Render the import admin page.
	 */
	public static function render_admin_page(): void {
		$active = static::detect_active_plugins();
		?>
		<div class="wrap" id="mw-seo-importer">
			<h1><?php esc_html_e( 'Import SEO Data', 'mw-seo' ); ?></h1>

			<?php if ( empty( $active ) ) : ?>

				<div class="notice notice-info"><p>
					<?php esc_html_e(
						'No supported SEO plugins detected on this site. Supported sources: Yoast SEO, RankMath, All in One SEO, SEOPress, The SEO Framework.',
						'mw-seo'
					); ?>
				</p></div>

			<?php else : ?>

			<p class="description">
				<?php esc_html_e(
					'Import SEO titles, meta descriptions, focus keyphrases, robots directives, and social meta from a previously installed SEO plugin into Manny Wenas SEO. Existing MW SEO values are preserved unless you check "Overwrite".',
					'mw-seo'
				); ?>
			</p>

			<form id="mw-seo-import-form">
				<?php wp_nonce_field( 'mw_seo_import', '_mw_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="mw-import-source"><?php esc_html_e( 'Import from', 'mw-seo' ); ?></label>
						</th>
						<td>
							<select id="mw-import-source" name="source" required>
								<option value=""><?php esc_html_e( '— select a plugin —', 'mw-seo' ); ?></option>
								<?php foreach ( $active as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>">
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'mw-seo' ); ?></th>
						<td>
							<?php
							$post_types = get_post_types( [ 'public' => true ], 'objects' );
							foreach ( $post_types as $pt ) :
							?>
							<label style="margin-right:16px;display:inline-flex;align-items:center;gap:4px;">
								<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
									<?php checked( in_array( $pt->name, [ 'post', 'page' ], true ) ); ?>>
								<?php echo esc_html( $pt->label ); ?>
							</label>
							<?php endforeach; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'mw-seo' ); ?></th>
						<td>
							<label style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
								<input type="checkbox" name="overwrite" value="1">
								<?php esc_html_e( 'Overwrite existing Manny Wenas SEO data', 'mw-seo' ); ?>
							</label>
							<label style="display:flex;align-items:center;gap:6px;">
								<input type="checkbox" name="dry_run" value="1" checked>
								<?php esc_html_e( 'Dry run — preview only, no data written', 'mw-seo' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<!-- Field-map preview (populated via JS on source change) -->
				<div id="mw-import-field-map" style="display:none;margin-top:16px;">
					<h3><?php esc_html_e( 'Field map', 'mw-seo' ); ?></h3>
					<div id="mw-import-field-map-content"></div>
				</div>

				<p class="submit">
					<button type="button" id="mw-import-preview-btn" class="button button-secondary">
						<?php esc_html_e( 'Preview', 'mw-seo' ); ?>
					</button>
					&nbsp;
					<button type="submit" id="mw-import-run-btn" class="button button-primary" disabled>
						<?php esc_html_e( 'Run Import', 'mw-seo' ); ?>
					</button>
				</p>
			</form>

			<!-- Progress bar -->
			<div id="mw-import-progress" style="display:none;max-width:640px;">
				<h3><?php esc_html_e( 'Progress', 'mw-seo' ); ?></h3>
				<div style="background:#e0e0e0;border-radius:4px;height:22px;width:100%;overflow:hidden;">
					<div id="mw-import-progress-bar"
						style="background:#0073aa;height:100%;border-radius:4px;width:0;transition:width .25s;"></div>
				</div>
				<p id="mw-import-progress-text" style="margin-top:6px;"></p>
			</div>

			<!-- Results -->
			<div id="mw-import-results" style="display:none;max-width:640px;">
				<h3><?php esc_html_e( 'Results', 'mw-seo' ); ?></h3>
				<div id="mw-import-results-content"></div>
			</div>

			<?php endif; ?>
		</div><!-- .wrap -->

		<script>
		(function($){
			'use strict';

			/* Human-readable labels for destination field slugs */
			const FIELD_LABELS = {
				title:               '<?php echo esc_js( __( 'SEO title', 'mw-seo' ) ); ?>',
				description:         '<?php echo esc_js( __( 'Meta description', 'mw-seo' ) ); ?>',
				focus_kw:            '<?php echo esc_js( __( 'Focus keyphrase', 'mw-seo' ) ); ?>',
				canonical:           '<?php echo esc_js( __( 'Canonical URL', 'mw-seo' ) ); ?>',
				robots:              '<?php echo esc_js( __( 'Robots directives', 'mw-seo' ) ); ?>',
				og_title:            '<?php echo esc_js( __( 'OG title', 'mw-seo' ) ); ?>',
				og_description:      '<?php echo esc_js( __( 'OG description', 'mw-seo' ) ); ?>',
				og_image:            '<?php echo esc_js( __( 'OG image URL', 'mw-seo' ) ); ?>',
				twitter_title:       '<?php echo esc_js( __( 'Twitter title', 'mw-seo' ) ); ?>',
				twitter_description: '<?php echo esc_js( __( 'Twitter description', 'mw-seo' ) ); ?>',
				twitter_image:       '<?php echo esc_js( __( 'Twitter image URL', 'mw-seo' ) ); ?>',
				cornerstone:         '<?php echo esc_js( __( 'Cornerstone content', 'mw-seo' ) ); ?>',
				schema_page_type:    '<?php echo esc_js( __( 'Schema page type', 'mw-seo' ) ); ?>',
				schema_article_type: '<?php echo esc_js( __( 'Schema article type', 'mw-seo' ) ); ?>',
			};

			/* Field maps emitted server-side — avoids a separate AJAX round-trip */
			const FIELD_MAPS = <?php echo wp_json_encode( static::get_field_maps_for_js() ); ?>;

			/* ---- Field-map preview ---------------------------------------- */
			$('#mw-import-source').on('change', function(){
				const source = $(this).val();
				const map    = FIELD_MAPS[source];
				if (!source || !map) { $('#mw-import-field-map').hide(); return; }

				let rows = '';
				for (const [src, dest] of Object.entries(map)) {
					rows += `<tr>
						<td><code style="font-size:12px">${src}</code></td>
						<td style="text-align:center">→</td>
						<td>${FIELD_LABELS[dest] || dest}</td>
					</tr>`;
				}
				// Add robots row — always present.
				rows += `<tr>
					<td><em style="font-size:12px"><?php echo esc_js( __( '(plugin-specific robots keys)', 'mw-seo' ) ); ?></em></td>
					<td style="text-align:center">→</td>
					<td>${FIELD_LABELS.robots}</td>
				</tr>`;

				$('#mw-import-field-map-content').html(
					`<table class="wp-list-table widefat striped" style="max-width:580px;">
						<thead>
							<tr>
								<th><?php echo esc_js( __( 'Source field (post meta key)', 'mw-seo' ) ); ?></th>
								<th></th>
								<th><?php echo esc_js( __( 'MW SEO field', 'mw-seo' ) ); ?></th>
							</tr>
						</thead>
						<tbody>${rows}</tbody>
					</table>`
				);
				$('#mw-import-field-map').show();
				$('#mw-import-run-btn').prop('disabled', false);
			});

			/* ---- Trigger handlers ----------------------------------------- */
			$('#mw-import-preview-btn').on('click', function(){
				runImport(true);
			});

			$('#mw-import-form').on('submit', function(e){
				e.preventDefault();
				runImport(false);
			});

			/* ---- Core import runner --------------------------------------- */
			function runImport(dryRun){
				const source    = $('#mw-import-source').val();
				const postTypes = $('input[name="post_types[]"]:checked').map((i, el) => el.value).get();
				const overwrite = $('input[name="overwrite"]').is(':checked');
				const nonce     = $('#_mw_nonce').val();

				if (!source)          { alert('<?php echo esc_js( __( 'Please select a source plugin.', 'mw-seo' ) ); ?>'); return; }
				if (!postTypes.length){ alert('<?php echo esc_js( __( 'Please select at least one post type.', 'mw-seo' ) ); ?>'); return; }

				$('#mw-import-progress').show();
				$('#mw-import-results').hide();
				$('#mw-import-run-btn, #mw-import-preview-btn').prop('disabled', true);
				$('#mw-import-progress-bar').css('width', '0');
				$('#mw-import-progress-text').text('');

				const totals = { imported:0, skipped:0, unchanged:0, errors:0 };
				let offset   = 0;

				(function batch(){
					$.post(ajaxurl, {
						action:     'mw_seo_import_batch',
						nonce,
						source,
						post_types: postTypes,
						overwrite:  overwrite ? 1 : 0,
						dry_run:    dryRun    ? 1 : 0,
						offset,
						batch_size: <?php echo (int) static::BATCH_SIZE; ?>,
					}).done(function(res){
						if (!res.success) {
							$('#mw-import-progress-text').text('Error: ' + (res.data || 'unknown error'));
							$('#mw-import-run-btn, #mw-import-preview-btn').prop('disabled', false);
							return;
						}

						const d = res.data;
						totals.imported  += d.imported;
						totals.skipped   += d.skipped;
						totals.unchanged += d.unchanged;
						totals.errors    += d.errors;

						const pct = d.total > 0 ? Math.min(100, Math.round((d.processed / d.total) * 100)) : 100;
						$('#mw-import-progress-bar').css('width', pct + '%');
						$('#mw-import-progress-text').text(d.processed + ' / ' + d.total + ' posts');

						if (d.done) {
							const label = dryRun ? '<?php echo esc_js( __( 'Dry run complete — no data was written.', 'mw-seo' ) ); ?>'
							                     : '<?php echo esc_js( __( 'Import complete.', 'mw-seo' ) ); ?>';
							let html = `<p><strong>${label}</strong></p><ul style="margin-left:1.5em;list-style:disc">
								<li><?php echo esc_js( __( 'Imported:', 'mw-seo' ) ); ?> <strong>${totals.imported}</strong></li>
								<li><?php echo esc_js( __( 'Skipped (MW SEO data already exists):', 'mw-seo' ) ); ?> <strong>${totals.skipped}</strong></li>
								<li><?php echo esc_js( __( 'Unchanged (no source data found):', 'mw-seo' ) ); ?> <strong>${totals.unchanged}</strong></li>
								<li><?php echo esc_js( __( 'Errors (see PHP error log):', 'mw-seo' ) ); ?> <strong>${totals.errors}</strong></li>
							</ul>`;
							if (dryRun) {
								html += `<p><?php echo esc_js( __( 'Uncheck "Dry run" and click Run Import to apply changes.', 'mw-seo' ) ); ?></p>`;
							}
							$('#mw-import-results-content').html(html);
							$('#mw-import-results').show();
							$('#mw-import-run-btn, #mw-import-preview-btn').prop('disabled', false);
						} else {
							offset = d.next_offset;
							batch();
						}
					}).fail(function(){
						$('#mw-import-progress-text').text('Request failed. Check your network connection.');
						$('#mw-import-run-btn, #mw-import-preview-btn').prop('disabled', false);
					});
				}());
			}
		}(jQuery));
		</script>
		<?php
	}

	/**
	 * Build field-map data for the admin page JS (source_meta_key => dest_field_slug).
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function get_field_maps_for_js(): array {
		$out = [];
		foreach ( static::source_plugins() as $slug => $config ) {
			$out[ $slug ] = $config['fields'];
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// AJAX batch handler
	// -------------------------------------------------------------------------

	/**
	 * Register AJAX handlers.
	 */
	public static function register_ajax(): void {
		add_action( 'wp_ajax_mw_seo_import_batch', [ static::class, 'ajax_import_batch' ] );
	}

	/**
	 * AJAX — process one batch of posts and return counts + pagination state.
	 */
	public static function ajax_import_batch(): void {
		check_ajax_referer( 'mw_seo_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}

		$source     = sanitize_key( $_POST['source']     ?? '' );
		$post_types = array_map( 'sanitize_key', (array) ( $_POST['post_types'] ?? [ 'post', 'page' ] ) );
		$overwrite  = (bool) ( $_POST['overwrite']  ?? false );
		$dry_run    = (bool) ( $_POST['dry_run']    ?? true  );
		$offset     = (int)  ( $_POST['offset']     ?? 0     );
		$batch_size = min( (int) ( $_POST['batch_size'] ?? static::BATCH_SIZE ), 200 );

		$plugins = static::source_plugins();
		if ( ! isset( $plugins[ $source ] ) ) {
			wp_send_json_error( 'Unknown source plugin: ' . esc_html( $source ) );
		}

		$config = $plugins[ $source ];

		// Total posts (approximate — for progress display only).
		$total = (int) array_sum( array_map(
			static fn( string $pt ) => (int) wp_count_posts( $pt )->publish,
			$post_types
		) );

		// Fetch one batch ordered by ID for stable pagination.
		$query = new WP_Query( [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );

		$imported  = 0;
		$skipped   = 0;
		$unchanged = 0;
		$errors    = 0;

		foreach ( $query->posts as $post_id ) {
			try {
				switch ( static::import_post( (int) $post_id, $config, $overwrite, $dry_run ) ) {
					case 'imported':  $imported++;  break;
					case 'skipped':   $skipped++;   break;
					case 'unchanged': $unchanged++; break;
				}
			} catch ( \Throwable $e ) {
				$errors++;
				error_log( sprintf( '[MW SEO Importer] Post %d: %s', $post_id, $e->getMessage() ) );
			}
		}

		$count     = count( $query->posts );
		$processed = $offset + $count;

		wp_send_json_success( [
			'imported'    => $imported,
			'skipped'     => $skipped,
			'unchanged'   => $unchanged,
			'errors'      => $errors,
			'processed'   => $processed,
			'total'       => $total,
			'next_offset' => $processed,
			'done'        => $count < $batch_size,
		] );
	}

	// -------------------------------------------------------------------------
	// Per-post import logic
	// -------------------------------------------------------------------------

	/**
	 * Import SEO meta for a single post.
	 *
	 * @param int   $post_id   WordPress post ID.
	 * @param array $config    Entry from source_plugins().
	 * @param bool  $overwrite Overwrite existing MW SEO values.
	 * @param bool  $dry_run   When true, compute writes but don't persist them.
	 * @return string  'imported' | 'skipped' | 'unchanged'
	 */
	private static function import_post(
		int   $post_id,
		array $config,
		bool  $overwrite,
		bool  $dry_run
	): string {
		$has_source_data = false;
		$writes          = [];

		// -- Standard fields -------------------------------------------------
		foreach ( $config['fields'] as $src_key => $dest_field ) {
			$raw = get_post_meta( $post_id, $src_key, true );

			// Skip genuinely empty values.
			if ( $raw === '' || $raw === false || $raw === null ) {
				continue;
			}

			$has_source_data = true;

			// Normalise focus_kw — may arrive as a comma-separated string
			// (RankMath, AIOSEO) or a single string (Yoast, SEOPress).
			if ( $dest_field === 'focus_kw' ) {
				if ( is_string( $raw ) ) {
					$raw = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
				}
				// Already an array from some sources — keep as-is.
			}

			// Normalise cornerstone — various sources use '1', 'yes', true.
			if ( $dest_field === 'cornerstone' ) {
				$raw = ( $raw && $raw !== '0' && $raw !== 'no' ) ? '1' : '0';
			}

			$dest_key = static::PREFIX . $dest_field;
			$existing = get_post_meta( $post_id, $dest_key, true );

			// Skip if already set and not overwriting.
			if ( ( $existing !== '' && $existing !== false ) && ! $overwrite ) {
				continue;
			}

			$writes[ $dest_key ] = $raw;
		}

		// -- Robots ----------------------------------------------------------
		if ( isset( $config['robots_cb'] ) ) {
			$directives = ( $config['robots_cb'] )( $post_id );
			if ( ! empty( $directives ) ) {
				$has_source_data = true;
				$dest_key        = static::PREFIX . 'robots';
				$existing        = get_post_meta( $post_id, $dest_key, true );
				if ( ( empty( $existing ) || $overwrite ) ) {
					$writes[ $dest_key ] = array_unique( $directives );
				}
			}
		}

		// -- Outcome ---------------------------------------------------------
		if ( ! $has_source_data ) {
			return 'unchanged'; // source plugin left no data on this post
		}

		if ( empty( $writes ) ) {
			return 'skipped'; // source had data, but all dest fields were populated and overwrite=false
		}

		if ( ! $dry_run ) {
			foreach ( $writes as $key => $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		return 'imported';
	}

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Wire up hooks.  Call once from the main plugin file on 'plugins_loaded'.
	 *
	 * Example:
	 *   add_action( 'plugins_loaded', function() {
	 *       require_once plugin_dir_path( __FILE__ ) . 'includes/class-mw-seo-importer.php';
	 *       MWSEO_Importer::init();
	 *   } );
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ static::class, 'register_admin_page' ], 20 );
		static::register_ajax();
	}
}
