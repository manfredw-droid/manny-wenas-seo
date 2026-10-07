<?php
/**
 * Admin settings page.
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tabbed settings screen driven by a declarative field map.
 */
class MWSEO_Settings {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MWSEO_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'update_option_' . MWSEO_Options::KEY, array( __CLASS__, 'after_save' ) );
	}

	/**
	 * Tabs.
	 *
	 * @return array
	 */
	private static function tabs() {
		return array(
			'general'  => __( 'General', 'manny-wenas-seo' ),
			'social'   => __( 'Social', 'manny-wenas-seo' ),
			'schema'   => __( 'Schema', 'manny-wenas-seo' ),
			'sitemaps' => __( 'Sitemaps', 'manny-wenas-seo' ),
			'robots'   => __( 'Robots & llms.txt', 'manny-wenas-seo' ),
			'gsc'      => __( 'Search Console', 'manny-wenas-seo' ),
			'verify'   => __( 'Verification & IndexNow', 'manny-wenas-seo' ),
			'pro'      => __( 'Pro', 'manny-wenas-seo' ),
		);
	}

	/**
	 * Field map: tab => fields.
	 *
	 * @return array
	 */
	private static function fields() {
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' !== $pt->name ) {
				$post_types[ $pt->name ] = $pt->labels->name;
			}
		}
		$users = array( 0 => __( '— none —', 'manny-wenas-seo' ) );
		foreach ( get_users(
			array(
				'capability' => 'edit_posts',
				'fields'     => array( 'ID', 'display_name' ),
			)
		) as $u ) {
			$users[ $u->ID ] = $u->display_name;
		}

		return array(
			'general'  => array(
				array( 'title_template', 'text', __( 'Default title template', 'manny-wenas-seo' ), __( 'Variables: %%title%%, %%sitename%%, %%tagline%%, %%sep%%.', 'manny-wenas-seo' ) ),
				array( 'title_separator', 'text', __( 'Title separator', 'manny-wenas-seo' ), '' ),
				array( 'post_types', 'multicheck', __( 'Post types with SEO controls', 'manny-wenas-seo' ), '', $post_types ),
				array(
					'yoast_mode',
					'select',
					__( 'When Yoast SEO is active', 'manny-wenas-seo' ),
					__( 'Stitch: Yoast renders the graph and we add our Pro nodes. Defer: we output nothing Yoast also outputs.', 'manny-wenas-seo' ),
					array(
						'stitch' => __( 'Stitch into Yoast’s schema graph', 'manny-wenas-seo' ),
						'defer'  => __( 'Defer to Yoast completely', 'manny-wenas-seo' ),
					),
				),
			),
			'social'   => array(
				array( 'default_image', 'number', __( 'Default social image (attachment ID)', 'manny-wenas-seo' ), __( 'Used when a post has no featured image. Find the ID in the Media Library.', 'manny-wenas-seo' ) ),
				array( 'twitter_site', 'text', __( 'X / Twitter handle', 'manny-wenas-seo' ), __( 'For example @yoursite.', 'manny-wenas-seo' ) ),
				array( 'fb_app_id', 'text', __( 'Facebook app ID (optional)', 'manny-wenas-seo' ), '' ),
			),
			'schema'   => array(
				array(
					'site_represents',
					'select',
					__( 'This site represents', 'manny-wenas-seo' ),
					__( 'Choose "both" for a personal site: the publisher becomes a hybrid [Person, Organization] node.', 'manny-wenas-seo' ),
					array(
						'organization' => __( 'An organization', 'manny-wenas-seo' ),
						'person'       => __( 'A person', 'manny-wenas-seo' ),
						'both'         => __( 'Both (personal brand)', 'manny-wenas-seo' ),
					),
				),
				array( 'org_name', 'text', __( 'Organization name', 'manny-wenas-seo' ), '' ),
				array( 'org_logo', 'number', __( 'Logo (attachment ID)', 'manny-wenas-seo' ), '' ),
				array( 'person_user', 'select', __( 'Person (for person / both)', 'manny-wenas-seo' ), '', $users ),
				array( 'social_profiles', 'textarea', __( 'Social profile URLs (one per line)', 'manny-wenas-seo' ), __( 'Output as sameAs.', 'manny-wenas-seo' ) ),
				array(
					'article_type',
					'select',
					__( 'Default article type for posts', 'manny-wenas-seo' ),
					'',
					array(
						'Article'     => 'Article',
						'NewsArticle' => 'NewsArticle',
						'BlogPosting' => 'BlogPosting',
					),
				),
			),
			'sitemaps' => array(
				array( 'sitemap_news', 'checkbox', __( 'News sitemap', 'manny-wenas-seo' ), '' ),
				array( 'news_publication', 'text', __( 'News publication name', 'manny-wenas-seo' ), '' ),
				array( 'sitemap_images', 'checkbox', __( 'Image sitemap', 'manny-wenas-seo' ), '' ),
				array( 'sitemap_videos', 'checkbox', __( 'Video sitemap', 'manny-wenas-seo' ), '' ),
				array( 'sitemap_categories', 'checkbox', __( 'Category sitemap', 'manny-wenas-seo' ), '' ),
				array( 'sitemap_tags', 'checkbox', __( 'Tag sitemap', 'manny-wenas-seo' ), '' ),
				array( 'sitemap_authors', 'checkbox', __( 'Author sitemap', 'manny-wenas-seo' ), '' ),
				array( 'sitemap_html', 'checkbox', __( 'HTML sitemap', 'manny-wenas-seo' ), __( 'Place it on any page with the [mwseo_html_sitemap] shortcode.', 'manny-wenas-seo' ) ),
			),
			'robots'   => array(
				array( 'robots_custom', 'textarea', __( 'Custom robots.txt rules', 'manny-wenas-seo' ), __( 'Appended to WordPress’s virtual robots.txt. A physical robots.txt file overrides this.', 'manny-wenas-seo' ) ),
				array( 'robots_add_sitemap', 'checkbox', __( 'Add sitemap URL to robots.txt', 'manny-wenas-seo' ), '' ),
				array( 'llms_enabled', 'checkbox', __( 'Serve /llms.txt', 'manny-wenas-seo' ), __( 'Regenerated weekly and whenever you save these settings. Cornerstone content is listed first.', 'manny-wenas-seo' ) ),
				array( 'llms_intro', 'textarea', __( 'llms.txt summary', 'manny-wenas-seo' ), __( 'Defaults to the site tagline.', 'manny-wenas-seo' ) ),
			),
			'gsc'      => array(
				array( 'gsc_client_id', 'text', __( 'Google OAuth client ID', 'manny-wenas-seo' ), '' ),
				array( 'gsc_client_secret', 'password', __( 'Google OAuth client secret', 'manny-wenas-seo' ), '' ),
				array( 'gsc_property', 'text', __( 'Search Console property', 'manny-wenas-seo' ), __( 'For example https://example.com/ or sc-domain:example.com. Defaults to the home URL.', 'manny-wenas-seo' ) ),
			),
			'verify'   => array(
				array( 'gsc_html_filename', 'text', __( 'Google Search Console HTML file', 'manny-wenas-seo' ), MWSEO_Verification::status( MWSEO_Options::get( 'gsc_html_filename' ) ), array(), 'googleXXXXXXXXXXXXXXXX.html' ),
			),
			'pro'      => array(
				array(
					'ai_provider',
					'select',
					__( 'AI provider (your own API key)', 'manny-wenas-seo' ),
					'',
					array(
						'anthropic' => 'Anthropic',
						'openai'    => 'OpenAI',
					),
				),
				array( 'ai_api_key', 'password', __( 'API key', 'manny-wenas-seo' ), __( 'Stored in the database; used only for requests you trigger.', 'manny-wenas-seo' ) ),
				array( 'ai_model', 'text', __( 'Model ID', 'manny-wenas-seo' ), '' ),
				array( 'report_enabled', 'checkbox', __( 'Weekly rank report email', 'manny-wenas-seo' ), '' ),
				array( 'report_email', 'text', __( 'Report recipient', 'manny-wenas-seo' ), __( 'Defaults to the admin email.', 'manny-wenas-seo' ) ),
				array( 'sitemap_autosubmit', 'checkbox', __( 'Auto-submit sitemap to Google and Bing', 'manny-wenas-seo' ), __( 'Google via the Search Console API; Bing via IndexNow.', 'manny-wenas-seo' ) ),
			),
		);
	}

	/**
	 * Menu registration.
	 */
	public static function menu() {
		add_menu_page( __( 'Manny Wenas SEO', 'manny-wenas-seo' ), __( 'Manny Wenas SEO', 'manny-wenas-seo' ), 'manage_options', 'mwseo', array( __CLASS__, 'render' ), 'dashicons-search', 80 );
	}

	/**
	 * Register the setting.
	 */
	public static function register() {
		register_setting(
			'mwseo_group',
			MWSEO_Options::KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => MWSEO_Options::defaults(),
			)
		);
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=mwseo' ) ) . '">' . esc_html__( 'Settings', 'manny-wenas-seo' ) . '</a>' );
		return $links;
	}

	/**
	 * Sanitize a submitted tab and merge it into the stored options.
	 *
	 * @param mixed $input Posted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = MWSEO_Options::all();
		$input   = is_array( $input ) ? $input : array();

		foreach ( self::fields() as $fields ) {
			foreach ( $fields as $f ) {
				list( $key, $type ) = $f;
				$choices            = isset( $f[4] ) ? $f[4] : array();
				if ( ! array_key_exists( $key, $input ) ) {
					continue;
				}
				$raw = $input[ $key ];
				switch ( $type ) {
					case 'checkbox':
						$current[ $key ] = empty( $raw ) ? 0 : 1;
						break;
					case 'number':
						$current[ $key ] = absint( $raw );
						break;
					case 'textarea':
						$current[ $key ] = sanitize_textarea_field( $raw );
						break;
					case 'select':
						$raw = is_scalar( $raw ) ? (string) $raw : '';
						if ( array_key_exists( $raw, $choices ) ) {
							$current[ $key ] = ( 'person_user' === $key ) ? (int) $raw : $raw;
						}
						break;
					case 'multicheck':
						$vals            = array_values( array_intersect( array_map( 'sanitize_key', (array) $raw ), array_keys( $choices ) ) );
						$current[ $key ] = $vals ? $vals : array( 'post' );
						break;
					case 'password':
						$val = sanitize_text_field( (string) $raw );
						if ( '' !== $val ) {
							$current[ $key ] = $val;
						}
						break;
					default:
						$current[ $key ] = sanitize_text_field( (string) $raw );
				}
			}
		}
		if ( array_key_exists( 'gsc_html_filename', $input ) ) {
			$current['gsc_html_filename'] = MWSEO_Verification::sanitize_gsc_filename( $input['gsc_html_filename'] );
		}
		// Non-UI keys set programmatically (kept when saving a tab).
		if ( isset( $input['indexnow_key'] ) ) {
			$current['indexnow_key'] = sanitize_text_field( $input['indexnow_key'] );
		}
		return $current;
	}

	/**
	 * After settings change: rebuild llms.txt and refresh rewrites.
	 */
	public static function after_save() {
		MWSEO_Options::flush();
		MWSEO_Llms_Txt::regenerate();
		flush_rewrite_rules( false );
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'general';
		$all  = self::fields();
		$opts = MWSEO_Options::all();
		?>
		<div class="wrap mwseo-settings">
			<h1><?php esc_html_e( 'Manny Wenas SEO', 'manny-wenas-seo' ); ?> <small>v<?php echo esc_html( MWSEO_VERSION ); ?></small></h1>
			<?php self::messages(); ?>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page' => 'mwseo',
								'tab'  => $slug,
							),
							admin_url( 'admin.php' )
						)
					);
					?>
										"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<form method="post" action="options.php">
				<?php settings_fields( 'mwseo_group' ); ?>
				<?php self::tab_intro( $tab ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( $all[ $tab ] as $f ) : ?>
						<?php self::field( $f, $opts ); ?>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Flash messages from the OAuth flow.
	 */
	private static function messages() {
		$msg = isset( $_GET['mwseo_msg'] ) ? sanitize_key( wp_unslash( $_GET['mwseo_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map = array(
			'connected'    => array( 'success', __( 'Connected to Google Search Console.', 'manny-wenas-seo' ) ),
			'disconnected' => array( 'info', __( 'Disconnected from Google Search Console.', 'manny-wenas-seo' ) ),
			'error'        => array( 'error', __( 'Google did not return a refresh token. Check your client ID, secret and redirect URI, then try again.', 'manny-wenas-seo' ) ),
			'no_client'    => array( 'error', __( 'Save your OAuth client ID and secret first.', 'manny-wenas-seo' ) ),
		);
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
		}
	}

	/**
	 * Extra per-tab content above the fields.
	 *
	 * @param string $tab Tab slug.
	 */
	private static function tab_intro( $tab ) {
		if ( 'sitemaps' === $tab ) {
			echo '<p>' . esc_html__( 'Sitemap index:', 'manny-wenas-seo' ) . ' <a href="' . esc_url( MWSEO_Sitemaps::index_url() ) . '" target="_blank" rel="noopener">' . esc_html( MWSEO_Sitemaps::index_url() ) . '</a></p>';
		}
		if ( 'robots' === $tab ) {
			echo '<p><a href="' . esc_url( home_url( '/robots.txt' ) ) . '" target="_blank" rel="noopener">/robots.txt</a> · <a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener">/llms.txt</a></p>';
		}
		if ( 'gsc' === $tab ) {
			echo '<p>' . esc_html__( 'Create an OAuth client (type: Web application) in Google Cloud Console, enable the Search Console API, and add this authorised redirect URI:', 'manny-wenas-seo' ) . '<br /><code>' . esc_html( MWSEO_Gsc::redirect_uri() ) . '</code></p>';
			if ( MWSEO_Gsc::is_connected() ) {
				$url = wp_nonce_url( admin_url( 'admin.php?page=mwseo&mwseo_gsc_disconnect=1' ), 'mwseo_gsc_disconnect' );
				echo '<p><strong>' . esc_html__( 'Status: connected.', 'manny-wenas-seo' ) . '</strong> <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Disconnect', 'manny-wenas-seo' ) . '</a></p>';
			} else {
				$url = wp_nonce_url( admin_url( 'admin.php?page=mwseo&mwseo_gsc_connect=1' ), 'mwseo_gsc_connect' );
				echo '<p><strong>' . esc_html__( 'Status: not connected.', 'manny-wenas-seo' ) . '</strong> <a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Connect Search Console', 'manny-wenas-seo' ) . '</a> <span class="description">' . esc_html__( 'Save your client ID and secret first.', 'manny-wenas-seo' ) . '</span></p>';
			}
		}
		if ( 'pro' === $tab && ! MWSEO_Pro::is_active() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Pro features are inactive. You can configure them here; they switch on when a Pro licence is active.', 'manny-wenas-seo' ) . '</p></div>';
		}
	}

	/**
	 * Render one field row.
	 *
	 * @param array $f    Field definition.
	 * @param array $opts Current options.
	 */
	private static function field( array $f, array $opts ) {
		list( $key, $type, $label, $desc ) = $f;
		$choices                           = isset( $f[4] ) ? $f[4] : array();
		$name                              = MWSEO_Options::KEY . '[' . $key . ']';
		$id                                = 'mwseo_opt_' . $key;
		$val                               = isset( $opts[ $key ] ) ? $opts[ $key ] : '';
		$placeholder                       = isset( $f[5] ) ? $f[5] : '';
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php
				switch ( $type ) {
					case 'checkbox':
						printf( '<input type="hidden" name="%1$s" value="0" /><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s />', esc_attr( $name ), esc_attr( $id ), checked( ! empty( $val ), true, false ) );
						break;
					case 'textarea':
						printf( '<textarea id="%1$s" name="%2$s" rows="5" class="large-text code">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $val ) );
						break;
					case 'select':
						printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
						foreach ( $choices as $v => $l ) {
							printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $v ), selected( (string) $val, (string) $v, false ), esc_html( $l ) );
						}
						echo '</select>';
						break;
					case 'multicheck':
						printf( '<input type="hidden" name="%s[]" value="" />', esc_attr( $name ) );
						foreach ( $choices as $v => $l ) {
							printf( '<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label><br />', esc_attr( $name ), esc_attr( $v ), checked( in_array( $v, (array) $val, true ), true, false ), esc_html( $l ) );
						}
						break;
					case 'number':
						printf( '<input type="number" min="0" id="%1$s" name="%2$s" value="%3$s" class="small-text" />', esc_attr( $id ), esc_attr( $name ), esc_attr( $val ) );
						break;
					case 'password':
						printf( '<input type="password" id="%1$s" name="%2$s" value="" class="regular-text" autocomplete="new-password" placeholder="%3$s" />', esc_attr( $id ), esc_attr( $name ), $val ? esc_attr__( '•••••••• (saved; leave blank to keep)', 'manny-wenas-seo' ) : '' );
						break;
					default:
						printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" placeholder="%4$s" />', esc_attr( $id ), esc_attr( $name ), esc_attr( $val ), esc_attr( $placeholder ) );
				}
				if ( $desc ) {
					echo '<p class="description">' . esc_html( $desc ) . '</p>';
				}
				?>
			</td>
		</tr>
		<?php
	}
}
