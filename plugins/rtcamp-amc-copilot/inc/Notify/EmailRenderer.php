<?php
/**
 * Builds the plain-text and HTML bodies of every AMC email.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Notify;

use AMC\Core\Brand;

/**
 * Class EmailRenderer
 *
 * Input shape (all keys optional except `heading`):
 *
 *   heading:      string
 *   intro:        string
 *   sections:     list<array{
 *                   title: string,
 *                   items: list<array{title: string, severity: string, check: string, url: string}>,
 *                   more?: int            // findings not listed
 *                 }>
 *   paragraphs:   list<string>
 *   action_url:   string
 *   action_label: string
 *
 * The HTML is a few inline-styled tables in rtCamp's brand colours (see
 * AMC\Core\Brand) with no external assets, so every mail client renders it.
 * Clients without Reddit Sans fall back to the system font stack.
 *
 * @since 0.1.0
 */
final class EmailRenderer {

	private const TEXT   = Brand::INK;
	private const MUTED  = Brand::MUTED;
	private const BORDER = Brand::RULE;
	private const BG     = Brand::CREAM;
	private const LINK   = Brand::INK;
	private const ACCENT = Brand::RED;

	/**
	 * Render both parts.
	 *
	 * @param array<string, mixed> $email Email content (see class docblock).
	 *
	 * @return array{text: string, html: string}
	 */
	public static function render( array $email ): array {
		return [
			'text' => self::text( $email ),
			'html' => self::html( $email ),
		];
	}

	/**
	 * Plain-text part.
	 *
	 * @param array<string, mixed> $email Email content.
	 */
	public static function text( array $email ): string {
		$lines   = [];
		$lines[] = (string) ( $email['heading'] ?? '' );
		$lines[] = '';

		if ( ! empty( $email['intro'] ) ) {
			$lines[] = (string) $email['intro'];
			$lines[] = '';
		}

		foreach ( self::sections( $email ) as $section ) {
			$lines[] = strtoupper( $section['title'] );
			foreach ( $section['items'] as $item ) {
				$lines[] = sprintf( '- [%s] %s (%s)', $item['severity'], $item['title'], $item['check'] );
				if ( '' !== $item['url'] ) {
					$lines[] = '  ' . $item['url'];
				}
			}
			if ( $section['more'] > 0 ) {
				/* translators: %d: Number of findings not listed. */
				$lines[] = '  ' . sprintf( _n( '… and %d more', '… and %d more', $section['more'], 'rtcamp-amc-copilot' ), $section['more'] );
			}
			$lines[] = '';
		}

		foreach ( self::paragraphs( $email ) as $paragraph ) {
			$lines[] = $paragraph;
			$lines[] = '';
		}

		if ( ! empty( $email['action_url'] ) ) {
			$lines[] = (string) ( $email['action_label'] ?? '' ) . ': ' . (string) $email['action_url'];
			$lines[] = '';
		}

		return wp_strip_all_tags( trim( implode( "\n", $lines ) ) ) . "\n";
	}

