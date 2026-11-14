/**
 * The ONLY data-access module of the console.
 *
 * Every export is an async function. When `window.amcCopilot.useMocks` is
 * truthy it returns stateful fixtures from `./mocks` (same routes, same
 * shapes); otherwise it calls the REST API `amc/v1` through
 * `@wordpress/api-fetch`. The routes and shapes are the frozen contract in
 * `docs/CONTRACTS.md` section 8; each function says which route it uses.
 *
 * Errors are thrown as {@link ApiError}. `isNotImplemented()` is true for the
 * 501 `amc_not_implemented` routes another lane has not built yet, which the
 * screens show as a "not available yet" notice. Reads that a lane still
 * stubs carry `stub: '<lane>'` (from the `X-AMC-Stub` header).
 *
 * Statuses: pass | warn | fail | info (runs add queued | running | error).
 * Severities: critical | high | medium | low | info.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import * as fixtures from './mocks';
import { clone, hoursAgo, iso, NOW } from './mocks/helpers';

const NAMESPACE = '/amc/v1';
const ERROR_CHECKS = [
	'errors.fatal',
	'errors.warnings',
	'errors.deprecations',
];

/**
 * Error thrown for a failed request.
 */
export class ApiError extends Error {
	/**
	 * @param {string} message       Message.
	 * @param {Object} [meta]        Extra fields.
	 * @param {string} [meta.code]   REST error code, e.g. `amc_not_implemented`.
	 * @param {number} [meta.status] HTTP status.
	 * @param {string} [meta.owner]  Lane that owns an unbuilt route.
	 */
	constructor( message, { code = '', status = 0, owner = '' } = {} ) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
		this.owner = owner;
	}
}

/**
 * Whether an error is a 501 "not implemented yet" response.
 *
 * @param {Error} error Error.
 * @return {boolean} True for routes another lane has not built yet.
 */
export const isNotImplemented = ( error ) =>
	'amc_not_implemented' === error?.code || 501 === error?.status;

/**
 * Whether the console reads fixtures instead of the REST API.
 *
 * @return {boolean} True when mocks are enabled.
 */
export const isMockMode = () => Boolean( window.amcCopilot?.useMocks );

const delay = () =>
	new Promise( ( resolve ) => {
		setTimeout( resolve, window.amcCopilot?.mockLatency ?? 120 );
	} );

let state = null;
let nextRunId = 9000;

/**
 * Reset the in-memory mock state (used by tests).
 */
export const resetMocks = () => {
	state = null;
	nextRunId = 9000;
};

const getState = () => {
	if ( ! state ) {
		state = {
			runs: clone( fixtures.runs ),
			findings: clone( [
				...fixtures.findings,
				...fixtures.errorFindings,
			] ),
			errorRows: clone( fixtures.errorRows ),
			smoke: clone( fixtures.smoke ),
			reports: clone( fixtures.reports ),
			savedFields: clone( fixtures.savedFields ),
			settings: clone( fixtures.settings ),
			engineers: clone( fixtures.engineers ),
			setup: clone( fixtures.setup ),
			pending: {},
		};
	}
	return state;
};

const ok = ( body, headers = {} ) => ( { body, headers } );

/**
 * Parse a failed fetch into an ApiError.
 *
 * @param {Error|Response} failure What apiFetch threw.
 * @return {Promise<ApiError>} Error.
 */
const toApiError = async ( failure ) => {
	if ( failure instanceof ApiError ) {
		return failure;
	}
	if ( typeof failure?.json === 'function' ) {
		let body = {};
		try {
			body = await failure.json();
		} catch {
			// Not JSON (for example a proxy error page).
		}
		return new ApiError( body.message || `HTTP ${ failure.status }`, {
			code: body.code,
			status: body.data?.status ?? failure.status,
			owner: body.data?.owner,
		} );
	}
	return new ApiError( failure?.message || 'Request failed.', {
		code: failure?.code,
	} );
};

/**
 * Call the REST API, or the mock handler in mock mode.
 *
 * @param {Object}                          options
 * @param {string}                          options.path           Route below the namespace, starting with `/`.
 * @param {string}                          [options.method='GET'] HTTP method.
 * @param {Object}                          [options.query]        Query args.
 * @param {Object}                          [options.data]         JSON body.
 * @param {string}                          [options.namespace]    Route namespace; defaults to `/amc/v1`.
 * @param {(...args: unknown[]) => unknown} options.mock           `( state ) => { body, headers }`.
 * @return {Promise<{body: unknown, headers: {total: number, totalPages: number, stub: string}}>} Response.
 */
const request = async ( {
	path,
	method = 'GET',
	query,
	data,
	namespace = NAMESPACE,
	mock,
} ) => {
	if ( isMockMode() ) {
		await delay();
		const { body, headers = {} } = mock( getState() );
		return {
			body: clone( body ?? null ),
			headers: {
				total: headers.total ?? 0,
				totalPages: headers.totalPages ?? 1,
				stub: headers.stub ?? '',
			},
		};
	}

	try {
		const response = await apiFetch( {
			path: addQueryArgs( namespace + path, query ?? {} ),
			method,
			...( data ? { data } : {} ),
			parse: false,
		} );
		return {
			body: 204 === response.status ? null : await response.json(),
			headers: {
				total: Number( response.headers.get( 'X-WP-Total' ) ) || 0,
				totalPages:
					Number( response.headers.get( 'X-WP-TotalPages' ) ) || 1,
				stub: response.headers.get( 'X-AMC-Stub' ) || '',
			},
		};
	} catch ( failure ) {
		throw await toApiError( failure );
	}
};

