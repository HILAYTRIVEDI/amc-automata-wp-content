<?php
/**
 * Tests for report period maths and schedules.
 *
 * Pure logic: no WordPress functions are called.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Reports\Period;
use AMC\Reports\Periods;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Class PeriodsTest
 */
final class PeriodsTest extends TestCase {

	/**
	 * Monthly periods cover the previous calendar month.
	 */
	public function test_monthly_previous_covers_previous_calendar_month(): void {
		$period = Periods::previous( Period::TYPE_MONTH, self::instant( '2026-11-13 12:00:00' ), null, self::utc() );

		$this->assert_period( Period::TYPE_MONTH, '2026-10-01 00:00:00', '2026-10-31 23:59:59', 'October 2026', $period );
		$this->assertSame( 'AMC-NWT-2026-10', $period->report_id( 'NWT' ) );
	}

	/**
	 * The value object exposes the same data as its getters.
	 */
	public function test_period_exposes_immutable_properties(): void {
		$period = Periods::previous( Period::TYPE_MONTH, self::instant( '2026-11-13 12:00:00' ), null, self::utc() );

		$this->assertSame( $period->type(), $period->type );
		$this->assertSame( $period->start(), $period->start );
		$this->assertSame( $period->end(), $period->end );
		$this->assertSame( $period->label(), $period->label );
	}

	/**
	 * Monthly periods cross the year boundary.
	 */
	public function test_monthly_previous_crosses_year_boundary(): void {
		$period = Periods::previous( Period::TYPE_MONTH, self::instant( '2026-01-05 12:00:00' ), null, self::utc() );

		$this->assert_period( Period::TYPE_MONTH, '2025-12-01 00:00:00', '2025-12-31 23:59:59', 'December 2025', $period );
		$this->assertSame( 'AMC-NWT-2025-12', $period->report_id( 'NWT' ) );
	}

	/**
	 * The site timezone decides which month "now" belongs to.
	 */
	public function test_monthly_previous_uses_site_timezone_not_now_timezone(): void {
		// 30 June in UTC is already 1 July in London (BST).
		$now    = self::instant( '2026-06-30 23:30:00' );
		$period = Periods::previous( Period::TYPE_MONTH, $now, null, self::zone( 'Europe/London' ) );

		$this->assert_period( Period::TYPE_MONTH, '2026-06-01 00:00:00', '2026-06-30 23:59:59', 'June 2026', $period );
		$this->assertSame( 'Europe/London', $period->start()->getTimezone()->getName() );
	}

	/**
	 * A run exactly at the run time still reports on the month that just ended.
	 */
	public function test_monthly_previous_at_run_time_still_returns_previous_month(): void {
		$period = Periods::previous( Period::TYPE_MONTH, self::instant( '2026-11-01 06:00:00', 'Europe/London' ), null, self::zone( 'Europe/London' ) );

		$this->assert_period( Period::TYPE_MONTH, '2026-10-01 00:00:00', '2026-10-31 23:59:59', 'October 2026', $period );
	}

	/**
	 * Calendar quarters apply when no contract start is set.
	 */
	public function test_quarterly_previous_uses_calendar_quarters_without_contract(): void {
		$period = Periods::previous( Period::TYPE_QUARTER, self::instant( '2026-11-13 12:00:00' ), null, self::utc() );

		$this->assert_period( Period::TYPE_QUARTER, '2026-07-01 00:00:00', '2026-09-30 23:59:59', 'Q3 2026', $period );
		$this->assertSame( 'AMC-NWT-2026-Q3', $period->report_id( 'NWT' ) );
	}

	/**
	 * Calendar quarters cross the year boundary.
	 */
	public function test_quarterly_previous_crosses_year_boundary(): void {
		$period = Periods::previous( Period::TYPE_QUARTER, self::instant( '2026-01-01 06:15:00' ), null, self::utc() );

		$this->assert_period( Period::TYPE_QUARTER, '2025-10-01 00:00:00', '2025-12-31 23:59:59', 'Q4 2025', $period );
	}

	/**
	 * Contract quarters start on the contract day of the month.
	 */
	public function test_quarterly_previous_follows_contract_start(): void {
		$contract = self::instant( '2025-04-15 00:00:00' );
		$period   = Periods::previous( Period::TYPE_QUARTER, self::instant( '2026-11-13 12:00:00' ), $contract, self::utc() );

		$this->assert_period( Period::TYPE_QUARTER, '2026-07-15 00:00:00', '2026-10-14 23:59:59', 'Q2 2026–27', $period );
		$this->assertSame( 'AMC-NWT-2026-Q2', $period->report_id( 'NWT' ) );
	}

