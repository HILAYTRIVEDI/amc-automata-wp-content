<?php
/**
 * Post Types module.
 *
 * Groups all custom post type classes so Main is decoupled from individual registrations.
 * To add a new post type: create it in PostTypes/ and add the class-string here.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules;

use AMC_Automata_Features\Features\Modules\PostTypes\ExamplePostType;
use AMC_Automata_Features\Features\Modules\PostTypes\ExamplePostTypeTwo;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class - PostTypes
 */
final class PostTypes extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			ExamplePostType::class,
			ExamplePostTypeTwo::class,
		];
	}
}
