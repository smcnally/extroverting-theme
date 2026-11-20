<?php
/**
 * Block registration and server-render smoke tests.
 *
 * @package Extroverting\Theme\Tests
 */

/**
 * The quiz blocks must register and render their initial state without JS.
 */
class Test_Quiz_Blocks extends WP_UnitTestCase {

	/**
	 * Never leak the source filter into another test.
	 */
	public function tear_down() {
		remove_all_filters( 'extroverting_theme_quiz_definition' );

		// The Interactivity API has no public reset; clear its per-request
		// store so one test's state does not leak into the next.
		$api = wp_interactivity();
		foreach ( array( 'state_data', 'config_data' ) as $property ) {
			$reflection = new ReflectionProperty( $api, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $api, array() );
		}

		parent::tear_down();
	}

	/**
	 * Minimal IQ-shaped definition supplied through the source filter.
	 *
	 * @return array<string,mixed>
	 */
	private function definition() {
		return array(
			'quizzes' => array(
				array(
					'id'      => 'calibrate-v2',
					'intro'   => array( 'title' => 'Let us calibrate', 'subtitle' => 'Two questions.' ),
					'success' => array( 'title' => 'Your profile', 'subtitleTemplate' => 'Ready for {q2.label}' ),
					'questions' => array(
						array(
							'id'       => 'q1',
							'question' => 'Which primary effect are you seeking?',
							'format'   => 'multi_select',
							'answers'  => array(
								array( 'id' => 'a1_1', 'label' => 'Mood Lift', 'icon' => '😌' ),
								array( 'id' => 'a1_2', 'label' => 'Deep Calm', 'icon' => '🧘' ),
							),
						),
						array(
							'id'       => 'q2',
							'question' => 'What is your destination?',
							'format'   => 'single_select',
							'answers'  => array(
								array( 'id' => 'a2_1', 'label' => 'Dinner with Friends' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * All four blocks register.
	 */
	public function test_blocks_register() {
		$registry = WP_Block_Type_Registry::get_instance();
		foreach ( array( 'extroverting/quiz', 'extroverting/quiz-question', 'extroverting/quiz-answer', 'extroverting/quiz-result' ) as $name ) {
			$this->assertTrue( $registry->is_registered( $name ), $name . ' should be registered' );
		}
	}

	/**
	 * The view module declares the Interactivity runtime dependency; without
	 * it WordPress never puts the bare import in the import map.
	 */
	public function test_view_module_declares_interactivity_dependency() {
		$asset = require dirname( __DIR__ ) . '/blocks/quiz/view.asset.php';
		$this->assertIsArray( $asset );
		$this->assertContains( '@wordpress/interactivity', $asset['dependencies'] );
	}

	/**
	 * A filtered definition renders the first question and its options server-side.
	 */
	public function test_quiz_renders_initial_state() {
		add_filter(
			'extroverting_theme_quiz_definition',
			function () {
				return $this->definition();
			}
		);

		$markup = '<!-- wp:extroverting/quiz {"eventId":991} -->'
			. '<!-- wp:extroverting/quiz-question /-->'
			. '<!-- wp:extroverting/quiz-answer /-->'
			. '<!-- wp:extroverting/quiz-result /-->'
			. '<!-- /wp:extroverting/quiz -->';

		$html = do_blocks( $markup );

		$this->assertStringContainsString( 'data-wp-interactive="extroverting/quiz"', $html );
		$this->assertStringContainsString( 'Which primary effect are you seeking?', $html );
		$this->assertStringContainsString( 'Mood Lift', $html );
		$this->assertStringContainsString( 'aria-pressed="false"', $html );
		$this->assertStringContainsString( 'data-wp-on--click="actions.toggle"', $html );
		$this->assertStringContainsString( 'data-wp-each="state.current.options"', $html );
		$this->assertStringContainsString( 'state.current.question.text', $html );
		$this->assertMatchesRegularExpression( '/<div[^>]*class="ext-result"[^>]*hidden/', $html );

		remove_all_filters( 'extroverting_theme_quiz_definition' );
	}

	/**
	 * A second Quiz on the same page fails loudly instead of overwriting state.
	 */
	public function test_only_one_quiz_per_page() {
		add_filter(
			'extroverting_theme_quiz_definition',
			function () {
				return $this->definition();
			}
		);

		$block  = '<!-- wp:extroverting/quiz {"eventId":991} -->'
			. '<!-- wp:extroverting/quiz-question /-->'
			. '<!-- wp:extroverting/quiz-answer /-->'
			. '<!-- wp:extroverting/quiz-result /-->'
			. '<!-- /wp:extroverting/quiz -->';
		$second = str_replace( '991', '992', $block );
		$html   = do_blocks( $block . $second );

		$this->assertStringContainsString( 'Only one Quiz block can run on a page.', $html );
	}

	/**
	 * A source error renders a clean message instead of a half-wired quiz.
	 */
	public function test_missing_event_renders_error() {
		$html = do_blocks( '<!-- wp:extroverting/quiz {"eventId":0} /-->' );
		$this->assertStringContainsString( 'Quiz unavailable', $html );
	}
}
