<?php
/**
 * Report section block: Overview.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class OverviewSection
 *
 * ==== EXEMPLAR SECTION BLOCK: copy this file for each new section. ====
 *
 * Block: amc/section-overview (source: src/blocks/section-overview/).
 * Fields (SnapshotSchema 'overview'):
 *   auto      website_url, developer_name, report_date
 *   saved     client_name, maintenance_schedule
 *   engineer  hours_used
 *
 * To make a new section:
 *   1. Copy this class, rename it, change section_key() to the schema key.
 *   2. List the fields in render_section() in the order of the rtCamp template.
 *   3. Copy src/blocks/section-overview/ to src/blocks/section-<slug>/ and
 *      update block.json (name, title) and edit.js (FIELDS).
 *   4. Add the class to SectionBlocksModule and the block to
 *      ReportPostType::template() (lane D1 merges template order).
 *   5. Copy tests/php/Reports/Blocks/OverviewSectionTest.php.
 *
 * @since 0.1.0
 */
final class OverviewSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'overview';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		// Auto and saved fields: effective value (override wins), formatted by type.
		foreach ( [ 'client_name', 'website_url', 'developer_name', 'report_date', 'maintenance_schedule' ] as $field ) {
			$rows[] = [ $data->label( $field ), $this->format_value( $data->value( $field ), $data->type( $field ) ) ];
		}

		// Engineer field: HTML typed in the editor with RichText.
		$hours  = $data->engineer( 'hours_used' );
		$rows[] = [ $data->label( 'hours_used' ), '' !== $hours ? $hours : esc_html__( '—', 'rtcamp-amc-copilot' ) ];

		return $this->key_value_table( $rows );
	}
}
