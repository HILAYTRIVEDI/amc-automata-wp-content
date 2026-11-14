/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { download } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { exportFieldsCsv } from '../utils/csv';
import SimpleTable, { exportMessage } from './SimpleTable';

const DEFAULT_LAYOUTS = { table: {} };

/**
 * Lists with at most this many rows and no row actions are drawn as a plain
 * core list table (see `SimpleTable`) unless a screen asks for the full view.
 */
export const COMPACT_MAX_ROWS = 10;

/**
 * A list with its CSV export. Long lists, and lists with row actions, use
 * `@wordpress/dataviews` (search, filters, sorting, pagination; the export
 * holds the filtered rows with every field). Short lists without actions use
 * a core `widefat striped` table instead (`SimpleTable`), so a box with three
 * rows is not buried under a toolbar.
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
 * @param {string}                          [props.variant]     `auto` (default), `full` (always DataViews) or `compact`
 *                                                              (always a core table; row actions are not shown).
 * @param {string}                          [props.emptyText]   Text for an empty compact table.
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
	variant = 'auto',
	emptyText,
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
		exportFieldsCsv( exportName, fields, filtered );
		speak( exportMessage( filtered.length ), 'polite' );
	};

	const compact =
		'compact' === variant ||
		( 'auto' === variant &&
			! actions?.length &&
			data.length <= COMPACT_MAX_ROWS );

	if ( compact ) {
		return (
			<SimpleTable
				data={ data }
				fields={ fields }
				getItemId={ getItemId }
				label={ tableLabel }
				exportName={ exportName }
				columns={ initialView.fields }
				emptyText={ emptyText }
			/>
		);
	}

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
