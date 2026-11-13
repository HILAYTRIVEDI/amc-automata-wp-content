<?php
/**
 * Example post type.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules\PostTypes;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractPostType;

/**
 * Class - ExamplePostType
 */
final class ExamplePostType extends AbstractPostType {

	/**
	 * {@inheritDoc}
	 */
	public static function get_slug(): string {
		return 'post-type-slug';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_singular_label(): string {
		return __( 'Post Type Label', 'amc-automata-features-features' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_plural_label(): string {
		return __( 'Post Type Labels', 'amc-automata-features-features' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_menu_icon(): string {
		return 'dashicons-id';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_editor_supports(): array {
		return array_merge( parent::get_editor_supports(), [ 'custom-fields' ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_supported_taxonomies(): array {
		return [ 'taxonomy-slug' ];
	}
}
