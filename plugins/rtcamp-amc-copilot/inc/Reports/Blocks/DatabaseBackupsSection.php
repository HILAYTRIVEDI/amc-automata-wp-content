<?php
/**
 * Report section block: Database backups.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

use AMC\Core\Settings;

/**
 * Class DatabaseBackupsSection
 *
 * @since 0.1.0
 */
final class DatabaseBackupsSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'database_backups';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		$rows[]          = [ $data->label( 'backup_method' ), $this->format_value( $data->value( 'backup_method' ), $data->type( 'backup_method' ) ) ];
		$rows[]          = [ $data->label( 'backup_schedule' ), $this->format_value( $data->value( 'backup_schedule' ), $data->type( 'backup_schedule' ) ) ];
		$rows[]          = [ $data->label( 'backup_retention' ), $this->format_value( $data->value( 'backup_retention' ), $data->type( 'backup_retention' ) ) ];
		$backups_working = $data->engineer( 'backups_working' );
		$rows[]          = [ $data->label( 'backups_working' ), '' !== $backups_working ? $backups_working : esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		$latest_backup   = $data->engineer( 'latest_backup' );
		$rows[]          = [ $data->label( 'latest_backup' ), '' !== $latest_backup ? $latest_backup : esc_html__( '—', 'rtcamp-amc-copilot' ) ];

		$html = $this->key_value_table( $rows );
		$disc = (string) ( Settings::get_value( 'disclaimers' )['backups'] ?? '' );
		if ( '' === $disc ) {
			$disc = 'Database backups are taken automatically and stored off-site. We verify that the latest backup completed and that restore points exist for the period covered by this report.';
		}
		return $html . '<p class="amc-disclaimer">' . wp_kses_post( $disc ) . '</p>';
	}
}
