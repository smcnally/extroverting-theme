<?php
/**
 * Nightly cache invalidation: midnight scheduling (DST-correct), the
 * purge callback, and the index-page TTL cap.
 *
 * @package Extroverting\Theme\Tests
 */

use Extroverting\Theme\Cache_Schedule;

class Test_Cache_Schedule extends WP_UnitTestCase {

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
		wp_clear_scheduled_hook( Cache_Schedule::CRON_HOOK );
		update_option( 'timezone_string', $this->orig_timezone );
		parent::tear_down();
	}

	public function test_next_local_midnight_is_true_utc_epoch() {
		// 2026-11-24 15:00 PST == 23:00 UTC; next midnight is 08:00 UTC + buffer.
		$now      = ( new DateTimeImmutable( '2026-11-24 15:00:00', new DateTimeZone( 'America/Los_Angeles' ) ) )->getTimestamp();
		$expected = ( new DateTimeImmutable( '2026-11-25 00:05:00', new DateTimeZone( 'America/Los_Angeles' ) ) )->getTimestamp();

		$this->assertSame( $expected, Cache_Schedule::next_local_midnight( Cache_Schedule::BUFFER, $now ) );
	}

	public function test_next_local_midnight_survives_dst_transitions() {
		update_option( 'timezone_string', 'America/New_York' );

		// Spring forward 2026-03-08: midnight before the jump is still EST (-5).
		$before = ( new DateTimeImmutable( '2026-03-07 15:00:00', new DateTimeZone( 'America/New_York' ) ) )->getTimestamp();
		$expected_before = ( new DateTimeImmutable( '2026-03-08 00:00:00', new DateTimeZone( 'America/New_York' ) ) )->getTimestamp();
		$this->assertSame( $expected_before, Cache_Schedule::next_local_midnight( 0, $before ) );

		// Fall back 2026-11-01: midnight before the jump is still EDT (-4).
		$fall = ( new DateTimeImmutable( '2026-10-31 15:00:00', new DateTimeZone( 'America/New_York' ) ) )->getTimestamp();
		$expected_fall = ( new DateTimeImmutable( '2026-11-01 00:00:00', new DateTimeZone( 'America/New_York' ) ) )->getTimestamp();
		$this->assertSame( $expected_fall, Cache_Schedule::next_local_midnight( 0, $fall ) );
	}

	public function test_schedule_queues_exactly_one_nightly_event() {
		Cache_Schedule::schedule();
		$this->assertSame( Cache_Schedule::next_local_midnight(), wp_next_scheduled( Cache_Schedule::CRON_HOOK ) );

		// Idempotent: a second pass must not move or duplicate the event.
		Cache_Schedule::schedule();
		$this->assertSame( Cache_Schedule::next_local_midnight(), wp_next_scheduled( Cache_Schedule::CRON_HOOK ) );
	}

	public function test_run_purge_purges_pages_and_reschedules() {
		set_transient( Cache_Schedule::COUNTS_TRANSIENT, array( 42 => 7 ), 600 );

		$purged  = array();
		$on_purge = function () use ( &$purged ) {
			$purged[] = 'purge-all';
		};
		add_action( 'litespeed_purge_all', $on_purge );

		$extra  = array();
		$on_extra = function () use ( &$extra ) {
			$extra[] = 1;
		};
		add_action( 'extroverting_nightly_cache_purged', $on_extra );

		Cache_Schedule::run_purge();

		$this->assertSame( array( 'purge-all' ), $purged, 'LiteSpeed purge-all hook must fire.' );
		$this->assertSame( array( 1 ), $extra );
		$this->assertFalse( get_transient( Cache_Schedule::COUNTS_TRANSIENT ), 'City counts transient must be dropped.' );
		$this->assertSame(
			Cache_Schedule::next_local_midnight(),
			wp_next_scheduled( Cache_Schedule::CRON_HOOK ),
			'Next night must be queued after a purge.'
		);

		remove_action( 'litespeed_purge_all', $on_purge );
		remove_action( 'extroverting_nightly_cache_purged', $on_extra );
	}

	public function test_cap_index_ttl_caps_front_page() {
		$captured = null;
		add_action(
			'litespeed_control_set_ttl',
			function ( $ttl ) use ( &$captured ) {
				$captured = $ttl;
			}
		);

		$this->go_to( '/' );
		Cache_Schedule::cap_index_ttl();

		$this->assertNotNull( $captured, 'Front page must receive a TTL cap.' );
		$this->assertGreaterThan( 0, $captured );
		$this->assertLessThanOrEqual( DAY_IN_SECONDS + Cache_Schedule::BUFFER, $captured );
	}

	public function test_cap_index_ttl_skips_search() {
		$captured = null;
		add_action(
			'litespeed_control_set_ttl',
			function ( $ttl ) use ( &$captured ) {
				$captured = $ttl;
			}
		);

		$this->go_to( '/?s=hello' );
		Cache_Schedule::cap_index_ttl();

		$this->assertNull( $captured );
	}
}