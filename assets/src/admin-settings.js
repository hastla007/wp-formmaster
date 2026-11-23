/**
 * Form Captain settings/integrations: confirmation dialogs and copy-to-clipboard.
 * ES2019, no build step required.
 */
( function () {
	'use strict';
	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( s ) {
		return s;
	};

	/* After a failed save the error list comes first: focus it like the error summary of the forms. */
	var summary = document.querySelector( '.fc-settings .fc-error-summary' );
	if ( summary ) {
		summary.focus();
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( form && form.getAttribute && form.getAttribute( 'data-fc-confirm' ) ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( form.getAttribute( 'data-fc-confirm' ) ) ) {
				event.preventDefault();
			}
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-fc-copy]' ) : null;
		if ( ! button ) {
			return;
		}
		var field = document.getElementById( button.getAttribute( 'data-fc-copy' ) );
		if ( ! field ) {
			return;
		}
		var done = function () {
			button.textContent = __( 'Copied', 'form-captain' );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( field.value ).then( done, function () {
				field.select();
			} );
		} else {
			field.select();
			document.execCommand( 'copy' );
			done();
		}
	} );

	/* Logo picker (SET-FC-03): enhances the media ID field when the media library is available. */
	Array.prototype.forEach.call( document.querySelectorAll( '[data-fc-media]' ), function ( wrap ) {
		var input = document.getElementById( wrap.getAttribute( 'data-fc-media' ) );
		var preview = wrap.querySelector( '.fc-media-preview' );
		var choose = wrap.querySelector( '.fc-media-choose' );
		var remove = wrap.querySelector( '.fc-media-remove' );
		if ( ! input || ! choose || ! window.wp || ! window.wp.media ) {
			return;
		}
		var frame = null;
		var sync = function () {
			remove.hidden = ! ( parseInt( input.value, 10 ) > 0 );
		};
		choose.hidden = false;
		sync();
		choose.addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = window.wp.media( { title: __( 'Choose image', 'form-captain' ), library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var item = frame.state().get( 'selection' ).first().toJSON();
					var sizes = item.sizes || {};
					input.value = String( item.id );
					preview.src = ( sizes.medium || sizes.full || item ).url;
					preview.hidden = false;
					sync();
					input.focus();
				} );
			}
			frame.open();
		} );
		remove.addEventListener( 'click', function () {
			input.value = '0';
			preview.hidden = true;
			preview.removeAttribute( 'src' );
			sync();
			choose.focus();
		} );
	} );
}() );
