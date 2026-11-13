<?php
/**
 * Example: cache an expensive remote request with the framework Transients utility.
 *
 * Demonstrates {@see \rtCamp\WPPrimitives\Utils\Transients}: prefix-namespaced
 * transients for caching slow or external work. The canonical use case is a
 * remote HTTP call you do not want to repeat on every request. Call
 * get_remote_payload() from a block, shortcode, or REST route in your project.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules\Transients;

use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;
use rtCamp\WPPrimitives\Utils\Transients;

/**
 * Class - ExampleTransients
 */
final class ExampleTransients implements Registrable {

	/**
	 * Logical transient key (namespaced by the Transients instance).
	 */
	private const KEY = 'remote_payload';

	/**
	 * Framework transients, namespaced to this plugin.
	 *
	 * @var Transients
	 */
	private Transients $transients;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->transients = new Transients( 'amc_automata_features_features' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The read path is exposed via get_remote_payload(); there is no hook to
	 * wire for it. A real feature calls that method where it needs the data.
	 */
	public function register_hooks(): void {}

	/**
	 * A remote JSON payload, fetched at most once per hour and served from a
	 * transient on every later call.
	 *
	 * @return array<mixed> Decoded payload, or [] on failure.
	 */
	public function get_remote_payload(): array {
		$cached = $this->transients->get( self::KEY );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Example demonstrating a remote fetch cached in a transient.
		$response = wp_remote_get( 'https://example.com/api/data.json', [ 'timeout' => 3 ] );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : [];

		$this->transients->set( self::KEY, $data, HOUR_IN_SECONDS );

		return $data;
	}
}
