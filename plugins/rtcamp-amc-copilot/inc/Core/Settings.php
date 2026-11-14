<?php
/**
 * The `amc_settings` option: schema, defaults, sanitising and access.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Core;

use AMC\Helpers\Util;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class Settings
 *
 * FROZEN SCHEMA (Wave 0). Every key, its type and default is in defaults().
 * Adding a key later: add it to defaults() AND sanitize(), and document it in
 * docs/CONTRACTS.md § Settings. Never rename or retype a key.
 *
 * The option is stored with autoload OFF. Read it only in admin, REST, cron
 * and CLI code, never on front-end requests.
 *
 * Usage:
 *   Settings::get();                          // Full array, defaults merged in.
 *   Settings::get_value( 'digest_time' );     // One key.
 *   Settings::update( [ 'kill_switch' => true ] ); // Partial update, sanitised.
 *   Settings::check_interval( 'updates.core' ); // '' = use the check's default.
 *
 * @since 0.1.0
 */
final class Settings implements Registrable {

	/**
	 * Option name.
	 */
	public const OPTION = 'amc_settings';

	/**
	 * Allowed values for `site_type`.
	 */
	public const SITE_TYPES = [ 'content', 'ecommerce' ];

	/**
	 * Allowed values for `environment` ('' = wp_get_environment_type()).
	 */
	public const ENVIRONMENTS = [ '', 'production', 'staging', 'development', 'local' ];

	/**
	 * Allowed per-check interval overrides ('' = the check's own default).
	 */
	public const INTERVALS = [ '', 'hourly', 'twicedaily', 'daily', 'weekly' ];

	/**
	 * Disclaimer keys editable per site (used by report section blocks).
	 */
	public const DISCLAIMERS = [ 'backups', 'hosting', 'domains', 'accessibility' ];

