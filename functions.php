<?php
/**
 * Extroverting block theme functions.
 *
 * @package Extroverting
 */

defined( 'ABSPATH' ) || exit;

require_once get_theme_file_path( 'inc/quiz/class-quiz-normalizer.php' );
require_once get_theme_file_path( 'inc/quiz/class-quiz-source.php' );
require_once get_theme_file_path( 'inc/quiz/class-quiz-blocks.php' );
require_once get_theme_file_path( 'inc/class-event-hero.php' );
require_once get_theme_file_path( 'inc/class-cache-schedule.php' );

\Extroverting\Theme\Quiz\Blocks::init();
\Extroverting\Theme\Cache_Schedule::init();

/**
 * Theme setup.
 *
 * @return void
 */
function extroverting_setup() {
	load_theme_textdomain( 'extroverting', get_theme_file_path( '/languages' ) );
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'navigation-widgets' );
	add_theme_support( 'custom-logo', array(
		'height'      => 60,
		'width'       => 240,
		'flex-height' => true,
		'flex-width'  => true,
	) );
}
add_action( 'after_setup_theme', 'extroverting_setup' );

/**
 * Enqueue theme scripts (frontend interactivity for save/OTP/quiz shell).
 *
 * Modern: front.js primarily talks to the REST API (extroverting/v1) with
 * X-WP-Nonce. The legacy admin-ajax path is retained for backwards compat
 * but all new features should use `wp.apiFetch` against the REST routes.
 *
 * @return void
 */
function extroverting_assets() {
	// Tie the style version to the on-disk mtime so cache busters on every
	// edit. Fall back to a sane default when the file isn't readable.
	$css_file = get_theme_file_path( 'style-components.css' );
	$css_ver  = ( $css_file && is_readable( $css_file ) ) ? (string) filemtime( $css_file ) : '0.1.0';
	wp_enqueue_style(
		'extroverting-components',
		get_theme_file_uri( 'style-components.css' ),
		array(),
		$css_ver
	);
	// Editor style: share the same component CSS in the Site Editor canvas.
	add_editor_style( 'style-components.css' );

	// Load the quiz block styles in the head on quiz surfaces so the server-
	// rendered template doesn't flash unstyled before render_block enqueues it.
	$is_quiz_surface = ( is_page() && has_block( 'extroverting/quiz', get_post() ) )
		|| ( is_singular( 'ext_event' ) && get_query_var( 'ext_quiz' ) );
	if ( $is_quiz_surface ) {
		wp_enqueue_style( 'extroverting-quiz-style' );
	}

	wp_enqueue_script(
		'extroverting-front',
		get_theme_file_uri( 'assets/js/front.js' ),
		array(),
		'0.1.1',
		true
	);
	wp_localize_script( 'extroverting-front', 'EXT', array(
		'ajax'      => admin_url( 'admin-ajax.php' ),
		'nonce'     => wp_create_nonce( 'extroverting_auth' ),
		'rest'      => esc_url_raw( rest_url( 'extroverting/v1/' ) ),
		'r2Rest'    => esc_url_raw( rest_url( 'extroverting-r2/v1/' ) ),
		'restNonce' => wp_create_nonce( 'wp_rest' ),
		'logged_in' => is_user_logged_in(),
		// Expose for `wp.apiFetch` nonce middleware.
		'root'      => esc_url_raw( rest_url() ),
	) );

	// Provide the REST nonce to wp.apiFetch (so blocks can use it without manual headers).
	wp_add_inline_script(
		'extroverting-front',
		'wp.apiFetch && wp.apiFetch.use && wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( "' . wp_create_nonce( 'wp_rest' ) . '" ) );',
		'after'
	);
}
add_action( 'wp_enqueue_scripts', 'extroverting_assets' );

/**
 * Block/editor enhancements — patterns, block styles, bindings docs.
 *
 * @return void
 */
