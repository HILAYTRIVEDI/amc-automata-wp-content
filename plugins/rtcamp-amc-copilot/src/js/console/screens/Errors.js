/**
 * WordPress dependencies
 */
import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { check, seen, unseen } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getEvents, muteSignature, resolveEvent } from '../api';
import DataTable from '../components/DataTable';
import ScreenState from '../components/ScreenState';
import Sparkline from '../components/Sparkline';
import StatusBadge from '../components/StatusBadge';
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

const typeElements = () => [
	{ value: 'fatal', label: __( 'Fatal error', 'rtcamp-amc-copilot' ) },
	{ value: 'warning', label: __( 'Warning', 'rtcamp-amc-copilot' ) },
	{ value: 'notice', label: __( 'Notice', 'rtcamp-amc-copilot' ) },
	{ value: 'deprecation', label: __( 'Deprecation', 'rtcamp-amc-copilot' ) },
	{
		value: 'doing_it_wrong',
		label: __( 'Doing it wrong', 'rtcamp-amc-copilot' ),
	},
];

const typeStatus = {
	fatal: 'fail',
	warning: 'warn',
	notice: 'info',
	deprecation: 'info',
	doing_it_wrong: 'info',
};

// `finding_status` is null when the signature has no open finding (resolved,
// or never raised one).
const stateOf = ( item ) => item.finding_status ?? 'none';

const stateElements = () => [
	{ value: 'open', label: __( 'Open', 'rtcamp-amc-copilot' ) },
	{ value: 'muted', label: __( 'Muted', 'rtcamp-amc-copilot' ) },
	{ value: 'accepted', label: __( 'Accepted risk', 'rtcamp-amc-copilot' ) },
	{ value: 'none', label: __( 'No open finding', 'rtcamp-amc-copilot' ) },
];

/**
 * Errors screen (`GET /errors`): fatal errors, warnings and deprecations
 * grouped by signature. "Mark resolved" and "Mute signature" change the
 * signature's finding (`POST /findings/{id}/status`).
 *
 * @return {Element} Screen.
 */
export default function Errors() {
	const { data, error, isLoading, reload } = useResource( getEvents );
	const notice = useNotice();

	const run = async ( promise, message ) => {
		try {
			await promise;
			notice.success( message );
			reload();
		} catch ( err ) {
			notice.error( err.message );
		}
	};

	const fields = useMemo(
		() => [
			enumField(
				'type',
				__( 'Type', 'rtcamp-amc-copilot' ),
				typeElements(),
				{
					filterBy: { operators: [ 'isAny' ], isPrimary: true },
					render: ( { item } ) => (
						<StatusBadge
							status={ typeStatus[ item.type ] }
							label={
								typeElements().find(
									( e ) => e.value === item.type
								)?.label
							}
						/>
					),
				}
			),
			textField( 'message', __( 'Message', 'rtcamp-amc-copilot' ) ),
			textField( 'source', __( 'Source', 'rtcamp-amc-copilot' ) ),
			numberField( 'count', __( 'Count', 'rtcamp-amc-copilot' ) ),
			dateField( 'first_seen', __( 'First seen', 'rtcamp-amc-copilot' ) ),
			dateField( 'last_seen', __( 'Last seen', 'rtcamp-amc-copilot' ) ),
			customField(
				'sparkline',
				__( '30 days', 'rtcamp-amc-copilot' ),
				( item ) => item.sparkline,
				( item ) => (
					<Sparkline
						values={ item.sparkline }
						label={ sprintf(
							/* translators: %s: error message. */
							__(
								'Occurrences per day over 30 days for %s',
								'rtcamp-amc-copilot'
							),
							item.message
						) }
					/>
				),
				{ enableSorting: false }
			),
			customField(
				'state',
				__( 'State', 'rtcamp-amc-copilot' ),
				stateOf,
				( item ) => (
					<span>
						{
							stateElements().find(
								( e ) => e.value === stateOf( item )
							)?.label
						}
					</span>
				),
				{
					elements: stateElements(),
					filterBy: { operators: [ 'isAny' ], isPrimary: true },
				}
			),
			runField(),
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id: 'resolve',
				label: __( 'Mark resolved', 'rtcamp-amc-copilot' ),
				icon: check,
				isPrimary: true,
				isEligible: ( item ) => null !== item.finding_id,
				callback: ( [ item ] ) =>
					run(
						resolveEvent( item.finding_id ),
						__(
							'Signature marked as resolved.',
							'rtcamp-amc-copilot'
						)
					),
			},
			{
				id: 'mute',
				label: __( 'Mute signature', 'rtcamp-amc-copilot' ),
				icon: unseen,
				isEligible: ( item ) =>
					null !== item.finding_id && 'muted' !== item.finding_status,
				callback: ( [ item ] ) =>
					run(
						muteSignature( item.finding_id, true ),
						__(
							'Signature muted. It will no longer alert.',
							'rtcamp-amc-copilot'
						)
					),
			},
			{
				id: 'unmute',
				label: __( 'Unmute signature', 'rtcamp-amc-copilot' ),
				icon: seen,
				isEligible: ( item ) => 'muted' === item.finding_status,
				callback: ( [ item ] ) =>
					run(
						muteSignature( item.finding_id, false ),
						__( 'Signature unmuted.', 'rtcamp-amc-copilot' )
					),
			},
		],
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ reload ]
	);

	return (
		<ScreenState
			title={ __( 'Errors', 'rtcamp-amc-copilot' ) }
			isLoading={ isLoading }
			error={ error }
			onRetry={ reload }
			hasData={ !! data }
		>
			{ notice.node }
			{ data && (
				<>
					<p className="description">
						{ sprintf(
							/* translators: %d: number of signatures. */
							__(
								'%d signatures captured. Errors are counted per signature per hour, not per occurrence.',
								'rtcamp-amc-copilot'
							),
							data.items.length
						) }
					</p>
					<DataTable
						data={ data.items }
						fields={ fields }
						actions={ actions }
						getItemId={ ( item ) => item.signature }
						exportName="amc-errors"
						label={ __(
							'Errors by signature',
							'rtcamp-amc-copilot'
						) }
					/>
				</>
			) }
		</ScreenState>
	);
}
