<?php
/**
 * Check: updates.themes (Theme updates).
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
 * Class ThemeUpdates
 *
 * Reads the `update_themes` site transient plus the installed themes and
 * reports pending theme updates. Active theme and parent are marked so an
 * engineer knows which update affects the rendered site.
 *
 * Themes are always distributed on wp.org or bundled (parent/child pairs on
 * other sources are rare), so unlike plugins there is no "No update source"
 * state here: a theme missing from the transient is simply unknown to
 * WordPress and is listed with update_source `none` all the same.
 *
 * Result::$data shape (consumed by the Updates screen and report CMS section):
 *   themes          list<array{stylesheet: string, name: string, version: string,
 *                     active: bool, parent: string|null, update_available: bool,
 *                     new_version: string|null, changelog_excerpt: string|null,
 *                     update_source: 'wp.org'|'none'}>
 *   total           int         Installed themes.
 *   pending         int         Themes with a pending update.
 *   active          string|null Active theme stylesheet.
 *   last_checked    string|null When WordPress last asked api.wordpress.org (UTC ISO 8601).
 *
 * Status rules:
 *   no update data     -> INFO (nothing to judge yet)
 *   any pending update -> WARN + Finding severity MEDIUM per theme
 *   up to date         -> PASS
 *
 * Finding dedupe key: 'theme:<stylesheet>:<new_version>' so a newer release
 * is a new finding (and alerts again), and updating resolves it.
 *
 * @since 0.1.0
 */
final class ThemeUpdates implements Check {

	/**
	 * Length of the changelog excerpt kept per theme.
	 *
	 * @var int
	 */
	private const EXCERPT_LENGTH = 500;

	/**
	 * Returns the `update_themes` site transient.
	 *
	 * @var callable(): mixed
	 */
	private $updates_source;

	/**
	 * Returns the installed themes.
	 *
	 * @var callable(): array<string, mixed>
	 */
	private $themes_source;

	/**
	 * Returns the active theme stylesheet and template.
	 *
	 * @var callable(): array{stylesheet: string, template: string}
	 */
	private $active_source;

