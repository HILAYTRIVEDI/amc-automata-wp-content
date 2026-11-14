<?php
/**
 * Test.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports\Blocks;

use AMC\Reports\Blocks\PerformanceSection;
use AMC\Reports\SnapshotSchema;
use AMC\Tests\TestCase;

/**
 * Test.
 *
 * @package AMC
 */
final class PerformanceSectionTest extends TestCase {

	/**
	 * Render the block in a report whose snapshot is the empty one plus $extra.
	 *
	 * @param string               $block_markup Block markup.
	 * @param array<string, mixed> $extra        Snapshot keys to merge in.
	 */
	private function render_in_report( string $block_markup, array $extra = [] ): string {
		$snapshot = array_replace_recursive( SnapshotSchema::empty_snapshot(), $extra );
		$post_id  = self::factory()->post->create(
			[
				'post_type'    => 'amc_report',
				'post_content' => $block_markup,
			]
		);
		update_post_meta( $post_id, SnapshotSchema::META_KEY, wp_slash( wp_json_encode( $snapshot ) ) );
		$this->go_to( '/' );
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );
		return do_blocks( $block_markup );
	}

	public function test_block_is_registered(): void {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( PerformanceSection::get_name() );
		$this->assertSame( 'amc/section-performance', PerformanceSection::get_name() );
		$this->assertNotNull( $type );
	}

	public function test_hidden_section_renders_nothing(): void {
		$this->assertSame( '', trim( $this->render_in_report( '<!-- wp:amc/section-performance {"hidden":true} /-->' ) ) );
	}

	/**
	 * Quarterly snapshot with one empty month.
	 *
	 * @return array<string, mixed>
	 */
	private function quarter_snapshot(): array {
		return [
			'period'   => [
				'type'  => 'quarter',
				'start' => '2026-07-01',
				'end'   => '2026-09-30',
			],
			'sections' => [
				'performance' => [
					'response_trend'      => [
						[
							'label'  => 'Jul 2026',
							'start'  => '2026-07-01',
							'avg_ms' => 400,
						],
						[
							'label'  => 'Aug 2026',
							'start'  => '2026-08-01',
							'avg_ms' => null,
						],
						[
							'label'  => 'Sep 2026',
							'start'  => '2026-09-01',
							'avg_ms' => 800,
						],
					],
					'database_size_bytes' => 50000000,
					'autoload_size_bytes' => 600000,
				],
			],
			'monthly'  => [
				[
					'label'               => 'Jul 2026',
					'start'               => '2026-07-01',
					'avg_response_ms'     => 400,
					'database_size_bytes' => 42000000,
					'autoload_size_bytes' => 600000,
				],
				[
					'label'               => 'Aug 2026',
					'start'               => '2026-08-01',
					'avg_response_ms'     => null,
					'database_size_bytes' => null,
					'autoload_size_bytes' => null,
				],
				[
					'label'               => 'Sep 2026',
					'start'               => '2026-09-01',
					'avg_response_ms'     => 800,
					'database_size_bytes' => 50000000,
					'autoload_size_bytes' => null,
				],
			],
		];
	}

	public function test_quarterly_report_shows_a_monthly_trend_table(): void {
		$html = $this->render_in_report( '<!-- wp:amc/section-performance /-->', $this->quarter_snapshot() );

		$this->assertStringContainsString( 'Monthly trend', $html );
		$this->assertStringContainsString( '<figure class="wp-block-table">', $html );
		$this->assertStringContainsString( '<th scope="col">Month</th>', $html );
		$this->assertStringContainsString( '<th scope="row">Jul 2026</th><td>400 ms</td><td>40.1 MB</td><td>585.9 KB</td>', $html );
		$this->assertStringContainsString( '<th scope="row">Sep 2026</th><td>800 ms</td>', $html );
		$this->assertStringNotContainsString( '"avg_ms"', $html, 'the raw JSON row is replaced by the table' );
	}

	public function test_empty_month_reads_no_data_not_zero(): void {
		$html = $this->render_in_report( '<!-- wp:amc/section-performance /-->', $this->quarter_snapshot() );

		$this->assertStringContainsString( '<th scope="row">Aug 2026</th><td>No data</td><td>No data</td><td>No data</td>', $html );
		$this->assertStringContainsString( '<td>800 ms</td><td>47.7 MB</td><td>No data</td>', $html, 'a missing autoload size is No data' );
	}

	public function test_chart_is_an_svg_with_a_text_alternative_and_no_new_colours(): void {
		$html = $this->render_in_report( '<!-- wp:amc/section-performance /-->', $this->quarter_snapshot() );

		$this->assertStringContainsString( '<svg role="img" aria-label="', $html );
		$this->assertStringContainsString( 'Jul 2026: 400 ms; Aug 2026: No data; Sep 2026: 800 ms', $html );
		$this->assertSame( 2, substr_count( $html, '<rect ' ), 'a bar for each month with data only' );
		$this->assertStringContainsString( 'fill="currentColor"', $html );
		$this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,6}\b|rgb\(/i', $html );
	}

	public function test_annual_report_lists_every_month(): void {
		$snapshot                   = $this->quarter_snapshot();
		$snapshot['period']['type'] = 'year';
		$snapshot['monthly']        = [];

		for ( $m = 1; $m <= 12; $m++ ) {
			$snapshot['monthly'][] = [
				'label'               => gmdate( 'M Y', (int) mktime( 0, 0, 0, $m, 1, 2026 ) ),
				'start'               => sprintf( '2026-%02d-01', $m ),
				'avg_response_ms'     => 1 === $m ? 250 : null,
				'database_size_bytes' => null,
				'autoload_size_bytes' => null,
			];
		}

		$html = $this->render_in_report( '<!-- wp:amc/section-performance /-->', $snapshot );

		$this->assertSame( 12, substr_count( $html, '<th scope="row">' ) - 2, 'twelve month rows beside the two size rows' );
		$this->assertStringContainsString( '<th scope="row">Dec 2026</th><td>No data</td>', $html );
		$this->assertSame( 1, substr_count( $html, '<rect ' ) );
	}

	public function test_quarter_without_any_response_has_a_table_but_no_chart(): void {
		$snapshot = $this->quarter_snapshot();

		foreach ( $snapshot['monthly'] as $i => $month ) {
			$snapshot['monthly'][ $i ]['avg_response_ms'] = null;
		}

		$html = $this->render_in_report( '<!-- wp:amc/section-performance /-->', $snapshot );

		$this->assertStringContainsString( 'Monthly trend', $html );
		$this->assertStringNotContainsString( '<svg', $html );
	}

	public function test_old_quarterly_snapshot_without_monthly_data_still_renders(): void {
		$snapshot = $this->quarter_snapshot();
		unset( $snapshot['monthly'] );

		$html = $this->render_in_report( '<!-- wp:amc/section-performance /-->', $snapshot );

		$this->assertStringContainsString( '<th scope="row">Jul 2026</th><td>400 ms</td><td>No data</td><td>No data</td>', $html );
		$this->assertStringContainsString( '<th scope="row">Aug 2026</th><td>No data</td>', $html );
	}

	public function test_old_monthly_entries_without_performance_keys_use_the_response_trend(): void {
		$snapshot = $this->quarter_snapshot();

		foreach ( $snapshot['monthly'] as $i => $month ) {
			$snapshot['monthly'][ $i ] = [
				'label'           => $month['label'],
				'start'           => $month['start'],
				'plugins_updated' => 1,
			];
		}

		$html = $this->render_in_report( '<!-- wp:amc/section-performance /-->', $snapshot );

		$this->assertStringContainsString( '<th scope="row">Sep 2026</th><td>800 ms</td><td>No data</td><td>No data</td>', $html );
	}

	public function test_monthly_report_keeps_the_weekly_rows_without_a_trend_table(): void {
		$snapshot = [
			'period'   => [
				'type'  => 'month',
				'start' => '2026-10-01',
				'end'   => '2026-10-31',
			],
			'sections' => [
				'performance' => [
					'response_trend'      => [
						[
							'label'  => 'Week of 1 Oct',
							'start'  => '2026-10-01',
							'avg_ms' => 400,
						],
					],
					'database_size_bytes' => 52428800,
					'autoload_size_bytes' => 700000,
				],
			],
		];

		$html = $this->render_in_report( '<!-- wp:amc/section-performance /-->', $snapshot );

		$this->assertStringNotContainsString( 'Monthly trend', $html );
		$this->assertStringNotContainsString( '<svg', $html );
		$this->assertStringContainsString( 'Week of 1 Oct', $html );
		$this->assertStringContainsString( '52428800', $html );
	}

	public function test_snapshot_without_trend_data_renders_dashes(): void {
		$html = $this->render_in_report( '<!-- wp:amc/section-performance /-->' );

		$this->assertStringNotContainsString( 'Monthly trend', $html );
		$this->assertStringContainsString( '—', $html );
	}
}
