<?php
/**
 * Normalise quiz payloads from IQ (v2) or the provider REST seam (v1).
 *
 * The blocks are presentation only: this class changes shape, never copy.
 *
 * @package Extroverting
 */

namespace Extroverting\Theme\Quiz;

defined( 'ABSPATH' ) || exit;

/**
 * Converts any supported quiz payload into one canonical array.
 */
final class Normalizer {

	/**
	 * Canonicalise a raw quiz payload.
	 *
	 * Supported shapes:
	 * - IQ `quiz-config.json` (root `quizzes` + `defaultQuiz`)
	 * - `GET /extroverting/v1/quiz/{id}` (`questions`, `consumer_info_line`)
	 * - the provider's bundled `quiz.json` (`quizQuestions`)
	 *
	 * @param array<string,mixed> $raw      Raw payload.
	 * @param int                 $event_id Event ID.
	 * @param string              $quiz_id  Optional named IQ quiz.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function normalize( array $raw, int $event_id = 0, string $quiz_id = '' ) {
		$picked = self::pick( $raw, $quiz_id );
		if ( is_wp_error( $picked ) ) {
			return $picked;
		}

		$quiz      = $picked['quiz'];
		$scoring   = $picked['scoring'];
		$questions = self::questions( $quiz );
		if ( is_wp_error( $questions ) ) {
			return $questions;
		}

		$success = isset( $quiz['success'] ) && is_array( $quiz['success'] ) ? $quiz['success'] : array();
		$intro   = isset( $quiz['intro'] ) && is_array( $quiz['intro'] ) ? $quiz['intro'] : array();

		return array(
			'id'            => self::text( $quiz['id'] ?? $raw['defaultQuiz'] ?? '' ),
			'provider'      => self::text( $raw['provider'] ?? $quiz['provider'] ?? 'gpiq' ),
			'event_id'      => $event_id,
			'intro'         => array(
				'title'    => self::text( $intro['title'] ?? '' ),
				'subtitle' => self::text( $intro['subtitle'] ?? '' ),
			),
			'questions'     => $questions,
			'consumer_info' => self::text( $raw['consumer_info_line'] ?? $raw['consumer_info'] ?? '' ),
			'copy'          => array(
				'resultTitle'       => self::text( $success['title'] ?? '' ),
				'subtitleTemplate'  => self::text( $success['subtitleTemplate'] ?? $success['subtitle'] ?? '' ),
				'methodology'       => self::text( $success['methodologyText'] ?? $success['methodology'] ?? '' ),
				'noMatchText'      => self::text( $scoring['noMatchText'] ?? '' ),
			),
		);
	}

	/**
	 * Pick the quiz to run from a payload.
	 *
	 * @param array<string,mixed> $raw     Raw payload.
	 * @param string              $quiz_id Optional named IQ quiz.
	 * @return array{quiz:array<string,mixed>,scoring:array<string,mixed>}|\WP_Error
	 */
	private static function pick( array $raw, string $quiz_id ) {
		if ( isset( $raw['quizzes'] ) && is_array( $raw['quizzes'] ) && array() !== $raw['quizzes'] ) {
			$quizzes = array();
			foreach ( $raw['quizzes'] as $quiz ) {
				if ( is_array( $quiz ) ) {
					$quizzes[] = $quiz;
				}
			}
			if ( array() === $quizzes ) {
				return new \WP_Error( 'ext_quiz_empty', __( 'This quiz has no questions yet.', 'extroverting' ) );
			}

			$scoring = isset( $raw['scoring'] ) && is_array( $raw['scoring'] ) ? $raw['scoring'] : array();

			if ( '' !== $quiz_id ) {
				foreach ( $quizzes as $quiz ) {
					if ( self::text( $quiz['id'] ?? '' ) === $quiz_id ) {
						return array( 'quiz' => $quiz, 'scoring' => $scoring );
					}
				}
				return new \WP_Error(
					'ext_quiz_variant_missing',
					sprintf(
						/* translators: %s: quiz ID. */
						__( 'The quiz source has no quiz called "%s".', 'extroverting' ),
						$quiz_id
					)
				);
			}

			$default = self::text( $raw['defaultQuiz'] ?? '' );
			if ( '' !== $default ) {
				foreach ( $quizzes as $quiz ) {
					if ( self::text( $quiz['id'] ?? '' ) === $default ) {
						return array( 'quiz' => $quiz, 'scoring' => $scoring );
					}
				}
			}

			return array( 'quiz' => $quizzes[0], 'scoring' => $scoring );
		}

		if ( isset( $raw['quizQuestions'] ) && is_array( $raw['quizQuestions'] ) ) {
			return array(
				'quiz'    => array(
					'id'        => self::text( $raw['id'] ?? '' ),
					'questions' => $raw['quizQuestions'],
					'intro'     => isset( $raw['intro'] ) && is_array( $raw['intro'] ) ? $raw['intro'] : array(),
					'success'   => isset( $raw['success'] ) && is_array( $raw['success'] ) ? $raw['success'] : array(),
				),
				'scoring' => isset( $raw['scoring'] ) && is_array( $raw['scoring'] ) ? $raw['scoring'] : array(),
			);
		}

		if ( isset( $raw['questions'] ) && is_array( $raw['questions'] ) ) {
			return array(
				'quiz'    => $raw,
				'scoring' => isset( $raw['scoring'] ) && is_array( $raw['scoring'] ) ? $raw['scoring'] : array(),
			);
		}

		return new \WP_Error(
			'ext_quiz_unsupported',
			__( 'The quiz source returned an unrecognised format.', 'extroverting' )
		);
	}

