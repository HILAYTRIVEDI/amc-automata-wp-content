/**
 * Report section block: Theme (amc/section-theme).
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
