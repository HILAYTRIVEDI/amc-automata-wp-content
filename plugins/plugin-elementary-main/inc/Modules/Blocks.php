<?php
/**
 * Blocks module.
 *
 * Groups all dynamic block classes (those with server-side rendering).
 * Static blocks (no render_callback) are registered via Assets::register_blocks().
 * To add a new dynamic block: create it in Blocks/ and add the class-string here.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules;

use AMC_Automata_Features\Features\Modules\Blocks\ExampleDynamicBlock;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class - Blocks
 */
final class Blocks extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			ExampleDynamicBlock::class,
		];
	}
}
