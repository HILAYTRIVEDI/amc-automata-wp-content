/**
 * Editor UI for the php_errors section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'php_errors';

const FIELDS = [
	{
		key: 'fatal_count',
		label: __( 'Fatal errors', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'int',
	},
	{
		key: 'warning_count',
		label: __( 'Warnings and notices', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'int',
	},
	{
		key: 'deprecation_count',
		label: __( 'Deprecations', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'int',
	},
	{
		key: 'top_signatures',
		label: __( 'Top signatures', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'PHP errors', 'rtcamp-amc-copilot' ) }
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
