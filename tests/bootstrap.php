<?php
/**
 * PHPUnit bootstrap for Manny Wenas SEO tests.
 *
 * PURPOSE: define ABSPATH before the production files are loaded.
 *
 * Both production classes begin with:
 *
 *     defined( 'ABSPATH' ) || exit;
 *
 * That guard prevents PHP from being executed outside WordPress. Without this
 * bootstrap, PHPUnit calls require_once on those files and PHP exits before a
 * single test can run. The constant defined here is a harmless placeholder; it
 * is not a real WordPress installation path.
 *
 * Brain Monkey stubs the WordPress functions each production class calls, so
 * WordPress itself is never bootstrapped during testing.
 *
 * @package MannyWenasSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Composer autoloader (Brain Monkey, PHPUnit, Patchwork).
// This is a hard require: a missing vendor/ directory should fail the test run
// immediately with a clear error rather than silently proceeding and producing
// cryptic "class not found" failures inside the test suite.
$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	fprintf(
		STDERR,
		"Bootstrap error: vendor/autoload.php not found.\n"
		. "Run 'composer install' before running the test suite.\n"
		. "Expected at: %s\n",
		$autoload
	);
	exit( 1 );
}
require_once $autoload;