const matches = ( value, wanted ) =>
	! wanted || 'all' === wanted || value === wanted;

/**
 * Latest run per check id in the mock state.
 *
 * @param {Object} s Mock state.
 * @return {Object} Map of check id to run.
 */
const latestRuns = ( s ) => {
	const latest = {};
	[ ...s.runs ]
		.filter( ( r ) => ! [ 'queued', 'running' ].includes( r.status ) )
		.sort( ( a, b ) => a.created_at.localeCompare( b.created_at ) )
		.forEach( ( r ) => {
			latest[ r.check_id ] = r;
		} );
	return latest;
};

const withoutData = ( row ) => {
	const { data, findings, ...rest } = row;
	return rest;
};

/**
 * Mock `latest_results()`: `{ check_id: { run, data } | null }`.
 *
 * @param {Object}   s   Mock state.
 * @param {string[]} ids Check ids.
 * @return {Object} Latest results.
 */
const mockLatestResults = ( s, ids ) => {
	const latest = latestRuns( s );
	return Object.fromEntries(
		ids.map( ( id ) => [
			id,
			latest[ id ]
				? { run: withoutData( latest[ id ] ), data: latest[ id ].data }
				: null,
		] )
	);
};

/**
 * Advance every queued or running mock run one step (queued, running,
 * finished) so polling shows progress.
 *
 * @param {Object} s Mock state.
 */
const tickMockRuns = ( s ) => {
	s.runs.forEach( ( row ) => {
		if ( 'queued' === row.status ) {
			row.status = 'running';
			row.started_at = iso( NOW );
		} else if ( 'running' === row.status ) {
			const [ status, summary, data ] = fixtures.CHECK_RESULTS[
				row.check_id
			] ?? [ 'pass', 'No issues found.', {} ];
			Object.assign( row, {
				status,
				summary,
				data,
				finished_at: iso( NOW ),
				duration_ms: 350,
			} );
		}
	} );
};

/**
 * Create a queued run in the mock state.
 *
 * @param {Object} s       Mock state.
 * @param {string} checkId Check id.
 * @return {number} Run id.
 */
const enqueueMockRun = ( s, checkId ) => {
	const existing = s.runs.find(
		( r ) =>
			r.check_id === checkId &&
			[ 'queued', 'running' ].includes( r.status )
	);
	if ( existing ) {
		return existing.id;
	}
	const check = fixtures.findCheck( checkId );
	const id = nextRunId++;
	s.runs.unshift( {
		id,
		check_id: checkId,
		check_label: check.label,
		group: check.group,
		trigger: 'manual',
		status: 'queued',
		summary: '',
		user_id: 7,
		created_at: iso( NOW ),
		started_at: null,
		finished_at: null,
		duration_ms: 0,
		data: {},
	} );
	return id;
};

/**
 * @typedef {Object} AmcOverview
 * @property {'pass'|'warn'|'fail'|'info'}                                                                                                                status               Worst latest result.
 * @property {boolean}                                                                                                                                    kill_switch          Whether the kill switch is on.
 * @property {Object<string, number>}                                                                                                                     findings_by_severity Open findings per severity.
 * @property {Array<{group: string, label: string, status: string|null, last_run_at: string|null, checks: number}>}                                       groups               Last run per group; `status` is null when nothing has run.
 * @property {{wordpress: string, php: string, mysql: string, theme: {name: string, version: string, parent: {name: string, version: string}|null}}}      versions             Versions.
 * @property {{plugins: number, plugins_active: number, themes: number, pending_updates: {core: number, plugins: number, themes: number, total: number}}} counts               Counts.
 * @property {Array<{check_id: string, label: string, next_run_at: string|null}>}                                                                         next_runs            Next scheduled runs, soonest first.
 */

/**
 * GET /overview
 *
 * @return {Promise<AmcOverview>} Overview.
 */
export const getOverview = async () =>
	(
		await request( {
			path: '/overview',
			mock: ( s ) => ok( fixtures.buildOverview( s.runs, s.findings ) ),
		} )
	).body;

/**
 * @typedef {Object} AmcCheck
 * @property {string}      id               Check id, e.g. `updates.core`.
 * @property {string}      label            Translated name.
 * @property {string}      group            Group slug.
 * @property {string}      group_label      Group name.
 * @property {string}      schedule         Effective schedule (hourly|twicedaily|daily|weekly|event).
 * @property {string}      default_schedule Default schedule.
 * @property {boolean}     enabled          Enabled for scheduled runs.
 * @property {AmcRun|null} last_run         Latest run.
 * @property {string|null} next_run_at      Next scheduled run.
 */

/**
 * GET /checks
 *
 * @return {Promise<AmcCheck[]>} Every registered check. Checks from lanes
 *                               that have not shipped yet are simply absent.
 */
export const getChecks = async () =>
	(
		await request( {
			path: '/checks',
			mock: ( s ) => {
				const latest = latestRuns( s );
				return ok(
					fixtures.CHECKS.map( ( check ) => {
						const override = s.settings.checks[ check.id ] ?? {};
						return {
							...check,
							schedule:
								override.interval || check.default_schedule,
							enabled: override.enabled ?? true,
							last_run: latest[ check.id ]
								? withoutData( latest[ check.id ] )
								: null,
							next_run_at:
								'event' === check.default_schedule
									? null
									: iso( NOW + 3600 * 1000 ),
						};
					} )
				);
			},
		} )
	).body;

