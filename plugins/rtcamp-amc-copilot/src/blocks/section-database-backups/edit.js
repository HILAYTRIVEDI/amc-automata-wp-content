/**
 * Editor UI for the database_backups section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'database_backups';

const FIELDS = [
	{
		key: 'backup_method',
		label: __( 'Method', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'backup_schedule',
		label: __( 'Schedule', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'backup_retention',
		label: __( 'Retention', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'backups_working',
		label: __( 'Working correctly', 'rtcamp-amc-copilot' ),
		source: 'engineer',
		type: 'html',
	},
	{
		key: 'latest_backup',
		label: __( 'Most recent backup', 'rtcamp-amc-copilot' ),
		source: 'engineer',
		type: 'html',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'Database backups', 'rtcamp-amc-copilot' ) }
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
