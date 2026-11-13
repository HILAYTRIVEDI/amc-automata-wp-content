/**
 * Editor UI for the domains section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'domains';

const FIELDS = [
	{
		key: 'primary_domain',
		label: __( 'Primary domain', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'domain_expiry',
		label: __( 'Expiry', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'auto_renewal',
		label: __( 'Auto-renewal', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'subdomains',
		label: __( 'Subdomains', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'Domains', 'rtcamp-amc-copilot' ) }
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
