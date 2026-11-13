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
	[ 'Overview', 'table' ],
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
	[ 'Settings', '.components-panel__body' ],
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
