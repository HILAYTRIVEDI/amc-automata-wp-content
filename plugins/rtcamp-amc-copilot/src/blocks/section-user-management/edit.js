/**
 * Editor UI for the user_management section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'user_management';

const FIELDS = [
	{
		key: 'total_users',
		label: __( 'Total users', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'int',
	},
	{
		key: 'users_per_role',
		label: __( 'Users per role', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'administrators',
		label: __( 'Administrators', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'users_added',
		label: __( 'Users added', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'users_removed',
		label: __( 'Users removed', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'User management', 'rtcamp-amc-copilot' ) }
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
