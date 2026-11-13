/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import '../_shared/editor.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	save: ( { attributes } ) => (
		<section className="amc-section amc-section--custom">
			<h2 className="wp-block-heading">{ attributes.title }</h2>
			<InnerBlocks />
		</section>
	),
} );
