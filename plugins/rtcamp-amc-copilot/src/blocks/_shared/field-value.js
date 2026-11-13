/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Display string for a value, mirroring AbstractSectionBlock::format_value().
 *
 * @param {?(string|number|boolean|Object)} value Value.
 * @param {string}                          type  Field type (string, url, bool, date, ...).
 * @return {string} Text.
 */
export function formatValue( value, type = 'string' ) {
	if (
		value === null ||
		value === undefined ||
		value === '' ||
		( Array.isArray( value ) && value.length === 0 )
	) {
		return __( '—', 'rtcamp-amc-copilot' );
	}
	if ( type === 'bool' ) {
		return value
			? __( 'Yes', 'rtcamp-amc-copilot' )
			: __( 'No', 'rtcamp-amc-copilot' );
	}
	if ( typeof value === 'object' ) {
		return JSON.stringify( value );
	}
	return String( value );
}

/**
 * Effective value of an auto/saved field: override first, then snapshot.
 *
 * @param {Object} field      Field definition ({ key }).
 * @param {Object} values     Snapshot section values.
 * @param {Object} attributes Block attributes.
 * @return {{ value: ?(string|number|boolean|Object), edited: boolean }} Value and whether it was overridden.
 */
export function effectiveValue( field, values, attributes ) {
	const override = attributes.overrides?.[ field.key ];
	if ( override !== undefined && override !== '' ) {
		return { value: override, edited: true };
	}
	return { value: values?.[ field.key ] ?? null, edited: false };
}

/**
 * Formatted value plus an "Edited" badge (editor only) when overridden.
 *
 * @param {Object} props            Props.
 * @param {Object} props.field      Field definition.
 * @param {Object} props.values     Snapshot section values.
 * @param {Object} props.attributes Block attributes.
 * @return {Element} Markup.
 */
export default function FieldValue( { field, values, attributes } ) {
	const { value, edited } = effectiveValue( field, values, attributes );

	return (
		<>
			{ formatValue( value, field.type ) }
			{ edited && (
				<span className="amc-edited-badge">
					{ __( 'Edited', 'rtcamp-amc-copilot' ) }
				</span>
			) }
		</>
	);
}
