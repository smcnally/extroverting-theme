<?php
/**
 * Event hero image + About-this-event resolver.
 *
 * Event detail pages need a real hero image and an "About this event"
 * body, but the imported event record often carries neither: the cfe
 * pipeline stores a description and a source URL (meta `ext_source_url`),
 * not media. This resolver fills both gaps without touching the import:
 *
 *   1. `extroverting_event_hero_url` filter (early out for custom sources).
 *   2. Manual override meta `ext_hero_url`.
 *   3. Featured image (post thumbnail).
 *   4. Open Graph / Twitter image on the event's source page.
 *   5. An image of the venue itself, searched on Wikimedia Commons.
 *
 * External fetches are cached in transients so at most one request per
 * source (and one per venue) happens per TTL window, and failures fall
 * back to the CSS hero placeholder rather than blocking the page.
 *
 * @package Extroverting\Theme
 */

namespace Extroverting\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves hero imagery and About copy for ext_event posts.
 */
final class Event_Hero {

	/**
	 * Transient prefix for a resolved event hero (value: array or '').
	 */
	const TRANSIENT_PREFIX = 'ext_hero_';

	/**
	 * Transient prefix for a fetched source page (value: array or null).
	 */
	const SOURCE_TRANSIENT_PREFIX = 'ext_srcpage_';

	/**
	 * Transient prefix for a resolved venue image (value: array or '').
	 */
	const VENUE_TRANSIENT_PREFIX = 'ext_venueimg_';

	/**
	 * How long a successful external lookup is kept.
	 */
	const TTL_HIT = 12 * HOUR_IN_SECONDS;

	/**
	 * How long a failed lookup is remembered before retrying.
	 */
	const TTL_MISS = 6 * HOUR_IN_SECONDS;

	/**
	 * User agent for outbound lookups.
	 */
	const USER_AGENT = 'Extroverting-Theme/0.3 (event hero resolver; +https://extroverting.com)';

	/**
	 * Resolve the hero image for an event.
	 *
	 * @param int $event_id Event post ID.
	 * @return array|'' Array { url, credit_url, credit_label, source }, or ''
	 *                  when nothing could be resolved (template falls back to
	 *                  the CSS placeholder).
	 */
	public static function hero( $event_id ) {
		$event_id = absint( $event_id );
		if ( ! $event_id ) {
			return '';
		}

		$cache_key = self::TRANSIENT_PREFIX . md5( (string) $event_id );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$resolved = self::resolve( $event_id );
		set_transient(
			$cache_key,
			$resolved,
			( '' === $resolved ) ? self::TTL_MISS : self::TTL_HIT
		);
		return $resolved;
	}

	/**
	 * Convenience: just the hero image URL, or '' when there is none.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	public static function hero_url( $event_id ) {
		$hero = self::hero( $event_id );
		return is_array( $hero ) ? (string) $hero['url'] : '';
	}

	/**
	 * Resolve the resolution chain once (uncached).
	 *
	 * @param int $event_id Event post ID.
	 * @return array|''
	 */
	private static function resolve( $event_id ) {
		// 1. Custom sources plug in here and win immediately.
		$filtered = apply_filters( 'extroverting_event_hero_url', '', $event_id );
		if ( is_string( $filtered ) && '' !== $filtered && esc_url_raw( $filtered ) ) {
			return array(
				'url'          => esc_url_raw( $filtered ),
				'credit_url'   => '',
				'credit_label' => '',
				'source'       => 'filter',
			);
		}

		// 2. Manual override meta (set in wp-admin or WP-CLI).
		$override = esc_url_raw( (string) get_post_meta( $event_id, 'ext_hero_url', true ) );
		if ( $override ) {
			return array(
				'url'          => $override,
				'credit_url'   => '',
				'credit_label' => '',
				'source'       => 'meta',
			);
		}

		// 3. Featured image, when the event has one.
		$thumb_id = get_post_thumbnail_id( $event_id );
		if ( $thumb_id ) {
			$thumb = wp_get_attachment_image_url( $thumb_id, 'large' );
			if ( ! $thumb ) {
				$thumb = wp_get_attachment_url( $thumb_id );
			}
			if ( $thumb ) {
				return array(
					'url'          => $thumb,
					'credit_url'   => '',
					'credit_label' => '',
					'source'       => 'thumbnail',
				);
			}
		}

		// 4. The event info source page (og:image / twitter:image).
		$source = self::source_page_data( get_post_meta( $event_id, 'ext_source_url', true ) );
		if ( $source && '' !== $source['image'] ) {
			return array(
				'url'          => $source['image'],
				'credit_url'   => '',
				'credit_label' => '',
				'source'       => 'source-page',
			);
		}

		// 5. No image on the source page — look for an image of the venue
		// itself (Wikimedia Commons, credited).
		$venue = self::venue_image( $event_id );
		if ( $venue ) {
			return $venue;
		}

		return '';
	}

