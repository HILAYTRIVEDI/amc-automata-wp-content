/**
 * Editor UI for the Overview section.
 *
 * Pattern for every section block:
 *   1. SECTION = the SnapshotSchema section key.
 *   2. FIELDS  = every field of that section, in display order, with its
 *                source. MUST match AMC\Reports\SnapshotSchema::sections()
 *                and the PHP render_section() order.
 *   3. Render <SectionShell> (hide toggle, override panel, heading) and a
 *      core Table with one <FieldRow> per field.
 * Nothing else: no custom components, colours or fonts.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'overview';

const FIELDS = [
	{
		key: 'client_name',
		label: __( 'Client', 'rtcamp-amc-copilot' ),
		source: 'saved',
	},
	{
		key: 'website_url',
		label: __( 'Website URL', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'url',
	},
	{
		key: 'developer_name',
		label: __( 'Developer', 'rtcamp-amc-copilot' ),
		source: 'auto',
	},
	{
		key: 'report_date',
		label: __( 'Report date', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'date',
	},
	{
		key: 'maintenance_schedule',
		label: __( 'Maintenance schedule', 'rtcamp-amc-copilot' ),
		source: 'saved',
	},
	{
		key: 'hours_used',
		label: __( 'Hours used', 'rtcamp-amc-copilot' ),
		source: 'engineer',
	},
];

/**
 * Edit component.
 *
 * @param {Object}                    props               Block props.
 * @param {Object}                    props.attributes    Attributes (hidden, overrides, engineer).
 * @param {( attrs: Object ) => void} props.setAttributes Attribute setter.
 * @param {Object}                    props.context       Block context (postId, postType).
 * @return {Element} Editor markup.
 */
export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'Overview', 'rtcamp-amc-copilot' ) }
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
