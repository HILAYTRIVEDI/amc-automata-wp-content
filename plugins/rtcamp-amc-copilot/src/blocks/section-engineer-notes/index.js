/**
 * Report section block: Engineer's notes (amc/section-engineer-notes).
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
