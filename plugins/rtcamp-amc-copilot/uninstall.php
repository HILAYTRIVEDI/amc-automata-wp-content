<?php
/**
 * Uninstall AMC Copilot: drop tables, delete options, remove capabilities.
 *
 * Reports (amc_report posts) are kept unless the `amc_delete_reports_on_uninstall`
 * option is true (lane D1 adds the "Delete reports" checkbox).
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

if ( get_option( 'amc_delete_reports_on_uninstall' ) ) {
	$amc_report_ids = get_posts(
		[
			'post_type'      => 'amc_report',
			'post_status'    => 'any',
			'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- One-off uninstall.
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]
	);
	foreach ( $amc_report_ids as $amc_report_id ) {
		wp_delete_post( (int) $amc_report_id, true );
	}
}

\AMC\Core\Capabilities::remove_all();
\AMC\Core\Installer::uninstall();

foreach ( [ 'amc_settings', 'amc_saved_fields', 'amc_version', 'amc_delete_reports_on_uninstall' ] as $amc_option ) {
	delete_option( $amc_option );
}
