/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getRunStatuses, rerunRun, runCheck } from '../api';

const DEFAULT_POLL_MS = 2500;
// About ten minutes: a queued run waits for the next scheduler tick.
const MAX_POLLS = 240;
const PENDING = [ 'queued', 'running' ];

/**
 * Politeness for the spoken result: a run that could not run interrupts,
 * everything else waits its turn.
 *
 * @param {string} status Notice status.
 * @return {string} `assertive` | `polite`.
 */
const politenessFor = ( status ) =>
	'error' === status ? 'assertive' : 'polite';

/**
 * Notice for a set of finished runs.
 *
 * @param {Object[]} runs Finished runs.
 * @return {{status: string, text: string}} Notice.
 */
const summarise = ( runs ) => {
	const errored = runs.filter( ( run ) => 'error' === run.status );
	const failed = runs.filter( ( run ) => 'fail' === run.status );
	const warned = runs.filter( ( run ) => 'warn' === run.status );

	let status = 'success';
	if ( errored.length ) {
		status = 'error';
	} else if ( failed.length || warned.length ) {
		status = 'warning';
	}

	if ( 1 === runs.length ) {
		return {
			status,
			text:
				runs[ 0 ].summary ||
				__( 'The check finished.', 'rtcamp-amc-copilot' ),
		};
	}

	return {
		status,
		text: sprintf(
			/* translators: 1: number of checks, 2: failed, 3: warnings, 4: errors. */
			_n(
				'%1$d check finished: %2$d failed, %3$d with warnings, %4$d could not run.',
				'%1$d checks finished: %2$d failed, %3$d with warnings, %4$d could not run.',
				runs.length,
				'rtcamp-amc-copilot'
			),
			runs.length,
			failed.length,
			warned.length,
			errored.length
		),
	};
};

/**
 * Queue one or more checks (`POST /checks/run`), then poll the runs
 * (`GET /runs/{id}`) until they finish. The check is never run inside the
 * request: the runner only queues it.
 *
 * @param {Object}                          props
 * @param {string|string[]}                 [props.checkIds]     Check id(s), or `'all'`.
 * @param {number}                          [props.rerunId]      Repeat this run instead (`POST /runs/{id}/rerun`).
 * @param {string}                          [props.label]        Button text.
 * @param {string}                          [props.variant]      Button variant.
 * @param {(...args: unknown[]) => unknown} [props.onComplete]   Called with the finished runs.
 * @param {number}                          [props.pollInterval] Poll delay in ms (default 2.5 s).
 * @return {Element} Button and inline notice.
 */
export default function RunNowButton( {
	checkIds,
	rerunId,
	label = __( 'Run now', 'rtcamp-amc-copilot' ),
	variant = 'secondary',
	onComplete,
	pollInterval,
} ) {
	const [ phase, setPhase ] = useState( 'idle' );
	const [ notice, setNotice ] = useState( null );
	const timer = useRef( null );
	const mounted = useRef( true );
	const onCompleteRef = useRef( onComplete );
	onCompleteRef.current = onComplete;

	useEffect(
		() => () => {
			mounted.current = false;
			clearTimeout( timer.current );
		},
		[]
	);

	// Shows the notice and speaks it. The Notice's own announcement is turned
	// off (`spokenMessage=""`), so the result is read out exactly once, with
	// the politeness chosen here.
	const finish = useCallback( ( result ) => {
		setPhase( 'idle' );
		setNotice( result );
		speak( result.text, politenessFor( result.status ) );
	}, [] );

	const fail = useCallback(
		( failure ) => {
			if ( ! mounted.current ) {
				return;
			}
			finish( {
				status: 'error',
				text:
					failure?.message ||
					__(
						'The check could not be queued.',
						'rtcamp-amc-copilot'
					),
			} );
		},
		[ finish ]
	);

	const poll = useCallback(
		( runIds, attempt = 0 ) => {
			const delay =
				pollInterval ??
				window.amcCopilot?.pollInterval ??
				DEFAULT_POLL_MS;
			timer.current = setTimeout( async () => {
				try {
					const runs = await getRunStatuses( runIds );
					if ( ! mounted.current ) {
						return;
					}
					if (
						! runs.some( ( run ) => PENDING.includes( run.status ) )
					) {
						finish( summarise( runs ) );
						onCompleteRef.current?.( runs );
					} else if ( attempt >= MAX_POLLS ) {
						finish( {
							status: 'warning',
							text: __(
								'Still queued. The result will appear on the Runs screen when the scheduler picks it up.',
								'rtcamp-amc-copilot'
							),
						} );
					} else {
						setPhase(
							runs.some( ( run ) => 'running' === run.status )
								? 'running'
								: 'queued'
						);
						poll( runIds, attempt + 1 );
					}
				} catch ( failure ) {
					fail( failure );
				}
			}, delay );
		},
		[ fail, finish, pollInterval ]
	);

	const start = async () => {
		setNotice( null );
		setPhase( 'queued' );
		speak(
			sprintf(
				/* translators: %s: button label, e.g. "Run all checks". */
				__(
					'%s: queued. The result will be announced.',
					'rtcamp-amc-copilot'
				),
				label
			),
			'polite'
		);
		try {
			const runIds = rerunId
				? [ ( await rerunRun( rerunId ) ).run_id ]
				: ( await runCheck( checkIds ) ).runs.map(
						( run ) => run.run_id
					);
			poll( runIds );
		} catch ( failure ) {
			fail( failure );
		}
	};

	const busy = 'idle' !== phase;
	const busyText =
		'running' === phase
			? __( 'Running…', 'rtcamp-amc-copilot' )
			: __( 'Queued…', 'rtcamp-amc-copilot' );

	return (
		<div className="amc-run-now">
			<Button
				variant={ variant }
				onClick={ start }
				disabled={ busy }
				accessibleWhenDisabled
				aria-busy={ busy }
				aria-label={
					busy
						? sprintf(
								/* translators: 1: button label, 2: state, e.g. "Queued…". */
								__( '%1$s: %2$s', 'rtcamp-amc-copilot' ),
								label,
								busyText
							)
						: undefined
				}
			>
				{ busy ? busyText : label }
			</Button>
			{ busy && <Spinner /> }
			{ notice && (
				<Notice
					status={ notice.status }
					spokenMessage=""
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
		</div>
	);
}
