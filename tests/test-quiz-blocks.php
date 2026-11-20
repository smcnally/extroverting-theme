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
	 * The per-test REST stub, when one is registered.
	 *
	 * @var callable|null
	 */
	private $rest_filter = null;

	/**
	 * Never leak the source filter into another test.
	 */
	public function tear_down() {
		remove_all_filters( 'extroverting_theme_quiz_definition' );
		wp_set_current_user( 0 );
		if ( $this->rest_filter ) {
			remove_filter( 'rest_pre_dispatch', $this->rest_filter, 10 );
			$this->rest_filter = null;
		}

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
	 * Create an event that declares a quiz provider.
	 *
	 * @return int Event ID.
	 */
	private function provider_event() {
		$event_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'ext_event',
				'post_status' => 'publish',
				'post_title'  => 'Quiz event',
			)
		);
		update_post_meta( $event_id, 'ext_quiz_provider', 'gpiq' );
		return $event_id;
	}

	/**
	 * Exercise the production REST branch: provider meta + rest_do_request.
	 */
	public function test_provider_rest_source_renders() {
		$event_id = $this->provider_event();

		$this->rest_filter = function ( $result, $server, $request ) use ( $event_id ) {
			if ( '/extroverting/v1/quiz/' . $event_id === $request->get_route() ) {
				return new WP_REST_Response(
					array(
						'provider'           => 'gpiq',
						'consumer_info_line' => 'Consumer information, not medical advice.',
						'questions'          => array(
							array(
								'id'       => 'q1',
								'question' => 'Provider question?',
								'format'   => 'single_select',
								'answers'  => array(
									array( 'id' => 'a1', 'label' => 'Provider answer' ),
								),
							),
						),
					),
					200
				);
			}
			return $result;
		};
		add_filter( 'rest_pre_dispatch', $this->rest_filter, 10, 3 );

		$html = do_blocks(
			'<!-- wp:extroverting/quiz {"eventId":' . $event_id . '} -->'
			. '<!-- wp:extroverting/quiz-question /-->'
			. '<!-- wp:extroverting/quiz-answer /-->'
			. '<!-- wp:extroverting/quiz-result /-->'
			. '<!-- /wp:extroverting/quiz -->'
		);

		$this->assertStringContainsString( 'Provider question?', $html );
		$this->assertStringContainsString( 'Provider answer', $html );
		$this->assertStringContainsString( 'Consumer information, not medical advice.', $html );
	}

	/**
	 * A provider failure shows a public message, not the internal REST reason.
	 */
	public function test_provider_error_hides_internal_message() {
		$event_id = $this->provider_event();

		$this->rest_filter = function ( $result, $server, $request ) use ( $event_id ) {
			if ( '/extroverting/v1/quiz/' . $event_id === $request->get_route() ) {
				return new WP_Error( 'gpiq_unavailable', 'SECRET INTERNAL DETAIL', array( 'status' => 503 ) );
			}
			return $result;
		};
		add_filter( 'rest_pre_dispatch', $this->rest_filter, 10, 3 );

		$html = do_blocks( '<!-- wp:extroverting/quiz {"eventId":' . $event_id . '} /-->' );

		$this->assertStringContainsString( 'Quiz unavailable', $html );
		$this->assertStringNotContainsString( 'SECRET INTERNAL DETAIL', $html );
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

		// The second block is not interactive; visitors get the public message.
		$this->assertStringContainsString( 'Quiz unavailable', $html );
		$this->assertSame( 1, substr_count( $html, 'data-wp-interactive="extroverting/quiz"' ) );
	}

	/**
	 * Editors still see why a quiz cannot render.
	 */
	public function test_editors_see_error_detail() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$html = do_blocks( '<!-- wp:extroverting/quiz {"eventId":0} /-->' );

		$this->assertStringContainsString( 'Choose a quiz-enabled event', $html );
	}

	/**
	 * A source error renders a clean message instead of a half-wired quiz.
	 */
	public function test_missing_event_renders_error() {
		$html = do_blocks( '<!-- wp:extroverting/quiz {"eventId":0} /-->' );
		$this->assertStringContainsString( 'Quiz unavailable', $html );
		$this->assertStringNotContainsString( 'Choose a quiz-enabled event', $html );
	}
}
