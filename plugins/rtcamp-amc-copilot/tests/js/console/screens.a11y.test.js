/**
 * Internal dependencies
 */
import {
	describeWithDom,
	expectNoViolations,
	render,
	settle,
} from './test-utils';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

// Screen and a selector proving its data (not just the loading state) rendered.
const SCREENS = [
	[ 'Overview', '.amc-columns table.widefat' ],
	[ 'Updates', '.amc-check-panel' ],
	[ 'Vulnerabilities', 'table' ],
	[ 'Errors', 'table' ],
	[ 'Smoke', 'table' ],
	[ 'Security', '.amc-check-panel' ],
	[ 'Performance', 'table' ],
	[ 'Inventory', '[role="tabpanel"] .amc-check-panel' ],
	[ 'Changes', 'table' ],
	[ 'Runs', 'table' ],
	[ 'Reports', 'table' ],
	[ 'Settings', '.form-table' ],
	[ 'Setup', '.amc-setup-steps' ],
];

jest.setTimeout( 60000 );

describeWithDom( 'Console screens (axe, mock data)', () => {
	beforeAll( () => {
		window.amcCopilot = { useMocks: true, mockLatency: 0 };
	} );

	afterAll( () => {
		delete window.amcCopilot;
	} );

	it.each( SCREENS )( '%s has no axe violations', async ( name, ready ) => {
		const Screen = require(
			`../../../src/js/console/screens/${ name }`
		).default;
		const { container, unmount } = await render( <Screen /> );
		await settle( 200 );
		expect( container.querySelector( 'h2' ) ).not.toBeNull();
		expect( container.querySelector( ready ) ).not.toBeNull();
		await expectNoViolations( container );
		await unmount();
	} );
} );

describeWithDom( 'Run detail page (axe, mock data)', () => {
	beforeAll( () => {
		window.amcCopilot = { useMocks: true, mockLatency: 0 };
	} );

	afterAll( () => {
		delete window.amcCopilot;
	} );

	it( 'shows the run, its timing, data and history with no axe violations', async () => {
		const { getRuns } = require( '../../../src/js/console/api' );
		const { items } = await getRuns();
		const RunDetail =
			require( '../../../src/js/console/screens/RunDetail' ).default;

		const { container, unmount } = await render(
			<RunDetail runId={ items[ 0 ].id } />
		);
		await settle( 200 );

		expect( container.querySelector( 'h2' ).textContent ).toContain(
			`Run #${ items[ 0 ].id }`
		);
		expect( container.querySelector( '.amc-run-pager' ) ).not.toBeNull();
		expect( container.querySelector( '.amc-run-data' ) ).not.toBeNull();
		expect(
			container.querySelector( 'tr[aria-current="true"]' )
		).not.toBeNull();
		await expectNoViolations( container );
		await unmount();
	} );
} );
