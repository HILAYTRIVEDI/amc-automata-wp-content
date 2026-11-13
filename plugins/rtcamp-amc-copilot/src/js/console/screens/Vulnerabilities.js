/**
 * WordPress dependencies
 */
import { Button, Notice, TextareaControl } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { check } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { acceptRisk, getVulnerabilities } from '../api';
import DataTable from '../components/DataTable';
import RunChecksButton from '../components/RunChecksButton';
import ScreenState from '../components/ScreenState';
import StatusBadge, { statusFromSeverity } from '../components/StatusBadge';
import { SCREEN_CHECKS } from '../constants';
import useNotice from '../hooks/useNotice';
import useResource from '../hooks/useResource';
import {
	customField,
	dateField,
	enumField,
	numberField,
	runField,
	textField,
} from '../utils/fields';

const severityElements = () => [
	{ value: 'critical', label: __( 'Critical', 'rtcamp-amc-copilot' ) },
	{ value: 'high', label: __( 'High', 'rtcamp-amc-copilot' ) },
	{ value: 'medium', label: __( 'Medium', 'rtcamp-amc-copilot' ) },
	{ value: 'low', label: __( 'Low', 'rtcamp-amc-copilot' ) },
];

const stateElements = () => [
	{ value: 'open', label: __( 'Open', 'rtcamp-amc-copilot' ) },
	{ value: 'accepted', label: __( 'Accepted risk', 'rtcamp-amc-copilot' ) },
	{ value: 'muted', label: __( 'Muted', 'rtcamp-amc-copilot' ) },
	{ value: 'resolved', label: __( 'Resolved', 'rtcamp-amc-copilot' ) },
];

/**
 * Flatten a finding for the table: the match details live in `data`.
 *
 * @param {Object} finding Finding.
 * @return {Object} Row.
 */
const toRow = ( finding ) => ( {
	id: finding.id,
	reference: finding.data.reference ?? '',
	title: finding.title,
	component: `${ finding.data.component_slug ?? '' }`,
	installed_version: finding.data.installed_version ?? '',
	severity: finding.severity,
	cvss: finding.data.cvss ?? 0,
	affected_range: finding.data.affected_range ?? '',
	fixed_in: finding.data.fixed_in ?? '',
	first_seen: finding.first_seen,
	status: finding.status,
	note: finding.note ?? '',
	run_id: finding.run_id,
} );

/**
 * Modal form collecting the note required to accept a risk.
 *
 * @param {Object}                          props
 * @param {Object[]}                        props.items      Selected vulnerabilities.
 * @param {(...args: unknown[]) => unknown} props.closeModal Close handler.
 * @param {(...args: unknown[]) => unknown} props.onSubmit   Called with the item and note.
 * @return {Element} Form.
 */
function AcceptRiskForm( { items, closeModal, onSubmit } ) {
	const [ note, setNote ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		try {
			await onSubmit( items[ 0 ], note.trim() );
			closeModal();
		} finally {
			setBusy( false );
		}
	};
	return (
		<form onSubmit={ submit }>
			<p>
				{ sprintf(
					/* translators: 1: CVE reference, 2: component slug. */
					__(
						'Accept the risk of %1$s in %2$s. The finding stays visible but stops alerting.',
						'rtcamp-amc-copilot'
					),
					items[ 0 ].reference,
					items[ 0 ].component
				) }
			</p>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Note (required)', 'rtcamp-amc-copilot' ) }
				help={ __(
					'Say why this risk is acceptable and when to revisit it.',
					'rtcamp-amc-copilot'
				) }
				value={ note }
				onChange={ setNote }
				required
				rows={ 4 }
			/>
			<div className="amc-modal-actions">
				<Button variant="tertiary" onClick={ closeModal }>
					{ __( 'Cancel', 'rtcamp-amc-copilot' ) }
				</Button>
				<Button
					variant="primary"
					type="submit"
					disabled={ ! note.trim() || busy }
					accessibleWhenDisabled
					isBusy={ busy }
				>
					{ __( 'Accept risk', 'rtcamp-amc-copilot' ) }
				</Button>
			</div>
		</form>
	);
}