function extroverting_blocks_init() {
	// Pattern category for City / Event layouts built from core blocks + bindings.
	if ( function_exists( 'register_block_pattern_category' ) ) {
		register_block_pattern_category( 'extroverting', array( 'label' => __( 'Extroverting', 'extroverting' ) ) );
	}

	// Block styles: give editors semantic variants for Group and Buttons that match the design system.
	if ( function_exists( 'register_block_style' ) ) {
		register_block_style( 'core/group', array(
			'name'  => 'ext-card',
			'label' => __( 'Card (Extroverting)', 'extroverting' ),
		) );
		register_block_style( 'core/group', array(
			'name'  => 'ext-hero',
			'label' => __( 'Hero (Extroverting)', 'extroverting' ),
		) );
		register_block_style( 'core/button', array(
			'name'  => 'ext-pill',
			'label' => __( 'Pill (Extroverting)', 'extroverting' ),
		) );
	}

	// Expose the current city ID to the block editor (used by the event-query pattern's preview).
	add_action( 'enqueue_block_editor_assets', function () {
		$city = extroverting_current_city();
		wp_add_inline_script(
			'wp-edit-post',
			'window.ExtCity = ' . wp_json_encode( array(
				'id'   => $city ? $city->ID : 0,
				'slug' => $city ? $city->post_name : '',
				'name' => $city ? $city->post_title : '',
			) ) . ';',
			'before'
		);
	} );
}
add_action( 'init', 'extroverting_blocks_init' );

/* ------------------------------------------------------------------ */
/* Template routing                                                    */
/*                                                                     */
/* This theme's templates are .html fragments containing raw PHP. The  */
/* block-theme canvas renders templates via do_blocks(), which does    */
/* NOT execute that PHP (it prints the source as literal text). So     */
/* every frontend request is routed through template-shell.php, which  */
/* includes the resolved fragment as PHP inside a full document.       */
/* ------------------------------------------------------------------ */

/**
 * Resolve the raw-PHP fragment for non-plugin pages.
 *
 * Runs after the extroverting-core plugin's template_include filter (20),
 * which already resolves dashboard/account/legal/age-gate fragments.
 *
 * @param string $template Current template path.
 * @return string
 */
function extroverting_resolve_fragment( $template ) {
	$template = (string) $template;

	// Any template already pointing at a theme file was chosen upstream
	// (plugin-mapped dashboard/account/legal pages, the age gate) — keep it.
	if ( false !== strpos( $template, '/templates/' ) ) {
		return $template;
	}

	if ( get_query_var( 'ext_quiz' ) && is_singular( 'ext_event' ) ) {
		$candidate = 'templates/ext-event-quiz.html';
	} elseif ( is_singular( 'ext_event' ) ) {
		$candidate = 'templates/single-ext_event.html';
	} elseif ( is_singular( 'ext_city' ) ) {
		$candidate = 'templates/single-ext_city.html';
	} elseif ( is_page() ) {
		$candidate = 'templates/page.html';
	} else {
		$candidate = 'templates/index.html';
	}

	$file = locate_template( array( $candidate ) );
	return $file ? $file : $template;
}
add_filter( 'template_include', 'extroverting_resolve_fragment', 30 );

/**
 * Add the event quiz sub-route: /events/{slug}/quiz.
 *
 * Kept in the theme rather than the core plugin because the destination is a
 * block template. Flush rewrite rules once after deploying.
 *
 * @return void
 */
function extroverting_quiz_routes() {
	add_rewrite_rule( '^events/([^/]+)/quiz/?$', 'index.php?ext_event=$matches[1]&ext_quiz=1', 'top' );
}
add_action( 'init', 'extroverting_quiz_routes', 20 );

/**
 * Make the sub-route query var public.
 *
 * @param string[] $vars Query vars.
 * @return string[]
 */
function extroverting_quiz_query_vars( $vars ) {
	$vars[] = 'ext_quiz';
	return $vars;
}
add_filter( 'query_vars', 'extroverting_quiz_query_vars' );

/**
 * Gate pages that contain a Quiz block.
 *
 * The core age gate covers events and posts. A page with a quiz needs the
 * same overlay, so run before the core gate (25) and reuse its template and
 * AJAX confirmation flow.
 *
 * @param string $template Current template path.
 * @return string
 */
