<?php
/**
 * Fixture: installed themes as wp_get_themes()-shaped fake theme objects.
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	'twentytwentysix' => new class() {

		/**
		 * Theme header value.
		 *
		 * @param string $header Header name.
		 */
		public function get( string $header ): string {
			return [
				'Name'    => 'Twenty Twenty-Six',
				'Version' => '1.0.2',
			][ $header ] ?? '';
		}

		/**
		 * Parent theme (none).
		 */
		public function parent(): bool {
			return false;
		}
	},
	'storefront'      => new class() {

		/**
		 * Theme header value.
		 *
		 * @param string $header Header name.
		 */
		public function get( string $header ): string {
			return [
				'Name'    => 'Storefront',
				'Version' => '4.6.0',
			][ $header ] ?? '';
		}

		/**
		 * Parent theme (none).
		 */
		public function parent(): bool {
			return false;
		}
	},
];
