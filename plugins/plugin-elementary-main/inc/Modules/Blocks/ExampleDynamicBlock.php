<?php
/**
 * Example dynamic block with server-side rendering.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules\Blocks;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractBlock;

/**
 * Class - ExampleDynamicBlock
 */
final class ExampleDynamicBlock extends AbstractBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function get_name(): string {
		return 'amc-automata-features-features/example-block-dynamic';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string Absolute path to this block's build directory.
	 */
	protected function get_block_dir(): string {
		return AMC_AUTOMATA_FEATURES_FEATURES_PATH . 'assets/build/blocks/example-block-dynamic';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array     $attributes Block attributes.
	 * @param string    $content Block inner content.
	 * @param \WP_Block $block Full block instance.
	 *
	 * @return string Rendered block HTML.
	 */
	public function render( array $attributes, string $content, \WP_Block $block ): string {
		return \AMC_Automata_Features\Features\Helpers\Util::templates()->get(
			'block-templates/example-block-dynamic',
			null,
			$attributes
		);
	}
}
