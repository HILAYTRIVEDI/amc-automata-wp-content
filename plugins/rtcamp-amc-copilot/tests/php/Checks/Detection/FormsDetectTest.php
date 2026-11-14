<?php
/**
 * Tests for the detect.forms check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Detection;

use AMC\Checks\Context;
use AMC\Checks\Detection\FormsDetect;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Status;
use AMC\Tests\TestCase;

/**
 * Class FormsDetectTest
 */
final class FormsDetectTest extends TestCase {

	/**
	 * A get_plugins()-shaped array with Gravity Forms, one of its add-ons and
	 * a look-alike that must not match.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function gravity_forms_plugins(): array {
		return [
			'gravityforms/gravityforms.php'        => [
				'Name'    => 'Gravity Forms',
				'Version' => '2.8.4',
			],
			'gravityformsmailchimp/gravityformsmailchimp.php' => [
				'Name'    => 'Gravity Forms Mailchimp Add-On',
				'Version' => '5.2.0',
			],
			'contact-form-7/wp-contact-form-7.php' => [
				'Name'    => 'Contact Form 7',
				'Version' => '5.9.3',
			],
		];
	}

	/**
	 * Build the check with canned seams.
	 *
	 * @param array<string, array<string, mixed>> $plugins   Installed plugins.
	 * @param string[]                            $active    Active plugin files.
	 * @param int|null                            $form_count Value the form counter seam returns.
	 */
	private function check( array $plugins, array $active = [], ?int $form_count = null ): FormsDetect {
		return new FormsDetect(
			static fn (): array => $plugins,
			static fn (): array => $active,
			static fn ( ?string $plugin ): ?int => null === $plugin ? null : $form_count
		);
	}

	public function test_metadata(): void {
		$check = new FormsDetect();

		$this->assertSame( 'detect.forms', $check->id() );
		$this->assertSame( Group::DETECTION, $check->group() );
		$this->assertSame( Schedule::WEEKLY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_it_reports_the_main_plugin_its_addons_and_the_form_count(): void {
		$result = $this->check(
			$this->gravity_forms_plugins(),
			[ 'gravityforms/gravityforms.php' ],
			4
		)->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 'gravityforms/gravityforms.php', $result->data['plugin'] );
		$this->assertTrue( $result->data['active'] );
		$this->assertSame( 4, $result->data['form_count'] );
		$this->assertSame(
			[
				[
					'file'   => 'gravityformsmailchimp/gravityformsmailchimp.php',
					'name'   => 'Gravity Forms Mailchimp Add-On',
					'active' => false,
				],
			],
			$result->data['addons']
		);
		$this->assertStringContainsString( 'Form plugin detected: gravityforms', $result->summary );
	}

	public function test_an_inactive_main_plugin_is_still_reported(): void {
		$result = $this->check( $this->gravity_forms_plugins() )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( 'gravityforms/gravityforms.php', $result->data['plugin'] );
		$this->assertFalse( $result->data['active'] );
	}

	public function test_no_form_plugin_passes_without_a_count(): void {
		$result = $this->check( [] )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertNull( $result->data['plugin'] );
		$this->assertFalse( $result->data['active'] );
		$this->assertSame( [], $result->data['addons'] );
		$this->assertNull( $result->data['form_count'] );
		$this->assertStringContainsString( 'No form plugin detected', $result->summary );
	}

	public function test_result_is_json_serialisable(): void {
		$result = $this->check( $this->gravity_forms_plugins(), [], 4 )->run( Context::for_tests() );

		$this->assertIsString( wp_json_encode( $result->to_array() ) );
	}
}
