/**
 * WordPress dependencies
 */
import {
	Button,
	Modal,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { external, pencil, plus, published } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import {
	generateReport,
	getReports,
	getSavedFields,
	publishReport,
} from '../api';
import DataTable from '../components/DataTable';
import ScreenState, { ErrorNotice } from '../components/ScreenState';
import StatusBadge from '../components/StatusBadge';
import useNotice from '../hooks/useNotice';
import useResource from '../hooks/useResource';
import { customField, dateField, enumField, textField } from '../utils/fields';
import { formatDate } from '../utils/format';
import { navigate, screenUrl } from '../utils/navigation';

const periodElements = () => [
	{ value: 'month', label: __( 'Monthly', 'rtcamp-amc-copilot' ) },
	{ value: 'quarter', label: __( 'Quarterly', 'rtcamp-amc-copilot' ) },
	{ value: 'year', label: __( 'Annual', 'rtcamp-amc-copilot' ) },
	{ value: 'custom', label: __( 'Custom', 'rtcamp-amc-copilot' ) },
];

const stateElements = () => [
	{ value: 'draft', label: __( 'Draft', 'rtcamp-amc-copilot' ) },
	{ value: 'publish', label: __( 'Published', 'rtcamp-amc-copilot' ) },
	{ value: 'pending', label: __( 'Pending', 'rtcamp-amc-copilot' ) },
	{ value: 'future', label: __( 'Scheduled', 'rtcamp-amc-copilot' ) },
];

const pad = ( n ) => String( n ).padStart( 2, '0' );
const isoDate = ( date ) =>
	`${ date.getFullYear() }-${ pad( date.getMonth() + 1 ) }-${ pad( date.getDate() ) }`;

/**
 * Default range for a period type: the previous full month, quarter or year.
 *
 * @param {string} type month | quarter | year | custom.
 * @return {{start: string, end: string}} Dates, YYYY-MM-DD.
 */
export const defaultRange = ( type ) => {
	const now = new Date();
	if ( 'quarter' === type ) {
		const q = Math.floor( now.getMonth() / 3 );
		return {
			start: isoDate( new Date( now.getFullYear(), ( q - 1 ) * 3, 1 ) ),
			end: isoDate( new Date( now.getFullYear(), q * 3, 0 ) ),
		};
	}
	if ( 'year' === type ) {
		return {
			start: isoDate( new Date( now.getFullYear() - 1, 0, 1 ) ),
			end: isoDate( new Date( now.getFullYear() - 1, 11, 31 ) ),
		};
	}
	return {
		start: isoDate( new Date( now.getFullYear(), now.getMonth() - 1, 1 ) ),
		end: isoDate( new Date( now.getFullYear(), now.getMonth(), 0 ) ),
	};
};

/**
 * Form to generate a draft report for a period (`POST /reports/generate`).
 *
 * @param {Object}                          props
 * @param {(...args: unknown[]) => unknown} props.closeModal Close handler.
 * @param {(...args: unknown[]) => unknown} props.onDone     Called with the new report.
 * @return {Element} Form.
 */
function GenerateForm( { closeModal, onDone } ) {
	const [ type, setType ] = useState( 'month' );
	const [ range, setRange ] = useState( defaultRange( 'month' ) );
	const [ busy, setBusy ] = useState( false );
	const [ problem, setProblem ] = useState( null );

	const changeType = ( next ) => {
		setType( next );
		setRange( defaultRange( next ) );
	};
	const invalid = ! range.start || ! range.end || range.start > range.end;

	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setProblem( null );
		try {
			const report = await generateReport( {
				period_type: type,
				start: range.start,
				end: range.end,
			} );
			onDone( report );
			closeModal();
		} catch ( err ) {
			setProblem( err );
		} finally {
			setBusy( false );
		}
	};

	return (
		<form onSubmit={ submit }>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Period', 'rtcamp-amc-copilot' ) }
				value={ type }
				options={ periodElements() }
				onChange={ changeType }
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="date"
				label={ __( 'Start date', 'rtcamp-amc-copilot' ) }
				value={ range.start }
				onChange={ ( start ) =>
					setRange( ( r ) => ( { ...r, start } ) )
				}
				required
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="date"
				label={ __( 'End date', 'rtcamp-amc-copilot' ) }
				value={ range.end }
				onChange={ ( end ) => setRange( ( r ) => ( { ...r, end } ) ) }
				required
				help={
					invalid && range.start && range.end
						? __(
								'The end date must not be before the start date.',
								'rtcamp-amc-copilot'
							)
						: undefined
				}
			/>
			{ problem && <ErrorNotice error={ problem } /> }
			<div className="amc-modal-actions">
				<Button variant="tertiary" onClick={ closeModal }>
					{ __( 'Cancel', 'rtcamp-amc-copilot' ) }
				</Button>
				<Button
					variant="primary"
					type="submit"
					isBusy={ busy }
					disabled={ invalid || busy }
					accessibleWhenDisabled
				>
					{ __( 'Generate draft', 'rtcamp-amc-copilot' ) }
				</Button>
			</div>
		</form>
	);
}

