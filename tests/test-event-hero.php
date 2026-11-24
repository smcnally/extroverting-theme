<?php
/**
 * Event hero resolution and About-body fallbacks.
 *
 * Outbound HTTP is stubbed with pre_http_request, so the resolution
 * chain (filter > meta > thumbnail > source page > venue) is exercised
 * without network access.
 *
 * @package Extroverting\Theme\Tests
 */

use Extroverting\Theme\Event_Hero;

class Test_Event_Hero extends WP_UnitTestCase {

	/**
	 * URLs requested through the stub, in order.
	 *
	 * @var string[]
	 */
	private $http_calls = array();

	public function set_up() {
		parent::set_up();
		if ( ! post_type_exists( 'ext_event' ) ) {
			register_post_type( 'ext_event', array(
				'labels'   => array( 'name' => 'Events' ),
				'public'   => true,
				'supports' => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
			) );
		}
		$this->http_calls = array();
		add_filter( 'pre_http_request', array( $this, 'stub_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 10 );
		remove_all_filters( 'extroverting_event_hero_url' );
		parent::tear_down();
	}

	/**
	 * Record the call and answer from fixtures.
	 *
	 * @param bool  $pre  Preempt value (unused).
	 * @param array $args Request args.
	 * @param string $url Requested URL.
	 * @return array|false
	 */
	public function stub_http( $pre, $args, $url ) {
		$this->http_calls[] = (string) $url;

		if ( false !== strpos( $url, 'source.example/rabin' ) ) {
			return $this->response(
				'<html><head>' .
				'<meta property="og:image" content="https://img.example/still.jpg">' .
				'<meta property="og:description" content="Two documentaries on Soviet nonconformist art.">' .
				'</head><body></body></html>'
			);
		}

		if ( false !== strpos( $url, 'noimage.example' ) ) {
			return $this->response( '<html><head><title>Listing</title></head><body>text</body></html>' );
		}

		if ( false !== strpos( $url, 'commons.wikimedia.org' ) ) {
			return $this->response(
				(string) wp_json_encode(
					array(
						'query' => array(
							'pages' => array(
								array(
									'title'     => 'File:Wende Museum building.jpg',
									'imageinfo' => array(
										array(
											'mime'           => 'image/jpeg',
											'width'          => 4000,
											'thumburl'       => 'https://upload.example/1200px-Wende.jpg',
											'url'            => 'https://upload.example/Wende.jpg',
											'descriptionurl' => 'https://commons.example/wiki/File:Wende_Museum_building.jpg',
											'extmetadata'    => array(
												'Artist'            => array( 'value' => 'Jane <b>Doe</b>' ),
												'LicenseShortName'  => array( 'value' => 'CC BY-SA 4.0' ),
											),
										),
									),
								),
							),
						),
					)
				)
			);
		}

		return array( 'response' => array( 'code' => 404 ), 'body' => '' );
	}

	/**
	 * Well-formed 200 response array.
	 *
	 * @param string $body Body.
	 * @return array
	 */
	private function response( $body ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => $body );
	}

	/**
	 * Create an event with the given meta.
	 *
	 * @param array $meta Key/value meta.
	 * @param array $args Post args overrides.
	 * @return int
	 */
	private function event( array $meta = array(), array $args = array() ) {
		$id = self::factory()->post->create(
			array_merge(
				// The post factory defaults content to 'Post content N'; the About
				// fallbacks need an event with truly empty content.
				array( 'post_type' => 'ext_event', 'post_status' => 'publish', 'post_content' => '' ),
				$args
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	public function test_source_page_og_image_is_used() {
		$id = $this->event( array( 'ext_source_url' => 'https://source.example/rabin' ) );
		$hero = Event_Hero::hero( $id );

		$this->assertIsArray( $hero );
		$this->assertSame( 'https://img.example/still.jpg', $hero['url'] );
		$this->assertSame( 'source-page', $hero['source'] );
	}

	public function test_hero_result_is_cached_per_event() {
		$id = $this->event( array( 'ext_source_url' => 'https://source.example/rabin' ) );
		Event_Hero::hero( $id );
		$calls_after_first = count( $this->http_calls );
		Event_Hero::hero( $id );

		$this->assertSame( $calls_after_first, count( $this->http_calls ), 'A resolved hero must not refetch within the TTL.' );
	}

	public function test_meta_override_wins_without_http() {
		$id = $this->event(
			array(
				'ext_hero_url'   => 'https://override.example/hero.jpg',
				'ext_source_url' => 'https://source.example/rabin',
			)
		);
		$hero = Event_Hero::hero( $id );

		$this->assertSame( 'https://override.example/hero.jpg', $hero['url'] );
		$this->assertSame( 'meta', $hero['source'] );
		$this->assertSame( array(), $this->http_calls );
	}

	public function test_filter_wins_over_everything() {
		add_filter(
			'extroverting_event_hero_url',
			function () {
				return 'https://filter.example/photo.jpg';
			}
		);
		$id = $this->event( array( 'ext_source_url' => 'https://source.example/rabin' ) );
		$hero = Event_Hero::hero( $id );

		$this->assertSame( 'https://filter.example/photo.jpg', $hero['url'] );
		$this->assertSame( 'filter', $hero['source'] );
		$this->assertSame( array(), $this->http_calls );
	}

	public function test_featured_image_beats_source_page() {
		$id = $this->event(
			array( 'ext_source_url' => 'https://source.example/rabin' ),
			array( 'post_title' => 'With thumbnail' )
		);
		$attachment = self::factory()->attachment->create_object(
			'hero.jpg',
			$id,
			array( 'post_mime_type' => 'image/jpeg' )
		);
		set_post_thumbnail( $id, $attachment );

		$hero = Event_Hero::hero( $id );
		$this->assertSame( 'thumbnail', $hero['source'] );
		$this->assertStringStartsWith( 'http', $hero['url'] );
		$this->assertSame( array(), $this->http_calls );
	}

	public function test_venue_fallback_when_source_has_no_image() {
		$city = self::factory()->post->create( array( 'post_type' => 'ext_city', 'post_title' => 'Culver City' ) );
		$id   = $this->event(
			array(
				'ext_venue'      => 'The Wende Museum',
				'ext_city'       => (string) $city,
				'ext_source_url' => 'https://noimage.example/listing',
			)
		);
		$hero = Event_Hero::hero( $id );

		$this->assertIsArray( $hero );
		$this->assertSame( 'venue', $hero['source'] );
		$this->assertSame( 'https://upload.example/1200px-Wende.jpg', $hero['url'] );
		$this->assertSame( 'https://commons.example/wiki/File:Wende_Museum_building.jpg', $hero['credit_url'] );
		$this->assertStringContainsString( 'Jane Doe', $hero['credit_label'] );
		$this->assertStringContainsString( 'CC BY-SA 4.0', $hero['credit_label'] );
		$this->assertStringContainsString( 'Wikimedia Commons', $hero['credit_label'] );
		$this->assertCount( 2, $this->http_calls, 'Source page then Commons API, one each.' );
	}

	public function test_empty_when_nothing_resolves() {
		$id = $this->event( array() );
		$this->assertSame( '', Event_Hero::hero( $id ) );
		$this->assertSame( '', Event_Hero::hero_url( $id ) );
		$this->assertSame( array(), $this->http_calls );
	}

	public function test_parse_page_handles_content_before_property() {
		// Some sites emit content= before property=; the first meta tag must
		// win and a match must never bleed across tags.
		$html = '<meta content="https://b.example/two.jpg" property="og:image">'
			. '<meta property="og:description" content="Plain description">';

		$data = Event_Hero::parse_page( $html );
		$this->assertSame( 'https://b.example/two.jpg', $data['image'] );
		$this->assertSame( 'Plain description', $data['description'] );
	}

	public function test_about_prefers_post_content() {
		$id = $this->event(
			array( 'ext_source_url' => 'https://source.example/rabin' ),
			array( 'post_content' => 'Oscar Rabin wanted to paint.' )
		);
		$about = Event_Hero::about( $id );

		$this->assertStringContainsString( '<p>Oscar Rabin wanted to paint.</p>', $about );
		$this->assertSame( array(), $this->http_calls );
	}

	public function test_about_falls_back_to_source_description() {
		$id = $this->event( array( 'ext_source_url' => 'https://source.example/rabin' ) );
		$about = Event_Hero::about( $id );

		$this->assertStringContainsString( 'Two documentaries on Soviet nonconformist art.', $about );
	}

	public function test_about_empty_without_content_or_source() {
		$this->assertSame( '', Event_Hero::about( $this->event() ) );
	}

	public function test_source_link_uses_source_meta_label() {
		$id = $this->event(
			array(
				'ext_source_url' => 'https://source.example/rabin',
				'ext_source'     => 'meetup',
			)
		);
		$link = Event_Hero::source_link( $id );
		$this->assertSame( 'https://source.example/rabin', $link['url'] );
		$this->assertSame( 'meetup', $link['label'] );
	}

	public function test_source_link_falls_back_to_host() {
		$id = $this->event( array( 'ext_source_url' => 'https://www.source.example/rabin' ) );
		$link = Event_Hero::source_link( $id );
		$this->assertSame( 'source.example', $link['label'] );
	}

	public function test_source_link_empty_without_url() {
		$this->assertSame( '', Event_Hero::source_link( $this->event() ) );
	}
}