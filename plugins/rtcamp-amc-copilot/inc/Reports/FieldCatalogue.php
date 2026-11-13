<?php
/**
 * The report field catalogue: every section and field with its source.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

/**
 * Class FieldCatalogue
 *
 * The plan's "Field catalogue" table, as code. It is a read-only view over
 * SnapshotSchema (the frozen source of truth), so the two can never drift:
 * there is no second list of fields to keep in sync.
 *
 * Sources:
 *
 *   auto      Detected by the SnapshotBuilder.
 *   saved     Carried forward from `amc_saved_fields` (SavedFields).
 *   engineer  Typed per report; never in the snapshot.
 *
 * @since 0.1.0
 */
final class FieldCatalogue {

	public const AUTO     = 'auto';
	public const SAVED    = 'saved';
	public const ENGINEER = 'engineer';

	/**
	 * Every source, in display order.
	 */
	public const SOURCES = [ self::AUTO, self::SAVED, self::ENGINEER ];

	/**
	 * Every section key, in report order.
	 *
	 * @return list<string>
	 */
	public static function sections(): array {
		return array_keys( SnapshotSchema::sections() );
	}

	/**
	 * Every field of a section: key => definition (source, type, label, ...).
	 *
	 * @param string $section Section key.
	 *
	 * @return array<string, array<string, mixed>> Empty for an unknown section.
	 */
	public static function fields( string $section ): array {
		return SnapshotSchema::sections()[ $section ]['fields'] ?? [];
	}

	/**
	 * Source of one field.
	 *
	 * @param string $section Section key.
	 * @param string $field   Field key.
	 *
	 * @return string|null 'auto' | 'saved' | 'engineer', or null for an unknown field.
	 */
	public static function source( string $section, string $field ): ?string {
		$definition = self::fields( $section )[ $field ] ?? null;

		return null === $definition ? null : (string) $definition['source'];
	}

	/**
	 * Every field of one source, grouped by section.
	 *
	 * @param string $source 'auto' | 'saved' | 'engineer'.
	 *
	 * @return array<string, list<string>> section => field keys (sections with none are left out).
	 */
	public static function by_source( string $source ): array {
		$grouped = [];

		foreach ( self::sections() as $section ) {
			$fields = SnapshotSchema::fields_by_source( $section, $source );

			if ( [] !== $fields ) {
				$grouped[ $section ] = $fields;
			}
		}

		return $grouped;
	}

	/**
	 * Every Auto field, section => field keys.
	 *
	 * @return array<string, list<string>>
	 */
	public static function auto(): array {
		return self::by_source( self::AUTO );
	}

	/**
	 * Every Saved field, section => field keys.
	 *
	 * @return array<string, list<string>>
	 */
	public static function saved(): array {
		return self::by_source( self::SAVED );
	}

	/**
	 * Every Engineer field, section => field keys.
	 *
	 * @return array<string, list<string>>
	 */
	public static function engineer(): array {
		return self::by_source( self::ENGINEER );
	}

	/**
	 * How many fields each source has.
	 *
	 * @return array{auto: int, saved: int, engineer: int, total: int}
	 */
	public static function counts(): array {
		$counts = [
			self::AUTO     => 0,
			self::SAVED    => 0,
			self::ENGINEER => 0,
		];

		foreach ( self::sections() as $section ) {
			foreach ( self::fields( $section ) as $definition ) {
				++$counts[ (string) $definition['source'] ];
			}
		}

		return [
			'auto'     => $counts[ self::AUTO ],
			'saved'    => $counts[ self::SAVED ],
			'engineer' => $counts[ self::ENGINEER ],
			'total'    => array_sum( $counts ),
		];
	}

	/**
	 * Whether a section/field pair belongs to the snapshot (auto or saved).
	 *
	 * @param string $section Section key.
	 * @param string $field   Field key.
	 */
	public static function in_snapshot( string $section, string $field ): bool {
		$source = self::source( $section, $field );

		return self::AUTO === $source || self::SAVED === $source;
	}
}
