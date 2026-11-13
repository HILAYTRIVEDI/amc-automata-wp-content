<?php
/**
 * Resolved values of one report section.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

use AMC\Reports\SnapshotSchema;

/**
 * Class SectionData
 *
 * What a section block's render_section() receives. Use:
 *   $data->value( 'website_url' )      auto/saved value (override wins), raw
 *   $data->is_overridden( 'x' )        true when the engineer edited it
 *   $data->engineer( 'hours_used' )    engineer HTML (kses'd), '' when empty
 *   $data->type( 'report_date' )       SnapshotSchema type ('url', 'date', ...)
 *   $data->label( 'website_url' )      translated label
 *   $data->snapshot                    full snapshot (period, site, all sections)
 *
 * @since 0.1.0
 */
final class SectionData {

	/**
	 * Field definitions of this section.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields;

	/**
	 * Constructor.
	 *
	 * @param string               $section    Section key.
	 * @param array<string, mixed> $snapshot   Full snapshot.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function __construct(
		public readonly string $section,
		public readonly array $snapshot,
		public readonly array $attributes
	) {
		$this->fields = SnapshotSchema::sections()[ $section ]['fields'] ?? [];
	}

	/**
	 * Effective auto/saved value: override, else snapshot, else null.
	 *
	 * @param string $field Field key.
	 */
	public function value( string $field ): mixed {
		if ( $this->is_overridden( $field ) ) {
			return (string) $this->attributes['overrides'][ $field ];
		}

		return $this->snapshot['sections'][ $this->section ][ $field ] ?? null;
	}

	/**
	 * Whether the engineer overrode this field.
	 *
	 * @param string $field Field key.
	 */
	public function is_overridden( string $field ): bool {
		$overrides = (array) ( $this->attributes['overrides'] ?? [] );

		return isset( $overrides[ $field ] ) && '' !== (string) $overrides[ $field ];
	}

	/**
	 * Engineer field HTML (sanitised).
	 *
	 * @param string $field Field key.
	 */
	public function engineer( string $field ): string {
		$engineer = (array) ( $this->attributes['engineer'] ?? [] );

		return wp_kses_post( (string) ( $engineer[ $field ] ?? '' ) );
	}

	/**
	 * Field type from the schema.
	 *
	 * @param string $field Field key.
	 */
	public function type( string $field ): string {
		return (string) ( $this->fields[ $field ]['type'] ?? 'string' );
	}

	/**
	 * Field label from the schema.
	 *
	 * @param string $field Field key.
	 */
	public function label( string $field ): string {
		return (string) ( $this->fields[ $field ]['label'] ?? $field );
	}
}
