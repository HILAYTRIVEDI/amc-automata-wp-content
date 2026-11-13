<?php
/**
 * Check: updates.plugins (Plugin updates).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Updates;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;

/**
 * Class PluginUpdates
 *
 * Reads the `update_plugins` site transient (what WordPress fills from
 * api.wordpress.org) plus the local plugin inventory, and reports pending
 * updates with the compatibility details an engineer needs before updating:
 * the changelog excerpt (via `plugins_api()`), the plugin's "Tested up to"
 * and "Requires PHP".
 *
 * Plugins that are not distributed on wp.org (premium or custom plugins,
 * often with their own updater) never appear in the transient. They are
 * reported with update_source `none`, so engineers check them by hand
 * (plan, Risks). We never refresh the transient ourselves; WordPress does
 * that twice a day on its own.
 *
 * The changelog excerpt comes from one `plugins_api()` request per plugin,
 * which is a remote call. At most {@see MAX_API_CALLS} are made per run; the
 * cursor in `Result::$data` lets the next run pick up where this one
 * stopped, so findings (which do not depend on the changelog) are complete
 * in every run and no batching with `partial()` is needed.
 *
 * Result::$data shape (consumed by the Updates screen and report CMS section):
 *   plugins           list<array{file: string, name: string, version: string,
 *                       active: bool, network_active: bool, update_available: bool,
 *                       new_version: string|null, tested_up_to: string|null,
 *                       requires_php: string|null, changelog_excerpt: string|null,
 *                       update_source: 'wp.org'|'none'}>
 *   total             int         Installed plugins.
 *   pending           int         Plugins with a pending update.
 *   no_update_source  list<string> Files without a wp.org update source.
 *   last_checked      string|null When WordPress last asked api.wordpress.org (UTC ISO 8601).
 *   cursor            int         Index into `plugins` where the next run resumes changelog fetches.
 *
 * Status rules:
 *   no update data    -> INFO (nothing to judge yet)
 *   any pending update -> WARN + Finding severity MEDIUM per plugin
 *   up to date        -> PASS
 *
 * Finding dedupe key: 'plugin:<file>:<new_version>' so a newer release is a
 * new finding (and alerts again), and updating resolves it.
 *
 * @since 0.1.0
 */
final class PluginUpdates implements Check {

	/**
	 * Maximum `plugins_api()` calls per run (each is a remote request).
	 *
	 * @var int
	 */
	private const MAX_API_CALLS = 10;

	/**
	 * Length of the changelog excerpt kept per plugin.
	 *
	 * @var int
	 */
	private const EXCERPT_LENGTH = 500;

	/**
	 * Returns the `update_plugins` site transient.
	 *
	 * @var callable(): mixed
	 */
	private $updates_source;

	/**
	 * Returns the installed plugins, keyed by file.
	 *
	 * @var callable(): array<string, array<string, mixed>>
	 */
	private $plugins_source;

	/**
	 * Returns the active plugin files.
	 *
	 * @var callable(): list<string>
	 */
	private $active_source;

	/**
	 * Returns the network-active plugin files.
	 *
	 * @var callable(): list<string>
	 */
	private $network_source;

