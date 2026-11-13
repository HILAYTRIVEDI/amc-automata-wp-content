<?php
/**
 * Tests for Saved fields: storage, carry-forward, write-back and the pre-publish review.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Reports\FieldCatalogue;
use AMC\Reports\ReportPublishing;
use AMC\Reports\SavedFields;
use AMC\Reports\SnapshotSchema;
use WP_REST_Request;

/**
 * Class SavedFieldsTest
 */
final class SavedFieldsTest extends ReportsTestCase {

	/**
	 * Replace the overrides of one section block in a report's content.
	 *
	 * @param int                  $post_id   Report ID.
	 * @param string               $block     Block name, e.g. 'amc/section-overview'.
	 * @param array<string, mixed> $overrides Field => value.
	 */
	private function override( int $post_id, string $block, array $overrides ): void {
		$content = (string) get_post_field( 'post_content', $post_id );
		$old     = '<!-- wp:' . $block . ' {"lock":{"move":true,"remove":true}} /-->';
		$new     = '<!-- wp:' . $block . ' ' . wp_json_encode(
			[
				'lock'      => [
					'move'   => true,
					'remove' => true,
				],
				'overrides' => $overrides,
			]
		) . ' /-->';

		$this->assertStringContainsString( $old, $content );

		wp_update_post(
			wp_slash(
				[
					'ID'           => $post_id,
					'post_content' => str_replace( $old, $new, $content ),
				]
			)
		);
	}

	public function test_all_lists_every_saved_field_as_null_at_first(): void {
		$all = SavedFields::all();

		$this->assertSame( SnapshotSchema::saved_fields(), array_map( 'array_keys', $all ) );

		foreach ( $all as $fields ) {
			foreach ( $fields as $value ) {
				$this->assertNull( $value );
			}
		}
	}

	public function test_update_merges_clears_and_ignores_unknown_fields(): void {
		SavedFields::update(
			[
				'overview' => [
					'client_name'          => 'Acme',
					'maintenance_schedule' => 'Monthly',
					'hours_used'           => 'an engineer field: never stored',
					'nope'                 => 'unknown field',
				],
				'not_real' => [ 'x' => 'y' ],
			]
		);

		$this->assertSame( 'Acme', SavedFields::all()['overview']['client_name'] );
		$this->assertSame(
			[
				'overview' => [
					'client_name'          => 'Acme',
					'maintenance_schedule' => 'Monthly',
				],
			],
			get_option( 'amc_saved_fields' )
		);

		// A second call changes only what it sends.
		SavedFields::update(
			[
				'overview' => [ 'maintenance_schedule' => 'Weekly' ],
				'ssl'      => [ 'ssl_type' => 'Free' ],
			]
		);

		$this->assertSame( 'Acme', SavedFields::all()['overview']['client_name'] );
		$this->assertSame( 'Weekly', SavedFields::all()['overview']['maintenance_schedule'] );
		$this->assertSame( 'Free', SavedFields::all()['ssl']['ssl_type'] );

		// null and '' clear.
		SavedFields::update(
			[
				'overview' => [ 'client_name' => null ],
				'ssl'      => [ 'ssl_type' => '' ],
			]
		);

		$this->assertNull( SavedFields::all()['overview']['client_name'] );
		$this->assertNull( SavedFields::all()['ssl']['ssl_type'] );
		$this->assertArrayNotHasKey( 'ssl', (array) get_option( 'amc_saved_fields' ), 'an emptied section leaves no key behind' );
	}

	public function test_values_are_sanitised_and_capped(): void {
		SavedFields::update(
			[
				'overview' => [ 'client_name' => "<script>alert(1)</script>Acme\nLtd" ],
				'hosting'  => [ 'hosting_users' => str_repeat( 'a', 5000 ) ],
			]
		);

		$all = SavedFields::all();

		$this->assertStringNotContainsString( '<script', (string) $all['overview']['client_name'] );
		$this->assertStringContainsString( "\n", (string) $all['overview']['client_name'], 'multi-line values are kept' );
		$this->assertSame( SavedFields::MAX_LENGTH, strlen( (string) $all['hosting']['hosting_users'] ) );

		SavedFields::update( [ 'overview' => [ 'client_name' => [ 'not' => 'a string' ] ] ] );
		$this->assertStringContainsString( 'Acme', (string) SavedFields::all()['overview']['client_name'], 'an array value is ignored' );
	}

	public function test_the_option_is_not_autoloaded(): void {
		global $wpdb;

		SavedFields::update( [ 'overview' => [ 'client_name' => 'Acme' ] ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reads the autoload flag, which no API exposes.
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'amc_saved_fields' ) );

		$this->assertContains( $autoload, [ 'no', 'off' ] );
		$this->assertArrayNotHasKey( 'amc_saved_fields', wp_load_alloptions() );
	}