	/**
	 * HTML part.
	 *
	 * @param array<string, mixed> $email Email content.
	 */
	public static function html( array $email ): string {
		$cell = 'padding:12px 24px;font-family:' . str_replace( '"', '\'', Brand::FONT_STACK ) . ';font-size:14px;line-height:1.55;color:' . self::TEXT . ';';

		$body = '<tr><td style="' . $cell . 'padding-top:20px;font-size:13px;font-weight:600;color:' . self::MUTED . ';">'
			// The brand name keeps its own casing; only the product label is set as an eyebrow.
			. '<span style="color:' . self::ACCENT . ';font-size:15px;">rtCamp</span> &middot; <span style="font-size:11px;letter-spacing:0.1em;text-transform:uppercase;">' . esc_html__( 'AMC Copilot', 'rtcamp-amc-copilot' ) . '</span></td></tr>';
		$body .= '<tr><td style="' . $cell . '"><h1 style="margin:0;font-size:22px;font-weight:600;line-height:1.3;color:' . self::TEXT . ';">' . esc_html( (string) ( $email['heading'] ?? '' ) ) . '</h1></td></tr>';

		if ( ! empty( $email['intro'] ) ) {
			$body .= '<tr><td style="' . $cell . 'color:' . self::MUTED . ';">' . esc_html( (string) $email['intro'] ) . '</td></tr>';
		}

		foreach ( self::sections( $email ) as $section ) {
			$body .= '<tr><td style="' . $cell . '"><h2 style="margin:0 0 8px;font-size:15px;color:' . self::TEXT . ';">' . esc_html( $section['title'] ) . '</h2>';
			$body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid ' . self::BORDER . ';">';

			foreach ( $section['items'] as $item ) {
				$title = esc_html( $item['title'] );
				if ( '' !== $item['url'] ) {
					$title = '<a href="' . esc_url( $item['url'] ) . '" style="color:' . self::LINK . ';text-decoration:underline;">' . $title . '</a>';
				}
				$body .= '<tr>'
					. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::BORDER . ';font-size:13px;white-space:nowrap;vertical-align:top;background:' . self::BG . ';"><strong>' . esc_html( $item['severity'] ) . '</strong></td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid ' . self::BORDER . ';font-size:14px;vertical-align:top;">' . $title
					. '<br /><span style="font-size:12px;color:' . self::MUTED . ';">' . esc_html( $item['check'] ) . '</span></td>'
					. '</tr>';
			}

			if ( $section['more'] > 0 ) {
				/* translators: %d: Number of findings not listed. */
				$more  = sprintf( _n( '… and %d more', '… and %d more', $section['more'], 'rtcamp-amc-copilot' ), $section['more'] );
				$body .= '<tr><td colspan="2" style="padding:8px 10px;font-size:13px;color:' . self::MUTED . ';">' . esc_html( $more ) . '</td></tr>';
			}

			$body .= '</table></td></tr>';
		}

		foreach ( self::paragraphs( $email ) as $paragraph ) {
			$body .= '<tr><td style="' . $cell . '">' . esc_html( $paragraph ) . '</td></tr>';
		}

		if ( ! empty( $email['action_url'] ) ) {
			$body .= '<tr><td style="' . $cell . '"><a href="' . esc_url( (string) $email['action_url'] ) . '" style="display:inline-block;padding:10px 20px;background:' . self::LINK . ';color:#ffffff;text-decoration:none;border-radius:4px;font-size:14px;font-weight:600;">'
				. esc_html( (string) ( $email['action_label'] ?? __( 'Open', 'rtcamp-amc-copilot' ) ) ) . '</a></td></tr>';
		}

		$footer = sprintf(
			/* translators: %s: Site name. */
			__( 'Sent by AMC Copilot on %s.', 'rtcamp-amc-copilot' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
		);
		$body .= '<tr><td style="' . $cell . 'font-size:12px;color:' . self::MUTED . ';">' . esc_html( $footer ) . '</td></tr>';

		$html = '<!DOCTYPE html><html><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /></head>'
			. '<body style="margin:0;padding:0;background:' . self::BG . ';">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::BG . ';"><tr><td align="center" style="padding:20px 10px;">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid ' . self::BORDER . ';border-top:4px solid ' . self::ACCENT . ';">'
			. $body
			. '</table></td></tr></table></body></html>';

		// Keep every line short: mail servers fold or re-encode very long ones.
		return str_replace( [ '</tr>', '</td>', '</table>' ], [ "</tr>\n", "</td>\n", "</table>\n" ], $html );
	}

	/**
	 * Normalised sections.
	 *
	 * @param array<string, mixed> $email Email content.
	 *
	 * @return list<array{title: string, items: list<array{title: string, severity: string, check: string, url: string}>, more: int}>
	 */
	private static function sections( array $email ): array {
		$sections = [];

		foreach ( (array) ( $email['sections'] ?? [] ) as $section ) {
			if ( ! is_array( $section ) || empty( $section['items'] ) ) {
				continue;
			}

			$items = [];
			foreach ( (array) $section['items'] as $item ) {
				$items[] = [
					'title'    => (string) ( $item['title'] ?? '' ),
					'severity' => (string) ( $item['severity'] ?? '' ),
					'check'    => (string) ( $item['check'] ?? '' ),
					'url'      => (string) ( $item['url'] ?? '' ),
				];
			}

			$sections[] = [
				'title' => (string) ( $section['title'] ?? '' ),
				'items' => $items,
				'more'  => (int) ( $section['more'] ?? 0 ),
			];
		}

		return $sections;
	}

	/**
	 * Normalised paragraphs.
	 *
	 * @param array<string, mixed> $email Email content.
	 *
	 * @return list<string>
	 */
	private static function paragraphs( array $email ): array {
		return array_values( array_map( 'strval', (array) ( $email['paragraphs'] ?? [] ) ) );
	}
}
