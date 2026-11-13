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

/**
 * Class SiteHealth
 *
 * Runs the WP_Site_Health direct tests only (never the async ones) and
 * turns critical results into findings.
 *
 * Result::$data shape:
 *   tests list<array{id, label, status, critical}>
 *   critical int, recommended int, good int
 *
 * Status: PASS/WARN based on critical count.
 *
 * @since 0.1.0
 */
final class SiteHealth implements Check {

	/**
	 * Returns the direct Site Health test results.
	 *
	 * @var callable(): list<array{id: string, label: string, status: string}>
	 */
	private $tests_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $tests_source Test seam; default runs WP_Site_Health direct tests.
	 */
	public function __construct( ?callable $tests_source = null ) {
		$this->tests_source = $tests_source ?? static function (): array {
			if ( ! function_exists( 'wp_site_health' ) ) {
				return [];
			}

			$health = wp_site_health();
			$tests  = \WP_Site_Health::get_tests();
			$direct = $tests['direct'] ?? [];
			$out    = [];

			foreach ( $direct as $id => $test ) {
				if ( ! is_array( $test ) || empty( $test['test'] ) ) {
					continue;
				}

				$method = (string) $test['test'];
				if ( ! method_exists( $health, $method ) ) {
					continue;
				}

				$result = $health->{$method}();
				if ( ! is_array( $result ) ) {
					continue;
				}

				$out[] = [
					'id'       => (string) $id,
					'label'    => (string) ( $result['label'] ?? ( $test['label'] ?? $id ) ),
					'status'   => (string) ( $result['status'] ?? 'good' ),
					'critical' => 'critical' === ( $result['status'] ?? '' ),
				];
			}

			return $out;
		};
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

		foreach ( $tests as $test ) {
			if ( ! is_array( $test ) ) {
				continue;
			}

			$status = (string) ( $test['status'] ?? 'good' );

			if ( 'good' === $status ) {
				++$good;
			} elseif ( 'recommended' === $status ) {
				++$recommended;
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
		];

		if ( [] !== $findings ) {
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

		return Result::pass( __( 'No critical site health results.', 'rtcamp-amc-copilot' ), $data );
	}
}
