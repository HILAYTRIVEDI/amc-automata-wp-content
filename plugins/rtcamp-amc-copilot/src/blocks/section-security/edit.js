/**
 * Editor UI for the security section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'security';

const FIELDS = [
	{
		key: 'vulnerabilities',
		label: __( 'Vulnerabilities', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'core_checksums',
		label: __( 'Core checksums', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'object',
	},
	{
		key: 'config_checks',
		label: __( 'Configuration checks', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'remediation_notes',
		label: __( 'Remediation notes', 'rtcamp-amc-copilot' ),
		source: 'engineer',
		type: 'html',
	},
	{
		key: 'malware_scan',
		label: __( 'Malware scan result', 'rtcamp-amc-copilot' ),
		source: 'engineer',
		type: 'html',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'Security', 'rtcamp-amc-copilot' ) }
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
