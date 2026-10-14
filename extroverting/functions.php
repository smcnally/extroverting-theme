<?php
/**
 * Extroverting block theme functions.
 *
 * @package Extroverting
 */

defined( 'ABSPATH' ) || exit;

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
 * @return void
 */
function extroverting_assets() {
	wp_enqueue_style(
		'extroverting-components',
		get_theme_file_uri( 'style-components.css' ),
		array(),
		'0.1.0'
	);
	wp_enqueue_script(
		'extroverting-front',
		get_theme_file_uri( 'assets/js/front.js' ),
		array(),
		'0.1.0',
		true
	);
	wp_localize_script( 'extroverting-front', 'EXT', array(
		'ajax'    => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'extroverting_auth' ),
		'rest'    => esc_url_raw( rest_url( 'extroverting/v1/' ) ),
		'restNonce' => wp_create_nonce( 'wp_rest' ),
		'logged_in' => is_user_logged_in(),
	) );
}
add_action( 'wp_enqueue_scripts', 'extroverting_assets' );

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

	// Plugin-mapped pages are already resolved.
	if ( false !== strpos( $template, '/templates/ext-' ) ) {
		return $template;
	}

	if ( is_singular( 'ext_event' ) ) {
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
 * Helper: current city (first launched city, or ?city= override).
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
	$q = new WP_Query( array(
		'post_type'      => 'ext_city',
		'posts_per_page' => 1,
		'meta_key'       => 'ext_launched',
		'meta_value'     => 1,
		'no_found_rows'  => true,
	) );
	$city = $q->posts ? $q->posts[0] : null;
	wp_reset_postdata();
	return $city;
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
 * @param int $event_id Event.
 * @return bool
 */
function extroverting_event_is_today( $event_id ) {
	$date = get_post_meta( $event_id, 'ext_date', true );
	return $date && $date === gmdate( 'Y-m-d' );
}

/**
 * Helper: event has ended?
 *
 * @param int $event_id Event.
 * @return bool
 */
function extroverting_event_ended( $event_id ) {
	$date = get_post_meta( $event_id, 'ext_date', true );
	return $date && $date < gmdate( 'Y-m-d' );
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
				<?php if ( $cat ) : ?><span class="ext-tag ext-tag-cat"><?php echo esc_html( $cat[0]->name ); ?></span><?php endif; ?>
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
