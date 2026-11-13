<?php
/**
 * Report period maths and schedules.
 *
 * Pure PHP: no WordPress, database or global state. The caller supplies the
 * site timezone and every returned date is expressed in it.
 *
 * Schedule summary (site time):
 *   - Monthly   : 1st of each month at 06:00, covering the previous calendar month.
 *   - Quarterly : first day of each contract quarter at 06:15, covering the
 *                 previous quarter. Without a contract the quarters are calendar
 *                 quarters (1 January, 1 April, 1 July, 1 October).
 *   - Annual    : contract anniversary at 06:30, covering the previous 12 months.
 *                 Without a contract this is 1 January, covering the previous
 *                 calendar year.
 *
 * A "contract quarter" runs three months from the contract start day, so a
 * contract starting on the 31st clamps to the last day of shorter months.
 *
 * @package AMC\Reports
 */

declare( strict_types = 1 );

namespace AMC\Reports; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound,SlevomatCodingStandard.Namespaces.NamespaceSpacing.IncorrectLinesCountAfterNamespace -- AMC is the plugin namespace trunk.

use DateTimeImmutable;
use DateTimeZone;

/**
 * Class - Periods
 *
 * Stateless factory for report periods and their run instants.
 */
final class Periods {

	/**
	 * Month in which every contract or calendar year starts.
	 */
	private const YEAR_START_MONTH = 1;

	/**
	 * Day of the month on which calendar years and months start.
	 */
	private const MONTH_START_DAY = 1;

	/**
	 * Run hour shared by every schedule.
	 */
	private const RUN_HOUR = 6;

	/**
	 * Monthly run minute (06:00).
	 */
	private const MONTHLY_RUN_MINUTE = 0;

	/**
	 * Quarterly run minute (06:15).
	 */
	private const QUARTERLY_RUN_MINUTE = 15;

	/**
	 * Annual run minute (06:30).
	 */
	private const ANNUAL_RUN_MINUTE = 30;

	/**
	 * Disallow instantiation - this class only exposes static helpers.
	 */
	private function __construct() {}

	/**
	 * The most recently completed period of a recurring type.
	 *
	 * @param string                 $type           Period type: month, quarter or year.
	 * @param DateTimeImmutable      $now            Current instant.
	 * @param DateTimeImmutable|null $contract_start Contract start date, when the AMC has one.
	 * @param DateTimeZone           $tz             Site timezone.
	 *
	 * @return Period Completed period.
	 *
	 * @throws \InvalidArgumentException When the type is custom or unknown.
	 */
	public static function previous( string $type, DateTimeImmutable $now, ?DateTimeImmutable $contract_start, DateTimeZone $tz ): Period {
		$local = $now->setTimezone( $tz );

		return match ( $type ) {
			Period::TYPE_MONTH => self::previous_month( $local, $tz ),
			Period::TYPE_QUARTER => self::previous_quarter( $local, $contract_start, $tz ),
			Period::TYPE_YEAR => self::previous_year( $local, $contract_start, $tz ),
			default => throw new \InvalidArgumentException( esc_html( sprintf( 'Cannot derive a previous "%s" period.', $type ) ) ),
		};
	}

	/**
	 * An ad-hoc period covering two dates inclusively.
	 *
	 * @param DateTimeImmutable $start First day of the period.
	 * @param DateTimeImmutable $end   Last day of the period.
	 * @param DateTimeZone      $tz    Site timezone.
	 *
	 * @return Period Custom period.
	 *
	 * @throws \InvalidArgumentException When the start date is after the end date.
	 */
	public static function custom( DateTimeImmutable $start, DateTimeImmutable $end, DateTimeZone $tz ): Period {
		$local_start = $start->setTimezone( $tz );
		$local_end   = $end->setTimezone( $tz );

		if ( $local_start->format( 'Y-m-d' ) > $local_end->format( 'Y-m-d' ) ) {
			throw new \InvalidArgumentException( 'A custom period must start on or before it ends.' );
		}

		$range_start = $local_start->setTime( 0, 0, 0 );
		$range_end   = $local_end->setTime( 23, 59, 59 );

		return new Period(
			Period::TYPE_CUSTOM,
			$range_start,
			$range_end,
			self::custom_label( $range_start, $range_end )
		);
	}

