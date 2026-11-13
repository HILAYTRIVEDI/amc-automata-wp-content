/**
 * Internal dependencies
 */
import { screenForFinding } from '../../../src/js/console/utils/findingScreen';

describe( 'screenForFinding (alert email deep link)', () => {
	it.each( [
		[
			{ group: 'vulnerabilities', check_id: 'vuln.match' },
			'vulnerabilities',
		],
		[ { group: 'errors', check_id: 'errors.fatal' }, 'errors' ],
		[ { group: 'smoke', check_id: 'smoke.urls' }, 'smoke' ],
		[ { group: 'security', check_id: 'security.checksums' }, 'security' ],
		[ { group: 'security', check_id: 'ssl.certificate' }, 'security' ],
		[ { group: 'performance', check_id: 'perf.database' }, 'performance' ],
		[ { group: 'updates', check_id: 'updates.plugins' }, 'updates' ],
		[ { group: 'inventory', check_id: 'inventory.plugins' }, 'inventory' ],
		[ { group: 'detection', check_id: 'detect.cdn' }, 'inventory' ],
		[ { group: 'hosting', check_id: 'hosting.platform' }, 'inventory' ],
		[ { group: 'users', check_id: 'users.roles' }, 'overview' ],
		[ { group: 'health', check_id: 'health.cron' }, 'overview' ],
	] )( 'sends %j to %s', ( finding, screen ) => {
		expect( screenForFinding( finding ) ).toBe( screen );
	} );

	it( 'falls back to the check id prefix when the group is unknown', () => {
		expect(
			screenForFinding( { group: null, check_id: 'ssl.certificate' } )
		).toBe( 'security' );
		expect(
			screenForFinding( { group: null, check_id: 'vuln.match' } )
		).toBe( 'vulnerabilities' );
	} );

	it( 'falls back to the overview for anything else', () => {
		expect(
			screenForFinding( { group: null, check_id: 'mystery.check' } )
		).toBe( 'overview' );
		expect( screenForFinding( undefined ) ).toBe( 'overview' );
	} );
} );
