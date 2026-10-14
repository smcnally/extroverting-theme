<?php
/**
 * PHPUnit bootstrap for Extroverting theme tests.
 *
 * Uses wp-phpunit (the WordPress core test library via Composer). The
 * WP_PHPUNIT__DIR constant is defined by vendor/autoload.php; the real
 * tests config location is passed via the WP_PHPUNIT__TESTS_CONFIG env
 * var (set in phpunit.xml.dist).
 *
 * @package Extroverting\Theme\Tests
 */

// Composer autoloader must be loaded before WP_PHPUNIT__DIR will be available.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Make the theme under test available to the WordPress install used by the
// test framework: symlink it into the themes directory of the WP core install.
$themes_dir = dirname( __DIR__ ) . '/wordpress/wp-content/themes';
if ( ! is_dir( $themes_dir ) ) {
	mkdir( $themes_dir, 0755, true );
}
// The repo root is the theme directory (style.css, functions.php, ...).
$link_target = dirname( __DIR__ );
$link        = $themes_dir . '/extroverting';
if ( ! file_exists( $link ) && ! is_link( $link ) ) {
	symlink( $link_target, $link );
}

// Give access to tests_add_filter() function.
require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';

// Start up the WP testing environment (activates WP_DEFAULT_THEME from wp-config).
require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';
