import { CHECKS, latestRunId } from './catalogue';
import { CHECK_RESULTS } from './checkData';
import { iso, NOW } from './helpers';

/**
 * Build one `Run` (without `data` and `findings`; those are added by
 * `GET /runs/{id}`).
 *
 * @param {Object} base Run fields.
 * @return {Object} Run.
 */
const run = ( base ) => ( {
	user_id: 0,
	...base,
} );

/**
 * The latest run of every check, built from the check results fixture, plus
 * some history for a few checks so the Runs screen has depth. Newest first.
 */
const latest = CHECKS.map( ( check, index ) => {
	const [ status, summary, data ] = CHECK_RESULTS[ check.id ];
	const startedHoursAgo = 1 + ( index % 12 );
	const durationMs = 120 + ( ( index * 373 ) % 4800 );
	const manual = index % 7 === 0;
	const started = NOW - startedHoursAgo * 3600 * 1000;
	return {
		...run( {
			id: latestRunId( check.id ),
			check_id: check.id,
			check_label: check.label,
			group: check.group,
			trigger: manual ? 'manual' : 'cron',
			status,
			summary,
			user_id: manual ? 7 : 0,
			created_at: iso( started - 1000 ),
			started_at: iso( started ),
			finished_at: iso( started + durationMs ),
			duration_ms: durationMs,
		} ),
		data,
	};
} );

const history = [
	'smoke.urls',
	'errors.fatal',
	'security.checksums',
	'vuln.match',
].flatMap( ( checkId, i ) =>
	[ 1, 2, 3 ].map( ( n ) => {
		const base = latest.find( ( r ) => r.check_id === checkId );
		const passed = n > 1;
		const started = NOW - ( 24 * n + 2 + i ) * 3600 * 1000;
		return {
			...base,
			id: latestRunId( checkId ) - 500 - n * 10 - i,
			trigger: 'cron',
			user_id: 0,
			status: passed ? 'pass' : base.status,
			summary: passed ? 'No issues found.' : base.summary,
			created_at: iso( started - 1000 ),
			started_at: iso( started ),
			finished_at: iso( started + base.duration_ms ),
		};
	} )
);

export const runs = [ ...latest, ...history ].sort( ( a, b ) =>
	b.created_at.localeCompare( a.created_at )
);
