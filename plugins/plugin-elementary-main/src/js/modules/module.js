/**
 * Example script module.
 *
 * Built to assets/build/js/modules/module.js and enqueued on the front end by
 * Assets::register_module_scripts(), with @wordpress/interactivity as its
 * dependency. Every page loads it: replace it with your module code, or remove
 * the enqueue when the project has no front-end module.
 */

/**
 * WordPress dependencies
 */
import { store } from '@wordpress/interactivity';

store( 'amc-automata-features-features/module', {
	state: {
		ready: true,
	},
} );
