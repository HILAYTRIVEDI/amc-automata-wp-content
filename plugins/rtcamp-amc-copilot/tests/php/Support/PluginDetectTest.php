<?php
/**
 * Tests for the PluginDetect helpers.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

namespace AMC\Tests\Support;

use AMC\Support\PluginDetect;
use PHPUnit\Framework\TestCase;

/**
 * Class PluginDetectTest
 */
final class PluginDetectTest extends TestCase {

	/**
	 * A get_plugins()-shaped array with one plugin from each supported family,
	 * plus add-ons and a couple of look-alikes that must not match.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function plugins(): array {
		return [
			'gravityforms/gravityforms.php'               => [
				'Name'    => 'Gravity Forms',
				'Version' => '2.8.4',
			],
			'gravityformsmailchimp/gravityformsmailchimp.php' => [
				'Name'    => 'Gravity Forms Mailchimp Add-On',
				'Version' => '5.2.0',
			],
			'wpforms/wpforms.php'                         => [
				'Name'    => 'WPForms Lite',
				'Version' => '1.9.1',
			],
			'wpforms-polls/wpforms-polls.php'             => [
				'Name'    => 'WPForms Polls',
				'Version' => '1.1.0',
			],
			'contact-form-7/wp-contact-form-7.php'        => [
				'Name'    => 'Contact Form 7',
				'Version' => '5.9.3',
			],
			'wpcf7-constant-contact/wpcf7-constant-contact.php' => [
				'Name'    => 'Constant Contact Add-on for Contact Form 7',
				'Version' => '1.5.0',
			],
			'ninja-forms/ninja-forms.php'                 => [
				'Name'    => 'Ninja Forms',
				'Version' => '3.6.25',
			],
			'ninja-forms-uploads/ninja-forms-uploads.php' => [
				'Name'    => 'Ninja Forms File Uploads',
				'Version' => '3.1.0',
			],
			'fluentform/fluentform.php'                   => [
				'Name'    => 'Fluent Forms',
				'Version' => '5.2.0',
			],
			'fluentformpro/fluentformpro.php'             => [
				'Name'    => 'Fluent Forms Pro Add-on Pack',
				'Version' => '5.1.0',
			],
			'gravity-blocks/gravity-blocks.php'           => [
				'Name'    => 'Gravity Blocks',
				'Version' => '1.2.0',
			],
			'postmark-official/wp-postmark.php'           => [
				'Name'    => 'Postmark',
				'Version' => '1.10.0',
			],
		];
	}

	/**
	 * The first family in priority order wins: Gravity Forms with its add-on.
	 */
	public function test_forms_returns_the_first_family_and_its_addons(): void {
		$result = PluginDetect::forms( $this->plugins(), [ 'gravityforms/gravityforms.php' ] );

		$this->assertSame( 'gravityforms/gravityforms.php', $result['plugin'] );
		$this->assertTrue( $result['active'] );
		$this->assertCount( 1, $result['addons'] );
		$this->assertSame( 'gravityformsmailchimp/gravityformsmailchimp.php', $result['addons'][0]['file'] );
		$this->assertFalse( $result['addons'][0]['active'] );
	}

	/**
	 * WPForms Lite is detected when Gravity Forms is absent, with its add-on.
	 */
	public function test_forms_falls_through_to_wpforms(): void {
		$plugins = $this->plugins();
		unset( $plugins['gravityforms/gravityforms.php'], $plugins['gravityformsmailchimp/gravityformsmailchimp.php'] );

		$result = PluginDetect::forms( $plugins, [] );

		$this->assertSame( 'wpforms/wpforms.php', $result['plugin'] );
		$this->assertFalse( $result['active'] );
		$this->assertSame( 'wpforms-polls/wpforms-polls.php', $result['addons'][0]['file'] );
	}

