<?php
/**
 * Nightly cache invalidation so past events never linger on index pages.
 *
 * The event listings (home, city landers, event pages' "More in …") are
 * queried server-side against `ext_date >= today`, but a full-page cache
 * (LiteSpeed on the production box) keeps serving yesterday's HTML —
 * events dated before today stay visible for days. Two defenses, both
 * theme-owned so they deploy with the theme:
 *
 *   1. A WP-Cron event at local midnight + 5 minutes purges the page
 *      cache (LiteSpeed purge-all when active) and drops the theme's
 *      event-count transient. A miss just means the next defense covers
 *      it; a catch-up run also reseats the next night.
 *   2. Index pages cap their cache TTL at the next local midnight, so
 *      even with cron disabled a cached listing expires right after the
 *      date rolls over and is rebuilt against the new "today".
 *
 * @package Extroverting\Theme
 */

namespace Extroverting\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and executes the nightly cache purge.
 */
final class Cache_Schedule {

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'extroverting_nightly_cache_purge';

	/**
	 * Minutes (in seconds) after local midnight to run the purge.
	 */
	const BUFFER = 300;

	/**
	 * The transient this theme caches city event counts in.
	 */
	const COUNTS_TRANSIENT = 'ext_city_event_counts_v1';

	/**
	 * Wire up scheduling and the TTL cap.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_purge' ) );
		add_action( 'template_redirect', array( __CLASS__, 'cap_index_ttl' ), 0 );
	}

	/**
	 * UTC timestamp of the next local midnight (plus the buffer).
 	*
	 * `wp_timezone()` keeps this DST-correct: "tomorrow 00:00" is computed
	 * on the site's wall clock and converted back to a true epoch.
	 *
	 * @param int   $buffer Seconds to add after midnight. Default self::BUFFER.
	 * @param float $now    Reference epoch (UTC). Defaults to time().
	 * @return int
	 */
	public static function next_local_midnight( $buffer = self::BUFFER, $now = null ) {
		$now   = ( null === $now ) ? time() : (float) $now;
		$local = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );
		return (int) $local->modify( 'tomorrow 00:00:00' )->getTimestamp() + (int) $buffer;
	}

	/**
	 * Make sure exactly one purge is queued for the coming night.
	 *
	 * Reschedules anything that is not aligned with the next local midnight
	 * (a leftover from a previous timezone setting, a DST shift, host clock
	 * changes). An event inside tonight's fire window is left alone so a
	 * purge queued for seconds from now is never cancelled.
	 *
	 * @return void
	 */
	public static function schedule() {
		$want = self::next_local_midnight();
		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $next ) {
			$aligned  = abs( (int) $next - $want ) <= 15 * MINUTE_IN_SECONDS;
			$imminent = (int) $next <= time() + self::BUFFER;
			if ( $aligned || $imminent ) {
				return;
			}
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( $want, self::CRON_HOOK );
	}

	/**
	 * Run the nightly purge and queue the next night.
	 *
	 * @return void
	 */
	public static function run_purge() {
		// Full page-cache purge; a no-op unless LiteSpeed Cache is active.
		do_action( 'litespeed_purge_all' );

		// Theme-side caches that embed event lists keyed to "today".
		delete_transient( self::COUNTS_TRANSIENT );

		// Other plugins (object cache warmers, sitemaps, …) hook here.
		do_action( 'extroverting_nightly_cache_purged' );

		self::schedule();
	}

	/**
	 * Cap the page-cache TTL of index pages at the next local midnight.
 	*
	 * Runs on template_redirect (priority 0, before the quiz no-cache gate)
	 * so LiteSpeed sees the control value before it decides. Pages that are
	 * already no-cached (quiz surfaces) ignore this harmlessly.
	 *
	 * @return void
	 */
	public static function cap_index_ttl() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_search() ) {
			return;
		}

		$is_index = is_front_page() || is_home() || is_archive() || is_singular( array( 'ext_city', 'ext_event' ) );
		if ( ! $is_index ) {
			return;
		}

		$ttl = self::next_local_midnight( self::BUFFER ) - time();
		if ( $ttl <= 0 || $ttl > DAY_IN_SECONDS + self::BUFFER ) {
			return;
		}

		// LiteSpeed Cache: shorten this page's cache TTL (default is days).
		do_action( 'litespeed_control_set_ttl', $ttl );
	}
}