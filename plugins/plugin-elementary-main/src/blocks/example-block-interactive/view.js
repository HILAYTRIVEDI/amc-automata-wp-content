/**
 * WordPress dependencies
 */
import { store, getContext } from '@wordpress/interactivity';

store( 'amc-automata-features-features/example-block-interactive', {
	actions: {
		toggle: () => {
			const context = getContext();
			context.isOpen = ! context.isOpen;
		},
	},
	callbacks: {
		logIsOpen: () => {
			const { isOpen } = getContext();
			// Log the value of `isOpen` each time it changes.
			// eslint-disable-next-line no-console
			console.log( `Is open: ${ isOpen }` );
		},
	},
} );
