<?php
/**
 * Report section block: Hosting.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

use AMC\Core\Settings;

/**
 * Class HostingSection
 *
 * @since 0.1.0
 */
final class HostingSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'hosting';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		$rows[]                 = [ $data->label( 'host' ), $this->format_value( $data->value( 'host' ), $data->type( 'host' ) ) ];
		$rows[]                 = [ $data->label( 'server_type' ), $this->format_value( $data->value( 'server_type' ), $data->type( 'server_type' ) ) ];
		$rows[]                 = [ $data->label( 'web_server' ), $this->format_value( $data->value( 'web_server' ), $data->type( 'web_server' ) ) ];
		$rows[]                 = [ $data->label( 'mysql_version' ), $this->format_value( $data->value( 'mysql_version' ), $data->type( 'mysql_version' ) ) ];
		$rows[]                 = [ $data->label( 'php_version' ), $this->format_value( $data->value( 'php_version' ), $data->type( 'php_version' ) ) ];
		$rows[]                 = [ $data->label( 'memory_limit' ), $this->format_value( $data->value( 'memory_limit' ), $data->type( 'memory_limit' ) ) ];
		$rows[]                 = [ $data->label( 'php_recommendation' ), $this->format_value( $data->value( 'php_recommendation' ), $data->type( 'php_recommendation' ) ) ];
		$rows[]                 = [ $data->label( 'hosting_type' ), $this->format_value( $data->value( 'hosting_type' ), $data->type( 'hosting_type' ) ) ];
		$rows[]                 = [ $data->label( 'control_panel' ), $this->format_value( $data->value( 'control_panel' ), $data->type( 'control_panel' ) ) ];
		$rows[]                 = [ $data->label( 'server_backup_frequency' ), $this->format_value( $data->value( 'server_backup_frequency' ), $data->type( 'server_backup_frequency' ) ) ];
		$rows[]                 = [ $data->label( 'server_backup_retention' ), $this->format_value( $data->value( 'server_backup_retention' ), $data->type( 'server_backup_retention' ) ) ];
		$rows[]                 = [ $data->label( 'redundancy' ), $this->format_value( $data->value( 'redundancy' ), $data->type( 'redundancy' ) ) ];
		$rows[]                 = [ $data->label( 'account_owners' ), $this->format_value( $data->value( 'account_owners' ), $data->type( 'account_owners' ) ) ];
		$rows[]                 = [ $data->label( 'hosting_users' ), $this->format_value( $data->value( 'hosting_users' ), $data->type( 'hosting_users' ) ) ];
		$disk_space             = $data->engineer( 'disk_space' );
		$rows[]                 = [ $data->label( 'disk_space' ), '' !== $disk_space ? $disk_space : esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		$server_backups_running = $data->engineer( 'server_backups_running' );
		$rows[]                 = [ $data->label( 'server_backups_running' ), '' !== $server_backups_running ? $server_backups_running : esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		$latest_server_backup   = $data->engineer( 'latest_server_backup' );
		$rows[]                 = [ $data->label( 'latest_server_backup' ), '' !== $latest_server_backup ? $latest_server_backup : esc_html__( '—', 'rtcamp-amc-copilot' ) ];

		$html = $this->key_value_table( $rows );
		$disc = (string) ( Settings::get_value( 'disclaimers' )['hosting'] ?? '' );
		if ( '' === $disc ) {
			$disc = 'Hosting infrastructure, network uptime and server-level backups are managed by the hosting provider. This report covers the WordPress installation running on that infrastructure.';
		}
		return $html . '<p class="amc-disclaimer">' . wp_kses_post( $disc ) . '</p>';
	}
}
