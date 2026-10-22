<?php
defined( 'ABSPATH' ) || exit;
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

/**
 * Imports SEO data from Yoast, Rank Math, AIOSEO and SEOPress.
 */
class MWSEO_Importer {

	/**
	 * Legacy prefix retained for the adapters outside finding #5 Stage A.
	 */
	const PREFIX = '_mw_seo_';

	/**
	 * Sources that can be imported in 1.0.6. The All in One SEO, SEOPress and The
	 * SEO Framework adapters below stay in the code but are switched off: their
	 * values were stored under keys the plugin does not read.
	 */
	const SUPPORTED_SOURCES = array( 'yoast', 'rankmath' );

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
	 * Legacy adapters use _mw_seo_{slug}; Yoast/Rank Math use import_native_post().
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
		$screens = array_filter( array( static::$page_hook, MWSEO_Setup::hook() ) );
		if ( ! $screens || ! in_array( $hook, $screens, true ) ) {
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
			'nativeSources' => array( 'yoast', 'rankmath' ),
			'outcomes' => array(
				'would_change' => __( 'Would change', 'manny-wenas-seo' ),
				'changed' => __( 'Changed', 'manny-wenas-seo' ),
				'matched' => __( 'Already matches', 'manny-wenas-seo' ),
				'conflict' => __( 'Preserved native value', 'manny-wenas-seo' ),
				'unsupported' => __( 'Unsupported or unresolved', 'manny-wenas-seo' ),
				'invalid' => __( 'Invalid value', 'manny-wenas-seo' ),
				'failed' => __( 'Write failed', 'manny-wenas-seo' ),
			),
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
				'anchor_post'                              => __( 'Cornerstone content', 'manny-wenas-seo' ),
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
				'click_run_import_to_apply_these_changes'  => __( 'Click "Run Import" to apply these changes.', 'manny-wenas-seo' ),
				'no_changes_would_be_made'                 => __( 'No changes would be made with the current selections.', 'manny-wenas-seo' ),
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
	const safe = value => $('<span>').text(String(value)).html();

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
				<td>${safe(FIELD_LABELS[dest] || dest)}</td>
			</tr>`;
		}
		// Add robots row — always present.
		rows += `<tr>
			<td><em style="font-size:12px">${cfg.i18n.plugin_specific_robots_keys}</em></td>
			<td style="text-align:center">→</td>
			<td>${cfg.nativeSources.includes(source) ? '_mwseo_noindex / _mwseo_nofollow (explicit only)' : FIELD_LABELS.robots}</td>
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
	});

	/* ---- Writing is possible only after a completed preview of exactly the current choices ---- */
	const choices = () => JSON.stringify([
		$('#mw-import-source').val(),
		$('input[name="post_types[]"]:checked').map((i, el) => el.value).get().sort(),
		$('input[name="overwrite"]').is(':checked')
	]);
	let previewed = null; // choices of the last completed preview
	$('#mw-seo-import-form').on('change', function(e){
		if (e && e.target && e.target.name === 'dry_run') return;
		previewed = null;
		$('#mw-import-run-btn').prop('disabled', true);
	});

	/* ---- Trigger handlers ----------------------------------------- */
	$('#mw-import-preview-btn').on('click', function(){
		runImport(true);
	});

	$('#mw-seo-import-form').on('submit', function(e){
		e.preventDefault();
		const dry = $('input[name="dry_run"]').is(':checked');
		if (!dry && previewed !== choices()) return; // never write without a preview of these choices
		runImport(dry);
	});

	/* ---- Core import runner --------------------------------------- */
	function runImport(dryRun){
		const source    = $('#mw-import-source').val();
		const postTypes = $('input[name="post_types[]"]:checked').map((i, el) => el.value).get();
		const overwrite = $('input[name="overwrite"]').is(':checked');
		const nonce     = $('#_mw_nonce').val();
		const signature = choices();

		if (!source)          { alert(cfg.i18n.please_select_a_source_plugin); return; }
		if (!postTypes.length){ alert(cfg.i18n.please_select_at_least_one_post_type); return; }

		previewed = null;
		$('#mw-import-progress').show();
		$('#mw-import-results').hide();
		$('#mw-import-run-btn, #mw-import-preview-btn').prop('disabled', true);
		$('#mw-import-progress-bar').css('width', '0');
		$('#mw-import-progress-text').text('');

		const totals = { imported:0, skipped:0, unchanged:0, errors:0 };
		const fieldTotals = Object.fromEntries(Object.keys(cfg.outcomes).map(key => [key, 0]));
		const details = [];
		let omitted = 0;
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
					$('#mw-import-preview-btn').prop('disabled', false);
					return;
				}

				const d = res.data;
				totals.imported  += d.imported;
				totals.skipped   += d.skipped;
				totals.unchanged += d.unchanged;
				totals.errors    += d.errors;
				for (const key of Object.keys(fieldTotals)) fieldTotals[key] += (d.field_outcomes || {})[key] || 0;
				for (const item of d.details || []) {
					if (details.length < 100) details.push(item); else omitted++;
				}
				omitted += d.details_omitted || 0;

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
					if (cfg.nativeSources.includes(source)) html = `<p><strong>${safe(label)}</strong></p><p>${d.processed} posts processed; ${totals.errors} posts encountered errors.</p>`;
					if (dryRun) {
						// A preview that finds nothing to write must not tell the writer to apply anything. Native
						// sources report planned writes as would_change; other sources count the posts they would import.
						const wouldChange = cfg.nativeSources.includes(source) ? fieldTotals.would_change : totals.imported;
						if (!wouldChange) {
							html += `<p>${cfg.i18n.no_changes_would_be_made}</p>`;
						} else {
							// The setup screen has no Dry run option, so it must not tell the writer to untick one.
							const setupForm = $('#mw-seo-importer').hasClass('mwseo-import--setup');
							html += `<p>${setupForm ? cfg.i18n.click_run_import_to_apply_these_changes : cfg.i18n.uncheck_dry_run_and_click_run_import_to_}</p>`;
						}
					}
					if (cfg.nativeSources.includes(source)) {
						html += '<ul>' + Object.entries(fieldTotals).map(([key, count]) => `<li>${safe(cfg.outcomes[key])}: ${count}</li>`).join('') + '</ul>';
						html += '<ul>' + details.map(item => `<li>${safe(item.post_id)} / ${safe(item.field)}: ${safe(item.status)} — ${safe(item.reason)}</li>`).join('') + '</ul>';
						if (omitted) html += `<p>${omitted} additional field details omitted; narrow the selected post types to review them. Totals include all outcomes.</p>`;
					}
					$('#mw-import-results-content').html(html);
					$('#mw-import-results').show();
					// The form may have been changed while this ran: only an unchanged form counts as previewed.
					if (dryRun && choices() === signature) previewed = signature;
					$('#mw-import-preview-btn').prop('disabled', false);
					$('#mw-import-run-btn').prop('disabled', previewed === null || previewed !== choices());
				} else {
					offset = d.next_offset;
					batch();
				}
			}).fail(function(){
				$('#mw-import-progress-text').text('Request failed. Check your network connection.');
				$('#mw-import-preview-btn').prop('disabled', false);
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
		MWSEO_Admin::register_screen( static::$page_hook );
	}

	/**
	 * Render the import admin page.
	 */
	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		MWSEO_Admin::shell_open( 'import', __( 'Import SEO Data', 'manny-wenas-seo' ), __( 'Preview and import supported post SEO data from Yoast SEO or Rank Math.', 'manny-wenas-seo' ) );
		?>
		<div class="mwseo-card">
			<p class="description">
				<?php
				esc_html_e(
					'Yoast and Rank Math imports support titles with compatible tokens, literal descriptions, primary focus phrases, explicit noindex/nofollow flags and cornerstone flags. Other fields are reported as unsupported. Source metadata must still exist; the source plugin may be inactive. Existing native records are preserved unless you check "Overwrite". Historical imports are not repaired. Import from All in One SEO, SEOPress and The SEO Framework is switched off.',
					'manny-wenas-seo'
				);
				?>
			</p>
			<?php static::render_import_form( 'import' ); ?>
		</div>
		<?php
		MWSEO_Admin::shell_close();
	}

	/**
	 * The import form with progress and results, shared by the Import page and the setup screen.
	 *
	 * Yoast and Rank Math are always offered (their metadata can outlive the plugin); other
	 * source plugins are not offered even when they are active. Writing is enabled by the script
	 * only after a preview of the current choices. On the setup screen the dry-run option is not
	 * shown: the Preview button previews and the Run button writes.
	 *
	 * @param string $context 'import' or 'setup'.
	 */
	public static function render_import_form( string $context = 'import' ): void {
		$sources = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math SEO',
		);
		?>
		<div id="mw-seo-importer" class="mwseo-import mwseo-import--<?php echo esc_attr( $context ); ?>">
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
								<?php foreach ( $sources as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'manny-wenas-seo' ); ?></th>
						<td>
							<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : ?>
								<label class="mwseo-inline-check">
									<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, array( 'post', 'page' ), true ) ); ?>>
									<?php echo esc_html( $pt->label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'manny-wenas-seo' ); ?></th>
						<td>
							<label class="mwseo-inline-check mwseo-block-check">
								<input type="checkbox" name="overwrite" value="1">
								<?php esc_html_e( 'Overwrite existing Manny Wenas SEO data', 'manny-wenas-seo' ); ?>
							</label>
							<?php if ( 'setup' === $context ) : ?>
								<input type="hidden" name="dry_run" value="0">
							<?php else : ?>
								<label class="mwseo-inline-check mwseo-block-check">
									<input type="checkbox" name="dry_run" value="1" checked>
									<?php esc_html_e( 'Dry run — preview only, no data written', 'manny-wenas-seo' ); ?>
								</label>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<!-- Field-map preview (populated via JS on source change) -->
				<div id="mw-import-field-map" class="mwseo-import__map" style="display:none;">
					<h3><?php esc_html_e( 'Field map', 'manny-wenas-seo' ); ?></h3>
					<div id="mw-import-field-map-content"></div>
				</div>

				<p class="submit">
					<button type="button" id="mw-import-preview-btn" class="button button-primary">
						<?php esc_html_e( 'Preview import', 'manny-wenas-seo' ); ?>
					</button>
					<button type="submit" id="mw-import-run-btn" class="button" disabled aria-describedby="mw-import-run-hint">
						<?php esc_html_e( 'Run Import', 'manny-wenas-seo' ); ?>
					</button>
					<span id="mw-import-run-hint" class="description"><?php esc_html_e( 'Available after a preview.', 'manny-wenas-seo' ); ?></span>
				</p>
			</form>

			<div id="mw-import-progress" class="mwseo-import__progress" style="display:none;">
				<h3><?php esc_html_e( 'Progress', 'manny-wenas-seo' ); ?></h3>
				<div class="mwseo-import__bar"><div id="mw-import-progress-bar" class="mwseo-import__bar-fill"></div></div>
				<p id="mw-import-progress-text" role="status"></p>
			</div>

			<div id="mw-import-results" class="mwseo-import__results" style="display:none;">
				<h3><?php esc_html_e( 'Results', 'manny-wenas-seo' ); ?></h3>
				<div id="mw-import-results-content"></div>
			</div>
		</div>
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
			if ( ! in_array( $slug, static::SUPPORTED_SOURCES, true ) ) {
				continue;
			}
			$out[ $slug ] = $config['fields'];
			if ( in_array( $slug, array( 'yoast', 'rankmath' ), true ) ) {
				foreach ( $out[ $slug ] as $key => $field ) {
					$native = array( 'title' => 'title', 'description' => 'desc', 'focus_kw' => 'focus', 'cornerstone' => 'cornerstone' );
					$out[ $slug ][ $key ] = isset( $native[ $field ] ) ? '_mwseo_' . $native[ $field ] : __( 'Unsupported — retained at source', 'manny-wenas-seo' );
				}
				unset( $out[ $slug ]['rank_math_og_content_image'], $out[ $slug ]['rank_math_twitter_image_url'] );
				if ( 'rankmath' === $slug ) {
					$out[ $slug ]['rank_math_facebook_image'] = __( 'Unsupported — retained at source', 'manny-wenas-seo' );
					$out[ $slug ]['rank_math_twitter_image'] = __( 'Unsupported — retained at source', 'manny-wenas-seo' );
				}
			}
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

		// Refuse any source that is not offered, before reading or writing anything.
		$plugins = static::source_plugins();
		if ( ! isset( $plugins[ $source ] ) ) {
			/* translators: %s: source plugin identifier */
			wp_send_json_error( sprintf( __( 'Unknown source plugin: %s', 'manny-wenas-seo' ), esc_html( $source ) ) );
		}
		if ( ! in_array( $source, static::SUPPORTED_SOURCES, true ) ) {
			wp_send_json_error( __( 'Import currently supports Yoast SEO and Rank Math. Support for the other sources is switched off.', 'manny-wenas-seo' ) );
		}

		$config = $plugins[ $source ];
		if ( in_array( $source, array( 'yoast', 'rankmath' ), true ) ) {
			$batch_size = max( 1, $batch_size );
		}

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
		$native = in_array( $source, array( 'yoast', 'rankmath' ), true );
		$field_outcomes = array_fill_keys( array( 'would_change', 'changed', 'matched', 'conflict', 'unsupported', 'invalid', 'failed' ), 0 );
		$details = array();
		$details_omitted = 0;

		foreach ( $query->posts as $post_id ) {
			try {
				if ( $native ) {
					$result = static::import_native_post( (int) $post_id, $source, $overwrite, $dry_run );
					foreach ( $result as $item ) {
						++$field_outcomes[ $item['status'] ];
						if ( count( $details ) < 100 ) {
							$details[] = array_merge( array( 'post_id' => (int) $post_id ), $item );
						} else {
							++$details_omitted;
						}
					}
					$statuses = array_column( $result, 'status' );
					if ( in_array( 'failed', $statuses, true ) ) {
						++$errors;
					}
					if ( in_array( 'changed', $statuses, true ) ) {
						++$imported;
					} elseif ( empty( $result ) || count( array_unique( $statuses ) ) === 1 && 'matched' === $statuses[0] ) {
						++$unchanged;
					} else {
						++$skipped;
					}
					continue;
				}
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
				if ( $native ) {
					++$field_outcomes['failed'];
					if ( count( $details ) < 100 ) {
						$details[] = array( 'post_id' => (int) $post_id, 'field' => 'post', 'status' => 'failed', 'reason' => 'Source read/planning failed; review this post before retrying.' );
					} else {
						++$details_omitted;
					}
				}
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
				'field_outcomes' => $field_outcomes,
				'details' => $details,
				'details_omitted' => $details_omitted,
			)
		);
	}

	/**
	 * Import only the existing native contract; never read historical importer keys.
	 * Each outcome contains identifiers/reasons, not potentially private source values.
	 *
	 * @return array<int, array<string, string>> Field outcomes.
	 */
	private static function import_native_post( int $post_id, string $source, bool $overwrite, bool $dry_run ): array {
		$config = static::source_plugins()[ $source ];
		$fields = $config['fields'];
		if ( 'rankmath' === $source ) {
			// The old OG key is a content-image cache, not the configured social image.
			$fields['rank_math_facebook_image'] = 'og_image';
			$fields['rank_math_twitter_image'] = 'twitter_image';
		}
		$native = array( 'title' => 'title', 'description' => 'desc', 'focus_kw' => 'focus', 'cornerstone' => 'cornerstone' );
		$candidates = array();
		$out = array();
		$report = static function ( $field, $status, $reason ) use ( &$out ) {
			$out[] = array( 'field' => $field, 'status' => $status, 'reason' => $reason );
		};
		foreach ( $fields as $key => $field ) {
			if ( ! metadata_exists( 'post', $post_id, $key ) ) {
				continue;
			}
			$raw = get_post_meta( $post_id, $key, true );
			if ( '' === $raw || null === $raw ) {
				continue;
			}
			if ( ! isset( $native[ $field ] ) ) {
				$report( $key, 'unsupported', 'No native destination; source data retained.' );
				continue;
			}
			if ( 'cornerstone' === $field ) {
				$true = 'yoast' === $source ? array( true, 1, '1', 'true' ) : array( 'on' );
				$false = 'yoast' === $source ? array( false, 0, '0', 'false' ) : array( 'off' );
				if ( in_array( $raw, $true, true ) ) {
					$value = '1';
				} elseif ( in_array( $raw, $false, true ) ) {
					$value = '0';
				} else {
					$report( $key, 'invalid', 'Unrecognized cornerstone representation; no guess made.' );
					continue;
				}
			} else {
				if ( ! is_string( $raw ) ) {
					$report( $key, 'invalid', 'Expected a string.' );
					continue;
				}
				// Detect either source syntax, including parameterized/custom tokens.
				$pattern = '/%{1,2}[a-zA-Z_][^%\r\n]*%{1,2}/';
				if ( 'description' === $field && preg_match( $pattern, $raw ) ) {
					$report( $key, 'unsupported', 'Description template skipped; no content expansion is performed.' );
					continue;
				}
				if ( 'title' === $field ) {
					// Site-specific separators and Yoast's custom site name are not equivalent.
					$tokens = 'yoast' === $source
						? array( '%%title%%' => '%%title%%', '%%sitedesc%%' => '%%tagline%%' )
						: array( '%title%' => '%%title%%', '%sitename%' => '%%sitename%%', '%sitedesc%' => '%%tagline%%' );
					preg_match_all( $pattern, $raw, $matches );
					if ( array_diff( $matches[0], array_keys( $tokens ) ) ) {
						$report( $key, 'unsupported', 'Title token lacks a verified native equivalent; separator/custom-site-name conversion needs separate approval.' );
						continue;
					}
					$raw = strtr( $raw, $tokens );
				}
				if ( 'focus_kw' === $field && 'rankmath' === $source ) {
					$phrases = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), static fn( $phrase ) => '' !== $phrase ) );
					$raw = $phrases[0] ?? '';
					if ( count( $phrases ) > 1 ) {
						$report( $key, 'unsupported', 'Additional keyphrases retained at source; related keyphrases remain untouched.' );
					}
				}
				$value = 'description' === $field ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
				if ( '' === $value ) {
					$report( $key, 'invalid', 'Empty after normalization; native data not erased.' );
					continue;
				}
			}
			$candidates[ '_mwseo_' . $native[ $field ] ] = $value;
		}

		if ( 'yoast' === $source ) {
			foreach ( array( 'noindex', 'nofollow' ) as $flag ) {
				$key = '_yoast_wpseo_meta-robots-' . $flag;
				if ( ! metadata_exists( 'post', $post_id, $key ) ) {
					continue;
				}
				$raw = get_post_meta( $post_id, $key, true );
				if ( '1' === $raw ) {
					$candidates[ '_mwseo_' . $flag ] = '1';
				} elseif ( ( 'noindex' === $flag && '2' === $raw ) || ( 'nofollow' === $flag && '0' === $raw ) ) {
					$candidates[ '_mwseo_' . $flag ] = '0';
				} else {
					$report( $key, 'unsupported', 'Inherited, empty or unrecognized robots setting; native flag preserved.' );
				}
			}
			$key = '_yoast_wpseo_meta-robots-adv';
			$raw = get_post_meta( $post_id, $key, true );
			if ( metadata_exists( 'post', $post_id, $key ) && '' !== $raw ) {
				if ( ! is_string( $raw ) || array_diff( array_map( 'trim', explode( ',', $raw ) ), array( 'noarchive', 'noimageindex', 'nosnippet' ) ) ) {
					unset( $candidates['_mwseo_noindex'], $candidates['_mwseo_nofollow'] );
					$report( $key, 'unsupported', 'Unrecognized advanced directive may conflict with basic robots; both native flags preserved.' );
				} else {
					$report( $key, 'unsupported', 'Advanced robots directives have no native destination.' );
				}
			}
		} elseif ( metadata_exists( 'post', $post_id, 'rank_math_robots' ) ) {
			$raw = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( ! is_array( $raw ) || count( array_filter( $raw, 'is_string' ) ) !== count( $raw ) ) {
				$report( 'rank_math_robots', 'invalid', 'Expected an array of directive strings; all flags preserved.' );
			} elseif ( array_diff( $raw, array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' ) ) ) {
				$report( 'rank_math_robots', 'unsupported', 'Unrecognized directive may affect indexing/following; all flags preserved.' );
			} else {
				foreach ( array( 'noindex' => 'index', 'nofollow' => 'follow' ) as $negative => $positive ) {
					$deny = in_array( $negative, $raw, true );
					$allow = in_array( $positive, $raw, true );
					if ( $deny === $allow ) {
						$report( 'rank_math_robots:' . $negative, 'unsupported', 'Conflicting or absent explicit directive; native flag preserved.' );
					} else {
						$candidates[ '_mwseo_' . $negative ] = $deny ? '1' : '0';
					}
				}
				if ( array_diff( $raw, array( 'index', 'noindex', 'follow', 'nofollow' ) ) ) {
					$report( 'rank_math_robots', 'unsupported', 'Additional directives have no supported native conversion.' );
				}
			}
		}

		foreach ( $candidates as $key => $value ) {
			try {
				$exists = metadata_exists( 'post', $post_id, $key );
				$existing = get_post_meta( $post_id, $key, true );
				if ( $exists && $existing === $value ) {
					$report( $key, 'matched', 'Native value already matches.' );
				} elseif ( $exists && ! $overwrite ) {
					$report( $key, 'conflict', 'Existing native record preserved.' );
				} elseif ( $dry_run ) {
					$report( $key, 'would_change', 'Preview only; no write attempted.' );
				} else {
					// Unique add avoids deliberately overwriting a record created after the check.
					$saved = $exists ? update_post_meta( $post_id, $key, wp_slash( $value ) ) : add_post_meta( $post_id, $key, wp_slash( $value ), true );
					$matches = metadata_exists( 'post', $post_id, $key ) && get_post_meta( $post_id, $key, true ) === $value;
					$report( $key, $matches ? ( false === $saved ? 'matched' : 'changed' ) : 'failed', $matches ? 'Stored value verified.' : 'Requested value was not stored; no rollback or cleanup attempted.' );
				}
			} catch ( \Throwable $e ) {
				$report( $key, 'failed', 'Write/check raised an exception; verify this native field before retrying.' );
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Legacy per-post logic: adapters outside Stage A retain their prior behavior.
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
