/**
 * Form Captain settings/integrations: confirmation dialogs and copy-to-clipboard.
 * ES2019, no build step required.
 */
( function () {
	'use strict';
	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( s ) {
		return s;
	};

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
}() );