/**
 * @typedef {Object} AmcRun
 * @property {number}                                                 id          Run id.
 * @property {string}                                                 check_id    Check id.
 * @property {string}                                                 check_label Check name.
 * @property {string|null}                                            group       Group slug.
 * @property {'cron'|'manual'|'cli'}                                  trigger     Trigger.
 * @property {'queued'|'running'|'pass'|'warn'|'fail'|'info'|'error'} status      Status.
 * @property {string}                                                 summary     Summary line.
 * @property {number}                                                 user_id     Who triggered it (0 = cron).
 * @property {string|null}                                            created_at  Queued or started (ISO 8601 UTC).
 * @property {string|null}                                            started_at  Started.
 * @property {string|null}                                            finished_at Finished.
 * @property {number}                                                 duration_ms Duration.
 * @property {Object}                                                 [data]      The check result's data (`GET /runs/{id}` only).
 * @property {AmcFinding[]}                                           [findings]  Findings this run saw (`GET /runs/{id}` only).
 *
 * @typedef {Object} AmcFinding
 * @property {number}                                                 id          Finding id.
 * @property {string}                                                 check_id    Check id.
 * @property {string|null}                                            group       Group slug.
 * @property {string}                                                 dedupe_key  Dedupe key.
 * @property {'critical'|'high'|'medium'|'low'|'info'}                severity    Severity.
 * @property {string}                                                 title       Title.
 * @property {Object}                                                 data        Check-specific data.
 * @property {'open'|'accepted'|'muted'|'resolved'}                   status      Status.
 * @property {string|null}                                            note        Accepted-risk note.
 * @property {number}                                                 run_id      Last run that saw it.
 * @property {string|null}                                            first_seen  First seen.
 * @property {string|null}                                            last_seen   Last seen.
 * @property {string|null}                                            resolved_at Resolved at.
 * @property {string|null}                                            alerted_at  Alerted at.
 *
 * @typedef {Object} AmcLatestResult
 * @property {AmcRun}                                                 run         Latest run.
 * @property {Object}                                                 data        That check's `Result::$data`.
 */

/**
 * GET /runs
 *
 * Filters: check_id, status, trigger, since, until (ISO 8601), page, per_page.
 * The console loads one big page and paginates and filters in the table.
 *
 * @param {Object} [args]
 * @param {string} [args.check_id] Check id.
 * @param {string} [args.status]   Run status.
 * @param {string} [args.trigger]  Trigger.
 * @param {string} [args.since]    From (inclusive).
 * @param {string} [args.until]    To (exclusive).
 * @param {number} [args.perPage]  Page size, max 500.
 * @return {Promise<{items: AmcRun[], total: number}>} Runs, newest first.
 */
export const getRuns = async ( {
	check_id: checkId,
	status,
	trigger,
	since,
	until,
	perPage = 500,
} = {} ) => {
	const { body, headers } = await request( {
		path: '/runs',
		query: {
			check_id: checkId || undefined,
			status: status && 'all' !== status ? status : undefined,
			trigger: trigger || undefined,
			since: since || undefined,
			until: until || undefined,
			per_page: perPage,
		},
		mock: ( s ) => {
			tickMockRuns( s );
			const items = s.runs
				.filter( ( r ) => ! checkId || r.check_id === checkId )
				.filter( ( r ) => matches( r.status, status ) )
				.filter( ( r ) => matches( r.trigger, trigger ) )
				.filter( ( r ) => ! since || r.created_at >= since )
				.filter( ( r ) => ! until || r.created_at < until )
				.slice( 0, perPage )
				.map( withoutData );
			return ok( items, { total: items.length } );
		},
	} );
	return { items: body, total: headers.total || body.length };
};

/**
 * GET /runs/{id}: the run with its `data` and `findings`. This is also the
 * "Run now" poll.
 *
 * @param {number} id Run id.
 * @return {Promise<AmcRun>} Run.
 */
export const getRun = async ( id ) =>
	(
		await request( {
			path: `/runs/${ id }`,
			mock: ( s ) => {
				tickMockRuns( s );
				const row = s.runs.find( ( r ) => r.id === id );
				if ( ! row ) {
					throw new ApiError( 'Run not found.', {
						code: 'amc_not_found',
						status: 404,
					} );
				}
				return ok( {
					...row,
					findings: s.findings.filter( ( f ) => f.run_id === id ),
				} );
			},
		} )
	).body;

/**
 * Poll one queued run: same route as {@link getRun}.
 *
 * @param {number} id Run id.
 * @return {Promise<AmcRun>} Run.
 */
export const getRunStatus = ( id ) => getRun( id );

/**
 * Statuses of several runs. A few runs are fetched one by one; for a big batch
 * ("Run all checks") the run list is read instead, so polling stays at one
 * request however many checks were queued.
 *
 * @param {number[]} runIds Run ids.
 * @return {Promise<AmcRun[]>} Runs, in the order asked.
 */
