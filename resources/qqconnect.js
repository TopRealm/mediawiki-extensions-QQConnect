/**
 * QQConnect extension client-side helpers.
 *
 * - Adds a confirm() prompt to unbind links so the user gets a JS confirmation
 *   before navigating (in addition to the server-side confirmation form).
 * - On the choose page, ensures only one of the two submit buttons posts.
 */
( function () {
	'use strict';

	function init() {
		// Confirm unbind actions (both user self-unbind and admin unbind).
		var unbindLinks = document.querySelectorAll( '.qqconnect-btn-unbind, .qqconnect-admin-unbind' );
		unbindLinks.forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				if ( !window.confirm( link.dataset.confirmMsg || 'Are you sure?' ) ) {
					e.preventDefault();
				}
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
