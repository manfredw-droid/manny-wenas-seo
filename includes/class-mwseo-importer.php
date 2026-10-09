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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports SEO data from Yoast, Rank Math, AIOSEO and SEOPress.
 */
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
		return array(

			// -----------------------------------------------------------------
			// Yoast SEO (Free & Premium)
			// -----------------------------------------------------------------
			'yoast'    => array(
				'label'     => 'Yoast SEO',
				'detect'    => static fn() => defined( 'WPSEO_VERSION' ),
				'fields'    => array(
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
				),
				'robots_cb' => static function ( int $post_id ): array {
					$directives = array();
					if ( (int) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) === 1 ) {
						$directives[] = 'noindex';
					}
					if ( (int) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true ) === 1 ) {
						$directives[] = 'nofollow';
					}
					// Yoast advanced robots stored as comma-separated in _yoast_wpseo_meta-robots-adv.
					$adv = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', true );
					if ( $adv && 'none' !== $adv ) {
						foreach ( explode( ',', $adv ) as $d ) {
							$d = trim( $d );
							if ( $d ) {
								$directives[] = $d;
							}
						}
					}
					return array_unique( $directives );
				},
			),

			// -----------------------------------------------------------------
			// RankMath SEO (Free & Pro)
			// -----------------------------------------------------------------
			'rankmath' => array(
				'label'     => 'RankMath SEO',
				'detect'    => static fn() => defined( 'RANK_MATH_VERSION' ),
				'fields'    => array(
					'rank_math_title'                => 'title',
					'rank_math_description'          => 'description',
					'rank_math_focus_keyword'        => 'focus_kw',   // Comma-separated; normalised below.
					'rank_math_canonical_url'        => 'canonical',
					'rank_math_facebook_title'       => 'og_title',
					'rank_math_facebook_description' => 'og_description',
					'rank_math_og_content_image'     => 'og_image',
					'rank_math_twitter_title'        => 'twitter_title',
					'rank_math_twitter_description'  => 'twitter_description',
					'rank_math_twitter_image_url'    => 'twitter_image',
					'rank_math_pillar_content'       => 'cornerstone',
				),
				'robots_cb' => static function ( int $post_id ): array {
					// RankMath stores robots as a serialised array.
					$robots = get_post_meta( $post_id, 'rank_math_robots', true );
					if ( ! is_array( $robots ) ) {
						return array();
					}
					return array_values(
						array_intersect(
							$robots,
							array( 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' )
						)
					);
				},
			),

			// -----------------------------------------------------------------
			// All in One SEO (AIOSEO) v4+
			// Note: AIOSEO v4 stores most data in its own tables but mirrors
			// the main fields back into post meta for compatibility.
			// -----------------------------------------------------------------
			'aioseo'   => array(
				'label'     => 'All in One SEO',
				'detect'    => static fn() => defined( 'AIOSEO_VERSION' ),
				'fields'    => array(
					'_aioseo_title'               => 'title',
					'_aioseo_description'         => 'description',
					'_aioseo_keywords'            => 'focus_kw',   // Comma-separated.
					'_aioseo_og_title'            => 'og_title',
					'_aioseo_og_description'      => 'og_description',
					'_aioseo_og_image_custom_url' => 'og_image',
					'_aioseo_twitter_title'       => 'twitter_title',
					'_aioseo_twitter_description' => 'twitter_description',
					'_aioseo_twitter_image_url'   => 'twitter_image',
				),
				'robots_cb' => static function ( int $post_id ): array {
					$directives = array();
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
			),

			// -----------------------------------------------------------------
			// SEOPress (Free & Pro)
			// -----------------------------------------------------------------
			'seopress' => array(
				'label'     => 'SEOPress',
				'detect'    => static fn() => defined( 'SEOPRESS_VERSION' ),
				'fields'    => array(
					'_seopress_titles_title'          => 'title',
					'_seopress_titles_desc'           => 'description',
					'_seopress_analysis_target_kw'    => 'focus_kw',
					'_seopress_titles_canonical_urls' => 'canonical',
					'_seopress_social_fb_title'       => 'og_title',
					'_seopress_social_fb_desc'        => 'og_description',
					'_seopress_social_fb_img'         => 'og_image',
					'_seopress_social_twitter_title'  => 'twitter_title',
					'_seopress_social_twitter_desc'   => 'twitter_description',
					'_seopress_social_twitter_img'    => 'twitter_image',
				),
				'robots_cb' => static function ( int $post_id ): array {
					$directives = array();
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
			),

			// -----------------------------------------------------------------
			// The SEO Framework (TSF)
			// -----------------------------------------------------------------
			'tsf'      => array(
				'label'     => 'The SEO Framework',
				'detect'    => static fn() => defined( 'THE_SEO_FRAMEWORK_VERSION' ),
				'fields'    => array(
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
				),
				'robots_cb' => static function ( int $post_id ): array {
					$directives = array();
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
			),
		);
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
		$active = array();
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
	 * Hook suffix of the import page (set when the submenu is registered).
	 *
	 * @var string|false
	 */
	private static $page_hook = false;

	/**
	 * Enqueue the importer script on the import page only. Hooked to
	 * 'admin_enqueue_scripts'.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook ): void {
		if ( ! static::$page_hook || $hook !== static::$page_hook ) {
			return;
		}
		// Handle without a file: it only carries the inline script.
		wp_register_script( 'mwseo-importer', false, array( 'jquery' ), MWSEO_VERSION, true );
		wp_enqueue_script( 'mwseo-importer' );
		wp_add_inline_script(
			'mwseo-importer',
			'const cfg = ' . wp_json_encode( static::script_data() ) . ';' . "\n" . static::script_body()
		);
	}

	/**
	 * Data handed to the importer script.
	 *
	 * @return array
	 */
	private static function script_data(): array {
		return array(
			'fieldMaps' => static::get_field_maps_for_js(),
			'batchSize' => (int) static::BATCH_SIZE,
			'i18n'      => array(
				'seo_title'                                => __( 'SEO title', 'manny-wenas-seo' ),
				'meta_description'                         => __( 'Meta description', 'manny-wenas-seo' ),
				'focus_keyphrase'                          => __( 'Focus keyphrase', 'manny-wenas-seo' ),
				'canonical_url'                            => __( 'Canonical URL', 'manny-wenas-seo' ),
				'robots_directives'                        => __( 'Robots directives', 'manny-wenas-seo' ),
				'og_title'                                 => __( 'OG title', 'manny-wenas-seo' ),
				'og_description'                           => __( 'OG description', 'manny-wenas-seo' ),
				'og_image_url'                             => __( 'OG image URL', 'manny-wenas-seo' ),
				'twitter_title'                            => __( 'Twitter title', 'manny-wenas-seo' ),
				'twitter_description'                      => __( 'Twitter description', 'manny-wenas-seo' ),
				'twitter_image_url'                        => __( 'Twitter image URL', 'manny-wenas-seo' ),
				'anchor_post'                              => __( 'Anchor post', 'manny-wenas-seo' ),
				'schema_page_type'                         => __( 'Schema page type', 'manny-wenas-seo' ),
				'schema_article_type'                      => __( 'Schema article type', 'manny-wenas-seo' ),
				'please_select_a_source_plugin'            => __( 'Please select a source plugin.', 'manny-wenas-seo' ),
				'please_select_at_least_one_post_type'     => __( 'Please select at least one post type.', 'manny-wenas-seo' ),
				'dry_run_complete_no_data_was_written'     => __( 'Dry run complete — no data was written.', 'manny-wenas-seo' ),
				'import_complete'                          => __( 'Import complete.', 'manny-wenas-seo' ),
				'plugin_specific_robots_keys'              => __( '(plugin-specific robots keys)', 'manny-wenas-seo' ),
				'source_field_post_meta_key'               => __( 'Source field (post meta key)', 'manny-wenas-seo' ),
				'mw_seo_field'                             => __( 'MW SEO field', 'manny-wenas-seo' ),
				'imported'                                 => __( 'Imported:', 'manny-wenas-seo' ),
				'skipped_mw_seo_data_already_exists'       => __( 'Skipped (MW SEO data already exists):', 'manny-wenas-seo' ),
				'unchanged_no_source_data_found'           => __( 'Unchanged (no source data found):', 'manny-wenas-seo' ),
				'errors_see_php_error_log'                 => __( 'Errors:', 'manny-wenas-seo' ),
				'uncheck_dry_run_and_click_run_import_to_' => __( 'Uncheck "Dry run" and click Run Import to apply changes.', 'manny-wenas-seo' ),
			),
		);
	}

	/**
	 * Importer script (reads its data from the `cfg` constant defined before it).
	 *
	 * @return string
	 */
	private static function script_body(): string {
		return <<<'JS'
(function($){
	'use strict';

	/* Human-readable labels for destination field slugs */
	const FIELD_LABELS = {
		title:               cfg.i18n.seo_title,
		description:         cfg.i18n.meta_description,
		focus_kw:            cfg.i18n.focus_keyphrase,
		canonical:           cfg.i18n.canonical_url,
		robots:              cfg.i18n.robots_directives,
		og_title:            cfg.i18n.og_title,
		og_description:      cfg.i18n.og_description,
		og_image:            cfg.i18n.og_image_url,
		twitter_title:       cfg.i18n.twitter_title,
		twitter_description: cfg.i18n.twitter_description,
		twitter_image:       cfg.i18n.twitter_image_url,
		cornerstone:         cfg.i18n.anchor_post,
		schema_page_type:    cfg.i18n.schema_page_type,
		schema_article_type: cfg.i18n.schema_article_type,
	};

	/* Field maps emitted server-side — avoids a separate AJAX round-trip */
	const FIELD_MAPS = cfg.fieldMaps;

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
			<td><em style="font-size:12px">${cfg.i18n.plugin_specific_robots_keys}</em></td>
			<td style="text-align:center">→</td>
			<td>${FIELD_LABELS.robots}</td>
		</tr>`;

		$('#mw-import-field-map-content').html(
			`<table class="wp-list-table widefat striped" style="max-width:580px;">
				<thead>
					<tr>
						<th>${cfg.i18n.source_field_post_meta_key}</th>
						<th></th>
						<th>${cfg.i18n.mw_seo_field}</th>
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

	$('#mw-seo-import-form').on('submit', function(e){
		e.preventDefault();
		runImport(false);
	});

	/* ---- Core import runner --------------------------------------- */
	function runImport(dryRun){
		const source    = $('#mw-import-source').val();
		const postTypes = $('input[name="post_types[]"]:checked').map((i, el) => el.value).get();
		const overwrite = $('input[name="overwrite"]').is(':checked');
		const nonce     = $('#_mw_nonce').val();

		if (!source)          { alert(cfg.i18n.please_select_a_source_plugin); return; }
		if (!postTypes.length){ alert(cfg.i18n.please_select_at_least_one_post_type); return; }

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
				batch_size: cfg.batchSize,
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
					const label = dryRun ? cfg.i18n.dry_run_complete_no_data_was_written
										: cfg.i18n.import_complete;
					let html = `<p><strong>${label}</strong></p><ul style="margin-left:1.5em;list-style:disc">
						<li>${cfg.i18n.imported} <strong>${totals.imported}</strong></li>
						<li>${cfg.i18n.skipped_mw_seo_data_already_exists} <strong>${totals.skipped}</strong></li>
						<li>${cfg.i18n.unchanged_no_source_data_found} <strong>${totals.unchanged}</strong></li>
						<li>${cfg.i18n.errors_see_php_error_log} <strong>${totals.errors}</strong></li>
					</ul>`;
					if (dryRun) {
						html += `<p>${cfg.i18n.uncheck_dry_run_and_click_run_import_to_}</p>`;
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

JS;
	}

	/**
	 * Register the Import submenu page under the MW SEO top-level menu.
	 * Hooked to 'admin_menu'.
	 */
	public static function register_admin_page(): void {
		static::$page_hook = add_submenu_page(
			'mwseo',
			__( 'Import SEO Data', 'manny-wenas-seo' ),
			__( 'Import', 'manny-wenas-seo' ),
			'manage_options',
			'mw-seo-import',
			array( static::class, 'render_admin_page' )
		);
	}

	/**
	 * Render the import admin page.
	 */
	public static function render_admin_page(): void {
		$active = static::detect_active_plugins();
		?>
		<div class="wrap" id="mw-seo-importer">
			<h1><?php esc_html_e( 'Import SEO Data', 'manny-wenas-seo' ); ?></h1>

			<?php if ( empty( $active ) ) : ?>

				<div class="notice notice-info"><p>
					<?php
					esc_html_e(
						'No supported SEO plugins detected on this site. Supported sources: Yoast SEO, RankMath, All in One SEO, SEOPress, The SEO Framework.',
						'manny-wenas-seo'
					);
					?>
				</p></div>

			<?php else : ?>

			<p class="description">
				<?php
				esc_html_e(
					'Import SEO titles, meta descriptions, focus keyphrases, robots directives, and social meta from a previously installed SEO plugin into Manny Wenas SEO. Existing MW SEO values are preserved unless you check "Overwrite".',
					'manny-wenas-seo'
				);
				?>
			</p>

			<form id="mw-seo-import-form" method="post" action="">
				<?php wp_nonce_field( 'mw_seo_import', '_mw_nonce' ); ?>
				<input type="hidden" name="page" value="mw-seo-import">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="mw-import-source"><?php esc_html_e( 'Import from', 'manny-wenas-seo' ); ?></label>
						</th>
						<td>
							<select id="mw-import-source" name="source" required>
								<option value=""><?php esc_html_e( '— select a plugin —', 'manny-wenas-seo' ); ?></option>
								<?php foreach ( $active as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>">
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'manny-wenas-seo' ); ?></th>
						<td>
							<?php
							$post_types = get_post_types( array( 'public' => true ), 'objects' );
							foreach ( $post_types as $pt ) :
								?>
							<label style="margin-right:16px;display:inline-flex;align-items:center;gap:4px;">
								<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
									<?php checked( in_array( $pt->name, array( 'post', 'page' ), true ) ); ?>>
								<?php echo esc_html( $pt->label ); ?>
							</label>
							<?php endforeach; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'manny-wenas-seo' ); ?></th>
						<td>
							<label style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
								<input type="checkbox" name="overwrite" value="1">
								<?php esc_html_e( 'Overwrite existing Manny Wenas SEO data', 'manny-wenas-seo' ); ?>
							</label>
							<label style="display:flex;align-items:center;gap:6px;">
								<input type="checkbox" name="dry_run" value="1" checked>
								<?php esc_html_e( 'Dry run — preview only, no data written', 'manny-wenas-seo' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<!-- Field-map preview (populated via JS on source change) -->
				<div id="mw-import-field-map" style="display:none;margin-top:16px;">
					<h3><?php esc_html_e( 'Field map', 'manny-wenas-seo' ); ?></h3>
					<div id="mw-import-field-map-content"></div>
				</div>

				<p class="submit">
					<button type="button" id="mw-import-preview-btn" class="button button-secondary">
						<?php esc_html_e( 'Preview', 'manny-wenas-seo' ); ?>
					</button>
					&nbsp;
					<button type="submit" id="mw-import-run-btn" class="button button-primary" disabled>
						<?php esc_html_e( 'Run Import', 'manny-wenas-seo' ); ?>
					</button>
				</p>
			</form>

			<!-- Progress bar -->
			<div id="mw-import-progress" style="display:none;max-width:640px;">
				<h3><?php esc_html_e( 'Progress', 'manny-wenas-seo' ); ?></h3>
				<div style="background:#e0e0e0;border-radius:4px;height:22px;width:100%;overflow:hidden;">
					<div id="mw-import-progress-bar"
						style="background:#0073aa;height:100%;border-radius:4px;width:0;transition:width .25s;"></div>
				</div>
				<p id="mw-import-progress-text" style="margin-top:6px;"></p>
			</div>

			<!-- Results -->
			<div id="mw-import-results" style="display:none;max-width:640px;">
				<h3><?php esc_html_e( 'Results', 'manny-wenas-seo' ); ?></h3>
				<div id="mw-import-results-content"></div>
			</div>

			<?php endif; ?>
		</div><!-- .wrap -->
		<?php
	}

	/**
	 * Build field-map data for the admin page JS (source_meta_key => dest_field_slug).
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function get_field_maps_for_js(): array {
		$out = array();
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
		add_action( 'wp_ajax_mw_seo_import_batch', array( static::class, 'ajax_import_batch' ) );
	}

	/**
	 * AJAX — process one batch of posts and return counts + pagination state.
	 */
	public static function ajax_import_batch(): void {
		check_ajax_referer( 'mw_seo_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'manny-wenas-seo' ), 403 );
		}

		$source     = sanitize_key( wp_unslash( $_POST['source'] ?? '' ) );
		$post_types = array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ?? array( 'post', 'page' ) ) );
		$overwrite  = isset( $_POST['overwrite'] ) && rest_sanitize_boolean( sanitize_text_field( wp_unslash( $_POST['overwrite'] ) ) );
		$dry_run    = ! isset( $_POST['dry_run'] ) || rest_sanitize_boolean( sanitize_text_field( wp_unslash( $_POST['dry_run'] ) ) );
		$offset     = isset( $_POST['offset'] ) ? absint( sanitize_text_field( wp_unslash( $_POST['offset'] ) ) ) : 0;
		$batch_size = min( isset( $_POST['batch_size'] ) ? absint( sanitize_text_field( wp_unslash( $_POST['batch_size'] ) ) ) : static::BATCH_SIZE, 200 );

		$plugins = static::source_plugins();
		if ( ! isset( $plugins[ $source ] ) ) {
			/* translators: %s: source plugin identifier */
			wp_send_json_error( sprintf( __( 'Unknown source plugin: %s', 'manny-wenas-seo' ), esc_html( $source ) ) );
		}

		$config = $plugins[ $source ];

		// Total posts (approximate — for progress display only).
		$total = (int) array_sum(
			array_map(
				static fn( string $pt ) => (int) wp_count_posts( $pt )->publish,
				$post_types
			)
		);

		// Fetch one batch ordered by ID for stable pagination.
		$query = new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$imported  = 0;
		$skipped   = 0;
		$unchanged = 0;
		$errors    = 0;

		foreach ( $query->posts as $post_id ) {
			try {
				switch ( static::import_post( (int) $post_id, $config, $overwrite, $dry_run ) ) {
					case 'imported':
						++$imported;
						break;
					case 'skipped':
						++$skipped;
						break;
					case 'unchanged':
						++$unchanged;
						break;
				}
			} catch ( \Throwable $e ) {
				++$errors;
			}
		}

		$count     = count( $query->posts );
		$processed = $offset + $count;

		wp_send_json_success(
			array(
				'imported'    => $imported,
				'skipped'     => $skipped,
				'unchanged'   => $unchanged,
				'errors'      => $errors,
				'processed'   => $processed,
				'total'       => $total,
				'next_offset' => $processed,
				'done'        => $count < $batch_size,
			)
		);
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
		int $post_id,
		array $config,
		bool $overwrite,
		bool $dry_run
	): string {
		$has_source_data = false;
		$writes          = array();

		// -- Standard fields -------------------------------------------------
		foreach ( $config['fields'] as $src_key => $dest_field ) {
			$raw = get_post_meta( $post_id, $src_key, true );

			// Skip genuinely empty values.
			if ( '' === $raw || false === $raw || null === $raw ) {
				continue;
			}

			$has_source_data = true;

			// Normalise focus_kw — may arrive as a comma-separated string
			// (RankMath, AIOSEO) or a single string (Yoast, SEOPress).
			if ( 'focus_kw' === $dest_field ) {
				if ( is_string( $raw ) ) {
					$raw = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
				}
				// Already an array from some sources; keep as-is.
			}

			// Normalise cornerstone — various sources use '1', 'yes', true.
			if ( 'cornerstone' === $dest_field ) {
				$raw = ( $raw && '0' !== $raw && 'no' !== $raw ) ? '1' : '0';
			}

			$dest_key = static::PREFIX . $dest_field;
			$existing = get_post_meta( $post_id, $dest_key, true );

			// Skip if already set and not overwriting.
			if ( ( '' !== $existing && false !== $existing ) && ! $overwrite ) {
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
			return 'unchanged'; // Source plugin left no data on this post.
		}

		if ( empty( $writes ) ) {
			return 'skipped'; // Source had data, but all dest fields were populated and overwrite=false.
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
		add_action( 'admin_menu', array( static::class, 'register_admin_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( static::class, 'enqueue_assets' ) );
		static::register_ajax();
	}
}
