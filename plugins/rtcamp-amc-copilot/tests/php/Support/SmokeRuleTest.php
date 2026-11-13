<?php
/**
 * Tests for the SmokeRule evaluator.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

namespace AMC\Tests\Support;

use AMC\Support\SmokeRule;
use PHPUnit\Framework\TestCase;

/**
 * Class SmokeRuleTest
 */
final class SmokeRuleTest extends TestCase {

	/**
	 * A clean home page body.
	 */
	private function healthy_body(): string {
		return "<!DOCTYPE html>\n<html><head><title>My Site</title></head>\n<body><p>Welcome home.</p></body></html>";
	}

	/**
	 * A healthy result passes: 200, fast, clean body.
	 */
	public function test_a_healthy_result_passes(): void {
		$this->assertSame(
			[
				'status'  => 'pass',
				'reasons' => [],
				'codes'   => [],
			],
			SmokeRule::evaluate( 200, 1.2, $this->healthy_body() )
		);
	}

	/**
	 * A healthy result with the required text present passes.
	 */
	public function test_required_text_present_passes(): void {
		$this->assertSame(
			[
				'status'  => 'pass',
				'reasons' => [],
				'codes'   => [],
			],
			SmokeRule::evaluate( 200, 0.9, $this->healthy_body(), 'Welcome home.' )
		);
	}

	/**
	 * Time at the boundary: 2.99s passes, exactly 3s fails.
	 */
	public function test_time_boundary(): void {
		$this->assertSame( 'pass', SmokeRule::evaluate( 200, 2.99, $this->healthy_body() )['status'] );

		$slow = SmokeRule::evaluate( 200, 3.0, $this->healthy_body() );

		$this->assertSame( 'fail', $slow['status'] );
		$this->assertStringContainsString( '3.00s', $slow['reasons'][0] );
	}

	/**
	 * A slow response fails with a timed reason.
	 */
	public function test_a_slow_response_fails(): void {
		$result = SmokeRule::evaluate( 200, 3.45, $this->healthy_body() );

		$this->assertSame( 'fail', $result['status'] );
		$this->assertStringContainsString( '3.45s', $result['reasons'][0] );
	}

	/**
	 * Non-200 statuses fail with the status in the reason.
	 *
	 * @dataProvider provide_bad_statuses
	 *
	 * @param int $status HTTP status.
	 */
	public function test_non_200_statuses_fail( int $status ): void {
		$result = SmokeRule::evaluate( $status, 0.5, $this->healthy_body() );

		$this->assertSame( 'fail', $result['status'] );
		$this->assertStringContainsString( (string) $status, $result['reasons'][0] );
	}

	/**
	 * Bad non-auth statuses.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function provide_bad_statuses(): array {
		return [
			'500' => [ 500 ],
			'404' => [ 404 ],
			'302' => [ 302 ],
			'403' => [ 403 ],
			'0'   => [ 0 ],
		];
	}

	/**
	 * PHP error text in the body fails, one test per real-world pattern.
	 *
	 * @dataProvider provide_php_error_bodies
	 *
	 * @param string $body     Body containing a PHP error.
	 * @param string $label    Expected reason fragment.
	 */
	public function test_php_error_text_fails( string $body, string $label ): void {
		$result = SmokeRule::evaluate( 200, 0.5, $body );

		$this->assertSame( 'fail', $result['status'] );
		$this->assertStringContainsString( $label, $result['reasons'][0] );
	}

	/**
	 * Realistic PHP error bodies.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_php_error_bodies(): array {
		return [
			'fatal error' => [ '<p>Fatal error: Uncaught Error: Call to undefined function xyz() in /home/site/wp-content/plugins/broken/broken.php on line 42</p>', 'fatal PHP error' ],
			'parse error' => [ 'Parse error: syntax error, unexpected token ";" in /var/www/html/wp-content/themes/child/functions.php on line 12', 'PHP parse error' ],
			'warning'     => [ '<div>Warning: Undefined array key "slug" in /home/site/wp-content/plugins/plugin/inc/class.php on line 1337</div>', 'PHP warning or notice' ],
			'notice'      => [ "Notice: Function WP_Scripts::localize was called incorrectly.\nin /var/www/wp-includes/functions.php on line 6031", 'PHP warning or notice' ],
			'deprecated'  => [ '<p>Deprecated: rtrim(): Passing null to parameter in /home/site/public/wp-includes/formatting.php on line 2700</p>', 'PHP warning or notice' ],
			'wp critical' => [ '<h1>There has been a critical error on this website.</h1><p>Check the site admin email inbox.</p>', 'WordPress fatal error' ],
		];
	}

	/**
	 * Look-alike prose that merely quotes these words must not fail.
	 *
	 * @dataProvider provide_clean_lookalike_bodies
	 *
	 * @param string $body Body with look-alike text.
	 */
	public function test_lookalike_text_does_not_fail( string $body ): void {
		$this->assertSame(
			[
				'status'  => 'pass',
				'reasons' => [],
				'codes'   => [],
			],
			SmokeRule::evaluate( 200, 0.5, $body )
		);
	}

