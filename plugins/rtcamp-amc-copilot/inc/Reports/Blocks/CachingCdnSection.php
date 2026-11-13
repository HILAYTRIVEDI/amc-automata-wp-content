<?php
/**
 * Report section block: Caching and CDN.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class CachingCdnSection
 *
 * @since 0.1.0
 */
final class CachingCdnSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'caching_cdn';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		$rows[]               = [ $data->label( 'page_cache' ), $this->format_value( $data->value( 'page_cache' ), $data->type( 'page_cache' ) ) ];
		$rows[]               = [ $data->label( 'object_cache' ), $this->format_value( $data->value( 'object_cache' ), $data->type( 'object_cache' ) ) ];
		$rows[]               = [ $data->label( 'cdn' ), $this->format_value( $data->value( 'cdn' ), $data->type( 'cdn' ) ) ];
		$configured_correctly = $data->engineer( 'configured_correctly' );
		$rows[]               = [ $data->label( 'configured_correctly' ), '' !== $configured_correctly ? $configured_correctly : esc_html__( '—', 'rtcamp-amc-copilot' ) ];

		return $this->key_value_table( $rows );
	}
}
