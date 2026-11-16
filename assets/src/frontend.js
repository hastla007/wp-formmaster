/**
 * Form Captain frontend – progressive enhancement (P3-16).
 *
 * Without this script forms still work (POST to admin-post.php, server-side validation and conditions).
 * With it: token refresh for cached pages, client-side conditions (same semantics as
 * FormCaptain\Domain\ConditionEvaluator), JSON submit via REST, inline errors, focus management.
 * ES2019, no dependencies, safe for several forms per page.
 */
( function () {
	'use strict';

	var config = window.formCaptain || {};
	var i18n = config.i18n || {};
	var TOKEN_REFRESH_AGE = 30 * 60; // seconds
	var TOKEN_MAX_AGE = 115 * 60;

	function now() {
		return Math.floor( Date.now() / 1000 );
	}

	function restUrl( publicId, path ) {
		var root = config.restRoot || '/wp-json/form-captain/v1/';
		if ( root.charAt( root.length - 1 ) !== '/' ) {
			root += '/';
		}
		return root + 'forms/' + encodeURIComponent( publicId ) + '/' + path;
	}

	/* ---------------------------------------------------------------
	 * Values and conditions (mirror of ConditionEvaluator)
	 * ------------------------------------------------------------- */

	function fieldInputs( form, id ) {
		return Array.prototype.filter.call( form.elements, function ( el ) {
			return el.name === 'fc[' + id + ']' || el.name === 'fc[' + id + '][]';
		} );
	}

	function fieldValue( form, id ) {
		var inputs = fieldInputs( form, id );
		if ( ! inputs.length ) {
			return '';
		}
		var first = inputs[ 0 ];
		if ( first.type === 'checkbox' && first.name.slice( -2 ) === '[]' ) {
			return inputs.filter( function ( el ) {
				return el.checked;
			} ).map( function ( el ) {
				return el.value;
			} );
		}
		if ( first.type === 'checkbox' ) {
			return first.checked ? '1' : '';
		}
		if ( first.type === 'radio' ) {
			var checked = inputs.filter( function ( el ) {
				return el.checked;
			} );
			return checked.length ? checked[ 0 ].value : '';
		}
		if ( first.tagName === 'SELECT' && first.multiple ) {
			return Array.prototype.filter.call( first.options, function ( o ) {
				return o.selected;
			} ).map( function ( o ) {
				return o.value;
			} );
		}
		return String( first.value || '' ).trim();
	}

	function matches( operator, value, expected ) {
		var isArray = Array.isArray( value );
		var list = isArray ? value.map( String ) : ( value === '' ? [] : [ String( value ) ] );
		var empty = list.filter( function ( v ) {
			return v.trim() !== '';
		} ).length === 0;
		switch ( operator ) {
			case 'equals':
				return list.indexOf( expected ) !== -1 && ( list.length === 1 || isArray );
			case 'not_equals':
				return list.indexOf( expected ) === -1;
			case 'contains':
				return expected !== '' && list.some( function ( v ) {
					return v.toLowerCase().indexOf( expected.toLowerCase() ) !== -1;
				} );
			case 'is_empty':
				return empty;
			case 'is_not_empty':
			case 'checked':
				return ! empty;
			default:
				return false;
		}
	}

	function applyConditions( form, announce ) {
		var visible = {};
		var nodes = form.querySelectorAll( '[data-fc-field]' );
		Array.prototype.forEach.call( nodes, function ( node ) {
			var id = node.getAttribute( 'data-fc-field' );
			var raw = node.getAttribute( 'data-fc-condition' );
			var show = true;
			if ( raw ) {
				var cond;
				try {
					cond = JSON.parse( raw );
				} catch ( e ) {
					cond = null;
				}
				if ( cond && cond.field ) {
					var value = visible[ cond.field ] ? fieldValue( form, cond.field ) : '';
					var match = matches( String( cond.operator || '' ), value, String( cond.value || '' ) );
					show = cond.action === 'hide' ? ! match : match;
				}
			}
			visible[ id ] = show;
			if ( node.tagName === 'INPUT' ) {
				node.disabled = ! show;
				return;
			}
			var wasHidden = node.hidden;
			node.hidden = ! show;
			Array.prototype.forEach.call( node.querySelectorAll( 'input,select,textarea' ), function ( el ) {
				el.disabled = ! show;
			} );
			if ( announce && wasHidden !== node.hidden ) {
				var live = wrapOf( form ).querySelector( '.fc-live' );
				var label = node.querySelector( '.fc-label' );
				if ( live && label ) {
					var text = label.textContent.replace( /\s*\*\s*$/, '' );
					live.textContent = ( ( show ? i18n.fieldShown : i18n.fieldHidden ) || '' ).replace( '%s', text );
				}
			}
		} );
	}

	/* ---------------------------------------------------------------
	 * Token handling (cache-safe, ADR 0003)
	 * ------------------------------------------------------------- */

	function tokenInput( form ) {
		return form.querySelector( 'input[name="_fc_token"]' );
	}

	function tokenAge( form ) {
		var issued = parseInt( form.getAttribute( 'data-fc-token-issued' ) || '0', 10 );
		return issued > 0 ? now() - issued : Infinity;
	}

	function refreshToken( form ) {
		if ( form._fcTokenPromise ) {
			return form._fcTokenPromise;
		}
		var url = restUrl( form.getAttribute( 'data-fc-form' ), 'token' );
		var purpose = form.getAttribute( 'data-fc-ctx-purpose' );
		if ( purpose ) {
			url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) +
				'ctx_purpose=' + encodeURIComponent( purpose ) +
				'&ctx_ref_type=' + encodeURIComponent( form.getAttribute( 'data-fc-ctx-ref-type' ) || '' ) +
				'&ctx_ref_id=' + encodeURIComponent( form.getAttribute( 'data-fc-ctx-ref-id' ) || '' );
		}
		form._fcTokenPromise = fetch( url, { method: 'GET', cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } } )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( data ) {
				if ( data && data.success && data.token ) {
					var input = tokenInput( form );
					if ( input ) {
						input.value = data.token;
					}
					form.setAttribute( 'data-fc-token-issued', String( data.issued_at || now() ) );
					form._fcOwnToken = true;
					form._fcMinSubmitAt = data.min_submit_at || 0;
					if ( data._fc_ctx ) {
						var ctx = form.querySelector( 'input[name="_fc_ctx"]' );
						if ( ! ctx ) {
							ctx = document.createElement( 'input' );
							ctx.type = 'hidden';
							ctx.name = '_fc_ctx';
							form.appendChild( ctx );
						}
						ctx.value = data._fc_ctx;
					}
				}
				return data;
			} )
			.catch( function () {
				return null;
			} )
			.then( function ( data ) {
				form._fcTokenPromise = null;
				return data;
			} );
		return form._fcTokenPromise;
	}

	function ensureFreshToken( form, maxAge ) {
		var input = tokenInput( form );
		// Tokens embedded in (possibly cached) HTML may be shared by several visitors,
		// so every page view fetches its own token once before submitting (P3-14).
		if ( ! input || ! input.value || ! form._fcOwnToken || tokenAge( form ) > maxAge ) {
			return refreshToken( form );
		}
		return Promise.resolve( null );
	}

	/* ---------------------------------------------------------------
	 * Errors and messages
	 * ------------------------------------------------------------- */

	function wrapOf( form ) {
		return form.closest( '.fc-form-wrap' ) || form.parentNode;
	}

	function clearErrors( form ) {
		var wrap = wrapOf( form );
		Array.prototype.forEach.call( wrap.querySelectorAll( '.fc-error' ), function ( el ) {
			el.textContent = '';
			el.hidden = true;
		} );
		Array.prototype.forEach.call( wrap.querySelectorAll( '[aria-invalid]' ), function ( el ) {
			el.removeAttribute( 'aria-invalid' );
		} );
		Array.prototype.forEach.call( wrap.querySelectorAll( '.fc-field--invalid' ), function ( el ) {
			el.classList.remove( 'fc-field--invalid' );
		} );
		var summary = wrap.querySelector( '.fc-summary' );
		if ( summary ) {
			summary.hidden = true;
			summary.textContent = '';
		}
	}

	function showSummary( form, message, errors ) {
		var wrap = wrapOf( form );
		var summary = wrap.querySelector( '.fc-summary' );
		if ( ! summary ) {
			return;
		}
		summary.textContent = '';
		var p = document.createElement( 'p' );
		p.className = 'fc-summary__title';
		p.textContent = message || i18n.summary || '';
		summary.appendChild( p );
		var ids = Object.keys( errors || {} );
		if ( ids.length ) {
			var ul = document.createElement( 'ul' );
			ids.forEach( function ( id ) {
				var node = form.querySelector( '[data-fc-field="' + id + '"]' );
				var control = node ? node.querySelector( 'input,select,textarea' ) : null;
				var label = node ? node.querySelector( '.fc-label' ) : null;
				var li = document.createElement( 'li' );
				var a = document.createElement( 'a' );
				a.href = control && control.id ? '#' + control.id : '#';
				a.textContent = ( label ? label.textContent.replace( /\s*\*\s*$/, '' ) + ': ' : '' ) + errors[ id ];
				a.addEventListener( 'click', function ( ev ) {
					if ( control ) {
						ev.preventDefault();
						control.focus();
					}
				} );
				li.appendChild( a );
				ul.appendChild( li );
			} );
			summary.appendChild( ul );
		}
		summary.hidden = false;
		summary.focus();
	}

	function showFieldErrors( form, errors ) {
		Object.keys( errors || {} ).forEach( function ( id ) {
			var node = form.querySelector( '[data-fc-field="' + id + '"]' );
			if ( ! node ) {
				return;
			}
			node.classList.add( 'fc-field--invalid' );
			var err = node.querySelector( '.fc-error' );
			if ( err ) {
				err.textContent = errors[ id ];
				err.hidden = false;
			}
			if ( node.tagName === 'FIELDSET' && node.getAttribute( 'role' ) === 'radiogroup' ) {
				node.setAttribute( 'aria-invalid', 'true' );
			}
			Array.prototype.forEach.call( node.querySelectorAll( 'input,select,textarea' ), function ( el ) {
				if ( el.type !== 'radio' ) {
					el.setAttribute( 'aria-invalid', 'true' );
				}
			} );
		} );
	}

	function showSuccess( form, message ) {
		var wrap = wrapOf( form );
		var status = wrap.querySelector( '.fc-status' );
		if ( status ) {
			status.textContent = '';
			var p = document.createElement( 'p' );
			p.textContent = message || '';
			status.appendChild( p );
			status.hidden = false;
		}
		form.reset();
		form.hidden = true;
		// A new submission needs a new token (T-SUB-12).
		var input = tokenInput( form );
		if ( input ) {
			input.value = '';
		}
		form.setAttribute( 'data-fc-token-issued', '0' );
		if ( status ) {
			status.focus();
		}
	}

	/* ---------------------------------------------------------------
	 * Submit
	 * ------------------------------------------------------------- */

	function collect( form ) {
		var data = { fields: {} };
		Array.prototype.forEach.call( form.elements, function ( el ) {
			if ( ! el.name || el.disabled || el.type === 'submit' || el.type === 'button' ) {
				return;
			}
			var m = /^fc\[([a-z0-9_]+)\](\[\])?$/.exec( el.name );
			if ( m ) {
				var id = m[ 1 ];
				if ( m[ 2 ] || ( el.tagName === 'SELECT' && el.multiple ) ) {
					data.fields[ id ] = data.fields[ id ] || [];
					if ( el.tagName === 'SELECT' ) {
						Array.prototype.forEach.call( el.options, function ( o ) {
							if ( o.selected ) {
								data.fields[ id ].push( o.value );
							}
						} );
					} else if ( el.checked ) {
						data.fields[ id ].push( el.value );
					}
					return;
				}
				if ( ( el.type === 'checkbox' || el.type === 'radio' ) && ! el.checked ) {
					return;
				}
				data.fields[ id ] = el.value;
				return;
			}
			if ( el.name === 'action' ) {
				return;
			}
			data[ el.name ] = el.value;
		} );
		return data;
	}

	function setBusy( form, busy ) {
		form._fcBusy = busy;
		form.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		Array.prototype.forEach.call( form.querySelectorAll( '[type="submit"]' ), function ( btn ) {
			btn.disabled = busy;
			if ( busy ) {
				btn.setAttribute( 'data-fc-label', btn.textContent );
				btn.textContent = i18n.sending || btn.textContent;
			} else if ( btn.getAttribute( 'data-fc-label' ) ) {
				btn.textContent = btn.getAttribute( 'data-fc-label' );
			}
		} );
	}

	function wait( until ) {
		var delay = until ? ( until - now() ) * 1000 : 0;
		return new Promise( function ( resolve ) {
			setTimeout( resolve, Math.max( 0, Math.min( delay, 60000 ) ) );
		} );
	}

	function onSubmit( ev ) {
		var form = ev.currentTarget;
		ev.preventDefault();
		if ( form._fcBusy ) {
			return; // double-click protection
		}
		clearErrors( form );
		if ( form.getAttribute( 'data-fc-preview' ) ) {
			showSummary( form, i18n.preview || '', {} );
			return;
		}
		setBusy( form, true );
		var hadToken = !! ( tokenInput( form ) && tokenInput( form ).value ) && tokenAge( form ) <= TOKEN_MAX_AGE;
		ensureFreshToken( form, TOKEN_MAX_AGE )
			.then( function () {
				return hadToken ? null : wait( form._fcMinSubmitAt );
			} )
			.then( function () {
				return fetch( restUrl( form.getAttribute( 'data-fc-form' ), 'submissions' ), {
					method: 'POST',
					credentials: 'same-origin',
					cache: 'no-store',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
					body: JSON.stringify( collect( form ) ),
				} );
			} )
			.then( function ( res ) {
				return res.json().catch( function () {
					return { success: false, message: i18n.network };
				} );
			} )
			.then( function ( data ) {
				setBusy( form, false );
				if ( data && data.success ) {
					if ( data.redirect_url ) {
						window.location.assign( data.redirect_url );
						return;
					}
					showSuccess( form, data.message );
					return;
				}
				if ( data && data.code === 'invalid_token' ) {
					form.setAttribute( 'data-fc-token-issued', '0' );
				}
				showFieldErrors( form, data && data.errors );
				showSummary( form, ( data && data.message ) || i18n.network, ( data && data.errors ) || {} );
			} )
			.catch( function () {
				setBusy( form, false );
				showSummary( form, i18n.network, {} );
			} );
	}

	function init( form ) {
		if ( form._fcInit ) {
			return;
		}
		form._fcInit = true;
		form.noValidate = true;
		wrapOf( form ).classList.add( 'fc-js' );
		applyConditions( form, false );
		form.addEventListener( 'change', function () {
			applyConditions( form, true );
		} );
		form.addEventListener( 'input', function () {
			applyConditions( form, true );
		} );
		form.addEventListener( 'focusin', function () {
			if ( ! form._fcFocused ) {
				form._fcFocused = true;
				ensureFreshToken( form, TOKEN_REFRESH_AGE );
			}
		} );
		form.addEventListener( 'submit', onSubmit );
		var focusTarget = wrapOf( form ).querySelector( '[data-fc-focus]:not([hidden])' );
		if ( focusTarget ) {
			focusTarget.focus();
		}
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form.fc-form[data-fc-form]' ), init );
		// No-JS round trip finished with a success message only.
		Array.prototype.forEach.call( document.querySelectorAll( '.fc-form-wrap' ), function ( wrap ) {
			if ( ! wrap.querySelector( 'form' ) ) {
				var target = wrap.querySelector( '[data-fc-focus]:not([hidden])' );
				if ( target ) {
					target.focus();
				}
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