	public function test_a_changed_saved_field_is_listed_with_its_previous_value_before_publish(): void {
		SavedFields::update(
			[
				'overview' => [ 'client_name' => 'Acme Ltd' ],
				'hosting'  => [ 'hosting_type' => 'Managed' ],
			]
		);

		$post_id = $this->generator()->generate( $this->october(), $this->engineer );

		$this->assertSame( [], SavedFields::changes_for_post( $post_id ), 'nothing changed yet' );

		$this->override(
			$post_id,
			'amc/section-overview',
			[
				'client_name' => 'Acme Limited',
				'report_date' => 'not a saved field',
			]
		);

		$review = SavedFields::review( $post_id );

		$this->assertSame(
			[
				[
					'section'  => 'overview',
					'field'    => 'client_name',
					'label'    => 'Client',
					'previous' => 'Acme Ltd',
					'current'  => 'Acme Limited',
				],
			],
			$review['changes'],
			'only the saved field the engineer changed; an override of an Auto field is not a Saved change'
		);
		$this->assertSame( 'Acme Ltd', $review['previous']['overview']['client_name'] );
		$this->assertSame( 'Managed', $review['previous']['hosting']['hosting_type'], 'every saved field has its previous value' );
		$this->assertSame( 'Acme Ltd', SavedFields::all()['overview']['client_name'], 'nothing is written before publish' );
	}

	public function test_changed_saved_fields_are_written_back_on_publish(): void {
		SavedFields::update( [ 'overview' => [ 'client_name' => 'Acme Ltd' ] ] );

		$published = [];
		add_action(
			'amc_report_published',
			static function ( int $id ) use ( &$published ): void {
				$published[] = $id;
			}
		);

		$post_id = $this->generator()->generate( $this->october(), $this->engineer );
		$this->override(
			$post_id,
			'amc/section-overview',
			[
				'client_name' => 'Acme Limited',
				'website_url' => 'https://elsewhere.test',
			]
		);

		$this->assertSame( 'Acme Ltd', SavedFields::all()['overview']['client_name'] );

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);

		$this->assertSame( 'Acme Limited', SavedFields::all()['overview']['client_name'], 'written back for the next report' );
		$this->assertNull( SavedFields::all()['overview']['maintenance_schedule'] );
		$this->assertSame( [ $post_id ], $published );

		// Saving a published report again is not a publish.
		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Renamed',
			]
		);

		$this->assertSame( [ $post_id ], $published );

		// The next report starts from the new value.
		$tz       = new \DateTimeZone( 'UTC' );
		$november = \AMC\Reports\Periods::previous( 'month', new \DateTimeImmutable( '2026-12-03', $tz ), null, $tz );
		$next     = $this->generator()->generate( $november );

		$this->assertSame( 'Acme Limited', SnapshotSchema::for_post( $next )['sections']['overview']['client_name'] );
	}

	public function test_publishing_an_unedited_report_changes_nothing(): void {
		SavedFields::update( [ 'overview' => [ 'client_name' => 'Acme Ltd' ] ] );
		$before = get_option( 'amc_saved_fields' );

		$post_id = $this->generator()->generate( $this->october() );
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);

		$this->assertSame( $before, get_option( 'amc_saved_fields' ) );
	}

	public function test_an_empty_override_clears_the_saved_value_on_publish(): void {
		SavedFields::update( [ 'ssl' => [ 'ssl_type' => 'Purchased' ] ] );

		$post_id = $this->generator()->generate( $this->october() );
		$this->override( $post_id, 'amc/section-ssl', [ 'ssl_type' => '' ] );
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);

		$this->assertNull( SavedFields::all()['ssl']['ssl_type'] );
	}

	public function test_other_post_types_do_not_touch_saved_fields(): void {
		SavedFields::update( [ 'overview' => [ 'client_name' => 'Acme' ] ] );

		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_update_post(
			[
				'ID'          => $post,
				'post_status' => 'publish',
			]
		);

		$this->assertSame( 'Acme', SavedFields::all()['overview']['client_name'] );
	}

	public function test_the_review_is_exposed_on_the_core_rest_report_for_engineers(): void {
		SavedFields::update( [ 'overview' => [ 'client_name' => 'Acme Ltd' ] ] );

		$post_id = $this->generator()->generate( $this->october(), $this->engineer );
		$this->override( $post_id, 'amc/section-overview', [ 'client_name' => 'Acme Limited' ] );

		do_action( 'rest_api_init' );

		wp_set_current_user( $this->engineer );

		$request = new WP_REST_Request( 'GET', '/wp/v2/amc-reports/' . $post_id );
		$request->set_param( 'context', 'edit' );
		$data = rest_do_request( $request )->get_data();

		$this->assertArrayHasKey( ReportPublishing::REST_FIELD, $data );
		$this->assertSame( 'Acme Ltd', $data[ ReportPublishing::REST_FIELD ]['changes'][0]['previous'] );
		$this->assertSame( 'Acme Limited', $data[ ReportPublishing::REST_FIELD ]['changes'][0]['current'] );
		$this->assertSame( 'Acme Ltd', $data[ ReportPublishing::REST_FIELD ]['previous']['overview']['client_name'] );
	}

	public function test_only_saved_fields_count_as_saved(): void {
		foreach ( FieldCatalogue::saved() as $section => $fields ) {
			foreach ( $fields as $field ) {
				$this->assertSame( 'saved', FieldCatalogue::source( $section, $field ) );
			}
		}
	}
}
