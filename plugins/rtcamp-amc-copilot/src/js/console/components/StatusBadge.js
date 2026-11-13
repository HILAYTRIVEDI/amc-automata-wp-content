/**
 * WordPress dependencies
 */
import { Icon, caution, error, info, published } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

const getMap = () => ( {
	pass: {
		label: __( 'Pass', 'rtcamp-amc-copilot' ),
		intent: 'success',
		icon: published,
	},
	warn: {
		label: __( 'Warning', 'rtcamp-amc-copilot' ),
		intent: 'warning',
		icon: caution,
	},
	fail: {
		label: __( 'Failed', 'rtcamp-amc-copilot' ),
		intent: 'error',
		icon: error,
	},
	info: {
		label: __( 'Info', 'rtcamp-amc-copilot' ),
		intent: 'info',
		icon: info,
	},
} );

/**
 * Map a severity to the status used for its badge colour.
 *
 * @param {string} severity critical | high | medium | low | info.
 * @return {string} pass | warn | fail | info.
 */
export const statusFromSeverity = ( severity ) => {
	if ( 'critical' === severity || 'high' === severity ) {
		return 'fail';
	}
	return 'medium' === severity ? 'warn' : 'info';
};

/**
 * Translated label for a severity (`critical` becomes "Critical").
 *
 * @param {string} severity critical | high | medium | low | info.
 * @return {string} Label; the raw value for an unknown severity.
 */
export const severityLabel = ( severity ) =>
	( {
		critical: __( 'Critical', 'rtcamp-amc-copilot' ),
		high: __( 'High', 'rtcamp-amc-copilot' ),
		medium: __( 'Medium', 'rtcamp-amc-copilot' ),
		low: __( 'Low', 'rtcamp-amc-copilot' ),
		info: __( 'Info', 'rtcamp-amc-copilot' ),
	} )[ severity ] ?? severity;

/**
 * Status shown as colour, icon AND a text label, never colour alone.
 *
 * Uses the `components-badge` classes that ship with `@wordpress/components`,
 * so the colours are core's own notice colours.
 *
 * @param {Object} props
 * @param {string} props.status  pass | warn | fail | info.
 * @param {string} [props.label] Overrides the default label (e.g. "Critical").
 * @return {Element} Badge.
 */
export default function StatusBadge( { status, label } ) {
	const entry = getMap()[ status ] ?? getMap().info;
	return (
		<span
			className={ `components-badge is-${ entry.intent } has-icon amc-status` }
		>
			<span className="components-badge__flex-wrapper">
				<Icon
					icon={ entry.icon }
					size={ 16 }
					fill="currentColor"
					className="components-badge__icon"
				/>
				<span className="components-badge__content">
					{ label ?? entry.label }
				</span>
			</span>
		</span>
	);
}