export const getRunStatuses = async ( runIds ) => {
	if ( runIds.length <= 3 ) {
		return Promise.all( runIds.map( getRunStatus ) );
	}
	const { items } = await getRuns( { perPage: 500 } );
	const byId = Object.fromEntries( items.map( ( r ) => [ r.id, r ] ) );
	return runIds.map(
		( id ) => byId[ id ] ?? { id, status: 'queued', summary: '' }
	);
};

/**
 * POST /checks/run
 *
 * Queues checks through the runner (202). It never runs a check inside the
 * request. Poll the returned run ids with {@link getRunStatuses}.
 *
 * @param {string[]|string} checkIds Check ids, or `'all'`.
 * @return {Promise<{runs: Array<{check_id: string, run_id: number}>}>} Queued runs.
 */
export const runCheck = async ( checkIds ) => {
	const ids = 'all' === checkIds ? [ 'all' ] : [].concat( checkIds );
	return (
		await request( {
			path: '/checks/run',
			method: 'POST',
			data: { check_ids: ids },
			mock: ( s ) => {
				const list =
					'all' === ids[ 0 ]
						? fixtures.CHECKS.filter(
								( c ) =>
									s.settings.checks[ c.id ]?.enabled !== false
							).map( ( c ) => c.id )
						: ids;
				const unknown = list.find(
					( id ) => ! fixtures.findCheck( id )
				);
				if ( unknown ) {
					throw new ApiError( `Unknown check: ${ unknown }`, {
						code: 'amc_unknown_check',
						status: 404,
					} );
				}
				return ok( {
					runs: list.map( ( id ) => ( {
						check_id: id,
						run_id: enqueueMockRun( s, id ),
					} ) ),
				} );
			},
		} )
	).body;
};

/**
 * POST /runs/{id}/rerun
 *
 * @param {number} id Run id to repeat.
 * @return {Promise<{run_id: number}>} The new queued run.
 */
export const rerunRun = async ( id ) =>
	(
		await request( {
			path: `/runs/${ id }/rerun`,
			method: 'POST',
			mock: ( s ) => {
				const row = s.runs.find( ( r ) => r.id === id );
				return ok( { run_id: enqueueMockRun( s, row.check_id ) } );
			},
		} )
	).body;

/**
 * Screen endpoints that return the latest result of several checks:
 * `{ checks: { <check_id>: AmcLatestResult | null } }`. `null` means the check
 * has never run (or its lane has not shipped it yet). `data` is owned by each
 * check, so the console renders it generically.
 *
 * @typedef {Object} AmcChecksScreen
 * @property {Object<string, AmcLatestResult|null>} checks Latest result per check.
 */

const screen = ( path, ids ) => async () =>
	(
		await request( {
			path,
			mock: ( s ) => ok( { checks: mockLatestResults( s, ids ) } ),
		} )
	).body;

/**
 * GET /updates (lane B1)
 *
 * @return {Promise<AmcChecksScreen>} Update checks.
 */
export const getUpdates = screen( '/updates', [
	'updates.core',
	'updates.plugins',
	'updates.themes',
	'updates.php',
	'updates.readiness',
] );

/**
 * GET /inventory (lane B1)
 *
 * @return {Promise<AmcChecksScreen>} Inventory, hosting and detection checks.
 */
export const getInventory = screen( '/inventory', [
	'inventory.plugins',
	'inventory.themes',
	'inventory.mu_dropins',
	'inventory.editor',
	'hosting.platform',
	'detect.cdn',
	'detect.forms',
	'detect.mailer',
	'detect.analytics',
	'detect.accessibility',
] );

/**
 * GET /security (lane B2)
 *
 * @return {Promise<AmcChecksScreen>} Security checks.
 */
export const getSecurity = screen( '/security', [
	'security.checksums',
	'security.config',
	'security.admins',
	'ssl.certificate',
] );

/**
 * @typedef {Object} AmcVulnerabilities
 * @property {{id: string, ready: boolean, last_error: string|null}} provider CVE provider state.
 * @property {AmcLatestResult|null}                                  last_run Latest `vuln.match` result.
 * @property {AmcFinding[]}                                          findings Matches as findings. `data` is
 *                                                                            `VulnMatch::to_array() + installed_version`.
 */

/**
 * GET /vulnerabilities?status=open|accepted|muted|resolved|unresolved|all
 *
 * @param {{status?: string}} [args] Filter.
 * @return {Promise<AmcVulnerabilities>} Vulnerabilities.
 */
export const getVulnerabilities = async ( { status = 'all' } = {} ) =>
	(
		await request( {
			path: '/vulnerabilities',
			query: { status },
			mock: ( s ) =>
				ok( {
					provider: {
						id: 'rtcamp-cve',
						ready: true,
						last_error: null,
					},
					last_run: mockLatestResults( s, [ 'vuln.match' ] )[
						'vuln.match'
					],
					findings: s.findings
						.filter( ( f ) => 'vuln.match' === f.check_id )
						.filter( ( f ) =>
							'unresolved' === status
								? 'resolved' !== f.status
								: matches( f.status, status )
						),
				} ),
		} )
	).body;

/**
 * GET /findings/{id}
 *
 * @param {number} id Finding id.
 * @return {Promise<AmcFinding>} Finding.
 */
export const getFinding = async ( id ) =>
	(
		await request( {
			path: `/findings/${ id }`,
			mock: ( s ) => {
				const finding = s.findings.find( ( f ) => f.id === id );
				if ( ! finding ) {
					throw new ApiError( 'Finding not found.', {
						code: 'amc_not_found',
						status: 404,
					} );
				}
				return ok( finding );
			},
		} )
	).body;

