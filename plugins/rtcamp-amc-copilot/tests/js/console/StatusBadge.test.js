/**
 * Internal dependencies
 */
import { describeWithDom, render } from './test-utils';

let StatusBadge;
let statusFromSeverity;

describeWithDom( 'StatusBadge', () => {
	beforeAll( () => {
		( {
			default: StatusBadge,
			statusFromSeverity,
		} = require( '../../../src/js/console/components/StatusBadge' ) );
	} );

	it.each( [
		[ 'pass', 'Pass', 'is-success' ],
		[ 'warn', 'Warning', 'is-warning' ],
		[ 'fail', 'Failed', 'is-error' ],
		[ 'info', 'Info', 'is-info' ],
	] )(
		'shows %s as text plus the core badge colour',
		async ( status, label, cls ) => {
			const { container, unmount } = await render(
				<StatusBadge status={ status } />
			);
			expect( container.textContent ).toBe( label );
			expect(
				container.querySelector( `.components-badge.${ cls }` )
			).not.toBeNull();
			// An icon accompanies the text, so colour is never the only signal.
			expect( container.querySelector( 'svg' ) ).not.toBeNull();
			await unmount();
		}
	);

	it( 'accepts a custom label', async () => {
		const { container, unmount } = await render(
			<StatusBadge status="info" label="Loopback blocked" />
		);
		expect( container.textContent ).toBe( 'Loopback blocked' );
		await unmount();
	} );

	it( 'falls back to info for an unknown status', async () => {
		const { container, unmount } = await render(
			<StatusBadge status="weird" />
		);
		expect( container.textContent ).toBe( 'Info' );
		await unmount();
	} );

	it( 'maps severities to statuses', () => {
		expect( statusFromSeverity( 'critical' ) ).toBe( 'fail' );
		expect( statusFromSeverity( 'high' ) ).toBe( 'fail' );
		expect( statusFromSeverity( 'medium' ) ).toBe( 'warn' );
		expect( statusFromSeverity( 'low' ) ).toBe( 'info' );
	} );
} );
