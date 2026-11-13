/**
 * Internal dependencies
 */
import EngineerField from './engineer-field';
import FieldValue from './field-value';

/**
 * One label/value row of a core Table, for any field source.
 *
 * @param {Object}                    props               Props.
 * @param {Object}                    props.field         Field definition ({ key, label, source, type }).
 * @param {Object}                    props.values        Snapshot section values.
 * @param {Object}                    props.attributes    Block attributes.
 * @param {( attrs: Object ) => void} props.setAttributes Attribute setter.
 * @return {Element} Table row.
 */
export default function FieldRow( {
	field,
	values,
	attributes,
	setAttributes,
} ) {
	return (
		<tr>
			<th scope="row">{ field.label }</th>
			<td>
				{ field.source === 'engineer' ? (
					<EngineerField
						field={ field }
						attributes={ attributes }
						setAttributes={ setAttributes }
					/>
				) : (
					<FieldValue
						field={ field }
						values={ values }
						attributes={ attributes }
					/>
				) }
			</td>
		</tr>
	);
}
