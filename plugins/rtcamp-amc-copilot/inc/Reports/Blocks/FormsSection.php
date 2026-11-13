<?php
/**
 * Report section block: Forms.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class FormsSection
 *
 * @since 0.1.0
 */
final class FormsSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'forms';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		// form_plugins (list) rendered below if applicable.
		$form_plugins = $data->value( 'form_plugins' );
		if ( is_array( $form_plugins ) && [] !== $form_plugins ) {
			$rows[] = [ $data->label( 'form_plugins' ), esc_html( wp_json_encode( $form_plugins ) ) ];
		} else {
			$rows[] = [ $data->label( 'form_plugins' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		$rows[] = [ $data->label( 'sending_method' ), $this->format_value( $data->value( 'sending_method' ), $data->type( 'sending_method' ) ) ];
		// integrations (list) rendered below if applicable.
		$integrations = $data->value( 'integrations' );
		if ( is_array( $integrations ) && [] !== $integrations ) {
			$rows[] = [ $data->label( 'integrations' ), esc_html( wp_json_encode( $integrations ) ) ];
		} else {
			$rows[] = [ $data->label( 'integrations' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		$entries_saving          = $data->engineer( 'entries_saving' );
		$rows[]                  = [ $data->label( 'entries_saving' ), '' !== $entries_saving ? $entries_saving : esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		$notifications_confirmed = $data->engineer( 'notifications_confirmed' );
		$rows[]                  = [ $data->label( 'notifications_confirmed' ), '' !== $notifications_confirmed ? $notifications_confirmed : esc_html__( '—', 'rtcamp-amc-copilot' ) ];

		return $this->key_value_table( $rows );
	}
}