	/**
	 * The first scheduled run instant at or after the given moment.
	 *
	 * The result is in the site timezone. When `$now` is exactly a run instant,
	 * that instant is returned.
	 *
	 * @param string                 $type           Period type: month, quarter or year.
	 * @param DateTimeImmutable      $now            Current instant.
	 * @param DateTimeImmutable|null $contract_start Contract start date, when the AMC has one.
	 * @param DateTimeZone           $tz             Site timezone.
	 *
	 * @return DateTimeImmutable Next run instant in site time.
	 *
	 * @throws \InvalidArgumentException When the type is custom or unknown.
	 */
	public static function next_run( string $type, DateTimeImmutable $now, ?DateTimeImmutable $contract_start, DateTimeZone $tz ): DateTimeImmutable {
		$local = $now->setTimezone( $tz );

		return match ( $type ) {
			Period::TYPE_MONTH => self::next_monthly_run( $local, $tz ),
			Period::TYPE_QUARTER => self::next_quarterly_run( $local, $contract_start, $tz ),
			Period::TYPE_YEAR => self::next_annual_run( $local, $contract_start, $tz ),
			default => throw new \InvalidArgumentException( esc_html( sprintf( 'Cannot schedule a "%s" run.', $type ) ) ),
		};
	}

	/**
	 * Whether a run is due at the given moment.
	 *
	 * True from the scheduled run time until the end of that local day, which
	 * lets a late WP-Cron tick still generate the report; false before the run
	 * time and on every other day.
	 *
	 * @param string                 $type           Period type: month, quarter or year.
	 * @param DateTimeImmutable      $now            Current instant.
	 * @param DateTimeImmutable|null $contract_start Contract start date, when the AMC has one.
	 * @param DateTimeZone           $tz             Site timezone.
	 *
	 * @return bool Whether the run is due.
	 *
	 * @throws \InvalidArgumentException When the type is custom or unknown.
	 */
	public static function is_due( string $type, DateTimeImmutable $now, ?DateTimeImmutable $contract_start, DateTimeZone $tz ): bool {
		$local        = $now->setTimezone( $tz );
		$start_of_day = $local->setTime( 0, 0, 0 );
		$run          = self::next_run( $type, $start_of_day, $contract_start, $tz );

		return $run->format( 'Y-m-d' ) === $local->format( 'Y-m-d' ) && $run <= $now;
	}

	/**
	 * Previous calendar month.
	 *
	 * @param DateTimeImmutable $local Current instant in site time.
	 * @param DateTimeZone      $tz    Site timezone.
	 *
	 * @return Period Monthly period.
	 */
	private static function previous_month( DateTimeImmutable $local, DateTimeZone $tz ): Period {
		$year  = (int) $local->format( 'Y' );
		$month = (int) $local->format( 'n' ) - 1;

		if ( 0 === $month ) {
			$month = 12;
			--$year;
		}

		$start = self::boundary_date( $local, self::month_index( $year, $month ), self::MONTH_START_DAY, $tz );
		$end   = $start->setDate( $year, $month, (int) $start->format( 't' ) )->setTime( 23, 59, 59 );

		return new Period( Period::TYPE_MONTH, $start, $end, $start->format( 'F Y' ) );
	}

	/**
	 * Previous quarter, anchored to the contract when one exists.
	 *
	 * @param DateTimeImmutable      $local          Current instant in site time.
	 * @param DateTimeImmutable|null $contract_start Contract start date, if any.
	 * @param DateTimeZone           $tz             Site timezone.
	 *
	 * @return Period Quarterly period.
	 */
	private static function previous_quarter( DateTimeImmutable $local, ?DateTimeImmutable $contract_start, DateTimeZone $tz ): Period {
		[ $anchor_index, $anchor_day ] = self::anchor( $local, $contract_start );

		$current_index = self::boundary_index_on_or_before( $anchor_index, $anchor_day, 3, $local, $tz );
		$start_index   = $current_index - 3;
		$start         = self::boundary_date( $local, $start_index, $anchor_day, $tz );
		$end           = self::boundary_date( $local, $current_index, $anchor_day, $tz )
			->modify( '-1 day' )
			->setTime( 23, 59, 59 );

		if ( null === $contract_start ) {
			$number = intdiv( (int) $start->format( 'n' ) - 1, 3 ) + 1;
			$label  = sprintf( 'Q%d %s', $number, $start->format( 'Y' ) );
		} else {
			$year_index = $anchor_index + 12 * (int) floor( ( $start_index - $anchor_index ) / 12 );
			$number     = intdiv( $start_index - $year_index, 3 ) + 1;
			$year       = intdiv( $year_index, 12 );
			$label      = sprintf( 'Q%d %d–%s', $number, $year, substr( (string) ( $year + 1 ), 2 ) );
		}

		return new Period( Period::TYPE_QUARTER, $start, $end, $label );
	}

