/**
 * Editor UI for the performance section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'performance';

const FIELDS = [
	{
		key: 'response_trend',
		label: __( 'Response time trend', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'database_size_bytes',
		label: __( 'Database size', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'int',
	},
	{
		key: 'autoload_size_bytes',
		label: __( 'Autoloaded options size', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'int',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'Performance', 'rtcamp-amc-copilot' ) }
			fields={ FIELDS }
			values={ values }
			attributes={ attributes }
			setAttributes={ setAttributes }
		>
			<figure className="wp-block-table">
				<table className="has-fixed-layout">
					<tbody>
						{ FIELDS.map( ( field ) => (
							<FieldRow
								key={ field.key }
								field={ field }
								values={ values }
								attributes={ attributes }
								setAttributes={ setAttributes }
							/>
						) ) }
					</tbody>
				</table>
			</figure>
		</SectionShell>
	);
}
