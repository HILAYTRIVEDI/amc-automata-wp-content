<?php
/**
 * Access to core's WP_Site_Health outside wp-admin.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- The AMC prefix belongs to AMC Copilot, whose rename is carried on another branch.

namespace AMC\Support;

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class - CoreSiteHealth
 *
 * `WP_Site_Health` lives in wp-admin, which cron and WP-CLI never load. Core's
 * own scheduled check (`WP_Site_Health::wp_cron_scheduled_check()`) bootstraps
 * wp-admin the same way before running its tests.
 */
final class CoreSiteHealth {

	/**
	 * Disallow instantiation - this class only exposes static helpers.
	 */
	private function __construct() {}

	/**
	 * Returns core's Site Health instance, loading its class when needed.
	 *
	 * @return \WP_Site_Health|null Null when the class cannot be loaded.
	 */
	public static function instance(): ?\WP_Site_Health {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			if ( ! defined( 'ABSPATH' ) ) {
				return null;
			}

			require_once ABSPATH . 'wp-admin/includes/admin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		return \WP_Site_Health::get_instance();
	}

	/**
	 * Runs a callback with every HTTP request's timeout capped.
	 *
	 * Core's tests use timeouts up to 10 seconds; the checks have a 20 second budget.
	 *
	 * @template T
	 *
	 * @param callable(): T $callback Work that makes the requests.
	 * @param int           $seconds  Maximum timeout per request.
	 *
	 * @return T
	 */
	public static function with_http_timeout( callable $callback, int $seconds ): mixed {
		$cap = static function ( $args ) use ( $seconds ) {
			if ( is_array( $args ) ) {
				$args['timeout'] = min( (float) ( $args['timeout'] ?? $seconds ), (float) $seconds );
			}

			return $args;
		};

		add_filter( 'http_request_args', $cap, PHP_INT_MAX ); // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.http_request_args -- Only ever lowers a timeout.

		try {
			return $callback();
		} finally {
			remove_filter( 'http_request_args', $cap, PHP_INT_MAX );
		}
	}
}
