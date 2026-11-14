/**
 * Internal dependencies
 */
import { providerNotice } from '../../../src/js/console/utils/providerNotice';

describe( 'providerNotice (Vulnerabilities screen)', () => {
	it( 'says plainly when no provider is configured', () => {
		const message = providerNotice( {
			id: 'null',
			ready: false,
			last_error: null,
		} );
		expect( message ).toContain(
			'No vulnerability provider is configured'
		);
		expect( message ).toContain( 'does not mean the site is safe' );
	} );

	it( 'keeps "not ready" for a real provider that is not ready', () => {
		expect(
			providerNotice( {
				id: 'rtcamp-cve',
				ready: false,
				last_error: null,
			} )
		).toContain( 'not ready' );
	} );

	it( 'shows the provider error first', () => {
		expect(
			providerNotice( {
				id: 'rtcamp-cve',
				ready: true,
				last_error: 'Timed out',
			} )
		).toContain( 'Timed out' );
	} );

	it( 'returns null when the provider is ready', () => {
		expect(
			providerNotice( {
				id: 'rtcamp-cve',
				ready: true,
				last_error: null,
			} )
		).toBeNull();
		expect( providerNotice( undefined ) ).toBeNull();
	} );
} );
