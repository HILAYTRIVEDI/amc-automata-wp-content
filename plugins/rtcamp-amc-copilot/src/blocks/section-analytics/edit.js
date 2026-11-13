/**
 * Editor UI for the analytics section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'analytics';

const FIELDS = [
	{
		key: 'gtm_ids',
		label: __( 'GTM IDs', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'ga_ids',
		label: __( 'GA IDs', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'list',
	},
	{
		key: 'tag_assistant',
		label: __( 'Tag Assistant warnings and errors', 'rtcamp-amc-copilot' ),
		source: 'engineer',
		type: 'html',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'Analytics and tagging', 'rtcamp-amc-copilot' ) }
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
