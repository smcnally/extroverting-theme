<?php
/**
 * The theme event query: local-date upcoming cutoff, sort modes, cat
 * filter, limit and exclusion.
 *
 * The extroverting-core plugin registers the real CPT; tests register a
 * minimal stand-in with the same type/taxonomy names.
 *
 * @package Extroverting\Theme\Tests
 */

class Test_Event_Query extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		update_option( 'timezone_string', 'America/Los_Angeles' );
		if ( ! post_type_exists( 'ext_event' ) ) {
			register_post_type( 'ext_event', array(
				'labels'   => array( 'name' => 'Events' ),
				'public'   => true,
				'supports' => array( 'title', 'editor', 'excerpt' ),
			) );
		}
		if ( ! taxonomy_exists( 'ext_event_cat' ) ) {
			register_taxonomy( 'ext_event_cat', 'ext_event', array( 'hierarchical' => true ) );
		}
	}

	public function tear_down() {
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	/**
	 * Create a city-bound event dated $offset days from the local today.
	 *
	 * @param int    $city_id City post ID.
	 * @param int    $offset  Day offset from today (negative = past).
	 * @param string $title   Event title.
	 * @return int
	 */
	private function event( $city_id, $offset = 0, $title = 'Event' ) {
		$today = current_time( 'Y-m-d' );
		$id    = self::factory()->post->create(
			array(
				'post_type'  => 'ext_event',
				'post_title' => $title,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, 'ext_city', (string) $city_id );
		update_post_meta( $id, 'ext_date', gmdate( 'Y-m-d', strtotime( $today . ' ' . ( $offset >= 0 ? '+' : '' ) . $offset . ' days' ) ) );
		return $id;
	}

	private function city() {
		return self::factory()->post->create( array( 'post_type' => 'ext_city', 'post_title' => 'Culver City' ) );
	}

	public function test_upcoming_cutoff_is_local_today() {
		$city = $this->city();
		$past = $this->event( $city, -1, 'Past' );
		$now  = $this->event( $city, 0, 'Today' );
		$next = $this->event( $city, 1, 'Tomorrow' );

		$q = extroverting_theme_event_query( $city );
		$ids = wp_list_pluck( $q->posts, 'ID' );

		$this->assertContains( $now, $ids, 'Same-day events must stay listed all day (local date).' );
		$this->assertContains( $next, $ids );
		$this->assertNotContains( $past, $ids, 'Past events must never render on index pages.' );
	}

	public function test_upcoming_only_false_includes_past() {
		$city = $this->city();
		$past = $this->event( $city, -1, 'Past' );
		$q    = extroverting_theme_event_query( $city, '', 'soon', false );
		$this->assertContains( $past, wp_list_pluck( $q->posts, 'ID' ) );
	}

	public function test_soon_sorts_by_date_ascending() {
		$city = $this->city();
		$this->event( $city, 2, 'Later' );
		$this->event( $city, 1, 'Sooner' );
		$q    = extroverting_theme_event_query( $city );
		$dates = wp_list_pluck( $q->posts, 'ext_date' );

		$this->assertCount( 2, $dates );
		$this->assertTrue( $dates[0] <= $dates[1] );
	}

	public function test_az_sorts_by_title() {
		$city = $this->city();
		$this->event( $city, 1, 'Zebra Night' );
		$this->event( $city, 2, 'Alpha Morning' );
		$q    = extroverting_theme_event_query( $city, '', 'az' );
		$titles = wp_list_pluck( $q->posts, 'post_title' );
		$this->assertSame( array( 'Alpha Morning', 'Zebra Night' ), $titles );
	}

	public function test_category_filter() {
		$city = $this->city();
		$a    = $this->event( $city, 1, 'Music Night' );
		$b    = $this->event( $city, 1, 'Quiet Walk' );
		$term = self::factory()->term->create(
			array( 'taxonomy' => 'ext_event_cat', 'name' => 'Music' )
		);
		wp_set_object_terms( $a, array( $term ), 'ext_event_cat' );

		$q = extroverting_theme_event_query( $city, 'music' );
		$ids = wp_list_pluck( $q->posts, 'ID' );
		$this->assertContains( $a, $ids );
		$this->assertNotContains( $b, $ids );
	}

	public function test_limit_and_exclude() {
		$city = $this->city();
		$one   = $this->event( $city, 1, 'One' );
		$two   = $this->event( $city, 2, 'Two' );
		$three = $this->event( $city, 3, 'Three' );

		$q = extroverting_theme_event_query( $city, '', 'soon', true, 2 );
		$this->assertCount( 2, $q->posts );

		$q = extroverting_theme_event_query( $city, '', 'soon', true, 0, $one );
		$ids = wp_list_pluck( $q->posts, 'ID' );
		$this->assertNotContains( $one, $ids );
		$this->assertEqualsCanonicalizing( array( $two, $three ), $ids );
	}

	public function test_only_requested_city() {
		$city_a = $this->city();
		$city_b = $this->city();
		$this->event( $city_a, 1 );
		$q = extroverting_theme_event_query( $city_b );
		$this->assertCount( 0, $q->posts );
	}
}