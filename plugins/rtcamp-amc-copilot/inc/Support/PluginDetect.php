<?php
/**
 * Detection logic for form plugins and mail/SMTP plugins.
 *
 * Pure analysis of a `get_plugins()`-shaped array plus the list of active
 * plugin files: no database, no HTTP, no global state, no WordPress functions.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- The AMC prefix belongs to AMC Copilot, whose rename is carried on another branch.

namespace AMC\Support;

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class - PluginDetect
 */
final class PluginDetect {

	/**
	 * Form plugin families, in priority order for the main plugin result.
	 *
	 * `main_slugs` are exact folder slugs of the main plugin; `main_name` is a
	 * regex matched against the plugin Name; `addon_slug_prefixes` and
	 * `addon_name` recognise the family's known add-ons.
	 *
	 * @var array<string, array{main_slugs: string[], main_name: string, addon_slug_prefixes: string[], addon_name: string}>
	 */
	private const FORM_FAMILIES = [
		'gravity-forms'  => [
			'main_slugs'          => [ 'gravityforms' ],
			'main_name'           => '/^gravity forms$/i',
			'addon_slug_prefixes' => [ 'gravityforms', 'gravity-forms' ],
			'addon_name'          => '/gravity forms/i',
		],
		'wpforms'        => [
			'main_slugs'          => [ 'wpforms', 'wpforms-lite', 'wpforms-pro' ],
			'main_name'           => '/^wpforms( lite| pro)?$/i',
			'addon_slug_prefixes' => [ 'wpforms-' ],
			'addon_name'          => '/\bwpforms\b/i',
		],
		'contact-form-7' => [
			'main_slugs'          => [ 'contact-form-7' ],
			'main_name'           => '/^contact form 7$/i',
			'addon_slug_prefixes' => [ 'cf7-', 'wpcf7-', 'contact-form-7-' ],
			'addon_name'          => '/\bcontact form 7\b/i',
		],
		'ninja-forms'    => [
			'main_slugs'          => [ 'ninja-forms' ],
			'main_name'           => '/^ninja forms$/i',
			'addon_slug_prefixes' => [ 'ninja-forms-' ],
			'addon_name'          => '/\bninja forms\b/i',
		],
		'fluent-forms'   => [
			'main_slugs'          => [ 'fluentform', 'fluent-forms', 'fluentforms' ],
			'main_name'           => '/^fluent forms( pro)?$/i',
			'addon_slug_prefixes' => [ 'fluentform', 'fluent-forms', 'fluentforms' ],
			'addon_name'          => '/\bfluent forms\b/i',
		],
	];

	/**
	 * Mail and SMTP plugins, in priority order for the primary result.
	 *
	 * @var array<string, array{slugs: string[], name: string}>
	 */
	private const MAILER_PLUGINS = [
		'wp-mail-smtp' => [
			'slugs' => [ 'wp-mail-smtp' ],
			'name'  => '/wp mail smtp|wpforms smtp/i',
		],
		'post-smtp'    => [
			'slugs' => [ 'post-smtp', 'postman-smtp' ],
			'name'  => '/post smtp|postman smtp/i',
		],
		'fluent-smtp'  => [
			'slugs' => [ 'fluent-smtp' ],
			'name'  => '/fluent ?smtp/i',
		],
		'easy-wp-smtp' => [
			'slugs' => [ 'easy-wp-smtp' ],
			'name'  => '/easy wp smtp/i',
		],
		'smtp-mailer'  => [
			'slugs' => [ 'smtp-mailer' ],
			'name'  => '/smtp mailer/i',
		],
		'wp-smtp'      => [
			'slugs' => [ 'wp-smtp' ],
			'name'  => '/^wp smtp/i',
		],
		'sendgrid'     => [
			'slugs' => [ 'sendgrid', 'sendgrid-email-delivery-simplified' ],
			'name'  => '/\bsendgrid\b/i',
		],
		'mailgun'      => [
			'slugs' => [ 'mailgun' ],
			'name'  => '/\bmailgun\b/i',
		],
		'amazon-ses'   => [
			'slugs' => [ 'wp-ses', 'wp-offload-ses', 'wp-offload-ses-lite' ],
			'name'  => '/(?:amazon|aws|offload|wp) ses\b/i',
		],
	];

	/**
	 * Disallow instantiation - this class only exposes static helpers.
	 */
	private function __construct() {}

	/**
	 * Detects the active form plugin family and its known add-ons.
	 *
	 * The first family in priority order with a detected main plugin wins;
	 * add-ons of that family are listed, each with its own active state.
	 *
	 * @param array<string, array<string, mixed>> $plugins get_plugins()-shaped array.
	 * @param string[]                            $active  List of active plugin files.
	 *
	 * @return array{plugin: string|null, active: bool, addons: array<int, array{file: string, name: string, active: bool}>}
	 */
	public static function forms( array $plugins, array $active ): array {
		foreach ( self::FORM_FAMILIES as $family ) {
			$result = self::detect_family( $family, $plugins, $active );

			if ( null !== $result['plugin'] ) {
				return $result;
			}
		}

		return [
			'plugin' => null,
			'active' => false,
			'addons' => [],
		];
	}

