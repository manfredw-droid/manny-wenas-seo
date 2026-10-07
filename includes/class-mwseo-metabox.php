<?php
/**
 * Post/page metabox with live score.
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the SEO metabox.
 */
class MWSEO_Metabox {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_posts_columns', array( __CLASS__, 'column' ) );
		add_filter( 'manage_pages_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
	}

	/**
	 * Register the box on all enabled post types.
	 */
	public static function register() {
		foreach ( MWSEO_Options::get( 'post_types' ) as $post_type ) {
			add_meta_box( 'mwseo', __( 'Manny Wenas SEO', 'manny-wenas-seo' ), array( __CLASS__, 'render' ), $post_type, 'normal', 'high' );
		}
	}

	/**
	 * Enqueue scripts on edit screens.
	 *
	 * @param string $hook Admin hook suffix.
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'mwseo-admin', MWSEO_URL . 'assets/css/admin.css', array(), MWSEO_VERSION );
		wp_enqueue_script( 'mwseo-metabox', MWSEO_URL . 'assets/js/metabox.js', array( 'wp-api-fetch', 'wp-data', 'jquery' ), MWSEO_VERSION, true );
		wp_localize_script(
			'mwseo-metabox',
			'mwseoBox',
			array(
				'postId' => get_the_ID(),
				'isPro'  => MWSEO_Pro::is_active(),
				'hasGsc' => MWSEO_Gsc::is_connected(),
				'i18n'   => array(
					'seo'         => __( 'SEO', 'manny-wenas-seo' ),
					'readability' => __( 'Readability', 'manny-wenas-seo' ),
					'analysing'   => __( 'Analysing…', 'manny-wenas-seo' ),
					'noData'      => __( 'No Search Console data for this URL yet.', 'manny-wenas-seo' ),
					'chars'       => __( 'characters', 'manny-wenas-seo' ),
					'use'         => __( 'Use', 'manny-wenas-seo' ),
					'error'       => __( 'Something went wrong.', 'manny-wenas-seo' ),
				),
			)
		);
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render( $post ) {
		wp_nonce_field( 'mwseo_save', 'mwseo_nonce' );
		$id      = $post->ID;
		$related = MWSEO_Meta::get( $id, 'related' );
		$is_pro  = MWSEO_Pro::is_active();
		$review  = MWSEO_Meta::get( $id, 'review' );
		$review  = array_pad( $review, 3, '' );
		?>
		<div class="mwseo-box">
			<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
				<img src="<?php echo esc_url( plugins_url( 'assets/images/mwseo-logo.svg', MWSEO_FILE ) ); ?>" alt="Manny Wenas SEO" height="28" width="28" style="display:block;">
				<strong><?php esc_html_e( 'Manny Wenas SEO', 'manny-wenas-seo' ); ?></strong>
			</div>
			<div class="mwseo-score" id="mwseo-score" aria-live="polite">
				<div class="mwseo-ring" id="mwseo-ring" data-state="none"><span id="mwseo-ring-num">–</span></div>
				<div class="mwseo-score-text">
					<strong id="mwseo-verdict"><?php esc_html_e( 'Analysing…', 'manny-wenas-seo' ); ?></strong>
					<span id="mwseo-subscores"></span>
				</div>
			</div>

			<p>
				<label for="mwseo_slug"><strong><?php esc_html_e( 'URL slug', 'manny-wenas-seo' ); ?></strong></label>
				<input type="text" id="mwseo_slug" name="mwseo_slug" class="widefat" value="<?php echo esc_attr( $post->post_name ); ?>" />
				<span class="description"><?php esc_html_e( 'The URL-friendly part of this post\'s address. Put the focus keyphrase here.', 'manny-wenas-seo' ); ?></span>
			</p>

			<p>
				<label for="mwseo_focus"><strong><?php esc_html_e( 'Focus keyphrase', 'manny-wenas-seo' ); ?></strong></label>
				<input type="text" id="mwseo_focus" name="mwseo_focus" class="widefat" value="<?php echo esc_attr( MWSEO_Meta::get( $id, 'focus' ) ); ?>" />
				<span class="description"><?php esc_html_e( 'Matching is semantic: word order and common variants (plurals, verb forms) count.', 'manny-wenas-seo' ); ?></span>
			</p>

			<div class="mwseo-related">
				<?php for ( $i = 0; $i < 2; $i++ ) : ?>
					<p>
						<label for="mwseo_related_<?php echo (int) $i; ?>"><?php /* translators: %d: number */ echo esc_html( sprintf( __( 'Related keyphrase %d (optional)', 'manny-wenas-seo' ), $i + 1 ) ); ?></label>
						<input type="text" id="mwseo_related_<?php echo (int) $i; ?>" name="mwseo_related[]" class="widefat mwseo-related-input" value="<?php echo esc_attr( isset( $related[ $i ] ) ? $related[ $i ] : '' ); ?>" />
					</p>
				<?php endfor; ?>
				<p>
					<button type="button" class="button" id="mwseo-suggest" <?php disabled( ! $is_pro ); ?>><?php esc_html_e( 'Suggest from Search Console', 'manny-wenas-seo' ); ?></button>
					<?php if ( ! $is_pro ) : ?>
						<span class="description"><?php esc_html_e( 'Pro feature.', 'manny-wenas-seo' ); ?></span>
					<?php endif; ?>
				</p>
				<ul id="mwseo-suggestions"></ul>
			</div>

