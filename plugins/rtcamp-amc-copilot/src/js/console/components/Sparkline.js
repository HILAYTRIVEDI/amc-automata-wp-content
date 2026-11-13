/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Tiny inline SVG line chart (for example 30-day counts). Uses `currentColor`.
 *
 * @param {Object}   props
 * @param {number[]} props.values      Data points, oldest first.
 * @param {number}   [props.width=96]  Width in px.
 * @param {number}   [props.height=24] Height in px.
 * @param {string}   [props.label]     What the series is (e.g. "Response time for /shop"), used to start the text alternative.
 * @param {string}   [props.unit]      Unit appended to the values in the text alternative (e.g. "ms").
 * @return {Element} SVG.
 */
export default function Sparkline( {
	values,
	width = 96,
	height = 24,
	label,
	unit = '',
} ) {
	if ( ! values?.length ) {
		return (
			<span>
				<span aria-hidden="true">{ '—' }</span>
				<span className="screen-reader-text">
					{ label
						? sprintf(
								/* translators: %s: what the trend shows. */
								__( '%s: no data', 'rtcamp-amc-copilot' ),
								label
							)
						: __( 'No data', 'rtcamp-amc-copilot' ) }
				</span>
			</span>
		);
	}
	const min = Math.min( ...values );
	const max = Math.max( ...values );
	const range = max - min || 1;
	const pad = 2;
	const step =
		values.length > 1 ? ( width - pad * 2 ) / ( values.length - 1 ) : 0;
	const points = values
		.map( ( value, i ) => {
			const x = pad + i * step;
			const y =
				height -
				pad -
				( ( value - min ) / range ) * ( height - pad * 2 );
			return `${ x.toFixed( 1 ) },${ y.toFixed( 1 ) }`;
		} )
		.join( ' ' );
	const withUnit = ( value ) =>
		`${ Number( Number( value ).toFixed( 1 ) ) }${ unit }`;
	// A text alternative with the facts a sighted user reads off the line:
	// how many points, where it started and ended, and the extremes.
	const description = sprintf(
		/* translators: 1: number of points, 2: first value, 3: latest value, 4: minimum, 5: maximum. */
		__(
			'Trend over %1$d points: first %2$s, latest %3$s, min %4$s, max %5$s',
			'rtcamp-amc-copilot'
		),
		values.length,
		withUnit( values[ 0 ] ),
		withUnit( values[ values.length - 1 ] ),
		withUnit( min ),
		withUnit( max )
	);
	const summary = label ? `${ label }. ${ description }` : description;
	return (
		<svg
			className="amc-sparkline"
			width={ width }
			height={ height }
			viewBox={ `0 0 ${ width } ${ height }` }
			role="img"
			aria-label={ summary }
			focusable="false"
		>
			<title>{ summary }</title>
			<polyline
				points={ points }
				fill="none"
				stroke="currentColor"
				strokeWidth="1.5"
				strokeLinejoin="round"
				strokeLinecap="round"
			/>
		</svg>
	);
}
