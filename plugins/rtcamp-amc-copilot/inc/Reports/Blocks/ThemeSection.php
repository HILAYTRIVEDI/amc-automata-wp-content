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
			$rows[] = [ $data->label( 'active_theme' ), esc_html( self::theme_label( $active_theme ) ) ];
		} else {
			$rows[] = [ $data->label( 'active_theme' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// parent_theme (object) rendered below if applicable.
		$parent_theme = $data->value( 'parent_theme' );
		if ( is_array( $parent_theme ) && [] !== $parent_theme ) {
			$rows[] = [ $data->label( 'parent_theme' ), esc_html( self::theme_label( $parent_theme ) ) ];
		} else {
			$rows[] = [ $data->label( 'parent_theme' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		$rows[] = [ $data->label( 'theme_origin' ), $this->format_value( $data->value( 'theme_origin' ), $data->type( 'theme_origin' ) ) ];

		return $this->key_value_table( $rows );
	}

	/**
	 * A theme as "Name Version", e.g. "Twenty Twenty-Five 1.5".
	 *
	 * Falls back to the slug when the theme has no name.
	 *
	 * @param array<string, mixed> $theme Theme fact: name, slug, version.
	 */
	private static function theme_label( array $theme ): string {
		$name = trim( (string) ( $theme['name'] ?? '' ) );
		$name = '' !== $name ? $name : trim( (string) ( $theme['slug'] ?? '' ) );

		return trim( $name . ' ' . trim( (string) ( $theme['version'] ?? '' ) ) );
	}
}