/**
 * Vulnerabilities screen (`GET /vulnerabilities`): open and resolved matches
 * from the CVE provider. "Mark as accepted risk" posts the finding status
 * with a note.
 *
 * @return {Element} Screen.
 */
export default function Vulnerabilities() {
	const { data, error, isLoading, reload } =
		useResource( getVulnerabilities );
	const notice = useNotice();

	const rows = useMemo(
		() => ( data?.findings ?? [] ).map( toRow ),
		[ data ]
	);

	const fields = useMemo(
		() => [
			textField( 'reference', __( 'Reference', 'rtcamp-amc-copilot' ) ),
			textField( 'title', __( 'Title', 'rtcamp-amc-copilot' ) ),
			textField( 'component', __( 'Component', 'rtcamp-amc-copilot' ) ),
			textField(
				'installed_version',
				__( 'Installed', 'rtcamp-amc-copilot' )
			),
			enumField(
				'severity',
				__( 'Severity', 'rtcamp-amc-copilot' ),
				severityElements(),
				{
					filterBy: { operators: [ 'isAny' ], isPrimary: true },
					render: ( { item } ) => (
						<StatusBadge
							status={ statusFromSeverity( item.severity ) }
							label={
								severityElements().find(
									( e ) => e.value === item.severity
								)?.label
							}
						/>
					),
				}
			),
			numberField( 'cvss', __( 'CVSS', 'rtcamp-amc-copilot' ), {
				type: 'number',
			} ),
			textField(
				'affected_range',
				__( 'Affected', 'rtcamp-amc-copilot' )
			),
			customField(
				'fixed_in',
				__( 'Fixed in', 'rtcamp-amc-copilot' ),
				( item ) => item.fixed_in ?? '',
				( item ) => (
					<span>
						{ item.fixed_in ||
							__( 'No fix yet', 'rtcamp-amc-copilot' ) }
					</span>
				)
			),
			dateField( 'first_seen', __( 'First seen', 'rtcamp-amc-copilot' ) ),
			enumField(
				'status',
				__( 'State', 'rtcamp-amc-copilot' ),
				stateElements(),
				{
					filterBy: { operators: [ 'isAny' ], isPrimary: true },
				}
			),
			textField(
				'note',
				__( 'Accepted-risk note', 'rtcamp-amc-copilot' )
			),
			runField(),
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id: 'accept-risk',
				label: __( 'Mark as accepted risk', 'rtcamp-amc-copilot' ),
				icon: check,
				isPrimary: true,
				isEligible: ( item ) => 'open' === item.status,
				modalHeader: __( 'Accept risk', 'rtcamp-amc-copilot' ),
				RenderModal: ( { items, closeModal } ) => (
					<AcceptRiskForm
						items={ items }
						closeModal={ closeModal }
						onSubmit={ async ( item, note ) => {
							try {
								await acceptRisk( item.id, note );
								notice.success(
									__( 'Risk accepted.', 'rtcamp-amc-copilot' )
								);
								reload();
							} catch ( err ) {
								notice.error( err.message );
							}
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
			title={ __( 'Vulnerabilities', 'rtcamp-amc-copilot' ) }
			actions={
				<RunChecksButton
					checkIds={ SCREEN_CHECKS.vulnerabilities }
					label={ __( 'Run check', 'rtcamp-amc-copilot' ) }
					onComplete={ reload }
				/>
			}
			isLoading={ isLoading }
			error={ error }
			onRetry={ reload }
			hasData={ !! data }
		>
			{ notice.node }
			{ data && (
				<>
					{ ( ! data.provider.ready || data.provider.last_error ) && (
						<Notice status="warning" isDismissible={ false }>
							{ data.provider.last_error ||
								__(
									'The CVE provider is not ready, so no vulnerability data is being matched.',
									'rtcamp-amc-copilot'
								) }
						</Notice>
					) }
					<DataTable
						data={ rows }
						fields={ fields }
						actions={ actions }
						exportName="amc-vulnerabilities"
					/>
				</>
			) }
		</ScreenState>
	);
}