	/**
	 * Fetch (cached) the Open Graph image + description of a source page.
	 *
	 * @param string $url Source page URL.
	 * @return array|null { image, description } or null when unfetchable.
	 */
	public static function source_page_data( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( ! $url ) {
			return null;
		}

		$cache_key = self::SOURCE_TRANSIENT_PREFIX . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$html = self::fetch( $url );
		$data = is_string( $html ) ? self::parse_page( $html ) : null;

		// A page that yields nothing at all counts as a miss so the resolver
		// retries on a shorter window instead of pinning an empty result.
		if ( $data && '' === $data['image'] && '' === $data['description'] ) {
			$data = null;
		}

		set_transient( $cache_key, $data, $data ? self::TTL_HIT : self::TTL_MISS );
		return $data;
	}

	/**
	 * Extract og:image / twitter:image and og:description from page HTML.
	 *
	 * @param string $html Raw HTML.
	 * @return array{image:string,description:string}
	 */
	public static function parse_page( $html ) {
		$image = self::meta_content( $html, array(
			'og:image',
			'og:image:secure_url',
			'twitter:image',
			'twitter:image:src',
		) );
		$description = self::meta_content( $html, array(
			'og:description',
			'description',
		) );

		return array(
			'image'       => esc_url_raw( html_entity_decode( $image, ENT_QUOTES ) ),
			'description' => trim( html_entity_decode( $description, ENT_QUOTES ) ),
		);
	}

	/**
	 * First non-empty content for any of the given meta names/properties.
	 *
	 * @param string   $html  Raw HTML.
	 * @param string[] $names Meta property/name values, in priority order.
	 * @return string
	 */
	private static function meta_content( $html, array $names ) {
		foreach ( $names as $name ) {
			$pattern = '#<meta[^>]+(?:property|name)=(["\'])' . preg_quote( $name, '#' ) . '\1[^>]*content=(["\'])([^>]*?)\2[^>]*>#is';
			$pattern_reversed = '#<meta[^>]+content=(["\'])([^>]*?)\1[^>]*(?:property|name)=(["\'])' . preg_quote( $name, '#' ) . '\3[^>]*>#is';
			if ( preg_match( $pattern, $html, $m ) ) {
				return trim( (string) $m[3] );
			}
			if ( preg_match( $pattern_reversed, $html, $m2 ) ) {
				return trim( (string) $m2[2] );
			}
		}
		return '';
	}

	/**
	 * Look for an image of the venue itself on Wikimedia Commons.
	 *
	 * Commons hosts photos of many museums, venues and buildings; results
	 * are free-license and always credited in the hero. Maps, logos, icons
	 * and small images are filtered out.
	 *
	 * @param int $event_id Event post ID.
	 * @return array|'' { url, credit_url, credit_label, source } or ''.
	 */
	public static function venue_image( $event_id ) {
		$venue = trim( (string) get_post_meta( $event_id, 'ext_venue', true ) );
		if ( '' === $venue ) {
			return '';
		}

		$city_id = absint( get_post_meta( $event_id, 'ext_city', true ) );
		$city    = $city_id ? trim( (string) get_the_title( $city_id ) ) : '';

		$cache_key = self::VENUE_TRANSIENT_PREFIX . md5( $venue . '|' . $city );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$resolved = '';
		$search   = trim( $venue . ( $city ? ' ' . $city : '' ) );
		$api      = add_query_arg(
			array(
				'action'      => 'query',
				'format'      => 'json',
				'formatversion' => '2',
				'generator'   => 'search',
				'gsrsearch'   => $search,
				'gsrnamespace' => 6,
				'gsrlimit'    => 10,
				'prop'        => 'imageinfo',
				'iiprop'      => 'url|mime|size|extmetadata',
				'iiurlwidth'  => 1200,
			),
			'https://commons.wikimedia.org/w/api.php'
		);

		$raw = self::fetch( $api );
		if ( is_string( $raw ) ) {
			$resolved = self::parse_commons( $raw );
		}

		set_transient( $cache_key, $resolved, ( '' === $resolved ) ? DAY_IN_SECONDS : 7 * DAY_IN_SECONDS );
		return $resolved;
	}

