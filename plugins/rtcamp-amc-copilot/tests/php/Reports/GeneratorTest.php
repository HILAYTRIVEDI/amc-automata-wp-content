<?php
/**
 * Tests for the report generator.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Core\Settings;
use AMC\Reports\FieldCatalogue;
use AMC\Reports\Generator;
use AMC\Reports\Period;
use AMC\Reports\ReportPostType;
use AMC\Reports\SnapshotSchema;
use AMC\Runner\Lock;
use WP_Post;

/**
 * Class GeneratorTest
 */
final class GeneratorTest extends ReportsTestCase {

	/**
	 * Posts of the report type, any status except the trash.
	 *
	 * @return list<int>
	 */
	private function report_ids(): array {
		return array_map(
			'intval',
			get_posts(
				[
					'post_type'   => ReportPostType::SLUG,
					'post_status' => 'any',
					'numberposts' => 50,
					'fields'      => 'ids',
				]
			)
		);
	}

	public function test_generate_creates_a_draft_with_every_section_block_and_all_meta(): void {
		$this->seed_change( 'plugin', 'install', 'new-form', '2026-10-10 12:00:00', [ 'new_version' => '1.0' ] );

		$post_id = $this->generator()->generate( $this->october(), $this->engineer );

		$this->assertIsInt( $post_id );

		$post = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( ReportPostType::SLUG, $post->post_type );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( $this->engineer, (int) $post->post_author );
		$this->assertSame( 'Website Maintenance Report: October 2026', $post->post_title );

		$blocks = array_values( array_filter( parse_blocks( $post->post_content ), static fn ( array $block ): bool => null !== $block['blockName'] ) );

		$this->assertSame( array_column( ReportPostType::template(), 0 ), array_column( $blocks, 'blockName' ), 'every section, in template order' );
		$this->assertSame(
			[
				'move'   => true,
				'remove' => true,
			],
			$blocks[7]['attrs']['lock'],
			'locked against move and remove'
		);

		$this->assertSame( 'month', get_post_meta( $post_id, '_amc_period_type', true ) );
		$this->assertSame( '2026-10-01', get_post_meta( $post_id, '_amc_period_start', true ) );
		$this->assertSame( '2026-10-31', get_post_meta( $post_id, '_amc_period_end', true ) );
		$this->assertSame( Settings::environment(), get_post_meta( $post_id, '_amc_environment', true ) );
		$this->assertSame( 'AMC-NWT-2026-10', get_post_meta( $post_id, '_amc_report_id', true ) );
		$this->assertSame( '2026-11-01T06:00:00Z', get_post_meta( $post_id, '_amc_snapshot_taken_at', true ) );
		$this->assertSame( ReportPostType::BUILD_READY, get_post_meta( $post_id, '_amc_build_status', true ) );

		$snapshot = SnapshotSchema::for_post( $post_id );

		$this->assertSame( '2026-10-01', $snapshot['period']['start'] );
		$this->assertSame( 'new-form', $snapshot['sections']['plugins_themes']['added'][0]['slug'] );
		$this->assertSame( 'Erin Engineer', $snapshot['sections']['overview']['developer_name'] );

		foreach ( FieldCatalogue::auto() as $section => $fields ) {
			foreach ( $fields as $field ) {
				$this->assertArrayHasKey( $field, $snapshot['sections'][ $section ], "{$section}.{$field}" );
			}
		}
	}

	public function test_generate_works_without_a_logged_in_user(): void {
		wp_set_current_user( 0 );

		$post_id = $this->generator()->generate( $this->october() );

		$this->assertIsInt( $post_id, 'cron has no current user' );
		$this->assertCount( 18, array_filter( parse_blocks( get_post( $post_id )->post_content ), static fn ( array $block ): bool => null !== $block['blockName'] ) );
	}

	public function test_blocks_survive_a_user_without_unfiltered_html(): void {
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		\AMC\Core\Capabilities::grant( $author );
		wp_set_current_user( $author );

		$this->assertFalse( current_user_can( 'unfiltered_html' ) );

		$post_id = $this->generator()->generate( $this->october(), $author );
		$content = get_post( $post_id )->post_content;

		$this->assertSame( \AMC\Reports\Generator::content(), $content, 'kses did not touch the block comments or their JSON' );
	}

