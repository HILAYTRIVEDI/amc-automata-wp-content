<?php
/**
 * Check: security.checksums (WordPress core files against the official checksums).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Security;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;

/**
 * Class CoreChecksums
 *
 * Compares the core files on disk with get_core_checksums() for the
 * installed version and locale. `wp-content/` is skipped (default themes
 * and plugins are routinely deleted on purpose).
 *
 * Batching: files are checked in path order until about 12 s have passed.
 * The cursor and every problem found so far live in `data`; the next run
 * reads them from `previous_data`. EVERY BATCH EXCEPT THE LAST RETURNS
 * `->partial()` (so findings from other batches do not resolve); the last
 * batch returns a normal result with every finding of the whole pass. A new
 * pass starts after a finished one, or when the WordPress version changed.
 *
 * If the checksums cannot be fetched (API down, offline host) the run is INFO,
 * partial, and keeps the pass state, so nothing resolves.
 *
 * Result::$data shape:
 *   version   string  WordPress version the checksums are for.
 *   locale    string
 *   total     int     Files to check this pass.
 *   cursor    int     Index of the next file to check (equals total when done).
 *   checked   int     Files checked so far this pass.
 *   done      bool    True when the pass has covered every file.
 *   found     list<array{path: string, issue: 'modified'|'missing'}>  Problems found so far this pass.
 *   modified  int     Count of `modified` in found.
 *   missing   int     Count of `missing` in found.
 *   error     string|null  Why the checksums were unavailable.
 *
 * Status rules (done or not): a modified file -> FAIL; only missing files ->
 * WARN; nothing found -> PASS (INFO while a pass is still running).
 *
 * Findings: one per problem file, dedupe key `core-file:<path>`. Modified is
 * HIGH (immediate alert); missing is MEDIUM, or LOW for files hosts commonly
 * remove on purpose (readme.html, license.txt, wp-config-sample.php).
 *
 * @since 0.1.0
 */
final class CoreChecksums implements Check {

	/**
	 * Seconds after which a batch stops starting new files.
	 */
	public const BATCH_SECONDS = 12.0;

	/**
	 * Core files that hosts and engineers often remove on purpose.
	 */
	private const OPTIONAL_FILES = [ 'readme.html', 'license.txt', 'wp-config-sample.php' ];

	/**
	 * Checksums: callable( string $version, string $locale ): array|false.
	 *
	 * @var callable
	 */
	private $checksums_source;

	/**
	 * Hash a file: callable( string $relative_path, string $algo ): string|null (null = missing).
	 *
	 * @var callable
	 */
	private $hasher;

	/**
	 * Clock: callable(): float.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Installed version: callable(): string.
	 *
	 * @var callable
	 */
	private $version_source;

	/**
	 * Locale: callable(): string.
	 *
	 * @var callable
	 */
	private $locale_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $checksums_source get_core_checksums().
	 * @param callable|null $hasher           Hashes a core file by its path relative to ABSPATH.
	 * @param callable|null $clock            microtime( true ).
	 * @param callable|null $version_source   wp_get_wp_version().
	 * @param callable|null $locale_source    get_locale().
	 */
	public function __construct( ?callable $checksums_source = null, ?callable $hasher = null, ?callable $clock = null, ?callable $version_source = null, ?callable $locale_source = null ) {
		$this->checksums_source = $checksums_source ?? static function ( string $version, string $locale ): array|false {
			if ( ! function_exists( 'get_core_checksums' ) ) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}

			return get_core_checksums( $version, $locale );
		};
		$this->hasher           = $hasher ?? static function ( string $relative, string $algo ): ?string {
			$path = ABSPATH . $relative;

			if ( ! is_file( $path ) ) {
				return null;
			}

			$hash = hash_file( $algo, $path );

			return false === $hash ? null : $hash;
		};
		$this->clock            = $clock ?? static fn (): float => microtime( true );
		$this->version_source   = $version_source ?? static fn (): string => wp_get_wp_version();
		$this->locale_source    = $locale_source ?? static fn (): string => get_locale();
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'security.checksums';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::SECURITY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Core file integrity', 'rtcamp-amc-copilot' );
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
	 * @param Context $ctx Run context.
	 */
	public function run( Context $ctx ): Result {
		$version  = (string) ( $this->version_source )();
		$locale   = (string) ( $this->locale_source )();
		$previous = $ctx->previous_data ?? [];

		// Resume only an unfinished pass for the same version.
		$resuming = false === ( $previous['done'] ?? true ) && ( $previous['version'] ?? '' ) === $version && ( $previous['locale'] ?? $locale ) === $locale;
		$sums     = ( $this->checksums_source )( $version, $locale );

		if ( ! is_array( $sums ) || [] === $sums ) {
			$data          = $resuming ? $previous : $this->blank( $version, $locale );
			$data['error'] = 'checksums_unavailable';

			return Result::info( __( 'The official core checksums could not be fetched, so core files were not verified.', 'rtcamp-amc-copilot' ), $data )->partial();
		}

		$files = array_values(
			array_filter(
				array_keys( $sums ),
				static fn ( $path ): bool => ! str_starts_with( (string) $path, 'wp-content/' )
			)
		);
		sort( $files, SORT_STRING );
		$total = count( $files );

		$cursor  = $resuming ? (int) ( $previous['cursor'] ?? 0 ) : 0;
		$cursor  = $cursor > $total ? 0 : $cursor;
		$found   = $resuming && 0 !== $cursor ? $this->clean_found( $previous['found'] ?? [] ) : [];
		$checked = $resuming && 0 !== $cursor ? (int) ( $previous['checked'] ?? 0 ) : 0;
		$began   = ( $this->clock )();
		$batch   = 0;

		while ( $cursor < $total ) {
			if ( $batch > 0 && ( ( $this->clock )() - $began ) > self::BATCH_SECONDS ) {
				break;
			}

			$path     = (string) $files[ $cursor ];
			$expected = $sums[ $path ];
			$algo     = is_array( $expected ) && isset( $expected['sha256'] ) ? 'sha256' : 'md5';
			$wanted   = is_array( $expected ) ? (string) ( $expected[ $algo ] ?? '' ) : (string) $expected;
			$actual   = ( $this->hasher )( $path, $algo );

			if ( null === $actual ) {
				$found[] = [
					'path'  => $path,
					'issue' => 'missing',
				];
			} elseif ( ! hash_equals( strtolower( $wanted ), strtolower( $actual ) ) ) {
				$found[] = [
					'path'  => $path,
					'issue' => 'modified',
				];
			}

			++$cursor;
			++$checked;
			++$batch;
		}

		$done     = $cursor >= $total;
		$modified = count( array_filter( $found, static fn ( array $f ): bool => 'modified' === $f['issue'] ) );
		$missing  = count( $found ) - $modified;
		$data     = [
			'version'  => $version,
			'locale'   => $locale,
			'total'    => $total,
			'cursor'   => $cursor,
			'checked'  => $checked,
			'done'     => $done,
			'found'    => $found,
			'modified' => $modified,
			'missing'  => $missing,
			'error'    => null,
		];

		$findings = array_map( [ $this, 'finding' ], $found );
		$result   = $this->result( $data, $findings, $modified, $missing, $done );

		return $done ? $result : $result->partial();
	}