function extroverting_gate_quiz_page( $template ) {
	if ( ! is_page() ) {
		return $template;
	}

	$post = get_post();
	if ( ! $post || ! has_block( 'extroverting/quiz', $post ) ) {
		return $template;
	}

	$verified = ! empty( $_COOKIE['ext_age_verified'] ) && (int) $_COOKIE['ext_age_verified'] > time() - 86400 * 14;
	if ( $verified ) {
		return $template;
	}

	$gate = locate_template( array( 'templates/ext-age-gate.html' ) );
	return $gate ? $gate : $template;
}
add_filter( 'template_include', 'extroverting_gate_quiz_page', 24 );

/**
 * Keep quiz surfaces out of the page cache.
 *
 * The age gate is cookie-based; a shared cache cannot vary on it. Covers both
 * pages containing the block and the event quiz sub-route. Runs early on
 * template_redirect so LiteSpeed sees the flag before it decides.
 *
 * @return void
 */
function extroverting_quiz_page_cache_control() {
	$is_quiz_page  = is_page() && has_block( 'extroverting/quiz', get_post() );
	$is_quiz_route = is_singular( 'ext_event' ) && get_query_var( 'ext_quiz' );

	if ( ! $is_quiz_page && ! $is_quiz_route ) {
		return;
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'quiz page' );

	if ( ! headers_sent() ) {
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-store' );
	}
}
add_action( 'template_redirect', 'extroverting_quiz_page_cache_control', 1 );

/**
 * Wrap any resolved fragment in the full-document shell.
 *
 * @param string $template Current template path.
 * @return string
 */
function extroverting_wrap_in_shell( $template ) {
	$template = (string) $template;
	if ( false !== strpos( $template, '/templates/' ) && is_file( $template ) ) {
		$shell = locate_template( array( 'template-shell.php' ) );
		if ( $shell ) {
			$GLOBALS['extroverting_fragment'] = $template;
			return $shell;
		}
	}
	return $template;
}
add_filter( 'template_include', 'extroverting_wrap_in_shell', 40 );

/**
 * Helper: current city (config default, or ?city= override).
 *
 * Resolution order:
 *   1. ?city=<id> URL override (any city post).
 *   2. The configured default city (option `extroverting_default_city`, set in
 *      wp-admin → Settings → Extroverting, or via
 *      `wp option update extroverting_default_city <post ID>`). Used when the
 *      city exists and is launched.
 *   3. Fallback: the launched city with the most upcoming events, so the
 *      homepage always shows a city with data when data exists.
 *
 * @return \WP_Post|null
 */
function extroverting_current_city() {
	$override = isset( $_GET['city'] ) ? absint( $_GET['city'] ) : 0;
	if ( $override ) {
		$city = get_post( $override );
		if ( $city && 'ext_city' === $city->post_type ) {
			return $city;
		}
	}

	// Configured default (hero) city — a post ID option, not a hardcoded slug.
	$default_id = absint( get_option( 'extroverting_default_city', 0 ) );
	if ( $default_id ) {
		$default = get_post( $default_id );
		if ( $default && 'ext_city' === $default->post_type && (bool) get_post_meta( $default->ID, 'ext_launched', true ) ) {
			return $default;
		}
	}

	// Fallback: launched city with most upcoming events.
	$launched = get_posts( array(
		'post_type'      => 'ext_city',
		'posts_per_page' => -1,
		'meta_query'     => array(
			array( 'key' => 'ext_launched', 'value' => 1 ),
		),
		'no_found_rows'  => true,
	) );
	if ( empty( $launched ) ) {
		// No launched city — return any city.
		$q = new WP_Query( array(
			'post_type'      => 'ext_city',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		) );
		$city = $q->posts ? $q->posts[0] : null;
		wp_reset_postdata();
		return $city;
	}

	// If the plugin's event query helper exists, use it to count upcoming
	// events per city and pick the max. Otherwise return the first launched
	// city by title.
	if ( class_exists( 'Extroverting\Core\Ext_Content_Types' ) ) {
		$best       = $launched[0];
		$best_count = -1;
		foreach ( $launched as $c ) {
			$qc = \Extroverting\Core\Ext_Content_Types::event_query( $c->ID, '', 'soon', true );
			$n  = count( $qc->posts );
			if ( $n > $best_count ) {
				$best       = $c;
				$best_count = $n;
			}
		}
		return $best;
	}

	// Fallback: alphabetical first launched city.
	usort( $launched, function ( $a, $b ) {
		return strcmp( $a->post_title, $b->post_title );
	} );
	return $launched[0];
}

