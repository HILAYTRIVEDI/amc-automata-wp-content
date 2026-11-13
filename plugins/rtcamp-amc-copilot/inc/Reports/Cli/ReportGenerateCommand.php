<?php
/**
 * `wp amc report generate`.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Cli;

use AMC\Reports\Generator;
use AMC\Reports\Period;
use rtCamp\WPPrimitives\Contracts\Interfaces\CLICommand;

/**
 * Class ReportGenerateCommand
 *
 * Owner: lane D1.
 *
 *   wp amc report generate --period=month [--start=2026-10-01 --end=2026-10-31]
 *
 *   --period  month | quarter | year | custom (default month).
 *   --start   month, quarter, year: any date inside the wanted period (default:
 *             the most recently completed one). custom: first day, required.
 *   --end     custom: last day, required. Ignored otherwise.
 *
 * Runs in this process: the snapshot is built now (including the fresh
 * inventory and detection checks), so it can take a minute. The report author is
 * the `--user` the command runs as. A period that already has a report is not
 * generated twice: the command prints the existing report instead.
 *
 * @since 0.1.0
 */
final class ReportGenerateCommand implements CLICommand {

	/**
	 * {@inheritDoc}
	 */
	public static function get_name(): string {
		return 'report generate';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_description(): string {
		return __( 'Generate a draft maintenance report.', 'rtcamp-amc-copilot' );
	}

	/**
	 * WP-CLI synopsis.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get_synopsis(): array {
		return [
			[
				'type'     => 'assoc',
				'name'     => 'period',
				'optional' => true,
				'default'  => 'month',
				'options'  => [ 'month', 'quarter', 'year', 'custom' ],
			],
			[
				'type'        => 'assoc',
				'name'        => 'start',
				'description' => 'Start date Y-m-d (custom periods).',
				'optional'    => true,
			],
			[
				'type'        => 'assoc',
				'name'        => 'end',
				'description' => 'End date Y-m-d (custom periods).',
				'optional'    => true,
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int, string>    $args       Unused.
	 * @param array<string, string> $assoc_args --period, --start, --end.
	 */
	public static function run( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- No positional arguments.
		$type = (string) ( $assoc_args['period'] ?? Period::TYPE_MONTH );

		if ( ! in_array( $type, [ Period::TYPE_MONTH, Period::TYPE_QUARTER, Period::TYPE_YEAR, Period::TYPE_CUSTOM ], true ) ) {
			\WP_CLI::error( 'Invalid --period. Use month, quarter, year or custom.' );
		}

		$generator = new Generator();
		$period    = $generator->resolve_period( $type, $assoc_args['start'] ?? null, $assoc_args['end'] ?? null );

		if ( $period instanceof \WP_Error ) {
			\WP_CLI::error( $period->get_error_message() );
		}

		$report_id = $generator->report_id( $period );
		$existing  = $generator->find_existing( $report_id );

		if ( null !== $existing ) {
			\WP_CLI::warning( sprintf( 'Report %s already exists (post %d). Nothing generated.', $report_id, $existing ) );
			\WP_CLI::log( (string) $existing );

			return;
		}

		\WP_CLI::log( sprintf( 'Generating %s (%s to %s)...', $report_id, $period->start()->format( 'Y-m-d' ), $period->end()->format( 'Y-m-d' ) ) );

		$post_id = $generator->generate( $period, get_current_user_id(), 'cli' );

		if ( $post_id instanceof \WP_Error ) {
			\WP_CLI::error( $post_id->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Draft report %s created (post %d).', $report_id, $post_id ) );
		\WP_CLI::log( Generator::edit_url( $post_id ) );
	}
}
