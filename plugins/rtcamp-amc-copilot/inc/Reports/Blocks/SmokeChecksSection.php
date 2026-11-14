<?php
/**
 * Report section block: Smoke checks.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class SmokeChecksSection
 *
 * @since 0.1.0
 */
final class SmokeChecksSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'smoke_checks';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		// urls (list) rendered below if applicable.
		$urls = $data->value( 'urls' );
		if ( is_array( $urls ) ) {
			$rows[] = [ $data->label( 'urls' ), $this->format_structured( $urls ) ];
		} else {
			$rows[] = [ $data->label( 'urls' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}

		return $this->key_value_table( $rows );
	}
}
