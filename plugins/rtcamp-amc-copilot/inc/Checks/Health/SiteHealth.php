<?php
/**
 * Check: health.site_health.
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
use AMC\Support\CoreSiteHealth;

/**
 * Class SiteHealth
 *
 * Runs the WP_Site_Health tests the way core's own scheduled check does
 * (`WP_Site_Health::wp_cron_scheduled_check()`): the direct tests, plus the
 * asynchronous ones that offer an `async_direct_test`, skipping `skip_cron`
 * tests. The page cache test is left to `perf.cache` (three loopback
 * requests). Critical results become MEDIUM findings and recommended results
 * LOW findings; `AlertRules` routes both to the daily digest only.
 *
 * Result::$data shape:
 *   tests list<array{id, label, status, critical}>; a test that threw has status "error"
 *   critical int, recommended int, good int, errors int
 *
 * Status: WARN when any result is critical (or an unknown status), otherwise PASS.
 *
 * Cost: the HTTP tests (WordPress.org, loopback, HTTPS) are capped at
 * HTTP_TIMEOUT seconds each, and no new test starts after TIME_BUDGET seconds.
 *
 * @since 0.1.0
 */
final class SiteHealth implements Check {

	/**
	 * Core test ids that another check owns.
	 *
	 * @var list<string>
	 */
	private const SKIPPED_TESTS = [ 'page_cache' ];

	/**
	 * Cap, in seconds, on every HTTP request made while the core tests run.
	 */
	private const HTTP_TIMEOUT = 5;

	/**
	 * Seconds after which no further test is started (the plan's budget is 20).
	 */
	private const TIME_BUDGET = 15;

