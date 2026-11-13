<?php
/**
 * Tests for the detect.accessibility check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Detection;

use AMC\Checks\Context;
use AMC\Checks\Detection\AccessibilityDetect;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Status;
use AMC\Tests\TestCase;

/**
 * Class AccessibilityDetectTest
 *
 * The home page is served through the pre_http_request filter, so the check
 * runs with its real default seams and no network call is made.
 */
final class AccessibilityDetectTest extends TestCase {

	/**
	 * A home page carrying a UserWay overlay script.
	 *
	 * @var string
	 */
	private const HTML = <<<'HTML'
<!DOCTYPE html><html><head>
<script src="https://cdn.userway.org/widget.js"></script>
</head><body></body></html>
HTML;

	/**
	 * Serve a canned HTTP response for the default wp_remote_get() seam.
	 *
	 * @param array<string, mixed>|\WP_Error $response Response or error.
	 */
	private function serve( array|\WP_Error $response ): void {
		add_filter( 'pre_http_request', static fn (): array|\WP_Error => $response );
	}

	public function test_metadata(): void {
		$check = new AccessibilityDetect();

		$this->assertSame( 'detect.accessibility', $check->id() );
		$this->assertSame( Group::DETECTION, $check->group() );
		$this->assertSame( Schedule::WEEKLY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_it_reports_the_tools_found_on_the_home_page(): void {
		$this->serve(
			[
				'response' => [ 'code' => 200 ],
				'body'     => self::HTML,
			]
		);

		$result = ( new AccessibilityDetect() )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( home_url( '/' ), $result->data['url'] );
		$this->assertSame( [ 'userway' ], $result->data['tools'] );
		$this->assertSame( 200, $result->data['status_code'] );
	}

	public function test_it_lists_published_statement_pages(): void {
		$page_id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Accessibility statement',
				'post_name'   => 'accessibility-statement',
			]
		);

		$this->serve(
			[
				'response' => [ 'code' => 200 ],
				'body'     => '<html><body></body></html>',
			]
		);

		$result = ( new AccessibilityDetect() )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame(
			[
				[
					'title' => 'Accessibility statement',
					'url'   => get_permalink( $page_id ),
				],
			],
			$result->data['statement_pages']
		);
	}

	public function test_a_blocked_home_page_is_info_not_a_failure(): void {
		$this->serve( new \WP_Error( 'http_request_failed', 'Loopback refused.' ) );

		$result = ( new AccessibilityDetect() )->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( [], $result->data['tools'] );
		$this->assertSame( [], $result->data['statement_pages'] );
		$this->assertNull( $result->data['status_code'] );
		$this->assertStringContainsString( 'Could not fetch the home page', $result->summary );
	}

	public function test_result_is_json_serialisable(): void {
		$this->serve(
			[
				'response' => [ 'code' => 200 ],
				'body'     => self::HTML,
			]
		);

		$result = ( new AccessibilityDetect() )->run( Context::for_tests() );

		$this->assertIsString( wp_json_encode( $result->to_array() ) );
	}
}
