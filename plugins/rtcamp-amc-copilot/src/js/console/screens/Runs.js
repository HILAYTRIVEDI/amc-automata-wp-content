/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useCallback, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { backup, seen } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getRuns } from '../api';
import DataTable from '../components/DataTable';
import RunNowButton from '../components/RunNowButton';
import ScreenState from '../components/ScreenState';
import StatusBadge from '../components/StatusBadge';
import useResource from '../hooks/useResource';
import { dateField, enumField, numberField, textField } from '../utils/fields';
import { formatMs } from '../utils/format';
import { navigate, useLocation } from '../utils/navigation';
import RunDetail from './RunDetail';

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
 * Runs screen. With `run=<id>` it is that run's detail page (RunDetail);
 * otherwise the run history (`GET /runs`), optionally limited to one check
 * with `check=<check_id>`. "Re-run" queues the same check again.
 *
 * @return {Element} Screen.
 */
export default function Runs() {
	const { run: runArg, check } = useLocation();
	const runId = Number.parseInt( runArg, 10 );

	if ( runId > 0 ) {
		return <RunDetail key={ runId } runId={ runId } />;
	}

	return <RunHistory check={ check } />;
}

/**
 * Run history table.
 *
 * @param {Object} props
 * @param {string} [props.check] Only this check's runs.
 * @return {Element} Screen.
 */
function RunHistory( { check } ) {
	const fetcher = useCallback(
		() => getRuns( check ? { check_id: check } : {} ),
		[ check ]
	);
	const { data, error, isLoading, reload } = useResource( fetcher );

	const fields = useMemo(
		() => [
			numberField( 'id', __( 'Run', 'rtcamp-amc-copilot' ), {
				render: ( { item } ) => (
					<Button
						variant="link"
						onClick={ () => navigate( 'runs', { run: item.id } ) }
						aria-label={ sprintf(
							/* translators: %d: run id. */
							__( 'Open run #%d', 'rtcamp-amc-copilot' ),
							item.id
						) }
					>
						{ `#${ item.id }` }
					</Button>
				),
			} ),
			textField( 'check_label', __( 'Check', 'rtcamp-amc-copilot' ) ),
			textField( 'check_id', __( 'Check ID', 'rtcamp-amc-copilot' ) ),
			textField( 'group', __( 'Group', 'rtcamp-amc-copilot' ), {
				getValue: ( { item } ) => item.group_label ?? item.group ?? '',
			} ),
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
			textField( 'summary', __( 'Summary', 'rtcamp-amc-copilot' ), {
				render: ( { item } ) => (
					<span className="amc-cell-wrap">{ item.summary }</span>
				),
			} ),
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
				label: __( 'Open run', 'rtcamp-amc-copilot' ),
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
			{ check && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: check id. */
						__(
							'Showing the runs of %s only.',
							'rtcamp-amc-copilot'
						),
						check
					) }{ ' ' }
					<Button variant="link" onClick={ () => navigate( 'runs' ) }>
						{ __( 'Show all runs', 'rtcamp-amc-copilot' ) }
					</Button>
				</Notice>
			) }
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
		</ScreenState>
	);
}
