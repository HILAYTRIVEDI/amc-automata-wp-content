<?php
/**
 * Report section block: Plugins and themes.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class PluginsThemesSection
 *
 * @since 0.1.0
 */
final class PluginsThemesSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'plugins_themes';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		// counts (object) rendered below if applicable.
		$counts = $data->value( 'counts' );
		if ( is_array( $counts ) && [] !== $counts ) {
			$rows[] = [ $data->label( 'counts' ), esc_html( wp_json_encode( $counts ) ) ];
		} else {
			$rows[] = [ $data->label( 'counts' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// updated (list) rendered below if applicable.
		$updated = $data->value( 'updated' );
		if ( is_array( $updated ) && [] !== $updated ) {
			$rows[] = [ $data->label( 'updated' ), esc_html( wp_json_encode( $updated ) ) ];
		} else {
			$rows[] = [ $data->label( 'updated' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// added (list) rendered below if applicable.
		$added = $data->value( 'added' );
		if ( is_array( $added ) && [] !== $added ) {
			$rows[] = [ $data->label( 'added' ), esc_html( wp_json_encode( $added ) ) ];
		} else {
			$rows[] = [ $data->label( 'added' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// removed (list) rendered below if applicable.
		$removed = $data->value( 'removed' );
		if ( is_array( $removed ) && [] !== $removed ) {
			$rows[] = [ $data->label( 'removed' ), esc_html( wp_json_encode( $removed ) ) ];
		} else {
			$rows[] = [ $data->label( 'removed' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// inactive (list) rendered below if applicable.
		$inactive = $data->value( 'inactive' );
		if ( is_array( $inactive ) && [] !== $inactive ) {
			$rows[] = [ $data->label( 'inactive' ), esc_html( wp_json_encode( $inactive ) ) ];
		} else {
			$rows[] = [ $data->label( 'inactive' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		$change_reasons = $data->engineer( 'change_reasons' );
		$rows[]         = [ $data->label( 'change_reasons' ), '' !== $change_reasons ? $change_reasons : esc_html__( '—', 'rtcamp-amc-copilot' ) ];

		return $this->key_value_table( $rows );
	}
}
