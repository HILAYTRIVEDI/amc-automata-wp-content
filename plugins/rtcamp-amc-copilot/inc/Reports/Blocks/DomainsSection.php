<?php
/**
 * Report section block: Domains.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

use AMC\Core\Settings;

/**
 * Class DomainsSection
 *
 * @since 0.1.0
 */
final class DomainsSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'domains';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		$rows[] = [ $data->label( 'primary_domain' ), $this->format_value( $data->value( 'primary_domain' ), $data->type( 'primary_domain' ) ) ];
		$rows[] = [ $data->label( 'domain_expiry' ), $this->format_value( $data->value( 'domain_expiry' ), $data->type( 'domain_expiry' ) ) ];
		$rows[] = [ $data->label( 'auto_renewal' ), $this->format_value( $data->value( 'auto_renewal' ), $data->type( 'auto_renewal' ) ) ];
		$rows[] = [ $data->label( 'subdomains' ), $this->format_value( $data->value( 'subdomains' ), $data->type( 'subdomains' ) ) ];

		$html = $this->key_value_table( $rows );
		$disc = (string) ( Settings::get_value( 'disclaimers' )['domains'] ?? '' );
		if ( '' === $disc ) {
			$disc = 'Domain registration and DNS records are managed by the domain owner. We monitor expiry dates and DNS changes but do not renew or transfer domains on your behalf.';
		}
		return $html . '<p class="amc-disclaimer">' . wp_kses_post( $disc ) . '</p>';
	}
}
