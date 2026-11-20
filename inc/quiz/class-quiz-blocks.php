<?php
/**
 * Quiz blocks: registration, rendering, and shared Interactivity state.
 *
 * Three sibling blocks render one quiz:
 * - Quiz Question  — prompt, intro, progress
 * - Quiz Answer    — options and navigation
 * - Quiz Result    — outcome, banners, consumer line
 *
 * They communicate through the `extroverting/quiz` store, not through each
 * other's DOM. The Quiz container owns the store state and the wrapper.
 *
 * @package Extroverting
 */

namespace Extroverting\Theme\Quiz;

defined( 'ABSPATH' ) || exit;

/**
 * Block registration and server rendering.
 */
final class Blocks {

	/**
	 * Interactivity store namespace.
	 */
	const STORE = 'extroverting/quiz';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_filter( 'block_categories_all', array( self::class, 'category' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
	}

	/**
	 * Add the Extroverting block category.
	 *
	 * @param array<int,array<string,mixed>> $categories Existing categories.
	 * @return array<int,array<string,mixed>>
	 */
	public static function category( array $categories ): array {
		array_unshift(
			$categories,
			array(
				'slug'  => 'extroverting',
				'title' => __( 'Extroverting', 'extroverting' ),
				'icon'  => 'calendar-alt',
			)
		);
		return $categories;
	}

	/**
	 * Register the four blocks from their block.json files.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( self::blocks() as $config ) {
			$dir = self::blocks_dir() . '/' . $config['dir'];
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			register_block_type( $dir, array( 'render_callback' => $config['render'] ) );
		}
	}

	/**
	 * Enqueue one small editor script for all four blocks.
	 *
	 * Server registration alone does not give the editor an `edit` function;
	 * this is the zero-build equivalent of a generated block registration.
	 *
	 * @return void
	 */
	public static function editor_assets(): void {
		$script = get_theme_file_path( 'assets/js/quiz-editor.js' );
		$version = is_readable( $script ) ? (string) filemtime( $script ) : '0.1.0';

		wp_enqueue_script(
			'extroverting-quiz-editor',
			get_theme_file_uri( 'assets/js/quiz-editor.js' ),
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n' ),
			$version,
			true
		);

		$data = array();
		foreach ( self::blocks() as $config ) {
			$file = self::blocks_dir() . '/' . $config['dir'] . '/block.json';
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$meta = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $meta ) || empty( $meta['name'] ) ) {
				continue;
			}
			$data[ $meta['name'] ] = array(
				'title'           => $meta['title'] ?? '',
				'description'     => $meta['description'] ?? '',
				'icon'            => $meta['icon'] ?? 'editor-help',
				'category'        => $meta['category'] ?? 'extroverting',
				'attributes'      => $meta['attributes'] ?? array(),
				'parent'          => $meta['parent'] ?? array(),
				'usesContext'     => $meta['usesContext'] ?? array(),
				'providesContext' => $meta['providesContext'] ?? array(),
				'supports'        => $meta['supports'] ?? array( 'html' => false, 'multiple' => false ),
			);
		}

