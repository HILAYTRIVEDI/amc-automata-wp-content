<?php
/**
 * Example taxonomy.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules\Taxonomies;

use AMC_Automata_Features\Features\Modules\PostTypes\ExamplePostType;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractTaxonomy;

/**
 * Class - ExampleTaxonomy
 */
final class ExampleTaxonomy extends AbstractTaxonomy {

	/**
	 * {@inheritDoc}
	 */
	public static function get_slug(): string {
		return 'taxonomy-slug';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_object_types(): array {
		return [ ExamplePostType::get_slug() ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_singular_label(): string {
		return __( 'Taxonomy Label', 'amc-automata-features-features' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_plural_label(): string {
		return __( 'Taxonomy Labels', 'amc-automata-features-features' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_hierarchical(): bool {
		return true;
	}
}
