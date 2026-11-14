<?php
/**
 * Report block: Custom section.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractBlock;

/**
 * Class CustomSectionBlock
 *
 * Block: amc/custom-section (source: src/blocks/custom-section/). A title plus
 * free inner blocks that an engineer inserts anywhere in a report. Unlike the
 * section blocks it is static: the editor saves its markup, so rendering just
 * returns the saved content. Registering it from PHP is what loads its editor
 * script and makes it insertable.
 *
 * @since 0.1.0
 */
final class CustomSectionBlock extends AbstractBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function get_name(): string {
		return 'amc/custom-section';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_block_dir(): string {
		return AMC_COPILOT_PATH . 'assets/build/blocks/custom-section';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Attributes mirror block.json, for registration without a build (PHPUnit).
	 *
	 * @return array<string, mixed>
	 */
	protected function get_block_args(): array {
		return [
			'api_version' => 3,
			'attributes'  => [
				'title'        => [
					'type'    => 'string',
					'default' => '',
				],
				'carryForward' => [
					'type'    => 'boolean',
					'default' => false,
				],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $attributes Block attributes (unused: static block).
	 * @param string               $content    Saved block markup.
	 * @param \WP_Block            $block      Block instance (unused).
	 */
	public function render( array $attributes, string $content, \WP_Block $block ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Contract signature.
		return $content;
	}
}
