<?php
/**
 * Local-date event helpers: is_today / ended compare against the site's
 * local calendar day, not UTC.
 *
 * @package Extroverting\Theme\Tests
 */

class Test_Event_Date_Helpers extends WP_UnitTestCase {

	/**
	 * Timezone before the test, restored afterwards.
	 *
	 * @var string
	 */
	private $orig_timezone = '';

	public function set_up() {
		parent::set_up();
		$this->orig_timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/Los_Angeles' );
	}

	public function tear_down() {
		update_option( 'timezone_string', $this->orig_timezone );
		parent::tear_down();
	}

	/**
	 * The local today, computed independently of the helpers under test.
	 *
	 * @return string
	 */
	private function local_today() {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/Los_Angeles' ) ) )->format( 'Y-m-d' );
	}

	/**
	 * Create an ext_event with the given ext_date.
	 *
	 * @param string $date Y-m-d.
	 * @return int
	 */
	private function event( $date ) {
		$id = self::factory()->post->create(
			array( 'post_type' => 'ext_event', 'post_status' => 'publish' )
		);
		update_post_meta( $id, 'ext_date', $date );
		return $id;
	}

	public function test_is_today_uses_local_date() {
		$today = $this->local_today();
		$this->assertTrue( extroverting_event_is_today( $this->event( $today ) ) );
		$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
		$this->assertFalse( extroverting_event_is_today( $this->event( $yesterday ) ) );
		$tomorrow = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );
		$this->assertFalse( extroverting_event_is_today( $this->event( $tomorrow ) ) );
	}

	public function test_ended_uses_local_date() {
		$today = $this->local_today();
		$this->assertFalse( extroverting_event_ended( $this->event( $today ) ) );
		$tomorrow = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );
		$this->assertFalse( extroverting_event_ended( $this->event( $tomorrow ) ) );
		$this->assertTrue( extroverting_event_ended( $this->event( '2000-01-01' ) ) );
	}

	public function test_helpers_accept_explicit_today() {
		$id = $this->event( '2026-11-23' );
		$this->assertTrue( extroverting_event_ended( $id, '2026-11-24' ) );
		$this->assertFalse( extroverting_event_ended( $id, '2026-11-23' ) );
		$this->assertTrue( extroverting_event_is_today( $id, '2026-11-23' ) );
		$this->assertFalse( extroverting_event_is_today( $id, '2026-11-24' ) );
	}

	public function test_helpers_handle_missing_date() {
		$id = self::factory()->post->create( array( 'post_type' => 'ext_event' ) );
		$this->assertFalse( extroverting_event_is_today( $id ) );
		$this->assertFalse( extroverting_event_ended( $id ) );
	}
}