	/**
	 * On the contract quarter boundary the previous quarter is already complete.
	 */
	public function test_quarterly_previous_at_contract_boundary(): void {
		$contract = self::instant( '2025-04-15 00:00:00' );
		$period   = Periods::previous( Period::TYPE_QUARTER, self::instant( '2026-10-15 06:15:00' ), $contract, self::utc() );

		$this->assert_period( Period::TYPE_QUARTER, '2026-07-15 00:00:00', '2026-10-14 23:59:59', 'Q2 2026–27', $period );
	}

	/**
	 * A contract day of 31 clamps when the quarter boundary month is shorter.
	 */
	public function test_quarterly_previous_clamps_month_end(): void {
		$contract = self::instant( '2025-01-31 00:00:00' );
		$period   = Periods::previous( Period::TYPE_QUARTER, self::instant( '2026-05-15 12:00:00' ), $contract, self::utc() );

		$this->assert_period( Period::TYPE_QUARTER, '2026-01-31 00:00:00', '2026-04-29 23:59:59', 'Q1 2026–27', $period );
	}

	/**
	 * Calendar quarterly runs fire on 1 January, 1 April, 1 July and 1 October.
	 */
	public function test_quarterly_next_run_uses_calendar_quarters(): void {
		$run = Periods::next_run( Period::TYPE_QUARTER, self::instant( '2026-11-13 12:00:00' ), null, self::utc() );

		$this->assertSame( '2027-01-01 06:15:00', $run->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Contract quarterly runs fire on the first day of the next contract quarter.
	 */
	public function test_quarterly_next_run_follows_contract(): void {
		$contract = self::instant( '2025-04-15 00:00:00' );
		$run      = Periods::next_run( Period::TYPE_QUARTER, self::instant( '2026-11-13 12:00:00' ), $contract, self::utc() );

		$this->assertSame( '2027-01-15 06:15:00', $run->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A contract day of 31 clamps to February's last day.
	 */
	public function test_quarterly_next_run_clamps_day_31(): void {
		$contract = self::instant( '2025-08-31 00:00:00' );
		$run      = Periods::next_run( Period::TYPE_QUARTER, self::instant( '2026-01-15 12:00:00' ), $contract, self::utc() );

		$this->assertSame( '2026-02-28 06:15:00', $run->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A contract day of 30 clamps to February's last day.
	 */
	public function test_quarterly_next_run_clamps_day_30(): void {
		$contract = self::instant( '2025-05-30 00:00:00' );
		$run      = Periods::next_run( Period::TYPE_QUARTER, self::instant( '2026-12-15 12:00:00' ), $contract, self::utc() );

		$this->assertSame( '2027-02-28 06:15:00', $run->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A clamped boundary run fires at its run time and rolls over one second later.
	 */
	public function test_quarterly_next_run_on_clamped_boundary_at_run_time(): void {
		$contract = self::instant( '2025-01-31 00:00:00' );

		$at_run = Periods::next_run( Period::TYPE_QUARTER, self::instant( '2026-04-30 06:15:00' ), $contract, self::utc() );
		$after  = Periods::next_run( Period::TYPE_QUARTER, self::instant( '2026-04-30 06:15:01' ), $contract, self::utc() );

		$this->assertSame( '2026-04-30 06:15:00', $at_run->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-07-31 06:15:00', $after->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Annual periods cover the previous calendar year when there is no contract.
	 */
	public function test_annual_previous_uses_calendar_year(): void {
		$period = Periods::previous( Period::TYPE_YEAR, self::instant( '2026-11-13 12:00:00' ), null, self::utc() );

		$this->assert_period( Period::TYPE_YEAR, '2025-01-01 00:00:00', '2025-12-31 23:59:59', 'Calendar year 2025', $period );
		$this->assertSame( 'AMC-NWT-2025', $period->report_id( 'NWT' ) );
	}

	/**
	 * Contract annual periods cover the previous 12 months.
	 */
	public function test_annual_previous_follows_contract_anniversary(): void {
		$contract = self::instant( '2025-04-15 00:00:00' );
		$period   = Periods::previous( Period::TYPE_YEAR, self::instant( '2026-04-15 06:30:00' ), $contract, self::utc() );

		$this->assert_period( Period::TYPE_YEAR, '2025-04-15 00:00:00', '2026-04-14 23:59:59', 'Contract year 2025–26', $period );
		$this->assertSame( 'AMC-NWT-2025-26', $period->report_id( 'NWT' ) );
	}

	/**
	 * Before the anniversary, the previous contract year is still the last complete one.
	 */
	public function test_annual_previous_before_anniversary_returns_previous_contract_year(): void {
		$contract = self::instant( '2025-04-15 00:00:00' );
		$period   = Periods::previous( Period::TYPE_YEAR, self::instant( '2026-04-14 12:00:00' ), $contract, self::utc() );

		$this->assert_period( Period::TYPE_YEAR, '2024-04-15 00:00:00', '2025-04-14 23:59:59', 'Contract year 2024–25', $period );
	}

	/**
	 * Calendar annual runs fire on 1 January.
	 */
	public function test_annual_next_run_uses_calendar_year(): void {
		$run = Periods::next_run( Period::TYPE_YEAR, self::instant( '2026-11-13 12:00:00' ), null, self::utc() );

		$this->assertSame( '2027-01-01 06:30:00', $run->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Contract annual runs fire on the contract anniversary.
	 */
	public function test_annual_next_run_follows_contract_anniversary(): void {
		$contract = self::instant( '2025-04-15 00:00:00' );
		$run      = Periods::next_run( Period::TYPE_YEAR, self::instant( '2026-11-13 12:00:00' ), $contract, self::utc() );

		$this->assertSame( '2027-04-15 06:30:00', $run->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A 29 February contract clamps to 28 February in a common year.
	 */
	public function test_leap_day_contract_annual_next_run_clamps_to_february_28(): void {
		$contract = self::instant( '2024-02-29 00:00:00' );
		$run      = Periods::next_run( Period::TYPE_YEAR, self::instant( '2025-01-01 12:00:00' ), $contract, self::utc() );

		$this->assertSame( '2025-02-28 06:30:00', $run->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A 29 February contract uses 29 February in a leap year.
	 */
	public function test_leap_day_contract_annual_next_run_uses_february_29_in_leap_year(): void {
		$contract = self::instant( '2024-02-29 00:00:00' );
		$run      = Periods::next_run( Period::TYPE_YEAR, self::instant( '2028-01-01 12:00:00' ), $contract, self::utc() );

		$this->assertSame( '2028-02-29 06:30:00', $run->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A 29 February contract produces contract years that clamp on both ends.
	 */
	public function test_leap_day_contract_previous_period(): void {
		$contract = self::instant( '2024-02-29 00:00:00' );

		$early = Periods::previous( Period::TYPE_YEAR, self::instant( '2025-03-01 12:00:00' ), $contract, self::utc() );
		$late  = Periods::previous( Period::TYPE_YEAR, self::instant( '2029-01-01 12:00:00' ), $contract, self::utc() );

		$this->assert_period( Period::TYPE_YEAR, '2024-02-29 00:00:00', '2025-02-27 23:59:59', 'Contract year 2024–25', $early );
		$this->assert_period( Period::TYPE_YEAR, '2027-02-28 00:00:00', '2028-02-28 23:59:59', 'Contract year 2027–28', $late );
	}

	/**
	 * A 29 February contract clamps quarterly boundaries in February.
	 */
	public function test_leap_day_contract_quarter_next_run_clamps(): void {
		$contract = self::instant( '2024-02-29 00:00:00' );
		$run      = Periods::next_run( Period::TYPE_QUARTER, self::instant( '2026-12-01 12:00:00' ), $contract, self::utc() );

		$this->assertSame( '2027-02-28 06:15:00', $run->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Monthly runs fire on the first day of the next month at 06:00.
	 */
	public function test_monthly_next_run_fires_on_the_first_at_six(): void {
		$run = Periods::next_run( Period::TYPE_MONTH, self::instant( '2026-11-13 12:00:00' ), null, self::utc() );

		$this->assertSame( '2026-12-01 06:00:00', $run->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A moment exactly at the run time returns that same instant.
	 */
	public function test_next_run_at_exact_run_time_returns_itself(): void {
		$now = self::instant( '2026-12-01 06:00:00' );
		$run = Periods::next_run( Period::TYPE_MONTH, $now, null, self::utc() );

		$this->assertSame( $now->getTimestamp(), $run->getTimestamp() );
	}

	/**
	 * One second after the run time the next month's run is returned.
	 */
	public function test_next_run_after_run_time_rolls_to_the_next_month(): void {
		$run = Periods::next_run( Period::TYPE_MONTH, self::instant( '2026-12-01 06:00:01' ), null, self::utc() );

		$this->assertSame( '2027-01-01 06:00:00', $run->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Monthly runs follow Europe/London DST.
	 */
	public function test_monthly_next_run_respects_london_dst(): void {
		$run = Periods::next_run( Period::TYPE_MONTH, self::instant( '2026-03-20 12:00:00' ), null, self::zone( 'Europe/London' ) );

		$this->assertSame( '2026-04-01 06:00:00+01:00', $run->format( 'Y-m-d H:i:sP' ) );
		$this->assertSame( 'Europe/London', $run->getTimezone()->getName() );
	}

	/**
	 * Monthly runs follow America/New_York DST.
	 */
	public function test_monthly_next_run_respects_new_york_dst(): void {
		$run = Periods::next_run( Period::TYPE_MONTH, self::instant( '2026-10-15 12:00:00' ), null, self::zone( 'America/New_York' ) );

		$this->assertSame( '2026-11-01 06:00:00-05:00', $run->format( 'Y-m-d H:i:sP' ) );
	}

	/**
	 * Period boundaries follow the London DST change inside the month.
	 */
	public function test_monthly_period_boundaries_respect_london_dst(): void {
		$period = Periods::previous( Period::TYPE_MONTH, self::instant( '2026-11-05 12:00:00' ), null, self::zone( 'Europe/London' ) );

		$this->assertSame( '2026-10-01 00:00:00+01:00', $period->start()->format( 'Y-m-d H:i:sP' ) );
		$this->assertSame( '2026-10-31 23:59:59+00:00', $period->end()->format( 'Y-m-d H:i:sP' ) );
		$this->assertSame( self::instant( '2026-09-30 23:00:00' )->getTimestamp(), $period->start()->getTimestamp() );
		$this->assertSame( self::instant( '2026-10-31 23:59:59' )->getTimestamp(), $period->end()->getTimestamp() );
	}

	/**
	 * Period boundaries follow the New York DST change inside the month.
	 */
	public function test_monthly_period_boundaries_respect_new_york_dst(): void {
		$period = Periods::previous( Period::TYPE_MONTH, self::instant( '2026-04-10 12:00:00' ), null, self::zone( 'America/New_York' ) );

		$this->assertSame( '2026-03-01 00:00:00-05:00', $period->start()->format( 'Y-m-d H:i:sP' ) );
		$this->assertSame( '2026-03-31 23:59:59-04:00', $period->end()->format( 'Y-m-d H:i:sP' ) );
		$this->assertSame( self::instant( '2026-03-01 05:00:00' )->getTimestamp(), $period->start()->getTimestamp() );
		$this->assertSame( self::instant( '2026-04-01 03:59:59' )->getTimestamp(), $period->end()->getTimestamp() );
	}

	/**
	 * A monthly run is due exactly at its run time.
	 */
	public function test_is_due_at_exact_run_time(): void {
		$this->assertTrue( Periods::is_due( Period::TYPE_MONTH, self::instant( '2026-12-01 06:00:00' ), null, self::utc() ) );
	}

	/**
	 * A monthly run is not due one second before its run time.
	 */
	public function test_is_due_one_second_before_run_time(): void {
		$this->assertFalse( Periods::is_due( Period::TYPE_MONTH, self::instant( '2026-12-01 05:59:59' ), null, self::utc() ) );
	}

	/**
	 * A late run is still due later on the same local day.
	 */
	public function test_is_due_later_on_the_same_day(): void {
		$this->assertTrue( Periods::is_due( Period::TYPE_MONTH, self::instant( '2026-12-01 12:00:00' ), null, self::utc() ) );
	}

	/**
	 * A monthly run is not due on any other day.
	 */
	public function test_is_due_is_false_on_other_days(): void {
		$this->assertFalse( Periods::is_due( Period::TYPE_MONTH, self::instant( '2026-11-30 23:59:59' ), null, self::utc() ) );
		$this->assertFalse( Periods::is_due( Period::TYPE_MONTH, self::instant( '2026-12-02 00:00:00' ), null, self::utc() ) );
	}

	/**
	 * Quarterly and annual run times are checked independently.
	 */
	public function test_is_due_for_quarterly_and_annual_runs(): void {
		$contract = self::instant( '2025-04-15 00:00:00' );

		$this->assertTrue( Periods::is_due( Period::TYPE_QUARTER, self::instant( '2026-10-01 06:15:00' ), null, self::utc() ) );
		$this->assertTrue( Periods::is_due( Period::TYPE_YEAR, self::instant( '2027-01-01 06:30:00' ), null, self::utc() ) );
		$this->assertTrue( Periods::is_due( Period::TYPE_YEAR, self::instant( '2027-04-15 06:30:00' ), $contract, self::utc() ) );
		$this->assertFalse( Periods::is_due( Period::TYPE_YEAR, self::instant( '2027-04-15 06:29:59' ), $contract, self::utc() ) );
	}

	/**
	 * A custom period normalises its range to whole days.
	 */
	public function test_custom_period_normalises_range_and_label(): void {
		$period = Periods::custom( self::instant( '2026-10-01 15:00:00' ), self::instant( '2026-10-31 09:00:00' ), self::utc() );

		$this->assert_period( Period::TYPE_CUSTOM, '2026-10-01 00:00:00', '2026-10-31 23:59:59', '1 Oct 2026 – 31 Oct 2026', $period );
		$this->assertSame( 'AMC-NWT-20261001-20261031', $period->report_id( 'NWT' ) );
	}

	/**
	 * A custom period can cover a single day.
	 */
	public function test_custom_period_allows_a_single_day(): void {
		$period = Periods::custom( self::instant( '2026-10-15 08:00:00' ), self::instant( '2026-10-15 17:00:00' ), self::utc() );

		$this->assert_period( Period::TYPE_CUSTOM, '2026-10-15 00:00:00', '2026-10-15 23:59:59', '15 Oct 2026', $period );
		$this->assertSame( 'AMC-NWT-20261015-20261015', $period->report_id( 'NWT' ) );
	}

	/**
	 * Custom ranges are interpreted in the site timezone.
	 */
	public function test_custom_period_converts_to_site_timezone(): void {
		$period = Periods::custom( self::instant( '2026-09-30 23:30:00' ), self::instant( '2026-10-31 12:00:00' ), self::zone( 'Europe/London' ) );

		$this->assertSame( '2026-10-01 00:00:00+01:00', $period->start()->format( 'Y-m-d H:i:sP' ) );
		$this->assertSame( '2026-10-31 23:59:59+00:00', $period->end()->format( 'Y-m-d H:i:sP' ) );
		$this->assertSame( '1 Oct 2026 – 31 Oct 2026', $period->label() );
	}

	/**
	 * A custom period cannot start after it ends.
	 */
	public function test_custom_period_rejects_inverted_range(): void {
		$this->expectException( \InvalidArgumentException::class );

		Periods::custom( self::instant( '2026-11-01 00:00:00' ), self::instant( '2026-10-31 23:59:59' ), self::utc() );
	}

	/**
	 * Custom periods have no previous window.
	 */
	public function test_previous_rejects_custom_type(): void {
		$this->expectException( \InvalidArgumentException::class );

		Periods::previous( Period::TYPE_CUSTOM, self::instant( '2026-11-13 12:00:00' ), null, self::utc() );
	}

	/**
	 * Unknown period types are rejected by previous().
	 */
	public function test_previous_rejects_unknown_type(): void {
		$this->expectException( \InvalidArgumentException::class );

		Periods::previous( 'week', self::instant( '2026-11-13 12:00:00' ), null, self::utc() );
	}

	/**
	 * Custom periods have no schedule.
	 */
	public function test_next_run_rejects_custom_type(): void {
		$this->expectException( \InvalidArgumentException::class );

		Periods::next_run( Period::TYPE_CUSTOM, self::instant( '2026-11-13 12:00:00' ), null, self::utc() );
	}

	/**
	 * Unknown types are rejected by next_run().
	 */
	public function test_next_run_rejects_unknown_type(): void {
		$this->expectException( \InvalidArgumentException::class );

		Periods::next_run( 'week', self::instant( '2026-11-13 12:00:00' ), null, self::utc() );
	}

	/**
	 * Custom periods have no due check.
	 */
	public function test_is_due_rejects_custom_type(): void {
		$this->expectException( \InvalidArgumentException::class );

		Periods::is_due( Period::TYPE_CUSTOM, self::instant( '2026-11-13 12:00:00' ), null, self::utc() );
	}

	/**
	 * The value object rejects an inverted range.
	 */
	public function test_period_rejects_inverted_range(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Period( Period::TYPE_MONTH, self::instant( '2026-10-31 00:00:00' ), self::instant( '2026-10-01 00:00:00' ), 'Bad range' );
	}

	/**
	 * Site codes are upper-cased and stripped of separators in report IDs.
	 */
	public function test_report_id_normalises_site_code(): void {
		$period = Periods::previous( Period::TYPE_MONTH, self::instant( '2026-11-13 12:00:00' ), null, self::utc() );

		$this->assertSame( 'AMC-NORTH-WEST-2026-10', $period->report_id( ' north west ' ) );
	}

	/**
	 * A report ID requires a usable site code.
	 */
	public function test_report_id_requires_a_site_code(): void {
		$this->expectException( \InvalidArgumentException::class );

		$period = Periods::previous( Period::TYPE_MONTH, self::instant( '2026-11-13 12:00:00' ), null, self::utc() );
		$period->report_id( ' - ' );
	}

	/**
	 * A contract start is a calendar date: building it at UTC midnight must not
	 * move the anchor to the previous day for a site west of UTC.
	 */
	public function test_contract_start_is_a_calendar_date_not_an_instant(): void {
		$tz       = self::zone( 'America/New_York' );
		$contract = self::instant( '2025-07-15 00:00:00' ); // UTC midnight.

		$run = Periods::next_run( Period::TYPE_QUARTER, self::instant( '2026-10-01 00:00:00', 'America/New_York' ), $contract, $tz );

		$this->assertSame( '2026-10-15 06:15:00', $run->format( 'Y-m-d H:i:s' ) );

		$period = Periods::previous( Period::TYPE_QUARTER, $run, $contract, $tz );

		$this->assertSame( '2026-07-15 00:00:00', $period->start()->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-10-14 23:59:59', $period->end()->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Quarterly and annual runs keep their local time across DST changes, so
	 * the UTC instant moves while 06:15 / 06:30 site time does not.
	 */
	public function test_quarterly_and_annual_runs_keep_local_time_across_dst(): void {
		$tz = self::zone( 'Europe/London' );

		// 1 April is BST (UTC+1): 06:15 local is 05:15 UTC.
		$quarterly = Periods::next_run( Period::TYPE_QUARTER, self::instant( '2026-03-15 00:00:00', 'Europe/London' ), null, $tz );
		$this->assertSame( '2026-04-01 06:15:00', $quarterly->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-04-01 05:15:00', $quarterly->setTimezone( self::utc() )->format( 'Y-m-d H:i:s' ) );

		// 1 October is still BST, 1 January is GMT: same local time, different offset.
		$winter = Periods::next_run( Period::TYPE_QUARTER, self::instant( '2026-10-02 00:00:00', 'Europe/London' ), null, $tz );
		$this->assertSame( '2027-01-01 06:15:00', $winter->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2027-01-01 06:15:00', $winter->setTimezone( self::utc() )->format( 'Y-m-d H:i:s' ) );

		// An annual anniversary on the spring-forward day itself.
		$contract = self::instant( '2024-03-29 00:00:00', 'Europe/London' );
		$annual   = Periods::next_run( Period::TYPE_YEAR, self::instant( '2026-03-15 00:00:00', 'Europe/London' ), $contract, $tz );
		$this->assertSame( '2026-03-29 06:30:00', $annual->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-03-29 05:30:00', $annual->setTimezone( self::utc() )->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * UTC timezone.
	 *
	 * @return DateTimeZone UTC timezone.
	 */
	private static function utc(): DateTimeZone {
		return new DateTimeZone( 'UTC' );
	}

	/**
	 * Named timezone.
	 *
	 * @param string $name Timezone name.
	 *
	 * @return DateTimeZone Timezone.
	 */
	private static function zone( string $name ): DateTimeZone {
		return new DateTimeZone( $name );
	}

	/**
	 * Create an instant.
	 *
	 * @param string $time     Date and time.
	 * @param string $timezone Timezone name.
	 *
	 * @return DateTimeImmutable Instant.
	 */
	private static function instant( string $time, string $timezone = 'UTC' ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( $timezone ) );
	}

	/**
	 * Assert the type, boundaries and label of a period.
	 *
	 * @param string $type   Expected type.
	 * @param string $start  Expected local start.
	 * @param string $end    Expected local end.
	 * @param string $label  Expected label.
	 * @param Period $period Period under test.
	 */
	private function assert_period( string $type, string $start, string $end, string $label, Period $period ): void {
		$this->assertSame( $type, $period->type() );
		$this->assertSame( $start, $period->start()->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( $end, $period->end()->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( $label, $period->label() );
	}
}
