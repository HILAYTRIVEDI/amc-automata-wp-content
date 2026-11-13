import { hoursAhead } from './helpers';
import { CHECKS, GROUP_LABELS } from './catalogue';

const RANK = { info: 0, pass: 1, warn: 2, error: 3, fail: 4 };

/**
 * Build the `Overview` (`GET /overview`) from the current runs and findings,
 * so the screen stays consistent with the other fixtures.
 *
 * @param {Object[]} runs     Run rows (latest per check is derived here).
 * @param {Object[]} findings Findings.
 * @return {Object} Overview.
 */
export const buildOverview = ( runs, findings ) => {
	const latest = {};
	[ ...runs ]
		.sort( ( a, b ) => a.created_at.localeCompare( b.created_at ) )
		.forEach( ( r ) => {
			latest[ r.check_id ] = r;
		} );

	const groups = Object.keys( GROUP_LABELS ).map( ( group ) => {
		const checks = CHECKS.filter( ( c ) => c.group === group );
		let status = null;
		let lastRunAt = null;
		checks.forEach( ( c ) => {
			const row = latest[ c.id ];
			if ( ! row ) {
				return;
			}
			if ( null === status || RANK[ row.status ] > RANK[ status ] ) {
				status = row.status;
			}
			if ( null === lastRunAt || row.created_at > lastRunAt ) {
				lastRunAt = row.created_at;
			}
		} );
		return {
			group,
			label: GROUP_LABELS[ group ],
			status: 'error' === status ? 'warn' : status,
			last_run_at: lastRunAt,
			checks: checks.length,
		};
	} );

	const worst = Object.values( latest ).reduce(
		( acc, r ) => ( RANK[ r.status ] > RANK[ acc ] ? r.status : acc ),
		'info'
	);

	const open = { critical: 0, high: 0, medium: 0, low: 0, info: 0 };
	findings
		.filter( ( f ) => 'open' === f.status )
		.forEach( ( f ) => {
			open[ f.severity ] += 1;
		} );

	return {
		status: 'error' === worst ? 'warn' : worst,
		kill_switch: false,
		findings_by_severity: open,
		groups,
		versions: {
			wordpress: '6.9.1',
			php: '8.2.27',
			mysql: '10.11.9',
			theme: {
				name: 'AMC Theme',
				version: '2.3.0',
				parent: { name: 'Twenty Twenty-Five', version: '1.2' },
			},
		},
		counts: {
			plugins: 8,
			plugins_active: 6,
			themes: 3,
			pending_updates: { core: 1, plugins: 4, themes: 1, total: 6 },
		},
		next_runs: [
			[ 'smoke.urls', 0.5 ],
			[ 'errors.fatal', 0.7 ],
			[ 'updates.plugins', 3 ],
			[ 'vuln.match', 3 ],
			[ 'security.checksums', 15 ],
			[ 'updates.readiness', 96 ],
		].map( ( [ id, hours ] ) => ( {
			check_id: id,
			label: CHECKS.find( ( c ) => c.id === id ).label,
			next_run_at: hoursAhead( hours ),
		} ) ),
	};
};
