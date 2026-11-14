<?php
/**
 * Dashboard widget: site status at a glance.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Admin;

use AMC\Core\Assets;
use AMC\Core\Brand;
use AMC\Core\Capabilities;
use AMC\Main;
use WP_REST_Request;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class DashboardWidget
 *
 * Owner: lane C. Shows the overall status and the open findings by severity
 * on the WordPress Dashboard, to engineers only. It costs ONE cached REST
 * call: `GET /amc/v1/overview` is dispatched internally and its response is
 * kept in a transient for five minutes (and dropped when a run completes), so
 * a Dashboard load normally runs no check queries at all. Nothing is loaded
 * on the front end.
 *
 * @since 0.1.0
 */
final class DashboardWidget implements Registrable {

	/**
	 * Dashboard widget id.
	 */
	public const WIDGET_ID = 'amc_copilot_overview';

	/**
	 * Transient key prefix (the blog id is appended).
	 */
	public const TRANSIENT = 'amc_dashboard_overview_';

	/**
	 * Cache lifetime in seconds.
	 */
	public const TTL = 300;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'register' ] );
		add_action( 'amc_run_completed', [ $this, 'flush' ] );
	}

	/**
	 * Add the widget for engineers.
	 */
	public function register(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'AMC Copilot', 'rtcamp-amc-copilot' ),
			[ $this, 'render' ]
		);

		self::enqueue_styles();
	}

	/**
	 * Brand type and colours for the widget (wp_dashboard_setup runs before
	 * the admin header prints styles).
	 */
	private static function enqueue_styles(): void {
		Brand::enqueue_fonts();

		/**
		 * Shared Assets service.
		 *
		 * @var Assets $assets
		 */
		$assets = Main::get_instance()->get_shared( Assets::class );

		if ( $assets->has_asset( 'css/admin', 'css' ) ) {
			$assets->register_style( 'amc-admin-brand', 'css/admin' );
			wp_enqueue_style( 'amc-admin-brand' );
		}
	}

	/**
	 * Drop the cached overview (a run finished, so it is stale).
	 */
	public function flush(): void {
		delete_transient( self::cache_key() );
	}

	/**
	 * The overview, from the cache or one internal REST dispatch.
	 *
	 * @return array<string, mixed>|null Overview, or null when the request failed.
	 */
	public function overview(): ?array {
		$cached = get_transient( self::cache_key() );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = rest_do_request( new WP_REST_Request( 'GET', '/amc/v1/overview' ) );

		if ( $response->is_error() ) {
			return null;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) ) {
			return null;
		}

		set_transient( self::cache_key(), $data, self::TTL );

		return $data;
	}

	/**
	 * Widget body. Status is an icon plus a text label, never colour alone.
	 */
	public function render(): void {
		$overview = $this->overview();

		if ( null === $overview ) {
			echo '<p>' . esc_html__( 'The status could not be loaded.', 'rtcamp-amc-copilot' ) . '</p>';
			$this->render_link( ConsolePage::url(), __( 'Open the console', 'rtcamp-amc-copilot' ) );
			return;
		}

		$status = (string) ( $overview['status'] ?? 'info' );

		if ( ! empty( $overview['kill_switch'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The kill switch is on: capture and scheduled checks are stopped.', 'rtcamp-amc-copilot' ) . '</p></div>';
		}

		printf(
			'<p><span class="dashicons %1$s" aria-hidden="true"></span> <strong>%2$s</strong> %3$s</p>',
			esc_attr( self::status_icon( $status ) ),
			esc_html__( 'Status:', 'rtcamp-amc-copilot' ),
			esc_html( self::status_label( $status ) )
		);

		$this->render_severities( (array) ( $overview['findings_by_severity'] ?? [] ) );

		$last_run = self::last_run_timestamp( (array) ( $overview['groups'] ?? [] ) );

		if ( null !== $last_run ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: time difference, e.g. "2 hours". */
						__( 'Last check ran %s ago.', 'rtcamp-amc-copilot' ),
						human_time_diff( $last_run )
					)
				)
			);
		}

		$this->render_link( ConsolePage::url(), __( 'Open the console', 'rtcamp-amc-copilot' ) );
	}

	/**
	 * Open findings by severity as a core table.
	 *
	 * @param array<string, mixed> $counts Counts by severity.
	 */
	private function render_severities( array $counts ): void {
		$labels = [
			'critical' => __( 'Critical', 'rtcamp-amc-copilot' ),
			'high'     => __( 'High', 'rtcamp-amc-copilot' ),
			'medium'   => __( 'Medium', 'rtcamp-amc-copilot' ),
			'low'      => __( 'Low', 'rtcamp-amc-copilot' ),
			'info'     => __( 'Info', 'rtcamp-amc-copilot' ),
		];

		echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Open findings by severity', 'rtcamp-amc-copilot' ) . '</caption><tbody>';

		foreach ( $labels as $severity => $label ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$d</td></tr>',
				esc_html( $label ),
				(int) ( $counts[ $severity ] ?? 0 )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * A primary link button.
	 *
	 * @param string $url   URL.
	 * @param string $label Text.
	 */
	private function render_link( string $url, string $label ): void {
		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Newest `last_run_at` across the groups.
	 *
	 * @param array<int, mixed> $groups Overview groups.
	 *
	 * @return int|null Unix timestamp, or null when nothing has run.
	 */
	private static function last_run_timestamp( array $groups ): ?int {
		$newest = null;

		foreach ( $groups as $group ) {
			$time = is_array( $group ) && is_string( $group['last_run_at'] ?? null ) ? strtotime( $group['last_run_at'] ) : false;

			if ( false !== $time && ( null === $newest || $time > $newest ) ) {
				$newest = $time;
			}
		}

		return $newest;
	}

	/**
	 * Dashicon for a status.
	 *
	 * @param string $status pass|warn|fail|info.
	 */
	private static function status_icon( string $status ): string {
		return [
			'pass' => 'dashicons-yes-alt',
			'warn' => 'dashicons-warning',
			'fail' => 'dashicons-dismiss',
		][ $status ] ?? 'dashicons-info';
	}

	/**
	 * Label for a status (matches the console's Pass / Warning / Failed).
	 *
	 * @param string $status pass|warn|fail|info.
	 */
	private static function status_label( string $status ): string {
		return [
			'pass' => __( 'Pass', 'rtcamp-amc-copilot' ),
			'warn' => __( 'Warning', 'rtcamp-amc-copilot' ),
			'fail' => __( 'Failed', 'rtcamp-amc-copilot' ),
		][ $status ] ?? __( 'Info', 'rtcamp-amc-copilot' );
	}

	/**
	 * Transient key for this site.
	 */
	private static function cache_key(): string {
		return self::TRANSIENT . get_current_blog_id();
	}
}
