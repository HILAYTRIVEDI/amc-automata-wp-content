<?php
/**
 * What a check returns.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks;

/**
 * Class Result
 *
 * FROZEN CONTRACT (Wave 0). Immutable value object.
 *
 * Build one with the named constructors:
 *
 *   return Result::pass( __( 'WordPress 6.9.1 is up to date.', 'rtcamp-amc-copilot' ), $data );
 *   return Result::fail( $summary, $data, [ new Finding( 'core:6.9.2', Severity::HIGH, $title ) ] );
 *
 * - $summary: one translated line (max 255 chars), shown in the console.
 * - $data:    structured, JSON-serialisable array. Shape documented on the check class.
 * - $findings: zero or more Finding objects. A PASS result usually has none;
 *              INFO results (inventory) may carry findings too (e.g. closed plugin).
 *
 * @since 0.1.0
 */
final class Result {

	/**
	 * Constructor. Prefer the named constructors.
	 *
	 * @param Status               $status   Status.
	 * @param string               $summary  One line.
	 * @param array<string, mixed> $data     Structured data.
	 * @param list<Finding>        $findings Findings.
	 *
	 * @throws \InvalidArgumentException When a finding is not a Finding.
	 */
	public function __construct(
		public readonly Status $status,
		public readonly string $summary,
		public readonly array $data = [],
		public readonly array $findings = []
	) {
		foreach ( $findings as $finding ) {
			if ( ! $finding instanceof Finding ) {
				throw new \InvalidArgumentException( 'Result findings must be Finding instances.' );
			}
		}
	}

	/**
	 * Passing result.
	 *
	 * @param string               $summary  One line.
	 * @param array<string, mixed> $data     Data.
	 * @param list<Finding>        $findings Findings.
	 *
	 * @return static
	 */
	public static function pass( string $summary, array $data = [], array $findings = [] ): self {
		return new self( Status::PASS, $summary, $data, $findings );
	}

	/**
	 * Warning result.
	 *
	 * @param string               $summary  One line.
	 * @param array<string, mixed> $data     Data.
	 * @param list<Finding>        $findings Findings.
	 *
	 * @return static
	 */
	public static function warn( string $summary, array $data = [], array $findings = [] ): self {
		return new self( Status::WARN, $summary, $data, $findings );
	}

	/**
	 * Failing result.
	 *
	 * @param string               $summary  One line.
	 * @param array<string, mixed> $data     Data.
	 * @param list<Finding>        $findings Findings.
	 *
	 * @return static
	 */
	public static function fail( string $summary, array $data = [], array $findings = [] ): self {
		return new self( Status::FAIL, $summary, $data, $findings );
	}

	/**
	 * Informational result.
	 *
	 * @param string               $summary  One line.
	 * @param array<string, mixed> $data     Data.
	 * @param list<Finding>        $findings Findings.
	 *
	 * @return static
	 */
	public static function info( string $summary, array $data = [], array $findings = [] ): self {
		return new self( Status::INFO, $summary, $data, $findings );
	}

	/**
	 * Array form (REST, tests).
	 *
	 * @return array{status: string, summary: string, data: array<string, mixed>, findings: list<array<string, mixed>>}
	 */
	public function to_array(): array {
		return [
			'status'   => $this->status->value,
			'summary'  => $this->summary,
			'data'     => $this->data,
			'findings' => array_map( static fn ( Finding $f ): array => $f->to_array(), $this->findings ),
		];
	}
}