	/**
	 * Previous year, anchored to the contract anniversary when one exists.
	 *
	 * @param DateTimeImmutable      $local          Current instant in site time.
	 * @param DateTimeImmutable|null $contract_start Contract start date, if any.
	 * @param DateTimeZone           $tz             Site timezone.
	 *
	 * @return Period Annual period.
	 */
	private static function previous_year( DateTimeImmutable $local, ?DateTimeImmutable $contract_start, DateTimeZone $tz ): Period {
		if ( null === $contract_start ) {
			$year  = (int) $local->format( 'Y' ) - 1;
			$start = self::boundary_date( $local, self::month_index( $year, self::YEAR_START_MONTH ), self::MONTH_START_DAY, $tz );
			$end   = self::boundary_date( $local, self::month_index( $year + 1, self::YEAR_START_MONTH ), self::MONTH_START_DAY, $tz )
				->modify( '-1 day' )
				->setTime( 23, 59, 59 );

			return new Period( Period::TYPE_YEAR, $start, $end, sprintf( 'Calendar year %d', $year ) );
		}

		[ $anchor_index, $anchor_day ] = self::anchor( $local, $contract_start );

		$current_index = self::boundary_index_on_or_before( $anchor_index, $anchor_day, 12, $local, $tz );
		$start_index   = $current_index - 12;
		$start         = self::boundary_date( $local, $start_index, $anchor_day, $tz );
		$end           = self::boundary_date( $local, $current_index, $anchor_day, $tz )
			->modify( '-1 day' )
			->setTime( 23, 59, 59 );

		$year  = intdiv( $start_index, 12 );
		$label = sprintf( 'Contract year %d–%s', $year, substr( (string) ( $year + 1 ), 2 ) );

		return new Period( Period::TYPE_YEAR, $start, $end, $label );
	}

	/**
	 * Next monthly run (1st of the month at 06:00).
	 *
	 * @param DateTimeImmutable $local Current instant in site time.
	 * @param DateTimeZone      $tz    Site timezone.
	 *
	 * @return DateTimeImmutable Next run instant.
	 */
	private static function next_monthly_run( DateTimeImmutable $local, DateTimeZone $tz ): DateTimeImmutable {
		$anchor_index = self::month_index( (int) $local->format( 'Y' ), self::YEAR_START_MONTH );

		return self::next_boundary_run( $local, $anchor_index, self::MONTH_START_DAY, 1, self::MONTHLY_RUN_MINUTE, $tz );
	}

	/**
	 * Next quarterly run (first day of the quarter at 06:15).
	 *
	 * @param DateTimeImmutable      $local          Current instant in site time.
	 * @param DateTimeImmutable|null $contract_start Contract start date, if any.
	 * @param DateTimeZone           $tz             Site timezone.
	 *
	 * @return DateTimeImmutable Next run instant.
	 */
	private static function next_quarterly_run( DateTimeImmutable $local, ?DateTimeImmutable $contract_start, DateTimeZone $tz ): DateTimeImmutable {
		[ $anchor_index, $anchor_day ] = self::anchor( $local, $contract_start );

		return self::next_boundary_run( $local, $anchor_index, $anchor_day, 3, self::QUARTERLY_RUN_MINUTE, $tz );
	}

	/**
	 * Next annual run (contract anniversary at 06:30).
	 *
	 * @param DateTimeImmutable      $local          Current instant in site time.
	 * @param DateTimeImmutable|null $contract_start Contract start date, if any.
	 * @param DateTimeZone           $tz             Site timezone.
	 *
	 * @return DateTimeImmutable Next run instant.
	 */
	private static function next_annual_run( DateTimeImmutable $local, ?DateTimeImmutable $contract_start, DateTimeZone $tz ): DateTimeImmutable {
		[ $anchor_index, $anchor_day ] = self::anchor( $local, $contract_start );

		return self::next_boundary_run( $local, $anchor_index, $anchor_day, 12, self::ANNUAL_RUN_MINUTE, $tz );
	}

	/**
	 * The first boundary run at or after the current moment.
	 *
	 * @param DateTimeImmutable $local        Current instant in site time.
	 * @param int               $anchor_index Absolute month index of the anchor.
	 * @param int               $anchor_day   Preferred day of month for boundaries.
	 * @param int               $step         Months between boundaries.
	 * @param int               $minute       Run minute past the hour.
	 * @param DateTimeZone      $tz           Site timezone.
	 *
	 * @return DateTimeImmutable Next run instant.
	 */
	private static function next_boundary_run( DateTimeImmutable $local, int $anchor_index, int $anchor_day, int $step, int $minute, DateTimeZone $tz ): DateTimeImmutable {
		$index = self::boundary_index_on_or_before( $anchor_index, $anchor_day, $step, $local, $tz );
		$run   = self::boundary_date( $local, $index, $anchor_day, $tz )->setTime( self::RUN_HOUR, $minute, 0 );

		if ( $run < $local ) {
			$run = self::boundary_date( $local, $index + $step, $anchor_day, $tz )->setTime( self::RUN_HOUR, $minute, 0 );
		}

		return $run;
	}