			<?php if ( MWSEO_Compat::owns_title_fields() ) : ?>
				<?php // Another SEO plugin owns these fields. Unnamed hidden inputs keep the live score working but are never submitted, so saving cannot overwrite stored values. ?>
				<p class="mwseo-yoast-notice notice notice-info inline"><?php /* translators: %s: SEO plugin name */ echo esc_html( sprintf( __( '%s is active — title and description are managed there.', 'manny-wenas-seo' ), MWSEO_Compat::owner_name() ) ); ?></p>
				<input type="hidden" id="mwseo_title" value="<?php echo esc_attr( MWSEO_Meta::get( $id, 'title' ) ); ?>" />
				<input type="hidden" id="mwseo_desc" value="<?php echo esc_attr( MWSEO_Meta::get( $id, 'desc' ) ); ?>" />
			<?php else : ?>
				<p>
					<label for="mwseo_title"><strong><?php esc_html_e( 'SEO title', 'manny-wenas-seo' ); ?></strong></label>
					<input type="text" id="mwseo_title" name="mwseo_title" class="widefat" value="<?php echo esc_attr( MWSEO_Meta::get( $id, 'title' ) ); ?>" />
					<span class="description mwseo-counter" data-for="mwseo_title" data-min="50" data-max="60"></span>
				</p>
				<p>
					<label for="mwseo_desc"><strong><?php esc_html_e( 'Meta description', 'manny-wenas-seo' ); ?></strong></label>
					<textarea id="mwseo_desc" name="mwseo_desc" class="widefat" rows="3"><?php echo esc_textarea( MWSEO_Meta::get( $id, 'desc' ) ); ?></textarea>
					<span class="description mwseo-counter" data-for="mwseo_desc" data-min="120" data-max="155"></span>
				</p>
			<?php endif; ?>

			<fieldset class="mwseo-robots">
				<legend><strong><?php esc_html_e( 'Search engine visibility', 'manny-wenas-seo' ); ?></strong></legend>
				<label><input type="checkbox" name="mwseo_noindex" value="1" <?php checked( MWSEO_Meta::get( $id, 'noindex' ) ); ?> /> <?php esc_html_e( 'noindex: keep this out of search results', 'manny-wenas-seo' ); ?></label><br />
				<label><input type="checkbox" name="mwseo_nofollow" value="1" <?php checked( MWSEO_Meta::get( $id, 'nofollow' ) ); ?> /> <?php esc_html_e( 'nofollow: do not follow links on this page', 'manny-wenas-seo' ); ?></label><br />
				<label><input type="checkbox" name="mwseo_cornerstone" value="1" <?php checked( MWSEO_Meta::get( $id, 'cornerstone' ) ); ?> /> <?php esc_html_e( 'Anchor post (prioritised in llms.txt)', 'manny-wenas-seo' ); ?></label>
			</fieldset>

			<h4><?php esc_html_e( 'Analysis', 'manny-wenas-seo' ); ?></h4>
			<ul id="mwseo-checks" class="mwseo-checks"></ul>
			<ul id="mwseo-tips" class="mwseo-tips"></ul>

			<div id="mwseo-gsc" class="mwseo-gsc">
				<h4><?php esc_html_e( 'Search Console queries (last 28 days)', 'manny-wenas-seo' ); ?></h4>
				<div id="mwseo-gsc-body">
					<?php if ( ! MWSEO_Gsc::is_connected() ) : ?>
						<em><?php esc_html_e( 'Connect Google Search Console in the plugin settings.', 'manny-wenas-seo' ); ?></em>
					<?php endif; ?>
				</div>
			</div>

