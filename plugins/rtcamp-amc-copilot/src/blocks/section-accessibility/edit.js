/**
 * Editor UI for the accessibility section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'accessibility';

const FIELDS = [
	{
		key: 'third_party_tool',
		label: __( 'Third-party tool', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'statement_page',
		label: __( 'Statement page', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'url',
	},
	{
		key: 'defined_standard',
		label: __( 'Defined standard', 'rtcamp-amc-copilot' ),
		source: 'saved',
		type: 'string',
	},
	{
		key: 'wave_result',
		label: __( 'WAVE result', 'rtcamp-amc-copilot' ),
		source: 'engineer',
		type: 'html',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'Accessibility', 'rtcamp-amc-copilot' ) }
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
