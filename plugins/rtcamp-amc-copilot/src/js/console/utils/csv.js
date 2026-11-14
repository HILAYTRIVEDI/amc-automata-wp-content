/**
 * Escape one CSV cell (RFC 4180), neutralising spreadsheet formulas.
 *
 * @param {unknown} value Cell value.
 * @return {string} Escaped cell.
 */
export const csvCell = ( value ) => {
	let text = Array.isArray( value )
		? value.join( ' ' )
		: String( value ?? '' );
	if ( /^[=+@\t\r]/.test( text ) ) {
		text = `'${ text }`;
	}
	return /[",\r\n]/.test( text ) ? `"${ text.replace( /"/g, '""' ) }"` : text;
};

/**
 * Build CSV text.
 *
 * @param {string[]} headers Column headers.
 * @param {Array[]}  rows    Rows of cell values.
 * @return {string} CSV text.
 */
export const toCsv = ( headers, rows ) =>
	[ headers, ...rows ]
		.map( ( row ) => row.map( csvCell ).join( ',' ) )
		.join( '\r\n' );

/**
 * Trigger a browser download of CSV text.
 *
 * @param {string} filename File name, without extension.
 * @param {string} csv      CSV text.
 */
export const downloadCsv = ( filename, csv ) => {
	const blob = new window.Blob( [ csv ], { type: 'text/csv;charset=utf-8' } );
	const url = window.URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = `${ filename }.csv`;
	document.body.appendChild( link );
	link.click();
	document.body.removeChild( link );
	window.URL.revokeObjectURL( url );
};

/**
 * Value of a field for an item, resolved to display text for CSV (option
 * values are exported as their labels).
 *
 * @param {Object} field Field definition (DataViews shape).
 * @param {Object} item  Row.
 * @return {unknown} Cell value.
 */
export const fieldValue = ( field, item ) => {
	const value = field.getValue
		? field.getValue( { item } )
		: item[ field.id ];
	const option = field.elements?.find(
		( element ) => element.value === value
	);
	return option ? option.label : value;
};

/**
 * Download rows as CSV with one column per field (every field, including
 * ones hidden from the table, so the CSV always carries the full row).
 *
 * @param {string}   filename File name, without extension.
 * @param {Object[]} fields   Field definitions.
 * @param {Object[]} rows     Rows to export.
 */
export const exportFieldsCsv = ( filename, fields, rows ) => {
	downloadCsv(
		filename,
		toCsv(
			fields.map( ( field ) => field.label ?? field.id ),
			rows.map( ( item ) =>
				fields.map( ( field ) => fieldValue( field, item ) )
			)
		)
	);
};
