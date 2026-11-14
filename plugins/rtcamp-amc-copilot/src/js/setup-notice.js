/**
 * "AMC Copilot is not set up yet" notice (`AMC\Admin\SetupNotice`).
 *
 * Core adds the close button to `.is-dismissible` notices and hides the
 * notice; this remembers the choice for the current user, so the notice does
 * not come back on the next page load. Built to assets/build/js/setup-notice.js
 * and enqueued only where the notice is shown.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

document.addEventListener( 'click', ( event ) => {
	if ( ! event.target.closest?.( '.amc-setup-notice .notice-dismiss' ) ) {
		return;
	}
	apiFetch( { path: '/amc/v1/setup/dismiss', method: 'POST' } ).catch( () => {
		// Not saved: the notice shows again on the next page load.
	} );
} );
