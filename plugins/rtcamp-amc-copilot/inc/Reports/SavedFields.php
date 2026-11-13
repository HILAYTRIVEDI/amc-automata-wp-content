<?php
/**
 * Saved fields: stable facts carried forward from report to report.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

use WP_Post;
use WP_Query;

/**
 * Class SavedFields
 *
 * The `amc_saved_fields` option (autoload OFF) holds the value of every Saved
 * field in the field catalogue: `{ "<section>": { "<field>": "<string>" } }`.
 *
 * - Generation copies each value into the new report's snapshot. When the option
 *   has no value for a field (a fresh install, or a site that already has
 *   published reports), the value comes from the latest PUBLISHED report for
 *   the same environment instead.
 * - Settings edits it through `POST /amc/v1/saved-fields`.
 * - On publish, any Saved field whose value in the report differs from the
 *   option is written back (write_back()), so the next report starts from it.
 * - Before publish, review() lists every changed Saved field next to the value
 *   it replaces ("Engineers keep editing Saved fields per report" risk).
 *
 * The value a report shows for a Saved field is the block override when the
 * engineer typed one (the section block's `overrides` attribute), else the
 * snapshot value, the same rule the section blocks render by.
 *
 * Admin, REST, cron and CLI only: never read on a front-end request.
 *
 * @since 0.1.0
 */
final class SavedFields {

	/**
	 * Option name.
	 */
	public const OPTION = 'amc_saved_fields';

	/**
	 * Longest value kept, in characters.
	 */
	public const MAX_LENGTH = 2000;

	/**
	 * Every Saved field, section => field => value or null when not set.
	 *
	 * @return array<string, array<string, string|null>>
	 */
	public static function all(): array {
		$stored = self::stored();
		$fields = [];

		foreach ( FieldCatalogue::saved() as $section => $keys ) {
			foreach ( $keys as $key ) {
				$value                      = $stored[ $section ][ $key ] ?? null;
				$fields[ $section ][ $key ] = is_string( $value ) ? $value : null;
			}
		}

		return $fields;
	}

	/**
	 * The raw option, restricted to known string values.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function stored(): array {
		$option = get_option( self::OPTION, [] );
		$clean  = [];

		if ( ! is_array( $option ) ) {
			return $clean;
		}

		foreach ( FieldCatalogue::saved() as $section => $keys ) {
			foreach ( $keys as $key ) {
				if ( isset( $option[ $section ][ $key ] ) && is_string( $option[ $section ][ $key ] ) ) {
					$clean[ $section ][ $key ] = $option[ $section ][ $key ];
				}
			}
		}

		return $clean;
	}

	/**
	 * Sanitise incoming values: unknown sections and fields are dropped.
	 *
	 * `null` means "clear this field" and is kept as null.
	 *
	 * @param array<mixed> $input section => field => value|null.
	 *
	 * @return array<string, array<string, string|null>>
	 */
	public static function sanitize( array $input ): array {
		$clean = [];

		foreach ( FieldCatalogue::saved() as $section => $keys ) {
			if ( ! isset( $input[ $section ] ) || ! is_array( $input[ $section ] ) ) {
				continue;
			}

			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $input[ $section ] ) ) {
					continue;
				}

				$value = $input[ $section ][ $key ];

