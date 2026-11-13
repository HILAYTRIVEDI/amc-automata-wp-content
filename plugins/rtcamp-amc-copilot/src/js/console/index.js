/**
 * Engineer console entry point.
 *
 * Built to assets/build/js/console.js (one entry for the whole app, see
 * webpack.config.js) and enqueued by `AMC\Admin\ConsolePage` on its own
 * screen only, which also sets `window.amcCopilot = { restNonce, useMocks }`
 * and prints the `#amc-console-root` mount point. See ./README.md.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import App from './app';
import './console.scss';

const config = window.amcCopilot ?? {};

if ( config.restNonce && ! config.useMocks ) {
	apiFetch.use( apiFetch.createNonceMiddleware( config.restNonce ) );
}

const container = document.getElementById( 'amc-console-root' );

if ( container ) {
	createRoot( container ).render( <App /> );
}
