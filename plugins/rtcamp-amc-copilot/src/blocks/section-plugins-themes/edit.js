/**
 * Editor UI for the plugins_themes section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'plugins_themes';

const FIELDS = [
	{
		key: 'counts',
		label: __( 'Counts', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'object',
	},
	{
		key: 'updated',
		label: __( 'Updated', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'added',
		label: __( 'Added', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'removed',
		label: __( 'Removed', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'inactive',
		label: __( 'Inactive', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'change_reasons',
		label: __(
			'Reason for each added or removed plugin',
			'rtcamp-amc-copilot'
		),
		source: 'engineer',
		type: 'map',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'Plugins and themes', 'rtcamp-amc-copilot' ) }
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
