<?php
/**
 * 1.0.8 interface checks on a disposable `equinox_` WordPress database.
 * Usage: php tests/interface-wordpress.php /path/to/wp-load.php
 *
 * Covers: setup redirect rules, setup/settings/import screens, settings preservation, the media field,
 * asset scoping and the editor strings. Browser behaviour is checked separately.
 */
define( 'WP_ADMIN', true );
require $argv[1];
if ( 0 !== strpos( $wpdb->prefix, 'equinox_' ) ) {
	throw new RuntimeException( 'Disposable equinox_ prefix required' );
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );

$checks   = 0;
$failures = 0;
function check_ui( $ok, $label ) {
	global $checks, $failures;
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
function page_html( callable $fn ) {
	ob_start();
	$fn();
	return ob_get_clean();
}
function has( $haystack, $needle ) {
	return false !== strpos( $haystack, $needle );
}
function private_call( $class, $method, array $args = array() ) {
	$m = new ReflectionMethod( $class, $method );
	$m->setAccessible( true );
	return $m->invokeArgs( null, $args );
}

$original = get_option( MWSEO_Options::KEY );

// ---------------------------------------------------------------------------------------------
// Activation: only a first-time installation is flagged for the setup screen.
delete_option( MWSEO_Options::KEY );
delete_transient( MWSEO_Setup::TRANSIENT );
MWSEO_Options::flush();
MWSEO_Plugin::activate();
check_ui( (string) get_current_user_id() === get_transient( MWSEO_Setup::TRANSIENT ), 'fresh installation (no stored options) sets the one-time setup flag for the activating user' );
check_ui( false !== get_option( MWSEO_Options::KEY ), 'activation still stores the default options' );
delete_transient( MWSEO_Setup::TRANSIENT );
MWSEO_Plugin::activate();
check_ui( false === get_transient( MWSEO_Setup::TRANSIENT ), 'reactivation or an upgrade with stored options is never flagged' );
update_option( MWSEO_Options::KEY, false !== $original ? $original : MWSEO_Options::defaults() );
MWSEO_Options::flush();

// Redirect rules. wp_redirect is intercepted so the check can observe the target without exiting.
class MWSEO_Test_Redirect extends Exception {}
add_filter(
	'wp_redirect',
	static function ( $location ) {
		throw new MWSEO_Test_Redirect( $location );
	}
);
function redirect_target() {
	try {
		MWSEO_Setup::maybe_redirect();
	} catch ( MWSEO_Test_Redirect $e ) {
		return $e->getMessage();
	}
	return null;
}
set_transient( MWSEO_Setup::TRANSIENT, 'any', 300 );
$_GET['activate-multi'] = '1';
check_ui( null === redirect_target() && (bool) get_transient( MWSEO_Setup::TRANSIENT ), 'bulk activation does not redirect and keeps nothing hidden' );
unset( $_GET['activate-multi'] );
wp_set_current_user( 0 );
check_ui( null === redirect_target(), 'a visitor without manage_options is never redirected' );
wp_set_current_user( 1 );
// The one-time landing must never intercept a POST or an administration action.
$GLOBALS['pagenow'] = 'index.php';
set_transient( MWSEO_Setup::TRANSIENT, '99999', 300 );
check_ui( null === redirect_target() && '99999' === get_transient( MWSEO_Setup::TRANSIENT ), "another user's one-time landing is not consumed by this administrator" );
set_transient( MWSEO_Setup::TRANSIENT, 'any', 300 );
$any_target = redirect_target();
check_ui( is_string( $any_target ) && has( $any_target, 'page=mwseo-setup' ), 'an activation without a user (WP-CLI) lets the first administrator page view land on setup' );
set_transient( MWSEO_Setup::TRANSIENT, (string) get_current_user_id(), 300 );
$_SERVER['REQUEST_METHOD'] = 'POST';
check_ui( null === redirect_target() && (bool) get_transient( MWSEO_Setup::TRANSIENT ), 'a POST request is never redirected and keeps the flag' );
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['pagenow']        = 'admin-post.php';
check_ui( null === redirect_target() && (bool) get_transient( MWSEO_Setup::TRANSIENT ), 'admin-post.php is never redirected (GET)' );
$_SERVER['REQUEST_METHOD'] = 'POST';
check_ui( null === redirect_target() && (bool) get_transient( MWSEO_Setup::TRANSIENT ), 'an admin-post.php action (POST) is not intercepted' );
$_SERVER['REQUEST_METHOD'] = 'GET';
foreach ( array( 'admin-ajax.php', 'async-upload.php', 'options.php' ) as $handler ) {
	$GLOBALS['pagenow'] = $handler;
	check_ui( null === redirect_target() && (bool) get_transient( MWSEO_Setup::TRANSIENT ), "$handler is never redirected" );
}
$GLOBALS['pagenow'] = 'index.php';
$_GET['action']     = 'mwseo_indexnow_regenerate';
check_ui( null === redirect_target() && (bool) get_transient( MWSEO_Setup::TRANSIENT ), 'a page view carrying an action parameter is not redirected' );
unset( $_GET['action'] );
add_filter( 'wp_doing_ajax', '__return_true' );
check_ui( null === redirect_target() && (bool) get_transient( MWSEO_Setup::TRANSIENT ), 'an AJAX request is not redirected' );
remove_filter( 'wp_doing_ajax', '__return_true' );
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['pagenow']        = 'index.php';
$target = redirect_target();
check_ui( is_string( $target ) && has( $target, 'page=mwseo-setup' ), 'administrator is sent to the setup screen once' );
check_ui( false === get_transient( MWSEO_Setup::TRANSIENT ) && null === redirect_target(), 'the redirect happens only once' );

// ---------------------------------------------------------------------------------------------
// Menu entries and screen registration.
global $menu, $submenu;
do_action( 'admin_menu' );
$slugs = array_map(
	static function ( $item ) {
		return $item[2];
	},
	isset( $submenu['mwseo'] ) ? $submenu['mwseo'] : array()
);
check_ui( in_array( 'mwseo-setup', $slugs, true ), 'a permanent "Get started" menu entry exists for existing administrators' );
check_ui( in_array( 'mw-seo-import', $slugs, true ) && in_array( 'mwseo', $slugs, true ), 'Import and Settings entries remain' );
check_ui( false !== MWSEO_Setup::hook() && MWSEO_Admin::is_screen( MWSEO_Setup::hook() ) && MWSEO_Admin::is_screen( 'toplevel_page_mwseo' ), 'setup and settings are registered Manny screens' );
check_ui( ! MWSEO_Admin::is_screen( 'edit.php' ) && ! MWSEO_Admin::is_screen( 'post.php' ) && ! MWSEO_Admin::is_screen( 'upload.php' ), 'other administration screens are not Manny screens' );

// ---------------------------------------------------------------------------------------------
// Asset scoping.
$reset_assets = static function () {
	foreach ( array( 'mwseo-admin' ) as $handle ) {
		wp_dequeue_style( $handle );
		wp_dequeue_script( $handle );
	}
	wp_dequeue_script( 'mwseo-importer' );
};
$reset_assets();
MWSEO_Admin::assets( 'post.php' );
check_ui( ! wp_style_is( 'mwseo-admin', 'enqueued' ) && ! wp_script_is( 'mwseo-admin', 'enqueued' ), 'admin CSS and script are not loaded on the post editor' );
MWSEO_Admin::assets( 'plugins.php' );
check_ui( ! wp_style_is( 'mwseo-admin', 'enqueued' ), 'admin CSS is not loaded on unrelated screens' );
MWSEO_Admin::assets( MWSEO_Setup::hook() );
check_ui( wp_style_is( 'mwseo-admin', 'enqueued' ) && wp_script_is( 'mwseo-admin', 'enqueued' ), 'admin CSS and the media script load on the setup screen' );
$reset_assets();
MWSEO_Importer::enqueue_assets( 'post.php' );
check_ui( ! wp_script_is( 'mwseo-importer', 'enqueued' ), 'the importer script is not loaded elsewhere' );
MWSEO_Importer::enqueue_assets( MWSEO_Setup::hook() );
check_ui( wp_script_is( 'mwseo-importer', 'enqueued' ), 'the importer script loads on the setup screen' );
$reset_assets();

// ---------------------------------------------------------------------------------------------
// Setup screen content.
$html = page_html( array( 'MWSEO_Setup', 'render' ) );
check_ui( has( $html, 'Write it your way.' ) && has( $html, 'mwseo-brand__mark' ) && has( $html, 'mwseo-logo.svg' ), 'brand lockup with the existing MW mark and the tagline as text' );
check_ui( has( $html, 'Bring your SEO data along' ) && has( $html, 'Preview and import supported post SEO data from Yoast SEO or Rank Math. Then check your site identity.' ), 'step 1 is the import, with the agreed copy' );
check_ui( strpos( $html, 'mwseo-step-import' ) < strpos( $html, 'mwseo-step-identity' ), 'import comes before site identity' );
check_ui( has( $html, '<option value="yoast">Yoast SEO</option>' ) && has( $html, '<option value="rankmath">Rank Math SEO</option>' ), 'both supported sources are offered even when neither plugin is active' );
check_ui( ! has( $html, 'value="aioseo"' ) && ! has( $html, 'value="seopress"' ) && ! has( $html, 'value="tsf"' ), 'unsupported sources are not offered' );
check_ui( has( $html, 'id="mw-import-preview-btn"' ) && preg_match( '/id="mw-import-run-btn"[^>]*\sdisabled/', $html ), 'Preview is available and Run Import starts disabled' );
check_ui( has( $html, '<input type="hidden" name="dry_run" value="0">' ) && ! has( $html, 'type="checkbox" name="dry_run"' ) && ! has( $html, 'Dry run' ), 'the setup form has no dry-run option and never previews by default' );
check_ui( has( $html, 'Existing Manny Wenas SEO values are preserved unless you choose to overwrite them.' ) && has( $html, 'Nothing is deleted' ) && has( $html, 'reviewed separately' ), 'copy explains retained source data, preserved values and separate site-wide review' );
check_ui( has( $html, 'name="mwseo_options[org_name]"' ) && has( $html, 'name="mwseo_options[org_logo]"' ) && has( $html, 'name="mwseo_options[site_represents]"' ) && has( $html, 'name="mwseo_options[person_user]"' ), 'site identity uses the existing option keys' );
check_ui( has( $html, 'data-mwseo-media' ), 'the logo uses the media selector' );
check_ui( has( $html, 'Skip this step' ) && ! has( $html, 'disabled="disabled"' ), 'setup can be skipped and has no completion gate' );
check_ui( has( $html, 'aria-current="page"' ) && has( $html, 'is-active' ), 'the navigation marks the current screen' );
check_ui( ! has( $html, 'is still active.' ), 'no other-plugin note when neither plugin is active' );

// An active source plugin: the note is shown.
$opts_before = MWSEO_Options::all();
define( 'WPSEO_VERSION', '99.0' );
$html = page_html( array( 'MWSEO_Setup', 'render' ) );
check_ui( has( $html, 'Yoast SEO is still active.' ), 'an active Yoast SEO is called out' );
define( 'RANK_MATH_VERSION', '99.0' );
$html = page_html( array( 'MWSEO_Setup', 'render' ) );
check_ui( has( $html, 'Rank Math SEO is still active.' ), 'an active Rank Math is called out' );
check_ui( has( $html, '<option value="yoast">' ) && has( $html, '<option value="rankmath">' ), 'the sources stay selectable while a plugin is active' );

// Missing identity data is named without becoming a requirement.
update_option( MWSEO_Options::KEY, array_merge( $opts_before, array( 'org_logo' => 0, 'site_represents' => 'person', 'person_user' => 0 ) ) );
MWSEO_Options::flush();
$html = page_html( array( 'MWSEO_Setup', 'render' ) );
check_ui( has( $html, 'Not set yet: logo, person.' ) && has( $html, 'All of it is optional for writing.' ), 'absent identity data is listed as optional' );
update_option( MWSEO_Options::KEY, $opts_before );
MWSEO_Options::flush();

// ---------------------------------------------------------------------------------------------
// Import page keeps its dry-run option and uses the same form.
$html = page_html( array( 'MWSEO_Importer', 'render_admin_page' ) );
check_ui( has( $html, 'name="dry_run" value="1" checked' ) && has( $html, 'Dry run — preview only, no data written' ), 'the Import page keeps a checked dry-run option' );
check_ui( has( $html, 'id="mw-import-source"' ) && has( $html, 'Import SEO Data' ) && has( $html, 'mwseo-nav__link is-active' ), 'the Import page uses the shared layout' );

// ---------------------------------------------------------------------------------------------
// Settings: every field still renders in its section; navigation, save bar and descriptions exist.
$fields = private_call( 'MWSEO_Settings', 'fields' );
$sections = MWSEO_Admin::sections();
check_ui( array_keys( $sections ) === array_keys( $fields ), 'the seven sections and their field groups match' );
$all_rendered = true;
$nav_ok       = true;
foreach ( $sections as $slug => $label ) {
	$_GET['tab'] = $slug;
	$html        = page_html( array( 'MWSEO_Settings', 'render' ) );
	foreach ( $fields[ $slug ] as $f ) {
		if ( ! has( $html, MWSEO_Options::KEY . '[' . $f[0] . ']' ) ) {
			$all_rendered = false;
			echo "  missing field {$f[0]} in $slug\n";
		}
	}
	$nav_ok = $nav_ok && 7 === substr_count( $html, 'mwseo-nav__link is-nested' ) + substr_count( $html, 'mwseo-nav__link is-active is-nested' ) - 1
		&& has( $html, 'aria-current="page"' ) && has( $html, 'mwseo-savebar' ) && has( $html, '<h1>' . esc_html( $label ) . '</h1>' );
}
unset( $_GET['tab'] );
check_ui( $all_rendered, 'all existing settings controls render in their original sections' );
check_ui( $nav_ok, 'each section has the left navigation, a title and a stable save bar' );
$_GET['tab'] = 'gsc';
$html        = page_html( array( 'MWSEO_Settings', 'render' ) );
check_ui( has( $html, 'mwseo_gsc_connect=1' ) && has( $html, 'name="mwseo_options[gsc_client_secret]"' ), 'Search Console connection controls are unchanged' );
$_GET['tab'] = 'verify';
$html        = page_html( array( 'MWSEO_Settings', 'render' ) );
check_ui( has( $html, 'mwseo_indexnow_regenerate' ) && has( $html, 'name="mwseo_options[indexnow_enabled]"' ), 'IndexNow controls are unchanged' );
unset( $_GET['tab'] );

// ---------------------------------------------------------------------------------------------
// Saving keeps every option that was not submitted, and sanitises the attachment IDs.
MWSEO_Settings::register();
$baseline = MWSEO_Options::defaults();
$baseline['org_name']       = 'Baseline Org';
$baseline['org_logo']       = 0;
$baseline['site_represents'] = 'organization';
$baseline['person_user']    = 0;
$baseline['default_image']  = 7;
$baseline['llms_intro']     = 'Kept';
$baseline['gsc_client_id']  = 'client';
update_option( MWSEO_Options::KEY, $baseline );
MWSEO_Options::flush();
update_option( MWSEO_Options::KEY, array( 'org_logo' => '12', 'org_name' => 'New Org', 'site_represents' => 'both', 'person_user' => '1' ) );
MWSEO_Options::flush();
$saved = get_option( MWSEO_Options::KEY );
$changed = array_keys( array_diff_assoc( array_map( 'wp_json_encode', $saved ), array_map( 'wp_json_encode', $baseline ) ) );
sort( $changed );
check_ui( array( 'org_logo', 'org_name', 'person_user', 'site_represents' ) === $changed, 'saving the identity fields changes only those four options' );
check_ui( 12 === $saved['org_logo'] && is_int( $saved['org_logo'] ) && 7 === $saved['default_image'], 'the logo is stored as an integer attachment ID and other IDs are untouched' );
update_option( MWSEO_Options::KEY, array( 'org_logo' => 'abc', 'default_image' => '-4' ) );
MWSEO_Options::flush();
$saved = get_option( MWSEO_Options::KEY );
check_ui( 0 === $saved['org_logo'] && 4 === $saved['default_image'], 'non-numeric and negative IDs are sanitised as before (absint)' );
check_ui( 'New Org' === $saved['org_name'] && 'Kept' === $saved['llms_intro'] && 'client' === $saved['gsc_client_id'], 'unrelated options survive a partial save' );

// ---------------------------------------------------------------------------------------------
// Media field: the stored ID is rendered back, with a preview when the attachment is an image.
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
$up  = wp_upload_bits( 'mwseo-ui-test.png', null, $png );
$att = 0;
if ( empty( $up['error'] ) ) {
	$att = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'ui test', 'post_status' => 'inherit' ), $up['file'] );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $up['file'] ) );
}
$field = array( 'org_logo', 'media', 'Logo', '' );
$html  = page_html( static function () use ( $field, $att ) {
	MWSEO_Settings::field( $field, array( 'org_logo' => $att ) );
} );
check_ui( $att > 0 && has( $html, 'value="' . $att . '"' ) && has( $html, 'name="mwseo_options[org_logo]"' ) && has( $html, 'data-mwseo-media' ), 'the media field keeps the attachment ID in the original input' );
check_ui( has( $html, '<img src=' ), 'the media field shows a preview of the selected image' );
$html = page_html( static function () use ( $field ) {
	MWSEO_Settings::field( $field, array( 'org_logo' => 0 ) );
} );
check_ui( has( $html, 'value="0"' ) && ! has( $html, '<img' ), 'an empty media field shows no preview' );
if ( $att ) {
	wp_delete_attachment( $att, true );
}

