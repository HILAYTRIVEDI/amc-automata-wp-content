/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import StatusBadge from '../components/StatusBadge';
import FindingLink from '../components/FindingLink';
import { formatDateTime } from './format';

/**
 * Status filter options (labels match StatusBadge).
 *
 * @return {Array<{value: string, label: string}>} Options.
 */
export const statusElements = () => [
	{ value: 'pass', label: __( 'Pass', 'rtcamp-amc-copilot' ) },
	{ value: 'warn', label: __( 'Warning', 'rtcamp-amc-copilot' ) },
	{ value: 'fail', label: __( 'Failed', 'rtcamp-amc-copilot' ) },
	{ value: 'info', label: __( 'Info', 'rtcamp-amc-copilot' ) },
];

/**
 * Build a filter-capable options list from plain values.
 *
 * @param {string[]} values Values, also used as labels.
 * @return {Array<{value: string, label: string}>} Options.
 */
export const toElements = ( values ) =>
	values.map( ( value ) => ( { value, label: value } ) );

/**
 * Plain text column.
 *
 * @param {string} id        Field ID (item key unless `getValue` is given).
 * @param {string} label     Column label.
 * @param {Object} [options] Extra DataViews field props.
 * @return {Object} Field.
 */
export const textField = ( id, label, options = {} ) => ( {
	id,
	label,
	type: 'text',
	enableGlobalSearch: true,
	getValue: ( { item } ) => item[ id ] ?? '',
	...options,
} );

/**
 * Integer column.
 *
 * @param {string} id        Field ID.
 * @param {string} label     Column label.
 * @param {Object} [options] Extra DataViews field props.
 * @return {Object} Field.
 */
export const numberField = ( id, label, options = {} ) => ( {
	id,
	label,
	type: 'integer',
	getValue: ( { item } ) => item[ id ] ?? 0,
	...options,
} );

/**
 * Date and time column (ISO 8601 values).
 *
 * @param {string} id        Field ID.
 * @param {string} label     Column label.
 * @param {Object} [options] Extra DataViews field props.
 * @return {Object} Field.
 */
export const dateField = ( id, label, options = {} ) => ( {
	id,
	label,
	type: 'datetime',
	getValue: ( { item } ) => item[ id ] ?? '',
	render: ( { item } ) => (
		<span className="amc-nowrap">{ formatDateTime( item[ id ] ) }</span>
	),
	...options,
} );

/**
 * Column with a fixed list of values, rendered as text and filterable.
 *
 * @param {string} id        Field ID.
 * @param {string} label     Column label.
 * @param {Array}  elements  Options `{ value, label }`.
 * @param {Object} [options] Extra DataViews field props.
 * @return {Object} Field.
 */
export const enumField = ( id, label, elements, options = {} ) => ( {
	id,
	label,
	type: 'text',
	elements,
	filterBy: { operators: [ 'isAny' ] },
	getValue: ( { item } ) => item[ id ] ?? '',
	render: ( { item } ) => (
		<span>
			{ elements.find( ( e ) => e.value === item[ id ] )?.label ??
				item[ id ] }
		</span>
	),
	...options,
} );

/**
 * Status column: StatusBadge (colour, icon and text), filterable.
 *
 * @param {Object} [options] Extra DataViews field props. `status` maps an item to a status.
 * @return {Object} Field.
 */
export const statusField = ( options = {} ) => {
	const { status = ( item ) => item.status, ...rest } = options;
	return {
		id: 'status',
		label: __( 'Status', 'rtcamp-amc-copilot' ),
		type: 'text',
		elements: statusElements(),
		filterBy: { operators: [ 'isAny' ], isPrimary: true },
		getValue: ( { item } ) => status( item ),
		render: ( { item } ) => <StatusBadge status={ status( item ) } />,
		...rest,
	};
};

/**
 * Column linking a row to the run that produced it.
 *
 * @param {string} [id='run_id'] Item key holding the run ID.
 * @return {Object} Field.
 */
export const runField = ( id = 'run_id' ) => ( {
	id,
	label: __( 'Run', 'rtcamp-amc-copilot' ),
	type: 'integer',
	enableSorting: false,
	getValue: ( { item } ) => item[ id ] ?? 0,
	render: ( { item } ) => <FindingLink runId={ item[ id ] } />,
} );

/**
 * Column that renders arbitrary content but exports plain text.
 *
 * @param {string}                          id        Field ID.
 * @param {string}                          label     Column label.
 * @param {(...args: unknown[]) => unknown} value     Item to plain value.
 * @param {(...args: unknown[]) => unknown} render    Item to element.
 * @param {Object}                          [options] Extra DataViews field props.
 * @return {Object} Field.
 */
export const customField = ( id, label, value, render, options = {} ) => ( {
	id,
	label,
	type: 'text',
	getValue: ( { item } ) => value( item ),
	render: ( { item } ) => render( item ),
	...options,
} );
