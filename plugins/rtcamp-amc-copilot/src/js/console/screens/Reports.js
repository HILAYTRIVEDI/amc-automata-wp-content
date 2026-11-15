/**
 * WordPress dependencies
 */
import {
	Button,
	Modal,
	RadioControl,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { external, pencil, plus, published } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import {
	generateReport,
	getPublishRecipients,
	getReports,
	getSavedFields,
	publishReport,
} from '../api';
import DataTable from '../components/DataTable';
import Postbox from '../components/Postbox';
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
				aria-invalid={
					!! ( range.start && range.end && range.start > range.end )
				}
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
					{ busy
						? __( 'Generating…', 'rtcamp-amc-copilot' )
						: __( 'Generate draft', 'rtcamp-amc-copilot' ) }
				</Button>
			</div>
		</form>
	);
}

/**
 * Modal form for publishing a report with option to send to client and list of recipients.
 *
 * @param {Object}                          props             Props.
 * @param {Object}                          props.item        Report item.
 * @param {(...args: unknown[]) => unknown} props.closeModal  Close modal callback.
 * @param {(...args: unknown[]) => unknown} props.onPublished On published callback.
 * @return {Element} Publish form.
 */
function PublishReportModal( { item, closeModal, onPublished } ) {
	const [ sendEmail, setSendEmail ] = useState( true );
	const [ busy, setBusy ] = useState( false );
	const [ recipients, setRecipients ] = useState( [] );
	const [ loadingRecipients, setLoadingRecipients ] = useState( true );
	const notice = useNotice();

	useEffect( () => {
		let active = true;
		getPublishRecipients()
			.then( ( res ) => {
				if ( active && res && Array.isArray( res.recipients ) ) {
					setRecipients( res.recipients );
				}
			} )
			.catch( () => {
				// Keep empty on error.
			} )
			.finally( () => {
				if ( active ) {
					setLoadingRecipients( false );
				}
			} );
		return () => {
			active = false;
		};
	}, [] );

	const handleSubmit = async ( e ) => {
		e.preventDefault();
		setBusy( true );
		try {
			await publishReport( item.id, { sendEmail } );
			notice.success(
				sendEmail
					? __(
							'Report published and sent to client.',
							'rtcamp-amc-copilot'
						)
					: __(
							'Report published (email not sent).',
							'rtcamp-amc-copilot'
						)
			);
			onPublished();
			closeModal();
		} catch ( err ) {
			notice.error( err.message );
			setBusy( false );
		}
	};

	let recipientsContent = null;
	if ( loadingRecipients ) {
		recipientsContent = (
			<div
				style={ {
					display: 'flex',
					alignItems: 'center',
					gap: '8px',
					color: '#64748b',
				} }
			>
				<Spinner />{ ' ' }
				{ __( 'Loading recipients…', 'rtcamp-amc-copilot' ) }
			</div>
		);
	} else if ( recipients.length > 0 ) {
		recipientsContent = (
			<ul
				style={ {
					margin: 0,
					paddingLeft: '16px',
					color: '#0f172a',
					lineHeight: 1.6,
				} }
			>
				{ recipients.map( ( email ) => (
					<li key={ email }>{ email }</li>
				) ) }
			</ul>
		);
	} else {
		recipientsContent = (
			<span style={ { color: '#94a3b8' } }>
				{ __(
					'No client recipients registered in AMC Settings.',
					'rtcamp-amc-copilot'
				) }
			</span>
		);
	}

	return (
		<form onSubmit={ handleSubmit }>
			<p>
				{ sprintf(
					/* translators: %s: report id. */
					__(
						'Publishing %s makes it visible to client administrators. Changed saved fields are carried forward.',
						'rtcamp-amc-copilot'
					),
					item.report_id
				) }
			</p>

			<fieldset
				style={ { margin: '16px 0', border: 'none', padding: 0 } }
			>
				<legend
					style={ {
						fontWeight: 600,
						marginBottom: '8px',
						fontSize: '13px',
					} }
				>
					{ __( 'Send report to client?', 'rtcamp-amc-copilot' ) }
				</legend>
				<RadioControl
					selected={ sendEmail ? 'yes' : 'no' }
					options={ [
						{
							label: __(
								'Yes, send report to registered client emails',
								'rtcamp-amc-copilot'
							),
							value: 'yes',
						},
						{
							label: __(
								'No, publish only (do not send email)',
								'rtcamp-amc-copilot'
							),
							value: 'no',
						},
					] }
					onChange={ ( val ) => setSendEmail( val === 'yes' ) }
				/>
			</fieldset>

			<div
				style={ {
					background: '#f8fafc',
					border: '1px solid #e2e8f0',
					borderRadius: '6px',
					padding: '12px',
					marginBottom: '16px',
					fontSize: '12px',
				} }
			>
				<strong
					style={ {
						display: 'block',
						marginBottom: '6px',
						color: '#334155',
					} }
				>
					{ __(
						'Registered recipient emails:',
						'rtcamp-amc-copilot'
					) }
				</strong>
				{ recipientsContent }
			</div>

			<div className="amc-modal-actions">
				<Button
					variant="tertiary"
					onClick={ closeModal }
					disabled={ busy }
				>
					{ __( 'Cancel', 'rtcamp-amc-copilot' ) }
				</Button>
				<Button
					variant="primary"
					type="submit"
					isBusy={ busy }
					disabled={ busy }
					accessibleWhenDisabled
				>
					{ sendEmail
						? __( 'Publish & send email', 'rtcamp-amc-copilot' )
						: __(
								'Publish without sending',
								'rtcamp-amc-copilot'
							) }
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
				modalSize: 'medium',
				RenderModal: ( { items, closeModal } ) => (
					<PublishReportModal
						item={ items[ 0 ] }
						closeModal={ closeModal }
						onPublished={ reload }
					/>
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
			screen="reports"
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
						variant="full"
						label={ __( 'Report list', 'rtcamp-amc-copilot' ) }
					/>
					<Postbox
						title={ __( 'Saved fields', 'rtcamp-amc-copilot' ) }
					>
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
								label={ __(
									'Saved fields',
									'rtcamp-amc-copilot'
								) }
								variant="compact"
							/>
						) }
					</Postbox>
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
