/**
 * Quiz Interactivity store.
 *
 * One store, three consumers:
 *   Question  reads state.current.question / progress
 *   Answer    writes state.current.answers, advances the index
 *   Result    reads state.current.result
 *
 * The container block seeds state.current on the server; these actions keep
 * it plain and serialisable so the same markup renders before and after
 * hydration.
 */

import {
	store,
	getContext,
	getConfig,
	getElement,
} from '@wordpress/interactivity';

const { state } = store( 'extroverting/quiz', {
	actions: {
		/**
		 * Select or deselect an option in the current question.
		 */
		toggle() {
			const context = getContext();
			const item = context && context.item;
			const quiz = state.current;
			if ( ! item || ! quiz || quiz.done || 'submitting' === quiz.status ) {
				return;
			}

			const selected = quiz.options
				.filter( ( option ) => option.selected )
				.map( ( option ) => option.id );
			const multi = 'multi_select' === quiz.question.format;
			let next;

			if ( multi ) {
				next = selected.includes( item.id )
					? selected.filter( ( id ) => id !== item.id )
					: selected.concat( item.id );
			} else {
				next = [ item.id ];
			}

			quiz.answers[ quiz.question.id ] = next;
			quiz.options.forEach( ( option ) => {
				option.selected = next.includes( option.id );
			} );
			quiz.nextDisabled = 0 === next.length;
			quiz.error = '';
			quiz.status = 'idle';
		},

		/**
		 * Return to the previous question.
		 */
		back() {
			const quiz = state.current;
			if ( ! quiz || quiz.done || 'submitting' === quiz.status || quiz.index < 1 ) {
				return;
			}
			quiz.index -= 1;
			quiz.status = 'idle';
			quiz.error = '';
			quiz.statusText = '';
			applyQuestion( quiz );
			focusQuestion( getElement() );
		},

		/**
		 * Advance, or submit on the last question.
		 */
		next() {
			const quiz = state.current;
			if ( ! quiz || quiz.done || 'submitting' === quiz.status || quiz.nextDisabled ) {
				return;
			}

			const element = getElement();
			if ( quiz.index < quiz.total - 1 ) {
				quiz.index += 1;
				quiz.status = 'idle';
				quiz.error = '';
				applyQuestion( quiz );
				focusQuestion( element );
				return;
			}

			submit( quiz, getConfig(), element );
		},

		/**
		 * Reset to the first question without leaving the page.
		 */
		restart() {
			const quiz = state.current;
			if ( ! quiz ) {
				return;
			}
			quiz.answers = {};
			quiz.index = 0;
			quiz.result = null;
			quiz.done = false;
			quiz.status = 'idle';
			quiz.statusText = '';
			quiz.error = '';
			applyQuestion( quiz );
			focusQuestion( getElement() );
		},
	},
} );

/**
 * Substitute the numbered placeholders in a translatable label.
 *
 * @param {string} template Label template
 * @param {number} current  Current question number
 * @param {number} total    Question count
 * @param {string} text     Question text
 * @return {string} Formatted label
 */
function formatLabel( template, current, total, text ) {
	return String( template || '' )
		.replace( '%1$d', String( current ) )
		.replace( '%2$d', String( total ) )
		.replace( '%3$s', text || '' );
}

/**
 * Rebuild the view fields for the current index.
 *
 * @param {Object} quiz state.current
 */
function applyQuestion( quiz ) {
	const question = quiz.questions[ quiz.index ];
	if ( ! question ) {
		return;
	}

	const selected = Array.isArray( quiz.answers[ question.id ] )
		? quiz.answers[ question.id ]
		: [];

	quiz.question = {
		id: question.id,
		text: question.text,
		format: question.format,
		help: question.help || '',
	};
	quiz.options = question.answers.map( ( answer ) => ( {
		id: answer.id,
		text: answer.text,
		icon: answer.icon || '',
		note: answer.note || '',
		selected: selected.includes( answer.id ),
	} ) );
	quiz.isFirst = 0 === quiz.index;
	quiz.showIntro = quiz.isFirst && Boolean( quiz.intro.title );
	quiz.nextDisabled = 0 === selected.length;
	quiz.nextLabel =
		quiz.index === quiz.total - 1 ? quiz.labels.seeResults : quiz.labels.next;
	quiz.countLabel = formatLabel(
		quiz.labels.count,
		quiz.index + 1,
		quiz.total
	);
	quiz.progressValue = Math.round(
		( ( quiz.index + 1 ) / quiz.total ) * 100
	);
	quiz.progress = quiz.progressValue + '%';
	quiz.liveLabel = formatLabel(
		quiz.labels.live,
		quiz.index + 1,
		quiz.total,
		quiz.question.text
	);
}

/**
 * Find the quiz wrapper that owns an interacting element.
 *
 * @param {Element|null} element Element that triggered the action
 * @return {Element|null} Quiz scope
 */
