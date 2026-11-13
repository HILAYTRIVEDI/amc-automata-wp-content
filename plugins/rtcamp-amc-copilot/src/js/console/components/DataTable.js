/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { download } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { downloadCsv, toCsv } from '../utils/csv';

const DEFAULT_LAYOUTS = { table: {} };

/**
 * Value of a field for an item, resolved to display text for CSV (option
 * values are exported as their labels).
 *
 * @param {Object} field Field definition.
 * @param {Object} item  Row.
 * @return {unknown} Cell value.
 */
const cellValue = ( field, item ) => {
	const value = field.getValue
		? field.getValue( { item } )
		: item[ field.id ];
	const option = field.elements?.find(
		( element ) => element.value === value
	);
	return option ? option.label : value;
};

/**
 * `@wordpress/dataviews` table with filtering, sorting, search, pagination
 * and a CSV export of the filtered rows (all fields, search and filters applied).
 *
 * @param {Object}                          props
 * @param {Object[]}                        props.data          All rows (filtering and paging happen here).
 * @param {Object[]}                        props.fields        DataViews field definitions. `getValue` should return plain
 *                                                              values (strings, numbers, ISO dates); `render` draws the cell.
 * @param {Object[]}                        [props.actions]     DataViews row actions.
 * @param {(...args: unknown[]) => unknown} [props.getItemId]   Row ID getter (defaults to `item.id`).
 * @param {string}                          props.exportName    File name for the CSV export, without extension.
 * @param {boolean}                         [props.isLoading]   Loading state.
 * @param {Object}                          [props.initialView] Overrides for the initial view (sort, filters, perPage, fields).
 * @param {string}                          [props.searchLabel] Accessible search label.
 * @param {Element}                         [props.header]      Extra toolbar content.
 * @param {Element}                         [props.empty]       Empty state.
 * @return {Element} Table.
 */
export default function DataTable( {
	data,
	fields,
	actions,
	getItemId,
	exportName,
	isLoading = false,
	initialView = {},
	searchLabel = __( 'Search', 'rtcamp-amc-copilot' ),
	header,
	empty,
} ) {
	const [ view, setView ] = useState( {
		type: 'table',
		search: '',
		page: 1,
		perPage: 10,
		filters: [],
		fields: fields.map( ( field ) => field.id ),
		layout: {},
		...initialView,
	} );

	const { data: pageData, paginationInfo } = useMemo(
		() => filterSortAndPaginate( data, view, fields ),
		[ data, view, fields ]
	);

	const exportCsv = () => {
		const { data: filtered } = filterSortAndPaginate(
			data,
			{ ...view, page: 1, perPage: Math.max( data.length, 1 ) },
			fields
		);
		// Every field is exported, including ones hidden from the table (such as
		// a run's raw data), so the CSV always carries the full row.
		const columns = fields;
		downloadCsv(
			exportName,
			toCsv(
				columns.map( ( field ) => field.label ?? field.id ),
				filtered.map( ( item ) =>
					columns.map( ( field ) => cellValue( field, item ) )
				)
			)
		);
	};

	return (
		<div className="amc-datatable">
			<DataViews
				data={ pageData }
				fields={ fields }
				actions={ actions }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ paginationInfo }
				defaultLayouts={ DEFAULT_LAYOUTS }
				getItemId={ getItemId ?? ( ( item ) => String( item.id ) ) }
				isLoading={ isLoading }
				searchLabel={ searchLabel }
				empty={ empty }
				header={
					<>
						{ header }
						<Button
							variant="tertiary"
							size="compact"
							icon={ download }
							onClick={ exportCsv }
						>
							{ __( 'Export CSV', 'rtcamp-amc-copilot' ) }
						</Button>
					</>
				}
			/>
		</div>
	);
}
