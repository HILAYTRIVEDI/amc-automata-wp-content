/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps( { className: 'amc-section' } );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Section', 'rtcamp-amc-copilot' ) }>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Title', 'rtcamp-amc-copilot' ) }
						value={ attributes.title || '' }
						onChange={ ( title ) => setAttributes( { title } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Carry forward', 'rtcamp-amc-copilot' ) }
						help={ __(
							'Repeat this section in the next report.',
							'rtcamp-amc-copilot'
						) }
						checked={ !! attributes.carryForward }
						onChange={ ( carryForward ) =>
							setAttributes( { carryForward } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<section { ...blockProps }>
				<h2 className="wp-block-heading">
					{ attributes.title ||
						__( 'Custom section', 'rtcamp-amc-copilot' ) }
				</h2>
				<InnerBlocks />
			</section>
		</>
	);
}
