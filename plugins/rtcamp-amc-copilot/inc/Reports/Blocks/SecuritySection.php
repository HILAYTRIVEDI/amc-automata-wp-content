<?php
/**
 * Report section block: Security.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class SecuritySection
 *
 * @since 0.1.0
 */
final class SecuritySection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'security';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		// vulnerabilities (list) rendered below if applicable.
		$vulnerabilities = $data->value( 'vulnerabilities' );
		if ( is_array( $vulnerabilities ) && [] !== $vulnerabilities ) {
			$rows[] = [ $data->label( 'vulnerabilities' ), esc_html( wp_json_encode( $vulnerabilities ) ) ];
		} else {
			$rows[] = [ $data->label( 'vulnerabilities' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// core_checksums (object) rendered below if applicable.
		$core_checksums = $data->value( 'core_checksums' );
		if ( is_array( $core_checksums ) && [] !== $core_checksums ) {
			$rows[] = [ $data->label( 'core_checksums' ), esc_html( wp_json_encode( $core_checksums ) ) ];
		} else {
			$rows[] = [ $data->label( 'core_checksums' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// config_checks (list) rendered below if applicable.
		$config_checks = $data->value( 'config_checks' );
		if ( is_array( $config_checks ) && [] !== $config_checks ) {
			$rows[] = [ $data->label( 'config_checks' ), esc_html( wp_json_encode( $config_checks ) ) ];
		} else {
			$rows[] = [ $data->label( 'config_checks' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		$remediation_notes = $data->engineer( 'remediation_notes' );
		$rows[]            = [ $data->label( 'remediation_notes' ), '' !== $remediation_notes ? $remediation_notes : esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		$malware_scan      = $data->engineer( 'malware_scan' );
		$rows[]            = [ $data->label( 'malware_scan' ), '' !== $malware_scan ? $malware_scan : esc_html__( '—', 'rtcamp-amc-copilot' ) ];

		return $this->key_value_table( $rows );
	}
}
