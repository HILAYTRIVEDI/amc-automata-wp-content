/**
 * WordPress dependencies
 */
import { Button, Modal, Notice, TextControl } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { pencil, plus } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getSmoke, saveSmokeUrl } from '../api';
import DataTable from '../components/DataTable';
import FindingLink from '../components/FindingLink';
import RunChecksButton from '../components/RunChecksButton';
import ScreenState from '../components/ScreenState';
import Sparkline from '../components/Sparkline';
import StatusBadge from '../components/StatusBadge';
import { SCREEN_CHECKS } from '../constants';
import useNotice from '../hooks/useNotice';
import useResource from '../hooks/useResource';
import { customField, numberField, textField } from '../utils/fields';
import { formatMs, formatPercent } from '../utils/format';

const STATUS_BY_RESULT = {
	pass: 'pass',
	fail: 'fail',
	loopback_blocked: 'info',
};

const statusLabel = ( item ) =>
	( {
		pass: __( 'Pass', 'rtcamp-amc-copilot' ),
		fail: __( 'Failed', 'rtcamp-amc-copilot' ),
		loopback_blocked: __( 'Loopback blocked', 'rtcamp-amc-copilot' ),
	} )[ item.last_status ] ?? __( 'Not checked yet', 'rtcamp-amc-copilot' );

/**
 * Form for adding a URL or editing its required text.
 *
 * @param {Object}                          props
 * @param {Object}                          [props.item]     URL being edited; omit to add one.
 * @param {(...args: unknown[]) => unknown} props.closeModal Close handler.
 * @param {(...args: unknown[]) => unknown} props.onSaved    Called after saving.
 * @return {Element} Form.
 */
function UrlForm( { item, closeModal, onSaved } ) {
	const [ url, setUrl ] = useState( item?.url ?? '' );
	const [ requiredText, setRequiredText ] = useState(
		item?.required_text ?? ''
	);
	const [ busy, setBusy ] = useState( false );
	const [ problem, setProblem ] = useState( '' );
	const editing = !! item;

	const submit = async ( event ) => {
		event.preventDefault();
		setProblem( '' );
		setBusy( true );
		try {
			await saveSmokeUrl( {
				url: url.trim(),
				required_text: requiredText.trim(),
				isNew: ! editing,
			} );
			onSaved( editing );
			closeModal();
		} catch ( err ) {
			setProblem( err.message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<form onSubmit={ submit }>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'URL', 'rtcamp-amc-copilot' ) }
				type="url"
				value={ url }
				onChange={ setUrl }
				disabled={ editing }
				required
				help={
					editing
						? __(
								'The URL cannot be changed; add a new one instead.',
								'rtcamp-amc-copilot'
							)
						: undefined
				}
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Required text (optional)', 'rtcamp-amc-copilot' ) }
				value={ requiredText }
				onChange={ setRequiredText }
				help={ __(
					'The check fails when the response does not contain this text.',
					'rtcamp-amc-copilot'
				) }
			/>
			{ problem && (
				<Notice status="error" isDismissible={ false }>
					{ problem }
				</Notice>
			) }
			<div className="amc-modal-actions">
				<Button variant="tertiary" onClick={ closeModal }>
					{ __( 'Cancel', 'rtcamp-amc-copilot' ) }
				</Button>
				<Button
					variant="primary"
					type="submit"
					isBusy={ busy }
					disabled={ ! url.trim() || busy }
					accessibleWhenDisabled
				>
					{ __( 'Save', 'rtcamp-amc-copilot' ) }
				</Button>
			</div>
		</form>
	);
}

/**
 * Smoke checks screen (`GET /smoke`): every URL with pass rate, last status
 * and response trend. Extra URLs are added and edited through the settings
 * (`smoke_urls`); the home, login and latest post or product URLs are always
 * checked and are not editable.
 *
 * @return {Element} Screen.
 */
