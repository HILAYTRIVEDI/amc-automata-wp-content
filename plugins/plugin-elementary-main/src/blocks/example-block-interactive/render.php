<?php

declare(strict_types = 1);

/**
 * PHP file to use when rendering the block type on the server to show on the front end.
 *
 * The following variables are exposed to the file:
 *     $attributes (array): The block attributes.
 *     $content (string): The block default content.
 *     $block (WP_Block): The block instance.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/docs/reference-guides/block-api/block-metadata.md#render
 *
 * @package AMC_Automata_Features\Features
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Generate unique id for aria-controls.
$unique_id = wp_unique_id( 'p-' );
?>

<div
	<?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core API returns safe HTML attributes. ?>
	data-wp-interactive="amc-automata-features-features/example-block-interactive"
	<?php echo wp_interactivity_data_wp_context( [ 'isOpen' => false ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core API returns safe HTML attributes. ?>
	data-wp-watch="callbacks.logIsOpen"
>
	<button
		data-wp-on--click="actions.toggle"
		data-wp-bind--aria-expanded="context.isOpen"
		aria-controls="<?php echo esc_attr( $unique_id ); ?>"
	>
		<?php esc_html_e( 'Toggle', 'amc-automata-features-features' ); ?>
	</button>

	<p
		id="<?php echo esc_attr( $unique_id ); ?>"
		data-wp-bind--hidden="!context.isOpen"
	>
		<?php
			esc_html_e( 'Example Block Interactive - hello from an interactive block!', 'amc-automata-features-features' );
		?>
	</p>
</div>
