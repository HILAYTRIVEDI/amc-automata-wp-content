<?php
/**
 * Check: perf.database.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Performance;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;

/**
 * Class DatabaseCheck
 *
 * Reports table sizes from information_schema (falling back to SHOW TABLE
 * STATUS, then to "unavailable"), and the autoloaded options size (finding
 * when over 800 KB).
 *
 * Result::$data shape:
 *   source        string 'information_schema'|'show_table_status'|'unavailable'
 *   tables        list<array{name, rows, data_mb, index_mb, total_mb}>
 *   total_mb      float
 *   autoload_kb   float
 *   autoload_over bool
 *
 * Status: PASS, or WARN when autoload is over 800 KB (or unavailable -> INFO).
 *
 * @since 0.1.0
 */
final class DatabaseCheck implements Check {

	/**
	 * Returns table sizes.
	 *
	 * @var callable(): array{source: string, tables: list<array<string, mixed>>}
	 */
	private $tables_source;

	/**
	 * Returns the autoloaded options size in bytes.
	 *
	 * @var callable(): int
	 */
	private $autoload_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $tables_source   Test seam; default information_schema / SHOW TABLE STATUS.
	 * @param callable|null $autoload_source Test seam; default SUM(LENGTH(option_value)) WHERE autoload='yes'.
	 */
	public function __construct( ?callable $tables_source = null, ?callable $autoload_source = null ) {
		$this->tables_source   = $tables_source ?? static function (): array {
			global $wpdb;

			// Try information_schema first.
			$db_name = $wpdb->dbname;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC LIMIT 50',
					$db_name
				),
				ARRAY_A
			);

			if ( is_array( $rows ) && [] !== $rows ) {
				$tables = [];
				foreach ( $rows as $row ) {
					$tables[] = [
						'name'     => (string) ( $row['TABLE_NAME'] ?? '' ),
						'rows'     => (int) ( $row['TABLE_ROWS'] ?? 0 ),
						'data_mb'  => round( (int) ( $row['DATA_LENGTH'] ?? 0 ) / 1048576, 2 ),
						'index_mb' => round( (int) ( $row['INDEX_LENGTH'] ?? 0 ) / 1048576, 2 ),
						'total_mb' => round( ( (int) ( $row['DATA_LENGTH'] ?? 0 ) + (int) ( $row['INDEX_LENGTH'] ?? 0 ) ) / 1048576, 2 ),
					];
				}

				return [
					'source' => 'information_schema',
					'tables' => $tables,
				];
			}

			// Fallback: SHOW TABLE STATUS.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

			if ( is_array( $rows ) && [] !== $rows ) {
				$tables = [];
				foreach ( $rows as $row ) {
					$tables[] = [
						'name'     => (string) ( $row['Name'] ?? '' ),
						'rows'     => (int) ( $row['Rows'] ?? 0 ),
						'data_mb'  => round( (int) ( $row['Data_length'] ?? 0 ) / 1048576, 2 ),
						'index_mb' => round( (int) ( $row['Index_length'] ?? 0 ) / 1048576, 2 ),
						'total_mb' => round( ( (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 ) ) / 1048576, 2 ),
					];
				}

				return [
					'source' => 'show_table_status',
					'tables' => $tables,
				];
			}

			return [
				'source' => 'unavailable',
				'tables' => [],
			];
		};
		$this->autoload_source = $autoload_source ?? static function (): int {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$value = $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'" );

			return (int) $value;
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'perf.database';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::PERFORMANCE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Database size', 'rtcamp-amc-copilot' );
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
		$tables   = (array) ( $this->tables_source )();
		$source   = (string) ( $tables['source'] ?? 'unavailable' );
		$list     = is_array( $tables['tables'] ?? null ) ? $tables['tables'] : [];
		$total_mb = 0.0;

		foreach ( $list as $row ) {
			$total_mb += (float) ( $row['total_mb'] ?? 0 );
		}

		$autoload_bytes = (int) ( $this->autoload_source )();
		$autoload_kb    = round( $autoload_bytes / 1024, 2 );
		$over           = $autoload_kb > 800;

		$data = [
			'source'        => $source,
			'tables'        => $list,
			'total_mb'      => round( $total_mb, 2 ),
			'autoload_kb'   => $autoload_kb,
			'autoload_over' => $over,
		];

		if ( 'unavailable' === $source ) {
			return Result::info( __( 'Database size information is unavailable.', 'rtcamp-amc-copilot' ), $data );
		}

		if ( $over ) {
			$finding = new \AMC\Checks\Finding(
				'autoload:' . (string) (int) $autoload_kb,
				Severity::MEDIUM,
				sprintf(
					/* translators: %s: Autoload size in KB. */
					__( 'Autoloaded options are %s KB (over 800 KB).', 'rtcamp-amc-copilot' ),
					number_format_i18n( $autoload_kb )
				),
				[ 'autoload_kb' => $autoload_kb ]
			);

			return Result::warn( __( 'Autoloaded options exceed 800 KB.', 'rtcamp-amc-copilot' ), $data, [ $finding ] );
		}

		return Result::pass( __( 'Database size collected.', 'rtcamp-amc-copilot' ), $data );
	}
}