	/**
	 * Anchor month and day for quarter or year boundaries.
	 *
	 * Without a contract the anchor is 1 January of the current year; with one
	 * it is the contract's start month and day, clamped month by month.
	 *
	 * @param DateTimeImmutable      $local          Current instant in site time.
	 * @param DateTimeImmutable|null $contract_start Contract start date, if any (its own calendar date is used).
	 *
	 * @return array{0: int, 1: int} Absolute anchor month index and preferred day.
	 */
	private static function anchor( DateTimeImmutable $local, ?DateTimeImmutable $contract_start ): array {
		if ( null === $contract_start ) {
			return [ self::month_index( (int) $local->format( 'Y' ), self::YEAR_START_MONTH ), self::MONTH_START_DAY ];
		}

		// The contract start is a calendar date, not an instant: read its own
		// year, month and day. Converting it to the site timezone would move a
		// date built at UTC midnight back a day for any site west of UTC.
		$contract = $contract_start;

		return [
			self::month_index( (int) $contract->format( 'Y' ), (int) $contract->format( 'n' ) ),
			(int) $contract->format( 'j' ),
		];
	}

	/**
	 * The boundary month index at or before the current date.
	 *
	 * The comparison is by calendar date, not instant, so a run day that has
	 * not reached its run time yet still counts as the current boundary.
	 *
	 * @param int               $anchor_index Absolute month index of the anchor.
	 * @param int               $anchor_day   Preferred day of month for boundaries.
	 * @param int               $step         Months between boundaries.
	 * @param DateTimeImmutable $local        Current instant in site time.
	 * @param DateTimeZone      $tz           Site timezone.
	 *
	 * @return int Absolute month index of the latest boundary.
	 */
	private static function boundary_index_on_or_before( int $anchor_index, int $anchor_day, int $step, DateTimeImmutable $local, DateTimeZone $tz ): int {
		$now_index = self::month_index( (int) $local->format( 'Y' ), (int) $local->format( 'n' ) );
		$steps     = (int) floor( ( $now_index - $anchor_index ) / $step );
		$index     = $anchor_index + $steps * $step;
		$candidate = self::boundary_date( $local, $index, $anchor_day, $tz );

		if ( $candidate->format( 'Y-m-d' ) > $local->format( 'Y-m-d' ) ) {
			$index -= $step;
		}

		return $index;
	}

	/**
	 * Local midnight for a boundary month, clamping the day to the month's length.
	 *
	 * @param DateTimeImmutable $reference Instant whose timezone the result carries.
	 * @param int               $index     Absolute month index (year * 12 + month - 1).
	 * @param int               $day       Preferred day of month.
	 * @param DateTimeZone      $tz        Site timezone.
	 *
	 * @return DateTimeImmutable Boundary date at 00:00:00.
	 */
	private static function boundary_date( DateTimeImmutable $reference, int $index, int $day, DateTimeZone $tz ): DateTimeImmutable {
		$year  = intdiv( $index, 12 );
		$month = ( $index % 12 ) + 1;

		return $reference->setTimezone( $tz )
			->setDate( $year, $month, min( $day, self::days_in_month( $year, $month, $tz ) ) )
			->setTime( 0, 0, 0 );
	}

	/**
	 * Absolute month number.
	 *
	 * @param int $year  Four-digit year.
	 * @param int $month Month number (1-12).
	 *
	 * @return int Month index where January 2026 is 2026 * 12.
	 */
	private static function month_index( int $year, int $month ): int {
		return $year * 12 + $month - 1;
	}

	/**
	 * Number of days in a month.
	 *
	 * @param int          $year  Four-digit year.
	 * @param int          $month Month number (1-12).
	 * @param DateTimeZone $tz    Site timezone.
	 *
	 * @return int Days in the month (28-31).
	 */
	private static function days_in_month( int $year, int $month, DateTimeZone $tz ): int {
		$first = new DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $tz );

		return (int) $first->format( 't' );
	}

	/**
	 * Label for an ad-hoc period.
	 *
	 * @param DateTimeImmutable $start First instant of the period.
	 * @param DateTimeImmutable $end   Last instant of the period.
	 *
	 * @return string Label such as "1 Oct 2026 – 31 Oct 2026".
	 */
	private static function custom_label( DateTimeImmutable $start, DateTimeImmutable $end ): string {
		if ( $start->format( 'Y-m-d' ) === $end->format( 'Y-m-d' ) ) {
			return $start->format( 'j M Y' );
		}

		return sprintf( '%s – %s', $start->format( 'j M Y' ), $end->format( 'j M Y' ) );
	}
}