	public function test_generating_the_same_period_twice_never_makes_a_second_draft(): void {
		$generator = $this->generator();

		$first  = $generator->generate( $this->october(), $this->engineer );
		$second = $generator->generate( $this->october(), $this->engineer );

		$this->assertIsInt( $first );
		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'amc_report_exists', $second->get_error_code() );
		$this->assertSame( 409, $second->get_error_data()['status'] );
		$this->assertSame( $first, $second->get_error_data()['existing_id'] );
		$this->assertSame( [ $first ], $this->report_ids() );
	}

	public function test_an_existing_report_blocks_a_new_one_in_any_status(): void {
		$generator = $this->generator();
		$first     = $generator->generate( $this->october(), $this->engineer );

		wp_update_post(
			[
				'ID'          => $first,
				'post_status' => 'publish',
			]
		);

		$again = $generator->create( $this->october() );

		$this->assertInstanceOf( \WP_Error::class, $again );
		$this->assertSame( 'amc_report_exists', $again->get_error_code() );

		wp_trash_post( $first );

		$this->assertIsInt( $generator->create( $this->october() ), 'a trashed report no longer holds the period' );
	}

	public function test_two_overlapping_creations_are_serialised_by_a_lock(): void {
		$generator = $this->generator();
		$lock      = Lock::named( 'report-create:AMC-NWT-2026-10', 120 );

		$this->assertTrue( $lock->acquire() );

		$blocked = $generator->create( $this->october() );

		$this->assertInstanceOf( \WP_Error::class, $blocked );
		$this->assertSame( 'amc_locked', $blocked->get_error_code() );
		$this->assertSame( [], $this->report_ids() );

		$lock->release();

		$this->assertIsInt( $generator->create( $this->october() ) );
	}

	public function test_different_periods_get_different_reports(): void {
		$generator = $this->generator();
		$tz        = new \DateTimeZone( 'UTC' );

		$september = \AMC\Reports\Periods::previous( Period::TYPE_MONTH, new \DateTimeImmutable( '2026-10-03', $tz ), null, $tz );

		$a = $generator->generate( $this->october() );
		$b = $generator->generate( $september );

		$this->assertNotSame( $a, $b );
		$this->assertSame( 'AMC-NWT-2026-09', get_post_meta( $b, '_amc_report_id', true ) );
	}

	public function test_saved_fields_carry_forward_into_the_new_report(): void {
		update_option(
			'amc_saved_fields',
			[
				'overview' => [
					'client_name'          => 'Acme Ltd',
					'maintenance_schedule' => 'Monthly, first week',
				],
				'hosting'  => [ 'hosting_type' => 'Managed WordPress' ],
			],
			false
		);

		$post_id  = $this->generator()->generate( $this->october() );
		$sections = SnapshotSchema::for_post( $post_id )['sections'];

		$this->assertSame( 'Acme Ltd', $sections['overview']['client_name'] );
		$this->assertSame( 'Monthly, first week', $sections['overview']['maintenance_schedule'] );
		$this->assertSame( 'Managed WordPress', $sections['hosting']['hosting_type'] );
		$this->assertNull( $sections['ssl']['ssl_type'] );
	}

	public function test_saved_fields_come_from_the_latest_published_report_of_the_same_environment(): void {
		$old = $this->generator()->generate( $this->october() );
		$this->assertIsInt( $old );

		$snapshot                                        = SnapshotSchema::for_post( $old );
		$snapshot['sections']['overview']['client_name'] = 'From the last report';
		update_post_meta( $old, '_amc_snapshot', wp_slash( (string) wp_json_encode( $snapshot ) ) );
		wp_update_post(
			[
				'ID'          => $old,
				'post_status' => 'publish',
			]
		);
		delete_option( 'amc_saved_fields' ); // The publish wrote it back; start from the report alone.

		$tz       = new \DateTimeZone( 'UTC' );
		$november = \AMC\Reports\Periods::previous( Period::TYPE_MONTH, new \DateTimeImmutable( '2026-12-03', $tz ), null, $tz );
		$new      = $this->generator()->generate( $november );

		$this->assertSame( 'From the last report', SnapshotSchema::for_post( $new )['sections']['overview']['client_name'] );

		// A report from another environment is not a source.
		update_post_meta( $old, '_amc_environment', 'staging' );
		delete_option( 'amc_saved_fields' );

		$tz       = new \DateTimeZone( 'UTC' );
		$december = \AMC\Reports\Periods::previous( Period::TYPE_MONTH, new \DateTimeImmutable( '2027-01-03', $tz ), null, $tz );
		$other    = $this->generator()->generate( $december );

		$this->assertNull( SnapshotSchema::for_post( $other )['sections']['overview']['client_name'] );
	}

	public function test_regenerate_replaces_the_snapshot_and_keeps_engineer_fields_and_custom_sections(): void {
		$generator = $this->generator();
		$post_id   = $generator->generate( $this->october(), $this->engineer );

		// The engineer edits the draft: Engineer fields, an override, a hidden section, a custom section.
		$edited = get_post( $post_id )->post_content;
		$edited = str_replace(
			'<!-- wp:amc/section-overview {"lock":{"move":true,"remove":true}} /-->',
			'<!-- wp:amc/section-overview {"lock":{"move":true,"remove":true},"engineer":{"hours_used":"<p>6.5 hours<\/p>"},"overrides":{"client_name":"Acme (override)"}} /-->',
			$edited
		);
		$edited = str_replace(
			'<!-- wp:amc/section-ssl {"lock":{"move":true,"remove":true}} /-->',
			'<!-- wp:amc/custom-section {"title":"Migration notes","carryForward":true} -->' . "\n"
			. '<p>We moved the CDN.</p>' . "\n"
			. '<!-- /wp:amc/custom-section -->' . "\n\n"
			. '<!-- wp:amc/section-ssl {"lock":{"move":true,"remove":true},"hidden":true} /-->',
			$edited
		);
		wp_update_post(
			wp_slash(
				[
					'ID'           => $post_id,
					'post_content' => $edited,
				]
			)
		);
		$content_before = get_post( $post_id )->post_content;

		$this->assertStringContainsString( '6.5 hours', $content_before );
		$this->assertStringContainsString( 'We moved the CDN.', $content_before );

		// New data arrives, then the engineer regenerates.
		$this->assertCount( 0, SnapshotSchema::for_post( $post_id )['sections']['plugins_themes']['added'] );
		$this->seed_change( 'plugin', 'install', 'late-plugin', '2026-10-30 12:00:00', [ 'new_version' => '2.0' ] );

		$result = $generator->regenerate( $post_id );

		$this->assertTrue( $result );

		$after = get_post( $post_id );

		$this->assertSame( $content_before, $after->post_content, 'content (Engineer fields, overrides, hidden, custom sections) is untouched' );
		$this->assertSame( 'draft', $after->post_status );
		$this->assertSame( 'late-plugin', SnapshotSchema::for_post( $post_id )['sections']['plugins_themes']['added'][0]['slug'], 'Auto fields are refreshed' );
		$this->assertSame( 'AMC-NWT-2026-10', get_post_meta( $post_id, '_amc_report_id', true ) );
		$this->assertSame( [ $post_id ], $this->report_ids(), 'regenerating does not create another report' );
	}

	public function test_regenerate_refreshes_saved_fields_from_settings(): void {
		$generator = $this->generator();
		$post_id   = $generator->generate( $this->october() );

		$this->assertNull( SnapshotSchema::for_post( $post_id )['sections']['overview']['client_name'] );

		update_option( 'amc_saved_fields', [ 'overview' => [ 'client_name' => 'Added later' ] ], false );
		$generator->regenerate( $post_id );

		$this->assertSame( 'Added later', SnapshotSchema::for_post( $post_id )['sections']['overview']['client_name'] );
	}

	public function test_a_published_report_cannot_be_regenerated(): void {
		$generator = $this->generator();
		$post_id   = $generator->generate( $this->october() );

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);

		$result = $generator->regenerate( $post_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'amc_report_published', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_regenerating_something_that_is_not_a_report_is_a_404(): void {
		$page   = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$result = $this->generator()->regenerate( $page );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'amc_not_found', $result->get_error_code() );
	}

	public function test_the_draft_ready_action_fires_once_per_report(): void {
		$fired = [];
		add_action(
			'amc_report_draft_ready',
			static function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$generator = $this->generator();
		$post_id   = $generator->generate( $this->october() );
		$generator->regenerate( $post_id );
		$generator->build( $post_id );

		$this->assertSame( [ $post_id ], $fired, 'only after the first snapshot, never on regenerate' );
	}

	public function test_create_then_queue_builds_later_not_inline(): void {
		$generator = $this->generator();
		$post_id   = $generator->create( $this->october(), $this->engineer );

		$this->assertIsInt( $post_id );
		$this->assertSame( ReportPostType::BUILD_PENDING, get_post_meta( $post_id, '_amc_build_status', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_amc_snapshot_taken_at', true ), 'no snapshot yet' );
		$this->assertNull( SnapshotSchema::for_post( $post_id )['sections']['domains']['primary_domain'] );

		$generator->queue_build( $post_id );
		$generator->queue_build( $post_id );

		$this->assertNotFalse( wp_next_scheduled( Generator::BUILD_HOOK, [ $post_id ] ) );
		$this->assertCount( 1, array_filter( _get_cron_array(), static fn ( array $events ): bool => isset( $events[ Generator::BUILD_HOOK ] ) ), 'queued once' );

		$generator->run_queued_build( $post_id );

		$this->assertSame( ReportPostType::BUILD_READY, get_post_meta( $post_id, '_amc_build_status', true ) );
		$this->assertSame( 'example.test', SnapshotSchema::for_post( $post_id )['sections']['domains']['primary_domain'] );
	}

	public function test_resolve_period_defaults_to_the_last_completed_period(): void {
		$generator = $this->generator();

		$month = $generator->resolve_period( 'month' );

		$this->assertInstanceOf( Period::class, $month );
		$this->assertSame( '2026-10-01', $month->start()->format( 'Y-m-d' ), 'clock is 2026-11-01' );

		$quarter = $generator->resolve_period( 'quarter' );
		$year    = $generator->resolve_period( 'year' );

		$this->assertSame( '2026-07-01', $quarter->start()->format( 'Y-m-d' ) );
		$this->assertSame( '2025-01-01', $year->start()->format( 'Y-m-d' ) );
	}

	public function test_resolve_period_follows_the_contract_start(): void {
		Settings::update( [ 'contract_start' => '2025-07-15' ] );

		$quarter = $this->generator()->resolve_period( 'quarter' );
		$year    = $this->generator()->resolve_period( 'year' );

		$this->assertSame( '2026-10-15', $this->generator()->contract_start()?->setDate( 2026, 10, 15 )->format( 'Y-m-d' ) );
		$this->assertSame( '2026-07-15', $quarter->start()->format( 'Y-m-d' ) );
		$this->assertSame( '2025-07-15', $year->start()->format( 'Y-m-d' ) );
		$this->assertSame( '2026-07-14', $year->end()->format( 'Y-m-d' ) );
	}

	public function test_resolve_period_with_a_date_picks_the_period_that_contains_it(): void {
		$generator = $this->generator();

		$this->assertSame( '2026-03-01', $generator->resolve_period( 'month', '2026-03-17' )->start()->format( 'Y-m-d' ) );
		$this->assertSame( '2026-01-01', $generator->resolve_period( 'quarter', '2026-02-20' )->start()->format( 'Y-m-d' ) );
		$this->assertSame( '2026-01-01', $generator->resolve_period( 'year', '2026-12-31' )->start()->format( 'Y-m-d' ) );
	}

	public function test_resolve_period_custom_needs_two_valid_dates(): void {
		$generator = $this->generator();

		$custom = $generator->resolve_period( 'custom', '2026-10-05', '2026-10-20' );

		$this->assertSame( 'custom', $custom->type() );
		$this->assertSame( 'AMC-NWT-20261005-20261020', $generator->report_id( $custom ) );

		foreach ( [ [ null, null ], [ '2026-10-05', null ], [ '2026-10-20', '2026-10-05' ], [ 'nope', '2026-10-05' ], [ '2026-02-31', '2026-03-01' ] ] as [ $start, $end ] ) {
			$error = $generator->resolve_period( 'custom', $start, $end );

			$this->assertInstanceOf( \WP_Error::class, $error, (string) $start . '..' . (string) $end );
			$this->assertSame( 'amc_invalid_period', $error->get_error_code() );
			$this->assertSame( 400, $error->get_error_data()['status'] );
		}
	}

	public function test_site_code_is_initials_or_a_short_word_and_can_be_filtered(): void {
		$this->assertSame( 'NWT', $this->generator()->site_code() );

		add_filter( 'amc_report_site_code', static fn (): string => 'acme-1' );

		$this->assertSame( 'ACME1', $this->generator()->site_code() );
	}

	public function test_content_has_no_section_twice(): void {
		$names = [];

		foreach ( parse_blocks( Generator::content() ) as $block ) {
			if ( null !== $block['blockName'] ) {
				$names[] = $block['blockName'];
			}
		}

		$this->assertSame( array_unique( $names ), $names );
		$this->assertCount( count( SnapshotSchema::sections() ), $names );
	}
}
