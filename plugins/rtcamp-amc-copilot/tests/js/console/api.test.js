/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import {
	ApiError,
	acceptRisk,
	generateReport,
	getEvents,
	getFinding,
	getOverview,
	getPerformance,
	getRuns,
	getRunStatuses,
	getSmoke,
	getVulnerabilities,
	isMockMode,
	isNotImplemented,
	muteSignature,
	resetMocks,
	runCheck,
	saveSettings,
	getSettings,
	saveSmokeUrl,
} from '../../../src/js/console/api';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

/**
 * A fetch `Response` stand-in for `apiFetch( { parse: false } )`.
 *
 * @param {unknown} body    JSON body.
 * @param {Object}  headers Response headers.
 * @param {number}  status  HTTP status.
 * @return {Object} Response-like object.
 */
const response = ( body, headers = {}, status = 200 ) => ( {
	status,
	headers: { get: ( name ) => headers[ name ] ?? null },
	json: async () => body,
} );

describe( 'console api (real routes)', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		resetMocks();
		window.amcCopilot = { useMocks: false };
	} );

	afterEach( () => {
		delete window.amcCopilot;
	} );

	it( 'calls the REST API and reports mock mode off', async () => {
		apiFetch.mockResolvedValue( response( { status: 'pass' } ) );

		await expect( getOverview() ).resolves.toEqual( { status: 'pass' } );
		expect( isMockMode() ).toBe( false );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/amc/v1/overview',
			method: 'GET',
			parse: false,
		} );
	} );

	it( 'uses the REST API when amcCopilot is not defined', async () => {
		delete window.amcCopilot;
		apiFetch.mockResolvedValue( response( {} ) );
		await getOverview();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'queues checks with POST /checks/run', async () => {
		apiFetch.mockResolvedValue(
			response(
				{ runs: [ { check_id: 'updates.core', run_id: 42 } ] },
				{},
				202
			)
		);

		await expect( runCheck( [ 'updates.core' ] ) ).resolves.toEqual( {
			runs: [ { check_id: 'updates.core', run_id: 42 } ],
		} );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/amc/v1/checks/run',
			method: 'POST',
			data: { check_ids: [ 'updates.core' ] },
			parse: false,
		} );

		await runCheck( 'all' );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].data ).toEqual( {
			check_ids: [ 'all' ],
		} );
	} );

	it( 'polls a few runs one by one with GET /runs/{id}', async () => {
		apiFetch.mockImplementation( ( { path } ) =>
			Promise.resolve(
				response( {
					id: Number( path.split( '/' ).pop() ),
					status: 'running',
				} )
			)
		);

		const runs = await getRunStatuses( [ 7, 8 ] );
		expect( runs.map( ( r ) => r.id ) ).toEqual( [ 7, 8 ] );
		expect( apiFetch.mock.calls.map( ( [ args ] ) => args.path ) ).toEqual(
			[ '/amc/v1/runs/7', '/amc/v1/runs/8' ]
		);
	} );

	it( 'polls a big batch with one list request', async () => {
		apiFetch.mockResolvedValue(
			response(
				[
					{ id: 1, status: 'pass' },
					{ id: 2, status: 'queued' },
				],
				{ 'X-WP-Total': '2' }
			)
		);

		const runs = await getRunStatuses( [ 1, 2, 3, 4 ] );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe(
			'/amc/v1/runs?per_page=500'
		);
		expect( runs.map( ( r ) => r.status ) ).toEqual( [
			'pass',
			'queued',
			'queued',
			'queued',
		] );
	} );

	it( 'reads list totals and the stub header', async () => {
		apiFetch.mockResolvedValueOnce(
			response( [ { id: 1 } ], { 'X-WP-Total': '120' } )
		);
		await expect(
			getRuns( { check_id: 'smoke.urls', status: 'fail' } )
		).resolves.toEqual( {
			items: [ { id: 1 } ],
			total: 120,
		} );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe(
			'/amc/v1/runs?check_id=smoke.urls&status=fail&per_page=500'
		);

		apiFetch.mockResolvedValueOnce(
			response( { last_run: null, urls: [] }, { 'X-AMC-Stub': 'B2' } )
		);
		await expect( getSmoke() ).resolves.toEqual( {
			last_run: null,
			urls: [],
			stub: 'B2',
		} );

		apiFetch.mockResolvedValueOnce(
			response( { checks: {}, response_trend: [], slow_queries: [] } )
		);
		await expect( getPerformance( { days: 7 } ) ).resolves.toMatchObject( {
			stub: '',
		} );
		expect( apiFetch.mock.calls[ 2 ][ 0 ].path ).toBe(
			'/amc/v1/performance?days=7'
		);
	} );

	it( 'posts finding statuses for accepted risk, resolve and mute', async () => {
		apiFetch.mockResolvedValue( response( { id: 7 } ) );

		await acceptRisk( 7, 'Only admins can reach it.' );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/amc/v1/findings/7/status',
			method: 'POST',
			data: { status: 'accepted', note: 'Only admins can reach it.' },
			parse: false,
		} );

		await muteSignature( 7, true );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].data ).toEqual( {
			status: 'muted',
		} );
		await muteSignature( 7, false );
		expect( apiFetch.mock.calls[ 2 ][ 0 ].data ).toEqual( {
			status: 'open',
		} );
	} );

	it( 'fetches a single finding for the alert deep link', async () => {
		apiFetch.mockResolvedValue(
			response( { id: 12, check_id: 'vuln.match' } )
		);
		await expect( getFinding( 12 ) ).resolves.toEqual( {
			id: 12,
			check_id: 'vuln.match',
		} );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe(
			'/amc/v1/findings/12'
		);
	} );

	it( 'joins the run id onto error rows from the findings', async () => {
		apiFetch.mockImplementation( ( { path } ) => {
			if ( path.startsWith( '/amc/v1/errors' ) ) {
				return Promise.resolve(
					response( [
						{
							signature: 'abc',
							type: 'fatal',
							finding_id: 5,
							finding_status: 'open',
						},
					] )
				);
			}
			return Promise.resolve(
				path.includes( 'errors.fatal' )
					? response( [ { id: 5, run_id: 99 } ] )
					: response( [] )
			);
		} );

		const { items } = await getEvents( { days: 30 } );
		expect( items[ 0 ].run_id ).toBe( 99 );
	} );

	it( 'adds a smoke URL through the settings', async () => {
		apiFetch.mockImplementation( ( { method, data } ) =>
			Promise.resolve(
				response(
					'POST' === method
						? { smoke_urls: data.smoke_urls }
						: {
								smoke_urls: [
									{
										url: 'https://a.test/',
										required_text: '',
									},
								],
							}
				)
			)
		);

		const saved = await saveSmokeUrl( {
			url: 'https://b.test/',
			required_text: 'Buy',
			isNew: true,
		} );
		expect( saved.smoke_urls ).toEqual( [
			{ url: 'https://a.test/', required_text: '' },
			{ url: 'https://b.test/', required_text: 'Buy' },
		] );
		await expect(
			saveSmokeUrl( { url: 'https://a.test/', isNew: true } )
		).rejects.toThrow( 'already being checked' );
	} );

	it( 'turns a failed response into an ApiError, flagging 501s', async () => {
		apiFetch.mockRejectedValue(
			response(
				{
					code: 'amc_not_implemented',
					message: 'Not implemented yet (owner: lane D1).',
					data: { status: 501, owner: 'D1' },
				},
				{},
				501
			)
		);

		const failure = await generateReport( { period_type: 'month' } ).catch(
			( e ) => e
		);
		expect( failure ).toBeInstanceOf( ApiError );
		expect( failure.code ).toBe( 'amc_not_implemented' );
		expect( failure.owner ).toBe( 'D1' );
		expect( isNotImplemented( failure ) ).toBe( true );
		expect( isNotImplemented( new ApiError( 'x', { status: 500 } ) ) ).toBe(
			false
		);
	} );

	it( 'handles network failures that are not responses', async () => {
		apiFetch.mockRejectedValue( {
			code: 'fetch_error',
			message: 'Offline',
		} );
		await expect( getOverview() ).rejects.toMatchObject( {
			message: 'Offline',
			code: 'fetch_error',
		} );
	} );

	it( 'sends only the changed settings', async () => {
		apiFetch.mockResolvedValue( response( {} ) );
		await saveSettings( { digest_time: '07:30' } );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			path: '/amc/v1/settings',
			method: 'POST',
			data: { digest_time: '07:30' },
		} );
		await getSettings();
		expect( apiFetch.mock.calls[ 1 ][ 0 ].method ).toBe( 'GET' );
	} );
} );

