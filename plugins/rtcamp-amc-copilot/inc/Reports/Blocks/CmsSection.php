<?php
/**
 * Report section block: CMS.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class CmsSection
 *
 * @since 0.1.0
 */
final class CmsSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'cms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		$rows[] = [ $data->label( 'wp_version_old' ), $this->format_value( $data->value( 'wp_version_old' ), $data->type( 'wp_version_old' ) ) ];
		$rows[] = [ $data->label( 'wp_version_new' ), $this->format_value( $data->value( 'wp_version_new' ), $data->type( 'wp_version_new' ) ) ];
		$rows[] = [ $data->label( 'editor_type' ), $this->format_value( $data->value( 'editor_type' ), $data->type( 'editor_type' ) ) ];
		$rows[] = [ $data->label( 'is_multisite' ), $this->format_value( $data->value( 'is_multisite' ), $data->type( 'is_multisite' ) ) ];
		$rows[] = [ $data->label( 'websites_covered' ), $this->format_value( $data->value( 'websites_covered' ), $data->type( 'websites_covered' ) ) ];

		return $this->key_value_table( $rows );
	}
}