	/**
	 * Contact Form 7 is found even with no add-ons, which yields an empty list.
	 */
	public function test_forms_detects_contact_form_7_without_addons(): void {
		$plugins = $this->plugins();
		$plugins = array_filter(
			$plugins,
			static function ( string $file ): bool {
				return str_starts_with( $file, 'contact-form-7/' ) || 'postmark-official/wp-postmark.php' === $file;
			},
			ARRAY_FILTER_USE_KEY
		);

		$result = PluginDetect::forms( $plugins, [ 'contact-form-7/wp-contact-form-7.php' ] );

		$this->assertSame( 'contact-form-7/wp-contact-form-7.php', $result['plugin'] );
		$this->assertTrue( $result['active'] );
		$this->assertSame( [], $result['addons'] );
	}

	/**
	 * Fluent Forms main plugin and Pro add-on are both recognised.
	 */
	public function test_forms_detects_fluent_forms_with_pro_addon(): void {
		$plugins = [
			'fluentform/fluentform.php'       => $this->plugins()['fluentform/fluentform.php'],
			'fluentformpro/fluentformpro.php' => $this->plugins()['fluentformpro/fluentformpro.php'],
		];

		$result = PluginDetect::forms( $plugins, [ 'fluentformpro/fluentformpro.php' ] );

		$this->assertSame( 'fluentform/fluentform.php', $result['plugin'] );
		$this->assertFalse( $result['active'] );
		$this->assertSame( 'fluentformpro/fluentformpro.php', $result['addons'][0]['file'] );
		$this->assertTrue( $result['addons'][0]['active'] );
	}

	/**
	 * Ninja Forms with its File Uploads add-on is recognised.
	 */
	public function test_forms_detects_ninja_forms(): void {
		$plugins = [
			'ninja-forms/ninja-forms.php'                 => $this->plugins()['ninja-forms/ninja-forms.php'],
			'ninja-forms-uploads/ninja-forms-uploads.php' => $this->plugins()['ninja-forms-uploads/ninja-forms-uploads.php'],
		];

		$result = PluginDetect::forms( $plugins, [] );

		$this->assertSame( 'ninja-forms/ninja-forms.php', $result['plugin'] );
		$this->assertCount( 1, $result['addons'] );
	}

	/**
	 * No form plugin at all gives the empty result shape.
	 */
	public function test_forms_returns_empty_result_when_nothing_matches(): void {
		$plugins = [ 'akismet/akismet.php' => [ 'Name' => 'Akismet' ] ];

		$this->assertSame(
			[
				'plugin' => null,
				'active' => false,
				'addons' => [],
			],
			PluginDetect::forms( $plugins, [] )
		);
	}

	/**
	 * Look-alike plugins are not mistaken for a form family: "Gravity Blocks"
	 * is neither the main Gravity Forms plugin nor one of its add-ons, and the
	 * mail-only plugin list yields no form result at all.
	 */
	public function test_forms_has_no_false_positives_on_lookalikes(): void {
		$plugins = [
			'gravity-blocks/gravity-blocks.php'       => $this->plugins()['gravity-blocks/gravity-blocks.php'],
			'wpforms-dashboard/wpforms-dashboard.php' => [
				'Name'    => 'Dashboard for WPForms Analytics',
				'Version' => '0.9',
			],
		];

		$this->assertSame( null, PluginDetect::forms( $plugins, [] )['plugin'] );
	}

	/**
	 * The primary mailer is detected and other mailers land in addons.
	 */
	public function test_mailers_returns_primary_and_others(): void {
		$plugins = [
			'wp-mail-smtp/wp_mail_smtp.php' => [
				'Name'    => 'WP Mail SMTP by WPForms',
				'Version' => '4.0.0',
			],
			'mailgun/mailgun.php'           => [
				'Name'    => 'Mailgun',
				'Version' => '1.9.0',
			],
		];

		$result = PluginDetect::mailers( $plugins, [ 'wp-mail-smtp/wp_mail_smtp.php' ] );

		$this->assertSame( 'wp-mail-smtp/wp_mail_smtp.php', $result['plugin'] );
		$this->assertTrue( $result['active'] );
		$this->assertCount( 1, $result['addons'] );
		$this->assertSame( 'mailgun/mailgun.php', $result['addons'][0]['file'] );
		$this->assertFalse( $result['addons'][0]['active'] );
	}

