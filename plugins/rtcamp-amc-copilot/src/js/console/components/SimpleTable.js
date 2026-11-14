/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { exportFieldsCsv } from '../utils/csv';
import { countItems } from '../utils/format';

/**
 * Text announced when a CSV export starts.
 *
 * @param {number} count Rows exported.
 * @return {string} Message.
 */
export const exportMessage = ( count ) =>
	sprintf(
		/* translators: %d: number of rows. */
		_n(
			'CSV export started: %d row.',
			'CSV export started: %d rows.',
			count,
			'rtcamp-amc-copilot'
		),
		count
	);

/**
 * Cell content for a field: its `render`, or its plain value.
 *
 * @param {Object} field Field definition (DataViews shape).
 * @param {Object} item  Row.
 * @return {Element|string} Cell content.
 */
const cell = ( field, item ) => {
	if ( field.render ) {
		return field.render( { item } );
	}
	const value = field.getValue
		? field.getValue( { item } )
		: item[ field.id ];
	return null === value || undefined === value || '' === value
		? '—'
		: String( value );
};

/**
 * Short list as a core list table (`widefat striped`), for the many lists
 * that only ever hold a handful of rows. Takes the same field definitions as
 * `DataTable`, so a list can move between the two. A `tablenav` under the
 * table shows the item count and the CSV export, as on core list screens.
 *
 * @param {Object}                          props
 * @param {Object[]}                        props.data         Rows.
 * @param {Object[]}                        props.fields       Field definitions (`id`, `label`, `getValue`, `render`).
 * @param {(...args: unknown[]) => unknown} [props.getItemId]  Row key getter (defaults to `item.id`).
 * @param {string}                          props.label        What the table lists; its caption and export name.
 * @param {string}                          [props.exportName] CSV file name without extension; no export when empty.
 * @param {string[]}                        [props.columns]    Field ids shown as columns (default: all). The CSV has every field.
 * @param {number}                          [props.limit]      Show only this many rows until "Show all" is pressed.
 * @param {string}                          [props.emptyText]  Text for an empty table.
 * @return {Element} Table.
 */
export default function SimpleTable( {
	data,
	fields,
	getItemId,
	label,
	exportName,
	columns,
	limit,
	emptyText,
} ) {
	const [ expanded, setExpanded ] = useState( false );
	const shown = columns
		? fields.filter( ( field ) => columns.includes( field.id ) )
		: fields;
	const rows =
		limit && ! expanded && data.length > limit
			? data.slice( 0, limit )
			: data;
	const keyOf = getItemId ?? ( ( item ) => String( item.id ) );

	const exportCsv = () => {
		exportFieldsCsv( exportName, fields, data );
		speak( exportMessage( data.length ), 'polite' );
	};

	return (
		<div className="amc-simple-table">
			<table className="widefat striped">
				<caption className="screen-reader-text">{ label }</caption>
				<thead>
					<tr>
						{ shown.map( ( field ) => (
							<th key={ field.id } scope="col">
								{ field.label }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ rows.length ? (
						rows.map( ( item, index ) => (
							<tr key={ keyOf( item ) ?? index }>
								{ shown.map( ( field ) => (
									<td key={ field.id }>
										{ cell( field, item ) }
									</td>
								) ) }
							</tr>
						) )
					) : (
						<tr className="no-items">
							<td colSpan={ shown.length }>
								{ emptyText ??
									__(
										'Nothing to show.',
										'rtcamp-amc-copilot'
									) }
							</td>
						</tr>
					) }
				</tbody>
			</table>
			{ ( exportName || rows.length < data.length || expanded ) && (
				<div className="tablenav bottom">
					<div className="alignleft actions">
						{ exportName && data.length > 0 && (
							<button
								type="button"
								className="button"
								onClick={ exportCsv }
								aria-label={ sprintf(
									/* translators: %s: what the table lists. */
									__(
										'Export CSV: %s',
										'rtcamp-amc-copilot'
									),
									label
								) }
							>
								{ __( 'Export CSV', 'rtcamp-amc-copilot' ) }
							</button>
						) }
						{ limit && data.length > limit && (
							<button
								type="button"
								className="button-link"
								aria-expanded={ expanded }
								onClick={ () => setExpanded( ! expanded ) }
							>
								{ expanded
									? __( 'Show fewer', 'rtcamp-amc-copilot' )
									: sprintf(
											/* translators: %d: number of rows. */
											__(
												'Show all %d',
												'rtcamp-amc-copilot'
											),
											data.length
										) }
							</button>
						) }
					</div>
					<div className="tablenav-pages one-page">
						<span className="displaying-num">
							{ countItems( data.length ) }
						</span>
					</div>
					<br className="clear" />
				</div>
			) }
		</div>
	);
}
