<?php
/**
 * Report section block: Accessibility.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

use AMC\Core\Settings;

/**
 * Class AccessibilitySection
 *
 * @since 0.1.0
 */
final class AccessibilitySection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'accessibility';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		$rows[]      = [ $data->label( 'third_party_tool' ), $this->format_value( $data->value( 'third_party_tool' ), $data->type( 'third_party_tool' ) ) ];
		$rows[]      = [ $data->label( 'statement_page' ), $this->format_value( $data->value( 'statement_page' ), $data->type( 'statement_page' ) ) ];
		$rows[]      = [ $data->label( 'defined_standard' ), $this->format_value( $data->value( 'defined_standard' ), $data->type( 'defined_standard' ) ) ];
		$wave_result = $data->engineer( 'wave_result' );
		$rows[]      = [ $data->label( 'wave_result' ), '' !== $wave_result ? $wave_result : esc_html__( '—', 'rtcamp-amc-copilot' ) ];

		$html = $this->key_value_table( $rows );
		$disc = (string) ( Settings::get_value( 'disclaimers' )['accessibility'] ?? '' );
		if ( '' === $disc ) {
			$disc = 'Accessibility is an ongoing process. This report records the checks performed in the period and is not a certification of conformance with any accessibility standard.';
		}
		return $html . '<p class="amc-disclaimer">' . wp_kses_post( $disc ) . '</p>';
	}
}
