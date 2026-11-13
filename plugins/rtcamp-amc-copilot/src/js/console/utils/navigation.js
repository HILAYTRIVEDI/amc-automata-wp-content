/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { addQueryArgs, getQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { MENU_SLUG, SCREEN_ARG } from '../constants';

const EVENT = 'amc-copilot:navigate';

/**
 * URL of a console screen, e.g. `?page=amc-copilot&screen=updates`.
 *
 * @param {string} screen Screen slug.
 * @param {Object} [args] Extra query args (e.g. `{ run: 12 }`).
 * @return {string} URL.
 */
export const screenUrl = ( screen, args = {} ) =>
	addQueryArgs( window.location.pathname, {
		page: MENU_SLUG,
		[ SCREEN_ARG ]: screen,
		...args,
	} );

/**
 * Read the current query args.
 *
 * @return {Object} Query args.
 */
export const readLocation = () => getQueryArgs( window.location.href );

/**
 * Navigate to a screen without reloading the page.
 *
 * @param {string}  screen            Screen slug.
 * @param {Object}  [args]            Extra query args.
 * @param {Object}  [options]
 * @param {boolean} [options.replace] Replace the history entry instead of adding one.
 */
export const navigate = ( screen, args = {}, { replace = false } = {} ) => {
	window.history[ replace ? 'replaceState' : 'pushState' ](
		{},
		'',
		screenUrl( screen, args )
	);
	window.dispatchEvent( new window.Event( EVENT ) );
};

/**
 * Subscribe a component to the current location.
 *
 * @return {Object} Query args of the current URL.
 */
export const useLocation = () => {
	const [ location, setLocation ] = useState( readLocation );
	useEffect( () => {
		const update = () => setLocation( readLocation() );
		window.addEventListener( 'popstate', update );
		window.addEventListener( EVENT, update );
		return () => {
			window.removeEventListener( 'popstate', update );
			window.removeEventListener( EVENT, update );
		};
	}, [] );
	return location;
};