/**
 * Upcoming-event counts for every city in ONE query (with a short transient
 * cache). The homepage city-chip section used to run one meta query per city
 * (N+1: ~100+ queries per page load); this collapses that to a single
 * grouped query plus a transient hit.
 *
 * @param int $ttl Transient TTL in seconds. Default 10 minutes.
 * @return array<int,int> Map of city post ID => upcoming event count.
 */
function extroverting_city_event_counts( $ttl = 600 ) {
	$cache_key = 'ext_city_event_counts_v1';
	$counts    = get_transient( $cache_key );
	if ( is_array( $counts ) ) {
		return $counts;
	}

	global $wpdb;
	$today  = current_time( 'Y-m-d' );
	$counts = array();
	$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT pm_city.meta_value AS city_id, COUNT( DISTINCT p.ID ) AS n "
		. "FROM {$wpdb->postmeta} pm_date "
		. "INNER JOIN {$wpdb->postmeta} pm_city ON pm_city.post_id = pm_date.post_id AND pm_city.meta_key = 'ext_city' "
		. "INNER JOIN {$wpdb->posts} p ON p.ID = pm_date.post_id AND p.post_type = 'ext_event' AND p.post_status = 'publish' "
		. "WHERE pm_date.meta_key = 'ext_date' AND pm_date.meta_value >= %s "
		. "GROUP BY pm_city.meta_value",
			$today
		)
	);
	foreach ( (array) $rows as $row ) {
		$counts[ absint( $row->city_id ) ] = (int) $row->n;
	}

	set_transient( $cache_key, $counts, $ttl );
	return $counts;
}

/**
 * Helper: format an event's date meta into display parts.
 *
 * @param int $event_id Event post ID.
 * @return array{dow:string, num:string, mon:string, full:string}
 */
function extroverting_event_date_parts( $event_id ) {
	$date = get_post_meta( $event_id, 'ext_date', true );
	if ( ! $date ) {
		return array( 'dow' => '', 'num' => '', 'mon' => '', 'full' => '' );
	}
	$ts  = strtotime( $date . ' 12:00:00' );
	return array(
		'dow'  => date_i18n( 'D', $ts ),
		'num'  => (string) (int) date_i18n( 'j', $ts ),
		'mon'  => date_i18n( 'M', $ts ),
		'full' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ),
	);
}

/**
 * Helper: is the event today?
 *
 * `ext_date` is a local calendar date (the site timezone), so compare
 * against the site's local today — not UTC, which is already "tomorrow"
 * from 4:00 PM Pacific on.
 *
 * @param int    $event_id Event.
 * @param string $today    Override for the comparison date (Y-m-d); defaults
 *                         to the site's local today. For tests.
 * @return bool
 */
function extroverting_event_is_today( $event_id, $today = null ) {
	$date = get_post_meta( $event_id, 'ext_date', true );
	return $date && $date === ( null !== $today ? (string) $today : current_time( 'Y-m-d' ) );
}

/**
 * Helper: event has ended?
 *
 * @param int    $event_id Event.
 * @param string $today    Override for the comparison date (Y-m-d); defaults
 *                         to the site's local today. For tests.
 * @return bool
 */
function extroverting_event_ended( $event_id, $today = null ) {
	$date = get_post_meta( $event_id, 'ext_date', true );
	return $date && $date < ( null !== $today ? (string) $today : current_time( 'Y-m-d' ) );
}