describe( 'console api (mock mode)', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		resetMocks();
		window.amcCopilot = { useMocks: true, mockLatency: 0 };
	} );

	afterEach( () => {
		delete window.amcCopilot;
	} );

	it( 'returns fixtures and never calls apiFetch', async () => {
		const overview = await getOverview();
		expect( overview.groups.length ).toBeGreaterThan( 0 );
		expect( isMockMode() ).toBe( true );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'keeps state between calls (accepted risk)', async () => {
		await acceptRisk( 301, 'Accepted for now.' );
		const { findings } = await getVulnerabilities( { status: 'accepted' } );
		const accepted = findings.find( ( f ) => 301 === f.id );
		expect( accepted.status ).toBe( 'accepted' );
		expect( accepted.note ).toBe( 'Accepted for now.' );
	} );

	it( 'mutes an errors signature through its finding', async () => {
		const { items } = await getEvents();
		const open = items.find( ( row ) => 'open' === row.finding_status );
		await muteSignature( open.finding_id );
		const after = await getEvents();
		expect(
			after.items.find( ( r ) => r.signature === open.signature )
				.finding_status
		).toBe( 'muted' );
	} );

	it( 'saves settings without echoing the loopback password', async () => {
		const current = await getSettings();
		const saved = await saveSettings( {
			digest_time: '07:30',
			loopback_auth: { username: 'amc', password: 'secret' },
		} );
		expect( current.digest_time ).toBe( '09:00' );
		expect( saved.digest_time ).toBe( '07:30' );
		expect( saved.loopback_auth ).toEqual( {
			username: 'amc',
			has_password: true,
			encryption_available: true,
		} );
	} );

	it( 'queues a check and shows it progressing to a result', async () => {
		const { runs } = await runCheck( [ 'smoke.urls' ] );
		expect( runs ).toHaveLength( 1 );

		const seen = [];
		let run;
		do {
			[ run ] = await getRunStatuses( [ runs[ 0 ].run_id ] );
			seen.push( run.status );
		} while ( [ 'queued', 'running' ].includes( run.status ) );

		expect( seen ).toEqual( [ 'running', 'fail' ] );
		expect( run.summary ).toContain( 'URLs failed' );
	} );

	it( 'rejects unknown checks like the runner does', async () => {
		await expect( runCheck( [ 'nope.nope' ] ) ).rejects.toMatchObject( {
			code: 'amc_unknown_check',
		} );
	} );

	it( 'serves a single finding and 404s on an unknown one', async () => {
		await expect( getFinding( 302 ) ).resolves.toMatchObject( {
			check_id: 'vuln.match',
		} );
		await expect( getFinding( 99999 ) ).rejects.toMatchObject( {
			status: 404,
		} );
	} );

	it( 'flags stubbed screens like the REST API does', async () => {
		expect( ( await getSmoke() ).stub ).toBe( 'B2' );
		expect( ( await getPerformance() ).stub ).toBe( 'B2' );
	} );
} );
