<?php
/**
 * Brand values (rtCamp) shared by every surface the plugin draws.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Core;

/**
 * Class Brand
 *
 * The palette and typefaces of rtcamp.com, in one place: the stylesheets
 * mirror these values in `src/css/_brand.scss`, and PHP output that cannot
 * use a stylesheet (emails) reads the constants.
 *
 * Reddit Sans and Reddit Mono (SIL OFL 1.1) are bundled in `assets/fonts/`,
 * so wp-admin makes no third-party font request.
 *
 * @since 0.1.0
 */
final class Brand {

	/**
	 * Style handle that carries the @font-face rules.
	 */
	public const FONTS_HANDLE = 'amc-brand-fonts';

	/**
	 * Text.
	 */
	public const INK = '#1c1c1c';

	/**
	 * Secondary text and labels.
	 */
	public const MUTED = '#565656';

	/**
	 * Hairlines.
	 */
	public const RULE = '#e5e3dc';

	/**
	 * Warm paper tint.
	 */
	public const CREAM = '#f9f8f2';

	/**
	 * The rtCamp red: accents only, never for status.
	 */
	public const RED = '#c63c51';

	/**
	 * Body font stack.
	 */
	public const FONT_STACK = '"Reddit Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif';

	/**
	 * The @font-face rules for the bundled typefaces.
	 */
	public static function font_face_css(): string {
		$base = plugins_url( 'assets/fonts/', AMC_COPILOT_FILE );

		return sprintf(
			'@font-face{font-family:"Reddit Sans";font-style:normal;font-weight:400 700;font-display:swap;src:url("%1$s") format("woff2");}'
			. '@font-face{font-family:"Reddit Mono";font-style:normal;font-weight:400 600;font-display:swap;src:url("%2$s") format("woff2");}',
			esc_url( $base . 'reddit-sans-latin.woff2' ),
			esc_url( $base . 'reddit-mono-latin.woff2' )
		);
	}

	/**
	 * Enqueue the @font-face rules (safe to call more than once).
	 */
	public static function enqueue_fonts(): void {
		if ( ! wp_style_is( self::FONTS_HANDLE, 'registered' ) ) {
			wp_register_style( self::FONTS_HANDLE, false, [], AMC_COPILOT_VERSION );
			wp_add_inline_style( self::FONTS_HANDLE, self::font_face_css() );
		}

		wp_enqueue_style( self::FONTS_HANDLE );
	}
}
