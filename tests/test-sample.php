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
	 * The front page renders through template-shell.php as a full document,
	 * without leaking raw PHP source (the FSE canvas would print it literally).
	 */
	public function test_front_page_renders_full_document_without_raw_php() {
		$this->go_to( '/' );

		// template-loader.php runs the full frontend render at require time
		// (normally required once by wp-blog-header.php, which also defines
		// WP_USE_THEMES).
		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', true );
		}
		ob_start();
		require ABSPATH . WPINC . '/template-loader.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<head>', $html, 'Front page should render a full HTML document via template-shell.php.' );
		$this->assertStringNotContainsString( '<?php', $html, 'Rendered front page must not leak raw PHP source.' );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*/legal/terms">Terms</a>#', $html, 'Footer legal links should be real URLs, not template source.' );
	}

	/**
	 * Required template files exist.
	 */
	public function test_required_templates_exist() {
		$base = dirname( __DIR__ ) . '/extroverting';
		$required = array(
			'template-shell.php',
			'templates/index.html',
			'parts/header.html',
			'parts/footer.html',
		);
		foreach ( $required as $template ) {
			$this->assertFileExists( $base . '/' . $template, "Template {$template} should exist." );
		}
	}
}
