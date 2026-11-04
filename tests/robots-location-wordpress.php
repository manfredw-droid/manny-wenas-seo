<?php
/**
 * Where a physical robots.txt can shadow WordPress's virtual one.
 *
 * Disposable site only (table prefix `equinox_`). Home and site URLs are simulated with option filters and
 * $_SERVER['SCRIPT_FILENAME'] points into a temporary tree, so no real server layout is needed.
 *
 * php tests/robots-location-wordpress.php /path/to/wp-load.php
 */
require $argv[1];
if ( 0 !== strpos( $wpdb->prefix, 'equinox_' ) ) {
	throw new RuntimeException( 'Disposable equinox_ prefix required' );
}
wp_set_current_user( 1 );
$checks   = 0;
$failures = 0;
function rl_check( $ok, $label ) {
	global $checks, $failures;
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
function rl_urls( $home, $siteurl ) {
	foreach ( array( 'home', 'siteurl' ) as $name ) {
		remove_all_filters( 'pre_option_' . $name );
	}
	add_filter(
		'pre_option_home',
		static function () use ( $home ) {
			return $home;
		}
	);
	add_filter(
		'pre_option_siteurl',
		static function () use ( $siteurl ) {
			return $siteurl;
		}
	);
}
function rl_warns() {
	ob_start();
	MWSEO_Notices::robots_notice();
	return false !== strpos( ob_get_clean(), 'physical robots.txt' );
}
function rl_path() {
	$m = new ReflectionMethod( 'MWSEO_Notices', 'physical_robots_path' );
	$m->setAccessible( true );
	return $m->invoke( null );
}
function rl_rm( $path ) {
	if ( is_dir( $path ) && ! is_link( $path ) ) {
		foreach ( scandir( $path ) as $f ) {
			if ( '.' !== $f && '..' !== $f ) {
				rl_rm( $path . '/' . $f );
			}
		}
		rmdir( $path );
	} elseif ( file_exists( $path ) ) {
		unlink( $path );
	}
}
delete_user_meta( 1, MWSEO_Notices::ROBOTS_META );
$tmp   = sys_get_temp_dir() . '/mwseo-robots-' . getmypid();
$root  = $tmp . '/public';
@mkdir( $root . '/wp/wp-admin', 0777, true ); // phpcs:ignore
@mkdir( $root . '/blog/wp-admin', 0777, true ); // phpcs:ignore
$abs_robots = ABSPATH . 'robots.txt';
rl_rm( $abs_robots );

// 1. Home and WordPress both at /.
rl_urls( 'http://example.test', 'http://example.test' );
rl_check( rl_path() === ABSPATH . 'robots.txt', 'both at /: the file is looked for in the WordPress folder, which is the public root' );
rl_check( ! rl_warns(), 'both at /: no file, no warning' );
file_put_contents( $abs_robots, "User-agent: *\n" );
rl_check( rl_warns(), 'both at /: a robots.txt in the public root warns' );
rl_rm( $abs_robots );
mkdir( $abs_robots );
rl_check( ! rl_warns(), 'both at /: a directory named robots.txt does not warn' );
rl_rm( $abs_robots );

// 2. WordPress in /wp, home at the host root: the public root is the parent of the WordPress folder.
rl_urls( 'http://example.test', 'http://example.test/wp' );
$_SERVER['SCRIPT_FILENAME'] = $root . '/wp/wp-admin/index.php';
rl_check( rl_path() === $root . '/robots.txt', 'WordPress in /wp: the file is looked for in the public root, not in /wp' );
rl_check( ! rl_warns(), 'WordPress in /wp: no file, no warning' );
file_put_contents( $root . '/wp/robots.txt', "User-agent: *\n" );
rl_check( ! rl_warns(), 'WordPress in /wp: a robots.txt inside the WordPress folder is inert and does not warn' );
file_put_contents( $root . '/robots.txt', "User-agent: *\n" );
rl_check( rl_warns(), 'WordPress in /wp: a robots.txt in the public root warns' );
rl_rm( $root . '/robots.txt' );
mkdir( $root . '/robots.txt' );
rl_check( ! rl_warns(), 'WordPress in /wp: a directory named robots.txt in the public root does not warn' );
rl_rm( $root . '/robots.txt' );
rl_rm( $root . '/wp/robots.txt' );

// 3. Home and WordPress both in /blog: WordPress does not serve /robots.txt for the host, so never warn.
rl_urls( 'http://example.test/blog', 'http://example.test/blog' );
$_SERVER['SCRIPT_FILENAME'] = $root . '/blog/wp-admin/index.php';
file_put_contents( $root . '/robots.txt', "User-agent: *\n" );
file_put_contents( $root . '/blog/robots.txt', "User-agent: *\n" );
file_put_contents( $abs_robots, "User-agent: *\n" );
rl_check( '' === rl_path(), 'both in /blog: no robots.txt location is considered' );
rl_check( ! rl_warns(), 'both in /blog: files in the public root, the blog folder and the WordPress folder never warn' );
rl_rm( $root . '/robots.txt' );
rl_rm( $root . '/blog/robots.txt' );
rl_rm( $abs_robots );

// 4. Different hosts for home and WordPress.
rl_urls( 'http://example.test', 'http://cms.example.test' );
file_put_contents( $abs_robots, "User-agent: *\n" );
rl_check( '' === rl_path() && ! rl_warns(), 'different hosts: never warn, even with a file in the WordPress folder' );
rl_rm( $abs_robots );

// 5. The dismissal and the capability check still apply.
rl_urls( 'http://example.test', 'http://example.test' );
file_put_contents( $abs_robots, "User-agent: *\n" );
update_user_meta( 1, MWSEO_Notices::ROBOTS_META, true );
rl_check( ! rl_warns(), 'a dismissed warning stays dismissed' );
delete_user_meta( 1, MWSEO_Notices::ROBOTS_META );
wp_set_current_user( 0 );
rl_check( ! rl_warns(), 'without manage_options nothing is shown' );
wp_set_current_user( 1 );
rl_check( rl_warns(), 'with manage_options the warning shows again' );
rl_rm( $abs_robots );
rl_rm( $tmp );

echo 'WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION . ": $checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