/**
 * Query a city's events with the site's LOCAL today as the cutoff.
 *
 * Mirrors \Extroverting\Core\Ext_Content_Types::event_query() but compares
 * `ext_date` against current_time('Y-m-d') instead of gmdate('Y-m-d'), so
 * an event stays listed through its local day (the UTC date rolls over at
 * 4:00 PM Pacific and would drop same-day events from every index page in
 * the evening).
 *
 * @param int    $city_id       City post ID.
 * @param string $cat           Tax term slug (empty = all).
 * @param string $sort          soon | az | added.
 * @param bool   $upcoming_only Only events on/after the local today.
 * @param int    $limit         Cap on returned posts (0 = all).
 * @param int    $exclude_id    Event post ID to leave out (0 = keep all).
 * @return \WP_Query
 */
function extroverting_theme_event_query( $city_id, $cat = '', $sort = 'soon', $upcoming_only = true, $limit = 0, $exclude_id = 0 ) {
	$args = array(
		'post_type'           => 'ext_event',
		'post_status'         => 'publish',
		'posts_per_page'      => $limit > 0 ? $limit : -1,
		'meta_query'          => array(
			array(
				'key'   => 'ext_city',
				'value' => (string) $city_id,
			),
		),
		'no_found_rows'       => true,
	);

	if ( '' !== $cat ) {
		$args['tax_query'] = array(
			array(
				'taxonomy' => 'ext_event_cat',
				'field'    => 'slug',
				'terms'    => $cat,
			),
		);
	}

	if ( 'az' === $sort ) {
		$args['orderby'] = 'title';
		$args['order']   = 'ASC';
	} elseif ( 'added' === $sort ) {
		$args['orderby'] = 'date';
		$args['order']   = 'DESC';
	} else {
		$args['meta_key'] = 'ext_date';
		$args['orderby']  = 'meta_value';
		$args['order']    = 'ASC';
	}

	if ( $upcoming_only ) {
		$args['meta_query'][] = array(
			'key'     => 'ext_date',
			'value'   => current_time( 'Y-m-d' ),
			'compare' => '>=',
		);
	}

	if ( $exclude_id > 0 ) {
		$args['post__not_in'] = array( (int) $exclude_id );
	}

	return new WP_Query( $args );
}

/**
 * Representative icon for a category tag.
 *
 * Returns an inline SVG string in the tag's own color (currentColor),
 * sized to match the surrounding label. Unknown categories fall back
 * to a generic tag glyph. Icons are monochrome-friendly so the same
 * markup works in tags, chips, and hero pills.
 *
 * @param string $slug Term slug (e.g. 'music', 'food-drink').
 * @param int    $size Icon size in px.
 * @return string Inline SVG markup.
 */
