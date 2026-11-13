/**
 * Editor UI for the hosting section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'hosting';

const FIELDS = [
	{
		key: 'host',
		label: __( 'Host', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'server_type',
		label: __( 'Server type', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'web_server',
		label: __( 'Web server', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'mysql_version',
		label: __( 'MySQL version', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'php_version',
		label: __( 'PHP version', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'memory_limit',
		label: __( 'Memory limit', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'php_recommendation',
		label: __( 'PHP update recommendation', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'hosting_type',
		label: __( 'Hosting type', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'control_panel',
		label: __( 'Control panel', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'server_backup_frequency',
		label: __( 'Server backup frequency', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'server_backup_retention',
		label: __( 'Server backup retention', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'redundancy',
		label: __( 'Redundancy', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'account_owners',
		label: __( 'Account owners', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'hosting_users',
		label: __( 'Hosting users', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'disk_space',
		label: __( 'Disk space', 'rtcamp-amc-copilot' ),
		source: 'engineer',
		type: 'html',
	},
	{
		key: 'server_backups_running',
		label: __( 'Server backups running', 'rtcamp-amc-copilot' ),
		source: 'engineer',
		type: 'html',
	},
	{
		key: 'latest_server_backup',
		label: __( 'Most recent server backup', 'rtcamp-amc-copilot' ),
		source: 'engineer',
		type: 'html',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'Hosting', 'rtcamp-amc-copilot' ) }
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
