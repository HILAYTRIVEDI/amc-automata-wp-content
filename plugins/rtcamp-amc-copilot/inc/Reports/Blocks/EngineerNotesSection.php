<?php
/**
 * Report section block: Engineer's notes.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class EngineerNotesSection
 *
 * @since 0.1.0
 */
final class EngineerNotesSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'engineer_notes';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		$note   = $data->engineer( 'note' );
		$rows[] = [ $data->label( 'note' ), '' !== $note ? $note : esc_html__( '—', 'rtcamp-amc-copilot' ) ];

		return $this->key_value_table( $rows );
	}
}