	/**
	 * Returns wp.org theme information for a slug, or false/WP_Error.
	 *
	 * @var callable(string): mixed
	 */
	private $api_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $updates_source Test seam; default reads the update_themes site transient.
	 * @param callable|null $themes_source  Test seam; default wp_get_themes().
	 * @param callable|null $active_source  Test seam; default get_option( 'stylesheet' ) and 'template'.
	 * @param callable|null $api_source     Test seam; default themes_api() for the changelog section.
	 */
	public function __construct(
		?callable $updates_source = null,
		?callable $themes_source = null,
		?callable $active_source = null,
		?callable $api_source = null
	) {
		$this->updates_source = $updates_source ?? static fn (): mixed => get_site_transient( 'update_themes' );
		$this->themes_source  = $themes_source ?? static fn (): array => wp_get_themes();
		$this->active_source  = $active_source ?? static fn (): array => [
			'stylesheet' => (string) get_option( 'stylesheet' ),
			'template'   => (string) get_option( 'template' ),
		];
		$this->api_source     = $api_source ?? static function ( string $slug ): mixed {
			if ( ! function_exists( 'themes_api' ) ) {
				return false;
			}

			return themes_api(
				'theme_information',
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
		return 'updates.themes';
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
		return __( 'Theme updates', 'rtcamp-amc-copilot' );
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
	 * @param Context $ctx Run context (unused here: theme updates are network-wide).
	 */
	public function run( Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the Check contract.
		$themes    = $this->normalise( ( $this->themes_source )(), (array) ( $this->active_source )() );
		$transient = ( $this->updates_source )();

		$data = [
			'themes'       => $themes,
			'total'        => count( $themes ),
			'pending'      => 0,
			'active'       => null,
			'last_checked' => null,
		];

		foreach ( $themes as $row ) {
			if ( ! empty( $row['active'] ) ) {
				$data['active'] = (string) $row['stylesheet'];
			}
		}

		if ( [] === $themes ) {
			return Result::info( __( 'No themes are installed.', 'rtcamp-amc-copilot' ), $data );
		}

		// 1. No update data yet (fresh install, or update checks blocked): INFO.
		if ( ! is_object( $transient ) ) {
			return Result::info( __( 'WordPress has not fetched theme update data yet.', 'rtcamp-amc-copilot' ), $data );
		}

		if ( ! empty( $transient->last_checked ) ) {
			$data['last_checked'] = gmdate( 'c', (int) $transient->last_checked );
		}

		$response = is_array( $transient->response ?? null ) ? $transient->response : [];
		$findings = [];

		foreach ( $themes as $index => $row ) {
			$stylesheet = (string) ( $row['stylesheet'] ?? '' );
			if ( '' === $stylesheet ) {
				continue;
			}

			// 2. Update available.
			if ( isset( $response[ $stylesheet ] ) ) {
				$offer                   = is_object( $response[ $stylesheet ] ) ? $response[ $stylesheet ] : (object) ( $response[ $stylesheet ] );
				$version                 = (string) ( $offer->new_version ?? '' );
				$row['update_available'] = true;
				$row['new_version']      = '' !== $version ? $version : null;
				$row['update_source']    = 'wp.org';

				if ( '' !== $version && version_compare( (string) $row['version'], $version, '<' ) ) {
					++$data['pending'];

					$row['changelog_excerpt'] = self::changelog_excerpt(
						( $this->api_source )( (string) ( $offer->theme ?? $stylesheet ) )
					);

					$findings[] = new Finding(
						'theme:' . $stylesheet . ':' . $version,
						Severity::MEDIUM,
						sprintf(
							/* translators: 1: Theme name, 2: Installed version, 3: Available version. */
							__( 'Theme update available: %1$s (installed: %2$s, available: %3$s).', 'rtcamp-amc-copilot' ),
							(string) $row['name'],
							(string) $row['version'],
							$version
						),
						[
							'stylesheet'  => $stylesheet,
							'installed'   => (string) $row['version'],
							'new_version' => $version,
							'active'      => (bool) $row['active'],
						]
					);
				}

				$themes[ $index ] = $row;
			}
		}

		$data['themes'] = array_values( $themes );

		if ( [] !== $findings ) {
			return Result::warn(
				sprintf(
					/* translators: %s: Number of themes with pending updates. */
					_n(
						'%s theme update is pending.',
						'%s theme updates are pending.',
						count( $findings ),
						'rtcamp-amc-copilot'
					),
					number_format_i18n( count( $findings ) )
				),
				$data,
				$findings
			);
		}

		return Result::pass( __( 'All themes are up to date.', 'rtcamp-amc-copilot' ), $data );
	}

	/**
	 * Normalises the installed themes into rows.
	 *
	 * @param array<string, mixed>                        $themes wp_get_themes() result.
	 * @param array{stylesheet: string, template: string} $active Active theme.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function normalise( array $themes, array $active ): array {
		$rows = [];

		foreach ( $themes as $stylesheet => $theme ) {
			$stylesheet = (string) $stylesheet;
			$parent     = $theme->parent();

			$rows[ $stylesheet ] = [
				'stylesheet'        => $stylesheet,
				'name'              => (string) $theme->get( 'Name' ),
				'version'           => (string) $theme->get( 'Version' ),
				'active'            => ( $active['stylesheet'] ?? '' ) === $stylesheet,
				'parent'            => $parent instanceof \WP_Theme ? $parent->get_stylesheet() : null,
				'update_available'  => false,
				'new_version'       => null,
				'changelog_excerpt' => null,
				'update_source'     => null,
			];
		}

		ksort( $rows );

		return array_values( $rows );
	}

	/**
	 * Extracts a plain-text changelog excerpt from a themes_api() result.
	 *
	 * @param mixed $info themes_api() result (object, false or WP_Error).
	 *
	 * @return string|null Excerpt, or null when unavailable.
	 */
	private static function changelog_excerpt( mixed $info ): ?string {
		if ( ! is_object( $info ) || empty( $info->sections->changelog ) || ! is_string( $info->sections->changelog ) ) {
			return null;
		}

		$text = trim( html_entity_decode( wp_strip_all_tags( $info->sections->changelog ), ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $text ) {
			return null;
		}

		return mb_substr( $text, 0, self::EXCERPT_LENGTH );
	}
}