	/**
	 * Normalise questions and answers, dropping empty entries.
	 *
	 * @param array<string,mixed> $quiz Selected quiz.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private static function questions( array $quiz ) {
		$raw_questions = isset( $quiz['questions'] ) && is_array( $quiz['questions'] ) ? $quiz['questions'] : array();
		$questions     = array();

		foreach ( $raw_questions as $question ) {
			if ( ! is_array( $question ) ) {
				continue;
			}

			$text = self::text( $question['question'] ?? $question['text'] ?? '' );
			if ( '' === $text ) {
				continue;
			}

			$raw_answers = isset( $question['answers'] ) && is_array( $question['answers'] ) ? $question['answers'] : array();
			$answers     = array();
			foreach ( $raw_answers as $answer ) {
				if ( ! is_array( $answer ) ) {
					continue;
				}
				$label = self::text( $answer['label'] ?? $answer['text'] ?? '' );
				if ( '' === $label ) {
					continue;
				}

				$answer_id = self::text( $answer['id'] ?? '' );
				if ( '' === $answer_id ) {
					$answer_id = 'a' . ( count( $questions ) + 1 ) . '_' . ( count( $answers ) + 1 );
				}

				$answers[] = array(
					'id'   => $answer_id,
					'text' => $label,
					'icon' => self::text( $answer['icon'] ?? '' ),
					'note' => self::text( $answer['counterEvidence'] ?? $answer['note'] ?? '' ),
				);
			}

			if ( array() === $answers ) {
				continue;
			}

			$question_id = self::text( $question['id'] ?? '' );
			if ( '' === $question_id ) {
				$question_id = 'q' . ( count( $questions ) + 1 );
			}

			$format = self::text( $question['format'] ?? 'single_select' );

			$questions[] = array(
				'id'      => $question_id,
				'text'    => $text,
				'format'  => 'multi_select' === $format ? 'multi_select' : 'single_select',
				'help'    => self::text( $question['help'] ?? $question['description'] ?? '' ),
				'answers' => $answers,
			);
		}

		if ( array() === $questions ) {
			return new \WP_Error( 'ext_quiz_empty', __( 'This quiz has no questions yet.', 'extroverting' ) );
		}

		return $questions;
	}

	/**
	 * Plain text from a scalar payload value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function text( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}