// ---------------------------------------------------------------------------------------------
// Editor strings: the honest score label and no leftover navigation strings.
$strings = private_call( 'MWSEO_Companion', 'strings' );
foreach ( array( 'checksScore', 'scoreNote', 'keyphrases', 'searchResult', 'editSlug' ) as $key ) {
	check_ui( ! empty( $strings[ $key ] ), "editor string $key exists" );
}
check_ui( ! isset( $strings['fields'] ) && ! isset( $strings['score'] ), 'the replaced editor strings are gone' );
check_ui( has( $strings['scoreNote'], 'does not judge the quality of your writing' ), 'the score note says what the number is not' );
$js = file_get_contents( MWSEO_DIR . 'assets/js/companion-block.js' );
preg_match_all( '/\bt\.([A-Za-z0-9_]+)/', $js, $used );
$strings = private_call( 'MWSEO_Companion', 'strings' );
$unknown = array_diff( array_unique( $used[1] ), array_keys( $strings ) );
check_ui( ! $unknown, 'every string the sidebar script uses is defined in PHP' . ( $unknown ? ' (' . implode( ',', $unknown ) . ')' : '' ) );

// ---------------------------------------------------------------------------------------------
// Import preview hint: the setup screen gets its own next step, the Import page keeps the old one.
$reset_assets();
MWSEO_Importer::enqueue_assets( MWSEO_Setup::hook() );
$inline = implode( "\n", (array) wp_scripts()->get_data( 'mwseo-importer', 'after' ) );
check_ui( has( $inline, 'click_run_import_to_apply_these_changes' ) && has( $inline, 'uncheck_dry_run_and_click_run_import_to_' ) && has( $inline, "hasClass('mwseo-import--setup')" ), 'the importer script carries both preview hints and picks by screen' );
$html = page_html( static function () {
	MWSEO_Importer::render_import_form( 'setup' );
} );
check_ui( has( $html, 'mwseo-import--setup' ), 'the setup form is marked so the script can tell the screens apart' );
$html = page_html( static function () {
	MWSEO_Importer::render_import_form( 'import' );
} );
check_ui( has( $html, 'mwseo-import--import' ) && ! has( $html, 'mwseo-import--setup' ), 'the Import page form is not marked as setup' );
$reset_assets();

// ---------------------------------------------------------------------------------------------
// POT: every message with a printf placeholder carries the php-format flag.
$pot     = file_get_contents( MWSEO_DIR . 'languages/manny-wenas-seo.pot' );
$missing = array();
foreach ( preg_split( '/\n\n/', $pot ) as $block ) {
	// A doubled percent sign is a literal, not a placeholder.
	if ( preg_match( '/^msgid "(?:[^%"]|%%)*%(\d+\$)?[sd]/m', $block ) && false === strpos( $block, '#, php-format' ) ) {
		$missing[] = strtok( substr( $block, strpos( $block, 'msgid' ) ), "\n" );
	}
}
check_ui( ! $missing, 'POT: every printf message is flagged php-format' . ( $missing ? ' (' . implode( '; ', $missing ) . ')' : '' ) );

// ---------------------------------------------------------------------------------------------
// Scoring and metadata contract are untouched.
check_ui( '1.2' === MWSEO_Scorer::SCORING_VERSION, 'SCORING_VERSION stays 1.2' );

if ( false !== $original ) {
	update_option( MWSEO_Options::KEY, $original );
} else {
	delete_option( MWSEO_Options::KEY );
}
MWSEO_Options::flush();
echo 'WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION . ": $checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
