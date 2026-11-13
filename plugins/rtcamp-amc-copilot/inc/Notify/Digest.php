<?php
/**
 * The daily digest email.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Notify;

use AMC\Checks\Severity;
use AMC\Core\KillSwitch;
use AMC\Core\Settings;
use AMC\Runner\Lock;
use AMC\Storage\FindingsRepository;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class Digest
 *
 * Sent once a day at `digest_time` (default 09:00) in the SITE's timezone.
 *
 * Schedule: one single WP-Cron event (`amc_digest`) at the next local
 * HH:MM, rescheduled each time it runs. A fixed 24 h interval would drift an
 * hour twice a year with daylight saving time. It is scheduled on
 * `amc_activate`, cleared on `amc_deactivate`, and rescheduled when
 * `digest_time` or the site timezone changes.
 *
 * Content, per site:
 *   1. "held back" : immediate-route findings never emailed (hourly cap overflow, failed send);
 *   2. "new"       : open findings first seen in the last 24 hours that were not emailed yet;
 *   3. "open"      : everything else still open (not accepted, muted or resolved) and routed to email.
 *
 * Nothing is sent when 1 and 2 are both empty ("no new findings"). Findings in
 * 1 and 2 are marked alerted once the mail was accepted, so they show under
 * "open" afterwards. The send runs under `Lock::named( 'digest' )`, so a
 * doubled cron run cannot send twice.
 *
 * @since 0.1.0
 */
final class Digest implements Registrable {

	/**
	 * Cron hook.
	 */
	public const HOOK = 'amc_digest';

	/**
	 * Findings listed per section (the rest is counted).
	 */
	public const LIST_LIMIT = 25;

