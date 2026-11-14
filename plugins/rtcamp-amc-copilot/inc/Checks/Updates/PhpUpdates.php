<?php
/**
 * Check: updates.php (PHP version).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Updates;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Support\AdminFunctions;

/**
 * Class PhpUpdates
 *
 * Compares the installed PHP version against what WordPress currently
 * considers supported (`wp_check_php_version()`, which asks the Serve Happy
 * API and caches its answer for a week), and flags active plugins whose
 * "Requires PHP" header is higher than the installed version.
 *
 * Result::$data shape (consumed by the Updates screen and report CMS section):
 *   installed           string       Installed PHP version (phpversion()).
 *   recommended         string|null  Latest PHP version WordPress recommends, null if no data.
 *   is_acceptable       bool|null    Whether WordPress considers this version acceptable (null = no data).
 *   lower_than_future_minimum bool Whether PHP is below the future minimum (8.0).
 *   blocking_plugins    list<array{file: string, name: string, version: string, requires_php: string}>
 *                                    Active plugins that require a newer PHP than installed.
 *   active_total        int          Active plugins checked.
 *
 * Status rules:
 *   no data (API down, older WordPress)         -> INFO
 *   version acceptable, nothing blocking        -> PASS
 *   not acceptable / below future minimum       -> WARN + Finding MEDIUM
 *   an active plugin requires a newer PHP       -> WARN + Finding HIGH
 *
 * Finding dedupe keys:
 *   'php:<installed_version>'      for the version itself
 *   'php-plugin:<file>'            for each blocking plugin (changes when the
 *                                  plugin is updated with a lower requirement)
 *
 * @since 0.1.0
 */
final class PhpUpdates implements Check {

	/**
	 * Returns the installed PHP version.
	 *
	 * @var callable(): string
	 */
	private $version_source;

	/**
	 * Returns the Serve Happy response, or null when unavailable.
	 *
	 * @var callable(): mixed
	 */
	private $check_source;

	/**
	 * Returns the installed plugins, keyed by file.
	 *
	 * @var callable(): array<string, array<string, mixed>>
	 */
	private $plugins_source;

