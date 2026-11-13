<?php
/**
 * Check: vuln.match (known vulnerabilities in installed code).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Vulnerabilities;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Vulnerability\Matcher;

/**
 * Class VulnMatchCheck
 *
 * Sends the inventory (core, plugins, themes) to the provider on the
 * `amc_vulnerability_provider` filter and turns each match into a finding.
 *
 * Result::$data shape (Vulnerabilities screen and the report snapshot):
 *   provider        string       Provider id ('null' when none is configured).
 *   provider_ready  bool
 *   provider_error  string|null  Thrown message or last_error(); null when fine.
 *   components      int          Components sent to the provider.
 *   matches         list<array>  VulnMatch::to_array() + installed_version.
 *   counts          array<string,int>  Matches per severity (every severity present).
 *
 * Status rules:
 *   provider not ready  -> INFO, partial (we know nothing, so nothing resolves)
 *   provider error      -> WARN, partial (same: a broken provider must not resolve findings)
 *   no matches          -> PASS
 *   high or critical    -> FAIL
 *   other matches       -> WARN
 *
 * Finding dedupe key: "{type}:{slug}:{installed_version}:{reference}" (hashed
 * if longer than 191 characters). Updating the plugin changes the installed
 * version, the match disappears and the finding resolves.
 *
 * @since 0.1.0
 */
final class VulnMatchCheck implements Check {

	/**
	 * The matcher.
	 *
	 * @var Matcher
	 */
	private Matcher $matcher;

	/**
	 * Constructor.
	 *
	 * @param Matcher|null $matcher Test seam; default reads the real inventory and provider.
	 */
	public function __construct( ?Matcher $matcher = null ) {
		$this->matcher = $matcher ?? new Matcher();
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'vuln.match';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::VULNERABILITIES;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Known vulnerabilities', 'rtcamp-amc-copilot' );
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
	 * @param Context $ctx Run context (unused: the check is stateless).
	 */
	public function run( Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the Check contract.
		try {
			$outcome = $this->matcher->run();
		} catch ( \Throwable $e ) {
			$outcome = [
				'provider'   => 'unknown',
				'ready'      => false,
				'error'      => $e->getMessage(),
				'components' => 0,
				'matches'    => [],
			];
		}

		$data = [
			'provider'       => $outcome['provider'],
			'provider_ready' => $outcome['ready'],
			'provider_error' => $outcome['error'],
			'components'     => $outcome['components'],
			'matches'        => [],
			'counts'         => array_fill_keys( array_map( static fn ( Severity $s ): string => $s->value, Severity::cases() ), 0 ),
		];

		if ( null !== $outcome['error'] ) {
			return Result::warn(
				/* translators: %s: Provider error message. */
				sprintf( __( 'The vulnerability provider reported an error: %s', 'rtcamp-amc-copilot' ), $outcome['error'] ),
				$data
			)->partial();
		}

		if ( ! $outcome['ready'] ) {
			return Result::info( __( 'No vulnerability provider is configured or ready, so nothing was checked.', 'rtcamp-amc-copilot' ), $data )->partial();
		}

		$findings = [];
		$worst    = null;

		foreach ( $outcome['matches'] as $row ) {
			$match   = $row['match'];
			$version = $row['installed_version'];
			$detail  = $match->to_array() + [ 'installed_version' => $version ];

			$data['matches'][]                          = $detail;
			$data['counts'][ $match->severity->value ] += 1;

			if ( null === $worst || $match->severity->rank() > $worst->rank() ) {
				$worst = $match->severity;
			}

			$findings[] = new Finding(
				self::dedupe_key( $match->component_type, $match->component_slug, $version, $match->reference ),
				$match->severity,
				sprintf(
					/* translators: 1: Vulnerability reference (CVE id), 2: Component slug, 3: Installed version, 4: Title. */
					__( '%1$s in %2$s %3$s: %4$s', 'rtcamp-amc-copilot' ),
					$match->reference,
					$match->component_slug,
					$version,
					$match->title
				),
				$detail
			);
		}

		if ( [] === $findings ) {
			return Result::pass(
				/* translators: %d: Number of components checked. */
				sprintf( __( 'No known vulnerabilities in %d components.', 'rtcamp-amc-copilot' ), $outcome['components'] ),
				$data
			);
		}

		$summary = sprintf(
			/* translators: %d: Number of known vulnerabilities. */
			_n( '%d known vulnerability found.', '%d known vulnerabilities found.', count( $findings ), 'rtcamp-amc-copilot' ),
			count( $findings )
		);

		return null !== $worst && $worst->at_least( Severity::HIGH )
			? Result::fail( $summary, $data, $findings )
			: Result::warn( $summary, $data, $findings );
	}

	/**
	 * The finding dedupe key.
	 *
	 * @param string $type      Component type.
	 * @param string $slug      Component slug.
	 * @param string $version   Installed version.
	 * @param string $reference Vulnerability reference.
	 */
	public static function dedupe_key( string $type, string $slug, string $version, string $reference ): string {
		$key = $type . ':' . $slug . ':' . $version . ':' . $reference;

		return strlen( $key ) > 191 ? substr( $key, 0, 150 ) . ':' . md5( $key ) : $key;
	}
}
