/**
 * Editor UI for the forms section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'forms';

const FIELDS = [
	{
		key: 'form_plugins',
		label: __( 'Form plugins', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'sending_method',
		label: __( 'Sending method', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'integrations',
		label: __( 'Integrations', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'entries_saving',
		label: __( 'Entries saving', 'rtcamp-amc-copilot' ),
		source: 'engineer',
		type: 'html',
	},
	{
		key: 'notifications_confirmed',
		label: __( 'Notifications confirmed', 'rtcamp-amc-copilot' ),
		source: 'engineer',
		type: 'html',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'Forms', 'rtcamp-amc-copilot' ) }
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
