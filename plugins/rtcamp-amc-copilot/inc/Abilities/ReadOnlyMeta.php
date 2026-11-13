<?php
/**
 * Shared ability meta.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Abilities;

/**
 * Class ReadOnlyMeta
 *
 * The meta every AMC Copilot ability carries: annotated read-only and
 * idempotent, and available over REST (permission still `amc_manage`).
 *
 * @since 0.1.0
 */
final class ReadOnlyMeta {

	/**
	 * Not instantiable.
	 */
	private function __construct() {}

	/**
	 * Ability meta.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		return [
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}
