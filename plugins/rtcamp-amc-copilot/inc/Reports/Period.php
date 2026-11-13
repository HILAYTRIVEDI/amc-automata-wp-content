<?php
/**
 * Immutable report period value object.
 *
 * @package AMC\Reports
 */

declare( strict_types = 1 );

namespace AMC\Reports; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound,SlevomatCodingStandard.Namespaces.NamespaceSpacing.IncorrectLinesCountAfterNamespace -- AMC is the plugin namespace trunk.

use DateTimeImmutable;

/**
 * Class - Period
 *
 * One inclusive reporting window. The first instant is always 00:00:00 on the
 * first day and the last instant 23:59:59 on the last day, in the timezone the
 * dates carry.
 */
final class Period {

	/**
	 * Monthly period.
	 */
	public const TYPE_MONTH = 'month';

	/**
	 * Quarterly period.
	 */
	public const TYPE_QUARTER = 'quarter';

	/**
	 * Annual period.
	 */
	public const TYPE_YEAR = 'year';

	/**
	 * Ad-hoc period with an explicit range.
	 */
	public const TYPE_CUSTOM = 'custom';

	/**
	 * Period type: one of the TYPE_* constants.
	 */
	public readonly string $type;

	/**
	 * First instant of the period.
	 */
	public readonly DateTimeImmutable $start;

	/**
	 * Last instant of the period.
	 */
	public readonly DateTimeImmutable $end;

	/**
	 * Human-readable label, e.g. "October 2026".
	 */
	public readonly string $label;

	/**
	 * Constructor.
	 *
	 * @param string            $type  Period type: one of the TYPE_* constants.
	 * @param DateTimeImmutable $start First instant of the period.
	 * @param DateTimeImmutable $end   Last instant of the period.
	 * @param string            $label Human-readable label.
	 *
	 * @throws \InvalidArgumentException When the type is unknown or the range is inverted.
	 */
	public function __construct( string $type, DateTimeImmutable $start, DateTimeImmutable $end, string $label ) {
		if ( ! in_array( $type, [ self::TYPE_MONTH, self::TYPE_QUARTER, self::TYPE_YEAR, self::TYPE_CUSTOM ], true ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown period type "%s".', $type ) ) );
		}

		if ( $start > $end ) {
			throw new \InvalidArgumentException( 'A period cannot start after it ends.' );
		}

		$this->type  = $type;
		$this->start = $start;
		$this->end   = $end;
		$this->label = $label;
	}

	/**
	 * Period type.
	 *
	 * @return string One of the TYPE_* constants.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * First instant of the period.
	 *
	 * @return DateTimeImmutable Start at 00:00:00 on the first day.
	 */
	public function start(): DateTimeImmutable {
		return $this->start;
	}

	/**
	 * Last instant of the period.
	 *
	 * @return DateTimeImmutable End at 23:59:59 on the last day.
	 */
	public function end(): DateTimeImmutable {
		return $this->end;
	}

	/**
	 * Human-readable label.
	 *
	 * @return string Label such as "October 2026" or "Q3 2026–27".
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Stable report identifier for a site, e.g. `AMC-NWT-2026-10`.
	 *
	 * The site code is upper-cased and any run of characters outside A-Z and
	 * 0-9 is collapsed to a single hyphen, so "north west" becomes
	 * `AMC-NORTH-WEST-...`.
	 *
	 * @param string $site_code Site code from the AMC contract (e.g. "NWT").
	 *
	 * @return string Report identifier.
	 *
	 * @throws \InvalidArgumentException When the site code is empty after normalisation.
	 */
	public function report_id( string $site_code ): string {
		$code = self::normalize_site_code( $site_code );

		$suffix = match ( $this->type ) {
			self::TYPE_MONTH => $this->start->format( 'Y-m' ),
			self::TYPE_QUARTER => $this->start->format( 'Y' ) . '-' . $this->quarter_token(),
			self::TYPE_YEAR => $this->year_token(),
			self::TYPE_CUSTOM => $this->start->format( 'Ymd' ) . '-' . $this->end->format( 'Ymd' ),
		};

		return sprintf( 'AMC-%s-%s', $code, $suffix );
	}

	/**
	 * Quarter token used in a quarterly report ID.
	 *
	 * The label is the source of truth because a contract quarter does not
	 * start on a calendar quarter boundary.
	 *
	 * @return string Token such as `Q3`.
	 */
	private function quarter_token(): string {
		if ( 1 === preg_match( '/^Q([1-4])/', $this->label, $matches ) ) {
			return 'Q' . $matches[1];
		}

		return 'Q' . (string) (int) ceil( (int) $this->start->format( 'n' ) / 3 );
	}

	/**
	 * Year token used in an annual report ID.
	 *
	 * @return string `2025` for a calendar year, `2025-26` for a contract year.
	 */
	private function year_token(): string {
		$start_year = $this->start->format( 'Y' );
		$end_year   = $this->end->format( 'Y' );

		if ( $start_year === $end_year ) {
			return $start_year;
		}

		return $start_year . '-' . $this->end->format( 'y' );
	}

	/**
	 * Normalise a site code for use inside an ID.
	 *
	 * @param string $site_code Raw site code.
	 *
	 * @return string Upper-case, hyphen-separated code.
	 *
	 * @throws \InvalidArgumentException When nothing usable is left.
	 */
	private static function normalize_site_code( string $site_code ): string {
		$code = strtoupper( trim( $site_code ) );
		$code = trim( (string) preg_replace( '/[^A-Z0-9]+/', '-', $code ), '-' );

		if ( '' === $code ) {
			throw new \InvalidArgumentException( 'A site code is required to build a report ID.' );
		}

		return $code;
	}
}
