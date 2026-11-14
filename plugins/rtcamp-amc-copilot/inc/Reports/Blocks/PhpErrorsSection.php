<?php
/**
 * Report section block: PHP errors.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class PhpErrorsSection
 *
 * @since 0.1.0
 */
final class PhpErrorsSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'php_errors';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		$rows[] = [ $data->label( 'fatal_count' ), $this->format_value( $data->value( 'fatal_count' ), $data->type( 'fatal_count' ) ) ];
		$rows[] = [ $data->label( 'warning_count' ), $this->format_value( $data->value( 'warning_count' ), $data->type( 'warning_count' ) ) ];
		$rows[] = [ $data->label( 'deprecation_count' ), $this->format_value( $data->value( 'deprecation_count' ), $data->type( 'deprecation_count' ) ) ];
		// top_signatures (list) rendered below if applicable.
		$top_signatures = $data->value( 'top_signatures' );
		if ( is_array( $top_signatures ) ) {
			$rows[] = [ $data->label( 'top_signatures' ), $this->format_structured( $top_signatures ) ];
		} else {
			$rows[] = [ $data->label( 'top_signatures' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}

		return $this->key_value_table( $rows );
	}
}
