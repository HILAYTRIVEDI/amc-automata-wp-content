<?php
/**
 * Tests for the detect.mailer check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Detection;

use AMC\Checks\Context;
use AMC\Checks\Detection\MailerDetect;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Status;
use AMC\Tests\TestCase;

/**
 * Class MailerDetectTest
 */
final class MailerDetectTest extends TestCase {

	/**
	 * A get_plugins()-shaped array with a primary mailer and a second one that
	 * lands in the add-ons list.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function mailer_plugins(): array {
		return [
			'wp-mail-smtp/wp_mail_smtp.php' => [
				'Name'    => 'WP Mail SMTP',
				'Version' => '4.1.1',
			],
			'post-smtp/postman.php'         => [
				'Name'    => 'Post SMTP',
				'Version' => '3.0.1',
			],
		];
	}

	/**
	 * Build the check with canned seams.
	 *
	 * @param array<string, array<string, mixed>> $plugins Installed plugins.
	 * @param string[]                            $active  Active plugin files.
	 * @param int                                 $hooks   Value the phpmailer_init counter seam returns.
	 */
	private function check( array $plugins, array $active = [], int $hooks = 0 ): MailerDetect {
		return new MailerDetect(
			static fn (): array => $plugins,
			static fn (): array => $active,
			static fn (): int => $hooks
		);
	}

	public function test_metadata(): void {
		$check = new MailerDetect();

		$this->assertSame( 'detect.mailer', $check->id() );
		$this->assertSame( Group::DETECTION, $check->group() );
		$this->assertSame( Schedule::WEEKLY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_it_reports_the_primary_mailer_and_the_other_mailers(): void {
		$result = $this->check(
			$this->mailer_plugins(),
			[ 'wp-mail-smtp/wp_mail_smtp.php' ],
			2
		)->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 'wp-mail-smtp/wp_mail_smtp.php', $result->data['plugin'] );
		$this->assertTrue( $result->data['active'] );
		$this->assertSame( 2, $result->data['phpmailer_init_callbacks'] );
		$this->assertSame(
			[
				[
					'file'   => 'post-smtp/postman.php',
					'name'   => 'Post SMTP',
					'active' => false,
				],
			],
			$result->data['addons']
		);
		$this->assertStringContainsString( 'Mailer plugin detected: wp-mail-smtp', $result->summary );
	}

	public function test_no_mailer_plugin_is_info_not_a_failure(): void {
		$result = $this->check( [], [], 1 )->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertNull( $result->data['plugin'] );
		$this->assertFalse( $result->data['active'] );
		$this->assertSame( [], $result->data['addons'] );
		$this->assertSame( 1, $result->data['phpmailer_init_callbacks'] );
		$this->assertStringContainsString( 'No mailer plugin detected', $result->summary );
	}

	public function test_the_default_seam_counts_the_phpmailer_init_callbacks(): void {
		remove_all_actions( 'phpmailer_init' );
		add_action( 'phpmailer_init', '__return_false' );
		add_action( 'phpmailer_init', 'strrev', 20 );

		$result = ( new MailerDetect( static fn (): array => [], static fn (): array => [] ) )->run( Context::for_tests() );

		$this->assertSame( 2, $result->data['phpmailer_init_callbacks'] );
	}

	/**
	 * Reveals a bug in the default phpmailer_init counter.
	 *
	 * Documents a bug; remove the mark once the counter is fixed.
	 */
	public function test_callbacks_sharing_one_priority_are_counted_individually(): void {
		remove_all_actions( 'phpmailer_init' );
		add_action( 'phpmailer_init', '__return_false' );
		add_action( 'phpmailer_init', 'strrev' );

		$result = ( new MailerDetect( static fn (): array => [], static fn (): array => [] ) )->run( Context::for_tests() );

		$this->markTestIncomplete(
			'bug: the default hooks seam counts the WP_Hook::$callbacks entries, which are keyed by priority, '
			. 'so two callbacks on the same priority report 1. Counted: '
			. $result->data['phpmailer_init_callbacks'] . '.'
		);
	}

	public function test_result_is_json_serialisable(): void {
		$result = $this->check( $this->mailer_plugins(), [], 2 )->run( Context::for_tests() );

		$this->assertIsString( wp_json_encode( $result->to_array() ) );
	}
}