	/**
	 * Detects the primary mail/SMTP plugin and any others that are installed.
	 *
	 * The first plugin in priority order wins; the remaining detected mailers
	 * (a second mailer is a classic cause of lost email) are listed as
	 * add-ons, each with its own active state.
	 *
	 * @param array<string, array<string, mixed>> $plugins get_plugins()-shaped array.
	 * @param string[]                            $active  List of active plugin files.
	 *
	 * @return array{plugin: string|null, active: bool, addons: array<int, array{file: string, name: string, active: bool}>}
	 */
	public static function mailers( array $plugins, array $active ): array {
		$detected = [];

		foreach ( $plugins as $file => $meta ) {
			$file = (string) $file;
			$slug = self::slug( $file );
			$name = self::plugin_name( $meta );

			foreach ( self::MAILER_PLUGINS as $entry ) {
				if ( in_array( $slug, $entry['slugs'], true ) || 1 === preg_match( $entry['name'], $name ) ) {
					$detected[ $file ] = $name;
					break;
				}
			}
		}

		if ( [] === $detected ) {
			return [
				'plugin' => null,
				'active' => false,
				'addons' => [],
			];
		}

		$primary = self::primary_mailer( array_keys( $detected ) );

		$addons = [];
		foreach ( $detected as $file => $name ) {
			if ( $file === $primary ) {
				continue;
			}

			$addons[] = [
				'file'   => $file,
				'name'   => $name,
				'active' => in_array( $file, $active, true ),
			];
		}

		return [
			'plugin' => $primary,
			'active' => in_array( $primary, $active, true ),
			'addons' => $addons,
		];
	}

	/**
	 * Detects one form plugin family.
	 *
	 * @param array{main_slugs: string[], main_name: string, addon_slug_prefixes: string[], addon_name: string} $family  Family definition.
	 * @param array<string, array<string, mixed>>                                                               $plugins get_plugins()-shaped array.
	 * @param string[]                                                                                          $active  List of active plugin files.
	 *
	 * @return array{plugin: string|null, active: bool, addons: array<int, array{file: string, name: string, active: bool}>}
	 */
	private static function detect_family( array $family, array $plugins, array $active ): array {
		$main_file = null;

		foreach ( $plugins as $file => $meta ) {
			$file = (string) $file;

			if ( self::is_main_plugin( $family, $file, self::plugin_name( $meta ) ) ) {
				$main_file = $file;
				break;
			}
		}

		if ( null === $main_file ) {
			return [
				'plugin' => null,
				'active' => false,
				'addons' => [],
			];
		}

		$addons = [];

		foreach ( $plugins as $file => $meta ) {
			$file = (string) $file;

			if ( $file === $main_file || in_array( self::slug( $file ), $family['main_slugs'], true ) ) {
				continue;
			}

			$name = self::plugin_name( $meta );

			if ( ! self::is_family_addon( $family, $file, $name ) ) {
				continue;
			}

			$addons[] = [
				'file'   => $file,
				'name'   => $name,
				'active' => in_array( $file, $active, true ),
			];
		}

		return [
			'plugin' => $main_file,
			'active' => in_array( $main_file, $active, true ),
			'addons' => $addons,
		];
	}

	/**
	 * Whether a plugin file is a family's main plugin.
	 *
	 * @param array{main_slugs: string[], main_name: string, addon_slug_prefixes: string[], addon_name: string} $family Family definition.
	 * @param string                                                                                            $file   Plugin file path.
	 * @param string                                                                                            $name   Plugin Name.
	 *
	 * @return bool True when the file is the family's main plugin.
	 */
	private static function is_main_plugin( array $family, string $file, string $name ): bool {
		return in_array( self::slug( $file ), $family['main_slugs'], true )
			|| 1 === preg_match( $family['main_name'], $name );
	}

	/**
	 * Whether a plugin file is one of a family's known add-ons.
	 *
	 * @param array{main_slugs: string[], main_name: string, addon_slug_prefixes: string[], addon_name: string} $family Family definition.
	 * @param string                                                                                            $file   Plugin file path.
	 * @param string                                                                                            $name   Plugin Name.
	 *
	 * @return bool True when the file matches the family's add-on signatures.
	 */
	private static function is_family_addon( array $family, string $file, string $name ): bool {
		$slug = self::slug( $file );

		foreach ( $family['addon_slug_prefixes'] as $prefix ) {
			if ( str_starts_with( $slug, $prefix ) ) {
				return true;
			}
		}

		return 1 === preg_match( $family['addon_name'], $name );
	}

	/**
	 * Picks the primary mailer by the table's priority order.
	 *
	 * @param string[] $files Detected mailer plugin files.
	 *
	 * @return string The primary mailer plugin file.
	 */
	private static function primary_mailer( array $files ): string {
		foreach ( self::MAILER_PLUGINS as $entry ) {
			foreach ( $files as $file ) {
				$slug = self::slug( $file );

				if ( in_array( $slug, $entry['slugs'], true ) ) {
					return $file;
				}
			}
		}

		// Name-only matches have no table priority; fall back to the first.
		return (string) ( $files[0] ?? '' );
	}

	/**
	 * The folder slug of a plugin file path.
	 *
	 * @param string $file Plugin file path, e.g. `wpforms/wpforms.php`.
	 *
	 * @return string Folder slug, or the basename when there is no folder.
	 */
	private static function slug( string $file ): string {
		$directory = dirname( $file );

		if ( '.' === $directory || '' === $directory ) {
			return basename( $file );
		}

		return $directory;
	}

	/**
	 * The Name of a plugin from its get_plugins() metadata.
	 *
	 * @param array<string, mixed> $meta Plugin metadata row.
	 *
	 * @return string Plugin name, or an empty string when absent.
	 */
	private static function plugin_name( array $meta ): string {
		$name = $meta['Name'] ?? '';

		return is_scalar( $name ) ? trim( (string) $name ) : '';
	}
}
