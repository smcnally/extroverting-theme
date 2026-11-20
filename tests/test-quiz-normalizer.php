<?php
/**
 * Normaliser tests.
 *
 * @package Extroverting\Theme\Tests
 */

require_once dirname( __DIR__ ) . '/inc/quiz/class-quiz-normalizer.php';

use Extroverting\Theme\Quiz\Normalizer;

/**
 * Shape conversion must preserve the source's text exactly (minus markup).
 */
class Test_Quiz_Normalizer extends WP_UnitTestCase {

	/**
	 * Build the IQ v2 fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function iq_v2() {
		return (array) json_decode(
			(string) file_get_contents( __DIR__ . '/fixtures/quiz-iq-v2.json' ),
			true
		);
	}

	/**
	 * IQ's root `quizzes` shape, including copy and counter-evidence notes.
	 */
	public function test_iq_v2_shape_is_preserved() {
		$quiz = Normalizer::normalize( $this->iq_v2(), 42 );

		$this->assertIsArray( $quiz );
		$this->assertSame( 'calibrate-v2', $quiz['id'] );
		$this->assertSame( 'gpiq', $quiz['provider'] );
		$this->assertSame( 'Let\'s Calibrate', $quiz['intro']['title'] );
		$this->assertCount( 2, $quiz['questions'] );
		$this->assertSame( 'multi_select', $quiz['questions'][0]['format'] );
		$this->assertSame( 'Focus & Clarity', $quiz['questions'][0]['answers'][1]['text'] );
		$this->assertSame(
			'Controlled studies do not support cognitive enhancement.',
			$quiz['questions'][0]['answers'][1]['note']
		);
		$this->assertSame( 'Your Contextual Profile', $quiz['copy']['resultTitle'] );
		$this->assertSame( 'Ready for {q2.label}', $quiz['copy']['subtitleTemplate'] );
		$this->assertStringNotContainsString( '<strong>', $quiz['copy']['methodology'] );
		$this->assertSame(
			'No strong differentiator — several products fit about equally',
			$quiz['copy']['noDifferentiator']
		);
	}

	/**
	 * The provider REST shape carries its own consumer line and provider ID.
	 */
	public function test_provider_rest_shape() {
		$raw = array(
			'event'              => array( 'id' => 7, 'title' => 'Midtown' ),
			'provider'           => 'gpiq',
			'consumer_info_line' => 'Consumer information, not medical advice.',
			'questions'          => array(
				array(
					'id'       => 'q1',
					'question' => 'What is your destination?',
					'format'   => 'single_select',
					'answers'  => array(
						array( 'id' => 'a1', 'label' => 'Dinner with Friends' ),
					),
				),
			),
		);

		$quiz = Normalizer::normalize( $raw, 7 );

		$this->assertIsArray( $quiz );
		$this->assertSame( 'gpiq', $quiz['provider'] );
		$this->assertSame( 'Consumer information, not medical advice.', $quiz['consumer_info'] );
		$this->assertSame( 'Dinner with Friends', $quiz['questions'][0]['answers'][0]['text'] );
		$this->assertSame( 'single_select', $quiz['questions'][0]['format'] );
	}

	/**
	 * A named variant is selectable; a missing one is a clear error.
	 */
	public function test_named_variant() {
		$quiz = Normalizer::normalize( $this->iq_v2(), 42, 'calibrate-v2' );
		$this->assertIsArray( $quiz );
		$this->assertSame( 'calibrate-v2', $quiz['id'] );

		$missing = Normalizer::normalize( $this->iq_v2(), 42, 'calibrate-v9' );
		$this->assertWPError( $missing );
		$this->assertSame( 'ext_quiz_variant_missing', $missing->get_error_code() );
	}

	/**
	 * A source with no usable questions returns an error, not an empty quiz.
	 */
	public function test_empty_quiz_is_an_error() {
		$error = Normalizer::normalize( array( 'quizzes' => array( array( 'id' => 'x', 'questions' => array() ) ) ), 1 );

		$this->assertWPError( $error );
		$this->assertSame( 'ext_quiz_empty', $error->get_error_code() );
	}

	/**
	 * Question and answer IDs are supplied when a source omits them.
	 */
	public function test_missing_ids_get_stable_fallbacks() {
		$quiz = Normalizer::normalize(
			array(
				'questions' => array(
					array(
						'question' => 'Pick one',
						'answers'  => array( array( 'label' => 'One' ) ),
					),
				),
			),
			3
		);

		$this->assertIsArray( $quiz );
		$this->assertSame( 'q1', $quiz['questions'][0]['id'] );
		$this->assertSame( 'a1_1', $quiz['questions'][0]['answers'][0]['id'] );
	}
}
