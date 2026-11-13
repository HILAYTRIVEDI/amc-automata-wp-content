<?php
/**
 * Alert routing: immediate, digest or none, per check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Notify;

use AMC\Checks\Severity;

/**
 * Class AlertRules
 *
 * One entry per check id, taken from the "Alert" column of the plan's checks
 * catalogue. A rule is a route string ('immediate', 'digest', 'none') or a
 * callable( array $finding ): string for checks whose route depends on the
 * finding (severity, days left, kind of change).
 *
 * A check id that is not in the table routes to 'none': an unknown check never
 * emails. Add or change rules with the `amc_alert_rules` filter.
 *
 * Findings that are not `open` (accepted risk, muted, resolved) never route
 * anywhere: Notify must not alert on them.
 *
 * @since 0.1.0
 */
final class AlertRules {

	public const IMMEDIATE = 'immediate';
	public const DIGEST    = 'digest';
	public const NONE      = 'none';

	/**
	 * Days left on a certificate at or below which the alert is immediate.
	 */
	public const SSL_IMMEDIATE_DAYS = 14;

	/**
	 * The rule table (after the `amc_alert_rules` filter).
	 *
	 * @return array<string, string|callable>
	 */
	public static function table(): array {
		$rules = [
			// Updates.
			'updates.core'         => self::DIGEST,
			'updates.plugins'      => self::DIGEST,
			'updates.themes'       => self::DIGEST,
			'updates.php'          => self::DIGEST,
			'updates.readiness'    => self::NONE,

			// Vulnerabilities: High and Critical are immediate, the rest wait for the digest.
			'vuln.match'           => [ self::class, 'route_vulnerability' ],

			// Inventory: only closed or abandoned plugins raise findings (digest).
			'inventory.plugins'    => self::DIGEST,
			'inventory.themes'     => self::NONE,
			'inventory.mu_dropins' => self::NONE,
			'inventory.editor'     => self::NONE,

			// Errors: findings are keyed by signature, so a new row IS a new signature.
			'errors.fatal'         => self::IMMEDIATE,
			'errors.warnings'      => self::DIGEST,
			'errors.deprecations'  => self::DIGEST,

			// Smoke: the check raises a finding only once a URL has failed twice in a row.
			'smoke.urls'           => self::IMMEDIATE,

			// Security.
			'security.checksums'   => self::IMMEDIATE,
			'security.config'      => self::DIGEST,
			'security.admins'      => [ self::class, 'route_admin_change' ],
			'ssl.certificate'      => [ self::class, 'route_certificate' ],

			// Performance.
			'perf.response'        => self::DIGEST,
			'perf.database'        => self::DIGEST,
			'perf.cache'           => self::NONE,
			'perf.slow_queries'    => self::DIGEST,

			// Health.
			'health.site_health'   => self::DIGEST,
			'health.cron'          => self::DIGEST,

			// Users.
			'users.roles'          => self::DIGEST,
			'users.changes'        => self::NONE,

			// Hosting and detection never email.
			'hosting.platform'     => self::NONE,
			'detect.cdn'           => self::NONE,
			'detect.forms'         => self::NONE,
			'detect.mailer'        => self::NONE,
			'detect.analytics'     => self::NONE,
			'detect.accessibility' => self::NONE,
		];

		/**
		 * Filters the alert routing table.
		 *
		 * @param array<string, string|callable> $rules check_id => 'immediate'|'digest'|'none',
		 *                                              or callable( array $finding ): string.
		 */
		$filtered = apply_filters( 'amc_alert_rules', $rules );

		return is_array( $filtered ) ? $filtered : $rules;
	}

	/**
	 * Route one finding row.
	 *
	 * @param array<string, mixed> $finding FindingsRepository row.
	 *
	 * @return string One of IMMEDIATE, DIGEST, NONE.
	 */
	public static function route( array $finding ): string {
		if ( 'open' !== ( $finding['status'] ?? 'open' ) ) {
			return self::NONE;
		}

		$rules = self::table();
		$rule  = $rules[ (string) ( $finding['check_id'] ?? '' ) ] ?? self::NONE;
		$route = is_callable( $rule ) ? $rule( $finding ) : $rule;

		return in_array( $route, [ self::IMMEDIATE, self::DIGEST ], true ) ? $route : self::NONE;
	}

	/**
	 * Check ids that can email (immediate or digest, for at least some findings).
	 *
	 * @return list<string>
	 */
	public static function alerting_checks(): array {
		$ids = [];

		foreach ( self::table() as $check_id => $rule ) {
			if ( self::NONE !== $rule ) {
				$ids[] = (string) $check_id;
			}
		}

		return $ids;
	}

	/**
	 * `vuln.match`: High or Critical is immediate.
	 *
	 * @param array<string, mixed> $finding Finding row.
	 */
	public static function route_vulnerability( array $finding ): string {
		return self::severity( $finding )->at_least( Severity::HIGH ) ? self::IMMEDIATE : self::DIGEST;
	}

	/**
	 * `ssl.certificate`: 14 days or less is immediate. Reads `days_remaining`
	 * (or `days_left`) from the finding data; without it, falls back to High+.
	 *
	 * @param array<string, mixed> $finding Finding row.
	 */
	public static function route_certificate( array $finding ): string {
		$data = (array) ( $finding['data'] ?? [] );
		$days = $data['days_remaining'] ?? $data['days_left'] ?? null;

		if ( is_numeric( $days ) ) {
			return (int) $days <= self::SSL_IMMEDIATE_DAYS ? self::IMMEDIATE : self::DIGEST;
		}

		return self::severity( $finding )->at_least( Severity::HIGH ) ? self::IMMEDIATE : self::DIGEST;
	}

	/**
	 * `security.admins`: an administrator added is immediate; other changes wait.
	 * Reads `change` (or `action`) from the finding data; absent means "added".
	 *
	 * @param array<string, mixed> $finding Finding row.
	 */
	public static function route_admin_change( array $finding ): string {
		$data   = (array) ( $finding['data'] ?? [] );
		$change = (string) ( $data['change'] ?? $data['action'] ?? 'added' );

		return 'added' === $change ? self::IMMEDIATE : self::DIGEST;
	}

	/**
	 * Severity of a finding row (INFO when unknown).
	 *
	 * @param array<string, mixed> $finding Finding row.
	 */
	private static function severity( array $finding ): Severity {
		return Severity::tryFrom( (string) ( $finding['severity'] ?? '' ) ) ?? Severity::INFO;
	}
}