function scopeFor( element ) {
	const scoped =
		element && element.closest
			? element.closest( '[data-wp-interactive="extroverting/quiz"]' )
			: null;
	return (
		scoped ||
		document.querySelector( '[data-wp-interactive="extroverting/quiz"]' )
	);
}

/**
 * Move focus to the new question heading after Back/Next/Start over.
 *
 * @param {Element|null} element Element that triggered the action
 */
function focusQuestion( element ) {
	requestAnimationFrame( () => {
		const scope = scopeFor( element );
		const title = scope && scope.querySelector( '.ext-q-title' );
		if ( title ) {
			title.focus( { preventScroll: true } );
		}
	} );
}

/**
 * Submit answers to the provider's existing REST seam and shape the result.
 *
 * @param {Object}       quiz    state.current
 * @param {Object}       config  Interactivity config (rest root + nonce)
 * @param {Element|null} element Element that triggered the action
 */
async function submit( quiz, config, element ) {
	quiz.status = 'submitting';
	quiz.statusText = quiz.labels.submitting;
	quiz.nextDisabled = true;
	quiz.nextLabel = quiz.labels.submitting;
	quiz.error = '';

	const root = ( config && config.rest ) || '/wp-json/extroverting/v1/';
	const url =
		root.replace( /\/?$/, '/' ) +
		'quiz/' +
		encodeURIComponent( quiz.eventId ) +
		'/submit';

	try {
		const response = await fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ( config && config.nonce ) || '',
			},
			body: JSON.stringify( { answers: quiz.answers } ),
		} );
		const data = await response.json().catch( () => ( {} ) );

		if ( ! response.ok ) {
			throw new Error( quiz.labels.error );
		}

		quiz.result = shapeResult( quiz, ( data && data.result ) || {} );
		quiz.done = true;
		quiz.status = 'done';
		quiz.statusText = '';
		quiz.nextLabel = quiz.labels.seeResults;

		if ( data && data.consumer_info_line ) {
			quiz.consumerInfo = data.consumer_info_line;
		}

		requestAnimationFrame( () => {
			const scope = scopeFor( element );
			const title = scope && scope.querySelector( '.ext-result-title' );
			if ( title ) {
				title.focus( { preventScroll: true } );
			}
		} );
	} catch ( error ) {
		quiz.status = 'error';
		quiz.statusText = '';
		quiz.error = quiz.labels.error;
		quiz.nextDisabled = false;
		quiz.nextLabel = quiz.labels.retry;
	}
}

/**
 * Turn the provider's result into the fields the Result block renders.
 *
 * @param {Object} quiz state.current
 * @param {Object} raw  Provider result
 * @return {Object} Result view model
 */
function shapeResult( quiz, raw ) {
	const rows = Array.isArray( raw.rows )
		? raw.rows.map( ( row, index ) => ( {
				key: index + 1,
				rank: index + 1,
				name: row.name || '',
				why: row.why || '',
				score:
					'number' === typeof row.score ? row.score : row.score || '',
		  } ) )
		: [];

	const banners = Array.isArray( raw.banners )
		? raw.banners
				.map( ( banner, index ) => ( {
					key: index,
					text: ( banner && banner.text ) || '',
				} ) )
				.filter( ( banner ) => banner.text )
		: [];

	const cautions = Array.isArray( raw.cautions )
		? raw.cautions.map( ( caution, index ) => ( {
				key: index,
				icon: caution.icon || '',
				title: caution.title || '',
				body: caution.body || '',
				evidence: caution.evidence || '',
		  } ) )
		: [];

	const noMatch = Boolean( raw.no_match );

	return {
		title:
			raw.title || quiz.copy.resultTitle || quiz.labels.results || '',
		subtitle:
			raw.subtitle || resolveTemplate( quiz.copy.subtitleTemplate, quiz ),
		rows,
		banners,
		cautions,
		noMatch,
		noMatchText:
			raw.no_match_text ||
			quiz.copy.noMatchText ||
			quiz.labels.noMatch,
	};
}

/**
 * Resolve IQ's subtitle placeholders such as "Ready for {q2.label}".
 *
 * @param {string} template Template copy
 * @param {Object} quiz     state.current
 * @return {string} Resolved copy
 */
function resolveTemplate( template, quiz ) {
	if ( ! template ) {
		return '';
	}

	return template.replace( /\{(q[\w-]+)\.label\}/g, ( match, questionId ) => {
		const question = quiz.questions.find(
			( candidate ) => candidate.id === questionId
		);
		if ( ! question ) {
			return '';
		}
		const selected = quiz.answers[ questionId ] || [];
		return selected
			.map( ( answerId ) => {
				const answer = question.answers.find(
					( candidate ) => candidate.id === answerId
				);
				return answer ? answer.text : '';
			} )
			.filter( Boolean )
			.join( ', ' );
	} );
}
