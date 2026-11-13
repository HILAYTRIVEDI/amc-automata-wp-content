<?php
/**
 * A single issue reported by a check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks;

/**
 * Class Finding
 *
 * FROZEN CONTRACT (Wave 0). Immutable value object.
 *
 * The dedupe key identifies "the same issue" across runs. The runner keeps
 * one open amc_findings row per (blog, check, dedupe_key): seen again ->
 * last_seen updated; missing from a later run -> resolved. Pick a key that
 * changes when the issue materially changes, e.g.:
 *   updates.core      'core:6.9.1'               (new release = new finding)
 *   vuln.match        'plugin:akismet:5.0:CVE-2025-1234'
 *   errors.fatal      the event signature
 *   smoke.urls        'url:' . md5( $url )
 * Max 191 characters.
 *
 * @since 0.1.0
 */
final class Finding {

	/**
	 * Constructor.
	 *
	 * @param string               $dedupe_key Stable key for "the same issue" (max 191 chars).
	 * @param Severity             $severity   Severity.
	 * @param string               $title      One line shown in console, emails and reports (max 255).
	 * @param array<string, mixed> $data       Extra structured data (JSON-serialisable).
	 *
	 * @throws \InvalidArgumentException When the dedupe key is empty or longer than 191 characters.
	 */
	public function __construct(
		public readonly string $dedupe_key,
		public readonly Severity $severity,
		public readonly string $title,
		public readonly array $data = []
	) {
		if ( '' === $dedupe_key || strlen( $dedupe_key ) > 191 ) {
			throw new \InvalidArgumentException( 'Finding dedupe_key must be 1-191 characters.' );
		}
	}

	/**
	 * Array form (REST, tests).
	 *
	 * @return array{dedupe_key: string, severity: string, title: string, data: array<string, mixed>}
	 */
	public function to_array(): array {
		return [
			'dedupe_key' => $this->dedupe_key,
			'severity'   => $this->severity->value,
			'title'      => $this->title,
			'data'       => $this->data,
		];
	}
}
