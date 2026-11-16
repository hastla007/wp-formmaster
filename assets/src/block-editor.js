/**
 * Block editor script for `form-captain/form` (P3-02, P3-23).
 * ES2019 without JSX; uses WordPress globals only.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var components = wp.components;
	var blockEditor = wp.blockEditor || {};
	var ServerSideRender = wp.serverSideRender;
	var useBlockProps = blockEditor.useBlockProps || function ( props ) {
		return props || {};
	};
	var InspectorControls = blockEditor.InspectorControls;

	var formsCache = null;

	function loadForms() {
		if ( formsCache ) {
			return formsCache;
		}
		formsCache = wp.apiFetch( { path: '/form-captain/v1/admin/forms?status=published&per_page=100' } )
			.then( function ( res ) {
				var items = Array.isArray( res ) ? res : ( res && Array.isArray( res.items ) ? res.items : [] );
				return items.map( function ( f ) {
					return { id: String( f.public_id || f.id ), title: String( f.title || f.public_id || f.id ) };
				} );
			} )
			.catch( function () {
				formsCache = null;
				return [];
			} );
		return formsCache;
	}

	function Edit( props ) {
		var formId = props.attributes.formId || '';
		var state = useState( null );
		var forms = state[ 0 ];
		var setForms = state[ 1 ];

		useEffect( function () {
			var alive = true;
			loadForms().then( function ( list ) {
				if ( alive ) {
					setForms( list );
				}
			} );
			return function () {
				alive = false;
			};
		}, [] );

		var options = [ { value: '', label: __( '— Select a form —', 'form-captain' ) } ].concat(
			( forms || [] ).map( function ( f ) {
				return { value: f.id, label: f.title };
			} )
		);
		var missing = formId && forms && ! forms.some( function ( f ) {
			return f.id === formId;
		} );

		var select = el( components.SelectControl, {
			label: __( 'Form', 'form-captain' ),
			value: formId,
			options: options,
			onChange: function ( value ) {
				props.setAttributes( { formId: value } );
			},
			__nextHasNoMarginBottom: true,
		} );

		var inspector = InspectorControls ? el(
			InspectorControls,
			null,
			el(
				components.PanelBody,
				{ title: __( 'Form', 'form-captain' ), initialOpen: true },
				select,
				el(
					'p',
					{ className: 'components-base-control__help' },
					__( 'SEO: page title, meta description, headings and indexing rules are managed in WordPress or your SEO plugin (e.g. Yoast SEO). The form is embedded page content and does not replace helpful page content.', 'form-captain' )
				)
			)
		) : null;

		var body;
		if ( ! formId ) {
			body = el(
				components.Placeholder,
				{ icon: 'feedback', label: __( 'Form Captain', 'form-captain' ), instructions: forms && ! forms.length ? __( 'No published forms found. Publish a form under Form Captain → Forms first.', 'form-captain' ) : __( 'Choose a published form to embed.', 'form-captain' ) },
				forms === null ? el( components.Spinner ) : select
			);
		} else {
			body = el(
				'div',
				null,
				missing ? el(
					components.Notice,
					{ status: 'warning', isDismissible: false },
					__( 'This form is no longer published (paused, archived or deleted). Visitors will not see a working form. Choose another form or publish it again.', 'form-captain' )
				) : null,
				ServerSideRender ? el( ServerSideRender, { block: 'form-captain/form', attributes: { formId: formId } } ) : null
			);
		}

		return el( 'div', useBlockProps(), inspector, body );
	}

	wp.blocks.registerBlockType( 'form-captain/form', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
