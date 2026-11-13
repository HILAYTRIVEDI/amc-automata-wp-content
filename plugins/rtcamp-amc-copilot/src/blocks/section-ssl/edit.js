/**
 * Editor UI for the ssl section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'ssl';

const FIELDS = [
	{
		key: 'https',
		label: __( 'HTTPS', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'bool',
	},
	{
		key: 'issuer',
		label: __( 'Issuer', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'expires_at',
		label: __( 'Expiry', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'date',
	},
	{
		key: 'days_left',
		label: __( 'Days left', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'int',
	},
	{
		key: 'ssl_type',
		label: __( 'Purchased or free', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'ssl_auto_renewal',
		label: __( 'Auto-renewal', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'SSL', 'rtcamp-amc-copilot' ) }
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
