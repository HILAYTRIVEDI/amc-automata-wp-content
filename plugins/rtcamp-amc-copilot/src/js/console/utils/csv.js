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
