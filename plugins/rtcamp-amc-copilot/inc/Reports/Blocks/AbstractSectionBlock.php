<?php
/**
 * Base class for report section blocks.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

use AMC\Reports\SnapshotSchema;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractBlock;

/**
 * Class AbstractSectionBlock
 *
 * Every report section block is DYNAMIC: PHP renders it from the report's
 * snapshot (post meta `_amc_snapshot`) plus the block attributes, so the
 * editor and the published report never disagree. A subclass only says
 * which section it is and how to lay out its fields; this class handles:
 *
 *   - block name / build directory from section_key(),
 *   - "Hide this section" (attribute `hidden`: render nothing),
 *   - loading the snapshot for the current post (block context postId),
 *   - effective values: overrides[field] ?? snapshot.sections[key][field],
 *   - engineer fields (attribute `engineer`, sanitised with wp_kses_post),
 *   - the wrapper <section> with block supports + the section heading,
 *   - core Table block markup helpers (no custom styles).
 *
 * Attributes (identical in every section block.json):
 *   lock      { move: true, remove: true }   default; supports.lock false
 *   hidden    bool
 *   overrides object  field => string
 *   engineer  object  field => html
 *
 * @since 0.1.0
 */
abstract class AbstractSectionBlock extends AbstractBlock {

	/**
	 * Section key in SnapshotSchema::sections(), e.g. 'overview'.
	 */
	abstract public static function section_key(): string;

	/**
	 * Inner HTML of the section (everything below the heading).
	 *
	 * @param SectionData $data Resolved values for this section.
	 *
	 * @return string Escaped HTML.
	 */
	abstract protected function render_section( SectionData $data ): string;

	/**
	 * {@inheritDoc}
	 *
	 * 'overview' -> 'amc/section-overview'; 'plugins_themes' -> 'amc/section-plugins-themes'.
	 */
	public static function get_name(): string {
		return 'amc/section-' . static::slug();
	}

	/**
	 * Hyphenated slug of the section key.
	 */
	public static function slug(): string {
		return str_replace( '_', '-', static::section_key() );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_block_dir(): string {
		return AMC_COPILOT_PATH . 'assets/build/blocks/section-' . static::slug();
	}

	/**
	 * Attributes and context, also used when the block is registered without
	 * a build (PHPUnit, or before `npm run build`). Must match block.json.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_block_args(): array {
		return [
			'api_version'  => 3,
			'uses_context' => [ 'postId', 'postType' ],
			'attributes'   => [
				'lock'      => [
					'type'    => 'object',
					'default' => [
						'move'   => true,
						'remove' => true,
					],
				],
				'hidden'    => [
					'type'    => 'boolean',
					'default' => false,
				],
				'overrides' => [
					'type'    => 'object',
					'default' => [],
				],
				'engineer'  => [
					'type'    => 'object',
					'default' => [],
				],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Inner content (unused: dynamic block).
	 * @param \WP_Block            $block      Block instance.
	 */
	public function render( array $attributes, string $content, \WP_Block $block ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Contract signature.
		if ( ! empty( $attributes['hidden'] ) ) {
			return '';
		}

		$post_id  = (int) ( $block->context['postId'] ?? get_the_ID() );
		$snapshot = SnapshotSchema::for_post( $post_id );
		$data     = new SectionData( static::section_key(), $snapshot, $attributes );
		$section  = SnapshotSchema::sections()[ static::section_key() ];

		$wrapper = get_block_wrapper_attributes(
			[
				'class' => 'amc-section amc-section--' . static::slug(),
			]
		);

		return sprintf(
			'<section %1$s><h2 class="wp-block-heading">%2$s</h2>%3$s</section>',
			$wrapper,
			esc_html( $section['title'] ),
			$this->render_section( $data )
		);
	}

	/**
	 * Core Table block markup for label/value rows.
	 *
	 * @param list<array{0: string, 1: string}> $rows [ label (plain text), value (ESCAPED html) ].
	 */
	protected function key_value_table( array $rows ): string {
		$html = '';

		foreach ( $rows as [ $label, $value ] ) {
			$html .= sprintf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), $value );
		}