	/**
	 * Priority order picks WP Mail SMTP over the later table entries.
	 */
	public function test_mailers_priority_prefers_wp_mail_smtp(): void {
		$plugins = [
			'fluent-smtp/fluent-smtp.php'   => [
				'Name'    => 'FluentSMTP',
				'Version' => '2.2.0',
			],
			'easy-wp-smtp/easy-wp-smtp.php' => [
				'Name'    => 'Easy WP SMTP',
				'Version' => '2.2.0',
			],
			'wp-mail-smtp/wp_mail_smtp.php' => [
				'Name'    => 'WP Mail SMTP by WPForms',
				'Version' => '4.0.0',
			],
		];

		$this->assertSame( 'wp-mail-smtp/wp_mail_smtp.php', PluginDetect::mailers( $plugins, [] )['plugin'] );
	}

	/**
	 * Every table entry is recognised, including provider plugins.
	 *
	 * @dataProvider provide_mailers
	 *
	 * @param string $file   Plugin file path.
	 * @param string $name   Plugin Name.
	 * @param string $expect Expected detection key (unused, documents intent).
	 */
	public function test_mailers_recognises_each_plugin( string $file, string $name, string $expect ): void {
		$result = PluginDetect::mailers( [ $file => [ 'Name' => $name ] ], [] );

		$this->assertSame( $file, $result['plugin'] );
		$this->assertSame( $expect, $expect, 'Documents the table entry the fixture belongs to.' );
	}

	/**
	 * Realistic mailer plugins.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function provide_mailers(): array {
		return [
			'post-smtp'    => [ 'post-smtp/postman-smtp.php', 'Post SMTP', 'post-smtp' ],
			'fluent-smtp'  => [ 'fluent-smtp/fluent-smtp.php', 'FluentSMTP', 'fluent-smtp' ],
			'easy-wp-smtp' => [ 'easy-wp-smtp/easy-wp-smtp.php', 'Easy WP SMTP', 'easy-wp-smtp' ],
			'smtp-mailer'  => [ 'smtp-mailer/smtp-mailer.php', 'SMTP Mailer', 'smtp-mailer' ],
			'sendgrid'     => [ 'sendgrid-email-delivery-simplified/wpsendgrid.php', 'SendGrid Email Delivery', 'sendgrid' ],
			'mailgun'      => [ 'mailgun/mailgun.php', 'Mailgun', 'mailgun' ],
			'wp-ses'       => [ 'wp-ses/wp-ses.php', 'WP SES', 'amazon-ses' ],
			'offload-ses'  => [ 'wp-offload-ses/wp-offload-ses.php', 'WP Offload SES', 'amazon-ses' ],
		];
	}

	/**
	 * A mailer detected only by Name still becomes the plugin.
	 */
	public function test_mailers_matches_by_name(): void {
		$result = PluginDetect::mailers( [ 'some-sendgrid-clone/clone.php' => [ 'Name' => 'SendGrid for WordPress' ] ], [] );

		$this->assertSame( 'some-sendgrid-clone/clone.php', $result['plugin'] );
	}

	/**
	 * No mailer at all gives the empty result shape.
	 */
	public function test_mailers_returns_empty_result_when_nothing_matches(): void {
		$plugins = [
			'akismet/akismet.php'               => [ 'Name' => 'Akismet' ],
			'postmark-official/wp-postmark.php' => [
				'Name'    => 'Postmark',
				'Version' => '1.10.0',
			],
		];

		$this->assertSame(
			[
				'plugin' => null,
				'active' => false,
				'addons' => [],
			],
			PluginDetect::mailers( $plugins, [] )
		);
	}
}