/**
 * Reports screen (`GET /reports`): report list, generate a report, saved
 * fields. Editing opens the block editor; publishing uses core's REST route
 * for the post type. Generating is lane D1 and shows "not available yet"
 * until it ships.
 *
 * @return {Element} Screen.
 */
export default function Reports() {
	const { data, error, isLoading, reload } = useResource( getReports );
	const saved = useResource( getSavedFields );
	const notice = useNotice();
	const [ generating, setGenerating ] = useState( false );

	const fields = useMemo(
		() => [
			textField( 'report_id', __( 'Report ID', 'rtcamp-amc-copilot' ) ),
			textField( 'title', __( 'Title', 'rtcamp-amc-copilot' ) ),
			enumField(
				'period_type',
				__( 'Period', 'rtcamp-amc-copilot' ),
				periodElements()
			),
			customField(
				'range',
				__( 'Covers', 'rtcamp-amc-copilot' ),
				( item ) => `${ item.period_start } – ${ item.period_end }`,
				( item ) => (
					<span>{ `${ formatDate( item.period_start ) } – ${ formatDate( item.period_end ) }` }</span>
				)
			),
			customField(
				'status',
				__( 'Status', 'rtcamp-amc-copilot' ),
				( item ) => item.status,
				( item ) => (
					<StatusBadge
						status={ 'publish' === item.status ? 'pass' : 'info' }
						label={
							stateElements().find(
								( e ) => e.value === item.status
							)?.label
						}
					/>
				),
				{
					elements: stateElements(),
					filterBy: { operators: [ 'isAny' ], isPrimary: true },
				}
			),
			customField(
				'author',
				__( 'Engineer', 'rtcamp-amc-copilot' ),
				( item ) => item.author?.name ?? '',
				( item ) => <span>{ item.author?.name || '—' }</span>
			),
			dateField( 'modified', __( 'Modified', 'rtcamp-amc-copilot' ) ),
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'rtcamp-amc-copilot' ),
				icon: pencil,
				isPrimary: true,
				isEligible: ( item ) => !! item.edit_url,
				callback: ( [ item ] ) => {
					window.location.assign( item.edit_url );
				},
			},
			{
				id: 'publish',
				label: __( 'Publish', 'rtcamp-amc-copilot' ),
				icon: published,
				isEligible: ( item ) => 'draft' === item.status,
				modalHeader: __( 'Publish report', 'rtcamp-amc-copilot' ),
				modalSize: 'small',
				RenderModal: ( { items, closeModal } ) => (
					<div>
						<p>
							{ sprintf(
								/* translators: %s: report id. */
								__(
									'Publishing %s makes it visible to client administrators. Changed saved fields are carried forward.',
									'rtcamp-amc-copilot'
								),
								items[ 0 ].report_id
							) }
						</p>
						<div className="amc-modal-actions">
							<Button variant="tertiary" onClick={ closeModal }>
								{ __( 'Cancel', 'rtcamp-amc-copilot' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ async () => {
									try {
										await publishReport( items[ 0 ].id );
										notice.success(
											__(
												'Report published.',
												'rtcamp-amc-copilot'
											)
										);
										reload();
									} catch ( err ) {
										notice.error( err.message );
									}
									closeModal();
								} }
							>
								{ __( 'Publish', 'rtcamp-amc-copilot' ) }
							</Button>
						</div>
					</div>
				),
			},
			{
				id: 'view',
				label: __( 'View', 'rtcamp-amc-copilot' ),
				icon: external,
				isEligible: ( item ) =>
					'publish' === item.status && !! item.view_url,
				callback: ( [ item ] ) => {
					window.location.assign( item.view_url );
				},
			},
		],
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ reload ]
	);

	const savedRows = useMemo(
		() =>
			saved.data
				? Object.entries( saved.data.schema ).flatMap(
						( [ section, keys ] ) =>
							keys.map( ( key ) => ( {
								id: `${ section }.${ key }`,
								section: section.replace( /_/g, ' ' ),
								field: key.replace( /_/g, ' ' ),
								value:
									saved.data.fields[ section ]?.[ key ] ?? '',
							} ) )
					)
				: [],
		[ saved.data ]
	);

	const savedFields = useMemo(
		() => [
			textField( 'section', __( 'Section', 'rtcamp-amc-copilot' ) ),
			textField( 'field', __( 'Field', 'rtcamp-amc-copilot' ) ),
			customField(
				'value',
				__( 'Value', 'rtcamp-amc-copilot' ),
				( item ) => item.value,
				( item ) => (
					<span>
						{ item.value || __( 'Not set', 'rtcamp-amc-copilot' ) }
					</span>
				)
			),
		],
		[]
	);

	return (
		<ScreenState
			title={ __( 'Reports', 'rtcamp-amc-copilot' ) }
			actions={
				<Button
					variant="primary"
					icon={ plus }
					onClick={ () => setGenerating( true ) }
				>
					{ __( 'Generate report', 'rtcamp-amc-copilot' ) }
				</Button>
			}
			isLoading={ isLoading }
			error={ error }
			onRetry={ reload }
			hasData={ !! data }
		>
			{ notice.node }
			{ data && (
				<>
					<DataTable
						data={ data.items }
						fields={ fields }
						actions={ actions }
						exportName="amc-reports"
					/>
					<h3>{ __( 'Saved fields', 'rtcamp-amc-copilot' ) }</h3>
					<p className="description">
						{ __(
							'Stable facts carried forward from the latest published report.',
							'rtcamp-amc-copilot'
						) }{ ' ' }
						<a
							href={ screenUrl( 'settings' ) }
							onClick={ ( event ) => {
								event.preventDefault();
								navigate( 'settings' );
							} }
						>
							{ __(
								'Edit them in Settings.',
								'rtcamp-amc-copilot'
							) }
						</a>
					</p>
					{ saved.error && <ErrorNotice error={ saved.error } /> }
					{ saved.data && (
						<DataTable
							data={ savedRows }
							fields={ savedFields }
							exportName="amc-saved-fields"
						/>
					) }
				</>
			) }
			{ generating && (
				<Modal
					title={ __( 'Generate report', 'rtcamp-amc-copilot' ) }
					onRequestClose={ () => setGenerating( false ) }
				>
					<GenerateForm
						closeModal={ () => setGenerating( false ) }
						onDone={ ( report ) => {
							notice.success(
								sprintf(
									/* translators: %s: report id. */
									__(
										'Draft %s generated. Open it with Edit to add your notes.',
										'rtcamp-amc-copilot'
									),
									report.report_id
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
