/**
 * WordPress dependencies
 */
import { Button, Modal, Spinner } from '@wordpress/components';
import { useCallback, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { backup, seen } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getRun, getRuns } from '../api';
import DataTable from '../components/DataTable';
import RunNowButton from '../components/RunNowButton';
import ScreenState, { ErrorNotice } from '../components/ScreenState';
import StatusBadge, {
	severityLabel,
	statusFromSeverity,
} from '../components/StatusBadge';
import useResource from '../hooks/useResource';
import { dateField, enumField, numberField, textField } from '../utils/fields';
import { formatDateTime, formatMs } from '../utils/format';
import {
	navigate,
	useFocusHeadingOnClose,
	useLocation,
} from '../utils/navigation';

const triggerElements = () => [
	{ value: 'cron', label: __( 'Cron', 'rtcamp-amc-copilot' ) },
	{ value: 'manual', label: __( 'Manual', 'rtcamp-amc-copilot' ) },
	{ value: 'cli', label: __( 'WP-CLI', 'rtcamp-amc-copilot' ) },
];

const runStatusElements = () => [
	{ value: 'pass', label: __( 'Pass', 'rtcamp-amc-copilot' ) },
	{ value: 'warn', label: __( 'Warning', 'rtcamp-amc-copilot' ) },
	{ value: 'fail', label: __( 'Failed', 'rtcamp-amc-copilot' ) },
	{ value: 'info', label: __( 'Info', 'rtcamp-amc-copilot' ) },
	{
		value: 'error',
		label: __( 'Error (check threw)', 'rtcamp-amc-copilot' ),
	},
	{ value: 'queued', label: __( 'Queued', 'rtcamp-amc-copilot' ) },
	{ value: 'running', label: __( 'Running', 'rtcamp-amc-copilot' ) },
];

const BADGE = { error: 'warn', queued: 'info', running: 'info' };

/**
 * Status badge for a run status (adds queued, running and error).
 *
 * @param {Object} props
 * @param {string} props.status Run status.
 * @return {Element} Badge.
 */
const RunStatus = ( { status } ) => (
	<StatusBadge
		status={ BADGE[ status ] ?? status }
		label={ runStatusElements().find( ( e ) => e.value === status )?.label }
	/>
);

/**
 * Modal with a run's raw data and findings (`GET /runs/{id}`).
 *
 * @param {Object}                          props
 * @param {number}                          props.runId   Run id.
 * @param {(...args: unknown[]) => unknown} props.onClose Close handler.
 * @return {Element} Modal.
 */
function RunDataModal( { runId, onClose } ) {
	const fetcher = useCallback( () => getRun( runId ), [ runId ] );
	const { data: run, error, isLoading } = useResource( fetcher );

	return (
		<Modal
			title={ sprintf(
				/* translators: %d: run id. */
				__( 'Run #%d', 'rtcamp-amc-copilot' ),
				runId
			) }
			onRequestClose={ onClose }
			size="large"
		>
			{ isLoading && ! run && (
				<p role="status">
					<Spinner />{ ' ' }
					<span className="screen-reader-text">
						{ __( 'Loading the run…', 'rtcamp-amc-copilot' ) }
					</span>
				</p>
			) }
			{ error && <ErrorNotice error={ error } /> }
			{ run && (
				<>
					<p>
						<RunStatus status={ run.status } />{ ' ' }
						<strong>{ run.check_label }</strong> ({ run.check_id })
					</p>
					<p>{ run.summary }</p>
					<p className="description">
						{ sprintf(
							/* translators: 1: trigger, 2: date and time, 3: duration. */
							__(
								'Trigger: %1$s. Started %2$s. Took %3$s.',
								'rtcamp-amc-copilot'
							),
							run.trigger,
							formatDateTime( run.started_at ?? run.created_at ),
							formatMs( run.duration_ms )
						) }
					</p>
					{ run.findings?.length > 0 && (
						<>
							<h2>
								{ __(
									'Findings from this run',
									'rtcamp-amc-copilot'
								) }
							</h2>
							<ul className="amc-list">
								{ run.findings.map( ( finding ) => (
									<li key={ finding.id }>
										<StatusBadge
											status={ statusFromSeverity(
												finding.severity
											) }
											label={ severityLabel(
												finding.severity
											) }
										/>{ ' ' }
										{ finding.title }
									</li>
								) ) }
							</ul>
						</>
					) }
					<h2>{ __( 'Result data', 'rtcamp-amc-copilot' ) }</h2>
					<pre
						className="amc-json"
						tabIndex={ 0 }
						role="region"
						aria-label={ __(
							'Run data (JSON)',
							'rtcamp-amc-copilot'
						) }
					>
						{ JSON.stringify( run.data, null, 2 ) }
					</pre>
				</>
			) }
		</Modal>
	);
}

