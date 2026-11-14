/**
 * WordPress dependencies
 */
import { Notice, TextControl } from '@wordpress/components';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getChanges } from '../api';
import DataTable from '../components/DataTable';
import ScreenState from '../components/ScreenState';
import useResource from '../hooks/useResource';
import { customField, dateField, enumField, textField } from '../utils/fields';

const typeElements = () => [
	{ value: 'plugin', label: __( 'Plugin', 'rtcamp-amc-copilot' ) },
	{ value: 'theme', label: __( 'Theme', 'rtcamp-amc-copilot' ) },
	{ value: 'core', label: __( 'Core', 'rtcamp-amc-copilot' ) },
	{ value: 'user', label: __( 'User', 'rtcamp-amc-copilot' ) },
];

const sourceElements = () => [
	{ value: 'admin', label: __( 'Admin', 'rtcamp-amc-copilot' ) },
	{ value: 'cli', label: __( 'WP-CLI', 'rtcamp-amc-copilot' ) },
	{ value: 'deploy', label: __( 'Deploy', 'rtcamp-amc-copilot' ) },
	{ value: 'auto', label: __( 'Auto-update', 'rtcamp-amc-copilot' ) },
];

const actionElements = () => [
	{ value: 'install', label: __( 'Install', 'rtcamp-amc-copilot' ) },
	{ value: 'update', label: __( 'Update', 'rtcamp-amc-copilot' ) },
	{ value: 'activate', label: __( 'Activate', 'rtcamp-amc-copilot' ) },
	{ value: 'deactivate', label: __( 'Deactivate', 'rtcamp-amc-copilot' ) },
	{ value: 'delete', label: __( 'Delete', 'rtcamp-amc-copilot' ) },
	{ value: 'role', label: __( 'Role change', 'rtcamp-amc-copilot' ) },
	{
		value: 'access_grant',
		label: __( 'Access granted', 'rtcamp-amc-copilot' ),
	},
	{
		value: 'access_revoke',
		label: __( 'Access revoked', 'rtcamp-amc-copilot' ),
	},
];

/**
 * Start of a day (`since`) or the start of the next day (`until`, which the
 * API treats as exclusive) as an ISO 8601 UTC string.
 *
 * @param {string}  date       YYYY-MM-DD, or ''.
 * @param {boolean} [endOfDay] Use the start of the following day.
 * @return {string|undefined} ISO string.
 */
const bound = ( date, endOfDay = false ) => {
	if ( ! date ) {
		return undefined;
	}
	const time =
		Date.parse( `${ date }T00:00:00Z` ) + ( endOfDay ? 86400000 : 0 );
	return new Date( time ).toISOString().replace( '.000Z', 'Z' );
};

/**
 * Changes screen (`GET /changes`): timeline of code and user changes with
 * their source. Filter by date here and by type, action and source in the
 * table.
 *
 * @return {Element} Screen.
 */
export default function Changes() {
	const [ range, setRange ] = useState( { from: '', to: '' } );
	const fetcher = useCallback(
		() =>
			getChanges( {
				since: bound( range.from ),
				until: bound( range.to, true ),
			} ),
		[ range ]
	);
	const { data, error, isLoading, reload } = useResource( fetcher );

	const fields = useMemo(
		() => [
			dateField( 'created_at', __( 'When', 'rtcamp-amc-copilot' ) ),
			enumField(
				'type',
				__( 'Type', 'rtcamp-amc-copilot' ),
				typeElements(),
				{
					filterBy: { operators: [ 'isAny' ], isPrimary: true },
				}
			),
			enumField(
				'action',
				__( 'Action', 'rtcamp-amc-copilot' ),
				actionElements()
			),
			textField( 'item_name', __( 'Item', 'rtcamp-amc-copilot' ) ),
			customField(
				'versions',
				__( 'Version change', 'rtcamp-amc-copilot' ),
				( item ) =>
					[ item.old_version, item.new_version ]
						.filter( Boolean )
						.join( ' → ' ),
				( item ) => (
					<span>
						{ [ item.old_version, item.new_version ]
							.filter( Boolean )
							.join( ' → ' ) || '—' }
					</span>
				)
			),
			enumField(
				'source',
				__( 'Source', 'rtcamp-amc-copilot' ),
				sourceElements(),
				{
					filterBy: { operators: [ 'isAny' ], isPrimary: true },
				}
			),
			customField(
				'user_display_name',
				__( 'User', 'rtcamp-amc-copilot' ),
				( item ) => item.user_display_name ?? '',
				( item ) => <span>{ item.user_display_name || '—' }</span>
			),
		],
		[]
	);

	return (
		<ScreenState
			screen="changes"
			isLoading={ isLoading }
			error={ error }
			onRetry={ reload }
			hasData={ !! data }
		>
			<div
				className="amc-filters"
				role="group"
				aria-label={ __( 'Date range', 'rtcamp-amc-copilot' ) }
			>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="date"
					label={ __( 'From', 'rtcamp-amc-copilot' ) }
					value={ range.from }
					max={ range.to || undefined }
					onChange={ ( from ) =>
						setRange( ( r ) => ( { ...r, from } ) )
					}
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="date"
					label={ __( 'To', 'rtcamp-amc-copilot' ) }
					value={ range.to }
					min={ range.from || undefined }
					onChange={ ( to ) => setRange( ( r ) => ( { ...r, to } ) ) }
				/>
			</div>
			{ data && data.total > data.items.length && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: 1: rows shown, 2: total rows. */
						__(
							'Showing the newest %1$d of %2$d changes. Narrow the dates to see older ones.',
							'rtcamp-amc-copilot'
						),
						data.items.length,
						data.total
					) }
				</Notice>
			) }
			{ data && (
				<DataTable
					data={ data.items }
					fields={ fields }
					exportName="amc-changes"
					variant="full"
					label={ __( 'Change log', 'rtcamp-amc-copilot' ) }
					isLoading={ isLoading }
				/>
			) }
		</ScreenState>
	);
}
