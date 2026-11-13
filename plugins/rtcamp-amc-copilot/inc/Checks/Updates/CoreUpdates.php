<?php
/**
 * Check: updates.core (WordPress core updates).
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

/**
 * Class CoreUpdates
 *
 * ==== EXEMPLAR CHECK: copy this file when you write a new check. ====
 *
 * Step-by-step pattern:
 *   1. id(), group(), label(), schedule(): plain values, no logic.
 *   2. Constructor takes optional callables for every WordPress read you
 *      want to fake in tests. Defaults call the real WordPress function.
 *   3. run() reads data through those callables only, builds a `$data`
 *      array (shape documented below), decides a Status, and creates
 *      Findings for things an engineer must act on.
 *   4. Never write to the site. Here that means we do NOT call
 *      wp_version_check() to refresh update data; we report how old the
 *      data is instead (WordPress refreshes it twice a day on its own).
 *
 * Result::$data shape (consumed by the Updates screen and report CMS section):
 *   current_version   string        Installed WordPress version.
 *   latest_version    string|null   Highest version offered, null if no data.
 *   update_available  bool
 *   update_type       'none'|'minor'|'major'
 *                      minor = same x.y branch (security/maintenance release)
 *                      major = new x.y branch
 *   last_checked      string|null   When WordPress last asked api.wordpress.org (UTC ISO 8601).
 *   offers            list<array{version: string, locale: string, php_version: string, mysql_version: string, dismissed: bool}>
 *
 * Status rules:
 *   no update data    -> INFO  (nothing to judge yet)
 *   up to date        -> PASS
 *   major available   -> WARN  + Finding severity MEDIUM
 *   minor available   -> FAIL  + Finding severity HIGH (security releases are minor)
 *
 * Finding dedupe key: 'core:<latest_version>' so a newer release is a new
 * finding (and alerts again), and updating resolves it.
 *
 * @since 0.1.0
 */
final class CoreUpdates implements Check {

	/**
	 * Returns the `update_core` site transient (what get_core_updates() reads).
	 *
	 * @var callable(): mixed
	 */
	private $updates_source;

	/**
	 * Returns the installed WordPress version.
	 *
	 * @var callable(): string
	 */
	private $version_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $updates_source Test seam; default reads the update_core site transient.
	 * @param callable|null $version_source Test seam; default wp_get_wp_version().
	 */
	public function __construct( ?callable $updates_source = null, ?callable $version_source = null ) {
		$this->updates_source = $updates_source ?? static fn (): mixed => get_site_transient( 'update_core' );
		$this->version_source = $version_source ?? static fn (): string => wp_get_wp_version();
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'updates.core';
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
		return __( 'WordPress core updates', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::TWICE_DAILY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context (unused here: core updates are network-wide).
	 */
	public function run( Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the Check contract.
		$current   = (string) ( $this->version_source )();
		$transient = ( $this->updates_source )();

		$data = [
			'current_version'  => $current,
			'latest_version'   => null,
			'update_available' => false,
			'update_type'      => 'none',
			'last_checked'     => null,
			'offers'           => [],
		];

		// 1. No data yet (fresh install, or update checks blocked): INFO, no findings.
		if ( ! is_object( $transient ) || ! isset( $transient->updates ) || ! is_array( $transient->updates ) ) {
			return Result::info( __( 'WordPress has not fetched core update data yet.', 'rtcamp-amc-copilot' ), $data );
		}

		if ( ! empty( $transient->last_checked ) ) {
			$data['last_checked'] = gmdate( 'c', (int) $transient->last_checked );
		}

		// 2. Normalise the offers (same filtering as get_core_updates(), but
		// keep dismissed ones: a dismissed update is still pending).
		$dismissed = get_site_option( 'dismissed_update_core' );
		$dismissed = is_array( $dismissed ) ? $dismissed : [];
		$latest    = $current;

		foreach ( $transient->updates as $offer ) {
			if ( ! is_object( $offer ) || 'upgrade' !== ( $offer->response ?? '' ) ) {
				continue; // 'latest', 'autoupdate' and 'development' are not pending updates.
			}

			$version = (string) ( $offer->current ?? $offer->version ?? '' );
			if ( '' === $version ) {
				continue;
			}

			$data['offers'][] = [
				'version'       => $version,
				'locale'        => (string) ( $offer->locale ?? 'en_US' ),
				'php_version'   => (string) ( $offer->php_version ?? '' ),
				'mysql_version' => (string) ( $offer->mysql_version ?? '' ),
				'dismissed'     => array_key_exists( $version . '|' . ( $offer->locale ?? 'en_US' ), $dismissed ),
			];

			if ( version_compare( $version, $latest, '>' ) ) {
				$latest = $version;
			}
		}

		$data['latest_version'] = $latest;

		// 3. Up to date.
		if ( version_compare( $latest, $current, '<=' ) ) {
			return Result::pass(
				/* translators: %s: WordPress version. */
				sprintf( __( 'WordPress %s is up to date.', 'rtcamp-amc-copilot' ), $current ),
				$data
			);
		}

		// 4. Update pending: minor (same branch) or major (new branch).
		$is_major                 = self::branch( $latest ) !== self::branch( $current );
		$data['update_available'] = true;
		$data['update_type']      = $is_major ? 'major' : 'minor';

		$title = sprintf(
			/* translators: 1: Available version, 2: Installed version. */
			__( 'WordPress %1$s is available (installed: %2$s).', 'rtcamp-amc-copilot' ),
			$latest,
			$current
		);

		$finding = new Finding(
			'core:' . $latest,
			$is_major ? Severity::MEDIUM : Severity::HIGH,
			$title,
			[
				'current_version' => $current,
				'latest_version'  => $latest,
				'update_type'     => $data['update_type'],
			]
		);

		return $is_major
			? Result::warn( $title, $data, [ $finding ] )
			: Result::fail( $title, $data, [ $finding ] );
	}

	/**
	 * The x.y branch of a version ('6.9.1' -> '6.9').
	 *
	 * @param string $version Version.
	 */
	private static function branch( string $version ): string {
		return implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
	}
}