/**
 * POST /findings/{id}/status. Accept risk, mute, resolve or reopen a finding.
 *
 * @param {number} id     Finding id.
 * @param {string} status open | accepted | muted | resolved.
 * @param {string} [note] Note (used for accepted risk).
 * @return {Promise<AmcFinding>} Updated finding.
 */
export const setFindingStatus = async ( id, status, note ) =>
	(
		await request( {
			path: `/findings/${ id }/status`,
			method: 'POST',
			data: { status, ...( undefined === note ? {} : { note } ) },
			mock: ( s ) => {
				const finding = s.findings.find( ( f ) => f.id === id );
				if ( ! finding ) {
					throw new ApiError( 'Finding not found.', {
						code: 'amc_not_found',
						status: 404,
					} );
				}
				finding.status = status;
				finding.resolved_at = 'resolved' === status ? iso( NOW ) : null;
				if ( undefined !== note ) {
					finding.note = note;
				}
				return ok( finding );
			},
		} )
	).body;

/**
 * Mark a vulnerability as accepted risk, with a note.
 *
 * @param {number} id   Finding id.
 * @param {string} note Why the risk is accepted.
 * @return {Promise<AmcFinding>} Updated finding.
 */
export const acceptRisk = ( id, note ) =>
	setFindingStatus( id, 'accepted', note );

/**
 * @typedef {Object} AmcErrorRow
 * @property {string}                                                    signature      Signature hash.
 * @property {'fatal'|'warning'|'notice'|'deprecation'|'doing_it_wrong'} type           Type.
 * @property {string}                                                    message        Message.
 * @property {string}                                                    source         `core`, `plugin:<slug>`, `theme:<slug>`, `mu-plugin:<file>` or `unknown`.
 * @property {string}                                                    file           File.
 * @property {number}                                                    line           Line.
 * @property {number}                                                    count          Occurrences in the window.
 * @property {string|null}                                               first_seen     First seen.
 * @property {string|null}                                               last_seen      Last seen.
 * @property {number[]}                                                  sparkline      Daily counts, oldest first.
 * @property {number|null}                                               finding_id     Open finding for the signature, if any.
 * @property {string|null}                                               finding_status Status of that finding.
 * @property {number}                                                    [run_id]       Run that produced the finding (joined from `/findings`).
 */

/**
 * GET /errors?type=&days=
 *
 * Rows get a `run_id` joined from the unresolved errors findings
 * (`GET /findings?check_id=errors.*&status=unresolved`), so a signature links
 * to the run behind it.
 *
 * @param {{type?: string, days?: number}} [args] Filters.
 * @return {Promise<{items: AmcErrorRow[]}>} Signatures.
 */
export const getEvents = async ( { type = 'all', days = 30 } = {} ) => {
	const [ errors, ...lookups ] = await Promise.all( [
		request( {
			path: '/errors',
			query: { days, ...( 'all' === type ? {} : { type } ) },
			mock: ( s ) =>
				ok(
					s.errorRows
						.filter( ( r ) => matches( r.type, type ) )
						.map( ( row ) => {
							const finding = s.findings.find(
								( f ) =>
									f.dedupe_key === row.signature &&
									'resolved' !== f.status
							);
							return {
								...row,
								finding_id: finding ? finding.id : null,
								finding_status: finding ? finding.status : null,
							};
						} )
				),
		} ),
		...ERROR_CHECKS.map( ( checkId ) =>
			request( {
				path: '/findings',
				query: {
					check_id: checkId,
					status: 'unresolved',
					per_page: 500,
				},
				mock: ( s ) =>
					ok(
						s.findings.filter(
							( f ) =>
								f.check_id === checkId &&
								'resolved' !== f.status
						)
					),
			} ).catch( () => null )
		),
	] );

	const runByFinding = {};
	lookups.forEach( ( lookup ) => {
		( lookup?.body ?? [] ).forEach( ( f ) => {
			runByFinding[ f.id ] = f.run_id;
		} );
	} );

	return {
		items: errors.body.map( ( row ) => ( {
			...row,
			run_id: runByFinding[ row.finding_id ] ?? 0,
		} ) ),
	};
};

/**
 * Mark an errors finding resolved (`POST /findings/{id}/status`).
 *
 * @param {number} findingId Finding id (`AmcErrorRow.finding_id`).
 * @return {Promise<AmcFinding>} Updated finding.
 */
export const resolveEvent = ( findingId ) =>
	setFindingStatus( findingId, 'resolved' );

/**
 * Mute or unmute an errors signature (`POST /findings/{id}/status`).
 * Muted findings stay listed but never alert.
 *
 * @param {number}  findingId    Finding id.
 * @param {boolean} [muted=true] Mute (true) or reopen (false).
 * @return {Promise<AmcFinding>} Updated finding.
 */
export const muteSignature = ( findingId, muted = true ) =>
	setFindingStatus( findingId, muted ? 'muted' : 'open' );

