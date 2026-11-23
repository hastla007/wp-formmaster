/**
 * Form Captain – form editor (P2-05 … P2-15, P8-27).
 *
 * ES2019, no JSX, no imports: uses the WordPress globals wp.element, wp.components,
 * wp.i18n and wp.apiFetch. Saving goes through the admin REST routes with revision.
 */
( function ( wp, boot ) {
	'use strict';

	if ( ! wp || ! boot ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var C = wp.components;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var apiFetch = wp.apiFetch;

	var TYPES = {};
	( boot.fieldTypes || [] ).forEach( function ( t ) {
		TYPES[ t.type ] = t;
	} );
	var ACTIONS = {};
	( boot.actions || [] ).forEach( function ( a ) {
		ACTIONS[ a.key ] = a;
	} );
	var MSG = boot.messages || {};
	var SUGGEST = boot.fieldSuggestions || {};
	var KEY_RE = /^[a-z][a-z0-9_]{0,39}$/;
	var OPTION_RE = /^[A-Za-z0-9_\-]{1,64}$/;
	var CSS_RE = /^[A-Za-z0-9_\- ]{0,100}$/;

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function clone( v ) {
		return JSON.parse( JSON.stringify( v ) );
	}

	function randomId( prefix, len, alphabet ) {
		var out = prefix;
		var bytes = new Uint8Array( len );
		window.crypto.getRandomValues( bytes );
		for ( var i = 0; i < len; i++ ) {
			out += alphabet[ bytes[ i ] % alphabet.length ];
		}
		return out;
	}

	function newFieldId( used ) {
		var id;
		do {
			id = randomId( 'f_', 10, 'abcdefghijklmnopqrstuvwxyz0123456789' );
		} while ( used.indexOf( id ) !== -1 );
		return id;
	}

	function newInstanceId() {
		return randomId( 'a_', 10, '0123456789abcdef' );
	}

	function hasValue( type ) {
		return !! ( TYPES[ type ] && TYPES[ type ].has_value );
	}

	function uniqueKey( base, fields, exceptId ) {
		base = ( base || 'field' ).toLowerCase().replace( /[^a-z0-9_]+/g, '_' ).replace( /^[^a-z]+/, '' ).slice( 0, 34 ) || 'field';
		var key = base;
		var n = 2;
		var taken = function ( k ) {
			return fields.some( function ( f ) {
				return f.key === k && f.id !== exceptId;
			} );
		};
		while ( taken( key ) ) {
			key = base + '_' + n;
			n++;
		}
		return key;
	}

	function message( code ) {
		return MSG[ code ] || code;
	}

	function fieldLabel( f ) {
		return f.label || f.key || ( TYPES[ f.type ] ? TYPES[ f.type ].label : f.type );
	}

	/** Maps server errors (fields.N.prop) to field IDs using the schema they were computed for. */
	function mapErrors( errors, schemaFields ) {
		var byField = {};
		var other = {};
		Object.keys( errors || {} ).forEach( function ( path ) {
			var m = /^fields\.(\d+)(?:\.(.+))?$/.exec( path );
			if ( m && schemaFields[ +m[ 1 ] ] ) {
				var id = schemaFields[ +m[ 1 ] ].id;
				byField[ id ] = byField[ id ] || {};
				byField[ id ][ m[ 2 ] || '_' ] = errors[ path ];
			} else {
				other[ path ] = errors[ path ];
			}
		} );
		return { fields: byField, other: other };
	}

	/** Client-side checks mirroring the server validator (P2-08). */
	function clientErrors( fields ) {
		var out = {};
		var keys = {};
		fields.forEach( function ( f, i ) {
			var e = {};
			if ( ! KEY_RE.test( f.key || '' ) ) {
				e.key = 'invalid_key';
			} else if ( keys[ f.key ] ) {
				e.key = 'duplicate_key';
			}
			keys[ f.key ] = true;
			if ( [ 'html', 'hidden' ].indexOf( f.type ) === -1 && ! ( f.label || '' ).trim() ) {
				e.label = 'label_required';
			}
			if ( ! CSS_RE.test( f.css_class || '' ) ) {
				e.css_class = 'invalid_key';
			}
			if ( TYPES[ f.type ] && TYPES[ f.type ].has_options ) {
				var opts = f.options || [];
				var seen = {};
				if ( ! opts.length ) {
					e.options = 'options_required';
				}
				opts.forEach( function ( o ) {
					if ( ! OPTION_RE.test( o.value || '' ) || ! ( o.label || '' ).trim() ) {
						e.options = 'invalid_option';
					} else if ( seen[ o.value ] ) {
						e.options = 'duplicate_option';
					}
					seen[ o.value ] = true;
				} );
			}
			if ( f.type === 'consent' && ! ( f.consent_text || '' ).replace( /<[^>]*>/g, '' ).trim() ) {
				e.consent_text = 'consent_text_required';
			}
			if ( f.condition ) {
				var idx = -1;
				fields.forEach( function ( g, j ) {
					if ( g.id === f.condition.field ) {
						idx = j;
					}
				} );
				if ( idx === -1 || idx >= i || ! hasValue( fields[ idx ].type ) ) {
					e.condition = 'invalid_condition_field';
				}
			}
			if ( Object.keys( e ).length ) {
				out[ f.id ] = e;
			}
		} );
		return out;
	}

	function defaultField( type, fields, used ) {
		var def = TYPES[ type ] || { label: type };
		var f = {
			id: newFieldId( used ),
			type: type,
			key: uniqueKey( type, fields ),
			label: type === 'html' || type === 'hidden' ? '' : def.label,
			required: false,
			help: '',
			css_class: '',
			sensitive: false,
			validation: {},
			condition: null,
		};
		if ( def.has_placeholder ) {
			f.placeholder = '';
		}
		// Country profile (SET-FC-12): new phone fields start with the suggested format.
		if ( type === 'tel' && SUGGEST.tel_format ) {
			f.validation = { format: SUGGEST.tel_format };
		}
		if ( type !== 'consent' && type !== 'html' ) {
			f[ 'default' ] = '';
		}
		if ( def.has_options ) {
			f.options = [
				{ value: 'option_1', label: __( 'Option 1', 'form-captain' ) },
				{ value: 'option_2', label: __( 'Option 2', 'form-captain' ) },
			];
		}
		if ( type === 'textarea' ) {
			f.rows = 5;
		}
		if ( type === 'html' ) {
			f.content = '<p>' + __( 'Note text', 'form-captain' ) + '</p>';
		}
		if ( type === 'consent' ) {
			f.label = __( 'Consent', 'form-captain' );
			f.required = true;
			f.consent_text = __( 'I agree that my details will be stored to process my request.', 'form-captain' );
			f.privacy_link = boot.consentLink !== false; // Setting privacy.policy_page_hint.
			f.consent_revision = 1;
		}
		if ( type === 'email' ) {
			f.confirmation_recipient = false;
			f.privacy_lookup = true;
		}
		return f;
	}

	/* ------------------------------------------------------------------ */
	/* Small components                                                    */
	/* ------------------------------------------------------------------ */

	var errorSeq = 0;

	/**
	 * Inline error below a control. Links itself to the control just before it (SET-FC-P5-15):
	 * aria-invalid="true" and its id in aria-describedby, removed again when the error goes away.
	 * The WordPress components create their own input ids, so the link is set on the DOM node.
	 */
	function FieldError( props ) {
		var ref = useRef( null );
		var auto = useRef( null );
		if ( ! auto.current ) {
			errorSeq += 1;
			auto.current = 'fc-field-error-' + errorSeq;
		}
		var id = props.id || auto.current;
		var code = props.code;
		useEffect( function () {
			var node = ref.current;
			var control = node ? errorControl( node ) : null;
			if ( ! control ) {
				return undefined;
			}
			var before = control.getAttribute( 'aria-describedby' );
			var hadInvalid = control.hasAttribute( 'aria-invalid' );
			var ids = ( before || '' ).split( /\s+/ ).filter( function ( x ) {
				return x && x !== id;
			} );
			control.setAttribute( 'aria-describedby', ids.concat( [ id ] ).join( ' ' ) );
			control.setAttribute( 'aria-invalid', 'true' );
			return function () {
				var rest = ( control.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( function ( x ) {
					return x && x !== id;
				} );
				if ( rest.length ) {
					control.setAttribute( 'aria-describedby', rest.join( ' ' ) );
				} else {
					control.removeAttribute( 'aria-describedby' );
				}
				if ( ! hadInvalid ) {
					control.removeAttribute( 'aria-invalid' );
				}
			};
		}, [ code, id ] );
		if ( ! code ) {
			return null;
		}
		return el( 'p', { className: 'fc-field-error', id: id, role: 'alert', ref: ref }, message( code ) );
	}

	/** Form control an inline error belongs to: the last control in the element before it. */
	function errorControl( node ) {
		var prev = node.previousElementSibling;
		while ( prev && prev.classList && prev.classList.contains( 'fc-field-error' ) ) {
			prev = prev.previousElementSibling;
		}
		if ( ! prev ) {
			return null;
		}
		if ( prev.matches && prev.matches( 'input, select, textarea' ) ) {
			return prev;
		}
		var list = prev.querySelectorAll ? prev.querySelectorAll( 'input:not([type=hidden]), select, textarea' ) : [];
		return list.length ? list[ list.length - 1 ] : null;
	}

	function NumberInput( props ) {
		return el( C.TextControl, {
			label: props.label,
			type: 'number',
			value: props.value === undefined || props.value === null ? '' : String( props.value ),
			onChange: function ( v ) {
				props.onChange( v === '' ? undefined : v );
			},
			help: props.help,
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Field settings panel                                                */
	/* ------------------------------------------------------------------ */

	function OptionsEditor( props ) {
		var options = props.options || [];
		var update = function ( next ) {
			props.onChange( next );
		};
		return el(
			'fieldset',
			{ className: 'fc-options' },
			el( 'legend', null, __( 'Options', 'form-captain' ) ),
			options.map( function ( o, i ) {
				return el(
					'div',
					{ className: 'fc-option-row', key: i },
					el( C.TextControl, {
						label: sprintf( __( 'Label of option %d', 'form-captain' ), i + 1 ),
						hideLabelFromVision: i > 0,
						value: o.label,
						onChange: function ( v ) {
							var next = clone( options );
							next[ i ].label = v;
							if ( ! next[ i ].value || next[ i ].value === 'option_' + ( i + 1 ) ) {
								var slug = v.toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).replace( /[^a-z0-9_\-]+/g, '_' ).replace( /^_+|_+$/g, '' ).slice( 0, 64 );
								if ( slug && ! options.some( function ( p, j ) {
									return j !== i && p.value === slug;
								} ) ) {
									next[ i ].value = slug;
								}
							}
							update( next );
						},
					} ),
					el( C.TextControl, {
						label: sprintf( __( 'Value of option %d', 'form-captain' ), i + 1 ),
						hideLabelFromVision: i > 0,
						value: o.value,
						onChange: function ( v ) {
							var next = clone( options );
							next[ i ].value = v;
							update( next );
						},
					} ),
					el( C.Button, {
						icon: 'arrow-up-alt2',
						label: sprintf( __( 'Move option %d up', 'form-captain' ), i + 1 ),
						disabled: i === 0,
						onClick: function () {
							var next = clone( options );
							next.splice( i - 1, 0, next.splice( i, 1 )[ 0 ] );
							update( next );
						},
					} ),
					el( C.Button, {
						icon: 'arrow-down-alt2',
						label: sprintf( __( 'Move option %d down', 'form-captain' ), i + 1 ),
						disabled: i === options.length - 1,
						onClick: function () {
							var next = clone( options );
							next.splice( i + 1, 0, next.splice( i, 1 )[ 0 ] );
							update( next );
						},
					} ),
					el( C.Button, {
						icon: 'trash',
						isDestructive: true,
						label: sprintf( __( 'Remove option %d', 'form-captain' ), i + 1 ),
						onClick: function () {
							var next = clone( options );
							next.splice( i, 1 );
							update( next );
						},
					} )
				);
			} ),
			el(
				C.Button,
				{
					variant: 'secondary',
					onClick: function () {
						var next = clone( options );
						var n = next.length + 1;
						next.push( { value: 'option_' + n, label: sprintf( __( 'Option %d', 'form-captain' ), n ) } );
						update( next );
					},
				},
				__( 'Add option', 'form-captain' )
			),
			el( FieldError, { code: props.error } )
		);
	}

	function ConditionEditor( props ) {
		var field = props.field;
		var previous = props.previous.filter( function ( f ) {
			return hasValue( f.type );
		} );
		var cond = field.condition;
		if ( ! previous.length ) {
			return el( 'p', { className: 'description' }, __( 'Conditions can refer to fields above this one. Add an input field before it to use a condition.', 'form-captain' ) );
		}
		var set = function ( patch ) {
			props.onChange( patch === null ? null : Object.assign( {}, cond || {}, patch ) );
		};
		var ref = null;
		if ( cond ) {
			previous.forEach( function ( f ) {
				if ( f.id === cond.field ) {
					ref = f;
				}
			} );
		}
		var needsValue = cond && [ 'equals', 'not_equals', 'contains' ].indexOf( cond.operator ) !== -1;
		return el(
			'fieldset',
			{ className: 'fc-condition' },
			el( 'legend', null, __( 'Condition', 'form-captain' ) ),
			el( C.CheckboxControl, {
				label: __( 'Show or hide this field depending on another field', 'form-captain' ),
				checked: !! cond,
				onChange: function ( on ) {
					set( on ? { action: 'show', field: previous[ previous.length - 1 ].id, operator: 'is_not_empty', value: '' } : null );
				},
			} ),
			cond &&
				el(
					Fragment,
					null,
					el( C.SelectControl, {
						label: __( 'Action', 'form-captain' ),
						value: cond.action,
						options: [
							{ value: 'show', label: __( 'Show this field if', 'form-captain' ) },
							{ value: 'hide', label: __( 'Hide this field if', 'form-captain' ) },
						],
						onChange: function ( v ) {
							set( { action: v } );
						},
					} ),
					el( C.SelectControl, {
						label: __( 'Field', 'form-captain' ),
						value: cond.field,
						options: [ { value: '', label: __( '— Select —', 'form-captain' ) } ].concat(
							previous.map( function ( f ) {
								return { value: f.id, label: fieldLabel( f ) };
							} )
						),
						onChange: function ( v ) {
							set( { field: v, value: '' } );
						},
					} ),
					el( C.SelectControl, {
						label: __( 'Operator', 'form-captain' ),
						value: cond.operator,
						options: [
							{ value: 'equals', label: __( 'equals', 'form-captain' ) },
							{ value: 'not_equals', label: __( 'does not equal', 'form-captain' ) },
							{ value: 'contains', label: __( 'contains', 'form-captain' ) },
							{ value: 'is_empty', label: __( 'is empty', 'form-captain' ) },
							{ value: 'is_not_empty', label: __( 'is not empty', 'form-captain' ) },
							{ value: 'checked', label: __( 'is checked', 'form-captain' ) },
						],
						onChange: function ( v ) {
							set( { operator: v } );
						},
					} ),
					needsValue &&
						( ref && ref.options && ref.options.length
							? el( C.SelectControl, {
								label: __( 'Value', 'form-captain' ),
								value: cond.value,
								options: [ { value: '', label: __( '— Select —', 'form-captain' ) } ].concat(
									ref.options.map( function ( o ) {
										return { value: o.value, label: o.label };
									} )
								),
								onChange: function ( v ) {
									set( { value: v } );
								},
							} )
							: el( C.TextControl, {
								label: __( 'Value', 'form-captain' ),
								value: cond.value || '',
								onChange: function ( v ) {
									set( { value: v } );
								},
							} ) ),
					el( FieldError, { code: props.error } )
				)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Settings of extensions (contract 1.1.0, form_captain_editor_extensions_v1) */
	/* ------------------------------------------------------------------ */

	var OPERATORS = [
		{ value: 'equals', label: __( 'equals', 'form-captain' ) },
		{ value: 'not_equals', label: __( 'does not equal', 'form-captain' ) },
		{ value: 'contains', label: __( 'contains', 'form-captain' ) },
		{ value: 'is_empty', label: __( 'is empty', 'form-captain' ) },
		{ value: 'is_not_empty', label: __( 'is not empty', 'form-captain' ) },
		{ value: 'checked', label: __( 'is checked', 'form-captain' ) },
	];

	/** Group of rules: { action, match, rules: [ { field, operator, value } ] } on the given fields. */
	function RulesControl( props ) {
		var group = props.value && Array.isArray( props.value.rules ) ? props.value : null;
		var candidates = props.fields.filter( function ( f ) {
			return hasValue( f.type );
		} );
		if ( ! candidates.length ) {
			return el( 'p', { className: 'description' }, __( 'Rules can refer to fields above this one. Add an input field before it first.', 'form-captain' ) );
		}
		var set = function ( next ) {
			props.onChange( next );
		};
		var rules = group ? group.rules : [];
		var setRule = function ( i, patch ) {
			var next = rules.slice();
			next[ i ] = Object.assign( {}, next[ i ], patch );
			set( Object.assign( {}, group, { rules: next } ) );
		};
		return el(
			'div',
			{ className: 'fc-rules' },
			el( C.CheckboxControl, {
				label: props.label,
				checked: !! group,
				onChange: function ( on ) {
					set( on ? { action: 'show', match: 'all', rules: [ { field: candidates[ candidates.length - 1 ].id, operator: 'is_not_empty', value: '' } ] } : null );
				},
			} ),
			group &&
				el(
					Fragment,
					null,
					props.withAction &&
						el( C.SelectControl, {
							label: __( 'Action', 'form-captain' ),
							value: group.action || 'show',
							options: [
								{ value: 'show', label: __( 'Show this field if', 'form-captain' ) },
								{ value: 'hide', label: __( 'Hide this field if', 'form-captain' ) },
							],
							onChange: function ( v ) {
								set( Object.assign( {}, group, { action: v } ) );
							},
						} ),
					el( C.SelectControl, {
						label: __( 'Match', 'form-captain' ),
						value: group.match || 'all',
						options: [
							{ value: 'all', label: __( 'all rules (AND)', 'form-captain' ) },
							{ value: 'any', label: __( 'at least one rule (OR)', 'form-captain' ) },
						],
						onChange: function ( v ) {
							set( Object.assign( {}, group, { match: v } ) );
						},
					} ),
					rules.map( function ( r, i ) {
						var needsValue = [ 'equals', 'not_equals', 'contains' ].indexOf( r.operator ) !== -1;
						return el(
							'div',
							{ key: i, className: 'fc-rule' },
							el( C.SelectControl, {
								label: __( 'Field', 'form-captain' ),
								value: r.field,
								options: candidates.map( function ( f ) {
									return { value: f.id, label: fieldLabel( f ) };
								} ),
								onChange: function ( v ) {
									setRule( i, { field: v, value: '' } );
								},
							} ),
							el( C.SelectControl, {
								label: __( 'Operator', 'form-captain' ),
								value: r.operator,
								options: OPERATORS,
								onChange: function ( v ) {
									setRule( i, { operator: v } );
								},
							} ),
							needsValue &&
								el( C.TextControl, {
									label: __( 'Value', 'form-captain' ),
									value: r.value || '',
									onChange: function ( v ) {
										setRule( i, { value: v } );
									},
								} ),
							el( C.Button, {
								variant: 'link',
								isDestructive: true,
								onClick: function () {
									var next = rules.filter( function ( x, j ) {
										return j !== i;
									} );
									set( next.length ? Object.assign( {}, group, { rules: next } ) : null );
								},
							}, __( 'Remove rule', 'form-captain' ) )
						);
					} ),
					rules.length < 10 &&
						el( C.Button, {
							variant: 'secondary',
							onClick: function () {
								set( Object.assign( {}, group, { rules: rules.concat( [ { field: candidates[ 0 ].id, operator: 'is_not_empty', value: '' } ] ) } ) );
							},
						}, __( 'Add rule', 'form-captain' ) )
				)
		);
	}

	/** JSON for structures without their own control; the server checks and returns errors. */
	function JsonControl( props ) {
		var initial = props.value === undefined || props.value === null ? '' : JSON.stringify( props.value, null, 2 );
		var state = useState( initial );
		var text = state[ 0 ];
		var setText = state[ 1 ];
		var bad = useState( false );
		return el(
			Fragment,
			null,
			el( C.TextareaControl, {
				label: props.label,
				help: props.help,
				rows: 8,
				className: 'fc-json',
				value: text,
				onChange: function ( v ) {
					setText( v );
					if ( v.trim() === '' ) {
						bad[ 1 ]( false );
						props.onChange( null );
						return;
					}
					try {
						props.onChange( JSON.parse( v ) );
						bad[ 1 ]( false );
					} catch ( e ) {
						bad[ 1 ]( true );
					}
				},
			} ),
			bad[ 0 ] && el( 'p', { className: 'fc-error' }, __( 'This is not valid JSON yet; the last valid value is kept.', 'form-captain' ) )
		);
	}

	function ExtensionControl( props ) {
		var c = props.control;
		var v = props.value;
		var change = props.onChange;
		switch ( c.type ) {
			case 'rules':
				return el( RulesControl, { label: c.label, value: v, fields: props.fields, withAction: props.scope === 'field', onChange: change } );
			case 'json':
				return el( JsonControl, { label: c.label, help: c.help, value: v, onChange: change } );
			case 'checkbox':
				return el( C.CheckboxControl, { label: c.label, help: c.help, checked: !! v, onChange: change } );
			case 'select':
				return el( C.SelectControl, { label: c.label, help: c.help, value: v === undefined || v === null ? '' : String( v ), options: c.options, onChange: change } );
			case 'number':
				return el( NumberInput, { label: c.label, value: v, onChange: change } );
			case 'textarea':
				return el( C.TextareaControl, { label: c.label, help: c.help, rows: 3, value: v || '', onChange: change } );
			default:
				return el( C.TextControl, { label: c.label, help: c.help, value: v || '', onChange: change } );
		}
	}

	/**
	 * Panels of extensions for one scope. Values live in obj.x[control.key]; the core never
	 * interprets them, the extension checks them on save (errors under x.<key>).
	 */
	function ExtensionPanels( props ) {
		var panels = ( boot.extensions || [] ).filter( function ( p ) {
			return p.scope === props.scope && ( ! p.types || ! p.types.length || ( props.type && p.types.indexOf( props.type ) !== -1 ) );
		} );
		if ( ! panels.length ) {
			return null;
		}
		var x = props.x || {};
		return panels.map( function ( p ) {
			return el(
				'fieldset',
				{ key: 'ext-' + p.key, className: 'fc-extension fc-extension--' + p.key },
				el( 'legend', null, p.label ),
				p.description && el( 'p', { className: 'description' }, p.description ),
				p.controls.map( function ( c ) {
					var errs = Object.keys( props.errors || {} ).filter( function ( k ) {
						return k === 'x.' + c.key || k.indexOf( 'x.' + c.key + '.' ) === 0;
					} );
					return el(
						Fragment,
						{ key: c.key },
						el( ExtensionControl, {
							control: c,
							scope: props.scope,
							value: x[ c.key ],
							fields: props.fields,
							onChange: function ( v ) {
								var next = Object.assign( {}, x );
								if ( v === null || v === undefined || v === '' ) {
									delete next[ c.key ];
								} else {
									next[ c.key ] = v;
								}
								props.onChange( next );
							},
						} ),
						errs.map( function ( k ) {
							return el( FieldError, { key: k, code: props.errors[ k ] } );
						} )
					);
				} )
			);
		} );
	}

	function FieldSettings( props ) {
		var f = props.field;
		var err = props.errors || {};
		var def = TYPES[ f.type ] || {};
		var set = function ( patch ) {
			props.onChange( Object.assign( {}, f, patch ) );
		};
		var setRule = function ( name, value ) {
			var v = Object.assign( {}, f.validation || {} );
			if ( value === undefined || value === '' ) {
				delete v[ name ];
			} else {
				v[ name ] = value;
			}
			set( { validation: v } );
		};
		var rules = f.validation || {};
		var parts = [];

		parts.push( el( 'h2', { key: 'h', className: 'fc-panel-title' }, sprintf( __( '%s – settings', 'form-captain' ), def.label || f.type ) ) );
		if ( f.type !== 'html' ) {
			parts.push(
				el( C.TextControl, {
					key: 'label',
					label: f.type === 'hidden' ? __( 'Label (internal)', 'form-captain' ) : __( 'Label', 'form-captain' ),
					value: f.label || '',
					onChange: function ( v ) {
						set( { label: v } );
					},
				} ),
				el( FieldError, { key: 'label-e', code: err.label } )
			);
		}
		parts.push(
			el( C.TextControl, {
				key: 'key',
				label: __( 'Field key', 'form-captain' ),
				help: __( 'Used for export columns and email placeholders like {field:key}.', 'form-captain' ),
				value: f.key || '',
				onChange: function ( v ) {
					set( { key: v.toLowerCase() } );
				},
			} ),
			el( FieldError, { key: 'key-e', code: err.key } )
		);
		if ( [ 'html', 'hidden' ].indexOf( f.type ) === -1 ) {
			parts.push(
				el( C.CheckboxControl, {
					key: 'req',
					label: __( 'Required', 'form-captain' ),
					checked: !! f.required,
					onChange: function ( v ) {
						set( { required: v } );
					},
				} )
			);
		}
		if ( f.type === 'html' ) {
			parts.push(
				el( C.TextareaControl, {
					key: 'content',
					label: __( 'Content (HTML)', 'form-captain' ),
					help: __( 'Allowed: p, br, strong, em, b, i, ul, ol, li, a, h3, h4, span. Everything else is removed when saving.', 'form-captain' ),
					rows: 6,
					value: f.content || '',
					onChange: function ( v ) {
						set( { content: v } );
					},
				} )
			);
		}
		if ( f.type === 'consent' ) {
			parts.push(
				el( C.TextareaControl, {
					key: 'consent',
					label: __( 'Consent text', 'form-captain' ),
					help: sprintf( __( 'Revision %d – changing the text creates a new revision, earlier entries keep their version.', 'form-captain' ), f.consent_revision || 1 ),
					rows: 4,
					value: f.consent_text || '',
					onChange: function ( v ) {
						var orig = props.originalConsent[ f.id ];
						var rev = orig ? ( v.trim() === orig.text.trim() ? orig.revision : orig.revision + 1 ) : f.consent_revision || 1;
						set( { consent_text: v, consent_revision: rev } );
					},
				} ),
				el( FieldError, { key: 'consent-e', code: err.consent_text || err[ 'default' ] } ),
				el( C.CheckboxControl, {
					key: 'plink',
					label: __( 'Link the privacy policy page', 'form-captain' ),
					checked: !! f.privacy_link,
					onChange: function ( v ) {
						set( { privacy_link: v } );
					},
				} )
			);
		}
		if ( f.type !== 'html' ) {
			parts.push(
				el( C.TextareaControl, {
					key: 'help',
					label: __( 'Help text', 'form-captain' ),
					rows: 2,
					value: f.help || '',
					onChange: function ( v ) {
						set( { help: v } );
					},
				} )
			);
		}
		if ( def.has_placeholder ) {
			parts.push(
				el( C.TextControl, {
					key: 'ph',
					label: __( 'Placeholder', 'form-captain' ),
					value: f.placeholder || '',
					onChange: function ( v ) {
						set( { placeholder: v } );
					},
				} )
			);
		}
		if ( f.type !== 'consent' && f.type !== 'html' ) {
			parts.push(
				el( C.TextControl, {
					key: 'def',
					label: f.type === 'hidden' ? __( 'Value', 'form-captain' ) : __( 'Default value', 'form-captain' ),
					help: f.type === 'hidden' ? __( 'Hidden values can be changed by visitors and are never trusted.', 'form-captain' ) : undefined,
					value: f[ 'default' ] || '',
					onChange: function ( v ) {
						var p = {};
						p[ 'default' ] = v;
						set( p );
					},
				} )
			);
		}
		if ( def.has_options ) {
			parts.push(
				el( OptionsEditor, {
					key: 'opts',
					options: f.options,
					error: err.options || err[ Object.keys( err ).filter( function ( k ) {
						return k.indexOf( 'options' ) === 0;
					} )[ 0 ] ],
					onChange: function ( v ) {
						set( { options: v } );
					},
				} )
			);
			if ( f.type === 'select' ) {
				parts.push(
					el( C.CheckboxControl, {
						key: 'multi',
						label: __( 'Allow multiple selections', 'form-captain' ),
						checked: !! f.multiple,
						onChange: function ( v ) {
							set( { multiple: v } );
						},
					} )
				);
			}
		}

		// Limits.
		var limits = [];
		if ( f.type === 'text' || f.type === 'textarea' ) {
			limits.push(
				el( NumberInput, { key: 'min', label: __( 'Minimum length', 'form-captain' ), value: rules.min_length, onChange: function ( v ) {
					setRule( 'min_length', v );
				} } ),
				el( NumberInput, { key: 'max', label: __( 'Maximum length', 'form-captain' ), value: rules.max_length, onChange: function ( v ) {
					setRule( 'max_length', v );
				} } )
			);
		}
		if ( f.type === 'text' ) {
			limits.push(
				el( C.SelectControl, {
					key: 'pattern',
					label: __( 'Allowed characters', 'form-captain' ),
					value: rules.pattern || '',
					options: [ { value: '', label: __( 'Any', 'form-captain' ) } ].concat( boot.patterns || [] ),
					onChange: function ( v ) {
						setRule( 'pattern', v );
					},
				} )
			);
			if ( SUGGEST.zip_pattern && ! rules.pattern ) {
				limits.push(
					el( C.Button, {
						key: 'zipsuggest',
						variant: 'link',
						onClick: function () {
							setRule( 'pattern', SUGGEST.zip_pattern );
						},
					},
					/* translators: %s: postal code pattern, e.g. "German postal code" */
					sprintf( __( 'Postal code field? Use the suggestion of the country profile: %s', 'form-captain' ), SUGGEST.zip_label ) )
				);
			}
			if ( rules.pattern === 'custom' ) {
				limits.push(
					el( C.TextControl, {
						key: 'custom_pattern',
						label: __( 'Own pattern (regular expression)', 'form-captain' ),
						help: __( 'Without delimiters; the whole value must match. Example: [A-Z]{2}-[0-9]{4}. Nested repetitions, back-references and look-arounds are rejected because they can slow down the website.', 'form-captain' ),
						value: rules.custom_pattern || '',
						onChange: function ( v ) {
							setRule( 'custom_pattern', v );
						},
					} )
				);
			}
		}
		if ( f.type === 'tel' ) {
			limits.push(
				el( C.SelectControl, {
					key: 'telformat',
					label: __( 'Phone number format', 'form-captain' ),
					value: rules.format || '',
					options: [
						{ value: '', label: __( 'Any phone number', 'form-captain' ) },
						{ value: 'international', label: __( 'International with + and country code', 'form-captain' ) },
					],
					onChange: function ( v ) {
						setRule( 'format', v );
					},
				} )
			);
		}
		if ( f.type === 'textarea' ) {
			limits.push( el( NumberInput, { key: 'rows', label: __( 'Rows', 'form-captain' ), value: f.rows, onChange: function ( v ) {
				set( { rows: v } );
			} } ) );
		}
		if ( f.type === 'email' ) {
			limits.push(
				el( C.CheckboxControl, {
					key: 'cr',
					label: __( 'Send the confirmation email to this address', 'form-captain' ),
					checked: !! f.confirmation_recipient,
					onChange: function ( v ) {
						set( { confirmation_recipient: v } );
					},
				} ),
				el( C.CheckboxControl, {
					key: 'pl',
					label: __( 'Use for privacy requests (export/erase personal data)', 'form-captain' ),
					checked: f.privacy_lookup !== false,
					onChange: function ( v ) {
						set( { privacy_lookup: v } );
					},
				} )
			);
		}
		if ( f.type === 'number' ) {
			[ [ 'min', __( 'Minimum', 'form-captain' ) ], [ 'max', __( 'Maximum', 'form-captain' ) ], [ 'step', __( 'Step', 'form-captain' ) ] ].forEach( function ( r ) {
				limits.push( el( NumberInput, { key: r[ 0 ], label: r[ 1 ], value: rules[ r[ 0 ] ], onChange: function ( v ) {
					setRule( r[ 0 ], v );
				} } ) );
			} );
		}
		if ( f.type === 'date' ) {
			[ [ 'min', __( 'Earliest date', 'form-captain' ) ], [ 'max', __( 'Latest date', 'form-captain' ) ] ].forEach( function ( r ) {
				limits.push(
					el( C.TextControl, {
						key: r[ 0 ],
						label: r[ 1 ],
						help: __( 'YYYY-MM-DD or "today"', 'form-captain' ),
						value: rules[ r[ 0 ] ] || '',
						onChange: function ( v ) {
							setRule( r[ 0 ], v );
						},
					} )
				);
			} );
			limits.push(
				el( NumberInput, {
					key: 'minage',
					label: __( 'Minimum age (years)', 'form-captain' ),
					help: __( 'Only dates of persons who are at least this old are accepted. Empty = no age check. Have the required age checked legally for your country and purpose.', 'form-captain' ),
					value: rules.min_age,
					onChange: function ( v ) {
						setRule( 'min_age', v === undefined ? v : parseInt( v, 10 ) || v );
					},
				} )
			);
		}
		if ( f.type === 'checkbox' ) {
			limits.push(
				el( NumberInput, { key: 'minc', label: __( 'Minimum choices', 'form-captain' ), value: rules.min_choices, onChange: function ( v ) {
					setRule( 'min_choices', v );
				} } ),
				el( NumberInput, { key: 'maxc', label: __( 'Maximum choices', 'form-captain' ), value: rules.max_choices, onChange: function ( v ) {
					setRule( 'max_choices', v );
				} } )
			);
		}
		if ( hasValue( f.type ) && f.type !== 'hidden' ) {
			limits.push(
				el( C.TextControl, {
					key: 'vmsg',
					label: __( 'Own error message', 'form-captain' ),
					help: __( 'Shown instead of the standard message when the value is missing or invalid. Empty = standard message.', 'form-captain' ),
					value: rules.message || '',
					onChange: function ( v ) {
						setRule( 'message', v );
					},
				} )
			);
		}
		if ( ( boot.autocompleteTypes || [] ).indexOf( f.type ) !== -1 ) {
			limits.push(
				el( C.SelectControl, {
					key: 'autocomplete',
					label: __( 'Autofill (browser)', 'form-captain' ),
					help: __( 'Tells the browser which saved data fits this field, e.g. name or postal code.', 'form-captain' ),
					value: f.autocomplete || '',
					options: [ { value: '', label: __( 'Automatic', 'form-captain' ) } ].concat( boot.autocomplete || [] ),
					onChange: function ( v ) {
						set( { autocomplete: v || undefined } );
					},
				} ),
				el( FieldError, { key: 'autocomplete-e', code: err.autocomplete } )
			);
		}
		var ruleErr = Object.keys( err ).filter( function ( k ) {
			return k.indexOf( 'validation' ) === 0;
		} )[ 0 ];
		if ( limits.length ) {
			parts.push( el( 'fieldset', { key: 'limits', className: 'fc-limits' }, el( 'legend', null, __( 'Limits', 'form-captain' ) ), limits, el( FieldError, { code: ruleErr ? err[ ruleErr ] : '' } ) ) );
		}

		parts.push(
			el( C.TextControl, {
				key: 'css',
				label: __( 'CSS class', 'form-captain' ),
				value: f.css_class || '',
				onChange: function ( v ) {
					set( { css_class: v } );
				},
			} ),
			el( FieldError, { key: 'css-e', code: err.css_class } )
		);
		if ( hasValue( f.type ) ) {
			parts.push(
				el( C.CheckboxControl, {
					key: 'sens',
					label: __( 'Sensitive (not included in emails and connector actions by default)', 'form-captain' ),
					checked: !! f.sensitive,
					onChange: function ( v ) {
						set( { sensitive: v } );
					},
				} )
			);
		}
		parts.push(
			el( ConditionEditor, {
				key: 'cond',
				field: f,
				previous: props.previous,
				error: err.condition || err[ 'condition.field' ] || err[ 'condition.operator' ],
				onChange: function ( c ) {
					set( { condition: c } );
				},
			} ),
			el( ExtensionPanels, {
				key: 'ext',
				scope: 'field',
				type: f.type,
				x: f.x,
				fields: props.previous,
				errors: err,
				onChange: function ( x ) {
					set( { x: Object.keys( x ).length ? x : undefined } );
				},
			} )
		);
		if ( err._ || err.type || err.id ) {
			parts.push( el( FieldError, { key: 'gen', code: err._ || err.type || err.id } ) );
		}
		return el( 'div', { className: 'fc-field-settings' }, parts );
	}

	/* ------------------------------------------------------------------ */
	/* Fields tab: palette + canvas + settings                             */
	/* ------------------------------------------------------------------ */

	function FieldsTab( props ) {
		var fields = props.fields;
		var selected = props.selected;
		var errors = props.errors;
		var focusRef = useRef( null );

		useEffect( function () {
			if ( focusRef.current ) {
				var node = document.querySelector( '[data-fc-focus="' + focusRef.current + '"]' );
				focusRef.current = null;
				if ( node ) {
					node.focus();
				}
			}
		} );

		var move = function ( i, dir ) {
			var next = fields.slice();
			var j = i + dir;
			if ( j < 0 || j >= next.length ) {
				return;
			}
			next.splice( j, 0, next.splice( i, 1 )[ 0 ] );
			focusRef.current = next[ j ].id + ( dir < 0 ? '-up' : '-down' );
			if ( ( dir < 0 && j === 0 ) || ( dir > 0 && j === next.length - 1 ) ) {
				focusRef.current = next[ j ].id + '-select';
			}
			props.onFields( next );
		};

		var palette = el(
			'div',
			{ className: 'fc-palette', role: 'group', 'aria-labelledby': 'fc-palette-title' },
			el( 'h2', { id: 'fc-palette-title' }, __( 'Add field', 'form-captain' ) ),
			( boot.fieldTypes || [] ).map( function ( t ) {
				return el(
					C.Button,
					{
						key: t.type,
						variant: 'secondary',
						className: 'fc-palette-item',
						onClick: function () {
							props.onAdd( t.type );
						},
					},
					t.label
				);
			} ),
			boot.proHint && el( 'p', { className: 'fc-pro-note' }, boot.proHint )
		);

		var canvas = el(
			'div',
			{ className: 'fc-canvas' },
			el( 'h2', null, __( 'Fields', 'form-captain' ) ),
			! fields.length && el( 'p', { className: 'fc-empty' }, __( 'No fields yet. Add a field from the list.', 'form-captain' ) ),
			el(
				'ol',
				{ className: 'fc-field-list' },
				fields.map( function ( f, i ) {
					var hasErr = !! errors[ f.id ];
					return el(
						'li',
						{
							key: f.id,
							className: 'fc-field-item' + ( selected === f.id ? ' is-selected' : '' ) + ( hasErr ? ' has-error' : '' ),
							draggable: true,
							onDragStart: function ( e ) {
								e.dataTransfer.setData( 'text/plain', String( i ) );
							},
							onDragOver: function ( e ) {
								e.preventDefault();
							},
							onDrop: function ( e ) {
								e.preventDefault();
								var from = parseInt( e.dataTransfer.getData( 'text/plain' ), 10 );
								if ( isNaN( from ) || from === i ) {
									return;
								}
								var next = fields.slice();
								next.splice( i, 0, next.splice( from, 1 )[ 0 ] );
								props.onFields( next );
							},
						},
						el( 'span', { className: 'fc-drag-handle', 'aria-hidden': 'true', title: __( 'Drag to reorder', 'form-captain' ) }, '⋮⋮' ),
						el(
							'button',
							{
								type: 'button',
								className: 'fc-field-select',
								'aria-pressed': selected === f.id,
								'data-fc-focus': f.id + '-select',
								onClick: function () {
									props.onSelect( f.id );
								},
							},
							el( 'span', { className: 'fc-field-label' }, fieldLabel( f ), f.required ? el( 'span', { className: 'fc-required', 'aria-label': __( 'required', 'form-captain' ) }, ' *' ) : null ),
							el( 'span', { className: 'fc-field-meta' }, ( TYPES[ f.type ] ? TYPES[ f.type ].label : f.type ) + ( f.condition ? ' · ' + __( 'conditional', 'form-captain' ) : '' ) + ( f.sensitive ? ' · ' + __( 'sensitive', 'form-captain' ) : '' ) ),
							hasErr && el( 'span', { className: 'fc-field-badge' }, __( 'Needs attention', 'form-captain' ) )
						),
						el(
							'span',
							{ className: 'fc-field-tools' },
							el( C.Button, { icon: 'arrow-up-alt2', label: sprintf( __( 'Move “%s” up', 'form-captain' ), fieldLabel( f ) ), disabled: i === 0, 'data-fc-focus': f.id + '-up', onClick: function () {
								move( i, -1 );
							} } ),
							el( C.Button, { icon: 'arrow-down-alt2', label: sprintf( __( 'Move “%s” down', 'form-captain' ), fieldLabel( f ) ), disabled: i === fields.length - 1, 'data-fc-focus': f.id + '-down', onClick: function () {
								move( i, 1 );
							} } ),
							el( C.Button, { icon: 'admin-page', label: sprintf( __( 'Duplicate “%s”', 'form-captain' ), fieldLabel( f ) ), onClick: function () {
								props.onDuplicate( i );
							} } ),
							el( C.Button, { icon: 'trash', isDestructive: true, label: sprintf( __( 'Remove “%s”', 'form-captain' ), fieldLabel( f ) ), onClick: function () {
								props.onRemove( i );
							} } )
						)
					);
				} )
			)
		);

		var current = null;
		var index = -1;
		fields.forEach( function ( f, i ) {
			if ( f.id === selected ) {
				current = f;
				index = i;
			}
		} );
		var panel = el(
			'div',
			{ className: 'fc-settings-panel', 'aria-live': 'polite' },
			current
				? el( FieldSettings, {
					field: current,
					errors: errors[ current.id ],
					previous: fields.slice( 0, index ),
					originalConsent: props.originalConsent,
					onChange: function ( nf ) {
						var next = fields.slice();
						next[ index ] = nf;
						props.onFields( next );
					},
				} )
				: el( 'p', { className: 'description' }, __( 'Select a field to edit its settings.', 'form-captain' ) )
		);

		return el( 'div', { className: 'fc-fields-tab' }, palette, canvas, panel );
	}

	/* ------------------------------------------------------------------ */
	/* Settings tabs                                                       */
	/* ------------------------------------------------------------------ */

	/** Editor tab that shows the setting at this error path (form errors other than fields). */
	function settingTabOf( path ) {
		if ( /^(schema|fields?)\b/.test( path ) ) {
			return 'fields';
		}
		if ( /^actions\b/.test( path ) ) {
			return 'actions';
		}
		if ( /^confirmation\./.test( path ) ) {
			return 'confirmation';
		}
		return /^(notification|confirmation_mail|email|double_opt_in)\./.test( path ) ? 'notifications' : 'settings';
	}

	/** "Problems in: Fields, Notifications" for the notice after saving. */
	function problemTabs( formErrors, actionErrors ) {
		var titles = {
			fields: __( 'Fields', 'form-captain' ),
			notifications: __( 'Notifications', 'form-captain' ),
			confirmation: __( 'Confirmation', 'form-captain' ),
			settings: __( 'Form settings', 'form-captain' ),
			actions: __( 'Actions', 'form-captain' ),
		};
		var found = {};
		Object.keys( formErrors ).forEach( function ( k ) {
			found[ settingTabOf( k ) ] = true;
		} );
		if ( Object.keys( actionErrors ).length ) {
			found.actions = true;
		}
		var names = Object.keys( titles ).filter( function ( t ) {
			return found[ t ];
		} ).map( function ( t ) {
			return titles[ t ];
		} );
		/* translators: %s: names of the editor tabs, e.g. "Notifications, Form settings" */
		return names.length ? sprintf( __( 'Problems in: %s', 'form-captain' ), names.join( ', ' ) ) : '';
	}

	function SettingError( props ) {
		return el( FieldError, { code: props.errors[ props.path ] } );
	}

	/** "Send test to me" (SET-FC-19): the real mail with sample values, current editor settings. */
	function TestMailButton( props ) {
		var state = useState( null );
		var busy = useState( false );
		var send = function () {
			busy[ 1 ]( true );
			state[ 1 ]( null );
			apiFetch( { path: boot.restPath + '/test-mail', method: 'POST', data: { kind: props.kind, settings: props.settings } } ).then(
				function ( res ) {
					busy[ 1 ]( false );
					state[ 1 ]( { status: 'success', text: res.message } );
				},
				function ( err ) {
					busy[ 1 ]( false );
					state[ 1 ]( { status: 'error', text: ( err && err.message ) || __( 'The request failed.', 'form-captain' ) } );
				}
			);
		};
		return el(
			'div',
			{ className: 'fc-test-mail' },
			el( C.Button, { variant: 'secondary', isBusy: busy[ 0 ], disabled: busy[ 0 ], onClick: send }, __( 'Send test to me', 'form-captain' ) ),
			el( 'p', { className: 'description' }, sprintf( __( 'Sends this e-mail with sample values to your own address (%s), never to the recipients. Unsaved changes are used.', 'form-captain' ), boot.userEmail || '' ) ),
			state[ 0 ] && el( 'p', { className: state[ 0 ].status === 'error' ? 'fc-field-error' : 'fc-notice-success', role: state[ 0 ].status === 'error' ? 'alert' : 'status' }, state[ 0 ].text )
		);
	}

	/** Double opt-in (SET-FC-10). */
	function DoubleOptInSection( props ) {
		var s = props.settings;
		var d = s.double_opt_in || {};
		var emails = props.fields.filter( function ( f ) {
			return f.type === 'email';
		} );
		var setD = function ( patch ) {
			props.onChange( Object.assign( {}, s, { double_opt_in: Object.assign( {}, d, patch ) } ) );
		};
		return el(
			Fragment,
			null,
			el( 'h2', null, __( 'Double opt-in', 'form-captain' ) ),
			el( C.CheckboxControl, {
				label: __( 'Accept submissions only after the visitor confirmed them by e-mail', 'form-captain' ),
				help: __( 'Notifications and actions run only after the confirmation. Whether you need double opt-in (e.g. for newsletters) is a legal question – have the texts checked.', 'form-captain' ),
				checked: !! d.enabled,
				onChange: function ( v ) {
					setD( { enabled: v } );
				},
			} ),
			d.enabled &&
				el(
					Fragment,
					null,
					el( C.SelectControl, {
						label: __( 'E-mail field for the confirmation link', 'form-captain' ),
						value: d.email_field || '',
						options: [ { value: '', label: __( '— Select —', 'form-captain' ) } ].concat(
							emails.map( function ( f ) {
								return { value: f.id, label: fieldLabel( f ) + ( f.required ? '' : ' (' + __( 'not required', 'form-captain' ) + ')' ) };
							} )
						),
						onChange: function ( v ) {
							setD( { email_field: v } );
						},
					} ),
					el( SettingError, { errors: props.errors, path: 'double_opt_in.email_field' } ),
					el( C.TextControl, {
						label: __( 'Subject', 'form-captain' ),
						help: __( 'Leave empty for the default text in the language of the page.', 'form-captain' ),
						value: d.subject || '',
						onChange: function ( v ) {
							setD( { subject: v } );
						},
					} ),
					el( SettingError, { errors: props.errors, path: 'double_opt_in.subject' } ),
					el( C.TextareaControl, {
						label: __( 'Message', 'form-captain' ),
						help: __( 'Placeholders: {confirm_link}, {link_hours}, {form_title}, {site_title}, {field:key}. Without {confirm_link} the link is added below the text. Simple HTML is allowed: p, br, strong, em, b, i, ul, ol, li, a, h3, h4, span. Leave empty for the default text.', 'form-captain' ),
						rows: 5,
						value: d.body || '',
						onChange: function ( v ) {
							setD( { body: v } );
						},
					} ),
					el( C.TextareaControl, {
						label: __( 'Message after submitting', 'form-captain' ),
						help: __( 'Shown instead of the success message until the visitor confirmed. Leave empty for the default text.', 'form-captain' ),
						rows: 2,
						value: d.pending_message || '',
						onChange: function ( v ) {
							setD( { pending_message: v } );
						},
					} ),
					el( SettingError, { errors: props.errors, path: 'double_opt_in.pending_message' } ),
					el( NumberInput, {
						label: __( 'Link valid for (hours)', 'form-captain' ),
						help: __( '1 to 720 hours. Default: 48.', 'form-captain' ),
						value: d.link_hours === undefined ? 48 : d.link_hours,
						onChange: function ( v ) {
							setD( { link_hours: v } );
						},
					} ),
					el( SettingError, { errors: props.errors, path: 'double_opt_in.link_hours' } ),
					el( NumberInput, {
						label: __( 'Delete unconfirmed submissions after (days)', 'form-captain' ),
						help: __( '1 to 90 days, at least as long as the link is valid. Default: 7.', 'form-captain' ),
						value: d.delete_after_days === undefined ? 7 : d.delete_after_days,
						onChange: function ( v ) {
							setD( { delete_after_days: v } );
						},
					} ),
					el( SettingError, { errors: props.errors, path: 'double_opt_in.delete_after_days' } ),
					el( TestMailButton, { kind: 'double_opt_in', settings: s } )
				)
		);
	}

	function NotificationsTab( props ) {
		var s = props.settings;
		var n = s.notification || {};
		var cm = s.confirmation_mail || {};
		var fields = props.fields;
		var emails = fields.filter( function ( f ) {
			return f.type === 'email';
		} );
		var valueFields = fields.filter( function ( f ) {
			return hasValue( f.type );
		} );
		var setN = function ( patch ) {
			props.onChange( Object.assign( {}, s, { notification: Object.assign( {}, n, patch ) } ) );
		};
		var setCM = function ( patch ) {
			props.onChange( Object.assign( {}, s, { confirmation_mail: Object.assign( {}, cm, patch ) } ) );
		};
		var selected = Array.isArray( n.fields ) ? n.fields : null;
		var cmFields = Array.isArray( cm.fields ) ? cm.fields : [];
		var placeholders = '{form_title}, {admin_email}, {entry_number}, ' + valueFields.slice( 0, 4 ).map( function ( f ) {
			return '{field:' + f.key + '}';
		} ).join( ', ' );

		return el(
			'div',
			{ className: 'fc-settings-tab' },
			el( 'h2', null, __( 'Admin notification', 'form-captain' ) ),
			el( C.CheckboxControl, { label: __( 'Send an email for every new entry', 'form-captain' ), help: __( 'Sends an e-mail to the recipients below for every new submission.', 'form-captain' ), checked: n.enabled !== false, onChange: function ( v ) {
				setN( { enabled: v } );
			} } ),
			n.enabled !== false &&
				el(
					Fragment,
					null,
					el( C.TextareaControl, {
						label: __( 'Recipients', 'form-captain' ),
						help: sprintf( __( 'One address per line. {admin_email} stands for %s.', 'form-captain' ), boot.adminEmail ),
						rows: 3,
						value: ( n.to || [] ).join( '\n' ),
						onChange: function ( v ) {
							setN( { to: v.split( /[\n,;]+/ ).map( function ( x ) {
								return x.trim();
							} ) } );
						},
					} ),
					el( SettingError, { errors: props.errors, path: 'notification.to' } ),
					el( C.SelectControl, {
						label: __( 'Reply-To field', 'form-captain' ),
						help: __( 'An e-mail field of this form whose value is used as reply-to address.', 'form-captain' ),
						value: n.reply_to_field || '',
						options: [ { value: '', label: __( 'None', 'form-captain' ) } ].concat(
							emails.map( function ( f ) {
								return { value: f.id, label: fieldLabel( f ) };
							} )
						),
						onChange: function ( v ) {
							setN( { reply_to_field: v } );
						},
					} ),
					el( SettingError, { errors: props.errors, path: 'notification.reply_to_field' } ),
					el( C.TextControl, {
						label: __( 'Subject', 'form-captain' ),
						help: sprintf( __( 'Placeholders: %s', 'form-captain' ), placeholders ),
						value: n.subject || '',
						onChange: function ( v ) {
							setN( { subject: v } );
						},
					} ),
					el( C.RadioControl, {
						label: __( 'Fields in the email', 'form-captain' ),
						help: __( 'All fields not marked as sensitive, or a selection.', 'form-captain' ),
						selected: selected ? 'selected' : 'all_non_sensitive',
						options: [
							{ value: 'all_non_sensitive', label: __( 'All fields except sensitive ones', 'form-captain' ) },
							{ value: 'selected', label: __( 'Only selected fields', 'form-captain' ) },
						],
						onChange: function ( v ) {
							setN( { fields: v === 'selected' ? valueFields.filter( function ( f ) {
								return ! f.sensitive;
							} ).map( function ( f ) {
								return f.id;
							} ) : 'all_non_sensitive' } );
						},
					} ),
					selected &&
						el(
							'fieldset',
							{ className: 'fc-checklist-fields' },
							el( 'legend', { className: 'screen-reader-text' }, __( 'Selected fields', 'form-captain' ) ),
							valueFields.map( function ( f ) {
								return el( C.CheckboxControl, {
									key: f.id,
									label: fieldLabel( f ) + ( f.sensitive ? ' (' + __( 'sensitive', 'form-captain' ) + ')' : '' ),
									checked: selected.indexOf( f.id ) !== -1,
									onChange: function ( on ) {
										var next = selected.filter( function ( x ) {
											return x !== f.id;
										} );
										if ( on ) {
											next.push( f.id );
										}
										setN( { fields: next } );
									},
								} );
							} )
						),
					el( SettingError, { errors: props.errors, path: 'notification.fields' } ),
					el( TestMailButton, { kind: 'admin', settings: s } )
				),
			el( 'h2', null, __( 'Confirmation email to the visitor', 'form-captain' ) ),
			el( C.CheckboxControl, { label: __( 'Send a confirmation email', 'form-captain' ), help: __( 'Needs an e-mail field marked as confirmation recipient.', 'form-captain' ), checked: !! cm.enabled, onChange: function ( v ) {
				setCM( { enabled: v } );
			} } ),
			cm.enabled &&
				el(
					Fragment,
					null,
					el( 'p', { className: 'description' }, __( 'Goes to the email field marked “Send the confirmation email to this address”.', 'form-captain' ) ),
					el( SettingError, { errors: props.errors, path: 'confirmation_mail.enabled' } ),
					el( C.TextControl, { label: __( 'Subject', 'form-captain' ), help: __( 'Required when the confirmation e-mail is on.', 'form-captain' ), value: cm.subject || '', onChange: function ( v ) {
						setCM( { subject: v } );
					} } ),
					el( SettingError, { errors: props.errors, path: 'confirmation_mail.subject' } ),
					el( C.TextareaControl, {
						label: __( 'Message', 'form-captain' ),
						help: sprintf( __( 'Placeholders: %s. Simple HTML is allowed: p, br, strong, em, b, i, ul, ol, li, a, h3, h4, span; everything else is removed. Plain text stays plain text.', 'form-captain' ), placeholders.replace( '{admin_email}, ', '{site_title}, ' ) ),
						rows: 5,
						value: cm.body || '',
						onChange: function ( v ) {
							setCM( { body: v } );
						},
					} ),
					el(
						'fieldset',
						{ className: 'fc-checklist-fields' },
						el( 'legend', null, __( 'Include these answers (optional)', 'form-captain' ) ),
						valueFields.filter( function ( f ) {
							return ! f.sensitive;
						} ).map( function ( f ) {
							return el( C.CheckboxControl, {
								key: f.id,
								label: fieldLabel( f ),
								checked: cmFields.indexOf( f.id ) !== -1,
								onChange: function ( on ) {
									var next = cmFields.filter( function ( x ) {
										return x !== f.id;
									} );
									if ( on ) {
										next.push( f.id );
									}
									setCM( { fields: next } );
								},
							} );
						} )
					),
					el( TestMailButton, { kind: 'confirmation', settings: s } )
				),
			el( DoubleOptInSection, { settings: s, fields: fields, errors: props.errors, onChange: props.onChange } ),
			el( 'h2', null, __( 'Sender', 'form-captain' ) ),
			el( 'p', { className: 'description' }, __( 'Copied from the global email settings when the form was created. Changes here only affect this form.', 'form-captain' ) ),
			el( C.TextControl, {
				label: __( 'Sender name', 'form-captain' ),
				help: __( 'Copied from the global setting when the form is created.', 'form-captain' ),
				value: ( s.email || {} ).from_name || '',
				onChange: function ( v ) {
					props.onChange( Object.assign( {}, s, { email: Object.assign( {}, s.email || {}, { from_name: v } ) } ) );
				},
			} ),
			el( C.TextControl, {
				label: __( 'Sender address', 'form-captain' ),
				help: __( 'Copied from the global setting when the form is created. Empty uses the global sender.', 'form-captain' ),
				type: 'email',
				value: ( s.email || {} ).from_address || '',
				onChange: function ( v ) {
					props.onChange( Object.assign( {}, s, { email: Object.assign( {}, s.email || {}, { from_address: v } ) } ) );
				},
			} ),
			el( SettingError, { errors: props.errors, path: 'email.from_address' } ),
			el( C.SelectControl, {
				label: __( 'E-mail format', 'form-captain' ),
				help: __( 'HTML e-mails always contain a plain-text version as well.', 'form-captain' ),
				value: ( s.email || {} ).format || 'global',
				options: [
					{ value: 'global', label: __( 'As in the global e-mail settings', 'form-captain' ) },
					{ value: 'html', label: __( 'HTML', 'form-captain' ) },
					{ value: 'text', label: __( 'Plain text', 'form-captain' ) },
				],
				onChange: function ( v ) {
					props.onChange( Object.assign( {}, s, { email: Object.assign( {}, s.email || {}, { format: v } ) } ) );
				},
			} )
		);
	}

	function ConfirmationTab( props ) {
		var s = props.settings;
		var c = s.confirmation || {};
		var set = function ( patch ) {
			props.onChange( Object.assign( {}, s, { confirmation: Object.assign( {}, c, patch ) } ) );
		};
		return el(
			'div',
			{ className: 'fc-settings-tab' },
			el( 'h2', null, __( 'After submitting', 'form-captain' ) ),
			el( C.RadioControl, {
				label: __( 'Show', 'form-captain' ),
				help: __( 'Show a message below the form or redirect to a published page.', 'form-captain' ),
				selected: c.type || 'message',
				options: [
					{ value: 'message', label: __( 'A message', 'form-captain' ) },
					{ value: 'page', label: __( 'A page of this site', 'form-captain' ) },
				],
				onChange: function ( v ) {
					set( { type: v } );
				},
			} ),
			( c.type || 'message' ) === 'message'
				? el( C.TextareaControl, { label: __( 'Success message', 'form-captain' ), help: __( 'Shown after a successful submission. Empty uses the default text.', 'form-captain' ), rows: 3, value: c.message || '', onChange: function ( v ) {
					set( { message: v } );
				} } )
				: el( C.SelectControl, {
					label: __( 'Page', 'form-captain' ),
					help: __( 'Only published pages of this site can be chosen.', 'form-captain' ),
					value: String( c.page_id || '' ),
					options: [ { value: '', label: __( '— Select —', 'form-captain' ) } ].concat(
						( boot.pages || [] ).map( function ( p ) {
							return { value: String( p.id ), label: p.title };
						} )
					),
					onChange: function ( v ) {
						set( { page_id: parseInt( v, 10 ) || 0 } );
					},
				} ),
			el( SettingError, { errors: props.errors, path: 'confirmation.page_id' } )
		);
	}

	function FormSettingsTab( props ) {
		var s = props.settings;
		var texts = s.texts || {};
		var lim = s.limits || {};
		var setTexts = function ( patch ) {
			props.onChange( Object.assign( {}, s, { texts: Object.assign( {}, texts, patch ) } ) );
		};
		var setLimits = function ( patch ) {
			props.onChange( Object.assign( {}, s, { limits: Object.assign( {}, lim, patch ) } ) );
		};
		return el(
			'div',
			{ className: 'fc-settings-tab' },
			el( 'h2', null, __( 'Texts', 'form-captain' ) ),
			el( C.TextControl, {
				label: __( 'Submit button text', 'form-captain' ),
				help: __( 'Up to 60 characters. Empty = “Send”.', 'form-captain' ),
				maxLength: 60,
				value: s.submit_label || '',
				onChange: function ( v ) {
					props.onChange( Object.assign( {}, s, { submit_label: v } ) );
				},
			} ),
			el( SettingError, { errors: props.errors, path: 'submit_label' } ),
			el( C.TextControl, {
				label: __( 'Note on required fields', 'form-captain' ),
				help: __( 'Shown above the fields when the form has required fields. An asterisk (*) is shown as the required marker. Empty = standard text.', 'form-captain' ),
				value: texts.required_hint || '',
				onChange: function ( v ) {
					setTexts( { required_hint: v } );
				},
			} ),
			el( SettingError, { errors: props.errors, path: 'texts.required_hint' } ),
			el( C.TextControl, {
				label: __( 'Heading of the error summary', 'form-captain' ),
				help: __( 'Shown above the list of fields to correct. Empty = standard text.', 'form-captain' ),
				value: texts.error_summary || '',
				onChange: function ( v ) {
					setTexts( { error_summary: v } );
				},
			} ),
			el( SettingError, { errors: props.errors, path: 'texts.error_summary' } ),
			el( 'h2', null, __( 'Submission period and limit', 'form-captain' ) ),
			el( 'p', { className: 'description' }, __( 'Times use the time zone of the website. Outside the period or after the maximum number of entries, visitors see the closed text instead of the form. Spam and entries in the trash do not count.', 'form-captain' ) ),
			el( C.TextControl, {
				label: __( 'Accept submissions from', 'form-captain' ),
				help: __( 'Date and time in the site time zone. Empty means the form is open right away.', 'form-captain' ),
				type: 'datetime-local',
				value: lim.opens_at || '',
				onChange: function ( v ) {
					setLimits( { opens_at: v } );
				},
			} ),
			el( SettingError, { errors: props.errors, path: 'limits.opens_at' } ),
			el( C.TextControl, {
				label: __( 'Accept submissions until', 'form-captain' ),
				help: __( 'Date and time in the site time zone; must be after the start. Empty means no end.', 'form-captain' ),
				type: 'datetime-local',
				value: lim.closes_at || '',
				onChange: function ( v ) {
					setLimits( { closes_at: v } );
				},
			} ),
			el( SettingError, { errors: props.errors, path: 'limits.closes_at' } ),
			el( NumberInput, {
				label: __( 'Maximum number of entries', 'form-captain' ),
				help: __( 'Empty or 0 = no limit.', 'form-captain' ),
				value: lim.max_entries || undefined,
				onChange: function ( v ) {
					setLimits( { max_entries: v === undefined ? 0 : v } );
				},
			} ),
			el( SettingError, { errors: props.errors, path: 'limits.max_entries' } ),
			el( C.TextareaControl, {
				label: __( 'Text when the form is closed', 'form-captain' ),
				help: __( 'Empty = standard text.', 'form-captain' ),
				rows: 2,
				value: lim.closed_message || '',
				onChange: function ( v ) {
					setLimits( { closed_message: v } );
				},
			} ),
			el( 'h2', null, __( 'Pause message', 'form-captain' ) ),
			el( C.TextareaControl, {
				label: __( 'Shown while the form is paused', 'form-captain' ),
				help: __( 'Shown instead of the form while it is paused.', 'form-captain' ),
				rows: 3,
				value: s.paused_message || '',
				onChange: function ( v ) {
					props.onChange( Object.assign( {}, s, { paused_message: v } ) );
				},
			} ),
			el( 'h2', null, __( 'Appearance', 'form-captain' ) ),
			el( C.RadioControl, {
				label: __( 'Styling', 'form-captain' ),
				help: __( 'Copied from the global setting when the form is created.', 'form-captain' ),
				selected: ( s.appearance || {} ).mode || 'inherit',
				options: [
					{ value: 'inherit', label: __( 'Use the theme styling', 'form-captain' ) },
					{ value: 'base', label: __( 'Use Form Captain base styling', 'form-captain' ) },
				],
				onChange: function ( v ) {
					props.onChange( Object.assign( {}, s, { appearance: { mode: v } } ) );
				},
			} ),
			el( 'h2', null, __( 'Spam protection', 'form-captain' ) ),
			el( C.SelectControl, {
				label: __( 'Captcha for this form', 'form-captain' ),
				help: __( 'Honeypot, minimum time, blocklist and rate limits always apply. "Stricter" requires the captcha also from visitors without JavaScript (built-in proof of work if no captcha is chosen globally).', 'form-captain' ),
				value: ( s.spam || {} ).mode || 'global',
				options: [
					{ value: 'global', label: __( 'As in the global spam settings', 'form-captain' ) },
					{ value: 'strict', label: __( 'Stricter: always require the captcha', 'form-captain' ) },
					{ value: 'off', label: __( 'No captcha for this form', 'form-captain' ) },
				],
				onChange: function ( v ) {
					props.onChange( Object.assign( {}, s, { spam: { mode: v } } ) );
				},
			} ),
			el( SettingError, { errors: props.errors || {}, path: 'spam.mode' } ),
			el( 'h2', null, __( 'Retention', 'form-captain' ) ),
			el( RetentionControl, {
				value: ( s.retention || {} ).mode || 'global',
				onChange: function ( v ) {
					props.onChange( Object.assign( {}, s, { retention: { mode: v } } ) );
				},
			} ),
			el( SettingError, { errors: props.errors, path: 'retention.mode' } ),
			el( ExtensionPanels, {
				scope: 'form',
				x: s.x,
				fields: props.fields || [],
				errors: props.errors || {},
				onChange: function ( x ) {
					props.onChange( Object.assign( {}, s, { x: Object.keys( x ).length ? x : undefined } ) );
				},
			} )
		);
	}

	/**
	 * Retention of one form (SET-FC-01): global setting, a period (amount + unit) or manual.
	 * The value is stored as "global", "manual" or e.g. "90d", "6m", "10y".
	 */
	function RetentionControl( props ) {
		var value = props.value || 'global';
		var match = /^(\d*)([dmy])$/.exec( value );
		var kind = 'global' === value || 'manual' === value ? value : 'period';
		var amount = match ? match[ 1 ] : '12';
		var unit = match ? match[ 2 ] : 'm';
		return el(
			'div',
			{ className: 'fc-retention-control' },
			el( C.SelectControl, {
				label: __( 'Keep entries of this form', 'form-captain' ),
				help: __( 'Overrides the global retention period for this form.', 'form-captain' ),
				value: kind,
				options: [
					{ value: 'global', label: __( 'As in the global privacy settings', 'form-captain' ) },
					{ value: 'period', label: __( 'For a fixed period', 'form-captain' ) },
					{ value: 'manual', label: __( 'Until deleted manually', 'form-captain' ) },
				],
				onChange: function ( v ) {
					props.onChange( 'period' === v ? amount + unit : v );
				},
			} ),
			'period' === kind &&
				el(
					'div',
					{ className: 'fc-retention-period' },
					el( C.TextControl, {
						label: __( 'Period', 'form-captain' ),
						type: 'number',
						min: 1,
						value: amount,
						onChange: function ( v ) {
							props.onChange( String( v ).replace( /[^0-9]/g, '' ) + unit );
						},
					} ),
					el( C.SelectControl, {
						label: __( 'Unit', 'form-captain' ),
						value: unit,
						options: [
							{ value: 'd', label: __( 'days', 'form-captain' ) },
							{ value: 'm', label: __( 'months', 'form-captain' ) },
							{ value: 'y', label: __( 'years', 'form-captain' ) },
						],
						onChange: function ( v ) {
							props.onChange( amount + v );
						},
					} )
				),
			el(
				'p',
				{ className: 'description' },
				__( 'Between 1 day and 30 years. Shortening the period never deletes existing entries sooner than the grace period set in the privacy settings.', 'form-captain' )
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Actions tab (P8-27)                                                 */
	/* ------------------------------------------------------------------ */

	function ActionsTab( props ) {
		var actions = props.actions;
		var fields = props.fields;
		var addState = useState( '' );
		var toAdd = addState[ 0 ];
		var setToAdd = addState[ 1 ];
		var readOnly = ! boot.canIntegrations;
		var catalog = boot.actions || [];

		var update = function ( i, patch ) {
			var next = actions.slice();
			next[ i ] = Object.assign( {}, next[ i ], patch );
			props.onChange( next );
		};

		var intro = el( 'p', { className: 'description' }, __( 'Actions pass new entries to connected plugins in the background. Only mapped, non-sensitive fields are transferred.', 'form-captain' ) );
		var notices = [];
		if ( readOnly ) {
			notices.push( el( C.Notice, { key: 'ro', status: 'info', isDismissible: false }, __( 'Changing actions requires the “Manage integrations” permission.', 'form-captain' ) ) );
		}
		if ( ! catalog.length ) {
			notices.push(
				el(
					C.Notice,
					{ key: 'none', status: 'info', isDismissible: false },
					__( 'No connector actions are available. Activate a connector under Integrations first.', 'form-captain' ),
					boot.canIntegrations ? el( 'a', { href: boot.integrationsUrl, style: { marginLeft: '6px' } }, __( 'Open integrations', 'form-captain' ) ) : null
				)
			);
		}

		var list = actions.map( function ( a, i ) {
			var def = ACTIONS[ a.action_key ];
			var errPrefix = 'actions.' + i;
			var errs = props.errors || {};
			var myErrors = Object.keys( errs ).filter( function ( k ) {
				return k === errPrefix || k.indexOf( errPrefix + '.' ) === 0;
			} );
			return el(
				'div',
				{ key: a.instance_id, className: 'fc-action-card' },
				el( 'h3', null, def ? def.label : a.action_key, def && def.connector ? el( 'span', { className: 'fc-action-connector' }, ' – ' + def.connector ) : null ),
				! def && el( C.Notice, { status: 'warning', isDismissible: false }, __( 'This action is currently not available (connector inactive or removed). It will not run.', 'form-captain' ) ),
				def && def.available === false && def.message && el( C.Notice, { status: 'warning', isDismissible: false }, def.message ),
				el( C.ToggleControl, {
					label: __( 'Enabled', 'form-captain' ),
					checked: !! a.enabled,
					disabled: readOnly || ! def,
					onChange: function ( v ) {
						update( i, { enabled: v } );
					},
				} ),
				def &&
					def.settings.length > 0 &&
					el(
						'fieldset',
						null,
						el( 'legend', null, __( 'Settings', 'form-captain' ) ),
						def.settings.map( function ( s ) {
							var value = ( a.settings || {} )[ s.key ];
							var onChange = function ( v ) {
								var ns = Object.assign( {}, a.settings || {} );
								ns[ s.key ] = v;
								update( i, { settings: ns } );
							};
							var label = s.label + ( s.required ? ' *' : '' );
							if ( s.type === 'checkbox' || s.type === 'bool' ) {
								return el( C.CheckboxControl, { key: s.key, label: label, checked: !! value, disabled: readOnly, onChange: onChange } );
							}
							if ( s.options && s.options.length ) {
								return el( C.SelectControl, {
									key: s.key,
									label: label,
									value: value || '',
									disabled: readOnly,
									options: [ { value: '', label: __( '— Select —', 'form-captain' ) } ].concat( s.options.map( function ( o ) {
										return { value: o, label: o };
									} ) ),
									onChange: onChange,
								} );
							}
							if ( s.type === 'textarea' ) {
								return el( C.TextareaControl, { key: s.key, label: label, value: value || '', disabled: readOnly, onChange: onChange } );
							}
							return el( C.TextControl, { key: s.key, label: label, value: value || '', disabled: readOnly, onChange: onChange } );
						} )
					),
				def &&
					Object.keys( def.mapping ).length > 0 &&
					el(
						'fieldset',
						null,
						el( 'legend', null, __( 'Field mapping', 'form-captain' ) ),
						Object.keys( def.mapping ).map( function ( mk ) {
							var m = def.mapping[ mk ];
							var candidates = fields.filter( function ( f ) {
								return hasValue( f.type ) && ( ! m.types.length || m.types.indexOf( f.type ) !== -1 );
							} );
							var opts = [ { value: '', label: __( '— Not mapped —', 'form-captain' ) } ].concat(
								candidates.map( function ( f ) {
									return { value: f.id, label: fieldLabel( f ) + ( f.sensitive ? ' (' + __( 'sensitive – not allowed', 'form-captain' ) + ')' : '' ), disabled: !! f.sensitive };
								} )
							);
							return el( C.SelectControl, {
								key: mk,
								label: m.label + ( m.required ? ' *' : '' ),
								value: ( a.mapping || {} )[ mk ] || '',
								disabled: readOnly,
								options: opts,
								onChange: function ( v ) {
									var nm = Object.assign( {}, a.mapping || {} );
									if ( v ) {
										nm[ mk ] = v;
									} else {
										delete nm[ mk ];
									}
									update( i, { mapping: nm } );
								},
							} );
						} )
					),
				myErrors.map( function ( k ) {
					return el( 'p', { key: k, className: 'fc-field-error', role: 'alert' }, message( errs[ k ] ) + ' (' + k.replace( errPrefix + '.', '' ) + ')' );
				} ),
				! readOnly &&
					el(
						C.Button,
						{
							isDestructive: true,
							variant: 'link',
							onClick: function () {
								var next = actions.slice();
								next.splice( i, 1 );
								props.onChange( next );
							},
						},
						__( 'Remove action', 'form-captain' )
					)
			);
		} );

		var adder =
			! readOnly && catalog.length
				? el(
					'div',
					{ className: 'fc-action-add' },
					el( C.SelectControl, {
						label: __( 'Add action', 'form-captain' ),
						value: toAdd,
						options: [ { value: '', label: __( '— Select —', 'form-captain' ) } ].concat(
							catalog.map( function ( d ) {
								return { value: d.key, label: d.label + ( d.connector ? ' (' + d.connector + ')' : '' ) };
							} )
						),
						onChange: setToAdd,
					} ),
					el(
						C.Button,
						{
							variant: 'secondary',
							disabled: ! toAdd,
							onClick: function () {
								props.onChange( actions.concat( [ { instance_id: newInstanceId(), action_key: toAdd, enabled: false, settings: {}, mapping: {} } ] ) );
								setToAdd( '' );
							},
						},
						__( 'Add', 'form-captain' )
					)
				)
				: null;

		return el( 'div', { className: 'fc-settings-tab fc-actions-tab' }, el( 'h2', null, __( 'Actions', 'form-captain' ) ), intro, notices, list, ! actions.length && el( 'p', null, __( 'No actions configured for this form.', 'form-captain' ) ), adder );
	}

	/* ------------------------------------------------------------------ */
	/* Preview modal                                                       */
	/* ------------------------------------------------------------------ */

	function PreviewModal( props ) {
		var deviceState = useState( 'desktop' );
		var device = deviceState[ 0 ];
		return el(
			C.Modal,
			{ title: __( 'Preview', 'form-captain' ), onRequestClose: props.onClose, className: 'fc-preview-modal', isFullScreen: true },
			el(
				'div',
				{ className: 'fc-preview-toolbar', role: 'group', 'aria-label': __( 'Preview size', 'form-captain' ) },
				el( C.Button, { variant: device === 'desktop' ? 'primary' : 'secondary', isPressed: device === 'desktop', onClick: function () {
					deviceState[ 1 ]( 'desktop' );
				} }, __( 'Desktop', 'form-captain' ) ),
				el( C.Button, { variant: device === 'mobile' ? 'primary' : 'secondary', isPressed: device === 'mobile', onClick: function () {
					deviceState[ 1 ]( 'mobile' );
				} }, __( 'Mobile', 'form-captain' ) ),
				el( 'span', { className: 'description' }, __( 'Submissions are disabled in the preview.', 'form-captain' ) )
			),
			el(
				'div',
				{ className: 'fc-preview-stage fc-preview-' + device },
				el( 'iframe', { src: props.url, title: __( 'Form preview', 'form-captain' ), sandbox: 'allow-same-origin allow-scripts' } )
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* App                                                                 */
	/* ------------------------------------------------------------------ */

	function statusLabel( status ) {
		return {
			draft: __( 'Draft', 'form-captain' ),
			published: __( 'Published', 'form-captain' ),
			paused: __( 'Paused', 'form-captain' ),
			archived: __( 'Archived', 'form-captain' ),
		}[ status ] || status;
	}

	function App() {
		var s = {
			form: useState( null ),
			title: useState( '' ),
			fields: useState( [] ),
			retired: useState( [] ),
			settings: useState( {} ),
			actions: useState( [] ),
			errors: useState( { fields: {}, other: {} } ),
			actionErrors: useState( {} ),
			selected: useState( null ),
			dirty: useState( false ),
			actionsDirty: useState( false ),
			busy: useState( false ),
			notice: useState( null ),
			preview: useState( null ),
			tab: useState( boot.initialTab || 'fields' ),
			consent: useState( {} ),
		};
		var form = s.form[ 0 ];
		var fields = s.fields[ 0 ];
		var settings = s.settings[ 0 ];
		var actions = s.actions[ 0 ];
		var dirty = s.dirty[ 0 ] || s.actionsDirty[ 0 ];

		var load = function ( data, keepNotice ) {
			s.form[ 1 ]( data );
			s.title[ 1 ]( data.title );
			s.fields[ 1 ]( clone( data.schema.fields || [] ) );
			s.retired[ 1 ]( clone( data.schema.retired_ids || [] ) );
			var st = clone( data.settings || {} );
			s.actions[ 1 ]( st.actions || [] );
			delete st.actions;
			s.settings[ 1 ]( st );
			s.errors[ 1 ]( mapErrors( data.errors || {}, data.schema.fields || [] ) );
			var orig = {};
			( data.schema.fields || [] ).forEach( function ( f ) {
				if ( f.type === 'consent' ) {
					orig[ f.id ] = { text: f.consent_text || '', revision: f.consent_revision || 1 };
				}
			} );
			s.consent[ 1 ]( orig );
			s.dirty[ 1 ]( false );
			s.actionsDirty[ 1 ]( false );
			if ( ! keepNotice ) {
				s.notice[ 1 ]( null );
			}
		};

		var showError = function ( err ) {
			if ( err && err.code === 'revision_conflict' ) {
				s.notice[ 1 ]( { status: 'error', text: err.message, conflict: true } );
				return;
			}
			if ( err && err.errors && form ) {
				s.errors[ 1 ]( mapErrors( err.errors, fields ) );
			}
			s.notice[ 1 ]( { status: 'error', text: ( err && err.message ) || __( 'The request failed.', 'form-captain' ) } );
		};

		var request = function ( path, method, data ) {
			return apiFetch( { path: boot.restPath + path, method: method, data: data } );
		};

		useEffect( function () {
			request( '', 'GET' ).then( function ( res ) {
				load( res.form );
			}, showError );
		}, [] );

		useEffect( function () {
			var handler = function ( e ) {
				if ( dirty ) {
					e.preventDefault();
					e.returnValue = '';
				}
			};
			window.addEventListener( 'beforeunload', handler );
			return function () {
				window.removeEventListener( 'beforeunload', handler );
			};
		}, [ dirty ] );

		// After "Save draft" the button is disabled and the browser drops the focus to <body>; move it
		// to the notice so keyboard and screen reader users stay at the result (SET-FC-P5-15).
		var noticeRef = useRef( null );
		var focusNotice = useRef( false );
		useEffect( function () {
			if ( ! focusNotice.current || ! s.notice[ 0 ] || ! noticeRef.current ) {
				return;
			}
			focusNotice.current = false;
			var active = document.activeElement;
			if ( ! active || active === document.body ) {
				noticeRef.current.focus();
			}
		}, [ s.notice[ 0 ] ] );

		if ( ! form ) {
			return el( 'div', { className: 'fc-loading' }, el( C.Spinner ), s.notice[ 0 ] && el( C.Notice, { status: 'error', isDismissible: false }, s.notice[ 0 ].text ) );
		}

		var client = clientErrors( fields );
		var mergedFieldErrors = {};
		Object.keys( s.errors[ 0 ].fields ).forEach( function ( id ) {
			mergedFieldErrors[ id ] = Object.assign( {}, s.errors[ 0 ].fields[ id ] );
		} );
		Object.keys( client ).forEach( function ( id ) {
			mergedFieldErrors[ id ] = Object.assign( mergedFieldErrors[ id ] || {}, client[ id ] );
		} );
		var otherErrors = s.errors[ 0 ].other;
		// While there are unsaved changes only client checks block publishing; the server re-validates on publish.
		var hasErrors = Object.keys( dirty ? client : mergedFieldErrors ).length > 0 || ( ! dirty && Object.keys( otherErrors ).length > 0 ) || ! fields.some( function ( f ) {
			return hasValue( f.type ) && f.type !== 'hidden';
		} );

		var setFields = function ( next ) {
			s.fields[ 1 ]( next );
			s.dirty[ 1 ]( true );
		};
		var setSettings = function ( next ) {
			s.settings[ 1 ]( next );
			s.dirty[ 1 ]( true );
		};
		var usedIds = function () {
			return fields.map( function ( f ) {
				return f.id;
			} ).concat( s.retired[ 0 ] );
		};

		var addField = function ( type ) {
			var f = defaultField( type, fields, usedIds() );
			var idx = fields.findIndex( function ( x ) {
				return x.id === s.selected[ 0 ];
			} );
			var next = fields.slice();
			next.splice( idx === -1 ? next.length : idx + 1, 0, f );
			setFields( next );
			s.selected[ 1 ]( f.id );
		};
		var duplicateField = function ( i ) {
			var f = clone( fields[ i ] );
			f.id = newFieldId( usedIds() );
			f.key = uniqueKey( fields[ i ].key + '_copy', fields );
			if ( f.type === 'consent' ) {
				f.consent_revision = 1;
			}
			var next = fields.slice();
			next.splice( i + 1, 0, f );
			setFields( next );
			s.selected[ 1 ]( f.id );
		};
		var removeField = function ( i ) {
			var gone = fields[ i ];
			if ( ! window.confirm( sprintf( __( 'Remove the field “%s”? Existing entries keep their values.', 'form-captain' ), fieldLabel( gone ) ) ) ) {
				return;
			}
			var next = fields.filter( function ( f, j ) {
				return j !== i;
			} ).map( function ( f ) {
				return f.condition && f.condition.field === gone.id ? Object.assign( {}, f, { condition: null } ) : f;
			} );
			s.retired[ 1 ]( s.retired[ 0 ].concat( [ gone.id ] ) );
			setFields( next );
			s.selected[ 1 ]( null );
		};

		var save = function () {
			s.busy[ 1 ]( true );
			var payload = {
				title: s.title[ 0 ],
				revision: form.revision,
				schema: { schema_version: 1, fields: fields, retired_ids: s.retired[ 0 ] },
				settings: settings,
			};
			var chain = s.dirty[ 0 ] || ! s.actionsDirty[ 0 ] ? request( '', 'PUT', payload ) : Promise.resolve( { form: form } );
			return chain
				.then( function ( res ) {
					if ( s.actionsDirty[ 0 ] && boot.canIntegrations ) {
						return request( '/actions', 'PUT', { revision: res.form.revision, actions: actions } );
					}
					return res;
				} )
				.then(
					function ( res ) {
						load( res.form, true );
						s.actionErrors[ 1 ]( res.action_errors || {} );
						s.busy[ 1 ]( false );
						var errCount = Object.keys( res.form.errors || {} ).length + Object.keys( res.action_errors || {} ).length;
						focusNotice.current = true;
						s.notice[ 1 ]( errCount
							? { status: 'warning', text: __( 'Draft saved. Please fix the highlighted problems before publishing.', 'form-captain' ) + ' ' + problemTabs( res.form.errors || {}, res.action_errors || {} ) }
							: { status: 'success', text: __( 'Saved.', 'form-captain' ) } );
						return res;
					},
					function ( err ) {
						s.busy[ 1 ]( false );
						showError( err );
						throw err;
					}
				);
		};

		var saveIfDirty = function () {
			return dirty ? save() : Promise.resolve( { form: form } );
		};

		var setStatus = function ( status ) {
			saveIfDirty().then( function () {
				s.busy[ 1 ]( true );
				var call = status === 'published' && form.status === 'draft' ? request( '/publish', 'POST', {} ) : request( '/status', 'POST', { status: status } );
				call.then(
					function ( res ) {
						load( res.form );
						s.busy[ 1 ]( false );
						s.notice[ 1 ]( { status: 'success', text: {
							published: __( 'The form is live.', 'form-captain' ),
							paused: __( 'The form is paused.', 'form-captain' ),
							draft: __( 'The form is a draft again.', 'form-captain' ),
						}[ res.form.status ] || __( 'Status changed.', 'form-captain' ) } );
					},
					function ( err ) {
						s.busy[ 1 ]( false );
						showError( err );
					}
				);
			}, function () {} );
		};

		var publish = function () {
			saveIfDirty().then( function () {
				s.busy[ 1 ]( true );
				request( '/publish', 'POST', {} ).then(
					function ( res ) {
						load( res.form );
						s.busy[ 1 ]( false );
						s.notice[ 1 ]( { status: 'success', text: __( 'Published. Changes are now live.', 'form-captain' ) } );
					},
					function ( err ) {
						s.busy[ 1 ]( false );
						showError( err );
					}
				);
			}, function () {} );
		};

		var openPreview = function () {
			saveIfDirty().then( function () {
				request( '/preview-link', 'POST', {} ).then( function ( res ) {
					s.preview[ 1 ]( res.url );
				}, showError );
			}, function () {} );
		};

		var reload = function () {
			request( '', 'GET' ).then( function ( res ) {
				load( res.form );
			}, showError );
		};

		var pending = form.pending_changes || {};
		var pendingNotice = null;
		if ( form.published_version_id && pending.unpublished ) {
			var items = [];
			( pending.removed || [] ).forEach( function ( f ) {
				items.push( sprintf( __( 'Removed: %s', 'form-captain' ), f.label ) );
			} );
			( pending.newly_required || [] ).forEach( function ( f ) {
				items.push( sprintf( __( 'Now required: %s', 'form-captain' ), f.label ) );
			} );
			( pending.options_removed || [] ).forEach( function ( f ) {
				items.push( sprintf( __( 'Options removed from %1$s: %2$s', 'form-captain' ), f.label, f.values.join( ', ' ) ) );
			} );
			( pending.type_changed || [] ).forEach( function ( f ) {
				items.push( sprintf( __( 'Type changed: %s', 'form-captain' ), f.label ) );
			} );
			pendingNotice = el(
				C.Notice,
				{ status: 'info', isDismissible: false },
				el( 'p', null, __( 'Saved changes are not live yet. Publish to apply them. Existing entries keep the version they were submitted with.', 'form-captain' ) ),
				items.length ? el( 'ul', null, items.map( function ( t, i ) {
					return el( 'li', { key: i }, t );
				} ) ) : null
			);
		}

		var notice = s.notice[ 0 ];
		var otherList = Object.keys( otherErrors ).filter( function ( k ) {
			return k.indexOf( 'notification' ) !== 0 && k.indexOf( 'confirmation' ) !== 0 && k.indexOf( 'email' ) !== 0;
		} );
		// Mark every tab that shows a problem, so it can be found without opening each tab (SET-FC-P5).
		var tabErrors = { fields: 0, notifications: 0, confirmation: 0, settings: 0, actions: 0 };
		Object.keys( otherErrors ).forEach( function ( k ) {
			tabErrors[ settingTabOf( k ) ]++;
		} );

		var header = el(
			'div',
			{ className: 'fc-editor-header' },
			el( C.TextControl, {
				className: 'fc-title-input',
				label: __( 'Form title', 'form-captain' ),
				value: s.title[ 0 ],
				onChange: function ( v ) {
					s.title[ 1 ]( v );
					s.dirty[ 1 ]( true );
				},
			} ),
			el( 'span', { className: 'fc-status fc-status--' + form.status }, statusLabel( form.status ) ),
			el(
				'div',
				{ className: 'fc-editor-buttons' },
				s.busy[ 0 ] && el( C.Spinner ),
				el( C.Button, { variant: 'secondary', onClick: openPreview, disabled: s.busy[ 0 ] }, __( 'Preview', 'form-captain' ) ),
				el( C.Button, { variant: 'secondary', onClick: save, disabled: s.busy[ 0 ] || ! dirty, 'aria-keyshortcuts': 'Control+S' }, dirty ? __( 'Save draft', 'form-captain' ) : __( 'Saved', 'form-captain' ) ),
				form.status === 'published' && el( C.Button, { variant: 'secondary', onClick: function () {
					setStatus( 'paused' );
				}, disabled: s.busy[ 0 ] }, __( 'Pause', 'form-captain' ) ),
				form.status === 'paused' && el( C.Button, { variant: 'secondary', onClick: function () {
					setStatus( 'published' );
				}, disabled: s.busy[ 0 ] }, __( 'Resume', 'form-captain' ) ),
				form.status !== 'archived' &&
					el(
						C.Button,
						{
							variant: 'primary',
							onClick: publish,
							disabled: s.busy[ 0 ] || hasErrors,
							'aria-describedby': hasErrors ? 'fc-publish-hint' : undefined,
						},
						form.status === 'draft' ? __( 'Publish', 'form-captain' ) : __( 'Publish changes', 'form-captain' )
					)
			),
			hasErrors && el( 'p', { id: 'fc-publish-hint', className: 'fc-publish-hint' }, __( 'Publishing is possible once all problems are fixed and the form has at least one input field.', 'form-captain' ) ),
			el( 'p', { className: 'fc-embed' }, __( 'Shortcode:', 'form-captain' ) + ' ', el( 'code', null, form.shortcode ) )
		);

		var tabs = [
			{ name: 'fields', title: __( 'Fields', 'form-captain' ) + ( Object.keys( mergedFieldErrors ).length ? ' (!)' : '' ) },
			{ name: 'notifications', title: __( 'Notifications', 'form-captain' ) + ( tabErrors.notifications ? ' (!)' : '' ) },
			{ name: 'confirmation', title: __( 'Confirmation', 'form-captain' ) + ( tabErrors.confirmation ? ' (!)' : '' ) },
			{ name: 'settings', title: __( 'Form settings', 'form-captain' ) + ( tabErrors.settings ? ' (!)' : '' ) },
			{ name: 'actions', title: __( 'Actions', 'form-captain' ) + ( actions.length ? ' (' + actions.length + ')' : '' ) },
		];

		return el(
			'div',
			{
				className: 'fc-editor-app',
				onKeyDown: function ( e ) {
					if ( ( e.ctrlKey || e.metaKey ) && e.key === 's' ) {
						e.preventDefault();
						if ( dirty && ! s.busy[ 0 ] ) {
							save();
						}
					}
				},
			},
			header,
			notice &&
				el(
					'div',
					{ ref: noticeRef, tabIndex: -1, className: 'fc-editor-notice' },
					el(
						C.Notice,
						{
							status: notice.status,
							onRemove: function () {
								s.notice[ 1 ]( null );
							},
							actions: notice.conflict ? [ { label: __( 'Reload latest version (discards your changes)', 'form-captain' ), onClick: reload } ] : [],
						},
						notice.text
					)
				),
			pendingNotice,
			otherList.length > 0 &&
				el(
					C.Notice,
					{ status: 'error', isDismissible: false },
					el( 'ul', null, otherList.map( function ( k ) {
						return el( 'li', { key: k }, message( otherErrors[ k ] ) );
					} ) )
				),
			el( C.TabPanel, {
				className: 'fc-editor-tabs',
				tabs: tabs,
				initialTabName: s.tab[ 0 ],
				onSelect: function ( name ) {
					s.tab[ 1 ]( name );
				},
				children: function ( tab ) {
					switch ( tab.name ) {
						case 'notifications':
							return el( NotificationsTab, { settings: settings, fields: fields, errors: otherErrors, onChange: setSettings } );
						case 'confirmation':
							return el( ConfirmationTab, { settings: settings, errors: otherErrors, onChange: setSettings } );
						case 'settings':
							return el( FormSettingsTab, { settings: settings, fields: fields, errors: otherErrors, onChange: setSettings } );
						case 'actions':
							return el( ActionsTab, {
								actions: actions,
								fields: fields,
								errors: s.actionErrors[ 0 ],
								onChange: function ( next ) {
									s.actions[ 1 ]( next );
									s.actionsDirty[ 1 ]( true );
								},
							} );
						default:
							return el( FieldsTab, {
								fields: fields,
								selected: s.selected[ 0 ],
								errors: mergedFieldErrors,
								originalConsent: s.consent[ 0 ],
								onFields: setFields,
								onSelect: s.selected[ 1 ],
								onAdd: addField,
								onDuplicate: duplicateField,
								onRemove: removeField,
							} );
					}
				},
			} ),
			s.preview[ 0 ] &&
				el( PreviewModal, {
					url: s.preview[ 0 ],
					onClose: function () {
						s.preview[ 1 ]( null );
					},
				} )
		);
	}

	function mount() {
		var root = document.getElementById( 'form-captain-editor' );
		if ( ! root ) {
			return;
		}
		if ( wp.element.createRoot ) {
			wp.element.createRoot( root ).render( el( App ) );
		} else {
			wp.element.render( el( App ), root );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
}( window.wp, window.formCaptainEditor ) );
