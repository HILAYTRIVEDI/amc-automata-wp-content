<?php
/**
 * Check: security.config (hardening settings).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Security;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Support\SecurityConfig;

/**
 * Class ConfigCheck
 *
 * Gathers the facts (constants, the `admin` user, XML-RPC, uploads listing)
 * and lets SecurityConfig judge them. The helper's English strings are not
 * used: every title and detail is translated here from the item key and code.
 * Secret values never leave this class: only a verdict per salt is stored.
 *
 * Facts: disallow_file_edit (DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS), wp_debug,
 * wp_debug_display, salts (name => value), admin_username_exists, xmlrpc_enabled,
 * uploads_listing (a real request for the uploads folder that finds an index page).
 *
 * Result::$data shape:
 *   items   list<array{key: string, status: 'pass'|'warn'|'fail', code: string, title: string, detail: string}>
 *   counts  array{pass: int, warn: int, fail: int}
 *
 * Status rules: any fail -> FAIL; any warn -> WARN; else PASS.
 * Findings: one per item that is not a pass, dedupe key `config:<key>`,
 * severity MEDIUM for a fail and LOW for a warn (digest, never immediate).
 *
 * @since 0.1.0
 */
final class ConfigCheck implements Check {

	/**
	 * Secret key constants.
	 */
	private const SALTS = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ];

	/**
	 * Facts: callable(): array<string, mixed>.
	 *
	 * @var callable
	 */
	private $facts_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $facts_source Test seam; default reads the real site.
	 */
	public function __construct( ?callable $facts_source = null ) {
		$this->facts_source = $facts_source ?? [ self::class, 'gather_facts' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'security.config';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::SECURITY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Security configuration', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::DAILY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context (unused: the facts describe the current site).
	 */
	public function run( Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the Check contract.
		$items    = [];
		$findings = [];
		$counts   = [
			'pass' => 0,
			'warn' => 0,
			'fail' => 0,
		];

		foreach ( SecurityConfig::evaluate( ( $this->facts_source )() ) as $raw ) {
			$text    = self::describe( $raw['key'], $raw['status'], $raw['code'] );
			$item    = [
				'key'    => $raw['key'],
				'status' => $raw['status'],
				'code'   => $raw['code'],
				'title'  => $text['title'],
				'detail' => $text['detail'],
			];
			$items[] = $item;

			++$counts[ $raw['status'] ];

			if ( 'pass' !== $raw['status'] ) {
				$findings[] = new Finding( 'config:' . $raw['key'], 'fail' === $raw['status'] ? Severity::MEDIUM : Severity::LOW, $item['title'] . ': ' . $item['detail'], $item );
			}
		}

		$data = [
			'items'  => $items,
			'counts' => $counts,
		];

		if ( $counts['fail'] > 0 ) {
			/* translators: %d: Number of failed checks. */
			$summary = sprintf( _n( '%d security setting needs fixing.', '%d security settings need fixing.', $counts['fail'], 'rtcamp-amc-copilot' ), $counts['fail'] );

			if ( $counts['warn'] > 0 ) {
				$summary .= ' ' . sprintf(
					/* translators: %d: Number of warnings. */
					_n( '%d more needs a look.', '%d more need a look.', $counts['warn'], 'rtcamp-amc-copilot' ),
					$counts['warn']
				);
			}

			return Result::fail( $summary, $data, $findings );
		}

		if ( $counts['warn'] > 0 ) {
			/* translators: %d: Number of warnings. */
			return Result::warn( sprintf( _n( '%d security setting needs a look.', '%d security settings need a look.', $counts['warn'], 'rtcamp-amc-copilot' ), $counts['warn'] ), $data, $findings );
		}

		return Result::pass( __( 'All security settings look good.', 'rtcamp-amc-copilot' ), $data );
	}

	/**
	 * Translated title and detail for one evaluated item.
	 *
	 * @param string $key    Item key.
	 * @param string $status pass, warn or fail.
	 * @param string $code   Reason code.
	 *
	 * @return array{title: string, detail: string}
	 */
	public static function describe( string $key, string $status, string $code ): array {
		if ( str_starts_with( $key, 'salt_' ) ) {
			$name = strtoupper( substr( $key, 5 ) );

			return [
				/* translators: %s: Constant name, e.g. AUTH_KEY. */
				'title'  => sprintf( __( 'Secret key %s', 'rtcamp-amc-copilot' ), $name ),
				'detail' => match ( $code ) {
					'missing'     => sprintf(
						/* translators: %s: Constant name. */
						__( '%s is missing. Generate real keys with the WordPress.org secret-key service.', 'rtcamp-amc-copilot' ),
						$name
					),
					'placeholder' => sprintf(
						/* translators: %s: Constant name. */
						__( '%s is still the default placeholder. Generate real keys with the WordPress.org secret-key service.', 'rtcamp-amc-copilot' ),
						$name
					),
					'short'       => sprintf(
						/* translators: %s: Constant name. */
						__( '%s is shorter than 32 characters. Generate real keys with the WordPress.org secret-key service.', 'rtcamp-amc-copilot' ),
						$name
					),
					'duplicate'   => sprintf(
						/* translators: %s: Constant name. */
						__( '%s has the same value as another secret key. Every key and salt must be unique.', 'rtcamp-amc-copilot' ),
						$name
					),
					default       => sprintf(
						/* translators: %s: Constant name. */
						__( '%s is set to a real, unique value.', 'rtcamp-amc-copilot' ),
						$name
					),
				},
			];
		}

		$ok = 'pass' === $status;

		return match ( $key ) {
			'disallow_file_edit'    => [
				'title'  => __( 'Plugin and theme file editing', 'rtcamp-amc-copilot' ),
				'detail' => $ok
					? __( 'File editing from wp-admin is disabled.', 'rtcamp-amc-copilot' )
					: __( 'Plugin and theme files can be edited from wp-admin. Set DISALLOW_FILE_EDIT to true in wp-config.php.', 'rtcamp-amc-copilot' ),
			],
			'wp_debug_display'      => [
				'title'  => __( 'Debug output on live pages', 'rtcamp-amc-copilot' ),
				'detail' => $ok
					? __( 'PHP errors are not shown to visitors.', 'rtcamp-amc-copilot' )
					: __( 'PHP warnings and errors are shown to visitors. Set WP_DEBUG_DISPLAY to false.', 'rtcamp-amc-copilot' ),
			],
			'admin_username_exists' => [
				'title'  => __( 'Default "admin" username', 'rtcamp-amc-copilot' ),
				'detail' => $ok
					? __( 'No user is named "admin".', 'rtcamp-amc-copilot' )
					: __( 'A user named "admin" exists. Rename it and use a strong password with two-factor authentication.', 'rtcamp-amc-copilot' ),
			],
			'xmlrpc_enabled'        => [
				'title'  => __( 'XML-RPC', 'rtcamp-amc-copilot' ),
				'detail' => $ok
					? __( 'XML-RPC is disabled.', 'rtcamp-amc-copilot' )
					: __( 'XML-RPC is enabled. Disable it unless a client such as Jetpack or the mobile app needs it.', 'rtcamp-amc-copilot' ),
			],
			'uploads_listing'       => [
				'title'  => __( 'Uploads directory listing', 'rtcamp-amc-copilot' ),
				'detail' => $ok
					? __( 'The uploads folder does not list its files.', 'rtcamp-amc-copilot' )
					: __( 'The uploads folder lists every uploaded file. Turn off directory indexes for it.', 'rtcamp-amc-copilot' ),
			],
			default                 => [
				'title'  => $key,
				'detail' => $status,
			],
		};
	}

	/**
	 * Read the facts from this site.
	 *
	 * @return array<string, mixed>
	 */
	public static function gather_facts(): array {
		$salts = [];
		foreach ( self::SALTS as $name ) {
			$salts[ $name ] = defined( $name ) ? constant( $name ) : null;
		}

		return [
			'disallow_file_edit'    => ( defined( 'DISALLOW_FILE_EDIT' ) && constant( 'DISALLOW_FILE_EDIT' ) ) || ( defined( 'DISALLOW_FILE_MODS' ) && constant( 'DISALLOW_FILE_MODS' ) ),
			'wp_debug'              => defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ),
			'wp_debug_display'      => defined( 'WP_DEBUG_DISPLAY' ) ? constant( 'WP_DEBUG_DISPLAY' ) : null,
			'salts'                 => $salts,
			'admin_username_exists' => (bool) username_exists( 'admin' ),
			'xmlrpc_enabled'        => (bool) apply_filters( 'xmlrpc_enabled', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading a core filter.
			'uploads_listing'       => self::uploads_listing_is_on(),
		];
	}

	/**
	 * Whether a request for the uploads folder returns an index page.
	 *
	 * An unreachable site counts as "off": nothing was observed.
	 */
	private static function uploads_listing_is_on(): bool {
		$uploads = wp_upload_dir( null, false );
		$url     = trailingslashit( (string) $uploads['baseurl'] );

		$verify   = (bool) apply_filters( 'https_local_ssl_verify', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$response = wp_remote_get( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- A single short request to this site's own uploads folder.
			$url,
			[
				'timeout'     => 3,
				'redirection' => 0,
				'sslverify'   => $verify,
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		return 1 === preg_match( '/<title>\s*Index of\b/i', substr( (string) wp_remote_retrieve_body( $response ), 0, 4096 ) );
	}
}
