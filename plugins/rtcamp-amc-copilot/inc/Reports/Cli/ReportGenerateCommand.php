<?php
/**
 * `wp amc report generate` (stub, lane D1).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Cli;

use rtCamp\WPPrimitives\Contracts\Interfaces\CLICommand;

/**
 * Class ReportGenerateCommand
 *
 * Owner: lane D1. Wave 0 registers the command and its arguments only.
 * D1 replaces run() with a call to Reports\Generator.
 *
 *   wp amc report generate --period=month [--start=2026-10-01 --end=2026-10-31]
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
	public static function run( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub until D1 implements it.
		\WP_CLI::error( 'Not implemented yet (lane D1: Reports\Generator).' );
	}
}
