<?php
/**
 * Resolve the quiz definition the blocks render.
 *
 * The blocks never author quiz text and never score. They ask the event's
 * provider through the existing `extroverting/v1/quiz/{id}` seam, or a named
 * source via the `extroverting_theme_quiz_definition` filter.
 *
 * @package Extroverting
 */

namespace Extroverting\Theme\Quiz;

defined( 'ABSPATH' ) || exit;

/**
 * Quiz source resolver.
 */
final class Source {

	/**
	 * Per-request cache, keyed by event + quiz.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static $cache = array();

	/**
	 * Resolve the event a Quiz block should read.
	 *
	 * `eventId` 0 means "the event this page is for", so the same pattern can
	 * be dropped into an event template later without editing attributes.
	 *
	 * @param int $event_id Explicit event ID.
	 * @return int
	 */
	public static function resolve_event_id( int $event_id ): int {
		if ( $event_id > 0 ) {
			return $event_id;
		}
		$current = (int) get_the_ID();
		if ( $current > 0 && 'ext_event' === get_post_type( $current ) ) {
			return $current;
		}
		return 0;
	}

	/**
	 * Load and normalise a quiz definition.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $quiz_id  Optional named quiz from a source that offers several.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function get( int $event_id, string $quiz_id = '' ) {
		$key = $event_id . '|' . $quiz_id;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		/**
		 * Supply a quiz definition from a source other than the provider REST seam.
		 *
		 * The payload may be the IQ `quiz-config.json` shape (root `quizzes`),
		 * the provider endpoint shape (`questions`), or the bundled provider
		 * shape (`quizQuestions`). Normalisation happens here.
		 *
		 * @param array|null          $definition Null to fall through to REST.
		 * @param int                 $event_id   Event ID.
		 * @param string              $quiz_id    Named quiz, when supported.
		 */
		$definition = apply_filters( 'extroverting_theme_quiz_definition', null, $event_id, $quiz_id );
		if ( is_array( $definition ) ) {
			$normalized = Normalizer::normalize( $definition, $event_id, $quiz_id );
			if ( ! is_wp_error( $normalized ) ) {
				self::$cache[ $key ] = $normalized;
			}
			return $normalized;
		}

		if ( $event_id <= 0 ) {
			return new \WP_Error(
				'ext_quiz_missing_event',
				__( 'Choose a quiz-enabled event in the Quiz block.', 'extroverting' )
			);
		}

		$provider = (string) get_post_meta( $event_id, 'ext_quiz_provider', true );
		if ( '' === $provider ) {
			return new \WP_Error(
				'ext_quiz_missing',
				__( 'This event does not have a quiz.', 'extroverting' )
			);
		}

		if ( ! function_exists( 'rest_do_request' ) ) {
			return new \WP_Error(
				'ext_quiz_unavailable',
				__( 'We couldn\'t reach the quiz service. Your data is safe — try again.', 'extroverting' )
			);
		}

		$request  = new \WP_REST_Request( 'GET', '/extroverting/v1/quiz/' . $event_id );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$error   = $response->as_error();
			$message = $error instanceof \WP_Error ? $error->get_error_message() : '';
			return new \WP_Error(
				'ext_quiz_unavailable',
				$message ? $message : __( 'We couldn\'t reach the quiz service. Your data is safe — try again.', 'extroverting' ),
				array( 'status' => 503 )
			);
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'ext_quiz_unavailable',
				__( 'The quiz service returned an unexpected response.', 'extroverting' )
			);
		}

		$normalized = Normalizer::normalize( $data, $event_id, $quiz_id );
		if ( ! is_wp_error( $normalized ) ) {
			self::$cache[ $key ] = $normalized;
		}
		return $normalized;
	}
}
