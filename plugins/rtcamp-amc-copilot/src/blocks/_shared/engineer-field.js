/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { RichText } from '@wordpress/block-editor';

/**
 * Inline RichText for an Engineer field, stored in attributes.engineer[ key ].
 *
 * @param {Object}                    props               Props.
 * @param {Object}                    props.field         Field definition ({ key, label }).
 * @param {Object}                    props.attributes    Block attributes.
 * @param {( attrs: Object ) => void} props.setAttributes Attribute setter.
 * @return {Element} RichText.
 */
export default function EngineerField( { field, attributes, setAttributes } ) {
	const engineer = attributes.engineer || {};

	return (
		<RichText
			tagName="div"
			aria-label={ field.label }
			placeholder={ __( 'Type here…', 'rtcamp-amc-copilot' ) }
			value={ engineer[ field.key ] ?? '' }
			allowedFormats={ [ 'core/bold', 'core/italic', 'core/link' ] }
			onChange={ ( next ) =>
				setAttributes( {
					engineer: { ...engineer, [ field.key ]: next },
				} )
			}
		/>
	);
}
