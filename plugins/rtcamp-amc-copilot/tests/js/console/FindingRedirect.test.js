/**
 * Internal dependencies
 */
import { advance, describeWithDom, render } from './test-utils';

jest.mock( '@wordpress/components', () => ( {
	Spinner: () => <span className="components-spinner" />,
	Notice: ( { status, children } ) => (
		<div className={ `components-notice is-${ status }` }>{ children }</div>
	),
} ) );

jest.mock( '../../../src/js/console/api', () => ( {
	getFinding: jest.fn(),
	isNotImplemented: () => false,
} ) );

let FindingRedirect;
let getFinding;

describeWithDom( 'FindingRedirect (alert email deep link)', () => {
	beforeAll( () => {
		FindingRedirect =
			require( '../../../src/js/console/components/FindingRedirect' ).default;
		( { getFinding } = require( '../../../src/js/console/api' ) );
	} );

	beforeEach( () => {
		jest.useFakeTimers();
		getFinding.mockReset();
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=amc-copilot&screen=findings&finding=302'
		);
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'looks the finding up and replaces the URL with its screen plus the finding id', async () => {
		getFinding.mockResolvedValue( {
			id: 302,
			group: 'vulnerabilities',
			check_id: 'vuln.match',
		} );
		const { unmount } = await render( <FindingRedirect findingId="302" /> );
		await advance( 10 );

		expect( getFinding ).toHaveBeenCalledWith( 302 );
		expect( window.location.search ).toBe(
			'?page=amc-copilot&screen=vulnerabilities&finding=302'
		);
		await unmount();
	} );

	it( 'sends error findings to the Errors screen', async () => {
		getFinding.mockResolvedValue( {
			id: 9,
			group: 'errors',
			check_id: 'errors.fatal',
		} );
		const { unmount } = await render( <FindingRedirect findingId="9" /> );
		await advance( 10 );
		expect( window.location.search ).toContain( 'screen=errors&finding=9' );
		await unmount();
	} );

	it( 'shows an error and stays put when the finding is gone', async () => {
		getFinding.mockRejectedValue( new Error( 'Finding not found.' ) );
		const { container, unmount } = await render(
			<FindingRedirect findingId="5" />
		);
		await advance( 10 );
		expect(
			container.querySelector( '.components-notice.is-error' ).textContent
		).toContain( 'Finding not found.' );
		expect( window.location.search ).toContain( 'screen=findings' );
		await unmount();
	} );

	it( 'rejects a link without a finding id', async () => {
		const { container, unmount } = await render(
			<FindingRedirect findingId={ undefined } />
		);
		await advance( 10 );
		expect( getFinding ).not.toHaveBeenCalled();
		expect(
			container.querySelector( '.components-notice.is-error' )
		).not.toBeNull();
		await unmount();
	} );
} );