	/**
	 * "Last 24 hours".
	 */
	public const WINDOW = DAY_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param FindingsRepository|null $findings Findings repository.
	 */
	public function __construct( private ?FindingsRepository $findings = null ) {
		$this->findings ??= new FindingsRepository();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'on_cron' ] );
		add_action( 'amc_activate', [ $this, 'on_activate' ] );
		add_action( 'amc_deactivate', [ self::class, 'unschedule' ] );

		add_action( 'update_option_' . Settings::OPTION, [ $this, 'on_settings_updated' ], 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, [ $this, 'on_settings_added' ] );
		add_action( 'update_option_timezone_string', [ self::class, 'reschedule' ] );
		add_action( 'update_option_gmt_offset', [ self::class, 'reschedule' ] );

		// Self-heal a missing event, but never on front-end requests.
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'init', [ $this, 'on_activate' ] );
		}
	}

	/**
	 * Unix time of the next `HH:MM` in a timezone, strictly after `$from`.
	 *
	 * Built from wall-clock time each day, so it stays at HH:MM across
	 * daylight saving changes. (A time that does not exist on the spring-forward
	 * day, e.g. 02:30, runs an hour later that day.)
	 *
	 * @param string             $time Time 'HH:MM'.
	 * @param int                $from Unix time.
	 * @param \DateTimeZone|null $zone Timezone (default: the site's).
	 */
	public static function next_run( string $time, int $from, ?\DateTimeZone $zone = null ): int {
		$zone ??= wp_timezone();
		$parts  = explode( ':', 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '09:00' );
		$hour   = (int) $parts[0];
		$minute = (int) $parts[1];
		$local  = ( new \DateTimeImmutable( '@' . $from ) )->setTimezone( $zone );
		$at     = $local->setTime( $hour, $minute, 0 );

		if ( $at->getTimestamp() <= $from ) {
			$at = $local->modify( '+1 day' )->setTime( $hour, $minute, 0 );
		}

		return $at->getTimestamp();
	}

	/**
	 * Schedule the next digest if none is scheduled.
	 *
	 * @param int|null $from Unix time to count from (test seam).
	 */
	public static function schedule( ?int $from = null ): void {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_single_event(
			self::next_run( (string) Settings::get_value( 'digest_time' ), $from ?? time() ),
			self::HOOK
		);
	}

	/**
	 * `amc_activate` and the admin/cron/CLI `init` self-heal. WordPress passes
	 * an empty string to hooks without arguments, so this wraps schedule().
	 */
	public function on_activate(): void {
		self::schedule();
	}

	/**
	 * Replace the scheduled digest (setting or timezone changed).
	 */
	public static function reschedule(): void {
		self::unschedule();
		self::schedule();
	}

	/**
	 * Remove the digest event.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * `update_option_amc_settings`: reschedule when the time changed.
	 *
	 * @param mixed $old_value Old settings.
	 * @param mixed $value     New settings.
	 */
	public function on_settings_updated( mixed $old_value, mixed $value ): void {
		$old = is_array( $old_value ) ? (string) ( $old_value['digest_time'] ?? '' ) : '';
		$new = is_array( $value ) ? (string) ( $value['digest_time'] ?? '' ) : '';

		if ( $old !== $new ) {
			self::reschedule();
		}
	}

	/**
	 * `add_option_amc_settings`: the first save may carry a custom time.
	 */
	public function on_settings_added(): void {
		self::reschedule();
	}

	/**
	 * Cron callback.
	 */
	public function on_cron(): void {
		// Chain the next day first, so a failure below cannot end the series.
		// Counted from a minute ahead: a run that fires a moment early must not pick today's slot again.
		self::schedule( time() + MINUTE_IN_SECONDS );

		$this->run();
	}

	/**
	 * Build and send the digest.
	 *
	 * @param int|null $now Unix time (test seam).
	 *
	 * @return bool Whether a digest was sent.
	 */
	public function run( ?int $now = null ): bool {
		if ( KillSwitch::is_active() ) {
			return false;
		}

		$lock = Lock::named( 'digest' );

		if ( ! $lock->acquire() ) {
			return false;
		}

		try {
			$digest     = $this->build( $now ?? time() );
			$recipients = Settings::recipients();

			if ( null === $digest || [] === $recipients ) {
				return false;
			}

			if ( ! Mailer::send( $recipients, $digest['subject'], $digest['email'] ) ) {
				return false;
			}

			$this->findings->mark_alerted( $digest['alerted_ids'] );

			return true;
		} finally {
			$lock->release();
		}
	}

	/**
	 * Work out what the digest would say. Sends and writes nothing.
	 *
	 * @param int $now Unix time.
	 *
	 * @return array{subject: string, email: array<string, mixed>, alerted_ids: list<int>}|null Null when there is nothing new.
	 */
	public function build( int $now ): ?array {
		$since     = gmdate( 'Y-m-d H:i:s', $now - self::WINDOW );
		$check_ids = AlertRules::alerting_checks();

		if ( [] === $check_ids ) {
			return null;
		}

		$held = [];
		$new  = [];
		foreach ( $this->open_findings( $check_ids, false ) as $row ) {
			$route = AlertRules::route( $row );

			if ( AlertRules::IMMEDIATE === $route ) {
				$held[] = $row;
			} elseif ( AlertRules::DIGEST === $route && (string) $row['first_seen'] >= $since ) {
				$new[] = $row;
			}
		}

		if ( [] === $held && [] === $new ) {
			return null;
		}

		$reported = [];
		foreach ( array_merge( $held, $new ) as $row ) {
			$reported[ (int) $row['id'] ] = true;
		}

		$open = [];
		foreach ( $this->open_findings( $check_ids, null ) as $row ) {
			if ( ! isset( $reported[ (int) $row['id'] ] ) && AlertRules::NONE !== AlertRules::route( $row ) ) {
				$open[] = $row;
			}
		}

		$sections = array_values(
			array_filter(
				[
					self::section( __( 'Immediate alerts held back by the hourly limit', 'rtcamp-amc-copilot' ), $held ),
					self::section( __( 'New in the last 24 hours', 'rtcamp-amc-copilot' ), $new ),
					self::section( __( 'Still open', 'rtcamp-amc-copilot' ), $open ),
				]
			)
		);

		$new_count = count( $held ) + count( $new );

		return [
			'subject'     => sprintf(
				/* translators: %d: Number of new findings. */
				_n( 'Daily digest: %d new finding', 'Daily digest: %d new findings', $new_count, 'rtcamp-amc-copilot' ),
				$new_count
			),
			'email'       => [
				'heading'      => __( 'Daily digest', 'rtcamp-amc-copilot' ),
				'intro'        => sprintf(
					/* translators: 1: Number of new findings, 2: Number of findings still open. */
					__( '%1$d new and %2$d still open.', 'rtcamp-amc-copilot' ),
					$new_count,
					count( $open )
				),
				'sections'     => $sections,
				'action_url'   => Mailer::console_url(),
				'action_label' => __( 'Open the AMC Copilot console', 'rtcamp-amc-copilot' ),
			],
			'alerted_ids' => array_keys( $reported ),
		];
	}

	/**
	 * Send a sample digest to the recipients (Settings "send test").
	 *
	 * @return bool Whether it was sent.
	 */
	public static function send_sample(): bool {
		$rows = [
			[
				'id'       => 0,
				'check_id' => 'updates.plugins',
				'severity' => 'medium',
				'title'    => __( 'Sample: a plugin has an update available', 'rtcamp-amc-copilot' ),
			],
			[
				'id'       => 0,
				'check_id' => 'security.config',
				'severity' => 'low',
				'title'    => __( 'Sample: file editing is not disabled', 'rtcamp-amc-copilot' ),
			],
		];

		return Mailer::send(
			Settings::recipients(),
			__( 'Test: daily digest', 'rtcamp-amc-copilot' ),
			[
				'heading'      => __( 'Daily digest (test)', 'rtcamp-amc-copilot' ),
				'intro'        => __( 'This is a sample. Real digests list the findings that appeared in the last 24 hours.', 'rtcamp-amc-copilot' ),
				'sections'     => [ self::section( __( 'New in the last 24 hours', 'rtcamp-amc-copilot' ), $rows ) ],
				'action_url'   => Mailer::console_url(),
				'action_label' => __( 'Open the AMC Copilot console', 'rtcamp-amc-copilot' ),
			]
		);
	}

	/**
	 * Unresolved `open` findings of the emailing checks.
	 *
	 * @param list<string> $check_ids Check ids that can email.
	 * @param bool|null    $alerted   false = not emailed yet; null = any.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function open_findings( array $check_ids, ?bool $alerted ): array {
		$args = [
			'check_ids' => $check_ids,
			'status'    => FindingsRepository::STATUS_OPEN,
			'per_page'  => 500,
		];

		if ( null !== $alerted ) {
			$args['alerted'] = $alerted;
		}

		return $this->findings->query( $args )['items'];
	}

	/**
	 * One email section, worst first and capped.
	 *
	 * @param string                     $title Section title.
	 * @param list<array<string, mixed>> $rows  Finding rows.
	 *
	 * @return array{title: string, items: list<array{title: string, severity: string, check: string, url: string}>, more: int}|null
	 */
	private static function section( string $title, array $rows ): ?array {
		if ( [] === $rows ) {
			return null;
		}

		usort(
			$rows,
			static fn ( array $a, array $b ): int => ( Severity::tryFrom( (string) $b['severity'] )?->rank() ?? 0 ) <=> ( Severity::tryFrom( (string) $a['severity'] )?->rank() ?? 0 )
		);

		return [
			'title' => sprintf( '%s (%d)', $title, count( $rows ) ),
			'items' => array_map( [ Mailer::class, 'item' ], array_slice( $rows, 0, self::LIST_LIMIT ) ),
			'more'  => max( 0, count( $rows ) - self::LIST_LIMIT ),
		];
	}
}
