<?php
/**
 * Report section block: Theme.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class ThemeSection
 *
 * @since 0.1.0
 */
final class ThemeSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'theme';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		// active_theme (object) rendered below if applicable.
		$active_theme = $data->value( 'active_theme' );
		if ( is_array( $active_theme ) && [] !== $active_theme ) {
			$rows[] = [ $data->label( 'active_theme' ), esc_html( wp_json_encode( $active_theme ) ) ];
		} else {
			$rows[] = [ $data->label( 'active_theme' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// parent_theme (object) rendered below if applicable.
		$parent_theme = $data->value( 'parent_theme' );
		if ( is_array( $parent_theme ) && [] !== $parent_theme ) {
			$rows[] = [ $data->label( 'parent_theme' ), esc_html( wp_json_encode( $parent_theme ) ) ];
		} else {
			$rows[] = [ $data->label( 'parent_theme' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		$rows[] = [ $data->label( 'theme_origin' ), $this->format_value( $data->value( 'theme_origin' ), $data->type( 'theme_origin' ) ) ];

		return $this->key_value_table( $rows );
	}
}
