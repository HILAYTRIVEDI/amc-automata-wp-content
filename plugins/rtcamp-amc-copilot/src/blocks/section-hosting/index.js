/**
 * Report section block: Hosting (amc/section-hosting).
 */

/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';
import '../_shared/editor.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
