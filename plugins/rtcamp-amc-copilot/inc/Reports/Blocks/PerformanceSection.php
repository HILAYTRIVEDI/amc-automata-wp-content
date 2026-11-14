<?php
/**
 * Report section block: Performance.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

use AMC\Reports\Period;

/**
 * Class PerformanceSection
 *
 * Monthly reports show the weekly response trend, database size and autoload
 * size, as before. Quarterly and annual reports (and long custom ones) show the
 * sizes at the end of the period plus a monthly trend: a core Table block
 * (month, average response, database size, autoload size) and a small inline
 * SVG bar chart of the average response time. A month without data reads
 * "No data", never zero. Reports stored before the monthly figures existed
 * fall back to their `response_trend` points and show "No data" for the sizes.
 *
 * @since 0.1.0
 */
final class PerformanceSection extends AbstractSectionBlock {

	/**
	 * Bar slot width in the chart's SVG units.
	 */
	private const CHART_SLOT = 40;

	/**
	 * Chart height in SVG units: bars grow up from CHART_BASELINE.
	 */
	private const CHART_HEIGHT = 110;

	/**
	 * Y of the bar baseline.
	 */
	private const CHART_BASELINE = 90;

	/**
	 * Tallest bar, in SVG units.
	 */
	private const CHART_BAR_MAX = 70;

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'performance';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$trend = $this->monthly_trend( $data );
		$rows  = [];

		// The monthly table replaces the raw response_trend row.
		if ( [] === $trend ) {
			$response_trend = $data->value( 'response_trend' );

			if ( is_array( $response_trend ) ) {
				$rows[] = [ $data->label( 'response_trend' ), $this->format_structured( $response_trend ) ];
			} else {
				$rows[] = [ $data->label( 'response_trend' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
			}
		}

		$rows[] = [ $data->label( 'database_size_bytes' ), $this->format_value( $data->value( 'database_size_bytes' ), $data->type( 'database_size_bytes' ) ) ];
		$rows[] = [ $data->label( 'autoload_size_bytes' ), $this->format_value( $data->value( 'autoload_size_bytes' ), $data->type( 'autoload_size_bytes' ) ) ];

		$html = $this->key_value_table( $rows );

		if ( [] !== $trend ) {
			$html .= sprintf(
				'<h3 class="wp-block-heading">%1$s</h3>%2$s%3$s',
				esc_html__( 'Monthly trend', 'rtcamp-amc-copilot' ),
				$this->trend_table( $trend ),
				$this->trend_chart( $trend )
			);
		}

		return $html;
	}

	/**
	 * Monthly rows for the trend, [] when the report has no monthly breakdown.
	 *
	 * Reads `monthly` (quarterly, annual, long custom). Entries that predate
	 * the performance keys take their average from the `response_trend` point
	 * with the same start date, and have no size figures.
	 *
	 * @param SectionData $data Resolved values.
	 *
	 * @return list<array{label: string, avg_ms: int|null, database_size_bytes: int|null, autoload_size_bytes: int|null}>
	 */
	private function monthly_trend( SectionData $data ): array {
		$monthly = $data->snapshot['monthly'] ?? null;
		$points  = [];

		foreach ( (array) $data->value( 'response_trend' ) as $point ) {
			if ( is_array( $point ) && isset( $point['start'] ) ) {
				$points[ (string) $point['start'] ] = $point['avg_ms'] ?? null;
			}
		}

		// Snapshots from before `monthly` existed carry only the monthly response points.
		if ( ! is_array( $monthly ) || [] === $monthly ) {
			$period_type = (string) ( $data->snapshot['period']['type'] ?? '' );

			if ( ! in_array( $period_type, [ Period::TYPE_QUARTER, Period::TYPE_YEAR ], true ) || [] === $points ) {
				return [];
			}

			$monthly = array_map(
				static fn ( array $point ): array => [
					'label' => (string) ( $point['label'] ?? $point['start'] ),
					'start' => (string) $point['start'],
				],
				array_values( array_filter( (array) $data->value( 'response_trend' ), static fn ( $point ): bool => is_array( $point ) && isset( $point['start'] ) ) )
			);
		}

		$rows = [];

		foreach ( $monthly as $month ) {
			if ( ! is_array( $month ) ) {
				continue;
			}

			$start = (string) ( $month['start'] ?? '' );
			// An explicit null is "no data for that month"; only a missing key (older snapshot) falls back.
			$avg = array_key_exists( 'avg_response_ms', $month ) ? $month['avg_response_ms'] : ( $points[ $start ] ?? null );

			$rows[] = [
				'label'               => (string) ( $month['label'] ?? $start ),
				'avg_ms'              => is_numeric( $avg ) ? (int) $avg : null,
				'database_size_bytes' => is_numeric( $month['database_size_bytes'] ?? null ) ? (int) $month['database_size_bytes'] : null,
				'autoload_size_bytes' => is_numeric( $month['autoload_size_bytes'] ?? null ) ? (int) $month['autoload_size_bytes'] : null,
			];
		}

		return $rows;
	}

	/**
	 * Core Table block markup for the monthly trend.
	 *
	 * @param list<array{label: string, avg_ms: int|null, database_size_bytes: int|null, autoload_size_bytes: int|null}> $trend Monthly rows.
	 *
	 * @return string Escaped HTML.
	 */
	private function trend_table( array $trend ): string {
		$body = '';

		foreach ( $trend as $month ) {
			$body .= sprintf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( $month['label'] ),
				$this->milliseconds( $month['avg_ms'] ),
				$this->bytes( $month['database_size_bytes'] ),
				$this->bytes( $month['autoload_size_bytes'] )
			);
		}

		return sprintf(
			'<figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr><th scope="col">%1$s</th><th scope="col">%2$s</th><th scope="col">%3$s</th><th scope="col">%4$s</th></tr></thead><tbody>%5$s</tbody></table></figure>',
			esc_html__( 'Month', 'rtcamp-amc-copilot' ),
			esc_html__( 'Avg response', 'rtcamp-amc-copilot' ),
			esc_html__( 'Database size', 'rtcamp-amc-copilot' ),
			esc_html__( 'Autoload size', 'rtcamp-amc-copilot' ),
			$body
		);
	}

