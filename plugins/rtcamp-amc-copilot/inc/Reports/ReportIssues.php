<?php
/**
 * Detects issues and failures in a maintenance report.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

use AMC\Admin\ConsolePage;

/**
 * Class ReportIssues
 *
 * Scans a report snapshot to identify failures and issues across all report
 * sections (security vulnerabilities, modified core files, failed config checks,
 * PHP fatal errors, smoke check failures, SSL certificate expiry or lack of HTTPS).
 *
 * Stored in post meta:
 *   _amc_has_failures: '1' | '0' (bool)
 *   _amc_issue_count:  int
 *   _amc_issues:       JSON-encoded list of detected issues
 *
 * @since 0.1.0
 */
final class ReportIssues {

	/**
	 * Detect all issues and failures present in a snapshot.
	 *
	 * @param array<string, mixed> $snapshot Decoded SnapshotSchema data.
	 *
	 * @return list<array{section: string, section_title: string, block: string, severity: 'fail'|'warn', title: string, message: string, url: string}>
	 */
	public static function detect( array $snapshot ): array {
		$issues   = [];
		$sections = (array) ( $snapshot['sections'] ?? [] );

		// 1. Security vulnerabilities.
		$security = (array) ( $sections['security'] ?? [] );
		$vulns    = (array) ( $security['vulnerabilities'] ?? [] );

		foreach ( $vulns as $vuln ) {
			if ( ! is_array( $vuln ) ) {
				continue;
			}

			$status   = (string) ( $vuln['status'] ?? '' );
			$resolved = $vuln['resolved_at'] ?? null;

			if ( 'resolved' !== $status && empty( $resolved ) ) {
				$severity = strtolower( (string) ( $vuln['severity'] ?? 'high' ) );
				$name     = (string) ( $vuln['component'] ?? $vuln['reference'] ?? __( 'Unknown component', 'rtcamp-amc-copilot' ) );
				$ref      = (string) ( $vuln['reference'] ?? '' );

				$issues[] = [
					'section'       => 'security',
					'section_title' => __( 'Security', 'rtcamp-amc-copilot' ),
					'block'         => 'amc/section-security',
					'severity'      => in_array( $severity, [ 'critical', 'high' ], true ) ? 'fail' : 'warn',
					/* translators: 1: Component or plugin name, 2: Reference/CVE. */
					'title'         => sprintf( __( 'Open Vulnerability: %1$s (%2$s)', 'rtcamp-amc-copilot' ), $name, $ref ),
					/* translators: %s: Severity level. */
					'message'       => sprintf( __( 'Unresolved vulnerability with %s severity.', 'rtcamp-amc-copilot' ), strtoupper( $severity ) ),
					'url'           => ConsolePage::url( 'vulnerabilities' ),
				];
			}
		}

		// 2. Core checksums.
		$checksums = $security['core_checksums'] ?? null;
		if ( is_array( $checksums ) ) {
			$status   = (string) ( $checksums['status'] ?? '' );
			$modified = (array) ( $checksums['modified_files'] ?? [] );

			if ( 'fail' === $status || [] !== $modified ) {
				$issues[] = [
					'section'       => 'security',
					'section_title' => __( 'Security', 'rtcamp-amc-copilot' ),
					'block'         => 'amc/section-security',
					'severity'      => 'fail',
					'title'         => __( 'Modified WordPress Core Files', 'rtcamp-amc-copilot' ),
					/* translators: %d: Number of modified files. */
					'message'       => sprintf( __( '%d official WordPress core file(s) failed checksum verification.', 'rtcamp-amc-copilot' ), count( $modified ) ),
					'url'           => ConsolePage::url( 'security' ),
				];
			}
		}

		// 3. Security configuration checks.
		$configs = (array) ( $security['config_checks'] ?? [] );
		foreach ( $configs as $check ) {
			if ( is_array( $check ) && 'fail' === ( $check['status'] ?? '' ) ) {
				$label = (string) ( $check['label'] ?? $check['id'] ?? __( 'Configuration check', 'rtcamp-amc-copilot' ) );

				$issues[] = [
					'section'       => 'security',
					'section_title' => __( 'Security', 'rtcamp-amc-copilot' ),
					'block'         => 'amc/section-security',
					'severity'      => 'fail',
					/* translators: %s: Check label. */
					'title'         => sprintf( __( 'Security Config Failure: %s', 'rtcamp-amc-copilot' ), $label ),
					'message'       => __( 'Security configuration check flagged a critical risk.', 'rtcamp-amc-copilot' ),
					'url'           => ConsolePage::url( 'security' ),
				];
			}
		}

		// 4. PHP Fatal Errors.
		$php_errors  = (array) ( $sections['php_errors'] ?? [] );
		$fatal_count = (int) ( $php_errors['fatal_count'] ?? 0 );
		if ( $fatal_count > 0 ) {
			$issues[] = [
				'section'       => 'php_errors',
				'section_title' => __( 'PHP errors', 'rtcamp-amc-copilot' ),
				'block'         => 'amc/section-php-errors',
				'severity'      => 'fail',
				'title'         => __( 'PHP Fatal Errors Detected', 'rtcamp-amc-copilot' ),
				/* translators: %d: Number of fatal errors. */
				'message'       => sprintf( __( '%d PHP fatal error(s) occurred during the reporting period.', 'rtcamp-amc-copilot' ), $fatal_count ),
				'url'           => ConsolePage::url( 'errors' ),
			];
		}

		// 5. Smoke Checks.
		$smoke = (array) ( $sections['smoke_checks'] ?? [] );
		$urls  = (array) ( $smoke['urls'] ?? [] );
		foreach ( $urls as $item ) {
			if ( ! is_array( $item ) || empty( $item['url'] ) ) {
				continue;
			}

			$pass_rate = isset( $item['pass_rate'] ) ? (float) $item['pass_rate'] : 100.0;
			if ( $pass_rate < 100.0 ) {
				$issues[] = [
					'section'       => 'smoke_checks',
					'section_title' => __( 'Smoke checks', 'rtcamp-amc-copilot' ),
					'block'         => 'amc/section-smoke-checks',
					'severity'      => 'fail',
					/* translators: %s: Target URL. */
					'title'         => sprintf( __( 'Smoke Check Failure: %s', 'rtcamp-amc-copilot' ), (string) $item['url'] ),
					/* translators: 1: Pass rate percentage, 2: Run count. */
					'message'       => sprintf( __( 'Pass rate dropped to %1$s%% across %2$d runs.', 'rtcamp-amc-copilot' ), (string) $pass_rate, (int) ( $item['runs'] ?? 0 ) ),
					'url'           => ConsolePage::url( 'smoke' ),
				];
			}
		}

		// 6. SSL issues.
		$ssl = (array) ( $sections['ssl'] ?? [] );
		if ( [] !== $ssl ) {
			if ( false === ( $ssl['https'] ?? null ) ) {
				$issues[] = [
					'section'       => 'ssl',
					'section_title' => __( 'SSL', 'rtcamp-amc-copilot' ),
					'block'         => 'amc/section-ssl',
					'severity'      => 'fail',
					'title'         => __( 'Site Not Served Over HTTPS', 'rtcamp-amc-copilot' ),
					'message'       => __( 'The site URL is not using encrypted HTTPS protocol.', 'rtcamp-amc-copilot' ),
					'url'           => ConsolePage::url( 'security' ),
				];
			}

			if ( isset( $ssl['days_left'] ) && is_numeric( $ssl['days_left'] ) ) {
				$days_left = (int) $ssl['days_left'];
				if ( $days_left <= 0 ) {
					$issues[] = [
						'section'       => 'ssl',
						'section_title' => __( 'SSL', 'rtcamp-amc-copilot' ),
						'block'         => 'amc/section-ssl',
						'severity'      => 'fail',
						'title'         => __( 'SSL Certificate Expired', 'rtcamp-amc-copilot' ),
						'message'       => __( 'The site SSL certificate has expired.', 'rtcamp-amc-copilot' ),
						'url'           => ConsolePage::url( 'security' ),
					];
				} elseif ( $days_left <= 15 ) {
					$issues[] = [
						'section'       => 'ssl',
						'section_title' => __( 'SSL', 'rtcamp-amc-copilot' ),
						'block'         => 'amc/section-ssl',
						'severity'      => 'fail',
						'title'         => __( 'SSL Certificate Expiring Soon', 'rtcamp-amc-copilot' ),
						/* translators: %d: Days remaining. */
						'message'       => sprintf( __( 'The SSL certificate expires in %d days.', 'rtcamp-amc-copilot' ), $days_left ),
						'url'           => ConsolePage::url( 'security' ),
					];
				}
			}
		}

		/**
		 * Filter detected report issues.
		 *
		 * @param list<array{section: string, section_title: string, block: string, severity: 'fail'|'warn', title: string, message: string, url: string}> $issues
		 * @param array<string, mixed>                                                                                                                      $snapshot
		 */
		return (array) apply_filters( 'amc_report_issues', $issues, $snapshot );
	}