/**
 * @typedef {Object} AmcSmokeUrl
 * @property {string}                                       url              URL.
 * @property {string}                                       required_text    Text the response must contain, may be empty.
 * @property {boolean}                                      is_default       Home, login and latest post or product.
 * @property {'pass'|'fail'|'loopback_blocked'|null}        last_status      Last result.
 * @property {number|null}                                  last_http_code   Last HTTP status.
 * @property {number|null}                                  last_response_ms Last response time.
 * @property {number|null}                                  pass_rate_30d    Pass rate over 30 days, 0 to 100.
 * @property {Array<{at: string, ms: number, ok: boolean}>} trend            Response trend.
 *
 * @typedef {Object} AmcSmoke
 * @property {AmcLatestResult|null}                         last_run         Latest `smoke.urls` result.
 * @property {AmcSmokeUrl[]}                                urls             URLs.
 * @property {string}                                       stub             Lane that still stubs this route (`B2`), or ''.
 */

/**
 * GET /smoke (lane B2, stub until it lands)
 *
 * @return {Promise<AmcSmoke>} Smoke checks.
 */
export const getSmoke = async () => {
	const { body, headers } = await request( {
		path: '/smoke',
		mock: ( s ) =>
			ok(
				{ last_run: s.smoke.last_run, urls: s.smoke.urls },
				{ stub: 'B2' }
			),
	} );
	return { ...body, stub: headers.stub };
};

/**
 * Add a smoke URL or edit its required text. There is no smoke-URL route, so
 * this updates `smoke_urls` through `POST /settings` (which needs
 * `manage_options` too). Home, login and latest post or product are always
 * checked and are not stored.
 *
 * @param {Object}  args
 * @param {string}  args.url             Page URL.
 * @param {string}  [args.required_text] Text the response must contain.
 * @param {boolean} [args.isNew]         True when adding.
 * @return {Promise<AmcSettings>} Saved settings.
 */
export const saveSmokeUrl = async ( {
	url,
	required_text: requiredText = '',
	isNew = true,
} ) => {
	const settings = await getSettings();
	const urls = settings.smoke_urls.map( ( row ) => ( { ...row } ) );
	const index = urls.findIndex( ( row ) => row.url === url );
	if ( index >= 0 && isNew ) {
		throw new ApiError( 'That URL is already being checked.', {
			code: 'amc_duplicate',
		} );
	}
	if ( index >= 0 ) {
		urls[ index ].required_text = requiredText;
	} else {
		urls.push( { url, required_text: requiredText } );
	}
	return saveSettings( { smoke_urls: urls } );
};

/**
 * @typedef {Object} AmcPerformance
 * @property {Object<string, AmcLatestResult|null>}                                                                                             checks         `perf.*` results.
 * @property {Array<{date: string, avg_ms: number, url_count: number}>}                                                                         response_trend Daily average response time.
 * @property {Array<{signature: string, sql_sample: string, caller: string, count: number, max_ms: number, avg_ms: number, last_seen: string}>} slow_queries   Sampled slow queries by caller.
 * @property {string}                                                                                                                           stub           Lane that still stubs `response_trend`, or ''.
 */

/**
 * GET /performance?days= (lane B2, `response_trend` stubbed until it lands)
 *
 * @param {{days?: number}} [args] Window, 1 to 90.
 * @return {Promise<AmcPerformance>} Performance data.
 */
export const getPerformance = async ( { days = 30 } = {} ) => {
	const { body, headers } = await request( {
		path: '/performance',
		query: { days },
		mock: ( s ) =>
			ok(
				{
					checks: mockLatestResults( s, [
						'perf.response',
						'perf.database',
						'perf.cache',
						'perf.slow_queries',
					] ),
					response_trend: fixtures.performance.response_trend,
					slow_queries: fixtures.performance.slow_queries,
				},
				{ stub: 'B2' }
			),
	} );
	return { ...body, stub: headers.stub };
};

/**
 * @typedef {Object} AmcChange
 * @property {number}                         id                Row id.
 * @property {'plugin'|'theme'|'core'|'user'} type              Type.
 * @property {string}                         action            install|update|activate|deactivate|delete|role|access_grant|access_revoke.
 * @property {string}                         item              Slug, stylesheet, `wordpress` or user login.
 * @property {string}                         item_name         Display name.
 * @property {string}                         old_version       Old version or role, may be empty.
 * @property {string}                         new_version       New version or role, may be empty.
 * @property {'admin'|'cli'|'deploy'|'auto'}  source            Source.
 * @property {number}                         user_id           Acting user, 0 when none.
 * @property {string|null}                    user_display_name Acting user's name.
 * @property {string}                         created_at        ISO 8601 UTC.
 */

/**
 * GET /changes?type=&action=&source=&since=&until=&page=&per_page=
 *
 * @param {Object} [args]
 * @param {string} [args.type]    plugin | theme | core | user.
 * @param {string} [args.source]  admin | cli | deploy | auto.
 * @param {string} [args.since]   From, ISO 8601 (inclusive).
 * @param {string} [args.until]   To, ISO 8601 (exclusive).
 * @param {number} [args.perPage] Page size, max 500.
 * @return {Promise<{items: AmcChange[], total: number}>} Changes, newest first.
 */
export const getChanges = async ( {
	type,
	source,
	since,
	until,
	perPage = 500,
} = {} ) => {
	const { body, headers } = await request( {
		path: '/changes',
		query: {
			type: type && 'all' !== type ? type : undefined,
			source: source && 'all' !== source ? source : undefined,
			since: since || undefined,
			until: until || undefined,
			per_page: perPage,
		},
		mock: () => {
			const items = fixtures.changes
				.filter( ( c ) => matches( c.type, type ) )
				.filter( ( c ) => matches( c.source, source ) )
				.filter( ( c ) => ! since || c.created_at >= since )
				.filter( ( c ) => ! until || c.created_at < until );
			return ok( items, { total: items.length } );
		},
	} );
	return { items: body, total: headers.total || body.length };
};