	/**
	 * Inline SVG bar chart of the average response time per month.
	 *
	 * Bars use currentColor, so the chart follows the theme and prints in the
	 * text colour. The table above holds the same numbers; the SVG also has a
	 * text alternative. Empty when no month has a response time.
	 *
	 * @param list<array{label: string, avg_ms: int|null, database_size_bytes: int|null, autoload_size_bytes: int|null}> $trend Monthly rows.
	 *
	 * @return string Escaped HTML.
	 */
	private function trend_chart( array $trend ): string {
		$values = array_filter( array_column( $trend, 'avg_ms' ), static fn ( $ms ): bool => null !== $ms );

		if ( [] === $values ) {
			return '';
		}

		$max      = max( 1, ...$values );
		$no_data  = __( 'No data', 'rtcamp-amc-copilot' );
		$bars     = '';
		$summary  = [];
		$bar_size = self::CHART_SLOT * 0.6;

		foreach ( $trend as $i => $month ) {
			$center = ( $i + 0.5 ) * self::CHART_SLOT;
			$ms     = $month['avg_ms'];
			$short  = explode( ' ', $month['label'] )[0];

			if ( null === $ms ) {
				$summary[] = $month['label'] . ': ' . $no_data;
				$bars     .= sprintf( '<text x="%1$s" y="%2$s" text-anchor="middle" font-size="7" fill="currentColor">%3$s</text>', $center, self::CHART_BASELINE - 4, esc_html( $no_data ) );
			} else {
				$height = max( 1, round( $ms / $max * self::CHART_BAR_MAX ) );

				/* translators: 1: month label, 2: average response time in milliseconds. */
				$summary[] = sprintf( __( '%1$s: %2$s ms', 'rtcamp-amc-copilot' ), $month['label'], number_format_i18n( $ms ) );
				$bars     .= sprintf(
					'<rect x="%1$s" y="%2$s" width="%3$s" height="%4$s" fill="currentColor" fill-opacity="0.6"/><text x="%5$s" y="%6$s" text-anchor="middle" font-size="8" fill="currentColor">%7$s</text>',
					$center - $bar_size / 2,
					self::CHART_BASELINE - $height,
					$bar_size,
					$height,
					$center,
					self::CHART_BASELINE - $height - 3,
					esc_html( number_format_i18n( $ms ) )
				);
			}

			$bars .= sprintf( '<text x="%1$s" y="%2$s" text-anchor="middle" font-size="8" fill="currentColor">%3$s</text>', $center, self::CHART_BASELINE + 12, esc_html( $short ) );
		}

		$alt = sprintf(
			/* translators: %s: list of "Month: value ms" entries. */
			__( 'Average response time per month, in milliseconds. %s', 'rtcamp-amc-copilot' ),
			implode( '; ', $summary )
		);

		return sprintf(
			'<figure class="amc-trend-chart"><svg role="img" aria-label="%1$s" viewBox="0 0 %2$s %3$s" xmlns="http://www.w3.org/2000/svg"><title>%4$s</title><line x1="0" y1="%5$s" x2="%2$s" y2="%5$s" stroke="currentColor" stroke-width="0.5"/>%6$s</svg></figure>',
			esc_attr( $alt ),
			count( $trend ) * self::CHART_SLOT,
			self::CHART_HEIGHT,
			esc_html__( 'Average response time per month', 'rtcamp-amc-copilot' ),
			self::CHART_BASELINE,
			$bars
		);
	}

	/**
	 * Response time cell: "123 ms" or "No data".
	 *
	 * @param int|null $ms Milliseconds.
	 *
	 * @return string Escaped HTML.
	 */
	private function milliseconds( ?int $ms ): string {
		if ( null === $ms ) {
			return esc_html__( 'No data', 'rtcamp-amc-copilot' );
		}

		/* translators: %s: response time in milliseconds. */
		return esc_html( sprintf( __( '%s ms', 'rtcamp-amc-copilot' ), number_format_i18n( $ms ) ) );
	}

	/**
	 * Size cell: "50 MB" or "No data".
	 *
	 * @param int|null $bytes Bytes.
	 *
	 * @return string Escaped HTML.
	 */
	private function bytes( ?int $bytes ): string {
		if ( null === $bytes ) {
			return esc_html__( 'No data', 'rtcamp-amc-copilot' );
		}

		return esc_html( (string) size_format( $bytes, 1 ) );
	}
}