		wp_add_inline_script(
			'extroverting-quiz-editor',
			'window.ExtQuizBlocks = ' . (string) wp_json_encode( $data ) . ';',
			'before'
		);
	}

	/**
	 * Quiz container: resolve the definition, seed the store, render children.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Rendered inner blocks.
	 * @param \WP_Block            $block      Block instance.
	 * @return string
	 */
	public static function render_quiz( array $attributes, string $content, \WP_Block $block ): string {
		$event_id = Source::resolve_event_id( (int) ( $attributes['eventId'] ?? 0 ) );
		$quiz_id  = (string) ( $attributes['quizId'] ?? '' );
		$quiz     = Source::get( $event_id, $quiz_id );

		if ( is_wp_error( $quiz ) ) {
			return self::error_markup( $quiz );
		}

		// The store has one current quiz. Fail loudly rather than merging two
		// quizzes into each other; the editor enforces this with multiple:false.
		$existing    = wp_interactivity_state( self::STORE );
		$existing_id = is_array( $existing['current'] ?? null ) ? (string) ( $existing['current']['eventId'] ?? '' ) : '';
		if ( '' !== $existing_id ) {
			return self::error_markup(
				new \WP_Error(
					'ext_quiz_one_per_page',
					__( 'Only one Quiz block can run on a page.', 'extroverting' )
				)
			);
		}

		wp_interactivity_state( self::STORE, array( 'current' => self::initial_state( $quiz, $event_id ) ) );
		wp_interactivity_config(
			self::STORE,
			array(
				'rest'  => esc_url_raw( rest_url( 'extroverting/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);

		$wrapper = get_block_wrapper_attributes( array( 'class' => 'ext-quiz ext-quiz-shell' ) );
		$context = wp_interactivity_data_wp_context( array( 'eventId' => (string) $event_id ), self::STORE );
		$note    = '<p class="ext-consumernote" data-wp-text="state.current.consumerInfo" data-wp-bind--hidden="!state.current.consumerInfo"></p>';

		return sprintf(
			'<div %1$s %2$s data-wp-interactive="%3$s"><noscript><p class="ext-consumernote">%4$s</p></noscript>%5$s%6$s</div>',
			$wrapper,
			$context,
			esc_attr( self::STORE ),
			esc_html__( 'This quiz needs JavaScript turned on.', 'extroverting' ),
			$content,
			$note
		);
	}

	/**
	 * Question block: intro on the first question, prompt and progress always.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Inner blocks (unused).
	 * @param \WP_Block            $block      Block instance.
	 * @return string
	 */
	public static function render_question( array $attributes, string $content, \WP_Block $block ): string {
		$wrapper = get_block_wrapper_attributes( array( 'class' => 'ext-quiz-question' ) );

		ob_start();
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-wp-bind--hidden="state.current.done">
			<p class="ext-sr-only" aria-live="polite" data-wp-text="state.current.liveLabel"></p>
			<div class="ext-quiz-intro" data-wp-bind--hidden="!state.current.showIntro">
				<h2 class="ext-intro-title" data-wp-text="state.current.intro.title"></h2>
				<p class="ext-intro-sub" data-wp-text="state.current.intro.subtitle"></p>
			</div>
			<div class="ext-progress" role="progressbar" aria-label="<?php echo esc_attr__( 'Quiz progress', 'extroverting' ); ?>" aria-valuemin="0" aria-valuemax="100" data-wp-bind--aria-valuenow="state.current.progressValue">
				<i data-wp-style--width="state.current.progress"></i>
			</div>
			<div class="ext-q-count" data-wp-text="state.current.countLabel"></div>
			<h2 class="ext-q-title" data-wp-text="state.current.question.text" tabindex="-1"></h2>
			<p class="ext-q-context" data-wp-text="state.current.question.help" data-wp-bind--hidden="!state.current.question.help"></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Answer block: options for the current question plus Back/Next.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Inner blocks (unused).
	 * @param \WP_Block            $block      Block instance.
	 * @return string
	 */
	public static function render_answer( array $attributes, string $content, \WP_Block $block ): string {
		$wrapper = get_block_wrapper_attributes( array( 'class' => 'ext-quiz-answer' ) );

		ob_start();
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-wp-bind--hidden="state.current.done">
			<div class="ext-opts" role="group" aria-label="<?php echo esc_attr__( 'Answer options', 'extroverting' ); ?>">
				<template data-wp-each="state.current.options" data-wp-each-key="context.item.id">
					<button type="button" class="ext-opt" data-wp-bind--aria-pressed="context.item.selected" data-wp-on--click="actions.toggle">
						<span class="mk" aria-hidden="true"></span>
						<span class="ic" aria-hidden="true" data-wp-text="context.item.icon"></span>
						<span class="tx" data-wp-text="context.item.text"></span>
						<span class="note" data-wp-text="context.item.note" data-wp-bind--hidden="!context.item.note"></span>
					</button>
				</template>
			</div>
			<div class="ext-q-nav">
				<button type="button" class="ext-btn ext-btn-ghost" data-wp-on--click="actions.back" data-wp-bind--hidden="state.current.isFirst"><?php esc_html_e( 'Back', 'extroverting' ); ?></button>
				<button type="button" class="ext-btn ext-btn-primary" data-wp-on--click="actions.next" data-wp-bind--disabled="state.current.nextDisabled" data-wp-text="state.current.nextLabel"></button>
			</div>
			<p class="ext-err" data-wp-text="state.current.error" data-wp-bind--hidden="!state.current.error"></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Result block: provider rows/banners plus the provider's result copy.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Inner blocks (unused).
	 * @param \WP_Block            $block      Block instance.
	 * @return string
	 */
	public static function render_result( array $attributes, string $content, \WP_Block $block ): string {
		$wrapper = get_block_wrapper_attributes( array( 'class' => 'ext-quiz-result' ) );

		ob_start();
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<p class="ext-status" data-wp-text="state.current.statusText" data-wp-bind--hidden="!state.current.statusText"></p>
			<div class="ext-result" data-wp-bind--hidden="!state.current.done" aria-live="polite">
				<h2 class="ext-result-title" data-wp-text="state.current.result.title" tabindex="-1"></h2>
				<p class="ext-result-sub" data-wp-text="state.current.result.subtitle" data-wp-bind--hidden="!state.current.result.subtitle"></p>
				<div class="ext-result-rows" data-wp-bind--hidden="state.current.result.noMatch">
					<template data-wp-each="state.current.result.rows" data-wp-each-key="context.item.key">
						<div class="ext-result-card">
							<div class="rank" data-wp-text="context.item.rank"></div>
							<div class="ext-result-body">
								<div class="rname" data-wp-text="context.item.name"></div>
								<div class="rwhy" data-wp-text="context.item.why" data-wp-bind--hidden="!context.item.why"></div>
							</div>
							<div class="rscore" data-wp-text="context.item.score"></div>
						</div>
					</template>
				</div>
				<p class="ext-no-match" data-wp-text="state.current.result.noMatchText" data-wp-bind--hidden="!state.current.result.noMatch"></p>
				<template data-wp-each="state.current.result.banners" data-wp-each-key="context.item.key">
					<div class="ext-banner warn" data-wp-text="context.item.text"></div>
				</template>
				<template data-wp-each="state.current.result.cautions" data-wp-each-key="context.item.key">
					<div class="ext-caution">
						<h3><span data-wp-text="context.item.icon" aria-hidden="true"></span> <span data-wp-text="context.item.title"></span></h3>
						<p data-wp-text="context.item.body"></p>
						<p class="ext-caution-evidence" data-wp-text="context.item.evidence"></p>
					</div>
				</template>
				<p class="ext-methodology" data-wp-text="state.current.copy.methodology" data-wp-bind--hidden="!state.current.copy.methodology"></p>
				<button type="button" class="ext-btn ext-btn-ghost" data-wp-on--click="actions.restart"><?php esc_html_e( 'Start over', 'extroverting' ); ?></button>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The initial store state. It is deliberately plain data (no getters) so
	 * the Interactivity API can render it on the server as well as update it.
	 *
	 * @param array<string,mixed> $quiz     Normalised quiz.
	 * @param int                 $event_id Event ID.
	 * @return array<string,mixed>
	 */
	private static function initial_state( array $quiz, int $event_id ): array {
		$questions = $quiz['questions'];
		$first     = $questions[0];
		$total     = count( $questions );
		$labels    = array(
			'question'   => __( 'Question', 'extroverting' ),
			'of'         => __( 'of', 'extroverting' ),
			'next'       => __( 'Next', 'extroverting' ),
			'seeResults' => __( 'See results', 'extroverting' ),
			'submitting' => __( 'Matching…', 'extroverting' ),
			'retry'      => __( 'Try again', 'extroverting' ),
			'error'      => __( 'We couldn\'t reach the quiz service. Your data is safe — try again.', 'extroverting' ),
			'noMatch'    => __( 'No strong match. Nothing in the catalogue fits this profile closely enough.', 'extroverting' ),
			'results'    => __( 'Your results', 'extroverting' ),
		);

		$live_label = $labels['question'] . ' 1 ' . $labels['of'] . ' ' . $total . ': ' . $first['text'];

		return array(
			'eventId'       => (string) $event_id,
			'provider'      => $quiz['provider'],
			'intro'         => $quiz['intro'],
			'questions'     => $questions,
			'copy'          => $quiz['copy'],
			'consumerInfo'  => $quiz['consumer_info'],
			'labels'        => $labels,
			'total'         => $total,
			'index'         => 0,
			'answers'       => (object) array(),
			'question'      => array(
				'id'     => $first['id'],
				'text'   => $first['text'],
				'format' => $first['format'],
				'help'   => $first['help'],
			),
			'options'       => self::options( $first, array() ),
			'countLabel'    => $labels['question'] . ' 1 ' . $labels['of'] . ' ' . $total,
			'liveLabel'     => $live_label,
			'progress'      => (int) round( 100 / $total ) . '%',
			'progressValue' => (int) round( 100 / $total ),
			'isFirst'       => true,
			'showIntro'     => '' !== $quiz['intro']['title'],
			'backDisabled'  => true,
			'nextDisabled'  => true,
			'nextLabel'     => $total > 1 ? $labels['next'] : $labels['seeResults'],
			'status'        => 'idle',
			'statusText'    => '',
			'done'          => false,
			'error'         => '',
			'result'        => null,
		);
	}

	/**
	 * Option view models for one question.
	 *
	 * @param array<string,mixed> $question Question.
	 * @param array<int,string>   $selected Selected answer IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private static function options( array $question, array $selected ): array {
		$options = array();
		foreach ( $question['answers'] as $answer ) {
			$options[] = array(
				'id'       => $answer['id'],
				'text'     => $answer['text'],
				'icon'     => $answer['icon'],
				'note'     => $answer['note'],
				'selected' => in_array( $answer['id'], $selected, true ),
			);
		}
		return $options;
	}

	/**
	 * Block directories and their render callbacks.
	 *
	 * @return array<string,array{dir:string,render:callable}>
	 */
	private static function blocks(): array {
		return array(
			'extroverting/quiz'          => array(
				'dir'    => 'quiz',
				'render' => array( self::class, 'render_quiz' ),
			),
			'extroverting/quiz-question' => array(
				'dir'    => 'quiz-question',
				'render' => array( self::class, 'render_question' ),
			),
			'extroverting/quiz-answer'   => array(
				'dir'    => 'quiz-answer',
				'render' => array( self::class, 'render_answer' ),
			),
			'extroverting/quiz-result'   => array(
				'dir'    => 'quiz-result',
				'render' => array( self::class, 'render_result' ),
			),
		);
	}

	/**
	 * Theme blocks directory.
	 *
	 * @return string
	 */
	private static function blocks_dir(): string {
		return dirname( __DIR__, 2 ) . '/blocks';
	}

	/**
	 * Inline error state (no inner blocks, so no half-wired directives).
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	private static function error_markup( \WP_Error $error ): string {
		return sprintf(
			'<div class="ext-quiz ext-quiz-shell ext-empty"><div class="em-ic" aria-hidden="true">⚠️</div><h3>%1$s</h3><p>%2$s</p></div>',
			esc_html__( 'Quiz unavailable', 'extroverting' ),
			esc_html( $error->get_error_message() )
		);
	}
}