function extroverting_tag_icon( $slug, $size = 14 ) {
	$s = strtolower( trim( (string) $slug ) );
	if ( in_array( $s, array( 'food', 'food-drink', 'food--drink' ), true ) ) {
		$s = 'food';
	}
	$icons = array(
		'community' => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19.5c.9-3.2 3.2-4.8 5.5-4.8s4.6 1.6 5.5 4.8"/><circle cx="17.2" cy="9.2" r="2.4"/><path d="M16.2 14.6c2.5.2 4.2 1.5 4.9 4.3"/>',
		'music'      => '<path d="M9.2 17.5V6.2l10-2.3v11.1"/><circle cx="6.4" cy="17.6" r="2.6"/><circle cx="16.2" cy="15.1" r="2.6"/>',
		'arts'       => '<path d="M12 3.4a8.6 8.6 0 1 0 0 17.2c1.4 0 2-1.1 1.6-2.1-.4-1.2.6-2.2 2-2.2h2.6a3.9 3.9 0 0 0 3.9-3.9c0-4.3-4.6-8.2-9.1-9.2z"/><circle cx="8.2" cy="10.4" r="1"/><circle cx="12.3" cy="8.1" r="1"/><circle cx="16" cy="10.6" r="1"/>',
		'sports'     => '<circle cx="12" cy="12" r="8.4"/><path d="M4.6 9.6c5 1.7 9.8 1.7 14.8 0M12 3.6c-4.6 4.7-4.6 12.1 0 16.8M12 3.6c4.6 4.7 4.6 12.1 0 16.8"/>',
		'food'       => '<path d="M6.5 3v7.6a3 3 0 0 0 6 0V3"/><path d="M9.5 13.6V21"/><ellipse cx="16.5" cy="5.6" rx="2.3" ry="3.1"/><path d="M16.5 9V21"/>',
		'family'     => '<path d="M3.5 10.8 12 4l8.5 6.8"/><path d="M5.5 9.6V20.5h13V9.6"/><path d="M12 17.6c-1.7-1.5-2.8-2.5-2.8-3.8a1.7 1.7 0 0 1 2.8-1.1 1.7 1.7 0 0 1 2.8 1.1c0 1.3-1.1 2.3-2.8 3.8z" fill="currentColor" stroke="none"/>',
		'workshops'  => '<path d="M19.2 7.6a4.6 4.6 0 0 1-6.6 4.6L4.2 19.6l1.6 1.6 7.4-8.4a4.6 4.6 0 0 1 4.6-6.6l-3.6 3.6-4.4-1.6-1.6-4.4z"/>',
		'nightlife'  => '<path d="M20.2 13.4A8.4 8.4 0 0 1 10.6 3.8 6.4 6.4 0 1 0 20.2 13.4z"/><path d="M17.6 2.6l.9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9z"/>',
		'outdoors'   => '<path d="M12 3l5.2 6.8h-3.2l4.4 6.2H5.6l4.4-6.2H6.8z"/><path d="M12 16v4.6"/>',
		'history'    => '<circle cx="12" cy="12" r="8.4"/><path d="M12 7.4V12l3.2 2.1"/>',
		'holiday'    => '<rect x="4.2" y="9.2" width="15.6" height="10.6" rx="1.6"/><path d="M3.2 6.4h17.6V9.2H3.2z"/><path d="M12 6.4V19.8"/><path d="M12 6.4C10.7 4.5 7.8 4.1 7.3 5.6c-.5 1.2 1.5 1.6 3.3 1zM12 6.4c1.3-1.9 4.2-2.3 4.7-.8.5 1.2-1.5 1.6-3.3 1z"/>',
		'volunteer'  => '<path d="M12 20.4S4.2 15.6 4.2 9.9A4.5 4.5 0 0 1 8.7 6.2c1.5 0 2.6 1 3.3 2.2.7-1.2 1.8-2.2 3.3-2.2a4.5 4.5 0 0 1 4.5 3.7c0 5.7-7.8 10.5-7.8 10.5z"/>',
		'default'    => '<path d="M3.4 5.4a2 2 0 0 1 2-2h6.2a3 3 0 0 1 2.1.9l7.3 7.3a2 2 0 0 1 0 2.8l-6.4 6.4a2 2 0 0 1-2.8 0l-7.3-7.3a3 3 0 0 1-.9-2.1z"/><circle cx="8" cy="7.4" r="1.5"/>',
	);
	$inner = $icons[ $s ] ?? $icons['default'];
	return '<svg class="ext-tag-icon" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
}

/**
 * Render an event row (list item) — the atomic calendar component.
 *
 * @param int    $event_id  Event post ID.
 * @param bool   $show_hood Show neighborhood in meta.
 * @param bool   $show_save Show the save star.
 * @return void
 */