	/**
	 * Pick the best usable image from a Commons API response.
	 *
	 * @param string $raw JSON body.
	 * @return array|'' { url, credit_url, credit_label, source } or ''.
	 */
	public static function parse_commons( $raw ) {
		$json = json_decode( (string) $raw, true );
		if ( empty( $json['query']['pages'] ) || ! is_array( $json['query']['pages'] ) ) {
			return '';
		}

		foreach ( $json['query']['pages'] as $page ) {
			$info = $page['imageinfo'][0] ?? null;
			if ( ! $info ) {
				continue;
			}

			$mime = (string) ( $info['mime'] ?? '' );
			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				continue; // Skip SVG diagrams, GIFs, TIFF scans.
			}

			$width = (int) ( $info['width'] ?? 0 );
			if ( $width < 800 ) {
				continue; // Skip thumbnails and small icons.
			}

			$title = (string) ( $page['title'] ?? '' );
			if ( preg_match( '/map|logo|icon|flag|coat[_ -]of[_ -]arms|locator|diagram|chart|plan\b/i', $title ) ) {
				continue; // Maps and emblems are not venue photos.
			}

			$thumb = (string) ( $info['thumburl'] ?? '' );
			if ( ! $thumb ) {
				$thumb = (string) ( $info['url'] ?? '' );
			}
			if ( ! $thumb ) {
				continue;
			}

			$meta    = is_array( $info['extmetadata'] ?? null ) ? $info['extmetadata'] : array();
			$artist  = isset( $meta['Artist']['value'] ) ? trim( wp_strip_all_tags( (string) $meta['Artist']['value'] ) ) : '';
			$license = isset( $meta['LicenseShortName']['value'] ) ? trim( (string) $meta['LicenseShortName']['value'] ) : '';

			$credit_parts = array_filter( array( $artist, $license ) );
			$credit_label = $credit_parts
				? implode( ', ', $credit_parts ) . ' — via Wikimedia Commons'
				: 'via Wikimedia Commons';

			return array(
				'url'          => esc_url_raw( $thumb ),
				'credit_url'   => esc_url_raw( (string) ( $info['descriptionurl'] ?? '' ) ),
				'credit_label' => $credit_label,
				'source'       => 'venue',
			);
		}

		return '';
	}

	/**
	 * The "About this event" body.
	 *
	 * Returns the imported description; when the event has none, falls back
	 * to the description on the source listing page.
	 *
	 * @param int $event_id Event post ID.
	 * @return string Safe HTML (echo directly).
	 */
	public static function about( $event_id ) {
		$event_id = absint( $event_id );
		if ( ! $event_id ) {
			return '';
		}

		$content = trim( (string) get_post_field( 'post_content', $event_id ) );
		if ( '' !== $content ) {
			// The canonical renderer for stored post content (wpautop etc.).
			return apply_filters( 'the_content', $content );
		}

		$source = self::source_page_data( get_post_meta( $event_id, 'ext_source_url', true ) );
		if ( $source && '' !== $source['description'] ) {
			return '<p>' . esc_html( $source['description'] ) . '</p>';
		}

		return '';
	}

	/**
	 * "View the original listing" link data for the About block.
	 *
	 * @param int $event_id Event post ID.
	 * @return array|'' { url, label } or ''.
	 */
	public static function source_link( $event_id ) {
		$url = esc_url_raw( (string) get_post_meta( $event_id, 'ext_source_url', true ) );
		if ( ! $url ) {
			return '';
		}

		$label = trim( (string) get_post_meta( $event_id, 'ext_source', true ) );
		if ( '' === $label ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			$label = is_string( $host ) && '' !== $host ? preg_replace( '/^www\./', '', $host ) : __( 'the original listing', 'extroverting' );
		}

		return array( 'url' => $url, 'label' => $label );
	}

	/**
	 * Fetch a URL, safe and short-timeout; returns the body or null.
	 *
	 * @param string $url URL to fetch.
	 * @return string|null
	 */
	private static function fetch( $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 8,
				'user-agent' => self::USER_AGENT,
				'redirection' => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		return ( '' !== $body ) ? $body : null;
	}
}