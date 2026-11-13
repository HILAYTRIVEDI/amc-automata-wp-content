<?php
/**
 * Report section block: SSL.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class SslSection
 *
 * @since 0.1.0
 */
final class SslSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'ssl';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		$rows[] = [ $data->label( 'https' ), $this->format_value( $data->value( 'https' ), $data->type( 'https' ) ) ];
		$rows[] = [ $data->label( 'issuer' ), $this->format_value( $data->value( 'issuer' ), $data->type( 'issuer' ) ) ];
		$rows[] = [ $data->label( 'expires_at' ), $this->format_value( $data->value( 'expires_at' ), $data->type( 'expires_at' ) ) ];
		$rows[] = [ $data->label( 'days_left' ), $this->format_value( $data->value( 'days_left' ), $data->type( 'days_left' ) ) ];
		$rows[] = [ $data->label( 'ssl_type' ), $this->format_value( $data->value( 'ssl_type' ), $data->type( 'ssl_type' ) ) ];
		$rows[] = [ $data->label( 'ssl_auto_renewal' ), $this->format_value( $data->value( 'ssl_auto_renewal' ), $data->type( 'ssl_auto_renewal' ) ) ];

		return $this->key_value_table( $rows );
	}
}
