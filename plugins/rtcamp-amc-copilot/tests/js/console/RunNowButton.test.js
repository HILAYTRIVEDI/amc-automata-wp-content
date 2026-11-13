/**
 * Internal dependencies
 */
import { act, advance, describeWithDom, render } from './test-utils';

// Lightweight stand-ins keep this test independent of the full
// @wordpress/components tree; only the markup the assertions need.
jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, disabled, onClick } ) => (
		<button type="button" disabled={ disabled } onClick={ onClick }>
			{ children }
		</button>
	),
	Spinner: () => <span className="components-spinner" />,
	Notice: ( { status, children } ) => (
		<div className={ `components-notice is-${ status }` }>{ children }</div>
	),
} ) );

jest.mock( '../../../src/js/console/api', () => ( {
	runCheck: jest.fn(),
	rerunRun: jest.fn(),
	getRunStatuses: jest.fn(),
} ) );

let RunNowButton;
let runCheck;
let rerunRun;
let getRunStatuses;

const click = async ( container ) => {
	await act( async () => {
		container.querySelector( 'button' ).click();
	} );
};

describeWithDom( 'RunNowButton', () => {
	beforeAll( () => {
		RunNowButton =
			require( '../../../src/js/console/components/RunNowButton' ).default;
		( {
			runCheck,
			rerunRun,
			getRunStatuses,
		} = require( '../../../src/js/console/api' ) );
	} );

	beforeEach( () => {
		jest.useFakeTimers();
		runCheck.mockReset();
		rerunRun.mockReset();
		getRunStatuses.mockReset();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'queues with POST /checks/run, polls the runs, then shows a notice and calls onComplete', async () => {
		runCheck.mockResolvedValue( {
			runs: [ { check_id: 'smoke.urls', run_id: 42 } ],
		} );
		getRunStatuses
			.mockResolvedValueOnce( [ { id: 42, status: 'queued' } ] )
			.mockResolvedValueOnce( [ { id: 42, status: 'running' } ] )
			.mockResolvedValueOnce( [
				{ id: 42, status: 'pass', summary: 'No issues found.' },
			] );
		const onComplete = jest.fn();

		const { container, unmount } = await render(
			<RunNowButton
				checkIds="smoke.urls"
				pollInterval={ 1000 }
				onComplete={ onComplete }
			/>
		);
		expect( container.querySelector( 'button' ).textContent ).toBe(
			'Run now'
		);

		await click( container );
		// The request only enqueues; nothing has been polled yet.
		expect( runCheck ).toHaveBeenCalledWith( 'smoke.urls' );
		expect( getRunStatuses ).not.toHaveBeenCalled();
		expect(
			container.querySelector( '.components-spinner' )
		).not.toBeNull();
		expect( container.querySelector( 'button' ).disabled ).toBe( true );

		await advance( 1000 );
		expect( getRunStatuses ).toHaveBeenCalledWith( [ 42 ] );
		expect( container.querySelector( 'button' ).textContent ).toBe(
			'Queued…'
		);

		await advance( 1000 );
		expect( container.querySelector( 'button' ).textContent ).toBe(
			'Running…'
		);

		await advance( 1000 );
		expect( getRunStatuses ).toHaveBeenCalledTimes( 3 );
		expect( container.querySelector( '.components-spinner' ) ).toBeNull();
		expect(
			container.querySelector( '.components-notice.is-success' )
				.textContent
		).toContain( 'No issues found.' );
		expect( container.querySelector( 'button' ).disabled ).toBe( false );
		expect( onComplete ).toHaveBeenCalledTimes( 1 );

		// No further polling once finished.
		await advance( 5000 );
		expect( getRunStatuses ).toHaveBeenCalledTimes( 3 );
		await unmount();
	} );

	it( 'warns when a finished check has a failing result', async () => {
		runCheck.mockResolvedValue( {
			runs: [ { check_id: 'smoke.urls', run_id: 1 } ],
		} );
		getRunStatuses.mockResolvedValue( [
			{ id: 1, status: 'fail', summary: '1 of 5 URLs failed.' },
		] );
		const { container, unmount } = await render(
			<RunNowButton checkIds="smoke.urls" pollInterval={ 500 } />
		);
		await click( container );
		await advance( 500 );
		expect(
			container.querySelector( '.components-notice.is-warning' )
		).not.toBeNull();
		await unmount();
	} );

	it( 'summarises several runs and errors when a check could not run', async () => {
		runCheck.mockResolvedValue( {
			runs: [
				{ check_id: 'a.one', run_id: 1 },
				{ check_id: 'a.two', run_id: 2 },
			],
		} );
		getRunStatuses.mockResolvedValue( [
			{ id: 1, status: 'pass', summary: '' },
			{ id: 2, status: 'error', summary: 'It threw.' },
		] );
		const { container, unmount } = await render(
			<RunNowButton checkIds="all" pollInterval={ 500 } />
		);
		await click( container );
		await advance( 500 );
		expect( runCheck ).toHaveBeenCalledWith( 'all' );
		const notice = container.querySelector( '.components-notice.is-error' );
		expect( notice.textContent ).toContain( '2 checks finished' );
		expect( notice.textContent ).toContain( '1 could not run' );
		await unmount();
	} );

	it( 're-runs a run with POST /runs/{id}/rerun', async () => {
		rerunRun.mockResolvedValue( { run_id: 77 } );
		getRunStatuses.mockResolvedValue( [
			{ id: 77, status: 'pass', summary: 'Done.' },
		] );
		const { container, unmount } = await render(
			<RunNowButton rerunId={ 12 } pollInterval={ 500 } />
		);
		await click( container );
		await advance( 500 );
		expect( rerunRun ).toHaveBeenCalledWith( 12 );
		expect( runCheck ).not.toHaveBeenCalled();
		expect( getRunStatuses ).toHaveBeenCalledWith( [ 77 ] );
		await unmount();
	} );

	it( 'shows an error notice when queueing fails and does not poll', async () => {
		runCheck.mockRejectedValue( new Error( 'Forbidden' ) );
		const { container, unmount } = await render(
			<RunNowButton checkIds="all" />
		);
		await click( container );
		expect(
			container.querySelector( '.components-notice.is-error' ).textContent
		).toContain( 'Forbidden' );
		await advance( 5000 );
		expect( getRunStatuses ).not.toHaveBeenCalled();
		await unmount();
	} );

	it( 'stops polling when unmounted', async () => {
		runCheck.mockResolvedValue( {
			runs: [ { check_id: 'x.y', run_id: 5 } ],
		} );
		getRunStatuses.mockResolvedValue( [ { id: 5, status: 'running' } ] );
		const { container, unmount } = await render(
			<RunNowButton checkIds="x.y" pollInterval={ 500 } />
		);
		await click( container );
		await advance( 500 );
		expect( getRunStatuses ).toHaveBeenCalledTimes( 1 );
		await unmount();
		await advance( 5000 );
		expect( getRunStatuses ).toHaveBeenCalledTimes( 1 );
	} );
} );
