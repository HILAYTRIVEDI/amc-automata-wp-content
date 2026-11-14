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
		if ( is_array( $counts ) ) {
			$rows[] = [ $data->label( 'counts' ), $this->format_structured( $counts ) ];
		} else {
			$rows[] = [ $data->label( 'counts' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// updated (list) rendered below if applicable.
		$updated = $data->value( 'updated' );
		if ( is_array( $updated ) ) {
			$rows[] = [ $data->label( 'updated' ), $this->format_structured( $updated ) ];
		} else {
			$rows[] = [ $data->label( 'updated' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// added (list) rendered below if applicable.
		$added = $data->value( 'added' );
		if ( is_array( $added ) ) {
			$rows[] = [ $data->label( 'added' ), $this->format_structured( $added ) ];
		} else {
			$rows[] = [ $data->label( 'added' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// removed (list) rendered below if applicable.
		$removed = $data->value( 'removed' );
		if ( is_array( $removed ) ) {
			$rows[] = [ $data->label( 'removed' ), $this->format_structured( $removed ) ];
		} else {
			$rows[] = [ $data->label( 'removed' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// inactive (list) rendered below if applicable.
		$inactive = $data->value( 'inactive' );
		if ( is_array( $inactive ) ) {
			$rows[] = [ $data->label( 'inactive' ), $this->format_structured( $inactive ) ];
		} else {
			$rows[] = [ $data->label( 'inactive' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		$change_reasons = $data->engineer( 'change_reasons' );
		$rows[]         = [ $data->label( 'change_reasons' ), '' !== $change_reasons ? $change_reasons : esc_html__( '—', 'rtcamp-amc-copilot' ) ];

		return $this->key_value_table( $rows );
	}
}
