<?php
/**
 * Base class for the errors.* checks.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Errors;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Storage\EventsRepository;

/**
 * Class AbstractEventsCheck
 *
 * Summarises the hourly aggregates in `amc_events` (written by the capture
 * layer, lane A1) since the previous run. Events are never written here.
 *
 * "Since the previous run" means signatures whose `last_seen` is later than
 * the previous run's `checked_at` (the first run looks back one hour). A
 * finding is raised per group (see group_key()). While a group keeps
 * producing events, its finding stays open (the runner touches it); after a
 * run without new events it resolves, and a recurrence is a new finding.
 *
 * Result::$data shape (shared by errors.fatal, errors.warnings, errors.deprecations):
 *   checked_at  string  UTC ISO 8601: when this run looked. The next run only reports newer events.
 *   since       string  UTC ISO 8601: start of the window this run covered.
 *   events      int     Events counted in the window (hourly row counts; the hour of the previous run can be counted again).
 *   signatures  int     Distinct signatures seen.
 *   items       list<array{key: string, source: string, file: string, line: int, type: string, count: int, signatures: int, message: string, first_seen: string, last_seen: string}>
 *                       One entry per finding group, highest count first, at most 50.
 *   truncated   int     Groups left out of `items` and the findings.
 *
 * @since 0.1.0
 */
abstract class AbstractEventsCheck implements Check {

	/**
	 * Maximum findings per run.
	 */
	private const MAX_ITEMS = 50;

	/**
	 * Groups events: callable( array $args ): list<array> (EventsRepository::grouped()).
	 *
	 * @var callable
	 */
	private $events_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $events_source Test seam; default EventsRepository::grouped().
	 */
	public function __construct( ?callable $events_source = null ) {
		$this->events_source = $events_source ?? static fn ( array $args ): array => ( new EventsRepository() )->grouped( $args );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::HOURLY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::ERRORS;
	}

	/**
	 * The event types this check summarises.
	 *
	 * @return list<string>
	 */
	abstract protected function types(): array;

	/**
	 * What one finding stands for: the dedupe key of an event row.
	 *
	 * @param array<string, mixed> $row EventsRepository::grouped() row.
	 */
	abstract protected function group_key( array $row ): string;

	/**
	 * Severity of a group.
	 */
	abstract protected function severity(): Severity;

	/**
	 * Finding title for a group.
	 *
	 * @param array<string, mixed> $item Item from `data.items`.
	 */
	abstract protected function title( array $item ): string;

	/**
	 * Summary and status when there is nothing to report.
	 */
	abstract protected function clean_summary(): string;

	/**
	 * Build the result for a non-empty set of items.
	 *
	 * @param string                           $summary  Summary.
	 * @param array<string, mixed>             $data     Data.
	 * @param list<\AMC\Checks\Finding>        $findings Findings.
	 */
	abstract protected function result_with_findings( string $summary, array $data, array $findings ): Result;

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context.
	 */
	public function run( Context $ctx ): Result {
		$now   = $ctx->now->getTimestamp();
		$since = $this->since( $ctx );

		$data = [
			'checked_at' => gmdate( 'c', $now ),
			'since'      => gmdate( 'c', $since ),
			'events'     => 0,
			'signatures' => 0,
			'items'      => [],
			'truncated'  => 0,
		];

		/** @var array<string, array<string, mixed>> $groups */
		$groups = [];

		foreach ( $this->types() as $type ) {
			$rows = ( $this->events_source )(
				[
					'blog_id' => $ctx->blog_id,
					'type'    => $type,
					'since'   => EventsRepository::hour( $since ),
					'limit'   => 500,
				]
			);

			foreach ( $rows as $row ) {
				if ( (int) strtotime( (string) $row['last_seen'] . ' UTC' ) <= $since ) {
					continue; // Already reported by an earlier run.
				}

				$data['events']     += (int) $row['count'];
				$data['signatures'] += 1;

				$key = $this->group_key( $row );

				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = [
						'key'        => $key,
						'source'     => (string) $row['source'],
						'file'       => (string) $row['file'],
						'line'       => (int) $row['line'],
						'type'       => (string) $row['type'],
						'count'      => 0,
						'signatures' => 0,
						'message'    => '',
						'first_seen' => (string) $row['first_seen'],
						'last_seen'  => (string) $row['last_seen'],
						'top'        => -1,
					];
				}

				$group                = &$groups[ $key ];
				$group['count']      += (int) $row['count'];
				$group['signatures'] += 1;
				$group['first_seen']  = min( $group['first_seen'], (string) $row['first_seen'] );
				$group['last_seen']   = max( $group['last_seen'], (string) $row['last_seen'] );

				// The group is described by its noisiest signature.
				if ( (int) $row['count'] > $group['top'] ) {
					$group['top']     = (int) $row['count'];
					$group['message'] = mb_substr( (string) $row['message'], 0, 300 );
					$group['file']    = (string) $row['file'];
					$group['line']    = (int) $row['line'];
					$group['type']    = (string) $row['type'];
				}
				unset( $group );
			}
		}

		if ( [] === $groups ) {
			return Result::pass( $this->clean_summary(), $data );
		}

		usort( $groups, static fn ( array $a, array $b ): int => $b['count'] <=> $a['count'] );

		$data['truncated'] = max( 0, count( $groups ) - self::MAX_ITEMS );
		$groups            = array_slice( $groups, 0, self::MAX_ITEMS );
		$findings          = [];

		foreach ( $groups as $group ) {
			unset( $group['top'] );

			$data['items'][] = $group;
			$findings[]      = new Finding( $this->dedupe_key( $group['key'] ), $this->severity(), $this->title( $group ), $group );
		}

		return $this->result_with_findings(
			sprintf(
				/* translators: 1: Number of events, 2: Number of findings. */
				_n( '%1$d event in %2$d group since the last run.', '%1$d events in %2$d groups since the last run.', $data['events'], 'rtcamp-amc-copilot' ),
				$data['events'],
				count( $findings )
			),
			$data,
			$findings
		);
	}

	/**
	 * The start of the window: the previous run's time, or one hour ago on the first run.
	 *
	 * @param Context $ctx Run context.
	 */
	private function since( Context $ctx ): int {
		$previous = $ctx->previous_data['checked_at'] ?? null;
		$time     = is_string( $previous ) ? strtotime( $previous ) : false;

		return false === $time ? $ctx->now->getTimestamp() - HOUR_IN_SECONDS : $time;
	}

	/**
	 * A key that fits the 191-character limit.
	 *
	 * @param string $key Group key.
	 */
	private function dedupe_key( string $key ): string {
		return strlen( $key ) > 191 ? 'h:' . md5( $key ) : $key;
	}
}
