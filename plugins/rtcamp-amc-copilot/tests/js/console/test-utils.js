/**
 * Test helpers (no testing-library in the skeleton): render into a detached
 * container with React's `act`.
 *
 * `react-dom` is a peer dependency of `@wordpress/element` that the skeleton
 * does not install, so DOM tests skip themselves when it cannot be resolved
 * (add `react-dom` to devDependencies to run them; see README).
 */

/* global describe, expect, jest */

let reactDomAvailable = true;
try {
	require.resolve( 'react-dom' );
} catch {
	reactDomAvailable = false;
}

/**
 * `describe` that skips when react-dom is not installed.
 */
export const describeWithDom = reactDomAvailable ? describe : describe.skip;

/**
 * Render an element into a detached container.
 *
 * @param {Element} element React element.
 * @return {Promise<{container: HTMLElement, unmount: () => Promise<void>}>} Handle.
 */
export const render = async ( element ) => {
	const { act } = require( 'react' );
	const { createRoot } = require( '@wordpress/element' );
	globalThis.IS_REACT_ACT_ENVIRONMENT = true;
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render( element );
	} );
	return {
		container,
		unmount: async () => {
			await act( async () => root.unmount() );
			container.remove();
		},
	};
};

/**
 * Run an async action inside `act`.
 *
 * @param {(...args: unknown[]) => unknown} callback Action.
 */
export const act = async ( callback ) => {
	await require( 'react' ).act( callback );
};

/**
 * Advance fake timers and flush the resulting promises and renders.
 *
 * @param {number} ms Milliseconds.
 */
export const advance = async ( ms ) => {
	await act( async () => {
		await jest.advanceTimersByTimeAsync( ms );
	} );
};

/**
 * Assert a container has no axe-core violations (WCAG 2.x A/AA rules plus
 * axe's best practices). Colour contrast is not evaluated: jsdom has no
 * layout or stylesheets, so that rule cannot give a result.
 *
 * @param {HTMLElement} container Rendered container.
 * @param {Object}      [options] axe run options.
 */
export const expectNoViolations = async ( container, options = {} ) => {
	const { configureAxe, toHaveNoViolations } = require( 'jest-axe' );
	expect.extend( toHaveNoViolations );
	const axe = configureAxe( {
		rules: { 'color-contrast': { enabled: false } },
		...options,
	} );
	expect( await axe( container ) ).toHaveNoViolations();
};

/**
 * Let pending promises and timers (mock API latency, effects) settle.
 *
 * @param {number} [ms] Real milliseconds to wait.
 */
export const settle = async ( ms = 30 ) => {
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, ms ) );
	} );
};
