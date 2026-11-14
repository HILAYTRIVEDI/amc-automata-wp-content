/**
 * Internal dependencies
 */
import {
	CheckPanels,
	PANELS_KEY,
	openByDefault,
} from '../../../src/js/console/components/CheckPanel';
import { act, describeWithDom, render } from './test-utils';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const result = ( status, summary ) => ( {
	run: {
		id: 1,
		check_label: `Check ${ status }`,
		status,
		summary,
		created_at: '2026-11-14T12:00:00Z',
		finished_at: '2026-11-14T12:00:01Z',
	},
	data: { answer: 42 },
} );

const CHECKS = {
	'a.fail': result( 'fail', 'Something failed' ),
	'b.pass': result( 'pass', 'All good' ),
	'c.never': null,
};

const toggles = ( container ) =>
	Array.from( container.querySelectorAll( '.amc-postbox-toggle' ) );

describe( 'openByDefault', () => {
	it( 'opens results that need attention only', () => {
		expect( openByDefault( result( 'fail' ) ) ).toBe( true );
		expect( openByDefault( result( 'warn' ) ) ).toBe( true );
		expect( openByDefault( result( 'error' ) ) ).toBe( true );
		expect( openByDefault( result( 'pass' ) ) ).toBe( false );
		expect( openByDefault( result( 'info' ) ) ).toBe( false );
		expect( openByDefault( null ) ).toBe( false );
	} );
} );

describeWithDom( 'CheckPanels', () => {
	beforeEach( () => window.localStorage.clear() );

	it( 'opens failures, closes the rest, and keeps summaries visible', async () => {
		const { container, unmount } = await render(
			<CheckPanels ids={ Object.keys( CHECKS ) } checks={ CHECKS } />
		);

		expect(
			toggles( container ).map( ( b ) =>
				b.getAttribute( 'aria-expanded' )
			)
		).toEqual( [ 'true', 'false', 'false' ] );

		const subtitles = Array.from(
			container.querySelectorAll( '.amc-postbox-subtitle' )
		).map( ( p ) => p.textContent );
		expect( subtitles ).toEqual( [
			'Something failed',
			'All good',
			'Not run yet',
		] );

		// Only the open (failed) panel renders its details.
		expect( container.textContent.split( 'Answer' ) ).toHaveLength( 2 );
		expect( container.textContent ).toContain( 'Check fail: details' );
		expect( container.textContent ).not.toContain( 'Check pass: details' );
		await unmount();
	} );

	it( 'toggles a panel, remembers it, and expands or collapses all', async () => {
		const { container, unmount } = await render(
			<CheckPanels ids={ Object.keys( CHECKS ) } checks={ CHECKS } />
		);

		await act( async () => toggles( container )[ 1 ].click() );
		expect(
			toggles( container )[ 1 ].getAttribute( 'aria-expanded' )
		).toBe( 'true' );
		expect(
			JSON.parse( window.localStorage.getItem( PANELS_KEY ) )
		).toEqual( { 'b.pass': true } );

		const [ expandAll, collapseAll ] = Array.from(
			container.querySelectorAll( '.amc-panels-toggle button' )
		);

		await act( async () => collapseAll.click() );
		expect(
			toggles( container ).every(
				( b ) => 'false' === b.getAttribute( 'aria-expanded' )
			)
		).toBe( true );

		await act( async () => expandAll.click() );
		expect(
			toggles( container ).every(
				( b ) => 'true' === b.getAttribute( 'aria-expanded' )
			)
		).toBe( true );
		await unmount();
	} );

	it( 'restores the saved choice over the default', async () => {
		window.localStorage.setItem(
			PANELS_KEY,
			JSON.stringify( { 'a.fail': false } )
		);
		const { container, unmount } = await render(
			<CheckPanels ids={ [ 'a.fail' ] } checks={ CHECKS } />
		);
		expect(
			toggles( container )[ 0 ].getAttribute( 'aria-expanded' )
		).toBe( 'false' );
		await unmount();
	} );
} );