		return '<figure class="wp-block-table"><table class="has-fixed-layout"><tbody>' . $html . '</tbody></table></figure>';
	}

	/**
	 * Escaped display string for an auto/saved value ('—' when empty).
	 *
	 * @param mixed  $value Value.
	 * @param string $type  SnapshotSchema field type.
	 *
	 * @return string Escaped HTML.
	 */
	protected function format_value( mixed $value, string $type = 'string' ): string {
		if ( null === $value || '' === $value || [] === $value ) {
			return esc_html__( '—', 'rtcamp-amc-copilot' );
		}

		return match ( $type ) {
			'url'   => sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) $value ), esc_html( (string) $value ) ),
			'bool'  => $value ? esc_html__( 'Yes', 'rtcamp-amc-copilot' ) : esc_html__( 'No', 'rtcamp-amc-copilot' ),
			'date'  => esc_html( (string) mysql2date( get_option( 'date_format' ), (string) $value ) ),
			'html'  => wp_kses_post( (string) $value ),
			default => is_scalar( $value ) ? esc_html( (string) $value ) : $this->format_structured( $value ),
		};
	}

	/**
	 * Escaped HTML for a list or record value, readable by a client.
	 *
	 * - list of records:  a small table, one column per key;
	 * - record:           "Label: value" list;
	 * - list of scalars:  comma-separated;
	 * - empty list:       "None".
	 *
	 * @param mixed $value Value from the snapshot.
	 *
	 * @return string Escaped HTML.
	 */
	protected function format_structured( mixed $value ): string {
		if ( null === $value || '' === $value ) {
			return esc_html__( '—', 'rtcamp-amc-copilot' );
		}

		if ( is_bool( $value ) ) {
			return $value ? esc_html__( 'Yes', 'rtcamp-amc-copilot' ) : esc_html__( 'No', 'rtcamp-amc-copilot' );
		}

		if ( ! is_array( $value ) ) {
			return esc_html( (string) $value );
		}

		if ( [] === $value ) {
			return esc_html__( 'None', 'rtcamp-amc-copilot' );
		}

		if ( array_is_list( $value ) ) {
			if ( ! is_array( $value[0] ) ) {
				return esc_html( implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) );
			}

			$keys = [];
			foreach ( $value as $row ) {
				$keys = array_unique( array_merge( $keys, is_array( $row ) ? array_keys( $row ) : [] ) );
			}

			$head = '';
			foreach ( $keys as $key ) {
				$head .= '<th scope="col">' . esc_html( self::humanize_key( (string) $key ) ) . '</th>';
			}

			$body = '';
			foreach ( $value as $row ) {
				$body .= '<tr>';
				foreach ( $keys as $key ) {
					$body .= '<td>' . $this->format_structured( is_array( $row ) ? ( $row[ $key ] ?? null ) : null ) . '</td>';
				}
				$body .= '</tr>';
			}

			return '<table class="amc-subtable"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
		}

		$items = '';
		foreach ( $value as $key => $item ) {
			$items .= '<li><span class="amc-kv-label">' . esc_html( self::humanize_key( (string) $key ) ) . ':</span> ' . $this->format_structured( $item ) . '</li>';
		}

		return '<ul class="amc-kv-list">' . $items . '</ul>';
	}

	/**
	 * "themes_installed" → "Themes installed".
	 *
	 * @param string $key Snapshot key.
	 */
	private static function humanize_key( string $key ): string {
		$labels = [
			'avg_response_ms' => __( 'Avg response (ms)', 'rtcamp-amc-copilot' ),
			'pass_rate'       => __( 'Pass rate', 'rtcamp-amc-copilot' ),
			'fixed_in'        => __( 'Fixed in', 'rtcamp-amc-copilot' ),
			'old_version'     => __( 'From', 'rtcamp-amc-copilot' ),
			'new_version'     => __( 'To', 'rtcamp-amc-copilot' ),
			'display_name'    => __( 'Name', 'rtcamp-amc-copilot' ),
		];

		return $labels[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) );
	}
}
