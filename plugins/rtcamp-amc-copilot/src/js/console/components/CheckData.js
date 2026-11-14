/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { external } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { formatBytes, formatDateTime, formatMs } from '../utils/format';
import DataTable from './DataTable';
import StatusBadge from './StatusBadge';

const STATUSES = [ 'pass', 'warn', 'fail', 'info' ];
const ISO =
	/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/;
const MAX_COLUMNS = 9;
const MAX_DEPTH = 3;

const knownLabels = () => ( {
	name: __( 'Name', 'rtcamp-amc-copilot' ),
	slug: __( 'Slug', 'rtcamp-amc-copilot' ),
	version: __( 'Version', 'rtcamp-amc-copilot' ),
	current_version: __( 'Current version', 'rtcamp-amc-copilot' ),
	new_version: __( 'Available version', 'rtcamp-amc-copilot' ),
	latest_version: __( 'Latest version', 'rtcamp-amc-copilot' ),
	tested_up_to: __( 'Tested up to', 'rtcamp-amc-copilot' ),
	requires_php: __( 'Requires PHP', 'rtcamp-amc-copilot' ),
	changelog_excerpt: __( 'Changelog', 'rtcamp-amc-copilot' ),
	changelog_url: __( 'Changelog link', 'rtcamp-amc-copilot' ),
	update_source: __( 'Update source', 'rtcamp-amc-copilot' ),
	status: __( 'Status', 'rtcamp-amc-copilot' ),
	note: __( 'Note', 'rtcamp-amc-copilot' ),
	detail: __( 'Detail', 'rtcamp-amc-copilot' ),
	label: __( 'Check', 'rtcamp-amc-copilot' ),
	active: __( 'Active', 'rtcamp-amc-copilot' ),
	closed: __( 'Closed on wordpress.org', 'rtcamp-amc-copilot' ),
	last_updated: __( 'Last updated', 'rtcamp-amc-copilot' ),
	url: __( 'URL', 'rtcamp-amc-copilot' ),
	login: __( 'Username', 'rtcamp-amc-copilot' ),
	email: __( 'Email', 'rtcamp-amc-copilot' ),
	php: __( 'PHP', 'rtcamp-amc-copilot' ),
	php_version: __( 'PHP version', 'rtcamp-amc-copilot' ),
	mysql_version: __( 'MySQL version', 'rtcamp-amc-copilot' ),
	wp_org: __( 'On wordpress.org', 'rtcamp-amc-copilot' ),
	file: __( 'File', 'rtcamp-amc-copilot' ),
	parent: __( 'Parent theme', 'rtcamp-amc-copilot' ),
	issuer: __( 'Issuer', 'rtcamp-amc-copilot' ),
	expires_at: __( 'Expires', 'rtcamp-amc-copilot' ),
	valid_from: __( 'Valid from', 'rtcamp-amc-copilot' ),
	days_left: __( 'Days left', 'rtcamp-amc-copilot' ),
	https: __( 'HTTPS', 'rtcamp-amc-copilot' ),
} );

/**
 * Human label for a data key: a translated label for well-known keys, a
 * sentence-cased version of the key otherwise (`last_checked` becomes
 * "Last checked").
 *
 * @param {string} key Data key.
 * @return {string} Label.
 */
export const labelFor = ( key ) => {
	const known = knownLabels()[ key ];
	if ( known ) {
		return known;
	}
	const text = String( key ).replace( /[_-]+/g, ' ' ).trim();
	return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
};

const isPlainObject = ( value ) =>
	null !== value && 'object' === typeof value && ! Array.isArray( value );

const isScalar = ( value ) => null === value || 'object' !== typeof value;

const isRows = ( value ) =>
	Array.isArray( value ) && value.length > 0 && value.every( isPlainObject );

/**
 * Plain value for sorting, searching and CSV: scalars stay, anything nested
 * becomes JSON.
 *
 * @param {unknown} value Cell value.
 * @return {string|number|boolean} Plain value.
 */
const plain = ( value ) => {
	if ( null === value || undefined === value ) {
		return '';
	}
	if ( Array.isArray( value ) && value.every( isScalar ) ) {
		return value.join( ', ' );
	}
	return 'object' === typeof value ? JSON.stringify( value ) : value;
};

/**
 * One value, formatted by what its key and shape suggest: status badge,
 * date, bytes, milliseconds, yes/no, link.
 *
 * @param {Object}  props
 * @param {string}  props.name  Data key.
 * @param {unknown} props.value Value.
 * @return {Element} Rendered value.
 */
export function DataValue( { name, value } ) {
	if ( null === value || undefined === value || '' === value ) {
		return <span>{ '—' }</span>;
	}
	if ( Array.isArray( value ) && ! value.length ) {
		return <span>{ __( 'None', 'rtcamp-amc-copilot' ) }</span>;
	}
	if ( 'boolean' === typeof value ) {
		return (
			<span>
				{ value
					? __( 'Yes', 'rtcamp-amc-copilot' )
					: __( 'No', 'rtcamp-amc-copilot' ) }
			</span>
		);
	}
	if (
		'string' === typeof value &&
		STATUSES.includes( value ) &&
		/status$/.test( name )
	) {
		return <StatusBadge status={ value } />;
	}
	if ( 'string' === typeof value && ISO.test( value ) ) {
		return <span>{ formatDateTime( value ) }</span>;
	}
	if ( 'string' === typeof value && /^https?:\/\//.test( value ) ) {
		return (
			<a href={ value } target="_blank" rel="noopener noreferrer">
				{ /_url$|^url$/.test( name ) && 'url' !== name
					? labelFor( name )
					: value }
				<span className="screen-reader-text">
					{ ' ' }
					{ __( '(opens in a new tab)', 'rtcamp-amc-copilot' ) }
				</span>
			</a>
		);
	}
	if ( 'number' === typeof value ) {
		if ( /_bytes$/.test( name ) ) {
			return <span>{ formatBytes( value ) }</span>;
		}
		if ( /_ms$/.test( name ) ) {
			return <span>{ formatMs( value ) }</span>;
		}
		if ( /_pct$/.test( name ) ) {
			return <span>{ `${ value }%` }</span>;
		}
	}
	return <span>{ String( plain( value ) ) }</span>;
}

