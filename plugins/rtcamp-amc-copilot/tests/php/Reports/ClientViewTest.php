<?php
/**
 * Tests for the client "Maintenance Reports" page.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Reports\ClientView;
use AMC\Reports\ReportPostType;

/**
 * Class ClientViewTest
 */
final class ClientViewTest extends ReportsTestCase {

	/**
	 * Published report ID.
	 *
	 * @var int
	 */
	private int $published = 0;

	/**
	 * Draft report ID.
	 *
	 * @var int
	 */
	private int $draft = 0;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		$this->published = self::factory()->post->create(
			[
				'post_type'    => ReportPostType::SLUG,
				'post_status'  => 'publish',
				'post_title'   => 'October Report',
				'post_content' => '<p>Hello from the report body</p>',
			]
		);
		$this->draft     = self::factory()->post->create(
			[
				'post_type'   => ReportPostType::SLUG,
				'post_status' => 'draft',
				'post_title'  => 'Secret Draft',
			]
		);

		unset( $_GET['report'] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tear_down(): void {
		unset( $_GET['report'] );

		parent::tear_down();
	}

	/**
	 * Run render() and return what it printed.
	 */
	private function rendered(): string {
		$level = ob_get_level();
		ob_start();

		try {
			( new ClientView() )->render();

			return (string) ob_get_contents();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
	}

	public function test_a_client_administrator_gets_a_top_level_menu(): void {
		global $menu;

		wp_set_current_user( $this->client );
		$view = new ClientView();
		$menu = [];
		$view->register_menu();

		$entry = null;

		foreach ( $menu as $item ) {
			if ( ClientView::SLUG === $item[2] ) {
				$entry = $item;
			}
		}

		$this->assertNotNull( $entry );
		$this->assertSame( 'Maintenance Reports', $entry[0] );
		$this->assertSame( 'amc_view_reports', $entry[1] );
	}

	public function test_an_engineer_gets_a_client_view_submenu_under_the_post_type(): void {
		global $submenu;

		wp_set_current_user( $this->engineer );
		$submenu = [];
		( new ClientView() )->register_menu();

		$parent = 'edit.php?post_type=' . ReportPostType::SLUG;

		$this->assertContains( ClientView::SLUG, array_column( $submenu[ $parent ] ?? [], 2 ) );
	}

	public function test_a_user_who_cannot_read_reports_gets_no_menu(): void {
		global $menu, $submenu;

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$menu    = [];
		$submenu = [];
		( new ClientView() )->register_menu();

		$this->assertNotContains( ClientView::SLUG, array_column( $menu, 2 ) );
		$this->assertArrayNotHasKey( 'edit.php?post_type=' . ReportPostType::SLUG, $submenu );
	}

	public function test_the_list_shows_published_reports_only(): void {
		wp_set_current_user( $this->client );

		$html = $this->rendered();

		$this->assertStringContainsString( 'October Report', $html );
		$this->assertStringNotContainsString( 'Secret Draft', $html );
		$this->assertStringContainsString( 'page=amc-reports&#038;report=' . $this->published, $html );
		$this->assertStringContainsString( 'widefat', $html, 'core admin table classes' );
	}

	public function test_an_empty_list_says_so(): void {
		wp_delete_post( $this->published, true );
		wp_set_current_user( $this->client );

		$this->assertStringContainsString( 'No reports have been published yet', $this->rendered() );
	}

	public function test_a_published_report_is_shown_read_only_with_its_body(): void {
		wp_set_current_user( $this->client );
		$_GET['report'] = (string) $this->published;

		$html = $this->rendered();

		$this->assertStringContainsString( 'amc-report-view', $html );
		$this->assertStringContainsString( 'id="amc-report-' . $this->published . '"', $html );
		$this->assertStringContainsString( 'Hello from the report body', $html );
		$this->assertStringNotContainsString( 'post.php', $html, 'no edit link' );
		$this->assertStringNotContainsString( 'draft preview', $html );
	}

	public function test_a_client_cannot_open_a_draft(): void {
		wp_set_current_user( $this->client );
		$_GET['report'] = (string) $this->draft;

		$this->expectException( \WPDieException::class );

		$this->rendered();
	}

	public function test_a_user_without_the_read_capability_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_GET['report'] = (string) $this->published;

		$this->expectException( \WPDieException::class );

		$this->rendered();
	}

	public function test_an_engineer_can_preview_a_draft_here(): void {
		wp_set_current_user( $this->engineer );
		$_GET['report'] = (string) $this->draft;

		$this->assertStringContainsString( 'draft preview', $this->rendered() );
	}

	public function test_something_that_is_not_a_report_is_not_found(): void {
		wp_set_current_user( $this->client );
		$_GET['report'] = (string) self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->expectException( \WPDieException::class );

		$this->rendered();
	}

	public function test_visible_report_rules(): void {
		wp_set_current_user( $this->client );
		$this->assertNotNull( ClientView::visible_report( $this->published ) );
		$this->assertNull( ClientView::visible_report( $this->draft ) );
		$this->assertNull( ClientView::visible_report( 0 ) );

		wp_set_current_user( $this->engineer );
		$this->assertNotNull( ClientView::visible_report( $this->draft ) );

		wp_set_current_user( 0 );
		$this->assertNull( ClientView::visible_report( $this->published ) );
	}

	public function test_view_urls_differ_for_clients_and_engineers(): void {
		$this->assertSame( admin_url( 'admin.php?page=amc-reports&report=' . $this->published ), ClientView::view_url( $this->published, false ) );
		$this->assertSame( admin_url( 'edit.php?post_type=amc_report&page=amc-reports&report=' . $this->published ), ClientView::view_url( $this->published, true ) );
	}

	public function test_the_body_can_be_replaced_through_the_filter(): void {
		add_filter( 'amc_report_view_content', static fn (): string => '<p>D2 markup</p>' );
		wp_set_current_user( $this->client );
		$_GET['report'] = (string) $this->published;

		$html = $this->rendered();

		$this->assertStringContainsString( 'D2 markup', $html );
		$this->assertStringNotContainsString( 'Hello from the report body', $html );
	}

	public function test_a_published_report_shows_the_rtcamp_logo_in_its_header(): void {
		wp_set_current_user( $this->client );
		$_GET['report'] = (string) $this->published;

		$html = $this->rendered();

		$this->assertStringContainsString( 'class="amc-report-logo"', $html );
		$this->assertStringContainsString( 'alt="rtCamp"', $html );
		$this->assertStringContainsString( 'width="96" height="32"', $html );
		$this->assertStringContainsString( 'assets/images/rtcamp-logo.svg', $html );
		$this->assertFileExists( AMC_COPILOT_PATH . 'assets/images/rtcamp-logo.svg' );
		$this->assertLessThan( strpos( $html, 'October Report' ), strpos( $html, 'amc-report-logo' ), 'the logo comes before the title' );
	}

	public function test_the_logo_url_can_be_overridden_through_the_filter(): void {
		add_filter( 'amc_report_logo_url', static fn (): string => 'https://example.test/brand.svg' );
		wp_set_current_user( $this->client );
		$_GET['report'] = (string) $this->published;

		$html = $this->rendered();

		$this->assertStringContainsString( 'src="https://example.test/brand.svg"', $html );
		$this->assertStringNotContainsString( 'assets/images/rtcamp-logo.svg', $html );
	}

	public function test_the_report_view_marks_the_body_for_the_print_stylesheet(): void {
		( new ClientView() )->enqueue_print_styles();

		$this->assertStringContainsString( 'amc-report-page', (string) apply_filters( 'admin_body_class', '' ) );
	}

	public function test_the_d2_enqueue_hook_fires_only_on_the_view_screen_of_one_report(): void {
		global $submenu;

		$fired = [];
		add_action(
			'amc_report_view_enqueue',
			static function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		wp_set_current_user( $this->client );
		$view = new ClientView();
		$view->register_menu();

		$screen = ( new \ReflectionProperty( $view, 'hook_suffix' ) )->getValue( $view );

		$this->assertNotSame( '', $screen );

		$view->enqueue( $screen ); // The list: no report named.
		$this->assertSame( [], $fired );

		$_GET['report'] = (string) $this->published;
		$view->enqueue( 'some-other-screen' );
		$this->assertSame( [], $fired );

		$view->enqueue( $screen );
		$this->assertSame( [ $this->published ], $fired );

		$_GET['report'] = (string) $this->draft;
		$view->enqueue( $screen );
		$this->assertSame( [ $this->published ], $fired, 'a draft is not enqueued for a client' );
	}
}
