<?php
/**
 * Report section block: Analytics and tagging.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class AnalyticsSection
 *
 * @since 0.1.0
 */
final class AnalyticsSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'analytics';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		// gtm_ids (list) rendered below if applicable.
		$gtm_ids = $data->value( 'gtm_ids' );
		if ( is_array( $gtm_ids ) ) {
			$rows[] = [ $data->label( 'gtm_ids' ), $this->format_structured( $gtm_ids ) ];
		} else {
			$rows[] = [ $data->label( 'gtm_ids' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// ga_ids (list) rendered below if applicable.
		$ga_ids = $data->value( 'ga_ids' );
		if ( is_array( $ga_ids ) ) {
			$rows[] = [ $data->label( 'ga_ids' ), $this->format_structured( $ga_ids ) ];
		} else {
			$rows[] = [ $data->label( 'ga_ids' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		$tag_assistant = $data->engineer( 'tag_assistant' );
		$rows[]        = [ $data->label( 'tag_assistant' ), '' !== $tag_assistant ? $tag_assistant : esc_html__( '—', 'rtcamp-amc-copilot' ) ];

		return $this->key_value_table( $rows );
	}
}
