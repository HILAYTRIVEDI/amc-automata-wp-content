/**
 * Editor UI for the caching_cdn section.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SectionShell, FieldRow, useSnapshotSection } from '../_shared';

const SECTION = 'caching_cdn';

const FIELDS = [
	{
		key: 'page_cache',
		label: __( 'Page cache', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'object_cache',
		label: __( 'Object cache', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'cdn',
		label: __( 'CDN', 'rtcamp-amc-copilot' ),
		source: 'auto',
		type: 'string',
	},
	{
		key: 'configured_correctly',
		label: __( 'Configured correctly', 'rtcamp-amc-copilot' ),
		source: 'engineer',
		type: 'html',
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const values = useSnapshotSection( SECTION, context );

	return (
		<SectionShell
			title={ __( 'Caching and CDN', 'rtcamp-amc-copilot' ) }
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