	/**
	 * Whether a snapshot contains any failures.
	 *
	 * @param array<string, mixed> $snapshot Decoded SnapshotSchema data.
	 */
	public static function has_failures( array $snapshot ): bool {
		$issues = self::detect( $snapshot );

		foreach ( $issues as $issue ) {
			if ( 'fail' === ( $issue['severity'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compute and update post meta for issues from a snapshot.
	 *
	 * @param int                  $post_id  Report post ID.
	 * @param array<string, mixed> $snapshot Decoded snapshot.
	 */
	public static function update_post_issues( int $post_id, array $snapshot ): void {
		$issues       = self::detect( $snapshot );
		$has_failures = self::has_failures( $snapshot );

		update_post_meta( $post_id, '_amc_has_failures', $has_failures ? '1' : '0' );
		update_post_meta( $post_id, '_amc_issue_count', (string) count( $issues ) );
		update_post_meta( $post_id, '_amc_issues', wp_slash( (string) wp_json_encode( $issues ) ) );
	}

	/**
	 * Get the dedicated console screen URL for an issue section.
	 *
	 * @param string $section Section slug.
	 * @return string Console screen URL.
	 */
	public static function get_url_for_section( string $section ): string {
		$screen = match ( $section ) {
			'vulnerabilities' => 'vulnerabilities',
			'security', 'ssl' => 'security',
			'php_errors'      => 'errors',
			'smoke_checks'    => 'smoke',
			'performance'     => 'performance',
			'plugins_themes'  => 'updates',
			'hosting'         => 'inventory',
			default           => 'security',
		};

		return ConsolePage::url( $screen );
	}

	/**
	 * Retrieve issues for a report post.
	 *
	 * @param int $post_id Report post ID.
	 *
	 * @return list<array{section: string, section_title: string, block: string, severity: 'fail'|'warn', title: string, message: string, url: string}>
	 */
	public static function for_post( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_amc_issues', true );

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$needs_update = false;
				foreach ( $decoded as &$item ) {
					if ( empty( $item['url'] ) || str_contains( $item['url'], 'screen=overview' ) || ! str_contains( $item['url'], 'screen=' ) ) {
						$item['url']  = self::get_url_for_section( (string) ( $item['section'] ?? '' ) );
						$needs_update = true;
					}
				}
				unset( $item );

				if ( $needs_update ) {
					update_post_meta( $post_id, '_amc_issues', wp_slash( (string) wp_json_encode( $decoded ) ) );
				}

				return $decoded;
			}
		}

		$snapshot = SnapshotSchema::for_post( $post_id );
		$issues   = self::detect( $snapshot );
		self::update_post_issues( $post_id, $snapshot );

		return $issues;
	}
}