/**
 * Runs screen (`GET /runs`): every run with trigger, duration, status and
 * the result's data. "Re-run" queues the same check again.
 *
 * @return {Element} Screen.
 */
export default function Runs() {
	const { data, error, isLoading, reload } = useResource( getRuns );
	const { run: runArg } = useLocation();
	useFocusHeadingOnClose( !! runArg );

	const fields = useMemo(
		() => [
			numberField( 'id', __( 'Run', 'rtcamp-amc-copilot' ), {
				render: ( { item } ) => (
					<Button
						variant="link"
						onClick={ () => navigate( 'runs', { run: item.id } ) }
						aria-label={ sprintf(
							/* translators: %d: run id. */
							__( 'View data for run #%d', 'rtcamp-amc-copilot' ),
							item.id
						) }
					>
						{ `#${ item.id }` }
					</Button>
				),
			} ),
			textField( 'check_label', __( 'Check', 'rtcamp-amc-copilot' ) ),
			textField( 'check_id', __( 'Check ID', 'rtcamp-amc-copilot' ) ),
			textField( 'group', __( 'Group', 'rtcamp-amc-copilot' ) ),
			enumField(
				'trigger',
				__( 'Trigger', 'rtcamp-amc-copilot' ),
				triggerElements(),
				{
					filterBy: { operators: [ 'isAny' ], isPrimary: true },
				}
			),
			enumField(
				'status',
				__( 'Status', 'rtcamp-amc-copilot' ),
				runStatusElements(),
				{
					filterBy: { operators: [ 'isAny' ], isPrimary: true },
					render: ( { item } ) => (
						<RunStatus status={ item.status } />
					),
				}
			),
			textField( 'summary', __( 'Summary', 'rtcamp-amc-copilot' ) ),
			dateField( 'created_at', __( 'Started', 'rtcamp-amc-copilot' ) ),
			numberField(
				'duration_ms',
				__( 'Duration', 'rtcamp-amc-copilot' ),
				{
					render: ( { item } ) => (
						<span>{ formatMs( item.duration_ms ) }</span>
					),
				}
			),
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id: 'view-data',
				label: __( 'View data', 'rtcamp-amc-copilot' ),
				icon: seen,
				isPrimary: true,
				callback: ( [ item ] ) => navigate( 'runs', { run: item.id } ),
			},
			{
				id: 're-run',
				label: __( 'Re-run', 'rtcamp-amc-copilot' ),
				icon: backup,
				modalHeader: __( 'Re-run check', 'rtcamp-amc-copilot' ),
				modalSize: 'small',
				RenderModal: ( { items } ) => (
					<div>
						<p>
							{ sprintf(
								/* translators: %s: check id. */
								__(
									'Queue %s to run now. The result appears as a new run.',
									'rtcamp-amc-copilot'
								),
								items[ 0 ].check_id
							) }
						</p>
						<RunNowButton
							rerunId={ items[ 0 ].id }
							label={ __( 'Re-run', 'rtcamp-amc-copilot' ) }
							variant="primary"
							onComplete={ reload }
						/>
					</div>
				),
			},
		],
		[ reload ]
	);

	return (
		<ScreenState
			screen="runs"
			isLoading={ isLoading }
			error={ error }
			onRetry={ reload }
			hasData={ !! data }
		>
			{ data && (
				<DataTable
					data={ data.items }
					fields={ fields }
					actions={ actions }
					exportName="amc-runs"
					variant="full"
					label={ __( 'Run history', 'rtcamp-amc-copilot' ) }
					initialView={ {
						fields: fields
							.map( ( f ) => f.id )
							.filter( ( id ) => 'check_id' !== id ),
					} }
				/>
			) }
			{ runArg && (
				<RunDataModal
					key={ runArg }
					runId={ Number.parseInt( runArg, 10 ) }
					onClose={ () => navigate( 'runs' ) }
				/>
			) }
		</ScreenState>
	);
}
