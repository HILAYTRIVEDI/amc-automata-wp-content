<?php
/**
 * Report section block: Performance.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class PerformanceSection
 *
 * @since 0.1.0
 */
final class PerformanceSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'performance';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		// response_trend (list) rendered below if applicable.
		$response_trend = $data->value( 'response_trend' );
		if ( is_array( $response_trend ) && [] !== $response_trend ) {
			$rows[] = [ $data->label( 'response_trend' ), esc_html( wp_json_encode( $response_trend ) ) ];
		} else {
			$rows[] = [ $data->label( 'response_trend' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		$rows[] = [ $data->label( 'database_size_bytes' ), $this->format_value( $data->value( 'database_size_bytes' ), $data->type( 'database_size_bytes' ) ) ];
		$rows[] = [ $data->label( 'autoload_size_bytes' ), $this->format_value( $data->value( 'autoload_size_bytes' ), $data->type( 'autoload_size_bytes' ) ) ];

		return $this->key_value_table( $rows );
	}
}
