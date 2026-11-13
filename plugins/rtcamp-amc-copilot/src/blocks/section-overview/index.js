/**
 * Report section block: Overview (amc/section-overview).
 *
 * EXEMPLAR: copy this folder for each section block. The block is dynamic
 * (save returns null): AMC\Reports\Blocks\OverviewSection renders it in PHP.
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
