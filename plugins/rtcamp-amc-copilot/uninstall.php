<?php
/**
 * Uninstall AMC Copilot: drop tables, delete options, remove capabilities.
 *
 * Reports (amc_report posts) are kept unless the `amc_delete_reports_on_uninstall`
 * option is true (Settings → General → Uninstall, "Delete reports").
 *
 * @package AMC
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

if ( ! defined( 'AMC_COPILOT_FILE' ) ) {
	define( 'AMC_COPILOT_FILE', __DIR__ . '/rtcamp-amc-copilot.php' );
}

\AMC\Runner\Scheduler::unschedule();

/** This action is documented in inc/Core/PluginSetup.php */
do_action( 'amc_deactivate' );

$amc_clean_site = static function (): void {
	if ( get_option( 'amc_delete_reports_on_uninstall' ) ) {
		// In batches until none are left, so a long-running site loses them all.
		do {
			$amc_report_ids = get_posts(
				[
					'post_type'      => 'amc_report',
					'post_status'    => array_keys( get_post_stati() ), // 'any' skips trashed reports.
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			);
			foreach ( $amc_report_ids as $amc_report_id ) {
				wp_delete_post( (int) $amc_report_id, true );
			}
		} while ( [] !== $amc_report_ids );
	}

	foreach ( [ 'amc_settings', 'amc_saved_fields', 'amc_version', 'amc_delete_reports_on_uninstall', 'amc_setup_completed' ] as $amc_option ) {
		delete_option( $amc_option );
	}

	delete_transient( 'amc_activation_redirect' );
};

if ( is_multisite() ) {
	$amc_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);
	foreach ( $amc_site_ids as $amc_site_id ) {
		switch_to_blog( (int) $amc_site_id );
		$amc_clean_site();
		restore_current_blog();
	}
} else {
	$amc_clean_site();
}

\AMC\Core\Capabilities::remove_all();
\AMC\Core\Installer::uninstall();

delete_metadata( 'user', 0, 'amc_setup_notice_dismissed', '', true );