/**
 * @typedef {Object} AmcReport
 * @property {number}                               id           Post id.
 * @property {string}                               title        Title.
 * @property {'draft'|'publish'|'pending'|'future'} status       Post status.
 * @property {string}                               report_id    Report id, e.g. `AMC-NWT-2026-10`.
 * @property {'month'|'quarter'|'year'|'custom'}    period_type  Period type.
 * @property {string}                               period_start YYYY-MM-DD.
 * @property {string}                               period_end   YYYY-MM-DD.
 * @property {string}                               environment  Environment.
 * @property {{id: number, name: string}}           author       Engineer.
 * @property {string|null}                          modified     ISO 8601 UTC.
 * @property {string|null}                          edit_url     Block editor URL.
 * @property {string|null}                          view_url     Read-only client view URL.
 */

/**
 * GET /reports?status=any|draft|publish
 *
 * @param {{status?: string}} [args] Filter.
 * @return {Promise<{items: AmcReport[], total: number}>} Reports.
 */
export const getReports = async ( { status = 'any' } = {} ) => {
	const { body, headers } = await request( {
		path: '/reports',
		query: { status, per_page: 100 },
		mock: ( s ) =>
			ok(
				s.reports.filter(
					( r ) => 'any' === status || r.status === status
				),
				{ total: s.reports.length }
			),
	} );
	return { items: body, total: headers.total || body.length };
};

/**
 * POST /reports/generate (lane D1; 501 until it lands)
 *
 * @param {Object} args
 * @param {string} args.period_type month | quarter | year | custom.
 * @param {string} [args.start]     YYYY-MM-DD (required for custom).
 * @param {string} [args.end]       YYYY-MM-DD.
 * @return {Promise<AmcReport>} The new draft.
 */
export const generateReport = async ( {
	period_type: periodType,
	start,
	end,
} ) =>
	(
		await request( {
			path: '/reports/generate',
			method: 'POST',
			data: {
				period_type: periodType,
				...( start ? { start } : {} ),
				...( end ? { end } : {} ),
			},
			mock: ( s ) => {
				const id = 5200 + s.reports.length;
				const report = {
					id,
					title: `Website Maintenance Report: ${ start } to ${ end }`,
					status: 'draft',
					report_id: `AMC-EXC-${ String( start ).slice( 0, 7 ) }`,
					period_type: periodType,
					period_start: start,
					period_end: end,
					environment: 'production',
					author: { id: 7, name: 'Priya Nair' },
					modified: hoursAgo( 0 ),
					edit_url: `http://localhost/wp-admin/post.php?post=${ id }&action=edit`,
					view_url: null,
				};
				s.reports.unshift( report );
				return ok( report );
			},
		} )
	).body;

/**
 * Publish a draft report through core's REST route for the post type
 * (`POST /wp/v2/amc-reports/{id}`), as the contract says report editing and
 * publishing go through core.
 *
 * @param {number} id Report post id.
 * @return {Promise<{id: number, status: string}>} Updated post.
 */
export const publishReport = async ( id ) =>
	(
		await request( {
			namespace: '/wp/v2',
			path: `/amc-reports/${ id }`,
			method: 'POST',
			data: { status: 'publish' },
			mock: ( s ) => {
				const report = s.reports.find( ( r ) => r.id === id );
				report.status = 'publish';
				return ok( { id, status: 'publish' } );
			},
		} )
	).body;

/**
 * @typedef {Object} AmcSavedFields
 * @property {Object<string, Object<string, string|null>>} fields Values by section and field.
 * @property {Object<string, string[]>}                    schema Field keys by section.
 */

/**
 * GET /saved-fields
 *
 * @return {Promise<AmcSavedFields>} Carried-forward report values.
 */
export const getSavedFields = async () =>
	(
		await request( {
			path: '/saved-fields',
			mock: ( s ) => ok( s.savedFields ),
		} )
	).body;

/**
 * POST /saved-fields (lane D1; 501 until it lands)
 *
 * @param {Object<string, Object<string, string>>} fields Values by section and field.
 * @return {Promise<AmcSavedFields>} Saved fields.
 */
export const saveSavedFields = async ( fields ) =>
	(
		await request( {
			path: '/saved-fields',
			method: 'POST',
			data: { fields },
			mock: ( s ) => {
				// Merge like the route: only the fields sent change.
				Object.entries( fields ).forEach( ( [ section, values ] ) => {
					s.savedFields.fields[ section ] = {
						...s.savedFields.fields[ section ],
						...Object.fromEntries(
							Object.entries( values ).map(
								( [ key, value ] ) => [
									key,
									'' === value ? null : value,
								]
							)
						),
					};
				} );
				return ok( s.savedFields );
			},
		} )
	).body;

