<?php
/**
 * Smoke tests for the Extroverting block theme.
 *
 * @package Extroverting\Theme\Tests
 */

/**
 * The theme loads as the active (default) theme in the test environment.
 */
class Test_Ext_Theme_Smoke extends WP_UnitTestCase {

	/**
	 * The theme is activated via WP_DEFAULT_THEME.
	 */
	public function test_theme_is_active() {
		$this->assertSame( 'extroverting', get_template(), 'The extroverting theme should be the active template.' );
	}

	/**
	 * functions.php defines the theme setup callback.
	 */
	public function test_setup_function_exists() {
		$this->assertTrue( function_exists( 'extroverting_setup' ), 'extroverting_setup() should be defined by functions.php.' );
	}

	/**
	 * theme.json is valid JSON with a schema version.
	 */
	public function test_theme_json_is_valid() {
		$json = file_get_contents( dirname( __DIR__ ) . '/extroverting/theme.json' );
		$this->assertNotFalse( $json, 'theme.json should exist.' );

		$data = json_decode( (string) $json, true );
		$this->assertIsArray( $data, 'theme.json should decode to an array.' );
		$this->assertArrayHasKey( 'version', $data, 'theme.json should declare a schema version.' );
	}

	/**
	 * Required template files exist.
	 */
	public function test_required_templates_exist() {
		$base = dirname( __DIR__ ) . '/extroverting';
		$required = array(
			'templates/index.html',
			'parts/header.html',
			'parts/footer.html',
		);
		foreach ( $required as $template ) {
			$this->assertFileExists( $base . '/' . $template, "Template {$template} should exist." );
		}
	}
}