	/**
	 * The result for the totals.
	 *
	 * @param array<string, mixed>      $data     Data.
	 * @param list<\AMC\Checks\Finding> $findings Findings.
	 * @param int                       $modified Modified files.
	 * @param int                       $missing  Missing files.
	 * @param bool                      $done     Whether the pass is finished.
	 */
	private function result( array $data, array $findings, int $modified, int $missing, bool $done ): Result {
		if ( $modified > 0 ) {
			return Result::fail(
				sprintf(
					/* translators: 1: Number of modified files, 2: Number of missing files. */
					__( '%1$d core files are modified and %2$d are missing.', 'rtcamp-amc-copilot' ),
					$modified,
					$missing
				),
				$data,
				$findings
			);
		}

		if ( $missing > 0 ) {
			return Result::warn(
				/* translators: %d: Number of missing files. */
				sprintf( _n( '%d core file is missing.', '%d core files are missing.', $missing, 'rtcamp-amc-copilot' ), $missing ),
				$data,
				$findings
			);
		}

		if ( ! $done ) {
			return Result::info(
				sprintf(
					/* translators: 1: Files checked, 2: Total files. */
					__( 'Checked %1$d of %2$d core files so far, no problems.', 'rtcamp-amc-copilot' ),
					$data['checked'],
					$data['total']
				),
				$data
			);
		}

		return Result::pass(
			/* translators: %d: Number of core files checked. */
			sprintf( __( 'All %d core files match the official checksums.', 'rtcamp-amc-copilot' ), $data['total'] ),
			$data
		);
	}

	/**
	 * A finding for one problem file.
	 *
	 * @param array{path: string, issue: string} $problem Problem.
	 */
	private function finding( array $problem ): Finding {
		$path     = $problem['path'];
		$modified = 'modified' === $problem['issue'];
		$optional = in_array( $path, self::OPTIONAL_FILES, true );
		$key      = 'core-file:' . $path;

		return new Finding(
			strlen( $key ) > 191 ? 'core-file:' . md5( $path ) : $key,
			$modified ? Severity::HIGH : ( $optional ? Severity::LOW : Severity::MEDIUM ),
			$modified
				/* translators: %s: File path. */
				? sprintf( __( 'Core file modified: %s', 'rtcamp-amc-copilot' ), $path )
				/* translators: %s: File path. */
				: sprintf( __( 'Core file missing: %s', 'rtcamp-amc-copilot' ), $path ),
			[
				'path'  => $path,
				'issue' => $problem['issue'],
			]
		);
	}

	/**
	 * The empty pass state.
	 *
	 * @param string $version Version.
	 * @param string $locale  Locale.
	 *
	 * @return array<string, mixed>
	 */
	private function blank( string $version, string $locale ): array {
		return [
			'version'  => $version,
			'locale'   => $locale,
			'total'    => 0,
			'cursor'   => 0,
			'checked'  => 0,
			'done'     => true,
			'found'    => [],
			'modified' => 0,
			'missing'  => 0,
			'error'    => null,
		];
	}

	/**
	 * Keep only well-formed problems from the previous run's data.
	 *
	 * @param mixed $found Previous `found`.
	 *
	 * @return list<array{path: string, issue: string}>
	 */
	private function clean_found( mixed $found ): array {
		$clean = [];

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			if ( is_array( $row ) && isset( $row['path'] ) && in_array( $row['issue'] ?? '', [ 'modified', 'missing' ], true ) ) {
				$clean[] = [
					'path'  => (string) $row['path'],
					'issue' => (string) $row['issue'],
				];
			}
		}

		return $clean;
	}
}
