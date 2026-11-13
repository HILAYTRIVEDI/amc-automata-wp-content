/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
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
 * @param {string}                          [props.label]       What the table lists (for example "Last run per group"). Names the
 *                                                              table, its search field and its export button, so several tables
 *                                                              on one screen can be told apart.
 * @param {string}                          [props.searchLabel] Accessible search label (defaults to "Search" plus `label`).
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
	label,
	searchLabel,
	header,
	empty,
} ) {
	const wrapper = useRef( null );
	const tableLabel = label ?? exportName;
	const searchName =
		searchLabel ??
		( label
			? sprintf(
					/* translators: %s: what the table lists. */
					__( 'Search %s', 'rtcamp-amc-copilot' ),
					label
				)
			: __( 'Search', 'rtcamp-amc-copilot' ) );

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

	// DataViews renders a bare `<table>` with no caption or name, so name it
	// after every render (the element is replaced when the view changes).
	useEffect( () => {
		wrapper.current
			?.querySelector( 'table.dataviews-view-table' )
			?.setAttribute( 'aria-label', tableLabel );
	} );

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
		speak(
			sprintf(
				/* translators: %d: number of rows. */
				_n(
					'CSV export started: %d row.',
					'CSV export started: %d rows.',
					filtered.length,
					'rtcamp-amc-copilot'
				),
				filtered.length
			),
			'polite'
		);
	};

	return (
		<div
			className="amc-datatable"
			ref={ wrapper }
			role="region"
			aria-label={ tableLabel }
		>
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
				searchLabel={ searchName }
				empty={ empty }
				header={
					<>
						{ header }
						<Button
							variant="tertiary"
							size="compact"
							icon={ download }
							onClick={ exportCsv }
							aria-label={ sprintf(
								/* translators: %s: what the table lists. */
								__( 'Export CSV: %s', 'rtcamp-amc-copilot' ),
								tableLabel
							) }
						>
							{ __( 'Export CSV', 'rtcamp-amc-copilot' ) }
						</Button>
					</>
				}
			/>
		</div>
	);
}