export default function Smoke() {
	const { data, error, isLoading, reload } = useResource( getSmoke );
	const notice = useNotice();
	const [ adding, setAdding ] = useState( false );

	const fields = useMemo(
		() => [
			textField( 'url', __( 'URL', 'rtcamp-amc-copilot' ) ),
			customField(
				'is_default',
				__( 'Source', 'rtcamp-amc-copilot' ),
				( item ) => ( item.is_default ? 'default' : 'added' ),
				( item ) => (
					<span>
						{ item.is_default
							? __( 'Default', 'rtcamp-amc-copilot' )
							: __( 'Added', 'rtcamp-amc-copilot' ) }
					</span>
				),
				{
					elements: [
						{
							value: 'default',
							label: __( 'Default', 'rtcamp-amc-copilot' ),
						},
						{
							value: 'added',
							label: __( 'Added', 'rtcamp-amc-copilot' ),
						},
					],
					filterBy: { operators: [ 'isAny' ] },
				}
			),
			numberField(
				'pass_rate_30d',
				__( 'Pass rate (30 days)', 'rtcamp-amc-copilot' ),
				{
					type: 'number',
					render: ( { item } ) => (
						<span>{ formatPercent( item.pass_rate_30d ) }</span>
					),
				}
			),
			customField(
				'last_status',
				__( 'Last status', 'rtcamp-amc-copilot' ),
				statusLabel,
				( item ) => (
					<StatusBadge
						status={
							STATUS_BY_RESULT[ item.last_status ] ?? 'info'
						}
						label={ statusLabel( item ) }
					/>
				),
				{
					elements: [
						{
							value: 'Pass',
							label: __( 'Pass', 'rtcamp-amc-copilot' ),
						},
						{
							value: 'Failed',
							label: __( 'Failed', 'rtcamp-amc-copilot' ),
						},
						{
							value: 'Loopback blocked',
							label: __(
								'Loopback blocked',
								'rtcamp-amc-copilot'
							),
						},
					],
					filterBy: { operators: [ 'isAny' ], isPrimary: true },
				}
			),
			numberField( 'last_http_code', __( 'HTTP', 'rtcamp-amc-copilot' ), {
				render: ( { item } ) => (
					<span>{ item.last_http_code || '—' }</span>
				),
			} ),
			numberField(
				'last_response_ms',
				__( 'Response time', 'rtcamp-amc-copilot' ),
				{
					render: ( { item } ) => (
						<span>{ formatMs( item.last_response_ms ) }</span>
					),
				}
			),
			customField(
				'trend',
				__( 'Response trend', 'rtcamp-amc-copilot' ),
				( item ) => item.trend.map( ( point ) => point.ms ),
				( item ) => (
					<Sparkline
						values={ item.trend.map( ( point ) => point.ms ) }
					/>
				),
				{ enableSorting: false }
			),
			textField(
				'required_text',
				__( 'Required text', 'rtcamp-amc-copilot' )
			),
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit required text', 'rtcamp-amc-copilot' ),
				icon: pencil,
				isPrimary: true,
				isEligible: ( item ) => ! item.is_default,
				modalHeader: __( 'Edit required text', 'rtcamp-amc-copilot' ),
				RenderModal: ( { items, closeModal } ) => (
					<UrlForm
						item={ items[ 0 ] }
						closeModal={ closeModal }
						onSaved={ () => {
							notice.success(
								__(
									'Required text saved.',
									'rtcamp-amc-copilot'
								)
							);
							reload();
						} }
					/>
				),
			},
		],
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ reload ]
	);

	return (
		<ScreenState
			title={ __( 'Smoke checks', 'rtcamp-amc-copilot' ) }
			actions={
				<>
					<Button
						variant="secondary"
						icon={ plus }
						onClick={ () => setAdding( true ) }
					>
						{ __( 'Add URL', 'rtcamp-amc-copilot' ) }
					</Button>
					<RunChecksButton
						checkIds={ SCREEN_CHECKS.smoke }
						variant="primary"
						label={ __( 'Run now', 'rtcamp-amc-copilot' ) }
						onComplete={ reload }
					/>
				</>
			}
			isLoading={ isLoading }
			error={ error }
			onRetry={ reload }
			hasData={ !! data }
			stub={ data?.stub }
		>
			{ notice.node }
			{ data && (
				<>
					{ data.last_run && (
						<p className="description">
							{ data.last_run.run.summary }{ ' ' }
							<FindingLink runId={ data.last_run.run.id } />
						</p>
					) }
					<DataTable
						data={ data.urls }
						fields={ fields }
						actions={ actions }
						getItemId={ ( item ) => item.url }
						exportName="amc-smoke-checks"
					/>
				</>
			) }
			{ adding && (
				<Modal
					title={ __( 'Add smoke check URL', 'rtcamp-amc-copilot' ) }
					onRequestClose={ () => setAdding( false ) }
				>
					<UrlForm
						closeModal={ () => setAdding( false ) }
						onSaved={ () => {
							notice.success(
								__(
									'URL added. It is checked on the next run.',
									'rtcamp-amc-copilot'
								)
							);
							reload();
						} }
					/>
				</Modal>
			) }
		</ScreenState>
	);
}