function extroverting_render_event_row( $event_id, $show_hood = false, $show_save = true ) {
	$p      = get_post( $event_id );
	if ( ! $p ) {
		return;
	}
	$parts   = extroverting_event_date_parts( $event_id );
	$time    = get_post_meta( $event_id, 'ext_time', true );
	$venue   = get_post_meta( $event_id, 'ext_venue', true );
	$hood    = get_post_meta( $event_id, 'ext_hood', true );
	$cat     = get_the_terms( $event_id, 'ext_event_cat' );
	$has_q   = (bool) get_post_meta( $event_id, 'ext_has_quiz', true );
	$today   = extroverting_event_is_today( $event_id );
	$saved   = function_exists( 'extroverting_core' )
		&& extroverting_core()->get( 'saves' )->is_saved( $event_id );

	?>
	<div class="ext-eventrow <?php echo $today ? 'tonight' : ''; ?>" data-event="<?php echo esc_attr( (string) $event_id ); ?>">
		<div class="ext-datecell">
			<div class="dow"><?php echo esc_html( $parts['dow'] ); ?></div>
			<div class="num"><?php echo esc_html( $parts['num'] ); ?></div>
			<div class="mon"><?php echo esc_html( $parts['mon'] ); ?></div>
		</div>
		<div class="ext-eventbody">
			<h3><a href="<?php echo esc_url( get_permalink( $event_id ) ); ?>"><?php echo esc_html( get_the_title( $event_id ) ); ?></a></h3>
			<div class="ext-evmeta">
				<?php if ( $time ) : ?><span class="t ext-tnum"><?php echo esc_html( $time ); ?></span><?php endif; ?>
				<span><?php echo esc_html( $venue . ( ( $show_hood && $hood ) ? ' · ' . $hood : '' ) ); ?></span>
			</div>
			<div class="ext-evtags">
				<?php if ( $cat ) : ?><span class="ext-tag ext-tag-cat"><?php echo extroverting_tag_icon( $cat[0]->slug ); ?><?php echo esc_html( $cat[0]->name ); ?></span><?php endif; ?>
				<?php if ( $today ) : ?><span class="ext-tag ext-tag-tonight"><span class="ext-dot-live" aria-hidden="true"></span> <?php esc_html_e( 'Tonight', 'extroverting' ); ?></span><?php endif; ?>
				<?php if ( $has_q ) : ?><span class="ext-tag ext-tag-quiz"><?php esc_html_e( 'Quiz available', 'extroverting' ); ?></span><?php endif; ?>
			</div>
		</div>
		<div class="ext-eventactions">
			<?php if ( $show_save ) : ?>
				<button class="ext-star <?php echo $saved ? 'saved' : ''; ?>" data-save="<?php echo esc_attr( (string) $event_id ); ?>" aria-pressed="<?php echo $saved ? 'true' : 'false'; ?>">
					<span class="glyph" aria-hidden="true"><?php echo $saved ? '★' : '☆'; ?></span>
					<?php echo $saved ? esc_html__( 'Saved', 'extroverting' ) : esc_html__( 'Save', 'extroverting' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * Render the empty/skeleton/error states from DESIGN.md §7.
 *
 * @param string $kind   empty-user | empty-none | loading | error-service | error-external.
 * @param array  $args   { noun, city, service }.
 * @return void
 */
function extroverting_render_state( $kind, $args = array() ) {
	$noun    = $args['noun'] ?? __( 'events', 'extroverting' );
	$city    = $args['city'] ?? '';
	$service = $args['service'] ?? '[service]';

	switch ( $kind ) {
		case 'loading':
			echo '<div class="ext-skel" style="height:74px;margin-bottom:10px" aria-hidden="true"></div>';
			echo '<div class="ext-skel" style="height:74px;margin-bottom:10px" aria-hidden="true"></div>';
			echo '<div class="ext-skel" style="height:74px" aria-hidden="true"></div>';
			echo '<p class="ext-loading-note" role="status">' . sprintf(
				/* translators: %s: noun */
				esc_html__( 'Loading %s…', 'extroverting' ),
				esc_html( $noun )
			) . '</p>';
			break;
		case 'empty-user':
			printf(
				'<div class="ext-empty"><div class="em-ic" aria-hidden="true">📅</div><h3>%s</h3><p>%s</p><a class="ext-btn ext-btn-primary" href="%s">%s</a></div>',
				esc_html__( 'You haven\'t saved any events yet.', 'extroverting' ),
				esc_html__( 'Tap the star on any event to keep it here.', 'extroverting' ),
				esc_url( home_url( '/cities/' . ( $city ? $city : '' ) ) ),
				esc_html__( 'Browse events', 'extroverting' )
			);
			break;
		case 'empty-none':
			printf(
				'<div class="ext-empty"><div class="em-ic" aria-hidden="true">📅</div><h3>%s</h3><p>%s</p></div>',
				sprintf(
					/* translators: %s: city name */
					esc_html__( 'No %s events yet — new ones land regularly.', 'extroverting' ),
					esc_html( $city ? $city : '' )
				),
				esc_html__( 'Check back soon.', 'extroverting' )
			);
			break;
		case 'error-service':
			printf(
				'<div class="ext-empty"><div class="em-ic" aria-hidden="true">⚠️</div><h3>%s</h3><p>%s</p><button class="ext-btn ext-btn-ink" data-retry>%s</button></div>',
				sprintf(
					/* translators: %s: noun (quiz/results) */
					esc_html__( "We couldn't reach the %s service.", 'extroverting' ),
					esc_html( $noun )
				),
				esc_html__( 'Your data is safe — try again.', 'extroverting' ),
				esc_html__( 'Try again', 'extroverting' )
			);
			break;
		case 'error-external':
			printf(
				'<div class="ext-empty"><div class="em-ic" aria-hidden="true">⚠️</div><h3>%s</h3><p>%s</p><button class="ext-btn ext-btn-ghost" data-retry>%s</button></div>',
				sprintf(
					/* translators: %s: noun (retailers/map/directions) */
					esc_html__( 'We couldn\'t load %s.', 'extroverting' ),
					esc_html( $noun )
				),
				sprintf(
					/* translators: %s: service name */
					esc_html__( 'Try again in a moment. (%s)', 'extroverting' ),
					esc_html( $service )
				),
				esc_html__( 'Retry', 'extroverting' )
			);
			break;
	}
}

/**
 * Short newsletter signup for event detail pages.
 *
 * Shows a MailPoet form with one pre-checked checkbox for the current event
 * category's list, plus a link to the full multi-list signup page.
 *
 * @return string
 */
function extroverting_event_signup_shortcode() {
	$event_id = get_queried_object_id();
	if ( ! $event_id || 'ext_event' !== get_post_type( $event_id ) ) {
		return '';
	}

	// MailPoet form IDs created for the event-page short forms.
	$form_by_slug = array(
		'community' => 5,
		'music'     => 6,
		'arts'      => 7,
		'comedy'    => 8,
		'other'     => 9,
	);

	$terms = get_the_terms( $event_id, 'ext_event_cat' );
	$term  = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
	$slug  = $term ? $term->slug : '';
	$label = $term ? $term->name : __( 'West LA', 'extroverting' );
	$form_id = isset( $form_by_slug[ $slug ] ) ? $form_by_slug[ $slug ] : 10;

	$signup_page = get_page_by_path( 'newsletter-signup' );
	$signup_url  = $signup_page ? get_permalink( $signup_page ) : home_url( '/newsletter-signup/' );

	$html  = '<div class="ext-block ext-event-signup">';
	$html .= '<h2>' . esc_html( sprintf( /* translators: %s: event category */ __( 'Get %s events in your inbox', 'extroverting' ), $label ) ) . '</h2>';
	$html .= '<p class="ext-signup-blurb">' . esc_html__( 'One email with upcoming events. Unsubscribe anytime.', 'extroverting' ) . '</p>';
	$html .= do_shortcode( '[mailpoet_form id="' . (int) $form_id . '"]' );
	$html .= '<p class="ext-signup-more"><a href="' . esc_url( $signup_url ) . '">' . esc_html__( 'Want more? Choose additional newsletters →', 'extroverting' ) . '</a></p>';
	$html .= '</div>';

	return $html;
}
add_shortcode( 'extroverting_event_signup', 'extroverting_event_signup_shortcode' );