	/**
	 * Returns wp.org plugin information for a slug, or false/WP_Error.
	 *
	 * @var callable(string): mixed
	 */
	private $api_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $updates_source Test seam; default reads the update_plugins site transient.
	 * @param callable|null $plugins_source Test seam; default get_plugins().
	 * @param callable|null $active_source  Test seam; default get_option( 'active_plugins' ).
	 * @param callable|null $network_source Test seam; default keys of get_site_option( 'active_sitewide_plugins' ).
	 * @param callable|null $api_source     Test seam; default plugins_api() for the changelog section.
	 */
	public function __construct(
		?callable $updates_source = null,
		?callable $plugins_source = null,
		?callable $active_source = null,
		?callable $network_source = null,
		?callable $api_source = null
	) {
		$this->updates_source = $updates_source ?? static fn (): mixed => get_site_transient( 'update_plugins' );
		$this->plugins_source = $plugins_source ?? static fn (): array => function_exists( 'get_plugins' ) ? get_plugins() : [];
		$this->active_source  = $active_source ?? static fn (): array => (array) get_option( 'active_plugins', [] );
		$this->network_source = $network_source ?? static fn (): array => array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
		$this->api_source     = $api_source ?? static function ( string $slug ): mixed {
			if ( ! function_exists( 'plugins_api' ) ) {
				return false;
			}

			return plugins_api(
				'plugin_information',
				[
					'slug'   => $slug,
					'fields' => [
						'sections' => true,
					],
				]
			);
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'updates.plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::UPDATES;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Plugin updates', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::TWICE_DAILY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context; `previous_data` carries the changelog cursor.
	 */
	public function run( Context $ctx ): Result {
		$plugins   = $this->normalise( ( $this->plugins_source )(), (array) ( $this->active_source )(), (array) ( $this->network_source )() );
		$transient = ( $this->updates_source )();

		$data = [
			'plugins'          => $plugins,
			'total'            => count( $plugins ),
			'pending'          => 0,
			'no_update_source' => [],
			'last_checked'     => null,
			'cursor'           => 0,
		];

		if ( [] === $plugins ) {
			return Result::info( __( 'No plugins are installed.', 'rtcamp-amc-copilot' ), $data );
		}

		// 1. No update data yet (fresh install, or update checks blocked): INFO.
		if ( ! is_object( $transient ) ) {
			return Result::info( __( 'WordPress has not fetched plugin update data yet.', 'rtcamp-amc-copilot' ), $data );
		}

		if ( ! empty( $transient->last_checked ) ) {
			$data['last_checked'] = gmdate( 'c', (int) $transient->last_checked );
		}

		$response   = is_array( $transient->response ?? null ) ? $transient->response : [];
		$no_update  = is_array( $transient->no_update ?? null ) ? $transient->no_update : [];
		$findings   = [];
		$has_source = [];

		foreach ( $plugins as $index => $row ) {
			$file = (string) ( $row['file'] ?? '' );
			if ( '' === $file ) {
				continue;
			}

			if ( isset( $response[ $file ] ) ) {
				// 2. Update available: add compatibility details from the offer.
				$offer       = is_object( $response[ $file ] ) ? $response[ $file ] : (object) ( $response[ $file ] );
				$new_version = (string) ( $offer->new_version ?? '' );

				$row['update_available'] = true;
				$row['new_version']      = '' !== $new_version ? $new_version : null;
				$row['tested_up_to']     = self::string_or_null( $offer->tested ?? null );
				$row['requires_php']     = self::string_or_null( $offer->requires_php ?? $offer->requires ?? null );
				$row['update_source']    = 'wp.org';
				$has_source[ $file ]     = true;

				if ( '' !== $new_version && version_compare( (string) $row['version'], $new_version, '<' ) ) {
					++$data['pending'];

					$findings[] = new Finding(
						'plugin:' . $file . ':' . $new_version,
						Severity::MEDIUM,
						sprintf(
							/* translators: 1: Plugin name, 2: Installed version, 3: Available version. */
							__( 'Plugin update available: %1$s (installed: %2$s, available: %3$s).', 'rtcamp-amc-copilot' ),
							(string) $row['name'],
							(string) $row['version'],
							$new_version
						),
						[
							'file'         => $file,
							'installed'    => (string) $row['version'],
							'new_version'  => $new_version,
							'tested_up_to' => $row['tested_up_to'],
							'requires_php' => $row['requires_php'],
						]
					);
				}

				$plugins[ $index ] = $row;
			} elseif ( isset( $no_update[ $file ] ) ) {
				// 3. On wp.org and up to date.
				$has_source[ $file ] = true;
			} else {
				// 4. Not on wp.org: premium or custom plugin with its own updater.
				$data['no_update_source'][] = $file;
			}
		}

		// 5. Changelog excerpts, at most MAX_API_CALLS per run (cursor rotates).
		$data['cursor'] = $this->fetch_changelogs( $response, $plugins, $ctx->previous_data );

		$data['plugins'] = array_values( $plugins );

		if ( [] !== $findings ) {
			return Result::warn(
				sprintf(
					/* translators: %s: Number of plugins with pending updates. */
					_n(
						'%s plugin update is pending.',
						'%s plugin updates are pending.',
						count( $findings ),
						'rtcamp-amc-copilot'
					),
					number_format_i18n( count( $findings ) )
				),
				$data,
				$findings
			);
		}

		return Result::pass( __( 'All plugins are up to date.', 'rtcamp-amc-copilot' ), $data );
	}

	/**
	 * Normalises the installed plugins into rows.
	 *
	 * @param array<string, mixed> $plugins   get_plugins()-shaped array.
	 * @param list<string>         $active    Active plugin files.
	 * @param list<string>         $networked Network-active plugin files.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function normalise( array $plugins, array $active, array $networked ): array {
		$rows = [];

		foreach ( $plugins as $file => $meta ) {
			$meta                   = is_array( $meta ) ? $meta : [];
			$rows[ (string) $file ] = [
				'file'              => (string) $file,
				'name'              => (string) ( $meta['Name'] ?? '' ),
				'version'           => (string) ( $meta['Version'] ?? '' ),
				'active'            => in_array( (string) $file, $active, true ),
				'network_active'    => in_array( (string) $file, $networked, true ),
				'update_available'  => false,
				'new_version'       => null,
				'tested_up_to'      => (string) ( $meta['Tested up to'] ?? '' ) ?: null,
				'requires_php'      => (string) ( $meta['RequiresPHP'] ?? '' ) ?: null,
				'changelog_excerpt' => null,
				'update_source'     => null,
			];
		}

		ksort( $rows );

		return array_values( $rows );
	}

	/**
	 * Fills changelog excerpts for update offers, at most MAX_API_CALLS per run.
	 *
	 * The cursor rotates over the update offers so every plugin gets its
	 * excerpt within a few runs, without the run ever blocking on dozens of
	 * remote calls.
	 *
	 * @param array<string, mixed>       $response  Transient `response` keyed by file.
	 * @param list<array<string, mixed>> $plugins  Plugin rows (updated in place).
	 * @param array<string, mixed>|null  $previous  Previous run data.
	 *
	 * @return int Cursor for the next run.
	 */
	private function fetch_changelogs( array $response, array &$plugins, ?array $previous ): int {
		$files = array_keys( $response );
		$count = count( $files );

		if ( 0 === $count ) {
			return 0;
		}

		$cursor = (int) ( $previous['cursor'] ?? 0 );
		if ( $cursor < 0 || $cursor >= $count ) {
			$cursor = 0;
		}

		$by_file = [];
		foreach ( $plugins as $index => $row ) {
			$by_file[ (string) ( $row['file'] ?? '' ) ] = $index;
		}

		$made      = 0;
		$next      = $cursor;
		$processed = 0;

		while ( $processed < $count && $made < self::MAX_API_CALLS ) {
			$file = (string) $files[ $next ];
			++$next;
			if ( $next >= $count ) {
				$next = 0;
			}
			++$processed;

			$offer = $response[ $file ] ?? null;
			$slug  = $offer->slug ?? ( is_array( $offer ) ? ( $offer['slug'] ?? '' ) : '' );
			if ( '' === (string) $slug ) {
				continue;
			}

			$index = $by_file[ $file ] ?? null;
			if ( null === $index ) {
				continue;
			}

			$info                                   = ( $this->api_source )( (string) $slug );
			$plugins[ $index ]['changelog_excerpt'] = self::changelog_excerpt( $info );
			++$made;
		}

		return $processed >= $count ? 0 : $next;
	}

	/**
	 * Extracts a plain-text changelog excerpt from a plugins_api() result.
	 *
	 * @param mixed $info plugins_api() result (object, false or WP_Error).
	 *
	 * @return string|null Excerpt, or null when unavailable.
	 */
	private static function changelog_excerpt( mixed $info ): ?string {
		if ( ! is_object( $info ) || empty( $info->sections->changelog ) || ! is_string( $info->sections->changelog ) ) {
			return null;
		}

		// wp.org changelog sections are HTML; keep the plain text only.
		$text = trim( html_entity_decode( wp_strip_all_tags( $info->sections->changelog ), ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $text ) {
			return null;
		}

		return mb_substr( $text, 0, self::EXCERPT_LENGTH );
	}

	/**
	 * String, or null when the value is empty.
	 *
	 * @param mixed $value Value.
	 */
	private static function string_or_null( mixed $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
