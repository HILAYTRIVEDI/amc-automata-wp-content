/**
 * Editor UI for the theme section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'theme';

const FIELDS = [
	{
		key: 'active_theme',
		label: __( 'Active theme', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'object',
	},
	{
		key: 'parent_theme',
		label: __( 'Parent theme', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'object',
	},
	{
		key: 'theme_origin',
		label: __( 'Theme origin', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'Theme', 'rtcamp-amc-copilot' ) }
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
