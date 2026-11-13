<?php
/**
 * Check: health.cron.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Health;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;

/**
 * Class CronHealth
 *
 * Reports overdue cron events (more than 1 hour past their scheduled time).
 *
 * Result::$data shape:
 *   overdue list<array{hook, scheduled, overdue_seconds}>
 *   total_events int
 *
 * Status: PASS when none overdue, WARN otherwise.
 *
 * @since 0.1.0
 */
final class CronHealth implements Check {

	/**
	 * Returns the cron array.
	 *
	 * @var callable(): array<mixed>
	 */
	private $cron_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $cron_source Test seam; default _get_cron_array().
	 */
	public function __construct( ?callable $cron_source = null ) {
		$this->cron_source = $cron_source ?? static fn (): array => function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'health.cron';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::HEALTH;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Cron health', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::HOURLY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context.
	 */
	public function run( Context $ctx ): Result {
		$cron    = (array) ( $this->cron_source )();
		$now     = $ctx->now->getTimestamp();
		$cutoff  = $now - HOUR_IN_SECONDS;
		$overdue = [];
		$total   = 0;

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook => $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}

				foreach ( $events as $event ) {
					++$total;

					if ( (int) $timestamp < $cutoff ) {
						$overdue[] = [
							'hook'            => (string) $hook,
							'scheduled'       => gmdate( 'c', (int) $timestamp ),
							'overdue_seconds' => $now - (int) $timestamp,
						];
					}
				}
			}
		}

		$findings = [];
		foreach ( $overdue as $row ) {
			$findings[] = new Finding(
				'cron:' . $row['hook'],
				Severity::MEDIUM,
				sprintf( /* translators: %s: Hook name. */ __( 'Overdue cron event: %s.', 'rtcamp-amc-copilot' ), $row['hook'] ),
				$row
			);
		}

		$data = [
			'overdue'      => $overdue,
			'total_events' => $total,
		];

		if ( [] !== $findings ) {
			return Result::warn(
				sprintf(
					/* translators: %s: Number of overdue events. */
					_n( '%s overdue cron event.', '%s overdue cron events.', count( $overdue ), 'rtcamp-amc-copilot' ),
					number_format_i18n( count( $overdue ) )
				),
				$data,
				$findings
			);
		}

		return Result::pass( __( 'No overdue cron events.', 'rtcamp-amc-copilot' ), $data );
	}
}
