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
 * @param {string}   [props.label]     Accessible label; a summary is generated when omitted.
 * @return {Element} SVG.
 */
export default function Sparkline( {
	values,
	width = 96,
	height = 24,
	label,
} ) {
	if ( ! values?.length ) {
		return <span>{ '—' }</span>;
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
	const summary =
		label ??
		sprintf(
			/* translators: 1: number of points, 2: minimum, 3: maximum, 4: latest value. */
			__(
				'Trend over %1$d points: min %2$d, max %3$d, latest %4$d',
				'rtcamp-amc-copilot'
			),
			values.length,
			min,
			max,
			values[ values.length - 1 ]
		);
	return (
		<svg
			className="amc-sparkline"
			width={ width }
			height={ height }
			viewBox={ `0 0 ${ width } ${ height }` }
			role="img"
			aria-label={ summary }
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
