/**
 * Zero-build editor registration for the quiz blocks.
 *
 * PHP registers the blocks (and their render callbacks) from block.json.
 * This script adds the editor-only `edit` function to the same names, using
 * the attributes PHP passes in window.ExtQuizBlocks so block.json stays the
 * single source of truth.
 */

( function ( blocks, element, components, blockEditor, i18n ) {
	const el = element.createElement;
	const __ = i18n.__;
	const data = window.ExtQuizBlocks || {};

	Object.keys( data ).forEach( function ( name ) {
		const meta = data[ name ];
		const isQuiz = 'extroverting/quiz' === name;

		blocks.registerBlockType( name, {
			apiVersion: 3,
			title: meta.title,
			description: meta.description,
			icon: meta.icon,
			category: meta.category,
			attributes: meta.attributes,
			parent: meta.parent,
			usesContext: meta.usesContext,
			providesContext: meta.providesContext,
			supports: meta.supports,
			edit: function ( props ) {
				const blockProps = blockEditor.useBlockProps( {
					className: 'ext-quiz-editor-placeholder',
				} );

				const controls = isQuiz
					? [
							el( components.TextControl, {
								key: 'event',
								label: __( 'Event ID', 'extroverting' ),
								help: __(
									'The quiz-enabled event whose provider supplies the questions.',
									'extroverting'
								),
								type: 'number',
								value: props.attributes.eventId || '',
								onChange: function ( value ) {
									props.setAttributes( {
										eventId: parseInt( value, 10 ) || 0,
									} );
								},
							} ),
							el( components.TextControl, {
								key: 'variant',
								label: __( 'Quiz variant (optional)', 'extroverting' ),
								help: __(
									'Only for sources that offer several quizzes, such as IQ config v2.',
									'extroverting'
								),
								value: props.attributes.quizId || '',
								onChange: function ( value ) {
									props.setAttributes( { quizId: value } );
								},
							} ),
					  ]
					: [
							el(
								'p',
								{ key: 'note' },
								__(
									'Rendered on the front end from the parent Quiz state.',
									'extroverting'
								)
							),
					  ];

				return el(
					'div',
					blockProps,
					el(
						components.Placeholder,
						{
							icon: meta.icon,
							label: meta.title,
							instructions: meta.description,
						},
						controls
					)
				);
			},
			save: function () {
				// The container is dynamic but must still serialise its children.
				return isQuiz ? el( blockEditor.InnerBlocks.Content ) : null;
			},
		} );
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.components,
	window.wp.blockEditor,
	window.wp.i18n
);