				if ( null === $value ) {
					$clean[ $section ][ $key ] = null;
				} elseif ( is_scalar( $value ) ) {
					$clean[ $section ][ $key ] = mb_substr( sanitize_textarea_field( (string) $value ), 0, self::MAX_LENGTH );
				}
			}
		}

		return $clean;
	}

	/**
	 * Merge changes into the option and save it (autoload off).
	 *
	 * Only the fields present in `$changes` are touched. A null or empty value
	 * clears the field.
	 *
	 * @param array<mixed> $changes section => field => value|null.
	 *
	 * @return array<string, array<string, string|null>> The saved values, as all() returns them.
	 */
	public static function update( array $changes ): array {
		$stored = self::stored();

		foreach ( self::sanitize( $changes ) as $section => $fields ) {
			foreach ( $fields as $key => $value ) {
				if ( null === $value || '' === $value ) {
					unset( $stored[ $section ][ $key ] );
				} else {
					$stored[ $section ][ $key ] = $value;
				}
			}

			if ( [] === ( $stored[ $section ] ?? [] ) ) {
				unset( $stored[ $section ] );
			}
		}

		update_option( self::OPTION, $stored, false );

		return self::all();
	}

	/**
	 * The values a new report starts with.
	 *
	 * The option wins. A field the option has no value for takes the value the
	 * latest published report for the same environment shows.
	 *
	 * @param string $environment Environment label of the new report.
	 * @param int    $exclude     Report to ignore as the "previous" one (the report being published).
	 *
	 * @return array<string, array<string, string|null>>
	 */
	public static function for_new_report( string $environment, int $exclude = 0 ): array {
		$values  = self::all();
		$missing = false;

		foreach ( $values as $fields ) {
			foreach ( $fields as $value ) {
				if ( null === $value ) {
					$missing = true;
					break 2;
				}
			}
		}

		if ( ! $missing ) {
			return $values;
		}

		$previous = self::latest_published( $environment, $exclude );

		if ( null === $previous ) {
			return $values;
		}

		$from_report = self::effective_values( $previous );

		foreach ( $values as $section => $fields ) {
			foreach ( $fields as $key => $value ) {
				if ( null === $value && isset( $from_report[ $section ][ $key ] ) ) {
					$values[ $section ][ $key ] = $from_report[ $section ][ $key ];
				}
			}
		}

		return $values;
	}

	/**
	 * Latest published report for an environment.
	 *
	 * @param string $environment Environment label.
	 * @param int    $exclude     Report ID to skip.
	 *
	 * @return int|null Post ID.
	 */
	public static function latest_published( string $environment, int $exclude = 0 ): ?int {
		$query = new WP_Query(
			[
				'post_type'              => ReportPostType::SLUG,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'orderby'                => 'date ID',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'post__not_in'           => $exclude > 0 ? [ $exclude ] : [], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- One post, admin/cron only.
				'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One row, admin/cron only.
					[
						'key'   => '_amc_environment',
						'value' => $environment,
					],
				],
			]
		);

		$ids = array_map( 'intval', $query->posts );

		return [] === $ids ? null : $ids[0];
	}

	/**
	 * The Saved field values a report shows: block override first, snapshot second.
	 *
	 * @param int $post_id Report ID.
	 *
	 * @return array<string, array<string, string|null>>
	 */
	public static function effective_values( int $post_id ): array {
		$snapshot = SnapshotSchema::for_post( $post_id );
		$post     = get_post( $post_id );
		$override = $post instanceof WP_Post ? self::overrides( $post->post_content ) : [];
		$values   = [];

		foreach ( FieldCatalogue::saved() as $section => $keys ) {
			foreach ( $keys as $key ) {
				if ( isset( $override[ $section ] ) && array_key_exists( $key, $override[ $section ] ) ) {
					$values[ $section ][ $key ] = $override[ $section ][ $key ];
					continue;
				}

				$value                      = $snapshot['sections'][ $section ][ $key ] ?? null;
				$values[ $section ][ $key ] = is_string( $value ) ? $value : null;
			}
		}

		return $values;
	}

	/**
	 * Saved fields whose value in a report differs from the carried-forward value.
	 *
	 * @param int $post_id Report ID.
	 *
	 * @return list<array{section: string, field: string, label: string, previous: string|null, current: string|null}>
	 */
	public static function changes_for_post( int $post_id ): array {
		$previous = self::previous_for_post( $post_id );
		$current  = self::effective_values( $post_id );
		$changes  = [];

		foreach ( $current as $section => $fields ) {
			foreach ( $fields as $key => $value ) {
				$before = $previous[ $section ][ $key ] ?? null;

				if ( self::normalise( $before ) === self::normalise( $value ) ) {
					continue;
				}

				$changes[] = [
					'section'  => $section,
					'field'    => $key,
					'label'    => (string) ( FieldCatalogue::fields( $section )[ $key ]['label'] ?? $key ),
					'previous' => $before,
					'current'  => $value,
				];
			}
		}

		return $changes;
	}

	/**
	 * What the editor shows before publish: the carried-forward value of every
	 * Saved field, plus the fields that changed in this report.
	 *
	 * @param int $post_id Report ID.
	 *
	 * @return array{previous: array<string, array<string, string|null>>, changes: list<array{section: string, field: string, label: string, previous: string|null, current: string|null}>}
	 */
	public static function review( int $post_id ): array {
		return [
			'previous' => self::previous_for_post( $post_id ),
			'changes'  => self::changes_for_post( $post_id ),
		];
	}

	/**
	 * Write a report's changed Saved fields back to the option (on publish).
	 *
	 * @param int $post_id Report ID.
	 *
	 * @return int Number of fields written.
	 */
	public static function write_back( int $post_id ): int {
		$changes = self::changes_for_post( $post_id );

		if ( [] === $changes ) {
			return 0;
		}

		$update = [];

		foreach ( $changes as $change ) {
			$update[ $change['section'] ][ $change['field'] ] = $change['current'];
		}

		self::update( $update );

		return count( $changes );
	}

	/**
	 * The carried-forward values a report is compared against.
	 *
	 * @param int $post_id Report ID.
	 *
	 * @return array<string, array<string, string|null>>
	 */
	private static function previous_for_post( int $post_id ): array {
		return self::for_new_report( (string) get_post_meta( $post_id, '_amc_environment', true ), $post_id );
	}

	/**
	 * Block overrides found in post content: section => field => string.
	 *
	 * @param string $content Post content.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function overrides( string $content ): array {
		$by_block = [];

		foreach ( SnapshotSchema::sections() as $section => $definition ) {
			$by_block[ $definition['block'] ] = $section;
		}

		$found = [];

		self::collect_overrides( parse_blocks( $content ), $by_block, $found );

		return $found;
	}

	/**
	 * Walk parsed blocks and collect `overrides` attributes of section blocks.
	 *
	 * @param array<int, array<string, mixed>>     $blocks   Parsed blocks.
	 * @param array<string, string>                $by_block Block name => section.
	 * @param array<string, array<string, string>> $found    Collected overrides.
	 */
	private static function collect_overrides( array $blocks, array $by_block, array &$found ): void {
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );

			if ( isset( $by_block[ $name ] ) ) {
				$overrides = $block['attrs']['overrides'] ?? [];

				if ( is_array( $overrides ) ) {
					foreach ( $overrides as $field => $value ) {
						if ( is_string( $value ) && FieldCatalogue::SAVED === FieldCatalogue::source( $by_block[ $name ], (string) $field ) ) {
							$found[ $by_block[ $name ] ][ (string) $field ] = $value;
						}
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_overrides( $block['innerBlocks'], $by_block, $found );
			}
		}
	}

	/**
	 * Treat null and '' as the same "empty" when comparing.
	 *
	 * @param string|null $value Value.
	 */
	private static function normalise( ?string $value ): string {
		return trim( (string) $value );
	}
}