	/**
	 * Most addresses `report_publish_recipients` may hold.
	 */
	public const MAX_REPORT_PUBLISH_RECIPIENTS = 25;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_setting' ] );
	}

	/**
	 * Register the option with the Settings API (sanitize callback, no REST:
	 * the console reads and writes it through `amc/v1/settings`).
	 */
	public function register_setting(): void {
		register_setting(
			'amc',
			self::OPTION,
			[
				'type'              => 'object',
				'default'           => self::defaults(),
				'sanitize_callback' => [ self::class, 'sanitize_for_settings_api' ],
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * Default value of every key. This IS the schema.
	 *
	 * @return array{
	 *   site_type: string,
	 *   environment: string,
	 *   contract_start: string,
	 *   recipients: list<string>,
	 *   digest_time: string,
	 *   immediate_alerts_per_hour: int,
	 *   notify_client_on_publish: bool,
	 *   report_publish_recipients: list<string>,
	 *   smoke_urls: list<array{url: string, required_text: string}>,
	 *   loopback_auth: array{username: string, password: string},
	 *   kill_switch: bool,
	 *   checks: array<string, array{enabled: bool, interval: string}>,
	 *   retention_months: int,
	 *   disclaimers: array<string, string>
	 * }
	 */
	public static function defaults(): array {
		return [
			// 'content' (latest post is a smoke URL) or 'ecommerce' (latest product).
			'site_type'                 => 'content',
			// Environment label on reports. '' = wp_get_environment_type().
			'environment'               => '',
			// Contract start date, 'Y-m-d' or '' (quarters/years follow the calendar).
			'contract_start'            => '',
			// Alert and report emails. [] = every user with amc_manage (see recipients()).
			'recipients'                => [],
			// Daily digest time, site timezone, 'HH:MM'.
			'digest_time'               => '09:00',
			// Cap on immediate alert emails per hour; extra ones go to the digest.
			'immediate_alerts_per_hour' => 10,
			// Email client administrators when a report is published.
			'notify_client_on_publish'  => true,
			// Extra addresses emailed when a report is first published (on top of client administrators).
			'report_publish_recipients' => [],
			// Extra smoke URLs. The smoke check always adds home, login and latest post/product.
			'smoke_urls'                => [],
			// Optional basic-auth credentials for loopback requests. Password stored encrypted.
			'loopback_auth'             => [
				'username' => '',
				'password' => '',
			],
			// Kill switch (the AMC_COPILOT_DISABLED constant overrides this).
			'kill_switch'               => false,
			// Per-check overrides keyed by check id: [ 'enabled' => bool, 'interval' => '' | schedule ].
			'checks'                    => [],
			// History kept in the custom tables, in months (amc_queries is always 90 days).
			'retention_months'          => 13,
			// Per-site disclaimer text for report sections. '' = the block's built-in text.
			'disclaimers'               => array_fill_keys( self::DISCLAIMERS, '' ),
		];
	}

	/**
	 * The full settings array with defaults merged in.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return array_replace( self::defaults(), $stored );
	}

	/**
	 * One settings key.
	 *
	 * @param string $key Key from defaults().
	 *
	 * @return mixed The value, or null for an unknown key.
	 */
	public static function get_value( string $key ): mixed {
		$settings = self::get();

		return $settings[ $key ] ?? null;
	}

	/**
	 * Merge a partial array into the stored settings, sanitise and save.
	 *
	 * Unknown keys are dropped. A `loopback_auth.password` that is absent or
	 * empty keeps the stored password; send `null` to clear it.
	 *
	 * @param array<string, mixed> $changes Partial settings.
	 *
	 * @return array<string, mixed> The saved settings.
	 */
	public static function update( array $changes ): array {
		$current = self::get();
		$merged  = array_replace( $current, array_intersect_key( $changes, self::defaults() ) );

		if ( isset( $changes['loopback_auth'] ) && is_array( $changes['loopback_auth'] ) ) {
			$merged['loopback_auth'] = self::merge_loopback_auth( $current['loopback_auth'], $changes['loopback_auth'] );
		}

		$clean = self::sanitize( $merged );

		update_option( self::OPTION, $clean, false );

		return $clean;
	}

	/**
	 * Settings safe to send to the browser (password replaced by a flag).
	 *
	 * @return array<string, mixed>
	 */
	public static function for_rest(): array {
		$settings = self::get();

		$settings['loopback_auth']      = [
			'username'             => (string) $settings['loopback_auth']['username'],
			'has_password'         => '' !== (string) $settings['loopback_auth']['password'],
			'encryption_available' => self::encryption_available(),
		];
		$settings['kill_switch_locked'] = KillSwitch::is_locked_by_constant();
		$settings['environment']        = self::environment();

		return $settings;
	}

	/**
	 * Interval override for a check ('' = use Check::schedule()).
	 *
	 * @param string $check_id Check id.
	 */
	public static function check_interval( string $check_id ): string {
		$checks = (array) self::get_value( 'checks' );

		return (string) ( $checks[ $check_id ]['interval'] ?? '' );
	}

	/**
	 * Whether a check is enabled for scheduled runs (default true).
	 *
	 * @param string $check_id Check id.
	 */
	public static function check_enabled( string $check_id ): bool {
		$checks = (array) self::get_value( 'checks' );

		return (bool) ( $checks[ $check_id ]['enabled'] ?? true );
	}

	/**
	 * Effective environment label.
	 */
	public static function environment(): string {
		$value = (string) self::get_value( 'environment' );

		return '' !== $value ? $value : wp_get_environment_type();
	}

	/**
	 * Effective alert recipients: the configured list, or every user with
	 * `amc_manage` when the list is empty.
	 *
	 * @return list<string> Email addresses.
	 */
	public static function recipients(): array {
		$configured = (array) self::get_value( 'recipients' );

		if ( [] !== $configured ) {
			return array_values( $configured );
		}

		$emails = [];
		foreach ( Capabilities::engineers() as $user ) {
			$emails[] = $user->user_email;
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Split a list of email addresses into valid and invalid entries.
	 *
	 * Accepts an array of strings or one string separated by new lines, commas
	 * or semicolons. Valid addresses are sanitised with sanitize_email() and
	 * deduplicated case-insensitively (the first spelling wins); empty entries
	 * are ignored.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return array{valid: list<string>, invalid: list<string>}
	 */
	public static function parse_email_list( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,;]+/', $value );
		}

		$valid   = [];
		$invalid = [];

		foreach ( is_array( $value ) ? $value : [] as $entry ) {
			if ( ! is_scalar( $entry ) ) {
				$invalid[] = (string) wp_json_encode( $entry );
				continue;
			}

			$entry = trim( (string) $entry );

			if ( '' === $entry ) {
				continue;
			}

			$email = sanitize_email( $entry );

			if ( $email !== $entry || ! is_email( $email ) ) {
				$invalid[] = $entry;
				continue;
			}

			$valid[ strtolower( $email ) ] ??= $email;
		}

		return [
			'valid'   => array_values( $valid ),
			'invalid' => array_values( array_unique( $invalid ) ),
		];
	}

	/**
	 * Whether a password can be stored: needs AMC_COPILOT_ENCRYPTION_KEY in
	 * wp-config.php (the skeleton's Encryption deliberately has no salt fallback).
	 */
	public static function encryption_available(): bool {
		return defined( 'AMC_COPILOT_ENCRYPTION_KEY' ) && '' !== (string) constant( 'AMC_COPILOT_ENCRYPTION_KEY' );
	}

	/**
	 * Decrypted loopback password ('' when none). Server-side use only.
	 */
	public static function loopback_password(): string {
		$auth      = (array) self::get_value( 'loopback_auth' );
		$encrypted = (string) ( $auth['password'] ?? '' );

		if ( '' === $encrypted ) {
			return '';
		}

		if ( ! self::encryption_available() ) {
			return '';
		}

		$plain = Util::encryption()->decrypt( $encrypted );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Settings API sanitize callback (receives the whole option value).
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return array<string, mixed>
	 */
	public static function sanitize_for_settings_api( mixed $value ): array {
		return self::sanitize( is_array( $value ) ? array_replace( self::defaults(), $value ) : self::defaults() );
	}

	/**
	 * Sanitise a full settings array. Unknown keys are dropped; invalid values
	 * fall back to the default.
	 *
	 * @param array<string, mixed> $settings Settings (all keys present).
	 *
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $settings ): array {
		$defaults = self::defaults();
		$clean    = [];

		$clean['site_type']      = in_array( $settings['site_type'] ?? '', self::SITE_TYPES, true ) ? $settings['site_type'] : $defaults['site_type'];
		$clean['environment']    = in_array( $settings['environment'] ?? '', self::ENVIRONMENTS, true ) ? $settings['environment'] : $defaults['environment'];
		$clean['contract_start'] = self::sanitize_date( (string) ( $settings['contract_start'] ?? '' ) );

		$clean['recipients'] = [];
		foreach ( (array) ( $settings['recipients'] ?? [] ) as $email ) {
			$email = sanitize_email( (string) $email );
			if ( '' !== $email && is_email( $email ) ) {
				$clean['recipients'][] = $email;
			}
		}
		$clean['recipients'] = array_values( array_unique( $clean['recipients'] ) );

		$digest_time          = (string) ( $settings['digest_time'] ?? '' );
		$clean['digest_time'] = 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $digest_time ) ? $digest_time : $defaults['digest_time'];

		$clean['immediate_alerts_per_hour'] = max( 0, min( 100, absint( $settings['immediate_alerts_per_hour'] ?? $defaults['immediate_alerts_per_hour'] ) ) );
		$clean['notify_client_on_publish']  = (bool) ( $settings['notify_client_on_publish'] ?? true );
		$clean['report_publish_recipients'] = array_slice(
			self::parse_email_list( $settings['report_publish_recipients'] ?? [] )['valid'],
			0,
			self::MAX_REPORT_PUBLISH_RECIPIENTS
		);

		$clean['smoke_urls'] = [];
		foreach ( (array) ( $settings['smoke_urls'] ?? [] ) as $row ) {
			$url = esc_url_raw( (string) ( is_array( $row ) ? ( $row['url'] ?? '' ) : $row ) );
			if ( '' === $url ) {
				continue;
			}
			$clean['smoke_urls'][] = [
				'url'           => $url,
				'required_text' => sanitize_text_field( (string) ( is_array( $row ) ? ( $row['required_text'] ?? '' ) : '' ) ),
			];
		}

		$auth                   = (array) ( $settings['loopback_auth'] ?? [] );
		$clean['loopback_auth'] = [
			'username' => sanitize_text_field( (string) ( $auth['username'] ?? '' ) ),
			// Already encrypted by merge_loopback_auth(); stored as-is.
			'password' => (string) ( $auth['password'] ?? '' ),
		];

		$clean['kill_switch'] = (bool) ( $settings['kill_switch'] ?? false );

		$clean['checks'] = [];
		foreach ( (array) ( $settings['checks'] ?? [] ) as $check_id => $override ) {
			$check_id = (string) $check_id;
			if ( 1 !== preg_match( '/^[a-z0-9_]+\.[a-z0-9_]+$/', $check_id ) || ! is_array( $override ) ) {
				continue;
			}
			$interval                     = (string) ( $override['interval'] ?? '' );
			$clean['checks'][ $check_id ] = [
				'enabled'  => (bool) ( $override['enabled'] ?? true ),
				'interval' => in_array( $interval, self::INTERVALS, true ) ? $interval : '',
			];
		}

		$clean['retention_months'] = max( 1, min( 60, absint( $settings['retention_months'] ?? $defaults['retention_months'] ) ) );

		$clean['disclaimers'] = [];
		$disclaimers          = (array) ( $settings['disclaimers'] ?? [] );
		foreach ( self::DISCLAIMERS as $key ) {
			$clean['disclaimers'][ $key ] = wp_kses_post( (string) ( $disclaimers[ $key ] ?? '' ) );
		}

		return $clean;
	}

	/**
	 * Merge an incoming loopback_auth array with the stored one, encrypting a
	 * new password.
	 *
	 * @param array<string, mixed> $current  Stored value (password encrypted).
	 * @param array<string, mixed> $incoming Incoming value (password plain).
	 *
	 * @return array{username: string, password: string}
	 */
	private static function merge_loopback_auth( array $current, array $incoming ): array {
		$password = (string) ( $current['password'] ?? '' );

		if ( array_key_exists( 'password', $incoming ) && null === $incoming['password'] ) {
			$password = '';
		} elseif ( isset( $incoming['password'] ) && '' !== (string) $incoming['password'] ) {
			// Without AMC_COPILOT_ENCRYPTION_KEY the password is NOT stored (never
			// in plain text); for_rest() reports encryption_available = false.
			if ( self::encryption_available() ) {
				$encrypted = Util::encryption()->encrypt( (string) $incoming['password'] );
				$password  = false === $encrypted ? '' : $encrypted;
			}
		}

		return [
			'username' => (string) ( $incoming['username'] ?? $current['username'] ?? '' ),
			'password' => $password,
		];
	}

	/**
	 * Return a valid 'Y-m-d' date or ''.
	 *
	 * @param string $date Raw date.
	 */
	private static function sanitize_date( string $date ): string {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

		return $parsed && $parsed->format( 'Y-m-d' ) === $date ? $date : '';
	}
}