/**
 * Table for an array of objects: one column per key (the first
 * MAX_COLUMNS), filterable, sortable and exportable like every list. Rows
 * with a `changelog_url` get an "Open changelog" action.
 *
 * @param {Object}   props
 * @param {string}   props.name    Data key the rows came from (used for the file name).
 * @param {Object[]} props.rows    Rows.
 * @param {string}   props.prefix  File name prefix for the CSV export.
 * @param {string}   [props.title] Name of the check, to tell tables with the same key in different checks apart.
 * @return {Element} Table.
 */
export function RowsTable( { name, rows, prefix, title } ) {
	const keys = [
		...new Set( rows.flatMap( ( row ) => Object.keys( row ) ) ),
	].slice( 0, MAX_COLUMNS );
	const data = rows.map( ( row, index ) => ( {
		...row,
		__row: String( index ),
	} ) );

	const fields = keys.map( ( key ) => {
		const allNumbers = rows.every(
			( row ) =>
				'number' === typeof row[ key ] ||
				undefined === row[ key ] ||
				null === row[ key ]
		);
		return {
			id: key,
			label: labelFor( key ),
			type: allNumbers ? 'number' : 'text',
			enableGlobalSearch: true,
			getValue: ( { item } ) => plain( item[ key ] ),
			render: ( { item } ) => (
				<DataValue name={ key } value={ item[ key ] } />
			),
		};
	} );

	const actions = rows.some( ( row ) => row.changelog_url )
		? [
				{
					id: 'open-changelog',
					label: __( 'Open changelog', 'rtcamp-amc-copilot' ),
					icon: external,
					isEligible: ( item ) => !! item.changelog_url,
					callback: ( [ item ] ) => {
						window.open(
							item.changelog_url,
							'_blank',
							'noopener,noreferrer'
						);
					},
				},
			]
		: undefined;

	return (
		<DataTable
			data={ data }
			fields={ fields }
			actions={ actions }
			getItemId={ ( item ) => item.__row }
			exportName={ `${ prefix }-${ name }` }
			label={
				title
					? sprintf(
							/* translators: 1: check name, 2: table name. */
							__( '%1$s: %2$s', 'rtcamp-amc-copilot' ),
							title,
							labelFor( name )
						)
					: labelFor( name )
			}
			initialView={ { perPage: 10 } }
		/>
	);
}

/**
 * Render a check's `Result::$data` without knowing its shape: scalars as a
 * two-column core table (label, value; as on Site Health's Info tab), arrays
 * of objects as tables, nested objects as sections, anything deeper as JSON.
 *
 * @param {Object} props
 * @param {Object} props.data    The data object.
 * @param {string} props.prefix  File name prefix for CSV exports.
 * @param {string} [props.title] Name of the check the data belongs to.
 * @param {number} [props.depth] Nesting level.
 * @return {Element|null} Rendered data.
 */
export default function CheckData( { data, prefix, title, depth = 0 } ) {
	if ( ! isPlainObject( data ) || ! Object.keys( data ).length ) {
		return null;
	}

	if ( depth >= MAX_DEPTH ) {
		return (
			<pre
				className="amc-json"
				tabIndex={ 0 }
				role="region"
				aria-label={ __( 'Result data (JSON)', 'rtcamp-amc-copilot' ) }
			>
				{ JSON.stringify( data, null, 2 ) }
			</pre>
		);
	}

	const entries = Object.entries( data );
	const simple = entries.filter(
		( [ , value ] ) =>
			isScalar( value ) ||
			( Array.isArray( value ) &&
				! isRows( value ) &&
				value.every( isScalar ) )
	);
	const complex = entries.filter( ( entry ) => ! simple.includes( entry ) );

	return (
		<div className="amc-check-data">
			{ simple.length > 0 && (
				<table className="widefat striped amc-kv">
					<caption className="screen-reader-text">
						{ title
							? sprintf(
									/* translators: %s: check name. */
									__( '%s: details', 'rtcamp-amc-copilot' ),
									title
								)
							: __( 'Details', 'rtcamp-amc-copilot' ) }
					</caption>
					<tbody>
						{ simple.map( ( [ key, value ] ) => (
							<tr key={ key }>
								<th scope="row">{ labelFor( key ) }</th>
								<td>
									<DataValue name={ key } value={ value } />
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ complex.map( ( [ key, value ] ) => (
				<div key={ key } className="amc-check-data-section">
					<h4>{ labelFor( key ) }</h4>
					{ isRows( value ) ? (
						<RowsTable
							name={ key }
							rows={ value }
							prefix={ prefix }
							title={ title }
						/>
					) : (
						<CheckData
							data={
								Array.isArray( value )
									? { items: value }
									: value
							}
							prefix={ `${ prefix }-${ key }` }
							title={ title }
							depth={ depth + 1 }
						/>
					) }
				</div>
			) ) }
		</div>
	);
}