/**
 * @typedef {Object} AmcSettings
 * @property {'content'|'ecommerce'}                                                      site_type                 Smoke default URL: latest post or product.
 * @property {string}                                                                     environment               '' | production | staging | development | local.
 * @property {string}                                                                     contract_start            YYYY-MM-DD or ''.
 * @property {string[]}                                                                   recipients                Alert and report recipients; empty = every engineer.
 * @property {string}                                                                     digest_time               HH:MM site time.
 * @property {number}                                                                     immediate_alerts_per_hour 0 to 100.
 * @property {boolean}                                                                    notify_client_on_publish  Email client administrators on publish.
 * @property {Array<{url: string, required_text: string}>}                                smoke_urls                Extra smoke URLs.
 * @property {{username: string, has_password: boolean, encryption_available: boolean}}   loopback_auth             Basic-auth credentials (the password is write-only).
 * @property {boolean}                                                                    kill_switch               Kill switch setting.
 * @property {boolean}                                                                    kill_switch_locked        True when the AMC_COPILOT_DISABLED constant is defined.
 * @property {Object<string, {enabled: boolean, interval: string}>}                       checks                    Per-check overrides.
 * @property {number}                                                                     retention_months          1 to 60.
 * @property {{backups: string, hosting: string, domains: string, accessibility: string}} disclaimers               Report disclaimers (HTML).
 */

/**
 * GET /settings
 *
 * @return {Promise<AmcSettings>} Settings.
 */
export const getSettings = async () =>
	( await request( { path: '/settings', mock: ( s ) => ok( s.settings ) } ) )
		.body;

/**
 * POST /settings (needs `amc_manage` and `manage_options`). Send only what
 * changes; `loopback_auth.password` is write-only and only sent when set.
 *
 * @param {Partial<AmcSettings>} changes Partial settings.
 * @return {Promise<AmcSettings>} Saved settings.
 */
export const saveSettings = async ( changes ) =>
	(
		await request( {
			path: '/settings',
			method: 'POST',
			data: changes,
			mock: ( s ) => {
				const { loopback_auth: auth, ...rest } = changes;
				Object.assign( s.settings, rest );
				if ( auth ) {
					s.settings.loopback_auth = {
						...s.settings.loopback_auth,
						username:
							auth.username ?? s.settings.loopback_auth.username,
						has_password: auth.password
							? true
							: s.settings.loopback_auth.has_password,
					};
				}
				return ok( s.settings );
			},
		} )
	).body;

/**
 * GET /access (needs `amc_manage` and `manage_options`)
 *
 * @return {Promise<Array<{id: number, login: string, display_name: string, email: string}>>} Engineers.
 */
export const getEngineers = async () =>
	( await request( { path: '/access', mock: ( s ) => ok( s.engineers ) } ) )
		.body;

/**
 * POST /access
 *
 * @param {number} userId User to grant `amc_manage`.
 * @return {Promise<Object>} The engineer.
 */
export const grantAccess = async ( userId ) =>
	(
		await request( {
			path: '/access',
			method: 'POST',
			data: { user_id: userId },
			mock: ( s ) => {
				const user = fixtures.candidateUsers.find(
					( u ) => u.id === userId
				);
				const engineer = {
					id: user.id,
					login: user.slug,
					display_name: user.name,
					email: `${ user.slug }@example.org`,
				};
				s.engineers.push( engineer );
				return ok( engineer );
			},
		} )
	).body;

/**
 * DELETE /access/{user_id} (400 `amc_self_revoke` for your own account)
 *
 * @param {number} userId User to revoke.
 * @return {Promise<{revoked: boolean, user_id: number}>} Result.
 */
export const revokeAccess = async ( userId ) =>
	(
		await request( {
			path: `/access/${ userId }`,
			method: 'DELETE',
			mock: ( s ) => {
				s.engineers = s.engineers.filter( ( e ) => e.id !== userId );
				return ok( { revoked: true, user_id: userId } );
			},
		} )
	).body;

/**
 * Users who can be given access: core `GET /wp/v2/users` minus current
 * engineers. Needs the `list_users` capability (administrators have it).
 *
 * @return {Promise<Array<{id: number, name: string, slug: string}>>} Users.
 */
export const getCandidateUsers = async () =>
	(
		await request( {
			namespace: '/wp/v2',
			path: '/users',
			query: { per_page: 100, context: 'edit', _fields: 'id,name,slug' },
			mock: ( s ) =>
				ok(
					fixtures.candidateUsers.filter(
						( u ) => ! s.engineers.some( ( e ) => e.id === u.id )
					)
				),
		} )
	).body;

/**
 * @typedef {Object} AmcSetup
 * @property {boolean}     completed        Whether the wizard has been finished.
 * @property {string|null} completed_at     When, ISO 8601 UTC.
 * @property {boolean}     notice_dismissed Whether the current user closed the setup notice.
 * @property {Object}      [detected]       What the plugin already knows (GET only):
 *                                          environment, site_name, home_url, primary_domain,
 *                                          admin_email, engineer_emails, host, ssl, and
 *                                          `suggestions` in the saved-fields shape.
 */

/**
 * GET /setup (needs `amc_manage` and `manage_options`)
 *
 * @return {Promise<AmcSetup>} Setup state with detected values.
 */
export const getSetup = async () =>
	( await request( { path: '/setup', mock: ( s ) => ok( s.setup ) } ) ).body;

/**
 * POST /setup/complete
 *
 * @return {Promise<AmcSetup>} Setup state.
 */
export const completeSetup = async () =>
	(
		await request( {
			path: '/setup/complete',
			method: 'POST',
			mock: ( s ) => {
				s.setup.completed = true;
				s.setup.completed_at = iso( NOW );
				const { detected, ...setupState } = s.setup;
				return ok( setupState );
			},
		} )
	).body;
