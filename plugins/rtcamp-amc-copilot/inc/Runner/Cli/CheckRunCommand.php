<?php
/**
 * `wp amc check run`.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Runner\Cli;

use AMC\Checks\Registry;
use AMC\Core\Installer;
use AMC\Runner\Runner;
use rtCamp\WPPrimitives\Contracts\Interfaces\CLICommand;

/**
 * Class CheckRunCommand
 *
 * Runs checks synchronously in the CLI process (trigger 'cli').
 *
 *   wp amc check run updates.core
 *   wp amc check run updates.core updates.plugins --format=json
 *   wp amc check run --all
 *
 * @since 0.1.0
 */
final class CheckRunCommand implements CLICommand {

	/**
	 * {@inheritDoc}
	 */
	public static function get_name(): string {
		return 'check run';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_description(): string {
		return __( 'Run one or more checks now and store the results.', 'rtcamp-amc-copilot' );
	}

	/**
	 * WP-CLI synopsis.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get_synopsis(): array {
		return [
			[
				'type'        => 'positional',
				'name'        => 'check-id',
				'description' => 'Check id(s), e.g. updates.core.',
				'optional'    => true,
				'repeating'   => true,
			],
			[
				'type'        => 'flag',
				'name'        => 'all',
				'description' => 'Run every registered check.',
				'optional'    => true,
			],
			[
				'type'        => 'assoc',
				'name'        => 'format',
				'description' => 'Output format.',
				'optional'    => true,
				'default'     => 'table',
				'options'     => [ 'table', 'json', 'csv', 'yaml' ],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int, string>    $args       Check ids.
	 * @param array<string, string> $assoc_args --all, --format.
	 */
	public static function run( array $args, array $assoc_args ): void {
		Installer::maybe_upgrade();

		$registry = new Registry();
		$ids      = ! empty( $assoc_args['all'] ) ? array_keys( $registry->all() ) : $args;

		if ( [] === $ids ) {
			\WP_CLI::error( 'Give at least one check id, or --all. See `wp amc check list`.' );
		}

		$runner = new Runner( $registry );
		$rows   = [];
		$failed = false;

		foreach ( $ids as $check_id ) {
			$result = $runner->run( (string) $check_id, 'cli', get_current_user_id() );

			if ( is_wp_error( $result ) ) {
				$failed = true;
				$rows[] = [
					'check'    => $check_id,
					'run'      => '',
					'status'   => 'error',
					'duration' => '',
					'summary'  => $result->get_error_message(),
				];
				continue;
			}

			$failed = $failed || 'error' === $result['status'];
			$rows[] = [
				'check'    => $check_id,
				'run'      => $result['id'],
				'status'   => $result['status'],
				'duration' => $result['duration_ms'] . ' ms',
				'summary'  => $result['summary'],
			];
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, [ 'check', 'run', 'status', 'duration', 'summary' ] );

		if ( $failed ) {
			\WP_CLI::halt( 1 );
		}
	}
}