			<details class="mwseo-pro" <?php echo $is_pro ? '' : 'aria-disabled="true"'; ?>>
				<summary><?php esc_html_e( 'Advanced schema (Pro)', 'manny-wenas-seo' ); ?></summary>
				<?php if ( ! $is_pro ) : ?>
					<p class="description"><?php esc_html_e( 'FAQPage, VideoObject and Review schema are part of Pro.', 'manny-wenas-seo' ); ?></p>
				<?php endif; ?>
				<fieldset <?php disabled( ! $is_pro ); ?>>
					<p>
						<label for="mwseo_faq"><strong><?php esc_html_e( 'FAQ (one per line: Question | Answer)', 'manny-wenas-seo' ); ?></strong></label>
						<textarea id="mwseo_faq" name="mwseo_faq" class="widefat" rows="4"><?php echo esc_textarea( MWSEO_Meta::get( $id, 'faq' ) ); ?></textarea>
					</p>
					<p>
						<label for="mwseo_video"><strong><?php esc_html_e( 'Video URL (YouTube, Vimeo or file)', 'manny-wenas-seo' ); ?></strong></label>
						<input type="url" id="mwseo_video" name="mwseo_video" class="widefat" value="<?php echo esc_attr( MWSEO_Meta::get( $id, 'video' ) ); ?>" />
					</p>
					<p>
						<strong><?php esc_html_e( 'Review', 'manny-wenas-seo' ); ?></strong><br />
						<input type="text" name="mwseo_review[]" placeholder="<?php esc_attr_e( 'Item reviewed', 'manny-wenas-seo' ); ?>" value="<?php echo esc_attr( $review[0] ); ?>" />
						<input type="number" min="1" max="5" step="0.5" name="mwseo_review[]" placeholder="<?php esc_attr_e( 'Rating 1–5', 'manny-wenas-seo' ); ?>" value="<?php echo esc_attr( $review[1] ); ?>" />
						<input type="text" name="mwseo_review[]" class="widefat" placeholder="<?php esc_attr_e( 'Review summary', 'manny-wenas-seo' ); ?>" value="<?php echo esc_attr( $review[2] ); ?>" />
					</p>
				</fieldset>
			</details>
		</div>
		<?php
	}

	/**
	 * Save handler.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['mwseo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mwseo_nonce'] ) ), 'mwseo_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Slug — update post_name directly; unhook to avoid infinite recursion.
		if ( isset( $_POST['mwseo_slug'] ) ) {
			$new_slug = sanitize_title( wp_unslash( $_POST['mwseo_slug'] ) );
			if ( $new_slug && $new_slug !== $post->post_name ) {
				remove_action( 'save_post', array( __CLASS__, 'save' ), 10 );
				wp_update_post( array( 'ID' => $post_id, 'post_name' => $new_slug ) );
				add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
			}
		}

		$related = isset( $_POST['mwseo_related'] ) ? array_slice( array_filter( array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['mwseo_related'] ) ) ), 0, 2 ) : array();
		MWSEO_Meta::set( $post_id, 'focus', isset( $_POST['mwseo_focus'] ) ? sanitize_text_field( wp_unslash( $_POST['mwseo_focus'] ) ) : '' );
		MWSEO_Meta::set( $post_id, 'related', $related );
		// When another SEO plugin owns the fields they are not rendered; leave stored values untouched.
		if ( ! MWSEO_Compat::owns_title_fields() ) {
			MWSEO_Meta::set( $post_id, 'title', isset( $_POST['mwseo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['mwseo_title'] ) ) : '' );
			MWSEO_Meta::set( $post_id, 'desc', isset( $_POST['mwseo_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mwseo_desc'] ) ) : '' );
		}
		foreach ( array( 'noindex', 'nofollow', 'cornerstone' ) as $flag ) {
			MWSEO_Meta::set( $post_id, $flag, ! empty( $_POST[ 'mwseo_' . $flag ] ) );
		}

		if ( MWSEO_Pro::is_active() ) {
			MWSEO_Meta::set( $post_id, 'faq', isset( $_POST['mwseo_faq'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mwseo_faq'] ) ) : '' );
			MWSEO_Meta::set( $post_id, 'video', isset( $_POST['mwseo_video'] ) ? esc_url_raw( wp_unslash( $_POST['mwseo_video'] ) ) : '' );
			$review = isset( $_POST['mwseo_review'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['mwseo_review'] ) ) : array();
			MWSEO_Meta::set( $post_id, 'review', $review && '' !== $review[0] ? $review : array() );
		}

		$result = MWSEO_Rest::analyze_post( $post );
		MWSEO_Meta::set( $post_id, 'score', (int) $result['score'] );
	}

	/**
	 * Add a score column.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function column( $cols ) {
		$cols['mwseo_score'] = __( 'SEO', 'manny-wenas-seo' );
		return $cols;
	}

	/**
	 * Render the score column.
	 *
	 * @param string $col     Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function column_value( $col, $post_id ) {
		if ( 'mwseo_score' !== $col ) {
			return;
		}
		$score = MWSEO_Meta::get( $post_id, 'score' );
		echo $score ? esc_html( $score . '/100' ) : '&ndash;';
	}
}