	/**
	 * Returns the direct Site Health test results.
	 *
	 * @var callable(): list<array{id: string, label: string, status: string}>
	 */
	private $tests_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $tests_source Test seam; default runs the WP_Site_Health tests.
	 */
	public function __construct( ?callable $tests_source = null ) {
		$this->tests_source = $tests_source ?? static fn (): array => self::run_core_tests();
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'health.site_health';
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
		return __( 'Site health', 'rtcamp-amc-copilot' );
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
	 * @param Context $ctx Run context (unused here).
	 */
	public function run( Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the Check contract.
		$tests       = (array) ( $this->tests_source )();
		$findings    = [];
		$good        = 0;
		$recommended = 0;
		$critical    = 0;
		$errors      = 0;

		foreach ( $tests as $test ) {
			if ( ! is_array( $test ) ) {
				continue;
			}

			$status = (string) ( $test['status'] ?? 'good' );

			if ( 'good' === $status ) {
				++$good;
			} elseif ( 'error' === $status ) {
				++$errors;
			} elseif ( 'recommended' === $status ) {
				++$recommended;

				$findings[] = new Finding(
					'site-health:' . (string) ( $test['id'] ?? '' ) . ':recommended',
					Severity::LOW,
					sprintf( /* translators: %s: Test label. */ __( 'Site health recommendation: %s.', 'rtcamp-amc-copilot' ), (string) ( $test['label'] ?? '' ) ),
					[
						'id'     => (string) ( $test['id'] ?? '' ),
						'status' => $status,
					]
				);
			} else {
				++$critical;

				$findings[] = new Finding(
					'site-health:' . (string) ( $test['id'] ?? '' ),
					Severity::MEDIUM,
					sprintf( /* translators: %s: Test label. */ __( 'Site health critical: %s.', 'rtcamp-amc-copilot' ), (string) ( $test['label'] ?? '' ) ),
					[
						'id'     => (string) ( $test['id'] ?? '' ),
						'status' => $status,
					]
				);
			}
		}

		$data = [
			'tests'       => $tests,
			'good'        => $good,
			'recommended' => $recommended,
			'critical'    => $critical,
			'errors'      => $errors,
		];

		if ( $critical > 0 ) {
			return Result::warn(
				sprintf(
					/* translators: %s: Number of critical results. */
					_n( '%s critical site health result.', '%s critical site health results.', $critical, 'rtcamp-amc-copilot' ),
					number_format_i18n( $critical )
				),
				$data,
				$findings
			);
		}

		return Result::pass( __( 'No critical site health results.', 'rtcamp-amc-copilot' ), $data, $findings );
	}

	/**
	 * Runs the core Site Health tests server-side, like wp_cron_scheduled_check().
	 *
	 * @return list<array{id: string, label: string, status: string, critical: bool}>
	 */
	private static function run_core_tests(): array {
		$health = CoreSiteHealth::instance();
		if ( null === $health ) {
			return [];
		}

		return CoreSiteHealth::with_http_timeout(
			static function () use ( $health ): array {
				$tests = \WP_Site_Health::get_tests();

				return self::run_tests( $health, (array) ( $tests['direct'] ?? [] ), (array) ( $tests['async'] ?? [] ) );
			},
			self::HTTP_TIMEOUT
		);
	}

	/**
	 * Runs the direct tests, then the async tests that can run directly.
	 *
	 * @param \WP_Site_Health      $health Core instance.
	 * @param array<string, mixed> $direct Core `direct` tests keyed by id.
	 * @param array<string, mixed> $async  Core `async` tests keyed by id.
	 *
	 * @return list<array{id: string, label: string, status: string, critical: bool}>
	 */
	private static function run_tests( \WP_Site_Health $health, array $direct, array $async ): array {
		$deadline = microtime( true ) + self::TIME_BUDGET;
		$out      = [];

		foreach ( [ $direct, $async ] as $index => $group ) {
			foreach ( $group as $id => $test ) {
				$id = (string) $id;

				if ( ! is_array( $test ) || ! empty( $test['skip_cron'] ) || in_array( $id, self::SKIPPED_TESTS, true ) ) {
					continue;
				}

				if ( microtime( true ) > $deadline ) {
					break 2;
				}

				$callback = 1 === $index ? self::async_callback( $test ) : self::direct_callback( $health, $test );
				if ( null === $callback ) {
					continue;
				}

				try {
					// Same filter core's perform_test() applies.
					$result = apply_filters( 'site_status_test_result', call_user_func( $callback ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter.
				} catch ( \Throwable $e ) {
					$out[] = [
						'id'       => $id,
						'label'    => (string) ( $test['label'] ?? $id ),
						'status'   => 'error',
						'critical' => false,
					];
					continue;
				}

				if ( ! is_array( $result ) ) {
					continue;
				}

				$status = (string) ( $result['status'] ?? 'good' );
				$out[]  = [
					'id'       => $id,
					'label'    => (string) ( $result['label'] ?? '' ?: ( $test['label'] ?? $id ) ),
					'status'   => $status,
					'critical' => 'critical' === $status,
				];
			}
		}

		return $out;
	}

	/**
	 * Resolves a direct test: a `get_test_<name>` method name or any callable.
	 *
	 * @param \WP_Site_Health      $health Core instance.
	 * @param array<string, mixed> $test   Core test definition.
	 */
	private static function direct_callback( \WP_Site_Health $health, array $test ): ?callable {
		$name = $test['test'] ?? null;

		if ( is_string( $name ) ) {
			$method = sprintf( 'get_test_%s', $name );

			if ( method_exists( $health, $method ) && is_callable( [ $health, $method ] ) ) {
				return [ $health, $method ];
			}
		}

		return is_callable( $name ) ? $name : null;
	}

	/**
	 * Resolves an async test to its `async_direct_test` callable; the REST-only ones are skipped.
	 *
	 * @param array<string, mixed> $test Core test definition.
	 */
	private static function async_callback( array $test ): ?callable {
		$direct = $test['async_direct_test'] ?? null;

		return ! empty( $direct ) && is_callable( $direct ) ? $direct : null;
	}
}
