<?php
/**
 * Immediate alerts: listens to finished runs and emails new findings.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Notify;

use AMC\Core\KillSwitch;
use AMC\Core\Settings;
use AMC\Runner\ProcessedFindings;
use AMC\Storage\FindingsRepository;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class Alerts
 *
 * On `amc_findings_processed`, takes the NEW findings of the run and sends
 * the ones AlertRules routes to 'immediate' in one email.
 *
 * - Alert once: a finding is emailed only while `alerted_at` is NULL, and is
 *   marked alerted through FindingsRepository::mark_alerted() after the mail
 *   was accepted. A finding that resolves and comes back is a NEW row (the
 *   runner's rule), so it alerts again.
 * - Throttle: at most `immediate_alerts_per_hour` emails per site per hour
 *   (Throttle). Over the cap, nothing is sent and `alerted_at` stays NULL, so
 *   the finding goes out in the next digest.
 * - Accepted or muted findings and `none` routes never email.
 *
 * @since 0.1.0
 */
final class Alerts implements Registrable {

	/**
	 * Constructor.
	 *
	 * @param Throttle|null           $throttle Hourly cap.
	 * @param FindingsRepository|null $findings Findings repository.
	 */
	public function __construct(
		private ?Throttle $throttle = null,
		private ?FindingsRepository $findings = null
	) {
		$this->throttle ??= new Throttle();
		$this->findings ??= new FindingsRepository();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'amc_findings_processed', [ $this, 'on_findings' ] );

		// uninstall.php fires amc_deactivate; the hourly counter is throwaway data.
		add_action( 'amc_deactivate', [ $this->throttle, 'reset' ] );
	}

	/**
	 * `amc_findings_processed` callback.
	 *
	 * @param ProcessedFindings $processed Payload.
	 */
	public function on_findings( ProcessedFindings $processed ): void {
		$this->alert( $processed );
	}

	/**
	 * Send the immediate alert for a processed run.
	 *
	 * @param ProcessedFindings $processed Payload.
	 *
	 * @return bool Whether an email was sent.
	 */
	public function alert( ProcessedFindings $processed ): bool {
		if ( [] === $processed->new || KillSwitch::is_active() ) {
			return false;
		}

		$alerts = [];
		foreach ( $processed->new as $row ) {
			// Read the stored row again: another handler may have alerted it already.
			$fresh = $this->findings->find( (int) $row['id'] );

			if ( null === $fresh || null !== $fresh['alerted_at'] || AlertRules::IMMEDIATE !== AlertRules::route( $fresh ) ) {
				continue;
			}

			$alerts[] = $fresh;
		}

		$recipients = Settings::recipients();

		if ( [] === $alerts || [] === $recipients || ! $this->throttle->acquire() ) {
			return false;
		}

		if ( ! Mailer::send_immediate( $alerts, $recipients ) ) {
			return false;
		}

		$this->findings->mark_alerted( array_map( static fn ( array $row ): int => (int) $row['id'], $alerts ) );

		return true;
	}
}
