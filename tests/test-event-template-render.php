<?php
/**
 * Integration smoke test: the raw-PHP event detail fragment must render
 * end-to-end inside a real query — hero image, About body, and no past
 * events in the related list.
 *
 * @package Extroverting\Theme\Tests
 */

class Test_Event_Template_Render extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		update_option( 'timezone_string', 'America/Los_Angeles' );
		if ( ! post_type_exists( 'ext_event' ) ) {
			register_post_type( 'ext_event', array(
				'labels'   => array( 'name' => 'Events' ),
				'public'   => true,
				'supports' => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
			) );
		}
		if ( ! taxonomy_exists( 'ext_event_cat' ) ) {
			register_taxonomy( 'ext_event_cat', 'ext_event', array( 'hierarchical' => true ) );
		}
		add_filter( 'pre_http_request', array( $this, 'stub_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 10 );
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	/**
	 * Answer the fixture source page with an og:image.
	 *
	 * @param bool  $pre  Preempt value.
	 * @param array $args Request args.
	 * @param string $url Requested URL.
	 * @return array|false
	 */
	public function stub_http( $pre, $args, $url ) {
		if ( false !== strpos( (string) $url, 'source.example' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html><head><meta property="og:image" content="https://img.example/still.jpg"></head><body></body></html>',
			);
		}
		// Never let unmatched hosts reach the network: tests must stay
		// deterministic with or without internet access.
		return array( 'response' => array( 'code' => 404 ), 'body' => '' );
	}

	/**
	 * Build a city + event and render the detail fragment.
	 *
	 * @param array $meta Event meta.
	 * @param array $args Post args.
	 * @return string Rendered HTML.
	 */
	private function render_event( array $meta = array(), array $args = array() ) {
		$city = self::factory()->post->create(
			array( 'post_type' => 'ext_city', 'post_title' => 'Culver City' )
		);
		$id = self::factory()->post->create(
			array_merge(
				array( 'post_type' => 'ext_event', 'post_status' => 'publish', 'post_content' => '' ),
				$args
			)
		);
		update_post_meta( $id, 'ext_city', (string) $city );
		update_post_meta( $id, 'ext_date', gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +3 days' ) ) );
		update_post_meta( $id, 'ext_venue', 'The Wende Museum' );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		$this->go_to( '/?ext_event=' . get_post_field( 'post_name', $id ) );
		$this->assertTrue( is_singular( 'ext_event' ), 'The event query must resolve.' );

		$template = locate_template( array( 'templates/single-ext_event.html' ) );
		$this->assertNotEmpty( $template, 'The event fragment must resolve.' );

		ob_start();
		include $template;
		return (string) ob_get_clean();
	}

	public function test_about_body_renders_post_content() {
		$html = $this->render_event(
			array(),
			array( 'post_content' => 'Oscar Rabin wanted to paint.' )
		);

		$this->assertStringContainsString( 'About this event', $html );
		$this->assertStringContainsString( '<p>Oscar Rabin wanted to paint.</p>', $html );
		$this->assertStringNotContainsString( 'wp-block-post-content', $html, 'The old empty block stub must be gone.' );
	}

	public function test_hero_placeholder_without_source() {
		$html = $this->render_event();

		$this->assertStringContainsString( 'ext-ev-hero', $html );
		$this->assertStringContainsString( 'The Wende Museum', $html, 'The venue shows in the placeholder.' );
		$this->assertStringNotContainsString( 'ext-ev-hero-img', $html );
	}

	public function test_hero_image_renders_from_source_page() {
		$html = $this->render_event(
			array( 'ext_source_url' => 'https://source.example/listing' )
		);

		$this->assertStringContainsString( 'ext-ev-hero-img', $html );
		$this->assertStringContainsString( 'src="https://img.example/still.jpg"', $html );
		$this->assertStringContainsString( 'alt="', $html );
	}

	public function test_related_list_skips_past_events() {
		$html = $this->render_event();

		// The fragment renders "More in Culver City" with the city's other
		// upcoming events; seed one upcoming and one past event.
		$city_id = (int) get_post_meta( get_queried_object_id(), 'ext_city', true );
		$upcoming = self::factory()->post->create(
			array( 'post_type' => 'ext_event', 'post_title' => 'Upcoming Jam', 'post_status' => 'publish' )
		);
		update_post_meta( $upcoming, 'ext_city', (string) $city_id );
		update_post_meta( $upcoming, 'ext_date', gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +2 days' ) ) );

		$past = self::factory()->post->create(
			array( 'post_type' => 'ext_event', 'post_title' => 'Past Jam', 'post_status' => 'publish' )
		);
		update_post_meta( $past, 'ext_city', (string) $city_id );
		update_post_meta( $past, 'ext_date', gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -9 days' ) ) );

		ob_start();
		include locate_template( array( 'templates/single-ext_event.html' ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Upcoming Jam', $html );
		$this->assertStringNotContainsString( 'Past Jam', $html, 'Past events must never appear in the related list.' );
	}
}