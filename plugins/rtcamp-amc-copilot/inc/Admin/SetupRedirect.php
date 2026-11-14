<?php
/**
 * Sends the activating user to the setup wizard once.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Admin;

use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class SetupRedirect
 *
 * The standard activation-redirect pattern:
 *
 * 1. On `amc_activate`, store the activating user in a short-lived transient,
 *    unless the activation is a bulk one, network-wide, from WP-CLI or AJAX.
 * 2. On the next `admin_init`, delete the transient and, when the same user
 *    is on a normal single-site admin request and setup is not finished,
 *    redirect to `?page=amc-copilot&screen=setup`.
 *
 * The transient is deleted before any other test, so the redirect happens at
 * most once even when it is skipped.
 *
 * @since 0.1.0
 */
final class SetupRedirect implements Registrable {

	/**
	 * Seconds the activation transient lives.
	 */
	public const TTL = 60;

	/**
	 * Whether the request comes from WP-CLI.
	 *
	 * @var callable(): bool
	 */
	private $is_cli;

	/**
	 * Constructor.
	 *
	 * @param callable|null $is_cli Test seam; defaults to checking the WP_CLI constant.
	 */
	public function __construct( ?callable $is_cli = null ) {
		$this->is_cli = $is_cli ?? static fn (): bool => defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'amc_activate', [ $this, 'queue' ] );
		add_action( 'admin_init', [ $this, 'maybe_redirect' ], 1 );
	}

	/**
	 * Activation: remember who to send to the wizard.
	 */
	public function queue(): void {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 || $this->is_skipped_request() || $this->is_bulk_activation() || SetupState::is_completed() ) {
			return;
		}

		set_transient( SetupState::REDIRECT_TRANSIENT, $user_id, self::TTL );
	}

	/**
	 * Whether a redirect is waiting for the next admin page load.
	 */
	public static function is_queued(): bool {
		return (int) get_transient( SetupState::REDIRECT_TRANSIENT ) > 0;
	}

	/**
	 * Where to redirect this request to, consuming the transient.
	 *
	 * @return string|null Wizard URL, or null for no redirect.
	 */
	public function target(): ?string {
		$queued_for = (int) get_transient( SetupState::REDIRECT_TRANSIENT );

		if ( $queued_for <= 0 ) {
			return null;
		}

		// Once only, whatever happens next.
		delete_transient( SetupState::REDIRECT_TRANSIENT );

		if (
			$this->is_skipped_request()
			|| $this->is_bulk_activation()
			|| SetupState::is_completed()
			|| get_current_user_id() !== $queued_for
			|| ! SetupState::can_run( $queued_for )
		) {
			return null;
		}

		return ConsolePage::url( SetupState::SCREEN );
	}

	/**
	 * `admin_init`: redirect when one is due.
	 */
	public function maybe_redirect(): void {
		$target = $this->target();

		if ( null === $target ) {
			return;
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Requests that never redirect: network admin, AJAX, WP-CLI, cron, REST.
	 */
	private function is_skipped_request(): bool {
		return ( $this->is_cli )()
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| is_network_admin()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Whether the plugin is being activated together with others.
	 *
	 * During the request `action=activate-selected`; on the page WordPress
	 * redirects to afterwards, `activate-multi`.
	 */
	private function is_bulk_activation(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only check of where the request came from; core verified the nonce.
		if ( isset( $_GET['activate-multi'] ) ) {
			return true;
		}

		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'activate-selected' === $action;
	}
}
