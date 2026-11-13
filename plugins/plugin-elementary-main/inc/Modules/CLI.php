<?php
/**
 * WP-CLI commands for AMC Automata Features Features.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules;

use rtCamp\WPPrimitives\Contracts\Interfaces\ConditionallyRegistrable;

/**
 * Class - CLI
 *
 * Registers WP-CLI commands for the plugin.
 */
final class CLI implements ConditionallyRegistrable {

	/**
	 * {@inheritDoc}
	 *
	 * Skip registration entirely when not running under WP-CLI.
	 */
	public function can_register(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Commands are added on init because their descriptions are translated.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_commands' ] );
	}

	/**
	 * Add the plugin's commands to WP-CLI.
	 */
	public function register_commands(): void {
		foreach ( $this->get_commands() as $name => $command ) {
			\WP_CLI::add_command(
				"amc-automata-features-features {$name}",
				$command['callback'],
				[
					'shortdesc' => $command['description'],
				]
			);
		}
	}

	/**
	 * Get available CLI commands.
	 *
	 * @return array<string, array{
	 *   callback: callable( array<int, mixed>, array<string, mixed> ): void,
	 *   description: string
	 * }>
	 */
	private function get_commands(): array {
		$commands = [
			CLI\Healthcheck::class,
		];

		return array_reduce(
			$commands,
			static function ( array $carry, string $command_class ): array {
				$carry[ $command_class::get_name() ] = [
					'callback'    => [ $command_class, 'run' ],
					'description' => $command_class::get_description(),
				];
				return $carry;
			},
			[]
		);
	}
}