	/**
	 * Clean bodies containing look-alike error words.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_clean_lookalike_bodies(): array {
		return [
			'warning without file'   => [ '<p>Warning: flash flood alert issued for Riverside County tonight.</p>' ],
			'deprecated as a word'   => [ '<p>This method of printing is now deprecated in favour of laser printing.</p>' ],
			'plural fatal errors'    => [ '<p>Webmasters often debug fatal errors: enable WP_DEBUG first.</p>' ],
			'quote of a notice word' => [ '<p>Notice: the office will be closed on Monday.</p>' ],
		];
	}

	/**
	 * Missing required text fails.
	 */
	public function test_missing_required_text_fails(): void {
		$result = SmokeRule::evaluate( 200, 0.5, $this->healthy_body(), 'Maintenance Mode' );

		$this->assertSame( 'fail', $result['status'] );
		$this->assertStringContainsString( 'Required text', $result['reasons'][0] );
	}

	/**
	 * Every accumulated problem is reported in one evaluation.
	 */
	public function test_multiple_problems_accumulate(): void {
		$result = SmokeRule::evaluate( 500, 4.0, 'Fatal error: Uncaught Error in /x/y.php on line 1', 'Wanted text' );

		$this->assertSame( 'fail', $result['status'] );
		$this->assertCount( 4, $result['reasons'] );
	}

	/**
	 * Loopback-style transport errors block the check instead of failing it.
	 *
	 * @dataProvider provide_loopback_transport_errors
	 *
	 * @param string $transport_error Transport error message.
	 */
	public function test_loopback_transport_errors_block( string $transport_error ): void {
		$result = SmokeRule::evaluate( 0, 0.0, '', null, $transport_error );

		$this->assertSame( 'loopback_blocked', $result['status'] );
		$this->assertStringContainsString( 'loopback', strtolower( $result['reasons'][0] ) );
	}

	/**
	 * Transport errors that mean the site's own host could not be reached.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_loopback_transport_errors(): array {
		return [
			'curl 6 dns'     => [ 'cURL error 6: Could not resolve host: example.com' ],
			'curl 7 refused' => [ 'cURL error 7: Failed to connect to 127.0.0.1 port 8888: Connection refused' ],
			'refused words'  => [ 'Connection refused by peer' ],
			'basic auth'     => [ 'HTTP 401 Unauthorized: basic auth credentials required' ],
		];
	}

	/**
	 * A timeout is a failure (a hung site), not a blocked loopback.
	 */
	public function test_timeout_fails(): void {
		$result = SmokeRule::evaluate( 0, 0.0, '', null, 'cURL error 28: Operation timed out after 5001 milliseconds' );

		$this->assertSame( 'fail', $result['status'] );
		$this->assertSame( [ 'transport_error' ], $result['codes'] );
	}

	/**
	 * PHP errors rendered with html_errors (<b> tags) are still detected.
	 */
	public function test_html_errors_markup_is_detected(): void {
		$body = "<br />\n<b>Warning</b>:  Undefined variable \$x in <b>/var/www/wp-content/plugins/p/p.php</b> on line <b>9</b><br />";

		$result = SmokeRule::evaluate( 200, 0.5, $body );

		$this->assertSame( 'fail', $result['status'] );
		$this->assertSame( [ 'php_error' ], $result['codes'] );

		$fatal = SmokeRule::evaluate( 200, 0.5, '<b>Fatal error</b>: Uncaught Error in <b>/x.php</b>:3' );
		$this->assertSame( 'fail', $fatal['status'] );
	}

	/**
	 * Other transport errors are plain failures.
	 */
	public function test_other_transport_errors_fail(): void {
		$result = SmokeRule::evaluate( 0, 0.0, '', null, 'cURL error 60: SSL certificate problem: self signed certificate' );

		$this->assertSame( 'fail', $result['status'] );
		$this->assertStringContainsString( 'Transport error', $result['reasons'][0] );
	}

	/**
	 * An HTTP 401 status without a transport error still blocks.
	 */
	public function test_http_401_blocks(): void {
		$result = SmokeRule::evaluate( 401, 0.2, '' );

		$this->assertSame( 'loopback_blocked', $result['status'] );
	}

	/**
	 * Empty transport-error strings are ignored.
	 */
	public function test_blank_transport_error_is_ignored(): void {
		$this->assertSame( 'pass', SmokeRule::evaluate( 200, 0.3, $this->healthy_body(), null, '  ' )['status'] );
	}
}
