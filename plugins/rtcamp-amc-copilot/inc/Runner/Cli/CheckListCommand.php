<?php
/**
 * `wp amc check list`.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Runner\Cli;

use AMC\Checks\Registry;
use AMC\Core\Settings;
use AMC\Storage\RunsRepository;
use rtCamp\WPPrimitives\Contracts\Interfaces\CLICommand;

/**
 * Class CheckListCommand
 *
 * Usage:
 *
 *   wp amc check list
 *   wp amc check list --format=json
 *
 * @since 0.1.0
 */
final class CheckListCommand implements CLICommand {

	/**
	 * {@inheritDoc}
	 */
	public static function get_name(): string {
		return 'check list';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_description(): string {
		return __( 'List registered checks with their schedule and last result.', 'rtcamp-amc-copilot' );
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
				'name'     => 'format',
				'optional' => true,
				'default'  => 'table',
				'options'  => [ 'table', 'json', 'csv', 'yaml', 'ids' ],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int, string>    $args       Unused.
	 * @param array<string, string> $assoc_args --format.
	 */
	public static function run( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Contract signature.
		$latest = ( new RunsRepository() )->latest_per_check();
		$rows   = [];

		foreach ( ( new Registry() )->all() as $id => $check ) {
			$rows[] = [
				'id'          => $id,
				'group'       => $check->group()->value,
				'schedule'    => Registry::effective_schedule( $check )->value,
				'enabled'     => Settings::check_enabled( $id ) ? 'yes' : 'no',
				'last_status' => $latest[ $id ]['status'] ?? '',
				'last_run'    => $latest[ $id ]['created_at'] ?? '',
			];
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', array_column( $rows, 'id' ) ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, [ 'id', 'group', 'schedule', 'enabled', 'last_status', 'last_run' ] );
	}
}
