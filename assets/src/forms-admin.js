/* Form Captain – small helpers for forms overview and dashboard (copy shortcode, entry import steps). */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.fc-copy' ) : null;
		if ( ! button ) {
			return;
		}
		var input = document.getElementById( button.getAttribute( 'data-target' ) );
		if ( ! input ) {
			return;
		}
		var done = function () {
			var original = button.getAttribute( 'data-label' ) || button.textContent;
			button.setAttribute( 'data-label', original );
			button.textContent = button.getAttribute( 'data-copied' ) || '✓';
			window.setTimeout( function () {
				button.textContent = original;
			}, 1500 );
		};
		input.select();
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( input.value ).then( done, function () {} );
		} else {
			try {
				document.execCommand( 'copy' );
				done();
			} catch ( e ) {}
		}
	} );

	// Entry import: batch steps continue automatically (the button stays as fallback).
	var auto = document.querySelector( 'form[data-fc-autosubmit]' );
	if ( auto ) {
		window.setTimeout( function () {
			auto.submit();
		}, 400 );
	}
}() );