	/**
	 * Returns the active plugin files.
	 *
	 * @var callable(): list<string>
	 */
	private $active_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $version_source Test seam; default phpversion().
	 * @param callable|null $check_source   Test seam; default wp_check_php_version() (Serve Happy API, cached).
	 * @param callable|null $plugins_source Test seam; default get_plugins().
	 * @param callable|null $active_source  Test seam; default get_option( 'active_plugins' ).
	 */
	public function __construct(
		?callable $version_source = null,
		?callable $check_source = null,
		?callable $plugins_source = null,
		?callable $active_source = null
	) {
		$this->version_source = $version_source ?? static fn (): string => (string) phpversion();
		$this->check_source   = $check_source ?? static function (): mixed {
			if ( ! AdminFunctions::ensure( 'wp_check_php_version' ) ) {
				return null;
			}

			return wp_check_php_version();
		};
		$this->plugins_source = $plugins_source ?? static fn (): array => AdminFunctions::ensure( 'get_plugins' ) ? get_plugins() : [];
		$this->active_source  = $active_source ?? static fn (): array => (array) get_option( 'active_plugins', [] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'updates.php';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::UPDATES;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'PHP version', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::DAILY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context (unused here: the PHP version is host-wide).
	 */
	public function run( Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the Check contract.
		$installed = ( $this->version_source )();
		$response  = ( $this->check_source )();

		$data = [
			'installed'                 => $installed,
			'recommended'               => null,
			'is_acceptable'             => null,
			'lower_than_future_minimum' => false,
			'blocking_plugins'          => [],
			'active_total'              => 0,
		];

		// 1. Active plugins requiring a newer PHP than installed.
		$blocking             = [];
		$active               = (array) ( $this->active_source )();
		$plugins              = (array) ( $this->plugins_source )();
		$data['active_total'] = count( $active );

		foreach ( $active as $file ) {
			$meta     = is_array( $plugins[ $file ] ?? null ) ? $plugins[ $file ] : [];
			$requires = (string) ( $meta['RequiresPHP'] ?? '' );

			if ( '' === $requires || version_compare( $installed, $requires, '>=' ) ) {
				continue;
			}

			$blocking[] = [
				'file'         => (string) $file,
				'name'         => (string) ( $meta['Name'] ?? $file ),
				'version'      => (string) ( $meta['Version'] ?? '' ),
				'requires_php' => $requires,
			];
		}

		$data['blocking_plugins'] = $blocking;

		// 2. No Serve Happy data (API down, or WordPress too old): still report blockers.
		if ( ! is_array( $response ) ) {
			if ( [] !== $blocking ) {
				$finding = $this->blocking_finding( $blocking );

				return Result::warn(
					__( 'WordPress could not verify the PHP version; an active plugin requires a newer PHP.', 'rtcamp-amc-copilot' ),
					$data,
					[ $finding ]
				);
			}

			return Result::info( __( 'PHP version data from WordPress.org is not available yet.', 'rtcamp-amc-copilot' ), $data );
		}

		$data['recommended']               = self::string_or_null( $response['recommended_version'] ?? null );
		$data['is_acceptable']             = ! empty( $response['is_acceptable'] );
		$data['lower_than_future_minimum'] = ! empty( $response['is_lower_than_future_minimum'] );

		$findings = [];

		// 3. Active plugin needs a newer PHP: the most urgent outcome.
		if ( [] !== $blocking ) {
			$findings[] = $this->blocking_finding( $blocking );
		}

		// 4. The version itself is not acceptable.
		if ( ! $data['is_acceptable'] || $data['lower_than_future_minimum'] ) {
			$findings[] = new Finding(
				'php:' . $installed,
				Severity::MEDIUM,
				sprintf(
					/* translators: 1: Installed PHP version, 2: Recommended PHP version. */
					__( 'PHP %1$s is below what WordPress recommends (%2$s).', 'rtcamp-amc-copilot' ),
					$installed,
					$data['recommended'] ?? __( 'unknown', 'rtcamp-amc-copilot' )
				),
				[
					'installed'   => $installed,
					'recommended' => $data['recommended'],
				]
			);
		}

		if ( [] !== $findings ) {
			return Result::warn(
				sprintf(
					/* translators: %s: Installed PHP version. */
					__( 'PHP %s needs attention.', 'rtcamp-amc-copilot' ),
					$installed
				),
				$data,
				$findings
			);
		}

		return Result::pass(
			sprintf(
				/* translators: %s: Installed PHP version. */
				__( 'PHP %s is supported by WordPress and every active plugin.', 'rtcamp-amc-copilot' ),
				$installed
			),
			$data
		);
	}

	/**
	 * Finding for active plugins that require a newer PHP.
	 *
	 * @param list<array<string, string>> $blocking Blocking plugins.
	 */
	private function blocking_finding( array $blocking ): Finding {
		$first = $blocking[0];

		return new Finding(
			'php-plugin:' . (string) $first['file'],
			Severity::HIGH,
			count( $blocking ) > 1
				? sprintf(
					/* translators: %s: Number of plugins. */
					_n(
						'%s active plugin requires a newer PHP than installed.',
						'%s active plugins require a newer PHP than installed.',
						count( $blocking ),
						'rtcamp-amc-copilot'
					),
					number_format_i18n( count( $blocking ) )
				)
				: sprintf(
					/* translators: 1: Plugin name, 2: Required PHP version, 3: Installed PHP version. */
					__( '%1$s requires PHP %2$s (installed: %3$s).', 'rtcamp-amc-copilot' ),
					(string) $first['name'],
					(string) $first['requires_php'],
					(string) $first['version'],
				),
			[
				'plugins' => $blocking,
			]
		);
	}

	/**
	 * String, or null when the value is empty.
	 *
	 * @param mixed $value Value.
	 */
	private static function string_or_null( mixed $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
